<?php

namespace App\Rn;

use App\Dao\CaminhoJaRegistradoException;
use App\Dao\OrdemColetaArquivoDao;
use Util\OrdemColetaArquivoStorage;

/**
 * Regra de negocio do anexo (PDF) da Ordem de Coleta enviado pelo n8n
 * (demanda anexo-ordem-coleta-n8n, 2026-10-05). Escopo: Expedicao.
 *
 * O servidor valida SO o minimo (a validacao pesada do PDF e do n8n):
 * campos, base64 estrito, tamanho decodificado <= limite e assinatura %PDF-.
 *
 * Retorno de receber(): array com 'http', e
 *  - sucesso (200/201): 'dados' => {status, tamanho_bytes, sha256};
 *  - erro: 'codigo' (estavel) e 'mensagem' (fixa), opcional 'dados'.
 * Nunca ecoa entrada, caminho ou mensagem de excecao; logs so com codigos
 * fixos e get_class.
 */
class OrdemColetaArquivoRn
{
    public const LIMITE_PADRAO_BYTES = 5242880;

    /** Lote fixo da retencao. */
    public const LOTE_RETENCAO = 200;

    public const DIAS_RETENCAO = 15;

    /** Voltas de gravacao+registro quando o NOME colide com outra linha. */
    private const MAX_TENTATIVAS_NOME = 3;

    public function __construct(
        private OrdemColetaArquivoDao $dao,
        private OrdemColetaArquivoStorage $storage,
        private int $limiteBytes = self::LIMITE_PADRAO_BYTES
    ) {}

    public static function limiteDoAmbiente(?string $valor): int
    {
        if ($valor !== null && preg_match('/\A[1-9]\d{0,9}\z/D', $valor) === 1) {
            return (int) $valor;
        }

        return self::LIMITE_PADRAO_BYTES;
    }

    /** Teto do corpo HTTP bruto (JSON + base64): 1,5 x o limite do arquivo. */
    public static function tetoCorpo(int $limiteBytes): int
    {
        return intdiv($limiteBytes * 3, 2);
    }

    /** @return array{http:int, codigo?:string, mensagem?:string, dados?:array} */
    public function receber(mixed $entrada): array
    {
        if (!is_array($entrada)) {
            $entrada = [];
        }

        $cnpj = $entrada['cnpj_cliente'] ?? null;
        if (!is_string($cnpj) || preg_match('/\A\d{14}\z/D', $cnpj) !== 1) {
            return self::erro(400, 'CAMPO_INVALIDO', 'Campo invalido ou ausente.', ['campo' => 'cnpj_cliente']);
        }

        $numero = self::normalizarNumero($entrada['numero_ordem_coleta'] ?? null);
        if ($numero === null) {
            return self::erro(400, 'CAMPO_INVALIDO', 'Campo invalido ou ausente.', ['campo' => 'numero_ordem_coleta']);
        }

        $base64 = $entrada['arquivo_base64'] ?? null;
        if (!is_string($base64) || $base64 === '') {
            return self::erro(400, 'CAMPO_INVALIDO', 'Campo invalido ou ausente.', ['campo' => 'arquivo_base64']);
        }

        $tamanhoBase64 = strlen($base64);
        if ($tamanhoBase64 % 4 !== 0) {
            return self::erro(400, 'CAMPO_INVALIDO', 'Campo invalido ou ausente.', ['campo' => 'arquivo_base64']);
        }
        // limite barato ANTES de decodificar (nao aloca o binario de um corpo enorme)
        if (intdiv($tamanhoBase64, 4) * 3 > $this->limiteBytes + 3) {
            return self::erro(413, 'ARQUIVO_MUITO_GRANDE', 'Arquivo acima do limite permitido.');
        }

        $bytes = self::decodificarBase64Estrito($base64);
        unset($entrada, $base64);
        if ($bytes === null || $bytes === '') {
            return self::erro(400, 'CAMPO_INVALIDO', 'Campo invalido ou ausente.', ['campo' => 'arquivo_base64']);
        }
        $tamanho = strlen($bytes);
        if ($tamanho > $this->limiteBytes) {
            return self::erro(413, 'ARQUIVO_MUITO_GRANDE', 'Arquivo acima do limite permitido.');
        }
        if (!str_starts_with($bytes, '%PDF-')) {
            return self::erro(422, 'FORMATO_NAO_SUPORTADO', 'O arquivo enviado nao e um PDF.');
        }

        $sha256 = hash('sha256', $bytes);

        // Duplicado idempotente: mesmo conteudo ja registrado E arquivo ainda
        // presente com o mesmo tamanho (se o arquivo sumiu, regrava abaixo).
        try {
            $atual = $this->dao->buscarPorChave($cnpj, $numero);
        } catch (\Throwable $e) {
            return $this->falhaBanco($e, 'consulta_chave');
        }
        if ($atual !== null
            && hash_equals($atual['sha256'], $sha256)
            && $atual['tamanho_bytes'] === $tamanho
            && $this->storage->existeComTamanho($atual['caminho_relativo'], $tamanho)) {
            return ['http' => 200, 'dados' => ['status' => 'duplicado', 'tamanho_bytes' => $tamanho, 'sha256' => $sha256]];
        }

        // 1) publica o arquivo novo (nome exclusivo)  2) transacao  3) COMMIT
        // 4) apaga o antigo. 1062 em uk caminho_relativo = colisao de NOME com
        // outra linha: gera outro nome (o arquivo existente NUNCA e apagado).
        $reservado = function (string $relativo): bool {
            try {
                return $this->dao->caminhoRegistrado($relativo);
            } catch (\Throwable $e) {
                return false; // a publicacao exclusiva e a garantia real
            }
        };
        $resultado = null;
        $novo = null;
        for ($tentativa = 0; $tentativa < self::MAX_TENTATIVAS_NOME; $tentativa++) {
            try {
                $novo = $this->storage->gravar($cnpj, $numero, $bytes, $reservado);
            } catch (\Throwable $e) {
                error_log('OrdemColetaArquivoRn: storage_gravacao_falhou ' . get_class($e));

                return self::erro(500, 'ERRO_INTERNO', 'Erro interno ao processar o arquivo.');
            }

            try {
                $resultado = $this->dao->substituir($cnpj, $numero, $novo, $tamanho, $sha256);
                break;
            } catch (CaminhoJaRegistradoException $e) {
                // o caminho pertence a OUTRA linha: nao apagar; novo nome na proxima volta
                error_log('OrdemColetaArquivoRn: caminho_ja_registrado');
                $novo = null;
            } catch (\Throwable $e) {
                // a transacao falhou: o registro antigo (e o arquivo antigo) ficam
                // intactos; o arquivo novo (criado por esta chamada) sai SO se
                // nenhuma linha o referencia.
                $this->descartarArquivoNovo($novo);

                return $this->falhaBanco($e, 'gravacao_registro');
            }
        }
        unset($bytes);
        if ($resultado === null) {
            error_log('OrdemColetaArquivoRn: nome_indisponivel');

            return self::erro(503, 'INDISPONIVEL', 'Servico temporariamente indisponivel.');
        }

        $anterior = $resultado['anterior'];
        if ($anterior !== null && $anterior !== $novo) {
            $r = $this->storage->remover($anterior);
            if ($r === 'falha' || $r === 'invalido') {
                error_log('OrdemColetaArquivoRn: arquivo_antigo_nao_removido');
            }
        }

        return [
            'http' => 201,
            'dados' => [
                'status' => $anterior === null ? 'criado' : 'atualizado',
                'tamanho_bytes' => $tamanho,
                'sha256' => $sha256,
            ],
        ];
    }

    /**
     * Retencao diaria (cron). Para cada linha elegivel: valida o caminho
     * (regex/realpath/sem symlink) -> unlink (ausente = ok) -> DELETE da linha.
     * Falha de unlink ou caminho invalido MANTEM a linha (contada em
     * 'falhas'). Devolve so contagens.
     *
     * @return array{elegiveis:int, apagados:int, ausentes:int, falhas:int}
     */
    public function limparExpirados(int $lote = self::LOTE_RETENCAO, int $dias = self::DIAS_RETENCAO): array
    {
        $contagem = ['elegiveis' => 0, 'apagados' => 0, 'ausentes' => 0, 'falhas' => 0];

        $linhas = $this->dao->listarExpirados($lote, $dias);
        $contagem['elegiveis'] = count($linhas);

        foreach ($linhas as $linha) {
            $r = $this->storage->remover($linha['caminho_relativo']);
            if ($r !== 'removido' && $r !== 'ausente') {
                $contagem['falhas']++;
                continue;
            }
            try {
                $this->dao->excluirPorIdECaminho($linha['id'], $linha['caminho_relativo']);
            } catch (\Throwable $e) {
                $contagem['falhas']++;
                continue;
            }
            $contagem[$r === 'removido' ? 'apagados' : 'ausentes']++;
        }

        return $contagem;
    }

    /** Numero da OC: string UTF-8 valida, 1-50 caracteres apos trim, sem controles/NUL. */
    public static function normalizarNumero(mixed $numero): ?string
    {
        if (!is_string($numero) || str_contains($numero, "\0")) {
            return null;
        }
        $numero = trim($numero);
        if ($numero === '' || preg_match('//u', $numero) !== 1) {
            return null;
        }
        if (preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $numero) === 1) {
            return null;
        }
        if (strlen($numero) > 200 || preg_match_all('/./us', $numero) > 50) {
            return null;
        }

        return $numero;
    }

    /** Base64 estrito (alfabeto padrao, padding correto, sem espacos nem prefixo data:). */
    public static function decodificarBase64Estrito(string $base64): ?string
    {
        $tamanho = strlen($base64);
        if ($tamanho === 0 || $tamanho % 4 !== 0) {
            return null;
        }
        $semPadding = rtrim($base64, '=');
        if ($tamanho - strlen($semPadding) > 2) {
            return null;
        }
        if (strspn($semPadding, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/') !== strlen($semPadding)) {
            return null;
        }
        $bytes = base64_decode($base64, true);

        return $bytes === false ? null : $bytes;
    }

    /** Remove o arquivo novo SO se nenhuma linha o referencia (em duvida, mantem). */
    private function descartarArquivoNovo(string $novo): void
    {
        try {
            if ($this->dao->caminhoRegistrado($novo)) {
                return;
            }
        } catch (\Throwable $e) {
            error_log('OrdemColetaArquivoRn: arquivo_novo_mantido_por_duvida');

            return;
        }
        $r = $this->storage->remover($novo);
        if ($r === 'falha' || $r === 'invalido') {
            error_log('OrdemColetaArquivoRn: arquivo_novo_nao_removido');
        }
    }

    private function falhaBanco(\Throwable $e, string $etapa): array
    {
        error_log('OrdemColetaArquivoRn: banco_falhou etapa=' . $etapa . ' ' . get_class($e));

        return self::erro(503, 'INDISPONIVEL', 'Servico temporariamente indisponivel.');
    }

    private static function erro(int $http, string $codigo, string $mensagem, array $dados = []): array
    {
        return ['http' => $http, 'codigo' => $codigo, 'mensagem' => $mensagem, 'dados' => $dados];
    }
}
