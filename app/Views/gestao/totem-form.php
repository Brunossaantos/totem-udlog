<?php
/**
 * Novo totem (só admin). Variáveis: $empresas (list de id_empresa, nome; só ativas),
 * $valores (array empresa, nome), $erros (array campo => mensagem).
 */
$erroCampo = static function (string $campo) use ($erros): string {
    if (!isset($erros[$campo])) {
        return '';
    }

    return '<span class="gestao-campo__erro" id="erro-' . h($campo) . '" role="alert">' . gestaoIcone('alerta') . '<span class="gestao-sr">Erro: </span>' . h($erros[$campo]) . '</span>';
};
$atributosCampo = static fn (string $campo): string => isset($erros[$campo]) ? ' aria-invalid="true" aria-describedby="erro-' . h($campo) . '"' : '';
?>
<section class="gestao-cartao" id="totem-form-cartao">
<h2 class="gestao-cartao__titulo">Dados do novo totem</h2>
<form class="gestao-form" id="form-totem" method="post" action="/gestao/totem-form.php" autocomplete="off">
<?= gestaoCsrfInput($ctx) ?>
<div class="gestao-campo<?= isset($erros['empresa']) ? ' gestao-campo--erro' : '' ?>">
<label class="gestao-campo__rotulo" for="id_empresa">Empresa</label>
<select class="gestao-campo__entrada" id="id_empresa" name="id_empresa" required<?= $atributosCampo('empresa') ?>>
<option value="">Escolha a empresa</option>
<?php foreach ($empresas as $e): ?>
<option value="<?= (int) $e['id_empresa'] ?>"<?= (string) $valores['empresa'] === (string) (int) $e['id_empresa'] ? ' selected' : '' ?>><?= h($e['nome']) ?></option>
<?php endforeach; ?>
</select>
<?= $erroCampo('empresa') ?>
</div>
<div class="gestao-campo<?= isset($erros['nome']) ? ' gestao-campo--erro' : '' ?>">
<label class="gestao-campo__rotulo" for="nome">Nome do totem</label>
<input class="gestao-campo__entrada" id="nome" name="nome" type="text" value="<?= h($valores['nome']) ?>" maxlength="24" autocapitalize="characters" spellcheck="false" required<?= $atributosCampo('nome') ?>>
<span class="gestao-campo__ajuda">De 2 a 24 letras ou números (exemplo: GUICHE-04). Acentos e símbolos são ajustados e as letras viram maiúsculas.</span>
<?= $erroCampo('nome') ?>
</div>
<div class="gestao-ajuda" id="ajuda-url-totem"><?= gestaoIcone('info') ?><div class="gestao-ajuda__corpo"><p>A URL do quiosque terá este formato:</p><code class="gestao-totem-previa">NOME-EMPRESA-&lt;16 caracteres gerados ao salvar&gt;</code><p>O código secreto é gerado só ao salvar. A URL completa aparece na próxima tela e fica disponível na lista de totens.</p></div></div>
<div class="gestao-barra-acoes">
<button class="gestao-botao gestao-botao--primario" id="btn-salvar-totem" type="submit">Criar totem</button>
<a class="gestao-botao gestao-botao--secundario" id="btn-cancelar-totem" href="/gestao/totens.php">Cancelar</a>
</div>
</form>
</section>
