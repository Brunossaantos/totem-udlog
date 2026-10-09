<?php

namespace App\Controller;

use App\Rn\NotaFiscalRn;
use App\Dao\AtendimentoDao;
use App\Dao\RateLimitOcrDao;
use Util\LogSistema;
use Util\NotaArquivoStorage;
use Util\UploadHelper;
use Util\Resposta;

class NotaController
{
    private const RATE_LIMIT_JANELA_SEGUNDOS = 60;
    private const RATE_LIMIT_MAX_CHAMADAS = 30;

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

    private function logFalhaBancoPdo(string $contexto, \PDOException $e): void
    {
        $sqlstate = (string) $e->getCode();
        $sqlstateValidado = preg_match('/^[A-Z0-9]{5}$/', $sqlstate) === 1 ? $sqlstate : null;

        error_log(
            $contexto . ': falha de banco (PDOException)'
            . ($sqlstateValidado !== null ? " [SQLSTATE={$sqlstateValidado}]" : '')
        );
        LogSistema::registrar('erro_banco_pdo', ['tipo' => 'recebimento', 'excecao' => $e]);
    }

    private function logFalhaTecnica(string $contexto, \Throwable $e): void
    {
        error_log($contexto . ': falha nao prevista [' . get_class($e) . ']');
        LogSistema::registrar('erro_tecnico', ['tipo' => 'recebimento', 'excecao' => $e]);
    }


    private function ehFalhaDeLock(\PDOException $e): bool
    {
        $codigoDriver = (int) ($e->errorInfo[1] ?? 0);

        return $codigoDriver === 1205 || $codigoDriver === 1213;
    }

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

    private function responder(array $resultado): void
    {
        $http = (int) ($resultado['http'] ?? 500);
        if ($http >= 400) {
            Resposta::erro((string) ($resultado['erro'] ?? 'Nao foi possivel concluir a operacao'), $http);
            return;
        }

        Resposta::sucesso($resultado['dados'] ?? []);
    }

    private function validarAtendimentoSobLock(int $idAtendimento, int $idTotem): array
    {
        $atendimento = $this->atendimentoDao->buscarPorIdParaUpdate($idAtendimento);

        if (!$atendimento || (int) $atendimento['id_totem'] !== $idTotem || $atendimento['tipo'] !== 'recebimento') {
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

        $ordemInformada = null;
        $brutaOrdem = $entrada['ordem'] ?? null;
        if ($brutaOrdem !== null && $brutaOrdem !== '') {
            $ordemInformada = (int) $brutaOrdem;
            if ($ordemInformada < 1 || $ordemInformada > 5) {
                Resposta::erro('Ordem da nota invalida');
            }
        }

        $chave = is_string($chave) ? $chave : null;

        $resultado = $this->executarSobLock(
            function (array &$compensacoes) use ($idAtendimento, $idTotem, $ordemInformada, $imagemBase64, $chave, $uid): array {
                $validacao = $this->validarAtendimentoSobLock($idAtendimento, $idTotem);
                if ($validacao['http'] !== 200) {
                    return $validacao;
                }
                $atendimento = $validacao['atendimento'];

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

                $compensacoes[] = static function () use ($caminhoArquivo): void {
                    @unlink($caminhoArquivo);
                };

                try {
                    $leitura = $this->notaFiscalRn->processarLeitura($idAtendimento, $ordem, $nomeArquivo, $chave, $uid);
                } catch (\Throwable $e) {
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

    public function identificarCliente(array $entrada, int $idTotem): void
    {
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

        try {
            $atendimento = $this->buscarAtendimentoDoTotem($idAtendimento, $idTotem);

            if ($atendimento['tipo'] !== 'recebimento') {
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

    private function verificarRateLimit(int $idTotem): void
    {
        try {
            $agora = time();
            $janela = intdiv($agora, self::RATE_LIMIT_JANELA_SEGUNDOS);
            $contador = $this->rateLimitOcrDao->incrementarEContar($idTotem, $janela);
        } catch (\PDOException $e) {
            throw $e;
        } catch (\Throwable $e) {
            error_log('identificarCliente (rate limit): falha inesperada na dependencia de rate limit');
            LogSistema::registrar('erro_tecnico', ['tipo' => 'recebimento', 'excecao' => $e, 'http' => 503, 'motivo' => 'indisponivel']);
            Resposta::erro('Servico de protecao indisponivel no momento. Tente novamente em instantes.', 503);
            return;
        }

        if ($contador > self::RATE_LIMIT_MAX_CHAMADAS) {
            $segundosRestantes = self::RATE_LIMIT_JANELA_SEGUNDOS - ($agora % self::RATE_LIMIT_JANELA_SEGUNDOS);
            header('Retry-After: ' . $segundosRestantes);
            LogSistema::registrar('rate_limit_ocr_excedido', ['id_totem' => $idTotem]);
            Resposta::erro('Muitas requisicoes de identificacao de cliente em pouco tempo. Tente novamente em instantes.', 429);
        }
    }

    private function buscarAtendimentoDoTotem(int $idAtendimento, int $idTotem): array
    {
        $atendimento = $this->atendimentoDao->buscarPorId($idAtendimento);
        if (!$atendimento || (int) $atendimento['id_totem'] !== $idTotem) {
            Resposta::erro('Atendimento nao encontrado', 404);
        }

        return $atendimento;
    }
}
