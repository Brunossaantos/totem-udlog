// Regressao local QR-only de CNH/CRLV. Nao usa camera fisica, banco, storage
// ou API: simula apenas o frame e a leitura local no navegador headless.
//   node tests/manual/vio_captura_layout.js
const http = require('http');
const fs = require('fs');
const path = require('path');
const os = require('os');
const { qrSintetico } = require('./qr_sintetico.js');

let puppeteer;
try { puppeteer = require('puppeteer'); } catch (e) {
    puppeteer = require(path.join(process.env.APPDATA || os.homedir(), 'npm', 'node_modules', 'puppeteer'));
}
const RAIZ = path.join(__dirname, '..', '..');
const APPJS = path.join(RAIZ, 'public', 'totem', 'assets', 'app.js');
const IMPRJS = path.join(RAIZ, 'public', 'totem', 'assets', 'impressao.js');
const APPCSS =path.join(RAIZ, 'public', 'totem', 'assets', 'app.css');
const CHROME = process.env.CHROME || (fs.existsSync('C:/Program Files/Google/Chrome/Application/chrome.exe') ? 'C:/Program Files/Google/Chrome/Application/chrome.exe' : undefined);
const INDEX = `<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/app.css"></head><body data-totem-token="TESTE" data-totem-nome="qa"><div id="app"></div><script type="application/json" id="lgpd-termo-dados">{"versao":"t","hash":"t","texto":"t"}</script><script type="application/json" id="assets-versoes">{}</script><script src="/assets/app.js"></script><script src="/assets/impressao.js"></script></body></html>`;

let porta = 0, passou = 0, falhou = 0, externos = 0;
let CSS_HEAD = null;
try { CSS_HEAD = require('child_process').execFileSync('git', ['show', 'HEAD:public/totem/assets/app.css'], { cwd: RAIZ, encoding: 'utf8', maxBuffer: 1 << 24 }); } catch (e) { CSS_HEAD = null; }
const MATRIZ_TESTE = qrSintetico(7, 4242); // QR aleatorio sintetico (sem dado real)
function ok(cond, texto) { if (cond) passou++; else { falhou++; console.log('FALHA: ' + texto); } }
const servidor = http.createServer((req, res) => {
    if (req.url === '/') { res.setHeader('Content-Type', 'text/html; charset=utf-8'); return res.end(INDEX); }
    if (req.url === '/assets/app.js') { res.setHeader('Content-Type', 'text/javascript'); return res.end(fs.readFileSync(APPJS)); }
    if (req.url === '/assets/impressao.js') { res.setHeader('Content-Type', 'text/javascript'); return res.end(fs.readFileSync(IMPRJS)); }
    if (req.url === '/assets/app.css') { res.setHeader('Content-Type', 'text/css'); return res.end(fs.readFileSync(APPCSS)); }
    const assetsTotem = path.join(RAIZ, 'public', 'totem', 'assets');
    const estaticos = { '/assets/qr-worker.js': 'qr-worker.js', '/assets/qr-leitura.js': 'qr-leitura.js', '/assets/vendor/jsqr/jsQR.js': path.join('vendor', 'jsqr', 'jsQR.js') };
    if (estaticos[req.url]) { res.setHeader('Content-Type', 'text/javascript'); return res.end(fs.readFileSync(path.join(assetsTotem, estaticos[req.url]))); }
    res.statusCode = 404; res.end('nao encontrado');
});

async function exercitarQr(page) {
    return page.evaluate(async () => {
        const app = document.getElementById('app');
        const resultados = {};
        const prepararVideo = id => {
            const video = document.getElementById(id);
            Object.defineProperty(video, 'readyState', { configurable: true, value: 4 });
            Object.defineProperty(video, 'videoWidth', { configurable: true, value: 3264 });
            Object.defineProperty(video, 'videoHeight', { configurable: true, value: 2448 });
            return video;
        };
        const caixa = id => {
            const r = document.getElementById(id).getBoundingClientRect();
            return { w: Math.round(r.width), h: Math.round(r.height) };
        };

        // Quatro telas reais QR-only, sem pre-visualizacao/documento completo.
        app.innerHTML = telaExpCnhQr();
        prepararVideo('expCamVideo');
        configurarProporcaoCameraDocumento('exp', document.getElementById('expCamVideo'), 'expCamCaixa', 'expCamPreview');
        resultados.expCnh = { caixa: caixa('expCamCaixa'), guia: !!document.querySelector('#expCamCaixa .guia-qr'), botao: document.getElementById('expCamBtnCapturar')?.textContent };
        limparProporcaoCameraDocumento('exp');
        app.innerHTML = telaExpCrlvQr();
        resultados.expCrlv = { guia: !!document.querySelector('#expCamCaixa .guia-qr'), botao: document.getElementById('expCamBtnCapturar')?.textContent };
        app.innerHTML = telaRecCnhQr();
        resultados.recCnh = { guia: !!document.querySelector('#recCamCaixa .guia-qr'), botao: document.getElementById('recCamBtnCapturar')?.textContent };
        app.innerHTML = telaRecCrlvQr();
        prepararVideo('recCamVideo');
        configurarProporcaoCameraDocumento('rec', document.getElementById('recCamVideo'), 'recCamCaixa', 'recCamPreview');
        resultados.recCrlv = { caixa: caixa('recCamCaixa'), guia: !!document.querySelector('#recCamCaixa .guia-qr'), botao: document.getElementById('recCamBtnCapturar')?.textContent };
        limparProporcaoCameraDocumento('rec');

        // Falha local nao toca backend; na terceira tentativa segue somente ao manual.
        app.innerHTML = telaExpCnhQr();
        prepararVideo('expCamVideo');
        state.exp = estadoExpVazio(); state.exp.emAndamento = false;
        let chamadasBackend = 0, telaManual = null;
        const original = {
            frame: window.expCamCapturarFrame, ler: window.lerQrDoCanvas,
            iniciar: window.iniciarProcessamentoDocumento, avancar: window.tentarAvancarEtapaDocumentos,
            ir: window.ir, atualizar: window.expCamAtualizarBotao,
        };
        window.expCamCapturarFrame = () => ({ toDataURL: () => 'data:image/jpeg;base64,QUJD' });
        window.lerQrDoCanvas = async () => ({ ok: false });
        window.iniciarProcessamentoDocumento = async () => { chamadasBackend++; };
        window.tentarAvancarEtapaDocumentos = async () => { chamadasBackend++; };
        window.ir = tela => { telaManual = tela; };
        window.expCamAtualizarBotao = () => {};
        await expCapturarQr('cnh'); await expCapturarQr('cnh');
        resultados.duasFalhas = { chamadasBackend, tentativas: state.exp.qrTentativas.cnh, telaManual };
        await expCapturarQr('cnh');
        resultados.tresFalhas = { chamadasBackend, tentativas: state.exp.qrTentativas.cnh, telaManual };

        // QR valido transmite somente o JPEG; binaryData nunca faz parte da chamada.
        state.exp = estadoExpVazio(); state.exp.emAndamento = false; telaManual = null;
        let payload = null, chamadasInicio = 0;
        window.lerQrDoCanvas = async () => ({ ok: true, binaryData: new Uint8ClampedArray([0, 1, 2]) });
        window.iniciarProcessamentoDocumento = async (...args) => { chamadasInicio++; payload = args; return { terminal: true }; };
        window.tentarAvancarEtapaDocumentos = async () => {};
        await expCapturarQr('cnh');
        resultados.qrValido = {
            chamadasInicio,
            tipo: payload && payload[1],
            jpeg: payload && payload[2],
            temBinaryData: !!(payload && JSON.stringify(payload).includes('binaryData')),
            tentativas: state.exp.qrTentativas.cnh,
        };
        Object.assign(window, { expCamCapturarFrame: original.frame, lerQrDoCanvas: original.ler, iniciarProcessamentoDocumento: original.iniciar, tentarAvancarEtapaDocumentos: original.avancar, ir: original.ir, expCamAtualizarBotao: original.atualizar });
        return resultados;
    });
}

async function exercitarQrLeitura(page, matriz) {
    return page.evaluate(async (matriz) => {
        const r = {};
        const app = document.getElementById('app');
        const preparar = id => {
            const video = document.getElementById(id);
            Object.defineProperty(video, 'readyState', { configurable: true, value: 4 });
            Object.defineProperty(video, 'videoWidth', { configurable: true, value: 3264 });
            Object.defineProperty(video, 'videoHeight', { configurable: true, value: 2448 });
            return video;
        };
        // ---- guia: quadrado de foco com cantos, centralizado, 36% da altura ----
        r.guias = [];
        for (const [fn, pre] of [[telaExpCnhQr, 'exp'], [telaExpCrlvQr, 'exp'], [telaRecCnhQr, 'rec'], [telaRecCrlvQr, 'rec']]) {
            app.innerHTML = fn();
            const video = preparar(pre + 'CamVideo');
            configurarProporcaoCameraDocumento(pre, video, pre + 'CamCaixa', pre + 'CamPreview');
            const caixa = document.getElementById(pre + 'CamCaixa');
            const g = caixa.querySelector('.guia-qr');
            const cr = caixa.getBoundingClientRect(), gr = g.getBoundingClientRect();
            const btn = document.getElementById(pre + 'CamBtnCapturar').getBoundingClientRect();
            const antes = getComputedStyle(g, '::before'), depois = getComputedStyle(g, '::after');
            r.guias.push({
                pre, antigaGuia: !!caixa.querySelector('.guia-scanner'), aria: g.getAttribute('aria-hidden'), pe: getComputedStyle(g).pointerEvents,
                ladoH: gr.height / cr.height, quadrado: Math.abs(gr.width - gr.height) <= 1,
                cx: (gr.left + gr.width / 2 - cr.left) / cr.width, cy: (gr.top + gr.height / 2 - cr.top) / cr.height,
                dentro: gr.left - 3 >= cr.left && gr.right + 3 <= cr.right && gr.top - 3 >= cr.top && gr.bottom + 3 <= cr.bottom,
                ladoPx: Math.round(gr.height), caixaW: Math.round(cr.width), caixaH: Math.round(cr.height),
                cantos: depois.backgroundImage.split('linear-gradient').length - 1 === 8 && antes.backgroundImage.split('linear-gradient').length - 1 === 8,
                corAzul: depois.backgroundImage.includes('rgb(1, 121, 173)'),
                semOverflow: document.documentElement.scrollWidth <= innerWidth && cr.left >= 0 && cr.right <= innerWidth,
                botaoAlt: Math.round(btn.height), botaoDentro: btn.bottom <= innerHeight && btn.top >= 0,
                subtitulo: app.querySelector('.subtitulo').textContent,
                miolo: g.children.length === 0,
            });
            limparProporcaoCameraDocumento(pre);
        }

        // ---- contador por toque (nao por frame), 0 rede sem QR, payload inalterado ----
        const orig = {
            frame: window.expCamCapturarFrame, ler: window.lerQrDoCanvas, iniciar: window.iniciarProcessamentoDocumento,
            avancar: window.tentarAvancarEtapaDocumentos, ir: window.ir, atualizar: window.expCamAtualizarBotao,
            recFrame: window.recCamCapturarFrame, recAtualizar: window.recCamAtualizarBotao, fetch: window.fetch,
        };
        let frames = 0, lidos = 0, backend = 0, redes = 0, tela = null, payload = null, aoLer = null;
        window.fetch = (...a) => { redes++; return orig.fetch(...a); };
        window.ir = t => { tela = t; };
        window.expCamAtualizarBotao = () => {}; window.recCamAtualizarBotao = () => {};
        window.iniciarProcessamentoDocumento = async (...a) => { backend++; payload = a; return { terminal: true }; };
        window.tentarAvancarEtapaDocumentos = async () => {};
        const framesMock = () => ({ toDataURL: () => 'data:image/jpeg;base64,F' + frames });
        window.expCamCapturarFrame = () => { frames++; return framesMock(); };
        window.recCamCapturarFrame = () => { frames++; return framesMock(); };
        window.lerQrDoCanvas = async () => { lidos++; return aoLer ? aoLer(lidos) : { ok: false }; };
        const zerar = () => { frames = 0; lidos = 0; backend = 0; redes = 0; tela = null; payload = null; };
        const montar = (pre) => {
            app.innerHTML = pre === 'exp' ? telaExpCnhQr() : telaRecCnhQr();
            preparar(pre + 'CamVideo');
            state[pre] = pre === 'exp' ? estadoExpVazio() : estadoRecVazio(); state[pre].emAndamento = false;
        };
        for (const pre of ['exp', 'rec']) {
            const tocar = () => (pre === 'exp' ? expCapturarQr : recCapturarQr)('cnh');
            // (a) toque sem QR: 3 frames lidos, UMA tentativa consumida, nada no backend/rede
            montar(pre); zerar(); aoLer = null;
            await tocar();
            r[pre + 'A'] = { frames, lidos, tentativas: state[pre].qrTentativas.cnh, backend, redes, tela, status: document.getElementById(pre + 'CamStatus').textContent };
            // (b) segundo toque conta 2; terceiro vai ao manual (3 tentativas por documento, 9 frames no total)
            await tocar(); const aposDois = state[pre].qrTentativas.cnh;
            await tocar();
            r[pre + 'B'] = { aposDois, tentativas: state[pre].qrTentativas.cnh, frames, tela, backend, redes };
            // (c) QR no 2o frame: para ali, envia o JPEG DESSE frame, contador zerado
            montar(pre); zerar(); state[pre].qrTentativas.cnh = 2; aoLer = (n) => ({ ok: n === 2 });
            await tocar();
            r[pre + 'C'] = { frames, lidos, backend, payloadN: payload && payload.length, tipo: payload && payload[1], jpeg: payload && payload[2], cb: payload && typeof payload[3], json: JSON.stringify(payload && payload.slice(0, 3)), tentativas: state[pre].qrTentativas.cnh };
            // (d) QR no 1o frame: nenhum frame extra
            montar(pre); zerar(); aoLer = () => ({ ok: true });
            await tocar();
            r[pre + 'D'] = { frames, lidos, backend };
            // (e) tela trocada durante a leitura: sem tentativa consumida, sem backend, sem novo frame
            montar(pre); zerar(); aoLer = () => { app.innerHTML = ''; return { ok: false }; };
            await tocar();
            r[pre + 'E'] = { frames, backend, tentativas: state[pre].qrTentativas.cnh, tela, emAndamento: state[pre].emAndamento };
            aoLer = null;
        }
        Object.assign(window, { lerQrDoCanvas: orig.ler, expCamCapturarFrame: orig.frame, recCamCapturarFrame: orig.recFrame, iniciarProcessamentoDocumento: orig.iniciar, tentarAvancarEtapaDocumentos: orig.avancar, ir: orig.ir, expCamAtualizarBotao: orig.atualizar, recCamAtualizarBotao: orig.recAtualizar, fetch: orig.fetch });

        // ---- lerQrDoCanvas: mensagem ao worker (guia + orcamento) e resposta somente {ok} ----
        const msgs = [];
        const falso = { postMessage(m) { msgs.push(m); const id = m.id; setTimeout(() => this.onmessage({ data: { ok: true, id, binaryData: [1, 2, 3] } }), 0); } };
        const WorkerReal = window.Worker; qrWorker = null;
        window.Worker = function () { return falso; };
        const cv = document.createElement('canvas'); cv.width = 64; cv.height = 48;
        const ler1 = await lerQrDoCanvas(cv);
        r.worker = { chaves: Object.keys(ler1).join(','), ok: ler1.ok, guia: msgs[0] && JSON.stringify(msgs[0].guia), orc: msgs[0] && msgs[0].orcamentoMs, dim: msgs[0] && msgs[0].width + 'x' + msgs[0].height, buf: msgs[0] && msgs[0].data instanceof Uint8ClampedArray };
        // watchdog: worker mudo nao prende a tela
        qrWorker = null;
        window.Worker = function () { return { postMessage() {} }; };
        const t0 = performance.now();
        const ler2 = await lerQrDoCanvas(cv);
        r.watchdog = { ok: ler2.ok, ms: Math.round(performance.now() - t0), timeout: QR_TIMEOUT_WORKER_MS };
        window.Worker = WorkerReal; qrWorker = null;

        // ---- worker REAL: QR sintetico na guia, fora da guia (quadro inteiro) e quadro vazio ----
        const desenhar = (cx, cy, lado, comQr) => {
            const c = document.createElement('canvas'); c.width = 1632; c.height = 1224;
            const x = c.getContext('2d'); x.fillStyle = '#fff'; x.fillRect(0, 0, c.width, c.height);
            if (comQr) {
                const n = matriz.length, q = 4, m = lado / n;
                x.fillStyle = '#fff'; x.fillRect(cx - lado / 2 - q * m, cy - lado / 2 - q * m, lado + 2 * q * m, lado + 2 * q * m);
                x.fillStyle = '#111';
                for (let yy = 0; yy < n; yy++) for (let xx = 0; xx < n; xx++) if (matriz[yy][xx]) x.fillRect(cx - lado / 2 + xx * m - 0.3, cy - lado / 2 + yy * m - 0.3, m + 0.6, m + 0.6);
            }
            return c;
        };
        const medir = async (c) => { const t = performance.now(); const res = await lerQrDoCanvas(c); return { ok: res.ok, chaves: Object.keys(res).join(','), ms: Math.round(performance.now() - t) }; };
        r.real = {
            guia: await medir(desenhar(816, 612, 380, true)),
            fora: await medir(desenhar(300, 260, 320, true)),
            vazio: await medir(desenhar(0, 0, 0, false)),
        };

        // ---- worker REAL: validacao da entrada do postMessage ----
        const wv = new Worker('/assets/qr-worker.js');
        const enviar = (msg, ms) => new Promise(resolve => {
            const t = setTimeout(() => { wv.onmessage = null; resolve({ timeout: true }); }, ms || 4000);
            wv.onmessage = e => { clearTimeout(t); resolve({ d: e.data, chaves: Object.keys(e.data).sort().join(',') }); };
            wv.onerror = () => { clearTimeout(t); resolve({ erro: true }); };
            wv.postMessage(msg);
        });
        const quadroBase = (id, extra) => {
            const c = desenhar(816, 612, 380, true);
            const idata = c.getContext('2d').getImageData(0, 0, c.width, c.height);
            return Object.assign({ id, data: idata.data, width: idata.width, height: idata.height, guia: { cx: 0.5, cy: 0.5, lado: 0.36 }, orcamentoMs: 300 }, extra || {});
        };
        const pequeno = (id, extra) => Object.assign({ id, data: new Uint8ClampedArray(16 * 16 * 4), width: 16, height: 16, guia: { cx: 0.5, cy: 0.5, lado: 0.36 }, orcamentoMs: 300 }, extra || {});
        const invalidos = {
            guiaAusente: pequeno('i1', { guia: undefined }),
            guiaNaN: pequeno('i2', { guia: { cx: NaN, cy: 0.5, lado: 0.36 } }),
            guiaNegativa: pequeno('i3', { guia: { cx: 0.5, cy: -1, lado: 0.36 } }),
            guiaGigante: pequeno('i4', { guia: { cx: 0.5, cy: 0.5, lado: 1e9 } }),
            guiaTexto: pequeno('i5', { guia: { cx: '0.5', cy: 0.5, lado: 0.36 } }),
            orcNaN: pequeno('i6', { orcamentoMs: NaN }),
            orcZero: pequeno('i7', { orcamentoMs: 0 }),
            orcGigante: pequeno('i8', { orcamentoMs: 1e9 }),
            orcAusente: pequeno('i9', { orcamentoMs: undefined }),
            dimGigante: pequeno('i10', { width: 1e9, height: 1e9 }),
            dimNegativa: pequeno('i11', { width: -4 }),
            dimNaN: pequeno('i12', { height: NaN }),
            dadosCurtos: pequeno('i13', { data: new Uint8ClampedArray(8) }),
            dadosAusentes: pequeno('i14', { data: undefined }),
        };
        r.wvalida = { invalidos: {} };
        for (const [nome, msg] of Object.entries(invalidos)) {
            const t0 = performance.now();
            const res = await enviar(msg);
            r.wvalida.invalidos[nome] = { ok: res.d && res.d.ok, id: res.d && res.d.id === msg.id, chaves: res.chaves, travou: !!res.timeout || !!res.erro, ms: Math.round(performance.now() - t0) };
        }
        const nulo = await enviar(null); r.wvalida.nulo = { ok: nulo.d && nulo.d.ok, travou: !!nulo.timeout || !!nulo.erro };
        const valido = await enviar(quadroBase('v1'));
        r.wvalida.valido = { ok: valido.d && valido.d.ok, id: valido.d && valido.d.id, chaves: valido.chaves };
        const vazioV = await enviar(quadroBase('v2', { data: new Uint8ClampedArray(1632 * 1224 * 4).fill(255) }));
        r.wvalida.vazio = { ok: vazioV.d && vazioV.d.ok, chaves: vazioV.chaves };
        wv.terminate();

        // ---- camera: ajustes opcionais, toleram ausencia/erro de capability ----
        const faz = (cap, falha) => {
            const aplicados = [];
            const track = { getCapabilities: cap === null ? undefined : () => cap, applyConstraints: async (c) => { aplicados.push(JSON.stringify(c)); if (falha) throw new Error('x'); } };
            return { aplicados, stream: { getVideoTracks: () => [track] } };
        };
        const a1 = faz({ focusMode: ['manual', 'continuous'], exposureMode: ['continuous'], whiteBalanceMode: ['manual'] });
        await qrOtimizarCamera(a1.stream);
        const a2 = faz({}); await qrOtimizarCamera(a2.stream);
        const a3 = faz(null); await qrOtimizarCamera(a3.stream);
        const a4 = faz({ focusMode: ['continuous'], exposureMode: ['continuous'], whiteBalanceMode: ['continuous'] }, true);
        let erroCamera = null; try { await qrOtimizarCamera(a4.stream); await qrOtimizarCamera(null); await qrOtimizarCamera({ getVideoTracks: () => [] }); } catch (e) { erroCamera = e.message; }
        const a5 = faz({ focusMode: ['manual', 'single-shot'] });
        const t5 = performance.now(); await qrFocarAntesDaCaptura(a5.stream);
        const a5ms = Math.round(performance.now() - t5);
        const a6 = faz({ focusMode: ['continuous', 'single-shot'] }); await qrFocarAntesDaCaptura(a6.stream);
        r.camera = { a1: a1.aplicados, a2: a2.aplicados.length, a3: a3.aplicados.length, a4: a4.aplicados.length, erroCamera, a5: a5.aplicados, a5ms, a6: a6.aplicados.length };
        return r;
    }, matriz);
}

async function exercitarModalReprovacao(page) {
    return page.evaluate(async () => {
        const r = {};
        const orig = { ir: window.ir };
        const destinos = [];
        window.ir = tela => { destinos.push(tela); };
        iniciarApp(); // restaura a estrutura (modais) apagada pelos testes anteriores
        state.idAtendimento = 99; state.tipo = 'expedicao'; state.tela = 'exp_aguarde_documentos'; state.placa = 'ABC1D23';
        state.exp = estadoExpVazio();
        const motivos = ['placa_divergente', 'cnh_vencida', 'documento_ilegivel', 'dados_invalidos'];
        r.mensagens = [];
        for (const m of motivos) {
            state.exp.motivos.cnh = null;
            registrarMotivosReprovacao('exp', { cnh: { terminal: true, pode_avancar: false, status_processamento: 'CONCLUIDO', motivo_usuario: m } });
            const registrou = state.exp.motivos.cnh === m;
            fecharModal();
            abrirModalReprovacaoDocumento('exp', 'cnh');
            const caixa = document.getElementById('modalCaixa');
            const botoes = [...caixa.querySelectorAll('button')].map(b => ({ t: b.textContent.trim(), h: Math.round(b.getBoundingClientRect().height) }));
            const cr = caixa.getBoundingClientRect();
            r.mensagens.push({
                m, registrou, texto: caixa.querySelector('.subtitulo').textContent, botoes,
                dentro: cr.left >= 0 && cr.right <= innerWidth && cr.top >= 0 && cr.bottom <= innerHeight && caixa.scrollWidth <= caixa.clientWidth,
            });
        }
        // motivo null / fora da allowlist nao registra nem abre modal
        state.exp.motivos.cnh = null; fecharModal();
        registrarMotivosReprovacao('exp', { cnh: { terminal: true, pode_avancar: false, status_processamento: 'CONCLUIDO', motivo_usuario: null } });
        registrarMotivosReprovacao('exp', { cnh: { terminal: true, pode_avancar: false, status_processamento: 'CONCLUIDO', motivo_usuario: 'x_tecnico' } });
        r.nuloSemModal = state.exp.motivos.cnh === null && abrirModalReprovacaoDocumento('exp', 'cnh') === false && !modalReprovacaoAberto();
        // sem duplo modal
        state.exp.motivos.crlv = 'cnh_vencida';
        abrirModalReprovacaoDocumento('exp', 'crlv'); abrirModalReprovacaoDocumento('exp', 'crlv');
        r.modais = document.querySelectorAll('#modalCaixa .modal-reprovacao-doc').length;
        // escanear novamente: conta tentativa, limpa e volta a tela QR do mesmo documento
        destinos.length = 0;
        reescanearDocumento('exp', 'crlv');
        r.reescanear = { destino: destinos[0], tent: state.exp.qrTentativas.crlv, motivo: state.exp.motivos.crlv, flag: state.exp.reescaneio.crlv };
        // terceira reprovacao: sem botao de re-escaneio; ao chegar a 3 vai ao manual
        state.exp.qrTentativas.cnh = 2; state.exp.motivos.cnh = 'cnh_vencida'; fecharModal();
        abrirModalReprovacaoDocumento('exp', 'cnh');
        r.terceira = [...document.querySelectorAll('#modalCaixa button')].map(b => b.textContent.trim());
        destinos.length = 0; reescanearDocumento('exp', 'cnh');
        r.aoTres = destinos[0];
        // preencher manualmente
        state.exp = estadoExpVazio(); state.exp.motivos.cnh = 'dados_invalidos'; destinos.length = 0;
        preencherDocumentoManualmente('exp', 'cnh');
        r.manual = { destino: destinos[0], motivo: state.exp.motivos.cnh };
        // recebimento
        state.tipo = 'recebimento'; state.tela = 'rec_aguarde_documentos'; state.rec = estadoRecVazio();
        state.rec.motivos.crlv = 'dados_invalidos'; fecharModal();
        r.recAbre = abrirModalReprovacaoDocumento('rec', 'crlv') && modalReprovacaoAberto();
        destinos.length = 0; reescanearDocumento('rec', 'crlv'); r.recDestino = destinos[0];
        // tela fora do fluxo (ex.: cancelou) nao abre modal
        state.tela = 'lgpd'; fecharModal(); state.rec.motivos.crlv = 'dados_invalidos';
        r.foraDoFluxo = abrirModalReprovacaoDocumento('rec', 'crlv') === false;
        // tela de espera com indicador e texto de progresso
        document.getElementById('app').innerHTML = telaExpAguardeDocumentos();
        r.spinner = !!document.querySelector('.impr-spinner');
        r.rotulo = rotuloStatusProcessamento('PROCESSANDO_LEITURA');
        window.ir = orig.ir;
        return r;
    });
}

async function exercitarConfirmacao(page) {
    return page.evaluate(async () => {
        const r = {};
        const orig = { ir: window.ir, api: window.api };
        const destinos = []; const chamadas = [];
        window.ir = tela => { destinos.push(tela); };
        let falha = null;
        window.api = async (a, acao, corpo) => { chamadas.push(acao); if (falha) throw falha; return {}; };
        const cheios = { motorista_nome: 'JOAO DA SILVA', motorista_cpf: '12345678909', cnh_validade: '2030-01-01', crlv_ano: '2020', crlv_uf: 'SP', crlv_rntc: '123456789', crlv_tipo_veiculo: 'CAMINHAO', numero: 'OC1', cliente_nome: 'ACME' };
        const caixaInfo = () => {
            const caixa = document.getElementById('modalCaixa'); const cr = caixa.getBoundingClientRect();
            return {
                aberto: document.getElementById('modalFundo').classList.contains('aberto'),
                titulo: (caixa.querySelector('.titulo') || {}).textContent, texto: (caixa.querySelector('.subtitulo') || {}).textContent,
                botoes: [...caixa.querySelectorAll('button')].map(b => ({ t: b.textContent.trim(), h: Math.round(b.getBoundingClientRect().height) })),
                dentro: cr.left >= 0 && cr.right <= innerWidth && cr.top >= 0 && cr.bottom <= innerHeight && caixa.scrollWidth <= caixa.clientWidth,
            };
        };
        const montarConfirma = () => { let t = document.getElementById('telaTeste'); if (!t) { t = document.createElement('div'); t.id = 'telaTeste'; document.body.appendChild(t); } t.innerHTML = telaConfirma('teste'); };
        for (const tipo of ['expedicao', 'recebimento']) {
            const k = tipo === 'expedicao' ? 'exp' : 'rec';
            iniciarApp();
            state.idAtendimento = 99; state.tipo = tipo; state.tela = k + '_confirma'; state.placa = 'ABC1D23';
            state[k] = k === 'exp' ? estadoExpVazio() : estadoRecVazio();
            state.dados = Object.assign({}, cheios, { crlv_rntc: '' });
            montarConfirma();
            const rntrc = document.getElementById('confCrlvRntc');
            const rotulo = rntrc.parentElement.textContent;
            destinos.length = 0; chamadas.length = 0;
            await confirmarDados();
            const vazio = caixaInfo();
            r[k + 'Vazio'] = { ...vazio, destinos: destinos.length, chamadas: chamadas.length, rotuloObrig: /RNTRC/.test(rotulo) && rotulo.includes('*'), ph: rntrc.placeholder,
                marcado: rntrc.classList.contains('campo-invalido') && !!rntrc.nextElementSibling && rntrc.nextElementSibling.textContent === 'Obrigatório' };
            document.querySelector('#modalCaixa button').click();
            r[k + 'Foco'] = document.activeElement === rntrc && !document.getElementById('modalFundo').classList.contains('aberto');
            // readonly: atributo, sem teclado virtual, sem marca de obrigatorio, legivel
            const idLeit = k === 'exp' ? 'confOc' : 'confCliente';
            r[k + 'Leitura'] = ['confPlaca', idLeit].map(id => {
                const el = document.getElementById(id); const cs = getComputedStyle(el);
                return { ro: el.readOnly, kb: el.classList.contains('kb-input'), req: el.hasAttribute('aria-required') || !!el.placeholder, marca: !!el.parentElement.querySelector('.obrig-marca'),
                    fonte: parseFloat(cs.fontSize), cor: cs.color, fundo: cs.backgroundColor, valor: el.value };
            });
            r[k + 'EditaveisSemLeitura'] = camposObrigatoriosConfirma().every(c => !['confPlaca', 'confOc', 'confCliente'].includes(c.id));
            // atendimento vazio (so leitura) -> nao avanca, "Procure o atendente", sem botao de preencher
            state.dados = Object.assign({}, cheios, { numero: '', cliente_nome: '' }); state.placa = ''; montarConfirma();
            destinos.length = 0; chamadas.length = 0;
            await confirmarDados();
            r[k + 'SoAtend'] = { ...caixaInfo(), destinos: destinos.length, chamadas: chamadas.length };
            document.querySelectorAll('#modalCaixa button')[0].click();
            r[k + 'SoAtendVoltar'] = !document.getElementById('modalFundo').classList.contains('aberto');
            // 422 do servidor so com dado do atendimento (cliente/ordem/placa) e rotulos do servidor
            state.dados = cheios; state.placa = 'ABC1D23'; montarConfirma();
            falha = Object.assign(new Error('Nao e possivel continuar: preencha cliente'), { status: 422, codigo: 'CONFIRMACAO_INCOMPLETA', dados: { campos: ['cliente'], rotulos: ['cliente'] } });
            destinos.length = 0;
            await confirmarDados();
            r[k + 'Srv422Atend'] = { ...caixaInfo(), destinos: destinos.length };
            fecharModal();
            falha = Object.assign(new Error('x'), { status: 422, codigo: 'CONFIRMACAO_INCOMPLETA', dados: { campos: ['crlv_rntc'], rotulos: ['rntrc'] } });
            await confirmarDados();
            r[k + 'Srv422Edit'] = { ...caixaInfo(), destinos: destinos.length };
            fecharModal();
            falha = Object.assign(new Error('x'), { status: 422, codigo: 'CONFIRMACAO_INCOMPLETA', dados: { campos: ['crlv_rntc', 'placa', 'ordem_coleta'], rotulos: ['rntrc', 'placa', 'ordem de coleta'] } });
            await confirmarDados();
            r[k + 'Srv422Misto'] = { ...caixaInfo(), destinos: destinos.length };
            fecharModal(); falha = null;
            // payload de salvar-etapa nao carrega placa/ordem/cliente
            state.dados = cheios; state.placa = 'ABC1D23'; montarConfirma();
            let corpoEnviado = null; const apiAnt = window.api;
            window.api = async (a, acao, corpo) => { corpoEnviado = corpo; return {}; };
            await confirmarDados(); window.api = apiAnt;
            r[k + 'Payload'] = Object.keys(corpoEnviado.dados).sort().join(',');
            state.dados = Object.assign({}, cheios, { crlv_rntc: '' }); montarConfirma();
            document.querySelectorAll('.kb-input').forEach(el => { el.value = '   '; });
            document.getElementById('confCrlvUf').value = '';
            document.getElementById('confPlaca').value = ''; document.getElementById(idLeit).value = '';
            chamadas.length = 0;
            await confirmarDados();
            r[k + 'Todos'] = { ...caixaInfo(), n: document.querySelectorAll('.campo-invalido').length, esperado: camposObrigatoriosConfirma().length, chamadas: chamadas.length };
            fecharModal();
            state.dados = cheios; montarConfirma();
            destinos.length = 0; chamadas.length = 0;
            await confirmarDados();
            r[k + 'Cheio'] = { destinos: destinos.join(','), chamadas: chamadas.join(','), modal: document.getElementById('modalFundo').classList.contains('aberto') };
            falha = Object.assign(new Error('Nao e possivel continuar: preencha RNTRC <b>'), { status: 422 });
            destinos.length = 0;
            await confirmarDados();
            r[k + 'Servidor'] = { ...caixaInfo(), destinos: destinos.length, html: document.getElementById('modalCaixa').querySelector('.subtitulo').innerHTML };
            fecharModal(); falha = null;
        }
        window.ir = orig.ir; window.api = orig.api;
        return r;
    });
}

async function exercitarConfirmacaoLayout(page, tipo, variante) {
    return page.evaluate(async (tipo, variante) => {
        const r = {};
        const orig = { api: window.api };
        const k = tipo === 'expedicao' ? 'exp' : 'rec';
        const base = 'ABCDEFGHIJ';
        const semEspaco = n => base.repeat(Math.ceil(n / 10)).slice(0, n);
        const comEspaco = n => { let t = ''; while (t.length < n) t += 'MARIA APARECIDA DOS SANTOS '; return t.slice(0, n).trim(); };
        const gerar = variante === 'sem-espaco' ? semEspaco : comEspaco;
        const sobra = document.getElementById('telaTeste'); if (sobra) sobra.remove();
        iniciarApp();
        state.idAtendimento = 99; state.tipo = tipo; state.placa = 'ABC1D23';
        state[k] = k === 'exp' ? estadoExpVazio() : estadoRecVazio();
        state.dados = { motorista_nome: gerar(150), motorista_cpf: '12345678909', cnh_validade: '2030-01-01', crlv_ano: '2020', crlv_uf: 'SP', crlv_rntc: '123456789', crlv_tipo_veiculo: 'CAMINHAO TRATOR', numero: 'OC123456', cliente_nome: gerar(120) };
        state.tela = k + '_confirma';
        document.getElementById('barraCancelar').style.display = 'block';
        state.tela = k + '_confirma'; renderTela();
        const tela = document.getElementById('tela');
        const campos = [...document.querySelectorAll('.conf-valor')];
        const semCorte = el => el.scrollWidth <= el.clientWidth + 1 && el.scrollHeight <= el.clientHeight + 1;
        const linhas = el => Math.round((el.clientHeight - 32) / (parseFloat(getComputedStyle(el).lineHeight) || 35));
        r.cartoes = document.querySelectorAll('.conf-cartao').length;
        r.titulos = [...document.querySelectorAll('.conf-cartao-titulo')].map(e => e.textContent).join('|');
        r.fontes = campos.map(e => parseFloat(getComputedStyle(e).fontSize));
        r.rotuloFonte = Math.min(...[...document.querySelectorAll('.conf-rotulo')].map(e => parseFloat(getComputedStyle(e).fontSize)));
        r.semCorte = campos.every(semCorte);
        r.cortados = campos.filter(e => !semCorte(e)).map(e => e.id);
        r.overflowX = tela.scrollWidth > tela.clientWidth || document.documentElement.scrollWidth > innerWidth;
        const mot = document.getElementById('confMotorista'), cli = document.getElementById(k === 'exp' ? 'confOc' : 'confCliente');
        r.motTag = mot.tagName; r.motH = mot.offsetHeight; r.motLinhas = linhas(mot); r.motValor = mot.value.length === 150 && mot.value === state.dados.motorista_nome;
        r.cliTag = cli.tagName; r.cliH = cli.offsetHeight; r.cliLinhas = linhas(cli); r.cliRo = cli.readOnly;
        r.ro = ['confPlaca', k === 'exp' ? 'confOc' : 'confCliente'].map(id => document.getElementById(id).hasAttribute('readonly'));
        r.rotulosFor = [...document.querySelectorAll('.conf-rotulo')].every(l => l.htmlFor && document.getElementById(l.htmlFor));
        // botao Confirmar: visivel dentro do viewport sem rolar (rodape sticky) e >= 96px
        tela.scrollTop = 0;
        const btn = document.querySelector('.conf-confirmar'); const br = btn.getBoundingClientRect();
        r.btn = { h: Math.round(br.height), fonte: parseFloat(getComputedStyle(btn).fontSize), top: Math.round(br.top), bottom: Math.round(br.bottom), dentro: br.top >= 0 && br.bottom <= innerHeight, fundo: getComputedStyle(btn).backgroundColor };
        const tr = tela.getBoundingClientRect();
        r.rolagem = tela.scrollHeight > tela.clientHeight;
        r.btnNaoCobre = (() => { // rodape sticky nao cobre campos no fim da rolagem
            tela.scrollTop = tela.scrollHeight;
            const rodape = document.querySelector('.conf-rodape').getBoundingClientRect();
            const v = campos.filter(e => { const c = e.getBoundingClientRect(); return c.bottom > rodape.top + 1 && c.top < rodape.bottom - 1; }).length === 0;
            tela.scrollTop = 0; return v;
        })();
        r.alturaTela = Math.round(tr.height); r.alturaConteudo = tela.scrollHeight;
        // teclado virtual: digitacao em textarea cresce sem cortar
        mot.value = 'AB'; ajustarAlturaTextarea(mot);
        r.hAntes = mot.offsetHeight;
        mot.focus();
        r.tecladoAberto = document.getElementById('teclado').classList.contains('aberto') && campoAtivo === mot;
        for (let i = 0; i < 120; i++) digitar(i % 7 === 6 ? ' ' : 'X');
        r.hDepois = mot.offsetHeight; r.digitouSemCorte = semCorte(mot) && mot.value.length === 122;
        for (let i = 0; i < 119; i++) apagar();
        r.hVolta = mot.offsetHeight;
        fecharTeclado();
        // erro: borda 3px vermelha + texto Obrigatorio
        mot.value = ''; ajustarAlturaTextarea(mot);
        document.getElementById('confCrlvRntc').value = '';
        const corpos = []; window.api = async (a, acao, corpo) => { corpos.push(corpo); return {}; };
        await confirmarDados();
        const inv = [...document.querySelectorAll('.campo-invalido')];
        r.erro = inv.map(e => ({ id: e.id, borda: parseFloat(getComputedStyle(e).borderTopWidth), cor: getComputedStyle(e).borderTopColor, txt: e.nextElementSibling && e.nextElementSibling.textContent }));
        r.erroNaoEnviou = corpos.length === 0;
        r.modal = document.getElementById('modalFundo').classList.contains('aberto') && /Preencha os campos obrigatórios: Motorista, RNTRC\./.test(document.getElementById('modalCaixa').textContent);
        document.querySelector('#modalCaixa button').click();
        r.focoPrimeiro = document.activeElement === mot;
        fecharTeclado();
        // payload inalterado com nomes longos
        mot.value = state.dados.motorista_nome; document.getElementById('confCrlvRntc').value = '123456789'; limparMarcasCamposObrigatorios();
        await confirmarDados();
        r.payload = corpos.length === 1 ? Object.keys(corpos[0].dados).sort().join(',') : 'n=' + corpos.length;
        r.payloadNome = corpos.length === 1 && corpos[0].dados.motorista_nome === state.dados.motorista_nome;
        r.payloadEtapa = corpos.length === 1 && corpos[0].etapa === 'confirmacao';
        window.api = orig.api;
        // restaura estado visual para captura
        state.tela = k + '_confirma'; renderTela();
        return r;
    }, tipo, variante);
}

async function exercitarSemPapel(page) {
    return page.evaluate(async () => {
        const r = {};
        iniciarApp();
        const orig = { api: window.api, cfg: window.imprApiConfiguracaoServicoLocal, fetchSvc: window.imprFetchServicoLocal, st: window.setTimeout.bind(window), ct: window.clearTimeout.bind(window) };
        const acoes = []; const envios = []; let seq = 0; let fila = [];
        window.api = async (a, acao) => { acoes.push(acao); if (acao === 'gerar-etiqueta') return { pdf_base64: 'QUJD', identificador: 'ident' + (++seq) }; return { senha: '123' }; };
        window.imprApiConfiguracaoServicoLocal = async () => ({ url: 'http://servico.local', token: 't', frontend_timeout_ms: 1000 });
        window.imprFetchServicoLocal = async (caminho, opcoes) => { envios.push(JSON.parse(opcoes.body).identificador); return fila.shift(); };
        // timers simulados: captura os agendamentos para "passar" mais de 180 s sem esperar
        let timers = []; let nid = 0;
        window.setTimeout = (fn, ms) => { const id = ++nid; timers.push({ id, fn, ms }); return id; };
        window.clearTimeout = id => { timers = timers.filter(t => t.id !== id); };
        const aguardar = () => new Promise(res => orig.st(res, 0));
        const avisoAberto = () => document.getElementById('modalInatividadeFundo').classList.contains('aberto');
        const disparar = async () => { const t = timers.filter(x => x.ms === IDLE_MS || x.ms === 120000).pop(); if (t) { timers = timers.filter(x => x !== t); await t.fn(); } return !!t; };
        const info = () => {
            const el = document.getElementById('imprConteudo'); const b = [...el.querySelectorAll('button')];
            return { titulo: (el.querySelector('.impr-alerta-titulo') || {}).textContent, texto: (el.querySelector('.impr-alerta-texto') || {}).textContent,
                botoes: b.map(x => ({ t: x.textContent.trim(), h: Math.round(x.getBoundingClientRect().height), prim: x.classList.contains('btn-primario'), fundo: getComputedStyle(x).backgroundColor })),
                overflow: el.scrollWidth > el.clientWidth || document.documentElement.scrollWidth > innerWidth, html: el.innerHTML };
        };
        localStorage.setItem('totem_impressora_nome', 'EPSON TESTE');
        document.getElementById('tela').innerHTML = telaImpressao();
        state.idAtendimento = 7; state.tela = 'exp_impressao'; state.dados = {};
        reiniciarIdle();
        fila = [{ ok: false, status: 409, dados: { status: 'sem_papel', identificador: 'ident1' }, abortou: false }];
        await processarImpressao();
        r.passo1 = { tela: imprState.tela, acoes: acoes.slice(), envios: envios.slice(), ...info() };
        // >180 s simulados varias vezes: sem aviso, sem cancelamento, sem teto
        let canc = 0; const cancOrig = window.cancelarESair; window.cancelarESair = () => { canc++; };
        let disparos = 0; for (let i = 0; i < 4; i++) { if (await disparar()) disparos++; if (avisoAberto()) break; }
        r.idle = { disparos, aviso: avisoAberto(), canc, reagendado: timers.some(t => t.ms === IDLE_MS), abandono: timers.some(t => t.ms === IDLE_ABANDONO_MS) };
        // tentar novamente: novo identificador, sem novo finalizar; sem_papel de novo volta ao alerta
        fila = [{ ok: false, status: 500, dados: { status: 'sem_papel', identificador: 'ident2' }, abortou: false }];
        acoes.length = 0;
        imprTentarNovamente(); const durante = imprState.tela;
        await aguardar();
        r.repetido = { acoes: acoes.slice(), envios: envios.slice(), tela: imprState.tela, durante, ...info() };
        for (let i = 0; i < 2; i++) await disparar();
        r.idle2 = { aviso: avisoAberto(), canc };
        // sucesso: comportamento normal volta
        fila = [{ ok: true, status: 200, dados: { status: 'impresso', identificador: 'ident3' }, abortou: false }];
        acoes.length = 0;
        imprTentarNovamente();
        await aguardar();
        r.sucesso = { tela: imprState.tela, acoes: acoes.slice(), envios: envios.slice() };
        reiniciarIdle(); await disparar();
        r.normal = { aviso: avisoAberto(), abandono: timers.some(t => t.ms === IDLE_ABANDONO_MS) };
        fecharAvisoInatividade(); timers = [];
        // aviso aberto antes (durante "imprimindo") e fechado ao entrar em sem_papel; HTTP 200 tambem vale
        imprState.tela = 'imprimindo'; reiniciarIdle(); await disparar();
        r.avisoAntes = avisoAberto();
        fila = [{ ok: true, status: 200, dados: { status: 'sem_papel' }, abortou: false }];
        await imprExecutarImpressao('EPSON TESTE', true);
        r.http200 = { tela: imprState.tela, aviso: avisoAberto(), abandono: timers.some(t => t.ms === IDLE_ABANDONO_MS) };
        // erro de impressao com "Tentar novamente" tambem suspende; indeterminado/outras telas nao
        fecharAvisoInatividade(); timers = [];
        imprState.tela = 'erro'; reiniciarIdle(); await disparar(); r.erro = avisoAberto();
        imprState.tela = 'indeterminado'; r.indeterminadoSuspensa = imprInatividadeSuspensa();
        fecharAvisoInatividade(); timers = [];
        reiniciarIdle(); await disparar(); r.indeterminadoAviso = avisoAberto();
        imprState.tela = 'erro_finalizar'; r.erroFinalizarSuspensa = imprInatividadeSuspensa();
        fecharAvisoInatividade(); timers = [];
        reiniciarIdle(); await disparar(); r.erroFinalizarAviso = avisoAberto();
        fecharAvisoInatividade(); timers = [];
        imprState.tela = 'imprimindo'; r.imprimindoSuspensa = imprInatividadeSuspensa();
        imprState.tela = 'concluido'; r.concluidoSuspensa = imprInatividadeSuspensa();
        state.tela = 'exp_placa'; imprState.tela = 'sem_papel'; r.outraTela = imprInatividadeSuspensa();
        fecharAvisoInatividade();
        window.api = orig.api; window.imprApiConfiguracaoServicoLocal = orig.cfg; window.imprFetchServicoLocal = orig.fetchSvc; window.setTimeout = orig.st; window.clearTimeout = orig.ct; window.cancelarESair = cancOrig;
        return r;
    });
}

async function exercitarEtiquetaAjudante(page) {
    return page.evaluate(async () => {
        const r = {};
        iniciarApp();
        const orig = { api: window.api, cfg: window.imprApiConfiguracaoServicoLocal, fetchSvc: window.imprFetchServicoLocal };
        let chamadas = []; let envios = []; let seq = 0; let fila = []; let temAj = false; let ajApi = null; let progresso = [];
        window.api = async (arq, acao, corpo) => {
            chamadas.push({ acao, dest: corpo && corpo.destinatario, reimp: corpo && corpo.reimpressao });
            if (acao === 'gerar-etiqueta') {
                if (corpo.destinatario === 'ajudante') { if (ajApi) { const e = new Error('Este atendimento nao possui ajudante'); e.status = 409; throw e; } return { pdf_base64: 'QUJD', identificador: 'aj' + (++seq), destinatario: 'ajudante' }; }
                return { pdf_base64: 'QUJD', identificador: 'mt' + (++seq), tem_etiqueta_ajudante: temAj };
            }
            return { senha: '123' };
        };
        window.imprApiConfiguracaoServicoLocal = async () => ({ url: 'http://servico.local', token: 't', frontend_timeout_ms: 1000 });
        window.imprFetchServicoLocal = async (caminho, opcoes) => {
            envios.push(JSON.parse(opcoes.body).identificador);
            const t = document.querySelector('#imprConteudo .subtitulo'); progresso.push(t ? t.textContent : '');
            return fila.shift();
        };
        const esperar = () => new Promise(res => setTimeout(res, 30));
        const OK = { ok: true, status: 200, dados: { status: 'impresso' }, abortou: false };
        const SP = { ok: false, status: 409, dados: { status: 'sem_papel' }, abortou: false };
        const ER = { ok: false, status: 500, dados: { erro: 'Falha tecnica' }, abortou: false };
        const IND = { ok: false, status: 504, dados: { status: 'indeterminado' }, abortou: false };
        const info = () => {
            const el = document.getElementById('imprConteudo'); const b = [...el.querySelectorAll('button')];
            return { tela: imprState.tela, texto: el.textContent, botoes: b.map(x => ({ t: x.textContent.trim(), h: Math.round(x.getBoundingClientRect().height) })),
                overflow: el.scrollWidth > el.clientWidth || document.documentElement.scrollWidth > innerWidth, suspensa: imprInatividadeSuspensa(),
                pendente: imprState.etiquetaPendente, motImp: imprState.motoristaImpressa, chamadas: chamadas.map(c => c.acao + (c.dest ? ':' + c.dest : '')).join(','),
                reimps: chamadas.map(c => c.reimp || 0).join(','), envios: envios.join(','), progresso: progresso.slice() };
        };
        const zerar = () => { chamadas = []; envios = []; progresso = []; };
        localStorage.setItem('totem_impressora_nome', 'EPSON TESTE');
        document.getElementById('tela').innerHTML = telaImpressao();
        state.idAtendimento = 7; state.tela = 'exp_impressao'; state.dados = {};
        reiniciarIdle();
        // A) sem ajudante: fluxo atual
        temAj = false; ajApi = null; fila = [OK]; zerar();
        await processarImpressao(); r.semAj = info();
        // B) com ajudante: motorista e depois ajudante
        temAj = true; fila = [OK, OK]; zerar();
        await processarImpressao(); r.comAj = info();
        // C) falha do ajudante: sem_papel, erro, indeterminado; retry so do ajudante
        r.falhaAj = {};
        for (const [nome, falha] of [['sem_papel', SP], ['erro', ER], ['indeterminado', IND]]) {
            fila = [OK, falha]; zerar();
            await processarImpressao(); const f1 = info();
            fila = [OK]; chamadas = []; envios = []; progresso = [];
            imprTentarNovamente(); await esperar(); const f2 = info();
            r.falhaAj[nome] = { f1, f2 };
        }
        // C2) ajudante falha duas vezes e depois imprime
        fila = [OK, SP]; zerar(); await processarImpressao();
        fila = [SP]; chamadas = []; envios = []; imprTentarNovamente(); await esperar(); const dupla1 = info();
        fila = [OK]; chamadas = []; envios = []; imprTentarNovamente(); await esperar(); r.dupla = { dupla1, fim: info() };
        // D) falha do motorista: retry reimprime o motorista (nunca finalizar); depois segue ao ajudante
        r.falhaMot = {};
        for (const [nome, falha] of [['sem_papel', SP], ['erro', ER], ['indeterminado', IND]]) {
            fila = [falha]; zerar(); await processarImpressao(); const m1 = info();
            fila = [OK, OK]; chamadas = []; envios = []; progresso = [];
            imprTentarNovamente(); await esperar(); r.falhaMot[nome] = { m1, m2: info() };
        }
        // E) backend 409 (sem ajudante) na etiqueta do ajudante: erro leve, Tentar novamente, sem finalizar
        ajApi = 409; fila = [OK]; zerar(); await processarImpressao(); const e1 = info();
        ajApi = null; fila = [OK]; chamadas = []; envios = [];
        imprTentarNovamente(); await esperar(); r.e409 = { e1, e2: info() };
        // F) reinicio por novoAtendimento
        fila = [OK, SP]; zerar(); await processarImpressao(); r.antesReset = { pendente: imprState.etiquetaPendente, mot: imprState.motoristaImpressa, aj: imprState.temEtiquetaAjudante };
        novoAtendimento(); r.depoisReset = { pendente: imprState.etiquetaPendente, mot: imprState.motoristaImpressa, aj: imprState.temEtiquetaAjudante };
        window.api = orig.api; window.imprApiConfiguracaoServicoLocal = orig.cfg; window.imprFetchServicoLocal = orig.fetchSvc;
        return r;
    });
}

async function exercitarErroFinalizar(page) {
    return page.evaluate(async () => {
        const r = {};
        iniciarApp();
        const origApi = window.api; const origSt = window.setTimeout.bind(window);
        const L300 = ('Reg. acesso ajudante. Crachá já associado a outro ajudante. Ação não permitida. ' + 'X'.repeat(40) + ' ').repeat(3).slice(0, 299) + 'Z';
        const HTML = '<script>window.__xss=1<\/script><img src=x onerror="window.__xss=2"><b>negrito</b>';
        const longa = 'A'.repeat(300);
        let proximo = null;
        window.api = async () => { const e = Object.assign(new Error('Nao foi possivel concluir o check-in. Chame o atendimento.'), { status: 202 }); if (proximo !== undefined && proximo !== null) e.dados = proximo; throw e; };
        localStorage.setItem('totem_impressora_nome', 'EPSON TESTE');
        document.getElementById('tela').innerHTML = telaImpressao();
        state.idAtendimento = 7; state.tela = 'exp_impressao'; state.dados = {};
        const coleta = async dados => {
            proximo = dados;
            await processarImpressao();
            const el = document.getElementById('imprConteudo'); const b = [...el.querySelectorAll('button')];
            const m = document.getElementById('imprMotivoApi');
            return { tela: imprState.tela, texto: el.textContent, motivo: m ? m.textContent : null, filhosMotivo: m ? m.children.length : null,
                scripts: el.querySelectorAll('script,img,b').length, xss: window.__xss || 0,
                fonte: m ? parseFloat(getComputedStyle(m).fontSize) : null,
                botoes: b.map(x => ({ t: x.textContent.trim(), h: Math.round(x.getBoundingClientRect().height) })),
                overflow: el.scrollWidth > el.clientWidth || document.documentElement.scrollWidth > innerWidth || (m ? m.scrollWidth > m.clientWidth : false),
                suspensa: imprInatividadeSuspensa(), html: el.innerHTML };
        };
        r.L300 = L300;
        r.com = await coleta({ mensagem_api: 'Reg. acesso ajudante. Crachá já associado a outro ajudante. Ação não permitida.' });
        r.longa = await coleta({ mensagem_api: L300 });
        r.contig = await coleta({ mensagem_api: longa });
        r.html = await coleta({ mensagem_api: HTML });
        r.htmlLiteral = HTML;
        r.sem = await coleta(null);
        r.vazia = await coleta({ mensagem_api: '   ' });
        r.naoString = await coleta({ mensagem_api: { a: 1 } });
        r.mensagemApiAposSem = imprState.mensagemApi;
        window.api = origApi;
        return r;
    });
}

async function exercitarCorrecaoAjudante(page) {
    return page.evaluate(async () => {
        const r = {};
        const origApi = window.api;
        const aguardar = () => new Promise(res => setTimeout(res, 0));
        const MSG = 'Reg. acesso ajudante. Crachá já associado a outro ajudante. Ação não permitida.';
        let chamadas = []; let falhaSalvar = null;
        window.api = async (a, acao, corpo) => {
            chamadas.push({ acao, corpo });
            if (acao === 'finalizar') throw Object.assign(new Error('x'), { status: 202, dados: { mensagem_api: MSG } });
            if (acao === 'salvar-etapa') { if (falhaSalvar) throw falhaSalvar; return { ok: true }; }
            return {};
        };
        const info = () => {
            const t = document.getElementById('tela');
            const vis = id => { const e = document.getElementById(id); return !!e && getComputedStyle(e).display !== 'none'; };
            const alt = id => { const e = document.getElementById(id); return e ? Math.round(e.getBoundingClientRect().height) : null; };
            const err = document.getElementById('ajudanteErro');
            return { tela: state.tela, corr: state.ajudanteCorrecao, perguntaVis: vis('ajudantePergunta'), camposVis: vis('ajudanteCampos'),
                nome: (document.getElementById('ajudanteNome') || {}).value, cpf: (document.getElementById('ajudanteCpf') || {}).value,
                erro: err && err.style.display !== 'none' ? err.textContent : null, erroFilhos: err ? err.children.length : null,
                voltarAlt: alt('ajudanteBtnVoltar'), novoVis: vis('ajudanteBtnNovo'), novoAlt: vis('ajudanteBtnNovo') ? alt('ajudanteBtnNovo') : null,
                confirmarAlt: vis('ajudanteBtnConfirmar') ? alt('ajudanteBtnConfirmar') : null,
                botoes: [...t.querySelectorAll('button')].filter(b => b.offsetParent !== null).map(b => ({ t: b.textContent.trim(), h: Math.round(b.getBoundingClientRect().height) })),
                suspensa: imprInatividadeSuspensa(), imprTela: imprState.tela,
                overflow: t.scrollWidth > t.clientWidth || document.documentElement.scrollWidth > innerWidth };
        };
        const abrirErro = async tipo => {
            iniciarApp();
            const k = tipo === 'expedicao' ? 'exp' : 'rec';
            Object.assign(state, { idAtendimento: 7, tipo, tela: k + '_impressao', dados: {}, ajudante: { nome: 'JOSE LIMA', cpf: '52998224725' }, ajudanteCorrecao: false });
            localStorage.setItem('totem_impressora_nome', 'EPSON TESTE');
            document.getElementById('tela').innerHTML = telaImpressao();
            chamadas = []; falhaSalvar = null;
            await processarImpressao();
            return k;
        };
        for (const tipo of ['expedicao', 'recebimento']) {
            const k = tipo === 'expedicao' ? 'exp' : 'rec';
            const R = r[k] = {};
            await abrirErro(tipo);
            const btn = document.getElementById('imprBtnCorrigirAjudante');
            const bs = [...document.querySelectorAll('#imprConteudo button')].map(b => ({ t: b.textContent.trim(), h: Math.round(b.getBoundingClientRect().height), prim: b.classList.contains('btn-primario') }));
            R.botoes = bs;
            btn.click();
            R.abre = info();
            document.querySelector('#ajudantePergunta .btn-primario').click();
            R.sim = info();
            // Voltar: sem API, sem novo finalizar, volta a erro_finalizar com o motivo
            const antes = chamadas.length;
            document.getElementById('ajudanteBtnVoltar').click(); await aguardar();
            R.voltar = { ...info(), chamadas: chamadas.length - antes, motivo: (document.getElementById('imprMotivoApi') || {}).textContent, flagRetorno: imprState.retornoSemProcessar,
                temCorrigir: !!document.getElementById('imprBtnCorrigirAjudante') };
            // 422: permanece com campos editaveis e mensagem do servidor
            falhaSalvar = Object.assign(new Error('Dados do ajudante invalidos: informe o nome completo e um CPF valido'), { status: 422 });
            document.getElementById('imprBtnCorrigirAjudante').click();
            document.querySelector('#ajudantePergunta .btn-primario').click();
            document.getElementById('ajudanteNome').value = 'AB'; document.getElementById('ajudanteCpf').value = '123';
            chamadas = [];
            document.getElementById('ajudanteBtnConfirmar').click(); await aguardar();
            R.e422 = { ...info(), acoes: chamadas.map(c => c.acao).join(',') };
            // 409: mostra mensagem, oferece Voltar e Novo atendimento, nao avanca
            falhaSalvar = Object.assign(new Error('Nao e possivel alterar o ajudante: o check-in ja esta em processamento ou foi enviado'), { status: 409 });
            document.getElementById('ajudanteBtnConfirmar').style.display = '';
            document.getElementById('ajudanteCampos').style.display = 'block';
            document.getElementById('ajudanteBtnConfirmar').click(); await aguardar();
            R.e409 = { ...info(), acoes: chamadas.map(c => c.acao).join(',') };
            // 409 -> Novo atendimento zera o modo correcao
            document.getElementById('ajudanteBtnNovo').click();
            R.novo = { tela: state.tela, corr: state.ajudanteCorrecao };
            // sucesso: salvar-etapa com payload e DEPOIS finalizar de novo (sem fluxo normal)
            await abrirErro(tipo);
            document.getElementById('imprBtnCorrigirAjudante').click();
            document.querySelector('#ajudantePergunta .btn-primario').click();
            document.getElementById('ajudanteNome').value = '  MARIA SOUZA '; document.getElementById('ajudanteCpf').value = '11144477735';
            chamadas = [];
            document.getElementById('ajudanteBtnConfirmar').click(); await aguardar(); await aguardar();
            R.ok = { acoes: chamadas.map(c => c.acao).join(','), payload: JSON.stringify(chamadas[0] && chamadas[0].corpo), tela: state.tela, corr: state.ajudanteCorrecao,
                imprTela: imprState.tela, ajudante: JSON.stringify(state.ajudante), motivo: (document.getElementById('imprMotivoApi') || {}).textContent };
            // sem ajudante: nulls
            await abrirErro(tipo);
            document.getElementById('imprBtnCorrigirAjudante').click();
            chamadas = [];
            document.querySelector('#ajudantePergunta .btn-fantasma').click(); await aguardar(); await aguardar();
            R.semAj = { acoes: chamadas.map(c => c.acao).join(','), payload: JSON.stringify(chamadas[0] && chamadas[0].corpo), tela: state.tela };
            // pre-preenchimento vazio quando o front nao tem dados
            await abrirErro(tipo);
            state.ajudante = { nome: '', cpf: '' };
            document.getElementById('imprBtnCorrigirAjudante').click();
            R.vazio = info();
            // fora do modo correcao: sem Voltar, sem pre-preenchimento, inatividade normal
            iniciarApp();
            Object.assign(state, { idAtendimento: 7, tipo, tela: k + '_ajudante', ajudanteCorrecao: false, ajudante: { nome: 'X', cpf: 'Y' } });
            document.getElementById('tela').innerHTML = telaAjudante();
            R.normal = { ...info(), temVoltar: !!document.getElementById('ajudanteBtnVoltar'), nomeCampo: document.getElementById('ajudanteNome').value };
        }
        // criterio de texto: com/sem a palavra
        const crit = ['Reg. acesso AJUDÁNTE negado', 'ajudante', 'Cracha invalido', '', 'Ajudantes'].map(m => imprMensagemIndicaAjudante(m));
        r.crit = crit;
        window.api = origApi;
        return r;
    });
}


// ---------------------------------------------------------------------------
// Ampliacao de botoes e teclado (2026-10-04): medidas por tela nos 3 viewports.
// ---------------------------------------------------------------------------
async function exercitarAlvosToque(page) {
    return page.evaluate(async () => {
        const esperar = ms => new Promise(res => setTimeout(res, ms));
        const r = { telas: {}, modais: {}, teclado: {}, erros: [] };
        iniciarApp(); // estrutura limpa (barra, #tela, #teclado, modais)
        const IMG = 'data:image/gif;base64,R0lGODlhAQABAAAAACw=';
        state.tipo = 'expedicao'; state.placa = 'ABC1D23'; state.idAtendimento = 7;
        state.exp = estadoExpVazio(); state.rec = estadoRecVazio();
        state.dados = { numero: '12345', cliente_nome: 'Cliente Teste', motorista_nome: 'Fulano de Tal', motorista_cpf: '11144477735', cnh_validade: '2030-01-01', crlv_ano: '2020', crlv_uf: 'SP', crlv_rntc: '123', crlv_tipo_veiculo: 'Truck' };
        state.ordens = [{ numero: '111', data: '01/01', cliente_nome: 'ACME' }, { numero: '222', data: '02/01', cliente_nome: 'BETA' }];
        const tela = document.getElementById('tela'), kb = document.getElementById('teclado');
        const visivel = e => { const c = e.getBoundingClientRect(); return c.width > 0 && c.height > 0 && getComputedStyle(e).visibility !== 'hidden'; };
        const ehPrim = b => b.matches('.btn-primario, .rev-continuar, .conf-confirmar, .tecla-numerica-confirmar');
        const botoes = raiz => [...raiz.querySelectorAll('button')].filter(b => !b.classList.contains('tecla') && !b.classList.contains('tecla-numerica') && visivel(b)).map(b => {
            const c = b.getBoundingClientRect();
            return { t: b.textContent.trim().slice(0, 28), prim: ehPrim(b), h: Math.round(c.height), w: Math.round(c.width), fonte: parseFloat(getComputedStyle(b).fontSize), dentro: c.left >= 0 && c.right <= innerWidth };
        });
        const montar = (nome, html, antes) => {
            fecharTeclado(); fecharModal(); fecharConfirmacaoExcluirNota();
            state.tela = nome;
            document.getElementById('barraCancelar').style.display = 'block';
            if (antes) antes();
            tela.innerHTML = html();
        };
        const medirTela = (nome) => {
            const cancel = document.querySelector('.btn-cancelar').getBoundingClientRect();
            const kbs = [...tela.querySelectorAll('.kb-input')];
            r.telas[nome] = {
                botoes: botoes(tela),
                cancelar: { h: Math.round(cancel.height), w: Math.round(cancel.width), visivel: cancel.top >= 0 && cancel.bottom <= innerHeight },
                semOverflowX: document.documentElement.scrollWidth <= innerWidth && tela.scrollWidth <= tela.clientWidth + 1,
                inputs: kbs.map(e => Math.round(e.getBoundingClientRect().height)),
            };
        };
        const testarTeclado = async (nome, el, rotuloCampo) => {
            tela.scrollTop = 0;
            el.dispatchEvent(new FocusEvent('focusin', { bubbles: true }));
            await esperar(220);
            const tr = tela.getBoundingClientRect(), kr = kb.getBoundingClientRect(), er = el.getBoundingClientRect();
            const teclas = [...kb.querySelectorAll('.tecla')];
            const linhas = [...kb.querySelectorAll('.linha-teclas')].map(l => {
                const ks = [...l.querySelectorAll('.tecla')].map(k => k.getBoundingClientRect());
                return { n: ks.length, esq: Math.round(ks[0].left), dir: Math.round(ks[ks.length - 1].right), minW: Math.round(Math.min(...ks.map(k => k.width))) };
            });
            const gaps = [...kb.querySelectorAll('.linha-teclas')].map(l => { const ks = [...l.querySelectorAll('.tecla')].map(k => k.getBoundingClientRect()); return ks.length > 1 ? Math.round(ks[1].left - ks[0].right) : 0; });
            const esp = kb.querySelector('.tecla-espaco').getBoundingClientRect(), apg = kb.querySelector('.tecla-apagar').getBoundingClientRect(), okk = kb.querySelector('.tecla-ok').getBoundingClientRect();
            const letra = teclas.find(k => k.textContent === 'A').getBoundingClientRect();
            const dados = {
                aberto: kb.classList.contains('aberto'), campoAtivo: campoAtivo === el,
                alturaMin: Math.round(Math.min(...teclas.map(k => k.getBoundingClientRect().height))),
                fonteMin: Math.min(...teclas.filter(k => k.textContent.length === 1).map(k => parseFloat(getComputedStyle(k).fontSize))),
                larguraLetra: Math.round(letra.width), linhas, gaps,
                espacoW: Math.round(esp.width), apagarW: Math.round(apg.width), apagarH: Math.round(apg.height), okW: Math.round(okk.width), okH: Math.round(okk.height),
                especiaisMaiores: apg.width > 2 * letra.width && okk.width > 2 * letra.width,
                chars: teclas.map(k => k.textContent.trim()).join(''),
                painelAlt: Math.round(kr.height), painelTop: Math.round(kr.top),
                campoVisivel: er.top >= tr.top - 1 && er.bottom <= tr.bottom + 1 && er.bottom <= kr.top + 1,
            };
            // botao principal alcancavel acima do painel ao rolar ate o fim; topo alcancavel
            const prim = [...tela.querySelectorAll('.btn-primario, .rev-continuar')].filter(visivel).pop();
            tela.scrollTop = tela.scrollHeight;
            if (prim) { const pr = prim.getBoundingClientRect(); dados.primarioAlcancavel = pr.bottom <= kr.top + 1 && pr.top >= tr.top - 1; } else dados.primarioAlcancavel = true;
            tela.scrollTop = 0;
            const primeiro = [...tela.children].find(visivel);
            dados.topoAlcancavel = !primeiro || primeiro.getBoundingClientRect().top >= tr.top - 1;
            dados.semOverflowX = document.documentElement.scrollWidth <= innerWidth;
            r.teclado[nome + ':' + rotuloCampo] = dados;
            return dados;
        };
        const comTeclado = async (nome, htmlFn, antes, depois) => {
            montar(nome, htmlFn, antes); if (depois) depois();
            medirTela(nome);
            const campos = [...tela.querySelectorAll('.kb-input')].filter(visivel);
            if (campos.length) {
                await testarTeclado(nome, campos[0], 'primeiro');
                if (campos.length > 1) await testarTeclado(nome, campos[campos.length - 1], 'ultimo');
            }
            fecharTeclado();
        };
        const semTeclado = (nome, htmlFn, antes, depois) => { montar(nome, htmlFn, antes); if (depois) depois(); medirTela(nome); };

        try {
            await comTeclado('exp_placa', telaPlacaExpedicao);
            await comTeclado('rec_placa_qtd', telaRecPlacaQtd);
            semTeclado('exp_selecionar_ordem', telaSelecionarOrdem);
            await comTeclado('exp_dados', telaDados);
            semTeclado('exp_cnh_qr', telaExpCnhQr);
            semTeclado('exp_crlv_qr', telaExpCrlvQr);
            semTeclado('rec_cnh_qr', telaRecCnhQr);
            semTeclado('rec_crlv_qr', telaRecCrlvQr);
            await comTeclado('exp_cnh_manual', telaExpCnhManual);
            await comTeclado('exp_crlv_manual', telaExpCrlvManual);
            await comTeclado('rec_cnh_manual', telaRecCnhManual);
            await comTeclado('rec_crlv_manual', telaRecCrlvManual);
            await comTeclado('exp_confirma', () => telaConfirma('retirada de carga'), null, () => ajustarTextareasConfirma());
            state.tipo = 'recebimento';
            await comTeclado('rec_confirma', () => telaConfirma('entrega de carga'), null, () => ajustarTextareasConfirma());
            state.tipo = 'expedicao';
            await comTeclado('exp_ajudante', telaAjudante, null, () => mostrarCamposAjudante());
            await comTeclado('rec_cliente', telaCliente);
            semTeclado('rec_digitaliza', telaDigitaliza);
            state.notasNumeros = [
                { uid: 'n1', estado: 'pendente', confirmado: false, imagem: IMG },
                { uid: 'n2', estado: 'sugerido', confirmado: false, imagem: IMG, numero: '12345', numeroSugerido: '12345' },
                { uid: 'n3', estado: 'sem_sugestao', confirmado: false, imagem: null },
            ];
            semTeclado('rec_revisao_numeros', telaRevisaoNumeros, null, () => atualizarRevisaoNumeros());
            r.revisao = { cartoes: [...tela.querySelectorAll('.rev-cartao')].map(c => Math.round(c.getBoundingClientRect().height)) };
        } catch (e) { r.erros.push('telas: ' + e.message); }

        // ---- modais ----
        const medirModal = (id, nome) => {
            const caixa = document.getElementById(id), c = caixa.getBoundingClientRect();
            const bs = [...caixa.querySelectorAll('button')].filter(b => !b.classList.contains('tecla-numerica')).filter(visivel).map(b => ({ t: b.textContent.trim().slice(0, 28), h: Math.round(b.getBoundingClientRect().height), dentro: b.getBoundingClientRect().left >= c.left && b.getBoundingClientRect().right <= c.right + 1 }));
            r.modais[nome] = { botoes: bs, caixaDentro: c.left >= 0 && c.right <= innerWidth && c.top >= 0 && c.bottom <= innerHeight, caixaW: Math.round(c.width), semOverflowX: caixa.scrollWidth <= caixa.clientWidth + 1 };
            return caixa;
        };
        try {
            montar('exp_placa', telaPlacaExpedicao);
            confirmarCancelar(); medirModal('modalCaixa', 'cancelar'); fecharModal();
            abrirModal('<div class="titulo">Selecione a câmera</div><div class="grupo-botoes"><button class="btn-fantasma">Câmera 1</button><button class="btn-fantasma">Câmera 2</button></div>'); medirModal('modalCaixa', 'camera'); fecharModal();
            state.tela = 'rec_revisao_numeros';
            const detalhar = cx => [...cx.querySelectorAll('button')].filter(visivel).map(b => { const c = b.getBoundingClientRect(), s = getComputedStyle(b); return { t: b.textContent.trim().slice(0, 24), w: Math.round(c.width * 10) / 10, h: Math.round(c.height * 10) / 10, left: Math.round(c.left * 10) / 10, top: Math.round(c.top * 10) / 10, right: Math.round(c.right * 10) / 10, bottom: Math.round(c.bottom * 10) / 10, fonte: s.fontSize, peso: s.fontWeight, raio: s.borderTopLeftRadius, alin: s.textAlign, tecla: b.classList.contains('tecla-numerica') }; });
            abrirModalNumeroNotaSugestao('n2', '12345'); medirModal('modalCaixa', 'nota_sugestao');
            r.modais.nota_sugestao.detalhe = detalhar(document.getElementById('modalCaixa'));
            abrirModalNumeroNotaManual('n2', '', '');
            const caixa = medirModal('modalCaixa', 'nota_manual');
            r.modais.nota_manual.detalhe = detalhar(caixa);
            r.modais.nota_manual.teclas = [...caixa.querySelectorAll('.tecla-numerica')].map(k => { const c = k.getBoundingClientRect(); return { t: k.textContent.trim(), h: Math.round(c.height), w: Math.round(c.width), fonte: parseFloat(getComputedStyle(k).fontSize), noViewport: c.top >= 0 && c.bottom <= innerHeight, dentro: c.left >= 0 && c.right <= innerWidth }; });
            r.modais.nota_manual.campoH = Math.round(document.getElementById('inputNumeroNota').getBoundingClientRect().height);
            r.modais.nota_manual.rolagem = caixa.scrollHeight > caixa.clientHeight;
            r.modais.nota_manual.horizontal = caixa.scrollWidth > caixa.clientWidth + 1;
            fecharModal();
            abrirConfirmacaoExcluirNota('n2'); medirModal('modalConfirmExcluirNotaCaixa', 'excluir_nota'); fecharConfirmacaoExcluirNota();
            state.tela = 'exp_placa'; mostrarInatividade();
            medirModal('modalInatividadeCaixa', 'inatividade'); fecharAvisoInatividade();
        } catch (e) { r.erros.push('modais: ' + e.message); }
        return r;
    });
}

// Estado ao toque (:active) com o mouse pressionado: cor solida de feedback.
async function medirFeedbackToque(page, seletor) {
    const alvo = await page.evaluate(sel => {
        const k = document.querySelector(sel); if (!k) return null;
        k.scrollIntoView({ block: 'center' });
        const c = k.getBoundingClientRect();
        return { x: c.left + c.width / 2, y: c.top + c.height / 2 };
    }, seletor);
    if (!alvo) return null;
    await page.mouse.move(alvo.x, alvo.y);
    await page.mouse.down();
    const ativo = await page.evaluate(sel => { const k = document.querySelector(sel); const s = getComputedStyle(k); return { fundo: s.backgroundColor, cor: s.color, ativa: k.matches(':active') }; }, seletor);
    await page.mouse.up();
    return ativo;
}

// Captura estrutural da tela LGPD e da tela inicial: cada elemento visivel com
// retangulo e estilos que a ampliacao poderia ter alterado.
async function capturarLgpdEHome(page) {
    return page.evaluate(async () => {
        const esperar = ms => new Promise(res => setTimeout(res, ms));
        const props = ['fontSize', 'fontWeight', 'paddingTop', 'paddingBottom', 'paddingLeft', 'paddingRight', 'marginTop', 'marginBottom', 'borderRadius', 'borderTopWidth', 'minHeight', 'maxWidth', 'gap', 'lineHeight', 'color', 'backgroundColor'];
        const foto = (raiz) => [...raiz.querySelectorAll('*')].filter(e => { const c = e.getBoundingClientRect(); return c.width > 0 && c.height > 0; }).map(e => {
            const c = e.getBoundingClientRect(), s = getComputedStyle(e);
            const o = { k: e.tagName + '.' + (typeof e.className === 'string' ? e.className : '') + '#' + e.id, x: Math.round(c.left * 10) / 10, y: Math.round(c.top * 10) / 10, w: Math.round(c.width * 10) / 10, h: Math.round(c.height * 10) / 10 };
            props.forEach(p => { o[p] = s[p]; });
            return JSON.stringify(o);
        });
        const out = {};
        iniciarApp();
        await esperar(50);
        out.lgpd = foto(document.getElementById('app'));
        out.lgpdBotao = Math.round(document.querySelector('.lgpd-btn-continuar').getBoundingClientRect().height);
        abrirModalLgpd(); await esperar(50);
        out.lgpdModal = foto(document.getElementById('app'));
        out.lgpdModalBotao = Math.round(document.querySelector('.modal-lgpd-btn-fechar').getBoundingClientRect().height);
        fecharModalLgpd();
        ir('home'); await esperar(50);
        out.home = foto(document.getElementById('app'));
        out.tiles = [...document.querySelectorAll('.tile')].map(t => { const c = t.getBoundingClientRect(), s = getComputedStyle(t); return { h: Math.round(c.height), w: Math.round(c.width), fonte: s.fontSize, raio: s.borderTopLeftRadius }; });
        out.barraCancelarVisivel = getComputedStyle(document.getElementById('barraCancelar')).display !== 'none';
        return out;
    });
}

async function main() {
    await new Promise(resolve => servidor.listen(0, '127.0.0.1', resolve));
    porta = servidor.address().port;
    const browser = await puppeteer.launch({ executablePath: CHROME, headless: true, args: ['--no-sandbox'] });
    try {
        for (const viewport of [{ width: 768, height: 1366 }, { width: 1080, height: 1920 }, { width: 1152, height: 1846 }]) {
            const page = await browser.newPage();
            await page.setViewport(viewport);
            await page.setRequestInterception(true);
            page.on('request', req => {
                const url = new URL(req.url());
                if (url.protocol === 'data:' || url.protocol === 'blob:') return req.continue();
                if (url.host !== '127.0.0.1:' + porta) { externos++; return req.abort(); }
                return req.continue();
            });
            const erros = [];
            page.on('pageerror', erro => erros.push(erro.message));
            await page.goto('http://127.0.0.1:' + porta + '/');
            const r = await exercitarQr(page);
            console.log(`${viewport.width}x${viewport.height}: exp ${r.expCnh.caixa.w}x${r.expCnh.caixa.h}; rec ${r.recCrlv.caixa.w}x${r.recCrlv.caixa.h}`);
            for (const item of [r.expCnh, r.expCrlv, r.recCnh, r.recCrlv]) {
                ok(item.guia && item.botao === 'Ler QR code', `${viewport.width}: tela QR-only possui guia e acao de leitura`);
            }
            const proporcao = item => item.h === Math.round(item.w * 2448 / 3264);
            ok(r.expCnh.caixa.w === r.recCrlv.caixa.w && r.expCnh.caixa.w <= 900 && r.expCnh.caixa.w > 360, `${viewport.width}: CNH e CRLV usam a mesma largura util ampla`);
            ok(proporcao(r.expCnh.caixa) && proporcao(r.recCrlv.caixa), `${viewport.width}: caixas respeitam a proporcao real do video`);
            ok(r.duasFalhas.chamadasBackend === 0 && r.duasFalhas.tentativas === 2 && r.duasFalhas.telaManual === null, `${viewport.width}: duas falhas locais nao chamam backend nem avancam`);
            ok(r.tresFalhas.chamadasBackend === 0 && r.tresFalhas.telaManual === 'exp_cnh_manual', `${viewport.width}: terceira falha leva ao preenchimento manual sem backend`);
            ok(r.qrValido.chamadasInicio === 1 && r.qrValido.tipo === 'cnh' && r.qrValido.jpeg === 'data:image/jpeg;base64,QUJD' && !r.qrValido.temBinaryData && r.qrValido.tentativas === 0, `${viewport.width}: QR valido inicia uma vez com JPEG e sem binaryData`);
            const ql = await exercitarQrLeitura(page, MATRIZ_TESTE);
            for (const g of ql.guias) {
                const t = `${viewport.width}: guia ${g.pre} (${g.caixaW}x${g.caixaH}, lado ${g.ladoPx}px)`;
                ok(!g.antigaGuia && g.aria === 'true' && g.pe === 'none' && g.miolo, `${t} nova, aria-hidden, pointer-events:none e sem cobrir o centro`);
                ok(g.quadrado && Math.abs(g.ladoH - 0.36) < 0.01 && Math.abs(g.cx - 0.5) < 0.01 && Math.abs(g.cy - 0.5) < 0.01, `${t} quadrada, 36% da altura, centralizada`);
                ok(g.dentro && g.semOverflow, `${t} dentro da caixa, sem overflow`);
                ok(g.cantos && g.corAzul, `${t} cantos (8 tracos x contorno e azul UDLOG)`);
                ok(g.botaoAlt >= 96 && g.botaoDentro, `${t} botao Ler QR code >= 96px e visivel no viewport`);
                ok(g.subtitulo === 'Encaixe o QR code dentro do quadrado, bem iluminado e sem reflexo.', `${t} subtitulo atualizado`);
            }
            for (const k of ['exp', 'rec']) {
                const A = ql[k + 'A'], B = ql[k + 'B'], C = ql[k + 'C'], D = ql[k + 'D'], E = ql[k + 'E'];
                ok(A.frames === 3 && A.lidos === 3 && A.tentativas === 1 && A.backend === 0 && A.redes === 0 && A.tela === null && /Tentativa 1 de 3/.test(A.status), `${viewport.width}: ${k} um toque sem QR le 3 frames e consome UMA tentativa, sem backend/rede`);
                ok(B.aposDois === 2 && B.tentativas === 3 && B.frames === 9 && B.tela === k + '_cnh_manual' && B.backend === 0 && B.redes === 0, `${viewport.width}: ${k} contador por toque (2, 3 -> manual) e 9 frames no total`);
                ok(C.frames === 2 && C.lidos === 2 && C.backend === 1 && C.payloadN === 4 && C.tipo === 'cnh' && C.jpeg === 'data:image/jpeg;base64,F2' && C.cb === 'function' && !/binaryData/.test(C.json) && C.tentativas === 0, `${viewport.width}: ${k} para no 1o frame com QR e envia o JPEG desse frame (payload de inicio inalterado, sem binaryData)`);
                ok(D.frames === 1 && D.lidos === 1 && D.backend === 1, `${viewport.width}: ${k} QR no 1o frame nao captura frames extras`);
                ok(E.frames === 1 && E.backend === 0 && E.tentativas === 0 && E.tela === null && E.emAndamento === false, `${viewport.width}: ${k} tela trocada durante a leitura nao consome tentativa nem chama backend`);
            }
            ok(ql.worker.chaves === 'ok' && ql.worker.ok === true && ql.worker.guia === '{"cx":0.5,"cy":0.5,"lado":0.36}' && ql.worker.orc === 300 && ql.worker.dim === '64x48' && ql.worker.buf, `${viewport.width}: lerQrDoCanvas envia guia+orcamento ao worker e so repassa {ok}`);
            ok(ql.watchdog.ok === false && ql.watchdog.ms >= ql.watchdog.timeout - 50 && ql.watchdog.ms < ql.watchdog.timeout + 1500, `${viewport.width}: watchdog libera a tela se o worker nao responde (${ql.watchdog.ms} ms)`);
            ok(ql.real.guia.ok && ql.real.fora.ok && !ql.real.vazio.ok && Object.values(ql.real).every(x => x.chaves === 'ok'), `${viewport.width}: worker real le QR na guia e fora dela (quadro inteiro), nao le quadro vazio, resposta so {ok}`);
            ok(ql.real.guia.ms < 1000 && ql.real.fora.ms < 1500 && ql.real.vazio.ms < 1500, `${viewport.width}: leitura real dentro do tempo (guia ${ql.real.guia.ms}, fora ${ql.real.fora.ms}, vazio ${ql.real.vazio.ms} ms)`);
            for (const [nome, v] of Object.entries(ql.wvalida.invalidos)) {
                ok(v.ok === false && v.id && v.chaves === 'id,ok' && !v.travou && v.ms < 1000, `${viewport.width}: worker rejeita entrada invalida (${nome}) com {ok:false,id}, sem excecao e sem travar`);
            }
            ok(ql.wvalida.nulo.ok === false && !ql.wvalida.nulo.travou, `${viewport.width}: worker rejeita mensagem nula sem excecao`);
            ok(ql.wvalida.valido.ok === true && ql.wvalida.valido.id === 'v1' && ql.wvalida.valido.chaves === 'id,ok', `${viewport.width}: worker com entrada valida le QR sintetico e responde so {ok,id}`);
            ok(ql.wvalida.vazio.ok === false && ql.wvalida.vazio.chaves === 'id,ok', `${viewport.width}: worker com quadro valido vazio responde {ok:false,id}`);
            ok(ql.camera.a1.length === 2 && ql.camera.a1.every(x => x.includes('continuous')) && ql.camera.a1.some(x => x.includes('focusMode')) && ql.camera.a1.some(x => x.includes('exposureMode')) && !ql.camera.a1.some(x => x.includes('whiteBalance')), `${viewport.width}: camera aplica so as capabilities continuous existentes`);
            ok(ql.camera.a2 === 0 && ql.camera.a3 === 0 && ql.camera.erroCamera === null && ql.camera.a4 === 3, `${viewport.width}: camera sem capability/getCapabilities ou com applyConstraints falhando nao quebra`);
            ok(ql.camera.a5.length === 1 && ql.camera.a5[0].includes('single-shot') && ql.camera.a5ms >= 240 && ql.camera.a6 === 0, `${viewport.width}: single-shot so quando nao ha foco continuo`);
            const m = await exercitarModalReprovacao(page);
            const esperado = {
                placa_divergente: 'A placa do documento é diferente da placa informada no atendimento.',
                cnh_vencida: 'A CNH está vencida.',
                documento_ilegivel: 'Não foi possível ler os dados do documento.',
                dados_invalidos: 'Os dados lidos do documento estão incompletos ou inválidos.',
            };
            ok(m.mensagens.every(x => x.registrou && x.texto === esperado[x.m]), `${viewport.width}: modal mostra a mensagem exata de cada motivo`);
            ok(m.mensagens.every(x => x.botoes.map(b => b.t).join('|') === 'Escanear novamente|Preencher manualmente|Cancelar atendimento' && x.botoes.every(b => b.h >= 88)), `${viewport.width}: botoes do modal presentes e com alvo >= 88px`);
            ok(m.mensagens.every(x => x.dentro), `${viewport.width}: modal sem overflow no viewport`);
            ok(m.nuloSemModal, `${viewport.width}: motivo null/fora da allowlist nao abre modal (mantem manual)`);
            ok(m.modais === 1, `${viewport.width}: sem modal duplicado`);
            ok(m.reescanear.destino === 'exp_crlv_qr' && m.reescanear.tent === 1 && m.reescanear.motivo === null && m.reescanear.flag === true, `${viewport.width}: Escanear novamente volta a tela QR do mesmo documento e conta tentativa`);
            ok(m.terceira.join('|') === 'Preencher manualmente|Cancelar atendimento' && m.aoTres === 'exp_cnh_manual', `${viewport.width}: na terceira tentativa vai ao manual`);
            ok(m.manual.destino === 'exp_cnh_manual' && m.manual.motivo === null, `${viewport.width}: Preencher manualmente vai a tela manual`);
            ok(m.recAbre && m.recDestino === 'rec_crlv_qr', `${viewport.width}: Recebimento abre modal e re-escaneia`);
            ok(m.foraDoFluxo, `${viewport.width}: nao abre modal fora do fluxo`);
            ok(m.spinner && m.rotulo === 'Lendo documento... trazendo os dados.', `${viewport.width}: progresso com indicador e texto claro`);
            const cf = await exercitarConfirmacao(page);
            for (const k of ['exp', 'rec']) {
                const v = cf[k + 'Vazio'];
                ok(v.aberto && v.titulo === 'Não é possível continuar' && v.texto === 'Preencha os campos obrigatórios: RNTRC.' && v.destinos === 0 && v.chamadas === 0, `${viewport.width}: ${k} RNTRC vazio nao envia nem avanca e lista RNTRC no modal`);
                ok(v.botoes.length === 1 && v.botoes[0].t === 'Voltar e preencher' && v.botoes[0].h >= 88 && v.dentro, `${viewport.width}: ${k} modal com botao >= 88px e sem overflow`);
                ok(v.rotuloObrig && v.ph === 'obrigatório' && v.marcado, `${viewport.width}: ${k} RNTRC editavel, marcado obrigatorio e destacado com texto`);
                ok(cf[k + 'Foco'], `${viewport.width}: ${k} Voltar e preencher fecha e foca o primeiro campo vazio`);
                const t = cf[k + 'Todos'];
                const rotAtend = k === 'exp' ? 'Placa, Ordem de coleta' : 'Placa, Cliente';
                ok(t.aberto && t.chamadas === 0 && t.n === t.esperado && t.dentro && t.texto.indexOf('Preencha os campos obrigatórios: Motorista') === 0 && t.texto.indexOf('RNTRC') > 0 && t.texto.endsWith(`Faltam dados do atendimento: ${rotAtend}. Procure o atendente na portaria.`), `${viewport.width}: ${k} todos vazios (espacos) listados no mesmo modal, sem overflow`);
                ok(t.botoes.map(b => b.t).join('|') === 'Voltar e preencher|Cancelar atendimento' && t.botoes.every(b => b.h >= 88), `${viewport.width}: ${k} modal misto com Voltar e preencher + Cancelar, >= 88px`);
                ok(cf[k + 'Leitura'].every(l => l.ro && !l.kb && !l.req && !l.marca && l.fonte >= 15 && l.fundo === 'rgb(242, 242, 242)' && l.cor === 'rgb(58, 58, 58)'), `${viewport.width}: ${k} placa/${k === 'exp' ? 'ordem' : 'cliente'} somente leitura, sem teclado, sem marca de obrigatorio, legiveis`);
                ok(cf[k + 'Leitura'][0].valor === 'ABC1D23' && cf[k + 'EditaveisSemLeitura'], `${viewport.width}: ${k} leitura mostra valor do atendimento e nao e campo editavel validado`);
                const sa = cf[k + 'SoAtend'];
                ok(sa.aberto && sa.titulo === 'Não é possível continuar' && sa.destinos === 0 && sa.chamadas === 0 && sa.texto === `Faltam dados do atendimento: ${rotAtend}. Procure o atendente na portaria.` && sa.dentro, `${viewport.width}: ${k} dado do atendimento vazio nao avanca e manda procurar o atendente`);
                ok(sa.botoes.map(b => b.t).join('|') === 'Voltar|Cancelar atendimento' && sa.botoes.every(b => b.h >= 88) && cf[k + 'SoAtendVoltar'], `${viewport.width}: ${k} modal do atendente sem botao de preencher, Voltar fecha`);
                const s1 = cf[k + 'Srv422Atend'];
                ok(s1.aberto && s1.destinos === 0 && s1.texto === 'Faltam dados do atendimento: Cliente. Procure o atendente na portaria.' && s1.botoes.length === 2 && s1.dentro, `${viewport.width}: ${k} 422 campos [cliente] abre o modal Procure o atendente`);
                const s2 = cf[k + 'Srv422Edit'];
                ok(s2.aberto && s2.destinos === 0 && s2.texto === 'Preencha os campos obrigatórios: rntrc.' && s2.botoes.length === 1 && s2.botoes[0].t === 'Voltar e preencher', `${viewport.width}: ${k} 422 com campo editavel usa rotulos do servidor e modal Preencha`);
                const s3 = cf[k + 'Srv422Misto'];
                ok(s3.aberto && s3.texto === 'Preencha os campos obrigatórios: rntrc. Faltam dados do atendimento: Placa, Ordem de coleta. Procure o atendente na portaria.' && s3.dentro, `${viewport.width}: ${k} 422 misto junta os dois textos em um modal`);
                ok(cf[k + 'Payload'] === 'cnh_validade,crlv_ano,crlv_rntc,crlv_tipo_veiculo,crlv_uf,motorista_cpf,motorista_nome', `${viewport.width}: ${k} payload de salvar-etapa inalterado (sem placa/ordem/cliente)`);
                const c = cf[k + 'Cheio'];
                ok(!c.modal && c.chamadas === 'salvar-etapa' && c.destinos === (k === 'exp' ? 'exp_ajudante' : 'rec_ajudante'), `${viewport.width}: ${k} tudo preenchido avanca`);
                const sv = cf[k + 'Servidor'];
                ok(sv.aberto && sv.titulo === 'Não é possível continuar' && sv.destinos === 0 && sv.html === 'Nao e possivel continuar: preencha RNTRC &lt;b&gt;', `${viewport.width}: ${k} erro 422 do servidor abre o modal com texto escapado`);
            }
            for (const [tipoC, variante] of [['expedicao', 'sem-espaco'], ['recebimento', 'sem-espaco'], ['expedicao', 'com-espaco'], ['recebimento', 'com-espaco']]) {
                const L = await exercitarConfirmacaoLayout(page, tipoC, variante);
                const tg = `${viewport.width}: confirma ${tipoC} (${variante})`;
                ok(L.cartoes === 3 && L.titulos === 'Motorista|Veículo|Atendimento', `${tg} tem 3 cartoes (${L.cartoes}: ${L.titulos})`);
                ok(L.fontes.every(f => f >= 26) && L.rotuloFonte >= 18, `${tg} valores >= 26px (min ${Math.min(...L.fontes)}) e rotulos >= 18px`);
                ok(L.semCorte && !L.overflowX, `${tg} sem corte nem overflow horizontal ${L.cortados.join(',')}`);
                ok(L.motTag === 'TEXTAREA' && L.motValor && L.motLinhas >= 2 && L.motLinhas <= 5 && L.cliTag === (tipoC === 'recebimento' ? 'TEXTAREA' : 'INPUT') && (tipoC === 'expedicao' || (L.cliLinhas >= 2 && L.cliLinhas <= 5)), `${tg} nome 150 em ${L.motLinhas} linhas (h ${L.motH}px)${tipoC === 'recebimento' ? ', cliente 120 em ' + L.cliLinhas + ' linhas (h ' + L.cliH + 'px)' : ''}`);
                ok(L.ro.every(Boolean) && L.rotulosFor, `${tg} somente leitura com readonly e labels associados (for/id)`);
                ok(L.btn.h >= 96 && L.btn.fonte >= 24 && L.btn.dentro && L.btn.fundo === 'rgb(1, 121, 173)' && L.btnNaoCobre, `${tg} Confirmar ${JSON.stringify(L.btn)} nao cobre ${L.btnNaoCobre}`);
                ok(L.tecladoAberto && L.hDepois > L.hAntes && L.digitouSemCorte && L.hVolta < L.hDepois, `${tg} teclado virtual digita no textarea que cresce (${L.hAntes} -> ${L.hDepois}px) e encolhe ao apagar`);
                ok(L.erro.length === 2 && L.erro.every(e => e.borda >= 3 && e.cor === 'rgb(163, 45, 45)' && e.txt === 'Obrigatório') && L.erroNaoEnviou && L.modal && L.focoPrimeiro, `${tg} erro com borda + "Obrigatório", modal e foco no primeiro campo invalido`);
                ok(L.payload === 'cnh_validade,crlv_ano,crlv_rntc,crlv_tipo_veiculo,crlv_uf,motorista_cpf,motorista_nome' && L.payloadNome && L.payloadEtapa, `${tg} payload de salvar-etapa inalterado (7 campos)`);
                console.log(`  confirma ${viewport.width}x${viewport.height} ${tipoC}/${variante}: valor ${Math.min(...L.fontes)}px, rotulo ${L.rotuloFonte}px, nome ${L.motH}px (${L.motLinhas} linhas)${tipoC === 'recebimento' ? ', cliente ' + L.cliH + 'px (' + L.cliLinhas + ' linhas)' : ''}, Confirmar ${L.btn.h}px, rolagem ${L.rolagem ? 'sim' : 'nao'} (${L.alturaConteudo}/${L.alturaTela})`);
                if (process.env.CAPTURAS_DIR && variante === 'sem-espaco') {
                    await page.screenshot({ path: path.join(process.env.CAPTURAS_DIR, `confirma_${viewport.width}_${tipoC}_${variante}.png`) });
                }
            }
            const sp = await exercitarSemPapel(page);
            ok(sp.passo1.titulo === 'Falta de papel na impressora.' && sp.passo1.texto === 'Chame o atendimento.' && sp.passo1.tela === 'sem_papel', `${viewport.width}: sem_papel (HTTP 409) mostra o alerta`);
            ok(sp.passo1.botoes.length === 1 && sp.passo1.botoes[0].t === 'Tentar novamente' && sp.passo1.botoes[0].prim && sp.passo1.botoes[0].h >= 96 && sp.passo1.botoes[0].fundo === 'rgb(1, 121, 173)' && !sp.passo1.overflow, `${viewport.width}: botao Tentar novamente solido >= 96px e sem overflow`);
            ok(!/status|identificador|HTTP|sem_papel|409/.test(sp.passo1.html), `${viewport.width}: alerta sem texto tecnico`);
            ok(sp.passo1.acoes.join(',') === 'finalizar,gerar-etiqueta' && sp.passo1.envios.join(',') === 'ident1', `${viewport.width}: primeira impressao faz finalizar uma vez`);
            ok(sp.idle.disparos === 4 && !sp.idle.aviso && sp.idle.canc === 0 && sp.idle.reagendado && !sp.idle.abandono, `${viewport.width}: sem papel nao dispara aviso nem cancela apos 4x180 s (sem teto)`);
            ok(sp.repetido.durante === 'preparando' || sp.repetido.durante === 'imprimindo', `${viewport.width}: Tentar novamente mostra estado de preparo/impressao`);
            ok(sp.repetido.acoes.join(',') === 'gerar-etiqueta' && sp.repetido.envios.join(',') === 'ident1,ident2', `${viewport.width}: retentativa reenvia so a etiqueta com identificador novo e sem novo finalizar`);
            ok(sp.repetido.tela === 'sem_papel' && sp.repetido.titulo === 'Falta de papel na impressora.' && !sp.idle2.aviso && sp.idle2.canc === 0, `${viewport.width}: sem_papel repetido volta ao alerta, ainda sem inatividade`);
            ok(sp.sucesso.tela === 'concluido' && sp.sucesso.acoes.join(',') === 'gerar-etiqueta' && sp.sucesso.envios.join(',') === 'ident1,ident2,ident3', `${viewport.width}: impressao ok conclui sem novo finalizar`);
            ok(sp.normal.aviso && sp.normal.abandono, `${viewport.width}: apos sucesso a inatividade normal volta (aviso + abandono)`);
            ok(sp.avisoAntes && sp.http200.tela === 'sem_papel' && !sp.http200.aviso && !sp.http200.abandono, `${viewport.width}: sem_papel com HTTP 200 vale e fecha aviso aberto`);
            ok(sp.erro === false && sp.indeterminadoSuspensa === true && sp.indeterminadoAviso === false && sp.erroFinalizarSuspensa === true && sp.erroFinalizarAviso === false, `${viewport.width}: erro, indeterminado e erro_finalizar suspendem a inatividade`);
            ok(sp.imprimindoSuspensa === false && sp.concluidoSuspensa === false && sp.outraTela === false, `${viewport.width}: imprimindo, concluido e outras telas mantem a inatividade normal`);
            const ea = await exercitarEtiquetaAjudante(page);
            const LINHA = 'A etiqueta do ajudante ainda não foi impressa.';
            const botoesOk = x => x.botoes.every(b => b.h >= 80) && !x.overflow;
            ok(ea.semAj.tela === 'concluido' && ea.semAj.chamadas === 'finalizar,gerar-etiqueta' && ea.semAj.envios === 'mt1' && ea.semAj.texto.includes('Retire o comprovante na bandeja abaixo') && !ea.semAj.texto.includes('duas etiquetas') && ea.semAj.progresso.join('|') === 'Imprimindo...', `${viewport.width}: sem ajudante mantem o fluxo atual (1 etiqueta, texto atual)`);
            ok(ea.comAj.chamadas === 'finalizar,gerar-etiqueta,gerar-etiqueta:ajudante' && ea.comAj.envios.split(',').length === 2 && /^mt\d+,aj\d+$/.test(ea.comAj.envios), `${viewport.width}: com ajudante imprime motorista e depois ajudante (ordem e destinatario)`);
            ok(ea.comAj.tela === 'concluido' && ea.comAj.texto.includes('Retire as duas etiquetas.') && ea.comAj.progresso.join('|') === 'Imprimindo etiqueta do motorista...|Imprimindo etiqueta do ajudante...' && botoesOk(ea.comAj), `${viewport.width}: concluido so apos as duas, com mensagens de progresso e "Retire as duas etiquetas."`);
            for (const k of ['sem_papel', 'erro', 'indeterminado']) {
                const { f1, f2 } = ea.falhaAj[k];
                ok(f1.tela === k && f1.pendente === 'ajudante' && f1.motImp === true && f1.texto.includes(LINHA) && f1.suspensa === true && botoesOk(f1) && f1.botoes.some(b => b.t === 'Tentar novamente') && f1.envios.split(',').length === 2, `${viewport.width}: falha do ajudante (${k}) mostra alerta + linha do ajudante, suspende inatividade, botoes >= 80px`);
                ok(f2.chamadas === 'gerar-etiqueta:ajudante' && f2.reimps === '1' && f2.envios.split(',').length === 1 && /^aj/.test(f2.envios) && f2.tela === 'concluido' && f2.texto.includes('Retire as duas etiquetas.'), `${viewport.width}: Tentar novamente (${k}) chama SOMENTE gerar-etiqueta do ajudante (sem motorista nem finalizar) e conclui`);
            }
            ok(ea.falhaAj.sem_papel.f1.texto.includes('Falta de papel na impressora.') && ea.falhaAj.sem_papel.f1.texto.includes('Chame o atendimento.'), `${viewport.width}: sem_papel do ajudante mantem o texto de falta de papel`);
            ok(ea.falhaAj.indeterminado.f1.texto.includes('Não foi possível confirmar a impressão'), `${viewport.width}: indeterminado do ajudante mantem o texto atual`);
            ok(ea.dupla.dupla1.tela === 'sem_papel' && ea.dupla.dupla1.chamadas === 'gerar-etiqueta:ajudante' && ea.dupla.fim.tela === 'concluido' && ea.dupla.fim.chamadas === 'gerar-etiqueta:ajudante', `${viewport.width}: ajudante falha de novo e depois imprime, sempre so a etiqueta do ajudante`);
            for (const k of ['sem_papel', 'erro', 'indeterminado']) {
                const { m1, m2 } = ea.falhaMot[k];
                ok(m1.tela === k && m1.pendente === 'motorista' && m1.motImp === false && !m1.texto.includes(LINHA) && m1.suspensa === true && botoesOk(m1), `${viewport.width}: falha do motorista (${k}) sem linha do ajudante, inatividade suspensa`);
                ok(m2.chamadas === 'gerar-etiqueta,gerar-etiqueta:ajudante' && m2.reimps === '1,1' && !m2.chamadas.includes('finalizar') && m2.tela === 'concluido', `${viewport.width}: retry apos falha do motorista (${k}) reimprime o motorista sem finalizar e depois o ajudante`);
            }
            ok(ea.e409.e1.tela === 'erro' && ea.e409.e1.texto.includes('Não foi possível imprimir a etiqueta do ajudante. Chame o atendimento.') && ea.e409.e1.texto.includes(LINHA) && ea.e409.e1.botoes.map(b => b.t).join('|') === 'Tentar novamente|Novo atendimento' && ea.e409.e1.suspensa && botoesOk(ea.e409.e1), `${viewport.width}: 409 do backend no ajudante vira erro leve com Tentar novamente e Novo atendimento`);
            ok(ea.e409.e2.chamadas === 'gerar-etiqueta:ajudante' && ea.e409.e2.tela === 'concluido', `${viewport.width}: retry do 409 chama so o ajudante`);
            ok(ea.antesReset.pendente === 'ajudante' && ea.antesReset.mot && ea.antesReset.aj && ea.depoisReset.pendente === 'motorista' && !ea.depoisReset.mot && !ea.depoisReset.aj, `${viewport.width}: novoAtendimento reinicia o estado das etiquetas`);
            const ef = await exercitarErroFinalizar(page);
            const FRASE = /senha ser[aá] processada/i;
            ok(ef.com.tela === 'erro_finalizar' && ef.com.motivo === 'Reg. acesso ajudante. Crachá já associado a outro ajudante. Ação não permitida.' && ef.com.texto.includes('Motivo informado pelo sistema:') && ef.com.texto.includes('Chame o atendimento.'), `${viewport.width}: erro_finalizar com mensagem_api mostra motivo + orientacao`);
            ok(!FRASE.test(ef.com.texto) && !/Não foi possível concluir o check-in agora/.test(ef.com.texto), `${viewport.width}: com mensagem_api a frase generica/"senha sera processada" nao aparece`);
            ok(ef.longa.motivo === ef.L300 && ef.longa.motivo.length === 300 && !ef.longa.overflow && ef.longa.fonte >= 20, `${viewport.width}: mensagem de 300 chars exibida inteira, fonte >= 20px, sem overflow`);
            ok(ef.contig.motivo.length === 300 && !ef.contig.overflow, `${viewport.width}: 300 chars sem espacos quebra sem estourar`);
            ok(ef.html.motivo === ef.htmlLiteral && ef.html.filhosMotivo === 0 && ef.html.scripts === 0 && ef.html.xss === 0 && !ef.html.overflow, `${viewport.width}: HTML/script aparece como texto, sem injetar elementos nem executar`);
            ok(ef.com.botoes.map(b => b.t).join('|') === 'Corrigir dados do ajudante|Tentar novamente|Novo atendimento' &&ef.com.botoes.every(b => b.h >= 80) && ef.longa.botoes.every(b => b.h >= 80), `${viewport.width}: erro_finalizar com motivo mantem botoes >= 80px`);
            ok(ef.sem.motivo === null && ef.sem.texto.includes('Não foi possível concluir o check-in agora. Chame o atendente.') && !ef.sem.texto.includes('Motivo informado') && ef.sem.botoes.length === 2 && ef.sem.botoes.every(b => b.h >= 80), `${viewport.width}: sem mensagem_api mantem a tela atual`);
            ok(ef.vazia.motivo === null && ef.naoString.motivo === null && ef.mensagemApiAposSem === '', `${viewport.width}: mensagem_api vazia/nao string e ignorada e nao vaza para a tentativa seguinte`);
            ok([ef.com, ef.longa, ef.html, ef.sem].every(x => x.suspensa === true), `${viewport.width}: inatividade suspensa em erro_finalizar (com e sem motivo)`);
            ok(ef.html.botoes.length === 2 && ef.sem.botoes.length === 2 && ef.sem.botoes.every(b => b.t !== 'Corrigir dados do ajudante') && !ef.html.botoes.some(b => b.t === 'Corrigir dados do ajudante'), `${viewport.width}: sem a palavra "ajudante" nao mostra "Corrigir dados do ajudante"`);
            const ca = await exercitarCorrecaoAjudante(page);
            ok(ca.crit.join(',') === 'true,true,false,false,true', `${viewport.width}: criterio de texto (acentos/caixa) para erro de ajudante`);
            for (const k of ['exp', 'rec']) {
                const c = ca[k];
                const tAj = k + '_ajudante', tImp = k + '_impressao';
                ok(c.botoes[0].t === 'Corrigir dados do ajudante' && c.botoes[0].prim && c.botoes.every(b => b.h >= 80), `${viewport.width}: ${k} erro_finalizar mostra Corrigir dados do ajudante primario >= 80px`);
                ok(c.abre.tela === tAj && c.abre.corr === true && c.abre.perguntaVis && !c.abre.camposVis, `${viewport.width}: ${k} botao abre a tela do ajudante em modo correcao na pergunta`);
                ok(c.sim.camposVis && c.sim.nome === 'JOSE LIMA' && c.sim.cpf === '52998224725' && c.sim.voltarAlt >= 80 && c.sim.confirmarAlt >= 80 && !c.sim.overflow, `${viewport.width}: ${k} campos pre-preenchidos, Voltar/Confirmar >= 80px, sem overflow`);
                ok(c.voltar.tela === tImp && c.voltar.imprTela === 'erro_finalizar' && c.voltar.chamadas === 0 && c.voltar.corr === false && c.voltar.flagRetorno === false && c.voltar.motivo === 'Reg. acesso ajudante. Crachá já associado a outro ajudante. Ação não permitida.' && c.voltar.temCorrigir, `${viewport.width}: ${k} Voltar retorna a erro_finalizar sem chamar a API`);
                ok(c.e422.tela === tAj && c.e422.camposVis && c.e422.erro === 'Dados do ajudante invalidos: informe o nome completo e um CPF valido' && c.e422.nome === 'AB' && c.e422.acoes === 'salvar-etapa' && !c.e422.overflow, `${viewport.width}: ${k} 422 mantem a tela, campos editaveis e mostra a mensagem`);
                ok(c.e409.tela === tAj && c.e409.erro.startsWith('Nao e possivel alterar o ajudante') && !c.e409.camposVis && !c.e409.perguntaVis && c.e409.novoVis && c.e409.novoAlt >= 80 && c.e409.voltarAlt >= 80 && c.e409.acoes === 'salvar-etapa,salvar-etapa' && !c.e409.overflow && c.e409.erroFilhos === 0, `${viewport.width}: ${k} 409 mostra a mensagem com Voltar e Novo atendimento`);
                ok(c.novo.tela === 'lgpd' && c.novo.corr === false, `${viewport.width}: ${k} Novo atendimento zera o modo correcao`);
                ok(c.ok.acoes === 'salvar-etapa,finalizar' && c.ok.payload === JSON.stringify({ id_atendimento: 7, etapa: 'ajudante', dados: { nome: 'MARIA SOUZA', cpf: '11144477735' } }) && c.ok.tela === tImp && c.ok.corr === false && c.ok.imprTela === 'erro_finalizar', `${viewport.width}: ${k} salvar chama salvar-etapa ajudante e depois finalizar de novo (sem fluxo normal)`);
                ok(c.semAj.acoes === 'salvar-etapa,finalizar' && c.semAj.payload === JSON.stringify({ id_atendimento: 7, etapa: 'ajudante', dados: { nome: null, cpf: null } }) && c.semAj.tela === tImp, `${viewport.width}: ${k} sem ajudante envia nulls e refaz o finalizar`);
                ok(c.vazio.corr && c.vazio.camposVis === false, `${viewport.width}: ${k} correcao sem dados do front abre a pergunta`);
                ok(c.abre.suspensa && c.sim.suspensa && c.e422.suspensa && c.e409.suspensa && c.voltar.suspensa, `${viewport.width}: ${k} inatividade suspensa na correcao e na tela de erro`);
                ok(c.normal.suspensa === false && !c.normal.temVoltar && c.normal.nomeCampo === '' && c.normal.tela === tAj, `${viewport.width}: ${k} fora do modo correcao: sem Voltar, sem pre-preenchimento e inatividade normal`);
            }
            // ---- ampliacao de botoes e teclado (alvos de toque) ----
            const W = viewport.width;
            const at = await exercitarAlvosToque(page);
            ok(at.erros.length === 0, `${W}: medicao de telas/modais sem excecao ${at.erros.join(';')}`);
            const nomesTelas = Object.keys(at.telas);
            ok(nomesTelas.length === 18, `${W}: 18 telas dos fluxos medidas (${nomesTelas.length})`);
            for (const nome of nomesTelas) {
                const t = at.telas[nome], tg = `${W}: ${nome}`;
                const prim = t.botoes.filter(b => b.prim), sec = t.botoes.filter(b => !b.prim);
                ok(t.botoes.length > 0, `${tg} tem botoes visiveis`);
                ok(prim.every(b => b.h >= 96), `${tg} botoes principais >= 96px (${prim.map(b => b.h).join(',')})`);
                ok(sec.every(b => b.h >= 80), `${tg} botoes secundarios >= 80px (${sec.map(b => b.h).join(',')})`);
                ok(t.botoes.every(b => b.dentro) && t.semOverflowX, `${tg} sem overflow horizontal`);
                ok(t.cancelar.h >= 72 && t.cancelar.visivel, `${tg} Cancelar atendimento >= 72px e visivel (${t.cancelar.h}px)`);
                ok(t.inputs.every(h => h >= 84), `${tg} campos >= 84px (${t.inputs.join(',')})`);
            }
            console.log(`  alvos ${W}x${viewport.height}: cancelar ${at.telas.exp_placa.cancelar.h}px; Consultar ${at.telas.exp_placa.botoes[0].h}px; Ler QR ${at.telas.exp_cnh_qr.botoes[0].h}px; Confirmar ${at.telas.exp_confirma.botoes[0].h}px; Continuar revisao ${at.telas.rec_revisao_numeros.botoes.filter(b => b.prim)[0].h}px; cartao nota ${at.revisao.cartoes[0]}px`);
            const CARACTERES = [...'1234567890QWERTYUIOPASDFGHJKLÇZXCVBNM,.'];
            for (const [nome, k] of Object.entries(at.teclado)) {
                const tg = `${W}: teclado em ${nome}`;
                ok(k.aberto && k.campoAtivo, `${tg} abre e fica ligado ao campo em foco`);
                ok(k.alturaMin >= 96 && k.apagarH >= 96 && k.okH >= 96, `${tg} teclas >= 96px de altura (${k.alturaMin}px)`);
                ok(k.fonteMin >= 36, `${tg} fonte das teclas >= 36px (${k.fonteMin}px)`);
                const larguraEsperada = Math.floor((W - 12 - 9 * 6) / 10) - 1;
                ok(k.larguraLetra >= larguraEsperada, `${tg} largura maxima: tecla ${k.larguraLetra}px (>= ${larguraEsperada})`);
                ok(k.linhas.every(l => l.esq <= 8 && l.dir >= W - 8), `${tg} cada linha usa toda a largura util (margem lateral <= 8px)`);
                ok(k.gaps.every(g => g >= 4 && g <= 12), `${tg} espacamento entre teclas ${k.gaps.join('/')}px`);
                ok(k.especiaisMaiores && k.espacoW > 3 * k.larguraLetra, `${tg} apagar, OK e espaco bem mais largos que as teclas comuns`);
                ok(CARACTERES.every(c => k.chars.includes(c)) && k.chars.includes('⌫') && k.chars.includes('espaço') && k.chars.includes('OK'), `${tg} todos os caracteres continuam disponiveis (${k.chars})`);
                ok(k.campoVisivel, `${tg} campo em foco visivel acima do painel`);
                ok(k.primarioAlcancavel && k.topoAlcancavel, `${tg} botao principal alcancavel acima do painel (rolagem) e topo da tela alcancavel`);
                ok(k.semOverflowX, `${tg} sem overflow horizontal`);
            }
            const kp = at.teclado['exp_placa:primeiro'];
            console.log(`  teclado ${W}x${viewport.height}: tecla ${kp.alturaMin}px alt x ${kp.larguraLetra}px larg, fonte ${kp.fonteMin}px, apagar ${kp.apagarW}x${kp.apagarH}, OK ${kp.okW}x${kp.okH}, espaco ${kp.espacoW}px, painel ${kp.painelAlt}px (${Math.round(kp.painelAlt / viewport.height * 100)}% da altura)`);
            for (const nome of ['cancelar', 'camera', 'nota_sugestao', 'nota_manual', 'excluir_nota', 'inatividade']) {
                const m = at.modais[nome], tg = `${W}: modal ${nome}`;
                ok(m && m.botoes.length > 0 && m.botoes.every(b => b.h >= 88 && b.dentro), `${tg} botoes >= 88px (${m && m.botoes.map(b => b.h).join(',')})`);
                ok(m.caixaDentro && m.semOverflowX, `${tg} dentro do viewport e sem overflow horizontal`);
            }
            const nm = at.modais.nota_manual;
            if (process.env.DETALHE) for (const k of ['nota_sugestao', 'nota_manual']) console.log('DET ' + W + ' ' + k + '\n' + at.modais[k].detalhe.map(d => `  ${d.t.padEnd(24)} ${d.w}x${d.h} L${d.left} T${d.top} R${d.right} B${d.bottom} ${d.fonte}/${d.peso} r${d.raio}`).join('\n') + `\n  rolagem=${at.modais[k].rolagem}`);
            ok(nm.teclas.length === 12 && nm.teclas.every(k => k.h >= 120 && k.dentro && k.noViewport), `${W}: teclado numerico da nota: 12 teclas >= 120px, dentro do viewport sem rolar (${nm.teclas.map(k => k.h).join(',')})`);
            ok(nm.teclas.filter(k => /^\d$/.test(k.t)).length === 10 && nm.teclas.filter(k => /^\d$/.test(k.t)).every(k => k.fonte >= 48 && k.w >= 120), `${W}: digitos do teclado numerico com fonte >= 48px e largura >= 120px (${nm.teclas[0].w}px)`);
            {
                // Padronizacao dos modais de numero da nota (teclas e botoes de acao)
                const tk = nm.detalhe.filter(d => d.tecla), ac = d => d.filter(x => !x.tecla);
                const dig = tk.filter(d => /^\d$/.test(d.t)), apg = tk.find(d => d.t === 'Apagar'), cfm = tk.find(d => d.t === 'Confirmar');
                const iguais = (a, b) => Math.abs(a - b) <= 1;
                ok(tk.length === 12 && tk.every(d => iguais(d.w, tk[0].w) && iguais(d.h, tk[0].h)), `${W}: 12 teclas do numerico com a mesma largura e altura (${tk[0].w}x${tk[0].h})`);
                ok(tk.every(d => d.h >= 120), `${W}: teclas do numerico >= 120px de altura`);
                ok(new Set(dig.map(d => d.fonte + '/' + d.peso)).size === 1 && dig.length === 10, `${W}: digitos com a mesma fonte (${dig[0].fonte}/${dig[0].peso})`);
                ok(apg && cfm && apg.fonte === cfm.fonte && apg.peso === cfm.peso, `${W}: Apagar e Confirmar com a mesma fonte (${apg.fonte}/${apg.peso})`);
                ok(new Set(tk.map(d => d.raio)).size === 1, `${W}: teclas com o mesmo raio de canto`);
                const col = [...new Set(tk.map(d => Math.round(d.left)))].sort((a, b) => a - b), lin = [...new Set(tk.map(d => Math.round(d.top)))].sort((a, b) => a - b);
                const gH = col.slice(1).map((v, i) => v - col[i] - tk[0].w), gV = lin.slice(1).map((v, i) => v - lin[i] - tk[0].h);
                ok(col.length === 3 && lin.length === 4 && [...gH, ...gV].every(g => iguais(g, gH[0])), `${W}: grade regular 3x4 com gap uniforme (${gH.map(g => Math.round(g)).join('/')} x ${gV.map(g => Math.round(g)).join('/')})`);
                ok(tk.every(d => d.left >= 0 && d.right <= W && d.top >= 0 && d.bottom <= viewport.height || nm.rolagem), `${W}: teclas dentro do viewport (ou na rolagem interna do modal)`);
                for (const [rot, det] of [['manual', nm.detalhe], ['sugestao', at.modais.nota_sugestao.detalhe]]) {
                    const b = ac(det);
                    ok(b.length >= 3 && b.every(x => iguais(x.h, b[0].h) && iguais(x.w, b[0].w) && x.fonte === b[0].fonte && x.peso === b[0].peso && x.raio === b[0].raio && x.h >= 88), `${W}: botoes de acao do modal ${rot} (${b.map(x => x.t.replace('✕ ', '')).join(', ')}) com mesma largura, altura, fonte e cantos (${b[0].w}x${b[0].h}, ${b[0].fonte}/${b[0].peso}, ${b[0].raio})`);
                    ok(b.every(x => x.left >= 0 && x.right <= W), `${W}: botoes de acao do modal ${rot} sem sair da largura do viewport`);
                }
                ok(iguais(ac(nm.detalhe)[0].w, tk[2].right - tk[0].left), `${W}: botoes de acao com a largura do teclado numerico`);
                ok(!nm.horizontal, `${W}: modal do numero sem overflow horizontal`);
                console.log(`  padronizacao ${W}x${viewport.height}: teclas ${tk[0].w}x${tk[0].h} (digitos ${dig[0].fonte}, Apagar/Confirmar ${apg.fonte}), gap ${Math.round(gH[0])}px; acoes ${ac(nm.detalhe)[0].w}x${ac(nm.detalhe)[0].h}`);
            }
            ok(nm.campoH >= 100, `${W}: campo do numero da nota >= 100px (${nm.campoH}px)`);
            console.log(`  numerico ${W}x${viewport.height}: tecla ${nm.teclas[0].w}x${nm.teclas[0].h}px, fonte ${nm.teclas[0].fonte}px, modal ${nm.caixaW}px, rolagem interna ${nm.rolagem ? 'sim' : 'nao'}; botoes de modal min ${Math.min(...Object.values(at.modais).flatMap(m => m.botoes.map(b => b.h)))}px`);
            // feedback visual ao toque (:active, cor solida) e nenhum :hover no bloco novo
            await page.evaluate(() => { document.getElementById('tela').innerHTML = telaPlacaExpedicao(); abrirTeclado(document.getElementById('inputPlaca')); });
            const fbTecla = await medirFeedbackToque(page, '#teclado .tecla:not(.tecla-ok):not(.tecla-apagar):not(.tecla-espaco)');
            const fbApagar = await medirFeedbackToque(page, '#teclado .tecla-apagar');
            const fbPrim = await medirFeedbackToque(page, '#tela .btn-primario');
            const fbOk = await medirFeedbackToque(page, '#teclado .tecla-ok');
            await page.evaluate(() => { abrirModalNumeroNotaManual('n2', '', ''); });
            const fbNum = await medirFeedbackToque(page, '.tecla-numerica:not(.tecla-numerica-apagar):not(.tecla-numerica-confirmar)');
            await page.evaluate(() => { fecharModal(); });
            ok(fbTecla && fbTecla.ativa && fbTecla.fundo === 'rgb(1, 121, 173)' && fbTecla.cor === 'rgb(255, 255, 255)', `${W}: tecla do teclado ao toque fica azul solido com texto branco (${fbTecla && fbTecla.fundo})`);
            ok(fbApagar && fbApagar.ativa && fbApagar.fundo === 'rgb(58, 58, 58)', `${W}: apagar ao toque fica cinza escuro solido (${fbApagar && fbApagar.fundo})`);
            ok(fbOk && fbOk.ativa && fbOk.fundo === 'rgb(1, 90, 130)', `${W}: OK ao toque escurece (${fbOk && fbOk.fundo})`);
            ok(fbPrim && fbPrim.ativa && fbPrim.fundo === 'rgb(1, 90, 130)', `${W}: botao principal ao toque escurece (${fbPrim && fbPrim.fundo})`);
            ok(fbNum && fbNum.ativa && fbNum.fundo === 'rgb(1, 121, 173)' && fbNum.cor === 'rgb(255, 255, 255)', `${W}: tecla numerica ao toque fica azul solido (${fbNum && fbNum.fundo})`);

            // ---- LGPD e tela inicial: IDENTICAS ao HEAD (mesmo app.js, app.css do commit) ----
            const novo = await capturarLgpdEHome(page);
            const paginaHead = await browser.newPage();
            await paginaHead.setViewport(viewport);
            await paginaHead.setRequestInterception(true);
            paginaHead.on('request', req => {
                const url = new URL(req.url());
                if (url.protocol === 'data:' || url.protocol === 'blob:') return req.continue();
                if (url.host !== '127.0.0.1:' + porta) { externos++; return req.abort(); }
                if (url.pathname === '/assets/app.css') return req.respond({ status: 200, contentType: 'text/css', body: CSS_HEAD || '' });
                return req.continue();
            });
            await paginaHead.goto('http://127.0.0.1:' + porta + '/');
            const antigo = await capturarLgpdEHome(paginaHead);
            await paginaHead.close();
            ok(!!CSS_HEAD && CSS_HEAD.includes('.tile { width: 100%; height: 200px'), `${W}: CSS do HEAD carregado para comparacao`);
            ok(novo.lgpd.length > 5 && JSON.stringify(novo.lgpd) === JSON.stringify(antigo.lgpd), `${W}: tela LGPD identica ao HEAD (${novo.lgpd.length} elementos, retangulos e estilos)`);
            ok(novo.lgpdModal.length > novo.lgpd.length && JSON.stringify(novo.lgpdModal) === JSON.stringify(antigo.lgpdModal), `${W}: modal do termo LGPD identico ao HEAD`);
            ok(novo.home.length >= 3 && JSON.stringify(novo.home) === JSON.stringify(antigo.home), `${W}: tela inicial Recebimento/Expedicao identica ao HEAD (${novo.home.length} elementos)`);
            ok(novo.lgpdBotao === antigo.lgpdBotao && novo.lgpdModalBotao === antigo.lgpdModalBotao, `${W}: botoes da LGPD com as medidas antigas (${novo.lgpdBotao}px / modal ${novo.lgpdModalBotao}px)`);
            ok(novo.tiles.length === 2 && novo.tiles.every(t => t.h === 200 && t.fonte === '27px' && t.raio === '14px') && !novo.barraCancelarVisivel, `${W}: tiles Expedicao/Recebimento 200px/27px/14px e sem Cancelar na inicial`);
            console.log(`  LGPD/inicial ${W}x${viewport.height}: iguais ao HEAD (Continuar ${novo.lgpdBotao}px, tiles ${novo.tiles[0].h}px)`);
            ok(erros.length === 0, `${viewport.width}: sem pageerror`);
            await page.close();
        }
    } finally {
        await browser.close();
        await new Promise(resolve => servidor.close(resolve));
    }
    const fonte = fs.readFileSync(APPJS, 'utf8');
    const semComentarios = fonte.replace(/^\s*\/\/.*$/gm, '');
    ok(fonte.includes('Lendo documento... trazendo os dados.') && !semComentarios.includes('QR lido. Enviando'), 'texto de progresso novo presente e antigo removido');
    ok(['Escanear novamente', 'Preencher manualmente', 'A CNH está vencida.', 'Não foi possível ler os dados do documento.', 'Não é possível continuar', 'Voltar e preencher', 'Preencha os campos obrigatórios:', 'Faltam dados do atendimento:', 'Procure o atendente na portaria.'].every(t => fonte.includes(t)), 'strings do modal presentes em UTF-8');
    ok(!/rntrc_ausente|não possui RNTRC/i.test(fonte), 'app.js sem motivo/mensagem rntrc_ausente');
    const fonteImpr = fs.readFileSync(IMPRJS, 'utf8');
    ok(['Imprimindo etiqueta do motorista...', 'Imprimindo etiqueta do ajudante...', 'Retire as duas etiquetas.', 'A etiqueta do ajudante ainda não foi impressa.', 'Não foi possível imprimir a etiqueta do ajudante. Chame o atendimento.'].every(t => fonteImpr.includes(t)) && !/Ã[\u0080-¿]|â€|�/.test(fonteImpr), 'impressao.js com textos novos em UTF-8 e sem mojibake');
    ok(!/Ã[\u0080-¿]|â€|�/.test(fonte), 'app.js sem mojibake');
    ok(!/binaryData|FormData|cnh_frente|cnh_verso|\bfrente\b|\bverso\b/.test(semComentarios), 'sem legado (binaryData, upload, frente/verso)');
    ok(externos === 0, 'zero requisicoes externas');
    // CSS da ampliacao: sem :hover (cor solida sempre visivel; feedback so por :active)
    const cssAtual = fs.readFileSync(APPCSS, 'utf8').replace(/\/\*[\s\S]*?\*\//g, '');
    const blocoNovo = cssAtual.slice(cssAtual.lastIndexOf('.barra-cancelar { padding: 12px 20px 0; }'));
    ok(blocoNovo.length > 500 && !/:hover/.test(blocoNovo) && /\.tecla:active/.test(blocoNovo) && /\.tecla-numerica:active/.test(blocoNovo), 'bloco de ampliacao sem :hover e com estado :active nas teclas');
    ok(!/Ã[\u0080-¿]|â€|�/.test(fs.readFileSync(APPCSS, 'utf8')), 'app.css sem mojibake');
    // Titulo e favicon (index.php e a unica pagina HTML servida ao navegador)
    const indexPhp = fs.readFileSync(path.join(RAIZ, 'public', 'totem', 'index.php'), 'utf8');
    ok(/<title>Totem<\/title>/.test(indexPhp) && (indexPhp.match(/<title>/g) || []).length === 1, 'index.php: title === Totem (unico)');
    ok(!/document\.title/.test(semComentarios) && !/document\.title/.test(fs.readFileSync(IMPRJS, 'utf8')), 'JS nao altera document.title');
    const linkVersionado = (rel, arq, extra) => indexPhp.includes('<link rel="' + rel + '"' + extra + ' href="assets/' + arq + '?v=<?= (int) @filemtime(__DIR__ . \'/assets/' + arq + '\') ?>"');
    ok(linkVersionado('icon', 'favicon.svg', ' type="image/svg+xml"'), 'index.php: link rel=icon svg versionado');
    ok(linkVersionado('icon', 'favicon-32.png', ' type="image/png" sizes="32x32"'), 'index.php: link rel=icon png 32 versionado');
    ok(linkVersionado('apple-touch-icon', 'apple-touch-icon.png', ' sizes="180x180"'), 'index.php: apple-touch-icon versionado');
    const dirAssets = path.join(RAIZ, 'public', 'totem', 'assets');
    for (const [arq, ass] of [['favicon.svg', null], ['favicon-32.png', [32, 32]], ['favicon-192.png', [192, 192]], ['apple-touch-icon.png', [180, 180]], ['favicon.ico', null]]) {
        const buf = fs.existsSync(path.join(dirAssets, arq)) ? fs.readFileSync(path.join(dirAssets, arq)) : Buffer.alloc(0);
        ok(buf.length > 100, arq + ' existe e nao esta vazio');
        if (ass) ok(buf.subarray(1, 4).toString() === 'PNG' && buf.readUInt32BE(16) === ass[0] && buf.readUInt32BE(20) === ass[1], arq + ' e PNG ' + ass.join('x'));
    }
    const svgTxt = fs.readFileSync(path.join(dirAssets, 'favicon.svg'), 'utf8');
    const svgPage = await (async () => { const b2 = await puppeteer.launch({ executablePath: CHROME, headless: true, args: ['--no-sandbox'] }); try { const pg = await b2.newPage(); await pg.setContent('<body></body>'); return await pg.evaluate(t => { const d = new DOMParser().parseFromString(t, 'image/svg+xml'); return { erro: !!d.querySelector('parsererror'), raiz: d.documentElement.localName, vb: d.documentElement.getAttribute('viewBox') }; }, svgTxt); } finally { await b2.close(); } })();
    ok(!svgPage.erro && svgPage.raiz === 'svg' && svgPage.vb === '0 0 64 64' && !/href=|<text|@import|url\(http/.test(svgTxt), 'favicon.svg parseavel, sem recursos externos/fontes');
    console.log(`vio_captura_layout: ${passou} verificacoes, ${falhou} falhas`);
    process.exitCode = falhou ? 1 : 0;
}
main().catch(erro => { console.error(erro.stack || erro); process.exitCode = 1; });
