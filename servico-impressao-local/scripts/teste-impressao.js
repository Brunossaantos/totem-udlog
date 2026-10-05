'use strict';

/**
 * Teste de impressao do servico local (rodar no mini PC, com o servico ativo).
 * Verificacao (so GET /saude e /impressoras, NAO imprime): npm run teste:impressao
 * Imprime 1 etiqueta de TESTE (sem dado pessoal, sem retry):   npm run teste:impressao -- --imprimir
 * Tamanho da pagina do PDF (mm, padrao 80 x 50; largura x altura/comprimento):
 *   npm run teste:impressao -- --imprimir --largura=80 --altura=50
 * Enviar um PDF pronto em vez do gerado: --imprimir --pdf=<caminho> (ignora --largura/--altura)
 * O token e lido de config/config.json e nunca e exibido.
 */

const http = require('http');
const config = require('../src/config');

const HOST = config.host;
const PORTA = config.porta;
const IMPRESSORA = config.impressorasPermitidas[0];
const IMPRIMIR = process.argv.includes('--imprimir');

function lerMm(nome, padrao) {
  const arg = process.argv.find((a) => a.startsWith(`--${nome}=`));
  if (!arg) return padrao;
  const v = Number(arg.split('=')[1]);
  if (!Number.isFinite(v) || v < 20 || v > 300) {
    console.log(`[FALHA] --${nome} invalido (use numero entre 20 e 300 mm). Nada foi impresso.`);
    process.exit(1);
  }
  return v;
}
const LARGURA_MM = lerMm('largura', 80);
const ALTURA_MM = lerMm('altura', 50);
// Margem sobre o timeout do servico, para o servico responder 504 antes do cliente desistir.
const TIMEOUT_CLIENTE_MS = config.timeoutMs + 10000;

function requisitar(metodo, caminho, { autenticado = false, corpo = null, timeoutMs = 10000 } = {}) {
  return new Promise((resolve) => {
    const payload = corpo ? Buffer.from(JSON.stringify(corpo), 'utf8') : null;
    const headers = {};
    if (autenticado) headers.Authorization = `Bearer ${config.token}`;
    if (payload) {
      headers['Content-Type'] = 'application/json';
      headers['Content-Length'] = payload.length;
    }

    const req = http.request({ host: HOST, port: PORTA, path: caminho, method: metodo, headers }, (res) => {
      const partes = [];
      res.on('data', (c) => partes.push(c));
      res.on('end', () => {
        const texto = Buffer.concat(partes).toString('utf8');
        let json = null;
        try { json = JSON.parse(texto); } catch (e) { /* resposta nao JSON */ }
        resolve({ http: res.statusCode, json, texto });
      });
    });

    req.setTimeout(timeoutMs, () => {
      req.destroy();
      resolve({ erro: 'TIMEOUT' });
    });
    req.on('error', (e) => resolve({ erro: e.code || 'ERRO_REDE' }));
    if (payload) req.write(payload);
    req.end();
  });
}

/** Gera um PDF minimo valido (LARGURA_MM x ALTURA_MM, so ASCII) com moldura e texto de teste. */
function gerarPdfTeste(linhas) {
  const pt = (mm) => (mm * 72 / 25.4);
  const larg = pt(LARGURA_MM).toFixed(2);
  const alt = pt(ALTURA_MM).toFixed(2);
  const m = 4; // moldura a ~1,4 mm da borda
  const esc = (s) => s.replace(/[\\()]/g, '\\$&');
  const y0 = pt(ALTURA_MM) - 22;
  let conteudo = `0.75 w ${m} ${m} ${(pt(LARGURA_MM) - 2 * m).toFixed(2)} ${(pt(ALTURA_MM) - 2 * m).toFixed(2)} re S
`;
  conteudo += `BT /F1 9 Tf 12 ${y0.toFixed(2)} Td 12 TL
`;
  linhas.forEach((l) => { conteudo += `(${esc(l)}) Tj T*
`; });
  conteudo += 'ET';

  const objs = [
    '<< /Type /Catalog /Pages 2 0 R >>',
    '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    `<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ${larg} ${alt}] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>`,
    `<< /Length ${Buffer.byteLength(conteudo, 'latin1')} >>\nstream\n${conteudo}\nendstream`,
    '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
  ];

  let pdf = '%PDF-1.4\n';
  const offsets = [];
  objs.forEach((o, i) => {
    offsets.push(Buffer.byteLength(pdf, 'latin1'));
    pdf += `${i + 1} 0 obj\n${o}\nendobj\n`;
  });
  const xref = Buffer.byteLength(pdf, 'latin1');
  pdf += `xref\n0 ${objs.length + 1}\n0000000000 65535 f \n`;
  offsets.forEach((off) => { pdf += `${String(off).padStart(10, '0')} 00000 n \n`; });
  pdf += `trailer\n<< /Size ${objs.length + 1} /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF\n`;
  return Buffer.from(pdf, 'latin1');
}

function dataHora() {
  const d = new Date();
  const p = (n) => String(n).padStart(2, '0');
  return `${p(d.getDate())}/${p(d.getMonth() + 1)}/${d.getFullYear()} ${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`;
}

async function main() {
  console.log(`Servico: http://${HOST}:${PORTA} | Impressora da allowlist: "${IMPRESSORA}"`);

  // a/b) saude
  const saude = await requisitar('GET', '/saude');
  if (saude.erro) {
    console.log(`[FALHA] GET /saude: ${saude.erro}. Servico fora do ar ou porta incorreta. Nada foi impresso.`);
    process.exitCode = 1;
    return;
  }
  console.log(`[${saude.http === 200 ? 'OK' : 'FALHA'}] GET /saude -> HTTP ${saude.http} ${saude.texto}`);

  // b) impressoras
  const imp = await requisitar('GET', '/impressoras', { autenticado: true });
  if (imp.erro) {
    console.log(`[FALHA] GET /impressoras: ${imp.erro}.`);
    process.exitCode = 1;
    return;
  }
  if (imp.http === 401) {
    console.log('[FALHA] GET /impressoras -> HTTP 401: token invalido (config/config.json diferente do token do servico em execucao; reinicie o servico se o token foi alterado).');
    process.exitCode = 1;
    return;
  }
  const lista = imp.json && Array.isArray(imp.json.impressoras) ? imp.json.impressoras : [];
  const achou = lista.some((i) => i.nome === IMPRESSORA);
  console.log(`[${imp.http === 200 ? 'OK' : 'FALHA'}] GET /impressoras -> HTTP ${imp.http}; impressoras permitidas visiveis: ${lista.map((i) => i.nome).join(', ') || '(nenhuma)'}`);
  if (!achou) {
    console.log(`[FALHA] "${IMPRESSORA}" nao apareceu na lista (driver nao instalado/nome diferente no Windows). Nada foi impresso.`);
    process.exitCode = 1;
    return;
  }
  console.log(`[OK] "${IMPRESSORA}" disponivel.`);

  if (!IMPRIMIR) {
    console.log('\nNAO IMPRIMIU (modo verificacao). Para imprimir 1 etiqueta de teste: npm run teste:impressao -- --imprimir');
    return;
  }

  // c) imprimir (UMA tentativa, sem retry)
  const agora = dataHora();
  const identificador = `teste-impressao-${Date.now()}`;
  const argPdf = process.argv.find((a) => a.startsWith('--pdf='));
  const pdf = argPdf
    ? require('fs').readFileSync(argPdf.slice('--pdf='.length))
    : gerarPdfTeste(['TESTE DE IMPRESSAO', `${LARGURA_MM}x${ALTURA_MM} mm`, 'UDLOG - NAO UTILIZAR', agora]);
  console.log(`\nEnviando 1 etiqueta de teste ${LARGURA_MM}x${ALTURA_MM} mm (identificador ${identificador})...`);

  const r = await requisitar('POST', '/imprimir', {
    autenticado: true,
    timeoutMs: TIMEOUT_CLIENTE_MS,
    corpo: { pdf_base64: pdf.toString('base64'), impressora: IMPRESSORA, identificador },
  });

  // d) interpretacao
  if (r.erro === 'TIMEOUT') {
    console.log('[INDETERMINADO] Sem resposta no prazo. A etiqueta pode ou nao ter saido. NAO repita automaticamente; confira a impressora.');
  } else if (r.erro) {
    console.log(`[FALHA] Servico fora do ar durante o envio (${r.erro}). Estado indeterminado: confira a impressora antes de repetir.`);
  } else {
    console.log(`POST /imprimir -> HTTP ${r.http} ${r.texto}`);
    const st = r.json && r.json.status;
    if (r.http === 200 && st === 'impresso') console.log('[OK] Servico confirmou a impressao. Confira a etiqueta fisica.');
    else if (r.http === 200 && st === 'ja_impresso') console.log('[OK] Identificador ja impresso (duplicata ignorada).');
    else if (st === 'indeterminado' || r.http === 504) console.log('[INDETERMINADO] Timeout de impressao no servico. Confira a impressora; nao repita automaticamente.');
    else if (r.http === 401) console.log('[FALHA] Token invalido (401).');
    else if (r.http === 403) console.log('[FALHA] Impressora fora da allowlist (403).');
    else if (r.http === 503) console.log('[FALHA] Servico ocupado com outro job (503). Aguarde e rode de novo manualmente.');
    else console.log('[FALHA] Resposta inesperada do servico (ver HTTP acima).');
  }
  if (!(r.http === 200)) process.exitCode = 1;
}

main();
