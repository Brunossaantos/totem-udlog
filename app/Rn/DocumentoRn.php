<?php

namespace App\Rn;

use App\Dao\AtendimentoDao;
use App\Dao\VioCacheDao;
use Util\CpfValidador;
use Util\LogSistema;

class DocumentoRn
{
    public function __construct(
        private VioCacheDao $cacheDao,
        private AtendimentoDao $atendimentoDao
    ) {}

    private const CAMPO_REAL_CNH_NOME = 'Nome';
    private const CAMPO_REAL_CNH_CPF = 'CPF';
    private const CAMPO_REAL_CNH_VALIDADE = 'Validade';

    private const CAMPO_REAL_CRLV_PLACA = 'Placa';
    private const CAMPO_REAL_CRLV_RENAVAM = 'Renavam';
    private const CAMPO_REAL_CRLV_EXERCICIO = 'Exercício';
    private const CAMPO_REAL_CRLV_UF = 'UF';
    private const CAMPO_REAL_CRLV_RNTRC = 'RNTRC';
    private const CAMPO_REAL_CRLV_TIPO = 'Tipo';

    private const MENSAGEM_NAO_APROVADO_CNH = 'CNH nao aprovada automaticamente pela validacao. Preencha manualmente.';
    private const MENSAGEM_NAO_APROVADO_CRLV = 'CRLV nao aprovado automaticamente pela validacao. Preencha manualmente.';

    private const EXERCICIO_CRLV_MAXIMO_ARMAZENAVEL = 32767;

    public const UFS_VALIDAS = [
        'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS',
        'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC',
        'SP', 'SE', 'TO',
    ];

    public function preencherManualCnh(array $atendimento, string $nome, string $cpfBruto, string $dataValidadeBruta): array
    {
        $nome = trim($nome);
        $cpf = CpfValidador::normalizarEValidar($cpfBruto);
        $dataValidade = $this->normalizarData($dataValidadeBruta);

        $avaliacao = $this->avaliarCnh($atendimento, $nome, (string) $cpf, (string) $dataValidade, 'MANUAL');
        $avaliacao['aviso_trial'] = false;
        unset($avaliacao['motivo_codigo']);

        return $avaliacao;
    }

    public function preencherManualCrlv(array $atendimento, string $placaBruta, mixed $exercicioBruto, string $ufBruta, string $rntcBruto, string $tipoBruto): array
    {
        $placa = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $placaBruta));
        $origemAtual = $atendimento['crlv_origem_validacao'] ?? 'NAO_VALIDADO';
        $statusRevisaoAtual = $atendimento['crlv_status_revisao'] ?? 'OK';

        try {
            $exercicioValidado = $this->validarExercicioCompleto($exercicioBruto, 'crlv_manual', 'exercicio');
        } catch (DocumentoVioTipoInvalidoException $e) {
            $this->logErroTipoVio($atendimento, 'crlv', $e);

            return [
                'ok' => false,
                'pode_avancar' => false,
                'motivo' => 'Exercicio do CRLV nao informado/invalido',
                'aviso_trial' => false,
                'origem' => $origemAtual,
                'status_revisao' => $statusRevisaoAtual,
            ];
        }

        $exercicio = is_numeric($exercicioValidado) ? (int) $exercicioValidado : 0;
        $uf = strtoupper(trim($ufBruta));
        $rntc = trim($rntcBruto);
        $tipo = trim($tipoBruto);

        $avaliacao = $this->avaliarCrlv($atendimento, $placa, $exercicio, $uf, $rntc, $tipo, 'MANUAL');
        $avaliacao['aviso_trial'] = false;
        unset($avaliacao['motivo_codigo']);

        return $avaliacao;
    }

    private function avaliarCnh(array $atendimento, string $nome, string $cpf, string $dataValidade, string $origem, bool $persistir = true): array
    {
        $origemAtual = $atendimento['cnh_origem_validacao'] ?? 'NAO_VALIDADO';
        $statusRevisaoAtual = $atendimento['cnh_status_revisao'] ?? 'OK';

        if ($nome === '') {
            return ['ok' => false, 'pode_avancar' => false, 'motivo_codigo' => 'campos_cnh_invalidos', 'motivo' => 'Nome da CNH nao informado/legivel', 'origem' => $origemAtual, 'status_revisao' => $statusRevisaoAtual];
        }
        if ($this->ehValorPlaceholder($nome)) {
            return ['ok' => false, 'pode_avancar' => false, 'motivo_codigo' => 'placeholder', 'motivo' => 'Nome da CNH retornou dado placeholder (ambiente de demonstracao), sem dado real utilizavel', 'origem' => $origemAtual, 'status_revisao' => $statusRevisaoAtual];
        }
        if ($cpf === '') {
            return ['ok' => false, 'pode_avancar' => false, 'motivo_codigo' => 'campos_cnh_invalidos', 'motivo' => 'CPF da CNH invalido', 'origem' => $origemAtual, 'status_revisao' => $statusRevisaoAtual];
        }
        if ($dataValidade === '') {
            return ['ok' => false, 'pode_avancar' => false, 'motivo_codigo' => 'campos_cnh_invalidos', 'motivo' => 'Data de validade da CNH nao informada', 'origem' => $origemAtual, 'status_revisao' => $statusRevisaoAtual];
        }
        if ($this->dataVencida($dataValidade)) {
            return ['ok' => false, 'pode_avancar' => false, 'motivo_codigo' => 'cnh_vencida', 'motivo' => 'CNH vencida', 'origem' => $origemAtual, 'status_revisao' => $statusRevisaoAtual];
        }

        $statusRevisao = $origem === 'MANUAL' ? 'PENDENTE_REVISAO' : 'OK';
        if ($persistir) {
            $this->atendimentoDao->atualizarValidacaoCnh((int) $atendimento['id_atendimento'], $nome, $cpf, $dataValidade, $origem, $statusRevisao);
        }

        return ['ok' => true, 'pode_avancar' => true, 'motivo' => 'CNH aprovada', 'origem' => $origem, 'status_revisao' => $statusRevisao];
    }

    private function avaliarCrlv(array $atendimento, string $placa, int $exercicio, string $uf, string $rntc, string $tipoVeiculo, string $origem, bool $persistir = true): array
    {
        $origemAtual = $atendimento['crlv_origem_validacao'] ?? 'NAO_VALIDADO';
        $statusRevisaoAtual = $atendimento['crlv_status_revisao'] ?? 'OK';

        if ($exercicio <= 0) {
            return ['ok' => false, 'pode_avancar' => false, 'motivo_codigo' => 'exercicio_invalido', 'motivo' => 'Exercicio do CRLV nao informado/invalido', 'origem' => $origemAtual, 'status_revisao' => $statusRevisaoAtual];
        }

        if (
            $this->ehValorPlaceholder($placa) || $this->ehValorPlaceholder((string) $exercicio) || $this->ehValorPlaceholder($uf)
            || $this->ehValorPlaceholder($rntc) || $this->ehValorPlaceholder($tipoVeiculo)
        ) {
            return ['ok' => false, 'pode_avancar' => false, 'motivo_codigo' => 'placeholder', 'motivo' => 'CRLV retornou dado placeholder (ambiente de demonstracao), sem dado real utilizavel', 'origem' => $origemAtual, 'status_revisao' => $statusRevisaoAtual];
        }

        if (!in_array($uf, self::UFS_VALIDAS, true)) {
            return ['ok' => false, 'pode_avancar' => false, 'motivo_codigo' => 'uf_invalida', 'motivo' => 'UF do CRLV nao informada/invalida', 'origem' => $origemAtual, 'status_revisao' => $statusRevisaoAtual];
        }

        $placaAtendimento = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($atendimento['placa'] ?? '')));
        if ($placa === '' || $placa !== $placaAtendimento) {
            return ['ok' => false, 'pode_avancar' => false, 'motivo_codigo' => 'placa_divergente', 'motivo' => 'Placa do CRLV nao confere com a placa do atendimento', 'origem' => $origemAtual, 'status_revisao' => $statusRevisaoAtual];
        }


        $statusRevisao = $origem === 'MANUAL' ? 'PENDENTE_REVISAO' : 'OK';
        if ($persistir) {
            $this->atendimentoDao->atualizarValidacaoCrlv((int) $atendimento['id_atendimento'], $placa, $exercicio, $uf, $rntc, $tipoVeiculo, $origem, $statusRevisao);
        }

        return ['ok' => true, 'pode_avancar' => true, 'motivo' => 'CRLV aprovado', 'origem' => $origem, 'status_revisao' => $statusRevisao];
    }

    public function cnhAprovada(array $atendimento): bool
    {
        if (($atendimento['cnh_origem_validacao'] ?? 'NAO_VALIDADO') === 'NAO_VALIDADO') {
            return false;
        }

        $nome = trim((string) ($atendimento['motorista_nome'] ?? ''));
        $cpf = CpfValidador::normalizarEValidar($atendimento['motorista_cpf'] ?? null);
        $dataValidade = $atendimento['cnh_validade'] ?? null;

        if ($nome === '' || $cpf === null || $dataValidade === null) {
            return false;
        }

        return !$this->dataVencida($dataValidade);
    }

    private function extrairCampoTexto(array $dadosBrutos, string $documento, string $campo): ?string
    {
        if (!array_key_exists($campo, $dadosBrutos) || $dadosBrutos[$campo] === null) {
            return null;
        }

        $valor = $dadosBrutos[$campo];

        if (!is_string($valor)) {
            throw new DocumentoVioTipoInvalidoException($documento, $campo, get_debug_type($valor));
        }

        return $valor;
    }

    private function extrairCampoNumerico(array $dadosBrutos, string $documento, string $campo): int|float|string|null
    {
        if (!array_key_exists($campo, $dadosBrutos)) {
            return null;
        }

        return $this->validarTipoNumerico($dadosBrutos[$campo], $documento, $campo);
    }

    private function validarTipoNumerico(mixed $valor, string $documento, string $campo): int|float|string|null
    {
        if ($valor === null) {
            return null;
        }

        if (!is_int($valor) && !is_float($valor) && !is_string($valor)) {
            throw new DocumentoVioTipoInvalidoException($documento, $campo, get_debug_type($valor));
        }

        return $valor;
    }

    private function validarExercicioInteiroExato(string $documento, string $campo, int|float|string|null $valor): int|string|null
    {
        if ($valor === null) {
            return null;
        }

        if (is_int($valor)) {
            return $valor;
        }

        if (is_float($valor) || !preg_match('/^-?\d+$/', $valor)) {
            throw new DocumentoVioTipoInvalidoException($documento, $campo, 'numero_nao_inteiro_exato');
        }

        if (!$this->caberEmPhpInt($valor)) {
            throw new DocumentoVioTipoInvalidoException($documento, $campo, 'numero_fora_da_capacidade_php_int');
        }

        return $valor;
    }

    private function caberEmPhpInt(string $valorDigitos): bool
    {
        $negativo = $valorDigitos[0] === '-';
        $digitos = $negativo ? substr($valorDigitos, 1) : $valorDigitos;

        $digitosNormalizados = ltrim($digitos, '0');
        if ($digitosNormalizados === '') {
            $digitosNormalizados = '0';
        }

        $limiteMagnitude = $negativo
            ? ltrim((string) PHP_INT_MIN, '-')
            : (string) PHP_INT_MAX;

        $comprimentoValor = strlen($digitosNormalizados);
        $comprimentoLimite = strlen($limiteMagnitude);

        if ($comprimentoValor < $comprimentoLimite) {
            return true;
        }

        if ($comprimentoValor > $comprimentoLimite) {
            return false;
        }

        return strcmp($digitosNormalizados, $limiteMagnitude) <= 0;
    }

    private function validarExercicioDentroDaFaixaArmazenavel(string $documento, string $campo, int|string|null $valor): int|string|null
    {
        if ($valor === null) {
            return null;
        }

        $valorParaComparacao = (int) $valor;

        if ($valorParaComparacao > self::EXERCICIO_CRLV_MAXIMO_ARMAZENAVEL) {
            throw new DocumentoVioTipoInvalidoException($documento, $campo, 'numero_fora_da_faixa_armazenavel_crlv_ano');
        }

        return $valor;
    }

    private function validarExercicioCompleto(mixed $valorBruto, string $documento, string $campo): int|string|null
    {
        $valor = $this->validarTipoNumerico($valorBruto, $documento, $campo);
        $valor = $this->validarExercicioInteiroExato($documento, $campo, $valor);
        $valor = $this->validarExercicioDentroDaFaixaArmazenavel($documento, $campo, $valor);

        return $valor;
    }

    private function ehValorPlaceholder(string $valor): bool
    {
        $valor = strtolower(trim($valor));
        if ($valor === '') {
            return false;
        }

        if ($valor === 'string') {
            return true;
        }

        return (bool) preg_match('/^(.)\1*$/u', $valor);
    }

    private function ehDataCalendarioReal(int $ano, int $mes, int $dia): bool
    {
        return checkdate($mes, $dia, $ano);
    }

    public function crlvAprovado(array $atendimento): bool
    {
        if (($atendimento['crlv_origem_validacao'] ?? 'NAO_VALIDADO') === 'NAO_VALIDADO') {
            return false;
        }

        $exercicio = (int) ($atendimento['crlv_ano'] ?? 0);
        $uf = strtoupper(trim((string) ($atendimento['crlv_uf'] ?? '')));

        return $exercicio > 0 && in_array($uf, self::UFS_VALIDAS, true);
    }


    public function avaliarResultadoVioApiBrCnh(array $atendimento, array $resultado): array
    {
        $motivoCodigo = null;
        if (!$this->respostaVioApiBrAprovavel($resultado, null, $motivoCodigo)) {
            return $this->respostaVioApiBrNaoAprovada($atendimento, 'cnh', $motivoCodigo ?? 'leitura_failed');
        }

        $dados = $resultado['dados_leitura'];

        try {
            $nome = trim($this->extrairCampoTexto($dados, 'cnh_vio_api', self::CAMPO_REAL_CNH_NOME) ?? '');
            $cpf = CpfValidador::normalizarEValidar($this->extrairCampoTexto($dados, 'cnh_vio_api', self::CAMPO_REAL_CNH_CPF));
            $dataValidade = $this->normalizarData($this->extrairCampoTexto($dados, 'cnh_vio_api', self::CAMPO_REAL_CNH_VALIDADE));
        } catch (DocumentoVioTipoInvalidoException $e) {
            $this->logErroTipoVio($atendimento, 'cnh', $e);
            return $this->respostaVioApiBrNaoAprovada($atendimento, 'cnh', 'tipo_invalido_campo');
        }

        $avaliacao = $this->avaliarCnh($atendimento, $nome, (string) $cpf, (string) $dataValidade, 'VIO_API_BR', false);
        $avaliacao['aviso_trial'] = false;

        if (!$avaliacao['pode_avancar'] || $dataValidade === null || $this->dataVencida($dataValidade)) {
            return $avaliacao;
        }

        $tentativaId = $atendimento['cnh_tentativa_id'] ?? null;
        if (!is_string($tentativaId) || $tentativaId === '') {
            return $this->respostaVioApiBrNaoAprovada($atendimento, 'cnh');
        }

        try {
            $gravou = $this->atendimentoDao->executarEmTransacao(fn(): bool =>
                $this->atendimentoDao->atualizarValidacaoCnhVioApiBr((int) $atendimento['id_atendimento'], $tentativaId, $nome, (string) $cpf, $dataValidade)
            );
        } catch (\Throwable $e) {
            error_log('DocumentoRn: falha atomica VIO_API_BR (cnh) [' . get_class($e) . ']');
            return $this->respostaVioApiBrNaoAprovada($atendimento, 'cnh');
        }

        if (!$gravou) {
            return $this->respostaVioApiBrNaoAprovada($atendimento, 'cnh');
        }

        return $avaliacao;
    }

    public function avaliarResultadoVioApiBrCrlv(array $atendimento, array $resultado): array
    {
        $motivoCodigo = null;
        if (!$this->respostaVioApiBrAprovavel($resultado, null, $motivoCodigo)) {
            return $this->respostaVioApiBrNaoAprovada($atendimento, 'crlv', $motivoCodigo ?? 'leitura_failed');
        }

        $dados = $resultado['dados_leitura'];

        $codigoExcecao = 'tipo_invalido_campo';
        try {
            $placaBruta = $this->extrairCampoTexto($dados, 'crlv_vio_api', self::CAMPO_REAL_CRLV_PLACA);
            $codigoExcecao = 'exercicio_invalido';
            $exercicioBruto = $this->extrairCampoNumerico($dados, 'crlv_vio_api', self::CAMPO_REAL_CRLV_EXERCICIO);
            $exercicioBruto = $this->validarExercicioInteiroExato('crlv_vio_api', self::CAMPO_REAL_CRLV_EXERCICIO, $exercicioBruto);
            $exercicioBruto = $this->validarExercicioDentroDaFaixaArmazenavel('crlv_vio_api', self::CAMPO_REAL_CRLV_EXERCICIO, $exercicioBruto);
            $codigoExcecao = 'tipo_invalido_campo';
            $ufBruta = $this->extrairCampoTexto($dados, 'crlv_vio_api', self::CAMPO_REAL_CRLV_UF);
            $rntcBruto = $this->extrairCampoTexto($dados, 'crlv_vio_api', self::CAMPO_REAL_CRLV_RNTRC);
            $tipoBruto = $this->extrairCampoTexto($dados, 'crlv_vio_api', self::CAMPO_REAL_CRLV_TIPO);
            $renavamBruto = $this->extrairCampoTexto($dados, 'crlv_vio_api', self::CAMPO_REAL_CRLV_RENAVAM);
        } catch (DocumentoVioTipoInvalidoException $e) {
            $this->logErroTipoVio($atendimento, 'crlv', $e);
            return $this->respostaVioApiBrNaoAprovada($atendimento, 'crlv', $codigoExcecao);
        }

        $placa = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $placaBruta ?? ''));
        $exercicio = is_numeric($exercicioBruto) ? (int) $exercicioBruto : 0;
        $uf = strtoupper(trim($ufBruta ?? ''));
        $rntc = trim($rntcBruto ?? '');
        $tipo = trim($tipoBruto ?? '');
        $renavam = trim($renavamBruto ?? '');

        $avaliacao = $this->avaliarCrlv($atendimento, $placa, $exercicio, $uf, $rntc, $tipo, 'VIO_API_BR', false);
        $avaliacao['aviso_trial'] = false;

        if (!$avaliacao['pode_avancar']) {
            return $avaliacao;
        }

        $tentativaId = $atendimento['crlv_tentativa_id'] ?? null;
        if (!is_string($tentativaId) || $tentativaId === '') {
            return $this->respostaVioApiBrNaoAprovada($atendimento, 'crlv');
        }

        try {
            $gravou = $this->atendimentoDao->executarEmTransacao(fn(): bool =>
                $this->atendimentoDao->atualizarValidacaoCrlvVioApiBr((int) $atendimento['id_atendimento'], $tentativaId, $placa, $exercicio, $uf, $rntc, $tipo)
            );
        } catch (\Throwable $e) {
            error_log('DocumentoRn: falha atomica VIO_API_BR (crlv) [' . get_class($e) . ']');
            return $this->respostaVioApiBrNaoAprovada($atendimento, 'crlv');
        }

        if (!$gravou) {
            return $this->respostaVioApiBrNaoAprovada($atendimento, 'crlv');
        }

        return $avaliacao;
    }

    private function respostaVioApiBrAprovavel(array $resultado, ?int $paginasEsperadas, ?string &$motivoCodigo = null): bool
    {
        if (($resultado['estado_leitura'] ?? null) !== 'completed') {
            $motivoCodigo = 'leitura_failed';
            return false;
        }
        if (($resultado['qr_type'] ?? null) !== 'vio') {
            $motivoCodigo = 'qr_type_inesperado';
            return false;
        }
        if (!is_array($resultado['dados_leitura'] ?? null)) {
            $motivoCodigo = 'vio_result_ausente';
            return false;
        }

        if ($paginasEsperadas !== null) {
            $paginasProcessadas = $resultado['pages_processed'] ?? null;
            $paginasTotais = $resultado['total_pages'] ?? null;
            if ($paginasProcessadas !== null || $paginasTotais !== null) {
                if ($paginasProcessadas !== $paginasEsperadas || $paginasTotais !== $paginasEsperadas) {
                    $motivoCodigo = 'paginas_divergentes';
                    return false;
                }
            }
        }

        return true;
    }

    private function logErroTipoVio(array $atendimento, string $documento, \Throwable $e): void
    {
        error_log('DocumentoRn: campo VIO com tipo invalido (documento=' . $documento . ')');

        $ctx = ['excecao' => $e, 'documento' => $documento, 'motivo' => 'dados_invalidos'];
        $idAtendimento = (int) ($atendimento['id_atendimento'] ?? 0);
        if ($idAtendimento > 0) {
            $ctx['id_atendimento'] = $idAtendimento;
        }
        LogSistema::registrar('vio_erro_interno', $ctx);
    }

    private function respostaVioApiBrNaoAprovada(array $atendimento, string $tipo, string $motivoCodigo = 'persistencia_nao_vigente'): array
    {
        return [
            'ok' => false,
            'pode_avancar' => false,
            'motivo_codigo' => $motivoCodigo,
            'motivo' => $tipo === 'cnh' ? self::MENSAGEM_NAO_APROVADO_CNH : self::MENSAGEM_NAO_APROVADO_CRLV,
            'aviso_trial' => false,
            'origem' => $atendimento["{$tipo}_origem_validacao"] ?? 'NAO_VALIDADO',
            'status_revisao' => $atendimento["{$tipo}_status_revisao"] ?? 'OK',
        ];
    }

    private function dataVencida(?string $dataValidadeIso): bool
    {
        if ($dataValidadeIso === null || $dataValidadeIso === '') {
            return true;
        }

        try {
            $data = new \DateTimeImmutable($dataValidadeIso);
        } catch (\Throwable $e) {
            return true;
        }

        return $data < new \DateTimeImmutable('today');
    }

    private function normalizarData(?string $bruta): ?string
    {
        if ($bruta === null || trim($bruta) === '') {
            return null;
        }

        $bruta = trim($bruta);

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $bruta, $m)) {
            return $this->ehDataCalendarioReal((int) $m[1], (int) $m[2], (int) $m[3]) ? $bruta : null;
        }

        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $bruta, $m)) {
            if (!$this->ehDataCalendarioReal((int) $m[3], (int) $m[2], (int) $m[1])) {
                return null;
            }
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }

        return null;
    }
}
