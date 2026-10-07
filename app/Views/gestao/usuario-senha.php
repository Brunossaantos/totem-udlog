<?php
/**
 * Senha temporária (exibida UMA vez, só nesta resposta POST). Variáveis:
 * $contexto ('criado'|'redefinida'), $usuario_login, $usuario_nome,
 * $senha_temporaria. Não há como rever esta tela.
 */
?>
<section class="gestao-cartao" id="senha-temporaria-cartao">
<h2 class="gestao-cartao__titulo"><?= $contexto === 'criado' ? 'Usuário criado' : 'Senha redefinida' ?></h2>
<div class="gestao-aviso-caixa"><?= gestaoIcone('alerta') ?><span class="gestao-sr">Atenção: </span><p class="gestao-aviso" id="senha-temporaria-aviso" role="alert">Anote agora: esta senha não será exibida de novo. Copie e entregue com segurança.</p></div>
<dl class="gestao-dados">
<dt>Usuário</dt><dd id="senha-temporaria-usuario"><?= h($usuario_nome) ?> (<?= h($usuario_login) ?>)</dd>
<dt>Senha temporária</dt><dd class="gestao-senha-linha" data-copiar-de="senha-temporaria-valor"><code class="gestao-senha-temporaria" id="senha-temporaria-valor"><?= h($senha_temporaria) ?></code></dd>
</dl>
<p class="gestao-ajuda">No próximo acesso a pessoa será obrigada a trocar a senha.</p>
<div class="gestao-saida-senha">
<p class="gestao-saida-senha__aviso" id="senha-temporaria-saida">Ao sair desta tela, a senha não poderá ser vista de novo.</p>
<a class="gestao-botao gestao-botao--primario" id="btn-voltar-usuarios" href="/gestao/usuarios.php">Voltar para usuários</a>
</div>
</section>
