'use strict';

const { execFile } = require('child_process');

const SCRIPT_LISTAR_IMPRESSORAS = [
  "$ErrorActionPreference='Stop';",
  '[Console]::OutputEncoding=[System.Text.UTF8Encoding]::new($false);',
  '$p=@(Get-CimInstance Win32_Printer -Property Name,Default | Select-Object Name,Default);',
  'if($p.Count -eq 0){exit 0};',
  'ConvertTo-Json -Compress -InputObject $p',
].join(' ');

const TIMEOUT_MS = 10000;

function encerrarPorPid(pid, execFileImpl) {
  return new Promise((resolve) => {
    execFileImpl('taskkill', ['/PID', String(pid), '/T', '/F'], { windowsHide: true }, () => resolve());
  });
}

function executar(execFileImpl, timeoutMs) {
  return new Promise((resolve, reject) => {
    let finalizado = false;
    let temporizador = null;

    const processo = execFileImpl(
      'powershell.exe',
      ['-NoProfile', '-NonInteractive', '-Command', SCRIPT_LISTAR_IMPRESSORAS],
      { windowsHide: true, maxBuffer: 1024 * 1024, encoding: 'utf8' },
      (erro, stdout) => {
        if (finalizado) return;
        finalizado = true;
        clearTimeout(temporizador);
        if (erro) {
          reject(new Error('powershell_falhou'));
          return;
        }
        resolve(String(stdout || ''));
      }
    );

    temporizador = setTimeout(async () => {
      if (finalizado) return;
      finalizado = true;
      if (processo && processo.pid) {
        await encerrarPorPid(processo.pid, execFileImpl);
      }
      reject(new Error('timeout_listagem'));
    }, timeoutMs);
  });
}

function parsearSaida(texto) {
  const limpo = String(texto || '').replace(/^﻿/, '').trim();
  if (!limpo) {
    return [];
  }
  let dados;
  try {
    dados = JSON.parse(limpo);
  } catch (e) {
    throw new Error('json_invalido');
  }
  const lista = Array.isArray(dados) ? dados : [dados];
  return lista.map((item) => {
    if (!item || typeof item !== 'object' || typeof item.Name !== 'string' || !item.Name) {
      throw new Error('item_invalido');
    }
    return { name: item.Name, isDefault: item.Default === true };
  });
}

async function listarImpressoras(opcoes = {}) {
  const { execFileImpl = execFile, timeoutMs = TIMEOUT_MS } = opcoes;
  const saida = await executar(execFileImpl, timeoutMs);
  return parsearSaida(saida);
}

module.exports = { listarImpressoras, parsearSaida, SCRIPT_LISTAR_IMPRESSORAS };
