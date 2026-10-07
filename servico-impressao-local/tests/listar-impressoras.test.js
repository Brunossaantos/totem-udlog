'use strict';

// Sem PowerShell real e sem imprimir: executor injetado. Rodar: npm test
const test = require('node:test');
const assert = require('node:assert');
const fs = require('fs');
const os = require('os');
const path = require('path');

// Config temporaria (token ficticio) ANTES de carregar modulos que leem config.
const dirTmp = fs.mkdtempSync(path.join(os.tmpdir(), 'udlog-imp-'));
const TOKEN = 'token-de-teste-0123456789abcdef';
fs.writeFileSync(path.join(dirTmp, 'config.json'), JSON.stringify({
  porta: 4747,
  token: TOKEN,
  maxPdfBytesDecodificado: 2097152,
  origensPermitidas: [],
  idempotencia: { ttlMs: 600000, maxEntries: 500 },
  impressorasPermitidas: ['EPSON TM-T88VII Receipt'],
}));
process.env.CONFIG_PATH = path.join(dirTmp, 'config.json');

const express = require('express');
const { listarImpressoras, parsearSaida, SCRIPT_LISTAR_IMPRESSORAS } = require('../src/lib/listarImpressoras');
const listador = require('../src/lib/listarImpressoras');
const rota = require('../src/routes/impressoras');

function execFalso(stdout, { erro = null } = {}) {
  return (bin, args, opcoes, cb) => {
    if (bin === 'powershell.exe') {
      assert.strictEqual(args[3], SCRIPT_LISTAR_IMPRESSORAS);
      assert.strictEqual(opcoes.windowsHide, true);
      setImmediate(() => cb(erro, stdout));
    } else {
      setImmediate(() => cb(null, ''));
    }
    return { pid: 4321 };
  };
}

test('uma impressora (objeto) vira lista de um item', async () => {
  const r = await listarImpressoras({ execFileImpl: execFalso('{"Name":"EPSON TM-T88VII Receipt","Default":true}') });
  assert.deepStrictEqual(r, [{ name: 'EPSON TM-T88VII Receipt', isDefault: true }]);
});

test('varias impressoras (array)', async () => {
  const r = await listarImpressoras({ execFileImpl: execFalso('[{"Name":"A","Default":false},{"Name":"B","Default":true}]') });
  assert.deepStrictEqual(r, [{ name: 'A', isDefault: false }, { name: 'B', isDefault: true }]);
});

test('zero impressoras (saida vazia) => lista vazia', async () => {
  assert.deepStrictEqual(await listarImpressoras({ execFileImpl: execFalso('') }), []);
  assert.deepStrictEqual(parsearSaida('  \r\n'), []);
});

test('nome acentuado e BOM preservados', () => {
  const r = parsearSaida('\uFEFF{"Name":"Impressora Expedição Ç","Default":false}');
  assert.strictEqual(r[0].name, 'Impressora Expedição Ç');
});

test('JSON invalido => erro sanitizado', async () => {
  await assert.rejects(listarImpressoras({ execFileImpl: execFalso('nao e json') }), { message: 'json_invalido' });
  assert.throws(() => parsearSaida('[{"Default":true}]'), { message: 'item_invalido' });
});

test('falha do PowerShell => erro sanitizado', async () => {
  await assert.rejects(
    listarImpressoras({ execFileImpl: execFalso('', { erro: new Error('C:\\segredo\\caminho') }) }),
    { message: 'powershell_falhou' }
  );
});

test('timeout => erro e kill por PID', async () => {
  const chamadas = [];
  const exec = (bin, args, opcoes, cb) => {
    chamadas.push({ bin, args });
    if (bin === 'taskkill') setImmediate(() => cb(null, ''));
    return { pid: 4321 }; // powershell nunca responde
  };
  await assert.rejects(listarImpressoras({ execFileImpl: exec, timeoutMs: 20 }), { message: 'timeout_listagem' });
  const kill = chamadas.find((c) => c.bin === 'taskkill');
  assert.deepStrictEqual(kill.args, ['/PID', '4321', '/T', '/F']);
});

async function comServidor(fn) {
  const app = express();
  app.use(rota);
  const srv = await new Promise((resolve) => { const s = app.listen(0, '127.0.0.1', () => resolve(s)); });
  try {
    return await fn(`http://127.0.0.1:${srv.address().port}`);
  } finally {
    await new Promise((resolve) => srv.close(resolve));
  }
}

test('GET /impressoras: so a da allowlist aparece; virtuais nunca', async () => {
  const original = listador.listarImpressoras;
  listador.listarImpressoras = async () => [
    { name: 'Microsoft Print to PDF', isDefault: false },
    { name: 'OneNote (Desktop)', isDefault: false },
    { name: 'EPSON TM-T88VII Receipt', isDefault: true },
  ];
  try {
    await comServidor(async (base) => {
      const res = await fetch(`${base}/impressoras`, { headers: { Authorization: `Bearer ${TOKEN}` } });
      assert.strictEqual(res.status, 200);
      assert.deepStrictEqual(await res.json(), { impressoras: [{ nome: 'EPSON TM-T88VII Receipt', padrao: true }] });
    });
  } finally {
    listador.listarImpressoras = original;
  }
});

test('GET /impressoras: 401 sem token e 500 em falha', async () => {
  const original = listador.listarImpressoras;
  const logOriginal = console.error;
  console.error = () => {};
  listador.listarImpressoras = async () => { throw new Error('powershell_falhou'); };
  try {
    await comServidor(async (base) => {
      assert.strictEqual((await fetch(`${base}/impressoras`)).status, 401);
      assert.strictEqual((await fetch(`${base}/impressoras`, { headers: { Authorization: 'Bearer errado' } })).status, 401);
      const res = await fetch(`${base}/impressoras`, { headers: { Authorization: `Bearer ${TOKEN}` } });
      assert.strictEqual(res.status, 500);
      assert.deepStrictEqual(await res.json(), { erro: 'Nao foi possivel listar as impressoras.' });
    });
  } finally {
    listador.listarImpressoras = original;
    console.error = logOriginal;
  }
});
