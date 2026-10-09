'use strict';

const os = require('os');
const path = require('path');
const crypto = require('crypto');
const fs = require('fs/promises');
const express = require('express');

const config = require('../config');
const { autenticar } = require('../middleware/auth');
const { decodificarEValidarPdfBase64 } = require('../lib/validarPdf');
const { IdempotenciaStore } = require('../lib/idempotencia');
const imprimirModulo = require('../lib/imprimirComTimeout');
const filaModulo = require('../lib/verificarFila');

const router = express.Router();

const idempotencia = new IdempotenciaStore(config.idempotencia.ttlMs, config.idempotencia.maxEntries);

const IMPRESSORAS_PERMITIDAS = new Set(config.impressorasPermitidas);

let jobEmAndamento = false;

const IDENTIFICADOR_MAX_TAMANHO = 128;
const IDENTIFICADOR_REGEX = /^[A-Za-z0-9_-]+$/;
const IMPRESSORA_NOME_MAX_TAMANHO = 256;

function validarCorpo(body) {
  if (!body || typeof body !== 'object') {
    return 'Corpo da requisicao ausente ou invalido (esperado JSON).';
  }

  const { impressora, identificador } = body;

  if (typeof impressora !== 'string' || impressora.trim().length === 0) {
    return 'Campo "impressora" ausente ou vazio -- deve ser o nome exato da impressora, nunca um caminho.';
  }
  if (impressora.length > IMPRESSORA_NOME_MAX_TAMANHO) {
    return `Campo "impressora" excede o tamanho maximo de ${IMPRESSORA_NOME_MAX_TAMANHO} caracteres.`;
  }
  if (impressora.includes('/') || impressora.includes('\\')) {
    return 'Campo "impressora" deve ser apenas o nome da impressora, nunca um caminho de arquivo.';
  }

  if (typeof identificador !== 'string' || identificador.trim().length === 0) {
    return 'Campo "identificador" ausente ou vazio -- deve ser um ID unico de job gerado pelo backend PHP.';
  }
  if (identificador.length > IDENTIFICADOR_MAX_TAMANHO || !IDENTIFICADOR_REGEX.test(identificador)) {
    return `Campo "identificador" invalido -- use apenas letras, numeros, "-" e "_", ate ${IDENTIFICADOR_MAX_TAMANHO} caracteres.`;
  }

  return null;
}

router.post('/imprimir', autenticar, express.json({ limit: '32mb' }), async (req, res) => {
  const erroValidacao = validarCorpo(req.body);
  if (erroValidacao) {
    res.status(400).json({ erro: erroValidacao });
    return;
  }

  const { impressora, identificador } = req.body;

  const statusExistente = idempotencia.obterStatus(identificador);
  if (statusExistente === 'impresso') {
    res.status(200).json({
      status: 'ja_impresso',
      identificador,
      mensagem: 'Este identificador ja foi impresso anteriormente -- impressao duplicada ignorada.',
    });
    return;
  }
  if (statusExistente === 'indeterminado') {
    res.status(200).json({
      status: 'indeterminado',
      identificador,
      mensagem: 'Resultado deste job nao pode ser confirmado -- gere uma nova etiqueta antes de tentar novamente.',
    });
    return;
  }

  if (!IMPRESSORAS_PERMITIDAS.has(impressora)) {
    res.status(403).json({ erro: 'Impressora nao permitida.' });
    return;
  }

  const resultadoPdf = decodificarEValidarPdfBase64(req.body.pdf_base64, config.maxPdfBytesDecodificado);
  if (!resultadoPdf.ok) {
    res.status(400).json({ erro: resultadoPdf.erro });
    return;
  }

  if (jobEmAndamento) {
    res.status(503).json({ erro: 'Outro job de impressao esta em andamento neste dispositivo. Tente novamente em instantes.' });
    return;
  }
  jobEmAndamento = true;

  const nomeArquivoTemp = `impressao-local-udlog-${crypto.randomUUID()}.pdf`;
  const caminhoTemp = path.join(os.tmpdir(), nomeArquivoTemp);

  try {
    await fs.writeFile(caminhoTemp, resultadoPdf.buffer);
    await imprimirModulo.imprimirComTimeout(caminhoTemp, impressora, config.timeoutMs, { printSettings: config.printSettings });

    if (config.deteccaoSemPapel) {
      const verificacao = await filaModulo.verificarFila({
        impressora,
        nomeDocumento: nomeArquivoTemp,
        limiteMs: config.semPapelTimeoutMs,
        log: (msg) => console.warn(msg),
      });
      if (verificacao.resultado === 'sem_papel') {
        res.status(409).json({
          status: 'sem_papel',
          identificador,
          job_removido: verificacao.jobRemovido === true,
        });
        return;
      }
    }

    idempotencia.marcarProcessado(identificador);

    res.status(200).json({ status: 'impresso', identificador });
  } catch (erro) {
    if (erro && erro.timeout) {
      idempotencia.marcarIndeterminado(identificador);
      res.status(504).json({
        status: 'indeterminado',
        identificador,
        codigo: 'IMPRESSAO_TIMEOUT',
        erro: 'Tempo limite de impressao excedido -- resultado indeterminado.',
      });
      return;
    }

    console.error(`imprimir: falha ao imprimir identificador=${identificador}:`, erro);
    res.status(500).json({ erro: 'Falha ao imprimir.' });
  } finally {
    jobEmAndamento = false;
    fs.unlink(caminhoTemp).catch(() => {
    });
  }
});

module.exports = router;
