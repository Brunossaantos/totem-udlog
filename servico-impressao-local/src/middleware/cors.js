'use strict';

const config = require('../config');

function corsEPna(req, res, next) {
  const origem = req.headers.origin;
  const origemAutorizada = typeof origem === 'string' && config.origensPermitidas.includes(origem);

  if (origem) {
    if (origemAutorizada) {
      res.setHeader('Access-Control-Allow-Origin', origem);
      res.setHeader('Vary', 'Origin');
    }
  }

  if (req.headers['access-control-request-private-network'] === 'true') {
    if (origemAutorizada) {
      res.setHeader('Access-Control-Allow-Private-Network', 'true');
    }
  }

  if (req.method === 'OPTIONS') {
    res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
    res.setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization');
    res.setHeader('Access-Control-Max-Age', '600');

    if (origem && !origemAutorizada) {
      res.status(403).end();
      return;
    }

    res.status(204).end();
    return;
  }

  if (origem && !origemAutorizada) {
    res.status(403).json({ erro: 'Origem nao autorizada (CORS).' });
    return;
  }

  next();
}

module.exports = { corsEPna };
