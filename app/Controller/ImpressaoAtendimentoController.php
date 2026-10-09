<?php

namespace App\Controller;

use FPDF;
use App\Dao\AtendimentoDao;
use Util\ConfiguracaoServicoImpressao;
use Util\EtiquetaLayout;
use Util\LogSistema;
use Util\Resposta;
use Util\TextoEtiqueta;

/**
 * Endpoint de impressao REAL (demanda talent-doctos-finalizacao-checkin,
 * 2026-09-14) — separado e ISOLADO de App\Controller\ImpressaoTesteController
 * (nunca reaproveitado por ele, nem o contrario). Gera a etiqueta final com
 * nome do motorista + nrRegAcesso (persistido como tb_atendimento.talent_senha)
 * — SO LE dados ja persistidos, NUNCA dispara/redispara chamada ao Talent
 * (achado do security-especialista no planejamento: risco de caminho
 * paralelo de envio).
 *
 * Entrada aceita SOMENTE id_atendimento (+ flag de reimpressao) — nome do
 * motorista e nrRegAcesso SEMPRE lidos do banco via buscarAtendimentoDoTotem
 * (mesmo padrao de posse/tipo/status/etapa de
 * App\Controller\AtendimentoController::finalizar()), NUNCA aceitos do corpo
 * da requisicao (evita forjar senha impressa).
 *
 * NUNCA inclui CPF/CNH na etiqueta — so nome do motorista + nrRegAcesso. O
 * rotulo de UI ("Numero de acesso" vs. outro texto) e responsabilidade do
 * front-end — este controller so devolve o dado bruto.
 */
class ImpressaoAtendimentoController
{
    public function __construct(private AtendimentoDao $atendimentoDao) {}

    /**
     * Flag de reimpressao (so para log): aceita `reimpressao` na query OU no
     * corpo JSON, com valor verdadeiro `1`, `true` ou `'1'`.
     */
    public static function flagReimpressao(array $query, array $entrada): bool
    {
        foreach ([$query['reimpressao'] ?? null, $entrada['reimpressao'] ?? null] as $valor) {
            if ($valor === 1 || $valor === true || $valor === '1') {
                return true;
            }
        }

        return false;
    }

    /**
     * Devolve URL/token do servico local de impressao (mini PC Windows) para
     * o fluxo REAL de impressao — equivalente de producao da rota exclusiva
     * de diagnostico App\Controller\ImpressaoTesteController::configuracaoServicoLocal()
     * (impressao-teste.php), criada originalmente so para a tela de
     * diagnostico e ate 2026-09-15 reaproveitada indevidamente pelo front-end
     * de producao (achado do planejamento impressao-arquitetura-producao-ux).
     *
     * NAO recebe id_atendimento nem nenhum parametro: e configuracao de
     * totem/ambiente, nao de atendimento especifico. Autenticacao
     * (Util\Auth::validarTotem) ja e a primeira linha executada em
     * public/api/impressao.php, antes de qualquer chamada a este metodo.
     */
    public function configuracaoServicoLocal(): void
    {
        header('Cache-Control: no-store');

        try {
            $config = ConfiguracaoServicoImpressao::obter();
        } catch (\RuntimeException $e) {
            error_log('impressao configuracao-servico-local: ' . get_class($e));
            LogSistema::registrar('impressao_config_falhou', ['excecao' => $e, 'motivo' => 'config_invalida']);
            Resposta::erro('Servico local de impressao nao configurado', 503);
            return;
        }

        Resposta::sucesso($config);
    }

    /**
     * Reimpressao manual (nunca automatica) sempre gera um NOVO
     * `identificador` de job — cada chamada e logicamente um novo job de
     * impressao, mas sempre do MESMO nrRegAcesso ja persistido (nunca gera
     * um novo check-in, nunca chama o Talent).
     */
    public function gerarEtiqueta(array $entrada, int $idTotem, bool $reimpressao): void
    {
        $idAtendimento = (int) ($entrada['id_atendimento'] ?? 0);
        if (!$idAtendimento) {
            Resposta::erro('Dados incompletos');
            return;
        }

        // Destinatario da etiqueta (allowlist): motorista (padrao) ou ajudante.
        $destinatario = 'motorista';
        if (array_key_exists('destinatario', $entrada) && $entrada['destinatario'] !== null && $entrada['destinatario'] !== '') {
            $destinatario = $entrada['destinatario'];
            if (!is_string($destinatario) || !in_array($destinatario, ['motorista', 'ajudante'], true)) {
                Resposta::erro('Destinatario invalido: use motorista ou ajudante', 422);
                return;
            }
        }

        $atendimento = $this->buscarAtendimentoDoTotem($idAtendimento, $idTotem);

        if (
            $atendimento['status'] !== 'concluido'
            || $atendimento['talent_checkin_status'] !== 'ENVIADO'
            || empty($atendimento['talent_senha'])
        ) {
            Resposta::erro('Check-in ainda nao confirmado para este atendimento — nao ha etiqueta para imprimir', 409);
            return;
        }

        $nomeMotorista = TextoEtiqueta::paraAscii((string) ($atendimento['motorista_nome'] ?? ''));
        $nrRegAcesso = TextoEtiqueta::paraAscii((string) $atendimento['talent_senha']);
        if ($nrRegAcesso === '' || ($destinatario === 'motorista' && $nomeMotorista === '')) {
            Resposta::erro('Dados da etiqueta incompletos — procure um atendente', 500);
            return;
        }

        // Ajudante (opcional): so o NOME, nunca o CPF. Lido do mesmo registro
        // ja carregado (SELECT * por id, prepared statement no DAO).
        $nomeAjudante = '';
        if ((int) ($atendimento['possui_ajudante'] ?? 0) === 1) {
            $nomeAjudante = $this->sanitizarNomeAjudante($atendimento['ajudante_nome'] ?? '');
        }

        if ($destinatario === 'ajudante' && $nomeAjudante === '') {
            Resposta::erro('Este atendimento nao possui ajudante — nao ha etiqueta de ajudante para imprimir', 409);
            return;
        }

        $config = $this->lerConfiguracaoEtiqueta($idAtendimento, $idTotem, (string) $atendimento['tipo']);

        // Identificador SEMPRE novo — nunca reaproveita um job de impressao
        // anterior, mesmo em reimpressao (cada chamada e um novo job,
        // sempre do mesmo nrRegAcesso ja persistido).
        $identificador = bin2hex(random_bytes(16));

        try {
            $pdfBytes = $this->montarPdf($config, $nomeMotorista, $nrRegAcesso, $nomeAjudante, $destinatario);
        } catch (\Throwable $e) {
            error_log('impressao gerar-etiqueta: falha ao gerar PDF: ' . get_class($e));
            LogSistema::registrar('etiqueta_pdf_falhou', ['id_atendimento' => $idAtendimento, 'id_totem' => $idTotem, 'tipo' => (string) $atendimento['tipo'], 'excecao' => $e]);
            Resposta::erro('Nao foi possivel gerar a etiqueta agora', 500);
            return;
        }

        // Auditoria simples (log estruturado) — id_atendimento, timestamp,
        // se e reimpressao. Sem dado pessoal alem do proprio id_atendimento
        // (ja e um identificador interno, nao dado pessoal em si).
        error_log(sprintf(
            'impressao gerar-etiqueta: id_atendimento=%d destinatario=%s reimpressao=%s timestamp=%s',
            $idAtendimento,
            $destinatario,
            $reimpressao ? 'sim' : 'nao',
            date('Y-m-d H:i:s')
        ));

        $resposta = [
            'pdf_base64' => base64_encode($pdfBytes),
            'identificador' => $identificador,
            'largura_mm' => $config['largura_mm'],
            'comprimento_mm' => $config['comprimento_mm'],
            'orientacao' => $config['orientacao'],
            'corte_apos_impressao' => $config['corte_apos_impressao'],
            'destinatario' => $destinatario,
        ];
        if ($destinatario === 'motorista') {
            // Aditivo: ha uma segunda etiqueta (ajudante) para imprimir.
            $resposta['tem_etiqueta_ajudante'] = $nomeAjudante !== '';
        }

        Resposta::sucesso($resposta);
    }

    /**
     * Mesmo padrao de posse (IDOR) ja usado em
     * App\Controller\AtendimentoController::buscarAtendimentoDoTotem —
     * mensagem generica em qualquer caso de falha, nao revela existencia de
     * atendimento alheio.
     */
    private function buscarAtendimentoDoTotem(int $idAtendimento, int $idTotem): array
    {
        $atendimento = $this->atendimentoDao->buscarPorId($idAtendimento);
        if (!$atendimento || (int) $atendimento['id_totem'] !== $idTotem) {
            Resposta::erro('Atendimento nao encontrado', 404);
        }

        return $atendimento;
    }

    /**
     * Le e valida a configuracao de etiqueta do .env — mesmas 4 variaveis
     * ja usadas por App\Controller\ImpressaoTesteController (ETIQUETA_*),
     * fail-closed (nunca assume default silencioso). Duplicado
     * deliberadamente aqui (em vez de reaproveitar ImpressaoTesteController
     * diretamente, que e isolado por design) — leitura de config e simples
     * o bastante para nao justificar acoplamento entre os dois controllers.
     */
    private function lerConfiguracaoEtiqueta(int $idAtendimento, int $idTotem, string $tipo): array
    {
        $largura = $_ENV['ETIQUETA_LARGURA_MM'] ?? '';
        $comprimento = $_ENV['ETIQUETA_COMPRIMENTO_MM'] ?? '';
        $orientacao = $_ENV['ETIQUETA_ORIENTACAO'] ?? '';
        $corte = $_ENV['ETIQUETA_CORTE_APOS_IMPRESSAO'] ?? '';

        if (
            !is_numeric($largura) || (float) $largura <= 0
            || !is_numeric($comprimento) || (float) $comprimento <= 0
            || !in_array($orientacao, ['portrait', 'landscape'], true)
            || !in_array(strtolower((string) $corte), ['true', 'false'], true)
        ) {
            error_log('impressao gerar-etiqueta: configuracao de etiqueta ausente/invalida no .env (ETIQUETA_LARGURA_MM/ETIQUETA_COMPRIMENTO_MM/ETIQUETA_ORIENTACAO/ETIQUETA_CORTE_APOS_IMPRESSAO)');
            LogSistema::registrar('etiqueta_config_invalida', ['id_atendimento' => $idAtendimento, 'id_totem' => $idTotem, 'tipo' => $tipo, 'motivo' => 'config_invalida']);
            Resposta::erro('Configuracao de etiqueta ausente ou invalida', 500);
        }

        return [
            'largura_mm' => (float) $largura,
            'comprimento_mm' => (float) $comprimento,
            'orientacao' => $orientacao,
            'corte_apos_impressao' => strtolower((string) $corte) === 'true',
        ];
    }

    /**
     * Monta o PDF final: so nome do motorista + nrRegAcesso, SEM rotulo fixo
     * de "senha"/"numero de acesso" hardcoded (texto de UI e decisao do
     * front-end para a TELA; aqui usamos um rotulo tecnico neutro so para
     * legibilidade da etiqueta impressa em si). NUNCA CPF/CNH/placa.
     */
    /**
     * Nome do ajudante para a fonte core do FPDF: transliterado para ASCII
     * (Util\TextoEtiqueta::paraAscii: sem acentos, sem controles, espacos
     * normalizados) e limitado a 150 caracteres (tamanho da coluna). Vazio => sem bloco de ajudante.
     */
    private function sanitizarNomeAjudante(mixed $nome): string
    {
        if (!is_string($nome)) {
            return '';
        }
        // ASCII puro (sem acentos), sem controles, espacos normalizados, 150 chars.
        return TextoEtiqueta::paraAscii($nome, 150);
    }

    private function montarPdf(array $config, string $nomeMotorista, string $nrRegAcesso, string $nomeAjudante = '', string $destinatario = 'motorista'): string
    {
        // Todo texto variavel impresso passa por paraAscii (idempotente).
        $nomeMotorista = TextoEtiqueta::paraAscii($nomeMotorista);
        $nrRegAcesso = TextoEtiqueta::paraAscii($nrRegAcesso);
        $nomeAjudante = TextoEtiqueta::paraAscii($nomeAjudante, 150);

        $orientacaoFpdf = $config['orientacao'] === 'landscape' ? 'L' : 'P';

        $pdf = new FPDF($orientacaoFpdf, 'mm', [$config['largura_mm'], $config['comprimento_mm']]);
        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(2, 2, 2);
        $pdf->AddPage();

        // Layout (topo, fontes grandes, ajuste automatico a largura/altura da
        // pagina) centralizado em Util\EtiquetaLayout — so conteudo muda aqui.
        if ($destinatario === 'ajudante') {
            // Etiqueta do ajudante (papel separado): "AJUDANTE" no lugar de
            // "UDLOG", nome, mesmo nrRegAcesso do motorista e "Gerado em".
            EtiquetaLayout::desenhar($pdf, [
                ['texto' => 'AJUDANTE', 'estilo' => 'B', 'pt' => 22, 'max_linhas' => 1, 'espaco_antes_mm' => 0],
                ['texto' => $nomeAjudante, 'estilo' => '', 'pt' => 15, 'max_linhas' => 3, 'espaco_antes_mm' => 2],
                ['texto' => $nrRegAcesso, 'estilo' => 'B', 'pt' => 60, 'max_linhas' => 1, 'espaco_antes_mm' => 3],
                ['texto' => 'Gerado em: ' . date('Y-m-d H:i:s'), 'estilo' => '', 'pt' => 9, 'max_linhas' => 1, 'espaco_antes_mm' => 3],
            ]);
            return $pdf->Output('S');
        }

        // Etiqueta do MOTORISTA: nunca desenha o nome do ajudante (ele tem
        // etiqueta propria; $nomeAjudante e ignorado aqui).
        EtiquetaLayout::desenhar($pdf, [
            ['texto' => 'UDLOG', 'estilo' => 'B', 'pt' => 22, 'max_linhas' => 1, 'espaco_antes_mm' => 0],
            ['texto' => $nomeMotorista, 'estilo' => '', 'pt' => 15, 'max_linhas' => 3, 'espaco_antes_mm' => 2],
            ['texto' => $nrRegAcesso, 'estilo' => 'B', 'pt' => 60, 'max_linhas' => 1, 'espaco_antes_mm' => 3],
            ['texto' => 'Gerado em: ' . date('Y-m-d H:i:s'), 'estilo' => '', 'pt' => 9, 'max_linhas' => 1, 'espaco_antes_mm' => 3],
        ]);

        return $pdf->Output('S');
    }
}
