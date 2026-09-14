'use strict';

/**
 * Executa a impressao de um PDF ja validado, com timeout e controle real
 * do PID do processo de impressao.
 *
 * DECISAO TECNICA (registrar para revisao do security-especialista): a
 * biblioteca `pdf-to-printer` (ver node_modules/pdf-to-printer/dist/bundle.js)
 * NAO expoe o PID do processo que ela mesma spawna -- `print()` usa
 * internamente `util.promisify(child_process.execFile)`, que so devolve uma
 * Promise (stdout/stderr), sem acesso ao objeto ChildProcess/PID. Sem PID,
 * nao e possivel matar so o processo daquele job especifico em caso de
 * timeout (exigencia do handoff, para nunca usar `taskkill /IM` por nome).
 *
 * Para resolver isso sem reimplementar nada alem do necessario, este
 * modulo reproduz EXATAMENTE a mesma chamada que `pdf-to-printer` faz
 * internamente (mesmo binario, mesmos argumentos de linha de comando),
 * mas via `child_process.execFile` chamado diretamente por este modulo,
 * o que devolve o objeto ChildProcess (e portanto o `.pid` real) de forma
 * sincrona, antes do callback de conclusao. O comportamento de impressao
 * (SumatraPDF, `-print-to <impressora> -silent <arquivo>`) fica identico
 * ao da biblioteca -- so passamos a controlar o processo diretamente.
 *
 * Risco assumido (documentar, nao decidir sozinho): se uma futura
 * atualizacao de `pdf-to-printer` mudar o nome do executavel empacotado
 * (hoje `SumatraPDF-3.4.6-32.exe`) ou os argumentos de linha de comando
 * que ela gera, este modulo precisa ser atualizado em conjunto -- ele nao
 * chama mais `pdf-to-printer`'s `print()` para a impressao em si (so
 * continua usando `getPrinters()` da biblioteca, em impressoras.js, que
 * nao foi alterado).
 */

const fs = require('fs');
const path = require('path');
const { execFile } = require('child_process');

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

/**
 * Mata SOMENTE o PID informado e a arvore de subprocessos dele (nunca por
 * nome de processo/imagem). Devolve uma Promise que so resolve depois que
 * o `taskkill` efetivamente terminou (sucesso ou erro) -- quem chama esta
 * funcao DEVE aguardar essa confirmacao antes de considerar o processo
 * anterior encerrado (ex.: antes de liberar o mutex do job seguinte),
 * para nunca abrir uma janela onde dois jobs escrevem na mesma impressora
 * USB fisica ao mesmo tempo.
 * @param {number} pid
 * @returns {Promise<void>}
 */
function encerrarProcessoPorPid(pid) {
  return new Promise((resolveEncerramento) => {
    execFile('taskkill', ['/PID', String(pid), '/T', '/F'], () => {
      // Melhor esforco -- se o processo ja tiver terminado sozinho entre o
      // estouro do timeout e esta chamada, taskkill retorna erro (processo
      // nao encontrado) e isso e esperado, nao e uma falha a propagar.
      // O que importa aqui e so o "terminou" (ja no evento assincrono),
      // nao o resultado.
      resolveEncerramento();
    });
  });
}

/**
 * Imprime um PDF ja validado na impressora indicada, encerrando o processo
 * a forca (pelo PID real daquele job) se `timeoutMs` for atingido.
 *
 * @param {string} caminhoPdf caminho absoluto do PDF temporario ja gravado em disco
 * @param {string} impressora nome exato da impressora (ja revalidado contra a allowlist pelo chamador)
 * @param {number} timeoutMs
 * @returns {Promise<void>}
 */
function imprimirComTimeout(caminhoPdf, impressora, timeoutMs) {
  const caminhoSumatra = resolverCaminhoSumatra();
  const argumentos = ['-print-to', impressora, '-silent', caminhoPdf];

  return new Promise((resolve, reject) => {
    let finalizado = false;
    let temporizador = null;

    const processo = execFile(caminhoSumatra, argumentos, (erro) => {
      if (finalizado) {
        // Callback chegou depois do timeout ja ter reagido -- ignorar
        // (o job ja foi tratado como indeterminado).
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

      // So rejeita a Promise (e portanto so libera o mutex de quem chamou,
      // ver routes/imprimir.js) depois de ter certeza de que o processo
      // anterior nao esta mais rodando -- evita a janela de corrida em que
      // um novo job comecaria a imprimir enquanto o processo do job
      // anterior ainda pode estar vivo.
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
