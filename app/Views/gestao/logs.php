<?php
?>
<?php if ($erroCarga): ?>
<div class="gestao-estado gestao-estado--erro" id="logs-erro-carga" role="alert"><?= gestaoIcone('alerta') ?><span>Não foi possível carregar os logs agora. Tente novamente em instantes.</span></div>
<?php return; endif; ?>
<div class="gestao-nota-permanente" id="logs-aviso-retencao"><?= gestaoIcone('info') ?><p>Os logs são mantidos por 90 dias. Registros mais antigos são apagados automaticamente.</p></div>
<?php
$rotuloData = static function (mixed $valor): string {
    return preg_match('/\A(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/', (string) $valor, $m) === 1 ? $m[3] . '/' . $m[2] . '/' . $m[1] . ' ' . $m[4] . ':' . $m[5] : '';
};
$iconeNivel = ['erro' => 'erro', 'aviso' => 'alerta', 'info' => 'info'];
$plural = static fn (int $n, string $um, string $varios): string => $n . ' ' . ($n === 1 ? $um : $varios);
$colunas = $mostrarTotem ? 7 : 6;
?>
<nav class="gestao-abas" id="logs-abas" aria-label="Categorias de logs">
<ul class="gestao-abas__lista">
<?php foreach ($abas as $a): ?>
<li class="gestao-abas__item"><a class="gestao-aba<?= $a['ativa'] ? ' gestao-aba--ativa' : '' ?>" id="aba-<?= h($a['slug']) ?>" href="<?= h($a['href']) ?>"<?= $a['ativa'] ? ' aria-current="page"' : '' ?>><span class="gestao-aba__rotulo"><?= h($a['rotulo']) ?></span> <span class="gestao-aba__contagem" aria-hidden="true"><?= (int) $a['total'] ?></span><span class="gestao-sr"> (<?= h($plural((int) $a['total'], 'registro', 'registros')) ?>)</span></a></li>
<?php endforeach; ?>
</ul>
</nav>
<section class="gestao-painel-logs" id="logs-painel">
<p class="gestao-descricao" id="logs-descricao"><?= h($abaDescricao) ?></p>
<form class="gestao-filtros" id="logs-filtros" method="get" action="/gestao/logs.php" role="search" aria-label="Filtros dos logs">
<input type="hidden" name="aba" id="logs-aba-campo" value="<?= h($aba) ?>">
<div class="gestao-campo">
<label class="gestao-campo__rotulo" for="filtro-nivel">Nível</label>
<select class="gestao-campo__entrada" id="filtro-nivel" name="nivel">
<option value="">Todos os níveis</option>
<?php foreach ($niveis as $valor => $rotulo): ?>
<option value="<?= h($valor) ?>"<?= $filtros['nivel'] === $valor ? ' selected' : '' ?>><?= h($rotulo) ?></option>
<?php endforeach; ?>
</select>
</div>
<div class="gestao-campo<?= isset($errosFiltro['de']) ? ' gestao-campo--erro' : '' ?>">
<label class="gestao-campo__rotulo" for="filtro-periodo-de">De</label>
<input class="gestao-campo__entrada" id="filtro-periodo-de" name="de" type="date" value="<?= h($filtros['de']) ?>" min="<?= h($limiteIso) ?>" max="<?= h($hojeIso) ?>"<?= isset($errosFiltro['de']) ? ' aria-invalid="true" aria-describedby="erro-periodo-de"' : '' ?>>
<?php if (isset($errosFiltro['de'])): ?>
<span class="gestao-campo__erro" id="erro-periodo-de" role="alert"><?= gestaoIcone('alerta') ?><span class="gestao-sr">Erro: </span><?= h($errosFiltro['de']) ?></span>
<?php endif; ?>
</div>
<div class="gestao-campo<?= isset($errosFiltro['ate']) ? ' gestao-campo--erro' : '' ?>">
<label class="gestao-campo__rotulo" for="filtro-periodo-ate">Até</label>
<input class="gestao-campo__entrada" id="filtro-periodo-ate" name="ate" type="date" value="<?= h($filtros['ate']) ?>" min="<?= h($limiteIso) ?>" max="<?= h($hojeIso) ?>"<?= isset($errosFiltro['ate']) ? ' aria-invalid="true" aria-describedby="erro-periodo-ate"' : '' ?>>
<?php if (isset($errosFiltro['ate'])): ?>
<span class="gestao-campo__erro" id="erro-periodo-ate" role="alert"><?= gestaoIcone('alerta') ?><span class="gestao-sr">Erro: </span><?= h($errosFiltro['ate']) ?></span>
<?php endif; ?>
</div>
<div class="gestao-atalhos" id="logs-atalhos-periodo" role="group" aria-label="Atalhos de período">
<?php foreach ($atalhos as $at): ?>
<a class="gestao-botao gestao-botao--secundario gestao-botao--pequeno<?= $at['ativo'] ? ' gestao-botao--ativo' : '' ?>" id="<?= h($at['id']) ?>" href="<?= h($at['href']) ?>"<?= $at['ativo'] ? ' aria-current="true"' : '' ?>><span><?= h($at['rotulo']) ?></span></a>
<?php endforeach; ?>
</div>
<?php if ($mostrarTotem): ?>
<div class="gestao-campo">
<label class="gestao-campo__rotulo" for="filtro-totem">Totem</label>
<select class="gestao-campo__entrada" id="filtro-totem" name="totem">
<option value="">Todos os totens</option>
<option value="sem"<?= $filtros['totem'] === 'sem' ? ' selected' : '' ?>>Sem totem</option>
<?php foreach ($opcoesTotem as $o): ?>
<option value="<?= h($o['valor']) ?>"<?= $filtros['totem'] === $o['valor'] ? ' selected' : '' ?>><?= h($o['rotulo']) ?></option>
<?php endforeach; ?>
</select>
</div>
<?php endif; ?>
<div class="gestao-campo">
<label class="gestao-campo__rotulo" for="filtro-categoria">Categoria</label>
<select class="gestao-campo__entrada" id="filtro-categoria" name="categoria">
<option value="">Todas as categorias</option>
<?php foreach ($categoriasAba as $c): ?>
<option value="<?= h($c) ?>"<?= $filtros['categoria'] === $c ? ' selected' : '' ?>><?= h($c) ?></option>
<?php endforeach; ?>
</select>
</div>
<div class="gestao-barra-acoes">
<button class="gestao-botao gestao-botao--primario" id="btn-aplicar-filtros" type="submit"><span>Aplicar filtros</span></button>
<a class="gestao-botao gestao-botao--secundario" id="btn-limpar-filtros" href="<?= h($urlLimpar) ?>"><span>Limpar filtros</span></a>
</div>
</form>
<p class="gestao-contador" id="logs-contador" role="status"><?php if ($total === 0): ?>Nenhum registro.<?php else: ?>Exibindo <?= (int) $primeiro ?> a <?= (int) $ultimo ?> de <?= (int) $total ?> <?= $total === 1 ? 'registro' : 'registros' ?>.<?php endif; ?></p>
<?php if ($total === 0): ?>
<div class="gestao-estado gestao-estado--vazio" id="logs-vazio"><?= gestaoIcone('logs') ?><span><?= $temFiltros ? 'Nenhum registro encontrado com estes filtros.' : 'Nenhum registro nesta aba nos últimos 90 dias.' ?></span><?php if ($temFiltros): ?> <a class="gestao-link" id="logs-vazio-limpar" href="<?= h($urlLimpar) ?>">Limpar filtros</a><?php endif; ?></div>
<?php else: ?>
<div class="gestao-tabela-wrap" role="region" aria-label="Tabela de logs" tabindex="0">
<table class="gestao-tabela gestao-tabela--logs" id="logs-tabela">
<caption class="gestao-tabela__legenda">Registros de log, do mais recente para o mais antigo</caption>
<thead>
<tr><th scope="col" class="col-nivel">Nível</th><th scope="col" class="col-quando">Quando</th><?php if ($mostrarTotem): ?><th scope="col" class="col-totem">Totem</th><?php endif; ?><th scope="col" class="col-categoria">Categoria</th><th scope="col" class="col-mensagem">Mensagem</th><th scope="col" class="col-repeticoes">Repetições</th><th scope="col" class="col-detalhe">Detalhe</th></tr>
</thead>
<tbody>
<?php foreach ($itens as $i): ?>
<?php $n = (int) $i['contador']; $idLog = (int) $i['id_log']; $slug = (string) $i['nivel_slug']; ?>
<tr class="gestao-tabela__linha" data-id-log="<?= $idLog ?>" data-nivel="<?= h($slug) ?>">
<td class="col-nivel"><span class="gestao-nivel gestao-nivel--<?= h($slug) ?>"><?= gestaoIcone($iconeNivel[$slug] ?? 'info') ?><span><?= h($i['nivel_rotulo']) ?></span></span></td>
<td class="col-quando"><?= gestaoData($i['ultima_ocorrencia'] ?? null) ?></td>
<?php if ($mostrarTotem): ?>
<td class="col-totem"><?= h($i['totem_rotulo']) ?></td>
<?php endif; ?>
<td class="col-categoria"><?= str_replace('_', '_<wbr>', h($i['categoria'])) ?></td>
<td class="col-mensagem"><span class="gestao-mensagem-texto"><?= h($i['mensagem']) ?></span></td>
<td class="col-repeticoes"><span aria-hidden="true">x<?= $n ?></span><span class="gestao-sr"><?= h($plural($n, 'ocorrência', 'ocorrências')) ?></span></td>
<td class="col-detalhe"><a class="gestao-botao gestao-botao--secundario gestao-botao--pequeno" href="/gestao/log.php?id=<?= $idLog ?>&amp;<?= h($queryVolta) ?>" aria-label="Ver detalhe do registro <?= h($i['categoria']) ?>, <?= h($i['nivel_rotulo']) ?>, última ocorrência em <?= h($rotuloData($i['ultima_ocorrencia'] ?? null)) ?>"><span>Ver detalhe</span></a></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<nav class="gestao-paginacao" id="logs-paginacao" aria-label="Paginação">
<?php if ($urlAnterior !== null): ?>
<a class="gestao-botao gestao-botao--secundario" id="pag-anterior" href="<?= h($urlAnterior) ?>" rel="prev"><span>Anterior</span></a>
<?php else: ?>
<span class="gestao-botao gestao-botao--secundario gestao-botao--desabilitado" id="pag-anterior" aria-disabled="true"><span>Anterior</span></span>
<?php endif; ?>
<span class="gestao-paginacao__posicao" id="pag-posicao">Página <?= (int) $pagina ?> de <?= (int) $paginas ?></span>
<?php if ($urlProxima !== null): ?>
<a class="gestao-botao gestao-botao--secundario" id="pag-proxima" href="<?= h($urlProxima) ?>" rel="next"><span>Próxima</span></a>
<?php else: ?>
<span class="gestao-botao gestao-botao--secundario gestao-botao--desabilitado" id="pag-proxima" aria-disabled="true"><span>Próxima</span></span>
<?php endif; ?>
</nav>
<?php endif; ?>
</section>
