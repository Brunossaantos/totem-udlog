// Estrategia de leitura local de QR (UX gate) em multiplos passos. Roda dentro
// de qr-worker.js (importScripts) e tambem e carregada pelo harness
// tests/manual/qr_leitura_calibracao.js. Funcao pura: recebe pixels RGBA do
// frame, devolve somente {ok, passo}. Nunca devolve, loga ou persiste o valor
// do QR; o backend continua recebendo apenas o JPEG do frame completo.
// A validacao da entrada do postMessage (guia em fracoes 0..1, orcamentoMs
// 50..2000, dimensoes limitadas) fica em qr-worker.js, antes de lerQuadro.
(function (raiz) {
    'use strict';

    // Quadrado de foco (guia) em fracoes do quadro de video — espelha o CSS
    // `.guia-qr` (app.css): lado = 36% da ALTURA, centralizado.
    const GUIA_PADRAO = { cx: 0.5, cy: 0.5, lado: 0.36 };
    // O recorte de leitura e a guia com folga (o QR pode estar um pouco fora).
    const MARGEM_RECORTE = 1.6;

    // Ordem escolhida pela calibracao (tests/manual/qr_leitura_calibracao.js):
    // passos baratos da guia primeiro, quadro inteiro (fallback) em 4o, e os
    // demais so se sobrar orcamento (QR invertido, escalas extras).
    // escala = fator sobre o recorte nativo; ladoMax limita o maior lado da
    // imagem entregue ao jsQR (evita travar com recortes/escalas enormes).
    const PASSOS_PADRAO = [
        { regiao: 'guia', escala: 0.5, ladoMax: 1600, pre: 'cinza', inv: 'dontInvert' },
        { regiao: 'guia', escala: 1.0, ladoMax: 1600, pre: 'otsu', inv: 'dontInvert' },
        { regiao: 'guia', escala: 0.75, ladoMax: 1600, pre: 'cinza', inv: 'dontInvert' },
        { regiao: 'quadro', escala: 0.5, ladoMax: 1800, pre: 'cinza', inv: 'dontInvert' },
        { regiao: 'guia', escala: 0.75, ladoMax: 1600, pre: 'otsu', inv: 'dontInvert' },
        { regiao: 'guia', escala: 0.5, ladoMax: 1600, pre: 'cinza', inv: 'attemptBoth' },
        { regiao: 'quadro', escala: 0.35, ladoMax: 1800, pre: 'cinza', inv: 'dontInvert' },
    ];

    function agora() {
        return (typeof performance !== 'undefined' && performance.now) ? performance.now() : Date.now();
    }

    // ---------- conversao e recorte ----------

    function recorteCinza(rgba, w, x0, y0, cw, ch) {
        const g = new Uint8ClampedArray(cw * ch);
        for (let y = 0; y < ch; y++) {
            let s = ((y0 + y) * w + x0) * 4;
            let d = y * cw;
            for (let x = 0; x < cw; x++, s += 4) {
                g[d++] = (rgba[s] * 77 + rgba[s + 1] * 150 + rgba[s + 2] * 29) >> 8;
            }
        }
        return g;
    }

    function reduzir(src, w, h, nw, nh) {
        const out = new Uint8ClampedArray(nw * nh);
        const fx = w / nw, fy = h / nh;
        for (let y = 0; y < nh; y++) {
            const y0 = Math.floor(y * fy);
            const y1 = Math.max(y0 + 1, Math.min(h, Math.floor((y + 1) * fy)));
            for (let x = 0; x < nw; x++) {
                const x0 = Math.floor(x * fx);
                const x1 = Math.max(x0 + 1, Math.min(w, Math.floor((x + 1) * fx)));
                let soma = 0;
                for (let yy = y0; yy < y1; yy++) {
                    const base = yy * w;
                    for (let xx = x0; xx < x1; xx++) soma += src[base + xx];
                }
                out[y * nw + x] = soma / ((y1 - y0) * (x1 - x0));
            }
        }
        return out;
    }

    function ampliar(src, w, h, nw, nh) {
        const out = new Uint8ClampedArray(nw * nh);
        const fx = (w - 1) / Math.max(1, nw - 1), fy = (h - 1) / Math.max(1, nh - 1);
        for (let y = 0; y < nh; y++) {
            const sy = y * fy, y0 = Math.floor(sy), y1 = Math.min(h - 1, y0 + 1), wy = sy - y0;
            for (let x = 0; x < nw; x++) {
                const sx = x * fx, x0 = Math.floor(sx), x1 = Math.min(w - 1, x0 + 1), wx = sx - x0;
                const a = src[y0 * w + x0] * (1 - wx) + src[y0 * w + x1] * wx;
                const b = src[y1 * w + x0] * (1 - wx) + src[y1 * w + x1] * wx;
                out[y * nw + x] = a * (1 - wy) + b * wy;
            }
        }
        return out;
    }

    // ---------- pre-processamento (tons de cinza in-place/novo) ----------

    function normalizar(g) {
        const hist = new Uint32Array(256);
        for (let i = 0; i < g.length; i++) hist[g[i]]++;
        const alvoBaixo = g.length * 0.01, alvoAlto = g.length * 0.99;
        let acc = 0, baixo = 0, alto = 255;
        for (let v = 0; v < 256; v++) { acc += hist[v]; if (acc >= alvoBaixo) { baixo = v; break; } }
        acc = 0;
        for (let v = 0; v < 256; v++) { acc += hist[v]; if (acc >= alvoAlto) { alto = v; break; } }
        if (alto - baixo < 24) return g;
        const lut = new Uint8ClampedArray(256);
        for (let v = 0; v < 256; v++) lut[v] = ((v - baixo) * 255) / (alto - baixo);
        const out = new Uint8ClampedArray(g.length);
        for (let i = 0; i < g.length; i++) out[i] = lut[g[i]];
        return out;
    }

    function nitidez(g, w, h, quantidade) {
        const q = quantidade === undefined ? 1.5 : quantidade;
        const tmp = new Uint16Array(w * h);
        for (let y = 0; y < h; y++) {
            const b = y * w;
            for (let x = 0; x < w; x++) {
                const xa = x > 0 ? x - 1 : x, xb = x < w - 1 ? x + 1 : x;
                tmp[b + x] = g[b + xa] + g[b + x] + g[b + xb];
            }
        }
        const out = new Uint8ClampedArray(w * h);
        for (let y = 0; y < h; y++) {
            const ya = (y > 0 ? y - 1 : y) * w, yb = (y < h - 1 ? y + 1 : y) * w, b = y * w;
            for (let x = 0; x < w; x++) {
                const borrao = (tmp[ya + x] + tmp[b + x] + tmp[yb + x]) / 9;
                out[b + x] = g[b + x] + q * (g[b + x] - borrao);
            }
        }
        return out;
    }

    function limiarOtsu(g) {
        const hist = new Uint32Array(256);
        for (let i = 0; i < g.length; i++) hist[g[i]]++;
        let total = g.length, somaTotal = 0;
        for (let v = 0; v < 256; v++) somaTotal += v * hist[v];
        let somaB = 0, pesoB = 0, melhor = 0, limiar = 127;
        for (let v = 0; v < 256; v++) {
            pesoB += hist[v];
            if (!pesoB) continue;
            const pesoF = total - pesoB;
            if (!pesoF) break;
            somaB += v * hist[v];
            const mB = somaB / pesoB, mF = (somaTotal - somaB) / pesoF;
            const variancia = pesoB * pesoF * (mB - mF) * (mB - mF);
            if (variancia > melhor) { melhor = variancia; limiar = v; }
        }
        return limiar;
    }

    function binarizarOtsu(g) {
        const t = limiarOtsu(g);
        const out = new Uint8ClampedArray(g.length);
        for (let i = 0; i < g.length; i++) out[i] = g[i] > t ? 255 : 0;
        return out;
    }

    // Limiar adaptativo (media local via imagem integral, estilo Bradley).
    function binarizarAdaptativo(g, w, h) {
        const integral = new Float64Array((w + 1) * (h + 1));
        for (let y = 0; y < h; y++) {
            let linha = 0;
            for (let x = 0; x < w; x++) {
                linha += g[y * w + x];
                integral[(y + 1) * (w + 1) + x + 1] = integral[y * (w + 1) + x + 1] + linha;
            }
        }
        const meia = Math.max(8, Math.round(Math.max(w, h) / 24));
        const out = new Uint8ClampedArray(w * h);
        for (let y = 0; y < h; y++) {
            const y0 = Math.max(0, y - meia), y1 = Math.min(h, y + meia + 1);
            for (let x = 0; x < w; x++) {
                const x0 = Math.max(0, x - meia), x1 = Math.min(w, x + meia + 1);
                const soma = integral[y1 * (w + 1) + x1] - integral[y0 * (w + 1) + x1]
                    - integral[y1 * (w + 1) + x0] + integral[y0 * (w + 1) + x0];
                const media = soma / ((x1 - x0) * (y1 - y0));
                out[y * w + x] = g[y * w + x] < media * 0.92 ? 0 : 255;
            }
        }
        return out;
    }

    function aplicarPre(g, w, h, pre) {
        switch (pre) {
            case 'norm': return normalizar(g);
            case 'nitido': return nitidez(g, w, h);
            case 'norm+nitido': return nitidez(normalizar(g), w, h);
            case 'otsu': return binarizarOtsu(normalizar(g));
            case 'adapt': return binarizarAdaptativo(g, w, h);
            default: return g;
        }
    }

    function paraRgba(g) {
        const rgba = new Uint8ClampedArray(g.length * 4);
        for (let i = 0, j = 0; i < g.length; i++, j += 4) {
            const v = g[i];
            rgba[j] = v; rgba[j + 1] = v; rgba[j + 2] = v; rgba[j + 3] = 255;
        }
        return rgba;
    }

    // ---------- geometria ----------

    function retanguloRegiao(regiao, w, h, guia) {
        if (regiao === 'quadro') return { x0: 0, y0: 0, cw: w, ch: h };
        const lado = Math.round(guia.lado * h * MARGEM_RECORTE);
        const cx = guia.cx * w, cy = guia.cy * h;
        const x0 = Math.max(0, Math.round(cx - lado / 2));
        const y0 = Math.max(0, Math.round(cy - lado / 2));
        return {
            x0, y0,
            cw: Math.max(1, Math.min(w - x0, lado)),
            ch: Math.max(1, Math.min(h - y0, lado)),
        };
    }

    // Um passo: recorte -> cinza -> escala -> pre -> jsQR. `cache` guarda
    // recortes/escalas ja calculados entre passos do MESMO frame.
    function executarPasso(jsQR, rgba, w, h, guia, passo, cache) {
        const memo = cache || {};
        const r = retanguloRegiao(passo.regiao, w, h, guia);
        const chaveRegiao = passo.regiao;
        if (!memo[chaveRegiao]) memo[chaveRegiao] = recorteCinza(rgba, w, r.x0, r.y0, r.cw, r.ch);
        const maior = Math.max(r.cw, r.ch);
        const efetiva = Math.min(passo.escala, (passo.ladoMax || 1600) / maior);
        const nw = Math.max(32, Math.round(r.cw * efetiva));
        const nh = Math.max(32, Math.round(r.ch * efetiva));
        const chaveEscala = chaveRegiao + '@' + nw + 'x' + nh;
        if (!memo[chaveEscala]) {
            if (nw === r.cw && nh === r.ch) memo[chaveEscala] = memo[chaveRegiao];
            else if (nw < r.cw) memo[chaveEscala] = reduzir(memo[chaveRegiao], r.cw, r.ch, nw, nh);
            else memo[chaveEscala] = ampliar(memo[chaveRegiao], r.cw, r.ch, nw, nh);
        }
        const g = aplicarPre(memo[chaveEscala], nw, nh, passo.pre || 'cinza');
        const resultado = jsQR(paraRgba(g), nw, nh, { inversionAttempts: passo.inv || 'dontInvert' });
        return !!(resultado && resultado.binaryData && resultado.binaryData.length > 0);
    }

    // Executa os passos em ordem ate o primeiro sucesso ou ate estourar o
    // orcamento de tempo (checado ENTRE passos; um passo nunca e interrompido).
    function lerQuadro(jsQR, rgba, w, h, opcoes) {
        const o = opcoes || {};
        const guia = o.guia || GUIA_PADRAO;
        const passos = o.passos || PASSOS_PADRAO;
        const orcamento = o.orcamentoMs || 700;
        const inicio = agora();
        const cache = {};
        for (let i = 0; i < passos.length; i++) {
            if (i > 0 && agora() - inicio > orcamento) break;
            if (executarPasso(jsQR, rgba, w, h, guia, passos[i], cache)) return { ok: true, passo: i };
        }
        return { ok: false, passo: -1 };
    }

    raiz.QrLeitura = {
        GUIA_PADRAO, MARGEM_RECORTE, PASSOS_PADRAO,
        lerQuadro, executarPasso, retanguloRegiao,
        _interno: { recorteCinza, reduzir, ampliar, normalizar, nitidez, binarizarOtsu, binarizarAdaptativo, paraRgba },
    };
}(typeof self !== 'undefined' ? self : this));
