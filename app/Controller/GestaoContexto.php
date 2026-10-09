<?php

namespace App\Controller;

use PDO;
use Throwable;
use Util\AuthGestao;
use Util\Bootstrap;
use Util\GestaoConfig;
use Util\GestaoHttp;

/**
 * Contexto de uma requisicao da Gestao Totem (demanda gestao-totem, F1): faz o
 * GUARD (HTTPS, host, metodo, origem, sessao, perfil, troca obrigatoria de
 * senha, CSRF) ANTES de qualquer saida HTML e entrega ao controller a conexao,
 * o AuthGestao e a sessao validada.
 *
 * Usado pelo helper de pagina `gestaoPagina()` (app/Views/gestao/_helpers.php).
 */
final class GestaoContexto
{
    public const CAMINHO_CONTA = '/gestao/conta.php';

    public const CAMINHO_USUARIOS = '/gestao/usuarios.php';

    public const CAMINHO_TOTENS = '/gestao/totens.php';

    /**
     * Itens do menu lateral. `disponivel` false = ainda nao existe (fica oculto
     * ate a fase que o cria). `perfil` = perfil MINIMO para ver o item.
     *
     * @var list<array{id:string,rotulo:string,href:string,perfil:string,disponivel:bool}>
     */
    public const MENU = [
        ['id' => 'painel', 'rotulo' => 'Painel', 'href' => '/gestao/painel.php', 'perfil' => 'admin', 'disponivel' => false],
        ['id' => 'totens', 'rotulo' => 'Totens', 'href' => '/gestao/totens.php', 'perfil' => 'admin', 'disponivel' => true],
        ['id' => 'atendimentos', 'rotulo' => 'Atendimentos', 'href' => '/gestao/atendimentos.php', 'perfil' => 'admin', 'disponivel' => false],
        ['id' => 'ordens', 'rotulo' => 'Ordens de coleta', 'href' => '/gestao/ordens.php', 'perfil' => 'usuario', 'disponivel' => false],
        ['id' => 'anexos', 'rotulo' => 'Anexos órfãos', 'href' => '/gestao/anexos.php', 'perfil' => 'admin', 'disponivel' => false],
        ['id' => 'logs', 'rotulo' => 'Logs', 'href' => '/gestao/logs.php', 'perfil' => 'admin', 'disponivel' => true],
        ['id' => 'usuarios', 'rotulo' => 'Usuários', 'href' => '/gestao/usuarios.php', 'perfil' => 'admin', 'disponivel' => true],
        ['id' => 'conta', 'rotulo' => 'Minha conta', 'href' => '/gestao/conta.php', 'perfil' => 'usuario', 'disponivel' => true],
    ];

    /**
     * Mensagens de retorno por codigo fixo (?msg=codigo): nunca texto livre na URL.
     * tipo: sucesso | erro | info (info = neutro: "nada a alterar", avisos que
     * nao sao nem sucesso nem erro). Estilo: voz ativa, o que aconteceu, o efeito
     * e o proximo passo.
     *
     * @var array<string,array{0:string,1:string}>
     */
    public const MENSAGENS = [
        'usuario_editado' => ['sucesso', 'Usuário atualizado. As alterações já valem.'],
        'usuario_ativado' => ['sucesso', 'Usuário ativado. Ele já pode entrar.'],
        'usuario_desativado' => ['sucesso', 'Usuário desativado. Ele não pode mais entrar e as sessões abertas foram encerradas.'],
        'usuario_desbloqueado' => ['sucesso', 'Conta desbloqueada. O usuário já pode tentar entrar de novo.'],
        'sem_mudanca' => ['info', 'Nada foi alterado. O usuário já estava nesse estado.'],
        'senha_trocada' => ['sucesso', 'Senha alterada. As suas outras sessões foram encerradas.'],
        'ultimo_admin' => ['erro', 'Não é possível remover o último administrador ativo. Nada foi alterado. Ative ou cadastre outro administrador antes.'],
        'proprio_perfil' => ['erro', 'Você não pode alterar o seu próprio perfil. Nada foi alterado. Peça a outro administrador.'],
        'proprio_ativo' => ['erro', 'Você não pode ativar nem desativar a sua própria conta. Nada foi alterado. Peça a outro administrador.'],
        'proprio_desbloqueio' => ['erro', 'Você não pode desbloquear a própria conta. Nada foi alterado. Peça a outro administrador.'],
        'totem_criado' => ['sucesso', 'Totem criado e ativo. Copie a URL abaixo e abra no navegador do quiosque.'],
        'totem_ativado' => ['sucesso', 'Totem ativado. O quiosque já abre normalmente.'],
        'totem_desativado' => ['sucesso', 'Totem desativado. A página e a API do quiosque pararam de responder agora. Para voltar a usar, ative o totem de novo.'],
        'url_regerada' => ['sucesso', 'URL regerada. O endereço anterior deixou de funcionar. O acesso do totem em si (token) não foi alterado; para bloquear um totem, desative-o.'],
        'totem_sem_mudanca' => ['info', 'Nada foi alterado. O totem já estava nesse estado.'],
        'totem_nao_encontrado' => ['erro', 'Totem não encontrado. Nada foi alterado. Atualize a lista e tente de novo.'],
        'totem_atendimento_em_andamento' => ['erro', 'Há atendimento recente neste totem. Desative novamente e confirme a desativação.'],
        'url_ja_regerada' => ['erro', 'A URL deste totem já foi regerada por outro pedido. Nada foi alterado. Confira a URL atual na lista antes de tentar de novo.'],
        'totem_nome_confirmacao' => ['erro', 'O nome digitado não confere com o nome do totem. A URL não foi regerada. Digite o nome exatamente como aparece na lista.'],
        'totem_legado_invalido' => ['erro', 'Este totem é antigo e o nome ou a empresa dele não permitem montar uma URL no padrão novo. A URL não foi alterada. Avise quem administra o sistema.'],
        'totem_colisao' => ['erro', 'Não foi possível gerar um código único agora. Nada foi alterado. Tente novamente.'],
        'log_nao_encontrado' => ['erro', 'Registro não encontrado. Ele pode ter sido apagado pela retenção de 90 dias.'],
        'sessao_expirada' => ['info', 'Sua sessão terminou. Digite o login e a senha para entrar de novo.'],
        'saiu' => ['sucesso', 'Você saiu da gestão. Para voltar, digite o login e a senha.'],
        'senha_alterada_entrar' => ['sucesso', 'Senha alterada. Digite o login e a nova senha para entrar.'],
        'propria_senha' => ['erro', 'Para trocar a sua senha, use a página Minha conta. Nada foi alterado.'],
        'senha_ja_redefinida' => ['erro', 'A senha já foi redefinida. Peça a quem tem a tela original ou redefina de novo.'],
        'nao_encontrado' => ['erro', 'Usuário não encontrado. Nada foi alterado. Atualize a lista e tente de novo.'],
        'sem_permissao' => ['erro', 'Você não tem permissão para esta ação. Nada foi alterado.'],
        'erro_interno' => ['erro', 'Não foi possível concluir. Nada foi alterado. Tente novamente.'],
    ];

    /** Codigos que a tela de login (visitante anonimo, sem sessao) aceita em ?msg=; os demais sao ignorados. */
    public const MENSAGENS_LOGIN = ['sessao_expirada', 'saiu', 'senha_alterada_entrar'];

    public const TIPOS_MENSAGEM =['sucesso', 'erro', 'info'];

    /** @param array<string,mixed>|null $sessao */
    private function __construct(
        public PDO $pdo,
        public AuthGestao $auth,
        public ?array $sessao,
        public string $titulo,
        public string $itemMenu
    ) {
    }

    /**
     * @param string|null $perfilMinimo null = pagina publica (login). 'usuario' = qualquer perfil logado. 'admin'.
     * @param array{trocaPendenteOk?:bool,semSessaoRedireciona?:bool,metodos?:list<string>} $opcoes
     */
    public static function iniciar(string $titulo, string $itemMenu, ?string $perfilMinimo, array $opcoes = []): self
    {
        // M1: um stack trace nunca leva os argumentos (senha digitada, hash, token) e
        // uma excecao nao tratada vira resposta generica com log so da classe.
        ini_set('zend.exception_ignore_args', '1');
        GestaoHttp::cabecalhosSeguranca();
        GestaoHttp::registrarTratadorDeExcecao();

        try {
            $pdo = Bootstrap::conectar(__DIR__ . '/../../');
        } catch (Throwable $e) {
            GestaoHttp::negar(503, 'Serviço indisponível', 'Não foi possível acessar a gestão agora. Tente novamente em instantes.', [], GestaoHttp::ACAO_RECARREGAR);
        }
        $config = GestaoConfig::doAmbiente($_ENV);
        if (!$config->valida()) {
            error_log('gestao: configuracao_invalida (GESTAO_HASH_SALT ausente ou curta)');
            GestaoHttp::negar(503, 'Serviço indisponível', 'A gestão ainda não está configurada neste servidor. Avise quem administra o sistema.', [], GestaoHttp::ACAO_NENHUMA);
        }
        $auth = new AuthGestao($pdo, $config, $_SERVER, $_COOKIE);

        if (!$auth->transporteAceito()) {
            GestaoHttp::negar(403, 'HTTPS obrigatório', 'Esta área só abre por conexão segura. Acesse o endereço começando por https://.', [], GestaoHttp::ACAO_NENHUMA);
        }
        if ($auth->hostDaRequisicao() === null) {
            GestaoHttp::negar(400, 'Requisição inválida', 'O endereço de acesso não foi reconhecido. Digite o endereço da gestão de novo.', [], GestaoHttp::ACAO_NENHUMA);
        }
        $metodos = $opcoes['metodos'] ?? ['GET', 'POST'];
        if (!in_array($auth->metodo(), $metodos, true)) {
            GestaoHttp::negar(405, 'Método não permitido', 'Esta página não aceita esse tipo de pedido. Volte e use os botões da própria página.', ['Allow: ' . implode(', ', $metodos)], self::acaoSemSessao($auth));
        }
        if (!$auth->metodoSeguro() && !$auth->origemPermitida()) {
            GestaoHttp::negar(403, 'Requisição inválida', 'O pedido veio de uma origem não permitida e foi bloqueado. Nada foi alterado. Recarregue a página e tente de novo.', [], GestaoHttp::ACAO_RECARREGAR);
        }

        if ($perfilMinimo === null) {
            return new self($pdo, $auth, $auth->sessaoAtual(), $titulo, $itemMenu);
        }

        if (($opcoes['semSessaoRedireciona'] ?? false) === true && $auth->sessaoAtual() === null) {
            $auth->limparCookie();
            GestaoHttp::redirecionar('/gestao/login.php');
        }
        $sessao = $perfilMinimo === 'admin' ? $auth->exigirPerfil('admin') : $auth->exigirPerfil('admin', 'usuario');

        if ($sessao['deve_trocar_senha'] && ($opcoes['trocaPendenteOk'] ?? false) !== true) {
            if ($auth->metodoSeguro()) {
                GestaoHttp::redirecionar(self::CAMINHO_CONTA);
            }
            GestaoHttp::negar(403, 'Troca de senha obrigatória', 'Você precisa trocar a senha antes de continuar. Abra Minha conta e defina uma senha nova.', [], GestaoHttp::ACAO_CONTA);
        }

        if (!$auth->metodoSeguro()) {
            $enviado = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? null);
            if (!$auth->csrfValido(is_string($enviado) ? $enviado : null)) {
                GestaoHttp::negar(403, 'Página expirada', 'A página ficou aberta por tempo demais e o pedido foi bloqueado por segurança. Nada foi alterado. Recarregue a página e tente de novo.', [], GestaoHttp::ACAO_RECARREGAR);
            }
        }

        return new self($pdo, $auth, $sessao, $titulo, $itemMenu);
    }

    /** Botao da pagina de erro quando o erro nao e de sessao/CSRF: Voltar ao inicio (com sessao) ou Ir para o login. */
    private static function acaoSemSessao(AuthGestao $auth): string
    {
        return $auth->sessaoAtual() !== null ? GestaoHttp::ACAO_INICIO : GestaoHttp::ACAO_LOGIN;
    }

    public static function paginaInicial(string $perfil): string
    {
        return $perfil === 'admin' ? self::CAMINHO_USUARIOS : self::CAMINHO_CONTA;
    }

    public function idUsuario(): int
    {
        return (int) ($this->sessao['id_usuario'] ?? 0);
    }

    public function ehAdmin(): bool
    {
        return ($this->sessao['perfil'] ?? '') === 'admin';
    }

    public function ip(): string
    {
        return $this->auth->ipCliente();
    }

    /** Campo de formulario (POST) como string, '' se ausente ou nao for string. */
    public static function post(string $nome): string
    {
        $v = $_POST[$nome] ?? '';

        return is_string($v) ? $v : '';
    }

    /** Parametro de query (GET) como string, '' se ausente ou nao for string. */
    public static function query(string $nome): string
    {
        $v = $_GET[$nome] ?? '';

        return is_string($v) ? $v : '';
    }

    /** Inteiro positivo vindo de texto (so digitos), ou null. */
    public static function inteiroPositivo(string $texto): ?int
    {
        if (preg_match('/\A[1-9][0-9]{0,9}\z/D', $texto) !== 1) {
            return null;
        }

        return (int) $texto;
    }

    /**
     * Itens do menu que o perfil logado pode ver e que ja existem.
     *
     * @return list<array{id:string,rotulo:string,href:string,atual:bool}>
     */
    public function menu(): array
    {
        if ($this->sessao === null || $this->sessao['deve_trocar_senha']) {
            return [];
        }
        $itens = [];
        foreach (self::MENU as $item) {
            if (!$item['disponivel']) {
                continue;
            }
            if ($item['perfil'] === 'admin' && !$this->ehAdmin()) {
                continue;
            }
            $itens[] = ['id' => $item['id'], 'rotulo' => $item['rotulo'], 'href' => $item['href'], 'atual' => $item['id'] === $this->itemMenu];
        }

        return $itens;
    }

    /** @return array{tipo:string,texto:string}|null mensagem de retorno (?msg=codigo) */
    public function flash(): ?array
    {
        $codigo = self::query('msg');
        if ($codigo === '' || !isset(self::MENSAGENS[$codigo])) {
            return null;
        }
        // Sem sessao (login, anonimo): so os codigos seguros da tela de login.
        if ($this->sessao === null && !in_array($codigo, self::MENSAGENS_LOGIN, true)) {
            return null;
        }

        return ['tipo' => self::MENSAGENS[$codigo][0], 'texto' => self::MENSAGENS[$codigo][1]];
    }
}
