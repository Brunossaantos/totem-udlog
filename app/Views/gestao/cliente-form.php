<?php
/**
 * Criar/editar cliente (só admin). Variáveis: $editando (bool), $id_alvo (int|null),
 * $valores (array nome, cnpj, ativo), $erros (array campo => mensagem; campos nome, cnpj,
 * ativo), $situacao_atual (texto, só na edição), $ambiguidade (null, ou total e nomes: passo 1 da
 * confirmação de ambiguidade do OCR) e $texto_ambiguidade (mensagem fixa pronta).
 *
 * Com $ambiguidade a página mostra o aviso (até 5 nomes de clientes afetados), o resumo do que
 * será gravado e um formulário só com campos ocultos + confirmar=1 (o nome não fica editável
 * entre o aviso e a confirmação); o servidor só grava no segundo passo.
 *
 * Na edição só o nome muda: o CNPJ aparece somente leitura (e não é enviado) e a situação
 * muda pelos botões Ativar/Inativar da lista. Sem script nem style inline.
 */
$erroCampo = static function (string $campo) use ($erros): string {
    if (!isset($erros[$campo])) {
        return '';
    }

    return '<span class="gestao-campo__erro" id="erro-cliente-' . h($campo) . '" role="alert">' . gestaoIcone('alerta') . '<span class="gestao-sr">Erro: </span>' . h($erros[$campo]) . '</span>';
};
$atributosCampo = static fn (string $campo, string $ajuda = ''): string => isset($erros[$campo])
    ? ' aria-invalid="true" aria-describedby="' . ($ajuda !== '' ? h($ajuda) . ' ' : '') . 'erro-cliente-' . h($campo) . '"'
    : ($ajuda !== '' ? ' aria-describedby="' . h($ajuda) . '"' : '');
?>
<?php if ($ambiguidade !== null): ?>
<section class="gestao-cartao" id="cliente-ambiguidade-cartao">
<h2 class="gestao-cartao__titulo">Confirme para continuar</h2>
<div class="gestao-estado gestao-estado--aviso" id="cliente-ambiguidade" role="alert"><?= gestaoIcone('alerta') ?>
<div class="gestao-estado__corpo">
<p id="cliente-ambiguidade-texto"><?= h($texto_ambiguidade) ?></p>
<?php if ($ambiguidade['nomes'] !== []): ?>
<p id="cliente-ambiguidade-afetados">Clientes afetados (até 5): <?= implode(', ', array_map(static fn ($n): string => h($n), $ambiguidade['nomes'])) ?></p>
<?php endif; ?>
<p id="cliente-ambiguidade-resumo">Nome a gravar: <strong><?= h($valores['nome']) ?></strong></p>
</div>
</div>
<form class="gestao-form" id="form-cliente-confirmar-ambiguidade" method="post" action="/gestao/cliente-form.php" autocomplete="off">
<?= gestaoCsrfInput($ctx) ?>
<?php if ($editando): ?>
<input type="hidden" name="id_cliente" value="<?= (int) $id_alvo ?>">
<?php else: ?>
<input type="hidden" name="cnpj" value="<?= h($valores['cnpj']) ?>">
<input type="hidden" name="ativo" value="<?= h($valores['ativo']) ?>">
<?php endif; ?>
<input type="hidden" name="nome" value="<?= h($valores['nome']) ?>">
<input type="hidden" name="confirmar" value="1">
<div class="gestao-barra-acoes">
<button class="gestao-botao gestao-botao--primario" id="btn-confirmar-ambiguidade" type="submit"><?= $editando ? 'Confirmar e salvar' : 'Confirmar e criar cliente' ?></button>
<a class="gestao-botao gestao-botao--secundario" id="btn-voltar-ambiguidade" href="/gestao/cliente-form.php<?= $editando ? '?id=' . (int) $id_alvo : '' ?>">Voltar e editar o nome</a>
<a class="gestao-botao gestao-botao--secundario" id="btn-cancelar-ambiguidade" href="/gestao/clientes.php">Cancelar</a>
</div>
</form>
</section>
<?php return; endif; ?>
<section class="gestao-cartao" id="cliente-form-cartao">
<h2 class="gestao-cartao__titulo"><?= $editando ? 'Dados do cadastro' : 'Dados do novo cliente' ?></h2>
<form class="gestao-form" id="form-cliente" method="post" action="/gestao/cliente-form.php" autocomplete="off">
<?= gestaoCsrfInput($ctx) ?>
<?php if ($editando): ?>
<input type="hidden" name="id_cliente" value="<?= (int) $id_alvo ?>">
<?php endif; ?>
<div class="gestao-campo<?= isset($erros['nome']) ? ' gestao-campo--erro' : '' ?>">
<label class="gestao-campo__rotulo" for="cliente-nome">Nome do cliente</label>
<input class="gestao-campo__entrada" id="cliente-nome" name="nome" type="text" value="<?= h($valores['nome']) ?>" maxlength="150" required<?= $atributosCampo('nome', 'cliente-nome-ajuda') ?>>
<span class="gestao-campo__ajuda" id="cliente-nome-ajuda">O nome é normalizado (maiúsculas, sem acentos, sem pontuação e sem termos como LTDA e S/A) para o reconhecimento automático das notas; acentos são ignorados, então Café e Cafe são o mesmo nome. Prefira escrever o nome como aparece na nota fiscal.</span>
<?= $erroCampo('nome') ?>
</div>
<div class="gestao-campo<?= isset($erros['cnpj']) ? ' gestao-campo--erro' : '' ?>">
<label class="gestao-campo__rotulo" for="cliente-cnpj">CNPJ</label>
<?php if ($editando): ?>
<input class="gestao-campo__entrada" id="cliente-cnpj" type="text" value="<?= gestaoCnpj($valores['cnpj']) ?>" readonly aria-describedby="cliente-cnpj-ajuda">
<span class="gestao-campo__ajuda" id="cliente-cnpj-ajuda">O CNPJ não pode ser alterado. Para corrigir, exclua o cliente e cadastre de novo.</span>
<?php else: ?>
<input class="gestao-campo__entrada" id="cliente-cnpj" name="cnpj" type="text" inputmode="numeric" value="<?= h($valores['cnpj']) ?>" maxlength="30" required<?= $atributosCampo('cnpj', 'cliente-cnpj-ajuda') ?>>
<span class="gestao-campo__ajuda" id="cliente-cnpj-ajuda">14 números, com ou sem pontos, barra e traço. Depois de criado não pode ser alterado. O CNPJ da UDLOG não pode ser cadastrado como cliente.</span>
<?php endif; ?>
<?= $erroCampo('cnpj') ?>
</div>
<?php if ($editando): ?>
<p class="gestao-ajuda" id="cliente-situacao"><?= gestaoIcone('info') ?><span>Situação atual: <?= h($situacao_atual) ?>. Para mudar, use Ativar ou Inativar na lista de clientes.</span></p>
<?php else: ?>
<div class="gestao-campo<?= isset($erros['ativo']) ? ' gestao-campo--erro' : '' ?>">
<label class="gestao-campo__rotulo" for="cliente-ativo">Situação</label>
<select class="gestao-campo__entrada" id="cliente-ativo" name="ativo"<?= $atributosCampo('ativo') ?>>
<option value="1"<?= $valores['ativo'] !== '0' ? ' selected' : '' ?>>Ativo</option>
<option value="0"<?= $valores['ativo'] === '0' ? ' selected' : '' ?>>Inativo</option>
</select>
<?= $erroCampo('ativo') ?>
</div>
<?php endif; ?>
<div class="gestao-barra-acoes">
<button class="gestao-botao gestao-botao--primario" id="btn-salvar-cliente" type="submit"><?= $editando ? 'Salvar' : 'Criar cliente' ?></button>
<a class="gestao-botao gestao-botao--secundario" id="btn-cancelar-cliente" href="/gestao/clientes.php">Cancelar</a>
</div>
</form>
</section>
