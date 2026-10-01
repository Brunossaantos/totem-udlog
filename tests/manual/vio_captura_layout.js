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

async function exercitarModalReprovacao(page) {
    return page.evaluate(async () => {
        const r = {};
        const orig = { ir: window.ir };
        const destinos = [];
        window.ir = tela => { destinos.push(tela); };
        iniciarApp(); // restaura a estrutura (modais) apagada pelos testes anteriores
        state.idAtendimento = 99; state.tipo = 'expedicao'; state.tela = 'exp_aguarde_documentos'; state.placa = 'ABC1D23';
        state.exp = estadoExpVazio();
        const motivos = ['placa_divergente', 'rntrc_ausente', 'cnh_vencida', 'documento_ilegivel', 'dados_invalidos'];
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
        state.rec.motivos.crlv = 'rntrc_ausente'; fecharModal();
        r.recAbre = abrirModalReprovacaoDocumento('rec', 'crlv') && modalReprovacaoAberto();
        destinos.length = 0; reescanearDocumento('rec', 'crlv'); r.recDestino = destinos[0];
        // tela fora do fluxo (ex.: cancelou) nao abre modal
        state.tela = 'lgpd'; fecharModal(); state.rec.motivos.crlv = 'rntrc_ausente';
        r.foraDoFluxo = abrirModalReprovacaoDocumento('rec', 'crlv') === false;
        // tela de espera com indicador e texto de progresso
        document.getElementById('app').innerHTML = telaExpAguardeDocumentos();
        r.spinner = !!document.querySelector('.impr-spinner');
        r.rotulo = rotuloStatusProcessamento('PROCESSANDO_LEITURA');
        window.ir = orig.ir;
        return r;
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
            const m = await exercitarModalReprovacao(page);
            const esperado = {
                placa_divergente: 'A placa do documento é diferente da placa informada no atendimento.',
                rntrc_ausente: 'O documento não possui RNTRC. Escaneie outro documento ou preencha manualmente.',
                cnh_vencida: 'A CNH está vencida.',
                documento_ilegivel: 'Não foi possível ler os dados do documento.',
                dados_invalidos: 'Os dados lidos do documento estão incompletos ou inválidos.',
            };
            ok(m.mensagens.every(x => x.registrou && x.texto === esperado[x.m]), `${viewport.width}: modal mostra a mensagem exata de cada motivo`);
            ok(m.mensagens.every(x => x.botoes.map(b => b.t).join('|') === 'Escanear novamente|Preencher manualmente|Cancelar atendimento' && x.botoes.every(b => b.h >= 64)), `${viewport.width}: botoes do modal presentes e com alvo >= 64px`);
            ok(m.mensagens.every(x => x.dentro), `${viewport.width}: modal sem overflow no viewport`);
            ok(m.nuloSemModal, `${viewport.width}: motivo null/fora da allowlist nao abre modal (mantem manual)`);
            ok(m.modais === 1, `${viewport.width}: sem modal duplicado`);
            ok(m.reescanear.destino === 'exp_crlv_qr' && m.reescanear.tent === 1 && m.reescanear.motivo === null && m.reescanear.flag === true, `${viewport.width}: Escanear novamente volta a tela QR do mesmo documento e conta tentativa`);
            ok(m.terceira.join('|') === 'Preencher manualmente|Cancelar atendimento' && m.aoTres === 'exp_cnh_manual', `${viewport.width}: na terceira tentativa vai ao manual`);
            ok(m.manual.destino === 'exp_cnh_manual' && m.manual.motivo === null, `${viewport.width}: Preencher manualmente vai a tela manual`);
            ok(m.recAbre && m.recDestino === 'rec_crlv_qr', `${viewport.width}: Recebimento abre modal e re-escaneia`);
            ok(m.foraDoFluxo, `${viewport.width}: nao abre modal fora do fluxo`);
            ok(m.spinner && m.rotulo === 'Lendo documento... trazendo os dados.', `${viewport.width}: progresso com indicador e texto claro`);
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
    ok(['Escanear novamente', 'Preencher manualmente', 'A CNH está vencida.', 'Não foi possível ler os dados do documento.', 'O documento não possui RNTRC.'].every(t => fonte.includes(t)), 'strings do modal presentes em UTF-8');
    ok(!/Ã[\u0080-¿]|â€|�/.test(fonte), 'app.js sem mojibake');
    ok(!/binaryData|FormData|cnh_frente|cnh_verso|\bfrente\b|\bverso\b/.test(semComentarios), 'sem legado (binaryData, upload, frente/verso)');
    ok(externos === 0, 'zero requisicoes externas');
    console.log(`vio_captura_layout: ${passou} verificacoes, ${falhou} falhas`);
    process.exitCode = falhou ? 1 : 0;
}
main().catch(erro => { console.error(erro.stack || erro); process.exitCode = 1; });
