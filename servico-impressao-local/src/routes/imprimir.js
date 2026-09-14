'use strict';

/**
 * Recebe um PDF ja pronto (gerado por FPDF no backend PHP) em base64 e
 * manda para a impressora fisica indicada pelo nome. Este servico NUNCA
 * gera conteudo de etiqueta e NUNCA aceita um caminho de arquivo vindo da
 * requisicao -- so o conteudo binario em base64 do corpo do POST.
 *
 * Corpo esperado (JSON):
 * {
 *   "pdf_base64": "<base64 do PDF, sem prefixo data:...>",
 *   "impressora": "<nome exato da impressora no Windows>",
 *   "identificador": "<id unico do job, gerado pelo backend PHP>"
 * }
 *
 * Seguranca/robustez desta rota (ver docs/handoffs da demanda
 * impressao-etiqueta-teste, resultado dos testes de 2026-09-14):
 *  - "impressora" e SEMPRE revalidada contra a allowlist
 *    config.impressorasPermitidas antes de imprimir -- mesmo que o
 *    front-end so ofereca impressoras permitidas, esta e a defesa em
 *    profundidade contra uma chamada direta a este endpoint;
 *  - so um job de impressao roda por vez neste servico (mutex em memoria)
 *    -- evita sobreposicao no mesmo dispositivo USB fisico;
 *  - o processo de impressao tem um timeout configuravel
 *    (config.timeoutMs); ao estourar, so o PID daquele job especifico e
 *    encerrado (nunca por nome de processo) e o identificador fica
 *    marcado como "indeterminado" -- protegido contra reprocessamento,
 *    mas sinalizado ao front-end como resultado desconhecido (nao
 *    "impresso"), para nunca informar sucesso sem certeza.
 */

const os = require('os');
const path = require('path');
const crypto = require('crypto');
const fs = require('fs/promises');
const express = require('express');

const config = require('../config');
const { autenticar } = require('../middleware/auth');
const { decodificarEValidarPdfBase64 } = require('../lib/validarPdf');
const { IdempotenciaStore } = require('../lib/idempotencia');
const { imprimirComTimeout } = require('../lib/imprimirComTimeout');

const router = express.Router();

// Uma unica instancia em memoria para todo o processo -- e por isso que
// TTL/maxEntries vem da config central, nao de valores soltos aqui.
const idempotencia = new IdempotenciaStore(config.idempotencia.ttlMs, config.idempotencia.maxEntries);

const IMPRESSORAS_PERMITIDAS = new Set(config.impressorasPermitidas);

// Mutex simples de processo -- este servico roda como um unico processo
// Node por dispositivo (mini PC), entao uma flag em memoria e suficiente
// para garantir "um job de impressao por vez" na impressora USB fisica.
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

  // Defesa em profundidade: revalida a allowlist mesmo que o front-end so
  // ofereca impressoras permitidas. Nunca lista as impressoras reais do
  // driver nesta mensagem de erro.
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

  // Nome de arquivo temporario sempre gerado internamente (uuid), nunca a
  // partir de "identificador" ou "impressora" -- evita qualquer risco de
  // path traversal vindo da requisicao.
  const nomeArquivoTemp = `impressao-local-udlog-${crypto.randomUUID()}.pdf`;
  const caminhoTemp = path.join(os.tmpdir(), nomeArquivoTemp);

  try {
    await fs.writeFile(caminhoTemp, resultadoPdf.buffer);
    await imprimirComTimeout(caminhoTemp, impressora, config.timeoutMs);

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
      // Melhor esforco -- arquivo temporario orfao nao e um problema de
      // seguranca (sem dado alem do PDF ja impresso), so limpeza de disco.
    });
  }
});

module.exports = router;
