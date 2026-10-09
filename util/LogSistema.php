<?php

namespace Util;

use App\Dao\LogSistemaDao;
use Throwable;

final class LogSistema
{
    public const TETO_POR_REQUISICAO = 10;

    public const TETO_DIARIO = 2000;

    public const TETO_TOTAL = 100000;

    public const TETO_DIARIO_POR_CATEGORIA = 500;

    public const THROTTLE_SEGUNDOS = 60;

    private const OCORRENCIAS_MAXIMAS_POR_ENTRADA = 1000;

    private const INTERVALO_FALLBACK_SEGUNDOS = 60;

    private static array $fila = [];

    private static bool $ocupado = false;

    private static bool $bancoIndisponivel = false;

    private static bool $shutdownRegistrado = false;

    private static array $fallbackEm = [];

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

    private static function valorDominioValido(array $regra, $valor): bool
    {
        if ($regra[0] === 'enum') {
            return is_string($valor) && in_array($valor, $regra[1], true);
        }

        return is_int($valor) && $valor >= $regra[1] && $valor <= $regra[2];
    }

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
                }
                self::descarregar();
            });
        }
    }


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
                self::$bancoIndisponivel = true;
                foreach (array_slice($itens, $indice) as $restante) {
                    self::fallback($restante['categoria'], $e);
                }

                return;
            }
        }
    }

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

        if ($dao->incrementarSeExistir($chave, $item['janela'], $ocorrencias)) {
            return;
        }

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
        }
    }
}
