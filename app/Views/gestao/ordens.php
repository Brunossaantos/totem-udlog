<?php
?>
<?php if (!isset($aba) || $aba !== 'baixas'): ?>
<div class="gestao-nota-permanente" id="ordens-aviso-retencao"><?= gestaoIcone('info') ?><p>Depois que uma ordem é inativada, o PDF dela é apagado em 15 dias.</p></div>
<?php endif; ?>
<?php if ($erroCarga): ?>
<div class="gestao-estado gestao-estado--erro" id="ordens-erro-carga" role="alert"><?= gestaoIcone('alerta') ?><span>Não foi possível consultar as ordens de coleta agora. Tente novamente em instantes.</span></div>
<?php return; endif; ?>
<?php
$ehBaixas = $aba === 'baixas';
$rotuloData = static function (mixed $valor): string {
    return preg_match('/\A(\d{4})-(\d{2})-(\d{2})/', (string) $valor, $m) === 1 ? $m[3] . '/' . $m[2] . '/' . $m[1] : '';
};
?>
<nav class="gestao-abas" id="ordens-abas" aria-label="Categorias de ordens">
<ul class="gestao-abas__lista">
<?php foreach ($abas as $a): ?>
<li class="gestao-abas__item"><a class="gestao-aba<?= $a['ativa'] ? ' gestao-aba--ativa' : '' ?>" id="<?= h($a['id']) ?>" href="<?= h($a['href']) ?>"<?= $a['ativa'] ? ' aria-current="page"' : '' ?>><span class="gestao-aba__rotulo"><?= h($a['rotulo']) ?></span> <span class="gestao-aba__contagem" aria-hidden="true"><?= h($a['contagem']) ?></span><span class="gestao-sr"> (<?= h($a['contagem_sr']) ?>)</span></a></li>
<?php endforeach; ?>
</ul>
</nav>
<section class="gestao-painel-ordens" id="ordens-painel">
<p class="gestao-descricao" id="ordens-descricao"><?= h($abaDescricao) ?></p>
<form class="gestao-filtros" id="ordens-filtros" method="get" action="/gestao/ordens.php" role="search" aria-label="Filtros das ordens de coleta">
<input type="hidden" name="aba" id="ordens-aba-campo" value="<?= h($aba) ?>">
<?php if ($ehBaixas): ?>
<div class="gestao-campo">
<label class="gestao-campo__rotulo" for="filtro-baixas-mostrar">Mostrar</label>
<select class="gestao-campo__entrada" id="filtro-baixas-mostrar" name="mostrar">
<?php foreach ($opcoesMostrar as $valor => $rotulo): ?>
<option value="<?= h($valor) ?>"<?= $filtros['mostrar'] === $valor ? ' selected' : '' ?>><?= h($rotulo) ?></option>
<?php endforeach; ?>
</select>
</div>
<?php else: ?>
<div class="gestao-campo<?= isset($errosFiltro['cliente']) ? ' gestao-campo--erro' : '' ?>">
<label class="gestao-campo__rotulo" for="filtro-cliente">Cliente</label>
<select class="gestao-campo__entrada" id="filtro-cliente" name="cliente"<?= isset($errosFiltro['cliente']) ? ' aria-invalid="true" aria-describedby="erro-cliente"' : '' ?>>
<option value="">Todos os clientes</option>
<?php foreach ($opcoesCliente as $o): ?>
<option value="<?= h($o['valor']) ?>"<?= $filtros['cliente'] === $o['valor'] ? ' selected' : '' ?>><?= h($o['rotulo']) ?></option>
<?php endforeach; ?>
</select>
<?php if (isset($errosFiltro['cliente'])): ?>
<span class="gestao-campo__erro" id="erro-cliente" role="alert"><?= gestaoIcone('alerta') ?><span class="gestao-sr">Erro: </span><?= h($errosFiltro['cliente']) ?></span>
<?php endif; ?>
</div>
<div class="gestao-campo<?= isset($errosFiltro['numero']) ? ' gestao-campo--erro' : '' ?>">
<label class="gestao-campo__rotulo" for="filtro-numero">Número da ordem</label>
<input class="gestao-campo__entrada" id="filtro-numero" name="numero" type="text" maxlength="50" autocomplete="off" value="<?= h($filtros['numero']) ?>" aria-describedby="filtro-numero-ajuda<?= isset($errosFiltro['numero']) ? ' erro-numero' : '' ?>"<?= isset($errosFiltro['numero']) ? ' aria-invalid="true"' : '' ?>>
<span class="gestao-campo__ajuda" id="filtro-numero-ajuda">Número exato. Com 3 ou mais caracteres, busca pelo início do número.</span>
<?php if (isset($errosFiltro['numero'])): ?>
<span class="gestao-campo__erro" id="erro-numero" role="alert"><?= gestaoIcone('alerta') ?><span class="gestao-sr">Erro: </span><?= h($errosFiltro['numero']) ?></span>
<?php endif; ?>
</div>
<?php endif; ?>
<div class="gestao-campo<?= isset($errosFiltro['de']) ? ' gestao-campo--erro' : '' ?>">
<label class="gestao-campo__rotulo" for="filtro-criada-de"><?= $ehBaixas ? 'Baixa criada de' : 'Criada de' ?></label>
<input class="gestao-campo__entrada" id="filtro-criada-de" name="de" type="date" value="<?= h($filtros['de']) ?>"<?= isset($errosFiltro['de']) ? ' aria-invalid="true" aria-describedby="erro-criada-de"' : '' ?>>
<?php if (isset($errosFiltro['de'])): ?>
<span class="gestao-campo__erro" id="erro-criada-de" role="alert"><?= gestaoIcone('alerta') ?><span class="gestao-sr">Erro: </span><?= h($errosFiltro['de']) ?></span>
<?php endif; ?>
</div>
<div class="gestao-campo<?= isset($errosFiltro['ate']) ? ' gestao-campo--erro' : '' ?>">
<label class="gestao-campo__rotulo" for="filtro-criada-ate"><?= $ehBaixas ? 'Baixa criada até' : 'Criada até' ?></label>
<input class="gestao-campo__entrada" id="filtro-criada-ate" name="ate" type="date" value="<?= h($filtros['ate']) ?>"<?= isset($errosFiltro['ate']) ? ' aria-invalid="true" aria-describedby="erro-criada-ate"' : '' ?>>
<?php if (isset($errosFiltro['ate'])): ?>
<span class="gestao-campo__erro" id="erro-criada-ate" role="alert"><?= gestaoIcone('alerta') ?><span class="gestao-sr">Erro: </span><?= h($errosFiltro['ate']) ?></span>
<?php endif; ?>
</div>
<?php if ($aba === 'inativas'): ?>
<div class="gestao-campo<?= isset($errosFiltro['inativada_de']) ? ' gestao-campo--erro' : '' ?>">
<label class="gestao-campo__rotulo" for="filtro-inativada-de">Inativada de</label>
<input class="gestao-campo__entrada" id="filtro-inativada-de" name="inativada_de" type="date" value="<?= h($filtros['inativada_de']) ?>"<?= isset($errosFiltro['inativada_de']) ? ' aria-invalid="true" aria-describedby="erro-inativada-de"' : '' ?>>
<?php if (isset($errosFiltro['inativada_de'])): ?>
<span class="gestao-campo__erro" id="erro-inativada-de" role="alert"><?= gestaoIcone('alerta') ?><span class="gestao-sr">Erro: </span><?= h($errosFiltro['inativada_de']) ?></span>
<?php endif; ?>
</div>
<div class="gestao-campo<?= isset($errosFiltro['inativada_ate']) ? ' gestao-campo--erro' : '' ?>">
<label class="gestao-campo__rotulo" for="filtro-inativada-ate">Inativada até</label>
<input class="gestao-campo__entrada" id="filtro-inativada-ate" name="inativada_ate" type="date" value="<?= h($filtros['inativada_ate']) ?>"<?= isset($errosFiltro['inativada_ate']) ? ' aria-invalid="true" aria-describedby="erro-inativada-ate"' : '' ?>>
<?php if (isset($errosFiltro['inativada_ate'])): ?>
<span class="gestao-campo__erro" id="erro-inativada-ate" role="alert"><?= gestaoIcone('alerta') ?><span class="gestao-sr">Erro: </span><?= h($errosFiltro['inativada_ate']) ?></span>
<?php endif; ?>
</div>
<?php endif; ?>
<div class="gestao-barra-acoes">
<button class="gestao-botao gestao-botao--primario" id="btn-aplicar-filtros" type="submit"><span>Aplicar filtros</span></button>
<a class="gestao-botao gestao-botao--secundario" id="btn-limpar-filtros" href="<?= h($urlLimpar) ?>"><span>Limpar filtros</span></a>
</div>
</form>
<p class="gestao-contador" id="ordens-contador" role="status"><?= h($contador) ?></p>
<?php if ($total === 0): ?>
<div class="gestao-estado gestao-estado--vazio" id="<?= $ehBaixas ? 'baixas-vazio' : 'ordens-vazio' ?>"><?= gestaoIcone('ordens') ?><span><?= $temFiltros ? ($ehBaixas ? 'Nenhuma baixa encontrada com estes filtros.' : 'Nenhuma ordem encontrada com estes filtros.') : ($ehBaixas ? 'Nenhuma baixa pendente.' : ($aba === 'ativas_15d' ? 'Nenhuma ordem ativa há mais de 15 dias.' : 'Nenhuma ordem nesta aba.')) ?></span><?php if ($temFiltros): ?> <a class="gestao-link" id="ordens-vazio-limpar" href="<?= h($urlLimpar) ?>">Limpar filtros</a><?php endif; ?></div>
<?php elseif ($ehBaixas): ?>
<div class="gestao-tabela-wrap" role="region" aria-label="Tabela de baixas pendentes" tabindex="0">
<table class="gestao-tabela gestao-tabela--baixas" id="baixas-tabela">
<caption class="gestao-tabela__legenda">Baixas de ordens de coleta, da mais recente para a mais antiga</caption>
<thead>
<tr><th scope="col" class="col-atendimento">Atendimento</th><th scope="col" class="col-numero-oc">Número da ordem</th><th scope="col" class="col-baixa-criada">Criada em</th><th scope="col" class="col-baixa-situacao">Situação da baixa</th><th scope="col" class="col-acoes">Ações</th></tr>
</thead>
<tbody>
<?php foreach ($linhasBaixa as $b): ?>
<?php $idBaixa = (int) $b['id']; ?>
<tr class="gestao-tabela__linha" data-id-baixa="<?= $idBaixa ?>" data-resolvida="<?= $b['resolvida'] ? '1' : '0' ?>">
<td class="col-atendimento">#<?= (int) $b['id_atendimento'] ?></td>
<td class="col-numero-oc"><?= h($b['numero_ordem_coleta']) ?></td>
<td class="col-baixa-criada"><?= gestaoData($b['criado_em'] ?? null) ?></td>
<td class="col-baixa-situacao"><?php if ($b['resolvida']): ?><span class="gestao-situacao gestao-situacao--resolvida"><?= gestaoIcone('ok') ?><span>Resolvida em <?= gestaoData($b['resolvido_em'] ?? null) ?></span></span><?php else: ?><span class="gestao-situacao gestao-situacao--pendente"><?= gestaoIcone('alerta') ?><span>Pendente</span></span><?php endif; ?></td>
<td class="col-acoes">
<div class="gestao-acoes-linha">
<?php if ($b['href_abrir'] !== null): ?>
<a class="gestao-botao gestao-botao--secundario gestao-botao--pequeno" id="btn-baixa-abrir-<?= $idBaixa ?>" href="<?= h($b['href_abrir']) ?>" aria-label="Abrir a ordem <?= h($b['numero_ordem_coleta']) ?> do atendimento <?= (int) $b['id_atendimento'] ?>"><span>Abrir ordem</span></a>
<?php else: ?>
<span class="gestao-celula-sec gestao-oc-estado" id="baixa-oc-estado-<?= $idBaixa ?>"><?= gestaoIcone('alerta') ?><span><?= $b['oc_estado'] === 'ambigua' ? 'Mais de uma ordem com este número' : 'Ordem não localizada' ?></span></span>
<?php endif; ?>
<?php if (!$b['resolvida'] && $b['oc_estado'] === 'localizada'): ?>
<form class="gestao-form-linha" id="form-baixa-resolver-<?= $idBaixa ?>" method="post" action="/gestao/ordem-baixa.php">
<?= gestaoCsrfInput($ctx) ?>
<input type="hidden" name="id_baixa" value="<?= $idBaixa ?>">
<input type="hidden" name="retorno" value="<?= h($retorno) ?>">
<button class="gestao-botao gestao-botao--secundario gestao-botao--pequeno" id="btn-baixa-resolver-<?= $idBaixa ?>" type="submit" aria-label="Marcar como resolvida a baixa do atendimento <?= (int) $b['id_atendimento'] ?>"><span>Marcar como resolvida</span></button>
</form>
<?php endif; ?>
</div>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php else: ?>
<div class="gestao-tabela-wrap" role="region" aria-label="Tabela de ordens de coleta" tabindex="0">
<table class="gestao-tabela gestao-tabela--ordens" id="ordens-tabela">
<caption class="gestao-tabela__legenda">Ordens de coleta</caption>
<thead>
<tr>
<?php foreach (['numero' => 'col-numero', 'cliente' => 'col-cliente'] as $chave => $classe): ?>
<th scope="col" class="<?= $classe ?>" aria-sort="<?= h($cabecalhos[$chave]['aria']) ?>"><a class="gestao-ordenar" href="<?= h($cabecalhos[$chave]['href']) ?>"><?= h($cabecalhos[$chave]['rotulo']) ?></a></th>
<?php endforeach; ?>
<th scope="col" class="col-transportadora">Transportadora</th>
<th scope="col" class="col-situacao">Situação</th>
<th scope="col" class="col-criada" aria-sort="<?= h($cabecalhos['criado_em']['aria']) ?>"><a class="gestao-ordenar" href="<?= h($cabecalhos['criado_em']['href']) ?>"><?= h($cabecalhos['criado_em']['rotulo']) ?></a></th>
<th scope="col" class="col-pdf">PDF</th>
<th scope="col" class="col-acoes">Ações</th>
</tr>
</thead>
<tbody>
<?php foreach ($itens as $o): ?>
<?php $idOrdem = (int) $o['id']; ?>
<tr class="gestao-tabela__linha" data-id-ordem="<?= $idOrdem ?>" data-status="<?= h($o['situacao_slug']) ?>" data-idade="<?= $o['idade_atencao'] ? 'atencao' : 'normal' ?>">
<td class="col-numero"><?= h($o['numero']) ?><?php if ((int) $o['mesmo_numero_outros_clientes'] > 0): ?> <span class="gestao-sinal gestao-sinal--outros-clientes" data-outros-clientes="<?= (int) $o['mesmo_numero_outros_clientes'] ?>"><?= gestaoIcone('alerta') ?><span aria-hidden="true">Repetido em outro cliente</span><span class="gestao-sr">Mesmo número em outros clientes</span></span><?php endif; ?></td>
<td class="col-cliente"><span class="gestao-celula-principal"><?= h($o['razao_social']) ?></span> <span class="gestao-celula-sec"><?= h($o['cnpj_cliente_fmt']) ?></span><?php if ($o['cliente_inativo']): ?> <span class="gestao-sinal gestao-sinal--cliente-inativo" data-cliente-inativo="1"><?= gestaoIcone('alerta') ?><span aria-hidden="true">Cliente inativo</span><span class="gestao-sr">Cliente inativo: o totem não mostra esta ordem</span></span><?php endif; ?></td>
<td class="col-transportadora"><?php if (($o['transportadora_nome'] ?? '') !== '' || ($o['transportadora_cnpj'] ?? '') !== ''): ?><span class="gestao-celula-principal"><?= h($o['transportadora_nome'] ?? '') ?></span> <span class="gestao-celula-sec"><?= h($o['cnpj_transportadora_fmt']) ?></span><?php else: ?><span class="gestao-celula-sec">Não informada</span><?php endif; ?></td>
<td class="col-situacao"><span class="gestao-situacao gestao-situacao--<?= h($o['situacao_slug']) ?>"><?= gestaoIcone($o['situacao_slug'] === 'ativa' ? 'ok' : 'inativo') ?><span><?= h($o['situacao_rotulo']) ?></span></span><?php if ($o['situacao_slug'] === 'inativa' && ($o['inativada_em'] ?? null) !== null): ?> <span class="gestao-celula-sec gestao-situacao__inativada">Inativada em <?= gestaoData($o['inativada_em']) ?></span><?php endif; ?><?php if ($o['pdf_apagado_em'] !== null): ?> <span class="gestao-celula-sec gestao-situacao__pdf-apagado">PDF será apagado em <?= h($o['pdf_apagado_em']) ?></span><?php endif; ?></td>
<td class="col-criada"><span class="gestao-celula-principal gestao-idade<?= $o['idade_atencao'] ? ' gestao-idade--atencao' : '' ?>"><?= $o['idade_atencao'] ? gestaoIcone('alerta') . '<span class="gestao-sr">Atenção: ativa há mais de 15 dias. </span>' : '' ?><span><?= h($o['idade_texto']) ?></span></span> <span class="gestao-celula-sec"><?= gestaoData($o['criado_em'] ?? null) ?></span></td>
<td class="col-pdf"><?= $o['tem_pdf'] ? 'Disponível' : 'Não disponível' ?></td>
<td class="col-acoes">
<div class="gestao-acoes-linha">
<a class="gestao-botao gestao-botao--secundario gestao-botao--pequeno" id="btn-ordem-abrir-<?= $idOrdem ?>" href="<?= h($o['href_abrir']) ?>" aria-label="Abrir a ordem <?= h($o['numero']) ?> do cliente <?= h($o['razao_social']) ?>"><span>Abrir</span></a>
<?php if ($o['situacao_slug'] === 'ativa'): ?>
<div class="gestao-acoes-grupo gestao-acoes-grupo--destrutivo">
<form class="gestao-form-linha" id="form-ordem-inativar-<?= $idOrdem ?>" method="post" action="/gestao/ordem-status.php">
<?= gestaoCsrfInput($ctx) ?>
<input type="hidden" name="acao" value="inativar">
<input type="hidden" name="id_ordem" value="<?= $idOrdem ?>">
<input type="hidden" name="status_visto" value="ATIVA">
<input type="hidden" name="retorno" value="<?= h($retorno) ?>">
<button class="gestao-botao gestao-botao--destrutivo gestao-botao--pequeno" id="btn-ordem-inativar-<?= $idOrdem ?>" type="submit" aria-label="Inativar a ordem <?= h($o['numero']) ?> do cliente <?= h($o['razao_social']) ?>"><?= gestaoIcone('inativo') ?><span>Inativar</span></button>
</form>
</div>
<?php else: ?>
<form class="gestao-form-linha" id="form-ordem-ativar-<?= $idOrdem ?>" method="post" action="/gestao/ordem-status.php">
<?= gestaoCsrfInput($ctx) ?>
<input type="hidden" name="acao" value="ativar">
<input type="hidden" name="id_ordem" value="<?= $idOrdem ?>">
<input type="hidden" name="status_visto" value="INATIVA">
<input type="hidden" name="retorno" value="<?= h($retorno) ?>">
<button class="gestao-botao gestao-botao--secundario gestao-botao--pequeno" id="btn-ordem-ativar-<?= $idOrdem ?>" type="submit" aria-label="Ativar a ordem <?= h($o['numero']) ?> do cliente <?= h($o['razao_social']) ?>"><span>Ativar</span></button>
</form>
<?php endif; ?>
</div>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
<?php if ($total > 0): ?>
<nav class="gestao-paginacao" id="ordens-paginacao" aria-label="Paginação">
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
