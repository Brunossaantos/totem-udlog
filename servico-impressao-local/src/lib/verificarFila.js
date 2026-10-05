'use strict';

/**
 * Deteccao de falta de papel pela permanencia do job na fila do spooler.
 *
 * Medicao empirica (EPSON TM-T88VII Receipt, porta TMUSB001, driver
 * Receipt6): com papel o job aparece na fila e SAI em <1 s; sem papel fica
 * "Printing, Retained" com 0 paginas por 36+ s. Os campos de estado da
 * impressora nao distinguem os dois casos. So vale para este driver/porta.
 *
 * Seguranca: os comandos PowerShell abaixo sao CONSTANTES. Nome da
 * impressora (allowlist/config), nome do documento (arquivo temporario com
 * UUID gerado internamente) e Id do job (inteiro validado) viajam apenas
 * por variaveis de ambiente do processo filho -- nunca concatenados em
 * string de comando. execFile sem shell.
 */

const { execFile } = require('child_process');

const SCRIPT_LISTAR = [
  "$ErrorActionPreference='Stop';",
  '$j=@(Get-PrintJob -PrinterName $env:UDLOG_PRINTER_NAME | Select-Object Id,DocumentName,JobStatus,PagesPrinted);',
  'ConvertTo-Json -Compress -InputObject $j',
].join(' ');

// Remove SOMENTE o job de Id informado, e so se o nome do documento for
// exatamente o arquivo temporario deste servico.
const SCRIPT_REMOVER = [
  "$ErrorActionPreference='Stop';",
  '$id=[int]$env:UDLOG_JOB_ID;',
  '$j=Get-PrintJob -PrinterName $env:UDLOG_PRINTER_NAME -ID $id;',
  'if($j.DocumentName -ne $env:UDLOG_DOC_NAME){ConvertTo-Json -Compress -InputObject @{removido=$false}; exit 0};',
  'Remove-PrintJob -PrinterName $env:UDLOG_PRINTER_NAME -ID $id;',
  'ConvertTo-Json -Compress -InputObject @{removido=$true}',
].join(' ');

const TIMEOUT_POWERSHELL_MS = 5000;
const INTERVALO_PADRAO_MS = 500;
const JANELA_VISAO_PADRAO_MS = 3000;
const CONFIRMACAO_TENTATIVAS = 3;

function dormirPadrao(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function executarPowerShell(execFileImpl, script, variaveis) {
  return new Promise((resolve, reject) => {
    execFileImpl(
      'powershell.exe',
      ['-NoProfile', '-NonInteractive', '-Command', script],
      {
        env: { ...process.env, ...variaveis },
        timeout: TIMEOUT_POWERSHELL_MS,
        windowsHide: true,
        maxBuffer: 1024 * 1024,
      },
      (erro, stdout) => {
        if (erro) {
          reject(new Error('powershell_falhou'));
          return;
        }
        resolve(String(stdout || ''));
      }
    );
  });
}

function parsearJson(texto) {
  const limpo = texto.trim();
  if (!limpo) {
    throw new Error('saida_vazia');
  }
  try {
    return JSON.parse(limpo);
  } catch (e) {
    throw new Error('json_invalido');
  }
}

/** Lista e valida os jobs da fila. Lanca Error se a saida for invalida. */
async function listarJobs(execFileImpl, impressora) {
  const saida = parsearJson(await executarPowerShell(execFileImpl, SCRIPT_LISTAR, {
    UDLOG_PRINTER_NAME: impressora,
  }));
  const lista = Array.isArray(saida) ? saida : [saida];
  return lista.map((item) => {
    if (
      !item || typeof item !== 'object'
      || !Number.isInteger(item.Id) || item.Id < 0
      || typeof item.DocumentName !== 'string'
    ) {
      throw new Error('job_invalido');
    }
    return { id: item.Id, nome: item.DocumentName };
  });
}

async function removerJob(execFileImpl, impressora, jobId, nomeDocumento) {
  const saida = parsearJson(await executarPowerShell(execFileImpl, SCRIPT_REMOVER, {
    UDLOG_PRINTER_NAME: impressora,
    UDLOG_JOB_ID: String(jobId),
    UDLOG_DOC_NAME: nomeDocumento,
  }));
  return !!saida && saida.removido === true;
}

/**
 * Monitora a fila apos o Sumatra sair com codigo 0.
 *
 * @param {object} p
 * @param {string} p.impressora nome da impressora (allowlist)
 * @param {string} p.nomeDocumento nome exato do arquivo temporario gerado internamente
 * @param {number} p.limiteMs tempo maximo com o job retido antes de considerar sem papel
 * @param {Function} [p.execFileImpl] injetavel para teste
 * @param {Function} [p.dormir] injetavel para teste
 * @param {Function} [p.agora] injetavel para teste
 * @param {Function} [p.log]
 * @returns {Promise<{resultado: 'impresso'|'sem_papel', jobRemovido?: boolean, motivo?: string}>}
 */
async function verificarFila(p) {
  const {
    impressora,
    nomeDocumento,
    limiteMs,
    execFileImpl = execFile,
    dormir = dormirPadrao,
    agora = Date.now,
    log = () => {},
    intervaloMs = INTERVALO_PADRAO_MS,
    janelaVisaoMs = JANELA_VISAO_PADRAO_MS,
  } = p;

  const inicio = agora();
  let jobVisto = null;

  for (;;) {
    let jobs;
    try {
      jobs = await listarJobs(execFileImpl, impressora);
    } catch (erro) {
      log(`verificarFila: falha ao consultar a fila (${erro.message}); mantendo resultado "impresso".`);
      return { resultado: 'impresso', motivo: 'consulta_falhou' };
    }

    const decorrido = agora() - inicio;
    const achado = jobs.find((j) => j.nome === nomeDocumento);

    if (achado) {
      jobVisto = achado;
      if (decorrido >= limiteMs) {
        return removerSemPapel(execFileImpl, impressora, nomeDocumento, jobVisto, dormir, log);
      }
    } else if (jobVisto) {
      return { resultado: 'impresso', motivo: 'job_saiu_da_fila' };
    } else if (decorrido >= janelaVisaoMs) {
      log('verificarFila: job nao apareceu na fila na janela de observacao; mantendo resultado "impresso".');
      return { resultado: 'impresso', motivo: 'job_nao_visto' };
    }

    await dormir(intervaloMs);
  }
}

async function removerSemPapel(execFileImpl, impressora, nomeDocumento, job, dormir, log) {
  let jobRemovido = false;
  try {
    const removeu = await removerJob(execFileImpl, impressora, job.id, nomeDocumento);
    if (removeu) {
      for (let i = 0; i < CONFIRMACAO_TENTATIVAS; i += 1) {
        const jobs = await listarJobs(execFileImpl, impressora);
        if (!jobs.some((j) => j.nome === nomeDocumento)) {
          jobRemovido = true;
          break;
        }
        await dormir(INTERVALO_PADRAO_MS);
      }
    }
  } catch (erro) {
    log(`verificarFila: erro ao remover job da fila (${erro.message}).`);
  }
  if (!jobRemovido) {
    log('verificarFila: remocao do job nao confirmada.');
  }
  return { resultado: 'sem_papel', jobRemovido };
}

module.exports = { verificarFila, SCRIPT_LISTAR, SCRIPT_REMOVER };
