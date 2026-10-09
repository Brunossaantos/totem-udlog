<?php
$erroCampo = static function (string $campo) use ($erros): string {
    if (!isset($erros[$campo])) {
        return '';
    }

    return '<span class="gestao-campo__erro" id="erro-' . h($campo) . '" role="alert">' . gestaoIcone('alerta') . '<span class="gestao-sr">Erro: </span>' . h($erros[$campo]) . '</span>';
};
$atributosCampo = static fn (string $campo): string => isset($erros[$campo]) ? ' aria-invalid="true" aria-describedby="erro-' . h($campo) . '"' : '';
?>
<section class="gestao-cartao" id="usuario-form-cartao">
<h2 class="gestao-cartao__titulo"><?= $editando ? 'Dados do cadastro' : 'Dados do novo usuário' ?></h2>
<form class="gestao-form" id="form-usuario" method="post" action="/gestao/usuario-form.php" autocomplete="off">
<?= gestaoCsrfInput($ctx) ?>
<?php if ($editando): ?>
<input type="hidden" name="id_usuario" value="<?= (int) $id_alvo ?>">
<?php endif; ?>
<div class="gestao-campo<?= isset($erros['login']) ? ' gestao-campo--erro' : '' ?>">
<label class="gestao-campo__rotulo" for="login">Login</label>
<?php if ($editando): ?>
<input class="gestao-campo__entrada" id="login" type="text" value="<?= h($valores['login']) ?>" readonly>
<span class="gestao-campo__ajuda">O login não pode ser alterado.</span>
<?php else: ?>
<input class="gestao-campo__entrada" id="login" name="login" type="text" value="<?= h($valores['login']) ?>" maxlength="61" autocapitalize="none" spellcheck="false" required<?= $atributosCampo('login') ?>>
<span class="gestao-campo__ajuda">Formato primeiro.segundo, só letras minúsculas, sem acentos.</span>
<?php endif; ?>
<?= $erroCampo('login') ?>
</div>
<div class="gestao-campo<?= isset($erros['nome']) ? ' gestao-campo--erro' : '' ?>">
<label class="gestao-campo__rotulo" for="nome">Nome</label>
<input class="gestao-campo__entrada" id="nome" name="nome" type="text" value="<?= h($valores['nome']) ?>" maxlength="100" required<?= $atributosCampo('nome') ?>>
<?= $erroCampo('nome') ?>
</div>
<div class="gestao-campo<?= isset($erros['perfil']) ? ' gestao-campo--erro' : '' ?>">
<label class="gestao-campo__rotulo" for="perfil">Perfil</label>
<select class="gestao-campo__entrada" id="perfil" name="perfil"<?= $proprio ? ' disabled' : '' ?><?= $atributosCampo('perfil') ?>>
<?php foreach ($perfis as $p): ?>
<option value="<?= h($p) ?>"<?= $valores['perfil'] === $p ? ' selected' : '' ?>><?= h($p === 'admin' ? 'Administrador' : 'Usuário') ?></option>
<?php endforeach; ?>
</select>
<?php if ($proprio): ?>
<input type="hidden" name="perfil" value="<?= h($valores['perfil']) ?>">
<span class="gestao-campo__ajuda">Você não pode alterar o próprio perfil.</span>
<?php endif; ?>
<?= $erroCampo('perfil') ?>
</div>
<?php if (!$editando): ?>
<p class="gestao-ajuda" id="ajuda-senha-temporaria"><?= gestaoIcone('info') ?><span>Uma senha temporária será gerada e mostrada uma única vez. No primeiro acesso a pessoa precisa trocar a senha.</span></p>
<?php endif; ?>
<div class="gestao-barra-acoes">
<button class="gestao-botao gestao-botao--primario" id="btn-salvar-usuario" type="submit"><?= $editando ? 'Salvar' : 'Criar usuário' ?></button>
<a class="gestao-botao gestao-botao--secundario" id="btn-cancelar-usuario" href="/gestao/usuarios.php">Cancelar</a>
</div>
</form>
</section>
