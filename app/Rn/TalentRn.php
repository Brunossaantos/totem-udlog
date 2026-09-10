<?php

namespace App\Rn;

use App\Dao\AtendimentoDao;
use App\Dao\FilaEnvioDao;
use Util\AnexoPdfHelper;

/**
 * Orquestra o check-in na Portaria do Talent (Portaria/Checkin), conforme
 * contrato confirmado em docs/manual_talent.md — substitui o desenho
 * placeholder anterior. Ver
 * docs/handoffs/2026-09-09-integracao-talent-portaria-checkin.md para o
 * desenho completo aprovado.
 *
 * Idempotencia de 5 estados (NAO_ENVIADO/ENVIANDO/ENVIADO/
 * ERRO_REPROCESSAVEL/ENVIO_INDETERMINADO) via App\Dao\AtendimentoDao — a
 * TRANSICAO atomica (CAS) e feita la, esta classe so orquestra a SEQUENCIA
 * (marcar obsoleto -> checar estado atual -> adquirir lock -> montar
 * payload/anexos -> chamar o Talent -> gravar resultado).
 */
class TalentRn
{
    /**
     * Timeout para tratar um ENVIANDO como obsoleto/zumbi (requisicao PHP
     * morta no meio, sem nunca gravar resultado) — folga sobre o timeout de
     * 30s do TalentClient (30s + margem de seguranca).
     */
    private const TIMEOUT_INDETERMINADO_SEGUNDOS = 60;

    public function __construct(
        private TalentClient $talentClient,
        private FilaEnvioDao $filaEnvioDao,
        private AtendimentoDao $atendimentoDao,
        private string $caminhoBase
    ) {}

    /**
     * Executa (ou consulta idempotentemente) o check-in de um atendimento —
     * usado tanto por App\Controller\AtendimentoController::finalizar()
     * (checks de posse/tipo/status/etapa/documentos JA feitos pelo chamador,
     * ver assinatura) quanto por cron/reenviar-fila.php (retry de
     * ERRO_REPROCESSAVEL).
     *
     * @param array $empresa linha de tb_empresa JA resolvida a partir do
     *                       totem autenticado (nunca do frontend) —
     *                       resolucao de cnpjArmazem e responsabilidade do
     *                       chamador (controller/cron), ver
     *                       App\Dao\EmpresaDao.
     * @return array{status:string, senha:?string, protocolo:?string, erro_categoria:?string}
     *         status em: ENVIADO | JA_ENVIADO | EM_ANDAMENTO |
     *         INDETERMINADO_PENDENTE_MANUAL | ERRO_REPROCESSAVEL |
     *         ENVIO_INDETERMINADO
     */
    public function processarCheckin(array $atendimento, array $empresa, array $notas): array
    {
        $idAtendimento = (int) $atendimento['id_atendimento'];

        // Rede de seguranca: destrava ENVIANDO obsoleto (requisicao morta no
        // meio) ANTES de decidir se pode tentar de novo — mas ENVIO_INDETERMINADO
        // continua fora da elegibilidade de retry automatico por design.
        $this->atendimentoDao->marcarEnvioTalentObsoletoComoIndeterminado($idAtendimento, self::TIMEOUT_INDETERMINADO_SEGUNDOS);

        $atual = $this->atendimentoDao->buscarPorId($idAtendimento) ?? $atendimento;
        $statusAtual = $atual['talent_checkin_status'] ?? 'NAO_ENVIADO';

        if ($statusAtual === 'ENVIADO') {
            return ['status' => 'JA_ENVIADO', 'senha' => $atual['talent_senha'], 'protocolo' => $atual['talent_protocolo'], 'erro_categoria' => null];
        }
        if ($statusAtual === 'ENVIANDO') {
            return ['status' => 'EM_ANDAMENTO', 'senha' => null, 'protocolo' => null, 'erro_categoria' => null];
        }
        if ($statusAtual === 'ENVIO_INDETERMINADO') {
            return ['status' => 'INDETERMINADO_PENDENTE_MANUAL', 'senha' => null, 'protocolo' => null, 'erro_categoria' => null];
        }

        $tentativaId = bin2hex(random_bytes(16));
        if (!$this->atendimentoDao->iniciarEnvioTalent($idAtendimento, $tentativaId)) {
            // outra requisicao venceu a corrida entre a releitura acima e o CAS
            return ['status' => 'EM_ANDAMENTO', 'senha' => null, 'protocolo' => null, 'erro_categoria' => null];
        }

        try {
            $payload = $this->montarPayload($atual, $notas, $empresa);
        } catch (\Throwable $e) {
            // Falha ANTES de qualquer requisicao HTTP sair (dado invalido,
            // anexo ausente/corrompido, etc.) — sempre reprocessavel, nunca
            // indeterminado (nada foi transmitido ao Talent).
            $this->atendimentoDao->gravarResultadoEnvioTalent($idAtendimento, $tentativaId, 'ERRO_REPROCESSAVEL', null, null);
            return ['status' => 'ERRO_REPROCESSAVEL', 'senha' => null, 'protocolo' => null, 'erro_categoria' => 'erro_montagem_payload'];
        }

        try {
            $resultado = $this->talentClient->checkin($payload);
        } catch (TalentClientException $e) {
            // TODO(integracao-talent-portaria-checkin): HTTP 409 (categoria
            // 'conflito') tratado por precaucao como ERRO_REPROCESSAVEL
            // nesta versao — NAO reconciliar automaticamente como
            // duplicidade/ENVIADO ate um teste controlado em Producao
            // confirmar o significado real do 409 para este endpoint (o
            // manual so documenta "violacao de regra de negocio", sem
            // detalhar). Ver docs/manual_talent.md, secao "HTTP 409".
            // ehIndeterminado() cobre 'timeout' e 'erro_indeterminado' —
            // qualquer erro de curl que pode ter ocorrido depois que a
            // requisicao ja saiu do totem (ver App\Rn\TalentClient::checkin()
            // e App\Rn\TalentClientException::ehIndeterminado(), correcao de
            // seguranca apos revisao do security-especialista).
            $statusFinal = $e->ehIndeterminado() ? 'ENVIO_INDETERMINADO' : 'ERRO_REPROCESSAVEL';
            $this->atendimentoDao->gravarResultadoEnvioTalent($idAtendimento, $tentativaId, $statusFinal, null, null);
            return ['status' => $statusFinal, 'senha' => null, 'protocolo' => null, 'erro_categoria' => $e->categoria()];
        }

        $this->atendimentoDao->gravarResultadoEnvioTalent($idAtendimento, $tentativaId, 'ENVIADO', $resultado['senha'], $resultado['protocolo']);

        return ['status' => 'ENVIADO', 'senha' => $resultado['senha'], 'protocolo' => $resultado['protocolo'], 'erro_categoria' => null];
    }

    /**
     * Registra uma falha ERRO_REPROCESSAVEL na fila de reenvio do cron.
     * $categoriaErro e sempre uma categoria interna fechada e sanitizada
     * (TalentClientException::categoria() ou um codigo interno fixo como
     * 'erro_montagem_payload') — NUNCA o corpo bruto de uma excecao.
     */
    public function registrarFalhaParaReenvio(int $idAtendimento, string $categoriaErro): void
    {
        $this->filaEnvioDao->registrarFalha($idAtendimento, $categoriaErro);
    }

    /**
     * Monta o payload real do Talent (Portaria/Checkin), conforme
     * docs/manual_talent.md — campos opcionais sem fonte confiavel sao
     * OMITIDOS (nunca null/string vazia/placeholder). Lanca \RuntimeException
     * (mensagem curta, so codigo interno, nunca dado sensivel) se algum
     * campo OBRIGATORIO nao puder ser resolvido com seguranca.
     */
    private function montarPayload(array $atendimento, array $notas, array $empresa): array
    {
        $cnpjArmazem = preg_replace('/\D/', '', (string) ($empresa['cnpj'] ?? ''));
        if (strlen($cnpjArmazem) !== 14) {
            throw new \RuntimeException('cnpj_armazem_invalido');
        }

        $cnpjDepositante = preg_replace('/\D/', '', (string) ($atendimento['cliente_cnpj'] ?? ''));
        if (strlen($cnpjDepositante) !== 14) {
            throw new \RuntimeException('cnpj_depositante_invalido');
        }

        if (!in_array($atendimento['tipo'] ?? null, ['expedicao', 'recebimento'], true)) {
            throw new \RuntimeException('tipo_atendimento_invalido');
        }
        // Literais confirmados por decisao de produto (2026-09-09):
        // minusculas, "embarque"/"desembarque" — substitui a suposicao
        // anterior capitalizada ("Embarque"/"Desembarque").
        $tipoEmbDesemb = $atendimento['tipo'] === 'expedicao' ? 'embarque' : 'desembarque';

        $placa = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($atendimento['placa'] ?? '')));
        $uf = strtoupper(trim((string) ($atendimento['crlv_uf'] ?? '')));
        // rntc/tipo (obrigatorios pelo Talent, confirmados por teste real de
        // Producao em 2026-09-10) — SO chegam preenchidos aqui se
        // App\Rn\DocumentoRn::crlvAprovado() ja tiver aprovado o atendimento
        // (responsabilidade do chamador, ver AtendimentoController::finalizar()/
        // cron/reenviar-fila.php, que so acionam processarCheckin() depois
        // desse gate). Validado explicitamente aqui tambem como defesa em
        // profundidade — nunca confia cegamente no chamador.
        $rntc = trim((string) ($atendimento['crlv_rntc'] ?? ''));
        $tipoVeiculo = trim((string) ($atendimento['crlv_tipo_veiculo'] ?? ''));
        if ($placa === '' || !in_array($uf, DocumentoRn::UFS_VALIDAS, true) || $rntc === '' || $tipoVeiculo === '') {
            throw new \RuntimeException('veiculo_invalido');
        }

        $cpfMotorista = preg_replace('/\D/', '', (string) ($atendimento['motorista_cpf'] ?? ''));
        $nomeMotorista = trim((string) ($atendimento['motorista_nome'] ?? ''));
        if (strlen($cpfMotorista) !== 11 || $nomeMotorista === '') {
            throw new \RuntimeException('motorista_invalido');
        }

        $payload = [
            'cnpjArmazem' => $cnpjArmazem,
            'cnpjDepositante' => $cnpjDepositante,
            'tipoEmbDesemb' => $tipoEmbDesemb,
            'veiculo' => ['placa' => $placa, 'uf' => $uf, 'rntc' => $rntc, 'tipo' => $tipoVeiculo],
            'motorista' => ['cpf' => $cpfMotorista, 'nome' => $nomeMotorista],
        ];

        // ajudantes[]: so incluido se possui_ajudante=1 E nome preenchido —
        // NUNCA null/array vazio quando nao ha ajudante (campo omitido).
        if (!empty($atendimento['possui_ajudante']) && trim((string) ($atendimento['ajudante_nome'] ?? '')) !== '') {
            $payload['ajudantes'] = [[
                'cpf' => preg_replace('/\D/', '', (string) ($atendimento['ajudante_cpf'] ?? '')),
                'nome' => trim((string) $atendimento['ajudante_nome']),
            ]];
        }

        // doctos[] OMITIDO nesta versao — sem mapeamento confirmado de
        // nrDocto por tipo (decisao registrada em docs/manual_talent.md,
        // "Doctos — decisao desta rodada"). Demais campos opcionais sem
        // fonte de captura (reboque, exigePesagem, transportadora,
        // telefones, nrCNH/categoriaCNH, pernoite, paletes,
        // container/lacre/delivery, obs) tambem OMITIDOS.

        $payload['anexos'] = $this->montarAnexos($atendimento, $notas);

        return $payload;
    }

    /**
     * Monta os anexos em PDF (item 4 do escopo) — CNH (frente+verso, 2
     * paginas; fallback para o legado cnh.jpg de 1 pagina), CRLV (1 pagina)
     * e uma nota fiscal por PDF (1 pagina cada, ordem preservada). SEMPRE a
     * partir das imagens JPEG ja validadas em disco pelo scanner/leitor do
     * totem — NUNCA a partir de image.base64 da resposta da VIO Decode.
     *
     * CNH/CRLV ausentes ou corrompidos IMPEDEM a finalizacao (excecao
     * propagada, tratada pelo chamador como ERRO_REPROCESSAVEL, nunca chega
     * a chamar o Talent). Nota fiscal ausente no disco (registrada no banco
     * mas arquivo sumiu) e pulada silenciosamente — nao trava CNH/CRLV, mas
     * tambem nunca inventa um anexo para ela.
     */
    private function montarAnexos(array $atendimento, array $notas): array
    {
        $pasta = rtrim($this->caminhoBase, '/') . '/' . $atendimento['pasta_documentos'];
        $anexos = [];

        $cnhFrente = $pasta . '/cnh_frente.jpg';
        $cnhVerso = $pasta . '/cnh_verso.jpg';
        $cnhLegado = $pasta . '/cnh.jpg';

        if (is_file($cnhFrente) && is_file($cnhVerso)) {
            $anexos[] = $this->anexarPdf([$cnhFrente, $cnhVerso], 'CNH');
        } elseif (is_file($cnhLegado)) {
            $anexos[] = $this->anexarPdf([$cnhLegado], 'CNH');
        } else {
            throw new \RuntimeException('anexo_cnh_ausente');
        }

        $crlv = $pasta . '/crlv.jpg';
        if (!is_file($crlv)) {
            throw new \RuntimeException('anexo_crlv_ausente');
        }
        $anexos[] = $this->anexarPdf([$crlv], 'CRLV');

        foreach ($notas as $nota) {
            $arquivoNota = $pasta . '/' . $nota['arquivo'];
            if (!is_file($arquivoNota)) {
                continue;
            }
            $descricao = sprintf('Nota Fiscal %02d', (int) $nota['ordem']);
            $anexos[] = $this->anexarPdf([$arquivoNota], $descricao);
        }

        return $anexos;
    }

    /**
     * Gera o PDF (App\Util\AnexoPdfHelper, validado: assinatura %PDF-,
     * tamanho minimo, contagem de paginas) e retorna o item de anexo no
     * formato exato do Talent: base64 PURO (sem prefixo data:), descricao
     * literal.
     */
    private function anexarPdf(array $caminhosJpeg, string $descricao): array
    {
        $pdfBytes = AnexoPdfHelper::gerarPdfDeImagens($caminhosJpeg);

        return [
            'anexoBase64' => base64_encode($pdfBytes),
            'descricao' => $descricao,
        ];
    }
}
