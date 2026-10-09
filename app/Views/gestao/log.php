<?php
?>
<?php if ($erroCarga): ?>
<div class="gestao-estado gestao-estado--erro" id="logs-erro-carga" role="alert"><?= gestaoIcone('alerta') ?><span>Não foi possível carregar os logs agora. Tente novamente em instantes.</span></div>
<p><a class="gestao-botao gestao-botao--secundario" id="log-voltar" href="<?= h($urlVoltar) ?>"><span>Voltar para a lista</span></a></p>
<?php return; endif; ?>
<?php
$iconeNivel = ['erro' => 'erro', 'aviso' => 'alerta', 'info' => 'info'];
$n = (int) $registro['contador'];
?>
<div class="gestao-barra-acoes">
<a class="gestao-botao gestao-botao--secundario" id="log-voltar" href="<?= h($urlVoltar) ?>"><span>Voltar para a lista</span></a>
</div>
<section class="gestao-cartao" id="log-detalhe" data-id-log="<?= (int) $registro['id_log'] ?>" data-nivel="<?= h($nivelSlug) ?>">
<h2 class="gestao-cartao__titulo"><span class="gestao-nivel gestao-nivel--<?= h($nivelSlug) ?>"><?= gestaoIcone($iconeNivel[$nivelSlug] ?? 'info') ?><span><?= h($nivelRotulo) ?></span></span> <?= str_replace('_', '_<wbr>', h($registro['categoria'])) ?></h2>
<dl class="gestao-dados" id="log-contexto">
<dt>Mensagem</dt><dd id="log-mensagem"><?= h($registro['mensagem']) ?></dd>
<dt>Nível</dt><dd id="log-nivel"><?= h($nivelRotulo) ?></dd>
<dt>Aba</dt><dd id="log-aba"><?= h($abaRotulo) ?></dd>
<dt>Categoria</dt><dd id="log-categoria"><?= str_replace('_', '_<wbr>', h($registro['categoria'])) ?></dd>
<?php if ($mostrarTotem): ?>
<dt>Totem</dt><dd id="log-totem"><?= h($totemRotulo) ?></dd>
<?php endif; ?>
<dt>Primeira ocorrência</dt><dd id="log-primeira"><?= gestaoData($registro['criado_em'] ?? null) ?></dd>
<dt>Última ocorrência</dt><dd id="log-ultima"><?= gestaoData($registro['ultima_ocorrencia'] ?? null) ?></dd>
<dt>Repetições</dt><dd id="log-repeticoes"><?= h($n . ' ' . ($n === 1 ? 'ocorrência' : 'ocorrências')) ?></dd>
</dl>
<h3 class="gestao-cartao__subtitulo">Detalhe técnico</h3>
<?php if ($tecnico === []): ?>
<p id="log-tecnico-vazio">Este registro não tem detalhe técnico.</p>
<?php else: ?>
<dl class="gestao-dados" id="log-tecnico">
<?php foreach ($tecnico as [$chave, $valor]): ?>
<?php if ($chave === ''): ?>
<dt>Informação</dt><dd><?= h($valor) ?></dd>
<?php else: ?>
<dt><?= h($chave) ?></dt><dd><?= h($valor) ?></dd>
<?php endif; ?>
<?php endforeach; ?>
</dl>
<?php endif; ?>
</section>
