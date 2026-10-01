// Regressao local QR-only de CNH/CRLV. Nao usa camera fisica, banco, storage
// ou API: simula apenas o frame e a leitura local no navegador headless.
//   node tests/manual/vio_captura_layout.js
const http = require('http');
const fs = require('fs');
const path = require('path');
const os = require('os');

let puppeteer;
try { puppeteer = require('puppeteer'); } catch (e) {
    puppeteer = require(path.join(process.env.APPDATA || os.homedir(), 'npm', 'node_modules', 'puppeteer'));
}
const RAIZ = path.join(__dirname, '..', '..');
const APPJS = path.join(RAIZ, 'public', 'totem', 'assets', 'app.js');
const APPCSS = path.join(RAIZ, 'public', 'totem', 'assets', 'app.css');
const CHROME = process.env.CHROME || (fs.existsSync('C:/Program Files/Google/Chrome/Application/chrome.exe') ? 'C:/Program Files/Google/Chrome/Application/chrome.exe' : undefined);
const INDEX = `<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/app.css"></head><body data-totem-token="TESTE" data-totem-nome="qa"><div id="app"></div><script type="application/json" id="lgpd-termo-dados">{"versao":"t","hash":"t","texto":"t"}</script><script type="application/json" id="assets-versoes">{}</script><script src="/assets/app.js"></script></body></html>`;

let porta = 0, passou = 0, falhou = 0, externos = 0;
function ok(cond, texto) { if (cond) passou++; else { falhou++; console.log('FALHA: ' + texto); } }
const servidor = http.createServer((req, res) => {
    if (req.url === '/') { res.setHeader('Content-Type', 'text/html; charset=utf-8'); return res.end(INDEX); }
    if (req.url === '/assets/app.js') { res.setHeader('Content-Type', 'text/javascript'); return res.end(fs.readFileSync(APPJS)); }
    if (req.url === '/assets/app.css') { res.setHeader('Content-Type', 'text/css'); return res.end(fs.readFileSync(APPCSS)); }
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
        resultados.expCnh = { caixa: caixa('expCamCaixa'), guia: !!document.querySelector('#expCamCaixa .guia-scanner'), botao: document.getElementById('expCamBtnCapturar')?.textContent };
        limparProporcaoCameraDocumento('exp');
        app.innerHTML = telaExpCrlvQr();
        resultados.expCrlv = { guia: !!document.querySelector('#expCamCaixa .guia-scanner'), botao: document.getElementById('expCamBtnCapturar')?.textContent };
        app.innerHTML = telaRecCnhQr();
        resultados.recCnh = { guia: !!document.querySelector('#recCamCaixa .guia-scanner'), botao: document.getElementById('recCamBtnCapturar')?.textContent };
        app.innerHTML = telaRecCrlvQr();
        prepararVideo('recCamVideo');
        configurarProporcaoCameraDocumento('rec', document.getElementById('recCamVideo'), 'recCamCaixa', 'recCamPreview');
        resultados.recCrlv = { caixa: caixa('recCamCaixa'), guia: !!document.querySelector('#recCamCaixa .guia-scanner'), botao: document.getElementById('recCamBtnCapturar')?.textContent };
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
        Object.assign(window, original);
        return resultados;
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
            ok(erros.length === 0, `${viewport.width}: sem pageerror`);
            await page.close();
        }
    } finally {
        await browser.close();
        await new Promise(resolve => servidor.close(resolve));
    }
    ok(externos === 0, 'zero requisicoes externas');
    console.log(`vio_captura_layout: ${passou} verificacoes, ${falhou} falhas`);
    process.exitCode = falhou ? 1 : 0;
}
main().catch(erro => { console.error(erro.stack || erro); process.exitCode = 1; });
