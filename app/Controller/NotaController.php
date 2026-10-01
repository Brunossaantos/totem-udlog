<?php

namespace App\Controller;

use App\Rn\NotaFiscalRn;
use App\Dao\AtendimentoDao;
use App\Dao\RateLimitOcrDao;
use Util\NotaArquivoStorage;
use Util\UploadHelper;
use Util\Resposta;

class NotaController
{
    // Rate limit por totem, so para o endpoint identificar-cliente (ver
    // sql/migrations/004_tb_rate_limit_ocr.sql para o raciocinio completo).
    // Achado do security-especialista na etapa de planejamento: sem o rate
    // limit natural da antiga API externa (~60/min, janela nao confirmada),
    // consultar tb_cliente localmente fica barato demais de abusar como
    // "oraculo" de existencia de CNPJ. Janela fixa de 60s, limite de 30
    // chamadas/totem/janela — dimensionado com folga de ~2x sobre o pior
    // caso realista de uso legitimo (ate 5 notas por atendimento, cada uma
    // gerando 1 chamada "normal" + ate 2 retries de rede com backoff do
    // front-end = ate 15 chamadas em rajada).
    private const RATE_LIMIT_JANELA_SEGUNDOS = 60;
    private const RATE_LIMIT_MAX_CHAMADAS = 30;

    // Timeout de espera de lock de linha (innodb_lock_wait_timeout, por
    // sessao) das transacoes curtas de processar/excluir/definir-numero/
    // identificar-cliente (persistencia). Estourou = HTTP 503 sanitizado.
    private const TIMEOUT_LOCK_SEGUNDOS = 5;

    private NotaArquivoStorage $storage;

    public function __construct(
        private NotaFiscalRn $notaFiscalRn,
        private AtendimentoDao $atendimentoDao,
        private RateLimitOcrDao $rateLimitOcrDao,
        ?NotaArquivoStorage $storage = null
    ) {
        $this->storage = $storage ?? new NotaArquivoStorage();
    }

    /**
     * Loga falha de banco de forma minima e segura: nunca inclui getMessage(),
     * getTraceAsString(), getFile() ou getLine() da excecao (podem conter SQL,
     * valores de parametro ou dado pessoal). O SQLSTATE so entra no log se
     * bater estritamente no formato esperado (5 caracteres alfanumericos
     * maiusculos) — nunca confiamos as cegas em getCode().
     */
    private function logFalhaBancoPdo(string $contexto, \PDOException $e): void
    {
        $sqlstate = (string) $e->getCode();
        $sqlstateValidado = preg_match('/^[A-Z0-9]{5}$/', $sqlstate) === 1 ? $sqlstate : null;

        error_log(
            $contexto . ': falha de banco (PDOException)'
            . ($sqlstateValidado !== null ? " [SQLSTATE={$sqlstateValidado}]" : '')
        );
    }

    /**
     * Loga falha tecnica generica de forma minima e segura: nunca inclui
     * getMessage(), getTraceAsString(), getFile() ou getLine() da
     * excecao (podem conter SQL, payload, dado pessoal ou detalhe de
     * integracao externa). Registra so o contexto operacional fixo
     * (acao) + a classe concreta da excecao, suficiente para diferenciar
     * rapidamente o tipo de falha em debug futuro sem vazar conteudo.
     */
    private function logFalhaTecnica(string $contexto, \Throwable $e): void
    {
        error_log($contexto . ': falha nao prevista [' . get_class($e) . ']');
    }

    // ------------------------------------------------------------------
    // Lock de linha e transacao curta (demanda hardening-revisao-notas-e-
    // cliente, 2026-09-30)
    // ------------------------------------------------------------------

    /**
     * Timeout de lock (1205) e deadlock (1213) viram 503 sanitizado.
     */
    private function ehFalhaDeLock(\PDOException $e): bool
    {
        $codigoDriver = (int) ($e->errorInfo[1] ?? 0);

        return $codigoDriver === 1205 || $codigoDriver === 1213;
    }

    /**
     * Executa $acao dentro de UMA transacao curta (BEGIN ... COMMIT) e
     * devolve o resultado como array; NUNCA chama Resposta (exit dentro de
     * try pularia o finally e deixaria transacao/lock abertos): quem chama
     * responde via responder() DEPOIS de a transacao estar fechada.
     *
     * $acao recebe por referencia a lista de compensacoes (callables de
     * efeito em disco, ex.: apagar arquivo recem-gravado, devolver foto da
     * quarentena). Em QUALQUER caminho de insucesso antes do COMMIT (retorno
     * com http >= 400 ou excecao) as compensacoes rodam em ordem inversa
     * AINDA SOB LOCK, e so entao o ROLLBACK. Se o proprio COMMIT falhar as
     * compensacoes rodam em melhor esforco.
     *
     * $acao devolve ['http' => int, 'erro' => string] ou ['http' => 200,
     * 'dados' => array, 'apos_commit' => ?callable] (apos_commit roda depois
     * do COMMIT, falha tolerada).
     *
     * @param callable(array &): array $acao
     * @return array{http:int, erro?:string, dados?:array}
     */
    private function executarSobLock(callable $acao, string $contexto, string $mensagemFalha): array
    {
        try {
            $this->atendimentoDao->iniciarTransacao(self::TIMEOUT_LOCK_SEGUNDOS);
        } catch (\PDOException $e) {
            $this->logFalhaBancoPdo($contexto, $e);
            return $this->resultadoFalhaBanco($e, $mensagemFalha);
        }

        $compensacoes = [];
        $resultado = null;
        $confirmou = false;

        try {
            $resultado = $acao($compensacoes);

            if (($resultado['http'] ?? 500) >= 400) {
                $this->rodarCompensacoes($compensacoes);
                $this->atendimentoDao->desfazerTransacao();
                return $resultado;
            }

            try {
                $this->atendimentoDao->confirmarTransacao();
                $confirmou = true;
            } catch (\PDOException $e) {
                $this->logFalhaBancoPdo($contexto . ' (commit)', $e);
                $this->rodarCompensacoes($compensacoes);
                $this->atendimentoDao->desfazerTransacao();
                return ['http' => 500, 'erro' => $mensagemFalha];
            }
        } catch (\PDOException $e) {
            $this->logFalhaBancoPdo($contexto, $e);
            $this->rodarCompensacoes($compensacoes);
            $this->atendimentoDao->desfazerTransacao();
            return $this->resultadoFalhaBanco($e, $mensagemFalha);
        } catch (\Throwable $e) {
            $this->logFalhaTecnica($contexto, $e);
            $this->rodarCompensacoes($compensacoes);
            $this->atendimentoDao->desfazerTransacao();
            return ['http' => 500, 'erro' => $mensagemFalha];
        } finally {
            if (!$confirmou) {
                try {
                    $this->atendimentoDao->desfazerTransacao();
                } catch (\Throwable $e) {
                    // nada a fazer: a conexao e descartada no fim da requisicao
                }
            }
        }

        if (isset($resultado['apos_commit']) && is_callable($resultado['apos_commit'])) {
            try {
                ($resultado['apos_commit'])();
            } catch (\Throwable $e) {
                $this->logFalhaTecnica($contexto . ' (apos commit)', $e);
            }
        }
        unset($resultado['apos_commit']);

        return $resultado;
    }

    private function resultadoFalhaBanco(\PDOException $e, string $mensagemFalha): array
    {
        if ($this->ehFalhaDeLock($e)) {
            return ['http' => 503, 'erro' => 'Servico ocupado no momento. Tente novamente em instantes.'];
        }

        return ['http' => 500, 'erro' => $mensagemFalha];
    }

    /**
     * @param array<int, callable> $compensacoes
     */
    private function rodarCompensacoes(array $compensacoes): void
    {
        foreach (array_reverse($compensacoes) as $compensacao) {
            try {
                $compensacao();
            } catch (\Throwable $e) {
                error_log('nota: falha ao compensar efeito em disco [' . get_class($e) . ']');
            }
        }
    }

    /**
     * Responde (exit) o resultado de executarSobLock(), SEMPRE depois de a
     * transacao estar fechada.
     */
    private function responder(array $resultado): void
    {
        $http = (int) ($resultado['http'] ?? 500);
        if ($http >= 400) {
            Resposta::erro((string) ($resultado['erro'] ?? 'Nao foi possivel concluir a operacao'), $http);
            return;
        }

        Resposta::sucesso($resultado['dados'] ?? []);
    }

    /**
     * Revalida, SOB LOCK (linha de tb_atendimento travada), posse, tipo
     * (recebimento), status e etapa. Devolve null se tudo ok, ou o resultado
     * de erro. Mensagens identicas as checagens fora do lock.
     */
    private function validarAtendimentoSobLock(int $idAtendimento, int $idTotem): array
    {
        $atendimento = $this->atendimentoDao->buscarPorIdParaUpdate($idAtendimento);

        if (!$atendimento || (int) $atendimento['id_totem'] !== $idTotem || $atendimento['tipo'] !== 'recebimento') {
            // mensagem generica: nao revela que o atendimento existe mas eh de outro tipo/totem
            return ['http' => 404, 'erro' => 'Atendimento nao encontrado'];
        }
        if ($atendimento['status'] !== 'em_andamento') {
            return ['http' => 400, 'erro' => 'Atendimento nao esta em andamento'];
        }
        if ($atendimento['etapa_atual'] !== 'digitalizacao_notas') {
            return ['http' => 400, 'erro' => 'Atendimento nao esta na etapa de digitalizacao de notas'];
        }

        return ['http' => 200, 'atendimento' => $atendimento];
    }

    /**
     * id_nota (opcional) e ordem (opcional) da entrada. Retorna
     * [?int $idNota, ?int $ordem, ?string $erro]. Ausente (null/'' /chave
     * inexistente) = null; presente e invalido = erro. id_nota e sempre
     * inteiro positivo; ordem 1 a 5.
     */
    private function lerIdentificadoresDaNota(array $entrada): array
    {
        $idNota = null;
        $ordem = null;

        $brutoId = $entrada['id_nota'] ?? null;
        if ($brutoId !== null && $brutoId !== '') {
            if (!(is_int($brutoId) || (is_string($brutoId) && ctype_digit($brutoId))) || (int) $brutoId < 1) {
                return [null, null, 'Dados incompletos'];
            }
            $idNota = (int) $brutoId;
        }

        $brutaOrdem = $entrada['ordem'] ?? null;
        if ($brutaOrdem !== null && $brutaOrdem !== '') {
            $ordem = (int) $brutaOrdem;
            if ($ordem < 1 || $ordem > 5) {
                return [null, null, 'Ordem da nota invalida'];
            }
        }

        return [$idNota, $ordem, null];
    }

    public function processar(array $entrada, int $idTotem): void
    {
        $idAtendimento = (int) ($entrada['id_atendimento'] ?? 0);
        $imagemBase64 = $entrada['imagem'] ?? null;
        $chave = $entrada['chave'] ?? null;

        // uid (rodada corretiva F7, 2026-10-01): identificador gerado pelo
        // front, OPCIONAL (ausente = comportamento anterior). Formato
        // ^[A-Za-z0-9_-]{8,64}$ validado com regex ancorada (D). Com uid, o
        // processar e IDEMPOTENTE: retry com o mesmo uid no mesmo atendimento
        // devolve a nota ja criada sem duplicar nem regravar o arquivo, e a
        // imagem passa a ser opcional (o front pode so recuperar a nota).
        $uid = $entrada['uid'] ?? null;
        if ($uid === '') {
            $uid = null;
        }
        if ($uid !== null && !NotaFiscalRn::clientUidValido($uid)) {
            Resposta::erro('Identificador da nota invalido');
        }

        if (!$idAtendimento || (!$imagemBase64 && $uid === null)) {
            Resposta::erro('Dados incompletos');
        }

        // ordem agora e OPCIONAL (decisao D4): ausente = o servidor aloca a
        // primeira ordem livre de 1 a 5 sob lock; informada e ocupada continua
        // HTTP 400; informada fora de 1..5 = 400.
        $ordemInformada = null;
        $brutaOrdem = $entrada['ordem'] ?? null;
        if ($brutaOrdem !== null && $brutaOrdem !== '') {
            $ordemInformada = (int) $brutaOrdem;
            if ($ordemInformada < 1 || $ordemInformada > 5) {
                Resposta::erro('Ordem da nota invalida');
            }
        }

        $chave = is_string($chave) ? $chave : null;

        // Toda a decisao roda sob lock de linha de tb_atendimento em
        // transacao curta: contagem, alocacao de ordem, gravacao do arquivo
        // e INSERT sao atomicos em relacao a outros processar/excluir/
        // concluir do mesmo atendimento. PDOException sanitizada (demanda
        // integridade-conclusao-atendimento, 2026-09-16): nunca vaza
        // detalhe do driver nem loga payload/imagem do motorista.
        $resultado = $this->executarSobLock(
            function (array &$compensacoes) use ($idAtendimento, $idTotem, $ordemInformada, $imagemBase64, $chave, $uid): array {
                $validacao = $this->validarAtendimentoSobLock($idAtendimento, $idTotem);
                if ($validacao['http'] !== 200) {
                    return $validacao;
                }
                $atendimento = $validacao['atendimento'];

                // F7: uid ja conhecido NESTE atendimento (lookup sempre escopado
                // por id_atendimento e sob o lock do atendimento) = devolve a
                // mesma nota, sem escrita em banco nem em disco. O uid de outro
                // atendimento nunca e alcancado (e nao e tratado diferente de
                // um uid inexistente).
                if ($uid !== null) {
                    $existente = $this->notaFiscalRn->buscarNotaPorClientUid($idAtendimento, $uid, true);
                    if ($existente !== null) {
                        return ['http' => 200, 'dados' => [
                            'cliente_identificado' => false,
                            'cliente'              => null,
                            'id_nota'              => (int) $existente['id_nota'],
                            'ordem'                => (int) $existente['ordem'],
                            'uid'                  => $uid,
                            'reaproveitada'        => true,
                        ]];
                    }
                    if (!$imagemBase64) {
                        // uid desconhecido e sem imagem: nada a recuperar
                        return ['http' => 400, 'erro' => 'Dados incompletos'];
                    }
                }

                if ($this->notaFiscalRn->contarNotas($idAtendimento) >= 5) {
                    return ['http' => 400, 'erro' => 'Limite de 5 notas fiscais ja atingido para esse atendimento'];
                }

                if ($ordemInformada !== null) {
                    if ($this->notaFiscalRn->ordemJaRegistrada($idAtendimento, $ordemInformada)) {
                        return ['http' => 400, 'erro' => 'Ja existe uma nota registrada para essa ordem'];
                    }
                    $ordem = $ordemInformada;
                } else {
                    $ordem = $this->notaFiscalRn->primeiraOrdemLivre($idAtendimento);
                    if ($ordem === null) {
                        return ['http' => 400, 'erro' => 'Limite de 5 notas fiscais ja atingido para esse atendimento'];
                    }
                }

                $base64Limpo = null;
                if (is_string($imagemBase64)) {
                    $semPrefixo = preg_replace('#^data:image/jpeg;base64,#', '', $imagemBase64);
                    $base64Limpo = base64_decode($semPrefixo, true);
                }
                if ($base64Limpo === null || $base64Limpo === false || $base64Limpo === '') {
                    return ['http' => 400, 'erro' => 'Imagem invalida'];
                }

                $nomeArquivo = sprintf('nota_%02d.jpg', $ordem);

                try {
                    $caminhoArquivo = UploadHelper::salvarImagemBase64($imagemBase64, (string) $atendimento['pasta_documentos'], $nomeArquivo);
                } catch (\RuntimeException $e) {
                    return ['http' => 400, 'erro' => 'Nao foi possivel salvar a imagem da nota'];
                }

                // Se qualquer coisa falhar ate o COMMIT, o arquivo recem-gravado
                // e apagado AINDA SOB LOCK (nunca apaga arquivo legitimo de
                // outra requisicao concorrente).
                $compensacoes[] = static function () use ($caminhoArquivo): void {
                    @unlink($caminhoArquivo);
                };

                try {
                    // a identificacao do cliente NAO roda aqui: e feita por
                    // nota.php?acao=identificar-cliente (OCR client-side)
                    $leitura = $this->notaFiscalRn->processarLeitura($idAtendimento, $ordem, $nomeArquivo, $chave, $uid);
                } catch (\Throwable $e) {
                    // insercao falhou depois do arquivo ja gravado — o arquivo e
                    // apagado pela compensacao, sob lock
                    $this->logFalhaTecnica("processar id_atendimento={$idAtendimento}", $e);
                    return ['http' => 500, 'erro' => 'Nao foi possivel registrar a nota'];
                }

                if ($uid !== null) {
                    $leitura['uid'] = $uid;
                    $leitura['reaproveitada'] = false;
                }

                return ['http' => 200, 'dados' => $leitura];
            },
            'processar',
            'Nao foi possivel registrar a nota'
        );

        $this->responder($resultado);
    }

    /**
     * Endpoint nota.php?acao=identificar-cliente (demanda
     * recebimento-leitura-notas). Recebe candidatos extraidos por OCR
     * client-side (Tesseract.js, fora deste escopo) — entrada tratada como
     * nao confiavel, validada/normalizada dentro de NotaFiscalRn.
     *
     * Decisao aprovada em 2026-09-04: `chave_ocr` foi removido do processo
     * de identificacao (extracao de 44 digitos via OCR se mostrou
     * estruturalmente fragil em diagnostico real). O campo continua aceito
     * no payload por compatibilidade (o front-end pode mandar `null` ou
     * omitir o campo), mas e completamente IGNORADO aqui — nao validado,
     * nao repassado para logica de negocio.
     *
     * hardening-revisao-notas-e-cliente (2026-09-30): a nota e localizada
     * por id_nota (novo) e/ou ordem (compat; se vierem os dois devem
     * coincidir, senao 404). Calcula FORA do lock e trava so para persistir.
     */
    public function identificarCliente(array $entrada, int $idTotem): void
    {
        // Rate limit por totem — roda ANTES de qualquer outra validacao de
        // negocio, logo apos Auth::validarTotem() ja ter sido feito por
        // quem despachou para este metodo (nota.php), ja que o limite e por
        // totem autenticado (nao por IP anonimo). Responde 429 + Retry-After
        // e encerra a requisicao (Resposta::erro faz exit()) se excedido.
        //
        // PDOException sanitizada (achado do qa-testes/security-especialista
        // na revisao de 2026-09-16 desta mesma demanda): incrementarEContar()
        // executa 2 queries reais (INSERT ... ON DUPLICATE KEY UPDATE +
        // SELECT) e antes rodava fora de qualquer try/catch deste metodo —
        // uma falha de banco aqui propagaria ate o handler padrao do PHP.
        // Retorna imediatamente em caso de excecao, antes de qualquer OCR ou
        // outra logica; nao ha reexecucao de incrementarEContar() apos a
        // captura, entao nao ha risco de dupla contabilizacao do rate limit.
        try {
            $this->verificarRateLimit($idTotem);
        } catch (\PDOException $e) {
            $this->logFalhaBancoPdo('identificarCliente (rate limit)', $e);
            Resposta::erro('Nao foi possivel identificar o cliente', 500);
            return;
        }

        $idAtendimento = (int) ($entrada['id_atendimento'] ?? 0);
        $cnpjsCandidatos = $entrada['cnpjs_candidatos'] ?? [];
        $razaoSocialCandidata = $entrada['razao_social_candidata'] ?? null;

        [$idNota, $ordem, $erroIdentificadores] = $this->lerIdentificadoresDaNota($entrada);

        if (!$idAtendimento || ($idNota === null && $ordem === null && $erroIdentificadores === null)) {
            Resposta::erro('Dados incompletos');
        }
        if ($erroIdentificadores !== null) {
            Resposta::erro($erroIdentificadores);
        }

        if (!is_array($cnpjsCandidatos)) {
            $cnpjsCandidatos = [];
        }
        if ($razaoSocialCandidata !== null && !is_string($razaoSocialCandidata)) {
            $razaoSocialCandidata = null;
        }

        // PDOException sanitizada (ver processar() acima) — cobre
        // buscarAtendimentoDoTotem()/busca da nota, antes fora de qualquer
        // protecao. Checagem previa SEM lock (evita computar para entrada
        // invalida); tudo e revalidado sob lock na persistencia.
        try {
            $atendimento = $this->buscarAtendimentoDoTotem($idAtendimento, $idTotem);

            if ($atendimento['tipo'] !== 'recebimento') {
                // mensagem generica: nao revela que o atendimento existe mas eh de outro tipo/totem
                Resposta::erro('Atendimento nao encontrado', 404);
            }

            if ($atendimento['status'] !== 'em_andamento') {
                Resposta::erro('Atendimento nao esta em andamento');
            }

            if ($atendimento['etapa_atual'] !== 'digitalizacao_notas') {
                Resposta::erro('Atendimento nao esta na etapa de digitalizacao de notas');
            }

            $nota = $this->notaFiscalRn->buscarNotaDoAtendimento($idAtendimento, $idNota, $ordem);
            if ($nota === null) {
                Resposta::erro('Nota nao encontrada para essa ordem', 404);
            }
        } catch (\PDOException $e) {
            $this->logFalhaBancoPdo('identificarCliente', $e);
            Resposta::erro('Nao foi possivel identificar o cliente', 500);
            return;
        }

        $idNotaResolvida = (int) $nota['id_nota'];

        try {
            $avaliacao = $this->notaFiscalRn->avaliarNota(
                $idAtendimento,
                $idNotaResolvida,
                array_values(array_filter($cnpjsCandidatos, 'is_string')),
                $razaoSocialCandidata
            );
        } catch (\Throwable $e) {
            // falha nao prevista (a maioria dos casos de erro tecnico ja e
            // tratada dentro de NotaFiscalRn::avaliarNota, que devolve
            // status ERRO sem lancar excecao) — mesmo padrao de processar():
            // log tecnico sanitizado no servidor, resposta generica ao totem,
            // nunca vaza mensagem/stack trace da excecao.
            $this->logFalhaTecnica("identificar-cliente id_atendimento={$idAtendimento}", $e);
            Resposta::erro('Nao foi possivel identificar o cliente', 500);
            return;
        }

        $resultado = $this->executarSobLock(
            function (array &$compensacoes) use ($idAtendimento, $idTotem, $idNotaResolvida, $ordem, $avaliacao): array {
                $validacao = $this->validarAtendimentoSobLock($idAtendimento, $idTotem);
                if ($validacao['http'] !== 200) {
                    return $validacao;
                }

                // nota excluida (ou id_nota reaproveitado por outra nota) = 404 sem escrita
                $nota = $this->notaFiscalRn->buscarNotaDoAtendimento($idAtendimento, $idNotaResolvida, $ordem, true);
                if ($nota === null) {
                    return ['http' => 404, 'erro' => 'Nota nao encontrada para essa ordem'];
                }

                try {
                    $dados = $this->notaFiscalRn->persistirResultadoNota($idAtendimento, $idNotaResolvida, $avaliacao);
                } catch (\RuntimeException $e) {
                    return ['http' => 404, 'erro' => 'Nota nao encontrada para essa ordem'];
                }

                return ['http' => 200, 'dados' => $dados];
            },
            'identificarCliente',
            'Nao foi possivel identificar o cliente'
        );

        $this->responder($resultado);
    }

    /**
     * Endpoint nota.php?acao=definir-numero (demanda
     * talent-doctos-finalizacao-checkin, 2026-09-14) — grava o numero da
     * NF-e (OCR confirmado pelo motorista ou digitado manualmente).
     * Normalizacao (so digitos, sem zero a esquerda) e SEMPRE feita no
     * backend (App\Rn\NotaFiscalRn::atualizarNumeroNota), nunca confia no
     * que o front envia como "ja normalizado". Mesmo padrao de
     * posse/tipo/status/etapa das demais acoes deste controller.
     *
     * hardening-revisao-notas-e-cliente (2026-09-30): aceita id_nota (novo;
     * identidade imutavel) e/ou ordem (compat; se vierem os dois devem
     * coincidir, senao 404). Roda sob lock de linha do atendimento; nota
     * excluida = 404 sem escrita.
     */
    public function definirNumero(array $entrada, int $idTotem): void
    {
        $idAtendimento = (int) ($entrada['id_atendimento'] ?? 0);
        $numero = $entrada['numero'] ?? null;
        $origem = $entrada['origem'] ?? null;

        [$idNota, $ordem, $erroIdentificadores] = $this->lerIdentificadoresDaNota($entrada);

        if (
            !$idAtendimento || !is_string($numero) || trim($numero) === '' || !in_array($origem, ['OCR', 'MANUAL'], true)
            || ($idNota === null && $ordem === null && $erroIdentificadores === null)
        ) {
            Resposta::erro('Dados incompletos');
        }
        if ($erroIdentificadores !== null) {
            Resposta::erro($erroIdentificadores);
        }

        $resultado = $this->executarSobLock(
            function (array &$compensacoes) use ($idAtendimento, $idTotem, $idNota, $ordem, $numero, $origem): array {
                $validacao = $this->validarAtendimentoSobLock($idAtendimento, $idTotem);
                if ($validacao['http'] !== 200) {
                    return $validacao;
                }

                try {
                    $dados = $this->notaFiscalRn->atualizarNumeroNotaPorIdentificador(
                        $idAtendimento,
                        $idNota,
                        $ordem,
                        trim($numero),
                        $origem,
                        true
                    );
                } catch (\InvalidArgumentException $e) {
                    return ['http' => 400, 'erro' => 'Numero de nota invalido — informe apenas os digitos da NF-e'];
                } catch (\RuntimeException $e) {
                    if ($e->getMessage() === 'numero_nota_duplicado') {
                        return ['http' => 409, 'erro' => 'Ja existe uma nota com esse numero neste atendimento (NUMERO_NOTA_DUPLICADO)'];
                    }
                    if ($e->getMessage() === 'nota_nao_encontrada') {
                        return ['http' => 404, 'erro' => 'Nota nao encontrada para essa ordem'];
                    }
                    $this->logFalhaTecnica("definir-numero id_atendimento={$idAtendimento}" . ($ordem !== null ? " ordem={$ordem}" : ''), $e);
                    return ['http' => 500, 'erro' => 'Nao foi possivel gravar o numero da nota'];
                }

                return ['http' => 200, 'dados' => $dados];
            },
            'definirNumero',
            'Nao foi possivel gravar o numero da nota'
        );

        $this->responder($resultado);
    }

    /**
     * Endpoint nota.php?acao=excluir (demanda hardening-revisao-notas-e-
     * cliente, 2026-09-30, decisoes D3/D4). Exclui UMA nota por id_nota
     * (identidade imutavel, nunca reutilizada) dentro de UM atendimento em
     * digitalizacao. Sequencia sob lock de linha (innodb_lock_wait_timeout
     * de 5 s), na ordem atendimento -> nota:
     *   1. BEGIN; FOR UPDATE em tb_atendimento; posse/tipo/status/etapa;
     *   2. nota por id_nota E id_atendimento FOR UPDATE (ausente =
     *      ja_excluida, 200, sem escrita);
     *   3. caminho derivado SO no servidor (STORAGE_PATH + pasta_documentos +
     *      arquivo do banco, formatos validados, realpath com prefixo, sem
     *      symlink); invalido = 500 sem tocar disco nem banco;
     *   4. quarentena por rename atomico (ausente = segue; falha = 500);
     *   5. DELETE exigindo rowCount 1;
     *   6. COMMIT e SO ENTAO unlink do .del (falha tolerada, o cron cobre).
     * Qualquer falha antes do COMMIT devolve a foto (compensacao sob lock) e
     * faz ROLLBACK. Nunca chama Resposta entre o rename e o COMMIT. Campos
     * de caminho/arquivo no request sao IGNORADOS.
     */
    public function excluir(array $entrada, int $idTotem): void
    {
        $idAtendimento = (int) ($entrada['id_atendimento'] ?? 0);

        [$idNota, , $erroIdentificadores] = $this->lerIdentificadoresDaNota(['id_nota' => $entrada['id_nota'] ?? null]);

        if (!$idAtendimento || $idNota === null || $erroIdentificadores !== null) {
            Resposta::erro('Dados incompletos');
        }

        $resultado = $this->executarSobLock(
            function (array &$compensacoes) use ($idAtendimento, $idTotem, $idNota): array {
                $validacao = $this->validarAtendimentoSobLock($idAtendimento, $idTotem);
                if ($validacao['http'] !== 200) {
                    return $validacao;
                }
                $atendimento = $validacao['atendimento'];

                $nota = $this->notaFiscalRn->buscarNotaDoAtendimento($idAtendimento, $idNota, null, true);
                if ($nota === null) {
                    // id_nota inexistente neste atendimento (ja excluida, ou de
                    // outro atendimento): idempotente e sem vazar existencia.
                    return ['http' => 200, 'dados' => $this->montarRespostaExclusao($idAtendimento, $idNota, false, true)];
                }

                $caminho = $this->storage->caminhoDoArquivo($atendimento['pasta_documentos'] ?? null, $nota['arquivo'] ?? null);
                if ($caminho === null) {
                    error_log("excluir-nota: caminho do arquivo invalido, nenhum acesso a disco (id_atendimento={$idAtendimento} id_nota={$idNota})");
                    return ['http' => 500, 'erro' => 'Nao foi possivel excluir a nota'];
                }

                try {
                    $situacao = $this->storage->quarentenar($caminho, $idNota);
                } catch (\RuntimeException $e) {
                    error_log("excluir-nota: falha ao mover a foto para a quarentena (id_atendimento={$idAtendimento} id_nota={$idNota})");
                    return ['http' => 500, 'erro' => 'Nao foi possivel excluir a nota'];
                }

                if ($situacao === 'quarentenado') {
                    $compensacoes[] = function () use ($caminho, $idNota, $idAtendimento): void {
                        // F4 (rodada corretiva 2026-10-01): restaurar() devolve
                        // false quando nao conseguiu devolver a foto. Antes isso
                        // passava em silencio. Contexto FIXO sanitizado: so ids
                        // inteiros, nunca caminho, nome do arquivo, excecao
                        // bruta, trace ou dado da nota. Consistencia: o
                        // ROLLBACK segue (a linha continua existindo) e a foto
                        // fica em quarentena com nome reconhecivel; um novo
                        // excluir da mesma nota reconhece 'ja_em_quarentena' e
                        // conclui a exclusao.
                        if (!$this->storage->restaurar($caminho, $idNota)) {
                            error_log("excluir-nota: FALHA ao restaurar a foto da quarentena apos rollback; foto permanece em quarentena (id_atendimento={$idAtendimento} id_nota={$idNota})");
                        }
                    };
                } elseif ($situacao === 'ausente') {
                    error_log("excluir-nota: arquivo_ausente_na_exclusao (id_atendimento={$idAtendimento} id_nota={$idNota})");
                }

                if ($this->notaFiscalRn->excluirNotaPorId($idNota, $idAtendimento) !== 1) {
                    error_log("excluir-nota: DELETE nao afetou exatamente 1 linha (id_atendimento={$idAtendimento} id_nota={$idNota})");
                    return ['http' => 500, 'erro' => 'Nao foi possivel excluir a nota'];
                }

                $dados = $this->montarRespostaExclusao($idAtendimento, $idNota, true, false);

                return [
                    'http'        => 200,
                    'dados'       => $dados,
                    'apos_commit' => function () use ($caminho, $idNota, $idAtendimento): void {
                        if (!$this->storage->remover($caminho, $idNota)) {
                            error_log("excluir-nota: falha ao remover a foto em quarentena, o cron de limpeza cobre (id_atendimento={$idAtendimento} id_nota={$idNota})");
                        }
                    },
                ];
            },
            'excluirNota',
            'Nao foi possivel excluir a nota'
        );

        $this->responder($resultado);
    }

    /**
     * Snapshot devolvido por excluir (lido sob o mesmo lock, antes do
     * COMMIT): notas restantes (id_nota, ordem, numero_definido) e o estado
     * derivado do cliente. Sem numero, CNPJ, caminho ou imagem.
     */
    private function montarRespostaExclusao(int $idAtendimento, int $idNota, bool $excluida, bool $jaExcluida): array
    {
        $ativas = $this->notaFiscalRn->listarNotasAtivasTravadas($idAtendimento);
        $notas = [];
        foreach ($ativas as $n) {
            $notas[] = [
                'id_nota'         => (int) $n['id_nota'],
                'ordem'           => (int) $n['ordem'],
                'numero_definido' => NotaFiscalRn::numeroNotaValido($n['numero_nota']),
            ];
        }

        return [
            'excluida'            => $excluida,
            'ja_excluida'         => $jaExcluida,
            'id_nota'             => $idNota,
            'total_notas'         => count($notas),
            'notas'               => $notas,
            'cliente_atendimento' => NotaFiscalRn::clienteAtendimentoPublico(
                $this->notaFiscalRn->avaliarClienteDoAtendimento($idAtendimento)
            ),
        ];
    }

    /**
     * Endpoint nota.php?acao=listar (rodada corretiva F7, 2026-10-01) --
     * RECONCILIACAO somente leitura: devolve TODAS as notas ativas do
     * atendimento do totem autenticado, para o front recuperar o estado depois
     * de uma resposta perdida ou reload logico (e para o 422/409 nunca apontar
     * nota invisivel): {total_notas, notas:[{id_nota, ordem, uid,
     * numero_definido}]}. `uid` e null para nota criada sem uid. Nunca
     * devolve arquivo, caminho, imagem, numero, CNPJ ou status interno.
     * Posse/tipo/status: atendimento de outro totem, inexistente ou de outro
     * tipo = 404 identico; fora de em_andamento = 400.
     */
    public function listar(int $idAtendimento, int $idTotem): void
    {
        if (!$idAtendimento) {
            Resposta::erro('Dados incompletos');
        }

        try {
            $atendimento = $this->atendimentoDao->buscarPorId($idAtendimento);
            if (!$atendimento || (int) $atendimento['id_totem'] !== $idTotem || $atendimento['tipo'] !== 'recebimento') {
                $erro = ['http' => 404, 'erro' => 'Atendimento nao encontrado'];
            } elseif ($atendimento['status'] !== 'em_andamento') {
                $erro = ['http' => 400, 'erro' => 'Atendimento nao esta em andamento'];
            } else {
                $erro = null;
                $notas = $this->notaFiscalRn->listarNotasParaReconciliacao($idAtendimento);
            }
        } catch (\PDOException $e) {
            $this->logFalhaBancoPdo('listarNotas', $e);
            Resposta::erro('Nao foi possivel consultar as notas', 500);
            return;
        }

        if ($erro !== null) {
            Resposta::erro($erro['erro'], $erro['http']);
            return;
        }

        Resposta::sucesso(['total_notas' => count($notas), 'notas' => $notas]);
    }

    public function algumaIdentificada(int $idAtendimento, int $idTotem): void
    {
        // PDOException sanitizada (ver processar() acima) — antes este
        // metodo nao tinha NENHUM try/catch, cobre buscarAtendimentoDoTotem()
        // e algumaNotaIdentificouCliente().
        try {
            $this->buscarAtendimentoDoTotem($idAtendimento, $idTotem);

            $identificada = $this->notaFiscalRn->algumaNotaIdentificouCliente($idAtendimento);
        } catch (\PDOException $e) {
            $this->logFalhaBancoPdo('algumaIdentificada', $e);
            Resposta::erro('Nao foi possivel consultar a identificacao do cliente', 500);
            return;
        }

        Resposta::sucesso(['cliente_identificado' => $identificada]);
    }

    /**
     * Rate limit por totem para identificar-cliente (ver constantes da
     * classe). Janela fixa de 60s, incremento atomico via RateLimitOcrDao
     * (INSERT ... ON DUPLICATE KEY UPDATE — sem Redis/APCu, compativel com
     * Hostgator).
     *
     * Fail-closed (demanda robustez-rate-limit-migrations, 2026-09-18):
     * RateLimitOcrDao e dependencia OBRIGATORIA do construtor desde esta
     * demanda — o unico chamador em producao (public/api/nota.php) sempre
     * injeta. Como o parametro do construtor e tipado NAO-nullable, a
     * propriedade `$this->rateLimitOcrDao` nunca pode ser lida como `null`
     * em nenhum caminho real (uma tentativa de forcar isso via Reflection
     * resulta em `\Error: Typed property ... must not be accessed before
     * initialization` ao LER a propriedade, antes mesmo de qualquer `=== null`
     * — achado confirmado pelo qa-testes em 2026-09-18).
     *
     * Correcao (achado 1 do qa-testes, mesma data): a antiga checagem
     * `=== null` era codigo morto/inalcancavel — nunca produzia o 503
     * documentado, so um erro fatal cru em qualquer cenario real de falha.
     * Para o requisito original ("responder 503 se a dependencia nao puder
     * ser usada") ser cumprido de fato, TODA a logica que depende de
     * `$this->rateLimitOcrDao` roda dentro de um try/catch. `\PDOException`
     * e deliberadamente RELANCADA (nao capturada aqui) para continuar
     * subindo ate o catch(\PDOException) ja existente no chamador
     * (`identificarCliente()`), que ja responde 500 sanitizado — nao
     * queremos que esse cenario, ja correto, passe a responder 503. Qualquer
     * outro `\Throwable` (incluindo `\Error`/`\TypeError` — ex.: propriedade
     * tipada nao inicializada, falha de acesso inesperada) resulta em 503
     * sanitizado, sem vazar `$e->getMessage()`/stack trace.
     */
    private function verificarRateLimit(int $idTotem): void
    {
        try {
            $agora = time();
            $janela = intdiv($agora, self::RATE_LIMIT_JANELA_SEGUNDOS);
            $contador = $this->rateLimitOcrDao->incrementarEContar($idTotem, $janela);
        } catch (\PDOException $e) {
            // Relancada de proposito: o chamador (identificarCliente()) ja
            // tem seu proprio catch(\PDOException) em volta desta chamada,
            // respondendo 500 sanitizado — comportamento ja correto e
            // testado, nao deve virar 503.
            throw $e;
        } catch (\Throwable $e) {
            // Fail-safe real: qualquer falha inesperada na dependencia de
            // rate limit (incluindo \Error/\TypeError) vira 503 sanitizado,
            // nunca um erro fatal cru. Log interno minimo, sem interpolar
            // a mensagem da excecao (pode conter detalhe tecnico interno).
            error_log('identificarCliente (rate limit): falha inesperada na dependencia de rate limit');
            Resposta::erro('Servico de protecao indisponivel no momento. Tente novamente em instantes.', 503);
            return;
        }

        if ($contador > self::RATE_LIMIT_MAX_CHAMADAS) {
            $segundosRestantes = self::RATE_LIMIT_JANELA_SEGUNDOS - ($agora % self::RATE_LIMIT_JANELA_SEGUNDOS);
            header('Retry-After: ' . $segundosRestantes);
            Resposta::erro('Muitas requisicoes de identificacao de cliente em pouco tempo. Tente novamente em instantes.', 429);
        }
    }

    /**
     * Busca o atendimento e garante que pertence ao totem autenticado.
     * Mensagem de erro generica em qualquer caso de falha (nao existe / eh
     * de outro totem) para nao vazar a existencia de atendimento alheio.
     */
    private function buscarAtendimentoDoTotem(int $idAtendimento, int $idTotem): array
    {
        $atendimento = $this->atendimentoDao->buscarPorId($idAtendimento);
        if (!$atendimento || (int) $atendimento['id_totem'] !== $idTotem) {
            Resposta::erro('Atendimento nao encontrado', 404);
        }

        return $atendimento;
    }
}
