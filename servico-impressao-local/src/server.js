'use strict';

/**
 * Entrypoint do servico-impressao-local. Bind SEMPRE exclusivo em
 * 127.0.0.1 (nunca 0.0.0.0) -- config.host e fixo por design, nao e uma
 * opcao configuravel (ver src/config.js).
 */

const express = require('express');
const config = require('./config');
const { corsEPna } = require('./middleware/cors');

const rotaSaude = require('./routes/saude');
const rotaImpressoras = require('./routes/impressoras');
const rotaImprimir = require('./routes/imprimir');

const app = express();

app.disable('x-powered-by');
app.use(corsEPna);

app.use(rotaSaude);
app.use(rotaImpressoras);
app.use(rotaImprimir);

app.use((req, res) => {
  res.status(404).json({ erro: 'Rota nao encontrada.' });
});

// eslint-disable-next-line no-unused-vars
app.use((erro, req, res, next) => {
  console.error('server: erro nao tratado:', erro);
  res.status(500).json({ erro: 'Erro interno do servico.' });
});

app.listen(config.porta, config.host, () => {
  console.log(`servico-impressao-local ouvindo em http://${config.host}:${config.porta} (config: ${config.caminhoArquivo})`);
});
