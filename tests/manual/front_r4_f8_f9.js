// Teste front da rodada corretiva R4 (hardening-revisao-notas-e-cliente): F8 e F9.
// Chrome headless (puppeteer), api e Tesseract STUBADOS, so rede local (qualquer outro
// host e abortado e conta como falha). Sem banco, sem Talent/VIO/impressao.
//   node tests/manual/front_r4_f8_f9.js
// Variaveis opcionais (usadas pelas provas negativas, que servem COPIAS mutadas):
//   APPJS=<arquivo> APPCSS=<arquivo> CHROME=<exe>
const http = require('http');
const fs = require('fs');
const path = require('path');
const os = require('os');

let puppeteer;
try { puppeteer = require('puppeteer'); } catch (e) {
    puppeteer = require(path.join(process.env.APPDATA || os.homedir(), 'npm', 'node_modules', 'puppeteer'));
}
const ASSETS = path.join(__dirname, '..', '..', 'public', 'totem', 'assets');
const APPJS = process.env.APPJS || path.join(ASSETS, 'app.js');
const APPCSS = process.env.APPCSS || path.join(ASSETS, 'app.css');
const CHROME = process.env.CHROME || (fs.existsSync('C:/Program Files/Google/Chrome/Application/chrome.exe') ? 'C:/Program Files/Google/Chrome/Application/chrome.exe' : undefined);

const INDEX = `<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<link rel="stylesheet" href="assets/app.css"></head>
<body data-totem-token="TOKEN-FALSO" data-totem-nome="r4"><div id="app"></div>
<script type="application/json" id="lgpd-termo-dados">{"versao":"v","hash":"h","texto":"t"}</script>
<script type="application/json" id="assets-versoes">{}</script>
<script src="assets/app.js"></script></body></html>`;

let PORT = 0;
const server = http.createServer((req, res) => {
    const u = new URL(req.url, 'http://x');
    if (u.pathname === '/') { res.setHeader('Content-Type', 'text/html; charset=utf-8'); return res.end(INDEX); }
    if (u.pathname === '/assets/app.js') { res.setHeader('Content-Type', 'text/javascript'); return res.end(fs.readFileSync(APPJS)); }
    if (u.pathname === '/assets/app.css') { res.setHeader('Content-Type', 'text/css'); return res.end(fs.readFileSync(APPCSS)); }
    res.statusCode = 404; res.end('nf');
});

let passou = 0, falhou = 0; const falhas = [];
function ok(cond, msg) { if (cond) passou++; else { falhou++; falhas.push(msg); console.log('  FALHA: ' + msg); } }
function titulo(t) { console.log('\n== ' + t); }
const espera = ms => new Promise(r => setTimeout(r, ms));
function resp(status, sucesso, corpo) { return { status, contentType: 'application/json', body: JSON.stringify(sucesso ? { sucesso: true, dados: corpo } : corpo) }; }

function novoSrv() { return { notas: [], proxId: 1, calls: [], externos: 0, concluir422: null }; }
async function handlerApi(srv, arquivo, acao, b) {
    srv.calls.push({ arquivo, acao, body: b });
    if (arquivo === 'nota.php') {
        if (acao === 'processar') {
            let ordem = 1; while (srv.notas.some(n => n.ordem === ordem)) ordem++;
            const n = { id_nota: srv.proxId++, ordem, numero: null }; srv.notas.push(n);
            return resp(200, true, { cliente_identificado: false, cliente: null, id_nota: n.id_nota, ordem });
        }
        if (acao === 'definir-numero') {
            const n = srv.notas.find(x => x.id_nota === b.id_nota);
            if (!n) return resp(404, false, { sucesso: false, erro: 'Nota nao encontrada' });
            n.numero = String(parseInt(b.numero, 10));
            return resp(200, true, { numero_nota: n.numero, id_nota: n.id_nota, ordem: n.ordem });
        }
        if (acao === 'identificar-cliente') return resp(200, true, { status: 'NAO_IDENTIFICADA', cliente_atendimento: { estado: 'NAO_IDENTIFICADO', cliente: null } });
        if (acao === 'excluir') {
            const i = srv.notas.findIndex(x => x.id_nota === b.id_nota);
            const ja = i < 0; if (!ja) srv.notas.splice(i, 1);
            return resp(200, true, { excluida: !ja, ja_excluida: ja, id_nota: b.id_nota, total_notas: srv.notas.length });
        }
    }
    if (arquivo === 'atendimento.php' && acao === 'concluir-digitalizacao') {
        if (srv.concluir422) return resp(422, false, { sucesso: false, erro: 'x', codigo: 'NOTAS_SEM_NUMERO', dados: { ordens_pendentes: srv.concluir422, total_notas: srv.notas.length, total_pendentes: srv.concluir422.length } });
        return resp(200, true, { proxima_tela: 'rec_cnh', etapa: 'rec_cnh' });
    }
    return resp(200, true, {});
}

async function abrir(browser, viewport, query) {
    const page = await browser.newPage();
    await page.setViewport(viewport);
    const srv = novoSrv(); const erros = [];
    page.on('pageerror', e => erros.push('pageerror: ' + e.message));
    await page.setRequestInterception(true);
    page.on('request', async req => {
        const u = new URL(req.url());
        if (u.protocol === 'data:' || u.protocol === 'blob:') return req.continue();
        if (u.host !== '127.0.0.1:' + PORT) { srv.externos++; return req.abort(); }
        if (u.pathname.startsWith('/api/')) {
            let b = {}; try { b = JSON.parse(req.postData() || '{}'); } catch (e) { b = {}; }
            return req.respond(await handlerApi(srv, u.pathname.replace('/api/', ''), u.searchParams.get('acao'), b));
        }
        return req.continue();
    });
    await page.evaluateOnNewDocument(() => {
        window.__ocr = { hold: false, held: [], chamadas: 0 };
        window.Tesseract = { createWorker: async () => ({ recognize: () => {
            window.__ocr.chamadas++;
            const texto = 'NF-e\nN\u00ba 10' + String(window.__ocr.chamadas).padStart(5, '0') + '\n';
            if (!window.__ocr.hold) return Promise.resolve({ data: { text: texto } });
            return new Promise(res => window.__ocr.held.push(res));
        } }) };
    });
    await page.goto('http://127.0.0.1:' + PORT + '/' + (query || ''));
    await page.evaluate(() => { state.tipo = 'recebimento'; state.idAtendimento = 777; atendimentoGeracao++; ir('rec_digitaliza'); });
    return { page, srv, erros };
}
async function capturar(page) {
    await page.waitForFunction(() => { const b = document.getElementById('btnCapturarNota'); return b && !b.disabled; }, { timeout: 20000 });
    const n0 = await page.evaluate(() => state.notasNumeros.length);
    await page.click('#btnCapturarNota');
    await page.waitForSelector('#btnUsarImagem', { timeout: 10000 });
    await page.click('#btnUsarImagem');
    await page.waitForFunction(n => state.notasNumeros.length > n, { timeout: 10000 }, n0);
    await page.waitForFunction(() => !state.capturaNotaEmAndamento);
}
const IMG = i => 'data:image/svg+xml;base64,' + Buffer.from(`<svg xmlns="http://www.w3.org/2000/svg" width="72" height="96"><rect width="72" height="96" fill="#${['aa0000', '00aa00', '0000aa', 'aaaa00', 'aa00aa'][i]}"/></svg>`).toString('base64');
const ocrSolta = (page, texto) => page.evaluate(t => { const r = window.__ocr.held.shift(); if (r) r({ data: { text: t } }); return !!r; }, texto);
const todasLidas = page => page.waitForFunction(() => state.notasNumeros.every(n => n.ocrConcluido) && identificacoesEmVoo === 0, { timeout: 10000 });
const aberto = (page, id) => page.evaluate(i => document.getElementById(i).classList.contains('aberto'), id);
const html = (page, id) => page.evaluate(i => document.getElementById(i).innerHTML, id);
async function irRevisao(page) { await page.click('#btnFinalizarDigitalizacao'); await page.waitForSelector('#revisaoLista'); }
const clicaCartao = (page, i) => page.evaluate(i => document.querySelectorAll('#revisaoLista .rev-cartao')[i].click(), i);
async function abreModal(page, i) { await clicaCartao(page, i); await page.waitForSelector('#modalFundo.aberto'); }
const resumo = page => page.evaluate(() => state.notasNumeros.map(n => ({ uid: n.uid, estado: n.estado, sug: n.sugestao, conf: n.confirmado, ov: n.manualOverride, ocr: n.ocrConcluido, sOcr: n.sugestaoOcr })));
async function digitaConfirma(page, numero) {
    await page.evaluate(() => { document.getElementById('inputNumeroNota').value = ''; atualizarBotaoNumeroNota(); });
    for (const d of String(numero)) await page.evaluate(d => digitarNumeroNota(d), d);
    await page.click('#btnSalvarNumeroNota');
    await page.waitForFunction(() => !document.getElementById('modalFundo').classList.contains('aberto'), { timeout: 5000 });
}
function lum(rgb) { const c = rgb.map(v => { v /= 255; return v <= .03928 ? v / 12.92 : Math.pow((v + .055) / 1.055, 2.4); }); return .2126 * c[0] + .7152 * c[1] + .0722 * c[2]; }
function contraste(a, b) { const x = lum(a), y = lum(b); return (Math.max(x, y) + .05) / (Math.min(x, y) + .05); }
const rgbDe = s => s.match(/\d+/g).slice(0, 3).map(Number);

// instala um espiao que registra todo src atribuido/removido em imagens dentro dos modais
const ESPIAO = () => {
    window.__srcLog = [];
    new MutationObserver(ms => ms.forEach(m => {
        if (m.type === 'attributes' && m.attributeName === 'src' && m.target.closest && m.target.closest('.modal-caixa')) window.__srcLog.push({ id: m.target.id, v: m.target.getAttribute('src') });
    })).observe(document, { subtree: true, attributes: true, attributeFilter: ['src'] });
};

async function main() {
    await new Promise(r => server.listen(0, '127.0.0.1', r));
    PORT = server.address().port;
    const browser = await puppeteer.launch({ executablePath: CHROME, headless: true, args: ['--no-sandbox', '--use-fake-device-for-media-stream', '--use-fake-ui-for-media-stream', '--autoplay-policy=no-user-gesture-required'] });
    let externos = 0; const errosTotal = [];
    const fim = async c => { externos += c.srv.externos; errosTotal.push(...c.erros); await c.page.close(); };
    let ctx, page, srv, r;

    // =================== F8: limpeza dos modais ===================
    titulo('F8 - conteudo e foto limpos ao fechar');
    ctx = await abrir(browser, { width: 768, height: 1366 }); page = ctx.page; srv = ctx.srv;
    await page.evaluate(ESPIAO);
    for (let i = 0; i < 3; i++) await capturar(page);
    await todasLidas(page);
    await page.evaluate(imgs => state.notasNumeros.forEach((n, i) => { n.imagem = imgs[i]; }), [0, 1, 2].map(IMG));
    await irRevisao(page);
    ok(!(await html(page, 'modalCaixa')).includes('<img'), 'antes de abrir: #modalCaixa sem img');
    await abreModal(page, 0);
    r = await page.evaluate(() => { const i = document.getElementById('notaModalMini'); return { src: i.getAttribute('src') }; });
    ok(r.src === IMG(0), 'modal da nota 1 mostra a foto da nota 1');
    await page.click('#btnVoltarModalNota');
    ok(!(await aberto(page, 'modalFundo')), 'Voltar fecha o modal');
    ok((await html(page, 'modalCaixa')) === '', 'Voltar: #modalCaixa fica VAZIO (sem foto nem botoes)');
    r = await page.evaluate(() => ({ imgs: document.querySelectorAll('#modalCaixa img').length, srcs: document.querySelectorAll('.modal-caixa img[src]').length }));
    ok(r.imgs === 0 && r.srcs === 0, 'nenhuma img com src dentro de qualquer modal depois do Voltar');
    // abrir outra nota: nenhum src da nota 1 reaparece
    await page.evaluate(() => { window.__srcLog.length = 0; });
    await abreModal(page, 1);
    r = await page.evaluate(() => ({ log: window.__srcLog.slice(), srcAtual: document.getElementById('notaModalMini').getAttribute('src') }));
    ok(r.srcAtual === IMG(1) && r.log.every(e => e.v === IMG(1) || e.v === null), 'abrir nota 2: so o src da nota 2 e atribuido (nenhum da nota 1): ' + JSON.stringify(r.log.map(e => e.v && e.v.slice(-12))));
    // Corrigir troca o modal sem fechar: o img antigo perde o src
    ok(await page.evaluate(() => !!document.getElementById('btnCorrigirNumeroSugerido')), 'modal de conferir tem Corrigir');
    await page.evaluate(() => { window.__imgAntigo = document.getElementById('notaModalMini'); });
    await page.click('#btnCorrigirNumeroSugerido');
    r = await page.evaluate(() => ({ antigoSrc: window.__imgAntigo.getAttribute('src'), conectado: window.__imgAntigo.isConnected, novo: document.getElementById('notaModalMini').getAttribute('src') }));
    ok(r.antigoSrc === null && r.novo === IMG(1), 'Corrigir: img antiga sem src, nova com a foto da mesma nota');
    // Voltar do digitar
    await page.click('#btnVoltarModalNota');
    ok((await html(page, 'modalCaixa')) === '', 'Voltar do modal de digitar: #modalCaixa vazio');

    titulo('F8 - overlay de exclusao');
    await abreModal(page, 0);
    await page.click('#btnExcluirNota');
    ok((await html(page, 'modalConfirmExcluirNotaCaixa')).includes('Excluir a Nota 1 de 3?'), 'overlay montado para a nota 1');
    await page.click('#btnExcluirNaoVoltar');
    ok(!(await aberto(page, 'modalConfirmExcluirNotaFundo')) && (await html(page, 'modalConfirmExcluirNotaCaixa')) === '', 'Nao, voltar: overlay fechado e HTML vazio');
    ok((await aberto(page, 'modalFundo')) && (await html(page, 'modalCaixa')).includes('notaModalMini'), 'Nao, voltar: modal do numero intacto por baixo');
    await page.click('#btnVoltarModalNota');
    // handler antigo: overlay da nota 1 foi fechado; abrir o da nota 2 e confirmar exclui a nota 2 (id_nota 2) uma vez
    await abreModal(page, 1);
    await page.click('#btnExcluirNota');
    ok((await html(page, 'modalConfirmExcluirNotaCaixa')).includes('Excluir a Nota 2 de 3?'), 'overlay da nota 2 sem texto da nota 1');
    await page.click('#btnExcluirSim');
    await page.waitForFunction(() => !state.excluindoNota && state.notasNumeros.length === 2, { timeout: 5000 });
    const ex = srv.calls.filter(c => c.acao === 'excluir');
    ok(ex.length === 1 && ex[0].body.id_nota === 2, 'um unico excluir, com id_nota 2 (handler da nota 1 nao reaproveitado): ' + JSON.stringify(ex.map(c => c.body)));
    ok((await html(page, 'modalCaixa')) === '' && (await html(page, 'modalConfirmExcluirNotaCaixa')) === '', 'apos excluir: os dois modais vazios');
    ok(!(await aberto(page, 'modalFundo')) && !(await aberto(page, 'modalConfirmExcluirNotaFundo')), 'apos excluir: nenhum modal aberto');
    r = await page.evaluate(() => document.querySelectorAll('.modal-caixa img[src]').length);
    ok(r === 0, 'apos excluir: nenhuma img com src em modal');
    // uid imutavel preservado
    r = await resumo(page);
    ok(r.map(n => n.uid).join() === '1,3', 'uids imutaveis apos exclusao: ' + r.map(n => n.uid).join());
    // excluir a ultima restante abre captura; modais limpos
    await abreModal(page, 0); await page.click('#btnExcluirNota'); await page.click('#btnExcluirSim');
    await page.waitForFunction(() => state.notasNumeros.length === 1, { timeout: 5000 });
    await abreModal(page, 0); await page.click('#btnExcluirNota'); await page.click('#btnExcluirSim');
    await page.waitForFunction(() => state.notasNumeros.length === 0 && state.tela === 'rec_digitaliza', { timeout: 5000 });
    ok((await html(page, 'modalCaixa')) === '' && (await html(page, 'modalConfirmExcluirNotaCaixa')) === '', 'zero notas: volta a captura com modais vazios');
    await fim(ctx);

    titulo('F8 - fechamento programatico e confirmacao de cancelar');
    ctx = await abrir(browser, { width: 768, height: 1366 }); page = ctx.page; srv = ctx.srv;
    for (let i = 0; i < 2; i++) await capturar(page);
    await todasLidas(page);
    await page.evaluate(imgs => state.notasNumeros.forEach((n, i) => { n.imagem = imgs[i]; }), [0, 1].map(IMG));
    await irRevisao(page);
    await abreModal(page, 0);
    await page.evaluate(() => confirmarCancelarNotaModal());
    ok((await html(page, 'modalConfirmCancelNotaCaixa')).includes('Cancelar atendimento?'), 'confirmacao de cancelar montada');
    await page.evaluate(() => fecharConfirmacaoCancelarNotaModal());
    ok((await html(page, 'modalConfirmCancelNotaCaixa')) === '', 'Continuar atendimento: caixa da confirmacao de cancelar vazia');
    await page.evaluate(() => document.getElementById('btnExcluirNota').click());
    ok(await aberto(page, 'modalConfirmExcluirNotaFundo'), 'overlay de exclusao aberto');
    await page.evaluate(() => ir('rec_revisao_numeros')); // fechamento programatico (troca de tela)
    ok((await html(page, 'modalCaixa')) === '' && (await html(page, 'modalConfirmExcluirNotaCaixa')) === '' && !(await aberto(page, 'modalFundo')) && !(await aberto(page, 'modalConfirmExcluirNotaFundo')), 'ir(): fecha e esvazia modal e overlay');
    await page.evaluate(() => { numeroModalAberta = false; numeroModalOverrideUid = null; }); // ir() nao zera a flag (o fluxo real so troca de tela depois de fechar o modal)
    await abreModal(page, 0);
    await page.evaluate(() => { fecharModalNumeroNotaAtual(); });
    ok((await html(page, 'modalCaixa')) === '' && (await page.evaluate(() => document.querySelectorAll('.modal-caixa img[src]').length)) === 0, 'fechamento programatico (fecharModalNumeroNotaAtual): vazio e sem src');
    await fim(ctx);

    titulo('F8 - 422 (tratarNotasSemNumero) sem estado residual');
    ctx = await abrir(browser, { width: 768, height: 1366 }); page = ctx.page; srv = ctx.srv;
    await page.evaluate(() => { window.__ocr.hold = true; });
    for (let i = 0; i < 3; i++) await capturar(page);
    await page.waitForFunction(() => window.__ocr.held.length === 1);
    await irRevisao(page);
    // nota 1: pendente + override (digita 111 com OCR ainda lendo)
    await abreModal(page, 0);
    r = await resumo(page);
    ok(r[0].estado === 'pendente' && r[0].ov === true, 'nota 1 pendente com override ao abrir o modal');
    await digitaConfirma(page, 111);
    // nota 3: pendente + override; OCR da nota 1 e 2 nao liberados ainda -> libera so a fila ate chegar na 3 depois
    // nota 2: confirma via digitacao tambem (pendente + override)
    await abreModal(page, 1); await digitaConfirma(page, 222);
    // nota 3: abre o modal enquanto pendente (override), libera o OCR de todas, confirma manual
    await abreModal(page, 2);
    // A fila do OCR e serial. Libera so as notas 1 e 2 (OCR concluido + override: resultado ignorado,
    // sugestaoOcr guardada); a nota 3 fica com o OCR PENDENTE ate depois do 422.
    await ocrSolta(page, 'N\u00ba 000.000.501\n');
    await page.waitForFunction(() => window.__ocr.held.length >= 1, { timeout: 5000 });
    await ocrSolta(page, 'N\u00ba 000.000.502\n');
    await page.waitForFunction(() => window.__ocr.held.length >= 1, { timeout: 5000 });
    // deixa a nota 3 com OCR PENDENTE (cenario B) ate depois do 422; digita 333
    await digitaConfirma(page, 333);
    r = await resumo(page);
    ok(r.every(n => n.conf), 'as 3 notas confirmadas manualmente');
    ok(r[0].ov === true && r[0].ocr === true && r[0].sOcr === '000000501', 'nota 1: override residual + OCR concluido (sugestaoOcr 000000501): ' + JSON.stringify(r[0]));
    ok(r[2].ov === true && r[2].ocr === false, 'nota 3: override residual + OCR pendente');
    srv.concluir422 = [1, 3];
    await page.click('#btnContinuarRevisao');
    await page.waitForFunction(() => state.notasNumeros.some(n => n.destaque), { timeout: 5000 });
    r = await resumo(page);
    ok(r[0].ov === false && r[0].estado === 'sugerido' && r[0].sug === '000000501' && r[0].conf === false, 'nota 1 apos 422: override zerado, sugerido 000000501 a partir de sugestaoOcr: ' + JSON.stringify(r[0]));
    ok(r[2].ov === false && r[2].estado === 'pendente' && r[2].conf === false, 'nota 3 apos 422: override zerado, pendente (OCR ainda em curso): ' + JSON.stringify(r[2]));
    ok(r[1].conf === true && r[1].ov === true && r[1].estado === 'confirmado', 'nota 2 (nao apontada): confirmada e intacta');
    // o OCR tardio da nota 3 agora e APLICADO (nao fica em Lendo... para sempre)
    await ocrSolta(page, 'N\u00ba 000.000.503\n');
    await page.waitForFunction(() => state.notasNumeros[2].ocrConcluido, { timeout: 5000 });
    r = await resumo(page);
    ok(r[2].estado === 'sugerido' && r[2].sug === '000000503', 'nota 3: OCR tardio aplicado apos o 422 (sugerido 000000503): ' + JSON.stringify(r[2]));
    r = await page.evaluate(() => Array.from(document.querySelectorAll('.rev-selo-texto')).map(e => e.textContent));
    ok(!r.includes('Lendo...'), 'nenhum cartao em Lendo... apos o OCR terminar: ' + r.join('|'));
    // override orfao em nota NAO apontada e nao confirmada
    await page.evaluate(() => {
        const n = state.notasNumeros[1]; n.confirmado = false; n.estado = 'pendente'; n.ocrConcluido = false; n.manualOverride = true;
        tratarNotasSemNumero({ ordens_pendentes: [1] });
    });
    r = await resumo(page);
    ok(r[1].ov === false && r[1].estado === 'pendente', 'nota nao apontada e nao confirmada tambem perde o override orfao');
    await fim(ctx);

    // =================== F9: tipografia, contraste, geometria ===================
    for (const vp of [{ width: 768, height: 1366 }, { width: 1152, height: 1846 }]) {
        const V = vp.width + 'x' + vp.height;
        titulo('F9 - ' + V);
        ctx = await abrir(browser, vp); page = ctx.page; srv = ctx.srv;
        for (let i = 0; i < 2; i++) await capturar(page);
        await todasLidas(page);
        ok(await page.evaluate(() => !('clienteEstado' in state) && !('clienteMotivo' in state)), V + ' state sem clienteEstado/clienteMotivo');
        await irRevisao(page);
        // badges
        r = await page.evaluate(() => Array.from(document.querySelectorAll('.rev-selo')).map(s => { const c = s.closest('.rev-cartao').getBoundingClientRect(), b = s.getBoundingClientRect(); const cs = getComputedStyle(s); return { fs: cs.fontSize, fw: cs.fontWeight, h: Math.round(b.height), dentro: b.right <= c.right + 0.5 && b.left >= c.left - 0.5 }; }));
        ok(r.length === 2 && r.every(s => s.fs === '18px' && Number(s.fw) >= 700 && s.dentro), V + ' badges 18px/700 dentro do cartao: ' + JSON.stringify(r));
        // selo de destaque (texto mais longo) tambem cabe
        await page.evaluate(() => { state.notasNumeros[0].destaque = true; state.notasNumeros[0].estado = 'pendente'; atualizarRevisaoNumeros(); });
        r = await page.evaluate(() => { const s = document.querySelector('.rev-cartao-destaque .rev-selo'); const c = s.closest('.rev-cartao').getBoundingClientRect(), b = s.getBoundingClientRect(); return { t: s.textContent.trim(), dentro: b.right <= c.right + 0.5, h: Math.round(b.height), fs: getComputedStyle(s).fontSize }; });
        ok(r.dentro && r.fs === '18px' && r.h < 40, V + ' selo de destaque cabe e nao quebra linha: ' + JSON.stringify(r));
        await page.evaluate(() => { state.notasNumeros[0].destaque = false; recalcularEstadoOcrNota(state.notasNumeros[0]); atualizarRevisaoNumeros(); });
        // desabilitado: Continuar na revisao
        r = await page.evaluate(() => { const b = document.getElementById('btnContinuarRevisao'); const cs = getComputedStyle(b); return { dis: b.disabled, bg: cs.backgroundColor, col: cs.color, op: cs.opacity }; });
        ok(r.dis && r.bg === 'rgb(176, 176, 177)' && r.col === 'rgb(58, 58, 58)' && r.op === '1' && contraste(rgbDe(r.col), rgbDe(r.bg)) >= 4.5, V + ' Continuar desabilitado sem opacity, contraste ' + contraste(rgbDe(r.col), rgbDe(r.bg)).toFixed(2));

        for (const modo of ['conferir', 'digitar']) {
            await page.evaluate(() => { if (document.getElementById('modalFundo').classList.contains('aberto')) voltarModalNumeroNota(state.notasNumeros[0].uid); });
            await abreModal(page, 0);
            if (modo === 'digitar') await page.click('#btnCorrigirNumeroSugerido');
            const m = await page.evaluate(() => {
                const R = id => document.getElementById(id) ? document.getElementById(id).getBoundingClientRect() : null;
                const c = document.getElementById('modalCaixa'); const cr = c.getBoundingClientRect();
                const vo = R('btnVoltarModalNota'), ex = R('btnExcluirNota'), cancel = document.querySelector('.btn-saida-modal-nota').getBoundingClientRect();
                const conf = R('btnConfirmarNumeroSugerido') || R('btnSalvarNumeroNota');
                const fs = id => { const e = document.getElementById(id); return e ? getComputedStyle(e) : null; };
                const rot = document.querySelector('.nota-modal-rotulo-secao'), id = document.querySelector('.nota-modal-id');
                const cC = fs('btnConfirmarNumeroSugerido'), cR = fs('btnCorrigirNumeroSugerido');
                return {
                    h: Math.round(cr.height), sh: c.scrollHeight, ch: c.clientHeight, vh: innerHeight,
                    voltarExcluir: Math.round(ex.top - vo.bottom), excluirCancel: Math.round(cancel.top - ex.bottom),
                    hVoltar: Math.round(vo.height), hExcluir: Math.round(ex.height), hCancel: Math.round(cancel.height), hConf: Math.round(conf.height),
                    rotulo: rot.textContent, rotFs: getComputedStyle(rot).fontSize, idFs: getComputedStyle(id).fontSize,
                    confFs: cC && cC.fontSize, confFw: cC && cC.fontWeight, corrFs: cR && cR.fontSize, corrFw: cR && cR.fontWeight, corrH: cR ? Math.round(R('btnCorrigirNumeroSugerido').height) : null,
                    topo: Math.round(cr.top), base: Math.round(cr.bottom),
                };
            });
            console.log('  ' + V + ' ' + modo + ': ' + JSON.stringify(m));
            ok(m.voltarExcluir >= 64, V + ' ' + modo + ' Voltar->Excluir ' + m.voltarExcluir + ' px >= 64');
            ok(m.excluirCancel >= 99, V + ' ' + modo + ' Excluir->Cancelar atendimento ' + m.excluirCancel + ' px >= 99');
            ok(m.sh <= m.ch + 1 && m.topo >= 0 && m.base <= m.vh, V + ' ' + modo + ' caixa ' + m.h + ' px cabe (topo ' + m.topo + ', base ' + m.base + ', viewport ' + m.vh + ')');
            ok(m.hVoltar >= 64 && m.hExcluir >= 64 && m.hCancel >= 64 && m.hConf >= 64, V + ' ' + modo + ' alvos >= 64 px (' + [m.hVoltar, m.hExcluir, m.hCancel, m.hConf].join('/') + ')');
            ok(m.rotulo === 'Atendimento' && m.rotFs === '22px' && m.rotFs === m.idFs, V + ' ' + modo + ' rotulo Atendimento ' + m.rotFs + ' = Nota N de M ' + m.idFs);
            if (modo === 'conferir') {
                ok(m.confFs === '20px' && m.corrFs === '20px' && Number(m.confFw) >= 700 && Number(m.corrFw) >= 700 && m.corrH >= 64, V + ' Confirmar/Corrigir 20px/700, alvo >= 64');
            } else {
                // Confirmar do teclado desabilitado (campo vazio): contraste e distincao
                await page.evaluate(() => { document.getElementById('inputNumeroNota').value = ''; atualizarBotaoNumeroNota(); });
                const d = await page.evaluate(() => { const b = document.getElementById('btnSalvarNumeroNota'); const cs = getComputedStyle(b); return { dis: b.disabled, bg: cs.backgroundColor, col: cs.color, op: cs.opacity, bd: cs.borderTopColor }; });
                await page.evaluate(() => { digitarNumeroNota('1'); });
                const e = await page.evaluate(() => { const b = document.getElementById('btnSalvarNumeroNota'); const cs = getComputedStyle(b); return { dis: b.disabled, bg: cs.backgroundColor, col: cs.color, op: cs.opacity }; });
                const cd = contraste(rgbDe(d.col), rgbDe(d.bg));
                ok(d.dis && d.op === '1' && d.bg === 'rgb(176, 176, 177)' && d.col === 'rgb(58, 58, 58)' && cd >= 4.5, V + ' Confirmar desabilitado: sem opacity, texto #3A3A3A sobre #B0B0B1, contraste ' + cd.toFixed(2));
                ok(!e.dis && e.bg === 'rgb(1, 121, 173)' && e.col === 'rgb(255, 255, 255)' && e.op === '1' && e.bg !== d.bg && e.col !== d.col, V + ' Confirmar habilitado difere do desabilitado em fundo e texto (nao so opacity)');
                // Voltar e Excluir desabilitados durante o salvar
                await page.evaluate(() => definirSaidasModalNotaDesabilitadas(true));
                const s = await page.evaluate(() => ['btnVoltarModalNota', 'btnExcluirNota'].map(id => { const cs = getComputedStyle(document.getElementById(id)); return { bg: cs.backgroundColor, col: cs.color, op: cs.opacity }; }));
                ok(s.every(x => x.bg === 'rgb(176, 176, 177)' && x.col === 'rgb(58, 58, 58)' && x.op === '1'), V + ' Voltar/Excluir desabilitados: fundo #B0B0B1, texto #3A3A3A, opacity 1');
                await page.evaluate(() => definirSaidasModalNotaDesabilitadas(false));
                // overlay: botoes desabilitados durante a exclusao
                await page.click('#btnExcluirNota');
                await page.evaluate(() => definirBotoesExclusaoHabilitados(false));
                const o = await page.evaluate(() => ['btnExcluirNaoVoltar', 'btnExcluirSim'].map(id => { const cs = getComputedStyle(document.getElementById(id)); return { bg: cs.backgroundColor, col: cs.color, op: cs.opacity }; }));
                ok(o.every(x => x.bg === 'rgb(176, 176, 177)' && x.col === 'rgb(58, 58, 58)' && x.op === '1'), V + ' overlay Nao/Sim desabilitados: fundo #B0B0B1, texto #3A3A3A, opacity 1');
                await page.evaluate(() => definirBotoesExclusaoHabilitados(true));
                const z = await page.evaluate(() => getComputedStyle(document.getElementById('modalConfirmExcluirNotaFundo')).zIndex);
                ok(z === '56', V + ' overlay de exclusao z-index 56');
                await page.click('#btnExcluirNaoVoltar');
            }
        }
        await fim(ctx);
    }

    // =================== preservacoes ===================
    titulo('Preservacoes (?medir=1, Adicionar outra nota)');
    ctx = await abrir(browser, { width: 1152, height: 1846 }, '?medir=1'); page = ctx.page; srv = ctx.srv;
    ok((await page.evaluate(() => MEDIR && MEDIR_EVENTOS.size)) === 23, '?medir=1 com 23 eventos');
    await capturar(page); await todasLidas(page); await irRevisao(page);
    ok(await page.evaluate(() => { const b = document.getElementById('btnAdicionarNota'); return !!b && !b.disabled; }), 'Adicionar outra nota presente com menos de 5');
    await page.click('#btnAdicionarNota');
    await page.waitForFunction(() => state.tela === 'rec_digitaliza' && state.notasNumeros.length === 1, { timeout: 5000 });
    ok(true, 'Adicionar volta a captura preservando a nota');
    await fim(ctx);

    await browser.close(); server.close();
    console.log('\nRequisicoes externas abortadas: ' + externos);
    console.log('pageerrors: ' + (errosTotal.length ? errosTotal.join(' | ') : 'nenhum'));
    console.log('RESULTADO: ' + passou + ' ok, ' + falhou + ' falha(s)');
    if (falhas.length) console.log('FALHAS:\n - ' + falhas.join('\n - '));
    process.exit(falhou || errosTotal.length || externos ? 1 : 0);
}
main().catch(e => { console.error('ERRO NO HARNESS', e); process.exit(2); });
