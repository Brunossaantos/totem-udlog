'use strict';

// Sem imprimir e sem PowerShell real: execFile mockado. Rodar: npm test
const test = require('node:test');
const assert = require('node:assert');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { spawnSync } = require('child_process');

const { verificarFila, SCRIPT_LISTAR, SCRIPT_REMOVER } = require('../src/lib/verificarFila');

const IMP = 'EPSON TM-T88VII Receipt';
const DOC = 'impressao-local-udlog-11111111-2222-3333-4444-555555555555.pdf';

// Relogio falso: dormir avanca o tempo, sem esperar de verdade.
function relogio() {
  let t = 0;
  return { agora: () => t, dormir: async (ms) => { t += ms; } };
}

/**
 * execFile falso. `fila(t)` devolve a lista de jobs no instante t; `remocao`
 * define o comportamento do script de remocao.
 */
function criarExec(clk, { fila, remocao = 'ok', listagem = 'ok' }) {
  const chamadas = [];
  let removido = false;
  const impl = (bin, args, opcoes, cb) => {
    chamadas.push({ bin, args, env: opcoes.env });
    const script = args[3];
    setImmediate(() => {
      if (script === SCRIPT_LISTAR) {
        if (listagem === 'falha') return cb(new Error('x'));
        if (listagem === 'json_invalido') return cb(null, 'nao e json');
        if (listagem === 'job_invalido') return cb(null, '[{"Id":"abc"}]');
        let jobs = fila(clk.agora());
        if (removido) jobs = jobs.filter((j) => j.DocumentName !== DOC);
        return cb(null, JSON.stringify(jobs));
      }
      if (script === SCRIPT_REMOVER) {
        if (remocao === 'erro') return cb(new Error('x'));
        if (remocao === 'nao') return cb(null, '{"removido":false}');
        removido = true;
        return cb(null, '{"removido":true}');
      }
      return cb(new Error('script inesperado'));
    });
  };
  return { impl, chamadas };
}

const job = (id, nome) => ({ Id: id, DocumentName: nome, JobStatus: 'Printing, Retained', PagesPrinted: 0 });

test('job some em ~1 s -> impresso', async () => {
  const clk = relogio();
  const { impl } = criarExec(clk, { fila: (t) => (t < 1000 ? [job(7, DOC)] : []) });
  const r = await verificarFila({ impressora: IMP, nomeDocumento: DOC, limiteMs: 10000, execFileImpl: impl, ...clk });
  assert.strictEqual(r.resultado, 'impresso');
});

test('job fica ate o limite -> sem_papel e remove SO o job correto', async () => {
  const clk = relogio();
  const fila = () => [job(3, 'outro-documento.pdf'), job(9, DOC)];
  const { impl, chamadas } = criarExec(clk, { fila });
  const r = await verificarFila({ impressora: IMP, nomeDocumento: DOC, limiteMs: 10000, execFileImpl: impl, ...clk });
  assert.deepStrictEqual(r, { resultado: 'sem_papel', jobRemovido: true });
  const remocoes = chamadas.filter((c) => c.args[3] === SCRIPT_REMOVER);
  assert.strictEqual(remocoes.length, 1);
  assert.strictEqual(remocoes[0].env.UDLOG_JOB_ID, '9');
  assert.strictEqual(remocoes[0].env.UDLOG_DOC_NAME, DOC);
  assert.ok(clk.agora() >= 10000 && clk.agora() <= 13000);
});

test('outro job com outro nome na fila nao e removido nem confundido', async () => {
  const clk = relogio();
  const { impl, chamadas } = criarExec(clk, { fila: () => [job(3, 'outro-documento.pdf')] });
  const r = await verificarFila({ impressora: IMP, nomeDocumento: DOC, limiteMs: 10000, execFileImpl: impl, ...clk });
  assert.strictEqual(r.resultado, 'impresso');
  assert.strictEqual(chamadas.filter((c) => c.args[3] === SCRIPT_REMOVER).length, 0);
});

test('job nunca aparece -> impresso (janela de ~3 s)', async () => {
  const clk = relogio();
  const { impl } = criarExec(clk, { fila: () => [] });
  const r = await verificarFila({ impressora: IMP, nomeDocumento: DOC, limiteMs: 10000, execFileImpl: impl, ...clk });
  assert.strictEqual(r.resultado, 'impresso');
  assert.ok(clk.agora() <= 3500);
});

test('PowerShell falha / JSON invalido / job invalido -> impresso', async () => {
  for (const listagem of ['falha', 'json_invalido', 'job_invalido']) {
    const clk = relogio();
    const logs = [];
    const { impl } = criarExec(clk, { fila: () => [], listagem });
    const r = await verificarFila({
      impressora: IMP, nomeDocumento: DOC, limiteMs: 10000, execFileImpl: impl, log: (m) => logs.push(m), ...clk,
    });
    assert.strictEqual(r.resultado, 'impresso', listagem);
    assert.strictEqual(logs.length, 1);
    assert.ok(!logs[0].includes(IMP) && !logs[0].includes(DOC));
  }
});

test('remocao falha (erro ou nao confirmada) -> sem_papel com jobRemovido=false', async () => {
  for (const remocao of ['erro', 'nao']) {
    const clk = relogio();
    const { impl } = criarExec(clk, { fila: () => [job(9, DOC)], remocao });
    const r = await verificarFila({
      impressora: IMP, nomeDocumento: DOC, limiteMs: 10000, execFileImpl: impl, log: () => {}, ...clk,
    });
    assert.deepStrictEqual(r, { resultado: 'sem_papel', jobRemovido: false }, remocao);
  }
});

test('nome suspeito vai so por variavel de ambiente, nunca no comando', async () => {
  const clk = relogio();
  const suspeito = 'Imp"; Remove-Item C:\\* -Recurse; $(calc) `x';
  const doc = "a'b;calc & x.pdf";
  const { impl, chamadas } = criarExec(clk, { fila: () => [{ Id: 4, DocumentName: doc }] });
  const impl2 = (bin, args, op, cb) => impl(bin, args, op, cb);
  await verificarFila({ impressora: suspeito, nomeDocumento: doc, limiteMs: 3000, execFileImpl: impl2, log: () => {}, ...clk });
  assert.ok(chamadas.length > 0);
  for (const c of chamadas) {
    assert.strictEqual(c.bin, 'powershell.exe');
    assert.deepStrictEqual(c.args.slice(0, 3), ['-NoProfile', '-NonInteractive', '-Command']);
    assert.strictEqual(c.args.length, 4);
    assert.ok([SCRIPT_LISTAR, SCRIPT_REMOVER].includes(c.args[3]));
    assert.ok(!c.args.join(' ').includes('calc'));
    assert.strictEqual(c.env.UDLOG_PRINTER_NAME, suspeito);
  }
});

// ---- Config e rota ----

function configTemp(extra) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'udlog-cfg-'));
  const arq = path.join(dir, 'config.json');
  fs.writeFileSync(arq, JSON.stringify({
    porta: 4747,
    token: 'token-de-teste-com-mais-de-16-chars',
    maxPdfBytesDecodificado: 2097152,
    origensPermitidas: [],
    impressorasPermitidas: [IMP],
    idempotencia: { ttlMs: 600000, maxEntries: 50 },
    ...extra,
  }));
  return arq;
}

function carregarConfig(arq) {
  return spawnSync(process.execPath, ['-e', 'const c=require("./src/config");console.log(JSON.stringify([c.deteccaoSemPapel,c.semPapelTimeoutMs]))'], {
    cwd: path.join(__dirname, '..'),
    env: { ...process.env, CONFIG_PATH: arq },
    encoding: 'utf8',
  });
}

test('config: padroes e valores validos', () => {
  assert.strictEqual(carregarConfig(configTemp({})).stdout.trim(), '[true,10000]');
  assert.strictEqual(
    carregarConfig(configTemp({ deteccaoSemPapel: false, semPapelTimeoutMs: 3000 })).stdout.trim(),
    '[false,3000]'
  );
});

test('config invalida falha na inicializacao', () => {
  const ruins = [
    { deteccaoSemPapel: 'sim' }, { deteccaoSemPapel: 1 },
    { semPapelTimeoutMs: 2999 }, { semPapelTimeoutMs: 60001 }, { semPapelTimeoutMs: '10000' }, { semPapelTimeoutMs: 10000.5 },
  ];
  for (const extra of ruins) {
    const r = carregarConfig(configTemp(extra));
    assert.notStrictEqual(r.status, 0, JSON.stringify(extra));
    assert.match(r.stderr, /Config invalida/);
  }
});

test('rota: sem_papel (409) fora da idempotencia; impresso entra', async () => {
  process.env.CONFIG_PATH = configTemp({});
  const express = require('express');
  const imprimirModulo = require('../src/lib/imprimirComTimeout');
  const filaModulo = require('../src/lib/verificarFila');
  const rota = require('../src/routes/imprimir');

  let impressoes = 0;
  const nomes = [];
  imprimirModulo.imprimirComTimeout = async (caminho) => { impressoes += 1; nomes.push(path.basename(caminho)); };
  let resposta = { resultado: 'sem_papel', jobRemovido: true };
  const original = filaModulo.verificarFila;
  filaModulo.verificarFila = async (p) => { assert.ok(nomes.includes(p.nomeDocumento)); return resposta; };

  const app = express();
  app.use(rota);
  const servidor = app.listen(0, '127.0.0.1');
  await new Promise((r) => servidor.once('listening', r));
  const url = `http://127.0.0.1:${servidor.address().port}/imprimir`;
  const post = async (identificador) => {
    const r = await fetch(url, {
      method: 'POST',
      headers: { Authorization: 'Bearer token-de-teste-com-mais-de-16-chars', 'Content-Type': 'application/json' },
      body: JSON.stringify({ pdf_base64: Buffer.from('%PDF-1.4\n%%EOF').toString('base64'), impressora: IMP, identificador }),
    });
    return { http: r.status, corpo: await r.json() };
  };

  try {
    let r = await post('id-1');
    assert.strictEqual(r.http, 409);
    assert.deepStrictEqual(r.corpo, { status: 'sem_papel', identificador: 'id-1', job_removido: true });

    resposta = { resultado: 'sem_papel', jobRemovido: false };
    r = await post('id-1'); // mesmo id: nao foi memorizado -> imprime de novo
    assert.strictEqual(r.http, 409);
    assert.strictEqual(r.corpo.job_removido, false);
    assert.strictEqual(impressoes, 2);

    resposta = { resultado: 'impresso' };
    r = await post('id-2');
    assert.strictEqual(r.http, 200);
    assert.strictEqual(r.corpo.status, 'impresso');
    r = await post('id-2');
    assert.strictEqual(r.corpo.status, 'ja_impresso');
    assert.strictEqual(impressoes, 3);
  } finally {
    filaModulo.verificarFila = original;
    servidor.close();
  }
});
