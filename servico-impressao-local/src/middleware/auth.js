'use strict';

const crypto = require('crypto');
const config = require('../config');

function tokensIguais(tokenRecebido, tokenEsperado) {
  const hashRecebido = crypto.createHash('sha256').update(String(tokenRecebido)).digest();
  const hashEsperado = crypto.createHash('sha256').update(String(tokenEsperado)).digest();
  return crypto.timingSafeEqual(hashRecebido, hashEsperado);
}

function autenticar(req, res, next) {
  const cabecalho = req.headers.authorization || '';
  const partes = cabecalho.split(' ');
  const token = partes.length === 2 && partes[0] === 'Bearer' ? partes[1] : null;

  if (!token || !tokensIguais(token, config.token)) {
    res.status(401).json({ erro: 'Token de autenticacao ausente ou invalido.' });
    return;
  }

  next();
}

module.exports = { autenticar };
