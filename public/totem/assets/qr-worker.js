// Web Worker dedicado a leitura de QR code (CNH/CRLV da Expedicao, demanda
// expedicao-vio-cnh-crlv). jsQR e uma funcao SINCRONA sem Worker interno
// proprio (diferente do Tesseract.js, que gerencia seu proprio Worker) —
// por isso rodar jsQR aqui dentro NAO cria o bug de "Worker aninhado" ja
// documentado para o OCR (ver ia_development_state.md, entrada de
// 2026-09-04). Este worker e criado UMA UNICA VEZ pela thread principal
// (assets/app.js, obterQrWorker()) e reaproveitado entre capturas.
//
// Caminho relativo ao PROPRIO worker (nao aninhado dentro de outro worker):
// resolve para public/totem/assets/jsqr/jsQR.js.
importScripts('jsqr/jsQR.js');

self.onmessage = function (evento) {
    // evento.data e o proprio ImageData (structured clone), reconstruido com
    // o buffer transferido pela thread principal (ver lerQrDoCanvas em app.js)
    const imageData = evento.data;

    if (!imageData || !imageData.data || !imageData.width || !imageData.height) {
        self.postMessage({ ok: false });
        return;
    }

    try {
        const resultado = jsQR(imageData.data, imageData.width, imageData.height);

        if (resultado && Array.isArray(resultado.binaryData) && resultado.binaryData.length > 0) {
            self.postMessage({ ok: true, binaryData: resultado.binaryData });
        } else {
            self.postMessage({ ok: false });
        }
    } catch (erro) {
        self.postMessage({ ok: false, erro: String((erro && erro.message) || erro) });
    }
};
