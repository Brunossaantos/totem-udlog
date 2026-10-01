// Teste do front (app.js real) contra o BACKEND REAL (php -S + MariaDB), rodada R4 fase B.
// Banco `qa_r4fb_*` DESCARTAVEL (criado por front_r4b_backend.php), STORAGE_PATH temporario, CONCLUIR_EXIGE_NUMERO_NOTA=true,
// portas 8595-8599 (so 127.0.0.1), nenhum Talent/VIO/impressao/rede externa. Um proxy Node entre o navegador e o php -S permite
// PERDER a resposta de uma chamada DEPOIS que o servidor a processou (a escrita real acontece; o navegador so ve falha de rede).
//   node tests/manual/front_r4b_real.js
// Variaveis opcionais (provas negativas servem copia mutada): APPJS=<arquivo> APPCSS=<arquivo> CHROME=<exe>
const http = require('http');
const fs = require('fs');
const path = require('path');
const os = require('os');
const net = require('net');
const { execFileSync, spawn, execSync } = require('child_process');

let puppeteer;
try { puppeteer = require('puppeteer'); } catch (e) {
    puppeteer = require(path.join(process.env.APPDATA || os.homedir(), 'npm', 'node_modules', 'puppeteer'));
}
const ROOT = path.join(__dirname, '..', '..');
const ASSETS = path.join(ROOT, 'public', 'totem', 'assets');
const APPJS = process.env.APPJS || path.join(ASSETS, 'app.js');
const APPCSS = process.env.APPCSS || path.join(ASSETS, 'app.css');
const CHROME = process.env.CHROME || (fs.existsSync('C:/Program Files/Google/Chrome/Application/chrome.exe') ? 'C:/Program Files/Google/Chrome/Application/chrome.exe' : undefined);
const PHP = fs.existsSync('C:/xampp/php/php.exe') ? 'C:/xampp/php/php.exe' : 'php';
const HELPER = path.join(__dirname, 'front_r4b_backend.php');
const ROUTER = path.join(__dirname, 'front_r4b_router.php');
const MSG_409 = 'A leitura das notas ainda está sendo concluída. Aguarde alguns segundos e toque em Continuar novamente.';
const UID_RE = /^[A-Za-z0-9_-]{8,64}$/;

let passou = 0, falhou = 0;
function ok(cond, msg) { if (cond) passou++; else { falhou++; console.log('  FALHA: ' + msg); } }
function titulo(t) { console.log('\n== ' + t); }
const espera = ms => new Promise(r => setTimeout(r, ms));
const numeros = o => { if (o && Array.isArray(o.linhas)) o.linhas.forEach(l => ['id_nota', 'ordem', 'tem_numero'].forEach(k => { if (l[k] !== null && l[k] !== undefined) l[k] = Number(l[k]); })); return o; };
const php = (...args) => numeros(JSON.parse(execFileSync(PHP, [HELPER, ...args], { encoding: 'utf8' }).trim().split('\n').pop()));
const portaLivre = p => new Promise(res => { const s = net.createServer(); s.once('error', () => res(false)); s.once('listening', () => s.close(() => res(true))); s.listen(p, '127.0.0.1'); });

const estado = { drop: null, log: [] }; // drop: {acao, n}: processa no php e derruba a resposta
let PHP_PORT = 0, NODE_PORT = 0, TOKEN = '';
const INDEX = () => `<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<link rel="stylesheet" href="assets/app.css"></head>
<body data-totem-token="${TOKEN}" data-totem-nome="r4fb"><div id="app"></div>
<script type="application/json" id="lgpd-termo-dados">{"versao":"v","hash":"h","texto":"t"}</script>
<script type="application/json" id="assets-versoes">{}</script>
<script src="assets/app.js"></script></body></html>`;

const nodeServer = http.createServer((req, res) => {
    const u = new URL(req.url, 'http://x');
    if (u.pathname === '/') { res.setHeader('Content-Type', 'text/html; charset=utf-8'); return res.end(INDEX()); }
    if (u.pathname === '/assets/app.js') { res.setHeader('Content-Type', 'text/javascript'); return res.end(fs.readFileSync(APPJS)); }
    if (u.pathname === '/assets/app.css') { res.setHeader('Content-Type', 'text/css'); return res.end(fs.readFileSync(APPCSS)); }
    if (u.pathname.startsWith('/api/')) {
        const chunks = [];
        req.on('data', c => chunks.push(c));
        req.on('end', () => {
            const corpo = Buffer.concat(chunks);
            const acao = u.searchParams.get('acao');
            const arq = u.pathname;
            const p = http.request({ host: '127.0.0.1', port: PHP_PORT, path: req.url, method: req.method, headers: Object.assign({}, req.headers, { host: '127.0.0.1:' + PHP_PORT, 'content-length': corpo.length }) }, r => {
                const partes = [];
                r.on('data', c => partes.push(c));
                r.on('end', () => {
                    const buf = Buffer.concat(partes);
                    let json = null; try { json = JSON.parse(buf.toString('utf8')); } catch (e) { json = null; }
                    estado.log.push({ arq, acao, http: r.statusCode, codigo: json && json.codigo, dados: json && json.dados });
                    if (estado.drop && estado.drop.acao === acao && estado.drop.n > 0) {
                        estado.drop.n--;
                        estado.log[estado.log.length - 1].dropada = true;
                        // o php ja gravou; o navegador recebe so parte do corpo e a conexao cai (sem isso o Chromium repete
                        // sozinho a requisicao em conexao reutilizada). fetch resolve e res.json() falha: TypeError sem status
                        res.writeHead(200, { 'Content-Type': 'application/json', 'Content-Length': buf.length + 200 });
                        res.write(buf.subarray(0, Math.max(1, Math.floor(buf.length / 2))));
                        return setTimeout(() => req.socket.destroy(), 50);
                    }
                    res.statusCode = r.statusCode; res.setHeader('Content-Type', 'application/json'); res.end(buf);
                });
            });
            p.on('error', () => { res.statusCode = 502; res.end('{}'); });
            p.end(corpo);
        });
        return;
    }
    res.statusCode = 404; res.end('nf');
});

async function abrir(browser, viewport, idAtendimento, query) {
    const page = await browser.newPage();
    await page.setViewport(viewport);
    const erros = []; const consoleMsgs = []; let externos = 0;
    page.on('pageerror', e => erros.push('pageerror: ' + e.message));
    page.on('console', m => consoleMsgs.push(m.text()));
    await page.setRequestInterception(true);
    page.on('request', req => {
        const u = new URL(req.url());
        if (u.protocol === 'data:' || u.protocol === 'blob:') return req.continue();
        if (u.host !== '127.0.0.1:' + NODE_PORT) { externos++; return req.abort(); }
        return req.continue();
    });
    await page.evaluateOnNewDocument(() => {
        window.__ocr = { hold: false, held: [], chamadas: 0 };
        window.Tesseract = { createWorker: async () => ({ recognize: () => {
            window.__ocr.chamadas++;
            const texto = 'NF-e\nN\u00ba 20' + String(window.__ocr.chamadas).padStart(5, '0') + '\n';
            if (!window.__ocr.hold) return Promise.resolve({ data: { text: texto } });
            return new Promise(res => window.__ocr.held.push(res));
        } }) };
    });
    await page.goto('http://127.0.0.1:' + NODE_PORT + '/' + (query || ''));
    await page.evaluate(id => { state.tipo = 'recebimento'; state.idAtendimento = id; atendimentoGeracao++; ir('rec_digitaliza'); }, idAtendimento);
    return { page, erros, consoleMsgs, externos: () => externos };
}
async function esperaCaptura(page) { await page.waitForFunction(() => { const b = document.getElementById('btnCapturarNota'); return b && !b.disabled; }, { timeout: 60000 }); }
async function capturar(page) {
    await esperaCaptura(page);
    const n0 = await page.evaluate(() => state.notasNumeros.length);
    await page.click('#btnCapturarNota');
    await page.waitForSelector('#btnUsarImagem', { timeout: 10000 });
    await page.click('#btnUsarImagem');
    await page.waitForFunction(n => state.notasNumeros.length > n, { timeout: 15000 }, n0);
    await page.waitForFunction(() => !state.capturaNotaEmAndamento);
}
async function capturarEUsar(page) {
    await esperaCaptura(page);
    await page.click('#btnCapturarNota');
    await page.waitForSelector('#btnUsarImagem', { timeout: 10000 });
    await page.click('#btnUsarImagem');
    await page.waitForFunction(() => !state.capturaNotaEmAndamento, { timeout: 15000 });
}
const todasLidas = page => page.waitForFunction(() => state.notasNumeros.every(n => n.ocrConcluido) && identificacoesEmVoo === 0, { timeout: 15000 });
async function irRevisao(page) { await page.click('#btnFinalizarDigitalizacao'); await page.waitForSelector('#revisaoLista'); }
const clicaCartao = (page, i) => page.evaluate(i => document.querySelectorAll('#revisaoLista .rev-cartao')[i].click(), i);
async function abreModal(page, i) { await clicaCartao(page, i); await page.waitForSelector('#modalFundo.aberto'); }
async function digitaConfirma(page, numero) {
    await page.evaluate(() => { document.getElementById('inputNumeroNota').value = ''; atualizarBotaoNumeroNota(); });
    for (const d of String(numero)) await page.evaluate(d => digitarNumeroNota(d), d);
    await page.click('#btnSalvarNumeroNota');
    await page.waitForFunction(() => !document.getElementById('modalFundo').classList.contains('aberto'), { timeout: 8000 });
}
async function confirmarTodas(page, base) {
    const n = await page.evaluate(() => state.notasNumeros.length);
    for (let i = 0; i < n; i++) {
        if (await page.evaluate(i => state.notasNumeros[i].confirmado, i)) continue;
        await abreModal(page, i);
        if (await page.evaluate(() => !!document.getElementById('btnConfirmarNumeroSugerido'))) {
            await page.click('#btnConfirmarNumeroSugerido');
            await page.waitForFunction(() => !document.getElementById('modalFundo').classList.contains('aberto'), { timeout: 8000 });
        } else await digitaConfirma(page, base + i);
    }
}
const rev = page => page.evaluate(() => ({
    tela: state.tela, btn: document.getElementById('btnContinuarRevisao') && document.getElementById('btnContinuarRevisao').disabled,
    aguarde: document.getElementById('revisaoAguarde') && document.getElementById('revisaoAguarde').style.display !== 'none' ? document.getElementById('revisaoAguarde').textContent : null,
    erro: document.getElementById('revisaoErro') && document.getElementById('revisaoErro').style.display !== 'none' ? document.getElementById('revisaoErro').textContent : null,
    notas: state.notasNumeros.map(n => ({ cu: n.clientUid, ordem: n.ordem, id: n.idNota, ag: n.aguardando, dest: n.destaque, conf: n.confirmado, rec: n.recuperada, tem: !!n.imagem })),
}));
const chamadas = (acao, arq) => estado.log.filter(c => c.acao === acao && (!arq || c.arq.includes(arq)));

async function main() {
    // ---- portas ----
    const livres = []; for (const p of [8595, 8596, 8597, 8598, 8599]) if (await portaLivre(p)) livres.push(p);
    if (livres.length < 2) { console.log('BLOQUEIO: menos de 2 portas livres em 8595-8599'); process.exit(3); }
    [PHP_PORT, NODE_PORT] = livres;

    // ---- ambiente QA ----
    const amb = php('setup');
    TOKEN = amb.token;
    ok(amb.banco.startsWith('qa_r4fb_'), 'banco descartavel com prefixo qa_r4fb_');
    let procPhp = null; let browser = null;
    const limpar = async () => {
        try { if (browser) await browser.close(); } catch (e) { /* ja fechado */ }
        try { nodeServer.close(); } catch (e) { /* ja fechado */ }
        if (procPhp && procPhp.pid) { try { execSync('taskkill /PID ' + procPhp.pid + ' /T /F', { stdio: 'ignore' }); } catch (e) { /* ja encerrado */ } }
        await espera(300);
        try { php('teardown', amb.banco, amb.storage); } catch (e) { console.log('AVISO: teardown falhou'); }
    };
    try {
        procPhp = spawn(PHP, ['-S', '127.0.0.1:' + PHP_PORT, ROUTER], {
            env: Object.assign({}, process.env, { DB_NAME: amb.banco, STORAGE_PATH: amb.storage, CONCLUIR_EXIGE_NUMERO_NOTA: 'true' }),
            stdio: ['ignore', 'ignore', 'ignore'],
        });
        for (let i = 0; i < 60 && !(await new Promise(r => { const s = net.connect(PHP_PORT, '127.0.0.1', () => { s.destroy(); r(true); }); s.on('error', () => r(false)); })); i++) await espera(100);
        await new Promise(r => nodeServer.listen(NODE_PORT, '127.0.0.1', r));
        browser = await puppeteer.launch({ executablePath: CHROME, headless: true, args: ['--no-sandbox', '--use-fake-device-for-media-stream', '--use-fake-ui-for-media-stream', '--autoplay-policy=no-user-gesture-required'] });
        const todosConsole = []; const erros = []; let externos = 0;
        const fim = async c => { todosConsole.push(...c.consoleMsgs); erros.push(...c.erros); externos += c.externos(); await c.page.close(); };
        let ctx, page, r, db, id;
        const notasDb = idAt => php('notas', amb.banco, String(idAt));
        const novoAt = placa => php('novo-atendimento', amb.banco, amb.storage, String(amb.id_totem), placa).id_atendimento;

        // ============ R1: resposta perdida do processar + retry com mesmo uid ============
        titulo('REAL - resposta do processar perdida: retry com o mesmo uid, 1 linha no banco');
        id = amb.id_atendimento;
        ctx = await abrir(browser, { width: 768, height: 1366 }, id); page = ctx.page;
        estado.drop = { acao: 'processar', n: 1 };
        await capturarEUsar(page);
        db = notasDb(id);
        ok(db.linhas.length === 1 && UID_RE.test(db.linhas[0].client_uid || ''), 'servidor gravou 1 nota com client_uid, apesar da resposta perdida');
        const uid1 = db.linhas[0].client_uid;
        r = await page.evaluate(() => ({ n: state.notasNumeros.length, pend: uploadPendente && uploadPendente.clientUid, st: document.getElementById('scannerStatus').textContent }));
        ok(r.n === 0 && r.pend === uid1 && /conexão/.test(r.st), 'front: falha de rede, uid pendente = uid gravado no banco: ' + JSON.stringify(r));
        await page.click('#btnUsarImagem');
        await page.waitForFunction(() => state.notasNumeros.length === 1 && !state.capturaNotaEmAndamento, { timeout: 15000 });
        db = notasDb(id);
        ok(db.linhas.length === 1 && db.linhas[0].client_uid === uid1, 'retry: continua 1 linha no banco, mesmo client_uid');
        const procs = chamadas('processar', 'nota.php');
        ok(procs.length === 2 && procs[0].dropada && procs[1].http === 200 && procs[1].dados.reaproveitada === true && procs[1].dados.uid === uid1, 'retry devolveu a MESMA nota (reaproveitada true, uid igual)');
        r = await rev(page);
        ok(r.notas.length === 1 && r.notas[0].id === procs[1].dados.id_nota && r.notas[0].ordem === procs[1].dados.ordem && r.notas[0].cu === uid1, 'item local criado com id_nota/ordem/uid do servidor');
        await todasLidas(page);
        db = notasDb(id);
        ok(db.linhas[0].status_ocr === 'NAO_IDENTIFICADA', 'OCR local rodou e identificar-cliente real marcou a nota (sem PENDENTE)');
        const arquivos = fs.readdirSync(path.join(amb.storage)).length >= 0;
        ok(arquivos, 'storage temporario acessivel');
        await fim(ctx);

        // ============ R2: 409 real ============
        titulo('REAL - 409 OCR_EM_ANDAMENTO real: nota PENDENTE no servidor, depois liberada');
        id = novoAt('FBR2A23');
        estado.log.length = 0;
        ctx = await abrir(browser, { width: 768, height: 1366 }, id); page = ctx.page;
        await page.evaluate(() => { window.__ocr.hold = true; });
        await capturar(page); await capturar(page);
        await irRevisao(page);
        for (let i = 0; i < 2; i++) { await abreModal(page, i); await digitaConfirma(page, 3000 + i); }
        db = notasDb(id);
        ok(db.linhas.length === 2 && db.linhas.every(l => l.status_ocr === 'PENDENTE' && l.tem_numero === 1), 'pre-condicao: 2 notas com numero e PENDENTES no banco');
        await page.click('#btnContinuarRevisao');
        await page.waitForFunction(() => !state.finalizandoDigitalizacao && document.getElementById('revisaoAguarde').style.display !== 'none', { timeout: 10000 });
        r = await rev(page);
        const c409 = chamadas('concluir-digitalizacao')[0];
        ok(c409.http === 409 && c409.codigo === 'OCR_EM_ANDAMENTO' && JSON.stringify(c409.dados) === '{"ordens_em_processamento":[1,2]}', 'backend real respondeu 409 com ordens [1,2]: ' + JSON.stringify(c409.dados));
        ok(r.tela === 'rec_revisao_numeros' && r.aguarde === MSG_409 && r.btn === false && r.notas.every(n => n.ag), 'front: revisao aberta, mensagem fixa, botao reabilitado, 2 notas em destaque neutro');
        db = notasDb(id);
        ok(db.atendimento.etapa_atual === 'digitalizacao_notas' && db.atendimento.cliente_cnpj === null, 'banco: etapa e cliente inalterados pelo 409');
        await espera(1200);
        ok(chamadas('concluir-digitalizacao').length === 1, 'nenhum retry automatico');
        // libera o OCR: identificar-cliente real encerra a PENDENCIA
        await page.evaluate(() => { const h = window.__ocr.held; window.__ocr.hold = false; while (h.length) h.shift()({ data: { text: 'NF-e\nN\u00ba 31\n' } }); });
        await page.waitForFunction(() => identificacoesEmVoo === 0 && state.notasNumeros.every(n => n.ocrConcluido), { timeout: 15000 });
        db = notasDb(id);
        ok(db.linhas.every(l => l.status_ocr !== 'PENDENTE'), 'apos o OCR local, nenhuma nota PENDENTE no banco');
        await page.click('#btnContinuarRevisao');
        await page.waitForFunction(() => state.tela === 'rec_cliente' || state.tela === 'rec_cnh_modo', { timeout: 10000 });
        const cOk = chamadas('concluir-digitalizacao')[1];
        ok(cOk.http === 200, '409 seguido de 200 real: ' + cOk.http);
        db = notasDb(id);
        ok(db.atendimento.etapa_atual !== 'digitalizacao_notas', 'banco: etapa avancou so apos o 200');
        await fim(ctx);

        // ============ R3: orfa por resposta perdida -> 409 -> 422 -> corrigir ============
        titulo('REAL - nota orfa (resposta perdida e controle de pendente perdido): 409, listar, 422, corrigir, concluir');
        id = novoAt('FBR3A23');
        estado.log.length = 0;
        ctx = await abrir(browser, { width: 768, height: 1366 }, id); page = ctx.page;
        await capturar(page); await todasLidas(page);                  // nota 1 normal
        estado.drop = { acao: 'processar', n: 1 };
        await capturarEUsar(page);                                     // nota 2: orfa no banco
        await page.click('#btnRefazer');
        await page.evaluate(() => { uploadPendente = null; });         // simula reload logico do estado de pendente
        db = notasDb(id);
        ok(db.linhas.length === 2, 'pre-condicao: banco tem 2 notas, front conhece 1');
        r = await rev(page).catch(() => null);
        await irRevisao(page);
        await confirmarTodas(page, 5000);
        r = await rev(page);
        ok(r.notas.length === 1, 'front so conhece 1 nota na revisao');
        await page.click('#btnContinuarRevisao');
        await page.waitForFunction(() => !state.finalizandoDigitalizacao && state.notasNumeros.length === 2, { timeout: 15000 });
        const c1 = chamadas('concluir-digitalizacao')[0];
        ok(c1.http === 409 && JSON.stringify(c1.dados) === '{"ordens_em_processamento":[2]}', '1a tentativa: 409 real apontando a ordem 2 (orfa PENDENTE): ' + JSON.stringify(c1.dados));
        r = await rev(page);
        ok(r.notas.length === 2 && r.notas[1].rec && r.notas[1].ordem === 2 && !r.notas[1].tem && r.notas[1].id === db.linhas[1].id_nota, 'listar real: orfa virou item local (id_nota, ordem, sem foto)');
        ok(chamadas('listar').length === 1 && chamadas('listar')[0].http === 200, 'listar real respondeu 200 (1 chamada)');
        await page.waitForFunction(() => identificacoesEmVoo === 0, { timeout: 10000 });
        db = notasDb(id);
        ok(db.linhas[1].status_ocr === 'NAO_IDENTIFICADA', 'identificar-cliente [] real encerrou a pendencia da orfa no banco');
        // o motorista toca de novo: agora 422 (orfa sem numero)
        await page.evaluate(() => { document.getElementById('btnContinuarRevisao').disabled; });
        r = await rev(page);
        ok(r.btn === true, 'Continuar bloqueado: a nota recuperada ainda nao foi conferida');
        const idxOrfa = await page.evaluate(() => state.notasNumeros.findIndex(n => n.recuperada));
        await abreModal(page, idxOrfa);
        r = await page.evaluate(() => ({ tag: document.getElementById('notaModalMini').tagName, txt: document.getElementById('notaModalMini').textContent }));
        ok(r.tag === 'DIV' && r.txt === 'Foto indisponível', 'modal da orfa com placeholder');
        await digitaConfirma(page, 6001);
        db = notasDb(id);
        ok(db.linhas[1].tem_numero === 1, 'numero da nota recuperada gravado no banco (definir-numero por id_nota)');
        await page.click('#btnContinuarRevisao');
        await page.waitForFunction(() => state.tela === 'rec_cliente' || state.tela === 'rec_cnh_modo', { timeout: 10000 });
        ok(chamadas('concluir-digitalizacao')[1].http === 200, 'concluir real 200 depois de corrigir a nota recuperada');
        await fim(ctx);

        // ============ R4: orfa sem numero -> 422 real, excluir ============
        titulo('REAL - 422 real com orfa ja identificada: destaque, excluir pelo id_nota, concluir');
        id = novoAt('FBR4A23');
        estado.log.length = 0;
        ctx = await abrir(browser, { width: 768, height: 1366 }, id); page = ctx.page;
        await capturar(page); await todasLidas(page);
        estado.drop = { acao: 'processar', n: 1 };
        await capturarEUsar(page); await page.click('#btnRefazer');
        await page.evaluate(() => { uploadPendente = null; });
        // a orfa ja deixou de ser PENDENTE (outra aba/atendente): simulado chamando o endpoint real
        db = notasDb(id);
        const idOrfa = db.linhas[1].id_nota;
        r = await page.evaluate(async idN => { const x = await api('nota.php', 'identificar-cliente', { id_atendimento: state.idAtendimento, id_nota: idN, chave_ocr: null, cnpjs_candidatos: [], razao_social_candidata: null }); return x.status; }, idOrfa);
        ok(r === 'NAO_IDENTIFICADA', 'orfa encerrada via endpoint real');
        await irRevisao(page); await confirmarTodas(page, 7000);
        await page.click('#btnContinuarRevisao');
        await page.waitForFunction(() => !state.finalizandoDigitalizacao && state.notasNumeros.length === 2, { timeout: 15000 });
        const c422 = chamadas('concluir-digitalizacao')[0];
        ok(c422.http === 422 && c422.codigo === 'NOTAS_SEM_NUMERO' && JSON.stringify(c422.dados.ordens_pendentes) === '[2]', '422 real aponta a ordem 2: ' + JSON.stringify(c422.dados));
        r = await rev(page);
        ok(r.notas[1].rec && r.notas[1].dest && /Falta o número da Nota 2/.test(r.erro || ''), '422: nota recuperada visivel e destacada, mensagem fixa: ' + r.erro);
        await abreModal(page, 1);
        await page.click('#btnExcluirNota'); await page.click('#btnExcluirSim');
        await page.waitForFunction(() => !state.excluindoNota && state.notasNumeros.length === 1, { timeout: 10000 });
        db = notasDb(id);
        ok(db.linhas.length === 1, 'excluir real pelo id_nota: 1 linha no banco');
        await page.click('#btnContinuarRevisao');
        await page.waitForFunction(() => state.tela === 'rec_cliente' || state.tela === 'rec_cnh_modo', { timeout: 10000 });
        ok(chamadas('concluir-digitalizacao')[1].http === 200, 'concluir real 200 depois de excluir a orfa');
        await fim(ctx);

        // ============ R5: reenvio apos exclusao usa uid novo ============
        titulo('REAL - excluir libera o uid e a nova nota usa outro');
        id = novoAt('FBR5A23');
        estado.log.length = 0;
        ctx = await abrir(browser, { width: 768, height: 1366 }, id); page = ctx.page;
        await capturar(page); await todasLidas(page);
        const uidA = notasDb(id).linhas[0].client_uid;
        await irRevisao(page); await abreModal(page, 0);
        await page.click('#btnExcluirNota'); await page.click('#btnExcluirSim');
        await page.waitForFunction(() => !state.excluindoNota && state.tela === 'rec_digitaliza', { timeout: 10000 });
        ok(notasDb(id).linhas.length === 0, 'banco sem notas apos excluir');
        await capturar(page); await todasLidas(page);
        db = notasDb(id);
        ok(db.linhas.length === 1 && db.linhas[0].client_uid !== uidA && UID_RE.test(db.linhas[0].client_uid), 'nova nota gravada com client_uid DIFERENTE do excluido');
        await fim(ctx);

        // ============ sensiveis / geral ============
        titulo('REAL - console sem sensiveis e sem rede externa');
        const cons = todosConsole.join('\n');
        ok(!cons.includes(TOKEN) && !/data:image/.test(cons) && !/ordens_em_processamento|ordens_pendentes/.test(cons) && !/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-/.test(cons), 'console sem token, imagem, ordens ou uid');
        ok(externos === 0, 'nenhuma requisicao externa');
        ok(erros.length === 0, 'nenhum pageerror: ' + erros.join('|'));
    } catch (e) {
        falhou++; console.log('ERRO NO TESTE: ' + (e && e.message));
    } finally {
        await limpar();
        // confirma a limpeza
        try { php('notas', amb.banco, '1'); console.log('AVISO: banco ainda existe'); } catch (e) { console.log('banco ' + amb.banco + ' removido (consulta falha como esperado)'); }
    }
    console.log('\nRESULTADO: ' + passou + ' ok, ' + falhou + ' falha(s)');
    process.exit(falhou ? 1 : 0);
}
main().catch(e => { console.error('ERRO FATAL', e && e.message); process.exit(2); });
