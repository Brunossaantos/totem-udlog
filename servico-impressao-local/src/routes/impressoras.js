'use strict';

const express = require('express');
const listador = require('../lib/listarImpressoras');
const config = require('../config');
const { autenticar } = require('../middleware/auth');

const router = express.Router();

const IMPRESSORAS_PERMITIDAS = new Set(config.impressorasPermitidas);

router.get('/impressoras', autenticar, async (req, res) => {
  try {
    const impressoras = await listador.listarImpressoras();
    res.status(200).json({
      impressoras: impressoras
        .filter((impressora) => IMPRESSORAS_PERMITIDAS.has(impressora.name))
        .map((impressora) => ({
          nome: impressora.name,
          padrao: impressora.isDefault === true,
        })),
    });
  } catch (erro) {
    console.error(`impressoras: falha ao listar impressoras (${erro && erro.name}: ${erro && erro.message}).`);
    res.status(500).json({ erro: 'Nao foi possivel listar as impressoras.' });
  }
});

module.exports = router;
