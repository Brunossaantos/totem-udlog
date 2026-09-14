'use strict';

/**
 * TESTE MANUAL ISOLADO -- demanda impressao-etiqueta-teste (verificacao do
 * mecanismo de timeout/kill, 2026-09-14).
 *
 * Chama diretamente `imprimirComTimeout()` (sem HTTP, sem Express) com o
 * `child_process.execFile` interceptado por `_preload-mock-execFile.js`
 * (ver esse arquivo para o motivo/mecanismo). NUNCA invoca o binario
 * SumatraPDF real nem qualquer impressora.
 *
 * Rodar com:
 *   node -r ./tests/manual/_preload-mock-execFile.js ./tests/manual/teste_timeout_kill_isolado.js
 *
 * (a partir da raiz do projeto totem-udlog)
 */

const path = require('path');
const os = require('os');
const fs = require('fs');

const CAMINHO_MODULO = path.join(__dirname, '..', '..', 'servico-impressao-local', 'src', 'lib', 'imprimirComTimeout.js');
const { imprimirComTimeout } = require(CAMINHO_MODULO);

const TIMEOUT_TESTE_MS = 1500; // bem menor que os 90s do processo mock -- forca o timeout a disparar

// IMPORTANTE: usa o execFile ORIGINAL (nao interceptado), exposto pelo
// preload -- se usassemos o `require('child_process').execFile` normal
// aqui, a PROPRIA chamada de verificacao (`tasklist`) cairia no
// interceptador do preload e seria substituida pelo comando mock (ping),
// travando o teste por 90s. Isso foi observado na primeira execucao deste
// script (ver nota no relatorio de testes) e corrigido usando a referencia
// original exposta em `global.__EXECFILE_ORIGINAL__`.
const execFileOriginal = global.__EXECFILE_ORIGINAL__;
if (typeof execFileOriginal !== 'function') {
  console.error('Este script precisa ser rodado com -r ./tests/manual/_preload-mock-execFile.js (global.__EXECFILE_ORIGINAL__ ausente).');
  process.exit(1);
}

function tasklistPid(pid) {
  return new Promise((resolve) => {
    execFileOriginal('tasklist', ['/FI', `PID eq ${pid}`, '/FO', 'CSV'], (erro, stdout) => {
      resolve(String(stdout || ''));
    });
  });
}

async function main() {
  console.log('=== TESTE 1: timeout dispara e mata SOMENTE o PID do job ===\n');

  const arquivoPdfFake = path.join(os.tmpdir(), 'teste-isolado-nao-e-pdf-real.pdf');
  fs.writeFileSync(arquivoPdfFake, Buffer.from('conteudo-fake-nunca-lido-pelo-mock'));

  const inicio = Date.now();
  let erroCapturado = null;
  try {
    await imprimirComTimeout(arquivoPdfFake, 'IMPRESSORA-MOCK-NAO-EXISTE', TIMEOUT_TESTE_MS);
    console.log('FALHA DE TESTE: imprimirComTimeout resolveu com sucesso -- esperado era timeout.');
    process.exitCode = 1;
    return;
  } catch (erro) {
    erroCapturado = erro;
  }
  const duracaoMs = Date.now() - inicio;

  console.log(`Duracao ate rejeitar: ${duracaoMs}ms (timeout configurado: ${TIMEOUT_TESTE_MS}ms)`);
  console.log(`erro.timeout === true: ${erroCapturado.timeout === true}`);
  console.log(`Mensagem do erro: "${erroCapturado.message}"`);

  const chamadas = global.__CHAMADAS_EXECFILE__ || [];
  console.log(`\nChamadas a execFile interceptadas: ${chamadas.length}`);
  chamadas.forEach((c, i) => {
    console.log(`  [${i}] pedido="${c.fileOriginalPedido} ${JSON.stringify(c.argsOriginalPedido)}" -> executado="${c.comandoRealExecutado} ${JSON.stringify(c.argsRealExecutado)}" pid=${c.pid}`);
  });

  const chamadaMock = chamadas.find((c) => c.comandoRealExecutado === 'ping' || c.comandoRealExecutado === 'sleep');
  const chamadaTaskkill = chamadas.find((c) => c.fileOriginalPedido === 'taskkill');

  if (!chamadaMock) {
    console.log('\nFALHA DE TESTE: nenhuma chamada ao processo mock foi interceptada.');
    process.exitCode = 1;
    return;
  }
  if (!chamadaTaskkill) {
    console.log('\nFALHA DE TESTE: taskkill nunca foi chamado apos o timeout.');
    process.exitCode = 1;
    return;
  }

  console.log(`\nVerificando: taskkill foi chamado com args ${JSON.stringify(chamadaTaskkill.argsOriginalPedido)}`);
  const usouPidEspecifico = chamadaTaskkill.argsOriginalPedido[0] === '/PID'
    && chamadaTaskkill.argsOriginalPedido[1] === String(chamadaMock.pid)
    && chamadaTaskkill.argsOriginalPedido.includes('/T')
    && chamadaTaskkill.argsOriginalPedido.includes('/F');
  console.log(`taskkill usou /PID <pid especifico> (nunca /IM por nome): ${usouPidEspecifico}`);

  // pequena espera para o taskkill (assincrono, melhor esforco) ter efeito
  await new Promise((r) => setTimeout(r, 1500));

  const tasklistDepois = await tasklistPid(chamadaMock.pid);
  const aindaRodando = tasklistDepois.includes(String(chamadaMock.pid));
  console.log(`\ntasklist apos timeout para PID ${chamadaMock.pid}:`);
  console.log(tasklistDepois.trim() || '(vazio -- processo nao encontrado, como esperado)');
  console.log(`Processo mock ainda rodando apos taskkill: ${aindaRodando}`);

  try { fs.unlinkSync(arquivoPdfFake); } catch (e) { /* melhor esforco */ }

  console.log('\n=== TESTE 2: apos o timeout, uma nova chamada a imprimirComTimeout ainda funciona (nao trava globalmente) ===\n');
  // imprimirComTimeout() em si nao tem mutex (o mutex fica na rota
  // imprimir.js) -- aqui validamos so que o modulo em si nao fica preso
  // (ex.: temporizador nao cancelado, promise nunca resolvendo) para uma
  // segunda chamada independente.
  const arquivoPdfFake2 = path.join(os.tmpdir(), 'teste-isolado-nao-e-pdf-real-2.pdf');
  fs.writeFileSync(arquivoPdfFake2, Buffer.from('conteudo-fake-2'));
  const inicio2 = Date.now();
  try {
    await imprimirComTimeout(arquivoPdfFake2, 'IMPRESSORA-MOCK-NAO-EXISTE', TIMEOUT_TESTE_MS);
    console.log('FALHA DE TESTE: segunda chamada resolveu com sucesso -- esperado era timeout tambem (mock sempre dorme 90s).');
  } catch (erro2) {
    console.log(`Segunda chamada tambem rejeitou por timeout em ${Date.now() - inicio2}ms (esperado, confirma que o modulo nao ficou travado/preso apos o primeiro timeout).`);
  }
  try { fs.unlinkSync(arquivoPdfFake2); } catch (e) { /* melhor esforco */ }

  console.log('\n=== FIM DO TESTE ISOLADO ===');
}

main().then(() => {
  // da tempo do event loop drenar handles residuais antes de sair
  setTimeout(() => process.exit(process.exitCode || 0), 300);
}).catch((erroFatal) => {
  console.error('ERRO FATAL NO SCRIPT DE TESTE:', erroFatal);
  process.exit(1);
});
