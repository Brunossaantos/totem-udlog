<?php
?>
<section class="gestao-login" id="gestao-login">
<img class="gestao-login__logo" src="<?= h(gestaoAsset('udlog.png')) ?>" width="180" height="58" alt="UDLOG">
<h1 class="gestao-login__titulo" id="gestao-titulo">Gestão Totem</h1>
<form class="gestao-form gestao-form-login" id="form-login" method="post" action="/gestao/login.php">
<input type="hidden" name="token_login" value="<?= h($token_login) ?>">
<?php if ($erro !== null): ?>
<div class="gestao-erro-caixa"><?= gestaoIcone('alerta') ?><span class="gestao-sr">Erro: </span><p class="gestao-erro-form" id="login-erro" role="alert"><?= h($erro) ?></p></div>
<?php endif; ?>
<div class="gestao-campo">
<label class="gestao-campo__rotulo" for="login">Login</label>
<input class="gestao-campo__entrada" id="login" name="login" type="text" value="<?= h($login_digitado) ?>" autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="60" aria-describedby="login-ajuda" required<?= $erro === null ? ' autofocus' : '' ?>>
<span class="gestao-campo__ajuda" id="login-ajuda">Exemplo: bruno.carvalho</span>
</div>
<div class="gestao-campo">
<label class="gestao-campo__rotulo" for="senha">Senha</label>
<input class="gestao-campo__entrada" id="senha" name="senha" type="password" autocomplete="current-password" maxlength="1024" required data-alternar-senha>
</div>
<p class="gestao-campo__ajuda" id="login-esqueceu">Esqueceu a senha? Peça a um administrador.</p>
<button class="gestao-botao gestao-botao--primario gestao-botao--cheio" id="btn-entrar" type="submit">Entrar</button>
</form>
</section>
