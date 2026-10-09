<?php

namespace App\Rn;

use App\Dao\ClienteGestaoDao;
use PDO;
use PDOException;
use Throwable;
use Util\CnpjValidador;
use Util\LogSistema;
use Util\NomeCadastro;
use Util\RazaoSocialMatcher;

class ClienteGestaoRn extends CadastroGestaoBase
{
    public const NOME_MIN = 2;

    public const NOME_MAX = 150;

    public const RAZAO_MAX = 150;

    public const SIMULACAO_MAX_CLIENTES = 500;

    private ClienteGestaoDao $clientes;

    public function __construct(PDO $pdo)
    {
        parent::__construct($pdo);
        $this->clientes = new ClienteGestaoDao($pdo);
    }

    protected function alvoTipo(): string
    {
        return 'cliente';
    }

    protected function prefixoLog(): string
    {
        return 'ClienteGestaoRn';
    }

    protected function obterLock(int $segundos): bool
    {
        return $this->clientes->obterLock($segundos);
    }

    protected function liberarLock(): void
    {
        $this->clientes->liberarLock();
    }

    public static function filtrosValidos(string $situacao, string $qBruto): array
    {
        $situacao = in_array($situacao, ClienteGestaoDao::SITUACOES, true) ? $situacao : 'todos';
        $q = NomeCadastro::textoBusca($qBruto);
        $curta = false;
        if (trim($qBruto) !== '' && mb_strlen($q, 'UTF-8') < 3) {
            $curta = true;
            $q = '';
        }

        return ['situacao' => $situacao, 'q' => $q, 'busca_curta' => $curta];
    }

    public function listarPagina(array $filtros, int $paginaPedida): array
    {
        $filtro = ['situacao' => $filtros['situacao'], 'q' => $filtros['q']];
        $total = $this->clientes->contar($filtro);
        $paginas = max(1, (int) ceil($total / ClienteGestaoDao::POR_PAGINA));
        $pagina = max(1, min($paginaPedida, $paginas));

        return ['linhas' => $total === 0 ? [] : $this->clientes->listar($filtro, $pagina), 'total' => $total, 'pagina' => $pagina, 'paginas' => $paginas];
    }

    public function obter(int $idCliente): ?array
    {
        $c = $this->clientes->buscarPorId($idCliente);
        if ($c === null) {
            return null;
        }
        unset($c['razao_social_normalizada']);

        return $c;
    }

    public function andamentoDoCliente(string $cnpj): int
    {
        return $this->clientes->contarAtendimentosEmAndamento($cnpj);
    }

    public function ambiguidadeAoAtivar(int $idCliente): array
    {
        $c = $this->clientes->buscarPorId($idCliente);
        if ($c === null || (int) $c['ativo'] === 1 || (string) $c['razao_social_normalizada'] === '') {
            return ['total' => 0, 'nomes' => []];
        }

        return $this->ambiguidadeOcr($idCliente, (string) $c['nome'], (string) $c['razao_social_normalizada']);
    }

    private function validarNome(string $bruto): array
    {
        $nome = NomeCadastro::normalizar($bruto, self::NOME_MIN, self::NOME_MAX);
        if ($nome === null) {
            return [null, null, 'Informe o nome com 2 a 150 caracteres, sem quebras de linha nem caracteres de controle.'];
        }
        $razao = RazaoSocialMatcher::normalizar($nome);
        if ($razao === '') {
            return [null, null, 'O nome precisa ter pelo menos uma letra ou um número. Nomes só com símbolos, emojis ou só com termos como LTDA, ME ou SA não podem ser usados.'];
        }
        if (strlen($razao) > self::RAZAO_MAX) {
            return [null, null, 'O nome é longo demais depois de normalizado. Use um nome mais curto.'];
        }

        return [$nome, $razao, null];
    }

    private function ambiguidadeOcr(?int $idAlvo, string $nomeNovo, string $razaoNova): array
    {
        $vazio = ['total' => 0, 'nomes' => []];
        try {
            $antes = $this->clientes->listarAtivosParaSimulacao(self::SIMULACAO_MAX_CLIENTES + 1);
            if ($antes === [] || count($antes) > self::SIMULACAO_MAX_CLIENTES) {
                return $vazio;
            }
            $itemAntigo = null;
            $depois = [];
            foreach ($antes as $item) {
                if ($idAlvo !== null && (int) $item['id_cliente'] === $idAlvo) {
                    $itemAntigo = $item;
                    continue;
                }
                $depois[] = $item;
            }
            $chaveAlvo = $idAlvo ?? -1;
            $depois[] = ['id_cliente' => $chaveAlvo, 'nome' => $nomeNovo, 'razao_social' => $razaoNova];

            $identificaSe = static function (array $item, array $lista): bool {
                $r = RazaoSocialMatcher::melhorCandidato((string) $item['razao_social'], $lista);

                return $r['identificado'] === true && (int) ($r['cliente']['id_cliente'] ?? -2) === (int) $item['id_cliente'];
            };

            $total = 0;
            $nomes = [];
            foreach ($depois as $item) {
                if ($identificaSe($item, $depois)) {
                    continue;
                }
                $eraBom = (int) $item['id_cliente'] === $chaveAlvo
                    ? ($itemAntigo === null || $identificaSe($itemAntigo, $antes))
                    : $identificaSe($item, $antes);
                if ($eraBom) {
                    $total++;
                    if (count($nomes) < 5) {
                        $nomes[] = (string) $item['nome'];
                    }
                }
            }

            return ['total' => $total, 'nomes' => $nomes];
        } catch (Throwable $e) {
            error_log($this->prefixoLog() . ': simulacao_ocr_falhou ' . get_class($e));
            LogSistema::registrar('gestao_erro_interno', ['excecao' => $e]);

            return $vazio;
        }
    }

    public function criar(int $idAdmin, string $nomeBruto, string $cnpjBruto, string $ativoTexto, ?string $ip = null, bool $confirmou = false): array
    {
        $erros = [];
        [$nome, $razao, $erroNome] = $this->validarNome($nomeBruto);
        if ($erroNome !== null) {
            $erros['nome'] = $erroNome;
        }
        $cnpj = null;
        if (preg_match('/\A[0-9.\/\- ]{1,30}\z/D', $cnpjBruto) === 1) {
            $cnpj = CnpjValidador::normalizarEValidar($cnpjBruto);
        }
        if ($cnpj === null) {
            $erros['cnpj'] = 'Informe um CNPJ válido, com 14 números. Pode digitar com pontos, barra e traço.';
        } elseif (CnpjValidador::ehUdlog($cnpj)) {
            $erros['cnpj'] = 'Este CNPJ é da UDLOG e não pode ser cadastrado como cliente.';
        }
        if (!in_array($ativoTexto, ['', '0', '1'], true)) {
            $erros['ativo'] = 'Escolha a situação na lista.';
        }
        if ($erros !== []) {
            return ['ok' => false, 'codigo' => 'validacao', 'erros' => $erros];
        }

        return $this->comLock(fn (): array => $this->criarSobLock($idAdmin, (string) $nome, (string) $razao, (string) $cnpj, $ativoTexto !== '0', $ip, $confirmou));
    }

    private function criarSobLock(int $idAdmin, string $nome, string $razao, string $cnpj, bool $ativo, ?string $ip, bool $confirmou): array
    {
        $idAuditoria = 0;
        $idNovo = 0;
        try {
            $this->pdo->beginTransaction();
            if (!$this->atorAdmin($idAdmin)) {
                $this->pdo->rollBack();

                return $this->semPermissao('CLIENTE_CRIAR', $idAdmin, null, $ip);
            }
            if ($this->clientes->existeCnpj($cnpj)) {
                return $this->recusaCriarDuplicado($idAdmin, 'cnpj', $ip);
            }
            if ($this->clientes->existeRazaoNormalizada($razao, null)) {
                return $this->recusaCriarDuplicado($idAdmin, 'nome', $ip);
            }
            $detalhe = ['ativo_para' => $ativo ? '1' : '0'];
            if ($ativo) {
                $amb = $this->ambiguidadeOcr(null, $nome, $razao);
                if ($amb['total'] > 0) {
                    if (!$confirmou) {
                        $this->pdo->rollBack();

                        return ['ok' => false, 'codigo' => 'confirmacao_ambiguidade', 'ambiguidade' => $amb];
                    }
                    $detalhe['motivo_cad'] = 'confirmado_ambiguidade_ocr';
                }
            }
            try {
                $idNovo = $this->clientes->inserir($nome, $razao, $cnpj, $ativo);
            } catch (PDOException $e) {
                if (!$this->ehChaveDuplicada($e)) {
                    throw $e;
                }

                return $this->recusaCriarDuplicado($idAdmin, 'cnpj', $ip);
            }
            $idAuditoria = $this->auditoria->abrir($idAdmin, 'CLIENTE_CRIAR', 'cliente', $idNovo, $detalhe, $ip);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->desfazer();

            return $this->falhaTecnica('criar', $e, 'CLIENTE_CRIAR', $idAdmin, null, $ip);
        }
        $this->fecharAuditoria($idAuditoria, 'OK');

        return ['ok' => true, 'id' => $idNovo];
    }

    private function recusaCriarDuplicado(int $idAdmin, string $campo, ?string $ip): array
    {
        $this->desfazer();
        $this->auditarRecusa('CLIENTE_CRIAR', $idAdmin, null, ['motivo_cad' => 'duplicado'], $ip);
        $msg = $campo === 'cnpj'
            ? 'Já existe um cliente cadastrado com este CNPJ. Nada foi criado.'
            : 'Já existe um cliente cadastrado com um nome equivalente a este. Ajuste o nome. Nada foi criado.';

        return ['ok' => false, 'codigo' => 'validacao', 'erros' => [$campo => $msg]];
    }

    public function editar(int $idAdmin, int $idCliente, string $nomeBruto, ?string $ip = null, bool $confirmou = false): array
    {
        [$nome, $razao, $erroNome] = $this->validarNome($nomeBruto);
        if ($erroNome !== null) {
            return ['ok' => false, 'codigo' => 'validacao', 'erros' => ['nome' => $erroNome]];
        }

        return $this->comLock(fn (): array => $this->editarSobLock($idAdmin, $idCliente, (string) $nome, (string) $razao, $ip, $confirmou));
    }

    private function editarSobLock(int $idAdmin, int $idCliente, string $nome, string $razao, ?string $ip, bool $confirmou): array
    {
        $idAuditoria = 0;
        try {
            $this->pdo->beginTransaction();
            if (!$this->atorAdmin($idAdmin)) {
                $this->pdo->rollBack();

                return $this->semPermissao('CLIENTE_EDITAR', $idAdmin, $idCliente, $ip);
            }
            $atual = $this->clientes->buscarPorId($idCliente, true);
            if ($atual === null) {
                $this->pdo->rollBack();
                $this->auditarRecusa('CLIENTE_EDITAR', $idAdmin, null, ['motivo_cad' => 'nao_encontrado'], $ip);

                return ['ok' => false, 'codigo' => 'cliente_nao_encontrado'];
            }
            if ((string) $atual['nome'] === $nome && (string) $atual['razao_social_normalizada'] === $razao) {
                $this->pdo->rollBack();
                $this->auditarRecusa('CLIENTE_EDITAR', $idAdmin, $idCliente, ['motivo_cad' => 'ja_no_estado'], $ip);

                return ['ok' => true, 'sem_mudanca' => true];
            }
            if ($this->clientes->existeRazaoNormalizada($razao, $idCliente)) {
                $this->pdo->rollBack();
                $this->auditarRecusa('CLIENTE_EDITAR', $idAdmin, $idCliente, ['motivo_cad' => 'duplicado'], $ip);

                return ['ok' => false, 'codigo' => 'validacao', 'erros' => ['nome' => 'Já existe outro cliente cadastrado com um nome equivalente a este. Ajuste o nome. Nada foi alterado.']];
            }
            $detalhe = [];
            if ((int) $atual['ativo'] === 1 && (string) $atual['razao_social_normalizada'] !== $razao) {
                $amb = $this->ambiguidadeOcr($idCliente, $nome, $razao);
                if ($amb['total'] > 0) {
                    if (!$confirmou) {
                        $this->pdo->rollBack();

                        return ['ok' => false, 'codigo' => 'confirmacao_ambiguidade', 'ambiguidade' => $amb];
                    }
                    $detalhe['motivo_cad'] = 'confirmado_ambiguidade_ocr';
                }
            }
            $idAuditoria = $this->auditoria->abrir($idAdmin, 'CLIENTE_EDITAR', 'cliente', $idCliente, $detalhe, $ip);
            $this->clientes->atualizarNome($idCliente, $nome, $razao);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->desfazer();

            return $this->falhaTecnica('editar', $e, 'CLIENTE_EDITAR', $idAdmin, $idCliente, $ip);
        }
        $this->fecharAuditoria($idAuditoria, 'OK');

        return ['ok' => true];
    }

    public function definirAtivo(int $idAdmin, int $idCliente, bool $ativo, bool $confirmou, ?string $ip = null): array
    {
        return $this->comLock(fn (): array => $this->definirAtivoSobLock($idAdmin, $idCliente, $ativo, $confirmou, $ip));
    }

    private function definirAtivoSobLock(int $idAdmin, int $idCliente, bool $ativo, bool $confirmou, ?string $ip): array
    {
        $idAuditoria = 0;
        try {
            $this->pdo->beginTransaction();
            if (!$this->atorAdmin($idAdmin)) {
                $this->pdo->rollBack();

                return $this->semPermissao('CLIENTE_ATIVO', $idAdmin, $idCliente, $ip);
            }
            $c = $this->clientes->buscarPorId($idCliente, true);
            if ($c === null) {
                $this->pdo->rollBack();
                $this->auditarRecusa('CLIENTE_ATIVO', $idAdmin, null, ['motivo_cad' => 'nao_encontrado'], $ip);

                return ['ok' => false, 'codigo' => 'cliente_nao_encontrado'];
            }
            $paraTexto = $ativo ? '1' : '0';
            if (((int) $c['ativo'] === 1) === $ativo) {
                $this->pdo->rollBack();
                $this->auditarRecusa('CLIENTE_ATIVO', $idAdmin, $idCliente, ['ativo_para' => $paraTexto, 'motivo_cad' => 'ja_no_estado'], $ip);

                return ['ok' => true, 'sem_mudanca' => true];
            }
            $detalhe = ['ativo_para' => $paraTexto];
            if ($ativo && (string) $c['razao_social_normalizada'] !== '') {
                $amb = $this->ambiguidadeOcr($idCliente, (string) $c['nome'], (string) $c['razao_social_normalizada']);
                if ($amb['total'] > 0) {
                    if (!$confirmou) {
                        $this->pdo->rollBack();

                        return ['ok' => false, 'codigo' => 'confirmacao_ambiguidade', 'ambiguidade' => $amb];
                    }
                    $detalhe['motivo_cad'] = 'confirmado_ambiguidade_ocr';
                }
            }
            if (!$ativo) {
                $andamento = $this->clientes->contarAtendimentosEmAndamento((string) $c['cnpj']);
                if ($andamento > 0) {
                    if (!$confirmou) {
                        $this->pdo->rollBack();

                        return ['ok' => false, 'codigo' => 'confirmacao_necessaria', 'andamento' => $andamento];
                    }
                    $detalhe['motivo_cad'] = 'andamento_confirmado';
                }
            }
            $idAuditoria = $this->auditoria->abrir($idAdmin, 'CLIENTE_ATIVO', 'cliente', $idCliente, $detalhe, $ip);
            $this->clientes->atualizarAtivo($idCliente, $ativo);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->desfazer();

            return $this->falhaTecnica('definirAtivo', $e, 'CLIENTE_ATIVO', $idAdmin, $idCliente, $ip);
        }
        $this->fecharAuditoria($idAuditoria, 'OK');

        return ['ok' => true];
    }

    public function excluir(int $idAdmin, int $idCliente, bool $confirmou, ?string $ip = null): array
    {
        return $this->comLock(fn (): array => $this->excluirSobLock($idAdmin, $idCliente, $confirmou, $ip));
    }

    private function excluirSobLock(int $idAdmin, int $idCliente, bool $confirmou, ?string $ip): array
    {
        $idAuditoria = 0;
        try {
            $this->pdo->beginTransaction();
            if (!$this->atorAdmin($idAdmin)) {
                $this->pdo->rollBack();

                return $this->semPermissao('CLIENTE_EXCLUIR', $idAdmin, $idCliente, $ip);
            }
            $c = $this->clientes->buscarPorId($idCliente, true);
            if ($c === null) {
                $this->pdo->rollBack();
                $this->auditarRecusa('CLIENTE_EXCLUIR', $idAdmin, null, ['motivo_cad' => 'nao_encontrado'], $ip);

                return ['ok' => false, 'codigo' => 'cliente_nao_encontrado'];
            }
            $andamento = $this->clientes->contarAtendimentosEmAndamento((string) $c['cnpj']);
            if (!$confirmou) {
                $this->pdo->rollBack();

                return ['ok' => false, 'codigo' => 'confirmacao_necessaria', 'andamento' => $andamento];
            }
            $idAuditoria = $this->auditoria->abrir($idAdmin, 'CLIENTE_EXCLUIR', 'cliente', $idCliente, $andamento > 0 ? ['motivo_cad' => 'andamento_confirmado'] : [], $ip);
            $this->clientes->excluir($idCliente);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->desfazer();

            return $this->falhaTecnica('excluir', $e, 'CLIENTE_EXCLUIR', $idAdmin, $idCliente, $ip);
        }
        $this->fecharAuditoria($idAuditoria, 'OK');

        return ['ok' => true];
    }
}
