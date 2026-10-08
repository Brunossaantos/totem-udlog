<?php
/**
 * Layout (abertura) da Gestão Totem. Marcação semântica; o visual fica em
 * public/gestao/assets/gestao.css e o comportamento em gestao.js.
 *
 * Variáveis: $ctx (GestaoContexto), $tituloPagina (string), $flash
 * (array{tipo:'sucesso'|'erro'|'info',texto:string}|null), $menu (list de
 * {id,rotulo,href,atual}), $semMenu (bool), $usuarioLogado
 * (array{login,nome,perfil}|null). Proibido: script/style inline.
 */
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive">
<?php if ($usuarioLogado !== null): ?>
<meta name="csrf-token" content="<?= h($ctx->auth->csrfToken()) ?>">
<?php endif; ?>
<title><?= h($tituloPagina) ?> - Gestão Totem</title>
<link rel="icon" type="image/svg+xml" href="<?= h(gestaoAsset('favicon.svg')) ?>">
<link rel="icon" type="image/png" sizes="32x32" href="<?= h(gestaoAsset('favicon-32.png')) ?>">
<link rel="apple-touch-icon" sizes="180x180" href="<?= h(gestaoAsset('apple-touch-icon.png')) ?>">
<link rel="stylesheet" href="<?= h(gestaoAsset('gestao.css')) ?>">
</head>
<body class="gestao <?= $usuarioLogado !== null ? 'gestao--logada' : 'gestao--publica' ?><?= $semMenu ? ' gestao--sem-menu' : '' ?>"<?= $usuarioLogado !== null ? ' data-perfil="' . h($usuarioLogado['perfil']) . '"' : '' ?>>
<?= gestaoSprite() ?>
<a class="gestao-pular" href="#conteudo">Pular para o conteúdo</a>
<div class="gestao-app" id="gestao-app">
<?php if (!$semMenu): ?>
<aside class="gestao-sidebar" id="gestao-sidebar">
<div class="gestao-marca" id="gestao-marca">
<img class="gestao-marca__logo" src="<?= h(gestaoAsset('udlog.png')) ?>" width="140" height="45" alt="UDLOG">
<img class="gestao-marca__mini" src="<?= h(gestaoAsset('udlog-leao.png')) ?>" width="40" height="40" alt="">
<span class="gestao-marca__texto">Gestão Totem</span>
</div>
<nav class="gestao-nav" aria-label="Menu principal">
<ul class="gestao-menu">
<?php foreach ($menu as $item): ?>
<li class="gestao-menu__item<?= $item['atual'] ? ' gestao-menu__item--atual' : '' ?>"><a class="gestao-menu__link" href="<?= h($item['href']) ?>" title="<?= h($item['rotulo']) ?>"<?= $item['atual'] ? ' aria-current="page"' : '' ?>><?= gestaoIcone((string) $item['id']) ?><span class="gestao-menu__rotulo"><?= h($item['rotulo']) ?></span></a></li>
<?php endforeach; ?>
</ul>
</nav>
</aside>
<?php endif; ?>
<div class="gestao-principal">
<?php if ($usuarioLogado !== null): ?>
<header class="gestao-topo" id="gestao-topo">
<?php if ($semMenu): ?>
<img class="gestao-topo__logo" src="<?= h(gestaoAsset('udlog.png')) ?>" width="112" height="36" alt="UDLOG">
<?php endif; ?>
<h1 class="gestao-titulo" id="gestao-titulo"><?= h($tituloPagina) ?></h1>
<div class="gestao-usuario" id="gestao-usuario">
<span class="gestao-usuario__dados">
<span class="gestao-usuario__nome"><?= h($usuarioLogado['nome']) ?></span>
<span class="gestao-usuario__perfil"><?= h($usuarioLogado['perfil'] === 'admin' ? 'Administrador' : 'Usuário') ?></span>
</span>
<form class="gestao-form-sair" id="gestao-form-sair" method="post" action="/gestao/logout.php">
<?= gestaoCsrfInput($ctx) ?>
<button class="gestao-botao gestao-botao--secundario" id="gestao-sair" type="submit"><?= gestaoIcone('sair') ?><span>Sair</span></button>
</form>
</div>
</header>
<?php endif; ?>
<main class="gestao-conteudo" id="conteudo" tabindex="-1">
<?php if ($flash !== null): ?>
<?php
// tipos: sucesso | erro | info (neutro). Cada tipo tem icone, rotulo e role proprios.
$flashPartes = ['erro' => ['alerta', 'Erro:', 'alert'], 'info' => ['info', 'Informação:', 'status']][$flash['tipo']] ?? ['ok', 'Sucesso:', 'status'];
?>
<div class="gestao-flash gestao-flash--<?= h($flash['tipo']) ?>" id="gestao-flash" role="<?= $flashPartes[2] ?>" aria-live="polite" data-tipo="<?= h($flash['tipo']) ?>"><?= gestaoIcone($flashPartes[0]) ?><span class="gestao-flash__tipo"><?= $flashPartes[1] ?></span> <span class="gestao-flash__texto"><?= h($flash['texto']) ?></span></div>
<?php endif; ?>
