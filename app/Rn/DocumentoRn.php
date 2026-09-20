<?php

namespace App\Rn;

use App\Dao\AtendimentoDao;
use App\Dao\VioCacheDao;
use Util\CpfValidador;

/**
 * Orquestra a validacao de CNH/CRLV da Expedicao via VIO Decode (Serpro):
 * HMAC do QR bruto (nunca persiste o QR em si), cache local
 * (tb_vio_cache_cnh/tb_vio_cache_crlv, separado por ambiente), chamada a
 * VioDecodeClient em caso de cache miss/vencido, e as regras de aprovacao
 * de negocio (ver docs/handoffs/2026-09-08-expedicao-vio-cnh-crlv.md).
 *
 * NUNCA recebe/mantem o QR bruto alem do escopo de uma unica chamada de
 * metodo — o parametro $bytesQrBrutos so existe em memoria durante o
 * calculo do HMAC e (se cache miss) a montagem da chamada ao VIO.
 *
 * PENDENCIA registrada (nao inventado): o formato exato de codificacao do
 * "raw value" dentro do corpo JSON enviado a VIO Decode nao foi validado
 * na pratica (item 4 do handoff — confirmacao de jsQR.binaryData). Este
 * codigo assume que os bytes brutos, ja em ISO-8859-1 conforme o manual,
 * podem ser transportados como string PHP diretamente; se a API exigir
 * outro transporte (ex: base64 dentro do JSON), ajustar em
 * VioDecodeClient::chamarDecode sem alterar o restante deste fluxo.
 */
class DocumentoRn
{
    public function __construct(
        private VioCacheDao $cacheDao,
        private AtendimentoDao $atendimentoDao
    ) {}

    /**
     * Allowlist EXPLICITA de campos de CNH aceitos da resposta da VIO Decode
     * (nomes exatos conforme docs/manual_vio_decode.md). Qualquer outro
     * campo do envelope (`image`, `template`, ou qualquer campo de `data`
     * fora desta lista) e descartado imediatamente apos a extracao — nunca
     * copiado para variavel que sobreviva alem deste metodo.
     */
    private const CAMPOS_PERMITIDOS_CNH = ['nome', 'cpf', 'data_validade'];

    /**
     * Allowlist EXPLICITA de campos de CRLV aceitos da resposta da VIO
     * Decode (nomes exatos conforme docs/manual_vio_decode.md). 'uf'
     * adicionado na demanda integracao-talent-portaria-checkin (2026-09-09)
     * — resolve o campo obrigatorio veiculo.uf do payload do Talent.
     * 'rntc'/'tipo' adicionados na extensao de 2026-09-10 — resolvem os
     * campos obrigatorios veiculo.rntc/veiculo.tipo, confirmados por teste
     * real de Producao (HTTP 400: "The rntc field is required."/"The tipo
     * field is required.").
     *
     * DIVERGENCIA DE NOMENCLATURA registrada explicitamente (nao e erro de
     * digitacao): docs/manual_vio_decode.md documenta a chave real da
     * resposta da VIO como `rntrc` (sigla oficial do registro), nao `rntc`.
     * O nome de coluna/campo do payload do Talent e `rntc` (nome exato
     * exigido por `veiculo.rntc`). Por isso o INDICE 3 desta allowlist
     * usa a chave de EXTRACAO real `rntrc` (para ler a resposta da VIO),
     * mas o valor extraido e armazenado/exposto sempre como `rntc`
     * (destino). Ver uso em validarCrlv().
     */
    private const CAMPOS_PERMITIDOS_CRLV = ['placa', 'exercicio', 'uf', 'rntrc', 'tipo'];

    /**
     * Esquema EXPLICITO de tipos aceitos para cada campo das allowlists
     * acima -- fronteira de validacao de TIPO, aplicada SEMPRE antes de
     * qualquer cast/trim/normalizacao/persistencia (nunca depois). Achado
     * do /02-testes independente de 2026-09-19 (backend-especialista +
     * security-especialista, convergentes): sem esta fronteira, um campo
     * vindo como array aninhado sofria cast `(string)` implicito e virava
     * a string literal "Array" (so warning, nunca excecao), aprovada como
     * dado valido; e `dados`/`cpf`/`data_validade` como stdClass/array
     * aninhado lancavam Error/TypeError reais que so nao explodiam ao
     * cliente por acidente do catch generico do Controller.
     *
     * Regras (aplicadas por extrairCampoTexto()/extrairCampoNumerico()):
     * - 'texto': aceita string OU ausencia/null (campo opcional na
     *   pratica -- ausencia e tratada como "nao informado" pelas regras de
     *   CONTEUDO ja existentes em avaliarCnh()/avaliarCrlv(), nao aqui).
     *   REJEITA array, objeto/stdClass, bool, int, float, recurso.
     * - 'numerico': aceita string, int OU float (todos os formatos que a
     *   VIO poderia usar para um numero), OU ausencia/null. REJEITA array,
     *   objeto/stdClass, bool, recurso.
     * String vazia ('') e placeholder (ex.: "xxxxx") NAO sao tratados
     * aqui -- continuam sendo responsabilidade das regras de CONTEUDO ja
     * existentes (ehValorPlaceholder(), checks de string vazia em
     * avaliarCnh()/avaliarCrlv()). Esta fronteira SO valida tipo, nunca
     * conteudo.
     *
     * Campo com tipo incompativel invalida a resposta VIO INTEIRA daquele
     * documento (nunca so o campo) -- ver falhaEstruturaInvalidaCnh()/
     * falhaEstruturaInvalidaCrlv(): nenhum valor parcial e persistido,
     * origem nunca e marcada como VIO_TRIAL/VIO_VALIDADO, conduz ao mesmo
     * caminho de fallback manual ja usado para resposta VIO incompleta.
     */
    private const ESQUEMA_TIPOS_CNH = [
        'nome' => 'texto',
        'cpf' => 'texto',
        'data_validade' => 'texto',
    ];

    private const ESQUEMA_TIPOS_CRLV = [
        'placa' => 'texto',
        'exercicio' => 'numerico',
        'uf' => 'texto',
        'rntrc' => 'texto',
        'tipo' => 'texto',
    ];

    private const MENSAGEM_ESTRUTURA_INVALIDA_CNH = 'Falha ao validar CNH: dados retornados em formato invalido';

    private const MENSAGEM_ESTRUTURA_INVALIDA_CRLV = 'Falha ao validar CRLV: dados retornados em formato invalido';

    /**
     * Lista fechada das 27 siglas de UF brasileiras (26 estados + DF) —
     * usada tanto para validar o valor extraido da VIO Decode quanto o
     * preenchimento manual (dropdown fechado no front-end, nunca texto
     * livre). Publica para reaproveitamento pelo front-end/outros pontos
     * que precisem da mesma lista fechada.
     */
    public const UFS_VALIDAS = [
        'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS',
        'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC',
        'SP', 'SE', 'TO',
    ];

    /**
     * @return array{ok:bool, pode_avancar:bool, motivo:string, aviso_trial:bool, origem:string, status_revisao:string}
     */
    public function validarCnh(array $atendimento, VioDecodeClient $vio, string $bytesQrBrutos): array
    {
        $ttlDias = $this->cacheTtlDias();
        $identificadorQr = $this->calcularIdentificador($bytesQrBrutos);
        $ambiente = $vio->ambiente();

        $cache = $this->cacheDao->buscarCnhValido($identificadorQr, $ambiente);

        // CNH vencida nunca aproveita cache, mesmo com valido_ate no futuro —
        // forca nova consulta ao VIO (decisao adotada: nao simplifica direto
        // para MANUAL, da mais uma chance real de revalidar antes disso).
        if ($cache !== null && $this->dataVencida($cache['data_validade'])) {
            $cache = null;
        }

        if ($cache !== null) {
            return $this->avaliarCnh($atendimento, $cache['nome'], $cache['cpf'], $cache['data_validade'], $cache['origem']);
        }

        $resultadoVio = $vio->decodificar($bytesQrBrutos);

        if (!$resultadoVio['ok']) {
            return [
                'ok' => false,
                'pode_avancar' => false,
                'motivo' => 'Falha ao validar CNH: ' . ($resultadoVio['erro']['mensagem'] ?? 'erro desconhecido'),
                'aviso_trial' => $ambiente === 'trial',
                'origem' => $atendimento['cnh_origem_validacao'] ?? 'NAO_VALIDADO',
                'status_revisao' => $atendimento['cnh_status_revisao'] ?? 'OK',
            ];
        }

        // Resposta real da VIO Decode envelopa os campos do documento em
        // "data" (junto de "template"/"image"), confirmado por teste real
        // em 2026-09-08 (nao documentado assim em docs/manual_vio_decode.md
        // ate entao). Mantido fallback para o corpo "achatado" (sem
        // wrapper) para nao quebrar os mocks ja existentes em
        // tests/manual/teste_vio_decode.php.
        //
        // Descarte ativo: so os campos da allowlist (CAMPOS_PERMITIDOS_CNH)
        // sao extraidos para variaveis que sobrevivem alem deste bloco —
        // $resultadoVio/$dadosBrutos (que podem conter `image.base64`,
        // `template`, ou qualquer outro campo nao autorizado) saem de
        // escopo (unset explicito) logo em seguida, nunca persistidos, logados
        // ou retornados ao chamador.
        // Fronteira de tipo/esquema (ver ESQUEMA_TIPOS_CNH): `dados` e
        // `dados.data` precisam ser sequer um array antes de qualquer
        // acesso por chave -- caso contrario (ex.: stdClass), o acesso
        // `$x['data']`/`$x['nome']` lancaria um Error fatal real (achado
        // MODERADO do /02-testes de 2026-09-19). Resposta inteira e
        // rejeitada (fallback manual), nunca so o campo.
        $dadosBrutos = $resultadoVio['dados'];
        if (!is_array($dadosBrutos)) {
            unset($resultadoVio, $dadosBrutos);
            return $this->falhaEstruturaInvalidaCnh($atendimento, $ambiente);
        }
        $dadosBrutos = $dadosBrutos['data'] ?? $dadosBrutos;
        if (!is_array($dadosBrutos)) {
            unset($resultadoVio, $dadosBrutos);
            return $this->falhaEstruturaInvalidaCnh($atendimento, $ambiente);
        }

        try {
            $nome = trim($this->extrairCampoTexto($dadosBrutos, 'cnh', self::CAMPOS_PERMITIDOS_CNH[0]) ?? '');
            $cpf = CpfValidador::normalizarEValidar($this->extrairCampoTexto($dadosBrutos, 'cnh', self::CAMPOS_PERMITIDOS_CNH[1]));
            $dataValidade = $this->normalizarData($this->extrairCampoTexto($dadosBrutos, 'cnh', self::CAMPOS_PERMITIDOS_CNH[2]));
        } catch (DocumentoVioTipoInvalidoException $e) {
            // So o marcador categorizado (documento/campo/tipo PHP) vai ao
            // log -- nunca o valor recebido, nunca stack trace de payload.
            error_log($e->getMessage());
            unset($resultadoVio, $dadosBrutos);
            return $this->falhaEstruturaInvalidaCnh($atendimento, $ambiente);
        }
        unset($resultadoVio, $dadosBrutos);

        $origem = $ambiente === 'trial' ? 'VIO_TRIAL' : 'VIO_VALIDADO';

        $avaliacao = $this->avaliarCnh($atendimento, $nome, (string) $cpf, (string) $dataValidade, $origem);

        // So grava cache se aprovado E nao vencida (CNH vencida nunca deveria
        // ser reaproveitada de cache — decisao: nem grava, evita cache morto).
        if ($avaliacao['pode_avancar'] && $dataValidade !== null && !$this->dataVencida($dataValidade)) {
            $agora = new \DateTimeImmutable('now');
            $this->cacheDao->salvarCnh(
                $identificadorQr,
                $ambiente,
                $nome,
                (string) $cpf,
                $dataValidade,
                $origem,
                $agora->format('Y-m-d H:i:s'),
                $agora->modify("+{$ttlDias} days")->format('Y-m-d H:i:s')
            );
        }

        $avaliacao['aviso_trial'] = $ambiente === 'trial';

        return $avaliacao;
    }

    public function preencherManualCnh(array $atendimento, string $nome, string $cpfBruto, string $dataValidadeBruta): array
    {
        $nome = trim($nome);
        $cpf = CpfValidador::normalizarEValidar($cpfBruto);
        $dataValidade = $this->normalizarData($dataValidadeBruta);

        $avaliacao = $this->avaliarCnh($atendimento, $nome, (string) $cpf, (string) $dataValidade, 'MANUAL');
        $avaliacao['aviso_trial'] = false;

        // Preenchimento manual NUNCA e gravado em cache (nao alimenta o VIO,
        // nao impede consulta futura ao VIO para o mesmo QR).
        return $avaliacao;
    }

    /**
     * @return array{ok:bool, pode_avancar:bool, motivo:string, aviso_trial:bool, origem:string, status_revisao:string}
     */
    public function validarCrlv(array $atendimento, VioDecodeClient $vio, string $bytesQrBrutos): array
    {
        $ttlDias = $this->cacheTtlDias();
        $identificadorQr = $this->calcularIdentificador($bytesQrBrutos);
        $ambiente = $vio->ambiente();

        $cache = $this->cacheDao->buscarCrlvValido($identificadorQr, $ambiente);

        if ($cache !== null) {
            // SEMPRE recompara a placa do cache contra a placa ATUAL do
            // atendimento — o cache pode ter sido gerado por outro atendimento.
            // (VioCacheDao::buscarCrlvValido ja garante uf/rntc/tipo_veiculo
            // IS NOT NULL/NOT VAZIO — cache incompleto de antes desta demanda
            // nunca chega aqui.)
            return $this->avaliarCrlv($atendimento, $cache['placa'], (int) $cache['exercicio'], (string) $cache['uf'], (string) $cache['rntc'], (string) $cache['tipo_veiculo'], $cache['origem']);
        }

        $resultadoVio = $vio->decodificar($bytesQrBrutos);

        if (!$resultadoVio['ok']) {
            return [
                'ok' => false,
                'pode_avancar' => false,
                'motivo' => 'Falha ao validar CRLV: ' . ($resultadoVio['erro']['mensagem'] ?? 'erro desconhecido'),
                'aviso_trial' => $ambiente === 'trial',
                'origem' => $atendimento['crlv_origem_validacao'] ?? 'NAO_VALIDADO',
                'status_revisao' => $atendimento['crlv_status_revisao'] ?? 'OK',
            ];
        }

        // Mesmo wrapper "data" da resposta real da VIO Decode — ver
        // comentario equivalente em validarCnh(). Descarte ativo: so os
        // campos da allowlist (CAMPOS_PERMITIDOS_CRLV) sao extraidos para
        // variaveis que sobrevivem alem deste bloco — $resultadoVio/
        // $dadosBrutos saem de escopo (unset explicito) logo em seguida.
        // Fronteira de tipo/esquema (ver ESQUEMA_TIPOS_CRLV) -- mesmo
        // raciocinio de validarCnh() acima.
        $dadosBrutos = $resultadoVio['dados'];
        if (!is_array($dadosBrutos)) {
            unset($resultadoVio, $dadosBrutos);
            return $this->falhaEstruturaInvalidaCrlv($atendimento, $ambiente);
        }
        $dadosBrutos = $dadosBrutos['data'] ?? $dadosBrutos;
        if (!is_array($dadosBrutos)) {
            unset($resultadoVio, $dadosBrutos);
            return $this->falhaEstruturaInvalidaCrlv($atendimento, $ambiente);
        }

        try {
            $placaBruta = $this->extrairCampoTexto($dadosBrutos, 'crlv', self::CAMPOS_PERMITIDOS_CRLV[0]);
            $exercicioBruto = $this->extrairCampoNumerico($dadosBrutos, 'crlv', self::CAMPOS_PERMITIDOS_CRLV[1]);
            $exercicioBruto = $this->validarExercicioInteiroExato('crlv', self::CAMPOS_PERMITIDOS_CRLV[1], $exercicioBruto);
            $ufBruta = $this->extrairCampoTexto($dadosBrutos, 'crlv', self::CAMPOS_PERMITIDOS_CRLV[2]);
            // Chave de EXTRACAO real na resposta da VIO e `rntrc` (ver
            // comentario da allowlist acima) — o valor extraido e
            // tratado/armazenado daqui em diante sempre como `rntc` (nome
            // exigido por veiculo.rntc do Talent).
            $rntcBruto = $this->extrairCampoTexto($dadosBrutos, 'crlv', self::CAMPOS_PERMITIDOS_CRLV[3]);
            $tipoBruto = $this->extrairCampoTexto($dadosBrutos, 'crlv', self::CAMPOS_PERMITIDOS_CRLV[4]);
        } catch (DocumentoVioTipoInvalidoException $e) {
            error_log($e->getMessage());
            unset($resultadoVio, $dadosBrutos);
            return $this->falhaEstruturaInvalidaCrlv($atendimento, $ambiente);
        }

        $placaVio = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $placaBruta ?? ''));
        $exercicio = is_numeric($exercicioBruto) ? (int) $exercicioBruto : 0;
        $ufVio = strtoupper(trim($ufBruta ?? ''));
        $rntcVio = trim($rntcBruto ?? '');
        $tipoVio = trim($tipoBruto ?? '');
        unset($resultadoVio, $dadosBrutos);

        $origem = $ambiente === 'trial' ? 'VIO_TRIAL' : 'VIO_VALIDADO';

        // Cache e salvo pelo QR em si (estruturalmente valido: placa presente
        // + exercicio numerico + UF dentro da lista fechada de 27 siglas +
        // RNTC/tipo preenchidos e nao placeholder), independente de bater com
        // ESTE atendimento — um atendimento futuro com a mesma placa pode
        // reaproveitar. Placeholder do Trial (ex: "xxxxx") nunca e cacheado —
        // nao e dado real utilizavel.
        if (
            $placaVio !== '' && $exercicio > 0 && in_array($ufVio, self::UFS_VALIDAS, true)
            && $rntcVio !== '' && $tipoVio !== ''
            && !$this->ehValorPlaceholder($placaVio) && !$this->ehValorPlaceholder((string) $exercicio)
            && !$this->ehValorPlaceholder($rntcVio) && !$this->ehValorPlaceholder($tipoVio)
        ) {
            $agora = new \DateTimeImmutable('now');
            $this->cacheDao->salvarCrlv(
                $identificadorQr,
                $ambiente,
                $placaVio,
                $exercicio,
                $ufVio,
                $rntcVio,
                $tipoVio,
                $origem,
                $agora->format('Y-m-d H:i:s'),
                $agora->modify("+{$ttlDias} days")->format('Y-m-d H:i:s')
            );
        }

        $avaliacao = $this->avaliarCrlv($atendimento, $placaVio, $exercicio, $ufVio, $rntcVio, $tipoVio, $origem);
        $avaliacao['aviso_trial'] = $ambiente === 'trial';

        return $avaliacao;
    }

    public function preencherManualCrlv(array $atendimento, string $placaBruta, string $exercicioBruto, string $ufBruta, string $rntcBruto, string $tipoBruto): array
    {
        $placa = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $placaBruta));
        $exercicio = is_numeric($exercicioBruto) ? (int) $exercicioBruto : 0;
        $uf = strtoupper(trim($ufBruta));
        $rntc = trim($rntcBruto);
        $tipo = trim($tipoBruto);

        $avaliacao = $this->avaliarCrlv($atendimento, $placa, $exercicio, $uf, $rntc, $tipo, 'MANUAL');
        $avaliacao['aviso_trial'] = false;

        return $avaliacao;
    }

    /**
     * CNH aprovada = nome preenchido + CPF com DV valido + data_validade
     * preenchida e nao vencida. Se aprovado, persiste em tb_atendimento com
     * a origem informada (VIO_TRIAL/VIO_VALIDADO/MANUAL) e status_revisao
     * (OK para VIO, PENDENTE_REVISAO para MANUAL).
     */
    private function avaliarCnh(array $atendimento, string $nome, string $cpf, string $dataValidade, string $origem): array
    {
        $origemAtual = $atendimento['cnh_origem_validacao'] ?? 'NAO_VALIDADO';
        $statusRevisaoAtual = $atendimento['cnh_status_revisao'] ?? 'OK';

        if ($nome === '') {
            return ['ok' => false, 'pode_avancar' => false, 'motivo' => 'Nome da CNH nao informado/legivel', 'origem' => $origemAtual, 'status_revisao' => $statusRevisaoAtual];
        }
        if ($this->ehValorPlaceholder($nome)) {
            return ['ok' => false, 'pode_avancar' => false, 'motivo' => 'Nome da CNH retornou dado placeholder (ambiente de demonstracao), sem dado real utilizavel', 'origem' => $origemAtual, 'status_revisao' => $statusRevisaoAtual];
        }
        if ($cpf === '') {
            return ['ok' => false, 'pode_avancar' => false, 'motivo' => 'CPF da CNH invalido', 'origem' => $origemAtual, 'status_revisao' => $statusRevisaoAtual];
        }
        if ($dataValidade === '') {
            return ['ok' => false, 'pode_avancar' => false, 'motivo' => 'Data de validade da CNH nao informada', 'origem' => $origemAtual, 'status_revisao' => $statusRevisaoAtual];
        }
        if ($this->dataVencida($dataValidade)) {
            return ['ok' => false, 'pode_avancar' => false, 'motivo' => 'CNH vencida', 'origem' => $origemAtual, 'status_revisao' => $statusRevisaoAtual];
        }

        $statusRevisao = $origem === 'MANUAL' ? 'PENDENTE_REVISAO' : 'OK';
        $this->atendimentoDao->atualizarValidacaoCnh((int) $atendimento['id_atendimento'], $nome, $cpf, $dataValidade, $origem, $statusRevisao);

        return ['ok' => true, 'pode_avancar' => true, 'motivo' => 'CNH aprovada', 'origem' => $origem, 'status_revisao' => $statusRevisao];
    }

    /**
     * CRLV aprovado = exercicio numerico + placa igual (normalizada) a
     * tb_atendimento.placa do atendimento atual. Exercicio sozinho NUNCA
     * significa "veiculo licenciado em tempo real" — so confirma que o
     * campo veio preenchido/numerico.
     */
    private function avaliarCrlv(array $atendimento, string $placa, int $exercicio, string $uf, string $rntc, string $tipoVeiculo, string $origem): array
    {
        $origemAtual = $atendimento['crlv_origem_validacao'] ?? 'NAO_VALIDADO';
        $statusRevisaoAtual = $atendimento['crlv_status_revisao'] ?? 'OK';

        if ($exercicio <= 0) {
            return ['ok' => false, 'pode_avancar' => false, 'motivo' => 'Exercicio do CRLV nao informado/invalido', 'origem' => $origemAtual, 'status_revisao' => $statusRevisaoAtual];
        }

        if (
            $this->ehValorPlaceholder($placa) || $this->ehValorPlaceholder((string) $exercicio) || $this->ehValorPlaceholder($uf)
            || $this->ehValorPlaceholder($rntc) || $this->ehValorPlaceholder($tipoVeiculo)
        ) {
            return ['ok' => false, 'pode_avancar' => false, 'motivo' => 'CRLV retornou dado placeholder (ambiente de demonstracao), sem dado real utilizavel', 'origem' => $origemAtual, 'status_revisao' => $statusRevisaoAtual];
        }

        // UF ausente/invalida = CRLV NAO aprovado — lista fechada das 27
        // siglas de estado, nunca string livre de 2 caracteres (demanda
        // integracao-talent-portaria-checkin, resolve veiculo.uf obrigatorio
        // do Talent).
        if (!in_array($uf, self::UFS_VALIDAS, true)) {
            return ['ok' => false, 'pode_avancar' => false, 'motivo' => 'UF do CRLV nao informada/invalida', 'origem' => $origemAtual, 'status_revisao' => $statusRevisaoAtual];
        }

        $placaAtendimento = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($atendimento['placa'] ?? '')));
        if ($placa === '' || $placa !== $placaAtendimento) {
            return ['ok' => false, 'pode_avancar' => false, 'motivo' => 'Placa do CRLV nao confere com a placa do atendimento', 'origem' => $origemAtual, 'status_revisao' => $statusRevisaoAtual];
        }

        // RNTC/tipo de veiculo — obrigatorios pelo Talent (veiculo.rntc/
        // veiculo.tipo), confirmados por teste real de Producao em
        // 2026-09-10 (extensao desta demanda). Validados na mesma sequencia
        // (exercicio -> placeholder -> UF -> placa -> RNTC -> tipo) dos
        // demais campos.
        if ($rntc === '') {
            return ['ok' => false, 'pode_avancar' => false, 'motivo' => 'RNTC do CRLV nao informado/invalido', 'origem' => $origemAtual, 'status_revisao' => $statusRevisaoAtual];
        }
        if ($tipoVeiculo === '') {
            return ['ok' => false, 'pode_avancar' => false, 'motivo' => 'Tipo de veiculo do CRLV nao informado/invalido', 'origem' => $origemAtual, 'status_revisao' => $statusRevisaoAtual];
        }

        $statusRevisao = $origem === 'MANUAL' ? 'PENDENTE_REVISAO' : 'OK';
        $this->atendimentoDao->atualizarValidacaoCrlv((int) $atendimento['id_atendimento'], $placa, $exercicio, $uf, $rntc, $tipoVeiculo, $origem, $statusRevisao);

        return ['ok' => true, 'pode_avancar' => true, 'motivo' => 'CRLV aprovado', 'origem' => $origem, 'status_revisao' => $statusRevisao];
    }

    /**
     * Rechecagem independente no momento da TRANSICAO de etapa (seção 1 do
     * escopo) — nunca confia so no que foi decidido em validar-qr/
     * preencher-manual, sempre confere de novo os dados JA GRAVADOS em
     * tb_atendimento antes de autorizar o avanco de exp_cnh -> exp_crlv.
     */
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

    /**
     * Rejeita valor placeholder conhecido/tipico de ambiente de demonstracao
     * (ex: `crlv-demo.bin` do Trial retorna placa/exercicio literalmente
     * como "xxxxx", mesmo com HTTP 200) — mesmo aprovado tecnicamente pelo
     * transporte, isso NUNCA e um dado real utilizavel. Usado tanto para
     * CRLV (placa/exercicio) quanto para CNH (nome/data_validade), mesma
     * allowlist reforcada para os dois documentos (achado do /02-testes de
     * 2026-09-08: antes so cobria CRLV, assimetria de robustez).
     *
     * Cobre: sequencia de um unico caractere repetido ("xxxxx", "00000",
     * "11111", "aaaaa" — inclusive um unico caractere isolado), e o literal
     * "string" (valor classico de placeholder de Swagger/exemplo de API).
     * Vazio/so espacos NAO e placeholder aqui (ja tratado como erro
     * separado — "nao informado" — pelos chamadores, com mensagem mais
     * especifica).
     */
    /**
     * Extrai um campo do tipo 'texto' do ESQUEMA_TIPOS_CNH/ESQUEMA_TIPOS_CRLV
     * de $dadosBrutos (ja garantido array pelo chamador) validando TIPO
     * ANTES de qualquer trim/cast/uso. So aceita string ou ausencia/null --
     * NUNCA deixa array/objeto/bool/int/float ser silenciosamente coagido a
     * string (o que produziria "Array"/aviso, nunca excecao, e passaria
     * pelas validacoes de conteudo como dado real). Lanca
     * DocumentoVioTipoInvalidoException em caso de tipo incompativel --
     * SEMPRE capturada por validarCnh()/validarCrlv(), nunca deve escapar
     * de DocumentoRn.
     */
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

    /**
     * Extrai um campo do tipo 'numerico' do ESQUEMA_TIPOS_CRLV (hoje so
     * `exercicio`) validando TIPO antes de qualquer cast. Aceita string,
     * int, float, ou ausencia/null -- a validacao de CONTEUDO (se e de
     * fato numerico, ex.: "abc" nao e) continua sendo feita pelo chamador
     * via is_numeric(), como antes desta correcao. So REJEITA
     * estruturalmente array/objeto/bool/recurso, que antes desta correcao
     * ja resultavam em is_numeric()===false (portanto exercicio=0, sem
     * TypeError) so por comportamento incidental de is_numeric() -- agora
     * a rejeicao e por barreira explicita de tipo, invalidando a resposta
     * inteira (consistente com os demais campos), em vez de silenciosamente
     * zerar o campo.
     */
    private function extrairCampoNumerico(array $dadosBrutos, string $documento, string $campo): int|float|string|null
    {
        if (!array_key_exists($campo, $dadosBrutos) || $dadosBrutos[$campo] === null) {
            return null;
        }

        $valor = $dadosBrutos[$campo];

        if (!is_int($valor) && !is_float($valor) && !is_string($valor)) {
            throw new DocumentoVioTipoInvalidoException($documento, $campo, get_debug_type($valor));
        }

        return $valor;
    }

    /**
     * Fronteira de CONTEUDO (nao so tipo) para o campo `exercicio` do CRLV --
     * achado BLOQUEANTE do /03-revisao independente de 2026-09-20
     * (backend-especialista, reproduzido pelo fluxo real em banco
     * descartavel): `extrairCampoNumerico()` so valida TIPO (aceita
     * string/int/float), mas o cast `(int)` aplicado em seguida por
     * validarCrlv() truncava silenciosamente qualquer valor fracionario
     * (`2026.9` -> `2026`) sem NENHUMA sinalizacao de perda de precisao --
     * a resposta era aprovada e persistida normalmente.
     *
     * So aceita, sem cast/arredondamento/truncamento:
     * - `int` nativo -- sempre exato por definicao, sinal ou magnitude
     *   NAO importam aqui (a regra de FAIXA -- `$exercicio > 0` -- continua
     *   sendo decidida exclusivamente por avaliarCrlv(), nunca por esta
     *   fronteira; json_decode so produz int para um numero JSON sem
     *   ponto/notacao cientifica -- um numero fracionario/cientifico ou
     *   que estoure PHP_INT_MAX sempre chega aqui como `float`, nunca
     *   como `int` corrompido).
     * - `string` no MESMO formato que `(int)` converte SEM perda de
     *   precisao: sinal negativo opcional seguido exclusivamente de
     *   digitos (`/^-?\d+$/`) -- sem ponto decimal, sem notacao
     *   cientifica, sem espaco em branco (prefixo/sufixo), sem string
     *   vazia. Zero a esquerda preservado (regressao ja confirmada em
     *   rodada anterior). Sinal negativo aceito aqui de proposito -- NAO e
     *   responsabilidade desta fronteira decidir faixa, so exatidao;
     *   `"-2026"` continua sendo rejeitado do mesmo jeito que antes, mas
     *   pela regra de FAIXA existente em avaliarCrlv(), nao por esta.
     * - `null` -- ausencia continua sendo responsabilidade da regra de
     *   CONTEUDO ja existente em avaliarCrlv() (`$exercicio <= 0` ->
     *   "nao informado/invalido"), nao desta fronteira.
     *
     * REJEITA (invalida a resposta VIO inteira, mesmo caminho de
     * DocumentoVioTipoInvalidoException ja usado para tipo incompativel):
     * `float` -- SEMPRE, mesmo quando matematicamente inteiro (`2026.0`).
     * Investigado docs/manual_vio_decode.md (linhas 126-130) e o unico
     * teste real observado de `exercicio` (Trial, `crlv-demo.bin`)
     * retornou o placeholder literal string `"xxxxx"`, nunca um numero —
     * SEM EVIDENCIA real de que a VIO envie `exercicio` como float
     * (inteiro ou fracionario). Sem essa evidencia, o esquema estrito
     * rejeita qualquer float aqui (cobre fracionario, notacao cientifica
     * que o PHP decodifica como float, `NAN`/`INF` -- que tambem sao
     * `float` em PHP -- e o proprio `2026.0`, e overflow de inteiro que o
     * PHP converte automaticamente para float), preferindo lado estrito a
     * inventar um formato aceito sem prova. Tambem rejeita `string` com
     * qualquer caractere alem de digito puro/sinal negativo opcional
     * (decimal, notacao cientifica, espaco em branco/prefixo/sufixo,
     * string vazia).
     */
    private function validarExercicioInteiroExato(string $documento, string $campo, int|float|string|null $valor): int|string|null
    {
        if ($valor === null) {
            return null;
        }

        if (is_int($valor)) {
            // int nativo: ja e garantido pelo proprio PHP estar dentro de
            // PHP_INT_MIN..PHP_INT_MAX (json_decode/PHP nunca produzem um
            // int fora dessa faixa -- overflow vira float automaticamente,
            // tratado no ramo abaixo). Nenhuma validacao de magnitude
            // adicional e necessaria aqui.
            return $valor;
        }

        if (is_float($valor) || !preg_match('/^-?\d+$/', $valor)) {
            throw new DocumentoVioTipoInvalidoException($documento, $campo, 'numero_nao_inteiro_exato');
        }

        // Formato lexical OK (digitos puros, sinal opcional) -- mas isso NAO
        // garante que o valor cabe em PHP_INT. Uma string de digitos maior
        // que PHP_INT_MAX passaria incolume por esta regex e satura
        // silenciosamente no cast (int) mais adiante (achado BLOQUEANTE do
        // /03-revisao de 2026-09-20). Valida MAGNITUDE aqui, em nivel de
        // string, ANTES de qualquer cast/conversao numerica -- nunca
        // convertendo a string para int/float neste processo (evitaria
        // justamente o overflow/perda de precisao que estamos prevenindo).
        if (!$this->caberEmPhpInt($valor)) {
            throw new DocumentoVioTipoInvalidoException($documento, $campo, 'numero_fora_da_capacidade_php_int');
        }

        return $valor;
    }

    /**
     * Confirma que uma string de digitos puros (formato ja validado por
     * `/^-?\d+$/` no chamador -- sinal negativo opcional seguido so de
     * digitos) representa um valor dentro da faixa PHP_INT_MIN..PHP_INT_MAX,
     * SEM jamais converter a string para int/float (evita o proprio
     * overflow/saturacao/perda de precisao que esta validacao existe para
     * prevenir). Comparacao feita por COMPRIMENTO da parte numerica e,
     * quando o comprimento empata com o limite, LEXICOGRAFICAMENTE contra
     * a representacao em string de PHP_INT_MAX (valores positivos) ou do
     * modulo de PHP_INT_MIN (valores negativos -- a magnitude negativa
     * maxima permitida e 1 unidade maior que a positiva, ex.:
     * PHP_INT_MIN = -9223372036854775808, PHP_INT_MAX = 9223372036854775807).
     *
     * Zeros a esquerda sao removidos APENAS para esta comparacao de
     * magnitude (`ltrim($digitos, '0')`) -- o valor original (com zeros a
     * esquerda, se houver) e devolvido inalterado pelo chamador; o
     * comportamento ja aprovado para zeros a esquerda (ex.: "007") nao
     * muda em nada por esta funcao.
     *
     * Decisao: NAO usar `filter_var($valor, FILTER_VALIDATE_INT)` aqui --
     * embora ele tambem rejeite corretamente overflow (retorna `false` para
     * valores fora da faixa de PHP_INT), a comparacao lexicografica manual
     * evita qualquer dependencia do parsing interno do filtro (cujo
     * comportamento documentado nao cobre explicitamente todos os casos de
     * borda de zeros a esquerda/sinal de forma auditavel neste codigo) e
     * torna a garantia de "nunca converte a string gigante para numero"
     * explicita e verificavel por leitura direta desta funcao, em vez de
     * confiar em uma extensao externa. `filter_var('007', ...)` retorna
     * `7` (inteiro), o que serviria para uma checagem BOOLEANA de faixa,
     * mas essa funcao evita converter a string em nenhum momento -- nem
     * para validar, nem para devolver.
     */
    private function caberEmPhpInt(string $valorDigitos): bool
    {
        $negativo = $valorDigitos[0] === '-';
        $digitos = $negativo ? substr($valorDigitos, 1) : $valorDigitos;

        // Normalizacao de zeros a esquerda SOMENTE para fins de comparacao
        // de magnitude (nao altera o valor original devolvido ao chamador).
        $digitosNormalizados = ltrim($digitos, '0');
        if ($digitosNormalizados === '') {
            $digitosNormalizados = '0';
        }

        // Magnitude maxima permitida: PHP_INT_MAX para positivos;
        // |PHP_INT_MIN| (1 unidade maior) para negativos.
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

        // Mesmo comprimento -- decide por comparacao lexicografica pura de
        // string (nunca numerica), caractere a caractere na mesma ordem de
        // grandeza (ambas normalizadas sem zero a esquerda e mesmo
        // comprimento nesta ramificacao).
        return strcmp($digitosNormalizados, $limiteMagnitude) <= 0;
    }

    /**
     * Resultado de falha estruturada para CNH quando a resposta da VIO nao
     * bate com ESQUEMA_TIPOS_CNH (campo com tipo incompativel) ou quando
     * `dados`/`dados.data` nao e sequer um array. Mesmo formato de retorno
     * do ramo `!$resultadoVio['ok']` de validarCnh() -- nenhum valor
     * parcial e persistido, origem/status_revisao preservam o estado ATUAL
     * do atendimento (nunca marcados como VIO_TRIAL/VIO_VALIDADO/OK),
     * mensagem generica funcional (nunca expõe nome de campo/tipo PHP ao
     * cliente -- isso so vai para error_log).
     */
    private function falhaEstruturaInvalidaCnh(array $atendimento, string $ambiente): array
    {
        return [
            'ok' => false,
            'pode_avancar' => false,
            'motivo' => self::MENSAGEM_ESTRUTURA_INVALIDA_CNH,
            'aviso_trial' => $ambiente === 'trial',
            'origem' => $atendimento['cnh_origem_validacao'] ?? 'NAO_VALIDADO',
            'status_revisao' => $atendimento['cnh_status_revisao'] ?? 'OK',
        ];
    }

    /**
     * Equivalente a falhaEstruturaInvalidaCnh(), para CRLV.
     */
    private function falhaEstruturaInvalidaCrlv(array $atendimento, string $ambiente): array
    {
        return [
            'ok' => false,
            'pode_avancar' => false,
            'motivo' => self::MENSAGEM_ESTRUTURA_INVALIDA_CRLV,
            'aviso_trial' => $ambiente === 'trial',
            'origem' => $atendimento['crlv_origem_validacao'] ?? 'NAO_VALIDADO',
            'status_revisao' => $atendimento['crlv_status_revisao'] ?? 'OK',
        ];
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

    /**
     * Verifica se uma data no formato Y-m-d representa uma data de
     * calendario REAL (rejeita ex.: "0000-00-00") — usado por
     * normalizarData() para nunca aceitar formato claramente invalido, ainda
     * que sintaticamente pareca uma data.
     */
    private function ehDataCalendarioReal(int $ano, int $mes, int $dia): bool
    {
        return checkdate($mes, $dia, $ano);
    }

    /**
     * Mesma logica de rechecagem para CRLV, antes de exp_crlv -> exp_confirmacao.
     */
    public function crlvAprovado(array $atendimento): bool
    {
        if (($atendimento['crlv_origem_validacao'] ?? 'NAO_VALIDADO') === 'NAO_VALIDADO') {
            return false;
        }

        $exercicio = (int) ($atendimento['crlv_ano'] ?? 0);
        $uf = strtoupper(trim((string) ($atendimento['crlv_uf'] ?? '')));
        $rntc = trim((string) ($atendimento['crlv_rntc'] ?? ''));
        $tipoVeiculo = trim((string) ($atendimento['crlv_tipo_veiculo'] ?? ''));

        return $exercicio > 0 && in_array($uf, self::UFS_VALIDAS, true) && $rntc !== '' && $tipoVeiculo !== '';
    }

    private function calcularIdentificador(string $bytesQrBrutos): string
    {
        $chaveHex = $_ENV['DOCUMENTO_QR_HMAC_KEY'] ?? '';
        if ($chaveHex === '' || strlen($chaveHex) !== 64 || !ctype_xdigit($chaveHex)) {
            throw new \RuntimeException('DOCUMENTO_QR_HMAC_KEY ausente ou invalida');
        }

        return hash_hmac('sha256', $bytesQrBrutos, hex2bin($chaveHex));
    }

    private function cacheTtlDias(): int
    {
        $valor = $_ENV['VIO_CACHE_TTL_DIAS'] ?? '30';
        $dias = (int) $valor;

        return $dias > 0 ? $dias : 30;
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

    /**
     * Normaliza a data de validade da CNH para o formato Y-m-d. Aceita os
     * formatos mais prováveis (ISO Y-m-d, ou dd/mm/aaaa em pt-BR) — formato
     * exato retornado pela VIO Decode nao documentado explicitamente para
     * este campo (pendencia registrada, nao inventado como certeza).
     *
     * Rejeita explicitamente (retorna null) qualquer formato sintaticamente
     * parecido com data mas que nao seja uma data de calendario REAL (ex.:
     * "00/00/0000") — achado do /02-testes de 2026-09-08 (assimetria de
     * placeholder entre CNH/CRLV). Um retorno null aqui e tratado pelos
     * chamadores como "data de validade nao informada".
     */
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
