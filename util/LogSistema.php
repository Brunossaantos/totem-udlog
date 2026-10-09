<?php

namespace Util;

use App\Dao\LogSistemaDao;
use Throwable;

/**
 * Log central do sistema (tb_log_sistema, migration 023), consultado pela tela
 * de logs da Gestao Totem. Convive com os error_log() existentes: o error_log
 * continua sendo o registro bruto de infra, este e o registro CONSULTAVEL e
 * SEM DADO PESSOAL.
 *
 *     LogSistema::registrar('erro_banco_pdo', ['id_atendimento' => 12, 'id_totem' => 3,
 *                                              'tipo' => 'recebimento', 'excecao' => $e]);
 *
 * Contrato de seguranca (nao negociavel):
 *  - Categoria, origem, nivel, mensagem e janela vem do catalogo (Util\LogCatalogo).
 *    Nao existe parametro de mensagem, trace, arquivo, linha, IP nem token.
 *  - $ctx aceita SO: id_atendimento (int > 0), id_totem (int > 0, de totem JA
 *    autenticado pelo chamador), tipo ('expedicao'|'recebimento'), excecao
 *    (Throwable), http (100..599) e as chaves de dominio do catalogo, cada uma
 *    com regra fechada. Throwable NUNCA vira mensagem: so o nome da classe
 *    (validado por regex) e, na PDOException, o SQLSTATE (5 caracteres A-Z0-9).
 *  - Categoria/chave/valor fora do catalogo descarta o evento e conta como
 *    `log_parametro_invalido`. Nunca lanca (try/catch de Throwable, inclusive
 *    Error/TypeError, por isso os parametros nao tem tipo declarado: um TypeError
 *    na chamada ja seria uma excecao saindo daqui).
 *  - A escrita nao acontece na hora: os eventos ficam numa fila em memoria e sao
 *    gravados por uma CONEXAO PROPRIA (Conexao::criarDedicada, timeout curto,
 *    sem retry), FORA da transacao do chamador (um rollback dele nao apaga o log),
 *    no shutdown da requisicao (depois de fastcgi_finish_request quando existir).
 *    CLI chama descarregar() explicitamente.
 *  - Falha ao gravar (banco fora do ar, tabela ausente, qualquer erro): so um
 *    error_log fixo ("LogSistema: gravacao falhou categoria=<cat> classe=<X>"),
 *    no maximo 1 por categoria por minuto. Sem spool. Esta classe nunca chama
 *    AuditoriaDao nem a si mesma na falha, e um guard de reentrancia ignora
 *    chamadas feitas de dentro dela (ex.: um tratador de erro que logue).
 *  - Volume: no maximo 10 eventos DISTINTOS por requisicao; deduplicacao atomica
 *    no banco (UNIQUE dedup_chave+janela, contador incrementado por
 *    INSERT ... ON DUPLICATE KEY UPDATE); teto global de linhas novas por dia e
 *    total de linhas: estourado, nao cria linha nova (so soma na existente) e
 *    incrementa a categoria `log_suprimido`. Os tetos sao conferidos por
 *    contagem na descarga, SO para linha nova (o 1o passo e o UPDATE da linha
 *    existente da mesma dedup_chave+janela, sem COUNT): processos simultaneos
 *    podem passar do teto por poucas linhas (aceito).
 *  - Teto diario POR CATEGORIA (TETO_DIARIO_POR_CATEGORIA linhas novas/dia; `cron_*`
 *    e `log_suprimido` ficam fora): uma categoria com cardinalidade alta (ids
 *    variaveis) nao esgota o teto global das demais. Estourado: sem linha nova, so
 *    soma em `log_suprimido` (motivo=teto_categoria). 1 COUNT por categoria por
 *    descarga, so para linha nova.
 *  - Throttle de escrita pre-auth (flag `throttle_escrita` no catalogo, so no web, nao
 *    em CLI/cron): 1 tentativa de escrita por categoria por 60 s por servidor,
 *    marcador em STORAGE_PATH/log_throttle/<hmac da categoria>.json (so timestamp).
 *    Dentro da janela, ou se o marcador/pasta nao puder ser lido/gravado, o evento
 *    e DESCARTADO em silencio (nunca amplifica): nem conexao dedicada nem UPDATE. O
 *    contador dessas categorias fica SUBESTIMADO (conta ~1 por minuto por servidor,
 *    nao o total real de tentativas).
 *  - O contador em memoria por requisicao (repeticoes da mesma entrada antes do
 *    flush) e TRUNCADO em OCORRENCIAS_MAXIMAS_POR_ENTRADA (1000) por
 *    entrada/requisicao: acima disso as repeticoes nao somam mais nada.
 *  - Util\Conexao e Util\Bootstrap NAO referenciam esta classe (teste estatico).
 */
final class LogSistema
{
    public const TETO_POR_REQUISICAO = 10;

    public const TETO_DIARIO = 2000;

    public const TETO_TOTAL = 100000;

    public const TETO_DIARIO_POR_CATEGORIA = 500;

    public const THROTTLE_SEGUNDOS = 60;

    /** Teto de repeticoes somadas na memoria para uma mesma entrada antes do flush. */
    private const OCORRENCIAS_MAXIMAS_POR_ENTRADA = 1000;

    private const INTERVALO_FALLBACK_SEGUNDOS = 60;

    /** @var array<string,array<string,mixed>> */
    private static array $fila = [];

    private static bool $ocupado = false;

    private static bool $bancoIndisponivel = false;

    private static bool $shutdownRegistrado = false;

    /** @var array<string,int> */
    private static array $fallbackEm = [];

    /**
     * @param mixed $categoria string do catalogo (LogCatalogo::CATEGORIAS)
     * @param mixed $ctx array com as chaves permitidas da categoria
     */
    public static function registrar($categoria, $ctx = []): void
    {
        if (self::$ocupado) {
            return;
        }
        self::$ocupado = true;
        try {
            self::registrarInterno($categoria, $ctx);
        } catch (Throwable $e) {
            self::fallback(is_string($categoria) ? $categoria : null, $e);
        } finally {
            self::$ocupado = false;
        }
    }

    /**
     * Grava a fila em memoria (conexao propria). No web roda sozinho no shutdown;
     * scripts CLI (cron) chamam explicitamente antes de sair. Nunca lanca.
     */
    public static function descarregar(): void
    {
        if (self::$ocupado) {
            return;
        }
        self::$ocupado = true;
        try {
            self::descarregarInterno();
        } catch (Throwable $e) {
            self::fallback(null, $e);
        } finally {
            self::$ocupado = false;
        }
    }

    // ------------------------------------------------------------------
    // Registro (validacao e fila)
    // ------------------------------------------------------------------

    private static function registrarInterno($categoria, $ctx): void
    {
        $def = is_string($categoria) ? (LogCatalogo::CATEGORIAS[$categoria] ?? null) : null;
        if ($def === null) {
            self::rejeitar('categoria_desconhecida', null);

            return;
        }
        if (!empty($def['interna'])) {
            self::rejeitar('categoria_interna', $categoria);

            return;
        }
        if (!is_array($ctx)) {
            self::rejeitar('valor_invalido', $categoria);

            return;
        }

        if (!empty($def['throttle_escrita']) && PHP_SAPI !== 'cli' && !self::throttlePermiteEscrita($categoria)) {
            return;
        }

        $norm = self::validar($def['contexto'], $ctx);
        if (is_string($norm)) {
            self::rejeitar($norm, $categoria);

            return;
        }
        self::enfileirar($categoria, $def, $norm);
    }

    /**
     * 1 escrita por categoria por THROTTLE_SEGUNDOS por servidor. Falha segura =
     * descartar (false). Publico so para os testes em CLI.
     *
     * @param int|null $agora so para teste
     */
    public static function throttlePermiteEscrita(string $categoria, ?int $agora = null): bool
    {
        try {
            if (!isset(LogCatalogo::CATEGORIAS[$categoria])) {
                return false;
            }
            $base = rtrim((string) ($_ENV['STORAGE_PATH'] ?? ''), '/\\');
            if ($base === '') {
                return false;
            }
            $dir = $base . DIRECTORY_SEPARATOR . 'log_throttle';
            if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
                return false;
            }
            $sal = (string) ($_ENV['GESTAO_HASH_SALT'] ?? '');
            if (strlen($sal) < 16) {
                $sal = 'totem-udlog/log-throttle/v1';
            }
            $arquivo = $dir . DIRECTORY_SEPARATOR . substr(hash_hmac('sha256', $categoria, $sal), 0, 40) . '.json';
            $h = @fopen($arquivo, 'c+b');
            if ($h === false) {
                return false;
            }
            try {
                if (!@flock($h, LOCK_EX | LOCK_NB)) {
                    return false;
                }
                $agora ??= time();
                $ultimo = (int) trim((string) stream_get_contents($h));
                if ($ultimo > 0 && $agora - $ultimo >= 0 && $agora - $ultimo < self::THROTTLE_SEGUNDOS) {
                    return false;
                }
                if (!ftruncate($h, 0) || fseek($h, 0) !== 0 || fwrite($h, (string) $agora) === false || !fflush($h)) {
                    return false;
                }
                @flock($h, LOCK_UN);

                return true;
            } finally {
                @fclose($h);
            }
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * @param list<string> $permitidas
     * @param array<mixed> $ctx
     * @return array<string,mixed>|string array normalizado, ou o motivo da rejeicao
     */
    private static function validar(array $permitidas, array $ctx)
    {
        $idAtendimento = null;
        $idTotem = null;
        $tipo = null;
        $classe = null;
        $sqlstate = null;
        $http = null;
        $dominio = [];

        foreach ($ctx as $chave => $valor) {
            if (!is_string($chave) || !in_array($chave, $permitidas, true)) {
                return 'chave_desconhecida';
            }
            switch ($chave) {
                case 'id_atendimento':
                    if (!is_int($valor) || $valor < 1) {
                        return 'valor_invalido';
                    }
                    $idAtendimento = $valor;
                    break;
                case 'id_totem':
                    if (!is_int($valor) || $valor < 1 || $valor > 4294967295) {
                        return 'valor_invalido';
                    }
                    $idTotem = $valor;
                    break;
                case 'tipo':
                    if ($valor !== 'expedicao' && $valor !== 'recebimento') {
                        return 'valor_invalido';
                    }
                    $tipo = $valor;
                    break;
                case 'excecao':
                    if (!($valor instanceof Throwable)) {
                        return 'valor_invalido';
                    }
                    $nome = get_class($valor);
                    // classe anonima (contem NUL e caminho) ou longa demais: omite, nao descarta
                    $classe = preg_match('/\A[A-Za-z0-9_\\\\]{1,80}\z/D', $nome) === 1 ? $nome : null;
                    if ($valor instanceof \PDOException) {
                        $codigo = $valor->getCode();
                        $sqlstate = is_string($codigo) && preg_match('/\A[A-Z0-9]{5}\z/D', $codigo) === 1 ? $codigo : null;
                    }
                    break;
                case 'http':
                    if (!is_int($valor) || $valor < 100 || $valor > 599) {
                        return 'valor_invalido';
                    }
                    $http = $valor;
                    break;
                default:
                    $regra = LogCatalogo::DOMINIO[$chave] ?? null;
                    if ($regra === null || !self::valorDominioValido($regra, $valor)) {
                        return $regra === null ? 'chave_desconhecida' : 'valor_invalido';
                    }
                    $dominio[$chave] = $valor;
            }
        }

        return [
            'id_atendimento' => $idAtendimento,
            'id_totem' => $idTotem,
            'tipo' => $tipo,
            'detalhe' => self::montarDetalhe($classe, $sqlstate, $http, $dominio),
        ];
    }

    /** @param array<int,mixed> $regra */
    private static function valorDominioValido(array $regra, $valor): bool
    {
        if ($regra[0] === 'enum') {
            return is_string($valor) && in_array($valor, $regra[1], true);
        }

        return is_int($valor) && $valor >= $regra[1] && $valor <= $regra[2];
    }

    /** @param array<string,string|int> $dominio */
    private static function montarDetalhe(?string $classe, ?string $sqlstate, ?int $http, array $dominio): ?string
    {
        $partes = [];
        if ($classe !== null) {
            $partes[] = 'classe=' . $classe;
        }
        if ($sqlstate !== null) {
            $partes[] = 'sqlstate=' . $sqlstate;
        }
        if ($http !== null) {
            $partes[] = 'http=' . $http;
        }
        foreach (LogCatalogo::ORDEM_DOMINIO as $chave) {
            if (array_key_exists($chave, $dominio)) {
                $partes[] = $chave . '=' . $dominio[$chave];
            }
        }

        return $partes === [] ? null : implode(';', $partes);
    }

    /** Descarta a chamada invalida e registra (na fila) `log_parametro_invalido`. */
    private static function rejeitar(string $motivo, ?string $alvo): void
    {
        $dominio = ['motivo' => $motivo];
        if ($alvo !== null && isset(LogCatalogo::CATEGORIAS[$alvo])) {
            $dominio['alvo'] = $alvo;
        }
        $def = LogCatalogo::CATEGORIAS['log_parametro_invalido'];
        self::enfileirar('log_parametro_invalido', $def, [
            'id_atendimento' => null,
            'id_totem' => null,
            'tipo' => null,
            'detalhe' => self::montarDetalhe(null, null, null, $dominio),
        ]);
    }

    /**
     * @param array<string,mixed> $def
     * @param array<string,mixed> $norm
     */
    private static function enfileirar(string $categoria, array $def, array $norm): void
    {
        $segundos = (int) ($def['janela'] ?? 0);
        if ($segundos < 1) {
            $segundos = LogCatalogo::JANELA_PADRAO;
        }
        $balde = intdiv(time(), $segundos) * $segundos;
        $chaveLocal = implode('|', [$categoria, (string) $norm['tipo'], (string) $norm['id_totem'], (string) $norm['id_atendimento'], (string) $norm['detalhe'], $balde]);

        if (isset(self::$fila[$chaveLocal])) {
            if (self::$fila[$chaveLocal]['ocorrencias'] < self::OCORRENCIAS_MAXIMAS_POR_ENTRADA) {
                self::$fila[$chaveLocal]['ocorrencias']++;
            }

            return;
        }
        if (count(self::$fila) >= self::TETO_POR_REQUISICAO) {
            return;
        }

        self::$fila[$chaveLocal] = [
            'categoria' => $categoria,
            'tipo' => $norm['tipo'],
            'id_totem' => $norm['id_totem'],
            'id_atendimento' => $norm['id_atendimento'],
            'detalhe' => $norm['detalhe'],
            'janela' => $balde,
            'ocorrencias' => 1,
        ];

        if (!self::$shutdownRegistrado) {
            self::$shutdownRegistrado = true;
            register_shutdown_function(static function (): void {
                try {
                    if (function_exists('fastcgi_finish_request')) {
                        fastcgi_finish_request();
                    }
                } catch (Throwable $e) {
                    // sem efeito: so deixa de liberar a resposta antes da gravacao
                }
                self::descarregar();
            });
        }
    }

    // ------------------------------------------------------------------
    // Descarga (conexao propria, fora da transacao do chamador)
    // ------------------------------------------------------------------

    private static function descarregarInterno(): void
    {
        $itens = array_values(self::$fila);
        self::$fila = [];
        if ($itens === []) {
            return;
        }

        if (self::$bancoIndisponivel) {
            foreach ($itens as $item) {
                self::fallback($item['categoria'], null);
            }

            return;
        }

        try {
            $dao = new LogSistemaDao(Conexao::criarDedicada());
        } catch (Throwable $e) {
            self::$bancoIndisponivel = true;
            foreach ($itens as $item) {
                self::fallback($item['categoria'], $e);
            }

            return;
        }

        $estado = ['hoje' => null, 'total' => null, 'cat' => []];
        foreach ($itens as $indice => $item) {
            try {
                self::gravar($dao, $item, $estado);
            } catch (Throwable $e) {
                // qualquer falha de banco: nao insiste nesta requisicao
                self::$bancoIndisponivel = true;
                foreach (array_slice($itens, $indice) as $restante) {
                    self::fallback($restante['categoria'], $e);
                }

                return;
            }
        }
    }

    /**
     * @param array<string,mixed> $item
     * @param array{hoje:?int,total:?int,cat:array<string,int>} $estado
     */
    private static function gravar(LogSistemaDao $dao, array $item, array &$estado): void
    {
        $def = LogCatalogo::CATEGORIAS[$item['categoria']];

        $origem = $def['origem'];
        if ($origem === 'por_tipo') {
            $tipo = $item['tipo'];
            if ($tipo === null && $item['id_atendimento'] !== null) {
                $tipo = $dao->tipoDoAtendimento($item['id_atendimento']);
            }
            $origem = $tipo === 'expedicao' ? 'EXPEDICAO' : ($tipo === 'recebimento' ? 'RECEBIMENTO' : 'API');
        }

        $chave = sha1(implode('|', [$origem, $item['categoria'], (string) $item['id_totem'], (string) $item['id_atendimento'], (string) $item['detalhe']]));
        $ocorrencias = (int) $item['ocorrencias'];

        // 1) Linha da mesma (dedup_chave, janela) ja existe: so soma (UPDATE, sem COUNT).
        //    Os tetos globais so impedem linha NOVA, entao evento ja agrupado nao paga contagem.
        if ($dao->incrementarSeExistir($chave, $item['janela'], $ocorrencias)) {
            return;
        }

        // 2) Linha nova: confere os tetos (contagem 1x por descarga, depois estimativa local).
        $estado['hoje'] ??= $dao->contarCriadosHoje();
        $teto = null;
        if ($estado['hoje'] >= self::TETO_DIARIO) {
            $teto = 'teto_diario';
        } else {
            $estado['total'] ??= $dao->contarTotal();
            if ($estado['total'] >= self::TETO_TOTAL) {
                $teto = 'teto_total';
            }
        }
        if ($teto === null && !str_starts_with($item['categoria'], 'cron_') && $item['categoria'] !== 'log_suprimido') {
            $estado['cat'][$item['categoria']] ??= $dao->contarCriadosHojePorCategoria($item['categoria']);
            if ($estado['cat'][$item['categoria']] >= self::TETO_DIARIO_POR_CATEGORIA) {
                $teto = 'teto_categoria';
            }
        }
        if ($teto !== null) {
            self::registrarSuprimido($dao, $teto, $ocorrencias);

            return;
        }

        $dao->registrarOuIncrementar([
            'nivel' => $def['nivel'],
            'origem' => $origem,
            'categoria' => $item['categoria'],
            'mensagem' => $def['mensagem'],
            'id_atendimento' => $item['id_atendimento'],
            'id_totem' => $item['id_totem'],
            'detalhe' => $item['detalhe'],
            'dedup_chave' => $chave,
            'janela' => $item['janela'],
        ], $ocorrencias);
        // estimativa local (corrida: outro processo pode ter criado a linha entre o UPDATE e o INSERT)
        $estado['hoje']++;
        if (isset($estado['cat'][$item['categoria']])) {
            $estado['cat'][$item['categoria']]++;
        }
        if ($estado['total'] !== null) {
            $estado['total']++;
        }
    }

    private static function registrarSuprimido(LogSistemaDao $dao, string $motivo, int $ocorrencias): void
    {
        $def = LogCatalogo::CATEGORIAS['log_suprimido'];
        $detalhe = self::montarDetalhe(null, null, null, ['motivo' => $motivo]);
        $segundos = (int) $def['janela'];
        $dao->registrarOuIncrementar([
            'nivel' => $def['nivel'],
            'origem' => $def['origem'],
            'categoria' => 'log_suprimido',
            'mensagem' => $def['mensagem'],
            'id_atendimento' => null,
            'id_totem' => null,
            'detalhe' => $detalhe,
            'dedup_chave' => sha1(implode('|', [$def['origem'], 'log_suprimido', '', '', (string) $detalhe])),
            'janela' => intdiv(time(), $segundos) * $segundos,
        ], $ocorrencias);
    }

    // ------------------------------------------------------------------
    // Falha de gravacao: so error_log fixo, com limite de taxa
    // ------------------------------------------------------------------

    /**
     * Texto FIXO: categoria so se for do catalogo, classe so se passar na regex.
     * Nunca getMessage()/trace. Nao chama o banco, a auditoria nem registrar().
     */
    private static function fallback(?string $categoria, ?Throwable $e): void
    {
        try {
            $cat = $categoria !== null && isset(LogCatalogo::CATEGORIAS[$categoria]) ? $categoria : 'desconhecida';
            $agora = time();
            if (isset(self::$fallbackEm[$cat]) && $agora - self::$fallbackEm[$cat] < self::INTERVALO_FALLBACK_SEGUNDOS) {
                return;
            }
            self::$fallbackEm[$cat] = $agora;

            $classe = 'desconhecida';
            if ($e !== null) {
                $nome = get_class($e);
                if (preg_match('/\A[A-Za-z0-9_\\\\]{1,80}\z/D', $nome) === 1) {
                    $classe = $nome;
                }
            }
            error_log('LogSistema: gravacao falhou categoria=' . $cat . ' classe=' . $classe);
        } catch (Throwable $ignorado) {
            // nada a fazer: o log nunca derruba quem o chamou
        }
    }
}
