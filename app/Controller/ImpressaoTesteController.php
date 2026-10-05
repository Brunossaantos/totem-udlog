<?php

namespace App\Controller;

use FPDF;
use Util\ConfiguracaoServicoImpressao;
use Util\EtiquetaLayout;
use Util\Resposta;
use Util\TextoEtiqueta;

/**
 * Endpoint ISOLADO de diagnostico (demanda impressao-etiqueta-teste,
 * 2026-09-11/2026-09-14) — gera uma etiqueta de TESTE em PDF real (FPDF) e
 * devolve os dados de conexao do servico local de impressao (mini PC
 * Windows, fora do escopo Hostgator). NUNCA instancia
 * AtendimentoRn/TalentRn/TalentClient/OrdemColetaClient, NUNCA consulta ou
 * altera tb_atendimento/status/etapa real — arquivo proprio, sem nenhuma
 * dependencia de fluxo de atendimento.
 *
 * Dimensao/orientacao/corte da etiqueta SEMPRE lidas de $_ENV (nunca
 * hardcoded no codigo), conforme decisao do usuario em 2026-09-14:
 * ETIQUETA_LARGURA_MM / ETIQUETA_COMPRIMENTO_MM / ETIQUETA_ORIENTACAO /
 * ETIQUETA_CORTE_APOS_IMPRESSAO.
 *
 * O conteudo do PDF gerado NUNCA contem dado pessoal (sem CPF/CNH/placa/
 * nome) — so o texto fixo de aviso "ETIQUETA DE TESTE - NAO UTILIZAR" e
 * metadados tecnicos de diagnostico (identificador, timestamp).
 */
class ImpressaoTesteController
{
    /**
     * Gera a etiqueta de teste (PDF real via FPDF) usando as dimensoes
     * configuradas em .env e devolve em base64, junto com um identificador
     * unico de idempotencia para esta geracao especifica.
     */
    public function gerarEtiqueta(): void
    {
        $config = $this->lerConfiguracaoEtiqueta();
        $corpo = $this->lerCorpoJson();
        $config = $this->aplicarSobreposicao($config, $corpo);

        // Destinatario (allowlist estrita): motorista (padrao) ou ajudante.
        $destinatario = 'motorista';
        if (array_key_exists('destinatario', $corpo) && $corpo['destinatario'] !== null && $corpo['destinatario'] !== '') {
            if (!is_string($corpo['destinatario']) || !in_array($corpo['destinatario'], ['motorista', 'ajudante'], true)) {
                Resposta::erro('destinatario invalido: use motorista ou ajudante', 422);
                return;
            }
            $destinatario = $corpo['destinatario'];
        }

        $identificador = bin2hex(random_bytes(16));

        try {
            $pdfBytes = $this->montarPdf($config, $identificador, $destinatario);
        } catch (\Throwable $e) {
            error_log('impressao-teste gerar-etiqueta: falha ao gerar PDF: ' . get_class($e));
            Resposta::erro('Nao foi possivel gerar a etiqueta de teste agora', 500);
            return;
        }

        Resposta::sucesso([
            'pdf_base64' => base64_encode($pdfBytes),
            'identificador' => $identificador,
            'largura_mm' => $config['largura_mm'],
            'comprimento_mm' => $config['comprimento_mm'],
            'orientacao' => $config['orientacao'],
            'corte_apos_impressao' => $config['corte_apos_impressao'],
            'pagina_gerada_mm' => $this->extrairMediaBoxMm($pdfBytes),
            'destinatario' => $destinatario,
        ]);
    }

    /**
     * Devolve os valores atuais do .env (so leitura) para pre-preencher os
     * controles da pagina de teste. Sem dado pessoal.
     */
    public function configuracaoEtiqueta(): void
    {
        header('Cache-Control: no-store');
        Resposta::sucesso($this->lerConfiguracaoEtiqueta());
    }

    /**
     * Devolve a etiqueta pronta docs/50x80.pdf (caminho FIXO, nenhum nome de
     * arquivo vem do request) em base64, para a pagina imprimir pelo mesmo
     * POST /imprimir do servico local. Rota de diagnostico: exige o token do totem, le o caminho fixo docs/50x80.pdf (nao existe no deploy; 404 esperado) e deve ser avaliada para exclusao no deploy final.
     */
    public function etiquetaPronta(): void
    {
        header('Cache-Control: no-store');
        $caminho = __DIR__ . '/../../docs/50x80.pdf';

        if (!is_file($caminho) || !is_readable($caminho)) {
            Resposta::erro('Arquivo docs/50x80.pdf nao encontrado no servidor', 404);
            return;
        }
        $bytes = file_get_contents($caminho);
        if ($bytes === false || strncmp($bytes, '%PDF-', 5) !== 0) {
            Resposta::erro('Arquivo docs/50x80.pdf invalido', 500);
            return;
        }

        Resposta::sucesso([
            'pdf_base64' => base64_encode($bytes),
            'identificador' => bin2hex(random_bytes(16)),
            'origem' => 'docs/50x80.pdf',
            'pagina_gerada_mm' => $this->extrairMediaBoxMm($bytes),
        ]);
    }

    /** Corpo JSON opcional (somente objeto); qualquer outra coisa vira []. */
    private function lerCorpoJson(): array
    {
        $bruto = file_get_contents('php://input');
        if ($bruto === false || trim($bruto) === '') {
            return [];
        }
        $json = json_decode($bruto, true);
        return is_array($json) ? $json : [];
    }

    /**
     * Sobrepoe o .env SOMENTE nesta requisicao. Validacao estrita: numerico,
     * 20-300 mm, orientacao em allowlist. Valor invalido => 422.
     */
    private function aplicarSobreposicao(array $config, array $corpo): array
    {
        foreach (['largura_mm', 'comprimento_mm'] as $campo) {
            if (!array_key_exists($campo, $corpo) || $corpo[$campo] === null || $corpo[$campo] === '') {
                continue;
            }
            $v = $corpo[$campo];
            if ((!is_int($v) && !is_float($v) && !(is_string($v) && is_numeric($v))) || !is_finite((float) $v)
                || (float) $v < 20 || (float) $v > 300) {
                Resposta::erro($campo . ' invalido: informe um numero entre 20 e 300 mm', 422);
            }
            $config[$campo] = (float) $v;
        }
        if (array_key_exists('orientacao', $corpo) && $corpo['orientacao'] !== null && $corpo['orientacao'] !== '') {
            if (!is_string($corpo['orientacao']) || !in_array($corpo['orientacao'], ['portrait', 'landscape'], true)) {
                Resposta::erro('orientacao invalida: use portrait ou landscape', 422);
            }
            $config['orientacao'] = $corpo['orientacao'];
        }
        return $config;
    }

    /** MediaBox real (mm) lido do PDF; null se nao for localizavel em texto claro. */
    private function extrairMediaBoxMm(string $pdf): ?array
    {
        if (!preg_match('/\/MediaBox\s*\[\s*([\d.\-]+)\s+([\d.\-]+)\s+([\d.\-]+)\s+([\d.\-]+)\s*\]/', $pdf, $m)) {
            return null;
        }
        $k = 25.4 / 72;
        return [
            'largura' => round(((float) $m[3] - (float) $m[1]) * $k, 1),
            'altura' => round(((float) $m[4] - (float) $m[2]) * $k, 1),
        ];
    }

    /**
     * Devolve URL/token do servico local de impressao (mini PC Windows) so
     * depois de validar o token do totem — NUNCA exposto em arquivo JS
     * estatico versionado. Rota separada da geracao do PDF para permitir
     * que o front-end resolva a configuracao de conexao uma unica vez por
     * sessao, independente de quantas etiquetas gerar.
     */
    public function configuracaoServicoLocal(): void
    {
        header('Cache-Control: no-store');

        try {
            $config = ConfiguracaoServicoImpressao::obter();
        } catch (\RuntimeException $e) {
            error_log('impressao-teste configuracao-servico-local: ' . get_class($e));
            Resposta::erro('Servico local de impressao nao configurado', 503);
            return;
        }

        Resposta::sucesso($config);
    }

    /**
     * Le e valida a configuracao de etiqueta do .env. Fail-closed: qualquer
     * valor ausente/invalido interrompe a requisicao com erro, nunca assume
     * um default silencioso (mesmo padrao de VIO_AMBIENTE em outras rotas).
     */
    private function lerConfiguracaoEtiqueta(): array
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
            error_log('impressao-teste: configuracao de etiqueta ausente/invalida no .env (ETIQUETA_LARGURA_MM/ETIQUETA_COMPRIMENTO_MM/ETIQUETA_ORIENTACAO/ETIQUETA_CORTE_APOS_IMPRESSAO)');
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
     * Monta o PDF de teste com FPDF: pagina no tamanho exato configurado
     * (largura x comprimento em mm), orientacao configurada, sem nenhum
     * dado pessoal — so o aviso fixo e metadados tecnicos de diagnostico.
     */
    private function montarPdf(array $config, string $identificador, string $destinatario = 'motorista'): string
    {
        $identificador = TextoEtiqueta::paraAscii($identificador);
        $orientacaoFpdf = $config['orientacao'] === 'landscape' ? 'L' : 'P';

        $pdf = new FPDF($orientacaoFpdf, 'mm', [$config['largura_mm'], $config['comprimento_mm']]);
        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(2, 2, 2);
        $pdf->AddPage();

        // Mesmo layout da etiqueta real (Util\EtiquetaLayout), texto de teste.
        if ($destinatario === 'ajudante') {
            // Equivalente da etiqueta do ajudante: nome e nrRegAcesso ficticios.
            EtiquetaLayout::desenhar($pdf, [
                ['texto' => 'AJUDANTE', 'estilo' => 'B', 'pt' => 22, 'max_linhas' => 1, 'espaco_antes_mm' => 0],
                ['texto' => TextoEtiqueta::paraAscii('TESTE FICTICIO'), 'estilo' => '', 'pt' => 15, 'max_linhas' => 3, 'espaco_antes_mm' => 2],
                ['texto' => TextoEtiqueta::paraAscii('123456'), 'estilo' => 'B', 'pt' => 60, 'max_linhas' => 1, 'espaco_antes_mm' => 3],
                ['texto' => 'ETIQUETA DE TESTE - NAO UTILIZAR', 'estilo' => 'B', 'pt' => 9, 'max_linhas' => 2, 'espaco_antes_mm' => 2],
                ['texto' => 'ID: ' . $identificador, 'estilo' => '', 'pt' => 9, 'max_linhas' => 1, 'espaco_antes_mm' => 1],
                ['texto' => 'Gerado em: ' . date('Y-m-d H:i:s'), 'estilo' => '', 'pt' => 9, 'max_linhas' => 1, 'espaco_antes_mm' => 1],
            ]);
            return $pdf->Output('S');
        }

        EtiquetaLayout::desenhar($pdf, [
            ['texto' => 'ETIQUETA DE TESTE', 'estilo' => 'B', 'pt' => 22, 'max_linhas' => 2, 'espaco_antes_mm' => 0],
            ['texto' => 'NAO UTILIZAR', 'estilo' => 'B', 'pt' => 18, 'max_linhas' => 1, 'espaco_antes_mm' => 1],
            ['texto' => 'Diagnostico de impressao - sem dado pessoal', 'estilo' => '', 'pt' => 10, 'max_linhas' => 2, 'espaco_antes_mm' => 3],
            ['texto' => 'ID: ' . $identificador, 'estilo' => '', 'pt' => 9, 'max_linhas' => 1, 'espaco_antes_mm' => 2],
            ['texto' => 'Gerado em: ' . date('Y-m-d H:i:s'), 'estilo' => '', 'pt' => 9, 'max_linhas' => 1, 'espaco_antes_mm' => 1],
        ]);

        return $pdf->Output('S');
    }
}
