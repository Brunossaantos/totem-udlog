
const TOKEN = document.body.dataset.totemToken;
const API_BASE = '/api/';

const MEDIR = (function () {
    try { return new URLSearchParams(window.location.search).get('medir') === '1'; } catch (e) { return false; }
})();
const MEDIR_EVENTOS = new Set([
    'cap_click', 'cap_canvas_ini', 'cap_canvas_fim', 'enc_ini', 'enc_fim',
    'upload_ini', 'upload_fim', 'ocr_fila_cli', 'ocr_fila_num',
    'ocr_ini_cli', 'ocr_fim_cli', 'ocr_ini_num', 'ocr_fim_num',
    'res_aplic_cli', 'res_descart_cli', 'res_aplic_num', 'res_descart_num',
    'liberado', 'usar_click', 'falha_captura', 'falha_upload', 'falha_ocr_cli', 'falha_ocr_num'
]);
const MEDIR_MAX_EVENTOS = 500;
let medirSeqContador = 0;
let medirPiso = 0;
let medirSeqPrevia = 0;
let medirBuffer = [];
let medirPainelEls = null;

function medirNovaSeq() {
    if (!MEDIR) return 0;
    medirSeqContador += 1;
    return medirSeqContador;
}

function marca(nome, seq) {
    if (!MEDIR) return;
    try {
        if (!MEDIR_EVENTOS.has(nome)) return;
        if (typeof seq !== 'number' || !(seq > medirPiso)) return;
        medirBuffer.push({ n: nome, s: seq, t: Math.round(performance.now()) });
        if (medirBuffer.length > MEDIR_MAX_EVENTOS) medirBuffer.shift();
        if (nome === 'liberado') medirAtualizarPainel();
    } catch (e) { }
}

function medirLimpar() {
    if (!MEDIR) return;
    try {
        medirBuffer = [];
        medirPiso = medirSeqContador;
        medirSeqPrevia = 0;
        medirAtualizarPainel();
    } catch (e) { }
}

function medirAgrupar() {
    const porSeq = new Map();
    for (const ev of medirBuffer) {
        if (!porSeq.has(ev.s)) porSeq.set(ev.s, { usar_lista: [], falha_upload_lista: [] });
        const g = porSeq.get(ev.s);
        g[ev.n] = ev.t;
        if (ev.n === 'usar_click') g.usar_lista.push(ev.t);
        else if (ev.n === 'falha_upload') g.falha_upload_lista.push(ev.t);
    }
    return porSeq;
}

function medirDelta(ev, a, b) {
    return (typeof ev[a] === 'number' && typeof ev[b] === 'number') ? ev[b] - ev[a] : null;
}

function medirEstat(lista) {
    const n = lista.length;
    if (!n) return { n: 0, ultima: null, media: null, min: null, max: null };
    return {
        n,
        ultima: lista[n - 1],
        media: Math.round(lista.reduce((a, b) => a + b, 0) / n),
        min: Math.min.apply(null, lista),
        max: Math.max.apply(null, lista)
    };
}

function medirAmbiente() {
    const num = v => (typeof v === 'number' && isFinite(v) && v > 0 ? Math.round(v) : null);
    let vw = null;
    let vh = null;
    try {
        const video = document.getElementById('videoScanner');
        if (video) { vw = num(video.videoWidth); vh = num(video.videoHeight); }
    } catch (e) { }
    return {
        screen_width: num(window.screen && window.screen.width),
        screen_height: num(window.screen && window.screen.height),
        viewport_width: num(window.innerWidth),
        viewport_height: num(window.innerHeight),
        video_width: vw,
        video_height: vh
    };
}

function medirResumo() {
    const notas = [];
    const totais = [];
    const humanos = [];
    const tecnicos = [];
    const retries = [];
    for (const [seq, ev] of medirAgrupar()) {
        const usar = ev.usar_lista;
        const tentativas = usar.length ? usar.length : null;
        const primeiro = usar.length ? usar[0] : null;
        const ultimo = usar.length ? usar[usar.length - 1] : null;
        const total = medirDelta(ev, 'cap_click', 'liberado');
        const humano = (primeiro !== null && typeof ev.cap_click === 'number') ? primeiro - ev.cap_click : null;
        const tecnico = (ultimo !== null && typeof ev.liberado === 'number') ? ev.liberado - ultimo : null;
        let retry = null;
        if (usar.length > 1) {
            const antes = ev.falha_upload_lista.filter(t => t <= ultimo);
            if (antes.length) retry = ultimo - antes[antes.length - 1];
        }
        if (total !== null) totais.push(total);
        if (humano !== null && tecnico !== null) { humanos.push(humano); tecnicos.push(tecnico); }
        if (retry !== null) retries.push(retry);
        notas.push({
            nota_seq: seq,
            tempo_total_ms: total,
            tempo_humano_inicial_ms: humano,
            tempo_retry_ms: retry,
            tempo_tecnico_sucesso_ms: tecnico,
            tentativas_upload: tentativas,
            canvas_ms: medirDelta(ev, 'cap_canvas_ini', 'cap_canvas_fim'),
            encode_ms: medirDelta(ev, 'enc_ini', 'enc_fim'),
            upload_ms: medirDelta(ev, 'upload_ini', 'upload_fim'),
            fila_cli_ms: medirDelta(ev, 'ocr_fila_cli', 'ocr_ini_cli'),
            ocr_cli_ms: medirDelta(ev, 'ocr_ini_cli', 'ocr_fim_cli'),
            fila_num_ms: medirDelta(ev, 'ocr_fila_num', 'ocr_ini_num'),
            ocr_num_ms: medirDelta(ev, 'ocr_ini_num', 'ocr_fim_num'),
            falhas: ['falha_captura', 'falha_upload', 'falha_ocr_cli', 'falha_ocr_num'].filter(n => typeof ev[n] === 'number')
        });
    }
    const t = medirEstat(totais);
    return {
        n: t.n, ultima: t.ultima, media: t.media, min: t.min, max: t.max,
        media_humano: medirEstat(humanos).media,
        media_tecnico: medirEstat(tecnicos).media,
        media_retry: medirEstat(retries).media,
        notas
    };
}

function medirTexto() {
    const r = medirResumo();
    const f = v => (v === null ? 'na' : v);
    const a = medirAmbiente();
    const linhas = r.notas.map(x => 'nota_seq=' + x.nota_seq
        + ' total_ms=' + f(x.tempo_total_ms)
        + ' humano_inicial_ms=' + f(x.tempo_humano_inicial_ms)
        + ' retry_ms=' + f(x.tempo_retry_ms)
        + ' tecnico_sucesso_ms=' + f(x.tempo_tecnico_sucesso_ms)
        + ' tentativas_upload=' + f(x.tentativas_upload)
        + ' canvas_ms=' + f(x.canvas_ms)
        + ' encode_ms=' + f(x.encode_ms)
        + ' upload_ms=' + f(x.upload_ms)
        + ' fila_cli_ms=' + f(x.fila_cli_ms)
        + ' ocr_cli_ms=' + f(x.ocr_cli_ms)
        + ' fila_num_ms=' + f(x.fila_num_ms)
        + ' ocr_num_ms=' + f(x.ocr_num_ms)
        + (x.falhas.length ? ' falhas=' + x.falhas.join(',') : ''));
    return ['MEDICAO totem-udlog',
        'capturas=' + r.n + ' ultima_ms=' + f(r.ultima) + ' media_ms=' + f(r.media) + ' min_ms=' + f(r.min) + ' max_ms=' + f(r.max)
            + ' media_humano_inicial_ms=' + f(r.media_humano) + ' media_retry_ms=' + f(r.media_retry)
            + ' media_tecnico_sucesso_ms=' + f(r.media_tecnico),
        'screen=' + f(a.screen_width) + 'x' + f(a.screen_height) + ' viewport=' + f(a.viewport_width) + 'x' + f(a.viewport_height)
            + ' video=' + f(a.video_width) + 'x' + f(a.video_height)]
        .concat(linhas).join('\n');
}

function medirJson() {
    const r = medirResumo();
    return JSON.stringify({
        ambiente: medirAmbiente(),
        resumo: { capturas: r.n, ultima_ms: r.ultima, media_ms: r.media, min_ms: r.min, max_ms: r.max,
            media_humano_inicial_ms: r.media_humano,
            media_retry_ms: r.media_retry, media_tecnico_sucesso_ms: r.media_tecnico },
        notas: r.notas
    }, null, 2);
}

function medirStatus(txt) {
    if (medirPainelEls && medirPainelEls.status) medirPainelEls.status.textContent = txt;
}

function medirAtualizarPainel() {
    if (!MEDIR || !medirPainelEls) return;
    try {
        if (!medirPainelEls.raiz.isConnected) { medirPainelEls = null; return; }
        const r = medirResumo();
        const f = v => (v === null ? '-' : v + ' ms');
        medirPainelEls.stats.textContent = 'Capturas: ' + r.n + '\nÚltima (total): ' + f(r.ultima) + '\nMédia: ' + f(r.media)
            + '\nMínimo: ' + f(r.min) + '\nMáximo: ' + f(r.max)
            + '\nMédia humano inicial: ' + f(r.media_humano) + '\nMédia retry: ' + f(r.media_retry)
            + '\nMédia técnico sucesso: ' + f(r.media_tecnico);
    } catch (e) { }
}

function medirBaixarArquivo() {
    try {
        const url = URL.createObjectURL(new Blob([medirJson()], { type: 'application/json' }));
        const a = document.createElement('a');
        a.href = url;
        a.download = 'metricas-totem.json';
        a.style.display = 'none';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(() => { URL.revokeObjectURL(url); }, 1000);
        medirStatus('Arquivo JSON baixado');
    } catch (e) { medirStatus('Falha ao gerar o arquivo'); }
}

function medirCopiar() {
    try {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(medirTexto()).then(
                () => { medirStatus('Copiado para a área de transferência'); },
                () => { medirBaixarArquivo(); }
            );
        } else {
            medirBaixarArquivo();
        }
    } catch (e) { medirBaixarArquivo(); }
}

function medirDesmontarPainel() {
    if (!MEDIR || !medirPainelEls) return;
    try { medirPainelEls.tela.style.justifyContent = ''; } catch (e) { }
    medirPainelEls = null;
}

function medirMontarPainel(tela) {
    if (!MEDIR || !tela) return;
    try {
        const mk = (tag, css) => { const el = document.createElement(tag); el.style.cssText = css; return el; };
        const btnCss = 'min-height:64px;padding:0 18px;font-size:16px;font-weight:700;border-radius:10px;cursor:pointer;';
        const raiz = mk('details', 'flex-shrink:0;width:100%;max-width:min(90%,600px);margin:0 auto;box-sizing:border-box;background:#FFFFFF;color:#3A3A3A;border:2px solid #B0B0B1;border-radius:10px;');
        const resumo = mk('summary', 'min-height:64px;display:flex;align-items:center;padding:0 16px;font-size:16px;font-weight:700;color:#0179AD;cursor:pointer;');
        resumo.textContent = 'MEDIÇÃO ATIVA';
        const corpo = mk('div', 'padding:6px 16px 16px;font-size:15px;');
        const stats = mk('div', 'white-space:pre-line;margin-bottom:10px;color:#3A3A3A;');
        const status = mk('div', 'min-height:20px;margin-bottom:10px;color:#3A3A3A;');
        const copiar = mk('button', btnCss + 'width:100%;background:#0179AD;color:#FFFFFF;border:2px solid #0179AD;');
        copiar.type = 'button';
        copiar.textContent = 'Copiar métricas';
        corpo.appendChild(stats); corpo.appendChild(status); corpo.appendChild(copiar);
        raiz.appendChild(resumo); raiz.appendChild(corpo);
        tela.appendChild(raiz);
        medirPainelEls = { raiz, stats, status, tela };
        raiz.addEventListener('toggle', () => {
            tela.style.justifyContent = raiz.open ? 'flex-start' : '';
            if (raiz.open) { medirAtualizarPainel(); raiz.scrollIntoView({ block: 'nearest' }); }
        });
        copiar.addEventListener('click', medirCopiar);
        medirAtualizarPainel();
    } catch (e) { medirPainelEls = null; }
}

const termoLgpdDados = JSON.parse(
    document.getElementById('lgpd-termo-dados')?.textContent || '{"versao":"","hash":"","texto":""}'
);

const state = {
    tela: 'lgpd',
    lgpdAceito: false,
    lgpdTokenAceite: null,
    tipo: null,
    idAtendimento: null,
    placa: '',
    ordens: [],
    dados: {},
    ajudante: { nome: '', cpf: '' },
    ajudanteCorrecao: false,
    notaUidSeq: 0,
    notasNumeros: [],
    previewNotaAtual: null,
    capturaNotaEmAndamento: false,
    finalizandoDigitalizacao: false,
    excluindoNota: false,
    ultimaLeituraQr: null,
    exp: { previewImg: null, previewCanvas: null, qrTentativas: { cnh: 0, crlv: 0 }, motivos: { cnh: null, crlv: null }, reescaneio: { cnh: false, crlv: false }, reprovacaoAberta: null, cnhOrigem: null, crlvOrigem: null, emAndamento: false, cnhPromise: null, crlvPromise: null, cnhUltimoStatus: null, crlvUltimoStatus: null, cnhAoAtualizar: null, crlvAoAtualizar: null },
    rec: { previewImg: null, previewCanvas: null, qrTentativas: { cnh: 0, crlv: 0 }, motivos: { cnh: null, crlv: null }, reescaneio: { cnh: false, crlv: false }, reprovacaoAberta: null, cnhOrigem: null, crlvOrigem: null, emAndamento: false, cnhPromise: null, crlvPromise: null, cnhUltimoStatus: null, crlvUltimoStatus: null, cnhAoAtualizar: null, crlvAoAtualizar: null },
};

function estadoExpVazio() {
    return { previewImg: null, previewCanvas: null, qrTentativas: { cnh: 0, crlv: 0 }, motivos: { cnh: null, crlv: null }, reescaneio: { cnh: false, crlv: false }, reprovacaoAberta: null, cnhOrigem: null, crlvOrigem: null, emAndamento: false, cnhPromise: null, crlvPromise: null, cnhUltimoStatus: null, crlvUltimoStatus: null, cnhAoAtualizar: null, crlvAoAtualizar: null };
}

function estadoRecVazio() {
    return { previewImg: null, previewCanvas: null, qrTentativas: { cnh: 0, crlv: 0 }, motivos: { cnh: null, crlv: null }, reescaneio: { cnh: false, crlv: false }, reprovacaoAberta: null, cnhOrigem: null, crlvOrigem: null, emAndamento: false, cnhPromise: null, crlvPromise: null, cnhUltimoStatus: null, crlvUltimoStatus: null, cnhAoAtualizar: null, crlvAoAtualizar: null };
}

async function api(arquivo, acao, corpo, opcoes) {
    const timeoutMs = opcoes && Number.isInteger(opcoes.timeoutMs) && opcoes.timeoutMs > 0 ? opcoes.timeoutMs : 0;
    const ctrl = timeoutMs && typeof AbortController === 'function' ? new AbortController() : null;
    const timer = ctrl ? setTimeout(() => ctrl.abort(), timeoutMs) : null;
    let res;
    let json;
    try {
        res = await fetch(`${API_BASE}${arquivo}?acao=${acao}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${TOKEN}`,
            },
            body: JSON.stringify(corpo || {}),
            signal: ctrl ? ctrl.signal : undefined,
        });
        json = await res.json();
    } finally {
        if (timer) clearTimeout(timer);
    }
    if (!json.sucesso) {
        const erro = new Error(json.erro || 'Erro desconhecido');
        erro.status = res.status;
        if (json.codigo !== undefined) erro.codigo = json.codigo;
        if (json.dados !== undefined) erro.dados = json.dados;
        throw erro;
    }
    return json.dados;
}

function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function urlAssetComVersao(url, chave) {
    let versao = 0;
    try {
        const el = document.getElementById('assets-versoes');
        const dados = el ? JSON.parse(el.textContent) : null;
        versao = dados ? Number(dados[chave]) : 0;
    } catch (e) {
        versao = 0;
    }
    return Number.isInteger(versao) && versao > 0 ? url + '?v=' + versao : url;
}

const TESSERACT_VENDOR_PATH = 'assets/vendor/tesseract-5.1.1';

function mostrarErroTela(msg) {
    if (state.tela === 'rec_revisao_numeros') {
        atualizarRevisaoNumeros(msg);
        return;
    }
    const el = document.getElementById('toastErro');
    if (!el) return;
    el.textContent = msg;
    el.classList.add('visivel');
    clearTimeout(mostrarErroTela._timer);
    mostrarErroTela._timer = setTimeout(esconderToastErro, 6000);
}
function esconderToastErro() {
    clearTimeout(mostrarErroTela._timer);
    const el = document.getElementById('toastErro');
    if (!el) return;
    el.classList.remove('visivel');
    el.textContent = '';
}

let campoAtivo = null;

function montarTeclado() {
    const linhas = [
        ['1', '2', '3', '4', '5', '6', '7', '8', '9', '0'],
        ['Q', 'W', 'E', 'R', 'T', 'Y', 'U', 'I', 'O', 'P'],
        ['A', 'S', 'D', 'F', 'G', 'H', 'J', 'K', 'L', 'Ç'],
        ['Z', 'X', 'C', 'V', 'B', 'N', 'M'],
    ];
    let html = '';
    linhas.forEach((linha, i) => {
        html += '<div class="linha-teclas">';
        linha.forEach(t => { html += `<button type="button" class="tecla" onclick="digitar('${t}')">${t}</button>`; });
        if (i === 3) html += `<button type="button" class="tecla tecla-apagar" aria-label="Apagar" onclick="apagar()">⌫</button>`;
        html += '</div>';
    });
    html += `<div class="linha-teclas">
        <button type="button" class="tecla" onclick="digitar(',')">,</button>
        <button type="button" class="tecla" onclick="digitar('.')">.</button>
        <button type="button" class="tecla tecla-espaco" onclick="digitar(' ')">espaço</button>
        <button type="button" class="tecla tecla-ok" onclick="fecharTeclado()">OK</button>
    </div>`;
    document.getElementById('teclado').innerHTML = html;
}

function abrirTeclado(el) { campoAtivo = el; document.getElementById('teclado').classList.add('aberto'); }
function fecharTeclado() { document.getElementById('teclado').classList.remove('aberto'); campoAtivo = null; }
function notificarInput(el) { el.dispatchEvent(new Event('input', { bubbles: true })); }
function digitar(c) { if (campoAtivo) { campoAtivo.value += c; ajustarAlturaTextarea(campoAtivo); notificarInput(campoAtivo); } }
function apagar() { if (campoAtivo) { campoAtivo.value = campoAtivo.value.slice(0, -1); ajustarAlturaTextarea(campoAtivo); notificarInput(campoAtivo); } }

let idleTimerPrincipal = null;
let idleTimerAbandono = null;
let idleEstado = 'inativo';
const IDLE_MS = 180000;
const IDLE_ABANDONO_MS = 30000;
const INATIVIDADE_TETO_SUSPENSAO_MS = 120000;
let suspensaoInicioMs = 0;

function reiniciarIdle() {
    clearTimeout(idleTimerPrincipal);
    suspensaoInicioMs = 0;
    if (state.tela === 'home' || state.tela === 'lgpd') {
        idleEstado = 'inativo';
        return;
    }
    idleEstado = 'normal';
    idleTimerPrincipal = setTimeout(mostrarInatividade, IDLE_MS);
}

let atendimentoGeracao = 0;

function trabalhoAtivoNotas() {
    return !!state.capturaNotaEmAndamento
        || ocrProcessando
        || ocrFila.length > 0
        || identificacoesEmVoo > 0;
}

function notificarFimDeTrabalhoNotas() {
    if (idleEstado === 'aviso' || trabalhoAtivoNotas()) return;
    reiniciarIdle();
}

function mostrarInatividade() {
    if (typeof imprInatividadeSuspensa === 'function' && imprInatividadeSuspensa()) {
        suspensaoInicioMs = 0;
        clearTimeout(idleTimerPrincipal);
        idleTimerPrincipal = setTimeout(mostrarInatividade, IDLE_MS);
        return;
    }
    if (trabalhoAtivoNotas()) {
        if (!suspensaoInicioMs) suspensaoInicioMs = performance.now() || 1;
        const restante = INATIVIDADE_TETO_SUSPENSAO_MS - (performance.now() - suspensaoInicioMs);
        if (restante > 0) {
            clearTimeout(idleTimerPrincipal);
            idleTimerPrincipal = setTimeout(mostrarInatividade, restante);
            return;
        }
    }
    suspensaoInicioMs = 0;
    idleEstado = 'aviso';
    document.getElementById('modalInatividadeCaixa').innerHTML = `
        <div class="titulo">Ainda está aí?</div>
        <div class="subtitulo">Toque na tela para continuar o atendimento.</div>
        <button class="btn-primario" onclick="continuarAposAvisoInatividade();">Continuar</button>
    `;
    document.getElementById('modalInatividadeFundo').classList.add('aberto');
    clearTimeout(idleTimerAbandono);
    idleTimerAbandono = setTimeout(() => {
        idleTimerAbandono = null;
        fecharAvisoInatividade();
        cancelarESair();
    }, IDLE_ABANDONO_MS);
}

function continuarAposAvisoInatividade() {
    fecharAvisoInatividade();
    reiniciarIdle();
}

function fecharAvisoInatividade() {
    document.getElementById('modalInatividadeFundo').classList.remove('aberto');
    clearTimeout(idleTimerAbandono);
    idleTimerAbandono = null;
}

['click', 'touchstart', 'keydown'].forEach(evento => document.addEventListener(evento, () => {
    if (idleEstado === 'aviso') return;
    reiniciarIdle();
}));

function limparCaixaModal(id) {
    const caixa = document.getElementById(id);
    if (!caixa) return;
    caixa.querySelectorAll('img').forEach(img => { img.removeAttribute('src'); });
    caixa.innerHTML = '';
}

function abrirModal(html) {
    limparCaixaModal('modalCaixa');
    document.getElementById('modalCaixa').classList.remove('modal-caixa-reprovacao');
    document.getElementById('modalCaixa').innerHTML = html;
    document.getElementById('modalFundo').classList.add('aberto');
}
function fecharModal() {
    document.getElementById('modalFundo').classList.remove('aberto');
    limparCaixaModal('modalCaixa');
    document.getElementById('modalCaixa').classList.remove('modal-caixa-reprovacao');
}

function confirmarCancelar() {
    abrirModal(`
        <div class="titulo">Cancelar atendimento?</div>
        <div class="subtitulo">Os dados digitados serão perdidos.</div>
        <button class="btn-alerta" onclick="fecharModal(); cancelarESair();">Sim, cancelar</button>
        <button class="btn-fantasma" onclick="fecharModal()">Continuar atendimento</button>
    `);
}

async function cancelarESair() {
    if (state.idAtendimento) {
        try { await api('atendimento.php', 'cancelar', { id_atendimento: state.idAtendimento }); } catch (e) { }
    }
    novoAtendimento();
}

function ir(tela) {
    medirDesmontarPainel();
    pararCamera();
    state.tela = tela;
    document.getElementById('barraCancelar').style.display = (tela === 'home' || tela === 'lgpd') ? 'none' : 'block';
    fecharTeclado();
    fecharModal();
    fecharConfirmacaoExcluirNota();
    if (tela === 'rec_revisao_numeros') esconderToastErro();
    fecharAvisoInatividade();
    renderTela();
    reiniciarIdle();
}

function renderTela() {
    const tela = document.getElementById('tela');
    switch (state.tela) {
        case 'lgpd': tela.innerHTML = telaLgpd(); ligarConsentimentoLgpd(); break;
        case 'home': tela.innerHTML = telaHome(); break;
        case 'exp_placa': tela.innerHTML = telaPlacaExpedicao(); break;
        case 'exp_selecionar_ordem': tela.innerHTML = telaSelecionarOrdem(); ligarCartoesOrdem(); break;
        case 'exp_dados': tela.innerHTML = telaDados(); break;
        case 'exp_cnh_qr': tela.innerHTML = telaExpCnhQr(); iniciarCameraExp(); break;
        case 'exp_cnh_manual': tela.innerHTML = telaExpCnhManual(); break;
        case 'exp_crlv_qr': tela.innerHTML = telaExpCrlvQr(); iniciarCameraExp(); exibirIndicadorProcessamentoCnh(); break;
        case 'exp_crlv_manual': tela.innerHTML = telaExpCrlvManual(); break;
        case 'exp_aguarde_documentos': tela.innerHTML = telaExpAguardeDocumentos(); processarAguardeDocumentosExp(); break;
        case 'exp_confirma': tela.innerHTML = telaConfirma('retirada de carga'); ajustarTextareasConfirma(); break;
        case 'exp_ajudante': tela.innerHTML = telaAjudante(); break;
        case 'exp_impressao': tela.innerHTML = telaImpressao(); entrarTelaImpressao(); break;
        case 'rec_placa_qtd': tela.innerHTML = telaRecPlacaQtd(); break;
        case 'rec_bloqueado': tela.innerHTML = telaBloqueado(); break;
        case 'rec_digitaliza': tela.innerHTML = telaDigitaliza(); medirMontarPainel(tela); iniciarCameraScanner(); break;
        case 'rec_revisao_numeros': tela.innerHTML = telaRevisaoNumeros(); atualizarRevisaoNumeros(); break;
        case 'rec_cliente': tela.innerHTML = telaCliente(); habilitarAutocompleteCliente(); break;
        case 'rec_cnh_qr': tela.innerHTML = telaRecCnhQr(); iniciarCameraRec(); break;
        case 'rec_cnh_manual': tela.innerHTML = telaRecCnhManual(); break;
        case 'rec_crlv_qr': tela.innerHTML = telaRecCrlvQr(); iniciarCameraRec(); exibirIndicadorProcessamentoCnhRec(); break;
        case 'rec_crlv_manual': tela.innerHTML = telaRecCrlvManual(); break;
        case 'rec_aguarde_documentos': tela.innerHTML = telaRecAguardeDocumentos(); processarAguardeDocumentosRec(); break;
        case 'rec_confirma': tela.innerHTML = telaConfirma('entrega de carga'); ajustarTextareasConfirma(); break;
        case 'rec_ajudante': tela.innerHTML = telaAjudante(); break;
        case 'rec_impressao': tela.innerHTML = telaImpressao(); entrarTelaImpressao(); break;
    }
}

function novoAtendimento() {
    pollGeracao++;
    if (typeof imprResetarEtiquetas === 'function') imprResetarEtiquetas();
    atendimentoGeracao++;
    suspensaoInicioMs = 0;
    Object.assign(state, {
        tela: 'lgpd', lgpdAceito: false, lgpdTokenAceite: null,
        tipo: null, idAtendimento: null, placa: '',
        ordens: [], dados: {}, ajudante: { nome: '', cpf: '' }, ajudanteCorrecao: false, notaUidSeq: 0, notasNumeros: [], previewNotaAtual: null,
        capturaNotaEmAndamento: false, finalizandoDigitalizacao: false, excluindoNota: false,
        ultimaLeituraQr: null,
        exp: estadoExpVazio(),
        rec: estadoRecVazio(),
    });
    ocrFila = [];
    numeroModalAberta = false;
    numeroModalOverrideUid = null;
    avisoCapturaVazia = false;
    resetarControleNotasServidor();
    limparFaixaExclusaoNota();
    medirLimpar();
    ir('lgpd');
}

let focoAnteriorModalLgpd = null;

function telaLgpd() {
    return `<div class="lgpd-tela">
        <section class="lgpd-content-panel">
            <div>
                <header class="lgpd-intro">
                    <img class="lgpd-logo" src="assets/udlog.png" alt="UDLOG United Logistics">
                    <p class="lgpd-eyebrow">Seja bem-vindo</p>
                    <h1 class="lgpd-titulo">Vamos iniciar seu atendimento.</h1>
                    <p class="lgpd-subtitulo">Tenha os seus documentos em mão para continuar.</p>
                </header>

                <div class="lgpd-actions">
                    <div class="lgpd-consent-area">
                        <label class="lgpd-checkbox-label" for="lgpdCheckbox">
                            <input class="lgpd-checkbox" type="checkbox" id="lgpdCheckbox">
                            <span class="lgpd-consent-text">Li e estou ciente do Aviso de Privacidade.</span>
                        </label>

                        <button type="button" class="lgpd-link-ver-termo" id="lgpdBtnVerTermo" aria-label="Ver termo completo de privacidade">Ver termo completo</button>
                    </div>

                    <button type="button" class="lgpd-btn-continuar" id="lgpdBtnContinuar" disabled aria-disabled="true" onclick="aceitarLgpd()">Iniciar</button>
                </div>
            </div>
        </section>
    </div>
    <div class="modal-fundo modal-fundo-lgpd" id="modalLgpdFundo">
        <div class="modal-lgpd-caixa" id="modalLgpdCaixa">
            <div class="modal-lgpd-cabecalho">
                <div class="modal-lgpd-titulo">Aviso de Privacidade — texto completo</div>
                <button type="button" class="modal-lgpd-fechar" id="lgpdModalBtnFechar" onclick="fecharModalLgpd()" aria-label="Fechar">✕</button>
            </div>
            <div class="modal-lgpd-corpo">${termoLgpdDados.texto}</div>
            <div class="modal-lgpd-rodape">
                <button type="button" class="modal-lgpd-btn-fechar" onclick="fecharModalLgpd()">Fechar</button>
            </div>
        </div>
    </div>`;
}

function ligarConsentimentoLgpd() {
    const checkbox = document.getElementById('lgpdCheckbox');
    const btnContinuar = document.getElementById('lgpdBtnContinuar');
    checkbox.checked = false;
    checkbox.addEventListener('change', () => {
        state.lgpdAceito = checkbox.checked;
        if (checkbox.checked) {
            btnContinuar.disabled = false;
            btnContinuar.removeAttribute('aria-disabled');
        } else {
            btnContinuar.disabled = true;
            btnContinuar.setAttribute('aria-disabled', 'true');
        }
    });
    document.getElementById('lgpdBtnVerTermo').addEventListener('click', abrirModalLgpd);
}

function abrirModalLgpd() {
    focoAnteriorModalLgpd = document.activeElement;
    document.getElementById('modalLgpdFundo').classList.add('aberto');
    document.getElementById('lgpdModalBtnFechar').focus();
}

function fecharModalLgpd() {
    document.getElementById('modalLgpdFundo').classList.remove('aberto');
    if (focoAnteriorModalLgpd && typeof focoAnteriorModalLgpd.focus === 'function') {
        focoAnteriorModalLgpd.focus();
    }
    focoAnteriorModalLgpd = null;
}

async function aceitarLgpd() {
    const checkbox = document.getElementById('lgpdCheckbox');
    const btnContinuar = document.getElementById('lgpdBtnContinuar');
    if (!checkbox || !checkbox.checked || !state.lgpdAceito || btnContinuar.disabled) return;

    btnContinuar.disabled = true;
    try {
        const dados = await api('lgpd.php', 'aceitar', {});
        state.lgpdTokenAceite = dados.token_aceite;
        ir('home');
    } catch (e) {
        btnContinuar.disabled = false;
        btnContinuar.removeAttribute('aria-disabled');
        mostrarErroTela(e.message || 'Não foi possível confirmar sua ciência agora. Tente novamente.');
    }
}

function voltarParaLgpdPorAceiteExpirado(mensagem) {
    state.lgpdTokenAceite = null;
    state.lgpdAceito = false;
    ir('lgpd');
    mostrarErroTela(mensagem || 'Aceite de privacidade inválido ou expirado. Confirme novamente.');
}

function telaHome() {
    return `<div class="titulo titulo-home">Selecione o tipo de atendimento</div>
        <div class="grupo-botoes">
            <button class="tile tile-principal" onclick="selecionarTipo('expedicao')">Expedição</button>
            <button class="tile tile-secundaria" onclick="selecionarTipo('recebimento')">Recebimento</button>
        </div>
        <div id="diagHotspot" class="diag-hotspot" aria-hidden="true"></div>`;
}
function selecionarTipo(tipo) {
    state.tipo = tipo;
    ir(tipo === 'expedicao' ? 'exp_placa' : 'rec_placa_qtd');
}

function telaPlacaExpedicao() {
    return `<div class="titulo">Digite a placa do veículo</div>
        <input class="campo-texto kb-input" id="inputPlaca" placeholder="AAA-0A00">
        <button class="btn-primario" style="max-width:320px;margin:0 auto" onclick="consultarPlacaExpedicao()">Consultar</button>`;
}
async function consultarPlacaExpedicao() {
    const placa = document.getElementById('inputPlaca').value.trim();
    if (!placa) return mostrarErroTela('Digite a placa');
    state.placa = placa;
    try {
        const dados = await api('atendimento.php', 'iniciar', { tipo: 'expedicao', placa, token_aceite: state.lgpdTokenAceite });
        state.lgpdTokenAceite = null;
        state.idAtendimento = dados.id_atendimento;
        if (dados.proxima_tela === 'selecionar_ordem') {
            state.ordens = dados.ordens;
            ir('exp_selecionar_ordem');
        } else {
            state.dados = dados.dados;
            ir('exp_dados');
        }
    } catch (e) {
        if (e.status === 409) { voltarParaLgpdPorAceiteExpirado(e.message); return; }
        mostrarErroTela(e.message);
    }
}

function telaSelecionarOrdem() {
    const cartoes = state.ordens.map((o, i) => `
        <button class="cartao-oc" data-ordem-index="${i}">
            <div class="numero">${escapeHtml(o.numero)}</div>
            <div class="detalhe">${escapeHtml(o.data || '')} · ${escapeHtml(o.cliente_nome || '')}</div>
        </button>
    `).join('');
    return `<div class="titulo">Mais de uma ordem em aberto</div>
        <div class="subtitulo">Selecione qual você vai usar</div>
        <div class="grupo-botoes">${cartoes}</div>`;
}
function ligarCartoesOrdem() {
    document.querySelectorAll('.cartao-oc').forEach(btn => {
        btn.addEventListener('click', () => escolherOrdem(state.ordens[+btn.dataset.ordemIndex]));
    });
}
async function escolherOrdem(ordem) {
    try {
        const dados = await api('atendimento.php', 'selecionar-ordem', { id_atendimento: state.idAtendimento, ordem });
        state.dados = dados.dados;
        ir('exp_dados');
    } catch (e) { mostrarErroTela(e.message); }
}

function campo(rotulo, id, valor) {
    return `<div class="campo"><label>${rotulo}</label><input class="kb-input" id="${id}" value="${escapeHtml(valor)}"></div>`;
}

const UF_LISTA = [
    'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS',
    'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC',
    'SP', 'SE', 'TO',
];
function campoSelectUf(rotulo, id, valorAtual) {
    const opcoes = UF_LISTA.map((uf) => `<option value="${uf}" ${uf === valorAtual ? 'selected' : ''}>${uf}</option>`).join('');
    return `<div class="campo"><label>${rotulo}</label>
        <select class="kb-input" id="${id}">
            <option value="">Selecione</option>
            ${opcoes}
        </select>
    </div>`;
}
function telaDados() {
    const d = state.dados || {};
    return `<div class="subtitulo">Dados da ordem — toque para editar</div>
        <div class="grade-campos">
            ${campo('Cliente', 'campoCliente', d.cliente_nome)}
            ${campo('CNPJ', 'campoCnpj', d.cliente_cnpj)}
            ${campo('Placa', 'campoPlacaDados', state.placa)}
            ${campo('Veículo', 'campoVeiculo', d.veiculo)}
            ${campo('Ordem de coleta', 'campoOc', d.numero)}
        </div>
        <button class="btn-primario" id="btnAvancarDados" style="max-width:320px;margin:0 auto" onclick="avancarDados()">Avançar</button>`;
}
async function avancarDados() {
    state.dados.cliente_nome = document.getElementById('campoCliente').value;
    state.dados.cliente_cnpj = document.getElementById('campoCnpj').value;
    const btn = document.getElementById('btnAvancarDados');
    if (btn) btn.disabled = true;
    try {
        const dados = await api('atendimento.php', 'avancar-etapa-documentos', { id_atendimento: state.idAtendimento });
        if (dados.proxima_tela === 'exp_cnh') {
            ir('exp_cnh_qr');
        } else {
            mostrarErroTela('Não foi possível avançar agora.');
            if (btn) btn.disabled = false;
        }
    } catch (e) {
        mostrarErroTela(e.message);
        if (btn) btn.disabled = false;
    }
}

let streamAtual = null;

function telaCaptura(titulo) {
    return `<div class="titulo">${titulo}</div>
        <div class="caixa-camera"><video id="video" autoplay playsinline></video></div>
        <div class="status-leitura" id="statusQr">Aguardando leitura do QR code</div>
        <input type="text" id="inputScanner" style="position:absolute;left:-9999px" autocomplete="off">
        <button class="btn-primario" style="max-width:320px;margin:0 auto" onclick="capturarDocumento()">Capturar</button>`;
}

async function iniciarCamera() {
    try {
        streamAtual = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
        const video = document.getElementById('video');
        if (video) video.srcObject = streamAtual;
    } catch (e) {
        mostrarErroTela('Câmera não disponível: ' + e.message);
    }
}
function pararCamera() {
    if (streamAtual) { streamAtual.getTracks().forEach(t => t.stop()); streamAtual = null; }
    pararCameraScanner();
    pararCameraExp();
    pararCameraRec();
}
function capturarFotoBase64(videoEl) {
    const video = videoEl || document.getElementById('video');
    const canvas = document.createElement('canvas');
    const largura = 900;
    const escala = video.videoWidth ? largura / video.videoWidth : 1;
    canvas.width = largura;
    canvas.height = (video.videoHeight || 675) * escala;
    canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
    return canvas.toDataURL('image/jpeg', 0.7);
}

function habilitarLeitorScanner() {
    const input = document.getElementById('inputScanner');
    if (!input) return;
    input.focus();
    input.addEventListener('keydown', e => {
        if (e.key === 'Enter') {
            onLeituraScanner(input.value);
            input.value = '';
        }
    });
    document.getElementById('tela').addEventListener('click', e => {
        if (!e.target.classList.contains('kb-input')) input.focus();
    });
}
function onLeituraScanner(conteudo) {
    state.ultimaLeituraQr = conteudo;
    const status = document.getElementById('statusQr');
    if (status) { status.textContent = 'Código lido com sucesso'; status.classList.add('ok'); }
}


let expCamStream = null;
let expCamDeviceId = null;
let expCamVideoPronto = false;
let expCamDeviceChangeAtivo = false;

const ajustesProporcaoCameraDocumento = {
    exp: { observer: null, video: null, aoRedimensionar: null, largura: 0, altura: 0 },
    rec: { observer: null, video: null, aoRedimensionar: null, largura: 0, altura: 0 }
};

function limparProporcaoCameraDocumento(chave) {
    const ajuste = ajustesProporcaoCameraDocumento[chave];
    if (!ajuste) return;
    if (ajuste.observer) ajuste.observer.disconnect();
    if (ajuste.video && ajuste.aoRedimensionar) ajuste.video.removeEventListener('resize', ajuste.aoRedimensionar);
    ajuste.observer = null;
    ajuste.video = null;
    ajuste.aoRedimensionar = null;
    ajuste.largura = 0;
    ajuste.altura = 0;
}

function aplicarProporcaoCameraDocumento(chave, caixaId, previaId) {
    const ajuste = ajustesProporcaoCameraDocumento[chave];
    if (!ajuste || !ajuste.largura || !ajuste.altura) return;
    const caixa = document.getElementById(caixaId);
    const previa = document.getElementById(previaId);
    const referencia = caixa && caixa.offsetParent !== null ? caixa : previa;
    if (!referencia || !referencia.clientWidth) return;
    const altura = Math.round(referencia.clientWidth * ajuste.altura / ajuste.largura);
    if (caixa) caixa.style.height = altura + 'px';
    if (previa) previa.style.height = altura + 'px';
}

function configurarProporcaoCameraDocumento(chave, video, caixaId, previaId) {
    limparProporcaoCameraDocumento(chave);
    const ajuste = ajustesProporcaoCameraDocumento[chave];
    const atualizar = () => {
        if (!video.videoWidth || !video.videoHeight) return;
        ajuste.largura = video.videoWidth;
        ajuste.altura = video.videoHeight;
        aplicarProporcaoCameraDocumento(chave, caixaId, previaId);
    };
    ajuste.video = video;
    ajuste.aoRedimensionar = atualizar;
    video.addEventListener('resize', atualizar);
    if (typeof ResizeObserver !== 'undefined') {
        ajuste.observer = new ResizeObserver(atualizar);
        const caixa = document.getElementById(caixaId);
        const previa = document.getElementById(previaId);
        if (caixa) ajuste.observer.observe(caixa);
        if (previa) ajuste.observer.observe(previa);
    }
    atualizar();
}

function expCamMostrarStatus(msg, isErro) {
    const el = document.getElementById('expCamStatus');
    if (!el) return;
    el.textContent = msg;
    el.classList.toggle('erro', !!isErro);
    el.classList.toggle('ok', !isErro && (msg === 'Câmera pronta' || (msg || '').indexOf('aprovad') !== -1));
}

async function iniciarCameraExp() {
    expCamVideoPronto = false;
    if (!window.isSecureContext) {
        expCamMostrarStatus('Esta página precisa ser aberta via HTTPS ou localhost para acessar a câmera.', true);
        return;
    }
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !navigator.mediaDevices.enumerateDevices) {
        expCamMostrarStatus('Navegador sem suporte a câmera.', true);
        return;
    }
    expCamMostrarStatus('Conectando à câmera...');
    try {
        const permStream = await navigator.mediaDevices.getUserMedia({ video: true });
        permStream.getTracks().forEach(t => t.stop());
        const devices = await navigator.mediaDevices.enumerateDevices();
        const cams = devices.filter(d => d.kind === 'videoinput');
        if (cams.length === 0) {
            expCamMostrarStatus('Câmera não encontrada. Verifique a conexão USB.', true);
            return;
        }
        let deviceId = localStorage.getItem('totem_scanner_deviceId');
        if (deviceId && !cams.some(c => c.deviceId === deviceId)) deviceId = null;
        if (!deviceId) {
            if (cams.length === 1) {
                deviceId = cams[0].deviceId;
            } else {
                expCamAbrirSelecao(cams);
                return;
            }
        }
        await expCamAbrirStream(deviceId);
    } catch (e) {
        expCamTratarErro(e);
    }
}

function expCamAbrirSelecao(cams) {
    expCamMostrarStatus('Selecione a câmera');
    const botoes = cams.map((c, i) =>
        `<button class="btn-fantasma" data-device-id="${escapeHtml(c.deviceId)}">${escapeHtml(c.label || ('Câmera ' + (i + 1)))}</button>`
    ).join('');
    abrirModal(`<div class="titulo">Selecione a câmera</div><div class="grupo-botoes">${botoes}</div>`);
    document.querySelectorAll('#modalCaixa [data-device-id]').forEach(btn => {
        btn.addEventListener('click', () => {
            const deviceId = btn.dataset.deviceId;
            localStorage.setItem('totem_scanner_deviceId', deviceId);
            fecharModal();
            expCamAbrirStream(deviceId);
        });
    });
}

const EXP_CAM_RESOLUCAO_IDEAL_LARGURA = 4096;
const EXP_CAM_RESOLUCAO_IDEAL_ALTURA = 3072;

async function expCamAbrirStream(deviceId) {
    expCamMostrarStatus('Conectando à câmera...');
    try {
        const stream = await navigator.mediaDevices.getUserMedia({
            video: {
                deviceId: { exact: deviceId },
                width: { ideal: EXP_CAM_RESOLUCAO_IDEAL_LARGURA },
                height: { ideal: EXP_CAM_RESOLUCAO_IDEAL_ALTURA },
            },
        });
        expCamStream = stream;
        expCamDeviceId = deviceId;
        qrOtimizarCamera(stream);
        const video = document.getElementById('expCamVideo');
        if (!video) { stream.getTracks().forEach(t => t.stop()); expCamStream = null; return; }
        video.srcObject = stream;
        video.onloadedmetadata = () => {
            configurarProporcaoCameraDocumento('exp', video, 'expCamCaixa', 'expCamPreview');
            expCamVideoPronto = true;
            expCamMostrarStatus('Câmera pronta');
            expCamAtualizarBotao();
        };
        stream.getVideoTracks().forEach(track => {
            track.onended = () => {
                expCamVideoPronto = false;
                expCamMostrarStatus('A câmera foi desconectada. Reconecte o dispositivo e tente novamente.', true);
                expCamAtualizarBotao();
            };
        });
        if (!expCamDeviceChangeAtivo) {
            navigator.mediaDevices.ondevicechange = expCamTratarMudancaDispositivos;
            expCamDeviceChangeAtivo = true;
        }
    } catch (e) {
        expCamTratarErro(e);
    }
}

async function expCamTratarMudancaDispositivos() {
    if (!['exp_cnh_qr', 'exp_crlv_qr'].includes(state.tela)) return;
    try {
        const devices = await navigator.mediaDevices.enumerateDevices();
        const aindaConectado = devices.some(d => d.kind === 'videoinput' && d.deviceId === expCamDeviceId);
        if (!aindaConectado) {
            expCamVideoPronto = false;
            expCamMostrarStatus('A câmera foi desconectada. Reconecte o dispositivo e tente novamente.', true);
            expCamAtualizarBotao();
        }
    } catch (e) { }
}

function expCamTratarErro(e) {
    switch (e.name) {
        case 'NotAllowedError':
            expCamMostrarStatus('Permissão de câmera negada. Autorize o acesso à câmera para continuar.', true);
            break;
        case 'NotFoundError':
            expCamMostrarStatus('Câmera não encontrada. Verifique a conexão USB.', true);
            break;
        case 'NotReadableError':
        case 'TrackStartError':
            expCamMostrarStatus('Câmera ocupada por outro programa. Feche o NetumScan Pro ou qualquer outro aplicativo que esteja usando a câmera.', true);
            break;
        case 'OverconstrainedError':
            localStorage.removeItem('totem_scanner_deviceId');
            expCamMostrarStatus('A câmera selecionada não está mais disponível. Selecione novamente.', true);
            iniciarCameraExp();
            break;
        default:
            expCamMostrarStatus('Erro ao acessar a câmera: ' + (e.message || e.name || 'erro desconhecido'), true);
    }
}

function pararCameraExp() {
    if (expCamStream) { expCamStream.getTracks().forEach(t => t.stop()); expCamStream = null; }
    limparProporcaoCameraDocumento('exp');
    if (expCamDeviceChangeAtivo) { navigator.mediaDevices.ondevicechange = null; expCamDeviceChangeAtivo = false; }
    expCamVideoPronto = false;
    expCamDeviceId = null;
}

function expCamAtualizarBotao() {
    const btn = document.getElementById('expCamBtnCapturar');
    if (!btn) return;
    const video = document.getElementById('expCamVideo');
    const prontoVideo = !!video && video.readyState >= 2 && video.videoWidth > 0 && video.videoHeight > 0;
    btn.disabled = !prontoVideo || !expCamVideoPronto;
}

function telaExpQr(titulo, tipo) {
    return `<div class="titulo">${titulo}</div>
        <div class="subtitulo">Encaixe o QR code dentro do quadrado, bem iluminado e sem reflexo.</div>
        <div class="caixa-scanner" id="expCamCaixa">
            <video id="expCamVideo" autoplay playsinline></video>
            <div class="guia-qr" aria-hidden="true"></div>
        </div>
        <div class="status-scanner" id="expCamStatus">Conectando à câmera...</div>
        <div class="status-leitura" id="expBgStatus" style="display:none"></div>
        <div class="grupo-botoes" id="expCamControles">
            <button class="btn-primario" id="expCamBtnCapturar" onclick="expCapturarQr('${tipo}')" disabled>Ler QR code</button>
        </div>`;
}
function telaExpCnhQr() { return telaExpQr('Leia o QR code da CNH', 'cnh'); }
function telaExpCrlvQr() { return telaExpQr('Leia o QR code do CRLV', 'crlv'); }

function expLimparFrameQr() {
    state.exp.previewImg = null;
    state.exp.previewCanvas = null;
}

async function expCapturarQr(tipo) {
    if (state.exp.emAndamento) return;
    const video = document.getElementById('expCamVideo');
    if (!video || video.readyState < 2 || !video.videoWidth || !video.videoHeight) {
        expCamMostrarStatus('Aguarde o vídeo carregar.', true);
        return;
    }
    state.exp.emAndamento = true;
    const btn = document.getElementById('expCamBtnCapturar');
    if (btn) btn.disabled = true;
    let canvas = null;
    try {
        expCamMostrarStatus('Lendo QR code...');
        canvas = await lerQrEmVariosFrames(() => expCamCapturarFrame(video), video, expCamStream);
        if (!video.isConnected) return;
        if (!canvas) {
            expLimparFrameQr();
            const tentativa = ++state.exp.qrTentativas[tipo];
            if (tentativa >= 3) {
                ir(tipo === 'cnh' ? 'exp_cnh_manual' : 'exp_crlv_manual');
                return;
            }
            expCamMostrarStatus(`Não encontramos o QR. Aproxime e tente novamente. Tentativa ${tentativa} de 3.`, true);
            return;
        }

        const imagemQr = canvas.toDataURL('image/jpeg', 0.85);
        expLimparFrameQr();
        canvas = null;
        if (!state.exp.reescaneio[tipo]) state.exp.qrTentativas[tipo] = 0;
        expCamMostrarStatus(TEXTO_LENDO_DOCUMENTO);
        state.exp[tipo + 'Promise'] = iniciarProcessamentoDocumento(
            state.idAtendimento,
            tipo,
            imagemQr,
            (status) => atualizarStatusDocumento('exp', tipo, status),
        );
        if (state.exp.reescaneio[tipo]) {
            ir('exp_aguarde_documentos');
        } else {
            await tentarAvancarEtapaDocumentos('exp_cnh_manual', 'exp_crlv_manual');
        }
    } catch (e) {
        expCamMostrarStatus('Não foi possível iniciar a validação agora.', true);
    } finally {
        canvas = null;
        state.exp.emAndamento = false;
        if (btn && document.getElementById('expCamBtnCapturar') === btn) expCamAtualizarBotao();
    }
}
function exibirIndicadorProcessamentoCnh() {
    const el = document.getElementById('expBgStatus');
    if (!el || !state.exp.cnhPromise) return;
    state.exp.cnhAoAtualizar = (status) => {
        const alvo = document.getElementById('expBgStatus');
        if (!alvo) return;
        alvo.style.display = 'block';
        alvo.textContent = rotuloStatusProcessamento(status.status_processamento);
    };
    if (state.exp.cnhUltimoStatus) {
        state.exp.cnhAoAtualizar(state.exp.cnhUltimoStatus);
    } else {
        el.textContent = TEXTO_LENDO_DOCUMENTO;
        el.style.display = 'block';
    }
    state.exp.cnhPromise.then(resultado => {
        state.exp.cnhAoAtualizar = null;
        const alvo = document.getElementById('expBgStatus');
        if (!alvo) return;
        alvo.textContent = resultado.pode_avancar
            ? 'CNH validada'
            : 'CNH ainda pendente — será solicitado preenchimento manual se necessário';
    });
}

function telaExpAguardeDocumentos() {
    return `<div class="titulo">Estamos validando seus documentos. Aguarde.</div>
        <div class="impr-spinner" aria-hidden="true"></div>
        <div class="status-leitura" id="expAguardeCnhStatus"></div>
        <div class="status-leitura" id="expAguardeCrlvStatus"></div>`;
}

async function processarAguardeDocumentosExp() {
    state.exp.cnhAoAtualizar = (status) => {
        const el = document.getElementById('expAguardeCnhStatus');
        if (el) el.textContent = 'CNH: ' + rotuloStatusProcessamento(status.status_processamento);
    };
    state.exp.crlvAoAtualizar = (status) => {
        const el = document.getElementById('expAguardeCrlvStatus');
        if (el) el.textContent = 'CRLV: ' + rotuloStatusProcessamento(status.status_processamento);
    };
    if (state.exp.cnhUltimoStatus) state.exp.cnhAoAtualizar(state.exp.cnhUltimoStatus);
    if (state.exp.crlvUltimoStatus) state.exp.crlvAoAtualizar(state.exp.crlvUltimoStatus);

    const resultado = await aguardarDocumentos(state.idAtendimento, state.exp.cnhPromise, state.exp.crlvPromise);
    state.exp.cnhAoAtualizar = null;
    state.exp.crlvAoAtualizar = null;
    state.exp.cnhPromise = null;
    state.exp.crlvPromise = null;
    if (resultado.cnh.pode_avancar) state.exp.cnhOrigem = resultado.cnh.origem || 'VIO_VALIDADO';
    if (resultado.crlv.pode_avancar) state.exp.crlvOrigem = resultado.crlv.origem || 'VIO_VALIDADO';
    if (state.tela !== 'exp_aguarde_documentos') return;
    registrarMotivosReprovacao('exp', resultado);
    await tentarAvancarEtapaDocumentos('exp_cnh_manual', 'exp_crlv_manual');
}

function expCamCapturarFrame(video) {
    const canvas = document.createElement('canvas');
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
    return canvas;
}

function telaExpCnhManual() {
    return `<div class="titulo">Preencher dados da CNH manualmente</div>
        <div class="subtitulo">Um atendente vai revisar esses dados depois</div>
        <div class="grade-campos">
            ${campo('Nome completo', 'expManualCnhNome', '')}
            ${campo('CPF', 'expManualCnhCpf', '')}
            ${campo('Validade (AAAA-MM-DD)', 'expManualCnhValidade', '')}
        </div>
        <div class="status-scanner" id="expManualStatus"></div>
        <button class="btn-primario" id="expManualBtn" style="max-width:320px;margin:0 auto" onclick="expConfirmarCnhManual()">Confirmar</button>`;
}

async function expConfirmarCnhManual() {
    if (state.exp.emAndamento) return;
    const nome = document.getElementById('expManualCnhNome').value.trim();
    const cpf = document.getElementById('expManualCnhCpf').value.trim();
    const validade = document.getElementById('expManualCnhValidade').value.trim();
    const statusEl = document.getElementById('expManualStatus');

    if (!nome) return mostrarErroTela('Informe o nome completo');
    if (cpf.replace(/\D/g, '').length !== 11) return mostrarErroTela('CPF inválido');
    if (!validade) return mostrarErroTela('Informe a validade da CNH');

    state.exp.emAndamento = true;
    const btn = document.getElementById('expManualBtn');
    if (btn) btn.disabled = true;
    if (statusEl) { statusEl.textContent = 'Enviando...'; statusEl.classList.remove('erro'); }

    try {
        const resultado = await api('documento.php', 'preencher-manual', {
            id_atendimento: state.idAtendimento,
            tipo: 'cnh',
            nome, cpf, validade,
        });
        if (!resultado.pode_avancar) {
            const msg = resultado.motivo || 'Dados da CNH não aprovados';
            mostrarErroTela(msg);
            if (statusEl) { statusEl.textContent = msg; statusEl.classList.add('erro'); }
            return;
        }
        state.exp.cnhOrigem = 'MANUAL';
        await tentarAvancarEtapaDocumentos('exp_cnh_manual', 'exp_crlv_manual');
    } catch (e) {
        mostrarErroTela(e.message);
        if (statusEl) { statusEl.textContent = e.message; statusEl.classList.add('erro'); }
    } finally {
        state.exp.emAndamento = false;
        if (btn) btn.disabled = false;
    }
}

function telaExpCrlvManual() {
    return `<div class="titulo">Preencher dados do CRLV manualmente</div>
        <div class="subtitulo">Um atendente vai revisar esses dados depois</div>
        <div class="grade-campos">
            ${campo('Placa', 'expManualCrlvPlaca', state.placa)}
            ${campo('Exercício', 'expManualCrlvExercicio', '')}
            ${campoSelectUf('UF', 'expManualCrlvUf', '')}
            ${campo('RNTC', 'expManualCrlvRntc', '')}
            ${campo('Tipo de veículo', 'expManualCrlvTipoVeiculo', '')}
        </div>
        <div class="status-scanner" id="expManualStatus"></div>
        <button class="btn-primario" id="expManualBtn" style="max-width:320px;margin:0 auto" onclick="expConfirmarCrlvManual()">Confirmar</button>`;
}

async function expConfirmarCrlvManual() {
    if (state.exp.emAndamento) return;
    const placa = document.getElementById('expManualCrlvPlaca').value.trim();
    const exercicio = document.getElementById('expManualCrlvExercicio').value.trim();
    const uf = document.getElementById('expManualCrlvUf').value.trim();
    const rntc = document.getElementById('expManualCrlvRntc').value.trim();
    const tipoVeiculo = document.getElementById('expManualCrlvTipoVeiculo').value.trim();
    const statusEl = document.getElementById('expManualStatus');

    if (!placa) return mostrarErroTela('Informe a placa');
    if (!/^\d+$/.test(exercicio)) return mostrarErroTela('Exercício inválido');
    if (!uf) return mostrarErroTela('Selecione a UF');

    state.exp.emAndamento = true;
    const btn = document.getElementById('expManualBtn');
    if (btn) btn.disabled = true;
    if (statusEl) { statusEl.textContent = 'Enviando...'; statusEl.classList.remove('erro'); }

    try {
        const resultado = await api('documento.php', 'preencher-manual', {
            id_atendimento: state.idAtendimento,
            tipo: 'crlv',
            placa, exercicio, uf, rntc, tipo_veiculo: tipoVeiculo,
        });
        if (!resultado.pode_avancar) {
            const msg = resultado.motivo || 'Dados do CRLV não aprovados';
            mostrarErroTela(msg);
            if (statusEl) { statusEl.textContent = msg; statusEl.classList.add('erro'); }
            return;
        }
        state.exp.crlvOrigem = 'MANUAL';
        await tentarAvancarEtapaDocumentos('exp_cnh_manual', 'exp_crlv_manual');
    } catch (e) {
        mostrarErroTela(e.message);
        if (statusEl) { statusEl.textContent = e.message; statusEl.classList.add('erro'); }
    } finally {
        state.exp.emAndamento = false;
        if (btn) btn.disabled = false;
    }
}


let qrWorker = null;
let qrWorkerCallbackPendente = null;
let qrWorkerSeq = 0;

const QR_GUIA = { cx: 0.5, cy: 0.5, lado: 0.36 };
const QR_ORCAMENTO_FRAME_MS = 300;
const QR_TIMEOUT_WORKER_MS = 4000;
const QR_FRAMES_POR_TOQUE = 3;
const QR_INTERVALO_FRAMES_MS = 150;

function obterQrWorker() {
    if (!qrWorker) {
        qrWorker = new Worker(urlAssetComVersao('assets/qr-worker.js', 'qrWorker'));
        qrWorker.onmessage = (e) => {
            const pendente = qrWorkerCallbackPendente;
            if (!pendente || (e.data && e.data.id !== pendente.id)) return;
            qrWorkerCallbackPendente = null;
            clearTimeout(pendente.timer);
            pendente.resolve({ ok: !!(e.data && e.data.ok) });
        };
        qrWorker.onerror = () => {
            const pendente = qrWorkerCallbackPendente;
            qrWorkerCallbackPendente = null;
            if (pendente) { clearTimeout(pendente.timer); pendente.resolve({ ok: false }); }
        };
    }
    return qrWorker;
}

function lerQrDoCanvas(canvas) {
    return new Promise((resolve) => {
        try {
            const ctx = canvas.getContext('2d');
            const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
            const worker = obterQrWorker();
            const id = ++qrWorkerSeq;
            const timer = setTimeout(() => {
                if (qrWorkerCallbackPendente && qrWorkerCallbackPendente.id === id) {
                    qrWorkerCallbackPendente = null;
                    resolve({ ok: false });
                }
            }, QR_TIMEOUT_WORKER_MS);
            qrWorkerCallbackPendente = { id, resolve, timer };
            worker.postMessage({
                id,
                data: imageData.data,
                width: imageData.width,
                height: imageData.height,
                guia: QR_GUIA,
                orcamentoMs: QR_ORCAMENTO_FRAME_MS,
            }, [imageData.data.buffer]);
        } catch (e) {
            resolve({ ok: false });
        }
    });
}

async function qrOtimizarCamera(stream) {
    try {
        const track = stream && stream.getVideoTracks && stream.getVideoTracks()[0];
        if (!track || typeof track.getCapabilities !== 'function' || typeof track.applyConstraints !== 'function') return;
        const cap = track.getCapabilities() || {};
        for (const chave of ['focusMode', 'exposureMode', 'whiteBalanceMode']) {
            if (!Array.isArray(cap[chave]) || !cap[chave].includes('continuous')) continue;
            try { await track.applyConstraints({ advanced: [{ [chave]: 'continuous' }] }); } catch (e) { }
        }
    } catch (e) { }
}

async function qrFocarAntesDaCaptura(stream) {
    try {
        const track = stream && stream.getVideoTracks && stream.getVideoTracks()[0];
        if (!track || typeof track.getCapabilities !== 'function' || typeof track.applyConstraints !== 'function') return;
        const cap = track.getCapabilities() || {};
        if (Array.isArray(cap.focusMode) && !cap.focusMode.includes('continuous') && cap.focusMode.includes('single-shot')) {
            await track.applyConstraints({ advanced: [{ focusMode: 'single-shot' }] });
            await new Promise(r => setTimeout(r, 250));
        }
    } catch (e) { }
}

async function lerQrEmVariosFrames(capturarFrame, video, stream) {
    await qrFocarAntesDaCaptura(stream);
    for (let i = 0; i < QR_FRAMES_POR_TOQUE; i++) {
        let canvas = null;
        try {
            canvas = capturarFrame();
            const qr = await lerQrDoCanvas(canvas);
            if (qr && qr.ok) return canvas;
        } catch (e) { }
        canvas = null;
        if (i < QR_FRAMES_POR_TOQUE - 1) {
            await new Promise(r => setTimeout(r, QR_INTERVALO_FRAMES_MS));
            if (!video || !video.isConnected) return null;
        }
    }
    return null;
}


const PROCESSAMENTO_TIMEOUT_MS = 125000;
const PROCESSAMENTO_POLL_INTERVAL_MS = 2000;

let pollGeracao = 0;

const TEXTO_LENDO_DOCUMENTO = 'Lendo documento... trazendo os dados.';

function rotuloStatusProcessamento(status) {
    switch (status) {
        case 'ENVIANDO':
        case 'PROCESSANDO_LEITURA':
            return TEXTO_LENDO_DOCUMENTO;
        case 'PROCESSANDO_COMPARACAO': return 'Conferindo dados...';
        case 'CONCLUIDO': return 'Documento validado';
        case 'ERRO':
        case 'INDETERMINADO':
            return 'Não foi possível concluir a validação automática';
        default: return TEXTO_LENDO_DOCUMENTO;
    }
}

function atualizarStatusDocumento(prefixo, doc, status) {
    const alvo = state[prefixo];
    if (!alvo) return;
    alvo[doc + 'UltimoStatus'] = status;
    const handler = alvo[doc + 'AoAtualizar'];
    if (typeof handler === 'function') handler(status);
}

function iniciarProcessamentoDocumento(idAtendimento, tipo, imagemQrBase64, aoAtualizar) {
    const minhaGeracao = pollGeracao;
    return api('documento.php', 'iniciar-processamento', {
        id_atendimento: idAtendimento,
        tipo,
        imagem_qr_base64: imagemQrBase64,
    }).then(resultado => {
        if (typeof aoAtualizar === 'function') aoAtualizar(resultado);
        if (resultado.terminal) return resultado;
        return pollarAteTerminal(idAtendimento, tipo, minhaGeracao, aoAtualizar);
    }).catch(() => ({ ok: false, pode_avancar: false, motivo: 'Não foi possível validar o documento agora', terminal: true }));
}

async function consultarStatusProcessamento(idAtendimento, tipo) {
    try {
        return await api('documento.php', 'status-processamento', { id_atendimento: idAtendimento, tipo }, { timeoutMs: 20000 });
    } catch (e) {
        return { ok: false, pode_avancar: false, motivo: 'Não foi possível consultar o status agora', terminal: false };
    }
}

function resultadoTimeoutProcessamento() {
    return { ok: false, pode_avancar: false, motivo: 'Tempo de validação esgotado', terminal: false, timeout: true };
}

function comTimeout(promise, ms) {
    return Promise.race([
        promise,
        new Promise(resolve => setTimeout(() => resolve(resultadoTimeoutProcessamento()), ms)),
    ]);
}

async function pollarAteTerminal(idAtendimento, tipo, geracao, aoAtualizar) {
    const inicio = performance.now();
    while (performance.now() - inicio < PROCESSAMENTO_TIMEOUT_MS) {
        if (pollGeracao !== geracao) return resultadoTimeoutProcessamento();
        const status = await consultarStatusProcessamento(idAtendimento, tipo);
        if (pollGeracao !== geracao) return resultadoTimeoutProcessamento();
        if (typeof aoAtualizar === 'function') aoAtualizar(status);
        if (status.terminal) return status;
        await new Promise(resolve => setTimeout(resolve, PROCESSAMENTO_POLL_INTERVAL_MS));
    }
    return resultadoTimeoutProcessamento();
}

async function aguardarDocumentos(idAtendimento, cnhPromiseViva, crlvPromiseViva) {
    const resolverCnh = cnhPromiseViva
        ? comTimeout(cnhPromiseViva, PROCESSAMENTO_TIMEOUT_MS)
        : pollarAteTerminal(idAtendimento, 'cnh', pollGeracao);
    const resolverCrlv = crlvPromiseViva
        ? comTimeout(crlvPromiseViva, PROCESSAMENTO_TIMEOUT_MS)
        : pollarAteTerminal(idAtendimento, 'crlv', pollGeracao);

    const [cnh, crlv] = await Promise.all([resolverCnh, resolverCrlv]);
    return { cnh, crlv };
}

const TELA_BACKEND_PARA_FRONT = {
    exp_cnh: 'exp_cnh_qr',
    exp_crlv: 'exp_crlv_qr',
    rec_cnh: 'rec_cnh_qr',
    rec_crlv: 'rec_crlv_qr',
    impressao: telaImpressaoDoTipo,
};
function telaImpressaoDoTipo() {
    return state.tipo === 'recebimento' ? 'rec_impressao' : 'exp_impressao';
}
const TELAS_DOCUMENTOS_VALIDAS = [
    'exp_cnh_qr', 'exp_crlv_qr', 'exp_aguarde_documentos', 'exp_confirma', 'exp_impressao',
    'rec_cnh_qr', 'rec_crlv_qr', 'rec_aguarde_documentos', 'rec_confirma', 'rec_impressao',
];
function resolverTelaDocumentos(proximaTela) {
    const destino = TELA_BACKEND_PARA_FRONT[proximaTela] || proximaTela;
    const tela = typeof destino === 'function' ? destino() : destino;
    return TELAS_DOCUMENTOS_VALIDAS.includes(tela) ? tela : null;
}

const MENSAGENS_REPROVACAO_DOCUMENTO = {
    placa_divergente: 'A placa do documento é diferente da placa informada no atendimento.',
    cnh_vencida: 'A CNH está vencida.',
    documento_ilegivel: 'Não foi possível ler os dados do documento.',
    dados_invalidos: 'Os dados lidos do documento estão incompletos ou inválidos.',
};
const MAX_TENTATIVAS_DOCUMENTO = 3;

function prefixoFluxoDocumentos() {
    return state.tipo === 'recebimento' ? 'rec' : 'exp';
}

function registrarMotivosReprovacao(prefixo, resultado) {
    const est = state[prefixo];
    if (!est || !resultado) return;
    ['cnh', 'crlv'].forEach((doc) => {
        const r = resultado[doc];
        if (!r) return;
        if (r.pode_avancar) { est.motivos[doc] = null; return; }
        if (r.terminal === true && r.status_processamento === 'CONCLUIDO'
            && Object.prototype.hasOwnProperty.call(MENSAGENS_REPROVACAO_DOCUMENTO, r.motivo_usuario)) {
            est.motivos[doc] = r.motivo_usuario;
        }
    });
}

function modalReprovacaoAberto() {
    const fundo = document.getElementById('modalFundo');
    const caixa = document.getElementById('modalCaixa');
    return !!fundo && fundo.classList.contains('aberto') && !!caixa && !!caixa.querySelector('.modal-reprovacao-doc');
}

function abrirModalReprovacaoDocumento(prefixo, doc) {
    const est = state[prefixo];
    const msg = est && MENSAGENS_REPROVACAO_DOCUMENTO[est.motivos[doc]];
    if (!msg || !state.idAtendimento || typeof state.tela !== 'string' || state.tela.indexOf(prefixo + '_') !== 0) return false;
    if (modalReprovacaoAberto()) return true;
    est.reprovacaoAberta = doc;
    const nome = doc === 'cnh' ? 'CNH não aprovada' : 'CRLV não aprovado';
    const podeReescanear = est.qrTentativas[doc] + 1 < MAX_TENTATIVAS_DOCUMENTO;
    abrirModal(`
        <div class="modal-reprovacao-doc" data-doc="${doc}">
            <div class="titulo">${nome}</div>
            <div class="subtitulo">${msg}</div>
            <div class="modal-reprovacao-botoes">
                ${podeReescanear ? `<button type="button" class="btn-primario" onclick="reescanearDocumento('${prefixo}', '${doc}')">Escanear novamente</button>` : ''}
                <button type="button" class="${podeReescanear ? 'btn-fantasma' : 'btn-primario'}" onclick="preencherDocumentoManualmente('${prefixo}', '${doc}')">Preencher manualmente</button>
                <button type="button" class="btn-alerta" onclick="cancelarPelaReprovacao('${prefixo}', '${doc}')">Cancelar atendimento</button>
            </div>
        </div>`);
    document.getElementById('modalCaixa').classList.add('modal-caixa-reprovacao');
    return true;
}

function reescanearDocumento(prefixo, doc) {
    const est = state[prefixo];
    if (!est || est.emAndamento) return;
    est.motivos[doc] = null;
    est.reprovacaoAberta = null;
    est[doc + 'Promise'] = null;
    est[doc + 'UltimoStatus'] = null;
    est[doc + 'AoAtualizar'] = null;
    est.previewImg = null;
    est.previewCanvas = null;
    est.reescaneio[doc] = true;
    const tentativa = ++est.qrTentativas[doc];
    if (tentativa >= MAX_TENTATIVAS_DOCUMENTO) {
        ir(prefixo + '_' + doc + '_manual');
        return;
    }
    ir(prefixo + '_' + doc + '_qr');
}

function preencherDocumentoManualmente(prefixo, doc) {
    const est = state[prefixo];
    if (!est) return;
    est.motivos[doc] = null;
    est.reprovacaoAberta = null;
    ir(prefixo + '_' + doc + '_manual');
}

function cancelarPelaReprovacao(prefixo, doc) {
    abrirModal(`
        <div class="titulo">Cancelar atendimento?</div>
        <div class="subtitulo">Os dados digitados serão perdidos.</div>
        <button class="btn-alerta" onclick="fecharModal(); cancelarESair();">Sim, cancelar</button>
        <button class="btn-fantasma" onclick="abrirModalReprovacaoDocumento('${prefixo}', '${doc}')">Continuar atendimento</button>
    `);
}

function destinoDocumentoPendente(doc, telaManual) {
    const prefixo = prefixoFluxoDocumentos();
    if (state[prefixo].motivos[doc] && abrirModalReprovacaoDocumento(prefixo, doc)) return;
    ir(telaManual);
}

async function tentarAvancarEtapaDocumentos(telaManualCnh, telaManualCrlv) {
    try {
        const avanco = await api('atendimento.php', 'avancar-etapa-documentos', { id_atendimento: state.idAtendimento }, { timeoutMs: 30000 });
        const destino = resolverTelaDocumentos(avanco.proxima_tela);
        if (!destino) {
            mostrarErroTela('Não foi possível avançar agora.');
            return false;
        }
        if (avanco.dados_confirmacao && typeof avanco.dados_confirmacao === 'object') {
            const vindos = {};
            Object.keys(avanco.dados_confirmacao).forEach((k) => {
                if (k !== 'placa' && avanco.dados_confirmacao[k] !== '') vindos[k] = avanco.dados_confirmacao[k];
            });
            state.dados = Object.assign({}, state.dados, vindos);
            if (!state.placa && avanco.dados_confirmacao.placa) state.placa = avanco.dados_confirmacao.placa;
        }
        ir(destino);
        return true;
    } catch (e) {
        const [cnh, crlv] = await Promise.all([
            consultarStatusProcessamento(state.idAtendimento, 'cnh'),
            consultarStatusProcessamento(state.idAtendimento, 'crlv'),
        ]);
        if (!cnh.pode_avancar) { destinoDocumentoPendente('cnh', telaManualCnh); return false; }
        if (!crlv.pode_avancar) { destinoDocumentoPendente('crlv', telaManualCrlv); return false; }
        mostrarErroTela(e.message || 'Não foi possível avançar agora.');
        return false;
    }
}

const ICONES_CONFIRMA = {
    pessoa: '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-8 8-8s8 3.6 8 8"/>',
    caminhao: '<path d="M2 6h11v10H2z"/><path d="M13 9h4l4 4v3h-8z"/><circle cx="7" cy="18" r="2"/><circle cx="17" cy="18" r="2"/>',
    prancheta: '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4h6v3H9z"/><path d="M9 12h6M9 16h6"/>',
    cadeado: '<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
};
function iconeConfirma(nome, classe) {
    return `<svg class="${classe}" viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">${ICONES_CONFIRMA[nome]}</svg>`;
}
function linhaConfirma(rotulo, id, valor, obrigatorio, multilinha, largo) {
    const marca = obrigatorio ? ' <span class="obrig-marca" aria-hidden="true">*</span>' : '';
    const req = obrigatorio ? ' aria-required="true" placeholder="obrigatório"' : '';
    const comum = `class="kb-input conf-valor" id="${id}" autocomplete="off" spellcheck="false"${req}`;
    const ctl = multilinha
        ? `<textarea ${comum} rows="1">${escapeHtml(valor)}</textarea>`
        : `<input ${comum} value="${escapeHtml(valor)}">`;
    return `<div class="conf-campo${largo ? ' conf-campo-largo' : ''}"><label class="conf-rotulo" for="${id}">${rotulo}${marca}</label>${ctl}</div>`;
}
function linhaConfirmaLeitura(rotulo, id, valor, multilinha, largo) {
    const comum = `class="conf-valor conf-leitura" id="${id}" readonly tabindex="-1" aria-readonly="true"`;
    const ctl = multilinha
        ? `<textarea ${comum} rows="1">${escapeHtml(valor)}</textarea>`
        : `<input ${comum} value="${escapeHtml(valor)}">`;
    return `<div class="conf-campo conf-campo-leitura${largo ? ' conf-campo-largo' : ''}"><label class="conf-rotulo" for="${id}">${rotulo} ${iconeConfirma('cadeado', 'conf-cadeado')}</label>${ctl}</div>`;
}
function linhaConfirmaUf(id, valorAtual) {
    const opcoes = UF_LISTA.map((uf) => `<option value="${uf}" ${uf === valorAtual ? 'selected' : ''}>${uf}</option>`).join('');
    return `<div class="conf-campo"><label class="conf-rotulo" for="${id}">UF do CRLV <span class="obrig-marca" aria-hidden="true">*</span></label>
        <select class="kb-input conf-valor conf-select" id="${id}" aria-required="true">
            <option value="">Selecione</option>
            ${opcoes}
        </select></div>`;
}
function cartaoConfirma(titulo, icone, corpo) {
    return `<section class="conf-cartao" aria-label="${titulo}">
        <header class="conf-cartao-topo">${iconeConfirma(icone, 'conf-icone')}<h2 class="conf-cartao-titulo">${titulo}</h2></header>
        <div class="conf-grade">${corpo}</div>
    </section>`;
}
function ajustarAlturaTextarea(el) {
    if (!el || el.tagName !== 'TEXTAREA') return;
    el.style.height = 'auto';
    el.style.height = (el.scrollHeight + (el.offsetHeight - el.clientHeight)) + 'px';
}
function ajustarTextareasConfirma() {
    document.querySelectorAll('textarea.conf-valor').forEach(ajustarAlturaTextarea);
}
function camposObrigatoriosConfirma() {
    return [
        { id: 'confMotorista', rotulo: 'Motorista' },
        { id: 'confCpf', rotulo: 'CPF' },
        { id: 'confCnhValidade', rotulo: 'CNH validade' },
        { id: 'confCrlvAno', rotulo: 'CRLV ano' },
        { id: 'confCrlvUf', rotulo: 'UF do CRLV' },
        { id: 'confCrlvRntc', rotulo: 'RNTRC' },
        { id: 'confCrlvTipoVeiculo', rotulo: 'Tipo de veículo' },
    ];
}
function camposSomenteLeituraConfirma() {
    return [
        { id: 'confPlaca', rotulo: 'Placa' },
        state.tipo === 'expedicao'
            ? { id: 'confOc', rotulo: 'Ordem de coleta' }
            : { id: 'confCliente', rotulo: 'Cliente' },
    ];
}
const ROTULOS_CONFIRMA_ATENDIMENTO = { placa: 'Placa', ordem_coleta: 'Ordem de coleta', cliente: 'Cliente' };
function vaziosSomenteLeituraConfirma() {
    const vazios = [];
    camposSomenteLeituraConfirma().forEach((c) => {
        const el = document.getElementById(c.id);
        if (!el || String(el.value || '').trim() === '') vazios.push(c.rotulo);
    });
    return vazios;
}
function limparMarcasCamposObrigatorios() {
    document.querySelectorAll('.campo-invalido').forEach((el) => el.classList.remove('campo-invalido'));
    document.querySelectorAll('.erro-campo').forEach((el) => el.remove());
}
function validarCamposObrigatoriosConfirma() {
    limparMarcasCamposObrigatorios();
    const vazios = [];
    camposObrigatoriosConfirma().forEach((c) => {
        const el = document.getElementById(c.id);
        if (!el || String(el.value || '').trim() !== '') return;
        vazios.push(c.rotulo);
        el.classList.add('campo-invalido');
        el.setAttribute('aria-invalid', 'true');
        const aviso = document.createElement('span');
        aviso.className = 'erro-campo';
        aviso.textContent = 'Obrigatório';
        el.insertAdjacentElement('afterend', aviso);
    });
    return vazios;
}
let modalConfirmaHtml = '';
function abrirModalConfirmaIncompleta(editaveis, atendimento) {
    const partes = [];
    if (editaveis.length) partes.push(`Preencha os campos obrigatórios: ${escapeHtml(editaveis.join(', '))}.`);
    if (!atendimento.length) { abrirModalNaoPodeContinuar(partes.join(' ')); return; }
    partes.push(`Faltam dados do atendimento: ${escapeHtml(atendimento.join(', '))}. Procure o atendente na portaria.`);
    const voltar = editaveis.length
        ? '<button type="button" class="btn-primario" onclick="voltarEPreencherConfirma()">Voltar e preencher</button>'
        : '<button type="button" class="btn-primario" onclick="fecharModal()">Voltar</button>';
    modalConfirmaHtml = `
        <div class="modal-reprovacao-doc modal-nao-continuar">
            <div class="titulo">Não é possível continuar</div>
            <div class="subtitulo">${partes.join(' ')}</div>
            <div class="modal-reprovacao-botoes">
                ${voltar}
                <button type="button" class="btn-alerta" onclick="cancelarPelaConfirma()">Cancelar atendimento</button>
            </div>
        </div>`;
    reabrirModalConfirmaIncompleta();
}
function reabrirModalConfirmaIncompleta() {
    abrirModal(modalConfirmaHtml);
    document.getElementById('modalCaixa').classList.add('modal-caixa-reprovacao');
}
function cancelarPelaConfirma() {
    abrirModal(`
        <div class="titulo">Cancelar atendimento?</div>
        <div class="subtitulo">Os dados digitados serão perdidos.</div>
        <button class="btn-alerta" onclick="fecharModal(); cancelarESair();">Sim, cancelar</button>
        <button class="btn-fantasma" onclick="reabrirModalConfirmaIncompleta()">Continuar atendimento</button>
    `);
}
function abrirModalNaoPodeContinuar(textoHtml) {
    abrirModal(`
        <div class="modal-reprovacao-doc modal-nao-continuar">
            <div class="titulo">Não é possível continuar</div>
            <div class="subtitulo">${textoHtml}</div>
            <div class="modal-reprovacao-botoes">
                <button type="button" class="btn-primario" onclick="voltarEPreencherConfirma()">Voltar e preencher</button>
            </div>
        </div>`);
    document.getElementById('modalCaixa').classList.add('modal-caixa-reprovacao');
}
function voltarEPreencherConfirma() {
    fecharModal();
    const primeiro = document.querySelector('.campo-invalido');
    if (primeiro) {
        if (typeof primeiro.scrollIntoView === 'function') primeiro.scrollIntoView({ block: 'center' });
        primeiro.focus();
    }
}
function expOrigemLabel(origem) {
    if (origem === 'VIO_TRIAL') return 'VIO_TRIAL';
    if (origem === 'VIO_VALIDADO') return 'VIO_VALIDADO';
    if (origem === 'VIO_API_BR') return 'VIO_API_BR';
    if (origem === 'VIO_CACHE') return 'VIO_CACHE';
    if (origem === 'MANUAL') return 'MANUAL — pendente de revisão';
    return 'Não validado';
}
function telaConfirma(tipoTexto) {
    const d = state.dados || {};
    const expedicao = state.tipo === 'expedicao';
    const atendimento = expedicao
        ? linhaConfirmaLeitura('Ordem de coleta', 'confOc', d.numero)
        : linhaConfirmaLeitura('Cliente', 'confCliente', d.cliente_nome, true, true);
    const origensExpedicao = expedicao ? `
        <div class="status-scanner conf-origem">Origem da validação — CNH: ${escapeHtml(expOrigemLabel(state.exp.cnhOrigem))} · CRLV: ${escapeHtml(expOrigemLabel(state.exp.crlvOrigem))}</div>
    ` : '';
    const tipoCap = tipoTexto.charAt(0).toUpperCase() + tipoTexto.slice(1);
    return `<div class="conf-pagina">
        <div class="conf-cabecalho">
            <h1 class="conf-titulo">Confirme os dados</h1>
            <div class="conf-tipo">${tipoCap}</div>
        </div>
        ${cartaoConfirma('Motorista', 'pessoa',
            linhaConfirma('Nome do motorista', 'confMotorista', d.motorista_nome, true, true, true)
            + linhaConfirma('CPF', 'confCpf', d.motorista_cpf, true)
            + linhaConfirma('Validade da CNH', 'confCnhValidade', d.cnh_validade, true))}
        ${cartaoConfirma('Veículo', 'caminhao',
            linhaConfirmaLeitura('Placa', 'confPlaca', state.placa)
            + linhaConfirmaUf('confCrlvUf', d.crlv_uf || '')
            + linhaConfirma('Ano do CRLV', 'confCrlvAno', d.crlv_ano, true)
            + linhaConfirma('RNTRC', 'confCrlvRntc', d.crlv_rntc, true)
            + linhaConfirma('Tipo de veículo', 'confCrlvTipoVeiculo', d.crlv_tipo_veiculo, true, true, true))}
        ${cartaoConfirma('Atendimento', 'prancheta', atendimento)}
        ${origensExpedicao}
        <div class="conf-rodape">
            <button class="btn-primario conf-confirmar" onclick="confirmarDados()">Confirmar dados</button>
        </div>
    </div>`;
}
async function confirmarDados() {
    const vazios = validarCamposObrigatoriosConfirma();
    const faltaAtendimento = vaziosSomenteLeituraConfirma();
    if (vazios.length || faltaAtendimento.length) {
        abrirModalConfirmaIncompleta(vazios, faltaAtendimento);
        return;
    }
    const dados = {
        motorista_nome: document.getElementById('confMotorista').value,
        motorista_cpf: document.getElementById('confCpf').value,
        cnh_validade: document.getElementById('confCnhValidade').value,
        crlv_ano: document.getElementById('confCrlvAno').value,
        crlv_uf: document.getElementById('confCrlvUf').value,
        crlv_rntc: document.getElementById('confCrlvRntc').value,
        crlv_tipo_veiculo: document.getElementById('confCrlvTipoVeiculo').value,
    };
    try {
        await api('atendimento.php', 'salvar-etapa', { id_atendimento: state.idAtendimento, etapa: 'confirmacao', dados });
        ir(state.tipo === 'expedicao' ? 'exp_ajudante' : 'rec_ajudante');
    } catch (e) {
        const dadosErro = e && e.dados;
        if (e.status === 422 && e.codigo === 'CONFIRMACAO_INCOMPLETA' && dadosErro && Array.isArray(dadosErro.campos) && dadosErro.campos.length) {
            const rotulosSrv = Array.isArray(dadosErro.rotulos) ? dadosErro.rotulos : [];
            const editaveis = [];
            const atendimento = [];
            dadosErro.campos.forEach((campo, i) => {
                const rot = typeof rotulosSrv[i] === 'string' && rotulosSrv[i].trim() !== '' ? rotulosSrv[i].trim() : String(campo);
                if (Object.prototype.hasOwnProperty.call(ROTULOS_CONFIRMA_ATENDIMENTO, campo)) atendimento.push(ROTULOS_CONFIRMA_ATENDIMENTO[campo]);
                else editaveis.push(rot);
            });
            abrirModalConfirmaIncompleta(editaveis, atendimento);
            return;
        }
        if (e.status >= 400 && e.status < 500 && /^Nao e possivel continuar/i.test(String(e.message || ''))) {
            abrirModalNaoPodeContinuar(escapeHtml(e.message));
            return;
        }
        mostrarErroTela(e.message);
    }
}

function telaAjudante() {
    const corr = !!state.ajudanteCorrecao;
    const aj = state.ajudante || {};
    const blocoCorrecao = corr
        ? `<div class="impr-motivo-api" id="ajudanteErro" role="alert" style="display:none"></div>
        <div class="grupo-botoes" style="margin-top:16px">
            <button class="btn-fantasma impr-btn-alvo" id="ajudanteBtnVoltar" onclick="voltarDaCorrecaoAjudante()">Voltar</button>
            <button class="btn-alerta impr-btn-alvo" id="ajudanteBtnNovo" style="display:none" onclick="novoAtendimento()">Novo atendimento</button>
        </div>`
        : '';
    return `<div id="ajudantePergunta">
            <div class="titulo">Possui ajudante?</div>
            <div class="grupo-botoes-linha">
                <button class="btn-primario${corr ? ' impr-btn-alvo' : ''}" onclick="mostrarCamposAjudante()">Sim</button>
                <button class="btn-fantasma${corr ? ' impr-btn-alvo' : ''}" onclick="finalizarSemAjudante()">Não</button>
            </div>
        </div>
        <div id="ajudanteCampos" style="display:none">
            <div class="subtitulo">Dados do ajudante</div>
            <div class="grade-campos">
                ${campo('Nome completo', 'ajudanteNome', corr ? (aj.nome || '') : '')}
                ${campo('CPF', 'ajudanteCpf', corr ? (aj.cpf || '') : '')}
            </div>
            <button class="btn-primario${corr ? ' impr-btn-alvo' : ''}" id="ajudanteBtnConfirmar" style="max-width:320px;margin:16px auto 0" onclick="confirmarAjudante()">Confirmar</button>
        </div>
        ${blocoCorrecao}`;
}
function mostrarCamposAjudante() {
    document.getElementById('ajudantePergunta').style.display = 'none';
    document.getElementById('ajudanteCampos').style.display = 'block';
}
function finalizarSemAjudante() { salvarAjudanteEAvancar(null, null); }
function confirmarAjudante() {
    const nome = document.getElementById('ajudanteNome').value.trim();
    const cpf = document.getElementById('ajudanteCpf').value.trim();
    salvarAjudanteEAvancar(nome, cpf);
}
let ajudanteSalvando = false;
function mostrarErroAjudante(msg) {
    const el = document.getElementById('ajudanteErro');
    if (!el) { mostrarErroTela(msg); return; }
    el.textContent = msg;
    el.style.display = 'block';
}
async function salvarAjudanteEAvancar(nome, cpf) {
    if (ajudanteSalvando) return;
    ajudanteSalvando = true;
    const correcao = !!state.ajudanteCorrecao;
    try {
        await api('atendimento.php', 'salvar-etapa', { id_atendimento: state.idAtendimento, etapa: 'ajudante', dados: { nome, cpf } });
        state.ajudante = { nome: nome || '', cpf: cpf || '' };
        state.ajudanteCorrecao = false;
        ir(state.tipo === 'expedicao' ? 'exp_impressao' : 'rec_impressao');
    } catch (e) {
        if (!correcao) { mostrarErroTela(e.message); return; }
        mostrarErroAjudante(e.message);
        if (e.status === 409) {
            document.getElementById('ajudantePergunta').style.display = 'none';
            document.getElementById('ajudanteCampos').style.display = 'none';
            document.getElementById('ajudanteBtnNovo').style.display = '';
        }
    } finally { ajudanteSalvando = false; }
}
function voltarDaCorrecaoAjudante() {
    state.ajudanteCorrecao = false;
    imprState.retornoSemProcessar = true;
    ir(state.tipo === 'expedicao' ? 'exp_impressao' : 'rec_impressao');
}


function telaRecPlacaQtd() {
    return `<div class="titulo">Digite a placa do veículo</div>
        <input class="campo-texto kb-input" id="inputPlacaRec" placeholder="AAA-0A00">
        <div class="subtitulo">Quantas notas fiscais você possui?</div>
        <div class="grupo-botoes">
            <button class="btn-primario" onclick="iniciarRecebimento(false)">5 ou menos</button>
            <button class="btn-fantasma" onclick="iniciarRecebimento(true)">Mais de 5</button>
        </div>`;
}
async function iniciarRecebimento(excedeLimite) {
    const placa = document.getElementById('inputPlacaRec').value.trim();
    if (!placa) return mostrarErroTela('Digite a placa');
    state.placa = placa;
    let dados;
    try {
        dados = await api('atendimento.php', 'iniciar', { tipo: 'recebimento', placa, token_aceite: state.lgpdTokenAceite });
        state.lgpdTokenAceite = null;
    } catch (e) {
        if (e.status === 409) { voltarParaLgpdPorAceiteExpirado(e.message); return; }
        mostrarErroTela(e.message);
        return;
    }
    try {
        state.idAtendimento = dados.id_atendimento;
        if (excedeLimite) {
            await api('atendimento.php', 'bloquear-excesso-notas', { id_atendimento: state.idAtendimento });
            ir('rec_bloqueado');
        } else {
            state.notaUidSeq = 0;
            state.notasNumeros = [];
            state.previewNotaAtual = null;
            state.capturaNotaEmAndamento = false;
            state.finalizandoDigitalizacao = false;
            state.excluindoNota = false;
            atendimentoGeracao++;
            suspensaoInicioMs = 0;
            ocrFila = [];
            numeroModalAberta = false;
            numeroModalOverrideUid = null;
            avisoCapturaVazia = false;
            resetarControleNotasServidor();
            limparFaixaExclusaoNota();
            medirLimpar();
            await api('atendimento.php', 'salvar-etapa', { id_atendimento: state.idAtendimento, etapa: 'digitalizacao_notas' });
            ir('rec_digitaliza');
        }
    } catch (e) { mostrarErroTela(e.message); }
}
function telaBloqueado() {
    return `<div class="titulo" style="color:#a32d2d">Mais de 5 notas fiscais</div>
        <div class="subtitulo">Esse atendimento precisa ser concluído no balcão da portaria. Dirija-se ao atendente.</div>
        <button class="btn-alerta" style="max-width:320px;margin:0 auto" onclick="novoAtendimento()">Voltar ao início</button>`;
}


let avisoCapturaVazia = false;

const NOTAS_LIMITE = 5;

function buscarNotaPorUid(uid) {
    return (state.notasNumeros || []).find(n => n.uid === uid) || null;
}
function posicaoNotaPorUid(uid) {
    return (state.notasNumeros || []).findIndex(n => n.uid === uid) + 1;
}

function identificadorNotaApi(nota) {
    if (!nota) return null;
    if (Number.isInteger(nota.idNota) && nota.idNota > 0) return { id_nota: nota.idNota };
    if (Number.isInteger(nota.ordem) && nota.ordem > 0) return { ordem: nota.ordem };
    return null;
}

function renderMiniaturasNotas() {
    return (state.notasNumeros || []).map(n => n.imagem
        ? `<div class="miniatura"><img src="${n.imagem}" alt="Nota digitalizada"></div>`
        : '<div class="miniatura miniatura-sem-foto" title="Foto indisponível" role="img" aria-label="Foto indisponível"></div>'
    ).join('');
}

const NOTA_CLIENT_UID_REGEX = /^[A-Za-z0-9_-]{8,64}$/;
const NOTA_UPLOAD_TIMEOUT_MS = 90000;
const NOTA_LISTAR_TIMEOUT_MS = 20000;

let clientUidsUsados = new Set();
let idNotasExcluidas = new Set();
let uploadPendente = null;
let avisoOcrEmAndamento = false;
let reconciliacaoEmCurso = null;
let digitalizacaoConcluida = false;

function resetarControleNotasServidor() {
    clientUidsUsados = new Set();
    idNotasExcluidas = new Set();
    uploadPendente = null;
    avisoOcrEmAndamento = false;
    reconciliacaoEmCurso = null;
    digitalizacaoConcluida = false;
}

function gerarClientUidNota() {
    for (let i = 0; i < 8; i++) {
        let uid = '';
        try {
            const c = (typeof crypto !== 'undefined') ? crypto : null;
            if (c && typeof c.randomUUID === 'function') {
                uid = c.randomUUID();
            } else if (c && typeof c.getRandomValues === 'function') {
                const b = new Uint8Array(16);
                c.getRandomValues(b);
                uid = Array.from(b, x => x.toString(16).padStart(2, '0')).join('');
            }
        } catch (e) { uid = ''; }
        if (!uid) {
            uid = 'n' + Date.now().toString(36) + Math.random().toString(36).slice(2, 12) + Math.random().toString(36).slice(2, 12) + (++clientUidFallbackSeq).toString(36);
        }
        if (NOTA_CLIENT_UID_REGEX.test(uid) && !clientUidsUsados.has(uid)) {
            clientUidsUsados.add(uid);
            return uid;
        }
    }
    throw new Error('uid indisponivel');
}
let clientUidFallbackSeq = 0;

function novaNotaLocal(campos) {
    return Object.assign({
        uid: ++state.notaUidSeq,
        clientUid: null,
        ordem: null,
        idNota: null,
        imagem: null,
        numero: null, confirmado: false, origem: null,
        estado: 'pendente', sugestao: null, sugestaoOcr: null,
        ocrConcluido: false, manualOverride: false, destaque: false,
        aguardando: false,
        recuperada: false,
    }, campos);
}

function notasListarValidas(dados) {
    const bruto = dados && Array.isArray(dados.notas) ? dados.notas : null;
    if (!bruto) return null;
    const itens = [];
    bruto.forEach(x => {
        if (!x || !Number.isInteger(x.id_nota) || x.id_nota <= 0) return;
        if (!Number.isInteger(x.ordem) || x.ordem < 1 || x.ordem > NOTAS_LIMITE) return;
        if (x.uid !== null && x.uid !== undefined && !(typeof x.uid === 'string' && NOTA_CLIENT_UID_REGEX.test(x.uid))) return;
        if (itens.some(i => i.id_nota === x.id_nota)) return;
        itens.push({ id_nota: x.id_nota, ordem: x.ordem, uid: typeof x.uid === 'string' ? x.uid : null, numero_definido: x.numero_definido === true });
    });
    return itens;
}

function reconciliarNotasComServidor(pendente) {
    if (reconciliacaoEmCurso) return reconciliacaoEmCurso;
    const geracao = atendimentoGeracao;
    const idAtendimentoCapturado = state.idAtendimento;
    if (idAtendimentoCapturado == null) return Promise.resolve(false);
    reconciliacaoEmCurso = (async () => {
        try {
            const dados = await api('nota.php', 'listar', { id_atendimento: idAtendimentoCapturado }, { timeoutMs: NOTA_LISTAR_TIMEOUT_MS });
            if (geracao !== atendimentoGeracao || idAtendimentoCapturado !== state.idAtendimento) return false;
            const itens = notasListarValidas(dados);
            if (!itens) return false;
            itens.sort((a, b) => a.ordem - b.ordem);
            let mudou = false;
            itens.forEach(srv => {
                if (idNotasExcluidas.has(srv.id_nota)) return;
                let local = state.notasNumeros.find(n => n.idNota === srv.id_nota)
                    || (srv.uid ? state.notasNumeros.find(n => n.clientUid === srv.uid) : null);
                if (local) {
                    if (local.idNota == null) { local.idNota = srv.id_nota; mudou = true; }
                    if (local.idNota === srv.id_nota && local.ordem !== srv.ordem) { local.ordem = srv.ordem; mudou = true; }
                    return;
                }
                if (state.notasNumeros.length >= NOTAS_LIMITE) return;
                const doPendente = !!(pendente && srv.uid && pendente.clientUid === srv.uid && pendente.imagem);
                const nota = novaNotaLocal({
                    clientUid: srv.uid, ordem: srv.ordem, idNota: srv.id_nota,
                    imagem: doPendente ? pendente.imagem : null,
                    recuperada: !doPendente,
                });
                if (!doPendente) { nota.estado = 'sem_sugestao'; nota.ocrConcluido = true; }
                state.notasNumeros.push(nota);
                mudou = true;
                if (doPendente) {
                    processarOcrNota(nota.imagem, nota.uid, 0);
                } else {
                    const ident = identificarClienteNota(nota.uid, [], null, idAtendimentoCapturado, geracao, 0);
                    if (ident && typeof ident.catch === 'function') ident.catch(() => {});
                }
            });
            if (mudou) {
                atualizarIndicadorNumerosNota();
                atualizarRevisaoNumeros();
            }
            return true;
        } catch (e) {
            return false;
        } finally {
            reconciliacaoEmCurso = null;
        }
    })();
    return reconciliacaoEmCurso;
}

async function resolverUploadPendente() {
    const pend = uploadPendente;
    if (!pend) return;
    if (pend.geracao !== atendimentoGeracao || pend.idAtendimento !== state.idAtendimento) { uploadPendente = null; return; }
    const ok = await reconciliarNotasComServidor(pend);
    if (ok && uploadPendente === pend) uploadPendente = null;
}

function textoIndicadorNotas() {
    const total = (state.notasNumeros || []).length;
    if (total > 0) return `Notas capturadas: ${total}`;
    return avisoCapturaVazia ? 'Nenhuma nota. Capture a primeira nota.' : '';
}

function telaDigitaliza() {
    return `<div class="subtitulo">Notas digitalizadas: <span id="contadorNotas">${(state.notasNumeros || []).length}</span> de ${NOTAS_LIMITE}</div>
        <div class="caixa-scanner" id="caixaScanner">
            <video id="videoScanner" autoplay playsinline></video>
            <div class="guia-scanner"></div>
        </div>
        <img id="previaNota" class="previa-nota" style="display:none" alt="Nota capturada">
        <div class="status-scanner" id="scannerStatus">Conectando ao scanner...</div>
        <div class="miniaturas" id="miniaturas">${renderMiniaturasNotas()}</div>
        <div class="subtitulo" id="indicadorNumerosNota" role="status">${escapeHtml(textoIndicadorNotas())}</div>
        <div class="grupo-botoes" id="controlesScanner">
            <button class="btn-fantasma" id="btnCapturarNota" onclick="capturarPreviaNota()" disabled>Capturar nota</button>
        </div>
        <button class="btn-primario" id="btnFinalizarDigitalizacao" style="max-width:320px;margin:0 auto" onclick="finalizarDigitalizacao()"${(state.notasNumeros || []).length === 0 ? ' disabled' : ''}>Finalizar digitalização</button>`;
}

function atualizarBotaoFinalizarDigitalizacao() {
    const btnFinalizar = document.getElementById('btnFinalizarDigitalizacao');
    if (btnFinalizar) {
        btnFinalizar.disabled = (state.notasNumeros || []).length === 0
            || !!state.capturaNotaEmAndamento || !!state.finalizandoDigitalizacao;
    }
}

function atualizarIndicadorNumerosNota() {
    const indicador = document.getElementById('indicadorNumerosNota');
    if (indicador) indicador.textContent = textoIndicadorNotas();
    const miniaturas = document.getElementById('miniaturas');
    if (miniaturas) miniaturas.innerHTML = renderMiniaturasNotas();
    atualizarBotaoFinalizarDigitalizacao();
}

function telaRevisaoNumeros() {
    return `<div class="rev-wrap">
        <div class="titulo">Confira as notas</div>
        <p class="rev-instrucao">Toque em cada nota para conferir o número. Se estiver certo, toque em Confirmar. Se estiver errado, corrija. Quando todas estiverem confirmadas, toque em Continuar.</p>
        <div class="rev-faixa" id="revisaoFaixa" role="status" style="display:none"></div>
        <div class="rev-leitura" id="revisaoLeitura" role="status" style="display:none"></div>
        <div class="rev-lista" id="revisaoLista"></div>
        <button type="button" class="rev-adicionar" id="btnAdicionarNota" style="display:none" onclick="adicionarOutraNota()">Adicionar outra nota</button>
        <div class="rev-rodape">
            <div class="rev-motivo" id="revisaoMotivo" role="status"></div>
            <div class="rev-aguarde" id="revisaoAguarde" role="status" style="display:none"></div>
            <div class="rev-erro" id="revisaoErro" role="alert" style="display:none"></div>
            <button type="button" class="rev-continuar" id="btnContinuarRevisao" disabled onclick="concluirDigitalizacaoRevisao()">Continuar</button>
        </div>
    </div>`;
}

const REV_ICONES = {
    pendente: '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
    sugerido: '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="10.5" cy="10.5" r="6.5"/><path d="M15.5 15.5L21 21"/></svg>',
    sem_sugestao: '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3L22 20H2z"/><path d="M12 10v4M12 17.2v.3"/></svg>',
    confirmado: '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12.5l5 5L20 6.5"/></svg>'
};

function criarCartaoNota(nota) {
    const cartao = document.createElement('button');
    cartao.type = 'button';
    cartao.className = 'rev-cartao';
    cartao.id = 'cartaoNota' + nota.uid;
    cartao.dataset.uid = String(nota.uid);
    cartao._imagem = nota.imagem;
    const uid = nota.uid;
    cartao.addEventListener('click', () => tocarCartaoNota(uid));
    let img;
    if (nota.imagem) {
        img = document.createElement('img');
        img.className = 'rev-miniatura';
        img.src = nota.imagem;
    } else {
        img = document.createElement('div');
        img.className = 'rev-miniatura rev-sem-foto';
        img.textContent = 'Foto indisponível';
    }
    const textos = document.createElement('div');
    textos.className = 'rev-textos';
    const rotulo = document.createElement('div');
    rotulo.className = 'rev-rotulo';
    const numero = document.createElement('div');
    numero.className = 'rev-numero';
    const selo = document.createElement('span');
    selo.className = 'rev-selo';
    const seloIcone = document.createElement('span');
    seloIcone.className = 'rev-selo-icone';
    const seloTexto = document.createElement('span');
    seloTexto.className = 'rev-selo-texto';
    selo.appendChild(seloIcone);
    selo.appendChild(seloTexto);
    textos.appendChild(rotulo);
    textos.appendChild(numero);
    textos.appendChild(selo);
    cartao.appendChild(img);
    cartao.appendChild(textos);
    return cartao;
}

function preencherCartaoNota(cartao, nota, posicao, total) {
    const estado = nota.estado || 'pendente';
    const destacar = !!nota.destaque && !nota.confirmado;
    const rotuloEl = cartao.querySelector('.rev-rotulo');
    const numero = cartao.querySelector('.rev-numero');
    const selo = cartao.querySelector('.rev-selo');
    const seloIcone = cartao.querySelector('.rev-selo-icone');
    const seloTexto = cartao.querySelector('.rev-selo-texto');
    const rotulo = 'Nota ' + posicao + ' de ' + total;
    rotuloEl.textContent = rotulo;
    const miniatura = cartao.querySelector('.rev-miniatura');
    if (miniatura) {
        if (miniatura.tagName === 'IMG') miniatura.alt = rotulo; else miniatura.setAttribute('aria-label', rotulo + ': foto indisponível');
    }
    let textoNumero;
    let grande = false;
    let textoSelo;
    if (estado === 'confirmado') { textoNumero = nota.numero; grande = true; textoSelo = 'Confirmado'; }
    else if (estado === 'sugerido') { textoNumero = nota.sugestao; grande = true; textoSelo = 'Nº ' + nota.sugestao; }
    else if (estado === 'sem_sugestao') { textoNumero = 'Não consegui ler o número'; textoSelo = 'Conferir'; }
    else { textoNumero = 'Toque para digitar o número'; textoSelo = 'Lendo...'; }
    numero.textContent = textoNumero;
    numero.className = 'rev-numero' + (grande ? ' rev-numero-grande' : '');
    if (destacar) {
        selo.className = 'rev-selo rev-selo-destaque';
        cartao.className = 'rev-cartao rev-cartao-' + estado + ' rev-cartao-destaque';
        seloIcone.innerHTML = REV_ICONES.sem_sugestao;
        seloTexto.textContent = 'Falta o número';
    } else if (nota.aguardando) {
        selo.className = 'rev-selo rev-selo-aguardando';
        cartao.className = 'rev-cartao rev-cartao-' + estado + ' rev-cartao-aguardando';
        seloIcone.innerHTML = REV_ICONES.pendente;
        seloTexto.textContent = 'Leitura em andamento';
    } else {
        selo.className = 'rev-selo rev-selo-' + estado;
        cartao.className = 'rev-cartao rev-cartao-' + estado;
        seloIcone.innerHTML = REV_ICONES[estado] || REV_ICONES.pendente;
        seloTexto.textContent = textoSelo;
    }
}

function juntarPosicoesNotas(posicoes) {
    if (posicoes.length <= 1) return String(posicoes[0]);
    return posicoes.slice(0, -1).join(', ') + ' e ' + posicoes[posicoes.length - 1];
}

function mensagemNotasDestacadas() {
    const posicoes = [];
    (state.notasNumeros || []).forEach((n, i) => { if (n.destaque && !n.confirmado) posicoes.push(i + 1); });
    if (posicoes.length === 0) return '';
    if (posicoes.length === 1) return 'Falta o número da Nota ' + posicoes[0] + '. Toque na nota para digitar.';
    return 'Faltam os números das Notas ' + juntarPosicoesNotas(posicoes) + '. Toque em cada nota para digitar.';
}

function atualizarRevisaoNumeros(msgErro) {
    if (state.tela !== 'rec_revisao_numeros') return;
    const lista = document.getElementById('revisaoLista');
    if (!lista) return;
    const notas = state.notasNumeros || [];
    const vivos = new Set(notas.map(n => String(n.uid)));
    Array.from(lista.children).forEach(filho => {
        if (!vivos.has(filho.dataset.uid)) filho.remove();
    });
    notas.forEach((n, i) => {
        let cartao = document.getElementById('cartaoNota' + n.uid);
        if (cartao && cartao._imagem !== n.imagem) { cartao.remove(); cartao = null; }
        if (!cartao) cartao = criarCartaoNota(n);
        if (lista.children[i] !== cartao) lista.insertBefore(cartao, lista.children[i] || null);
        preencherCartaoNota(cartao, n, i + 1, notas.length);
    });
    const lendo = notas.filter(n => n.estado === 'pendente').length;
    const falta = notas.filter(n => !n.confirmado).length;
    const elLeitura = document.getElementById('revisaoLeitura');
    if (elLeitura) {
        elLeitura.textContent = lendo > 0 ? (lendo === 1 ? 'Lendo 1 nota...' : 'Lendo ' + lendo + ' notas...') : '';
        elLeitura.style.display = lendo > 0 ? '' : 'none';
    }
    const elMotivo = document.getElementById('revisaoMotivo');
    if (elMotivo) {
        elMotivo.textContent = falta > 0 ? (falta === 1 ? 'Falta conferir 1 nota.' : 'Faltam conferir ' + falta + ' notas.') : 'Todas as notas conferidas.';
    }
    const elAguarde = document.getElementById('revisaoAguarde');
    if (elAguarde) {
        elAguarde.textContent = avisoOcrEmAndamento ? MSG_OCR_EM_ANDAMENTO : '';
        elAguarde.style.display = avisoOcrEmAndamento ? '' : 'none';
    }
    const textoErro = msgErro || mensagemNotasDestacadas();
    const elErro = document.getElementById('revisaoErro');
    if (elErro) {
        elErro.textContent = textoErro;
        elErro.style.display = textoErro ? '' : 'none';
    }
    const btnAdicionar = document.getElementById('btnAdicionarNota');
    if (btnAdicionar) {
        btnAdicionar.style.display = notas.length > 0 && notas.length < NOTAS_LIMITE ? '' : 'none';
        btnAdicionar.disabled = !!state.excluindoNota || !!state.finalizandoDigitalizacao;
    }
    const btn = document.getElementById('btnContinuarRevisao');
    if (btn) btn.disabled = falta > 0 || notas.length === 0 || !!state.finalizandoDigitalizacao || !!state.excluindoNota;
}

function adicionarOutraNota() {
    if (numeroModalAberta || state.excluindoNota || state.finalizandoDigitalizacao) return;
    if ((state.notasNumeros || []).length >= NOTAS_LIMITE) return;
    avisoCapturaVazia = false;
    ir('rec_digitaliza');
}

let faixaExclusaoTimer = null;
function limparFaixaExclusaoNota() {
    clearTimeout(faixaExclusaoTimer);
    faixaExclusaoTimer = null;
}
function mostrarFaixaExclusaoNota(posicao) {
    limparFaixaExclusaoNota();
    const faixa = document.getElementById('revisaoFaixa');
    if (!faixa) return;
    faixa.textContent = 'Nota ' + posicao + ' excluída.';
    faixa.style.display = '';
    faixaExclusaoTimer = setTimeout(() => {
        faixaExclusaoTimer = null;
        const el = document.getElementById('revisaoFaixa');
        if (el) { el.textContent = ''; el.style.display = 'none'; }
    }, 5000);
}


let scannerStreamAtual = null;
let scannerDeviceIdAtual = null;
let scannerVideoPronto = false;
let scannerDeviceChangeHandlerAtivo = false;
let scannerVideoElementoAtual = null;
let scannerResizeHandlerAtual = null;
let scannerPollingIntervalId = null;
let scannerUltimaLarguraAplicada = null;
let scannerUltimaAlturaAplicada = null;
let scannerResizeObserver = null;
let scannerFrameId = null;
let scannerVideoLarguraAtual = null;
let scannerVideoAlturaAtual = null;
let scannerUltimaLarguraContainer = null;
let scannerUltimaAlturaCalculada = null;
let scannerVideoRevelado = false;
let scannerRevelarFrameId = null;

function mostrarStatusScanner(msg, isErro) {
    const el = document.getElementById('scannerStatus');
    if (!el) return;
    el.textContent = msg;
    el.classList.toggle('erro', !!isErro);
    el.classList.toggle('ok', !isErro && (msg === 'Scanner pronto' || msg === 'Documento salvo'));
}

function ajustarProporcaoScanner(largura, altura) {
    if (!largura || !altura) return;
    scannerVideoLarguraAtual = largura;
    scannerVideoAlturaAtual = altura;
    agendarRecalculoAlturaScanner();
}

function agendarRecalculoAlturaScanner() {
    if (scannerFrameId) {
        cancelAnimationFrame(scannerFrameId);
    }
    scannerFrameId = requestAnimationFrame(() => {
        scannerFrameId = null;
        aplicarAlturaScanner();
    });
}

function aplicarAlturaScanner() {
    if (!scannerVideoLarguraAtual || !scannerVideoAlturaAtual) return;
    const caixa = document.getElementById('caixaScanner');
    const previa = document.getElementById('previaNota');
    const referencia = caixa || previa;
    if (!referencia) return;
    const larguraContainer = referencia.clientWidth;
    if (!larguraContainer) return;
    const alturaCalculada = Math.round(larguraContainer * scannerVideoAlturaAtual / scannerVideoLarguraAtual);
    if (larguraContainer === scannerUltimaLarguraContainer && alturaCalculada === scannerUltimaAlturaCalculada) return;
    scannerUltimaLarguraContainer = larguraContainer;
    scannerUltimaAlturaCalculada = alturaCalculada;
    if (caixa) caixa.style.height = alturaCalculada + 'px';
    if (previa) previa.style.height = alturaCalculada + 'px';
}

function revelarVideoScannerQuandoPronto() {
    if (scannerVideoRevelado || scannerRevelarFrameId) return;
    aplicarAlturaScanner();
    scannerRevelarFrameId = requestAnimationFrame(() => {
        scannerRevelarFrameId = null;
        if (scannerVideoRevelado) return;
        scannerVideoRevelado = true;
        const video = document.getElementById('videoScanner');
        if (video) video.style.visibility = 'visible';
        mostrarStatusScanner('Scanner pronto');
    });
}

function atualizarBotaoCaptura() {
    const btn = document.getElementById('btnCapturarNota');
    if (!btn) return;
    const video = document.getElementById('videoScanner');
    const prontoVideo = !!video && video.readyState >= 2 && video.videoWidth > 0 && video.videoHeight > 0;
    btn.disabled = !prontoVideo || !scannerVideoPronto || (state.notasNumeros || []).length >= NOTAS_LIMITE;
}

async function iniciarCameraScanner() {
    iniciarOcrWorker();
    scannerVideoPronto = false;
    if (!window.isSecureContext) {
        mostrarStatusScanner('Esta página precisa ser aberta via HTTPS ou localhost para acessar a câmera.', true);
        return;
    }
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !navigator.mediaDevices.enumerateDevices) {
        mostrarStatusScanner('Navegador sem suporte a câmera.', true);
        return;
    }
    mostrarStatusScanner('Conectando ao scanner...');
    try {
        const permStream = await navigator.mediaDevices.getUserMedia({ video: true });
        permStream.getTracks().forEach(t => t.stop());
        const devices = await navigator.mediaDevices.enumerateDevices();
        const cams = devices.filter(d => d.kind === 'videoinput');
        if (cams.length === 0) {
            mostrarStatusScanner('Scanner não encontrado. Verifique a conexão USB.', true);
            return;
        }
        let deviceId = localStorage.getItem('totem_scanner_deviceId');
        if (deviceId && !cams.some(c => c.deviceId === deviceId)) deviceId = null;
        if (!deviceId) {
            if (cams.length === 1) {
                deviceId = cams[0].deviceId;
            } else {
                abrirSelecaoScanner(cams);
                return;
            }
        }
        await abrirStreamScanner(deviceId);
    } catch (e) {
        tratarErroScanner(e);
    }
}

function abrirSelecaoScanner(cams) {
    mostrarStatusScanner('Selecione o scanner');
    const botoes = cams.map((c, i) =>
        `<button class="btn-fantasma" data-device-id="${escapeHtml(c.deviceId)}">${escapeHtml(c.label || ('Câmera ' + (i + 1)))}</button>`
    ).join('');
    abrirModal(`<div class="titulo">Selecione o scanner</div><div class="grupo-botoes">${botoes}</div>`);
    document.querySelectorAll('#modalCaixa [data-device-id]').forEach(btn => {
        btn.addEventListener('click', () => {
            const deviceId = btn.dataset.deviceId;
            localStorage.setItem('totem_scanner_deviceId', deviceId);
            fecharModal();
            abrirStreamScanner(deviceId);
        });
    });
}

const SCANNER_RESOLUCAO_IDEAL_LARGURA = 4096;
const SCANNER_RESOLUCAO_IDEAL_ALTURA = 3072;

async function abrirStreamScanner(deviceId) {
    mostrarStatusScanner('Conectando ao scanner...');
    try {
        const stream = await navigator.mediaDevices.getUserMedia({
            video: {
                deviceId: { exact: deviceId },
                width: { ideal: SCANNER_RESOLUCAO_IDEAL_LARGURA },
                height: { ideal: SCANNER_RESOLUCAO_IDEAL_ALTURA }
            }
        });
        if (scannerPollingIntervalId) {
            clearInterval(scannerPollingIntervalId);
            scannerPollingIntervalId = null;
        }
        if (scannerResizeObserver) {
            scannerResizeObserver.disconnect();
            scannerResizeObserver = null;
        }
        if (scannerFrameId) {
            cancelAnimationFrame(scannerFrameId);
            scannerFrameId = null;
        }
        scannerStreamAtual = stream;
        scannerDeviceIdAtual = deviceId;
        const video = document.getElementById('videoScanner');
        if (!video) { stream.getTracks().forEach(t => t.stop()); scannerStreamAtual = null; return; }
        video.srcObject = stream;
        video.style.visibility = 'hidden';
        scannerVideoRevelado = false;
        if (scannerRevelarFrameId) {
            cancelAnimationFrame(scannerRevelarFrameId);
            scannerRevelarFrameId = null;
        }
        scannerUltimaLarguraAplicada = null;
        scannerUltimaAlturaAplicada = null;
        scannerVideoLarguraAtual = null;
        scannerVideoAlturaAtual = null;
        scannerUltimaLarguraContainer = null;
        scannerUltimaAlturaCalculada = null;

        const caixaObservada = document.getElementById('caixaScanner');
        if (caixaObservada && typeof ResizeObserver !== 'undefined') {
            scannerResizeObserver = new ResizeObserver(() => {
                agendarRecalculoAlturaScanner();
            });
            scannerResizeObserver.observe(caixaObservada);
        }

        scannerPollingIntervalId = setInterval(() => {
            if (!video.videoWidth || !video.videoHeight) return;
            if (video.videoWidth === scannerUltimaLarguraAplicada && video.videoHeight === scannerUltimaAlturaAplicada) return;
            ajustarProporcaoScanner(video.videoWidth, video.videoHeight);
            scannerUltimaLarguraAplicada = video.videoWidth;
            scannerUltimaAlturaAplicada = video.videoHeight;
            revelarVideoScannerQuandoPronto();
            atualizarBotaoCaptura();
        }, 250);

        const track = stream.getVideoTracks()[0];
        if (track) {
            const settings = (typeof track.getSettings === 'function') ? track.getSettings() : {};
            const capabilities = (typeof track.getCapabilities === 'function') ? track.getCapabilities() : null;
            console.log('[scanner Netum] dispositivo conectado:', {
                label: track.label,
                resolucao_ideal_solicitada: { width: SCANNER_RESOLUCAO_IDEAL_LARGURA, height: SCANNER_RESOLUCAO_IDEAL_ALTURA },
                resolucao_entregue: { width: settings.width, height: settings.height },
                capabilities_max: capabilities ? { width: capabilities.width && capabilities.width.max, height: capabilities.height && capabilities.height.max } : 'getCapabilities() não suportado neste navegador'
            });
        }

        video.onloadedmetadata = () => {
            scannerVideoPronto = true;
            ajustarProporcaoScanner(video.videoWidth, video.videoHeight);
            revelarVideoScannerQuandoPronto();
            atualizarBotaoCaptura();
        };

        scannerVideoElementoAtual = video;
        scannerResizeHandlerAtual = () => {
            if (!video.videoWidth || !video.videoHeight) return;
            ajustarProporcaoScanner(video.videoWidth, video.videoHeight);
            revelarVideoScannerQuandoPronto();
            atualizarBotaoCaptura();
        };
        video.addEventListener('resize', scannerResizeHandlerAtual);

        stream.getVideoTracks().forEach(track => {
            track.onended = () => {
                scannerVideoPronto = false;
                mostrarStatusScanner('O scanner foi desconectado. Reconecte o dispositivo e tente novamente.', true);
                atualizarBotaoCaptura();
            };
        });
        if (!scannerDeviceChangeHandlerAtivo) {
            navigator.mediaDevices.ondevicechange = tratarMudancaDispositivosScanner;
            scannerDeviceChangeHandlerAtivo = true;
        }
    } catch (e) {
        tratarErroScanner(e);
    }
}

async function tratarMudancaDispositivosScanner() {
    if (state.tela !== 'rec_digitaliza') return;
    try {
        const devices = await navigator.mediaDevices.enumerateDevices();
        const aindaConectado = devices.some(d => d.kind === 'videoinput' && d.deviceId === scannerDeviceIdAtual);
        if (!aindaConectado) {
            scannerVideoPronto = false;
            mostrarStatusScanner('O scanner foi desconectado. Reconecte o dispositivo e tente novamente.', true);
            atualizarBotaoCaptura();
        }
    } catch (e) { }
}

function tratarErroScanner(e) {
    switch (e.name) {
        case 'NotAllowedError':
            mostrarStatusScanner('Permissão de câmera negada. Autorize o acesso à câmera para continuar.', true);
            break;
        case 'NotFoundError':
            mostrarStatusScanner('Scanner não encontrado. Verifique a conexão USB.', true);
            break;
        case 'NotReadableError':
        case 'TrackStartError':
            mostrarStatusScanner('Scanner ocupado por outro programa. Feche o NetumScan Pro ou qualquer outro aplicativo que esteja usando a câmera.', true);
            break;
        case 'OverconstrainedError':
            localStorage.removeItem('totem_scanner_deviceId');
            mostrarStatusScanner('O scanner selecionado não está mais disponível. Selecione novamente.', true);
            iniciarCameraScanner();
            break;
        default:
            mostrarStatusScanner('Erro ao acessar o scanner: ' + (e.message || e.name || 'erro desconhecido'), true);
    }
}

function pararCameraScanner() {
    if (scannerStreamAtual) {
        scannerStreamAtual.getTracks().forEach(t => t.stop());
        scannerStreamAtual = null;
    }
    if (scannerVideoElementoAtual && scannerResizeHandlerAtual) {
        scannerVideoElementoAtual.removeEventListener('resize', scannerResizeHandlerAtual);
    }
    scannerVideoElementoAtual = null;
    scannerResizeHandlerAtual = null;
    if (scannerPollingIntervalId) {
        clearInterval(scannerPollingIntervalId);
        scannerPollingIntervalId = null;
    }
    scannerUltimaLarguraAplicada = null;
    scannerUltimaAlturaAplicada = null;
    if (scannerResizeObserver) {
        scannerResizeObserver.disconnect();
        scannerResizeObserver = null;
    }
    if (scannerFrameId) {
        cancelAnimationFrame(scannerFrameId);
        scannerFrameId = null;
    }
    scannerVideoLarguraAtual = null;
    scannerVideoAlturaAtual = null;
    scannerUltimaLarguraContainer = null;
    scannerUltimaAlturaCalculada = null;
    scannerVideoRevelado = false;
    if (scannerRevelarFrameId) {
        cancelAnimationFrame(scannerRevelarFrameId);
        scannerRevelarFrameId = null;
    }
    if (scannerDeviceChangeHandlerAtivo) {
        navigator.mediaDevices.ondevicechange = null;
        scannerDeviceChangeHandlerAtivo = false;
    }
    scannerVideoPronto = false;
    scannerDeviceIdAtual = null;
}

const SCANNER_NOTA_LIMITE_BYTES = 4.5 * 1024 * 1024;

function capturarFotoScannerNota(video) {
    marca('cap_canvas_ini', medirSeqPrevia);
    const canvas = document.createElement('canvas');
    canvas.width = video.videoHeight;
    canvas.height = video.videoWidth;
    const ctx = canvas.getContext('2d');
    ctx.translate(canvas.width / 2, canvas.height / 2);
    ctx.rotate(270 * Math.PI / 180);
    ctx.drawImage(video, -video.videoWidth / 2, -video.videoHeight / 2, video.videoWidth, video.videoHeight);
    marca('cap_canvas_fim', medirSeqPrevia);

    marca('enc_ini', medirSeqPrevia);
    const qualidades = [0.92, 0.85, 0.75, 0.65, 0.5, 0.4];
    let dataUrl = null;
    let tamanhoBytes = Infinity;
    for (const qualidade of qualidades) {
        dataUrl = canvas.toDataURL('image/jpeg', qualidade);
        tamanhoBytes = Math.round((dataUrl.length - 'data:image/jpeg;base64,'.length) * 0.75);
        if (tamanhoBytes <= SCANNER_NOTA_LIMITE_BYTES) break;
    }

    const larguraOriginal = canvas.width;
    const alturaOriginal = canvas.height;
    let escalaAtual = larguraOriginal;
    let alturaAtual = alturaOriginal;
    while (tamanhoBytes > SCANNER_NOTA_LIMITE_BYTES && escalaAtual > 600) {
        escalaAtual = Math.round(escalaAtual * 0.9);
        alturaAtual = Math.round(alturaAtual * 0.9);
        const fator = escalaAtual / larguraOriginal;
        const canvasMenor = document.createElement('canvas');
        canvasMenor.width = escalaAtual;
        canvasMenor.height = alturaAtual;
        const ctxMenor = canvasMenor.getContext('2d');
        ctxMenor.translate(canvasMenor.width / 2, canvasMenor.height / 2);
        ctxMenor.rotate(270 * Math.PI / 180);
        ctxMenor.drawImage(
            video,
            -(video.videoWidth * fator) / 2, -(video.videoHeight * fator) / 2,
            video.videoWidth * fator, video.videoHeight * fator
        );
        dataUrl = canvasMenor.toDataURL('image/jpeg', 0.4);
        tamanhoBytes = Math.round((dataUrl.length - 'data:image/jpeg;base64,'.length) * 0.75);
    }

    marca('enc_fim', medirSeqPrevia);
    console.log('[scanner Netum] captura gerada:', {
        largura: escalaAtual, altura: alturaAtual,
        tamanho_aproximado_kb: Math.round(tamanhoBytes / 1024)
    });

    return dataUrl;
}

function capturarPreviaNota() {
    const video = document.getElementById('videoScanner');
    if (!video || video.readyState < 2 || !video.videoWidth || !video.videoHeight) {
        mostrarStatusScanner('Aguarde o vídeo carregar.', true);
        return;
    }
    if ((state.notasNumeros || []).length >= NOTAS_LIMITE) return;
    medirSeqPrevia = medirNovaSeq();
    marca('cap_click', medirSeqPrevia);
    mostrarStatusScanner('Capturando imagem...');
    let imagem;
    try {
        imagem = capturarFotoScannerNota(video);
    } catch (e) {
        marca('falha_captura', medirSeqPrevia);
        throw e;
    }
    state.previewNotaAtual = imagem;
    exibirPreviaNota(imagem);
}

function exibirPreviaNota(imagemDataUrl) {
    const caixa = document.getElementById('caixaScanner');
    if (caixa) caixa.style.display = 'none';
    const img = document.getElementById('previaNota');
    if (img) { img.src = imagemDataUrl; img.style.display = 'block'; }
    const controles = document.getElementById('controlesScanner');
    if (controles) {
        controles.innerHTML = `
            <button class="btn-primario" id="btnUsarImagem" onclick="confirmarUsoImagemNota()">Usar imagem</button>
            <button class="btn-fantasma" id="btnRefazer" onclick="refazerCapturaNota()">Refazer</button>`;
    }
}

function voltarParaVideoAoVivo() {
    const img = document.getElementById('previaNota');
    if (img) img.style.display = 'none';
    const caixa = document.getElementById('caixaScanner');
    if (caixa) caixa.style.display = '';
    const controles = document.getElementById('controlesScanner');
    if (controles) {
        controles.innerHTML = `<button class="btn-fantasma" id="btnCapturarNota" onclick="capturarPreviaNota()">Capturar nota</button>`;
    }
    atualizarBotaoCaptura();
}

function refazerCapturaNota() {
    state.previewNotaAtual = null;
    voltarParaVideoAoVivo();
    mostrarStatusScanner(scannerVideoPronto ? 'Scanner pronto' : 'Conectando ao scanner...');
}

async function confirmarUsoImagemNota() {
    if (state.capturaNotaEmAndamento) return;
    state.capturaNotaEmAndamento = true;
    atualizarBotaoFinalizarDigitalizacao();
    const geracao = atendimentoGeracao;
    const idAtendimentoCapturado = state.idAtendimento;
    const btnUsar = document.getElementById('btnUsarImagem');
    const btnRefazer = document.getElementById('btnRefazer');
    if (btnUsar) btnUsar.disabled = true;
    if (btnRefazer) btnRefazer.disabled = true;
    mostrarStatusScanner('Enviando documento...');
    const imagem = state.previewNotaAtual;
    const notaSeq = medirSeqPrevia;
    marca('usar_click', notaSeq);
    try {
        if (uploadPendente && uploadPendente.imagem !== imagem) {
            await resolverUploadPendente();
            if (geracao !== atendimentoGeracao) return;
            atualizarIndicadorNumerosNota();
            if (state.notasNumeros.length >= NOTAS_LIMITE) {
                state.previewNotaAtual = null;
                voltarParaVideoAoVivo();
                const btnCap = document.getElementById('btnCapturarNota');
                if (btnCap) btnCap.disabled = true;
                const contadorCheio = document.getElementById('contadorNotas');
                if (contadorCheio) contadorCheio.textContent = state.notasNumeros.length;
                mostrarStatusScanner('Limite de 5 notas atingido');
                state.capturaNotaEmAndamento = false;
                atualizarBotaoFinalizarDigitalizacao();
                notificarFimDeTrabalhoNotas();
                return;
            }
        }
        if (!uploadPendente || uploadPendente.imagem !== imagem || uploadPendente.geracao !== geracao) {
            uploadPendente = { clientUid: gerarClientUidNota(), imagem, geracao, idAtendimento: idAtendimentoCapturado };
        }
        const pend = uploadPendente;
        marca('upload_ini', notaSeq);
        const resultado = await api('nota.php', 'processar', { id_atendimento: idAtendimentoCapturado, imagem, chave: null, uid: pend.clientUid }, { timeoutMs: NOTA_UPLOAD_TIMEOUT_MS });
        marca('upload_fim', notaSeq);
        if (geracao !== atendimentoGeracao) return;
        if (resultado && resultado.uid !== undefined && resultado.uid !== pend.clientUid) {
            if (uploadPendente === pend) uploadPendente = null;
            const divergente = new Error('uid divergente');
            divergente.status = 400;
            throw divergente;
        }
        if (uploadPendente === pend) uploadPendente = null;
        const nota = novaNotaLocal({
            clientUid: pend.clientUid,
            ordem: Number.isInteger(resultado.ordem) ? resultado.ordem : null,
            idNota: Number.isInteger(resultado.id_nota) && resultado.id_nota > 0 ? resultado.id_nota : null,
            imagem,
        });
        state.notasNumeros.push(nota);
        avisoCapturaVazia = false;
        state.previewNotaAtual = null;
        processarOcrNota(imagem, nota.uid, notaSeq);
        const contador = document.getElementById('contadorNotas');
        if (contador) contador.textContent = state.notasNumeros.length;
        atualizarIndicadorNumerosNota();
        mostrarStatusScanner('Documento salvo');
        voltarParaVideoAoVivo();
        marca('liberado', notaSeq);
        if (state.notasNumeros.length >= NOTAS_LIMITE) {
            const btn = document.getElementById('btnCapturarNota');
            if (btn) btn.disabled = true;
            mostrarStatusScanner('Limite de 5 notas atingido');
        }
    } catch (e) {
        marca('falha_upload', notaSeq);
        if (geracao !== atendimentoGeracao) return;
        if (uploadPendente && Number.isInteger(e.status) && e.status >= 400 && e.status < 500) uploadPendente = null;
        const msg = (e instanceof TypeError || !Number.isInteger(e.status))
            ? 'Erro ao enviar o documento. Verifique a conexão e tente novamente.'
            : (e.status === 400 ? 'Não foi possível salvar esta nota. Toque em Refazer e tente de novo.' : (e.message || 'Erro ao salvar o documento.'));
        mostrarStatusScanner(msg, true);
        if (btnUsar) btnUsar.disabled = false;
        if (btnRefazer) btnRefazer.disabled = false;
    }
    state.capturaNotaEmAndamento = false;
    atualizarBotaoFinalizarDigitalizacao();
    notificarFimDeTrabalhoNotas();
}

async function finalizarDigitalizacao() {
    if (state.finalizandoDigitalizacao || state.capturaNotaEmAndamento) return;
    if ((state.notasNumeros || []).length === 0) return;
    if (uploadPendente) {
        const geracao = atendimentoGeracao;
        state.finalizandoDigitalizacao = true;
        atualizarBotaoFinalizarDigitalizacao();
        try { await resolverUploadPendente(); } finally {
            if (geracao === atendimentoGeracao) state.finalizandoDigitalizacao = false;
        }
        if (geracao !== atendimentoGeracao) return;
    }
    ir('rec_revisao_numeros');
}

function ordensPendentesValidas(dados) {
    return ordensValidasDe(dados, 'ordens_pendentes');
}
function ordensValidasDe(dados, chave) {
    const bruto = dados && dados[chave];
    if (!Array.isArray(bruto)) return [];
    const ordens = [];
    bruto.forEach(o => {
        if (Number.isInteger(o) && o >= 1 && o <= NOTAS_LIMITE && !ordens.includes(o)) ordens.push(o);
    });
    return ordens;
}

function tratarNotasSemNumero(dados) {
    const ordens = ordensPendentesValidas(dados);
    if (ordens.length === 0) return false;
    let algum = false;
    state.notasNumeros.forEach(n => {
        if (n.ordem !== null && ordens.includes(n.ordem)) {
            n.confirmado = false;
            n.numero = null;
            n.origem = null;
            n.destaque = true;
            n.manualOverride = false;
            recalcularEstadoOcrNota(n);
            algum = true;
        } else if (!n.confirmado && n.manualOverride && n.uid !== numeroModalOverrideUid) {
            n.manualOverride = false;
            recalcularEstadoOcrNota(n);
        }
    });
    if (!algum) return false;
    atualizarRevisaoNumeros();
    const primeira = state.notasNumeros.find(n => n.destaque && !n.confirmado);
    const cartao = primeira ? document.getElementById('cartaoNota' + primeira.uid) : null;
    if (cartao && typeof cartao.scrollIntoView === 'function') cartao.scrollIntoView({ block: 'center' });
    return true;
}

async function concluirDigitalizacaoRevisao() {
    if (state.finalizandoDigitalizacao || state.excluindoNota) return;
    if ((state.notasNumeros || []).length === 0 || (state.notasNumeros || []).some(n => !n.confirmado)) return;
    state.finalizandoDigitalizacao = true;
    const geracao = atendimentoGeracao;
    const idAtendimentoCapturado = state.idAtendimento;
    limparAvisoOcrEmAndamento();
    atualizarRevisaoNumeros();
    try {
        const dados = await api('atendimento.php', 'concluir-digitalizacao', { id_atendimento: idAtendimentoCapturado });
        if (geracao !== atendimentoGeracao) return;
        state.finalizandoDigitalizacao = false;
        digitalizacaoConcluida = true;
        ir(dados.proxima_tela === 'rec_cnh' ? 'rec_cnh_qr' : 'rec_cliente');
    } catch (e) {
        if (geracao !== atendimentoGeracao) return;
        const ehNotasSemNumero = e.status === 422 && e.codigo === 'NOTAS_SEM_NUMERO';
        const ehOcrEmAndamento = e.status === 409 && e.codigo === 'OCR_EM_ANDAMENTO';
        if (ehNotasSemNumero || ehOcrEmAndamento) {
            await reconciliarNotasComServidor();
            if (geracao !== atendimentoGeracao) return;
            state.finalizandoDigitalizacao = false;
            if (ehNotasSemNumero && tratarNotasSemNumero(e.dados)) return;
            if (ehOcrEmAndamento) { tratarOcrEmAndamento(e.dados); return; }
            atualizarRevisaoNumeros('Não foi possível continuar. Tente novamente.');
            return;
        }
        state.finalizandoDigitalizacao = false;
        atualizarRevisaoNumeros('Não foi possível continuar. Tente novamente.');
    }
}

const MSG_OCR_EM_ANDAMENTO = 'A leitura das notas ainda está sendo concluída. Aguarde alguns segundos e toque em Continuar novamente.';
function tratarOcrEmAndamento(dados) {
    const ordens = ordensValidasDe(dados, 'ordens_em_processamento');
    avisoOcrEmAndamento = true;
    state.notasNumeros.forEach(n => { n.aguardando = n.ordem !== null && ordens.includes(n.ordem); });
    atualizarRevisaoNumeros();
}
function limparAvisoOcrEmAndamento() {
    avisoOcrEmAndamento = false;
    (state.notasNumeros || []).forEach(n => { n.aguardando = false; });
}


let tesseractWorkerPromise = null;
let ocrFila = [];
let ocrProcessando = false;

const CNPJ_REGEX = /\d{2}[.,\t ]{0,2}\d{3}[.,\t ]{0,2}\d{3}[/,\t ]{0,2}\d{4}[-,\t ]{0,2}\d{2}/g;

const OCR_PALAVRAS_CABECALHO_DANFE = new Set([
    'documento', 'auxiliar', 'cumento', 'eletronica', 'eletrônica', 'danfe',
    'fiscal', 'nota', 'autenticidade', 'tenticidade', 'portal', 'protocolo',
    'autorizacao', 'autorização', 'autorizadora', 'chave', 'acesso',
    'destinatario', 'destinatário', 'remetente', 'recebemos', 'recebimento',
    'transportador', 'fatura', 'duplicatas', 'imposto', 'icms', 'calculo',
    'cálculo', 'inscricao', 'inscrição', 'estadual', 'endereco', 'endereço',
    'informacoes', 'informações', 'complementares', 'reservado', 'fisco',
    'natureza', 'operacao', 'operação', 'serie', 'série', 'crt', 'regime',
    'tributario', 'tributário', 'consulta', 'assinatura', 'identificacao',
    'identificação', 'recebedor',
]);

const OCR_TERMOS_EMPRESA = [
    's/a', 'sa', 'ltda', 'eireli', 'me', 'industria', 'indústria', 'comercio',
    'comércio', 'transporte', 'transportes', 'armazens', 'armazéns',
    'armazem', 'armazém', 'logistica', 'logística', 'company', 'international',
    'agroindustria', 'agroindústria', 'quimicos', 'químicos',
];

function ocrNormalizarTexto(s) {
    return s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}

function extrairCandidatos(texto) {
    const textoSeguro = texto || '';

    const cnpjsCandidatos = [];
    let m;
    CNPJ_REGEX.lastIndex = 0;
    while ((m = CNPJ_REGEX.exec(textoSeguro)) !== null) {
        const digitos = m[0].replace(/\D/g, '');
        if (digitos.length === 14) cnpjsCandidatos.push(digitos);
    }
    const cnpjsUnicos = [...new Set(cnpjsCandidatos)];

    const linhas = textoSeguro.split(/\r?\n/).map(l => l.trim()).filter(Boolean);
    let razaoSocialCandidata = null;
    let melhorPontuacao = -Infinity;
    let apósSecaoDestinatario = false;

    for (const linha of linhas.slice(0, 40)) {
        if (/destinatari|remetente/.test(ocrNormalizarTexto(linha))) {
            apósSecaoDestinatario = true;
        }

        const tokens = linha.split(/\s+/).filter(Boolean);
        const prefixoTokens = [];
        for (const tok of tokens) {
            if (/\d/.test(tok)) break;
            prefixoTokens.push(tok);
        }
        if (prefixoTokens.some(t => t !== '-' && !/[A-Za-zÀ-ÖØ-öø-ÿ]/.test(t))) continue;

        const candidata = prefixoTokens.join(' ').trim().replace(/[\s-]+$/, '');
        if (candidata.length < 6 || candidata.length > 80) continue;

        const letras = (candidata.match(/[A-Za-zÀ-ÖØ-öø-ÿ]/g) || []).length;
        if (letras < candidata.length * 0.6) continue;

        const palavras = candidata.split(/\s+/).filter(Boolean);
        if (palavras.length < 2) continue;

        const curtas = palavras.filter(p => p.replace(/\W/g, '').length <= 2).length;
        if (curtas > palavras.length * 0.5) continue;

        let normPalavras = palavras.map(p => ocrNormalizarTexto(p.replace(/[^\p{L}]/gu, '')));
        const idxCabecalho = normPalavras.findIndex(p => OCR_PALAVRAS_CABECALHO_DANFE.has(p));
        let palavrasFinais = palavras;
        let candidataFinal = candidata;
        let coladaCabecalho = false;
        if (idxCabecalho !== -1) {
            if (idxCabecalho < 2) continue;
            const prefixo = palavras.slice(0, idxCabecalho);
            const curtasPrefixo = prefixo.filter(p => p.replace(/\W/g, '').length <= 2).length;
            if (curtasPrefixo > 0) continue;
            palavrasFinais = prefixo;
            normPalavras = normPalavras.slice(0, idxCabecalho);
            candidataFinal = prefixo.join(' ');
            coladaCabecalho = true;
        }

        let pontuacao = palavrasFinais.length;
        for (const termo of OCR_TERMOS_EMPRESA) {
            if (normPalavras.includes(termo)) pontuacao += 5;
        }
        pontuacao += Math.min(candidataFinal.length, 60) * 0.1;
        if (apósSecaoDestinatario) pontuacao -= 15;
        if (coladaCabecalho) pontuacao += 10;

        if (pontuacao > melhorPontuacao) {
            melhorPontuacao = pontuacao;
            razaoSocialCandidata = candidataFinal;
        }
    }

    return { cnpjsCandidatos: cnpjsUnicos, razaoSocialCandidata };
}

const NUMERO_NOTA_REGEX_ROTULO = /N[º°ºoO]\.?\s*[:\-]?\s*(\d[\d.]{0,12}\d|\d)/;

function extrairNumeroNota(texto) {
    const textoSeguro = texto || '';
    const linhas = textoSeguro.split(/\r?\n/);
    const candidatos = [];
    for (const linha of linhas) {
        const m = linha.match(NUMERO_NOTA_REGEX_ROTULO);
        if (!m) continue;
        const bruto = m[1].replace(/[.\s]/g, '');
        if (!/^\d+$/.test(bruto)) continue;
        if (bruto.length === 44) continue;
        if (bruto.length === 14) continue;
        if (bruto.length < 1 || bruto.length > 9) continue;
        candidatos.push(bruto);
    }
    if (candidatos.length === 0) return { numero: null, confiancaAlta: false };
    let melhor = candidatos[0];
    for (const candidato of candidatos) {
        if (candidato.length > melhor.length) melhor = candidato;
    }
    return { numero: melhor, confiancaAlta: true };
}

let filaExecucaoTesseract = Promise.resolve();
function executarReconhecimentoSerializado(fn) {
    const execucao = filaExecucaoTesseract.then(fn, fn);
    filaExecucaoTesseract = execucao.catch(() => {});
    return execucao;
}

function atendimentoVigente(item) {
    return !!item && item.geracao === atendimentoGeracao
        && item.idAtendimento != null && item.idAtendimento === state.idAtendimento;
}

function aplicarResultadoOcrNumero(uid, sugestao) {
    const entrada = buscarNotaPorUid(uid);
    if (!entrada) return;
    entrada.ocrConcluido = true;
    entrada.sugestaoOcr = sugestao || null;
    if (entrada.manualOverride || entrada.confirmado) return;
    entrada.sugestao = sugestao || null;
    entrada.estado = entrada.sugestao ? 'sugerido' : 'sem_sugestao';
    if (state.tela === 'rec_revisao_numeros') atualizarRevisaoNumeros();
}

function processarOcrNota(imagem, uid, medirSeq) {
    marca('ocr_fila_num', medirSeq);
    ocrFila.push({ imagem, uid, idAtendimento: state.idAtendimento, geracao: atendimentoGeracao, medirSeq });
    processarProximaOcrDaFila();
}

async function processarProximaOcrDaFila() {
    if (ocrProcessando) return;
    const proxima = ocrFila.shift();
    if (!proxima) return;
    ocrProcessando = true;
    let numeroSugerido = null;
    let cnpjsCandidatos = [];
    let razaoSocialCandidata = null;
    try {
        let texto = '';
        try {
            const workerPromise = iniciarOcrWorker();
            if (!workerPromise) throw new Error('Tesseract.js indisponivel');
            const worker = await workerPromise;
            const resultado = await executarReconhecimentoSerializado(() => {
                marca('ocr_ini_num', proxima.medirSeq);
                return worker.recognize(proxima.imagem);
            });
            marca('ocr_fim_num', proxima.medirSeq);
            texto = (resultado && resultado.data && resultado.data.text) || '';
        } catch (e) {
            marca('falha_ocr_num', proxima.medirSeq);
            logFalhaOcr('reconhecimento', e);
            texto = '';
        }
        try {
            const extraido = extrairNumeroNota(texto);
            numeroSugerido = extraido.confiancaAlta ? extraido.numero : null;
        } catch (e) {
            marca('falha_ocr_num', proxima.medirSeq);
            logFalhaOcr('numero', e);
            numeroSugerido = null;
        }
        try {
            const candidatos = extrairCandidatos(texto);
            cnpjsCandidatos = candidatos.cnpjsCandidatos;
            razaoSocialCandidata = candidatos.razaoSocialCandidata;
        } catch (e) {
            marca('falha_ocr_num', proxima.medirSeq);
            logFalhaOcr('candidatos', e);
            cnpjsCandidatos = [];
            razaoSocialCandidata = null;
        }
    } finally {
        ocrProcessando = false;
        try {
            concluirOcrDaNota(proxima, numeroSugerido, cnpjsCandidatos, razaoSocialCandidata);
        } finally {
            processarProximaOcrDaFila();
            notificarFimDeTrabalhoNotas();
        }
    }
}

function concluirOcrDaNota(proxima, numeroSugerido, cnpjsCandidatos, razaoSocialCandidata) {
    if (digitalizacaoConcluida || !atendimentoVigente(proxima) || !buscarNotaPorUid(proxima.uid)) {
        marca('res_descart_num', proxima.medirSeq);
        return;
    }
    try {
        aplicarResultadoOcrNumero(proxima.uid, numeroSugerido);
        marca('res_aplic_num', proxima.medirSeq);
    } catch (e) {
        logFalhaOcr('aplicar', e);
    }
    try {
        const identificacao = identificarClienteNota(proxima.uid, cnpjsCandidatos, razaoSocialCandidata, proxima.idAtendimento, proxima.geracao, proxima.medirSeq);
        if (identificacao && typeof identificacao.catch === 'function') {
            identificacao.catch(e => logFalhaOcr('identificar', e));
        }
    } catch (e) {
        logFalhaOcr('identificar', e);
    }
}

function logFalhaOcr(etapa, e) {
    const nome = (e && typeof e.name === 'string') ? e.name.slice(0, 40) : 'Erro';
    console.warn('[OCR] falha em ' + etapa + ': ' + nome);
}

let numeroModalAberta = false;
let numeroModalOverrideUid = null;

function confirmarCancelarNotaModal() {
    document.getElementById('modalConfirmCancelNotaCaixa').innerHTML = `
        <div class="titulo">Cancelar atendimento?</div>
        <div class="subtitulo">Os dados digitados serão perdidos.</div>
        <button class="btn-alerta" onclick="fecharConfirmacaoCancelarNotaModal(); fecharModal(); cancelarESair();">Sim, cancelar</button>
        <button class="btn-fantasma" onclick="fecharConfirmacaoCancelarNotaModal()">Continuar atendimento</button>
    `;
    document.getElementById('modalConfirmCancelNotaFundo').classList.add('aberto');
}
function fecharConfirmacaoCancelarNotaModal() {
    document.getElementById('modalConfirmCancelNotaFundo').classList.remove('aberto');
    limparCaixaModal('modalConfirmCancelNotaCaixa');
}

function tocarCartaoNota(uid) {
    if (numeroModalAberta || state.finalizandoDigitalizacao || state.excluindoNota) return;
    const entrada = buscarNotaPorUid(uid);
    if (!entrada) return;
    if (entrada.destaque || entrada.aguardando) {
        entrada.destaque = false;
        if (entrada.aguardando) {
            entrada.aguardando = false;
            if (!state.notasNumeros.some(n => n.aguardando)) avisoOcrEmAndamento = false;
        }
        atualizarRevisaoNumeros();
    }
    numeroModalAberta = true;
    if (entrada.estado === 'confirmado') {
        abrirModalNumeroNotaManual(uid, entrada.numero, '');
    } else if (entrada.estado === 'sugerido' && entrada.sugestao) {
        abrirModalNumeroNotaSugestao(uid, entrada.sugestao);
    } else {
        if (entrada.estado === 'pendente' && !entrada.manualOverride) {
            entrada.manualOverride = true;
            numeroModalOverrideUid = uid;
        }
        abrirModalNumeroNotaManual(uid, null, '');
    }
}

function fecharModalNumeroNotaAtual() {
    fecharModal();
    numeroModalAberta = false;
    numeroModalOverrideUid = null;
    atualizarRevisaoNumeros();
}

function recalcularEstadoOcrNota(entrada) {
    if (entrada.confirmado) return;
    if (!entrada.ocrConcluido) {
        entrada.sugestao = null;
        entrada.estado = 'pendente';
        return;
    }
    entrada.sugestao = entrada.sugestaoOcr || null;
    entrada.estado = entrada.sugestao ? 'sugerido' : 'sem_sugestao';
}

function voltarModalNumeroNota(uid) {
    if (!numeroModalAberta) return;
    const entrada = buscarNotaPorUid(uid);
    if (entrada && numeroModalOverrideUid === uid) {
        entrada.manualOverride = false;
        recalcularEstadoOcrNota(entrada);
    }
    fecharModalNumeroNotaAtual();
    const cartao = document.getElementById('cartaoNota' + uid);
    if (cartao) cartao.focus();
}

function definirSaidasModalNotaDesabilitadas(desabilitado) {
    ['btnVoltarModalNota', 'btnExcluirNota'].forEach(id => {
        const btn = document.getElementById(id);
        if (btn) btn.disabled = desabilitado;
    });
}

function mensagemErroSalvarNumero(e) {
    if (e && e.status === 409) return 'Este número já foi usado em outra nota. Confira o número na nota e corrija.';
    if (e && e.status === 404) return 'Não encontramos esta nota. Toque em Voltar e confira a lista.';
    if (e && e.status === 400 && e.message) return e.message;
    return 'Não foi possível salvar. Toque para tentar de novo.';
}

function cabecalhoModalNota(uid) {
    const total = (state.notasNumeros || []).length;
    const nota = buscarNotaPorUid(uid);
    const mini = (nota && !nota.imagem)
        ? '<div class="nota-modal-mini nota-modal-sem-foto" id="notaModalMini" role="img" aria-label="Foto indisponível">Foto indisponível</div>'
        : '<img class="nota-modal-mini" id="notaModalMini" alt="">';
    return `<div class="nota-modal-topo">
            ${mini}
            <div class="nota-modal-id">Nota ${escapeHtml(posicaoNotaPorUid(uid))} de ${escapeHtml(total)}</div>
        </div>`;
}
function preencherMiniaturaModalNota(uid) {
    const img = document.getElementById('notaModalMini');
    if (!img || img.tagName !== 'IMG') return;
    const nota = buscarNotaPorUid(uid);
    if (nota && nota.imagem) img.src = nota.imagem; else img.style.display = 'none';
    img.alt = 'Foto da nota ' + posicaoNotaPorUid(uid);
}

function blocoExcluirModalNota(uid) {
    return `<div class="nota-modal-divisor" role="separator"></div>
        <button type="button" class="btn-excluir-nota" id="btnExcluirNota" aria-label="Excluir Nota ${escapeHtml(posicaoNotaPorUid(uid))}">Excluir nota</button>`;
}
function blocoCancelarAtendimentoModalNota() {
    return `<div class="nota-modal-sep-atendimento" role="separator"></div>
        <div class="nota-modal-rotulo-secao">Atendimento</div>
        <button class="btn-saida-modal-nota" onclick="confirmarCancelarNotaModal()">✕ Cancelar atendimento</button>`;
}
function ligarExcluirModalNota(uid) {
    const btn = document.getElementById('btnExcluirNota');
    if (btn) btn.addEventListener('click', () => abrirConfirmacaoExcluirNota(uid));
}

function abrirModalNumeroNotaSugestao(uid, sugestao) {
    abrirModal(`
        ${cabecalhoModalNota(uid)}
        <div class="nota-modal-instrucao">O sistema leu este número. Confira na nota: está certo?</div>
        <div class="nota-modal-numero">${escapeHtml(sugestao)}</div>
        <div class="grupo-botoes">
            <button class="btn-primario" id="btnConfirmarNumeroSugerido">Confirmar</button>
            <button class="btn-fantasma" id="btnCorrigirNumeroSugerido">Corrigir</button>
            <button type="button" class="btn-voltar-modal-nota" id="btnVoltarModalNota" aria-label="Voltar para a lista de notas">Voltar</button>
        </div>
        ${blocoExcluirModalNota(uid)}
        ${blocoCancelarAtendimentoModalNota()}
    `);
    preencherMiniaturaModalNota(uid);
    const btnConfirmar = document.getElementById('btnConfirmarNumeroSugerido');
    const btnCorrigir = document.getElementById('btnCorrigirNumeroSugerido');
    if (btnConfirmar) btnConfirmar.addEventListener('click', () => confirmarNumeroNotaSugerido(uid, sugestao));
    if (btnCorrigir) btnCorrigir.addEventListener('click', () => abrirModalNumeroNotaManual(uid, sugestao, ''));
    const btnVoltar = document.getElementById('btnVoltarModalNota');
    if (btnVoltar) btnVoltar.addEventListener('click', () => voltarModalNumeroNota(uid));
    ligarExcluirModalNota(uid);
}

function encerrarSalvarDeNotaInexistente() {
    fecharModalNumeroNotaAtual();
}

async function confirmarNumeroNotaSugerido(uid, sugestao) {
    const nota = buscarNotaPorUid(uid);
    if (!nota) { encerrarSalvarDeNotaInexistente(); return; }
    const geracao = atendimentoGeracao;
    definirSaidasModalNotaDesabilitadas(true);
    try {
        await salvarNumeroNota(nota, sugestao, 'OCR');
        if (geracao !== atendimentoGeracao) return;
        if (!buscarNotaPorUid(uid)) { encerrarSalvarDeNotaInexistente(); return; }
        marcarNumeroNotaConfirmado(uid, sugestao, 'OCR');
        fecharModalNumeroNotaAtual();
    } catch (e) {
        if (geracao !== atendimentoGeracao) return;
        if (!buscarNotaPorUid(uid)) { encerrarSalvarDeNotaInexistente(); return; }
        abrirModalNumeroNotaManual(uid, sugestao, mensagemErroSalvarNumero(e));
    }
}

function abrirModalNumeroNotaManual(uid, valorInicial, mensagemErro) {
    abrirModal(`
        ${cabecalhoModalNota(uid)}
        <div class="nota-modal-instrucao">Digite o número da nota (só os números).</div>
        <input class="campo-texto" id="inputNumeroNota" inputmode="numeric" readonly value="${escapeHtml(valorInicial || '')}" placeholder="Número da nota">
        <div class="status-scanner erro" id="numeroNotaErro" style="${mensagemErro ? '' : 'display:none'}">${escapeHtml(mensagemErro || '')}</div>
        <div class="teclado-numerico-nota" id="tecladoNumericoNota"></div>
        <button type="button" class="btn-voltar-modal-nota btn-voltar-modal-nota-larga" id="btnVoltarModalNota" aria-label="Voltar para a lista de notas">Voltar</button>
        ${blocoExcluirModalNota(uid)}
        ${blocoCancelarAtendimentoModalNota()}
    `);
    preencherMiniaturaModalNota(uid);
    montarTecladoNumericoNota(uid);
    const btnVoltar = document.getElementById('btnVoltarModalNota');
    if (btnVoltar) btnVoltar.addEventListener('click', () => voltarModalNumeroNota(uid));
    ligarExcluirModalNota(uid);
}

function montarTecladoNumericoNota(uid) {
    const container = document.getElementById('tecladoNumericoNota');
    if (!container) return;
    container.innerHTML = `
        <button type="button" class="tecla-numerica" onclick="digitarNumeroNota('1')">1</button>
        <button type="button" class="tecla-numerica" onclick="digitarNumeroNota('2')">2</button>
        <button type="button" class="tecla-numerica" onclick="digitarNumeroNota('3')">3</button>
        <button type="button" class="tecla-numerica" onclick="digitarNumeroNota('4')">4</button>
        <button type="button" class="tecla-numerica" onclick="digitarNumeroNota('5')">5</button>
        <button type="button" class="tecla-numerica" onclick="digitarNumeroNota('6')">6</button>
        <button type="button" class="tecla-numerica" onclick="digitarNumeroNota('7')">7</button>
        <button type="button" class="tecla-numerica" onclick="digitarNumeroNota('8')">8</button>
        <button type="button" class="tecla-numerica" onclick="digitarNumeroNota('9')">9</button>
        <button type="button" class="tecla-numerica tecla-numerica-apagar" id="btnApagarNumeroNota">Apagar</button>
        <button type="button" class="tecla-numerica" onclick="digitarNumeroNota('0')">0</button>
        <button type="button" class="tecla-numerica tecla-numerica-confirmar" id="btnSalvarNumeroNota" disabled>Confirmar</button>
    `;
    const btnApagar = document.getElementById('btnApagarNumeroNota');
    const btnConfirmar = document.getElementById('btnSalvarNumeroNota');
    if (btnApagar) btnApagar.addEventListener('click', apagarNumeroNota);
    if (btnConfirmar) btnConfirmar.addEventListener('click', () => salvarNumeroNotaManual(uid));
    atualizarBotaoNumeroNota();
}

function atualizarBotaoNumeroNota() {
    const input = document.getElementById('inputNumeroNota');
    const btn = document.getElementById('btnSalvarNumeroNota');
    if (btn && input) btn.disabled = input.value.trim().length === 0;
}

function digitarNumeroNota(digito) {
    const input = document.getElementById('inputNumeroNota');
    if (!input) return;
    input.value += digito;
    atualizarBotaoNumeroNota();
}

function apagarNumeroNota() {
    const input = document.getElementById('inputNumeroNota');
    if (!input) return;
    input.value = input.value.slice(0, -1);
    atualizarBotaoNumeroNota();
}

async function salvarNumeroNotaManual(uid) {
    const input = document.getElementById('inputNumeroNota');
    const erroEl = document.getElementById('numeroNotaErro');
    const btn = document.getElementById('btnSalvarNumeroNota');
    const valor = (input && input.value || '').trim();
    if (!valor) return;
    const nota = buscarNotaPorUid(uid);
    if (!nota) { encerrarSalvarDeNotaInexistente(); return; }
    if (btn) btn.disabled = true;
    definirSaidasModalNotaDesabilitadas(true);
    const geracao = atendimentoGeracao;
    try {
        await salvarNumeroNota(nota, valor, 'MANUAL');
        if (geracao !== atendimentoGeracao) return;
        if (!buscarNotaPorUid(uid)) { encerrarSalvarDeNotaInexistente(); return; }
        marcarNumeroNotaConfirmado(uid, valor, 'MANUAL');
        fecharModalNumeroNotaAtual();
    } catch (e) {
        if (geracao !== atendimentoGeracao) return;
        if (!buscarNotaPorUid(uid)) { encerrarSalvarDeNotaInexistente(); return; }
        const msg = mensagemErroSalvarNumero(e);
        if (erroEl) { erroEl.textContent = msg; erroEl.style.display = 'block'; }
        if (btn) btn.disabled = false;
        definirSaidasModalNotaDesabilitadas(false);
    }
}

function salvarNumeroNota(nota, numero, origem) {
    const ident = identificadorNotaApi(nota);
    if (!ident) return Promise.reject(new Error('nota sem identificador'));
    return api('nota.php', 'definir-numero', Object.assign({
        id_atendimento: state.idAtendimento,
        numero,
        origem,
    }, ident));
}

function marcarNumeroNotaConfirmado(uid, numero, origem) {
    const entrada = buscarNotaPorUid(uid);
    if (entrada) {
        entrada.numero = numero;
        entrada.confirmado = true;
        entrada.estado = 'confirmado';
        entrada.origem = origem || entrada.origem;
        entrada.destaque = false;
    }
    atualizarRevisaoNumeros();
}

const EXCLUIR_NOTA_MSG_ERRO = 'Não foi possível excluir. Tente de novo.';

function abrirConfirmacaoExcluirNota(uid) {
    if (state.excluindoNota) return;
    const nota = buscarNotaPorUid(uid);
    if (!nota) return;
    const caixa = document.getElementById('modalConfirmExcluirNotaCaixa');
    const fundo = document.getElementById('modalConfirmExcluirNotaFundo');
    if (!caixa || !fundo) return;
    caixa.innerHTML = `
        <div class="titulo excluir-nota-titulo">Excluir a Nota ${escapeHtml(posicaoNotaPorUid(uid))} de ${escapeHtml((state.notasNumeros || []).length)}?</div>
        <div class="subtitulo excluir-nota-texto">A foto será apagada. Você precisará capturar de novo.</div>
        <div class="status-scanner erro excluir-nota-erro" id="excluirNotaErro" role="alert" style="display:none"></div>
        <button type="button" class="btn-excluir-nao" id="btnExcluirNaoVoltar">Não, voltar</button>
        <button type="button" class="btn-excluir-sim" id="btnExcluirSim">Sim, excluir</button>
    `;
    const btnNao = document.getElementById('btnExcluirNaoVoltar');
    const btnSim = document.getElementById('btnExcluirSim');
    if (btnNao) btnNao.addEventListener('click', () => {
        if (state.excluindoNota) return;
        fecharConfirmacaoExcluirNota();
        const btnExcluir = document.getElementById('btnExcluirNota');
        if (btnExcluir) btnExcluir.focus();
    });
    if (btnSim) btnSim.addEventListener('click', () => executarExclusaoNota(uid));
    fundo.classList.add('aberto');
    if (btnNao) btnNao.focus();
}

function fecharConfirmacaoExcluirNota() {
    const fundo = document.getElementById('modalConfirmExcluirNotaFundo');
    if (fundo) fundo.classList.remove('aberto');
    limparCaixaModal('modalConfirmExcluirNotaCaixa');
}

function mostrarErroExclusaoNota(msg) {
    const el = document.getElementById('excluirNotaErro');
    if (!el) return;
    el.textContent = msg;
    el.style.display = msg ? 'block' : 'none';
}

function definirBotoesExclusaoHabilitados(habilitados) {
    const btnNao = document.getElementById('btnExcluirNaoVoltar');
    const btnSim = document.getElementById('btnExcluirSim');
    if (btnNao) btnNao.disabled = !habilitados;
    if (btnSim) {
        btnSim.disabled = !habilitados;
        btnSim.textContent = habilitados ? 'Sim, excluir' : 'Excluindo...';
    }
}

async function executarExclusaoNota(uid) {
    if (state.excluindoNota) return;
    const nota = buscarNotaPorUid(uid);
    if (!nota) { fecharConfirmacaoExcluirNota(); return; }
    if (state.finalizandoDigitalizacao || state.capturaNotaEmAndamento) return;
    const idNota = nota.idNota;
    if (!Number.isInteger(idNota) || idNota <= 0) {
        mostrarErroExclusaoNota('Não foi possível excluir esta nota agora. Aguarde um instante e tente de novo.');
        return;
    }
    state.excluindoNota = true;
    definirBotoesExclusaoHabilitados(false);
    mostrarErroExclusaoNota('');
    atualizarRevisaoNumeros();
    const geracao = atendimentoGeracao;
    const idAtendimentoCapturado = state.idAtendimento;
    try {
        const dados = await api('nota.php', 'excluir', { id_atendimento: idAtendimentoCapturado, id_nota: idNota });
        if (geracao !== atendimentoGeracao) return;
        if (!dados || (dados.excluida !== true && dados.ja_excluida !== true)) throw new Error('resposta invalida');
        concluirExclusaoNota(uid, dados);
    } catch (e) {
        if (geracao !== atendimentoGeracao) return;
        state.excluindoNota = false;
        definirBotoesExclusaoHabilitados(true);
        mostrarErroExclusaoNota(EXCLUIR_NOTA_MSG_ERRO);
        atualizarRevisaoNumeros();
    }
}

function concluirExclusaoNota(uid, dados) {
    const posicao = posicaoNotaPorUid(uid);
    if (posicao > 0) {
        const removida = state.notasNumeros[posicao - 1];
        if (removida && Number.isInteger(removida.idNota)) idNotasExcluidas.add(removida.idNota);
        state.notasNumeros.splice(posicao - 1, 1);
        if (avisoOcrEmAndamento && !state.notasNumeros.some(n => n.aguardando) && removida && removida.aguardando) avisoOcrEmAndamento = false;
    }
    ocrFila = ocrFila.filter(item => item.uid !== uid);
    state.excluindoNota = false;
    fecharConfirmacaoExcluirNota();
    numeroModalAberta = false;
    numeroModalOverrideUid = null;
    fecharModal();
    if (state.notasNumeros.length === 0) {
        avisoCapturaVazia = true;
        ir('rec_digitaliza');
        notificarFimDeTrabalhoNotas();
        return;
    }
    atualizarRevisaoNumeros();
    if (posicao > 0) mostrarFaixaExclusaoNota(posicao);
    const primeiro = document.querySelector('#revisaoLista .rev-cartao');
    if (primeiro) primeiro.focus();
    notificarFimDeTrabalhoNotas();
}

function iniciarOcrWorker() {
    if (!tesseractWorkerPromise) {
        try {
            tesseractWorkerPromise = Tesseract.createWorker('por', 1, {
                workerPath: TESSERACT_VENDOR_PATH + '/worker.min.js',
                corePath: TESSERACT_VENDOR_PATH,
                langPath: TESSERACT_VENDOR_PATH,
                gzip: true,
                cacheMethod: 'none',
            });
        } catch (e) {
            logFalhaOcr('inicializacao', e);
            tesseractWorkerPromise = null;
        }
    }
    return tesseractWorkerPromise;
}

let identificacoesEmVoo = 0;
async function identificarClienteNota(uid, cnpjsCandidatos, razaoSocialCandidata, idAtendimentoCapturado, geracaoCapturada, medirSeq) {
    const alvo = { idAtendimento: idAtendimentoCapturado, geracao: geracaoCapturada };
    const backoffMs = [1000, 2000];
    identificacoesEmVoo++;
    marca('ocr_ini_cli', medirSeq);
    try {
        for (let tentativa = 0; tentativa <= backoffMs.length; tentativa++) {
            if (digitalizacaoConcluida || !atendimentoVigente(alvo)) return;
            const ident = identificadorNotaApi(buscarNotaPorUid(uid));
            if (!ident) return;
            try {
                await api('nota.php', 'identificar-cliente', Object.assign({
                    id_atendimento: idAtendimentoCapturado,
                    chave_ocr: null,
                    cnpjs_candidatos: cnpjsCandidatos,
                    razao_social_candidata: razaoSocialCandidata,
                }, ident));
                return;
            } catch (e) {
                const erroDeRede = e instanceof TypeError;
                if (!erroDeRede || tentativa === backoffMs.length) {
                    if (erroDeRede) logFalhaOcr('identificar', e);
                    return;
                }
                await new Promise(resolve => setTimeout(resolve, backoffMs[tentativa]));
            }
        }
    } finally {
        marca('ocr_fim_cli', medirSeq);
        identificacoesEmVoo--;
        notificarFimDeTrabalhoNotas();
    }
}

let clienteSelecionado = null;

function telaCliente() {
    clienteSelecionado = null;
    return `<div class="subtitulo cliente-aviso">Não conseguimos identificar o cliente. Digite o nome ou CNPJ.</div>
        <input class="kb-input cliente-campo" id="inputCliente" placeholder="Digite para buscar" aria-label="Nome ou CNPJ do cliente">
        <div class="lista-sugestoes" id="listaSugestoes"></div>
        <button class="btn-primario cliente-avancar" style="max-width:320px;margin:0 auto" onclick="confirmarCliente()">Avançar</button>`;
}
function habilitarAutocompleteCliente() {
    const input = document.getElementById('inputCliente');
    let timer = null;
    input.addEventListener('input', () => {
        clearTimeout(timer);
        const termo = input.value.trim();
        if (termo.length < 3) { document.getElementById('listaSugestoes').innerHTML = ''; return; }
        timer = setTimeout(() => buscarClientes(termo), 250);
    });
}
async function buscarClientes(termo) {
    try {
        const res = await fetch(`${API_BASE}cliente.php?acao=buscar&termo=${encodeURIComponent(termo)}`, {
            headers: { 'Authorization': `Bearer ${TOKEN}` },
        });
        const json = await res.json();
        const lista = (json.dados && json.dados.clientes) || [];
        document.getElementById('listaSugestoes').innerHTML = lista.map(c =>
            `<div class="item" data-nome="${escapeHtml(c.nome)}" data-cnpj="${escapeHtml(c.cnpj)}">${escapeHtml(c.nome)}</div>`
        ).join('');
        document.querySelectorAll('#listaSugestoes .item').forEach(item => {
            item.addEventListener('click', () => {
                document.getElementById('inputCliente').value = item.dataset.nome;
                clienteSelecionado = { nome: item.dataset.nome, cnpj: item.dataset.cnpj };
                document.getElementById('listaSugestoes').innerHTML = '';
            });
        });
    } catch (e) { }
}
async function confirmarCliente() {
    const nome = (clienteSelecionado && clienteSelecionado.nome) || document.getElementById('inputCliente').value.trim();
    const cnpj = (clienteSelecionado && clienteSelecionado.cnpj) || null;
    if (!nome) return mostrarErroTela('Digite ou selecione um cliente');
    try {
        await api('atendimento.php', 'salvar-etapa', { id_atendimento: state.idAtendimento, etapa: 'cliente', dados: { nome, cnpj } });
        state.dados = Object.assign({}, state.dados, { cliente_nome: nome, cliente_cnpj: cnpj });
        ir('rec_cnh_qr');
    } catch (e) { mostrarErroTela(e.message); }
}


let recCamStream = null;
let recCamDeviceId = null;
let recCamVideoPronto = false;
let recCamDeviceChangeAtivo = false;

function recCamMostrarStatus(msg, isErro) {
    const el = document.getElementById('recCamStatus');
    if (!el) return;
    el.textContent = msg;
    el.classList.toggle('erro', !!isErro);
    el.classList.toggle('ok', !isErro && (msg === 'Câmera pronta' || (msg || '').indexOf('aprovad') !== -1));
}

async function iniciarCameraRec() {
    recCamVideoPronto = false;
    if (!window.isSecureContext) {
        recCamMostrarStatus('Esta página precisa ser aberta via HTTPS ou localhost para acessar a câmera.', true);
        return;
    }
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !navigator.mediaDevices.enumerateDevices) {
        recCamMostrarStatus('Navegador sem suporte a câmera.', true);
        return;
    }
    recCamMostrarStatus('Conectando à câmera...');
    try {
        const permStream = await navigator.mediaDevices.getUserMedia({ video: true });
        permStream.getTracks().forEach(t => t.stop());
        const devices = await navigator.mediaDevices.enumerateDevices();
        const cams = devices.filter(d => d.kind === 'videoinput');
        if (cams.length === 0) {
            recCamMostrarStatus('Câmera não encontrada. Verifique a conexão USB.', true);
            return;
        }
        let deviceId = localStorage.getItem('totem_scanner_deviceId');
        if (deviceId && !cams.some(c => c.deviceId === deviceId)) deviceId = null;
        if (!deviceId) {
            if (cams.length === 1) {
                deviceId = cams[0].deviceId;
            } else {
                recCamAbrirSelecao(cams);
                return;
            }
        }
        await recCamAbrirStream(deviceId);
    } catch (e) {
        recCamTratarErro(e);
    }
}

function recCamAbrirSelecao(cams) {
    recCamMostrarStatus('Selecione a câmera');
    const botoes = cams.map((c, i) =>
        `<button class="btn-fantasma" data-device-id="${escapeHtml(c.deviceId)}">${escapeHtml(c.label || ('Câmera ' + (i + 1)))}</button>`
    ).join('');
    abrirModal(`<div class="titulo">Selecione a câmera</div><div class="grupo-botoes">${botoes}</div>`);
    document.querySelectorAll('#modalCaixa [data-device-id]').forEach(btn => {
        btn.addEventListener('click', () => {
            const deviceId = btn.dataset.deviceId;
            localStorage.setItem('totem_scanner_deviceId', deviceId);
            fecharModal();
            recCamAbrirStream(deviceId);
        });
    });
}

const REC_CAM_RESOLUCAO_IDEAL_LARGURA = 4096;
const REC_CAM_RESOLUCAO_IDEAL_ALTURA = 3072;

async function recCamAbrirStream(deviceId) {
    recCamMostrarStatus('Conectando à câmera...');
    try {
        const stream = await navigator.mediaDevices.getUserMedia({
            video: {
                deviceId: { exact: deviceId },
                width: { ideal: REC_CAM_RESOLUCAO_IDEAL_LARGURA },
                height: { ideal: REC_CAM_RESOLUCAO_IDEAL_ALTURA },
            },
        });
        recCamStream = stream;
        recCamDeviceId = deviceId;
        qrOtimizarCamera(stream);
        const video = document.getElementById('recCamVideo');
        if (!video) { stream.getTracks().forEach(t => t.stop()); recCamStream = null; return; }
        video.srcObject = stream;
        video.onloadedmetadata = () => {
            configurarProporcaoCameraDocumento('rec', video, 'recCamCaixa', 'recCamPreview');
            recCamVideoPronto = true;
            recCamMostrarStatus('Câmera pronta');
            recCamAtualizarBotao();
        };
        stream.getVideoTracks().forEach(track => {
            track.onended = () => {
                recCamVideoPronto = false;
                recCamMostrarStatus('A câmera foi desconectada. Reconecte o dispositivo e tente novamente.', true);
                recCamAtualizarBotao();
            };
        });
        if (!recCamDeviceChangeAtivo) {
            navigator.mediaDevices.ondevicechange = recCamTratarMudancaDispositivos;
            recCamDeviceChangeAtivo = true;
        }
    } catch (e) {
        recCamTratarErro(e);
    }
}

async function recCamTratarMudancaDispositivos() {
    if (!['rec_cnh_qr', 'rec_crlv_qr'].includes(state.tela)) return;
    try {
        const devices = await navigator.mediaDevices.enumerateDevices();
        const aindaConectado = devices.some(d => d.kind === 'videoinput' && d.deviceId === recCamDeviceId);
        if (!aindaConectado) {
            recCamVideoPronto = false;
            recCamMostrarStatus('A câmera foi desconectada. Reconecte o dispositivo e tente novamente.', true);
            recCamAtualizarBotao();
        }
    } catch (e) { }
}

function recCamTratarErro(e) {
    switch (e.name) {
        case 'NotAllowedError':
            recCamMostrarStatus('Permissão de câmera negada. Autorize o acesso à câmera para continuar.', true);
            break;
        case 'NotFoundError':
            recCamMostrarStatus('Câmera não encontrada. Verifique a conexão USB.', true);
            break;
        case 'NotReadableError':
        case 'TrackStartError':
            recCamMostrarStatus('Câmera ocupada por outro programa. Feche o NetumScan Pro ou qualquer outro aplicativo que esteja usando a câmera.', true);
            break;
        case 'OverconstrainedError':
            localStorage.removeItem('totem_scanner_deviceId');
            recCamMostrarStatus('A câmera selecionada não está mais disponível. Selecione novamente.', true);
            iniciarCameraRec();
            break;
        default:
            recCamMostrarStatus('Erro ao acessar a câmera: ' + (e.message || e.name || 'erro desconhecido'), true);
    }
}

function pararCameraRec() {
    if (recCamStream) { recCamStream.getTracks().forEach(t => t.stop()); recCamStream = null; }
    limparProporcaoCameraDocumento('rec');
    if (recCamDeviceChangeAtivo) { navigator.mediaDevices.ondevicechange = null; recCamDeviceChangeAtivo = false; }
    recCamVideoPronto = false;
    recCamDeviceId = null;
}

function recCamAtualizarBotao() {
    const btn = document.getElementById('recCamBtnCapturar');
    if (!btn) return;
    const video = document.getElementById('recCamVideo');
    const prontoVideo = !!video && video.readyState >= 2 && video.videoWidth > 0 && video.videoHeight > 0;
    btn.disabled = !prontoVideo || !recCamVideoPronto;
}

function telaRecQr(titulo, tipo) {
    return `<div class="titulo">${titulo}</div>
        <div class="subtitulo">Encaixe o QR code dentro do quadrado, bem iluminado e sem reflexo.</div>
        <div class="caixa-scanner" id="recCamCaixa">
            <video id="recCamVideo" autoplay playsinline></video>
            <div class="guia-qr" aria-hidden="true"></div>
        </div>
        <div class="status-scanner" id="recCamStatus">Conectando à câmera...</div>
        <div class="status-leitura" id="recBgStatus" style="display:none"></div>
        <div class="grupo-botoes" id="recCamControles">
            <button class="btn-primario" id="recCamBtnCapturar" onclick="recCapturarQr('${tipo}')" disabled>Ler QR code</button>
        </div>`;
}
function telaRecCnhQr() { return telaRecQr('Leia o QR code da CNH', 'cnh'); }
function telaRecCrlvQr() { return telaRecQr('Leia o QR code do CRLV', 'crlv'); }

function recLimparFrameQr() {
    state.rec.previewImg = null;
    state.rec.previewCanvas = null;
}

async function recCapturarQr(tipo) {
    if (state.rec.emAndamento) return;
    const video = document.getElementById('recCamVideo');
    if (!video || video.readyState < 2 || !video.videoWidth || !video.videoHeight) {
        recCamMostrarStatus('Aguarde o vídeo carregar.', true);
        return;
    }
    state.rec.emAndamento = true;
    const btn = document.getElementById('recCamBtnCapturar');
    if (btn) btn.disabled = true;
    let canvas = null;
    try {
        recCamMostrarStatus('Lendo QR code...');
        canvas = await lerQrEmVariosFrames(() => recCamCapturarFrame(video), video, recCamStream);
        if (!video.isConnected) return;
        if (!canvas) {
            recLimparFrameQr();
            const tentativa = ++state.rec.qrTentativas[tipo];
            if (tentativa >= 3) {
                ir(tipo === 'cnh' ? 'rec_cnh_manual' : 'rec_crlv_manual');
                return;
            }
            recCamMostrarStatus(`Não encontramos o QR. Aproxime e tente novamente. Tentativa ${tentativa} de 3.`, true);
            return;
        }

        const imagemQr = canvas.toDataURL('image/jpeg', 0.85);
        recLimparFrameQr();
        canvas = null;
        if (!state.rec.reescaneio[tipo]) state.rec.qrTentativas[tipo] = 0;
        recCamMostrarStatus(TEXTO_LENDO_DOCUMENTO);
        state.rec[tipo + 'Promise'] = iniciarProcessamentoDocumento(
            state.idAtendimento,
            tipo,
            imagemQr,
            (status) => atualizarStatusDocumento('rec', tipo, status),
        );
        if (state.rec.reescaneio[tipo]) {
            ir('rec_aguarde_documentos');
        } else {
            await tentarAvancarEtapaDocumentos('rec_cnh_manual', 'rec_crlv_manual');
        }
    } catch (e) {
        recCamMostrarStatus('Não foi possível iniciar a validação agora.', true);
    } finally {
        canvas = null;
        state.rec.emAndamento = false;
        if (btn && document.getElementById('recCamBtnCapturar') === btn) recCamAtualizarBotao();
    }
}
function exibirIndicadorProcessamentoCnhRec() {
    const el = document.getElementById('recBgStatus');
    if (!el || !state.rec.cnhPromise) return;
    state.rec.cnhAoAtualizar = (status) => {
        const alvo = document.getElementById('recBgStatus');
        if (!alvo) return;
        alvo.style.display = 'block';
        alvo.textContent = rotuloStatusProcessamento(status.status_processamento);
    };
    if (state.rec.cnhUltimoStatus) {
        state.rec.cnhAoAtualizar(state.rec.cnhUltimoStatus);
    } else {
        el.textContent = TEXTO_LENDO_DOCUMENTO;
        el.style.display = 'block';
    }
    state.rec.cnhPromise.then(resultado => {
        state.rec.cnhAoAtualizar = null;
        const alvo = document.getElementById('recBgStatus');
        if (!alvo) return;
        alvo.textContent = resultado.pode_avancar
            ? 'CNH validada'
            : 'CNH ainda pendente — será solicitado preenchimento manual se necessário';
    });
}

function recCamCapturarFrame(video) {
    const canvas = document.createElement('canvas');
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
    return canvas;
}

function telaRecCnhManual() {
    return `<div class="titulo">Preencher dados da CNH manualmente</div>
        <div class="subtitulo">Um atendente vai revisar esses dados depois</div>
        <div class="grade-campos">
            ${campo('Nome completo', 'recManualCnhNome', '')}
            ${campo('CPF', 'recManualCnhCpf', '')}
            ${campo('Validade (AAAA-MM-DD)', 'recManualCnhValidade', '')}
        </div>
        <div class="status-scanner" id="recManualStatus"></div>
        <button class="btn-primario" id="recManualBtn" style="max-width:320px;margin:0 auto" onclick="recConfirmarCnhManual()">Confirmar</button>`;
}

async function recConfirmarCnhManual() {
    if (state.rec.emAndamento) return;
    const nome = document.getElementById('recManualCnhNome').value.trim();
    const cpf = document.getElementById('recManualCnhCpf').value.trim();
    const validade = document.getElementById('recManualCnhValidade').value.trim();
    const statusEl = document.getElementById('recManualStatus');

    if (!nome) return mostrarErroTela('Informe o nome completo');
    if (cpf.replace(/\D/g, '').length !== 11) return mostrarErroTela('CPF inválido');
    if (!validade) return mostrarErroTela('Informe a validade da CNH');

    state.rec.emAndamento = true;
    const btn = document.getElementById('recManualBtn');
    if (btn) btn.disabled = true;
    if (statusEl) { statusEl.textContent = 'Enviando...'; statusEl.classList.remove('erro'); }

    try {
        const resultado = await api('documento.php', 'preencher-manual', {
            id_atendimento: state.idAtendimento,
            tipo: 'cnh',
            nome, cpf, validade,
        });
        if (!resultado.pode_avancar) {
            const msg = resultado.motivo || 'Dados da CNH não aprovados';
            mostrarErroTela(msg);
            if (statusEl) { statusEl.textContent = msg; statusEl.classList.add('erro'); }
            return;
        }
        state.rec.cnhOrigem = 'MANUAL';
        await tentarAvancarEtapaDocumentos('rec_cnh_manual', 'rec_crlv_manual');
    } catch (e) {
        mostrarErroTela(e.message);
        if (statusEl) { statusEl.textContent = e.message; statusEl.classList.add('erro'); }
    } finally {
        state.rec.emAndamento = false;
        if (btn) btn.disabled = false;
    }
}

function telaRecCrlvManual() {
    return `<div class="titulo">Preencher dados do CRLV manualmente</div>
        <div class="subtitulo">Um atendente vai revisar esses dados depois</div>
        <div class="grade-campos">
            ${campo('Placa', 'recManualCrlvPlaca', state.placa)}
            ${campo('Exercício', 'recManualCrlvExercicio', '')}
            ${campoSelectUf('UF', 'recManualCrlvUf', '')}
            ${campo('RNTC', 'recManualCrlvRntc', '')}
            ${campo('Tipo de veículo', 'recManualCrlvTipoVeiculo', '')}
        </div>
        <div class="status-scanner" id="recManualStatus"></div>
        <button class="btn-primario" id="recManualBtn" style="max-width:320px;margin:0 auto" onclick="recConfirmarCrlvManual()">Confirmar</button>`;
}

async function recConfirmarCrlvManual() {
    if (state.rec.emAndamento) return;
    const placa = document.getElementById('recManualCrlvPlaca').value.trim();
    const exercicio = document.getElementById('recManualCrlvExercicio').value.trim();
    const uf = document.getElementById('recManualCrlvUf').value.trim();
    const rntc = document.getElementById('recManualCrlvRntc').value.trim();
    const tipoVeiculo = document.getElementById('recManualCrlvTipoVeiculo').value.trim();
    const statusEl = document.getElementById('recManualStatus');

    if (!placa) return mostrarErroTela('Informe a placa');
    if (!/^\d+$/.test(exercicio)) return mostrarErroTela('Exercício inválido');
    if (!uf) return mostrarErroTela('Selecione a UF');

    state.rec.emAndamento = true;
    const btn = document.getElementById('recManualBtn');
    if (btn) btn.disabled = true;
    if (statusEl) { statusEl.textContent = 'Enviando...'; statusEl.classList.remove('erro'); }

    try {
        const resultado = await api('documento.php', 'preencher-manual', {
            id_atendimento: state.idAtendimento,
            tipo: 'crlv',
            placa, exercicio, uf, rntc, tipo_veiculo: tipoVeiculo,
        });
        if (!resultado.pode_avancar) {
            const msg = resultado.motivo || 'Dados do CRLV não aprovados';
            mostrarErroTela(msg);
            if (statusEl) { statusEl.textContent = msg; statusEl.classList.add('erro'); }
            return;
        }
        state.rec.crlvOrigem = 'MANUAL';
        await tentarAvancarEtapaDocumentos('rec_cnh_manual', 'rec_crlv_manual');
    } catch (e) {
        mostrarErroTela(e.message);
        if (statusEl) { statusEl.textContent = e.message; statusEl.classList.add('erro'); }
    } finally {
        state.rec.emAndamento = false;
        if (btn) btn.disabled = false;
    }
}

function telaRecAguardeDocumentos() {
    return `<div class="titulo">Estamos validando seus documentos. Aguarde.</div>
        <div class="impr-spinner" aria-hidden="true"></div>
        <div class="status-leitura" id="recAguardeCnhStatus"></div>
        <div class="status-leitura" id="recAguardeCrlvStatus"></div>`;
}

async function processarAguardeDocumentosRec() {
    state.rec.cnhAoAtualizar = (status) => {
        const el = document.getElementById('recAguardeCnhStatus');
        if (el) el.textContent = 'CNH: ' + rotuloStatusProcessamento(status.status_processamento);
    };
    state.rec.crlvAoAtualizar = (status) => {
        const el = document.getElementById('recAguardeCrlvStatus');
        if (el) el.textContent = 'CRLV: ' + rotuloStatusProcessamento(status.status_processamento);
    };
    if (state.rec.cnhUltimoStatus) state.rec.cnhAoAtualizar(state.rec.cnhUltimoStatus);
    if (state.rec.crlvUltimoStatus) state.rec.crlvAoAtualizar(state.rec.crlvUltimoStatus);

    const resultado = await aguardarDocumentos(state.idAtendimento, state.rec.cnhPromise, state.rec.crlvPromise);
    state.rec.cnhAoAtualizar = null;
    state.rec.crlvAoAtualizar = null;
    state.rec.cnhPromise = null;
    state.rec.crlvPromise = null;
    if (resultado.cnh.pode_avancar) state.rec.cnhOrigem = resultado.cnh.origem || 'VIO_VALIDADO';
    if (resultado.crlv.pode_avancar) state.rec.crlvOrigem = resultado.crlv.origem || 'VIO_VALIDADO';
    if (state.tela !== 'rec_aguarde_documentos') return;
    registrarMotivosReprovacao('rec', resultado);
    await tentarAvancarEtapaDocumentos('rec_cnh_manual', 'rec_crlv_manual');
}

function iniciarApp() {
    document.getElementById('app').innerHTML = `
        <div class="barra-cancelar" id="barraCancelar" style="display:none">
            <button class="btn-cancelar" onclick="confirmarCancelar()">✕ Cancelar atendimento</button>
        </div>
        <div class="tela" id="tela"></div>
        <div class="teclado" id="teclado"></div>
        <div class="modal-fundo" id="modalFundo"><div class="modal-caixa" id="modalCaixa"></div></div>
        <div class="modal-fundo modal-fundo-confirma-nota" id="modalConfirmCancelNotaFundo"><div class="modal-caixa" id="modalConfirmCancelNotaCaixa"></div></div>
        <div class="modal-fundo modal-fundo-confirma-excluir" id="modalConfirmExcluirNotaFundo"><div class="modal-caixa" id="modalConfirmExcluirNotaCaixa" role="dialog" aria-modal="true"></div></div>
        <div class="modal-fundo modal-fundo-inatividade" id="modalInatividadeFundo"><div class="modal-caixa" id="modalInatividadeCaixa"></div></div>
        <div id="toastErro" class="toast-erro" role="alert" aria-live="assertive" aria-atomic="true"></div>
    `;
    montarTeclado();
    document.getElementById('tela').addEventListener('focusin', e => {
        if (e.target.classList.contains('kb-input')) abrirTeclado(e.target);
        if ((e.target.classList.contains('kb-input') || e.target.classList.contains('conf-valor')) && typeof e.target.scrollIntoView === 'function') {
            setTimeout(() => e.target.scrollIntoView({ block: 'center' }), 80);
        }
    });
    document.getElementById('tela').addEventListener('input', e => {
        if (e.target.classList && e.target.classList.contains('conf-valor')) ajustarAlturaTextarea(e.target);
    });
    ir('lgpd');
}

iniciarApp();
