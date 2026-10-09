<?php

namespace App\Controller;

use PDO;
use Throwable;
use Util\AuthGestao;
use Util\Bootstrap;
use Util\GestaoConfig;
use Util\GestaoHttp;

final class GestaoContexto
{
    public const CAMINHO_CONTA = '/gestao/conta.php';

    public const CAMINHO_USUARIOS = '/gestao/usuarios.php';

    public const CAMINHO_TOTENS = '/gestao/totens.php';

    public const CAMINHO_ORDENS = '/gestao/ordens.php';

    public const CAMINHO_CLIENTES = '/gestao/clientes.php';

    public const CAMINHO_EMPRESAS = '/gestao/empresas.php';

    public const MENU = [
        ['id' => 'painel', 'rotulo' => 'Painel', 'href' => '/gestao/painel.php', 'perfil' => 'admin', 'disponivel' => false],
        ['id' => 'totens', 'rotulo' => 'Totens', 'href' => '/gestao/totens.php', 'perfil' => 'admin', 'disponivel' => true],
        ['id' => 'atendimentos', 'rotulo' => 'Atendimentos', 'href' => '/gestao/atendimentos.php', 'perfil' => 'admin', 'disponivel' => false],
        ['id' => 'ordens', 'rotulo' => 'Ordens de coleta', 'href' => '/gestao/ordens.php', 'perfil' => 'usuario', 'disponivel' => true],
        ['id' => 'clientes', 'rotulo' => 'Clientes', 'href' => '/gestao/clientes.php', 'perfil' => 'admin', 'disponivel' => true],
        ['id' => 'empresas', 'rotulo' => 'Empresas', 'href' => '/gestao/empresas.php', 'perfil' => 'admin', 'disponivel' => true],
        ['id' => 'anexos', 'rotulo' => 'Anexos órfãos', 'href' => '/gestao/anexos.php', 'perfil' => 'admin', 'disponivel' => false],
        ['id' => 'logs', 'rotulo' => 'Logs', 'href' => '/gestao/logs.php', 'perfil' => 'admin', 'disponivel' => true],
        ['id' => 'usuarios', 'rotulo' => 'Usuários', 'href' => '/gestao/usuarios.php', 'perfil' => 'admin', 'disponivel' => true],
        ['id' => 'conta', 'rotulo' => 'Minha conta', 'href' => '/gestao/conta.php', 'perfil' => 'usuario', 'disponivel' => true],
    ];

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
        'oc_ativada' => ['sucesso', 'Ordem ativada. Ela já pode ser usada em um check-in.'],
        'oc_inativada' => ['sucesso', 'Ordem inativada. Ela não pode mais ser usada em um check-in.'],
        'oc_sem_efeito_ja_ativa' => ['info', 'Nada foi alterado. A ordem já estava ativa.'],
        'oc_sem_efeito_ja_inativa' => ['info', 'Nada foi alterado. A ordem já estava inativa.'],
        'oc_estado_mudou' => ['erro', 'A ordem mudou de situação desde que você abriu a tela. Confira e tente de novo.'],
        'oc_nao_encontrada' => ['erro', 'Ordem de coleta não encontrada. Nada foi alterado. Atualize a lista e tente de novo.'],
        'oc_banco_indisponivel' => ['erro', 'Não foi possível consultar as ordens de coleta agora. Tente novamente em instantes.'],
        'oc_confirmacao_necessaria' => ['info', 'Esta ordem precisa de confirmação antes da alteração. Leia o aviso abaixo. Nada foi alterado ainda.'],
        'oc_pdf_indisponivel' => ['info', 'O PDF desta ordem não está disponível. Ele é apagado 15 dias depois que a ordem é inativada.'],
        'oc_baixa_resolvida' => ['sucesso', 'Baixa marcada como resolvida.'],
        'oc_baixa_ja_resolvida' => ['info', 'Nada foi alterado. Esta baixa já estava resolvida.'],
        'oc_baixa_nao_encontrada' => ['erro', 'Baixa pendente não encontrada. Nada foi alterado. Atualize a lista e tente de novo.'],
        'oc_baixa_recusada_cliente_ausente' => ['erro', 'A baixa não foi resolvida: o atendimento não tem o cliente informado, então a ordem não pode ser identificada. Nada foi alterado.'],
        'oc_baixa_recusada_oc_inexistente' => ['erro', 'A baixa não foi resolvida: a ordem não foi localizada. Nada foi alterado.'],
        'oc_baixa_recusada_oc_ambigua' => ['erro', 'A baixa não foi resolvida: há mais de uma ordem com este cliente e número. Nada foi alterado.'],
        'oc_baixa_recusada_oc_ativa' => ['erro', 'A baixa não foi resolvida: a ordem ainda está ativa. Inative a ordem antes, se for o caso. Nada foi alterado.'],
        'oc_baixa_recusada_externo_indisponivel' => ['erro', 'Não foi possível consultar as ordens de coleta agora. A baixa não foi resolvida. Tente novamente em instantes.'],
        'cliente_criado' => ['sucesso', 'Cliente cadastrado. O OCR e o autocomplete já o reconhecem.'],
        'cliente_editado' => ['sucesso', 'Cliente atualizado. O novo nome já vale no OCR e no autocomplete.'],
        'cliente_ativado' => ['sucesso', 'Cliente ativado. O OCR e o autocomplete voltaram a reconhecê-lo.'],
        'cliente_inativado' => ['sucesso', 'Cliente inativado. O OCR e o autocomplete deixaram de reconhecê-lo. Para voltar, ative o cliente de novo.'],
        'cliente_excluido' => ['sucesso', 'Cliente excluído de forma definitiva. O OCR e o autocomplete deixaram de reconhecê-lo.'],
        'cliente_sem_mudanca' => ['info', 'Nada foi alterado. O cliente já estava nesse estado.'],
        'cliente_nao_encontrado' => ['erro', 'Cliente não encontrado. Ele pode já ter sido excluído. Nada foi alterado. Atualize a lista e tente de novo.'],
        'cliente_confirmacao_necessaria' => ['info', 'Esta ação precisa de confirmação. Leia o aviso abaixo. Nada foi alterado ainda.'],
        'empresa_criada' => ['sucesso', 'Empresa cadastrada. Já pode receber totens.'],
        'empresa_editada' => ['sucesso', 'Empresa atualizada. As URLs dos totens já criados continuam com o nome anterior até serem regeradas.'],
        'empresa_ativada' => ['sucesso', 'Empresa ativada. Os totens dela voltam a concluir o check-in no Talent.'],
        'empresa_inativada' => ['sucesso', 'Empresa inativada. Os totens dela deixam de concluir o check-in no Talent enquanto ela estiver inativa.'],
        'empresa_excluida' => ['sucesso', 'Empresa excluída de forma definitiva.'],
        'empresa_sem_mudanca' => ['info', 'Nada foi alterado. A empresa já estava nesse estado.'],
        'empresa_nao_encontrada' => ['erro', 'Empresa não encontrada. Ela pode já ter sido excluída. Nada foi alterado. Atualize a lista e tente de novo.'],
        'empresa_confirmacao_necessaria' => ['info', 'Esta ação precisa de confirmação. Leia o aviso abaixo. Nada foi alterado ainda.'],
        'empresa_com_totens' => ['erro', 'Remova ou mova os totens desta empresa antes de excluir. Nada foi alterado.'],
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

    public const MENSAGENS_LOGIN = ['sessao_expirada', 'saiu', 'senha_alterada_entrar'];

    public const TIPOS_MENSAGEM =['sucesso', 'erro', 'info'];

    private function __construct(
        public PDO $pdo,
        public AuthGestao $auth,
        public ?array $sessao,
        public string $titulo,
        public string $itemMenu
    ) {
    }

    public static function iniciar(string $titulo, string $itemMenu, ?string $perfilMinimo, array $opcoes = []): self
    {
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

    private static function acaoSemSessao(AuthGestao $auth): string
    {
        return $auth->sessaoAtual() !== null ? GestaoHttp::ACAO_INICIO : GestaoHttp::ACAO_LOGIN;
    }

    public static function paginaInicial(string $perfil): string
    {
        return $perfil === 'admin' ? self::CAMINHO_USUARIOS : self::CAMINHO_ORDENS;
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

    public static function post(string $nome): string
    {
        $v = $_POST[$nome] ?? '';

        return is_string($v) ? $v : '';
    }

    public static function query(string $nome): string
    {
        $v = $_GET[$nome] ?? '';

        return is_string($v) ? $v : '';
    }

    public static function inteiroPositivo(string $texto): ?int
    {
        if (preg_match('/\A[1-9][0-9]{0,9}\z/D', $texto) !== 1) {
            return null;
        }

        return (int) $texto;
    }

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

    public function flash(): ?array
    {
        $codigo = self::query('msg');
        if ($codigo === '' || !isset(self::MENSAGENS[$codigo])) {
            return null;
        }
        if ($this->sessao === null && !in_array($codigo, self::MENSAGENS_LOGIN, true)) {
            return null;
        }

        return ['tipo' => self::MENSAGENS[$codigo][0], 'texto' => self::MENSAGENS[$codigo][1]];
    }
}
