<?php
/**
 * Lista de totens (só admin). Variáveis: $totens (list; cada linha: id_totem,
 * nome, empresa_nome, ativo, criado_em, criado_por_nome, criado_por_login, url
 * (completa, só admin), legado (bool: URL fora do padrão novo, ex. RECEPCAO-01),
 * url_versao, url_regerada_em, atualizado_em, atendimentos_recentes (int)),
 * $janela_atendimento_min (int). NUNCA há token do totem nestas variáveis.
 *
 * As ações são <form method=post> com CSRF e funcionam sem JS; o gestao.js só
 * intercepta os botões com data-confirmar para pedir confirmação num diálogo.
 * "Copiar URL": o gestao.js troca o link "Abrir URL" (sem JS) pelo botão que copia a URL da linha.
 */
?>
<div class="gestao-barra-acoes" id="totens-acoes">
<a class="gestao-botao gestao-botao--primario" id="btn-novo-totem" href="/gestao/totem-form.php"><?= gestaoIcone('mais') ?><span>Novo totem</span></a>
</div>
<div class="gestao-tabela-wrap" role="region" aria-label="Tabela de totens" tabindex="0">
<table class="gestao-tabela gestao-tabela--totens" id="tabela-totens">
<caption class="gestao-tabela__legenda">Totens cadastrados</caption>
<colgroup><col class="gestao-col-nome"><col class="gestao-col-empresa"><col class="gestao-col-situacao"><col class="gestao-col-criado"><col class="gestao-col-url"><col class="gestao-col-acoes"></colgroup>
<thead>
<tr><th scope="col">Nome</th><th scope="col">Empresa</th><th scope="col">Situação</th><th scope="col">Criado em</th><th scope="col">URL do quiosque</th><th scope="col">Ações</th></tr>
</thead>
<tbody>
<?php if ($totens === []): ?>
<tr class="gestao-tabela__vazio"><td colspan="6"><div class="gestao-estado gestao-estado--vazio"><?= gestaoIcone('totens') ?><span>Nenhum totem cadastrado. Crie o primeiro.</span></div></td></tr>
<?php endif; ?>
<?php foreach ($totens as $t): ?>
<?php $ativo = (int) $t['ativo'] === 1; $recentes = (int) $t['atendimentos_recentes']; $idT = (int) $t['id_totem']; ?>
<tr class="gestao-tabela__linha<?= $ativo ? '' : ' gestao-tabela__linha--inativa' ?>" data-id-totem="<?= $idT ?>">
<td class="col-nome"><?= h($t['nome']) ?></td>
<td class="col-empresa"><?= h($t['empresa_nome'] ?? 'Sem empresa') ?></td>
<td class="col-situacao"><span class="gestao-situacao gestao-situacao--<?= $ativo ? 'ativo' : 'inativo' ?>"><?= gestaoIcone($ativo ? 'ok' : 'inativo') ?><span><?= h($ativo ? 'Ativo' : 'Inativo') ?></span></span><?= $recentes > 0 ? '<span class="gestao-situacao__nota gestao-situacao__nota--destaque">' . gestaoIcone('alerta') . '<span>' . h($recentes === 1 ? 'Atendimento em andamento' : $recentes . ' atendimentos em andamento') . '</span></span>' : '' ?></td>
<td class="col-criado"><?= gestaoData($t['criado_em'] ?? null) ?><?= ($t['criado_por_nome'] ?? null) !== null ? '<span class="gestao-situacao__nota">por ' . h($t['criado_por_nome']) . '</span>' : '' ?></td>
<td class="col-url"><div class="gestao-url-celula"><code class="gestao-totem-url" id="totem-url-<?= $idT ?>"><?= h($t['url']) ?></code><span class="gestao-url-copiar" data-copiar-de="totem-url-<?= $idT ?>" data-copiar-id="btn-copiar-url-<?= $idT ?>" data-copiar-rotulo="Copiar URL" data-copiar-objeto="a URL" data-copiar-aria="Copiar a URL de <?= h($t['nome']) ?>"><a class="gestao-botao gestao-botao--secundario gestao-botao--pequeno" data-copiar-alternativa href="/gestao/totem-url.php?id=<?= $idT ?>" aria-label="Abrir a URL de <?= h($t['nome']) ?> (abre a página da URL)"><?= gestaoIcone('copiar') ?><span>Abrir URL</span></a></span><?= $t['legado'] ? '<span class="gestao-situacao__nota gestao-url-celula__nota">Endereço fixo; continua valendo até ser regerado.</span>' : '' ?></div></td>
<td class="col-acoes">
<div class="gestao-acoes-linha">
<div class="gestao-acoes-grupo">
<?php if (!$ativo): ?>
<form class="gestao-form-linha" method="post" action="/gestao/totens.php">
<?= gestaoCsrfInput($ctx) ?>
<input type="hidden" name="id_totem" value="<?= $idT ?>">
<button class="gestao-botao gestao-botao--secundario gestao-botao--pequeno" type="submit" name="acao" value="ativar" aria-label="Ativar <?= h($t['nome']) ?>"><?= gestaoIcone('ok') ?><span>Ativar</span></button>
</form>
<?php endif; ?>
<form class="gestao-form-linha gestao-form-regerar" method="post" action="/gestao/totens.php">
<?= gestaoCsrfInput($ctx) ?>
<input type="hidden" name="id_totem" value="<?= $idT ?>">
<input type="hidden" name="versao_url" value="<?= (int) $t['url_versao'] ?>">
<label class="gestao-campo__rotulo gestao-confirmacao-inline" for="nome-confirmacao-<?= $idT ?>">Para regerar a URL, digite o nome do totem</label>
<input class="gestao-campo__entrada gestao-confirmacao-inline" id="nome-confirmacao-<?= $idT ?>" name="nome_confirmacao" type="text" maxlength="100" autocomplete="off" autocapitalize="characters" spellcheck="false" required>
<button class="gestao-botao gestao-botao--secundario gestao-botao--pequeno" type="submit" name="acao" value="regerar-url" aria-label="Regerar a URL de <?= h($t['nome']) ?>" data-confirmar="O endereço anterior deixa de funcionar agora. O token do totem não muda; para bloquear um totem, desative-o. Atualize a URL no .bat do mini PC e reinicie o PC." data-confirmar-titulo="Regerar URL" data-confirmar-rotulo="Regerar URL" data-confirmar-alvo="<?= h($t['nome']) ?>" data-confirmar-destrutivo="1"><?= gestaoIcone('chave') ?><span>Regerar URL</span></button>
</form>
</div>
<?php if ($ativo): ?>
<div class="gestao-acoes-grupo gestao-acoes-grupo--destrutivo">
<form class="gestao-form-linha" method="post" action="/gestao/totens.php">
<?= gestaoCsrfInput($ctx) ?>
<input type="hidden" name="id_totem" value="<?= $idT ?>">
<?php if ($recentes > 0): ?>
<label class="gestao-campo__rotulo gestao-confirmacao-inline gestao-confirmacao-inline--caixa" for="confirmar-atendimento-<?= $idT ?>"><input id="confirmar-atendimento-<?= $idT ?>" name="confirmar_atendimento" type="checkbox" value="1" required> Há atendimento em andamento nos últimos <?= (int) $janela_atendimento_min ?> minutos. Desativar mesmo assim.</label>
<?php endif; ?>
<button class="gestao-botao gestao-botao--destrutivo gestao-botao--pequeno" type="submit" name="acao" value="desativar" aria-label="Desativar <?= h($t['nome']) ?>" data-confirmar="Desativar este totem? O quiosque para de abrir e de responder agora<?= $recentes > 0 ? ', e o atendimento em andamento será interrompido' : '' ?>." data-confirmar-titulo="Desativar totem" data-confirmar-rotulo="Desativar" data-confirmar-alvo="<?= h($t['nome']) ?>" data-confirmar-destrutivo="1"><?= gestaoIcone('alerta') ?><span>Desativar</span></button>
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
