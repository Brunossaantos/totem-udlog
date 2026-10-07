'use strict';

// DocumentName com caminho completo (driver Receipt5/ESDPRT001). Sem PowerShell real.
const test = require('node:test');
const assert = require('node:assert');

const { verificarFila, nomeBase, SCRIPT_LISTAR, SCRIPT_REMOVER } = require('../src/lib/verificarFila');

const IMP = 'EPSON TM-T88V Receipt5';
const UUID = '519a9957-8511-4222-8333-444455556666';
const DOC = `impressao-local-udlog-${UUID}.pdf`;
const DIR_WIN = 'C:\\Users\\Totem-Locarti\\AppData\\Local\\Temp\\';
const DIR_UNIX = 'C:/Users/Totem-Locarti/AppData/Local/Temp/';

function relogio() {
  let t = 0;
  return { agora: () => t, dormir: async (ms) => { t += ms; } };
}

// Simula o script de remocao: remove o job de Id informado so se o nome base conferir.
function criarExec(clk, fila) {
  const chamadas = [];
  const removidos = new Set();
  const impl = (bin, args, opcoes, cb) => {
    chamadas.push({ args, env: opcoes.env });
    setImmediate(() => {
      const script = args[3];
      if (script === SCRIPT_LISTAR) {
        return cb(null, JSON.stringify(fila(clk.agora()).filter((j) => !removidos.has(j.Id))));
      }
      if (script === SCRIPT_REMOVER) {
        const id = Number(opcoes.env.UDLOG_JOB_ID);
        const j = fila(clk.agora()).find((x) => x.Id === id);
        if (!j || nomeBase(j.DocumentName) !== opcoes.env.UDLOG_DOC_NAME) return cb(null, '{"removido":false}');
        removidos.add(id);
        return cb(null, '{"removido":true}');
      }
      return cb(new Error('script inesperado'));
    });
  };
  return { impl, chamadas, removidos };
}

const job = (id, nome) => ({ Id: id, DocumentName: nome, JobStatus: 'Normal', PagesPrinted: 0 });
const rodar = (clk, impl, extra = {}) => verificarFila({
  impressora: IMP, nomeDocumento: DOC, limiteMs: 10000, execFileImpl: impl, log: () => {}, ...clk, ...extra,
});

test('nomeBase: separadores \ e /, sem separador, e vazio', () => {
  assert.strictEqual(nomeBase(DOC), DOC);
  assert.strictEqual(nomeBase(DIR_WIN + DOC), DOC);
  assert.strictEqual(nomeBase(DIR_UNIX + DOC), DOC);
  assert.strictEqual(nomeBase('C:\\a/b\\' + DOC), DOC);
  assert.strictEqual(nomeBase(''), '');
});

test('SCRIPT_REMOVER compara o nome base e segue constante', () => {
  assert.ok(SCRIPT_REMOVER.includes('[System.IO.Path]::GetFileName([string]$j.DocumentName) -ne $env:UDLOG_DOC_NAME'));
  assert.ok(!SCRIPT_REMOVER.includes('$j.DocumentName -ne'));
});

test('DocumentName so com o nome: sem papel continua detectado', async () => {
  const clk = relogio();
  const { impl } = criarExec(clk, () => [job(5, DOC)]);
  assert.deepStrictEqual(await rodar(clk, impl), { resultado: 'sem_papel', jobRemovido: true });
});

test('caminho completo (\ e /) sem papel -> sem_papel, remocao confirmada', async () => {
  for (const dir of [DIR_WIN, DIR_UNIX]) {
    const clk = relogio();
    const { impl, chamadas, removidos } = criarExec(clk, () => [job(12, dir + DOC)]);
    const r = await rodar(clk, impl);
    assert.deepStrictEqual(r, { resultado: 'sem_papel', jobRemovido: true }, dir);
    assert.ok(removidos.has(12));
    const rem = chamadas.filter((c) => c.args[3] === SCRIPT_REMOVER);
    assert.strictEqual(rem.length, 1);
    assert.strictEqual(rem[0].env.UDLOG_DOC_NAME, DOC);
    assert.strictEqual(rem[0].env.UDLOG_JOB_ID, '12');
    assert.ok(rem[0].args[3].includes('GetFileName'));
  }
});

test('caminho completo com outro nome base nao casa e nada e removido', async () => {
  const clk = relogio();
  const { impl, chamadas } = criarExec(clk, () => [job(3, DIR_WIN + 'outro-documento.pdf')]);
  const r = await rodar(clk, impl);
  assert.strictEqual(r.resultado, 'impresso');
  assert.strictEqual(r.motivo, 'job_nao_visto');
  assert.strictEqual(chamadas.filter((c) => c.args[3] === SCRIPT_REMOVER).length, 0);
});

test('nome base que apenas contem o UUID nao casa', async () => {
  const clk = relogio();
  const fila = () => [
    job(1, DIR_WIN + 'x' + DOC),
    job(2, DIR_WIN + DOC + '.bak'),
    job(3, DIR_WIN + `copia-${UUID}.pdf`),
    job(4, `${DOC}\\sub`),
  ];
  const { impl, chamadas } = criarExec(clk, fila);
  const r = await rodar(clk, impl);
  assert.strictEqual(r.motivo, 'job_nao_visto');
  assert.strictEqual(chamadas.filter((c) => c.args[3] === SCRIPT_REMOVER).length, 0);
});

test('duas jobs na fila: so a nossa e removida', async () => {
  const clk = relogio();
  const { impl, chamadas, removidos } = criarExec(clk, () => [
    job(20, DIR_WIN + 'outro-documento.pdf'),
    job(21, DIR_WIN + DOC),
  ]);
  const r = await rodar(clk, impl);
  assert.deepStrictEqual(r, { resultado: 'sem_papel', jobRemovido: true });
  assert.deepStrictEqual([...removidos], [21]);
  const rem = chamadas.filter((c) => c.args[3] === SCRIPT_REMOVER);
  assert.strictEqual(rem.length, 1);
  assert.strictEqual(rem[0].env.UDLOG_JOB_ID, '21');
});

test('com papel, caminho completo: job sai em ~3 s -> impresso', async () => {
  const clk = relogio();
  const { impl, chamadas } = criarExec(clk, (t) => (t < 3000 ? [job(30, DIR_WIN + DOC)] : []));
  const r = await rodar(clk, impl);
  assert.deepStrictEqual(r, { resultado: 'impresso', motivo: 'job_saiu_da_fila' });
  assert.strictEqual(chamadas.filter((c) => c.args[3] === SCRIPT_REMOVER).length, 0);
});

test('job nao visto -> impresso (job_nao_visto)', async () => {
  const clk = relogio();
  const { impl } = criarExec(clk, () => []);
  const r = await rodar(clk, impl);
  assert.deepStrictEqual(r, { resultado: 'impresso', motivo: 'job_nao_visto' });
});
