'use strict';

/**
 * Autenticacao PROPRIA deste servico (nao e o token do totem, nem do
 * Trello, nem do Talent). O front-end do totem envia este token no
 * cabecalho Authorization: Bearer <token>, valor esse que o backend PHP
 * apenas repassa como referencia via IMPRESSAO_LOCAL_TOKEN no .env -- o
 * valor real e definido/gerado aqui, na config local do servico
 * (config/config.json), nunca no repositorio PHP.
 */

const crypto = require('crypto');
const config = require('../config');

/**
 * Compara dois tokens em tempo constante, imune a timing attack.
 *
 * Tecnica escolhida: hash SHA-256 de cada valor antes de comparar com
 * crypto.timingSafeEqual. E o padrao recomendado para Node.js quando os
 * dois valores de entrada podem ter comprimentos diferentes, porque:
 * - timingSafeEqual exige buffers do MESMO comprimento e lanca excecao
 *   caso contrario -- comparar os tokens crus diretamente vazaria o
 *   comprimento do token esperado (via throw/catch) antes mesmo de chegar
 *   na comparacao byte a byte.
 * - Hashear os dois lados para um digest de tamanho fixo (32 bytes)
 *   elimina esse vazamento: os buffers passados a timingSafeEqual tem
 *   sempre o mesmo tamanho, independente do comprimento do token
 *   recebido, entao nao ha branch nem excecao correlacionada ao
 *   comprimento da entrada do cliente.
 */
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
