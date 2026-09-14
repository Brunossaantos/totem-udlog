'use strict';

/**
 * Config centralizada do servico-impressao-local. NENHUM outro arquivo do
 * projeto deve ler process.env ou o arquivo de config diretamente --
 * tudo passa por aqui, e tudo falha alto (fail-closed) se algo essencial
 * de seguranca estiver ausente ou invalido. Nada de numero magico
 * espalhado pelo resto do codigo: quem precisar de porta, token, limite
 * de tamanho, origens permitidas ou parametros de idempotencia importa
 * este modulo.
 */

const fs = require('fs');
const path = require('path');

const CAMINHO_CONFIG = process.env.CONFIG_PATH
  || path.join(__dirname, '..', 'config', 'config.json');

function carregarArquivoConfig() {
  if (!fs.existsSync(CAMINHO_CONFIG)) {
    throw new Error(
      `Arquivo de config nao encontrado em ${CAMINHO_CONFIG}. `
      + 'Copie config/config.example.json para config/config.json e preencha os valores reais.'
    );
  }

  let bruto;
  try {
    bruto = fs.readFileSync(CAMINHO_CONFIG, 'utf8');
  } catch (erro) {
    throw new Error(`Nao foi possivel ler ${CAMINHO_CONFIG}: ${erro.message}`);
  }

  try {
    return JSON.parse(bruto);
  } catch (erro) {
    throw new Error(`${CAMINHO_CONFIG} nao e um JSON valido: ${erro.message}`);
  }
}

function validarPorta(valor) {
  const porta = Number(valor);
  if (!Number.isInteger(porta) || porta < 1 || porta > 65535) {
    throw new Error('Config invalida: "porta" precisa ser um inteiro entre 1 e 65535.');
  }
  return porta;
}

function validarToken(valor) {
  if (typeof valor !== 'string' || valor.trim().length < 16) {
    throw new Error(
      'Config invalida: "token" precisa ser uma string com pelo menos 16 caracteres. '
      + 'Gere um valor real com: node -e "console.log(require(\'crypto\').randomBytes(32).toString(\'hex\'))"'
    );
  }
  if (valor === 'SUBSTITUA_POR_UM_TOKEN_ALEATORIO_LONGO_GERADO_COM_CRYPTO') {
    throw new Error('Config invalida: "token" ainda esta com o valor de exemplo do config.example.json. Gere um token real.');
  }
  return valor;
}

function validarMaxBytes(valor) {
  const max = Number(valor);
  if (!Number.isInteger(max) || max <= 0) {
    throw new Error('Config invalida: "maxPdfBytesDecodificado" precisa ser um inteiro positivo (bytes).');
  }
  return max;
}

function validarOrigensPermitidas(valor) {
  if (!Array.isArray(valor) || !valor.every((item) => typeof item === 'string')) {
    throw new Error('Config invalida: "origensPermitidas" precisa ser uma lista de strings (pode ser vazia).');
  }
  return valor;
}

function validarImpressorasPermitidas(valor) {
  if (
    !Array.isArray(valor)
    || valor.length === 0
    || !valor.every((item) => typeof item === 'string' && item.trim().length > 0)
  ) {
    throw new Error(
      'Config invalida: "impressorasPermitidas" precisa ser uma lista nao vazia com o nome exato '
      + '(igual ao driver do Windows) de cada impressora fisica autorizada a imprimir. '
      + 'Nunca deixe vazio -- sem allowlist, nenhuma impressora e listada nem aceita.'
    );
  }
  return valor;
}

function validarTimeoutMs(valor) {
  if (valor === undefined || valor === null) {
    return 30000;
  }
  const timeoutMs = Number(valor);
  if (!Number.isInteger(timeoutMs) || timeoutMs <= 0) {
    throw new Error('Config invalida: "timeoutMs", quando informado, precisa ser um inteiro positivo (milissegundos).');
  }
  return timeoutMs;
}

function validarIdempotencia(valor) {
  if (!valor || typeof valor !== 'object') {
    throw new Error('Config invalida: "idempotencia" precisa ser um objeto { ttlMs, maxEntries }.');
  }
  const ttlMs = Number(valor.ttlMs);
  const maxEntries = Number(valor.maxEntries);
  if (!Number.isInteger(ttlMs) || ttlMs <= 0) {
    throw new Error('Config invalida: "idempotencia.ttlMs" precisa ser um inteiro positivo (milissegundos).');
  }
  if (!Number.isInteger(maxEntries) || maxEntries <= 0) {
    throw new Error('Config invalida: "idempotencia.maxEntries" precisa ser um inteiro positivo.');
  }
  return { ttlMs, maxEntries };
}

function construirConfig() {
  const bruto = carregarArquivoConfig();

  return Object.freeze({
    porta: validarPorta(bruto.porta),
    token: validarToken(bruto.token),
    maxPdfBytesDecodificado: validarMaxBytes(bruto.maxPdfBytesDecodificado),
    origensPermitidas: Object.freeze(validarOrigensPermitidas(bruto.origensPermitidas)),
    impressorasPermitidas: Object.freeze(validarImpressorasPermitidas(bruto.impressorasPermitidas)),
    timeoutMs: validarTimeoutMs(bruto.timeoutMs),
    idempotencia: Object.freeze(validarIdempotencia(bruto.idempotencia)),
    // Bind sempre exclusivo em loopback -- nunca configuravel para 0.0.0.0,
    // de proposito (requisito de seguranca fixo, nao uma opcao de config).
    host: '127.0.0.1',
    caminhoArquivo: CAMINHO_CONFIG,
  });
}

module.exports = construirConfig();
