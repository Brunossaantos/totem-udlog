<?php
?>
<div class="gestao-barra-acoes" id="empresas-acoes">
<a class="gestao-botao gestao-botao--primario" id="btn-nova-empresa" href="/gestao/empresa-form.php"><?= gestaoIcone('mais') ?><span>Nova empresa</span></a>
</div>
<div class="gestao-nota-permanente" id="empresas-aviso"><?= gestaoIcone('info') ?><p>O Talent só enxerga empresas ativas: ao inativar uma empresa, os totens ativos dela deixam de concluir o check-in. O CNPJ não pode ser alterado depois de criado. Só é possível excluir uma empresa sem nenhum totem vinculado.</p></div>
<?php if ($confirmacao !== null): ?>
<section class="gestao-estado gestao-estado--aviso" id="empresa-confirmacao" role="alert" data-acao="<?= h($confirmacao['acao']) ?>" data-id-empresa="<?= (int) $confirmacao['id_empresa'] ?>">
<?= gestaoIcone('alerta') ?>
<div class="gestao-estado__corpo">
<p><strong><?= h($confirmacao['nome']) ?></strong></p>
<p id="empresa-confirmacao-texto"><?= h($confirmacao['texto']) ?></p>
<form class="gestao-form-acao" id="form-empresa-confirmar" method="post" action="/gestao/empresa-acao.php">
<?= gestaoCsrfInput($ctx) ?>
<input type="hidden" name="acao" value="<?= h($confirmacao['acao']) ?>">
<input type="hidden" name="id_empresa" value="<?= (int) $confirmacao['id_empresa'] ?>">
<input type="hidden" name="confirmar" value="1">
<a class="gestao-botao gestao-botao--secundario" id="empresa-confirmacao-cancelar" href="/gestao/empresas.php"><span>Cancelar</span></a>
<button class="gestao-botao gestao-botao--destrutivo-cheio" id="btn-empresa-confirmar" type="submit" aria-label="<?= h($confirmacao['rotulo']) ?>: <?= h($confirmacao['nome']) ?>"><?= gestaoIcone($confirmacao['acao'] === 'excluir' ? 'excluir' : 'inativo') ?><span><?= h($confirmacao['rotulo']) ?></span></button>
</form>
</div>
</section>
<?php endif; ?>
<div class="gestao-tabela-wrap" role="region" aria-label="Tabela de empresas" tabindex="0">
<table class="gestao-tabela gestao-tabela--empresas" id="tabela-empresas">
<caption class="gestao-tabela__legenda">Empresas cadastradas</caption>
<colgroup><col class="gestao-col-nome"><col class="gestao-col-cnpj"><col class="gestao-col-situacao"><col class="gestao-col-totens"><col class="gestao-col-criada"><col class="gestao-col-acoes"></colgroup>
<thead>
<tr><th scope="col">Nome</th><th scope="col">CNPJ</th><th scope="col">Situação</th><th scope="col">Totens vinculados</th><th scope="col">Criada em</th><th scope="col">Ações</th></tr>
</thead>
<tbody>
<?php if ($empresas === []): ?>
<tr class="gestao-tabela__vazio"><td colspan="6"><div class="gestao-estado gestao-estado--vazio" id="empresas-vazio"><?= gestaoIcone('empresas') ?><span>Nenhuma empresa cadastrada. Cadastre a primeira.</span></div></td></tr>
<?php endif; ?>
<?php foreach ($empresas as $e): ?>
<?php $ativo = (int) $e['ativo'] === 1; $idE = (int) $e['id_empresa']; $nAtivos = (int) $e['totens_ativos']; $nTotal = (int) $e['totens_total']; ?>
<tr class="gestao-tabela__linha<?= $ativo ? '' : ' gestao-tabela__linha--inativa' ?>" data-id-empresa="<?= $idE ?>" data-ativo="<?= $ativo ? 1 : 0 ?>">
<td class="col-nome"><?= h($e['nome']) ?></td>
<td class="col-cnpj"><?= gestaoCnpj($e['cnpj']) ?></td>
<td class="col-situacao"><span class="gestao-situacao gestao-situacao--<?= $ativo ? 'ativo' : 'inativo' ?>"><?= gestaoIcone($ativo ? 'ok' : 'inativo') ?><span><?= h($ativo ? 'Ativa' : 'Inativa') ?></span></span></td>
<td class="col-totens" data-totens-ativos="<?= $nAtivos ?>" data-totens-total="<?= $nTotal ?>"><?= h($nTotal === 0 ? 'Nenhum totem' : $nAtivos . ' ativo(s) de ' . $nTotal) ?></td>
<td class="col-criada"><?= gestaoData($e['criado_em'] ?? null) ?></td>
<td class="col-acoes">
<div class="gestao-acoes-linha">
<div class="gestao-acoes-grupo">
<a class="gestao-botao gestao-botao--secundario gestao-botao--pequeno" id="btn-empresa-editar-<?= $idE ?>" href="/gestao/empresa-form.php?id=<?= $idE ?>" aria-label="Editar a empresa <?= h($e['nome']) ?>"><?= gestaoIcone('editar') ?><span>Editar</span></a>
<form class="gestao-form-linha" id="form-empresa-<?= $ativo ? 'inativar' : 'ativar' ?>-<?= $idE ?>" method="post" action="/gestao/empresa-acao.php">
<?= gestaoCsrfInput($ctx) ?>
<input type="hidden" name="acao" value="<?= $ativo ? 'inativar' : 'ativar' ?>">
<input type="hidden" name="id_empresa" value="<?= $idE ?>">
<?php if ($ativo): ?>
<button class="gestao-botao gestao-botao--secundario gestao-botao--pequeno" id="btn-empresa-inativar-<?= $idE ?>" type="submit" aria-label="Inativar a empresa <?= h($e['nome']) ?>"><?= gestaoIcone('inativo') ?><span>Inativar</span></button>
<?php else: ?>
<button class="gestao-botao gestao-botao--secundario gestao-botao--pequeno" id="btn-empresa-ativar-<?= $idE ?>" type="submit" aria-label="Ativar a empresa <?= h($e['nome']) ?>"><?= gestaoIcone('ok') ?><span>Ativar</span></button>
<?php endif; ?>
</form>
</div>
<div class="gestao-acoes-grupo gestao-acoes-grupo--destrutivo">
<form class="gestao-form-linha" id="form-empresa-excluir-<?= $idE ?>" method="post" action="/gestao/empresa-acao.php">
<?= gestaoCsrfInput($ctx) ?>
<input type="hidden" name="acao" value="excluir">
<input type="hidden" name="id_empresa" value="<?= $idE ?>">
<button class="gestao-botao gestao-botao--destrutivo gestao-botao--pequeno" id="btn-empresa-excluir-<?= $idE ?>" type="submit" aria-label="Excluir a empresa <?= h($e['nome']) ?><?= $nTotal > 0 ? ' (não é possível: há totens vinculados)' : '' ?>"><?= gestaoIcone('excluir') ?><span>Excluir</span></button>
</form>
</div>
</div>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
