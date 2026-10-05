<?php

namespace App\Rn;

use App\Dao\AtendimentoDao;

class AtendimentoRn
{
    public function __construct(
        private AtendimentoDao $atendimentoDao,
        private OrdemColetaClient $ordemColetaClient
    ) {}

    /**
     * $idAceiteLgpd opcional (demanda tela-inicial-lgpd-totem, 2026-09-24)
     * — repassado diretamente para App\Dao\AtendimentoDao::criar(). Ver
     * comentario la para o motivo de ser NULLABLE/opcional.
     */
    public function iniciar(int $idTotem, string $tipo, string $placa, ?int $idAceiteLgpd = null): int
    {
        return $this->atendimentoDao->criar($idTotem, $tipo, $placa, $idAceiteLgpd);
    }

    /**
     * Filtro morto removido em 2026-09-11 (demanda
     * expedicao-consulta-ordem-coleta-teste): o campo 'status' nunca existiu
     * de fato na fonte real de dados (era presumido pelo placeholder HTTP
     * antigo). O filtro de status/cliente ativo agora acontece na propria
     * consulta SQL (App\Dao\OrdemColetaDao::buscarPorPlacaNormalizada), nao
     * mais em array PHP aqui.
     */
    public function consultarOrdensAbertas(string $placa): array
    {
        return $this->ordemColetaClient->buscarPorPlaca($placa);
    }

    public function selecionarOrdem(int $idAtendimento, array $ordem): void
    {
        $this->atendimentoDao->preencherDadosOrdem($idAtendimento, $ordem);
    }

    public function definirPasta(int $idAtendimento, string $pasta): void
    {
        $this->atendimentoDao->definirPasta($idAtendimento, $pasta);
    }

    /**
     * Passthrough (Tarefa 3, rodada corretiva de 2026-09-26) — ver
     * App\Dao\AtendimentoDao::reconciliarProcessamentoVioApiBrAbandonado()
     * para o comportamento completo. Chamado por
     * App\Controller\AtendimentoController::consumirAceiteECriarAtendimento()
     * logo apos a criacao do novo atendimento, dentro da MESMA transacao.
     */
    public function reconciliarProcessamentoAbandonado(int $idTotem, int $idAtendimentoAtual): void
    {
        $this->atendimentoDao->reconciliarProcessamentoVioApiBrAbandonado($idTotem, $idAtendimentoAtual);
    }

    public function atualizarEtapa(int $idAtendimento, string $etapa): void
    {
        $this->atendimentoDao->atualizarEtapa($idAtendimento, $etapa);
    }

    /**
     * Grava os dados finais de motorista/CNH/CRLV confirmados na tela
     * exp_confirma da Expedicao. Se o documento (CNH e/ou CRLV) foi
     * validado pela VIO Decode (VIO_TRIAL/VIO_VALIDADO) ou pela vio.api.br
     * (VIO_API_BR, incluida na rodada corretiva de 2026-09-26 — sem isso,
     * uma validacao real da vio.api.br NUNCA seria rebaixada para MANUAL
     * mesmo com edicao divergente do snapshot, pois o bloco de comparacao
     * abaixo simplesmente nao rodaria para essa origem) e o atendente
     * EDITAR qualquer campo desse documento em relacao ao snapshot gravado
     * no momento da validacao (cnh_snapshot_* / crlv_snapshot_*), a origem
     * daquele documento e rebaixada para MANUAL + status_revisao =
     * PENDENTE_REVISAO. Confirmar sem editar (valor identico ao snapshot)
     * preserva a origem/status originais.
     *
     * A decisao e EXCLUSIVAMENTE do backend — qualquer campo de origem/
     * status vindo em $dados (ex.: 'cnh_origem') e IGNORADO, nunca usado
     * para decidir nada aqui.
     *
     * O registro em tb_vio_cache_cnh/tb_vio_cache_crlv NUNCA e alterado por
     * esta rotina (nenhum UPDATE/DELETE nessas tabelas aqui) — o cache
     * continua representando o que a VIO efetivamente retornou para aquele
     * QR; so o dado do ATENDIMENTO e rebaixado.
     *
     * Se o documento ja estava MANUAL (ou NAO_VALIDADO), nao ha snapshot
     * para comparar — mantem a origem/status como estavam (edicao de um
     * campo ja manual continua manual, nunca "promove" a origem sozinha).
     *
     * Design escolhido para reversao: uma vez rebaixado para MANUAL, o
     * atendimento SO volta a VIO_API_BR atraves de uma nova validacao
     * automatica bem-sucedida (App\Rn\DocumentoRn::avaliarResultadoVioApiBrCnh/
     * Crlv) — nao ha "auto-restauracao" so por o atendente digitar de volta
     * o valor original na tela de confirmacao, porque o snapshot so e
     * reescrito quando uma validacao automatica nova acontece, e a origem
     * so e recolocada em VIO_API_BR por essa mesma validacao. VIO_TRIAL/
     * VIO_VALIDADO permanecem so por compatibilidade historica (fluxo
     * antigo Serpro, removido em remocao-legado-serpro-e-hardening-
     * documentos, 2026-09-28) — nenhum codigo novo os grava mais.
     */
    public function salvarDadosMotorista(int $idAtendimento, array $dados): void
    {
        $atendimento = $this->atendimentoDao->buscarPorId($idAtendimento);
        if ($atendimento === null) {
            throw new \RuntimeException('Atendimento nao encontrado');
        }

        $nomeNormalizado = trim((string) ($dados['motorista_nome'] ?? ''));
        $cpfNormalizado = preg_replace('/\D/', '', (string) ($dados['motorista_cpf'] ?? ''));
        $cnhValidadeNormalizada = $this->normalizarDataParaComparacao($dados['cnh_validade'] ?? null);
        $crlvAnoNormalizado = (isset($dados['crlv_ano']) && is_numeric($dados['crlv_ano'])) ? (int) $dados['crlv_ano'] : null;
        // UF do CRLV (demanda integracao-talent-portaria-checkin, 2026-09-09)
        // — nunca texto livre no front (dropdown fechado de 27 UFs), mas
        // normalizado defensivamente aqui tambem.
        $crlvUfNormalizada = strtoupper(trim((string) ($dados['crlv_uf'] ?? '')));
        $crlvUfNormalizada = $crlvUfNormalizada !== '' ? $crlvUfNormalizada : null;
        // RNTC/tipo de veiculo (extensao integracao-talent-portaria-checkin,
        // 2026-09-10) — mesmo tratamento de normalizacao/rebaixamento ja
        // aplicado a crlv_uf.
        $crlvRntcNormalizado = trim((string) ($dados['crlv_rntc'] ?? ''));
        $crlvRntcNormalizado = $crlvRntcNormalizado !== '' ? $crlvRntcNormalizado : null;
        $crlvTipoVeiculoNormalizado = trim((string) ($dados['crlv_tipo_veiculo'] ?? ''));
        $crlvTipoVeiculoNormalizado = $crlvTipoVeiculoNormalizado !== '' ? $crlvTipoVeiculoNormalizado : null;
        // 'placa' nao e editavel hoje na tela exp_confirma (so exibida) —
        // se ausente no payload, usa a placa ja gravada do atendimento, o
        // que nunca acusa divergencia por um campo que o front nem envia.
        $placaNormalizada = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($dados['placa'] ?? $atendimento['placa'] ?? '')));

        $cnhOrigem = $atendimento['cnh_origem_validacao'] ?? 'NAO_VALIDADO';
        $cnhStatusRevisao = $atendimento['cnh_status_revisao'] ?? 'OK';

        if (in_array($cnhOrigem, ['VIO_TRIAL', 'VIO_VALIDADO', 'VIO_API_BR'], true)) {
            $snapshotNome = trim((string) ($atendimento['cnh_snapshot_nome'] ?? ''));
            $snapshotCpf = (string) ($atendimento['cnh_snapshot_cpf'] ?? '');
            $snapshotValidade = (string) ($atendimento['cnh_snapshot_validade'] ?? '');

            $cnhDivergiu = $nomeNormalizado !== $snapshotNome
                || $cpfNormalizado !== $snapshotCpf
                || (string) $cnhValidadeNormalizada !== $snapshotValidade;

            if ($cnhDivergiu) {
                $cnhOrigem = 'MANUAL';
                $cnhStatusRevisao = 'PENDENTE_REVISAO';
            }
        }

        $crlvOrigem = $atendimento['crlv_origem_validacao'] ?? 'NAO_VALIDADO';
        $crlvStatusRevisao = $atendimento['crlv_status_revisao'] ?? 'OK';

        if (in_array($crlvOrigem, ['VIO_TRIAL', 'VIO_VALIDADO', 'VIO_API_BR'], true)) {
            $snapshotPlaca = (string) ($atendimento['crlv_snapshot_placa'] ?? '');
            $snapshotExercicio = $atendimento['crlv_snapshot_exercicio'] !== null ? (int) $atendimento['crlv_snapshot_exercicio'] : null;
            $snapshotUf = $atendimento['crlv_snapshot_uf'] !== null ? (string) $atendimento['crlv_snapshot_uf'] : null;
            $snapshotRntc = $atendimento['crlv_snapshot_rntc'] !== null ? (string) $atendimento['crlv_snapshot_rntc'] : null;
            $snapshotTipoVeiculo = $atendimento['crlv_snapshot_tipo_veiculo'] !== null ? (string) $atendimento['crlv_snapshot_tipo_veiculo'] : null;

            // RNTRC que a API NAO trouxe (snapshot NULL/vazio) e preenchido
            // pelo motorista na confirmacao: nao e "edicao de dado validado",
            // entao nao rebaixa a origem (decisao 2026-10-02). Se o snapshot
            // TEM RNTRC, qualquer alteracao continua rebaixando.
            $snapshotRntcVazio = $snapshotRntc === null || trim($snapshotRntc) === '';
            $rntcDivergiu = !$snapshotRntcVazio && $crlvRntcNormalizado !== $snapshotRntc;

            // Mesma regra para o Tipo do veiculo (decisao 2026-10-02).
            $snapshotTipoVazio = $snapshotTipoVeiculo === null || trim($snapshotTipoVeiculo) === '';
            $tipoDivergiu = !$snapshotTipoVazio && $crlvTipoVeiculoNormalizado !== $snapshotTipoVeiculo;

            $crlvDivergiu = $placaNormalizada !== $snapshotPlaca
                || $crlvAnoNormalizado !== $snapshotExercicio
                || $crlvUfNormalizada !== $snapshotUf
                || $rntcDivergiu
                || $tipoDivergiu;

            if ($crlvDivergiu) {
                $crlvOrigem = 'MANUAL';
                $crlvStatusRevisao = 'PENDENTE_REVISAO';
            }
        }

        $this->atendimentoDao->salvarDadosMotoristaComOrigem(
            $idAtendimento,
            $nomeNormalizado,
            $cpfNormalizado,
            $cnhValidadeNormalizada,
            $crlvAnoNormalizado,
            $crlvUfNormalizada,
            $crlvRntcNormalizado,
            $crlvTipoVeiculoNormalizado,
            $cnhOrigem,
            $cnhStatusRevisao,
            $crlvOrigem,
            $crlvStatusRevisao
        );
    }

    /**
     * Campos exibidos na tela de confirmacao (exp_confirma/rec_confirma) e
     * necessarios ao Talent que estao ausentes/invalidos. Decisao de produto
     * 2026-10-02: o RNTRC (que o QR do CRLV nem sempre traz) e OBRIGATORIO
     * aqui. Valores editaveis vem de $dados (o que o motorista confirma);
     * placa, ordem de coleta (Expedicao) e cliente (Recebimento) nao sao
     * editaveis na tela e vem do atendimento. Retorna chave => rotulo fixo
     * (nunca valores).
     *
     * @return array<string,string>
     */
    public function camposObrigatoriosAusentes(array $atendimento, array $dados): array
    {
        $ausentes = [];
        $texto = static fn (mixed $v): string => is_scalar($v) ? trim((string) $v) : '';

        $placa = preg_replace('/[^A-Za-z0-9]/', '', (string) ($atendimento['placa'] ?? ''));
        if ($placa === '') {
            $ausentes['placa'] = 'placa';
        }
        if ($texto($dados['motorista_nome'] ?? null) === '') {
            $ausentes['motorista_nome'] = 'nome do motorista';
        }
        if (\Util\CpfValidador::normalizarEValidar(is_scalar($dados['motorista_cpf'] ?? null) ? (string) $dados['motorista_cpf'] : null) === null) {
            $ausentes['motorista_cpf'] = 'CPF';
        }
        $validadeNorm = $this->normalizarDataParaComparacao(is_scalar($dados['cnh_validade'] ?? null) ? $dados['cnh_validade'] : null);
        if ($validadeNorm === null
            || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $validadeNorm, $m)
            || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            $ausentes['cnh_validade'] = 'validade da CNH';
        }
        $ano = $texto($dados['crlv_ano'] ?? null);
        if (!ctype_digit($ano) || (int) $ano <= 0) {
            $ausentes['crlv_ano'] = 'ano do CRLV';
        }
        if (!in_array(strtoupper($texto($dados['crlv_uf'] ?? null)), DocumentoRn::UFS_VALIDAS, true)) {
            $ausentes['crlv_uf'] = 'UF do CRLV';
        }
        $rntc = $texto($dados['crlv_rntc'] ?? null);
        if ($rntc === '' || strlen($rntc) > 20) {
            $ausentes['crlv_rntc'] = 'RNTRC';
        }
        $tipo = $texto($dados['crlv_tipo_veiculo'] ?? null);
        if ($tipo === '' || strlen($tipo) > 60) {
            $ausentes['crlv_tipo_veiculo'] = 'tipo de veiculo';
        }

        if (($atendimento['tipo'] ?? '') === 'expedicao') {
            if (trim((string) ($atendimento['ordem_coleta'] ?? '')) === '') {
                $ausentes['ordem_coleta'] = 'ordem de coleta';
            }
        } elseif (trim((string) ($atendimento['cliente_nome'] ?? '')) === '') {
            $ausentes['cliente'] = 'cliente';
        }

        return $ausentes;
    }

    /**
     * Normaliza uma data recebida da tela exp_confirma para o formato Y-m-d
     * comparavel contra cnh_snapshot_validade (coluna DATE, PDO retorna
     * 'Y-m-d'). Aceita ISO (Y-m-d) e dd/mm/aaaa; qualquer outro formato e
     * mantido como veio (garante que uma divergencia real seja detectada em
     * vez de silenciosamente comparar null contra null).
     */
    private function normalizarDataParaComparacao(mixed $bruta): ?string
    {
        if ($bruta === null) {
            return null;
        }

        $bruta = trim((string) $bruta);
        if ($bruta === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $bruta)) {
            return $bruta;
        }

        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $bruta, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }

        return $bruta;
    }

    public function salvarCliente(int $idAtendimento, string $nome, ?string $cnpj): void
    {
        $this->atendimentoDao->salvarCliente($idAtendimento, $nome, $cnpj);
    }

    /**
     * Valida/normaliza os dados do ajudante vindos do front. "Sem ajudante" =
     * nome E cpf vazios/null -> ['nome' => null, 'cpf' => null]. Com ajudante:
     * nome nao vazio (<= 150 chars, sem caracteres de controle) E CPF com
     * EXATAMENTE 11 digitos apos remover tudo que nao for digito (com ou sem
     * pontuacao; gravado so com os 11 digitos). Decisao do usuario: o CPF do
     * ajudante NAO e validado pelo digito verificador (diferente do motorista).
     * Qualquer outra combinacao (so um dos dois, tipo nao-string, CPF com
     * menos/mais de 11 digitos) -> null.
     *
     * @return array{nome:?string,cpf:?string}|null
     */
    public function normalizarAjudante(mixed $nome, mixed $cpf): ?array
    {
        if (($nome !== null && !is_string($nome)) || ($cpf !== null && !is_string($cpf))) {
            return null;
        }
        $nomeLimpo = $nome === null ? '' : trim((string) preg_replace('/\s+/u', ' ', $nome));
        $cpfBruto = $cpf === null ? '' : trim($cpf);

        if ($nomeLimpo === '' && $cpfBruto === '') {
            return ['nome' => null, 'cpf' => null];
        }
        if ($nomeLimpo === '' || mb_strlen($nomeLimpo, 'UTF-8') > 150 || preg_match('/[\x00-\x1F\x7F]/', $nomeLimpo) === 1) {
            return null;
        }
        $cpfDigitos = (string) preg_replace('/\D/', '', $cpfBruto);
        if (strlen($cpfDigitos) !== 11) {
            return null;
        }

        return ['nome' => $nomeLimpo, 'cpf' => $cpfDigitos];
    }

    /**
     * Regrava o ajudante so se o atendimento ainda for editavel (em_andamento
     * e check-in NAO_ENVIADO/ERRO_REPROCESSAVEL, checado sob lock no DAO).
     * Retorna false (nada gravado) se o estado nao permitir.
     */
    public function salvarAjudante(int $idAtendimento, ?string $nome, ?string $cpf): bool
    {
        return $this->atendimentoDao->salvarAjudanteSeEditavel($idAtendimento, $nome, $cpf);
    }

    /**
     * Repassa o CAS de App\Dao\AtendimentoDao::cancelar() — true se a
     * transicao aconteceu, false se o atendimento ja estava concluido
     * (nenhuma alteracao no banco nesse caso).
     */
    public function cancelar(int $idAtendimento): bool
    {
        return $this->atendimentoDao->cancelar($idAtendimento);
    }

    /**
     * Repassa o CAS de App\Dao\AtendimentoDao::bloquear() — mesma semantica
     * de cancelar() acima.
     */
    public function bloquear(int $idAtendimento): bool
    {
        return $this->atendimentoDao->bloquear($idAtendimento);
    }

    /**
     * Repassa o CAS dedicado de concluirDigitalizacao() — ver
     * App\Dao\AtendimentoDao::concluirDigitalizacaoNotas().
     */
    public function concluirDigitalizacaoNotas(int $idAtendimento, string $novaEtapa): bool
    {
        return $this->atendimentoDao->concluirDigitalizacaoNotas($idAtendimento, $novaEtapa);
    }

    public function buscar(int $idAtendimento): ?array
    {
        return $this->atendimentoDao->buscarPorId($idAtendimento);
    }

    // Passthroughs de lock/transacao (demanda hardening-revisao-notas-e-
    // cliente, 2026-09-30) -- ver App\Dao\AtendimentoDao.
    public function iniciarTransacao(int $timeoutLockSegundos = 5): void
    {
        $this->atendimentoDao->iniciarTransacao($timeoutLockSegundos);
    }

    public function confirmarTransacao(): void
    {
        $this->atendimentoDao->confirmarTransacao();
    }

    public function desfazerTransacao(): void
    {
        $this->atendimentoDao->desfazerTransacao();
    }

    public function buscarParaUpdate(int $idAtendimento): ?array
    {
        return $this->atendimentoDao->buscarPorIdParaUpdate($idAtendimento);
    }

    public function gravarClienteAutomaticoSeVazio(int $idAtendimento, string $nome, string $cnpj): bool
    {
        return $this->atendimentoDao->gravarClienteAutomaticoSeVazio($idAtendimento, $nome, $cnpj);
    }
}
