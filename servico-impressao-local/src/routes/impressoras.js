'use strict';

/**
 * Lista as impressoras instaladas no Windows do mini PC. A escolha de
 * QUAL impressora usar e responsabilidade do front-end do totem
 * (localStorage) -- este servico so informa o que existe, nao persiste
 * nenhuma preferencia.
 *
 * A lista devolvida e SEMPRE filtrada pela allowlist
 * config.impressorasPermitidas (comparacao exata de nome, mesmo criterio
 * usado na revalidacao de POST /imprimir) -- impressoras virtuais/
 * interativas (Microsoft Print to PDF, XPS, Fax, OneNote, etc.) ou
 * qualquer impressora fisica nao autorizada NUNCA aparecem aqui, mesmo que
 * existam instaladas no Windows.
 */

const express = require('express');
const { getPrinters } = require('pdf-to-printer');
const config = require('../config');
const { autenticar } = require('../middleware/auth');

const router = express.Router();

const IMPRESSORAS_PERMITIDAS = new Set(config.impressorasPermitidas);

router.get('/impressoras', autenticar, async (req, res) => {
  try {
    const impressoras = await getPrinters();
    res.status(200).json({
      impressoras: impressoras
        .filter((impressora) => IMPRESSORAS_PERMITIDAS.has(impressora.name))
        .map((impressora) => ({
          nome: impressora.name,
          padrao: Boolean(impressora.isDefault ?? impressora.default ?? false),
        })),
    });
  } catch (erro) {
    console.error('impressoras: falha ao listar impressoras:', erro);
    res.status(500).json({ erro: 'Nao foi possivel listar as impressoras.' });
  }
});

module.exports = router;
