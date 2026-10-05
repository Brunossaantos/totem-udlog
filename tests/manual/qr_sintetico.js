// Gerador minimo de QR (modo byte, correcao M, versoes 1-20) APENAS para
// imagens sinteticas do harness de calibracao. Conteudo sempre aleatorio
// (seed fixa); nunca usa dado real. Validado pelo proprio harness (jsQR deve
// ler a matriz limpa).
'use strict';

const EC_M = {
    1: [10, [[1, 16]]], 2: [16, [[1, 28]]], 3: [26, [[1, 44]]], 4: [18, [[2, 32]]], 5: [24, [[2, 43]]],
    6: [16, [[4, 27]]], 7: [18, [[4, 31]]], 8: [22, [[2, 38], [2, 39]]], 9: [22, [[3, 36], [2, 37]]],
    10: [26, [[4, 43], [1, 44]]], 11: [30, [[1, 50], [4, 51]]], 12: [22, [[6, 36], [2, 37]]],
    13: [22, [[8, 37], [1, 38]]], 14: [24, [[4, 40], [5, 41]]], 15: [24, [[5, 41], [5, 42]]],
    16: [28, [[7, 45], [3, 46]]], 17: [28, [[10, 46], [1, 47]]], 18: [26, [[9, 43], [4, 44]]],
    19: [26, [[3, 44], [11, 45]]], 20: [26, [[3, 41], [13, 42]]],
};
const ALINHAMENTO = {
    1: [], 2: [6, 18], 3: [6, 22], 4: [6, 26], 5: [6, 30], 6: [6, 34], 7: [6, 22, 38], 8: [6, 24, 42],
    9: [6, 26, 46], 10: [6, 28, 50], 11: [6, 30, 54], 12: [6, 32, 58], 13: [6, 34, 62], 14: [6, 26, 46, 66],
    15: [6, 26, 48, 70], 16: [6, 26, 50, 74], 17: [6, 30, 54, 78], 18: [6, 30, 56, 82], 19: [6, 30, 58, 86],
    20: [6, 34, 62, 90],
};

const EXP = new Uint8Array(512), LOG = new Uint8Array(256);
(function () { let x = 1; for (let i = 0; i < 255; i++) { EXP[i] = x; LOG[x] = i; x <<= 1; if (x & 256) x ^= 0x11D; } for (let i = 255; i < 512; i++) EXP[i] = EXP[i - 255]; }());
const mul = (a, b) => (a && b ? EXP[LOG[a] + LOG[b]] : 0);

function geradorRs(grau) {
    let poly = [1];
    for (let i = 0; i < grau; i++) {
        const prox = new Array(poly.length + 1).fill(0);
        for (let j = 0; j < poly.length; j++) { prox[j] ^= poly[j]; prox[j + 1] ^= mul(poly[j], EXP[i]); }
        poly = prox;
    }
    return poly;
}
function ecRs(dados, grau) {
    const g = geradorRs(grau);
    const resto = new Array(grau).fill(0);
    for (const b of dados) {
        const f = b ^ resto.shift();
        resto.push(0);
        for (let i = 0; i < grau; i++) resto[i] ^= mul(g[i + 1], f);
    }
    return resto;
}

function capacidadeBytes(versao) {
    const [, grupos] = EC_M[versao];
    const dados = grupos.reduce((s, [n, c]) => s + n * c, 0);
    const cc = versao >= 10 ? 16 : 8;
    return Math.floor((dados * 8 - 4 - cc) / 8);
}

function gerarMatriz(bytes, versao, mascara) {
    const [ecPorBloco, grupos] = EC_M[versao];
    const dadosTotal = grupos.reduce((s, [n, c]) => s + n * c, 0);
    const cc = versao >= 10 ? 16 : 8;
    if (bytes.length > capacidadeBytes(versao)) throw new Error('payload grande demais');
    const bits = [];
    const push = (v, n) => { for (let i = n - 1; i >= 0; i--) bits.push((v >>> i) & 1); };
    push(4, 4); push(bytes.length, cc);
    for (const b of bytes) push(b, 8);
    push(0, Math.min(4, dadosTotal * 8 - bits.length));
    while (bits.length % 8) bits.push(0);
    const cw = [];
    for (let i = 0; i < bits.length; i += 8) { let v = 0; for (let j = 0; j < 8; j++) v = (v << 1) | bits[i + j]; cw.push(v); }
    for (let pad = 0xEC; cw.length < dadosTotal; pad ^= 0xEC ^ 0x11) cw.push(pad);

    const blocos = [];
    let pos = 0;
    for (const [n, c] of grupos) for (let i = 0; i < n; i++) { blocos.push(cw.slice(pos, pos + c)); pos += c; }
    const ecs = blocos.map(b => ecRs(b, ecPorBloco));
    const final = [];
    const maxDados = Math.max(...blocos.map(b => b.length));
    for (let i = 0; i < maxDados; i++) for (const b of blocos) if (i < b.length) final.push(b[i]);
    for (let i = 0; i < ecPorBloco; i++) for (const e of ecs) final.push(e[i]);

    const size = versao * 4 + 17;
    const mod = Array.from({ length: size }, () => new Array(size).fill(false));
    const func = Array.from({ length: size }, () => new Array(size).fill(false));
    const set = (x, y, v) => { if (x >= 0 && y >= 0 && x < size && y < size) { mod[y][x] = v; func[y][x] = true; } };
    for (let i = 0; i < size; i++) { set(6, i, i % 2 === 0); set(i, 6, i % 2 === 0); }
    for (const [cx, cy] of [[3, 3], [size - 4, 3], [3, size - 4]]) {
        for (let dy = -4; dy <= 4; dy++) for (let dx = -4; dx <= 4; dx++) {
            const d = Math.max(Math.abs(dx), Math.abs(dy));
            set(cx + dx, cy + dy, d !== 2 && d !== 4);
        }
    }
    const al = ALINHAMENTO[versao];
    for (let i = 0; i < al.length; i++) for (let j = 0; j < al.length; j++) {
        if ((i === 0 && j === 0) || (i === 0 && j === al.length - 1) || (i === al.length - 1 && j === 0)) continue;
        for (let dy = -2; dy <= 2; dy++) for (let dx = -2; dx <= 2; dx++) set(al[i] + dx, al[j] + dy, Math.max(Math.abs(dx), Math.abs(dy)) !== 1);
    }
    const bit = (v, i) => ((v >>> i) & 1) !== 0;
    const formato = (mascara) => {
        const data = (0 << 3) | mascara; // M = 0
        let rem = data;
        for (let i = 0; i < 10; i++) rem = (rem << 1) ^ ((rem >>> 9) * 0x537);
        const b = ((data << 10) | rem) ^ 0x5412;
        for (let i = 0; i <= 5; i++) set(8, i, bit(b, i));
        set(8, 7, bit(b, 6)); set(8, 8, bit(b, 7)); set(7, 8, bit(b, 8));
        for (let i = 9; i < 15; i++) set(14 - i, 8, bit(b, i));
        for (let i = 0; i < 8; i++) set(size - 1 - i, 8, bit(b, i));
        for (let i = 8; i < 15; i++) set(8, size - 15 + i, bit(b, i));
        set(8, size - 8, true);
    };
    formato(0);
    if (versao >= 7) {
        let rem = versao;
        for (let i = 0; i < 12; i++) rem = (rem << 1) ^ ((rem >>> 11) * 0x1F25);
        const b = (versao << 12) | rem;
        for (let i = 0; i < 18; i++) {
            const a = size - 11 + (i % 3), c = Math.floor(i / 3);
            set(a, c, bit(b, i)); set(c, a, bit(b, i));
        }
    }
    let i = 0;
    for (let direita = size - 1; direita >= 1; direita -= 2) {
        if (direita === 6) direita = 5;
        for (let v = 0; v < size; v++) for (let j = 0; j < 2; j++) {
            const x = direita - j, cima = ((direita + 1) & 2) === 0, y = cima ? size - 1 - v : v;
            if (!func[y][x] && i < final.length * 8) { mod[y][x] = bit(final[i >>> 3], 7 - (i & 7)); i++; }
        }
    }
    const mascaras = [
        (x, y) => (x + y) % 2 === 0, (x, y) => y % 2 === 0, (x, y) => x % 3 === 0, (x, y) => (x + y) % 3 === 0,
        (x, y) => (Math.floor(x / 3) + Math.floor(y / 2)) % 2 === 0, (x, y) => ((x * y) % 2) + ((x * y) % 3) === 0,
        (x, y) => (((x * y) % 2) + ((x * y) % 3)) % 2 === 0, (x, y) => (((x + y) % 2) + ((x * y) % 3)) % 2 === 0,
    ];
    for (let y = 0; y < size; y++) for (let x = 0; x < size; x++) if (!func[y][x] && mascaras[mascara](x, y)) mod[y][x] = !mod[y][x];
    formato(mascara);
    return mod;
}

function prng(semente) {
    let s = semente >>> 0;
    return () => { s = (s + 0x6D2B79F5) >>> 0; let t = s; t = Math.imul(t ^ (t >>> 15), t | 1); t ^= t + Math.imul(t ^ (t >>> 7), t | 61); return ((t ^ (t >>> 14)) >>> 0) / 4294967296; };
}

// QR de versao fixa, preenchido ate a capacidade (denso) com bytes aleatorios
// ASCII imprimiveis (conteudo sintetico).
function qrSintetico(versao, semente) {
    const r = prng(semente);
    const n = capacidadeBytes(versao);
    const bytes = [];
    for (let i = 0; i < n; i++) bytes.push(48 + Math.floor(r() * 43));
    return gerarMatriz(bytes, versao, semente % 8);
}

module.exports = { qrSintetico, gerarMatriz, capacidadeBytes, prng };
