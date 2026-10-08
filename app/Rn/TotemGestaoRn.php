<?php

namespace App\Rn;

use App\Dao\AuditoriaDao;
use App\Dao\TotemGestaoDao;
use App\Dao\UsuarioGestaoDao;
use LogicException;
use PDO;
use PDOException;
use Throwable;
use Util\TotemCodigo;
use Util\TotemUrlBase;

/**
 * Regras de negocio da gestao de totens (demanda gestao-totem, F2). SO admin
 * (o guard esta em GestaoContexto / nas paginas).
 *
 * Toda mutacao: (1) valida a entrada, (2) toma o lock nomeado dos totens, (3) abre
 * a transacao e reconfere que o ator ainda e admin ativo (FOR UPDATE nos admins),
 * (4) abre a auditoria PENDENTE dentro da transacao, (5) fecha como OK depois do
 * COMMIT. Recusas por regra viram SEM_EFEITO. Falha tecnica vira log fixo (SO a
 * classe da excecao) e uma linha ERRO.
 *
 * SEGREDOS: o `token_api` e gerado aqui (CSPRNG) e gravado, nunca devolvido, logado
 * nem auditado. O codigo/HASH da URL tambem nunca vai para log nem para auditoria
 * (a auditoria so guarda a allowlist do AuditoriaDao).
 */
class TotemGestaoRn
{
    public const TENTATIVAS_CODIGO = 5;

    private TotemGestaoDao $totens;

    private UsuarioGestaoDao $usuarios;

    private AuditoriaDao $auditoria;

    /** @var callable():string */
    private $geradorHash;

    /**
     * @param string $urlBase TOTEM_URL_BASE ja validada (TotemUrlBase::doAmbiente)
     * @param callable():string|null $geradorHash so para teste (colisao forcada); padrao TotemCodigo::hash (CSPRNG)
     */
    public function __construct(private PDO $pdo, private string $urlBase, ?callable $geradorHash = null)
    {
        $this->totens = new TotemGestaoDao($pdo);
        $this->usuarios = new UsuarioGestaoDao($pdo);
        $this->auditoria = new AuditoriaDao($pdo);
        $this->geradorHash = $geradorHash ?? [TotemCodigo::class, 'hash'];
    }

    // ------------------------------------------------------------------ leitura

    /**
     * Cada linha traz `url` (URL completa, so para o admin), `legado` (codigo fora do
     * padrao NOME-EMPRESA-HASH16) e NUNCA o token nem o `codigo` solto.
     *
     * @return list<array<string,mixed>>
     */
    public function listar(): array
    {
        $saida = [];
        foreach ($this->totens->listar() as $t) {
            $saida[] = $this->enriquecer($t);
        }

        return $saida;
    }

    /** @return array<string,mixed>|null */
    public function obter(int $idTotem): ?array
    {
        $t = $this->totens->buscarPorId($idTotem);

        return $t === null ? null : $this->enriquecer($t);
    }

    /** @return list<array{id_empresa:int|string,nome:string}> */
    public function empresasAtivas(): array
    {
        return $this->totens->empresasAtivas();
    }

    /**
     * @param array<string,mixed> $t
     * @return array<string,mixed>
     */
    private function enriquecer(array $t): array
    {
        $codigo = (string) $t['codigo'];
        $t['url'] = TotemUrlBase::urlDoCodigo($this->urlBase, $codigo);
        $t['legado'] = TotemCodigo::prefixoDoPadrao($codigo) === null;
        unset($t['codigo']);

        return $t;
    }

    // ------------------------------------------------------------------ criar

    /**
     * @return array{ok:bool,codigo?:string,erros?:array<string,string>,id?:int}
     */
    public function criar(int $idAdmin, ?int $idEmpresa, string $nomeBruto, ?string $ip = null): array
    {
        $erros = [];
        $nome = TotemCodigo::nome($nomeBruto);
        if ($nome === null) {
            $erros['nome'] = 'Informe o nome com 2 a 24 letras ou números. Acentos e símbolos são ajustados automaticamente (exemplo: Guichê 04 vira GUICHE-04).';
        }
        if ($idEmpresa === null) {
            $erros['empresa'] = 'Escolha a empresa na lista.';
        }
        if ($erros !== []) {
            return ['ok' => false, 'codigo' => 'validacao', 'erros' => $erros];
        }

        return $this->comLock(fn (): array => $this->criarSobLock($idAdmin, (int) $idEmpresa, (string) $nome, $ip));
    }

    /** @return array<string,mixed> */
    private function criarSobLock(int $idAdmin, int $idEmpresa, string $nome, ?string $ip): array
    {
        $idAuditoria = 0;
        $idNovo = 0;
        try {
            $this->pdo->beginTransaction();
            if (!in_array($idAdmin, $this->usuarios->travarAdminsAtivos(), true)) {
                $this->pdo->rollBack();

                return $this->semPermissao('TOTEM_CRIAR', $idAdmin, null, $ip);
            }
            $empresa = $this->totens->buscarEmpresa($idEmpresa, true);
            if ($empresa === null) {
                $this->pdo->rollBack();
                $this->auditarRecusa('TOTEM_CRIAR', $idAdmin, null, [], $ip);

                return ['ok' => false, 'codigo' => 'validacao', 'erros' => ['empresa' => 'Escolha uma empresa ativa da lista.']];
            }
            $slug = TotemCodigo::empresa((string) $empresa['nome']);
            if ($slug === '' || strlen($slug) > TotemCodigo::EMPRESA_MAX) {
                $this->pdo->rollBack();
                $this->auditarRecusa('TOTEM_CRIAR', $idAdmin, null, ['empresa' => $idEmpresa], $ip);

                return ['ok' => false, 'codigo' => 'validacao', 'erros' => ['empresa' => 'O nome desta empresa gera um identificador com mais de 16 letras ou números (ou nenhum). Nenhum totem foi criado. Ajuste o nome da empresa no cadastro antes de continuar.']];
            }
            if ($this->totens->existeNomeNaEmpresa($idEmpresa, $nome)) {
                $this->pdo->rollBack();
                $this->auditarRecusa('TOTEM_CRIAR', $idAdmin, null, ['empresa' => $idEmpresa], $ip);

                return ['ok' => false, 'codigo' => 'validacao', 'erros' => ['nome' => 'Já existe um totem com esse nome nesta empresa. Escolha outro nome.']];
            }

            for ($tentativa = 1; $idNovo === 0; $tentativa++) {
                if ($tentativa > self::TENTATIVAS_CODIGO) {
                    $this->pdo->rollBack();
                    error_log('TotemGestaoRn: criar_codigo_colisao_esgotada');
                    $this->registrarErro('TOTEM_CRIAR', $idAdmin, null, $ip);

                    return ['ok' => false, 'codigo' => 'totem_colisao'];
                }
                $codigo = TotemCodigo::montar($nome, $slug, $this->novoHash());
                try {
                    $idNovo = $this->totens->inserir($codigo, $nome, $idEmpresa, bin2hex(random_bytes(32)), $idAdmin);
                } catch (PDOException $e) {
                    if (!$this->ehChaveDuplicada($e)) {
                        throw $e;
                    }
                    // Nome repetido que escapou da checagem (ex.: outro processo sem o lock)? Recusa clara.
                    if ($this->totens->existeNomeNaEmpresa($idEmpresa, $nome)) {
                        $this->pdo->rollBack();
                        $this->auditarRecusa('TOTEM_CRIAR', $idAdmin, null, ['empresa' => $idEmpresa], $ip);

                        return ['ok' => false, 'codigo' => 'validacao', 'erros' => ['nome' => 'Já existe um totem com esse nome nesta empresa. Escolha outro nome.']];
                    }
                    // senao: colisao de codigo (ou de token) -> tenta de novo com outro HASH
                }
            }
            $idAuditoria = $this->auditoria->abrir($idAdmin, 'TOTEM_CRIAR', 'totem', $idNovo, ['empresa' => $idEmpresa], $ip);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->desfazer();

            return $this->falhaTecnica('criar', $e, 'TOTEM_CRIAR', $idAdmin, null, $ip);
        }
        $this->fecharAuditoria($idAuditoria, 'OK');

        return ['ok' => true, 'id' => $idNovo];
    }

    // ------------------------------------------------------------------ ativar / desativar

    /**
     * Desativar com atendimento recente (em_andamento com atividade nos ultimos 30
     * min) exige `$confirmouAtendimento`: sem ela devolve `totem_atendimento_em_andamento`
     * e NADA muda. Efeito imediato: a pagina do totem responde 404 e Util\Auth 401.
     *
     * @return array{ok:bool,codigo?:string,sem_mudanca?:bool}
     */
    public function definirAtivo(int $idAdmin, int $idTotem, bool $ativo, bool $confirmouAtendimento, ?string $ip = null): array
    {
        return $this->comLock(fn (): array => $this->definirAtivoSobLock($idAdmin, $idTotem, $ativo, $confirmouAtendimento, $ip));
    }

    /** @return array<string,mixed> */
    private function definirAtivoSobLock(int $idAdmin, int $idTotem, bool $ativo, bool $confirmouAtendimento, ?string $ip): array
    {
        $idAuditoria = 0;
        try {
            $this->pdo->beginTransaction();
            if (!in_array($idAdmin, $this->usuarios->travarAdminsAtivos(), true)) {
                $this->pdo->rollBack();

                return $this->semPermissao('TOTEM_ATIVO', $idAdmin, $idTotem, $ip);
            }
            $totem = $this->totens->buscarPorId($idTotem, true);
            if ($totem === null) {
                $this->pdo->rollBack();
                $this->auditarRecusa('TOTEM_ATIVO', $idAdmin, null, [], $ip);

                return ['ok' => false, 'codigo' => 'totem_nao_encontrado'];
            }
            if (((int) $totem['ativo'] === 1) === $ativo) {
                $this->pdo->rollBack();

                return ['ok' => true, 'sem_mudanca' => true];
            }
            if (!$ativo && !$confirmouAtendimento && $this->totens->contarAtendimentosRecentes($idTotem) > 0) {
                $this->pdo->rollBack();
                $this->auditarRecusa('TOTEM_ATIVO', $idAdmin, $idTotem, [], $ip);

                return ['ok' => false, 'codigo' => 'totem_atendimento_em_andamento'];
            }
            $this->totens->atualizarAtivo($idTotem, $ativo);
            $idAuditoria = $this->auditoria->abrir($idAdmin, 'TOTEM_ATIVO', 'totem', $idTotem, ['ativo_para' => $ativo ? '1' : '0'], $ip);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->desfazer();

            return $this->falhaTecnica('definirAtivo', $e, 'TOTEM_ATIVO', $idAdmin, $idTotem, $ip);
        }
        $this->fecharAuditoria($idAuditoria, 'OK');

        return ['ok' => true];
    }

    // ------------------------------------------------------------------ regerar URL

    /**
     * Troca SO o sufixo HASH do codigo (a URL antiga deixa de funcionar na hora) e
     * NAO muda o token_api. `$versaoEsperada` e a `url_versao` que a tela mostrou
     * (recusa reenvio/duplo clique) e `$nomeDigitado` a confirmacao pelo NOME do
     * totem. Totem legado (codigo fora do padrao, ex.: RECEPCAO-01) vira
     * `<NOME-NORMALIZADO>-<EMPRESA>-<HASH16>` a partir de tb_totem.nome e da empresa:
     * se o nome nao normaliza ou nao ha empresa valida, recusa SEM alterar nada.
     *
     * @return array{ok:bool,codigo?:string,id?:int}
     */
    public function regerarUrl(int $idAdmin, int $idTotem, int $versaoEsperada, string $nomeDigitado, ?string $ip = null): array
    {
        return $this->comLock(fn (): array => $this->regerarUrlSobLock($idAdmin, $idTotem, $versaoEsperada, $nomeDigitado, $ip));
    }

    /** @return array<string,mixed> */
    private function regerarUrlSobLock(int $idAdmin, int $idTotem, int $versaoEsperada, string $nomeDigitado, ?string $ip): array
    {
        $idAuditoria = 0;
        try {
            $this->pdo->beginTransaction();
            if (!in_array($idAdmin, $this->usuarios->travarAdminsAtivos(), true)) {
                $this->pdo->rollBack();

                return $this->semPermissao('TOTEM_URL_REGERAR', $idAdmin, $idTotem, $ip);
            }
            $totem = $this->totens->buscarPorId($idTotem, true);
            if ($totem === null) {
                $this->pdo->rollBack();
                $this->auditarRecusa('TOTEM_URL_REGERAR', $idAdmin, null, [], $ip);

                return ['ok' => false, 'codigo' => 'totem_nao_encontrado'];
            }
            if ((int) $totem['url_versao'] !== $versaoEsperada) {
                $this->pdo->rollBack();
                $this->auditarRecusa('TOTEM_URL_REGERAR', $idAdmin, $idTotem, [], $ip);

                return ['ok' => false, 'codigo' => 'url_ja_regerada'];
            }
            $nomeTotem = (string) $totem['nome'];
            $digitado = trim($nomeDigitado, " \t");
            if ($digitado === '' || strlen($digitado) > 200 || mb_strtolower($digitado, 'UTF-8') !== mb_strtolower($nomeTotem, 'UTF-8')) {
                $this->pdo->rollBack();
                $this->auditarRecusa('TOTEM_URL_REGERAR', $idAdmin, $idTotem, [], $ip);

                return ['ok' => false, 'codigo' => 'totem_nome_confirmacao'];
            }
            // O codigo atual so e lido aqui dentro (nunca sai desta funcao nem vai a log).
            $codigoAtual = (string) $totem['codigo'];
            $prefixo = TotemCodigo::prefixoDoPadrao($codigoAtual);
            if ($prefixo === null) {
                $prefixo = $this->prefixoDoLegado($totem);
                if ($prefixo === null) {
                    $this->pdo->rollBack();
                    $this->auditarRecusa('TOTEM_URL_REGERAR', $idAdmin, $idTotem, [], $ip);

                    return ['ok' => false, 'codigo' => 'totem_legado_invalido'];
                }
            }

            $trocou = false;
            for ($tentativa = 1; !$trocou; $tentativa++) {
                if ($tentativa > self::TENTATIVAS_CODIGO) {
                    $this->pdo->rollBack();
                    error_log('TotemGestaoRn: regerar_codigo_colisao_esgotada');
                    $this->registrarErro('TOTEM_URL_REGERAR', $idAdmin, $idTotem, $ip);

                    return ['ok' => false, 'codigo' => 'totem_colisao'];
                }
                $novo = $prefixo . '-' . $this->novoHash();
                if ($novo === $codigoAtual) {
                    continue;
                }
                try {
                    $this->totens->atualizarCodigo($idTotem, $novo);
                    $trocou = true;
                } catch (PDOException $e) {
                    if (!$this->ehChaveDuplicada($e)) {
                        throw $e;
                    }
                }
            }
            $idAuditoria = $this->auditoria->abrir($idAdmin, 'TOTEM_URL_REGERAR', 'totem', $idTotem, [], $ip);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->desfazer();

            return $this->falhaTecnica('regerarUrl', $e, 'TOTEM_URL_REGERAR', $idAdmin, $idTotem, $ip);
        }
        $this->fecharAuditoria($idAuditoria, 'OK');

        return ['ok' => true, 'id' => $idTotem];
    }

    /**
     * Prefixo NOME-EMPRESA de um totem legado a partir de tb_totem.nome e da empresa,
     * ou null se o nome nao normaliza (2..24) ou nao ha empresa com slug de 1..16.
     *
     * @param array<string,mixed> $totem
     */
    private function prefixoDoLegado(array $totem): ?string
    {
        $nome = TotemCodigo::nome((string) $totem['nome']);
        if ($nome === null || $totem['id_empresa'] === null) {
            return null;
        }
        $empresa = $this->totens->buscarEmpresa((int) $totem['id_empresa'], false);
        if ($empresa === null) {
            return null;
        }
        $slug = TotemCodigo::empresa((string) $empresa['nome']);
        if ($slug === '' || strlen($slug) > TotemCodigo::EMPRESA_MAX) {
            return null;
        }

        return $nome . '-' . $slug;
    }

    // ------------------------------------------------------------------ internos

    /** HASH do gerador, validado (um gerador defeituoso vira erro de programacao, nunca codigo ruim). */
    private function novoHash(): string
    {
        $hash = ($this->geradorHash)();
        if (!is_string($hash) || preg_match('/\A[A-Z2-7]{16}\z/D', $hash) !== 1) {
            throw new LogicException('gerador de hash invalido');
        }

        return $hash;
    }

    /**
     * Serializa as mutacoes de totens com um lock NOMEADO do MySQL tomado ANTES da
     * transacao (mesmo padrao da F1). Falha ou timeout viram `erro_interno` (log so
     * da classe da excecao).
     *
     * @return array<string,mixed>
     */
    private function comLock(callable $fn): array
    {
        try {
            $obteve = $this->totens->obterLock(10);
        } catch (Throwable $e) {
            error_log('TotemGestaoRn: lock_falhou ' . get_class($e));

            return ['ok' => false, 'codigo' => 'erro_interno'];
        }
        if (!$obteve) {
            error_log('TotemGestaoRn: lock_indisponivel');

            return ['ok' => false, 'codigo' => 'erro_interno'];
        }
        try {
            return $fn();
        } finally {
            $this->totens->liberarLock();
        }
    }

    private function desfazer(): void
    {
        try {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        } catch (Throwable $e) {
            error_log('TotemGestaoRn: rollback_falhou ' . get_class($e));
        }
    }

    private function ehChaveDuplicada(PDOException $e): bool
    {
        return isset($e->errorInfo[1]) && (int) $e->errorInfo[1] === 1062;
    }

    /** @return array{ok:false,codigo:string} */
    private function semPermissao(string $acao, int $idAdmin, ?int $idAlvo, ?string $ip): array
    {
        $this->auditarRecusa($acao, $idAdmin, $idAlvo, [], $ip);

        return ['ok' => false, 'codigo' => 'sem_permissao'];
    }

    /** @param array<string,string|int> $detalhe */
    private function auditarRecusa(string $acao, int $idAdmin, ?int $idAlvo, array $detalhe, ?string $ip): void
    {
        try {
            $this->auditoria->registrar($idAdmin, $acao, 'totem', $idAlvo, 'SEM_EFEITO', $detalhe, $ip);
        } catch (Throwable $e) {
            error_log('TotemGestaoRn: auditoria_recusa_falhou ' . get_class($e));
        }
    }

    private function registrarErro(string $acao, int $idAtor, ?int $idAlvo, ?string $ip): void
    {
        try {
            $this->auditoria->registrar($idAtor, $acao, 'totem', $idAlvo, 'ERRO', [], $ip);
        } catch (Throwable $e2) {
            error_log('TotemGestaoRn: auditoria_erro_falhou ' . get_class($e2));
        }
    }

    /** @return array{ok:false,codigo:string} */
    private function falhaTecnica(string $operacao, Throwable $e, string $acao, int $idAtor, ?int $idAlvo, ?string $ip): array
    {
        error_log('TotemGestaoRn: ' . $operacao . '_falhou ' . get_class($e));
        $this->registrarErro($acao, $idAtor, $idAlvo, $ip);

        return ['ok' => false, 'codigo' => 'erro_interno'];
    }

    private function fecharAuditoria(int $idAuditoria, string $resultado): void
    {
        try {
            $this->auditoria->fechar($idAuditoria, $resultado);
        } catch (Throwable $e) {
            error_log('TotemGestaoRn: auditoria_fechar_falhou ' . get_class($e));
        }
    }
}
