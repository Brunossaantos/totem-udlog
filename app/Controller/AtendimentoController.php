<?php

namespace App\Controller;

use App\Rn\AtendimentoRn;
use App\Rn\TalentRn;
use App\Rn\DocumentoRn;
use App\Rn\OrdemColetaClient;
use App\Rn\LgpdRn;
use App\Rn\NotaFiscalRn;
use App\Dao\AtendimentoNotaDao;
use App\Dao\ClienteDao;
use App\Dao\TotemDao;
use App\Dao\EmpresaDao;
use App\Dao\OrdemColetaPendenteBaixaDao;
use Util\CnpjValidador;
use Util\LogCatalogo;
use Util\LogSistema;
use Util\NotaArquivoStorage;
use Util\Resposta;
use Util\UploadHelper;
use PDO;

class AtendimentoController
{
    public function __construct(
        private AtendimentoRn $atendimentoRn,
        private TalentRn $talentRn,
        private AtendimentoNotaDao $notaDao,
        private ?DocumentoRn $documentoRn = null,
        private ?TotemDao $totemDao = null,
        private ?EmpresaDao $empresaDao = null,
        private ?OrdemColetaClient $ordemColetaClient = null,
        private ?OrdemColetaPendenteBaixaDao $ordemColetaPendenteBaixaDao = null,
        private ?LgpdRn $lgpdRn = null,
        private ?PDO $pdo = null,
        private ?NotaFiscalRn $notaFiscalRn = null,
        private ?ClienteDao $clienteDao = null,
        private ?NotaArquivoStorage $notaArquivoStorage = null
    ) {}

    private function notaFiscalRn(): NotaFiscalRn
    {
        return $this->notaFiscalRn ??= new NotaFiscalRn($this->notaDao, $this->clienteDao);
    }

    private function notaArquivoStorage(): NotaArquivoStorage
    {
        return $this->notaArquivoStorage ??= new NotaArquivoStorage();
    }

    private function clienteDao(): ?ClienteDao
    {
        if ($this->clienteDao === null && $this->pdo !== null) {
            $this->clienteDao = new ClienteDao($this->pdo);
        }

        return $this->clienteDao;
    }

    public function iniciar(int $idTotem, array $entrada): void
    {
        $tipo = $entrada['tipo'] ?? null;
        $placa = $entrada['placa'] ?? null;
        $tokenAceite = $entrada['token_aceite'] ?? null;

        if (!in_array($tipo, ['expedicao', 'recebimento'], true) || empty($placa)) {
            Resposta::erro('Tipo ou placa nao informados');
        }

        if ($this->lgpdRn === null || !$this->lgpdRn->formatoValido($tokenAceite)) {
            Resposta::erro('Aceite de privacidade invalido ou expirado', 409);
        }

        if ($this->pdo === null) {
            Resposta::erro('Nao foi possivel processar o atendimento agora', 500);
        }

        $idAtendimento = $this->consumirAceiteECriarAtendimento($idTotem, $tipo, $placa, $tokenAceite);

        $pasta = UploadHelper::montarPasta($placa);
        $this->atendimentoRn->definirPasta($idAtendimento, $pasta);

        if ($tipo === 'expedicao') {
            try {
                $ordens = $this->atendimentoRn->consultarOrdensAbertas($placa);
            } catch (\Throwable $e) {
                LogSistema::registrar('oc_consulta_falhou', ['id_atendimento' => $idAtendimento, 'id_totem' => $idTotem, 'excecao' => $e, 'http' => 502]);
                Resposta::erro('Nao foi possivel consultar as ordens de coleta agora', 502);
            }

            if (count($ordens) === 0) {
                Resposta::erro('Nenhuma ordem de coleta em aberto para essa placa');
            }
            if (count($ordens) === 1) {
                $this->atendimentoRn->selecionarOrdem($idAtendimento, $ordens[0]);
                Resposta::sucesso(['id_atendimento' => $idAtendimento, 'proxima_tela' => 'dados_encontrados', 'dados' => $ordens[0]]);
            }
            Resposta::sucesso(['id_atendimento' => $idAtendimento, 'proxima_tela' => 'selecionar_ordem', 'ordens' => $ordens]);
        }

        Resposta::sucesso(['id_atendimento' => $idAtendimento, 'proxima_tela' => 'quantidade_notas']);
    }

    private function consumirAceiteECriarAtendimento(int $idTotem, string $tipo, string $placa, string $tokenAceite): int
    {
        $this->pdo->beginTransaction();

        try {
            $idAceite = $this->lgpdRn->consumir($tokenAceite, $idTotem);

            if ($idAceite === null) {
                $this->pdo->rollBack();
                Resposta::erro('Aceite de privacidade invalido ou expirado', 409);
            }

            $idAtendimento = $this->atendimentoRn->iniciar($idTotem, $tipo, $placa, $idAceite);

            $this->atendimentoRn->reconciliarProcessamentoAbandonado($idTotem, $idAtendimento);

            $this->pdo->commit();

            return $idAtendimento;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log('AtendimentoController::iniciar: falha ao consumir aceite LGPD/criar atendimento (id_totem=' . $idTotem . ')');
            Resposta::erro('Nao foi possivel processar o atendimento agora', 500);
        }
    }

    public function selecionarOrdem(array $entrada, int $idTotem): void
    {
        $idAtendimento = (int) ($entrada['id_atendimento'] ?? 0);
        $ordemRecebida = $entrada['ordem'] ?? null;

        if (!$idAtendimento || !is_array($ordemRecebida) || empty($ordemRecebida['numero'])) {
            Resposta::erro('Dados incompletos');
        }

        $atendimento = $this->buscarAtendimentoDoTotem($idAtendimento, $idTotem);

        if ($atendimento['tipo'] !== 'expedicao') {
            Resposta::erro('Atendimento nao encontrado', 404);
        }
        if ($atendimento['status'] !== 'em_andamento') {
            Resposta::erro('Atendimento nao esta em andamento');
        }
        if ($atendimento['etapa_atual'] !== 'placa') {
            Resposta::erro('Atendimento nao esta na etapa esperada para selecionar a ordem');
        }

        $ordensReais = $this->atendimentoRn->consultarOrdensAbertas($atendimento['placa']);
        $numeroRecebido = (string) $ordemRecebida['numero'];

        $ordemReal = null;
        foreach ($ordensReais as $o) {
            if ((string) ($o['numero'] ?? '') === $numeroRecebido) {
                $ordemReal = $o;
                break;
            }
        }

        if ($ordemReal === null) {
            Resposta::erro('Ordem de coleta invalida para essa placa');
        }

        $this->atendimentoRn->selecionarOrdem($idAtendimento, $ordemReal);
        Resposta::sucesso(['proxima_tela' => 'dados_encontrados', 'dados' => $ordemReal]);
    }

    public function salvarEtapa(array $entrada, int $idTotem): void
    {
        $idAtendimento = (int) ($entrada['id_atendimento'] ?? 0);
        $etapa = $entrada['etapa'] ?? null;
        $dados = $entrada['dados'] ?? [];

        if (!$idAtendimento || !$etapa) {
            Resposta::erro('Dados incompletos');
        }

        switch ($etapa) {
            case 'confirmacao':
                $atendimento = $this->buscarAtendimentoDoTotem($idAtendimento, $idTotem);

                if (!in_array($atendimento['tipo'], ['expedicao', 'recebimento'], true)) {
                    Resposta::erro('Atendimento nao encontrado', 404);
                }
                if ($atendimento['status'] !== 'em_andamento') {
                    Resposta::erro('Atendimento nao esta em andamento');
                }
                $etapaEsperadaConfirmacao = $atendimento['tipo'] === 'expedicao' ? 'exp_confirmacao' : 'rec_confirmacao';
                if ($atendimento['etapa_atual'] !== $etapaEsperadaConfirmacao) {
                    Resposta::erro('Atendimento nao esta na etapa esperada para confirmar os dados');
                }

                $ausentes = $this->atendimentoRn->camposObrigatoriosAusentes($atendimento, is_array($dados) ? $dados : []);
                if ($ausentes !== []) {
                    Resposta::erroComDados(
                        'Nao e possivel continuar: preencha ' . implode(', ', array_values($ausentes)),
                        'CONFIRMACAO_INCOMPLETA',
                        ['campos' => array_keys($ausentes), 'rotulos' => array_values($ausentes)],
                        422
                    );
                    return;
                }

                $this->atendimentoRn->salvarDadosMotorista($idAtendimento, $dados);
                break;
            case 'cliente':
                $atendimento = $this->buscarAtendimentoDoTotem($idAtendimento, $idTotem);

                if ($atendimento['tipo'] !== 'recebimento') {
                    Resposta::erro('Atendimento nao encontrado', 404);
                }
                if ($atendimento['status'] !== 'em_andamento') {
                    Resposta::erro('Atendimento nao esta em andamento');
                }
                if ($atendimento['etapa_atual'] !== 'cliente') {
                    Resposta::erro('Atendimento nao esta na etapa esperada para identificar o cliente');
                }

                $clienteDao = $this->clienteDao();
                if ($clienteDao === null) {
                    Resposta::erro('Nao foi possivel confirmar o cliente agora', 500);
                }

                $cnpjEnviado = is_string($dados['cnpj'] ?? null) ? $dados['cnpj'] : null;
                $cnpjNormalizado = CnpjValidador::normalizarEValidar($cnpjEnviado);
                try {
                    $clienteValido = $cnpjNormalizado !== null ? $clienteDao->buscarPorCnpj($cnpjNormalizado) : null;
                } catch (\PDOException $e) {
                    error_log('salvar-etapa cliente: falha de banco ao validar o cliente (PDOException)');
                    Resposta::erro('Nao foi possivel confirmar o cliente agora', 500);
                }
                if ($clienteValido === null) {
                    Resposta::erro('Cliente invalido ou nao cadastrado');
                }

                $this->atendimentoRn->salvarCliente($idAtendimento, (string) $clienteValido['nome'], (string) $clienteValido['cnpj']);
                $this->atendimentoRn->atualizarEtapa($idAtendimento, 'rec_cnh');
                break;
            case 'ajudante':
                $atendimento = $this->buscarAtendimentoDoTotem($idAtendimento, $idTotem);

                if (!in_array($atendimento['tipo'], ['expedicao', 'recebimento'], true)) {
                    Resposta::erro('Atendimento nao encontrado', 404);
                }
                if ($atendimento['status'] === 'concluido') {
                    Resposta::erro('Atendimento ja concluido, nao pode mais ser alterado', 409);
                    return;
                }
                if ($atendimento['status'] !== 'em_andamento') {
                    Resposta::erro('Atendimento nao esta em andamento');
                }
                $etapaEsperadaAjudante = $atendimento['tipo'] === 'expedicao' ? 'exp_confirmacao' : 'rec_confirmacao';
                if ($atendimento['etapa_atual'] !== $etapaEsperadaAjudante) {
                    Resposta::erro('Atendimento nao esta na etapa esperada para informar o ajudante');
                }

                $ajudante = $this->atendimentoRn->normalizarAjudante($dados['nome'] ?? null, $dados['cpf'] ?? null);
                if ($ajudante === null) {
                    Resposta::erro('Dados do ajudante invalidos: informe o nome completo e o CPF com 11 digitos', 422);
                    return;
                }
                if (!$this->atendimentoRn->salvarAjudante($idAtendimento, $ajudante['nome'], $ajudante['cpf'])) {
                    Resposta::erro('Nao e possivel alterar o ajudante: o check-in ja esta em processamento ou foi enviado', 409);
                    return;
                }
                break;
            case 'digitalizacao_notas':
                $atendimento = $this->buscarAtendimentoDoTotem($idAtendimento, $idTotem);

                if ($atendimento['tipo'] !== 'recebimento') {
                    Resposta::erro('Atendimento nao encontrado', 404);
                }
                if ($atendimento['status'] !== 'em_andamento') {
                    Resposta::erro('Atendimento nao esta em andamento');
                }
                if ($atendimento['etapa_atual'] !== 'placa') {
                    Resposta::erro('Atendimento nao esta na etapa esperada para iniciar a digitalizacao');
                }

                $this->atendimentoRn->atualizarEtapa($idAtendimento, 'digitalizacao_notas');
                break;
        }

        Resposta::sucesso(['ok' => true]);
    }

    public function bloquearPorExcessoDeNotas(array $entrada, int $idTotem): void
    {
        $idAtendimento = (int) ($entrada['id_atendimento'] ?? 0);
        $atendimento = $this->buscarAtendimentoDoTotem($idAtendimento, $idTotem);

        if ($atendimento['tipo'] !== 'recebimento') {
            Resposta::erro('Atendimento nao encontrado', 404);
        }

        if (!$this->atendimentoRn->bloquear($idAtendimento)) {
            Resposta::erro('Atendimento ja concluido, nao pode mais ser bloqueado', 409);
            return;
        }

        Resposta::sucesso(['ok' => true]);
    }

    public function concluirDigitalizacao(array $entrada, int $idTotem): void
    {
        $idAtendimento = (int) ($entrada['id_atendimento'] ?? 0);

        if (!$idAtendimento) {
            Resposta::erro('Dados incompletos');
        }

        $atendimento = $this->buscarAtendimentoDoTotem($idAtendimento, $idTotem);

        if ($atendimento['tipo'] !== 'recebimento') {
            Resposta::erro('Atendimento nao encontrado', 404);
        }

        $exigeNumero = ($_ENV['CONCLUIR_EXIGE_NUMERO_NOTA'] ?? '') === 'true';

        $resultado = $this->concluirSobLock($idAtendimento, $idTotem, $exigeNumero);

        $http = (int) $resultado['http'];
        if ($http >= 400) {
            if (isset($resultado['codigo'])) {
                Resposta::erroComDados((string) $resultado['erro'], (string) $resultado['codigo'], (array) $resultado['dados'], $http);
                return;
            }
            Resposta::erro((string) $resultado['erro'], $http);
            return;
        }

        Resposta::sucesso($resultado['dados']);
    }

    private function concluirSobLock(int $idAtendimento, int $idTotem, bool $exigeNumero): array
    {
        $mensagemFalha = 'Nao foi possivel concluir a digitalizacao agora — tente novamente';
        $confirmou = false;

        try {
            $this->atendimentoRn->iniciarTransacao(5);
            $resultado = $this->concluirDentroDaTransacao($idAtendimento, $idTotem, $exigeNumero);

            if ($resultado['http'] < 400) {
                $this->atendimentoRn->confirmarTransacao();
                $confirmou = true;
            }

            return $resultado;
        } catch (\PDOException $e) {
            $sqlstate = (string) $e->getCode();
            error_log('concluir-digitalizacao: falha de banco (PDOException)'
                . (preg_match('/^[A-Z0-9]{5}$/', $sqlstate) === 1 ? " [SQLSTATE={$sqlstate}]" : ''));

            $codigoDriver = (int) ($e->errorInfo[1] ?? 0);
            if ($codigoDriver === 1205 || $codigoDriver === 1213) {
                return ['http' => 503, 'erro' => 'Servico ocupado no momento. Tente novamente em instantes.'];
            }

            return ['http' => 500, 'erro' => $mensagemFalha];
        } catch (\Throwable $e) {
            error_log('concluir-digitalizacao: falha nao prevista [' . get_class($e) . ']');

            return ['http' => 500, 'erro' => $mensagemFalha];
        } finally {
            if (!$confirmou) {
                try {
                    $this->atendimentoRn->desfazerTransacao();
                } catch (\Throwable $e) {
                }
            }
        }
    }

    private function corpoConclusao(array $avaliacao): array
    {
        $identificado = $avaliacao['estado'] === NotaFiscalRn::CLIENTE_IDENTIFICADO;

        return [
            'proxima_tela'   => $identificado ? 'rec_cnh' : 'rec_cliente',
            'etapa'          => $identificado ? 'rec_cnh' : 'cliente',
            'cliente_estado' => $avaliacao['estado'],
            'cliente_motivo' => $avaliacao['motivo'],
        ];
    }

    private function concluirDentroDaTransacao(int $idAtendimento, int $idTotem, bool $exigeNumero): array
    {
        $atendimento = $this->atendimentoRn->buscarParaUpdate($idAtendimento);

        if (!$atendimento || (int) $atendimento['id_totem'] !== $idTotem || $atendimento['tipo'] !== 'recebimento') {
            return ['http' => 404, 'erro' => 'Atendimento nao encontrado'];
        }
        if ($atendimento['status'] !== 'em_andamento') {
            return ['http' => 400, 'erro' => 'Atendimento nao esta em andamento'];
        }

        $notaRn = $this->notaFiscalRn();
        $notas = $notaRn->listarNotasAtivasTravadas($idAtendimento);

        if ($atendimento['etapa_atual'] !== 'digitalizacao_notas') {
            $avaliacao = $notaRn->avaliarClienteDoAtendimento($idAtendimento, false, true);
            $destino = $avaliacao['estado'] === NotaFiscalRn::CLIENTE_IDENTIFICADO ? 'rec_cnh' : 'cliente';

            if ($this->etapaEhAlvoOuPosterior((string) $atendimento['etapa_atual'], $destino)) {
                return ['http' => 200, 'dados' => $this->corpoConclusao($avaliacao)];
            }

            return ['http' => 400, 'erro' => 'Atendimento nao esta na etapa de digitalizacao de notas'];
        }

        $totalNotas = count($notas);

        if ($totalNotas < 1) {
            return ['http' => 400, 'erro' => 'Nenhuma nota fiscal foi digitalizada para esse atendimento'];
        }
        if ($totalNotas > 5) {
            return ['http' => 400, 'erro' => 'Limite de 5 notas fiscais excedido para esse atendimento'];
        }

        $classificacao = NotaFiscalRn::classificarNotasEmProcessamento($notas);
        if ($classificacao['bloqueantes'] !== []) {
            return [
                'http'   => 409,
                'erro'   => 'Ainda ha notas fiscais em processamento (OCR_EM_ANDAMENTO)',
                'codigo' => 'OCR_EM_ANDAMENTO',
                'dados'  => ['ordens_em_processamento' => $classificacao['bloqueantes']],
            ];
        }

        if ($exigeNumero) {
            $ordensPendentes = [];
            foreach ($notas as $nota) {
                if (!NotaFiscalRn::numeroNotaValido($nota['numero_nota'])) {
                    $ordensPendentes[] = (int) $nota['ordem'];
                }
            }

            if ($ordensPendentes !== []) {
                sort($ordensPendentes);

                return [
                    'http'   => 422,
                    'erro'   => 'Existem notas fiscais sem numero definido (NOTAS_SEM_NUMERO)',
                    'codigo' => 'NOTAS_SEM_NUMERO',
                    'dados'  => [
                        'ordens_pendentes' => $ordensPendentes,
                        'total_notas'      => $totalNotas,
                        'total_pendentes'  => count($ordensPendentes),
                    ],
                ];
            }
        }

        $avaliacao = $notaRn->avaliarClienteDoAtendimento($idAtendimento, false, true);
        $corpo = $this->corpoConclusao($avaliacao);
        $etapa = $corpo['etapa'];

        $venceuCas = $this->atendimentoRn->concluirDigitalizacaoNotas($idAtendimento, $etapa);

        if (!$venceuCas) {
            $atual = $this->atendimentoRn->buscar($idAtendimento);

            if ($atual !== null && $atual['status'] === 'em_andamento' && $this->etapaEhAlvoOuPosterior((string) $atual['etapa_atual'], $etapa)) {
                return ['http' => 200, 'dados' => $corpo];
            }

            return ['http' => 409, 'erro' => 'Nao foi possivel concluir a digitalizacao agora — tente novamente'];
        }

        if ($avaliacao['estado'] === NotaFiscalRn::CLIENTE_IDENTIFICADO && is_array($avaliacao['cliente'])) {
            $this->atendimentoRn->gravarClienteAutomaticoSeVazio(
                $idAtendimento,
                (string) $avaliacao['cliente']['razao_social'],
                (string) $avaliacao['cliente']['cnpj']
            );
        }

        return ['http' => 200, 'dados' => $corpo];
    }

    private const SEQUENCIA_POS_DIGITALIZACAO_RECEBIMENTO = [
        'cliente', 'rec_cnh', 'rec_crlv', 'rec_aguarde_documentos', 'rec_confirmacao', 'impressao',
    ];

    private function etapaEhAlvoOuPosterior(string $etapaAtual, string $etapaAlvo): bool
    {
        $indiceAtual = array_search($etapaAtual, self::SEQUENCIA_POS_DIGITALIZACAO_RECEBIMENTO, true);
        $indiceAlvo = array_search($etapaAlvo, self::SEQUENCIA_POS_DIGITALIZACAO_RECEBIMENTO, true);

        if ($indiceAtual === false || $indiceAlvo === false) {
            return false;
        }

        return $indiceAtual >= $indiceAlvo;
    }

    private const SEQUENCIA_EXPEDICAO = [
        'dados_encontrados'        => ['proxima_etapa' => 'exp_cnh', 'proxima_tela' => 'exp_cnh', 'gate' => null],
        'exp_cnh'                  => ['proxima_etapa' => 'exp_crlv', 'proxima_tela' => 'exp_crlv', 'gate' => 'upload_cnh'],
        'exp_crlv'                 => ['proxima_etapa' => 'exp_aguarde_documentos', 'proxima_tela' => 'exp_aguarde_documentos', 'gate' => 'upload_crlv'],
        'exp_aguarde_documentos'   => ['proxima_etapa' => 'exp_confirmacao', 'proxima_tela' => 'exp_confirma', 'gate' => 'ambos_aprovados'],
        'exp_confirmacao'          => ['proxima_etapa' => 'impressao', 'proxima_tela' => 'impressao', 'gate' => 'ambos_aprovados'],
    ];

    private const SEQUENCIA_RECEBIMENTO_DOCUMENTOS = [
        'rec_cnh'                => ['proxima_etapa' => 'rec_crlv', 'proxima_tela' => 'rec_crlv', 'gate' => 'upload_cnh'],
        'rec_crlv'               => ['proxima_etapa' => 'rec_aguarde_documentos', 'proxima_tela' => 'rec_aguarde_documentos', 'gate' => 'upload_crlv'],
        'rec_aguarde_documentos' => ['proxima_etapa' => 'rec_confirmacao', 'proxima_tela' => 'rec_confirma', 'gate' => 'ambos_aprovados'],
    ];

    public function avancarEtapaDocumentos(array $entrada, int $idTotem): void
    {
        $idAtendimento = (int) ($entrada['id_atendimento'] ?? 0);

        if (!$idAtendimento) {
            Resposta::erro('Dados incompletos');
        }

        $atendimento = $this->buscarAtendimentoDoTotem($idAtendimento, $idTotem);

        if (!in_array($atendimento['tipo'], ['expedicao', 'recebimento'], true)) {
            Resposta::erro('Atendimento nao encontrado', 404);
        }
        if ($atendimento['status'] !== 'em_andamento') {
            Resposta::erro('Atendimento nao esta em andamento');
        }
        if ($this->documentoRn === null) {
            Resposta::erro('Nao foi possivel avancar a etapa agora', 500);
        }

        $sequencia = $atendimento['tipo'] === 'expedicao'
            ? self::SEQUENCIA_EXPEDICAO
            : self::SEQUENCIA_RECEBIMENTO_DOCUMENTOS;

        $transicao = $sequencia[$atendimento['etapa_atual']] ?? null;
        if ($transicao === null) {
            Resposta::erro('Atendimento nao esta em uma etapa valida para avancar');
        }

        if (!$this->gateDeTransicaoLiberado($atendimento, $transicao['gate'])) {
            Resposta::erro('Nao foi possivel avancar a etapa agora — documentos pendentes');
        }

        $this->atendimentoRn->atualizarEtapa($idAtendimento, $transicao['proxima_etapa']);
        $resposta = ['proxima_tela' => $transicao['proxima_tela'], 'etapa' => $transicao['proxima_etapa']];
        if (in_array($transicao['proxima_etapa'], ['exp_confirmacao', 'rec_confirmacao'], true)) {
            $resposta['dados_confirmacao'] = $this->dadosParaConfirmacao($atendimento);
        }
        Resposta::sucesso($resposta);
    }

    private function dadosParaConfirmacao(array $a): array
    {
        $validade = (string) ($a['cnh_validade'] ?? '');
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $validade, $m)) {
            $validade = $m[3] . '/' . $m[2] . '/' . $m[1];
        }
        $texto = static fn ($v): string => $v === null ? '' : (string) $v;

        return [
            'placa' => $texto($a['placa'] ?? null),
            'motorista_nome' => $texto($a['motorista_nome'] ?? null),
            'motorista_cpf' => $texto($a['motorista_cpf'] ?? null),
            'cnh_validade' => $validade,
            'crlv_ano' => $texto($a['crlv_ano'] ?? null),
            'crlv_uf' => $texto($a['crlv_uf'] ?? null),
            'crlv_rntc' => $texto($a['crlv_rntc'] ?? null),
            'crlv_tipo_veiculo' => $texto($a['crlv_tipo_veiculo'] ?? null),
            'cliente_nome' => $texto($a['cliente_nome'] ?? null),
            'cliente_cnpj' => $texto($a['cliente_cnpj'] ?? null),
        ];
    }

    public function avancarEtapaExpedicao(array $entrada, int $idTotem): void
    {
        $this->avancarEtapaDocumentos($entrada, $idTotem);
    }

    private function gateDeTransicaoLiberado(array $atendimento, ?string $gate): bool
    {
        return match ($gate) {
            null => true,
            'upload_cnh', 'upload_crlv' => true,
            'ambos_aprovados' => $this->documentoRn->cnhAprovada($atendimento) && $this->documentoRn->crlvAprovado($atendimento),
            default => false,
        };
    }

    public function finalizar(array $entrada, int $idTotem): void
    {
        $idAtendimento = (int) ($entrada['id_atendimento'] ?? 0);
        $atendimento = $this->buscarAtendimentoDoTotem($idAtendimento, $idTotem);

        if (!in_array($atendimento['tipo'], ['expedicao', 'recebimento'], true)) {
            Resposta::erro('Atendimento nao encontrado', 404);
        }
        if ($atendimento['status'] !== 'em_andamento') {
            Resposta::erro('Atendimento nao esta em andamento');
        }
        $etapaEsperadaFinalizar = $atendimento['tipo'] === 'expedicao' ? 'exp_confirmacao' : 'rec_confirmacao';
        if ($atendimento['etapa_atual'] !== $etapaEsperadaFinalizar) {
            Resposta::erro('Atendimento nao esta na etapa esperada para finalizar');
        }
        if ($this->documentoRn === null || !$this->documentoRn->cnhAprovada($atendimento) || !$this->documentoRn->crlvAprovado($atendimento)) {
            Resposta::erro('Documentos obrigatorios pendentes/invalidos');
        }
        $ausentesFinal = [];
        if (trim((string) ($atendimento['crlv_rntc'] ?? '')) === '') {
            $ausentesFinal['crlv_rntc'] = 'RNTRC';
        }
        if (trim((string) ($atendimento['crlv_tipo_veiculo'] ?? '')) === '') {
            $ausentesFinal['crlv_tipo_veiculo'] = 'tipo de veiculo';
        }
        if ($ausentesFinal !== []) {
            Resposta::erroComDados(
                'Nao e possivel continuar: preencha ' . implode(', ', array_values($ausentesFinal)),
                'CONFIRMACAO_INCOMPLETA',
                ['campos' => array_keys($ausentesFinal), 'rotulos' => array_values($ausentesFinal)],
                422
            );
            return;
        }

        if ($this->totemDao === null || $this->empresaDao === null) {
            Resposta::erro('Nao foi possivel processar o check-in agora', 500);
        }

        $totemAtual = $this->totemDao->buscarPorId($idTotem);
        $idEmpresa = $totemAtual['id_empresa'] ?? null;
        $empresa = $idEmpresa !== null ? $this->empresaDao->buscarPorId((int) $idEmpresa) : null;

        if ($empresa === null) {
            Resposta::erro('Totem sem empresa/armazem configurado para envio ao Talent', 500);
        }

        $notas = $this->notaDao->listarPorAtendimento($idAtendimento);

        if ($atendimento['tipo'] === 'recebimento') {
            foreach ($notas as $nota) {
                if (trim((string) ($nota['numero_nota'] ?? '')) === '') {
                    Resposta::erro('Existem notas fiscais sem numero definido (NOTAS_SEM_NUMERO)', 422);
                    return;
                }
            }
        } else {
            if (trim((string) ($atendimento['ordem_coleta'] ?? '')) === '') {
                Resposta::erro('Atendimento sem ordem de coleta selecionada', 422);
                return;
            }
        }

        $talentCheckinAtivo = ($_ENV['TALENT_CHECKIN_ATIVO'] ?? '') === 'true';
        if (!$talentCheckinAtivo) {
            Resposta::erro('Envio ao Talent temporariamente desativado (TALENT_CHECKIN_DESATIVADO)', 503);
            return;
        }

        $resultado = $this->talentRn->processarCheckin($atendimento, $empresa, $notas);

        switch ($resultado['status']) {
            case 'ENVIADO':
            case 'JA_ENVIADO':
                if ($atendimento['tipo'] === 'expedicao') {
                    $this->tentarMarcarOrdemConcluida(
                        $idAtendimento,
                        (string) $atendimento['ordem_coleta'],
                        (string) ($atendimento['cliente_cnpj'] ?? '')
                    );
                }
                Resposta::sucesso(['senha' => $resultado['senha'], 'protocolo' => $resultado['protocolo']]);
                return;
            case 'EM_ANDAMENTO':
                Resposta::erro('Seu check-in ja esta sendo processado, aguarde', 409);
                return;
            case 'INDETERMINADO_PENDENTE_MANUAL':
                Resposta::erro('Nao foi possivel confirmar seu check-in — procure um atendente', 500);
                return;
            case 'ERRO_REPROCESSAVEL':
                $categoria = self::categoriaFalhaCheckinParaLog($resultado['erro_categoria'] ?? null);
                error_log('[AtendimentoController] checkin_talent_erro_reprocessavel id_atendimento=' . $idAtendimento . ' categoria=' . $categoria);
                self::registrarFalhaCheckinNoLog('talent_erro_reprocessavel', $idAtendimento, $idTotem, $atendimento['tipo'], $categoria);
                $textoFalha = self::textoFalhaCheckin($categoria);
                $mensagemApi = $resultado['mensagem_api'] ?? null;
                if (is_string($mensagemApi) && $mensagemApi !== '') {
                    Resposta::erroComDadosSemCodigo($textoFalha, ['mensagem_api' => $mensagemApi], 202);
                    return;
                }
                Resposta::erro($textoFalha, 202);
                return;
            case 'ENVIO_INDETERMINADO':
                self::registrarFalhaCheckinNoLog('talent_indeterminado', $idAtendimento, $idTotem, $atendimento['tipo'], self::categoriaFalhaCheckinParaLog($resultado['erro_categoria'] ?? null));
                Resposta::erro('Nao foi possivel confirmar seu check-in — procure um atendente', 500);
                return;
            default:
                Resposta::erro('Nao foi possivel processar o check-in agora', 500);
        }
    }

    private static function registrarFalhaCheckinNoLog(string $categoriaLog, int $idAtendimento, int $idTotem, string $tipo, string $categoriaErro): void
    {
        $ctx = ['id_atendimento' => $idAtendimento, 'id_totem' => $idTotem, 'tipo' => $tipo];
        if (in_array($categoriaErro, LogCatalogo::CATEGORIAS_ERRO_TALENT, true)) {
            $ctx['categoria_erro'] = $categoriaErro;
        }
        LogSistema::registrar($categoriaLog, $ctx);
    }

    private static function categoriaFalhaCheckinParaLog(mixed $categoria): string
    {
        if (is_string($categoria)
            && ($categoria === 'erro_montagem_payload' || in_array($categoria, \App\Rn\TalentClientException::CATEGORIAS_VALIDAS, true))) {
            return $categoria;
        }
        return 'erro_desconhecido';
    }

    private static function textoFalhaCheckin(string $categoria): string
    {
        switch ($categoria) {
            case 'timeout':
            case 'erro_indeterminado':
            case 'erro_conexao':
                return 'O sistema Talent esta fora do ar ou nao respondeu, e o check-in nao foi concluido. Chame o atendimento.';
            case 'erro_servidor':
                return 'O sistema Talent informou um erro interno e o check-in nao foi concluido. Chame o atendimento.';
            default:
                return 'Nao foi possivel concluir o check-in. Chame o atendimento.';
        }
    }

    public function cancelar(array $entrada, int $idTotem): void
    {
        $idAtendimento = (int) ($entrada['id_atendimento'] ?? 0);
        $atendimento = $this->buscarAtendimentoDoTotem($idAtendimento, $idTotem);

        if (!$this->atendimentoRn->cancelar($idAtendimento)) {
            Resposta::erro('Atendimento ja concluido, nao pode mais ser cancelado', 409);
            return;
        }

        $this->quarentenarFotosDasNotas($idAtendimento, $atendimento);

        Resposta::sucesso(['ok' => true]);
    }

    private function quarentenarFotosDasNotas(int $idAtendimento, array $atendimento): void
    {
        try {
            $notas = $this->notaDao->listarPorAtendimento($idAtendimento);
            $storage = $this->notaArquivoStorage();
            $falhas = 0;

            foreach ($notas as $nota) {
                $caminho = $storage->caminhoDoArquivo($atendimento['pasta_documentos'] ?? null, $nota['arquivo'] ?? null);
                if ($caminho === null) {
                    $falhas++;
                    continue;
                }

                try {
                    $storage->quarentenar($caminho, (int) $nota['id_nota']);
                } catch (\Throwable $e) {
                    $falhas++;
                }
            }

            if ($falhas > 0) {
                error_log("cancelar: falha ao mover foto(s) de nota para a quarentena (id_atendimento={$idAtendimento} falhas={$falhas})");
            }
        } catch (\Throwable $e) {
            error_log('cancelar: falha ao quarentenar as fotos das notas [' . get_class($e) . '] id_atendimento=' . $idAtendimento);
        }
    }

    private function tentarMarcarOrdemConcluida(int $idAtendimento, string $numeroOrdemColeta, string $cnpjCliente = ''): void
    {
        $cnpjCliente = \App\Dao\OrdemColetaDao::normalizarCnpj($cnpjCliente);

        if ($this->ordemColetaClient === null || $this->ordemColetaPendenteBaixaDao === null) {
            error_log("finalizar: OrdemColetaClient/OrdemColetaPendenteBaixaDao nao injetados — pendencia de baixa nao registrada para id_atendimento={$idAtendimento}");
            LogSistema::registrar('oc_baixa_falhou', ['id_atendimento' => $idAtendimento, 'motivo' => 'config_ausente']);
            return;
        }

        $statusAtual = null;
        if ($cnpjCliente === '') {
            error_log("finalizar: atendimento sem CNPJ do cliente, baixa da ordem de coleta NAO tentada (id_atendimento={$idAtendimento})");
        } else {
            try {
                $ok = $this->ordemColetaClient->marcarConcluida($cnpjCliente, $numeroOrdemColeta);
            } catch (\Throwable $e) {
                $ok = false;
            }

            if ($ok === true) {
                return;
            }

            try {
                $statusAtual = $this->ordemColetaClient->statusAtual($cnpjCliente, $numeroOrdemColeta);
            } catch (\Throwable $e) {
                $statusAtual = null;
            }
        }

        if ($statusAtual === 'INATIVA') {
            error_log("finalizar: ordem de coleta ja estava INATIVA (idempotente, nada a fazer) para id_atendimento={$idAtendimento}");
            return;
        }

        try {
            $this->ordemColetaPendenteBaixaDao->registrar($idAtendimento, $numeroOrdemColeta);
            error_log("finalizar: baixa da ordem de coleta pendente, registrada para reconciliacao manual (id_atendimento={$idAtendimento})");
            LogSistema::registrar('oc_baixa_falhou', ['id_atendimento' => $idAtendimento, 'motivo' => 'baixa_pendente']);
        } catch (\Throwable $e) {
            error_log("finalizar: falha ao registrar pendencia de baixa de ordem de coleta (id_atendimento={$idAtendimento})");
            LogSistema::registrar('oc_baixa_falhou', ['id_atendimento' => $idAtendimento, 'excecao' => $e, 'motivo' => 'pendencia_nao_registrada']);
        }
    }

    private function buscarAtendimentoDoTotem(int $idAtendimento, int $idTotem): array
    {
        $atendimento = $this->atendimentoRn->buscar($idAtendimento);
        if (!$atendimento || (int) $atendimento['id_totem'] !== $idTotem) {
            Resposta::erro('Atendimento nao encontrado', 404);
        }

        return $atendimento;
    }
}
