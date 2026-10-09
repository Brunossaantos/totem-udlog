<?php

namespace App\Rn;

use App\Dao\AtendimentoNotaDao;
use App\Dao\ClienteDao;
use Util\CnpjValidador;
use Util\LogSistema;
use Util\RazaoSocialMatcher;

class NotaFiscalRn
{
    // Limite maximo de CNPJs candidatos aceitos por chamada de
    // identificar-cliente — mitigacao contra uso do endpoint como "oraculo"
    // de existencia de CNPJ/dados de cliente (achado do security-especialista
    // na etapa de planejamento). Mesma ordem de grandeza do limite de 5
    // notas por atendimento, com folga.
    private const MAX_CNPJS_CANDIDATOS = 10;

    // Comprimento maximo de digitos aceito para numero_nota apos a remocao
    // de zeros a esquerda (ver atualizarNumeroNota) — teto real do layout
    // de numero de NF-e (posicoes 26-34 da chave de acesso), bem abaixo do
    // limite da coluna tb_atendimento_nota.numero_nota VARCHAR(20). Rejeita
    // explicitamente ANTES de qualquer persistencia (nunca trunca), o que
    // cobre tambem uma chave de acesso inteira (44 digitos) por engano.
    private const MAX_DIGITOS_NUMERO_NOTA = 9;

    // Estados DERIVADOS do cliente do atendimento (demanda
    // hardening-revisao-notas-e-cliente, 2026-09-30). Nunca persistidos.
    public const CLIENTE_IDENTIFICADO = 'IDENTIFICADO';
    public const CLIENTE_NAO_IDENTIFICADO = 'NAO_IDENTIFICADO';
    public const CLIENTE_ANOMALIA = 'ANOMALIA';

    public const MOTIVO_CONFLITO = 'CONFLITO';
    public const MOTIVO_INDETERMINADO = 'INDETERMINADO';

    // F1 (rodada corretiva 2026-10-01): tempo maximo, contado do upload da nota
    // (criado_em, relogio do banco), que o servidor espera o resultado do OCR
    // client-side. E o MESMO teto de 120 s ja aprovado para o trabalho de OCR
    // na inatividade do front (INATIVIDADE_TETO_SUSPENSAO_MS = 120000). Nota
    // PENDENTE/PROCESSANDO acima do teto NAO bloqueia mais a conclusao: vai
    // para o fallback manual de cliente (nunca bloqueio indefinido).
    public const OCR_TIMEOUT_SEGUNDOS = 120;

    public function __construct(
        private AtendimentoNotaDao $notaDao,
        // nullable so para permitir instanciar a Rn apenas com o que a
        // decisao de conclusao precisa (avaliarClienteDoAtendimento depende
        // so do notaDao); identificarCliente() exige o ClienteDao.
        private ?ClienteDao $clienteDao = null
    ) {}

    /**
     * Registra a nota (linha em tb_atendimento_nota). A identificacao pelo
     * caminho CHAVE foi DESATIVADA (demanda hardening-revisao-notas-e-
     * cliente, 2026-09-30): a chave nunca mais resolve cliente nem marca
     * cliente_identificado; a unica fonte de identificacao e a
     * identificacao por OCR (identificarCliente), sempre contra tb_cliente
     * ATIVA. O campo `chave` e apenas armazenado como antes.
     *
     * @return array{cliente_identificado: bool, cliente: null, id_nota: int, ordem: int}
     */
    public function processarLeitura(int $idAtendimento, int $ordem, string $arquivo, ?string $chave, ?string $clientUid = null): array
    {
        $idNota = $this->notaDao->inserir($idAtendimento, $ordem, $arquivo, $chave, null, false, $clientUid);

        return ['cliente_identificado' => false, 'cliente' => null, 'id_nota' => $idNota, 'ordem' => $ordem];
    }

    /**
     * Formato valido do uid gerado pelo front (F7): 8 a 64 caracteres de
     * [A-Za-z0-9_-]. Regex ancorada ao fim real (modificador D): newline,
     * CR, NUL e qualquer sufixo invisivel sao rejeitados.
     */
    public static function clientUidValido(mixed $uid): bool
    {
        return is_string($uid) && preg_match('/^[A-Za-z0-9_-]{8,64}$/D', $uid) === 1;
    }

    /** Nota ja criada para o uid dentro do atendimento (F7), ou null. */
    public function buscarNotaPorClientUid(int $idAtendimento, string $clientUid, bool $travar = false): ?array
    {
        return $this->notaDao->buscarPorClientUid($idAtendimento, $clientUid, $travar);
    }

    /**
     * Reconciliacao (somente leitura): lista as notas do atendimento com
     * id_nota, ordem, uid e se o numero ja esta definido.
     *
     * @return array<int, array{id_nota:int, ordem:int, uid:?string, numero_definido:bool}>
     */
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

    /**
     * F1: separa, entre as notas ATIVAS travadas, as ordens ainda em
     * processamento de OCR (status_ocr PENDENTE/PROCESSANDO). `bloqueantes` =
     * dentro do teto de OCR_TIMEOUT_SEGUNDOS (a conclusao deve esperar);
     * `expiradas` = ja acima do teto (fallback manual, nao bloqueiam).
     *
     * @param array<int, array{ordem:mixed, status_ocr:mixed, idade_segundos?:mixed}> $notasAtivas
     * @return array{bloqueantes: int[], expiradas: int[]}
     */
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

    /**
     * `status` legado: verdadeiro SOMENTE se o atendimento esta no estado
     * derivado IDENTIFICADO (cliente ATIVO unico e nenhuma nota em ERRO) —
     * o JOIN antigo sem filtro de ativo deixou de decidir.
     */
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

    /**
     * Busca a nota de uma ordem especifica dentro de um atendimento (usada
     * pelo endpoint identificar-cliente para validar posse/existencia antes
     * de aceitar o resultado — IDOR).
     */
    public function buscarNotaDaOrdem(int $idAtendimento, int $ordem): ?array
    {
        return $this->notaDao->buscarPorAtendimentoEOrdem($idAtendimento, $ordem);
    }

    /**
     * Resolve a nota por id_nota (identidade imutavel) e/ou ordem, SEMPRE
     * dentro do atendimento. Se vierem os dois, devem coincidir — senao null
     * (o controller responde 404 sem distinguir a causa). Nota excluida (ou
     * id_nota antigo apos reuso da ordem) tambem devolve null.
     */
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

    /**
     * Notas ATIVAS do atendimento (travadas com FOR UPDATE: so chamar dentro
     * de transacao). Usado por excluir/concluir para snapshot e validacao.
     *
     * @return array<int, array{id_nota:mixed, ordem:mixed, numero_nota:mixed, status_ocr:mixed, cnpj_emitente:mixed}>
     */
    public function listarNotasAtivasTravadas(int $idAtendimento): array
    {
        return $this->notaDao->listarAtivasParaUpdate($idAtendimento);
    }

    /** Primeira ordem livre (1 a 5) ou null se ja houver 5 notas. */
    public function primeiraOrdemLivre(int $idAtendimento): ?int
    {
        return $this->notaDao->primeiraOrdemLivre($idAtendimento);
    }

    /** Exclusao fisica da linha; devolve linhas removidas (esperado: 1). */
    public function excluirNotaPorId(int $idNota, int $idAtendimento): int
    {
        return $this->notaDao->excluirPorId($idNota, $idAtendimento);
    }

    /**
     * Predicado UNICO de numero_nota valido para conclusao: 1 a 9 digitos,
     * sem zero a esquerda (mesmo formato que atualizarNumeroNota grava).
     * NULL, vazio e lixo legado (zeros a esquerda, letras, mais de 9
     * digitos) contam como PENDENTE.
     */
    public static function numeroNotaValido(mixed $numero): bool
    {
        if (is_int($numero)) {
            $numero = (string) $numero;
        }

        return is_string($numero) && preg_match('/^[1-9][0-9]{0,' . (self::MAX_DIGITOS_NUMERO_NOTA - 1) . '}$/D', $numero) === 1;
    }

    /**
     * Normaliza (SEMPRE no backend, nunca confia no OCR/front) e persiste o
     * numero da NF-e para a nota de uma ordem especifica dentro de um
     * atendimento — demanda talent-doctos-finalizacao-checkin (2026-09-14).
     * Normalizacao: aceita so digitos (rejeita qualquer outro caractere —
     * numero de NF-e e numerico), remove zeros a esquerda, rejeita vazio
     * (inclusive "0000..." que normalizaria para string vazia).
     *
     * Achado bloqueante da fase 1 de testes (2026-09-14): a validacao
     * anterior nao limitava o tamanho do valor apos a normalizacao, o que
     * permitia que um numero acima do tamanho da coluna
     * tb_atendimento_nota.numero_nota (VARCHAR(20)) — por exemplo, uma
     * chave de acesso de 44 digitos capturada por engano pelo OCR — fosse
     * truncado SILENCIOSAMENTE pelo MySQL/MariaDB antes de gravar. Agora
     * o comprimento maximo plausivel de um numero de NF-e (9 digitos, teto
     * do proprio layout da chave de acesso: posicoes 26-34) e validado
     * explicitamente ANTES de qualquer tentativa de persistencia — nunca
     * trunca, sempre rejeita.
     *
     * @throws \InvalidArgumentException 'numero_invalido' se o valor bruto
     *         nao for numerico, normalizar para vazio, ou tiver mais de 9
     *         digitos apos a normalizacao (inclusive uma chave de acesso de
     *         44 digitos, que cai neste mesmo caso).
     * @throws \RuntimeException 'numero_nota_duplicado' se a UNIQUE KEY
     *         uk_atendimento_numero_nota for violada (PDOException de
     *         integridade), 'nota_nao_encontrada' se a ordem nao existir
     *         neste atendimento.
     */
    public function atualizarNumeroNota(int $idAtendimento, int $ordem, string $numeroBruto, string $origem): array
    {
        $nota = $this->notaDao->buscarPorAtendimentoEOrdem($idAtendimento, $ordem);
        if ($nota === null) {
            throw new \RuntimeException('nota_nao_encontrada');
        }

        return $this->gravarNumeroDaNota($nota, $numeroBruto, $origem);
    }

    /**
     * Variante por id_nota (contrato imutavel, decisao D4): localiza a nota
     * dentro do atendimento (com $ordem opcional, que deve coincidir) e
     * aplica exatamente a mesma normalizacao/validacao de
     * atualizarNumeroNota(). $travar = true para uso sob lock de transacao.
     * Nota excluida / id_nota de outro atendimento / ordem divergente =
     * 'nota_nao_encontrada', sem nenhuma escrita.
     */
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
            // Cobre tanto valores "so um pouco acima do limite" quanto o
            // caso especifico de chave de acesso (44 digitos) — mesma
            // categoria de erro, generica para o front-end, sem vazar
            // detalhe de schema/coluna do banco.
            throw new \InvalidArgumentException('numero_invalido');
        }

        try {
            $this->notaDao->atualizarNumero((int) $nota['id_nota'], $normalizado, $origem);
        } catch (\PDOException $e) {
            // 23000 = violacao de integridade (UNIQUE KEY
            // uk_atendimento_numero_nota) — traduzido para categoria fechada,
            // NUNCA deixa vazar a mensagem bruta do driver.
            if ($e->getCode() === '23000') {
                throw new \RuntimeException('numero_nota_duplicado');
            }
            throw $e;
        }

        return ['numero_nota' => $normalizado, 'id_nota' => (int) $nota['id_nota'], 'ordem' => (int) $nota['ordem']];
    }

    /**
     * Estado DERIVADO do cliente do atendimento, calculado a cada consulta a
     * partir das notas ATIVAS (excluir uma nota ou resolver um conflito
     * recalcula sozinho; nada e copiado entre notas). Fonte unica de decisao
     * de concluir-digitalizacao, excluir e identificar-cliente.
     *
     *  - IDENTIFICADO: exatamente 1 cliente ATIVO distinto (por id_cliente) e
     *    nenhuma nota em ERRO;
     *  - NAO_IDENTIFICADO: zero correspondencias e nenhuma nota em ERRO;
     *  - ANOMALIA: 2 ou mais clientes distintos (motivo CONFLITO) ou
     *    qualquer nota em ERRO (motivo INDETERMINADO; CONFLITO prevalece se
     *    houver os dois). Independe da ordem de chegada das notas.
     *  - $exigirTerminais (F1, so a conclusao usa): nota ainda PENDENTE/
     *    PROCESSANDO (a esta altura so as ja expiradas, pois o chamador
     *    bloqueia antes as que estao dentro do teto) vale como cliente
     *    INDETERMINADO: ANOMALIA/INDETERMINADO, sem cliente persistido
     *    (fallback manual). Sem a flag (identificar-cliente, excluir) o
     *    estado continua refletindo so os resultados ja terminais.
     *
     * $travar = true (dentro de transacao) primeiro trava as notas ativas
     * (SELECT ... FOR UPDATE, unica leitura travada — a consulta com JOIN em
     * tb_cliente e leitura simples para nao travar linhas de tb_cliente
     * compartilhadas entre atendimentos; o snapshot so nasce depois dos
     * locks, entao enxerga tudo o que foi confirmado).
     *
     * @return array{estado: string, motivo: ?string, cliente: ?array{id:mixed, razao_social:mixed, cnpj:mixed}}
     */
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

    /**
     * Forma publica de `cliente_atendimento` nas respostas (identificar-
     * cliente e excluir): so estado e, SE identificado, o cliente da
     * allowlist; nunca motivo nem dado de candidato (anomalia nao expoe
     * dados de terceiros).
     *
     * @return array{estado: string, cliente: ?array}
     */
    public static function clienteAtendimentoPublico(array $avaliacao): array
    {
        return [
            'estado'  => $avaliacao['estado'],
            'cliente' => $avaliacao['estado'] === self::CLIENTE_IDENTIFICADO ? $avaliacao['cliente'] : null,
        ];
    }

    /**
     * Endpoint identificar-cliente (demanda recebimento-leitura-notas,
     * reescrito em hardening-revisao-notas-e-cliente, 2026-09-30).
     * Composicao de avaliarNota() + persistirResultadoNota() SEM transacao
     * (o NotaController chama as duas fases separadamente: calcula fora do
     * lock e persiste sob lock). IDOR/posse do atendimento e da nota ja
     * devem ter sido validados pelo chamador antes desta chamada.
     *
     * A chave de acesso (44 digitos) continua removida do processo de
     * identificacao (decisao de 2026-09-04): $chaveOcr e ignorado por
     * completo, mantido na assinatura so por compatibilidade.
     *
     * @param array<int, string> $cnpjsCandidatos strings brutas, com ou sem mascara
     */
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

    /**
     * Fase 1 (sem escrita, pode rodar fora do lock): avalia os candidatos
     * de UMA nota. Regras (decisao D2: tb_cliente ATIVA e a unica allowlist;
     * candidato que nao existe como cliente ativo e ignorado — inclusive
     * transportadora e UDLOG):
     *  - coleta TODOS os CNPJs validos (sem early-stop e sem break no
     *    primeiro que casa), deduplica por id_cliente;
     *  - 1 cliente = IDENTIFICADA; 2 ou mais = ERRO (MULTIPLAS_CORRESPONDENCIAS);
     *  - razao social (fuzzy) SO quando ha zero casamentos por CNPJ: unica e
     *    inequivoca = IDENTIFICADA; ambigua = ERRO (RAZAO_SOCIAL_AMBIGUA);
     *    abaixo do limiar = NAO_IDENTIFICADA;
     *  - cliente sem CNPJ persistivel (CNPJ de tb_cliente nao e um CNPJ
     *    valido de 14 digitos como gravado) vale como nenhuma
     *    correspondencia: nao renormaliza e nao grava NULL;
     *  - falha tecnica = ERRO (ERRO_TECNICO), prevalece sobre o resto.
     *
     * @return array{status: string, motivo: ?string, cliente: ?array, cnpj: ?string}
     */
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
        // remove duplicados preservando a ordem recebida em cnpjs_candidatos
        $candidatosValidos = array_values(array_unique($candidatosValidos));

        // Passo CNPJ exato contra tb_cliente ATIVA (ClienteDao::buscarPorCnpj
        // ja filtra ativo = 1), TODOS os candidatos, deduplicando por
        // id_cliente.
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

        // Fuzzy de razao social so com ZERO casamentos por CNPJ.
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

    /**
     * Fase 2 (escrita): grava o resultado de UMA nota uma unica vez (UPDATE
     * condicionado a id_nota, id_atendimento e status_ocr PENDENTE/
     * PROCESSANDO); 0 linhas = resultado ja gravado, rele e devolve o que
     * esta gravado (idempotente, nunca sobrescreve). Em seguida deriva o
     * estado do cliente do atendimento. Deve rodar sob lock do atendimento
     * (o NotaController garante); lanca \RuntimeException('nota_nao_encontrada')
     * se a nota nao existe mais no atendimento (excluida).
     *
     * @param array{status: string, motivo: ?string, cliente: ?array, cnpj: ?string} $avaliacao
     */
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

            // resultado ja gravado: a resposta SEMPRE reflete o gravado (nunca
            // o que esta chamada calculou). O motivo de ERRO so e conhecido
            // quando o gravado coincide com o calculado agora.
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

    /** @return array{status: string, motivo: ?string, cliente: null, cnpj: null} */
    private function resultadoErro(string $motivo): array
    {
        return ['status' => 'ERRO', 'motivo' => $motivo, 'cliente' => null, 'cnpj' => null];
    }

    /**
     * Cliente "persistivel": o CNPJ de tb_cliente, exatamente como gravado,
     * e um CNPJ valido de 14 digitos (igual ao proprio resultado da
     * normalizacao). Senao vale como nenhuma correspondencia — nao
     * renormaliza e nao grava NULL em cnpj_emitente.
     */
    private function cnpjPersistivel(array $clienteLocal): bool
    {
        $cnpj = (string) ($clienteLocal['cnpj'] ?? '');

        return $cnpj !== '' && CnpjValidador::normalizarEValidar($cnpj) === $cnpj;
    }

    /**
     * Log tecnico SANITIZADO (mesmo padrao de logFalhaTecnica dos
     * controllers): texto fixo + classe da excecao + ids inteiros. Nunca
     * getMessage(), trace, CNPJ, razao social ou nome de cliente.
     */
    private function logFalhaTecnica(string $contexto, \Throwable $e, int $idAtendimento, int $idNota): void
    {
        error_log($contexto . ' [' . get_class($e) . '] id_atendimento=' . $idAtendimento . ' id_nota=' . $idNota);
        LogSistema::registrar('erro_tecnico', ['tipo' => 'recebimento', 'excecao' => $e]);
    }

    /**
     * Normaliza o shape do cliente vindo de tb_cliente local
     * (id_cliente/nome/cnpj/...) para o mesmo shape usado antes pela API
     * externa (id/razao_social/cnpj), preservando o contrato de resposta do
     * endpoint identificar-cliente mesmo com a troca de fonte de dado.
     * Usado tanto no resultado do CNPJ exato (ClienteDao::buscarPorCnpj,
     * "nome" = razao social original) quanto no resultado do fuzzy
     * (ClienteDao::listarParaFuzzy, que tambem carrega "nome" junto do
     * campo "razao_social" pre-normalizado usado so para o calculo do
     * score) — em ambos os casos "nome" e a razao social original, exibida
     * ao chamador em vez da versao normalizada.
     */
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

    // decodificacao 100% local, sem custo e sem chamada externa
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
