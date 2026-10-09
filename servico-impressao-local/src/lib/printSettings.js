'use strict';

const PRINT_SETTINGS_PADRAO = 'noscale,portrait,paper=totem';
const PRINT_SETTINGS_TAMANHO_MAX = 100;
const PRINT_SETTINGS_REGEX = /^[A-Za-z0-9,=._ -]*$/;

function validarPrintSettings(valor) {
  if (valor === undefined || valor === null) {
    return PRINT_SETTINGS_PADRAO;
  }
  if (typeof valor !== 'string') {
    throw new Error('Config invalida: "printSettings", quando informado, precisa ser uma string.');
  }
  if (valor.length > PRINT_SETTINGS_TAMANHO_MAX) {
    throw new Error(`Config invalida: "printSettings" excede ${PRINT_SETTINGS_TAMANHO_MAX} caracteres.`);
  }
  if (!PRINT_SETTINGS_REGEX.test(valor)) {
    throw new Error('Config invalida: "printSettings" aceita somente os caracteres A-Z a-z 0-9 , = . _ espaco e hifen.');
  }
  return valor;
}

function montarArgumentosSumatra(caminhoPdf, impressora, printSettings) {
  const argumentos = ['-print-to', impressora];
  if (printSettings) {
    argumentos.push('-print-settings', printSettings);
  }
  argumentos.push('-silent', caminhoPdf);
  return argumentos;
}

module.exports = {
  PRINT_SETTINGS_PADRAO,
  PRINT_SETTINGS_TAMANHO_MAX,
  validarPrintSettings,
  montarArgumentosSumatra,
};
