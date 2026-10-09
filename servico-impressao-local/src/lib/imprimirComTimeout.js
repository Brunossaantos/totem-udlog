'use strict';

const fs = require('fs');
const path = require('path');
const { execFile } = require('child_process');

const { PRINT_SETTINGS_PADRAO, montarArgumentosSumatra } = require('./printSettings');

const SUMATRA_PDF_EXECUTAVEL = 'SumatraPDF-3.4.6-32.exe';

function resolverCaminhoSumatra() {
  const diretorioPdfToPrinter = path.dirname(require.resolve('pdf-to-printer'));
  const caminho = path.join(diretorioPdfToPrinter, SUMATRA_PDF_EXECUTAVEL);

  if (!fs.existsSync(caminho)) {
    throw new Error(
      `Executavel de impressao (${SUMATRA_PDF_EXECUTAVEL}) nao encontrado em ${diretorioPdfToPrinter}. `
      + 'Reinstale as dependencias do servico (npm install) ou verifique a versao de pdf-to-printer.'
    );
  }

  return caminho;
}

function encerrarProcessoPorPid(pid) {
  return new Promise((resolveEncerramento) => {
    execFile('taskkill', ['/PID', String(pid), '/T', '/F'], () => {
      resolveEncerramento();
    });
  });
}

function imprimirComTimeout(caminhoPdf, impressora, timeoutMs, opcoes = {}) {
  const caminhoSumatra = resolverCaminhoSumatra();
  const printSettings = opcoes.printSettings === undefined ? PRINT_SETTINGS_PADRAO : opcoes.printSettings;
  const execFileImpl = opcoes.execFileImpl || execFile;
  const argumentos = montarArgumentosSumatra(caminhoPdf, impressora, printSettings);

  return new Promise((resolve, reject) => {
    let finalizado = false;
    let temporizador = null;

    const processo = execFileImpl(caminhoSumatra, argumentos, (erro) => {
      if (finalizado) {
        return;
      }
      finalizado = true;
      clearTimeout(temporizador);

      if (erro) {
        reject(Object.assign(new Error('Falha ao executar o processo de impressao.'), { causaOriginal: erro }));
        return;
      }
      resolve();
    });

    temporizador = setTimeout(async () => {
      if (finalizado) {
        return;
      }
      finalizado = true;

      if (processo.pid) {
        await encerrarProcessoPorPid(processo.pid);
      }

      const erroTimeout = new Error('Tempo limite de impressao excedido.');
      erroTimeout.timeout = true;
      reject(erroTimeout);
    }, timeoutMs);
  });
}

module.exports = { imprimirComTimeout };
