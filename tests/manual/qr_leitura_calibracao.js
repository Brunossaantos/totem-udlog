// Calibracao da leitura local de QR (UX gate) em Chromium headless.
//   node tests/manual/qr_leitura_calibracao.js [--sem-exemplos] [--so-final]
// Mede taxa de leitura e tempo por passo/estrategia sobre (a) imagens
// sinteticas (QR aleatorio gerado em tests/manual/qr_sintetico.js, com
// rotacao, baixa luz, desfoque, JPEG, reflexo) e (b) as imagens de exemplos/
// (LOCAL, podem conter dado pessoal: so sucesso/falha e tempo sao impressos;
// o conteudo decodificado NUNCA e exibido, logado ou salvo). Sem rede externa,
// sem backend. Quadro de 3264x2448 (resolucao real do Netum SD-2000).
const http = require('http');
const fs = require('fs');
const path = require('path');
const os = require('os');
const { qrSintetico, capacidadeBytes, prng } = require('./qr_sintetico.js');

let puppeteer;
try { puppeteer = require('puppeteer'); } catch (e) {
    puppeteer = require(path.join(process.env.APPDATA || os.homedir(), 'npm', 'node_modules', 'puppeteer'));
}
const RAIZ = path.join(__dirname, '..', '..');
const ASSETS = path.join(RAIZ, 'public', 'totem', 'assets');
const EXEMPLOS = path.join(RAIZ, 'exemplos');
const CHROME = process.env.CHROME || (fs.existsSync('C:/Program Files/Google/Chrome/Application/chrome.exe') ? 'C:/Program Files/Google/Chrome/Application/chrome.exe' : undefined);
const SEM_EXEMPLOS = process.argv.includes('--sem-exemplos');
const SO_FINAL = process.argv.includes('--so-final');
const W = 3264, H = 2448;
// area de video (sem a moldura preta do print) de cada exemplo, em px do PNG
const EXEMPLOS_AREA = {
    'cnhqrcode.png': { x: 6, y: 4, w: 598, h: 448 },
    'clvqrcode.png': { x: 8, y: 8, w: 598, h: 448 },
};

const PAGINA = `<!doctype html><meta charset="utf-8"><body><script src="/jsQR.js"></script><script src="/qr-leitura.js"></script></body>`;
const servidor = http.createServer((req, res) => {
    const u = req.url.split('?')[0];
    const envia = (arq, tipo) => { res.setHeader('Content-Type', tipo); res.end(fs.readFileSync(arq)); };
    if (u === '/') { res.setHeader('Content-Type', 'text/html; charset=utf-8'); return res.end(PAGINA); }
    if (u === '/jsQR.js') return envia(path.join(ASSETS, 'vendor', 'jsqr', 'jsQR.js'), 'text/javascript');
    if (u === '/qr-leitura.js') return envia(path.join(ASSETS, 'qr-leitura.js'), 'text/javascript');
    if (u === '/assets/vendor/jsqr/jsQR.js') return envia(path.join(ASSETS, 'vendor', 'jsqr', 'jsQR.js'), 'text/javascript');
    if (u === '/assets/qr-leitura.js') return envia(path.join(ASSETS, 'qr-leitura.js'), 'text/javascript');
    if (u === '/assets/qr-worker.js') return envia(path.join(ASSETS, 'qr-worker.js'), 'text/javascript');
    if (u.startsWith('/ex/') && !SEM_EXEMPLOS && EXEMPLOS_AREA[path.basename(u)]) return envia(path.join(EXEMPLOS, path.basename(u)), 'image/png');
    res.statusCode = 404; res.end('x');
});

// ---------- cenarios ----------
function cenarios() {
    const lista = [];
    const grande = 0.33, pequeno = 0.20; // lado do QR (sem quiet zone) em fracao da altura
    const conds = [
        { nome: 'limpo', lado: grande },
        { nome: 'pequeno (CRLV)', lado: pequeno },
        { nome: 'rot15', lado: grande, rot: 15 },
        { nome: 'rot45', lado: grande, rot: 45 },
        { nome: 'rot90', lado: grande, rot: 90 },
        { nome: 'baixa luz', lado: grande, brilho: 0.28, ruido: 7 },
        { nome: 'desfoque leve', lado: grande, blur: 3.5 },
        { nome: 'jpeg q0.2', lado: grande, jpeg: 0.2 },
        { nome: 'reflexo', lado: grande, reflexo: true },
        { nome: 'sombra', lado: grande, sombra: true },
        { nome: 'perspectiva', lado: grande, skew: [0.1, 0.06] },
        { nome: 'combinado', lado: grande, rot: 20, blur: 3, brilho: 0.45, ruido: 5, jpeg: 0.4 },
    ];
    let semente = 100;
    for (const versao of [7, 18]) {
        for (const pos of [{ dx: 0, dy: 0 }, { dx: 0.07, dy: -0.05 }]) {
            for (const c of conds) {
                semente++;
                lista.push({ tipo: 'sintetico', versao, pos, semente, ...c, matriz: qrSintetico(versao, semente) });
            }
        }
    }
    return lista;
}

function rotulo(c) {
    if (c.tipo === 'exemplo') return `exemplo ${c.arquivo.startsWith('cnh') ? 'CNH' : 'CRLV'} ${c.ampliado ? '(ampliado 3264x2448)' : '(nativo 598x448)'}`;
    return `v${c.versao} ${c.nome}${c.pos.dx ? ' deslocado' : ''}`;
}

// ---------- codigo executado no navegador ----------
const NAVEGADOR = () => {
    window.__prng = (s) => () => { s = (s + 0x6D2B79F5) >>> 0; let t = s; t = Math.imul(t ^ (t >>> 15), t | 1); t ^= t + Math.imul(t ^ (t >>> 7), t | 61); return ((t ^ (t >>> 14)) >>> 0) / 4294967296; };
    window.__montar = async (c) => {
        const canvas = document.createElement('canvas');
        canvas.width = 3264; canvas.height = 2448;
        const ctx = canvas.getContext('2d', { willReadFrequently: true });
        const W = 3264, H = 2448;
        if (c.tipo === 'exemplo') {
            const img = new Image();
            await new Promise((ok, err) => { img.onload = ok; img.onerror = err; img.src = '/ex/' + c.arquivo; });
            const a = c.area;
            if (c.ampliado) {
                ctx.imageSmoothingQuality = 'high';
                ctx.drawImage(img, a.x, a.y, a.w, a.h, 0, 0, W, H);
            } else {
                canvas.width = a.w; canvas.height = a.h;
                canvas.getContext('2d', { willReadFrequently: true }).drawImage(img, a.x, a.y, a.w, a.h, 0, 0, a.w, a.h);
            }
        } else {
            const rnd = window.__prng(c.semente * 7 + 1);
            ctx.fillStyle = '#050505'; ctx.fillRect(0, 0, W, H);
            ctx.fillStyle = '#ececec';
            ctx.fillRect(W * 0.1, H * 0.1, W * 0.8, H * 0.8);
            ctx.fillStyle = '#9a9a9a';
            for (let i = 0; i < 40; i++) ctx.fillRect(W * (0.12 + rnd() * 0.5), H * (0.12 + rnd() * 0.74), W * (0.05 + rnd() * 0.2), H * 0.008);
            const n = c.matriz.length, q = 4;
            const lado = c.lado * H, mod = lado / n;
            ctx.save();
            ctx.translate(W / 2 + c.pos.dx * H, H / 2 + c.pos.dy * H);
            ctx.rotate((c.rot || 0) * Math.PI / 180);
            if (c.skew) ctx.transform(1, c.skew[1], c.skew[0], 1, 0, 0);
            ctx.fillStyle = '#f4f4f4';
            ctx.fillRect(-(n / 2 + q) * mod, -(n / 2 + q) * mod, (n + 2 * q) * mod, (n + 2 * q) * mod);
            ctx.fillStyle = '#161616';
            for (let y = 0; y < n; y++) for (let x = 0; x < n; x++) if (c.matriz[y][x]) {
                ctx.fillRect((x - n / 2) * mod - 0.4, (y - n / 2) * mod - 0.4, mod + 0.8, mod + 0.8);
            }
            ctx.restore();
            if (c.reflexo) {
                const g = ctx.createLinearGradient(W * 0.3, H * 0.3, W * 0.7, H * 0.7);
                g.addColorStop(0, 'rgba(255,255,255,0)'); g.addColorStop(0.5, 'rgba(255,255,255,0.62)'); g.addColorStop(1, 'rgba(255,255,255,0)');
                ctx.fillStyle = g; ctx.fillRect(0, 0, W, H);
            }
            if (c.sombra) {
                const g = ctx.createLinearGradient(W * 0.3, 0, W * 0.7, 0);
                g.addColorStop(0, 'rgba(0,0,0,0)'); g.addColorStop(1, 'rgba(0,0,0,0.6)');
                ctx.fillStyle = g; ctx.fillRect(0, 0, W, H);
            }
            if (c.blur || c.brilho) {
                const t = document.createElement('canvas'); t.width = W; t.height = H;
                const tc = t.getContext('2d');
                tc.filter = `${c.blur ? 'blur(' + c.blur + 'px) ' : ''}${c.brilho ? 'brightness(' + c.brilho + ') ' : ''}`.trim();
                tc.drawImage(canvas, 0, 0);
                ctx.clearRect(0, 0, W, H); ctx.drawImage(t, 0, 0);
            }
            if (c.ruido) {
                const d = ctx.getImageData(0, 0, W, H); const p = d.data;
                for (let i = 0; i < p.length; i += 4) {
                    const r = (rnd() + rnd() + rnd() - 1.5) * c.ruido * 2;
                    p[i] += r; p[i + 1] += r; p[i + 2] += r;
                }
                ctx.putImageData(d, 0, 0);
            }
            if (c.jpeg) {
                const blob = await new Promise(r => canvas.toBlob(r, 'image/jpeg', c.jpeg));
                const bmp = await createImageBitmap(blob);
                ctx.drawImage(bmp, 0, 0);
            }
        }
        const dados = canvas.getContext('2d').getImageData(0, 0, canvas.width, canvas.height);
        window.__quadro = { rgba: dados.data, w: dados.width, h: dados.height, esperado: c.tipo === 'sintetico' ? c.esperado : null };
        return { w: dados.width, h: dados.height };
    };
    // confere que o decodificado (se houver) e igual ao esperado SEM expor valores
    window.__confere = (r) => {
        if (!r || !r.binaryData || !r.binaryData.length) return false;
        const e = window.__quadro.esperado;
        if (!e) return true;
        if (e.length !== r.binaryData.length) return false;
        for (let i = 0; i < e.length; i++) if (e[i] !== r.binaryData[i]) return false;
        return true;
    };
    window.__passo = (passo) => {
        const q = window.__quadro;
        const t0 = performance.now();
        let ok = false;
        try {
            if (passo.baseline) {
                const opt = passo.baseline === 'dontInvert' ? { inversionAttempts: 'dontInvert' } : undefined;
                ok = window.__confere(opt ? jsQR(q.rgba, q.w, q.h, opt) : jsQR(q.rgba, q.w, q.h));
            } else {
                // reaproveita o caminho real; confere o conteudo no caso sintetico
                const orig = window.jsQR; let achou = null;
                window.__jsqrEspia = (a, b, c, d) => { const r = orig(a, b, c, d); if (r) achou = r; return r; };
                QrLeitura.executarPasso(window.__jsqrEspia, q.rgba, q.w, q.h, QrLeitura.GUIA_PADRAO, passo, {});
                ok = window.__confere(achou);
            }
        } catch (e) { ok = false; }
        return { ok, ms: performance.now() - t0 };
    };
    // estrategia completa (lerQuadro) para confirmacao final, conferindo conteudo
    window.__estrategia = (opcoes) => {
        const q = window.__quadro;
        const t0 = performance.now();
        let achou = null;
        const espia = (a, b, c, d) => { const r = jsQR(a, b, c, d); if (r) achou = r; return r; };
        let res = { ok: false };
        try { res = QrLeitura.lerQuadro(espia, q.rgba, q.w, q.h, opcoes); } catch (e) { /* falha */ }
        return { ok: res.ok && window.__confere(achou), passo: res.passo, ms: performance.now() - t0 };
    };
    // round-trip real pelo worker (qr-worker.js novo) vs worker antigo (jsQR no quadro inteiro)
    window.__workerNovo = null; window.__workerAntigo = null;
    window.__roundTrip = (qual, guia, orcamentoMs) => new Promise((resolve) => {
        const q = window.__quadro;
        if (!window.__workerNovo) window.__workerNovo = new Worker('/assets/qr-worker.js');
        if (!window.__workerAntigo) {
            const fonte = "importScripts(self.location.origin + '/jsQR.js');self.onmessage=function(e){const d=e.data;try{const r=jsQR(d.data,d.width,d.height);self.postMessage({ok:!!(r&&r.binaryData&&r.binaryData.length),bin:r?Array.from(r.binaryData):null});}catch(x){self.postMessage({ok:false});}};";
            window.__workerAntigo = new Worker(URL.createObjectURL(new Blob([fonte], { type: 'text/javascript' })));
        }
        const w = qual === 'novo' ? window.__workerNovo : window.__workerAntigo;
        const copia = new Uint8ClampedArray(q.rgba); // o buffer e transferido; mantem o quadro para outros
        const t0 = performance.now();
        w.onmessage = (e) => {
            const ms = performance.now() - t0;
            let ok = !!(e.data && e.data.ok);
            if (qual === 'antigo' && ok && q.esperado) ok = window.__confere({ binaryData: e.data.bin });
            resolve({ ok, ms, vazou: !!(e.data && (e.data.binaryData || e.data.bin) && qual === 'novo') });
        };
        w.postMessage({ data: copia, width: q.w, height: q.h, guia, orcamentoMs }, [copia.buffer]);
    });
};

// ---------- estatistica ----------
const media = a => a.length ? a.reduce((s, v) => s + v, 0) / a.length : 0;
const pct = (a, p) => { if (!a.length) return 0; const s = [...a].sort((x, y) => x - y); return s[Math.min(s.length - 1, Math.floor(p * s.length))]; };
const f1 = v => v.toFixed(0);

async function main() {
    await new Promise(r => servidor.listen(0, '127.0.0.1', r));
    const porta = servidor.address().port;
    const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'new', protocolTimeout: 3600000, args: ['--no-sandbox'] });
    let falhas = 0;
    try {
        const page = await browser.newPage();
        page.setDefaultTimeout(0);
        const externos = [];
        await page.setRequestInterception(true);
        page.on('request', req => {
            const u = new URL(req.url());
            if (u.protocol === 'data:' || u.protocol === 'blob:') return req.continue();
            if (u.host !== '127.0.0.1:' + porta) { externos.push(req.url()); return req.abort(); }
            return req.continue();
        });
        await page.goto('http://127.0.0.1:' + porta + '/');
        await page.evaluate(NAVEGADOR);

        const lista = cenarios();
        for (const c of lista) {
            // esperado = bytes do payload (so para conferir; nunca impresso)
            const r = prng(c.semente); const n = capacidadeBytes(c.versao); c.esperado = [];
            for (let i = 0; i < n; i++) c.esperado.push(48 + Math.floor(r() * 43));
        }
        if (!SEM_EXEMPLOS) {
            for (const arquivo of Object.keys(EXEMPLOS_AREA)) {
                if (!fs.existsSync(path.join(EXEMPLOS, arquivo))) continue;
                for (const ampliado of [false, true]) lista.push({ tipo: 'exemplo', arquivo, area: EXEMPLOS_AREA[arquivo], ampliado });
            }
        }
        const sinteticos = lista.filter(c => c.tipo === 'sintetico').length;
        console.log(`Cenarios: ${sinteticos} sinteticos + ${lista.length - sinteticos} de exemplos (quadro 3264x2448).`);

        // sanidade do gerador: QR limpo e centralizado precisa ser lido (senao o harness esta errado)
        await page.evaluate(c => window.__montar(c), lista.find(c => c.tipo === 'sintetico' && c.nome === 'limpo' && c.versao === 18));
        const sane = await page.evaluate(() => window.__passo({ baseline: 'dontInvert' }));
        if (!sane.ok) { console.log('FALHA: gerador sintetico nao e lido nem no caso limpo'); falhas++; }

        // passos atomicos
        const passos = {};
        const add = (id, p) => { passos[id] = p; };
        add('A  quadro inteiro, jsQR padrao (ANTES)', { baseline: 'padrao' });
        add('B  quadro inteiro, dontInvert', { baseline: 'dontInvert' });
        for (const esc of [1.0, 0.75, 0.5, 1.5]) for (const pre of ['cinza', 'norm', 'nitido', 'norm+nitido', 'otsu', 'adapt']) {
            add(`guia x${esc} ${pre}`, { regiao: 'guia', escala: esc, ladoMax: 1600, pre, inv: 'dontInvert' });
        }
        for (const esc of [0.5, 0.35, 0.25]) for (const pre of ['cinza', 'norm', 'adapt']) {
            add(`quadro x${esc} ${pre}`, { regiao: 'quadro', escala: esc, ladoMax: 1800, pre, inv: 'dontInvert' });
        }
        add('guia x0.5 cinza +inverte', { regiao: 'guia', escala: 0.5, ladoMax: 1600, pre: 'cinza', inv: 'attemptBoth' });
        add('quadro x0.5 cinza +inverte', { regiao: 'quadro', escala: 0.5, ladoMax: 1800, pre: 'cinza', inv: 'attemptBoth' });

        const ids = Object.keys(passos);
        const res = {}; // res[id][i] = {ok, ms}
        ids.forEach(id => { res[id] = []; });
        if (!SO_FINAL) {
            let k = 0;
            for (const c of lista) {
                k++;
                await page.evaluate(x => window.__montar(x), c);
                for (const id of ids) res[id].push(await page.evaluate(p => window.__passo(p), passos[id]));
                process.stdout.write(`\r  medindo passos: cenario ${k}/${lista.length}   `);
            }
            process.stdout.write('\n');

            if (process.env.QR_JSON_SAIDA) {
                // so sucesso/falha e tempo por cenario/passo (nenhum conteudo de QR)
                fs.writeFileSync(process.env.QR_JSON_SAIDA, JSON.stringify({ ids, passos, rotulos: lista.map(rotulo), tipos: lista.map(c => c.tipo), res }));
            }
            const N = lista.length;
            const nSint = lista.map((c, i) => i).filter(i => lista[i].tipo === 'sintetico');
            const nEx = lista.map((c, i) => i).filter(i => lista[i].tipo === 'exemplo');
            console.log('\n== Passos isolados (taxa: sinteticos / exemplos; tempo em ms: media dos cenarios | max) ==');
            for (const id of ids) {
                const r = res[id];
                const s = nSint.filter(i => r[i].ok).length, e = nEx.filter(i => r[i].ok).length;
                console.log(`${id.padEnd(40)} ${String(s).padStart(2)}/${nSint.length}  ${e}/${nEx.length}   ${f1(media(r.map(x => x.ms))).padStart(5)} | ${f1(Math.max(...r.map(x => x.ms))).padStart(5)}`);
            }

            // estrategias compostas: ordem fixa de passos; tempo = soma ate o 1o sucesso (ou todos)
            const compor = (lst) => lista.map((c, i) => {
                let t = 0, ok = false;
                for (const id of lst) { t += res[id][i].ms; if (res[id][i].ok) { ok = true; break; } }
                return { ok, ms: t };
            });
            const nome = (pre, esc, regiao, ladoMax, inv) => `${regiao} x${esc} ${pre}`;
            const E = {
                'ANTES: quadro inteiro (jsQR padrao)': ['A  quadro inteiro, jsQR padrao (ANTES)'],
                'quadro inteiro dontInvert': ['B  quadro inteiro, dontInvert'],
                'N1 guia cinza x1.0 -> quadro x0.5': ['guia x1 cinza', 'quadro x0.5 cinza'],
                'N2 guia multiescala (cinza) -> quadro': ['guia x1 cinza', 'guia x0.75 cinza', 'guia x0.5 cinza', 'guia x1.5 cinza', 'quadro x0.5 cinza', 'quadro x0.35 cinza'],
                'N3 N2 + pre-processamento': [
                    'guia x1 cinza', 'guia x0.75 cinza', 'guia x0.5 cinza', 'guia x1.5 cinza',
                    'guia x0.5 norm', 'guia x0.75 norm+nitido', 'guia x0.5 otsu', 'guia x0.5 adapt',
                    'quadro x0.5 cinza', 'quadro x0.35 norm', 'quadro x0.5 adapt'],
            };
            const norm = (s) => s.replace(/x1\b/g, 'x1.0');
            const resolve = (lst) => lst.map(id => ids.find(k => k === id || norm(k) === norm(id) || k.startsWith(id)) || (() => { throw new Error('passo desconhecido ' + id); })());
            const linhas = [];
            const rel = (titulo, lst) => {
                const o = compor(resolve(lst));
                const s = nSint.filter(i => o[i].ok), e = nEx.filter(i => o[i].ok);
                const ts = o.map(x => x.ms);
                linhas.push({ titulo, s: s.length, e: e.length, media: media(ts), p95: pct(ts, 0.95), max: Math.max(...ts) });
            };
            for (const [t, l] of Object.entries(E)) rel(t, l);

            // guloso: maximiza cenarios novos por ms (so passos nao-baseline)
            const candidatos = ids.filter(id => !passos[id].baseline);
            const cobertos = new Set(); const ordem = [];
            while (true) {
                let melhor = null, ganhoMelhor = 0;
                for (const id of candidatos) {
                    if (ordem.includes(id)) continue;
                    let ganho = 0; for (let i = 0; i < N; i++) if (!cobertos.has(i) && res[id][i].ok) ganho++;
                    if (!ganho) continue;
                    const razao = ganho / media(res[id].map(x => x.ms));
                    if (!melhor || razao > ganhoMelhor) { melhor = id; ganhoMelhor = razao; }
                }
                if (!melhor) break;
                ordem.push(melhor);
                for (let i = 0; i < N; i++) if (res[melhor][i].ok) cobertos.add(i);
                if (ordem.length >= 8) break;
            }
            console.log('\n== Ordem gulosa (ganho de cenarios por ms) ==');
            console.log(ordem.join(' -> '));
            rel('GULOSA (ate 8 passos)', ordem);
            // cenarios que nenhum passo atomico leu (nem baseline)
            const nenhum = lista.map((c, i) => i).filter(i => !ids.some(id => res[id][i].ok));
            console.log('Cenarios que NENHUM passo leu: ' + (nenhum.length ? nenhum.map(i => rotulo(lista[i])).join('; ') : '(nenhum)'));
            const soAntes = lista.map((c, i) => i).filter(i => !res['A  quadro inteiro, jsQR padrao (ANTES)'][i].ok && ids.some(id => res[id][i].ok));
            console.log(`Lidos por algum passo novo mas NAO pelo ANTES: ${soAntes.length}`);

            console.log('\n== Estrategias compostas (simulacao pela soma dos tempos dos passos) ==');
            console.log('estrategia'.padEnd(42) + 'sint'.padStart(7) + 'exemplos'.padStart(10) + 'media ms'.padStart(10) + 'p95 ms'.padStart(9) + 'max ms'.padStart(9));
            for (const l of linhas) console.log(l.titulo.padEnd(42) + `${l.s}/${nSint.length}`.padStart(7) + `${l.e}/${nEx.length}`.padStart(10) + f1(l.media).padStart(10) + f1(l.p95).padStart(9) + f1(l.max).padStart(9));
            console.log('\nPor cenario (ANTES vs melhor passo isolado que leu):');
            for (let i = 0; i < N; i++) {
                const a = res['A  quadro inteiro, jsQR padrao (ANTES)'][i];
                const lidos = ids.filter(id => !passos[id].baseline && res[id][i].ok).length;
                console.log(`  ${rotulo(lista[i]).padEnd(46)} ANTES ${a.ok ? 'ok  ' : 'FALHA'} ${f1(a.ms).padStart(5)} ms | passos novos que leram: ${lidos}/${ids.length - 2}`);
            }
        }

        // ---- confirmacao final: estrategia PADRAO e worker real, ponta a ponta ----
        console.log('\n== Confirmacao ponta a ponta (PASSOS_PADRAO real; worker real; conteudo conferido sem exibir) ==');
        const orcamento = Number(process.env.QR_ORCAMENTO_MS || 300);
        const tab = { antigo: [], novo: [] };
        const porCenario = [];
        for (const c of lista) {
            await page.evaluate(x => window.__montar(x), c);
            const a = await page.evaluate(() => window.__roundTrip('antigo', null, 0));
            const n = await page.evaluate((o) => window.__roundTrip('novo', null, o), orcamento);
            tab.antigo.push(a); tab.novo.push(n);
            porCenario.push({ r: rotulo(c), a, n });
            if (n.vazou) { console.log('FALHA: worker novo devolveu dado do QR'); falhas++; }
        }
        const idx = tipo => lista.map((c, i) => i).filter(i => lista[i].tipo === tipo);
        const linha = (nomeLinha, t) => {
            const out = [];
            for (const tipo of ['sintetico', 'exemplo']) {
                const ix = idx(tipo); if (!ix.length) { out.push('-'); continue; }
                out.push(`${ix.filter(i => t[i].ok).length}/${ix.length}`);
            }
            const ms = t.map(x => x.ms), msOk = t.filter(x => x.ok).map(x => x.ms), msFalha = t.filter(x => !x.ok).map(x => x.ms);
            console.log(`${nomeLinha.padEnd(30)} sint ${out[0].padStart(6)}  exemplos ${out[1].padStart(4)}  tempo medio ${f1(media(ms)).padStart(5)} ms  p95 ${f1(pct(ms, 0.95)).padStart(5)}  max ${f1(Math.max(...ms)).padStart(5)}  (sucesso medio ${f1(media(msOk))} ms; falha media ${f1(media(msFalha))} ms)`);
        };
        console.log(`orcamento por frame: ${orcamento} ms`);
        linha('ANTES (worker antigo)', tab.antigo);
        linha('DEPOIS (worker novo)', tab.novo);
        console.log('\nPor cenario (ok? ms): ANTES | DEPOIS');
        for (const p of porCenario) console.log(`  ${p.r.padEnd(46)} ${p.a.ok ? 'ok   ' : 'FALHA'} ${f1(p.a.ms).padStart(5)} | ${p.n.ok ? 'ok   ' : 'FALHA'} ${f1(p.n.ms).padStart(5)}`);
        if (externos.length) { console.log('FALHA: requisicao externa: ' + externos.length); falhas++; }
    } finally {
        await browser.close();
        await new Promise(r => servidor.close(r));
    }
    console.log(falhas ? `qr_leitura_calibracao: ${falhas} falha(s)` : 'qr_leitura_calibracao: concluido (sem falhas de sanidade)');
    process.exitCode = falhas ? 1 : 0;
}
main().catch(e => { console.error(e.stack || e); process.exitCode = 1; });
