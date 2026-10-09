'use strict';

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

app.use((erro, req, res, next) => {
  console.error('server: erro nao tratado:', erro);
  res.status(500).json({ erro: 'Erro interno do servico.' });
});

app.listen(config.porta, config.host, () => {
  console.log(`servico-impressao-local ouvindo em http://${config.host}:${config.porta} (config: ${config.caminhoArquivo})`);
});
