<?php
/**
 * Detalhe de uma ordem de coleta (perfis usuario e admin). Variáveis (GestaoOrdemController::detalhe):
 * $erroCarga (bool; true = só $urlVoltar e o aviso de erro), $urlVoltar (lista com os filtros já
 * validados), $ordem (id, numero, razao_social, cnpj, transportadora_nome, transportadora_cnpj,
 * placa_prevista, motorista_nome_previsto, cnh_prevista, status, criado_em, inativada_em, tem_pdf,
 * mesmo_numero_outros_clientes), $situacaoSlug (ativa|inativa), $situacaoRotulo, $idadeTexto,
 * $idadeAtencao (bool), $cnpjCliente, $cnpjTransportadora (já formatados), $confirmacao (null ou
 * acao, n, texto: segundo passo de inativar/ativar), $pdf (disponivel|ausente|apagado), $pdfTexto,
 * $pdfAviso, $retorno (query validada que volta nos formulários), $diasRetencaoPdf,
 * $clienteInativo (bool; tb_clientes.status = INATIVO) e $avisoClienteInativo (texto fixo).
 * Sem máscara (decisão do usuário): nome, CNH e placa em claro. Sem script nem style inline.
 */
?>
<?php if ($erroCarga): ?>
<div class="gestao-estado gestao-estado--erro" id="ordens-erro-carga" role="alert"><?= gestaoIcone('alerta') ?><span>Não foi possível consultar as ordens de coleta agora. Tente novamente em instantes.</span></div>
<p><a class="gestao-botao gestao-botao--secundario" id="ordem-voltar" href="<?= h($urlVoltar) ?>"><span>Voltar para a lista</span></a></p>
<?php return; endif; ?>
<?php
$idOrdem = (int) $ordem['id'];
$vazio = static fn (mixed $v): string => ((string) $v) === '' ? 'Não informado' : (string) $v;
$ativa = $situacaoSlug === 'ativa';
?>
<div class="gestao-barra-acoes">
<a class="gestao-botao gestao-botao--secundario" id="ordem-voltar" href="<?= h($urlVoltar) ?>"><span>Voltar para a lista</span></a>
</div>
<div class="gestao-ordem" id="ordem-detalhe" data-id-ordem="<?= $idOrdem ?>" data-status="<?= h($situacaoSlug) ?>">
<section class="gestao-cartao" id="ordem-cartao-ordem" aria-labelledby="ordem-titulo-ordem">
<h2 class="gestao-cartao__titulo" id="ordem-titulo-ordem">Ordem <span id="ordem-numero"><?= h($ordem['numero']) ?></span></h2>
<dl class="gestao-dados" id="ordem-dados">
<dt>Situação</dt><dd id="ordem-situacao" data-situacao="<?= h($situacaoSlug) ?>"><span class="gestao-situacao gestao-situacao--<?= h($situacaoSlug) ?>"><?= gestaoIcone($ativa ? 'ok' : 'inativo') ?><span><?= h($situacaoRotulo) ?></span></span></dd>
<dt>Criada em</dt><dd id="ordem-criada"><?= gestaoData($ordem['criado_em'] ?? null, 'Não informado') ?></dd>
<dt>Inativada em</dt><dd id="ordem-inativada"><?= gestaoData($ordem['inativada_em'] ?? null, $ativa ? 'Ainda ativa' : 'Data não registrada') ?></dd>
<dt>Idade</dt><dd id="ordem-idade" data-idade="<?= $idadeAtencao ? 'atencao' : 'normal' ?>"><?php if ($idadeAtencao): ?><span class="gestao-idade gestao-idade--atencao"><?= gestaoIcone('alerta') ?><span class="gestao-sr">Atenção: ativa há mais de 15 dias. </span><span><?= h($idadeTexto) ?></span></span><?php else: ?><?= h($idadeTexto) ?><?php endif; ?></dd>
</dl>
<?php if ((int) $ordem['mesmo_numero_outros_clientes'] > 0): ?>
<p class="gestao-sinal gestao-sinal--outros-clientes" id="ordem-outros-clientes" data-outros-clientes="<?= (int) $ordem['mesmo_numero_outros_clientes'] ?>"><?= gestaoIcone('alerta') ?><span>Mesmo número em outros clientes. Esta ação vale só para esta ordem.</span></p>
<?php endif; ?>
<?php if ($clienteInativo): ?>
<div class="gestao-estado gestao-estado--aviso" id="ordem-cliente-inativo" data-cliente-status="INATIVO"><?= gestaoIcone('alerta') ?><span><?= h($avisoClienteInativo) ?></span></div>
<?php endif; ?>
<?php if ($confirmacao !== null): ?>
<div class="gestao-estado gestao-estado--aviso" id="ordem-confirmacao" role="alert" data-acao="<?= h($confirmacao['acao']) ?>"><?= gestaoIcone('alerta') ?><span><?= h($confirmacao['texto']) ?></span></div>
<?php endif; ?>
<form class="gestao-form-acao" id="ordem-acao-form" method="post" action="/gestao/ordem-status.php">
<?= gestaoCsrfInput($ctx) ?>
<input type="hidden" name="id_ordem" value="<?= $idOrdem ?>">
<input type="hidden" name="status_visto" value="<?= $ativa ? 'ATIVA' : 'INATIVA' ?>">
<input type="hidden" name="origem" value="detalhe">
<input type="hidden" name="retorno" value="<?= h($retorno) ?>">
<?php if ($confirmacao !== null): ?>
<input type="hidden" name="confirmar" value="1">
<?php endif; ?>
<?php if ($confirmacao !== null): ?>
<a class="gestao-botao gestao-botao--secundario" id="ordem-confirmacao-cancelar" href="/gestao/ordem.php?id=<?= $idOrdem ?>&amp;<?= h($retorno) ?>"><span>Cancelar</span></a>
<?php endif; ?>
<?php if ($ativa): ?>
<input type="hidden" name="acao" value="inativar">
<button class="gestao-botao <?= $confirmacao !== null ? 'gestao-botao--destrutivo-cheio' : 'gestao-botao--destrutivo' ?>" id="btn-ordem-inativar" type="submit" aria-label="<?= $confirmacao !== null ? 'Inativar mesmo assim' : 'Inativar' ?> a ordem <?= h($ordem['numero']) ?>"><?= gestaoIcone('inativo') ?><span><?= $confirmacao !== null ? 'Inativar mesmo assim' : 'Inativar' ?></span></button>
<?php else: ?>
<input type="hidden" name="acao" value="ativar">
<button class="gestao-botao <?= $confirmacao !== null ? 'gestao-botao--primario' : 'gestao-botao--secundario' ?>" id="btn-ordem-ativar" type="submit" aria-label="<?= $confirmacao !== null ? 'Ativar mesmo assim' : 'Ativar' ?> a ordem <?= h($ordem['numero']) ?>"><?= gestaoIcone('ok') ?><span><?= $confirmacao !== null ? 'Ativar mesmo assim' : 'Ativar' ?></span></button>
<?php endif; ?>
</form>
</section>
<section class="gestao-cartao" id="ordem-cliente" aria-labelledby="ordem-titulo-cliente">
<h2 class="gestao-cartao__titulo" id="ordem-titulo-cliente">Cliente</h2>
<dl class="gestao-dados">
<dt>Razão social</dt><dd><?= h($vazio($ordem['razao_social'])) ?></dd>
<dt>CNPJ</dt><dd><?= h($vazio($cnpjCliente)) ?></dd>
</dl>
</section>
<section class="gestao-cartao" id="ordem-transportadora" aria-labelledby="ordem-titulo-transportadora">
<h2 class="gestao-cartao__titulo" id="ordem-titulo-transportadora">Transportadora</h2>
<dl class="gestao-dados">
<dt>Nome</dt><dd><?= h($vazio($ordem['transportadora_nome'] ?? '')) ?></dd>
<dt>CNPJ</dt><dd><?= h($vazio($cnpjTransportadora)) ?></dd>
</dl>
</section>
<section class="gestao-cartao" id="ordem-motorista" aria-labelledby="ordem-titulo-motorista">
<h2 class="gestao-cartao__titulo" id="ordem-titulo-motorista">Motorista e veículo</h2>
<dl class="gestao-dados">
<dt>Motorista</dt><dd><?= h($vazio($ordem['motorista_nome_previsto'] ?? '')) ?></dd>
<dt>CNH</dt><dd><?= h($vazio($ordem['cnh_prevista'] ?? '')) ?></dd>
<dt>Placa</dt><dd><?= h($vazio($ordem['placa_prevista'] ?? '')) ?></dd>
</dl>
</section>
<section class="gestao-cartao" id="ordem-pdf" data-pdf="<?= h($pdf) ?>" aria-labelledby="ordem-titulo-pdf">
<h2 class="gestao-cartao__titulo" id="ordem-titulo-pdf">Documento (PDF)</h2>
<?php if ($pdf === 'disponivel'): ?>
<form class="gestao-form-acao" id="ordem-pdf-form" method="post" action="/gestao/ordem-pdf.php" target="_blank" rel="noopener">
<?= gestaoCsrfInput($ctx) ?>
<input type="hidden" name="id_ordem" value="<?= $idOrdem ?>">
<button class="gestao-botao gestao-botao--primario" id="btn-ordem-pdf" type="submit"><?= gestaoIcone('externo') ?><span>Baixar PDF</span><span class="gestao-sr"> (abre em uma nova aba)</span></button>
</form>
<?php else: ?>
<p id="ordem-pdf-texto"><?= h($pdfTexto) ?></p>
<?php endif; ?>
<?php if ($pdfAviso !== ''): ?>
<p class="gestao-nota" id="ordem-pdf-aviso"><?= gestaoIcone('info') ?><span><?= h($pdfAviso) ?></span></p>
<?php endif; ?>
</section>
</div>
