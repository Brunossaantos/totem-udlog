// Teste front da rodada corretiva R4 FASE B (hardening-revisao-notas-e-cliente): F1 (409 OCR_EM_ANDAMENTO)
// e F7 (uid idempotente, retry, reconciliacao via listar, nota recuperada sem foto).
// Chrome headless (puppeteer), api e Tesseract STUBADOS, so rede local (qualquer outro host e abortado e
// conta como falha). Sem banco, sem Talent/VIO/impressao.
//   node tests/manual/front_r4b_uid_409.js
// Variaveis opcionais (provas negativas servem COPIAS mutadas): APPJS=<arquivo> APPCSS=<arquivo> CHROME=<exe>
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
const UID_RE = /^[A-Za-z0-9_-]{8,64}$/;
const MSG_409 = 'A leitura das notas ainda está sendo concluída. Aguarde alguns segundos e toque em Continuar novamente.';

const INDEX = `<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<link rel="stylesheet" href="assets/app.css"></head>
<body data-totem-token="TOKEN-FALSO-R4B" data-totem-nome="r4b"><div id="app"></div>
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

function novoSrv() {
    return {
        notas: [], proxId: 1, calls: [], externos: 0, concluirFila: [], perderProcessar: 0, uidDivergente: false, semUidNaResposta: false,
        listarFalha: 0, concluirAtrasoMs: 0,
    };
}
async function handlerApi(srv, arquivo, acao, b) {
    srv.calls.push({ arquivo, acao, body: b });
    if (arquivo === 'x.php' && acao === 'travar') return null; // nunca responde (teste de timeout)
    if (arquivo === 'nota.php') {
        if (acao === 'processar') {
            if (b.uid !== undefined && !UID_RE.test(String(b.uid))) return resp(400, false, { sucesso: false, erro: 'Identificador da nota invalido' });
            let n = b.uid ? srv.notas.find(x => x.uid === b.uid) : null;
            let reaproveitada = !!n;
            if (!n) {
                if (!b.imagem) return resp(400, false, { sucesso: false, erro: 'Dados incompletos' });
                if (srv.notas.length >= 5) return resp(400, false, { sucesso: false, erro: 'limite' });
                let ordem = 1; while (srv.notas.some(x => x.ordem === ordem)) ordem++;
                n = { id_nota: srv.proxId++, ordem, numero: null, uid: b.uid || null }; srv.notas.push(n);
            }
            if (srv.perderProcessar > 0) { srv.perderProcessar--; return { abort: true }; }
            const dados = { cliente_identificado: false, cliente: null, id_nota: n.id_nota, ordem: n.ordem };
            if (b.uid && !srv.semUidNaResposta) { dados.uid = srv.uidDivergente ? 'uid_de_outra_nota_1' : n.uid; dados.reaproveitada = reaproveitada; }
            return resp(200, true, dados);
        }
        if (acao === 'listar') {
            if (srv.listarFalha > 0) { srv.listarFalha--; return { abort: true }; }
            const notas = srv.notas.slice().sort((a, c) => a.ordem - c.ordem).map(n => ({ id_nota: n.id_nota, ordem: n.ordem, uid: n.uid, numero_definido: n.numero !== null }));
            return resp(200, true, { total_notas: notas.length, notas });
        }
        if (acao === 'definir-numero') {
            const n = srv.notas.find(x => x.id_nota === b.id_nota);
            if (!n) return resp(404, false, { sucesso: false, erro: 'Nota nao encontrada' });
            if (String(b.numero) === '99999') return resp(409, false, { sucesso: false, erro: 'dup', codigo: 'NUMERO_NOTA_DUPLICADO' });
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
        if (srv.concluirAtrasoMs) await espera(srv.concluirAtrasoMs);
        const r = srv.concluirFila.shift();
        if (r) return r;
        return resp(200, true, { proxima_tela: 'rec_cnh', etapa: 'rec_cnh' });
    }
    return resp(200, true, {});
}
const r409 = (ordens, extra) => resp(409, false, Object.assign({ sucesso: false, erro: 'Ainda ha notas fiscais em processamento (OCR_EM_ANDAMENTO)', codigo: 'OCR_EM_ANDAMENTO', dados: { ordens_em_processamento: ordens } }, extra || {}));
const r422 = ordens => resp(422, false, { sucesso: false, erro: 'x', codigo: 'NOTAS_SEM_NUMERO', dados: { ordens_pendentes: ordens, total_notas: 5, total_pendentes: ordens.length } });

async function abrir(browser, viewport, query) {
    const page = await browser.newPage();
    await page.setViewport(viewport);
    const srv = novoSrv(); const erros = []; const consoleMsgs = [];
    page.on('pageerror', e => erros.push('pageerror: ' + e.message));
    page.on('console', m => consoleMsgs.push(m.text()));
    await page.setRequestInterception(true);
    page.on('request', async req => {
        const u = new URL(req.url());
        if (u.protocol === 'data:' || u.protocol === 'blob:') return req.continue();
        if (u.host !== '127.0.0.1:' + PORT) { srv.externos++; return req.abort(); }
        if (u.pathname.startsWith('/api/')) {
            let b = {}; try { b = JSON.parse(req.postData() || '{}'); } catch (e) { b = {}; }
            const r = await handlerApi(srv, u.pathname.replace('/api/', ''), u.searchParams.get('acao'), b);
            if (r === null) return; // pendura
            if (r.abort) { try { return await req.abort('failed'); } catch (e) { return; } }
            try { return await req.respond(r); } catch (e) { return; }
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
    return { page, srv, erros, consoleMsgs };
}
async function esperaCaptura(page) { await page.waitForFunction(() => { const b = document.getElementById('btnCapturarNota'); return b && !b.disabled; }, { timeout: 20000 }); }
async function capturar(page) {
    await esperaCaptura(page);
    const n0 = await page.evaluate(() => state.notasNumeros.length);
    await page.click('#btnCapturarNota');
    await page.waitForSelector('#btnUsarImagem', { timeout: 10000 });
    await page.click('#btnUsarImagem');
    await page.waitForFunction(n => state.notasNumeros.length > n, { timeout: 10000 }, n0);
    await page.waitForFunction(() => !state.capturaNotaEmAndamento);
}
// captura e clica Usar imagem UMA vez, sem esperar sucesso (para falhas)
async function capturarEUsar(page) {
    await esperaCaptura(page);
    await page.click('#btnCapturarNota');
    await page.waitForSelector('#btnUsarImagem', { timeout: 10000 });
    await page.click('#btnUsarImagem');
    await page.waitForFunction(() => !state.capturaNotaEmAndamento, { timeout: 10000 });
}
const todasLidas = page => page.waitForFunction(() => state.notasNumeros.every(n => n.ocrConcluido) && identificacoesEmVoo === 0, { timeout: 10000 });
const aberto = (page, id) => page.evaluate(i => document.getElementById(i).classList.contains('aberto'), id);
async function irRevisao(page) { await page.click('#btnFinalizarDigitalizacao'); await page.waitForSelector('#revisaoLista'); }
const clicaCartao = (page, i) => page.evaluate(i => document.querySelectorAll('#revisaoLista .rev-cartao')[i].click(), i);
async function abreModal(page, i) { await clicaCartao(page, i); await page.waitForSelector('#modalFundo.aberto'); }
async function digitaConfirma(page, numero) {
    await page.evaluate(() => { document.getElementById('inputNumeroNota').value = ''; atualizarBotaoNumeroNota(); });
    for (const d of String(numero)) await page.evaluate(d => digitarNumeroNota(d), d);
    await page.click('#btnSalvarNumeroNota');
    await page.waitForFunction(() => !document.getElementById('modalFundo').classList.contains('aberto'), { timeout: 5000 });
}
// confirma o numero sugerido de todos os cartoes (todas sugeridas pelo OCR stub)
async function confirmarTodas(page) {
    const n = await page.evaluate(() => state.notasNumeros.length);
    for (let i = 0; i < n; i++) {
        const conf = await page.evaluate(i => state.notasNumeros[i].confirmado, i);
        if (conf) continue;
        await abreModal(page, i);
        const temSug = await page.evaluate(() => !!document.getElementById('btnConfirmarNumeroSugerido'));
        if (temSug) {
            await page.click('#btnConfirmarNumeroSugerido');
            await page.waitForFunction(() => !document.getElementById('modalFundo').classList.contains('aberto'), { timeout: 5000 });
        } else {
            await digitaConfirma(page, 1000 + i);
        }
    }
}
const estadoRev = page => page.evaluate(() => ({
    tela: state.tela,
    btnDisabled: document.getElementById('btnContinuarRevisao') ? document.getElementById('btnContinuarRevisao').disabled : null,
    aguarde: document.getElementById('revisaoAguarde') ? { txt: document.getElementById('revisaoAguarde').textContent, disp: document.getElementById('revisaoAguarde').style.display } : null,
    erro: document.getElementById('revisaoErro') ? { txt: document.getElementById('revisaoErro').textContent, disp: document.getElementById('revisaoErro').style.display } : null,
    notas: state.notasNumeros.map(n => ({ uid: n.uid, cu: n.clientUid, ordem: n.ordem, id: n.idNota, aguardando: n.aguardando, destaque: n.destaque, conf: n.confirmado, rec: n.recuperada, tem: !!n.imagem })),
    finalizando: state.finalizandoDigitalizacao,
}));
const chamadas = (srv, acao) => srv.calls.filter(c => c.acao === acao);
const IMG = i => 'data:image/svg+xml;base64,' + Buffer.from(`<svg xmlns="http://www.w3.org/2000/svg" width="72" height="96"><rect width="72" height="96" fill="#${['aa0000', '00aa00', '0000aa', 'aaaa00', 'aa00aa'][i]}"/></svg>`).toString('base64');
const rgbDe = s => s.match(/\d+/g).slice(0, 3).map(Number);

async function main() {
    await new Promise(r => server.listen(0, '127.0.0.1', r));
    PORT = server.address().port;
    const browser = await puppeteer.launch({ executablePath: CHROME, headless: true, args: ['--no-sandbox', '--use-fake-device-for-media-stream', '--use-fake-ui-for-media-stream', '--autoplay-policy=no-user-gesture-required'] });
    let externos = 0; const errosTotal = []; const todosConsole = [];
    const fim = async c => { externos += c.srv.externos; errosTotal.push(...c.erros); todosConsole.push(...c.consoleMsgs); await c.page.close(); };
    let ctx, page, srv, r;

    // =================== F7: uid ===================
    titulo('F7 - uid gerado antes do upload, unico por nota, enviado no processar');
    ctx = await abrir(browser, { width: 768, height: 1366 }); page = ctx.page; srv = ctx.srv;
    for (let i = 0; i < 3; i++) await capturar(page);
    await todasLidas(page);
    let proc = chamadas(srv, 'processar');
    ok(proc.length === 3 && proc.every(c => typeof c.body.uid === 'string' && UID_RE.test(c.body.uid)), 'os 3 processar enviam uid conforme a regex: ' + JSON.stringify(proc.map(c => c.body.uid)));
    ok(new Set(proc.map(c => c.body.uid)).size === 3, 'os 3 uid sao diferentes');
    ok(proc.every(c => c.body.ordem === undefined), 'upload continua sem ordem');
    r = await estadoRev(page);
    ok(r.notas.map(n => n.cu).join() === proc.map(c => c.body.uid).join(), 'clientUid local = uid enviado, por nota');
    ok(r.notas.map(n => n.uid).join() === '1,2,3', 'uid LOCAL numerico (chave de DOM) preservado: ' + r.notas.map(n => n.uid));
    // exclusao libera, nova nota = novo uid
    const uidExcluido = proc[1].body.uid;
    await irRevisao(page);
    await abreModal(page, 1);
    await page.click('#btnExcluirNota'); await page.click('#btnExcluirSim');
    await page.waitForFunction(() => !state.excluindoNota && state.notasNumeros.length === 2, { timeout: 5000 });
    ok(srv.notas.length === 2 && !srv.notas.some(n => n.uid === uidExcluido), 'exclusao removeu a nota do servidor (stub) e liberou o uid la');
    await page.evaluate(() => adicionarOutraNota());
    await capturar(page);
    proc = chamadas(srv, 'processar');
    ok(proc.length === 4 && !proc.slice(0, 3).some(c => c.body.uid === proc[3].body.uid) && proc[3].body.uid !== uidExcluido, 'nova nota apos excluir usa uid NOVO (nao reutiliza o excluido)');
    await fim(ctx);

    titulo('F7 - gerador: fallbacks e regex');
    ctx = await abrir(browser, { width: 768, height: 1366 }); page = ctx.page;
    r = await page.evaluate(() => {
        const out = {};
        const base = []; for (let i = 0; i < 200; i++) base.push(gerarClientUidNota());
        out.base = { todosOk: base.every(u => NOTA_CLIENT_UID_REGEX.test(u)), unicos: new Set(base).size === 200, amostra: base[0].length };
        const rnd = crypto.randomUUID; const grv = crypto.getRandomValues.bind(crypto);
        // 1) sem randomUUID, com getRandomValues
        Object.defineProperty(crypto, 'randomUUID', { value: undefined, configurable: true });
        const g = []; for (let i = 0; i < 50; i++) g.push(gerarClientUidNota());
        out.grv = { todosOk: g.every(u => NOTA_CLIENT_UID_REGEX.test(u)), unicos: new Set(g).size === 50, len: g[0].length };
        // 2) sem crypto algum
        const orig = Object.getOwnPropertyDescriptor(window, 'crypto');
        Object.defineProperty(window, 'crypto', { value: undefined, configurable: true });
        const f = []; for (let i = 0; i < 50; i++) f.push(gerarClientUidNota());
        out.fb = { todosOk: f.every(u => NOTA_CLIENT_UID_REGEX.test(u)), unicos: new Set(f).size === 50, len: f[0].length };
        if (orig) Object.defineProperty(window, 'crypto', orig); else delete window.crypto;
        Object.defineProperty(crypto, 'randomUUID', { value: rnd, configurable: true });
        // 3) colisao: nunca devolve uid ja usado
        const antes = clientUidsUsados.size;
        const u1 = gerarClientUidNota(); out.usados = clientUidsUsados.has(u1) && clientUidsUsados.size === antes + 1;
        return out;
    });
    ok(r.base.todosOk && r.base.unicos, 'randomUUID: 200 uid validos e unicos');
    ok(r.grv.todosOk && r.grv.unicos, 'sem randomUUID (getRandomValues): 50 validos e unicos, len=' + r.grv.len);
    ok(r.fb.todosOk && r.fb.unicos, 'sem crypto (fallback): 50 validos e unicos, len=' + r.fb.len);
    ok(r.usados, 'uid gerado fica registrado como usado (nunca reaparece)');
    await fim(ctx);

    // =================== F7: perda de resposta + retry com o mesmo uid ===================
    titulo('F7 - resposta do processar perdida: retry com o MESMO uid, sem segunda nota');
    ctx = await abrir(browser, { width: 768, height: 1366 }); page = ctx.page; srv = ctx.srv;
    srv.perderProcessar = 1;
    await capturarEUsar(page);
    r = await page.evaluate(() => ({ st: document.getElementById('scannerStatus').textContent, n: state.notasNumeros.length, pend: uploadPendente ? uploadPendente.clientUid : null, btn: !document.getElementById('btnUsarImagem').disabled, prev: !!state.previewNotaAtual }));
    ok(srv.notas.length === 1, 'servidor criou 1 nota (resposta perdida): ' + srv.notas.length);
    ok(r.n === 0 && /conexão/.test(r.st) && r.btn && r.prev, 'front: nenhuma nota local, mensagem de conexao, Usar imagem reabilitado, captura mantida: ' + JSON.stringify(r));
    ok(typeof r.pend === 'string' && UID_RE.test(r.pend), 'uid pendente mantido');
    const uidPerdido = r.pend;
    await page.click('#btnUsarImagem');
    await page.waitForFunction(() => state.notasNumeros.length === 1 && !state.capturaNotaEmAndamento, { timeout: 10000 });
    proc = chamadas(srv, 'processar');
    ok(proc.length === 2 && proc[0].body.uid === uidPerdido && proc[1].body.uid === uidPerdido, 'retry reenviou o MESMO uid: ' + JSON.stringify(proc.map(c => c.body.uid)));
    ok(srv.notas.length === 1, 'continua 1 unica nota no servidor apos o retry');
    r = await estadoRev(page);
    ok(r.notas.length === 1 && r.notas[0].cu === uidPerdido && r.notas[0].id === srv.notas[0].id_nota && r.notas[0].tem, 'item local criado a partir da resposta reaproveitada (uid, id_nota, imagem em memoria)');
    ok(await page.evaluate(() => uploadPendente === null), 'pendente limpo apos sucesso');
    await todasLidas(page);
    ok((await page.evaluate(() => window.__ocr.chamadas)) === 1, 'OCR rodou uma unica vez para a nota');
    ok(chamadas(srv, 'listar').length === 0, 'retry da mesma captura nao precisou de listar');
    // proxima nota: uid novo
    await capturar(page);
    proc = chamadas(srv, 'processar');
    ok(proc[2].body.uid !== uidPerdido && srv.notas.length === 2, 'nota seguinte usa outro uid; 2 notas no servidor');
    await fim(ctx);

    titulo('F7 - falha definitiva (400) descarta o uid; falha 5xx mantem');
    ctx = await abrir(browser, { width: 768, height: 1366 }); page = ctx.page; srv = ctx.srv;
    for (let i = 0; i < 5; i++) srv.notas.push({ id_nota: srv.proxId++, ordem: i + 1, numero: null, uid: 'ja_existente_' + i }); // limite
    await esperaCaptura(page);
    await page.evaluate(() => { state.notasNumeros.length = 0; }); // front nao sabe (forca o 400 do limite)
    await page.click('#btnCapturarNota'); await page.waitForSelector('#btnUsarImagem'); await page.click('#btnUsarImagem');
    await page.waitForFunction(() => !state.capturaNotaEmAndamento);
    r = await page.evaluate(() => ({ pend: uploadPendente, st: document.getElementById('scannerStatus').textContent }));
    ok(r.pend === null && /Refazer/.test(r.st), '400 do servidor: sem uid pendente, mensagem fixa (Refazer)');
    await fim(ctx);

    titulo('F7 - uid devolvido que nao casa com o enviado nao e usado');
    ctx = await abrir(browser, { width: 768, height: 1366 }); page = ctx.page; srv = ctx.srv;
    srv.uidDivergente = true;
    await capturarEUsar(page);
    r = await page.evaluate(() => ({ n: state.notasNumeros.length, pend: uploadPendente, st: document.getElementById('scannerStatus').textContent }));
    ok(r.n === 0 && r.pend === null, 'resposta com uid alheio: nenhuma nota local criada, pendente descartado');
    ok(!/uid_de_outra/.test(r.st), 'uid da resposta nunca aparece na UI');
    await fim(ctx);

    titulo('F7 - resposta sem uid (backend antigo) segue aceita');
    ctx = await abrir(browser, { width: 768, height: 1366 }); page = ctx.page; srv = ctx.srv;
    srv.semUidNaResposta = true;
    await capturar(page);
    r = await estadoRev(page);
    ok(r.notas.length === 1 && typeof r.notas[0].cu === 'string' && r.notas[0].id === 1, 'sem uid na resposta: nota criada com o uid enviado');
    await fim(ctx);

    titulo('api(): timeout por AbortController (so quando pedido)');
    ctx = await abrir(browser, { width: 768, height: 1366 }); page = ctx.page; srv = ctx.srv;
    r = await page.evaluate(async () => { const t0 = performance.now(); try { await api('x.php', 'travar', {}, { timeoutMs: 400 }); return { ok: true }; } catch (e) { return { nome: e.name, status: e.status === undefined, ms: Math.round(performance.now() - t0) }; } });
    ok(r.nome === 'AbortError' && r.status && r.ms >= 350 && r.ms < 3000, 'timeout aborta sem status em ~400 ms: ' + JSON.stringify(r));
    await fim(ctx);

    // =================== F7: Refazer / Finalizar com upload pendente ===================
    titulo('F7 - Refazer com pendente: nova captura reconcilia via listar (orfa recuperada COM foto)');
    ctx = await abrir(browser, { width: 768, height: 1366 }); page = ctx.page; srv = ctx.srv;
    srv.perderProcessar = 1;
    await capturarEUsar(page);
    const uidOrfa = await page.evaluate(() => uploadPendente.clientUid);
    await page.click('#btnRefazer');
    await capturar(page); // nova captura: resolve a orfa (listar) e envia a nova com outro uid
    r = await estadoRev(page);
    ok(chamadas(srv, 'listar').length === 1, 'listar chamado uma vez antes do novo envio');
    ok(srv.notas.length === 2 && r.notas.length === 2, 'servidor 2 notas, front 2 itens (nenhuma orfa invisivel): srv=' + srv.notas.length + ' front=' + r.notas.length);
    ok(r.notas.some(n => n.cu === uidOrfa && n.tem && !n.rec), 'orfa virou item local com a foto que estava em memoria');
    proc = chamadas(srv, 'processar');
    ok(proc.length === 2 && proc[1].body.uid !== uidOrfa, 'nova nota usou uid novo');
    await todasLidas(page);
    ok((await page.evaluate(() => window.__ocr.chamadas)) === 2, 'OCR rodou nas duas (orfa recuperada e nova)');
    await fim(ctx);

    titulo('F7 - Finalizar com pendente: reconcilia antes de abrir a revisao');
    ctx = await abrir(browser, { width: 768, height: 1366 }); page = ctx.page; srv = ctx.srv;
    await capturar(page);
    srv.perderProcessar = 1;
    await capturarEUsar(page);
    await page.click('#btnRefazer');
    await irRevisao(page);
    r = await estadoRev(page);
    ok(chamadas(srv, 'listar').length === 1 && r.notas.length === 2 && srv.notas.length === 2, 'Finalizar reconciliou: 2 itens na revisao, 2 no servidor');
    await fim(ctx);

    titulo('F7 - listar falha ao reconciliar: nao trava o fluxo; pendente mantido');
    ctx = await abrir(browser, { width: 768, height: 1366 }); page = ctx.page; srv = ctx.srv;
    await capturar(page);
    srv.perderProcessar = 1; srv.listarFalha = 1;
    await capturarEUsar(page);
    await page.click('#btnRefazer');
    await irRevisao(page);
    r = await page.evaluate(() => ({ tela: state.tela, n: state.notasNumeros.length, pend: !!uploadPendente, fin: state.finalizandoDigitalizacao }));
    ok(r.tela === 'rec_revisao_numeros' && r.n === 1 && r.pend && !r.fin, 'revisao abriu mesmo com listar falho; pendente mantido; nada travado: ' + JSON.stringify(r));
    await fim(ctx);

    // =================== F1: 409 ===================
    titulo('F1 - 409 OCR_EM_ANDAMENTO: revisao aberta, mensagem, destaque neutro, nova tentativa');
    ctx = await abrir(browser, { width: 768, height: 1366 }, '?medir=1'); page = ctx.page; srv = ctx.srv;
    for (let i = 0; i < 3; i++) await capturar(page);
    await todasLidas(page);
    await irRevisao(page);
    await confirmarTodas(page);
    srv.concluirFila.push(r409([2]));
    await page.click('#btnContinuarRevisao');
    await page.waitForFunction(() => !state.finalizandoDigitalizacao && document.getElementById('revisaoAguarde').style.display !== 'none', { timeout: 5000 });
    r = await estadoRev(page);
    ok(r.tela === 'rec_revisao_numeros', '409: continua na revisao (nao avancou)');
    ok(r.aguarde.txt === MSG_409, '409: mensagem fixa em PT: ' + r.aguarde.txt);
    ok(r.erro.disp === 'none', '409: NAO usa o campo de erro');
    ok(r.btnDisabled === false, '409: Continuar reabilitado');
    ok(r.notas.map(n => n.aguardando).join() === 'false,true,false', '409: so a nota da ordem 2 fica em destaque neutro');
    const vis = await page.evaluate(() => {
        const cartoes = Array.from(document.querySelectorAll('#revisaoLista .rev-cartao'));
        const c2 = cartoes[1]; const selo = c2.querySelector('.rev-selo');
        const cs = getComputedStyle(c2), ss = getComputedStyle(selo), ag = getComputedStyle(document.getElementById('revisaoAguarde'));
        return { cls: c2.className, borda: cs.borderTopColor, seloBg: ss.backgroundColor, seloBorda: ss.borderTopColor, seloTxt: selo.textContent.trim(), numero: c2.querySelector('.rev-numero').textContent, aguardeBorda: ag.borderTopColor, aguardeCor: ag.color, outra: getComputedStyle(cartoes[0]).borderTopColor };
    });
    const vermelho = c => { const [rr, gg, bb] = rgbDe(c); return rr > 140 && gg < 80 && bb < 80; };
    ok(!vermelho(vis.borda) && !vermelho(vis.seloBg) && !vermelho(vis.seloBorda) && !vermelho(vis.aguardeBorda), 'destaque e aviso do 409 NAO sao vermelhos: ' + JSON.stringify(vis));
    ok(/Leitura em andamento/.test(vis.seloTxt) && /^\d+$/.test(vis.numero), 'selo "Leitura em andamento" e o numero da nota segue visivel: ' + vis.seloTxt + ' / ' + vis.numero);
    ok(/rev-cartao-aguardando/.test(vis.cls) && !/rev-cartao-destaque/.test(vis.cls), 'classe propria (nao a do 422)');
    ok(chamadas(srv, 'listar').length === 1, '409: reconciliou via listar uma vez');
    // sem retry automatico
    await espera(1500);
    ok(chamadas(srv, 'concluir-digitalizacao').length === 1, '409: nenhum retry automatico (1 chamada apos 1,5 s)');
    // abrir a nota limpa o destaque e o aviso
    await abreModal(page, 1);
    r = await estadoRev(page);
    ok(r.notas[1].aguardando === false && r.aguarde.disp === 'none', 'abrir a nota limpa o destaque e o aviso (era a unica apontada)');
    await page.click('#btnVoltarModalNota');
    // nova tentativa (409 de novo) e depois sucesso
    srv.concluirFila.push(r409([1, 3]));
    await page.click('#btnContinuarRevisao');
    await page.waitForFunction(() => !state.finalizandoDigitalizacao && document.getElementById('revisaoAguarde').style.display !== 'none', { timeout: 5000 });
    r = await estadoRev(page);
    ok(r.notas.map(n => n.aguardando).join() === 'true,false,true' && r.tela === 'rec_revisao_numeros', '2o 409: ordens 1 e 3 em destaque (o anterior sumiu)');
    ok(chamadas(srv, 'concluir-digitalizacao').length === 2, 'segunda chamada so por toque do motorista');
    await page.click('#btnContinuarRevisao'); // 200 por padrao
    await page.waitForFunction(() => state.tela === 'rec_cnh_modo', { timeout: 5000 });
    ok(chamadas(srv, 'concluir-digitalizacao').length === 3 && await page.evaluate(() => avisoOcrEmAndamento === false || state.tela !== 'rec_revisao_numeros'), '409 seguido de sucesso: avanca para a escolha do modo da CNH');
    // medicao sem dado sensivel
    const med = await page.evaluate(() => JSON.stringify(medirBuffer) + JSON.stringify(medirResumo()));
    const uidsEnviados = chamadas(srv, 'processar').map(c => c.body.uid);
    ok(!uidsEnviados.some(u => med.includes(u)) && !/NUMERO|ordens_em|OCR_EM_ANDAMENTO|TOKEN-FALSO/.test(med), '?medir=1: nenhum uid, ordem, codigo ou token nas medidas');
    await fim(ctx);

    titulo('F1 - 409 de outros codigos e o 409 de numero duplicado seguem como antes');
    ctx = await abrir(browser, { width: 768, height: 1366 }); page = ctx.page; srv = ctx.srv;
    await capturar(page); await todasLidas(page); await irRevisao(page); await confirmarTodas(page);
    for (const corpo of [resp(409, false, { sucesso: false, erro: 'Atendimento concluido', codigo: 'OUTRO_CODIGO' }), resp(409, false, { sucesso: false, erro: 'Conflito sem codigo' }), resp(409, false, { sucesso: false, erro: 'x', codigo: 'OCR_EM_ANDAMENTO_X' })]) {
        srv.concluirFila.push(corpo);
        await page.click('#btnContinuarRevisao');
        await page.waitForFunction(() => !state.finalizandoDigitalizacao, { timeout: 5000 });
        r = await estadoRev(page);
        ok(r.erro.txt === 'Não foi possível continuar. Tente novamente.' && r.aguarde.disp === 'none' && r.btnDisabled === false && r.tela === 'rec_revisao_numeros', '409 de outro codigo = erro generico, sem aviso do OCR: ' + JSON.stringify(r.erro));
        ok(!r.erro.txt.includes('Conflito') && !r.erro.txt.includes('concluido'), 'texto do backend nunca exibido');
    }
    // definir-numero 409 duplicado
    await page.evaluate(() => { state.notasNumeros[0].confirmado = false; state.notasNumeros[0].estado = 'sem_sugestao'; atualizarRevisaoNumeros(); });
    await abreModal(page, 0);
    await page.evaluate(() => { document.getElementById('inputNumeroNota').value = ''; });
    for (const d of '99999') await page.evaluate(d => digitarNumeroNota(d), d);
    await page.click('#btnSalvarNumeroNota');
    await page.waitForFunction(() => /já foi usado/.test(document.getElementById('numeroNotaErro').textContent), { timeout: 5000 });
    ok(true, '409 NUMERO_NOTA_DUPLICADO do definir-numero continua com a mensagem propria');
    await fim(ctx);

    titulo('F1 - cancelamento durante o concluir: 409 tardio e ignorado');
    ctx = await abrir(browser, { width: 768, height: 1366 }); page = ctx.page; srv = ctx.srv;
    await capturar(page); await todasLidas(page); await irRevisao(page); await confirmarTodas(page);
    srv.concluirAtrasoMs = 600; srv.concluirFila.push(r409([1]));
    await page.click('#btnContinuarRevisao');
    await espera(100);
    await page.evaluate(() => novoAtendimento());
    await espera(1200);
    r = await page.evaluate(() => ({ tela: state.tela, av: avisoOcrEmAndamento, n: state.notasNumeros.length, listar: 0 }));
    ok(r.tela === 'lgpd' && r.av === false && r.n === 0, '409 tardio nao mexe no estado do atendimento novo: ' + JSON.stringify(r));
    ok(chamadas(srv, 'listar').length === 0, 'sem listar tardio apos cancelar (guard de geracao)');
    await fim(ctx);

    titulo('F1 - sem identificar-cliente tardio apos concluir');
    ctx = await abrir(browser, { width: 768, height: 1366 }); page = ctx.page; srv = ctx.srv;
    await page.evaluate(() => { window.__ocr.hold = true; });
    await capturar(page); await capturar(page);
    await irRevisao(page);
    for (let i = 0; i < 2; i++) { await abreModal(page, i); await digitaConfirma(page, 500 + i); }
    await page.click('#btnContinuarRevisao');
    await page.waitForFunction(() => state.tela === 'rec_cnh_modo', { timeout: 5000 });
    const idents0 = chamadas(srv, 'identificar-cliente').length;
    await page.evaluate(() => { const h = window.__ocr.held; window.__ocr.hold = false; while (h.length) h.shift()({ data: { text: 'NF-e\nN\u00ba 777\n' } }); });
    await espera(1000);
    ok(chamadas(srv, 'identificar-cliente').length === idents0, 'resultado do OCR depois do concluir e descartado (nenhum identificar-cliente tardio)');
    await fim(ctx);

    // =================== F7: 422 com nota desconhecida ===================
    titulo('F7 - 422 aponta nota que o front nao conhece: listar cria item corrigivel e excluivel (sem foto)');
    ctx = await abrir(browser, { width: 768, height: 1366 }); page = ctx.page; srv = ctx.srv;
    srv.perderProcessar = 1;
    await capturarEUsar(page);              // nota A: criada no servidor, front nao sabe
    await page.click('#btnRefazer');
    await page.evaluate(() => { uploadPendente = null; }); // simula perda do controle de pendente (reload logico): so o 422 pode revelar
    await capturar(page);                   // nota B normal (ordem 2)
    await todasLidas(page);
    await irRevisao(page);
    r = await estadoRev(page);
    ok(r.notas.length === 1 && srv.notas.length === 2, 'pre-condicao: front conhece 1, servidor tem 2');
    await confirmarTodas(page);
    srv.concluirFila.push(r422([1]));
    await page.click('#btnContinuarRevisao');
    await page.waitForFunction(() => !state.finalizandoDigitalizacao && state.notasNumeros.length === 2, { timeout: 5000 });
    r = await estadoRev(page);
    ok(chamadas(srv, 'listar').length === 1, '422: reconciliou via listar');
    const rec = r.notas.find(n => n.rec);
    ok(!!rec && rec.ordem === 1 && rec.id === srv.notas.find(n => n.ordem === 1).id_nota && !rec.tem && rec.destaque, '422: item local criado (uid, id_nota, ordem), sem foto, destacado: ' + JSON.stringify(rec));
    ok(r.notas.length === 2 && r.notas[1].rec === true && r.notas[1].ordem === 1 && r.notas[0].ordem === 2 && r.notas[0].uid === 1, 'item recuperado entra no FIM da lista local (posicoes das notas ja conhecidas e uid local 1 nao mudam)');
    const cartaoTxt = await page.evaluate(() => Array.from(document.querySelectorAll('#revisaoLista .rev-cartao')).map(c => ({ foto: c.querySelector('.rev-miniatura').textContent, tag: c.querySelector('.rev-miniatura').tagName, over: c.querySelector('.rev-miniatura').scrollWidth > c.querySelector('.rev-miniatura').clientWidth + 1 || c.querySelector('.rev-miniatura').scrollHeight > c.querySelector('.rev-miniatura').clientHeight + 1 })));
    const semFoto = cartaoTxt.find(c => c.tag === 'DIV');
    ok(semFoto && semFoto.foto === 'Foto indisponível' && !semFoto.over, 'cartao mostra "Foto indisponível" sem estouro: ' + JSON.stringify(semFoto));
    ok(r.erro.txt.includes('Falta o número') || r.erro.txt.includes('Faltam'), '422: mensagem fixa de destaque');
    ok(r.btnDisabled === true, 'Continuar bloqueado ate a nota recuperada ser conferida');
    const idsIdent = chamadas(srv, 'identificar-cliente').filter(c => c.body.id_nota === rec.id);
    ok(idsIdent.length === 1 && Array.isArray(idsIdent[0].body.cnpjs_candidatos) && idsIdent[0].body.cnpjs_candidatos.length === 0 && idsIdent[0].body.razao_social_candidata === null, 'nota recuperada: identificar-cliente com candidatos vazios (nao fica PENDENTE no servidor)');
    // abrir o modal: placeholder e corrigir numero por id_nota
    const idxRec = await page.evaluate(() => state.notasNumeros.findIndex(n => n.recuperada));
    await abreModal(page, idxRec);
    r = await page.evaluate(() => { const m = document.getElementById('notaModalMini'); return { tag: m.tagName, txt: m.textContent, temImg: !!document.querySelector('#modalCaixa img'), manual: !!document.getElementById('inputNumeroNota'), excluir: !!document.getElementById('btnExcluirNota') }; });
    ok(r.tag === 'DIV' && r.txt === 'Foto indisponível' && !r.temImg && r.manual && r.excluir, 'modal da nota recuperada: placeholder, digitacao manual e Excluir disponiveis: ' + JSON.stringify(r));
    await digitaConfirma(page, 4242);
    const defs = chamadas(srv, 'definir-numero').filter(c => String(c.body.numero) === '4242');
    ok(defs.length === 1 && defs[0].body.id_nota === rec.id && defs[0].body.ordem === undefined, 'definir-numero da nota recuperada por id_nota');
    srv.concluirFila.length = 0;
    await page.click('#btnContinuarRevisao');
    await page.waitForFunction(() => state.tela === 'rec_cnh_modo', { timeout: 5000 });
    ok(true, 'apos corrigir a nota recuperada, Continuar avanca');
    await fim(ctx);

    titulo('F7 - 422 com nota desconhecida: excluir a recuperada');
    ctx = await abrir(browser, { width: 768, height: 1366 }); page = ctx.page; srv = ctx.srv;
    await capturar(page);
    srv.notas.push({ id_nota: srv.proxId++, ordem: 2, numero: null, uid: 'orfa_sem_front_0002' });
    await todasLidas(page); await irRevisao(page); await confirmarTodas(page);
    srv.concluirFila.push(r422([2]));
    await page.click('#btnContinuarRevisao');
    await page.waitForFunction(() => !state.finalizandoDigitalizacao && state.notasNumeros.length === 2, { timeout: 5000 });
    const idxR = await page.evaluate(() => state.notasNumeros.findIndex(n => n.recuperada));
    await abreModal(page, idxR);
    await page.click('#btnExcluirNota'); await page.click('#btnExcluirSim');
    await page.waitForFunction(() => !state.excluindoNota && state.notasNumeros.length === 1, { timeout: 5000 });
    const exs = chamadas(srv, 'excluir');
    ok(exs.length === 1 && exs[0].body.id_nota === 2 && srv.notas.length === 1, 'nota recuperada excluida por id_nota; servidor sem a nota');
    // listar defasado nao ressuscita a nota excluida localmente
    srv.notas.push({ id_nota: 2, ordem: 2, numero: null, uid: 'orfa_sem_front_0002' });
    r = await page.evaluate(async () => { const ok = await reconciliarNotasComServidor(); return { ok, n: state.notasNumeros.length }; });
    ok(r.ok && r.n === 1, 'listar defasado (id ja excluido localmente) nao recria a nota');
    await fim(ctx);

    titulo('F7 - reconciliacao nao remove item local e mantem uid/ordem');
    ctx = await abrir(browser, { width: 768, height: 1366 }); page = ctx.page; srv = ctx.srv;
    await capturar(page); await capturar(page);
    const antes = await page.evaluate(() => state.notasNumeros.map(n => [n.uid, n.clientUid, n.idNota, n.ordem].join('|')).join(','));
    srv.notas.pop(); // servidor sem a 2a (inconsistencia): front nao remove sozinho
    r = await page.evaluate(async () => { await reconciliarNotasComServidor(); return state.notasNumeros.map(n => [n.uid, n.clientUid, n.idNota, n.ordem].join('|')).join(','); });
    ok(r === antes, 'reconciliar so adiciona/atualiza; nada local some, uid/clientUid/idNota/ordem preservados');
    // listar com dados invalidos e ignorado
    srv.notas.push({ id_nota: srv.proxId++, ordem: 9, numero: null, uid: 'ordem_invalida_9' }, { id_nota: srv.proxId++, ordem: 3, numero: null, uid: 'x y' });
    r = await page.evaluate(async () => { await reconciliarNotasComServidor(); return state.notasNumeros.length; });
    ok(r === 2, 'itens de listar com ordem fora de 1..5 ou uid invalido sao ignorados');
    await fim(ctx);

    titulo('Sensiveis: nada de uid, token, imagem ou ordens no console');
    const todoConsole = todosConsole.join('\n');
    ok(!/TOKEN-FALSO|data:image|ordens_em_processamento|ordens_pendentes|OCR_EM_ANDAMENTO/.test(todoConsole), 'console limpo de token, imagem e dados do 409/422');
    ok(!/[0-9a-f]{8}-[0-9a-f]{4}-/.test(todoConsole) && !/orfa_sem_front|ja_existente/.test(todoConsole), 'console sem uid');

    // =================== viewports ===================
    for (const vp of [{ width: 768, height: 1366 }, { width: 1152, height: 1846 }]) {
        titulo('Viewport ' + vp.width + 'x' + vp.height + ' - 409 com 5 notas e uma recuperada sem foto');
        ctx = await abrir(browser, vp); page = ctx.page; srv = ctx.srv;
        for (let i = 0; i < 4; i++) await capturar(page);
        srv.notas.push({ id_nota: srv.proxId++, ordem: 5, numero: null, uid: 'orfa_sem_front_0005' });
        await todasLidas(page); await irRevisao(page); await confirmarTodas(page);
        srv.concluirFila.push(r409([1, 2, 5]));
        await page.click('#btnContinuarRevisao');
        await page.waitForFunction(() => !state.finalizandoDigitalizacao && state.notasNumeros.length === 5, { timeout: 5000 });
        const g = await page.evaluate(() => {
            const vh = window.innerHeight, vw = window.innerWidth;
            const rd = document.querySelector('.rev-rodape').getBoundingClientRect();
            const btn = document.getElementById('btnContinuarRevisao').getBoundingClientRect();
            const ag = document.getElementById('revisaoAguarde');
            const agr = ag.getBoundingClientRect();
            const selos = Array.from(document.querySelectorAll('.rev-selo')).map(s => ({ w: s.scrollWidth, c: s.clientWidth, r: s.getBoundingClientRect().right }));
            const sf = document.querySelector('.rev-sem-foto');
            return {
                vh, vw, rodapeBase: Math.round(rd.bottom), btnTopo: Math.round(btn.top), btnBase: Math.round(btn.bottom), btnAltura: Math.round(btn.height),
                aguardeAltura: Math.round(agr.height), aguardeLinhas: Math.round(agr.height / 26), aguardeVisivel: agr.width > 0 && agr.right <= vw && agr.left >= 0,
                overflowX: document.documentElement.scrollWidth > vw, selosFora: selos.some(s => s.r > vw), selosCortados: selos.some(s => s.w > s.c + 1),
                semFoto: sf ? { w: sf.clientWidth, h: sf.clientHeight, sw: sf.scrollWidth, sh: sf.scrollHeight } : null,
                nAguardando: document.querySelectorAll('.rev-cartao-aguardando').length,
            };
        });
        console.log('  ' + vp.width + 'x' + vp.height + ': ' + JSON.stringify(g));
        ok(g.btnBase <= g.vh && g.btnTopo >= 0 && g.btnAltura >= 64, 'Continuar dentro da tela e com 64 px');
        ok(g.aguardeVisivel && g.aguardeAltura > 0, 'aviso do 409 visivel dentro da largura');
        ok(!g.overflowX && !g.selosFora && !g.selosCortados, 'sem overflow horizontal, selos "Leitura em andamento" sem corte');
        ok(g.semFoto && g.semFoto.sw <= g.semFoto.w + 1 && g.semFoto.sh <= g.semFoto.h + 1, 'placeholder "Foto indisponivel" sem estouro');
        ok(g.nAguardando === 3, '3 notas em destaque neutro');
        // modal de nota recuperada nos viewports
        const idxRc = await page.evaluate(() => state.notasNumeros.findIndex(n => n.recuperada));
        await abreModal(page, idxRc);
        const gm = await page.evaluate(() => { const c = document.getElementById('modalCaixa'); const m = document.getElementById('notaModalMini').getBoundingClientRect(); const rr = c.getBoundingClientRect(); return { h: Math.round(rr.height), vh: window.innerHeight, topo: Math.round(rr.top), base: Math.round(rr.bottom), sh: c.scrollHeight, ch: c.clientHeight, mW: Math.round(m.width), mSw: document.getElementById('notaModalMini').scrollWidth, mCw: document.getElementById('notaModalMini').clientWidth }; });
        ok(gm.sh <= gm.ch + 1 && gm.topo >= 0 && gm.base <= gm.vh && gm.mSw <= gm.mCw + 1, 'modal da nota recuperada sem corte nem estouro: ' + JSON.stringify(gm));
        await fim(ctx);
    }

    await browser.close();
    server.close();
    console.log('\nRequisicoes externas abortadas: ' + externos);
    console.log('pageerrors: ' + (errosTotal.length ? errosTotal.join(' | ') : 'nenhum'));
    ok(externos === 0, 'nenhuma requisicao externa');
    ok(errosTotal.length === 0, 'nenhum pageerror');
    console.log('\nRESULTADO: ' + passou + ' ok, ' + falhou + ' falha(s)');
    process.exit(falhou ? 1 : 0);
}
main().catch(e => { console.error('ERRO NO TESTE', e); process.exit(2); });
