<?php
$situacoes = ['todos' => 'Todos', 'ativos' => 'Ativos', 'inativos' => 'Inativos'];
?>
<div class="gestao-barra-acoes" id="clientes-acoes">
<a class="gestao-botao gestao-botao--primario" id="btn-novo-cliente" href="/gestao/cliente-form.php"><?= gestaoIcone('mais') ?><span>Novo cliente</span></a>
</div>
<div class="gestao-nota-permanente" id="clientes-aviso"><?= gestaoIcone('info') ?><p>Estes clientes alimentam o OCR das notas e o autocomplete do totem, e as mudanças valem na hora. O CNPJ não pode ser alterado depois de criado; para corrigir, exclua o cliente e cadastre de novo.</p></div>
<?php if ($confirmacao !== null): ?>
<section class="gestao-estado gestao-estado--aviso" id="cliente-confirmacao" role="alert" data-acao="<?= h($confirmacao['acao']) ?>" data-id-cliente="<?= (int) $confirmacao['id_cliente'] ?>">
<?= gestaoIcone('alerta') ?>
<div class="gestao-estado__corpo">
<p><strong><?= h($confirmacao['nome']) ?></strong></p>
<p id="cliente-confirmacao-texto"><?= h($confirmacao['texto']) ?></p>
<?php if (!empty($confirmacao['nomes'])): ?>
<p id="cliente-confirmacao-afetados">Clientes afetados (até 5): <?= implode(', ', array_map(static fn ($n): string => h($n), $confirmacao['nomes'])) ?></p>
<?php endif; ?>
<form class="gestao-form-acao" id="form-cliente-confirmar" method="post" action="/gestao/cliente-acao.php">
<?= gestaoCsrfInput($ctx) ?>
<input type="hidden" name="acao" value="<?= h($confirmacao['acao']) ?>">
<input type="hidden" name="id_cliente" value="<?= (int) $confirmacao['id_cliente'] ?>">
<input type="hidden" name="confirmar" value="1">
<a class="gestao-botao gestao-botao--secundario" id="cliente-confirmacao-cancelar" href="<?= h($url_lista) ?>"><span>Cancelar</span></a>
<button class="gestao-botao <?= $confirmacao['acao'] === 'ativar' ? 'gestao-botao--primario' : 'gestao-botao--destrutivo-cheio' ?>" id="btn-cliente-confirmar" type="submit" aria-label="<?= h($confirmacao['rotulo']) ?>: <?= h($confirmacao['nome']) ?>"><?= gestaoIcone($confirmacao['acao'] === 'excluir' ? 'excluir' : ($confirmacao['acao'] === 'ativar' ? 'ok' : 'inativo')) ?><span><?= h($confirmacao['rotulo']) ?></span></button>
</form>
</div>
</section>
<?php endif; ?>
<form class="gestao-filtros" id="clientes-filtros" method="get" action="/gestao/clientes.php" role="search" aria-label="Filtros dos clientes">
<div class="gestao-campo">
<label class="gestao-campo__rotulo" for="filtro-situacao">Situação</label>
<select class="gestao-campo__entrada" id="filtro-situacao" name="situacao">
<?php foreach ($situacoes as $valor => $rotulo): ?>
<option value="<?= h($valor) ?>"<?= $filtros['situacao'] === $valor ? ' selected' : '' ?>><?= h($rotulo) ?></option>
<?php endforeach; ?>
</select>
</div>
<div class="gestao-campo<?= $busca_curta ? ' gestao-campo--erro' : '' ?>">
<label class="gestao-campo__rotulo" for="filtro-busca">Buscar por nome ou CNPJ</label>
<input class="gestao-campo__entrada" id="filtro-busca" name="q" type="search" maxlength="60" autocomplete="off" value="<?= h($filtros['q']) ?>" aria-describedby="filtro-busca-ajuda<?= $busca_curta ? ' erro-filtro-busca' : '' ?>"<?= $busca_curta ? ' aria-invalid="true"' : '' ?>>
<span class="gestao-campo__ajuda" id="filtro-busca-ajuda">Com 3 ou mais caracteres: começo do nome ou parte do CNPJ (só números).</span>
<?php if ($busca_curta): ?>
<span class="gestao-campo__erro" id="erro-filtro-busca" role="alert"><?= gestaoIcone('alerta') ?><span class="gestao-sr">Erro: </span>Digite pelo menos 3 caracteres para buscar. A busca foi ignorada.</span>
<?php endif; ?>
</div>
<div class="gestao-barra-acoes">
<button class="gestao-botao gestao-botao--primario" id="btn-aplicar-filtros" type="submit"><span>Aplicar filtros</span></button>
<a class="gestao-botao gestao-botao--secundario" id="btn-limpar-filtros" href="/gestao/clientes.php"><span>Limpar filtros</span></a>
</div>
</form>
<p class="gestao-contador" id="clientes-contador" role="status"><?= h($contador) ?></p>
<?php if ($total === 0): ?>
<div class="gestao-estado gestao-estado--vazio" id="clientes-vazio"><?= gestaoIcone('clientes') ?><span><?= $tem_filtros ? 'Nenhum cliente encontrado com estes filtros.' : 'Nenhum cliente cadastrado. Cadastre o primeiro.' ?></span></div>
<?php else: ?>
<div class="gestao-tabela-wrap" role="region" aria-label="Tabela de clientes" tabindex="0">
<table class="gestao-tabela gestao-tabela--clientes" id="tabela-clientes">
<caption class="gestao-tabela__legenda">Clientes cadastrados</caption>
<colgroup><col class="gestao-col-nome"><col class="gestao-col-cnpj"><col class="gestao-col-situacao"><col class="gestao-col-criado"><col class="gestao-col-acoes"></colgroup>
<thead>
<tr><th scope="col">Nome</th><th scope="col">CNPJ</th><th scope="col">Situação</th><th scope="col">Criado em</th><th scope="col">Ações</th></tr>
</thead>
<tbody>
<?php foreach ($clientes as $c): ?>
<?php $ativo = (int) $c['ativo'] === 1; $idC = (int) $c['id_cliente']; ?>
<tr class="gestao-tabela__linha<?= $ativo ? '' : ' gestao-tabela__linha--inativa' ?>" data-id-cliente="<?= $idC ?>" data-ativo="<?= $ativo ? 1 : 0 ?>">
<td class="col-nome"><?= h($c['nome']) ?></td>
<td class="col-cnpj"><?= gestaoCnpj($c['cnpj']) ?></td>
<td class="col-situacao"><span class="gestao-situacao gestao-situacao--<?= $ativo ? 'ativo' : 'inativo' ?>"><?= gestaoIcone($ativo ? 'ok' : 'inativo') ?><span><?= h($ativo ? 'Ativo' : 'Inativo') ?></span></span></td>
<td class="col-criado"><?= gestaoData($c['criado_em'] ?? null) ?></td>
<td class="col-acoes">
<div class="gestao-acoes-linha">
<div class="gestao-acoes-grupo">
<a class="gestao-botao gestao-botao--secundario gestao-botao--pequeno" id="btn-cliente-editar-<?= $idC ?>" href="/gestao/cliente-form.php?id=<?= $idC ?>" aria-label="Editar o cliente <?= h($c['nome']) ?>"><?= gestaoIcone('editar') ?><span>Editar</span></a>
<form class="gestao-form-linha" id="form-cliente-<?= $ativo ? 'inativar' : 'ativar' ?>-<?= $idC ?>" method="post" action="/gestao/cliente-acao.php">
<?= gestaoCsrfInput($ctx) ?>
<input type="hidden" name="acao" value="<?= $ativo ? 'inativar' : 'ativar' ?>">
<input type="hidden" name="id_cliente" value="<?= $idC ?>">
<?php if ($ativo): ?>
<button class="gestao-botao gestao-botao--secundario gestao-botao--pequeno" id="btn-cliente-inativar-<?= $idC ?>" type="submit" aria-label="Inativar o cliente <?= h($c['nome']) ?>"><?= gestaoIcone('inativo') ?><span>Inativar</span></button>
<?php else: ?>
<button class="gestao-botao gestao-botao--secundario gestao-botao--pequeno" id="btn-cliente-ativar-<?= $idC ?>" type="submit" aria-label="Ativar o cliente <?= h($c['nome']) ?>"><?= gestaoIcone('ok') ?><span>Ativar</span></button>
<?php endif; ?>
</form>
</div>
<div class="gestao-acoes-grupo gestao-acoes-grupo--destrutivo">
<form class="gestao-form-linha" id="form-cliente-excluir-<?= $idC ?>" method="post" action="/gestao/cliente-acao.php">
<?= gestaoCsrfInput($ctx) ?>
<input type="hidden" name="acao" value="excluir">
<input type="hidden" name="id_cliente" value="<?= $idC ?>">
<button class="gestao-botao gestao-botao--destrutivo gestao-botao--pequeno" id="btn-cliente-excluir-<?= $idC ?>" type="submit" aria-label="Excluir o cliente <?= h($c['nome']) ?>"><?= gestaoIcone('excluir') ?><span>Excluir</span></button>
</form>
</div>
</div>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<nav class="gestao-paginacao" id="clientes-paginacao" aria-label="Paginação">
<?php if ($url_anterior !== null): ?>
<a class="gestao-botao gestao-botao--secundario" id="pag-anterior" href="<?= h($url_anterior) ?>" rel="prev"><span>Anterior</span></a>
<?php else: ?>
<span class="gestao-botao gestao-botao--secundario gestao-botao--desabilitado" id="pag-anterior" aria-disabled="true"><span>Anterior</span></span>
<?php endif; ?>
<span class="gestao-paginacao__posicao" id="pag-posicao">Página <?= (int) $pagina ?> de <?= (int) $paginas ?></span>
<?php if ($url_proxima !== null): ?>
<a class="gestao-botao gestao-botao--secundario" id="pag-proxima" href="<?= h($url_proxima) ?>" rel="next"><span>Próxima</span></a>
<?php else: ?>
<span class="gestao-botao gestao-botao--secundario gestao-botao--desabilitado" id="pag-proxima" aria-disabled="true"><span>Próxima</span></span>
<?php endif; ?>
</nav>
<?php endif; ?>
