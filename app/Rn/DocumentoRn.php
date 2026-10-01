<?php

namespace App\Rn;

use App\Dao\AtendimentoDao;
use App\Dao\VioCacheDao;
use Util\CpfValidador;

/**
 * Orquestra a validacao de CNH/CRLV (Expedicao e Recebimento) via
 * vio.api.br (App\Rn\VioApiBrClient) — fluxo QR-only, sem cache e sem
 * armazenamento do QR — e as regras de aprovacao de negocio. O fluxo antigo
 * (Serpro/VioDecodeClient) foi removido em remocao-legado-serpro-e-hardening-
 * documentos (2026-09-28); o cache VIO_CACHE/HMAC foi removido em
 * fluxo-qr-exclusivo-cnh-crlv (2026-10-01).
 */
class DocumentoRn
{
    public function __construct(
        private VioCacheDao $cacheDao,
        private AtendimentoDao $atendimentoDao
    ) {}

    /**
     * CAMPOS_CRITICOS_CNH/CAMPOS_CRITICOS_CRLV (checagem de `mismatch`/
     * `not_found` por campo individual via `comparacao.campos`) REMOVIDAS
     * nesta demanda (remocao-legado-serpro-e-hardening-documentos,
     * 2026-09-28) — ver respostaVioApiBrAprovavel() para o registro
     * completo do risco residual aceito.
     */

    /**
     * Chaves REAIS confirmadas (2026-09-28, teste real pago/autorizado/unico
     * contra vio.api.br com uma CNH digital verdadeira, fora deste
     * ambiente de agentes — script tests/manual/../docs/
     * teste_real_cnh_e_UNICO_USO.php, NUNCA reexecutado por um agente) do
     * objeto piano devolvido dentro de `vio_result`
     * (= App\Rn\VioApiBrClient::normalizarResultado()['dados_leitura']) para
     * CNH. SUBSTITUEM as chaves antes assumidas/placeholder em minusculo
     * ('nome'/'cpf'/'data_validade', que eram um palpite nao confirmado
     * herdado do contrato antigo da VioDecodeClient/Serpro — ver
     * CAMPOS_PERMITIDOS_CNH, usado SOMENTE por aquele fluxo antigo/separado,
     * nunca por vio.api.br).
     *
     * Mapeamento feito AQUI (em DocumentoRn, na hora de ler os campos), nao
     * dentro de App\Rn\VioApiBrClient::normalizarResultado() — decisao
     * deliberada: VioApiBrClient e generico/"burro" por design (repassa
     * `vio_result` como veio, sem saber se e CNH ou CRLV, sem nenhum
     * conhecimento de schema de documento especifico); traduzir chaves de
     * CNH ali criaria uma responsabilidade nova e incoerente com a
     * separacao ja estabelecida (transporte/contrato minimo vs. logica de
     * negocio de documento, que sempre viveu em DocumentoRn). A fronteira
     * de tipo continua sendo feita por extrairCampoTexto(); so mudam os
     * LITERAIS de nome de campo passados a ela.
     *
     * CRLV — chaves REAIS confirmadas em rodada POSTERIOR (2026-09-28,
     * teste real pago/autorizado/unico contra vio.api.br com um CRLV
     * verdadeiro, script docs/teste_real_crlv_UNICO_USO.php, NUNCA
     * reexecutado por um agente). Substituem as chaves antes assumidas/
     * placeholder em minusculo ('placa'/'exercicio'/'uf'/'rntrc'/'tipo'/
     * 'renavam'), usadas SOMENTE por avaliarResultadoVioApiBrCrlv() ao ler
     * `dados_leitura`.
     *
     * Estrutura REAL completa confirmada no teste (30 chaves, dentro de
     * `dados_leitura`) — registrada aqui como referencia para extensoes
     * futuras, mesmo as nao usadas hoje: "Código de Segurança do CLA",
     * "Número do CRV", "UF", "Renavam", "RNTRC", "Exercício", "Nome",
     * "CPF/CNPJ", "Placa", "Chassi", "Espécie", "Tipo", "Carroceria",
     * "Combustível", "Ano Fabricação", "Ano Modelo", "Marca Modelo",
     * "Lotação", "Potência", "Cilindradas", "Categoria", "Cor", "Motor",
     * "Capacidade Máxima de Carga", "Peso Bruto Total", "Capacidade
     * Máxima de Tração", "Eixos", "Local", "Data de Emissão",
     * "Observações". `pages_processed`/`total_pages` vieram NULL (mesmo
     * padrao ja observado na CNH — CRLV ja nao exigia pagina, nada muda
     * aqui) e `comparacao.campos` (namespace usado por
     * respostaVioApiBrAprovavel() para os CAMPOS_CRITICOS_CRLV) veio
     * VAZIO nesse teste — MESMA pendencia ja registrada para CNH, agora
     * reforcada tambem para CRLV (nao alterado nesta correcao — ver
     * handoff).
     */
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

    /**
     * Teto tecnico de armazenamento da coluna `crlv_ano` -- demanda
     * vio-crlv-exercicio-faixa-storage (2026-09-20), complementar ao achado
     * BLOQUEANTE de `caberEmPhpInt()`/`validarExercicioInteiroExato()` (que
     * so garante caber em PHP_INT, nao necessariamente na coluna). Origem
     * EXATA confirmada empiricamente (preflight desta demanda, banco
     * descartavel destruido ao final): `sql/schema.sql:85` declara
     * `crlv_ano SMALLINT NULL` -- SEM `UNSIGNED` -> SMALLINT SIGNED, faixa
     * real `-32768` a `32767` (`COLUMN_TYPE = smallint(6)` confirmado via
     * `INFORMATION_SCHEMA.COLUMNS`). Nenhuma migration altera este tipo.
     *
     * Sem este teto, um `exercicio` tecnicamente valido em PHP (ex.:
     * `32768`, ou qualquer valor entre `32768` e `PHP_INT_MAX`) passaria
     * incolume por `validarExercicioInteiroExato()` (que so valida
     * magnitude de PHP_INT) e saturaria/seria rejeitado de forma
     * dependente do `sql_mode` do MySQL ao ser gravado -- comportamento
     * que a aplicacao nao pode delegar ao banco.
     *
     * So o TETO MAXIMO e validado explicitamente aqui -- o minimo teorico
     * da coluna (`-32768`) e muito mais permissivo que a regra de negocio
     * ja existente e aprovada em avaliarCrlv() (`$exercicio > 0`), entao
     * duplicar o minimo tecnico seria redundante (decisao explicita desta
     * demanda, nao uma omissao).
     */
    private const EXERCICIO_CRLV_MAXIMO_ARMAZENAVEL = 32767;

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

    public function preencherManualCnh(array $atendimento, string $nome, string $cpfBruto, string $dataValidadeBruta): array
    {
        $nome = trim($nome);
        $cpf = CpfValidador::normalizarEValidar($cpfBruto);
        $dataValidade = $this->normalizarData($dataValidadeBruta);

        $avaliacao = $this->avaliarCnh($atendimento, $nome, (string) $cpf, (string) $dataValidade, 'MANUAL');
        $avaliacao['aviso_trial'] = false;
        unset($avaliacao['motivo_codigo']); // uso interno (log VIO), nunca vai ao cliente

        // Preenchimento manual NUNCA e gravado em cache (nao alimenta o VIO,
        // nao impede consulta futura ao VIO para o mesmo QR).
        return $avaliacao;
    }

    /**
     * $exercicioBruto e `mixed` DE PROPOSITO -- o cast prematuro para
     * `string` foi removido de `DocumentoController::preencherManual()`
     * especificamente para este campo (demanda
     * vio-crlv-manual-exercicio-validacao, 2026-09-20). Antes dessa
     * mudanca, um valor de `exercicio` vindo como array/objeto no JSON da
     * requisicao ja sofria `(string)` dentro do PROPRIO Controller --
     * array vira a string literal "Array" (so warning, nunca excecao,
     * seria aprovado como dado valido) e objeto sem `__toString()`
     * lancaria `Error` fatal ANTES mesmo de chegar aqui. Recebendo o
     * valor bruto (mixed), a fronteira de tipo/formato/magnitude/faixa
     * (`validarExercicioCompleto()`) decide de forma controlada e
     * uniforme, sem cast implicito e sem `Error` fatal, exatamente como
     * ja acontece para o fluxo automatico via VIO Decode.
     */
    public function preencherManualCrlv(array $atendimento, string $placaBruta, mixed $exercicioBruto, string $ufBruta, string $rntcBruto, string $tipoBruto): array
    {
        $placa = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $placaBruta));
        $origemAtual = $atendimento['crlv_origem_validacao'] ?? 'NAO_VALIDADO';
        $statusRevisaoAtual = $atendimento['crlv_status_revisao'] ?? 'OK';

        try {
            // Mesma fronteira UNICA (tipo -> formato inteiro exato ->
            // magnitude PHP_INT -> faixa armazenavel de crlv_ano) ja usada
            // pelo fluxo automatico via VIO Decode -- ver
            // validarExercicioCompleto(). Nenhuma logica duplicada.
            $exercicioValidado = $this->validarExercicioCompleto($exercicioBruto, 'crlv_manual', 'exercicio');
        } catch (DocumentoVioTipoInvalidoException $e) {
            // So o marcador categorizado (documento/campo/tipo PHP) vai ao
            // log -- nunca o valor recebido, nunca stack trace. A
            // excecao NUNCA escapa desta classe (mesmo invariante do fluxo
            // VIO) -- o Controller so ve o retorno normal de
            // pode_avancar=false, nunca uma excecao/HTTP 500. Nenhum dado
            // (exercicio ou qualquer outro campo) e persistido; origem e
            // status_revisao permanecem exatamente os anteriores.
            error_log($e->getMessage());

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
        unset($avaliacao['motivo_codigo']); // uso interno (log VIO), nunca vai ao cliente

        return $avaliacao;
    }

    /**
     * CNH aprovada = nome preenchido + CPF com DV valido + data_validade
     * preenchida e nao vencida. Se aprovado, persiste em tb_atendimento com
     * a origem informada (VIO_TRIAL/VIO_VALIDADO/MANUAL) e status_revisao
     * (OK para VIO, PENDENTE_REVISAO para MANUAL).
     */
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

    /**
     * CRLV aprovado = exercicio numerico + placa igual (normalizada) a
     * tb_atendimento.placa do atendimento atual. Exercicio sozinho NUNCA
     * significa "veiculo licenciado em tempo real" — so confirma que o
     * campo veio preenchido/numerico.
     */
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

        // UF ausente/invalida = CRLV NAO aprovado — lista fechada das 27
        // siglas de estado, nunca string livre de 2 caracteres (demanda
        // integracao-talent-portaria-checkin, resolve veiculo.uf obrigatorio
        // do Talent).
        if (!in_array($uf, self::UFS_VALIDAS, true)) {
            return ['ok' => false, 'pode_avancar' => false, 'motivo_codigo' => 'uf_invalida', 'motivo' => 'UF do CRLV nao informada/invalida', 'origem' => $origemAtual, 'status_revisao' => $statusRevisaoAtual];
        }

        $placaAtendimento = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($atendimento['placa'] ?? '')));
        if ($placa === '' || $placa !== $placaAtendimento) {
            return ['ok' => false, 'pode_avancar' => false, 'motivo_codigo' => 'placa_divergente', 'motivo' => 'Placa do CRLV nao confere com a placa do atendimento', 'origem' => $origemAtual, 'status_revisao' => $statusRevisaoAtual];
        }

        // RNTC/tipo de veiculo — obrigatorios pelo Talent (veiculo.rntc/
        // veiculo.tipo), confirmados por teste real de Producao em
        // 2026-09-10 (extensao desta demanda). Validados na mesma sequencia
        // (exercicio -> placeholder -> UF -> placa -> RNTC -> tipo) dos
        // demais campos.
        if ($rntc === '') {
            return ['ok' => false, 'pode_avancar' => false, 'motivo_codigo' => 'rntrc_ausente', 'motivo' => 'RNTC do CRLV nao informado/invalido', 'origem' => $origemAtual, 'status_revisao' => $statusRevisaoAtual];
        }
        if ($tipoVeiculo === '') {
            return ['ok' => false, 'pode_avancar' => false, 'motivo_codigo' => 'tipo_ausente', 'motivo' => 'Tipo de veiculo do CRLV nao informado/invalido', 'origem' => $origemAtual, 'status_revisao' => $statusRevisaoAtual];
        }

        $statusRevisao = $origem === 'MANUAL' ? 'PENDENTE_REVISAO' : 'OK';
        if ($persistir) {
            $this->atendimentoDao->atualizarValidacaoCrlv((int) $atendimento['id_atendimento'], $placa, $exercicio, $uf, $rntc, $tipoVeiculo, $origem, $statusRevisao);
        }

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
     * Extrai um campo do tipo 'texto' de dados_leitura
     * de $dadosBrutos (ja garantido array pelo chamador) validando TIPO
     * ANTES de qualquer trim/cast/uso. So aceita string ou ausencia/null --
     * NUNCA deixa array/objeto/bool/int/float ser silenciosamente coagido a
     * string (o que produziria "Array"/aviso, nunca excecao, e passaria
     * pelas validacoes de conteudo como dado real). Lanca
     * DocumentoVioTipoInvalidoException em caso de tipo incompativel --
     * SEMPRE capturada por avaliarResultadoVioApiBrCnh()/Crlv(), nunca deve
     * escapar de DocumentoRn.
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
     * Extrai um campo do tipo 'numerico' (hoje so
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
        if (!array_key_exists($campo, $dadosBrutos)) {
            return null;
        }

        return $this->validarTipoNumerico($dadosBrutos[$campo], $documento, $campo);
    }

    /**
     * Fronteira de TIPO (nao de conteudo) para um valor 'numerico' --
     * extraida de dentro de extrairCampoNumerico() na demanda
     * vio-crlv-manual-exercicio-validacao (2026-09-20) para poder ser
     * reutilizada tambem pelo fluxo de preenchimento MANUAL do operador
     * (que nao possui um array `$dadosBrutos` vindo da VIO para extrair
     * de -- recebe o valor bruto do JSON da requisicao diretamente). Mesma
     * regra, mesmo comportamento, agora com UMA UNICA implementacao
     * compartilhada pelos dois chamadores (extrairCampoNumerico() para o
     * fluxo VIO, validarExercicioCompleto() para o fluxo manual) -- nunca
     * duas copias divergentes da mesma checagem.
     *
     * Aceita string, int, float, ou ausencia/null (identico ao
     * comportamento anterior desta checagem, documentado em
     * extrairCampoNumerico()). REJEITA estruturalmente array, objeto/
     * stdClass, bool, recurso -- inclusive quando vindos diretamente do
     * `json_decode` do corpo de uma requisicao HTTP (ex.: o operador
     * enviando `"exercicio": [1,2,3]` ou `"exercicio": true` no JSON de
     * preencher-manual), nao apenas de uma resposta da VIO Decode.
     */
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
     * Fronteira de FAIXA ARMAZENAVEL (nao so de PHP_INT) para o campo
     * `exercicio` do CRLV -- demanda vio-crlv-exercicio-faixa-storage
     * (2026-09-20), executada IMEDIATAMENTE apos
     * `validarExercicioInteiroExato()` e ANTES de qualquer cast `(int)`
     * usado para persistencia/envio ao DAO (ordem de validacao exigida:
     * tipo -> formato inteiro exato -> magnitude PHP_INT -> esta faixa de
     * armazenamento -> demais regras de negocio de avaliarCrlv()).
     *
     * Neste ponto o valor recebido (int nativo OU string de digitos puros)
     * JA e garantido, por `validarExercicioInteiroExato()`/
     * `caberEmPhpInt()`, caber inteiramente em PHP_INT_MIN..PHP_INT_MAX --
     * portanto o cast `(int)` feito AQUI (so para fins de COMPARACAO de
     * faixa, nunca reaproveitado como o valor final) e seguro e nao pode
     * ele mesmo estourar/saturar.
     *
     * So valida o TETO (`EXERCICIO_CRLV_MAXIMO_ARMAZENAVEL` = `32767`,
     * `crlv_ano SMALLINT SIGNED` -- ver constante). O piso tecnico da
     * coluna (`-32768`) nao e validado aqui de proposito -- a regra de
     * negocio ja existente e aprovada `$exercicio > 0`
     * (`avaliarCrlv()`) e muito mais restritiva, tornando uma checagem
     * de piso tecnico aqui redundante.
     *
     * REJEITA (invalida a resposta VIO inteira -- mesmo
     * `DocumentoVioTipoInvalidoException`/fluxo fail-closed ja usado para
     * tipo/formato incompativel, nunca uma segunda estrategia paralela):
     * qualquer valor de `exercicio` estritamente maior que `32767`,
     * incluindo `32768` e `PHP_INT_MAX`. Nunca deixa o banco decidir --
     * a rejeicao ocorre 100% em PHP, antes de qualquer chamada ao DAO.
     */
    private function validarExercicioDentroDaFaixaArmazenavel(string $documento, string $campo, int|string|null $valor): int|string|null
    {
        if ($valor === null) {
            return null;
        }

        // Cast seguro so para comparacao -- $valor ja garantido caber em
        // PHP_INT pelo chamador (validarExercicioInteiroExato()); o valor
        // ORIGINAL (int ou string) e devolvido inalterado, nunca o cast.
        $valorParaComparacao = (int) $valor;

        if ($valorParaComparacao > self::EXERCICIO_CRLV_MAXIMO_ARMAZENAVEL) {
            throw new DocumentoVioTipoInvalidoException($documento, $campo, 'numero_fora_da_faixa_armazenavel_crlv_ano');
        }

        return $valor;
    }

    /**
     * Fronteira UNICA e completa do campo `exercicio` do CRLV -- tipo
     * (`validarTipoNumerico()`) -> formato de inteiro exato
     * (`validarExercicioInteiroExato()`) -> magnitude compativel com
     * PHP_INT (`caberEmPhpInt()`, chamado por dentro do passo anterior) ->
     * faixa efetivamente armazenavel de `crlv_ano`
     * (`validarExercicioDentroDaFaixaArmazenavel()`). Demanda
     * vio-crlv-manual-exercicio-validacao (2026-09-20): antes desta
     * correcao, `preencherManualCrlv()` (fluxo de preenchimento MANUAL
     * pelo operador do totem) usava so `is_numeric() ? (int) : 0`, sem
     * NENHUMA das 4 fronteiras acima -- um valor fracionario truncava
     * silenciosamente, um valor fora de PHP_INT ou fora da faixa de
     * `crlv_ano` nao era barrado antes da persistencia (achado registrado
     * no /03-revisao de `vio-crlv-exercicio-faixa-storage`).
     *
     * Chamado hoje por `preencherManualCrlv()` (fluxo manual, chamado
     * DIRETAMENTE aqui, ja que nao ha um array `$dadosBrutos` de onde
     * extrair) e, de forma equivalente (mesmos 3 metodos privados, mesma
     * ordem), pelas 2 chamadas correspondentes dentro de
     * `avaliarResultadoVioApiBrCrlv()` (fluxo automatico via vio.api.br).
     * Nenhuma logica de validacao e duplicada entre os dois fluxos -- mesmo
     * comportamento de fail-closed via `DocumentoVioTipoInvalidoException`
     * (nunca escapa desta classe -- SEMPRE capturada pelo chamador).
     */
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

    // ============================================================
    // Fluxo vio.api.br (demanda migracao-vio-api-br-com-cache, 2026-09-25) —
    // unico fluxo de validacao automatica de CNH/CRLV neste arquivo. O fluxo
    // antigo (integracao direta com o Serpro via App\Rn\VioDecodeClient,
    // validarCnh()/validarCrlv()) foi removido nesta demanda
    // (remocao-legado-serpro-e-hardening-documentos, 2026-09-28) — era
    // codigo morto ha varias rodadas.
    // ============================================================

    /**
     * Avalia o resultado FINAL de uma consulta a vio.api.br para CNH (fluxo
     * QR-only, fluxo-qr-exclusivo-cnh-crlv). Gates: leitura principal
     * completed + qr_type=vio + dados_leitura estruturados (a comparacao
     * VIO e apenas diagnostica, ver respostaVioApiBrAprovavel()). Reaproveita
     * avaliarCnh() (conteudo/placeholder/vencimento) e grava na transacao com
     * CAS de tentativa (atualizarValidacaoCnhVioApiBr); origem VIO_API_BR.
     * Nao ha cache: nenhuma gravacao em VIO_CACHE. Chaves reais de
     * dados_leitura: CAMPO_REAL_CNH_NOME/CPF/VALIDADE.
     */
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
            error_log($e->getMessage());
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

    /**
     * Equivalente a avaliarResultadoVioApiBrCnh(), para CRLV. Chaves reais de
     * dados_leitura: CAMPO_REAL_CRLV_*. Renavam e extraido mas nao persistido
     * (sem consumidor hoje).
     */
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
            error_log($e->getMessage());
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

    /**
     * Criterio minimo de aprovacao automatica via vio.api.br, comum a
     * CNH/CRLV — ver App\Rn\VioApiBrClient::consultarResultado() para o
     * formato normalizado de $resultado.
     *
     * Decisao de produto (vio-comparacao-diagnostica-nao-bloqueante,
     * 2026-10-01): compare e exclusivamente diagnostico. Sua ausencia,
     * estado, reliable, score, mismatched e campos match/mismatch/not_found
     * nunca participam da aprovacao. Permanecem obrigatorios a leitura
     * principal completed, qr_type vio e dados_leitura estruturados; cada
     * avaliador ainda valida integralmente os campos obrigatorios e suas
     * regras de formato/faixa. A quantidade de paginas e garantida antes do
     * POST pelos arquivos/PDF locais (CNH fisica=2, digital=1, CRLV=1), pois
     * a API real pode omitir pages_processed/total_pages.
     *
     * Risco residual aceito: sem a comparacao visual na decisao automatica,
     * nao ha prova de presenca/liveness alem da resposta VIO e dos controles
     * locais de captura. Nenhum dado diagnostico bruto e persistido ou logado.
     */
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

        // A composicao local ja foi validada antes do CAS (PDF 2/1 paginas
        // ou JPEG unico). A VIO real pode omitir as contagens; se ela as
        // informou parcial ou integralmente, ambas precisam confirmar a
        // quantidade esperada para nunca aceitar uma divergencia declarada.
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
