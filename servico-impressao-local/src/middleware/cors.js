'use strict';

/**
 * CORS + Private Network Access (PNA), restritos a config.origensPermitidas
 * (lista configuravel em config/config.json, NUNCA hardcoded aqui).
 *
 * PNA: quando o totem e servido via HTTPS/dominio publico e o navegador
 * detecta que o destino da requisicao e um endereco privado/loopback
 * (127.0.0.1), o Chromium manda um preflight OPTIONS com o cabecalho
 * Access-Control-Request-Private-Network: true e exige de volta
 * Access-Control-Allow-Private-Network: true, alem dos cabecalhos normais
 * de CORS. Sem isso, a chamada do navegador para este servico local e
 * bloqueada mesmo com CORS "correto".
 *
 * PENDENCIA (nao inventar): a URL real de producao/dev do totem que deve
 * entrar em config.origensPermitidas ainda nao foi fixada no projeto.
 * Enquanto config.origensPermitidas estiver vazia, o servico so responde
 * (sem erro de CORS) a chamadas SEM cabecalho Origin (ex.: curl/PowerShell
 * rodando no proprio mini PC para diagnostico) -- qualquer chamada de
 * navegador com Origin e rejeitada, fail-closed.
 */

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
    // So confirma PNA quando a origem tambem e autorizada -- nunca liberar
    // PNA para uma origem que nao passaria no CORS de qualquer forma.
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
