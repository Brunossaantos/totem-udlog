<?php

namespace App\Rn;

use App\Dao\EmpresaGestaoDao;
use App\Dao\TotemGestaoDao;
use PDO;
use PDOException;
use Throwable;
use Util\CnpjValidador;
use Util\NomeCadastro;
use Util\TotemCodigo;

class EmpresaGestaoRn extends CadastroGestaoBase
{
    public const NOME_MIN = 2;

    public const NOME_MAX = 100;

    private const ERRO_SLUG = 'Já existe uma empresa com um nome equivalente. Use um nome diferente.';

    private EmpresaGestaoDao $empresas;

    private TotemGestaoDao $totens;

    public function __construct(PDO $pdo)
    {
        parent::__construct($pdo);
        $this->empresas = new EmpresaGestaoDao($pdo);
        $this->totens = new TotemGestaoDao($pdo);
    }

    protected function alvoTipo(): string
    {
        return 'empresa';
    }

    protected function prefixoLog(): string
    {
        return 'EmpresaGestaoRn';
    }

    protected function obterLock(int $segundos): bool
    {
        return $this->totens->obterLock($segundos);
    }

    protected function liberarLock(): void
    {
        $this->totens->liberarLock();
    }

    public function listar(): array
    {
        return $this->empresas->listar();
    }

    public function obter(int $idEmpresa): ?array
    {
        $e = $this->empresas->buscarPorId($idEmpresa);
        if ($e === null) {
            return null;
        }
        $c = $this->empresas->contarTotens($idEmpresa);
        $e['totens_ativos'] = $c['ativos'];
        $e['totens_total'] = $c['total'];

        return $e;
    }

    private function validarNome(string $bruto): array
    {
        $nome = NomeCadastro::normalizar($bruto, self::NOME_MIN, self::NOME_MAX);
        if ($nome === null) {
            return [null, 'Informe o nome com 2 a 100 caracteres, sem quebras de linha nem caracteres de controle.'];
        }
        $slug = TotemCodigo::empresa($nome);
        if ($slug === '' || strlen($slug) > TotemCodigo::EMPRESA_MAX) {
            return [null, 'O nome precisa gerar de 1 a 16 letras ou números sem espaços nem acentos (usados na URL dos totens). Ajuste o nome.'];
        }

        return [$nome, null];
    }

    private function slugEmUsoPorOutra(string $nome, ?int $exceto): bool
    {
        $slug = TotemCodigo::empresa($nome);
        foreach ($this->empresas->nomesDeOutras($exceto) as $outro) {
            if ($slug === TotemCodigo::empresa($outro)) {
                return true;
            }
        }

        return false;
    }

    public function criar(int $idAdmin, string $nomeBruto, string $cnpjBruto, ?string $ip = null): array
    {
        $erros = [];
        [$nome, $erroNome] = $this->validarNome($nomeBruto);
        if ($erroNome !== null) {
            $erros['nome'] = $erroNome;
        }
        $cnpj = null;
        if (preg_match('/\A[0-9.\/\- ]{1,30}\z/D', $cnpjBruto) === 1) {
            $cnpj = CnpjValidador::normalizarEValidar($cnpjBruto);
        }
        if ($cnpj === null) {
            $erros['cnpj'] = 'Informe um CNPJ válido, com 14 números. Pode digitar com pontos, barra e traço.';
        }
        if ($erros !== []) {
            return ['ok' => false, 'codigo' => 'validacao', 'erros' => $erros];
        }

        return $this->comLock(fn (): array => $this->criarSobLock($idAdmin, (string) $nome, (string) $cnpj, $ip));
    }

    private function criarSobLock(int $idAdmin, string $nome, string $cnpj, ?string $ip): array
    {
        $idAuditoria = 0;
        $idNovo = 0;
        try {
            $this->pdo->beginTransaction();
            if (!$this->atorAdmin($idAdmin)) {
                $this->pdo->rollBack();

                return $this->semPermissao('EMPRESA_CRIAR', $idAdmin, null, $ip);
            }
            if ($this->empresas->existeCnpj($cnpj)) {
                return $this->recusaCriarDuplicado($idAdmin, $ip);
            }
            if ($this->slugEmUsoPorOutra($nome, null)) {
                $this->desfazer();
                $this->auditarRecusa('EMPRESA_CRIAR', $idAdmin, null, ['motivo_cad' => 'duplicado'], $ip);

                return ['ok' => false, 'codigo' => 'validacao', 'erros' => ['nome' => self::ERRO_SLUG]];
            }
            try {
                $idNovo = $this->empresas->inserir($nome, $cnpj);
            } catch (PDOException $e) {
                if (!$this->ehChaveDuplicada($e)) {
                    throw $e;
                }

                return $this->recusaCriarDuplicado($idAdmin, $ip);
            }
            $idAuditoria = $this->auditoria->abrir($idAdmin, 'EMPRESA_CRIAR', 'empresa', $idNovo, [], $ip);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->desfazer();

            return $this->falhaTecnica('criar', $e, 'EMPRESA_CRIAR', $idAdmin, null, $ip);
        }
        $this->fecharAuditoria($idAuditoria, 'OK');

        return ['ok' => true, 'id' => $idNovo];
    }

    private function recusaCriarDuplicado(int $idAdmin, ?string $ip): array
    {
        $this->desfazer();
        $this->auditarRecusa('EMPRESA_CRIAR', $idAdmin, null, ['motivo_cad' => 'duplicado'], $ip);

        return ['ok' => false, 'codigo' => 'validacao', 'erros' => ['cnpj' => 'Já existe uma empresa cadastrada com este CNPJ. Nada foi criado.']];
    }

    public function editar(int $idAdmin, int $idEmpresa, string $nomeBruto, ?string $ip = null): array
    {
        [$nome, $erroNome] = $this->validarNome($nomeBruto);
        if ($erroNome !== null) {
            return ['ok' => false, 'codigo' => 'validacao', 'erros' => ['nome' => $erroNome]];
        }

        return $this->comLock(fn (): array => $this->editarSobLock($idAdmin, $idEmpresa, (string) $nome, $ip));
    }

    private function editarSobLock(int $idAdmin, int $idEmpresa, string $nome, ?string $ip): array
    {
        $idAuditoria = 0;
        try {
            $this->pdo->beginTransaction();
            if (!$this->atorAdmin($idAdmin)) {
                $this->pdo->rollBack();

                return $this->semPermissao('EMPRESA_EDITAR', $idAdmin, $idEmpresa, $ip);
            }
            $atual = $this->empresas->buscarPorId($idEmpresa, true);
            if ($atual === null) {
                $this->pdo->rollBack();
                $this->auditarRecusa('EMPRESA_EDITAR', $idAdmin, null, ['motivo_cad' => 'nao_encontrado'], $ip);

                return ['ok' => false, 'codigo' => 'empresa_nao_encontrada'];
            }
            if ((string) $atual['nome'] === $nome) {
                $this->pdo->rollBack();
                $this->auditarRecusa('EMPRESA_EDITAR', $idAdmin, $idEmpresa, ['motivo_cad' => 'ja_no_estado'], $ip);

                return ['ok' => true, 'sem_mudanca' => true];
            }
            if ($this->slugEmUsoPorOutra($nome, $idEmpresa)) {
                $this->pdo->rollBack();
                $this->auditarRecusa('EMPRESA_EDITAR', $idAdmin, $idEmpresa, ['motivo_cad' => 'duplicado'], $ip);

                return ['ok' => false, 'codigo' => 'validacao', 'erros' => ['nome' => self::ERRO_SLUG]];
            }
            $idAuditoria = $this->auditoria->abrir($idAdmin, 'EMPRESA_EDITAR', 'empresa', $idEmpresa, [], $ip);
            $this->empresas->atualizarNome($idEmpresa, $nome);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->desfazer();

            return $this->falhaTecnica('editar', $e, 'EMPRESA_EDITAR', $idAdmin, $idEmpresa, $ip);
        }
        $this->fecharAuditoria($idAuditoria, 'OK');

        return ['ok' => true];
    }

    public function definirAtivo(int $idAdmin, int $idEmpresa, bool $ativo, bool $confirmou, ?string $ip = null): array
    {
        return $this->comLock(fn (): array => $this->definirAtivoSobLock($idAdmin, $idEmpresa, $ativo, $confirmou, $ip));
    }

    private function definirAtivoSobLock(int $idAdmin, int $idEmpresa, bool $ativo, bool $confirmou, ?string $ip): array
    {
        $idAuditoria = 0;
        try {
            $this->pdo->beginTransaction();
            if (!$this->atorAdmin($idAdmin)) {
                $this->pdo->rollBack();

                return $this->semPermissao('EMPRESA_ATIVO', $idAdmin, $idEmpresa, $ip);
            }
            $e = $this->empresas->buscarPorId($idEmpresa, true);
            if ($e === null) {
                $this->pdo->rollBack();
                $this->auditarRecusa('EMPRESA_ATIVO', $idAdmin, null, ['motivo_cad' => 'nao_encontrado'], $ip);

                return ['ok' => false, 'codigo' => 'empresa_nao_encontrada'];
            }
            $paraTexto = $ativo ? '1' : '0';
            if (((int) $e['ativo'] === 1) === $ativo) {
                $this->pdo->rollBack();
                $this->auditarRecusa('EMPRESA_ATIVO', $idAdmin, $idEmpresa, ['ativo_para' => $paraTexto, 'motivo_cad' => 'ja_no_estado'], $ip);

                return ['ok' => true, 'sem_mudanca' => true];
            }
            $detalhe = ['ativo_para' => $paraTexto];
            if (!$ativo) {
                $ativos = $this->empresas->contarTotens($idEmpresa)['ativos'];
                if ($ativos > 0) {
                    if (!$confirmou) {
                        $this->pdo->rollBack();

                        return ['ok' => false, 'codigo' => 'confirmacao_necessaria', 'totens_ativos' => $ativos];
                    }
                    $detalhe['motivo_cad'] = 'totens_ativos_confirmado';
                }
            }
            $idAuditoria = $this->auditoria->abrir($idAdmin, 'EMPRESA_ATIVO', 'empresa', $idEmpresa, $detalhe, $ip);
            $this->empresas->atualizarAtivo($idEmpresa, $ativo);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->desfazer();

            return $this->falhaTecnica('definirAtivo', $e, 'EMPRESA_ATIVO', $idAdmin, $idEmpresa, $ip);
        }
        $this->fecharAuditoria($idAuditoria, 'OK');

        return ['ok' => true];
    }

    public function excluir(int $idAdmin, int $idEmpresa, bool $confirmou, ?string $ip = null): array
    {
        return $this->comLock(fn (): array => $this->excluirSobLock($idAdmin, $idEmpresa, $confirmou, $ip));
    }

    private function excluirSobLock(int $idAdmin, int $idEmpresa, bool $confirmou, ?string $ip): array
    {
        $idAuditoria = 0;
        try {
            $this->pdo->beginTransaction();
            if (!$this->atorAdmin($idAdmin)) {
                $this->pdo->rollBack();

                return $this->semPermissao('EMPRESA_EXCLUIR', $idAdmin, $idEmpresa, $ip);
            }
            $e = $this->empresas->buscarPorId($idEmpresa, true);
            if ($e === null) {
                $this->pdo->rollBack();
                $this->auditarRecusa('EMPRESA_EXCLUIR', $idAdmin, null, ['motivo_cad' => 'nao_encontrado'], $ip);

                return ['ok' => false, 'codigo' => 'empresa_nao_encontrada'];
            }
            if ($this->empresas->contarTotens($idEmpresa)['total'] > 0) {
                $this->pdo->rollBack();
                $this->auditarRecusa('EMPRESA_EXCLUIR', $idAdmin, $idEmpresa, ['motivo_cad' => 'totens_vinculados'], $ip);

                return ['ok' => false, 'codigo' => 'empresa_com_totens'];
            }
            if (!$confirmou) {
                $this->pdo->rollBack();

                return ['ok' => false, 'codigo' => 'confirmacao_necessaria'];
            }
            $idAuditoria = $this->auditoria->abrir($idAdmin, 'EMPRESA_EXCLUIR', 'empresa', $idEmpresa, [], $ip);
            $this->empresas->excluir($idEmpresa);
            $this->pdo->commit();
        } catch (PDOException $e) {
            $this->desfazer();
            if ($this->ehFkFilhoExistente($e)) {
                $this->auditarRecusa('EMPRESA_EXCLUIR', $idAdmin, $idEmpresa, ['motivo_cad' => 'totens_vinculados'], $ip);

                return ['ok' => false, 'codigo' => 'empresa_com_totens'];
            }

            return $this->falhaTecnica('excluir', $e, 'EMPRESA_EXCLUIR', $idAdmin, $idEmpresa, $ip);
        } catch (Throwable $e) {
            $this->desfazer();

            return $this->falhaTecnica('excluir', $e, 'EMPRESA_EXCLUIR', $idAdmin, $idEmpresa, $ip);
        }
        $this->fecharAuditoria($idAuditoria, 'OK');

        return ['ok' => true];
    }
}
