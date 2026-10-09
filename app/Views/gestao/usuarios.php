<?php
?>
<div class="gestao-barra-acoes" id="usuarios-acoes">
<a class="gestao-botao gestao-botao--primario" id="btn-novo-usuario" href="/gestao/usuario-form.php"><?= gestaoIcone('mais') ?><span>Novo usuário</span></a>
</div>
<div class="gestao-tabela-wrap" role="region" aria-label="Tabela de usuários da gestão" tabindex="0">
<table class="gestao-tabela" id="tabela-usuarios">
<caption class="gestao-tabela__legenda">Usuários da gestão</caption>
<thead>
<tr><th scope="col">Nome</th><th scope="col">Login</th><th scope="col">Perfil</th><th scope="col">Situação</th><th scope="col">Último acesso</th><th scope="col">Ações</th></tr>
</thead>
<tbody>
<?php if ($usuarios === []): ?>
<tr class="gestao-tabela__vazio"><td colspan="6"><div class="gestao-estado gestao-estado--vazio"><?= gestaoIcone('usuarios') ?><span>Nenhum usuário cadastrado.</span></div></td></tr>
<?php endif; ?>
<?php foreach ($usuarios as $u): ?>
<?php $proprio = (bool) $u['eh_proprio']; $ativo = (int) $u['ativo'] === 1; $bloqueadoAte = $u['bloqueado_ate'] ?? null; ?>
<tr class="gestao-tabela__linha<?= $ativo ? '' : ' gestao-tabela__linha--inativa' ?>" data-id-usuario="<?= (int) $u['id_usuario'] ?>">
<td class="col-nome"><?= h($u['nome']) ?><?= $proprio ? ' (você)' : '' ?></td>
<td class="col-login"><?= h($u['login']) ?></td>
<td class="col-perfil"><?= h($u['perfil'] === 'admin' ? 'Administrador' : 'Usuário') ?></td>
<td class="col-situacao"><span class="gestao-situacao gestao-situacao--<?= $ativo ? 'ativo' : 'inativo' ?>"><?= gestaoIcone($ativo ? 'ok' : 'inativo') ?><span><?= h($ativo ? 'Ativo' : 'Inativo') ?></span></span><?= (int) $u['deve_trocar_senha'] === 1 ? '<span class="gestao-situacao__nota">Troca de senha pendente</span>' : '' ?><?= $bloqueadoAte !== null ? '<span class="gestao-situacao__bloqueio">' . gestaoIcone('alerta') . '<span>Bloqueado até ' . gestaoHora($bloqueadoAte) . '</span></span>' : '' ?></td>
<td class="col-ultimo-acesso"><?= gestaoData($u['ultimo_login_em'] ?? null, 'Nunca') ?></td>
<td class="col-acoes">
<div class="gestao-acoes-linha">
<div class="gestao-acoes-grupo">
<a class="gestao-botao gestao-botao--secundario gestao-botao--pequeno" href="/gestao/usuario-form.php?id=<?= (int) $u['id_usuario'] ?>" aria-label="Editar <?= h($u['nome']) ?>"><?= gestaoIcone('editar') ?><span>Editar</span></a>
<?php if ($proprio): ?>
<span class="gestao-acoes-dica">Para trocar sua senha, use Minha conta.</span>
<?php else: ?>
<form class="gestao-form-linha" method="post" action="/gestao/usuarios.php">
<?= gestaoCsrfInput($ctx) ?>
<input type="hidden" name="id_usuario" value="<?= (int) $u['id_usuario'] ?>">
<input type="hidden" name="versao_senha" value="<?= (int) $u['senha_versao'] ?>">
<?php if (!$ativo): ?>
<button class="gestao-botao gestao-botao--secundario gestao-botao--pequeno" type="submit" name="acao" value="ativar" aria-label="Ativar <?= h($u['nome']) ?>"><?= gestaoIcone('ok') ?><span>Ativar</span></button>
<?php endif; ?>
<?php if ($bloqueadoAte !== null): ?>
<button class="gestao-botao gestao-botao--secundario gestao-botao--pequeno" type="submit" name="acao" value="desbloquear" aria-label="Desbloquear <?= h($u['nome']) ?>"><?= gestaoIcone('desbloquear') ?><span>Desbloquear</span></button>
<?php endif; ?>
<button class="gestao-botao gestao-botao--secundario gestao-botao--pequeno" type="submit" name="acao" value="redefinir-senha" aria-label="Redefinir senha de <?= h($u['nome']) ?>" data-confirmar="Redefinir a senha deste usuário? Uma nova senha temporária será gerada e mostrada uma única vez, a senha atual deixará de valer e as sessões abertas serão encerradas." data-confirmar-titulo="Redefinir senha" data-confirmar-rotulo="Redefinir senha" data-confirmar-alvo="<?= h($u['nome'] . ' (' . $u['login'] . ')') ?>"><?= gestaoIcone('chave') ?><span>Redefinir senha</span></button>
</form>
<?php endif; ?>
</div>
<?php if (!$proprio && $ativo): ?>
<div class="gestao-acoes-grupo gestao-acoes-grupo--destrutivo">
<form class="gestao-form-linha" method="post" action="/gestao/usuarios.php">
<?= gestaoCsrfInput($ctx) ?>
<input type="hidden" name="id_usuario" value="<?= (int) $u['id_usuario'] ?>">
<input type="hidden" name="versao_senha" value="<?= (int) $u['senha_versao'] ?>">
<button class="gestao-botao gestao-botao--destrutivo gestao-botao--pequeno" type="submit" name="acao" value="desativar" aria-label="Desativar <?= h($u['nome']) ?>" data-confirmar="Desativar este usuário? Ele não poderá mais entrar e as sessões abertas serão encerradas." data-confirmar-titulo="Desativar usuário" data-confirmar-rotulo="Desativar" data-confirmar-alvo="<?= h($u['nome'] . ' (' . $u['login'] . ')') ?>" data-confirmar-destrutivo="1"><?= gestaoIcone('alerta') ?><span>Desativar</span></button>
</form>
</div>
<?php endif; ?>
</div>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
