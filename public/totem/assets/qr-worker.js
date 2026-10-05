// Web Worker dedicado a leitura de QR code (CNH/CRLV, Expedicao e Recebimento).
// jsQR e uma funcao SINCRONA sem Worker interno proprio (diferente do
// Tesseract.js, que gerencia seu proprio Worker) — por isso rodar jsQR aqui
// dentro NAO cria o bug de "Worker aninhado" ja documentado para o OCR (ver
// ia_development_state.md, entrada de 2026-09-04). Este worker e criado UMA
// UNICA VEZ pela thread principal (assets/app.js, obterQrWorker()) e
// reaproveitado entre capturas.
//
// A leitura e uma ESTRATEGIA em multiplos passos (assets/qr-leitura.js):
// recorte da regiao da guia -> escalas/pre-processamento -> quadro inteiro.
// A resposta e SOMENTE {ok}: o valor do QR nunca sai do worker (o QR local e
// apenas um gate de UX; o backend recebe so o JPEG do frame).
//
// Caminhos relativos ao PROPRIO worker (nao aninhado dentro de outro worker):
// public/totem/assets/vendor/jsqr/jsQR.js (SHA-256 em vendor/MANIFEST.sha256)
// e public/totem/assets/qr-leitura.js.
// O ?v= do proprio worker (versao por mtime, index.php) e repassado para
// nao servir qr-leitura.js antigo do cache.
importScripts('vendor/jsqr/jsQR.js', 'qr-leitura.js' + (self.location.search || ''));

// Limites da entrada do postMessage (defesa em profundidade; o remetente e a
// propria app, mas o worker nunca confia no formato recebido).
const MAX_LADO_QUADRO = 8192;        // px por dimensao
const MAX_PIXELS_QUADRO = 40000000;  // ~ 8K x 5K
const ORCAMENTO_MIN_MS = 50;
const ORCAMENTO_MAX_MS = 2000;

function numeroEntre(v, min, max) {
    return typeof v === 'number' && isFinite(v) && v >= min && v <= max;
}

// guia em FRACOES do quadro (cx, cy em 0..1; lado em (0..1] da altura).
function entradaValida(q) {
    if (!q || typeof q !== 'object') return false;
    if (!Number.isInteger(q.width) || !Number.isInteger(q.height)) return false;
    if (!numeroEntre(q.width, 1, MAX_LADO_QUADRO) || !numeroEntre(q.height, 1, MAX_LADO_QUADRO)) return false;
    if (q.width * q.height > MAX_PIXELS_QUADRO) return false;
    if (!q.data || typeof q.data.length !== 'number' || q.data.length < q.width * q.height * 4) return false;
    const g = q.guia;
    if (!g || typeof g !== 'object') return false;
    if (!numeroEntre(g.cx, 0, 1) || !numeroEntre(g.cy, 0, 1) || !numeroEntre(g.lado, 0.01, 1)) return false;
    return numeroEntre(q.orcamentoMs, ORCAMENTO_MIN_MS, ORCAMENTO_MAX_MS);
}

self.onmessage = function (evento) {
    // evento.data: {id, data: Uint8ClampedArray (RGBA), width, height, guia,
    // orcamentoMs} — todos obrigatorios e validados (entradaValida).
    const quadro = evento.data;
    const id = (quadro && typeof quadro === 'object') ? quadro.id : undefined;

    let valida = false;
    try { valida = entradaValida(quadro); } catch (e) { valida = false; }
    if (!valida) {
        self.postMessage({ ok: false, id: id });
        return;
    }

    try {
        const opcoes = {};
        if (quadro.guia) opcoes.guia = quadro.guia;
        if (quadro.orcamentoMs) opcoes.orcamentoMs = quadro.orcamentoMs;
        const resultado = QrLeitura.lerQuadro(jsQR, quadro.data, quadro.width, quadro.height, opcoes);
        self.postMessage({ ok: !!resultado.ok, id: quadro.id });
    } catch (erro) {
        self.postMessage({ ok: false, id: quadro.id });
    }
};
