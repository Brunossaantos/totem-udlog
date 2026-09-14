'use strict';

/**
 * Registra este servico como um Servico do Windows nativo (via
 * node-windows), garantindo que ele suba sozinho junto com o Windows no
 * mini PC do totem, sem depender de nenhum usuario logar na sessao.
 *
 * NAO EXECUTAR neste ambiente de desenvolvimento -- este script so faz
 * sentido rodando com privilegio de Administrador no mini PC de producao,
 * apos `npm install` real ter sido feito naquela maquina. Ver
 * servico-impressao-local/README.md, secao "Inicializacao automatica".
 *
 * Uso (no mini PC, PowerShell como Administrador):
 *   cd caminho\para\servico-impressao-local
 *   npm run instalar-servico-windows
 */

const path = require('path');
const { Service } = require('node-windows');

const servico = new Service({
  name: 'UDLOG Servico Impressao Local',
  description: 'Servico local do totem UDLOG que recebe PDFs prontos e imprime na impressora fisica do mini PC.',
  script: path.join(__dirname, '..', 'src', 'server.js'),
  nodeOptions: [],
});

servico.on('install', () => {
  console.log('Servico instalado. Iniciando...');
  servico.start();
});

servico.on('alreadyinstalled', () => {
  console.log('Servico ja estava instalado.');
});

servico.on('start', () => {
  console.log('Servico iniciado com sucesso.');
});

servico.on('error', (erro) => {
  console.error('Erro ao instalar/iniciar o servico:', erro);
});

servico.install();
