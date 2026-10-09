'use strict';

const MAGIC_BYTES_PDF = '%PDF';

function decodificarEValidarPdfBase64(pdfBase64, maxBytesDecodificado) {
  if (typeof pdfBase64 !== 'string' || pdfBase64.trim().length === 0) {
    return { ok: false, erro: 'Campo "pdf_base64" ausente ou vazio.' };
  }

  let buffer;
  try {
    buffer = Buffer.from(pdfBase64, 'base64');
  } catch (erro) {
    return { ok: false, erro: 'Campo "pdf_base64" nao pode ser decodificado como base64.' };
  }

  if (buffer.length === 0) {
    return { ok: false, erro: 'Campo "pdf_base64" decodificado resultou em conteudo vazio.' };
  }

  if (buffer.length > maxBytesDecodificado) {
    return {
      ok: false,
      erro: `PDF decodificado (${buffer.length} bytes) excede o limite maximo configurado (${maxBytesDecodificado} bytes).`,
    };
  }

  const cabecalho = buffer.subarray(0, MAGIC_BYTES_PDF.length).toString('ascii');
  if (cabecalho !== MAGIC_BYTES_PDF) {
    return { ok: false, erro: 'Conteudo decodificado nao comeca com os magic bytes "%PDF" -- nao parece ser um PDF valido.' };
  }

  return { ok: true, buffer };
}

module.exports = { decodificarEValidarPdfBase64 };
