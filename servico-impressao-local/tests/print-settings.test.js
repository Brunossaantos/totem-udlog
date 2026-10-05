'use strict';

// Teste unitario sem impressao: execFile injetado. Rodar: npm test
const test = require('node:test');
const assert = require('node:assert');
const {
  PRINT_SETTINGS_PADRAO,
  validarPrintSettings,
  montarArgumentosSumatra,
} = require('../src/lib/printSettings');
const { imprimirComTimeout } = require('../src/lib/imprimirComTimeout');

test('padrao quando ausente', () => {
  assert.strictEqual(validarPrintSettings(undefined), 'noscale,portrait,paper=totem');
  assert.strictEqual(validarPrintSettings(null), PRINT_SETTINGS_PADRAO);
});

test('customizada e vazia aceitas', () => {
  assert.strictEqual(validarPrintSettings('fit,landscape'), 'fit,landscape');
  assert.strictEqual(validarPrintSettings(''), '');
});

test('invalidas rejeitadas', () => {
  for (const ruim of ['a;b', 'a"b', 'a&b', '$x', 'a\nb', 'x'.repeat(101), 5, {}]) {
    assert.throws(() => validarPrintSettings(ruim), /printSettings/);
  }
});

test('montagem dos argumentos: -print-settings antes do arquivo', () => {
  assert.deepStrictEqual(
    montarArgumentosSumatra('C:\\t.pdf', 'Imp', 'noscale,portrait,paper=totem'),
    ['-print-to', 'Imp', '-print-settings', 'noscale,portrait,paper=totem', '-silent', 'C:\\t.pdf']
  );
  assert.deepStrictEqual(
    montarArgumentosSumatra('C:\\t.pdf', 'Imp', ''),
    ['-print-to', 'Imp', '-silent', 'C:\\t.pdf']
  );
});

test('imprimirComTimeout usa execFile injetado (nao imprime)', async () => {
  let recebido = null;
  const fake = (bin, args, cb) => {
    recebido = { bin, args };
    setImmediate(() => cb(null));
    return { pid: 0 };
  };
  await imprimirComTimeout('C:\\t.pdf', 'Imp', 1000, { execFileImpl: fake });
  assert.match(recebido.bin, /SumatraPDF-3\.4\.6-32\.exe$/);
  assert.deepStrictEqual(recebido.args.slice(0, 4), ['-print-to', 'Imp', '-print-settings', PRINT_SETTINGS_PADRAO]);
  assert.strictEqual(recebido.args[recebido.args.length - 1], 'C:\\t.pdf');

  await imprimirComTimeout('C:\\t.pdf', 'Imp', 1000, { execFileImpl: fake, printSettings: '' });
  assert.deepStrictEqual(recebido.args, ['-print-to', 'Imp', '-silent', 'C:\\t.pdf']);
});
