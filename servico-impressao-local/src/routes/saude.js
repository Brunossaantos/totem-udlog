'use strict';

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
