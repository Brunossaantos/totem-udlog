<?php
/**
 * Criar/editar empresa (só admin). Variáveis: $editando (bool), $id_alvo (int|null), $valores
 * (array nome, cnpj), $erros (array campo => mensagem; campos nome, cnpj), $totens_ativos e
 * $totens_total (int; só na edição).
 *
 * Na edição só o nome muda: o CNPJ aparece somente leitura (e não é enviado). O aviso
 * permanente `empresa-aviso-urls` só existe na edição. Sem script nem style inline.
 */
$erroCampo = static function (string $campo) use ($erros): string {
    if (!isset($erros[$campo])) {
        return '';
    }

    return '<span class="gestao-campo__erro" id="erro-empresa-' . h($campo) . '" role="alert">' . gestaoIcone('alerta') . '<span class="gestao-sr">Erro: </span>' . h($erros[$campo]) . '</span>';
};
$atributosCampo = static fn (string $campo, string $ajuda = ''): string => isset($erros[$campo])
    ? ' aria-invalid="true" aria-describedby="' . ($ajuda !== '' ? h($ajuda) . ' ' : '') . 'erro-empresa-' . h($campo) . '"'
    : ($ajuda !== '' ? ' aria-describedby="' . h($ajuda) . '"' : '');
?>
<section class="gestao-cartao" id="empresa-form-cartao">
<h2 class="gestao-cartao__titulo"><?= $editando ? 'Dados do cadastro' : 'Dados da nova empresa' ?></h2>
<?php if ($editando): ?>
<div class="gestao-nota-permanente" id="empresa-aviso-urls"><?= gestaoIcone('info') ?><p>As URLs dos totens já criados continuam com o nome anterior da empresa até serem regeradas.</p></div>
<p class="gestao-ajuda" id="empresa-totens-resumo"><?= gestaoIcone('info') ?><span>Totens desta empresa: <?= h($totens_total === 0 ? 'nenhum' : $totens_total . ' no total, ' . $totens_ativos . ' ativo(s)') ?>.</span></p>
<?php endif; ?>
<form class="gestao-form" id="form-empresa" method="post" action="/gestao/empresa-form.php" autocomplete="off">
<?= gestaoCsrfInput($ctx) ?>
<?php if ($editando): ?>
<input type="hidden" name="id_empresa" value="<?= (int) $id_alvo ?>">
<?php endif; ?>
<div class="gestao-campo<?= isset($erros['nome']) ? ' gestao-campo--erro' : '' ?>">
<label class="gestao-campo__rotulo" for="empresa-nome">Nome da empresa</label>
<input class="gestao-campo__entrada" id="empresa-nome" name="nome" type="text" value="<?= h($valores['nome']) ?>" maxlength="100" required<?= $atributosCampo('nome', 'empresa-nome-ajuda') ?>>
<span class="gestao-campo__ajuda" id="empresa-nome-ajuda">O nome vira parte da URL dos totens: sem espaços nem acentos, precisa gerar de 1 a 16 letras ou números (exemplo: Maua I vira MAUAI).</span>
<?= $erroCampo('nome') ?>
</div>
<div class="gestao-campo<?= isset($erros['cnpj']) ? ' gestao-campo--erro' : '' ?>">
<label class="gestao-campo__rotulo" for="empresa-cnpj">CNPJ</label>
<?php if ($editando): ?>
<input class="gestao-campo__entrada" id="empresa-cnpj" type="text" value="<?= gestaoCnpj($valores['cnpj']) ?>" readonly aria-describedby="empresa-cnpj-ajuda">
<span class="gestao-campo__ajuda" id="empresa-cnpj-ajuda">O CNPJ não pode ser alterado. Para corrigir, exclua a empresa (sem totens vinculados) e cadastre de novo.</span>
<?php else: ?>
<input class="gestao-campo__entrada" id="empresa-cnpj" name="cnpj" type="text" inputmode="numeric" value="<?= h($valores['cnpj']) ?>" maxlength="30" required<?= $atributosCampo('cnpj', 'empresa-cnpj-ajuda') ?>>
<span class="gestao-campo__ajuda" id="empresa-cnpj-ajuda">14 números, com ou sem pontos, barra e traço. É o CNPJ do armazém enviado ao Talent. Depois de criado não pode ser alterado.</span>
<?php endif; ?>
<?= $erroCampo('cnpj') ?>
</div>
<div class="gestao-barra-acoes">
<button class="gestao-botao gestao-botao--primario" id="btn-salvar-empresa" type="submit"><?= $editando ? 'Salvar' : 'Criar empresa' ?></button>
<a class="gestao-botao gestao-botao--secundario" id="btn-cancelar-empresa" href="/gestao/empresas.php">Cancelar</a>
</div>
</form>
</section>
