<?php

namespace App\Rn;

use App\Dao\AtendimentoNotaDao;
use App\Dao\ClienteDao;
use Util\CnpjValidador;
use Util\LogSistema;
use Util\RazaoSocialMatcher;

class NotaFiscalRn
{
    private const MAX_CNPJS_CANDIDATOS = 10;

    private const MAX_DIGITOS_NUMERO_NOTA = 9;

    public const CLIENTE_IDENTIFICADO = 'IDENTIFICADO';
    public const CLIENTE_NAO_IDENTIFICADO = 'NAO_IDENTIFICADO';
    public const CLIENTE_ANOMALIA = 'ANOMALIA';

    public const MOTIVO_CONFLITO = 'CONFLITO';
    public const MOTIVO_INDETERMINADO = 'INDETERMINADO';

    public const OCR_TIMEOUT_SEGUNDOS = 120;

    public function __construct(
        private AtendimentoNotaDao $notaDao,
        private ?ClienteDao $clienteDao = null
    ) {}

    public function processarLeitura(int $idAtendimento, int $ordem, string $arquivo, ?string $chave, ?string $clientUid = null): array
    {
        $idNota = $this->notaDao->inserir($idAtendimento, $ordem, $arquivo, $chave, null, false, $clientUid);

        return ['cliente_identificado' => false, 'cliente' => null, 'id_nota' => $idNota, 'ordem' => $ordem];
    }

    public static function clientUidValido(mixed $uid): bool
    {
        return is_string($uid) && preg_match('/^[A-Za-z0-9_-]{8,64}$/D', $uid) === 1;
    }

    public function buscarNotaPorClientUid(int $idAtendimento, string $clientUid, bool $travar = false): ?array
    {
        return $this->notaDao->buscarPorClientUid($idAtendimento, $clientUid, $travar);
    }

    public function listarNotasParaReconciliacao(int $idAtendimento): array
    {
        $lista = [];
        foreach ($this->notaDao->listarParaReconciliacao($idAtendimento) as $n) {
            $lista[] = [
                'id_nota'         => (int) $n['id_nota'],
                'ordem'           => (int) $n['ordem'],
                'uid'             => $n['client_uid'] !== null ? (string) $n['client_uid'] : null,
                'numero_definido' => self::numeroNotaValido($n['numero_nota']),
            ];
        }

        return $lista;
    }

    public static function classificarNotasEmProcessamento(array $notasAtivas): array
    {
        $bloqueantes = [];
        $expiradas = [];

        foreach ($notasAtivas as $nota) {
            if (!in_array((string) $nota['status_ocr'], ['PENDENTE', 'PROCESSANDO'], true)) {
                continue;
            }
            $idade = isset($nota['idade_segundos']) ? (int) $nota['idade_segundos'] : 0;
            if ($idade >= self::OCR_TIMEOUT_SEGUNDOS) {
                $expiradas[] = (int) $nota['ordem'];
            } else {
                $bloqueantes[] = (int) $nota['ordem'];
            }
        }

        sort($bloqueantes);
        sort($expiradas);

        return ['bloqueantes' => $bloqueantes, 'expiradas' => $expiradas];
    }

    public function algumaNotaIdentificouCliente(int $idAtendimento): bool
    {
        return $this->avaliarClienteDoAtendimento($idAtendimento)['estado'] === self::CLIENTE_IDENTIFICADO;
    }

    public function contarNotas(int $idAtendimento): int
    {
        return $this->notaDao->contarPorAtendimento($idAtendimento);
    }

    public function ordemJaRegistrada(int $idAtendimento, int $ordem): bool
    {
        return $this->notaDao->existeOrdem($idAtendimento, $ordem);
    }

    public function buscarNotaDaOrdem(int $idAtendimento, int $ordem): ?array
    {
        return $this->notaDao->buscarPorAtendimentoEOrdem($idAtendimento, $ordem);
    }

    public function buscarNotaDoAtendimento(int $idAtendimento, ?int $idNota, ?int $ordem, bool $travar = false): ?array
    {
        if ($idNota !== null) {
            $nota = $this->notaDao->buscarPorIdNota($idAtendimento, $idNota, $travar);
            if ($nota === null) {
                return null;
            }
            if ($ordem !== null && (int) $nota['ordem'] !== $ordem) {
                return null;
            }

            return $nota;
        }

        if ($ordem === null) {
            return null;
        }

        return $travar
            ? $this->notaDao->buscarPorAtendimentoEOrdemParaUpdate($idAtendimento, $ordem)
            : $this->notaDao->buscarPorAtendimentoEOrdem($idAtendimento, $ordem);
    }

    public function listarNotasAtivasTravadas(int $idAtendimento): array
    {
        return $this->notaDao->listarAtivasParaUpdate($idAtendimento);
    }

    public function primeiraOrdemLivre(int $idAtendimento): ?int
    {
        return $this->notaDao->primeiraOrdemLivre($idAtendimento);
    }

    public function excluirNotaPorId(int $idNota, int $idAtendimento): int
    {
        return $this->notaDao->excluirPorId($idNota, $idAtendimento);
    }

    public static function numeroNotaValido(mixed $numero): bool
    {
        if (is_int($numero)) {
            $numero = (string) $numero;
        }

        return is_string($numero) && preg_match('/^[1-9][0-9]{0,' . (self::MAX_DIGITOS_NUMERO_NOTA - 1) . '}$/D', $numero) === 1;
    }

    public function atualizarNumeroNota(int $idAtendimento, int $ordem, string $numeroBruto, string $origem): array
    {
        $nota = $this->notaDao->buscarPorAtendimentoEOrdem($idAtendimento, $ordem);
        if ($nota === null) {
            throw new \RuntimeException('nota_nao_encontrada');
        }

        return $this->gravarNumeroDaNota($nota, $numeroBruto, $origem);
    }

    public function atualizarNumeroNotaPorIdentificador(
        int $idAtendimento,
        ?int $idNota,
        ?int $ordem,
        string $numeroBruto,
        string $origem,
        bool $travar = false
    ): array {
        $nota = $this->buscarNotaDoAtendimento($idAtendimento, $idNota, $ordem, $travar);
        if ($nota === null) {
            throw new \RuntimeException('nota_nao_encontrada');
        }

        return $this->gravarNumeroDaNota($nota, $numeroBruto, $origem);
    }

    private function gravarNumeroDaNota(array $nota, string $numeroBruto, string $origem): array
    {
        if (!ctype_digit($numeroBruto)) {
            throw new \InvalidArgumentException('numero_invalido');
        }

        $normalizado = ltrim($numeroBruto, '0');
        if ($normalizado === '') {
            throw new \InvalidArgumentException('numero_invalido');
        }

        if (strlen($normalizado) > self::MAX_DIGITOS_NUMERO_NOTA) {
            throw new \InvalidArgumentException('numero_invalido');
        }

        try {
            $this->notaDao->atualizarNumero((int) $nota['id_nota'], $normalizado, $origem);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new \RuntimeException('numero_nota_duplicado');
            }
            throw $e;
        }

        return ['numero_nota' => $normalizado, 'id_nota' => (int) $nota['id_nota'], 'ordem' => (int) $nota['ordem']];
    }

    public function avaliarClienteDoAtendimento(int $idAtendimento, bool $travar = false, bool $exigirTerminais = false): array
    {
        if ($travar) {
            $this->notaDao->listarAtivasParaUpdate($idAtendimento);
        }

        $distintos = $this->notaDao->clientesDistintosIdentificados($idAtendimento);
        $emErro = $this->notaDao->contarComStatusOcr($idAtendimento, 'ERRO');

        if (count($distintos) >= 2) {
            return ['estado' => self::CLIENTE_ANOMALIA, 'motivo' => self::MOTIVO_CONFLITO, 'cliente' => null];
        }
        if ($emErro > 0 || ($exigirTerminais && $this->notaDao->contarEmProcessamento($idAtendimento) > 0)) {
            return ['estado' => self::CLIENTE_ANOMALIA, 'motivo' => self::MOTIVO_INDETERMINADO, 'cliente' => null];
        }
        if (count($distintos) === 1) {
            return [
                'estado'  => self::CLIENTE_IDENTIFICADO,
                'motivo'  => null,
                'cliente' => $this->mapClienteLocal($distintos[0]),
            ];
        }

        return ['estado' => self::CLIENTE_NAO_IDENTIFICADO, 'motivo' => null, 'cliente' => null];
    }

    public static function clienteAtendimentoPublico(array $avaliacao): array
    {
        return [
            'estado'  => $avaliacao['estado'],
            'cliente' => $avaliacao['estado'] === self::CLIENTE_IDENTIFICADO ? $avaliacao['cliente'] : null,
        ];
    }

    public function identificarCliente(
        int $idAtendimento,
        int $idNota,
        ?string $chaveOcr,
        array $cnpjsCandidatos,
        ?string $razaoSocialCandidata
    ): array {
        $avaliacao = $this->avaliarNota($idAtendimento, $idNota, $cnpjsCandidatos, $razaoSocialCandidata);

        return $this->persistirResultadoNota($idAtendimento, $idNota, $avaliacao);
    }

    public function avaliarNota(int $idAtendimento, int $idNota, array $cnpjsCandidatos, ?string $razaoSocialCandidata): array
    {
        if ($this->clienteDao === null) {
            throw new \LogicException('ClienteDao nao injetado');
        }

        $cnpjsCandidatos = array_slice($cnpjsCandidatos, 0, self::MAX_CNPJS_CANDIDATOS);

        $candidatosValidos = [];
        foreach ($cnpjsCandidatos as $bruto) {
            if (!is_string($bruto)) {
                continue;
            }
            $normalizado = CnpjValidador::normalizarEValidar($bruto);
            if ($normalizado !== null && !CnpjValidador::ehUdlog($normalizado)) {
                $candidatosValidos[] = $normalizado;
            }
        }
        $candidatosValidos = array_values(array_unique($candidatosValidos));

        $casados = [];
        foreach ($candidatosValidos as $cnpj) {
            try {
                $encontrado = $this->clienteDao->buscarPorCnpj($cnpj);
            } catch (\Throwable $e) {
                $this->logFalhaTecnica('identificar-cliente: falha tecnica ao consultar clientes por CNPJ', $e, $idAtendimento, $idNota);
                return $this->resultadoErro('ERRO_TECNICO');
            }
            if ($encontrado !== null && $this->cnpjPersistivel($encontrado)) {
                $casados[(int) $encontrado['id_cliente']] = $encontrado;
            }
        }

        if (count($casados) >= 2) {
            return $this->resultadoErro('MULTIPLAS_CORRESPONDENCIAS');
        }
        if (count($casados) === 1) {
            $unico = reset($casados);

            return [
                'status'  => 'IDENTIFICADA',
                'motivo'  => null,
                'cliente' => $this->mapClienteLocal($unico),
                'cnpj'    => (string) $unico['cnpj'],
            ];
        }

        if ($razaoSocialCandidata !== null && trim($razaoSocialCandidata) !== '') {
            try {
                $listagem = $this->clienteDao->listarParaFuzzy();
                $fuzzy = RazaoSocialMatcher::melhorCandidato($razaoSocialCandidata, $listagem);
            } catch (\Throwable $e) {
                $this->logFalhaTecnica('identificar-cliente: falha tecnica ao consultar listagem local de clientes', $e, $idAtendimento, $idNota);
                return $this->resultadoErro('ERRO_TECNICO');
            }

            if (($fuzzy['ambiguo'] ?? false) === true) {
                return $this->resultadoErro('RAZAO_SOCIAL_AMBIGUA');
            }
            if ($fuzzy['identificado'] && $fuzzy['cliente'] !== null && $this->cnpjPersistivel($fuzzy['cliente'])) {
                return [
                    'status'  => 'IDENTIFICADA',
                    'motivo'  => null,
                    'cliente' => $this->mapClienteLocal($fuzzy['cliente']),
                    'cnpj'    => (string) $fuzzy['cliente']['cnpj'],
                ];
            }
        }

        return ['status' => 'NAO_IDENTIFICADA', 'motivo' => null, 'cliente' => null, 'cnpj' => null];
    }

    public function persistirResultadoNota(int $idAtendimento, int $idNota, array $avaliacao): array
    {
        $gravou = $this->notaDao->gravarResultadoOcrUnico($idNota, $idAtendimento, $avaliacao['status'], $avaliacao['cnpj']);

        $status = $avaliacao['status'];
        $motivo = $avaliacao['motivo'];
        $cliente = $avaliacao['cliente'];

        if (!$gravou) {
            $nota = $this->notaDao->buscarPorIdNota($idAtendimento, $idNota);
            if ($nota === null) {
                throw new \RuntimeException('nota_nao_encontrada');
            }

            $gravado = (string) $nota['status_ocr'];
            $motivo = $gravado === 'ERRO'
                ? ($status === 'ERRO' ? $motivo : self::MOTIVO_INDETERMINADO)
                : null;
            $status = $gravado;
            $cliente = null;
            if ($gravado === 'IDENTIFICADA' && !empty($nota['cnpj_emitente']) && $this->clienteDao !== null) {
                $cliente = $this->mapClienteLocal($this->clienteDao->buscarPorCnpj((string) $nota['cnpj_emitente']));
            }
        }

        $clienteAtendimento = $this->avaliarClienteDoAtendimento($idAtendimento);

        return [
            'status'                         => $status,
            'motivo'                         => $motivo,
            'cliente'                        => $cliente,
            'id_nota'                        => $idNota,
            'ja_identificado_no_atendimento' => $clienteAtendimento['estado'] === self::CLIENTE_IDENTIFICADO,
            'cliente_atendimento'            => self::clienteAtendimentoPublico($clienteAtendimento),
        ];
    }

    private function resultadoErro(string $motivo): array
    {
        return ['status' => 'ERRO', 'motivo' => $motivo, 'cliente' => null, 'cnpj' => null];
    }

    private function cnpjPersistivel(array $clienteLocal): bool
    {
        $cnpj = (string) ($clienteLocal['cnpj'] ?? '');

        return $cnpj !== '' && CnpjValidador::normalizarEValidar($cnpj) === $cnpj;
    }

    private function logFalhaTecnica(string $contexto, \Throwable $e, int $idAtendimento, int $idNota): void
    {
        error_log($contexto . ' [' . get_class($e) . '] id_atendimento=' . $idAtendimento . ' id_nota=' . $idNota);
        LogSistema::registrar('erro_tecnico', ['tipo' => 'recebimento', 'excecao' => $e]);
    }

    private function mapClienteLocal(?array $clienteLocal): ?array
    {
        if ($clienteLocal === null) {
            return null;
        }

        return [
            'id'           => $clienteLocal['id_cliente'] ?? null,
            'razao_social' => $clienteLocal['nome'] ?? null,
            'cnpj'         => $clienteLocal['cnpj'] ?? null,
        ];
    }

    public function chaveValida(string $chave): bool
    {
        return strlen($chave) === 44 && ctype_digit($chave);
    }

    public function decodificarChave(string $chave): array
    {
        $aamm = substr($chave, 2, 4);
        $dvInformado = (int) substr($chave, 43, 1);
        $dvCalculado = $this->calcularDV(substr($chave, 0, 43));

        return [
            'uf'            => substr($chave, 0, 2),
            'emissao'       => substr($aamm, 2, 2) . '/20' . substr($aamm, 0, 2),
            'cnpj_emitente' => substr($chave, 6, 14),
            'serie'         => (int) substr($chave, 22, 3),
            'numero_nf'     => (int) substr($chave, 25, 9),
            'dv_ok'         => $dvInformado === $dvCalculado,
        ];
    }

    private function calcularDV(string $chave43): int
    {
        $peso = 2;
        $soma = 0;
        for ($i = strlen($chave43) - 1; $i >= 0; $i--) {
            $soma += (int) $chave43[$i] * $peso;
            $peso = $peso === 9 ? 2 : $peso + 1;
        }
        $resto = $soma % 11;
        return ($resto === 0 || $resto === 1) ? 0 : 11 - $resto;
    }
}
