<?php
/**
 * URL do totem (só admin; destino do PRG depois de criar e de regerar, e também
 * abre pelo botão "Copiar URL" da lista). Variáveis: $totem (id_totem, nome,
 * empresa_nome, ativo, url (completa), legado, criado_em, url_regerada_em). NUNCA
 * há token do totem.
 *
 * A nota "O endereço anterior deixou de funcionar" aparece sempre que a URL já foi regerada
 * (url_regerada_em preenchido, com a data): permanente e neutra (informação, sem caixa de alerta),
 * vale em qualquer abertura da tela.
 * O flash de ação (msg=url_regerada / totem_criado) só aparece após o PRG, via GestaoContexto::flash().
 * O botão "Copiar URL" (id btn-copiar-url) é criado pelo gestao.js a partir dos data-copiar-*.
 */
$ativo = (int) $totem['ativo'] === 1;
$regerada = ($totem['url_regerada_em'] ?? null) !== null;
?>
<section class="gestao-cartao gestao-cartao--url" id="totem-url-cartao">
<h2 class="gestao-cartao__titulo"><?= h($totem['nome']) ?></h2>
<dl class="gestao-dados">
<dt>Totem</dt><dd id="totem-url-nome"><?= h($totem['nome']) ?></dd>
<dt>Empresa</dt><dd id="totem-url-empresa"><?= h($totem['empresa_nome'] ?? 'Sem empresa') ?></dd>
<dt>Situação</dt><dd id="totem-url-situacao"><span class="gestao-situacao gestao-situacao--<?= $ativo ? 'ativo' : 'inativo' ?>"><?= gestaoIcone($ativo ? 'ok' : 'inativo') ?><span><?= h($ativo ? 'Ativo' : 'Inativo') ?></span></span></dd>
</dl>
<dl class="gestao-dados gestao-dados--url">
<dt>URL do quiosque</dt><dd class="gestao-senha-linha gestao-url-destaque" data-copiar-de="totem-url-valor" data-copiar-rotulo="Copiar URL" data-copiar-id="btn-copiar-url" data-copiar-objeto="a URL"><code class="gestao-totem-url gestao-totem-url--destaque" id="totem-url-valor"><?= h($totem['url']) ?></code></dd>
</dl>
<div class="gestao-aviso-caixa" id="totem-url-aviso"><?= gestaoIcone('alerta') ?><p class="gestao-aviso">Guarde esta URL: ela é a chave de acesso do totem; qualquer pessoa com ela abre o totem.</p></div>
<?php if ($regerada): ?>
<div class="gestao-nota-permanente" id="totem-url-regerada"><?= gestaoIcone('info') ?><p>O endereço anterior deixou de funcionar (URL regerada em <?= gestaoData($totem['url_regerada_em'] ?? null) ?>). O acesso do totem em si (token) não foi alterado; para bloquear um totem, desative-o.</p></div>
<?php endif; ?>
<p class="gestao-ajuda gestao-ajuda--url" id="totem-url-ajuda"><?= gestaoIcone('info') ?><span>Não envie esta URL por e-mail ou mensagem; use-a apenas no mini PC do totem.</span></p>
<?php if (!$ativo): ?>
<p class="gestao-ajuda" id="totem-url-inativo"><?= gestaoIcone('alerta') ?><span>Este totem está desativado e a URL não abre até ele ser ativado de novo.</span></p>
<?php endif; ?>
<div class="gestao-saida-senha">
<a class="gestao-botao gestao-botao--primario" id="btn-voltar-totens" href="/gestao/totens.php">Voltar para totens</a>
</div>
</section>
