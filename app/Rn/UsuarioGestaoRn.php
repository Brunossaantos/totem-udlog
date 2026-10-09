<?php

namespace App\Rn;

use App\Dao\AuditoriaDao;
use App\Dao\SessaoGestaoDao;
use App\Dao\UsuarioGestaoDao;
use PDO;
use PDOException;
use Throwable;
use Util\AuthGestao;
use Util\LogSistema;
use Util\SenhaPolitica;

/**
 * Regras de negocio dos usuarios da Gestao Totem (demanda gestao-totem, F1).
 *
 * Toda mutacao: (1) valida a entrada, (2) roda numa transacao que TRAVA os admins
 * ativos (FOR UPDATE) e reconfere que o ator ainda e admin ativo, serializando
 * pedidos concorrentes (protecao do ultimo admin), (3) abre a linha de auditoria
 * PENDENTE dentro da mesma transacao (se nao der para auditar, a acao nao
 * acontece), (4) fecha a auditoria como OK depois do COMMIT. Recusas por regra
 * viram uma linha SEM_EFEITO. Falha tecnica vira log fixo (so a classe da
 * excecao, nunca getMessage) e uma linha ERRO.
 *
 * Senhas temporarias so existem no valor de retorno: nunca em log, auditoria ou
 * banco (so o hash). Parametros com senha/hash levam #[\SensitiveParameter], e
 * todo ponto que chama hash/senha ou obtem o lock de admins (GET_LOCK) esta num
 * try/catch(Throwable) que devolve `erro_interno` e loga SO o nome da classe da
 * excecao (nunca getMessage nem trace): uma excecao nunca carrega a senha para o
 * log ou para a resposta.
 */
class UsuarioGestaoRn
{
    /** Formato do login (decisao do usuario: primeiro.segundo). UMA constante. */
    public const LOGIN_REGEX = '/\A[a-z]{2,30}\.[a-z]{2,30}\z/D';

    public const PERFIS = ['admin', 'usuario'];

    private const NOME_REGEX = '/\A[\p{L}][\p{L}\p{M} .\'-]{1,99}\z/u';

    private UsuarioGestaoDao $usuarios;

    private SessaoGestaoDao $sessoes;

    private AuditoriaDao $auditoria;

    public function __construct(private PDO $pdo)
    {
        $this->usuarios = new UsuarioGestaoDao($pdo);
        $this->sessoes = new SessaoGestaoDao($pdo);
        $this->auditoria = new AuditoriaDao($pdo);
    }

    /** @return list<array<string,mixed>> */
    public function listar(): array
    {
        return $this->usuarios->listar();
    }

    /** @return array<string,mixed>|null sem o hash da senha */
    public function obter(int $idUsuario): ?array
    {
        $u = $this->usuarios->buscarPorId($idUsuario);
        if ($u !== null) {
            unset($u['senha_hash']);
        }

        return $u;
    }

    // ------------------------------------------------------------------ validacao

    public static function normalizarLogin(string $login): string
    {
        // so espacos e tabs nas pontas (o trim padrao tambem remove NUL e quebras de linha)
        return strtolower(trim($login, " 	"));
    }

    public static function normalizarNome(string $nome): string
    {
        $nome = trim($nome);
        $colapsado = preg_replace('/\s+/u', ' ', $nome);

        return is_string($colapsado) ? $colapsado : $nome;
    }

    /**
     * @return array<string,string> erros por campo (vazio = valido)
     */
    public static function validarCampos(string $login, string $nome, string $perfil, bool $validarLogin = true): array
    {
        $erros = [];
        if ($validarLogin && preg_match(self::LOGIN_REGEX, $login) !== 1) {
            $erros['login'] = 'Use o formato primeiro.segundo, só com letras minúsculas e sem acentos (exemplo: maria.silva).';
        }
        if (preg_match(self::NOME_REGEX, $nome) !== 1 || mb_strlen($nome, 'UTF-8') < 2) {
            $erros['nome'] = 'Informe o nome com 2 a 100 caracteres, usando só letras, espaços, ponto, apóstrofo e hífen.';
        }
        if (!in_array($perfil, self::PERFIS, true)) {
            $erros['perfil'] = 'Escolha um perfil da lista: Administrador ou Usuário.';
        }

        return $erros;
    }

    // ------------------------------------------------------------------ mutacoes (so admin)

    /**
     * @return array{ok:bool,codigo?:string,erros?:array<string,string>,id?:int,senha_temporaria?:string}
     */
    public function criar(int $idAdmin, string $login, string $nome, string $perfil, ?string $ip = null): array
    {
        $login = self::normalizarLogin($login);
        $nome = self::normalizarNome($nome);
        $erros = self::validarCampos($login, $nome, $perfil);
        if ($erros !== []) {
            return ['ok' => false, 'codigo' => 'validacao', 'erros' => $erros];
        }
        $credencial = $this->gerarCredencialTemporaria('criar');
        if ($credencial === null) {
            return ['ok' => false, 'codigo' => 'erro_interno'];
        }
        [$temporaria, $hash] = $credencial;

        return $this->comLockAdmins(fn (): array => $this->criarSobLock($idAdmin, $login, $nome, $perfil, $ip, $temporaria, $hash));
    }

    /** @return array<string,mixed> */
    private function criarSobLock(int $idAdmin, string $login, string $nome, string $perfil, ?string $ip, #[\SensitiveParameter] string $temporaria, #[\SensitiveParameter] string $hash): array
    {
        $idAuditoria = 0;
        $idNovo = 0;
        try {
            $this->pdo->beginTransaction();
            if (!in_array($idAdmin, $this->usuarios->travarAdminsAtivos(), true)) {
                $this->pdo->rollBack();

                return $this->semPermissao('USUARIO_CRIAR', $idAdmin, null, $ip);
            }
            if ($this->usuarios->buscarPorLogin($login) !== null) {
                $this->pdo->rollBack();
                $this->auditarRecusa('USUARIO_CRIAR', $idAdmin, null, [], $ip);

                return ['ok' => false, 'codigo' => 'validacao', 'erros' => ['login' => 'Já existe um usuário com esse login. Escolha outro login.']];
            }
            $idNovo = $this->usuarios->inserir($login, $nome, $perfil, $hash, true, $idAdmin);
            $idAuditoria = $this->auditoria->abrir($idAdmin, 'USUARIO_CRIAR', 'usuario', $idNovo, ['origem' => 'web', 'perfil_para' => $perfil], $ip);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->desfazer();
            if ($e instanceof PDOException && $this->ehChaveDuplicada($e)) {
                return ['ok' => false, 'codigo' => 'validacao', 'erros' => ['login' => 'Já existe um usuário com esse login. Escolha outro login.']];
            }

            return $this->falhaTecnica('criar', $e, 'USUARIO_CRIAR', $idAdmin, null, $ip);
        }
        $this->fecharAuditoria($idAuditoria, 'OK');

        return ['ok' => true, 'id' => $idNovo, 'senha_temporaria' => $temporaria];
    }

    /**
     * Edita nome e perfil. Admin nao altera o PROPRIO perfil e o ultimo admin
     * ativo nunca e rebaixado.
     *
     * @return array{ok:bool,codigo?:string,erros?:array<string,string>}
     */
    public function editar(int $idAdmin, int $idAlvo, string $nome, string $perfil, ?string $ip = null): array
    {
        $nome = self::normalizarNome($nome);
        $erros = self::validarCampos('', $nome, $perfil, false);
        if ($erros !== []) {
            return ['ok' => false, 'codigo' => 'validacao', 'erros' => $erros];
        }

        return $this->comLockAdmins(fn (): array => $this->editarSobLock($idAdmin, $idAlvo, $nome, $perfil, $ip));
    }

    /** @return array<string,mixed> */
    private function editarSobLock(int $idAdmin, int $idAlvo, string $nome, string $perfil, ?string $ip): array
    {
        $idAuditoria = 0;
        try {
            $this->pdo->beginTransaction();
            $admins = $this->usuarios->travarAdminsAtivos();
            if (!in_array($idAdmin, $admins, true)) {
                $this->pdo->rollBack();

                return $this->semPermissao('USUARIO_PERFIL', $idAdmin, $idAlvo, $ip);
            }
            $alvo = $this->usuarios->buscarPorId($idAlvo, true);
            if ($alvo === null) {
                $this->pdo->rollBack();

                return ['ok' => false, 'codigo' => 'nao_encontrado'];
            }
            $perfilAtual = (string) $alvo['perfil'];
            $mudaPerfil = $perfilAtual !== $perfil;
            if ($mudaPerfil && $idAlvo === $idAdmin) {
                $this->pdo->rollBack();
                $this->auditarRecusa('USUARIO_PERFIL', $idAdmin, $idAlvo, [], $ip);

                return ['ok' => false, 'codigo' => 'proprio_perfil'];
            }
            if ($mudaPerfil && $perfilAtual === 'admin' && (int) $alvo['ativo'] === 1 && count($admins) <= 1) {
                $this->pdo->rollBack();
                $this->auditarRecusa('USUARIO_PERFIL', $idAdmin, $idAlvo, [], $ip);

                return ['ok' => false, 'codigo' => 'ultimo_admin'];
            }
            $this->usuarios->atualizarNomePerfil($idAlvo, $nome, $perfil);
            if ($mudaPerfil) {
                $idAuditoria = $this->auditoria->abrir(
                    $idAdmin,
                    'USUARIO_PERFIL',
                    'usuario',
                    $idAlvo,
                    ['perfil_de' => $perfilAtual, 'perfil_para' => $perfil],
                    $ip
                );
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->desfazer();

            return $this->falhaTecnica('editar', $e, 'USUARIO_PERFIL', $idAdmin, $idAlvo, $ip);
        }
        if ($idAuditoria > 0) {
            $this->fecharAuditoria($idAuditoria, 'OK');
        }

        return ['ok' => true];
    }

    /**
     * Ativa/desativa. Admin nao altera o proprio `ativo`; o ultimo admin ativo
     * nunca e desativado; desativar revoga as sessoes do usuario.
     *
     * @return array{ok:bool,codigo?:string,sem_mudanca?:bool,sessoes_revogadas?:int}
     */
    public function definirAtivo(int $idAdmin, int $idAlvo, bool $ativo, ?string $ip = null): array
    {
        return $this->comLockAdmins(fn (): array => $this->definirAtivoSobLock($idAdmin, $idAlvo, $ativo, $ip));
    }

    /** @return array<string,mixed> */
    private function definirAtivoSobLock(int $idAdmin, int $idAlvo, bool $ativo, ?string $ip): array
    {
        $idAuditoria = 0;
        $revogadas = 0;
        try {
            $this->pdo->beginTransaction();
            $admins = $this->usuarios->travarAdminsAtivos();
            if (!in_array($idAdmin, $admins, true)) {
                $this->pdo->rollBack();

                return $this->semPermissao('USUARIO_ATIVO', $idAdmin, $idAlvo, $ip);
            }
            if ($idAlvo === $idAdmin) {
                $this->pdo->rollBack();
                $this->auditarRecusa('USUARIO_ATIVO', $idAdmin, $idAlvo, [], $ip);

                return ['ok' => false, 'codigo' => 'proprio_ativo'];
            }
            $alvo = $this->usuarios->buscarPorId($idAlvo, true);
            if ($alvo === null) {
                $this->pdo->rollBack();

                return ['ok' => false, 'codigo' => 'nao_encontrado'];
            }
            if (((int) $alvo['ativo'] === 1) === $ativo) {
                $this->pdo->rollBack();

                return ['ok' => true, 'sem_mudanca' => true];
            }
            if (!$ativo && (string) $alvo['perfil'] === 'admin' && count($admins) <= 1) {
                $this->pdo->rollBack();
                $this->auditarRecusa('USUARIO_ATIVO', $idAdmin, $idAlvo, [], $ip);

                return ['ok' => false, 'codigo' => 'ultimo_admin'];
            }
            $this->usuarios->atualizarAtivo($idAlvo, $ativo);
            if (!$ativo) {
                $revogadas = $this->sessoes->revogarDoUsuario($idAlvo);
            }
            $idAuditoria = $this->auditoria->abrir(
                $idAdmin,
                'USUARIO_ATIVO',
                'usuario',
                $idAlvo,
                ['ativo_para' => $ativo ? '1' : '0', 'sessoes_revogadas' => min($revogadas, 9999)],
                $ip
            );
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->desfazer();

            return $this->falhaTecnica('definirAtivo', $e, 'USUARIO_ATIVO', $idAdmin, $idAlvo, $ip);
        }
        $this->fecharAuditoria($idAuditoria, 'OK');

        return ['ok' => true, 'sessoes_revogadas' => $revogadas];
    }

    /**
     * Redefine a senha de OUTRO usuario (senha temporaria nova, exibida uma
     * vez, troca obrigatoria no proximo acesso, sessoes revogadas, bloqueio por
     * conta zerado). O admin troca a PROPRIA senha em "Minha conta".
     *
     * `$versaoEsperada` (B7): versao da senha (`senha_versao`) que o admin viu no
     * formulario. Se mudou (outro envio ja redefiniu, F5 do POST), recusa com
     * `senha_ja_redefinida` SEM gerar outra senha. null = sem conferencia (uso
     * interno/testes); o controller sempre a envia.
     *
     * @return array{ok:bool,codigo?:string,senha_temporaria?:string,sessoes_revogadas?:int}
     */
    public function redefinirSenha(int $idAdmin, int $idAlvo, ?string $ip = null, ?int $versaoEsperada = null): array
    {
        $credencial = $this->gerarCredencialTemporaria('redefinirSenha');
        if ($credencial === null) {
            return ['ok' => false, 'codigo' => 'erro_interno'];
        }
        [$temporaria, $hash] = $credencial;

        return $this->comLockAdmins(fn (): array => $this->redefinirSenhaSobLock($idAdmin, $idAlvo, $ip, $temporaria, $hash, $versaoEsperada));
    }

    /** @return array<string,mixed> */
    private function redefinirSenhaSobLock(int $idAdmin, int $idAlvo, ?string $ip, #[\SensitiveParameter] string $temporaria, #[\SensitiveParameter] string $hash, ?int $versaoEsperada): array
    {
        $idAuditoria = 0;
        $revogadas = 0;
        try {
            $this->pdo->beginTransaction();
            $admins = $this->usuarios->travarAdminsAtivos();
            if (!in_array($idAdmin, $admins, true)) {
                $this->pdo->rollBack();

                return $this->semPermissao('SENHA_RESETADA', $idAdmin, $idAlvo, $ip);
            }
            if ($idAlvo === $idAdmin) {
                $this->pdo->rollBack();
                $this->auditarRecusa('SENHA_RESETADA', $idAdmin, $idAlvo, [], $ip);

                return ['ok' => false, 'codigo' => 'propria_senha'];
            }
            $alvo = $this->usuarios->buscarPorId($idAlvo, true);
            if ($alvo === null) {
                $this->pdo->rollBack();

                return ['ok' => false, 'codigo' => 'nao_encontrado'];
            }
            if ($versaoEsperada !== null && (int) $alvo['senha_versao'] !== $versaoEsperada) {
                $this->pdo->rollBack();
                $this->auditarRecusa('SENHA_RESETADA', $idAdmin, $idAlvo, [], $ip);

                return ['ok' => false, 'codigo' => 'senha_ja_redefinida'];
            }
            $this->usuarios->atualizarSenha($idAlvo, $hash, true);
            $revogadas = $this->sessoes->revogarDoUsuario($idAlvo);
            $idAuditoria = $this->auditoria->abrir(
                $idAdmin,
                'SENHA_RESETADA',
                'usuario',
                $idAlvo,
                ['origem' => 'web', 'sessoes_revogadas' => min($revogadas, 9999)],
                $ip
            );
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->desfazer();

            return $this->falhaTecnica('redefinirSenha', $e, 'SENHA_RESETADA', $idAdmin, $idAlvo, $ip);
        }
        $this->fecharAuditoria($idAuditoria, 'OK');

        return ['ok' => true, 'senha_temporaria' => $temporaria, 'sessoes_revogadas' => $revogadas];
    }

    /**
     * Desbloqueia a conta de um usuario bloqueado por tentativas de login (zera
     * `bloqueado_ate` e `tentativas_falhas`; nao mexe em senha nem sessoes).
     * SO admin ativo e NUNCA a propria conta (`proprio_desbloqueio`, SEM_EFEITO);
     * mesma serializacao das demais acoes. Conta que ja nao tem
     * bloqueio nem falhas devolve `sem_mudanca` (sem auditoria).
     *
     * @return array{ok:bool,codigo?:string,sem_mudanca?:bool}
     */
    public function desbloquear(int $idAdmin, int $idAlvo, ?string $ip = null): array
    {
        return $this->comLockAdmins(fn (): array => $this->desbloquearSobLock($idAdmin, $idAlvo, $ip));
    }

    /** @return array<string,mixed> */
    private function desbloquearSobLock(int $idAdmin, int $idAlvo, ?string $ip): array
    {
        $idAuditoria = 0;
        try {
            $this->pdo->beginTransaction();
            if (!in_array($idAdmin, $this->usuarios->travarAdminsAtivos(), true)) {
                $this->pdo->rollBack();

                return $this->semPermissao('USUARIO_DESBLOQUEAR', $idAdmin, $idAlvo, $ip);
            }
            if ($idAlvo === $idAdmin) {
                $this->pdo->rollBack();
                $this->auditarRecusa('USUARIO_DESBLOQUEAR', $idAdmin, $idAlvo, [], $ip);

                return ['ok' => false, 'codigo' => 'proprio_desbloqueio'];
            }
            $alvo = $this->usuarios->buscarPorId($idAlvo, true);
            if ($alvo === null) {
                $this->pdo->rollBack();

                return ['ok' => false, 'codigo' => 'nao_encontrado'];
            }
            if ($alvo['bloqueado_ate'] === null && (int) $alvo['tentativas_falhas'] === 0) {
                $this->pdo->rollBack();

                return ['ok' => true, 'sem_mudanca' => true];
            }
            $this->usuarios->desbloquear($idAlvo);
            $idAuditoria = $this->auditoria->abrir($idAdmin, 'USUARIO_DESBLOQUEAR', 'usuario', $idAlvo, [], $ip);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->desfazer();

            return $this->falhaTecnica('desbloquear', $e, 'USUARIO_DESBLOQUEAR', $idAdmin, $idAlvo, $ip);
        }
        $this->fecharAuditoria($idAuditoria, 'OK');

        return ['ok' => true];
    }

    // ------------------------------------------------------------------ minha conta (qualquer perfil)

    /**
     * Troca a PROPRIA senha. Exige a senha atual (erro conta como falha de senha
     * da conta), aplica a politica, revoga TODAS as sessoes do usuario (o
     * controller cria a sessao nova) e limpa a troca obrigatoria.
     *
     * B6: com a conta BLOQUEADA (por tentativas de login) a senha atual NAO e
     * nem verificada: sem isso um cookie roubado serviria para testar a senha
     * atual sem limite durante o bloqueio. A tentativa vira recusa SEM_EFEITO na
     * auditoria, nao conta falha nova e nao estende o bloqueio.
     *
     * @return array{ok:bool,codigo?:string,erros?:array<string,string>,bloqueada?:bool}
     */
    public function trocarPropriaSenha(
        int $idUsuario,
        #[\SensitiveParameter] string $senhaAtual,
        #[\SensitiveParameter] string $senhaNova,
        #[\SensitiveParameter] string $confirmacao,
        ?string $ip = null
    ): array {
        try {
            return $this->trocarPropriaSenhaInterno($idUsuario, $senhaAtual, $senhaNova, $confirmacao, $ip);
        } catch (Throwable $e) {
            $this->desfazer();

            return $this->falhaTecnica('trocarPropriaSenha', $e, 'SENHA_TROCADA', $idUsuario, $idUsuario, $ip);
        }
    }

    /** @return array<string,mixed> */
    private function trocarPropriaSenhaInterno(
        int $idUsuario,
        #[\SensitiveParameter] string $senhaAtual,
        #[\SensitiveParameter] string $senhaNova,
        #[\SensitiveParameter] string $confirmacao,
        ?string $ip
    ): array {
        $usuario = $this->usuarios->buscarPorId($idUsuario);
        if ($usuario === null || (int) $usuario['ativo'] !== 1) {
            return ['ok' => false, 'codigo' => 'sem_permissao'];
        }
        $msgAtualErrada = 'A senha atual está incorreta. Confira e tente de novo.';
        if (strlen($senhaAtual) > SenhaPolitica::MAX_BYTES_LOGIN) {
            return ['ok' => false, 'codigo' => 'validacao', 'erros' => ['senha_atual' => $msgAtualErrada]];
        }
        if ($this->usuarios->estaBloqueada($idUsuario)) {
            $this->auditarRecusa('SENHA_TROCADA', $idUsuario, $idUsuario, ['motivo' => 'conta_bloqueada'], $ip);

            return [
                'ok' => false,
                'codigo' => 'validacao',
                'erros' => ['geral' => 'Sua conta está bloqueada por excesso de tentativas de acesso. A senha não foi trocada. Aguarde o fim do bloqueio ou peça a um administrador para desbloquear a conta.'],
            ];
        }
        if (!SenhaPolitica::verificar($senhaAtual, (string) $usuario['senha_hash'])) {
            $bloqueada = $this->usuarios->registrarFalhaSenha($idUsuario, AuthGestao::LIMITE_FALHAS_CONTA, AuthGestao::BLOQUEIO_CONTA_MIN);
            if ($bloqueada) {
                $this->sessoes->revogarDoUsuario($idUsuario);
            }

            return ['ok' => false, 'codigo' => 'validacao', 'erros' => ['senha_atual' => $msgAtualErrada], 'bloqueada' => $bloqueada];
        }
        $erros = [];
        $erroPolitica = SenhaPolitica::validar($senhaNova, (string) $usuario['login'], $senhaAtual);
        if ($erroPolitica !== null) {
            $erros['senha_nova'] = $erroPolitica;
        } elseif (!hash_equals($senhaNova, $confirmacao)) {
            $erros['senha_confirmacao'] = 'A confirmação não confere com a nova senha. Digite as duas iguais.';
        }
        if ($erros !== []) {
            return ['ok' => false, 'codigo' => 'validacao', 'erros' => $erros];
        }

        $hash = SenhaPolitica::gerarHash($senhaNova);
        $idAuditoria = 0;
        try {
            $this->pdo->beginTransaction();
            $this->usuarios->atualizarSenha($idUsuario, $hash, false);
            $revogadas = $this->sessoes->revogarDoUsuario($idUsuario);
            $idAuditoria = $this->auditoria->abrir(
                $idUsuario,
                'SENHA_TROCADA',
                'usuario',
                $idUsuario,
                ['origem' => 'web', 'sessoes_revogadas' => min($revogadas, 9999)],
                $ip
            );
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->desfazer();

            return $this->falhaTecnica('trocarPropriaSenha', $e, 'SENHA_TROCADA', $idUsuario, $idUsuario, $ip);
        }
        $this->fecharAuditoria($idAuditoria, 'OK');

        return ['ok' => true];
    }

    // ------------------------------------------------------------------ primeiro admin (CLI)

    /**
     * Cria o primeiro admin (usado so por tools/criar-admin.php). Sem senha
     * temporaria: a pessoa digitou a propria senha, entao nao ha troca
     * obrigatoria. Recusa se ja existe admin ativo, salvo $forcar.
     *
     * @return array{ok:bool,codigo?:string,erros?:array<string,string>,id?:int}
     */
    public function criarAdminInicial(string $login, string $nome, #[\SensitiveParameter] string $senha, bool $forcar = false): array
    {
        $login = self::normalizarLogin($login);
        $nome = self::normalizarNome($nome);
        $erros = self::validarCampos($login, $nome, 'admin');
        $erroSenha = SenhaPolitica::validar($senha, $login);
        if ($erroSenha !== null) {
            $erros['senha'] = $erroSenha;
        }
        if ($erros !== []) {
            return ['ok' => false, 'codigo' => 'validacao', 'erros' => $erros];
        }
        try {
            $hash = SenhaPolitica::gerarHash($senha);
        } catch (Throwable $e) {
            error_log('UsuarioGestaoRn: criarAdminInicial_hash_falhou ' . get_class($e));

            return ['ok' => false, 'codigo' => 'erro_interno'];
        }

        return $this->comLockAdmins(fn (): array => $this->criarAdminInicialSobLock($login, $nome, $hash, $forcar));
    }

    /** @return array<string,mixed> */
    private function criarAdminInicialSobLock(string $login, string $nome, #[\SensitiveParameter] string $hash, bool $forcar): array
    {
        $idAuditoria = 0;
        $idNovo = 0;
        try {
            $this->pdo->beginTransaction();
            $this->usuarios->travarAdminsAtivos();
            if (!$forcar && $this->usuarios->existeAdminAtivo()) {
                $this->pdo->rollBack();

                return ['ok' => false, 'codigo' => 'admin_existe'];
            }
            if ($this->usuarios->buscarPorLogin($login) !== null) {
                $this->pdo->rollBack();

                return ['ok' => false, 'codigo' => 'validacao', 'erros' => ['login' => 'Já existe um usuário com esse login. Escolha outro login.']];
            }
            $idNovo = $this->usuarios->inserir($login, $nome, 'admin', $hash, false, null);
            $idAuditoria = $this->auditoria->abrir(null, 'USUARIO_CRIAR', 'usuario', $idNovo, ['origem' => 'cli', 'perfil_para' => 'admin'], null);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->desfazer();
            error_log('UsuarioGestaoRn: criarAdminInicial_falhou ' . get_class($e));

            return ['ok' => false, 'codigo' => 'erro_interno'];
        }
        $this->fecharAuditoria($idAuditoria, 'OK');

        return ['ok' => true, 'id' => $idNovo];
    }

    // ------------------------------------------------------------------ internos

    /**
     * Serializa as mutacoes que mexem em admins com um lock NOMEADO do MySQL
     * (GET_LOCK, o mesmo recurso ja usado em DocumentoController) tomado ANTES da
     * transacao. Sem ele, dois admins agindo ao mesmo tempo sobre o indice
     * (perfil, ativo) podiam gerar deadlock do InnoDB (a requisicao perdedora
     * virava erro_interno). Dentro do lock a transacao ainda trava os admins com
     * FOR UPDATE e reconfere o ator. Falha (Throwable) ou timeout ao obter o lock
     * viram `erro_interno` com log so da classe da excecao (sem 500 cru).
     *
     * @return array<string,mixed>
     */
    private function comLockAdmins(callable $fn): array
    {
        try {
            $obteve = $this->usuarios->obterLockAdmins(10);
        } catch (Throwable $e) {
            error_log('UsuarioGestaoRn: lock_admins_falhou ' . get_class($e));

            return ['ok' => false, 'codigo' => 'erro_interno'];
        }
        if (!$obteve) {
            error_log('UsuarioGestaoRn: lock_admins_indisponivel');

            return ['ok' => false, 'codigo' => 'erro_interno'];
        }
        try {
            return $fn();
        } finally {
            $this->usuarios->liberarLockAdmins();
        }
    }

    /**
     * Senha temporaria (CSPRNG) + hash, ou null se a geracao falhou (log so com a
     * classe da excecao; o hash e a temporaria nunca aparecem em log).
     *
     * @return array{0:string,1:string}|null [temporaria, hash]
     */
    private function gerarCredencialTemporaria(string $operacao): ?array
    {
        try {
            $temporaria = SenhaPolitica::gerarTemporaria();

            return [$temporaria, SenhaPolitica::gerarHash($temporaria)];
        } catch (Throwable $e) {
            error_log('UsuarioGestaoRn: ' . $operacao . '_hash_falhou ' . get_class($e));

            return null;
        }
    }

    private function desfazer(): void
    {
        try {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        } catch (Throwable $e) {
            error_log('UsuarioGestaoRn: rollback_falhou ' . get_class($e));
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
            $this->auditoria->registrar($idAdmin, $acao, 'usuario', $idAlvo, 'SEM_EFEITO', $detalhe, $ip);
        } catch (Throwable $e) {
            error_log('UsuarioGestaoRn: auditoria_recusa_falhou ' . get_class($e));
            LogSistema::registrar('auditoria_falhou', ['excecao' => $e]);
        }
    }

    /** @return array{ok:false,codigo:string} */
    private function falhaTecnica(string $operacao, Throwable $e, string $acao, int $idAtor, ?int $idAlvo, ?string $ip): array
    {
        error_log('UsuarioGestaoRn: ' . $operacao . '_falhou ' . get_class($e));
        try {
            $this->auditoria->registrar($idAtor, $acao, 'usuario', $idAlvo, 'ERRO', [], $ip);
        } catch (Throwable $e2) {
            error_log('UsuarioGestaoRn: auditoria_erro_falhou ' . get_class($e2));
            LogSistema::registrar('auditoria_falhou', ['excecao' => $e2]);
        }

        return ['ok' => false, 'codigo' => 'erro_interno'];
    }

    private function fecharAuditoria(int $idAuditoria, string $resultado): void
    {
        try {
            $this->auditoria->fechar($idAuditoria, $resultado);
        } catch (Throwable $e) {
            error_log('UsuarioGestaoRn: auditoria_fechar_falhou ' . get_class($e));
            LogSistema::registrar('auditoria_falhou', ['excecao' => $e]);
        }
    }
}
