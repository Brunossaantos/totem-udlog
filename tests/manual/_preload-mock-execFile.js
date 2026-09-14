'use strict';

/**
 * PRELOAD DE TESTE (nunca usado em producao) -- demanda
 * impressao-etiqueta-teste, verificacao do comportamento de timeout/kill do
 * servico-impressao-local (2026-09-14).
 *
 * Monkeypatcha `child_process.execFile` ANTES de qualquer outro modulo do
 * servico ser carregado, para que `imprimirComTimeout.js` (que faz
 * `const { execFile } = require('child_process')` no topo do arquivo)
 * capture a versao interceptada.
 *
 * Regra do interceptador:
 *  - Se o `file` chamado for exatamente 'taskkill' -> repassa de verdade
 *    para o `execFile` original (e SEGURO: o unico PID que
 *    `encerrarProcessoPorPid()` pode matar e o do proprio processo mock
 *    criado por este preload, nunca um processo real do sistema).
 *  - Qualquer outro `file` (que seria o binario real do SumatraPDF) -> é
 *    SUBSTITUIDO por um processo mock inofensivo (`ping -n 90 127.0.0.1`
 *    no Windows) que dorme muito mais que qualquer timeout configurado no
 *    teste. O SumatraPDF real NUNCA e executado.
 *
 * Todas as chamadas (arquivo/args originais pedidos + comando real
 * executado + pid gerado) sao logadas em `global.__CHAMADAS_EXECFILE__`
 * para o script de teste inspecionar depois.
 *
 * Uso: `node -r ./tests/manual/_preload-mock-execFile.js <script>`
 */

const cp = require('child_process');

const execFileOriginal = cp.execFile.bind(cp);

// exposto para o script de teste poder rodar SUAS PROPRIAS chamadas de
// verificacao (ex.: `tasklist` para conferir se o PID ainda existe) sem
// cair no interceptador abaixo (senao a propria chamada de verificacao
// seria substituida pelo comando mock).
global.__EXECFILE_ORIGINAL__ = execFileOriginal;

global.__CHAMADAS_EXECFILE__ = [];

const COMANDO_MOCK = process.platform === 'win32' ? 'ping' : 'sleep';
const ARGS_MOCK = process.platform === 'win32' ? ['-n', '90', '127.0.0.1'] : ['90'];

cp.execFile = function execFileInterceptado(file, args, callback) {
  const ehTaskkill = file === 'taskkill';
  const comandoReal = ehTaskkill ? file : COMANDO_MOCK;
  const argsReal = ehTaskkill ? args : ARGS_MOCK;

  const processoFilho = execFileOriginal(comandoReal, argsReal, callback);

  global.__CHAMADAS_EXECFILE__.push({
    fileOriginalPedido: file,
    argsOriginalPedido: Array.isArray(args) ? args.slice() : args,
    comandoRealExecutado: comandoReal,
    argsRealExecutado: argsReal,
    pid: processoFilho ? processoFilho.pid : null,
    timestamp: Date.now(),
  });

  return processoFilho;
};

console.log('[preload-mock] child_process.execFile interceptado -- SumatraPDF real NUNCA sera invocado neste processo.');
