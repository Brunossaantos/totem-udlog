'use strict';

/**
 * Remove o Servico do Windows registrado por instalar-servico-windows.js.
 * NAO EXECUTAR neste ambiente de desenvolvimento -- ver README.md.
 *
 * Uso (no mini PC, PowerShell como Administrador):
 *   npm run desinstalar-servico-windows
 */

const path = require('path');
const { Service } = require('node-windows');

const servico = new Service({
  name: 'UDLOG Servico Impressao Local',
  script: path.join(__dirname, '..', 'src', 'server.js'),
});

servico.on('uninstall', () => {
  console.log('Servico removido.');
});

servico.on('error', (erro) => {
  console.error('Erro ao remover o servico:', erro);
});

servico.uninstall();
