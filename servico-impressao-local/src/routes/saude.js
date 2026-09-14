'use strict';

/**
 * Health check simples. Sem dado sensivel na resposta (nem token, nem
 * nomes de impressora) -- so confirma que o processo esta de pe. Nao
 * exige autenticacao de proposito (mesmo padrao de health check comum),
 * mas continua sujeito a CORS/PNA como qualquer outra rota.
 */

const express = require('express');
const pkg = require('../../package.json');

const router = express.Router();

router.get('/saude', (req, res) => {
  res.status(200).json({
    status: 'ok',
    servico: pkg.name,
    versao: pkg.version,
  });
});

module.exports = router;
