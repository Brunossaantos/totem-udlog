<?php
/**
 * Minha conta. Variáveis: $usuario (array login, nome, perfil, ultimo_login_em),
 * $troca_obrigatoria (bool), $erros (array campo => mensagem; campos:
 * senha_atual, senha_nova, senha_confirmacao, geral).
 */
$perfilRotulo = $usuario['perfil'] === 'admin' ? 'Administrador' : 'Usuário';
/** Mensagem de erro de campo: ícone + texto (o estado nunca depende só de cor). */
$erroCampo = static function (string $campo) use ($erros): string {
    if (!isset($erros[$campo])) {
        return '';
    }

    return '<span class="gestao-campo__erro" id="erro-' . h($campo) . '" role="alert">' . gestaoIcone('alerta') . '<span class="gestao-sr">Erro: </span>' . h($erros[$campo]) . '</span>';
};
$atributosCampo = static fn (string $campo): string => isset($erros[$campo]) ? ' aria-invalid="true" aria-describedby="erro-' . h($campo) . '"' : '';
?>
<?php if ($troca_obrigatoria): ?>
<div class="gestao-aviso-caixa"><?= gestaoIcone('alerta') ?><span class="gestao-sr">Atenção: </span><p class="gestao-aviso" id="aviso-troca-obrigatoria" role="alert">Troca de senha obrigatória: por segurança, defina uma nova senha antes de continuar.</p></div>
<?php endif; ?>
<div class="gestao-colunas<?= $troca_obrigatoria ? ' gestao-colunas--foco' : '' ?>">
<?php if (!$troca_obrigatoria): /* na troca obrigatória o formulário é o único foco da página */ ?>
<section class="gestao-cartao" id="conta-perfil">
<h2 class="gestao-cartao__titulo">Meu perfil</h2>
<dl class="gestao-dados">
<dt>Nome</dt><dd id="conta-nome"><?= h($usuario['nome']) ?></dd>
<dt>Login</dt><dd id="conta-login"><?= h($usuario['login']) ?></dd>
<dt>Perfil</dt><dd id="conta-perfil-rotulo"><?= h($perfilRotulo) ?></dd>
<dt>Último acesso</dt><dd id="conta-ultimo-acesso"><?= gestaoData($usuario['ultimo_login_em'] ?? null, 'Primeiro acesso') ?></dd>
</dl>
</section>
<?php endif; ?>
<section class="gestao-cartao<?= $troca_obrigatoria ? ' gestao-cartao--foco' : '' ?>" id="conta-senha">
<h2 class="gestao-cartao__titulo"><?= $troca_obrigatoria ? 'Defina sua nova senha' : 'Trocar senha' ?></h2>
<?php if (isset($erros['geral'])): ?>
<div class="gestao-erro-caixa"><?= gestaoIcone('alerta') ?><span class="gestao-sr">Erro: </span><p class="gestao-erro-form" role="alert"><?= h($erros['geral']) ?></p></div>
<?php endif; ?>
<form class="gestao-form" id="form-trocar-senha" method="post" action="/gestao/conta.php" data-login="<?= h($usuario['login']) ?>">
<?= gestaoCsrfInput($ctx) ?>
<div class="gestao-campo<?= isset($erros['senha_atual']) ? ' gestao-campo--erro' : '' ?>">
<label class="gestao-campo__rotulo" for="senha_atual">Senha atual</label>
<input class="gestao-campo__entrada" id="senha_atual" name="senha_atual" type="password" autocomplete="current-password" maxlength="1024" required data-alternar-senha<?= $atributosCampo('senha_atual') ?>>
<?= $erroCampo('senha_atual') ?>
</div>
<div class="gestao-campo<?= isset($erros['senha_nova']) ? ' gestao-campo--erro' : '' ?>">
<label class="gestao-campo__rotulo" for="senha_nova">Nova senha</label>
<input class="gestao-campo__entrada" id="senha_nova" name="senha_nova" type="password" autocomplete="new-password" maxlength="72" required data-alternar-senha data-requisitos="lista-requisitos" aria-describedby="<?= isset($erros['senha_nova']) ? 'erro-senha_nova ' : '' ?>lista-requisitos"<?= isset($erros['senha_nova']) ? ' aria-invalid="true"' : '' ?>>
<?= $erroCampo('senha_nova') ?>
<p class="gestao-requisitos__titulo" id="titulo-requisitos">A nova senha precisa ter:</p>
<ul class="gestao-requisitos" id="lista-requisitos" aria-labelledby="titulo-requisitos">
<li class="gestao-requisitos__item" data-requisito="minimo">Mínimo de 12 caracteres</li>
<li class="gestao-requisitos__item" data-requisito="maximo">Máximo de 72 caracteres (acentos contam mais)</li>
<li class="gestao-requisitos__item" data-requisito="login">Diferente do login</li>
</ul>
</div>
<div class="gestao-campo<?= isset($erros['senha_confirmacao']) ? ' gestao-campo--erro' : '' ?>">
<label class="gestao-campo__rotulo" for="senha_confirmacao">Confirmar nova senha</label>
<input class="gestao-campo__entrada" id="senha_confirmacao" name="senha_confirmacao" type="password" autocomplete="new-password" maxlength="72" required data-alternar-senha<?= $atributosCampo('senha_confirmacao') ?>>
<?= $erroCampo('senha_confirmacao') ?>
</div>
<div class="gestao-barra-acoes">
<button class="gestao-botao gestao-botao--primario" id="btn-trocar-senha" type="submit"><?= $troca_obrigatoria ? 'Salvar nova senha' : 'Trocar senha' ?></button>
</div>
</form>
</section>
</div>
