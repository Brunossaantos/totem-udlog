// ===================================================================
// Totem UDLOG — front-end real (Expedicao e Recebimento)
// Le o token do totem em data-totem-token (definido pelo index.php)
// ===================================================================

const TOKEN = document.body.dataset.totemToken;
const API_BASE = '/api/';

const state = {
    tela: 'home',
    tipo: null,
    idAtendimento: null,
    placa: '',
    ordens: [],
    dados: {},
    notaOrdem: 0,
    notasImagens: [],
    previewNotaAtual: null,
    capturaNotaEmAndamento: false,
    finalizandoDigitalizacao: false,
    clienteIdentificado: false,
    ultimaLeituraQr: null,
    // estado dedicado a captura/validacao de CNH/CRLV da Expedicao via VIO
    // Decode (demanda expedicao-vio-cnh-crlv) — nao compartilhado com Recebimento.
    // cnhPromise/crlvPromise: Promise fire-and-forget viva em memoria do
    // iniciar-processamento em segundo plano (REPLANEJAMENTO 2026-09-09,
    // ciclo assincrono) — perdida ao recarregar a pagina (limitacao aceita,
    // ver docs/handoffs/2026-09-08-expedicao-vio-cnh-crlv.md), nesse caso a
    // tela de espera recorre a polling (ver aguardarDocumentos()).
    exp: { previewImg: null, previewCanvas: null, cnhFrenteImg: null, cnhOrigem: null, crlvOrigem: null, emAndamento: false, cnhPromise: null, crlvPromise: null },
    // mesmo padrao para Recebimento (REPLANEJAMENTO 2026-09-09 estendeu a
    // validacao VIO Decode tambem para o Recebimento) — telas/estado NOVOS E
    // DEDICADOS, nao compartilhados com a Expedicao nem com o fluxo antigo
    // de rec_cnh/rec_crlv (semantica diferente, sem QR).
    rec: { previewImg: null, previewCanvas: null, cnhOrigem: null, crlvOrigem: null, emAndamento: false, cnhPromise: null, crlvPromise: null },
};

function estadoExpVazio() {
    return { previewImg: null, previewCanvas: null, cnhFrenteImg: null, cnhOrigem: null, crlvOrigem: null, emAndamento: false, cnhPromise: null, crlvPromise: null };
}

function estadoRecVazio() {
    return { previewImg: null, previewCanvas: null, cnhOrigem: null, crlvOrigem: null, emAndamento: false, cnhPromise: null, crlvPromise: null };
}

// -------------------- comunicacao com a API --------------------

async function api(arquivo, acao, corpo) {
    const res = await fetch(`${API_BASE}${arquivo}?acao=${acao}`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Authorization': `Bearer ${TOKEN}`,
        },
        body: JSON.stringify(corpo || {}),
    });
    const json = await res.json();
    if (!json.sucesso) {
        const erro = new Error(json.erro || 'Erro desconhecido');
        erro.status = res.status;
        throw erro;
    }
    return json.dados;
}

function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function mostrarErroTela(msg) {
    const el = document.getElementById('toastErro');
    el.textContent = msg;
    el.style.display = 'block';
    clearTimeout(mostrarErroTela._timer);
    mostrarErroTela._timer = setTimeout(() => { el.style.display = 'none'; }, 4000);
}

// -------------------- teclado virtual (padrao pt-BR, numeros na 1a linha) --------------------

let campoAtivo = null;

function montarTeclado() {
    const linhas = [
        ['1', '2', '3', '4', '5', '6', '7', '8', '9', '0'],
        ['Q', 'W', 'E', 'R', 'T', 'Y', 'U', 'I', 'O', 'P'],
        ['A', 'S', 'D', 'F', 'G', 'H', 'J', 'K', 'L', 'Ç'],
        ['Z', 'X', 'C', 'V', 'B', 'N', 'M', ',', '.'],
    ];
    let html = '';
    linhas.forEach((linha, i) => {
        html += '<div class="linha-teclas">';
        linha.forEach(t => { html += `<button type="button" class="tecla" onclick="digitar('${t}')">${t}</button>`; });
        if (i === 0) html += `<button type="button" class="tecla tecla-apagar" onclick="apagar()">⌫</button>`;
        html += '</div>';
    });
    html += `<div class="linha-teclas">
        <button type="button" class="tecla tecla-espaco" onclick="digitar(' ')">espaço</button>
        <button type="button" class="tecla tecla-ok" onclick="fecharTeclado()">OK</button>
    </div>`;
    document.getElementById('teclado').innerHTML = html;
}

function abrirTeclado(el) { campoAtivo = el; document.getElementById('teclado').classList.add('aberto'); }
function fecharTeclado() { document.getElementById('teclado').classList.remove('aberto'); campoAtivo = null; }
function digitar(c) { if (campoAtivo) campoAtivo.value += c; }
function apagar() { if (campoAtivo) campoAtivo.value = campoAtivo.value.slice(0, -1); }

// -------------------- inatividade --------------------

let idleTimer = null;
const IDLE_MS = 180000;
const IDLE_ABANDONO_MS = 30000;

function reiniciarIdle() {
    clearTimeout(idleTimer);
    if (state.tela !== 'home') idleTimer = setTimeout(mostrarInatividade, IDLE_MS);
}

function mostrarInatividade() {
    abrirModal(`
        <div class="titulo">Ainda está aí?</div>
        <div class="subtitulo">Toque na tela para continuar o atendimento.</div>
        <button class="btn-primario" onclick="fecharModal(); reiniciarIdle();">Continuar</button>
    `);
    // se ninguem responder ao aviso, cancela e volta pro inicio por seguranca
    idleTimer = setTimeout(() => { fecharModal(); cancelarESair(); }, IDLE_ABANDONO_MS);
}

['click', 'touchstart', 'keydown'].forEach(evento => document.addEventListener(evento, reiniciarIdle));

// -------------------- modal generico --------------------

function abrirModal(html) {
    document.getElementById('modalCaixa').innerHTML = html;
    document.getElementById('modalFundo').classList.add('aberto');
}
function fecharModal() { document.getElementById('modalFundo').classList.remove('aberto'); }

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
        try { await api('atendimento.php', 'cancelar', { id_atendimento: state.idAtendimento }); } catch (e) { /* segue mesmo se falhar */ }
    }
    novoAtendimento();
}

// -------------------- navegacao --------------------

function ir(tela) {
    pararCamera();
    state.tela = tela;
    document.getElementById('barraCancelar').style.display = tela === 'home' ? 'none' : 'block';
    fecharTeclado();
    fecharModal();
    renderTela();
    reiniciarIdle();
}

function renderTela() {
    const tela = document.getElementById('tela');
    switch (state.tela) {
        case 'home': tela.innerHTML = telaHome(); break;
        case 'exp_placa': tela.innerHTML = telaPlacaExpedicao(); break;
        case 'exp_selecionar_ordem': tela.innerHTML = telaSelecionarOrdem(); ligarCartoesOrdem(); break;
        case 'exp_dados': tela.innerHTML = telaDados(); break;
        case 'exp_cnh_frente': tela.innerHTML = telaExpCnhFrente(); iniciarCameraExp(); break;
        case 'exp_cnh_verso': tela.innerHTML = telaExpCnhVerso(); iniciarCameraExp(); break;
        case 'exp_cnh_manual': tela.innerHTML = telaExpCnhManual(); break;
        case 'exp_crlv': tela.innerHTML = telaExpCrlv(); iniciarCameraExp(); exibirIndicadorProcessamentoCnh(); break;
        case 'exp_crlv_manual': tela.innerHTML = telaExpCrlvManual(); break;
        case 'exp_aguarde_documentos': tela.innerHTML = telaExpAguardeDocumentos(); processarAguardeDocumentosExp(); break;
        case 'exp_confirma': tela.innerHTML = telaConfirma('retirada de carga'); break;
        case 'exp_ajudante': tela.innerHTML = telaAjudante(); break;
        case 'exp_impressao': tela.innerHTML = telaImpressao(); processarImpressao(); break;
        case 'rec_placa_qtd': tela.innerHTML = telaRecPlacaQtd(); break;
        case 'rec_bloqueado': tela.innerHTML = telaBloqueado(); break;
        case 'rec_digitaliza': tela.innerHTML = telaDigitaliza(); iniciarCameraScanner(); break;
        case 'rec_cliente': tela.innerHTML = telaCliente(); habilitarAutocompleteCliente(); break;
        case 'rec_cnh': tela.innerHTML = telaCaptura('Posicione a CNH no leitor'); iniciarCamera(); habilitarLeitorScanner(); break;
        // NOTA (demanda expedicao-vio-cnh-crlv, REPLANEJAMENTO 2026-09-09): o
        // backend agora usa a etapa/tela 'rec_crlv' para a NOVA captura via QR
        // (mesmo nome literal que a tela ANTIGA acima usava para o leitor
        // HID sem QR). Como o backend ja emite esse nome (AtendimentoController::
        // SEQUENCIA_RECEBIMENTO_DOCUMENTOS, ja implementado nesta mesma rodada)
        // e nada mais no front chama mais ir('rec_cnh')/ir('rec_crlv') no fluxo
        // antigo (concluirDigitalizacao ja emite 'rec_cnh_frente' desde o
        // replanejamento), o case abaixo SUBSTITUI o antigo (rota inalcancavel
        // por qualquer chamada real hoje) em vez de duplicar o rotulo — JS so
        // executaria o primeiro 'case' de qualquer forma. Telas/funcoes
        // NOVAS E DEDICADAS (recCam*), a antiga telaCaptura/iniciarCamera/
        // habilitarLeitorScanner continuam intocadas e exclusivas de 'rec_cnh'.
        case 'rec_cnh_frente': tela.innerHTML = telaRecCnhFrente(); iniciarCameraRec(); break;
        case 'rec_cnh_verso': tela.innerHTML = telaRecCnhVerso(); iniciarCameraRec(); break;
        case 'rec_cnh_manual': tela.innerHTML = telaRecCnhManual(); break;
        case 'rec_crlv': tela.innerHTML = telaRecCrlv(); iniciarCameraRec(); exibirIndicadorProcessamentoCnhRec(); break;
        case 'rec_crlv_manual': tela.innerHTML = telaRecCrlvManual(); break;
        case 'rec_aguarde_documentos': tela.innerHTML = telaRecAguardeDocumentos(); processarAguardeDocumentosRec(); break;
        case 'rec_confirma': tela.innerHTML = telaConfirma('entrega de carga'); break;
        case 'rec_ajudante': tela.innerHTML = telaAjudante(); break;
        case 'rec_impressao': tela.innerHTML = telaImpressao(); processarImpressao(); break;
    }
}

function novoAtendimento() {
    Object.assign(state, {
        tela: 'home', tipo: null, idAtendimento: null, placa: '',
        ordens: [], dados: {}, notaOrdem: 0, notasImagens: [], previewNotaAtual: null,
        capturaNotaEmAndamento: false, finalizandoDigitalizacao: false, clienteIdentificado: false, ultimaLeituraQr: null,
        exp: estadoExpVazio(),
        rec: estadoRecVazio(),
    });
    ocrFila = [];
    ir('home');
}

// -------------------- tela: inicio --------------------

function telaHome() {
    return `<div class="titulo">Selecione o tipo de atendimento</div>
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

// -------------------- expedicao: placa --------------------

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
        const dados = await api('atendimento.php', 'iniciar', { tipo: 'expedicao', placa });
        state.idAtendimento = dados.id_atendimento;
        if (dados.proxima_tela === 'selecionar_ordem') {
            state.ordens = dados.ordens;
            ir('exp_selecionar_ordem');
        } else {
            state.dados = dados.dados;
            ir('exp_dados');
        }
    } catch (e) { mostrarErroTela(e.message); }
}

// -------------------- expedicao: selecionar ordem de coleta --------------------

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

// -------------------- expedicao: dados encontrados --------------------

function campo(rotulo, id, valor) {
    return `<div class="campo"><label>${rotulo}</label><input class="kb-input" id="${id}" value="${escapeHtml(valor)}"></div>`;
}

// Lista fechada das 27 UFs brasileiras (demanda integracao-talent-portaria-checkin,
// 2026-09-09) — usada tanto no preenchimento manual do CRLV quanto na tela de
// confirmacao, SEMPRE como <select> fechado, nunca texto livre. Espelha
// App\Rn\DocumentoRn::UFS_VALIDAS no backend (backend revalida de qualquer forma,
// esta lista e so para a UX do totem).
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
        // maquina de estados REAL do backend (demanda expedicao-vio-cnh-crlv):
        // o front nunca decide sozinho ir para exp_cnh — so avanca se autorizado.
        const dados = await api('atendimento.php', 'avancar-etapa-documentos', { id_atendimento: state.idAtendimento });
        if (dados.proxima_tela === 'exp_cnh') {
            ir('exp_cnh_frente');
        } else {
            mostrarErroTela('Não foi possível avançar agora.');
            if (btn) btn.disabled = false;
        }
    } catch (e) {
        mostrarErroTela(e.message);
        if (btn) btn.disabled = false;
    }
}

// -------------------- captura de documento (CNH / CRLV — usado nos dois fluxos) --------------------

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
    // comprime antes de gerar o base64 — evita payload gigante pro Talent depois
    const largura = 900;
    const escala = video.videoWidth ? largura / video.videoWidth : 1;
    canvas.width = largura;
    canvas.height = (video.videoHeight || 675) * escala;
    canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
    return canvas.toDataURL('image/jpeg', 0.7);
}

// leitor Netum SD2000: funciona como teclado (HID), entao so precisamos
// manter um campo oculto focado e ouvir o Enter que ele dispara no final
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

async function capturarDocumento() {
    const tipo = (state.tela === 'exp_cnh' || state.tela === 'rec_cnh') ? 'cnh' : 'crlv';
    const imagem = capturarFotoBase64();
    try {
        await api('documento.php', 'upload', { id_atendimento: state.idAtendimento, tipo, imagem });
    } catch (e) { return mostrarErroTela(e.message); }

    const proxima = { exp_cnh: 'exp_crlv', exp_crlv: 'exp_confirma', rec_cnh: 'rec_crlv', rec_crlv: 'rec_confirma' }[state.tela];
    ir(proxima);
}

// ===================================================================
// EXPEDICAO — captura/validacao de CNH e CRLV via VIO Decode (QR code)
// Demanda expedicao-vio-cnh-crlv (2026-09-08; REPLANEJAMENTO 2026-09-09
// tornou o processamento assincrono do lado do navegador — ver secao
// "PROCESSAMENTO ASSINCRONO DE CNH/CRLV" mais abaixo). Telas e funcoes NOVAS
// E DEDICADAS: nao reaproveitam telaCaptura/iniciarCamera/capturarFotoBase64/
// capturarDocumento (que continuam exclusivas da etapa legada 'rec_cnh',
// acima), para nao alterar nada do fluxo antigo do Recebimento. O front
// NUNCA decide sozinho mudar de macro-etapa (exp_cnh -> exp_crlv ->
// exp_aguarde_documentos -> exp_confirmacao) — toda transicao passa por
// atendimento.php?acao=avancar-etapa-documentos, e so muda de tela se o
// backend autorizar.
// ===================================================================

// -------------------- expedicao: camera dedicada (Netum, mesmo deviceId salvo do scanner de notas) --------------------

let expCamStream = null;
let expCamDeviceId = null;
let expCamVideoPronto = false;
let expCamDeviceChangeAtivo = false;

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
        // mesma chave de localStorage ja usada pelo scanner de notas do
        // Recebimento (totem_scanner_deviceId) — mesmo hardware fisico (Netum)
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
        const video = document.getElementById('expCamVideo');
        if (!video) { stream.getTracks().forEach(t => t.stop()); expCamStream = null; return; }
        video.srcObject = stream;
        video.onloadedmetadata = () => {
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
    if (!['exp_cnh_frente', 'exp_cnh_verso', 'exp_crlv'].includes(state.tela)) return;
    try {
        const devices = await navigator.mediaDevices.enumerateDevices();
        const aindaConectado = devices.some(d => d.kind === 'videoinput' && d.deviceId === expCamDeviceId);
        if (!aindaConectado) {
            expCamVideoPronto = false;
            expCamMostrarStatus('A câmera foi desconectada. Reconecte o dispositivo e tente novamente.', true);
            expCamAtualizarBotao();
        }
    } catch (e) { /* falha ao enumerar aqui nao pode travar a tela */ }
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

// -------------------- expedicao: telas de captura (frente/verso CNH, CRLV) --------------------

function telaExpCaptura(titulo) {
    return `<div class="titulo">${titulo}</div>
        <div class="caixa-scanner" id="expCamCaixa">
            <video id="expCamVideo" autoplay playsinline></video>
        </div>
        <img id="expCamPreview" class="previa-nota" style="display:none" alt="Foto capturada">
        <div class="status-scanner" id="expCamStatus">Conectando à câmera...</div>
        <div class="status-leitura" id="expBgStatus" style="display:none"></div>
        <div class="grupo-botoes" id="expCamControles">
            <button class="btn-primario" id="expCamBtnCapturar" onclick="expCapturarFoto()" disabled>Capturar</button>
        </div>`;
}
function telaExpCnhFrente() { return telaExpCaptura('Fotografe a frente da CNH'); }
function telaExpCnhVerso() { return telaExpCaptura('Fotografe o verso da CNH (o QR code geralmente fica aqui)'); }
function telaExpCrlv() { return telaExpCaptura('Fotografe o CRLV completo'); }

// Indicador DISCRETO e NAO BLOQUANTE (nunca modal, nunca header fixo) de que
// a CNH ainda esta sendo validada em segundo plano enquanto o motorista ja
// esta fotografando o CRLV — item 2 do escopo do REPLANEJAMENTO 2026-09-09.
// So aparece se ainda houver uma Promise viva (senao a CNH ja terminou ou a
// pagina foi recarregada, e nesse caso nada e mostrado aqui — a tela de
// espera de exp_aguarde_documentos e quem trata o caso de reload).
function exibirIndicadorProcessamentoCnh() {
    const el = document.getElementById('expBgStatus');
    if (!el || !state.exp.cnhPromise) return;
    el.textContent = 'Validando CNH em segundo plano...';
    el.style.display = 'block';
    state.exp.cnhPromise.then(resultado => {
        if (!el.isConnected) return; // a tela ja pode ter mudado
        el.textContent = resultado.pode_avancar
            ? 'CNH validada'
            : 'CNH ainda pendente — será solicitado preenchimento manual se necessário';
    });
}

// -------------------- expedicao: tela de espera apos CRLV confirmado --------------------
// So existe DEPOIS do CRLV (nunca entre CNH e CRLV) — texto fixo pedido no
// escopo, sem loading intermediario nenhum antes disso.
function telaExpAguardeDocumentos() {
    return `<div class="titulo">Estamos validando seus documentos. Aguarde.</div>`;
}

async function processarAguardeDocumentosExp() {
    const resultado = await aguardarDocumentos(state.idAtendimento, state.exp.cnhPromise, state.exp.crlvPromise);
    state.exp.cnhPromise = null;
    state.exp.crlvPromise = null;
    if (resultado.cnh.pode_avancar) state.exp.cnhOrigem = resultado.cnh.aviso_trial ? 'VIO_TRIAL' : 'VIO_VALIDADO';
    if (resultado.crlv.pode_avancar) state.exp.crlvOrigem = resultado.crlv.aviso_trial ? 'VIO_TRIAL' : 'VIO_VALIDADO';
    if (state.tela !== 'exp_aguarde_documentos') return; // usuario ja saiu da tela (ex.: cancelou)
    await tentarAvancarEtapaDocumentos('exp_cnh_manual', 'exp_crlv_manual');
}

function expCamCapturarFrame(video) {
    const canvas = document.createElement('canvas');
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
    return canvas;
}

function expCapturarFoto() {
    const video = document.getElementById('expCamVideo');
    if (!video || video.readyState < 2 || !video.videoWidth || !video.videoHeight) {
        expCamMostrarStatus('Aguarde o vídeo carregar.', true);
        return;
    }
    expCamMostrarStatus('Capturando imagem...');
    const canvas = expCamCapturarFrame(video);
    state.exp.previewCanvas = canvas;
    state.exp.previewImg = canvas.toDataURL('image/jpeg', 0.85);
    expExibirPreview();
}

function expExibirPreview() {
    const caixa = document.getElementById('expCamCaixa');
    if (caixa) caixa.style.display = 'none';
    const img = document.getElementById('expCamPreview');
    if (img) { img.src = state.exp.previewImg; img.style.display = 'block'; }
    const controles = document.getElementById('expCamControles');
    if (controles) {
        controles.innerHTML = `
            <button class="btn-primario" id="expCamBtnUsar" onclick="expConfirmarFoto()">Usar foto</button>
            <button class="btn-fantasma" id="expCamBtnRefazer" onclick="expRefazerFoto()">Refazer</button>`;
    }
}

function expVoltarParaVideoAoVivo() {
    const img = document.getElementById('expCamPreview');
    if (img) img.style.display = 'none';
    const caixa = document.getElementById('expCamCaixa');
    if (caixa) caixa.style.display = '';
    const controles = document.getElementById('expCamControles');
    if (controles) controles.innerHTML = `<button class="btn-primario" id="expCamBtnCapturar" onclick="expCapturarFoto()">Capturar</button>`;
    expCamAtualizarBotao();
}

function expRefazerFoto() {
    state.exp.previewImg = null;
    state.exp.previewCanvas = null;
    expVoltarParaVideoAoVivo();
    expCamMostrarStatus(expCamVideoPronto ? 'Câmera pronta' : 'Conectando à câmera...');
}

// oferece ao motorista as duas saidas quando o QR nao pode ser lido/aprovado
// — nunca trava sem saida (item 3 do escopo)
function expOferecerFallback(tipo) {
    const rotulo = tipo === 'cnh' ? 'CNH' : 'CRLV';
    const telaManual = tipo === 'cnh' ? 'exp_cnh_manual' : 'exp_crlv_manual';
    abrirModal(`
        <div class="titulo">Não foi possível validar a ${rotulo}</div>
        <div class="subtitulo">Você pode tentar capturar novamente ou preencher os dados manualmente.</div>
        <button class="btn-primario" onclick="fecharModal(); expRefazerFoto();">Tentar novamente</button>
        <button class="btn-fantasma" onclick="fecharModal(); ir('${telaManual}');">Preencher dados manualmente</button>
    `);
}

// botao "Capturar"/"Usar foto" desabilitado durante qualquer chamada de rede
// em andamento (item 3 do escopo) — reabilitado so apos a resposta
async function expConfirmarFoto() {
    if (state.exp.emAndamento) return;
    state.exp.emAndamento = true;
    const btnUsar = document.getElementById('expCamBtnUsar');
    const btnRefazer = document.getElementById('expCamBtnRefazer');
    if (btnUsar) btnUsar.disabled = true;
    if (btnRefazer) btnRefazer.disabled = true;
    try {
        if (state.tela === 'exp_cnh_frente') {
            // CNH frente/verso sao enviadas JUNTAS num unico upload (contrato
            // do backend) — aqui so guarda a frente e avanca localmente para
            // a captura do verso (mesma macro-etapa exp_cnh, sem chamada ao
            // backend ainda)
            state.exp.cnhFrenteImg = state.exp.previewImg;
            ir('exp_cnh_verso');
        } else if (state.tela === 'exp_cnh_verso') {
            await expProcessarCnhVerso();
        } else if (state.tela === 'exp_crlv') {
            await expProcessarCrlv();
        }
    } finally {
        state.exp.emAndamento = false;
        if (btnUsar) btnUsar.disabled = false;
        if (btnRefazer) btnRefazer.disabled = false;
    }
}

async function expProcessarCnhVerso() {
    const versoImg = state.exp.previewImg;
    expCamMostrarStatus('Enviando documento...');
    try {
        await api('documento.php', 'upload', {
            id_atendimento: state.idAtendimento,
            tipo: 'cnh',
            imagem_frente: state.exp.cnhFrenteImg,
            imagem_verso: versoImg,
        });
    } catch (e) {
        expCamMostrarStatus(e.message, true);
        mostrarErroTela(e.message);
        return;
    }

    expCamMostrarStatus('Lendo QR code...');
    const qr = await expLerQrDaImagemCapturada();
    if (!qr.ok) {
        expOferecerFallback('cnh');
        return;
    }

    // ASSINCRONO (REPLANEJAMENTO 2026-09-09, item 1 do escopo): dispara a
    // validacao em segundo plano SEM aguardar (fire-and-forget) e libera a
    // captura do CRLV imediatamente — a Promise fica guardada para a tela de
    // espera (exp_aguarde_documentos) usar depois via Promise.all.
    state.exp.cnhPromise = iniciarProcessamentoDocumento(state.idAtendimento, 'cnh', qr.binaryData);
    expCamMostrarStatus('CNH enviada para validação');

    await tentarAvancarEtapaDocumentos('exp_cnh_manual', 'exp_crlv_manual');
}

async function expProcessarCrlv() {
    const crlvImg = state.exp.previewImg;
    expCamMostrarStatus('Enviando documento...');
    try {
        await api('documento.php', 'upload', {
            id_atendimento: state.idAtendimento,
            tipo: 'crlv',
            imagem: crlvImg,
        });
    } catch (e) {
        expCamMostrarStatus(e.message, true);
        mostrarErroTela(e.message);
        return;
    }

    expCamMostrarStatus('Lendo QR code...');
    const qr = await expLerQrDaImagemCapturada();
    if (!qr.ok) {
        expOferecerFallback('crlv');
        return;
    }

    // ASSINCRONO — dispara a validacao do CRLV em segundo plano (fire-and-
    // -forget) e segue direto para a tela de espera (exp_aguarde_documentos
    // so aparece a partir daqui, nunca entre CNH e CRLV — item 4 do escopo).
    state.exp.crlvPromise = iniciarProcessamentoDocumento(state.idAtendimento, 'crlv', qr.binaryData);
    expCamMostrarStatus('CRLV enviado para validação');

    await tentarAvancarEtapaDocumentos('exp_cnh_manual', 'exp_crlv_manual');
}

// -------------------- expedicao: preenchimento manual (fallback do QR) --------------------

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

    // validacao client-side leve, so para feedback rapido — a validacao real
    // e sempre no backend (preencher-manual)
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

// -------------------- expedicao: leitura de QR code via Web Worker (jsQR) --------------------
// Worker criado UMA UNICA VEZ e reaproveitado entre capturas (nunca recriado
// a cada leitura). jsQR e uma funcao sincrona sem Worker interno proprio —
// diferente do bug ja documentado do Tesseract.js (Worker aninhado), rodar
// jsQR DENTRO deste worker (nao aninhado dentro de outro) e seguro.

let qrWorker = null;
let qrWorkerCallbackPendente = null;

function obterQrWorker() {
    if (!qrWorker) {
        qrWorker = new Worker('assets/qr-worker.js');
        qrWorker.onmessage = (e) => {
            const cb = qrWorkerCallbackPendente;
            qrWorkerCallbackPendente = null;
            if (cb) cb(e.data);
        };
        qrWorker.onerror = () => {
            const cb = qrWorkerCallbackPendente;
            qrWorkerCallbackPendente = null;
            if (cb) cb({ ok: false });
        };
    }
    return qrWorker;
}

// le o QR a partir do MESMO canvas ja capturado (evita redecodificar a
// imagem gerada no upload) — capturas sao sequenciais (uma por vez, o botao
// fica desabilitado durante a chamada), entao um unico callback pendente
// por vez e suficiente
function lerQrDoCanvas(canvas) {
    return new Promise((resolve) => {
        try {
            const ctx = canvas.getContext('2d');
            const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
            const worker = obterQrWorker();
            qrWorkerCallbackPendente = resolve;
            // envia o proprio ImageData (structured clone), transferindo o
            // buffer subjacente (imageData.data.buffer) para evitar copia
            worker.postMessage(imageData, [imageData.data.buffer]);
        } catch (e) {
            resolve({ ok: false });
        }
    });
}

async function expLerQrDaImagemCapturada() {
    if (!state.exp.previewCanvas) return { ok: false };
    try {
        return await lerQrDoCanvas(state.exp.previewCanvas);
    } catch (e) {
        return { ok: false };
    }
}

// bytes do QR (array de numeros 0-255, vindo do jsQR) -> base64, para envio
// ao backend em documento.php?acao=validar-qr (nunca string UTF-8 direta —
// item 5 do escopo)
function bytesArrayParaBase64(bytesArray) {
    let binario = '';
    const tamanhoBloco = 0x8000;
    for (let i = 0; i < bytesArray.length; i += tamanhoBloco) {
        binario += String.fromCharCode.apply(null, bytesArray.slice(i, i + tamanhoBloco));
    }
    return btoa(binario);
}

// ===================================================================
// PROCESSAMENTO ASSINCRONO DE CNH/CRLV (VIO Decode) — Expedicao E Recebimento
// (REPLANEJAMENTO 2026-09-09). Reaproveitado pelos dois fluxos por ser
// mecanica pura de rede/tempo, sem nenhuma logica de tela/estado especifica
// de Expedicao ou Recebimento — os endpoints documento.php?acao=iniciar-
// -processamento/status-processamento e atendimento.php?acao=avancar-etapa-
// -documentos ja sao agnosticos de tipo_atendimento. Mesmo espirito de
// reaproveitamento ja usado para obterQrWorker()/bytesArrayParaBase64().
// ===================================================================

const PROCESSAMENTO_TIMEOUT_MS = 45000;
const PROCESSAMENTO_POLL_INTERVAL_MS = 2000;

// Dispara iniciar-processamento SEM aguardar (fire-and-forget) — quem chama
// guarda a Promise retornada (state.exp.cnhPromise/crlvPromise ou
// state.rec.cnhPromise/crlvPromise) para usar depois via aguardarDocumentos(),
// mas NUNCA usa await no ponto de disparo (item 1 do escopo: nenhum await
// bloqueante entre confirmar a foto e liberar a proxima captura). Erros de
// rede sao capturados aqui (nunca viram rejection nao tratada) e devolvidos
// como resultado nao aprovado, para serem tratados como qualquer outra falha
// de validacao pelo chamador.
function iniciarProcessamentoDocumento(idAtendimento, tipo, qrBytesArray) {
    return api('documento.php', 'iniciar-processamento', {
        id_atendimento: idAtendimento,
        tipo,
        qr_bytes_base64: bytesArrayParaBase64(qrBytesArray),
    }).catch(e => ({ ok: false, pode_avancar: false, motivo: e.message, terminal: true }));
}

async function consultarStatusProcessamento(idAtendimento, tipo) {
    try {
        return await api('documento.php', 'status-processamento', { id_atendimento: idAtendimento, tipo });
    } catch (e) {
        return { ok: false, pode_avancar: false, motivo: e.message, terminal: false };
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

// Faz polling em status-processamento a cada 2s (nunca dispara nova chamada
// de iniciar-processamento) ate o documento chegar a estado terminal ou o
// timeout de 45s esgotar — usado quando NAO ha Promise viva em memoria (ex.:
// apos recarregar a pagina no meio do processo).
async function pollarAteTerminal(idAtendimento, tipo) {
    const inicio = Date.now();
    while (Date.now() - inicio < PROCESSAMENTO_TIMEOUT_MS) {
        const status = await consultarStatusProcessamento(idAtendimento, tipo);
        if (status.terminal) return status;
        await new Promise(resolve => setTimeout(resolve, PROCESSAMENTO_POLL_INTERVAL_MS));
    }
    return resultadoTimeoutProcessamento();
}

// Aguarda CNH E CRLV chegarem a estado terminal — usa a Promise viva em
// memoria de cada documento, se existir (caminho normal, sem reload), com
// timeout de 45s; senao recorre a polling (ver pollarAteTerminal). Cada
// documento e resolvido de forma independente (um pode ter Promise viva
// enquanto o outro depende de polling, embora isso normalmente nao ocorra na
// pratica, ja que um reload de pagina perde as duas Promises ao mesmo tempo).
async function aguardarDocumentos(idAtendimento, cnhPromiseViva, crlvPromiseViva) {
    const resolverCnh = cnhPromiseViva
        ? comTimeout(cnhPromiseViva, PROCESSAMENTO_TIMEOUT_MS)
        : pollarAteTerminal(idAtendimento, 'cnh');
    const resolverCrlv = crlvPromiseViva
        ? comTimeout(crlvPromiseViva, PROCESSAMENTO_TIMEOUT_MS)
        : pollarAteTerminal(idAtendimento, 'crlv');

    const [cnh, crlv] = await Promise.all([resolverCnh, resolverCrlv]);
    return { cnh, crlv };
}

// Tenta avancar a etapa (documento.php ja revalida CNH/CRLV aprovados no
// gate correspondente — front nunca decide sozinho). Se o gate ainda nao
// estiver liberado (algum documento pendente/reprovado), consulta o status
// de cada um e abre o preenchimento manual SOMENTE do que ainda nao foi
// aprovado — preserva o outro documento ja aprovado, nunca pede para
// recapturar/reprocessar o que ja passou (item 5 do escopo). Generico o
// suficiente para Expedicao e Recebimento (so recebe os nomes de tela manual
// de cada fluxo).
async function tentarAvancarEtapaDocumentos(telaManualCnh, telaManualCrlv) {
    try {
        const avanco = await api('atendimento.php', 'avancar-etapa-documentos', { id_atendimento: state.idAtendimento });
        ir(avanco.proxima_tela);
        return true;
    } catch (e) {
        const [cnh, crlv] = await Promise.all([
            consultarStatusProcessamento(state.idAtendimento, 'cnh'),
            consultarStatusProcessamento(state.idAtendimento, 'crlv'),
        ]);
        if (!cnh.pode_avancar) { ir(telaManualCnh); return false; }
        if (!crlv.pode_avancar) { ir(telaManualCrlv); return false; }
        mostrarErroTela(e.message || 'Não foi possível avançar agora.');
        return false;
    }
}

// -------------------- confirmacao dos dados (expedicao e recebimento) --------------------

function linhaConfirma(rotulo, id, valor) {
    return `<div class="linha-confirma">${rotulo}: <input class="kb-input" id="${id}" value="${escapeHtml(valor)}"></div>`;
}
// origem da validacao de CNH/CRLV, exibida na tela de confirmacao da
// Expedicao (item 4 da demanda expedicao-vio-cnh-crlv) — texto literal,
// nunca "documento original"/"autenticidade confirmada"
function expOrigemLabel(origem) {
    if (origem === 'VIO_TRIAL') return 'VIO_TRIAL';
    if (origem === 'VIO_VALIDADO') return 'VIO_VALIDADO';
    if (origem === 'MANUAL') return 'MANUAL — pendente de revisão';
    return 'Não validado';
}
function telaConfirma(tipoTexto) {
    const d = state.dados || {};
    const linhaFinal = state.tipo === 'expedicao'
        ? linhaConfirma('Ordem de coleta', 'confOc', d.numero)
        : linhaConfirma('Cliente', 'confCliente', d.cliente_nome);
    // bloco de origem de validacao (CNH/CRLV) so aparece na Expedicao — nao
    // altera em nada a tela de confirmacao do Recebimento (rec_confirma)
    const origensExpedicao = state.tipo === 'expedicao' ? `
        <div class="status-scanner">Origem da validação — CNH: ${escapeHtml(expOrigemLabel(state.exp.cnhOrigem))} · CRLV: ${escapeHtml(expOrigemLabel(state.exp.crlvOrigem))}</div>
    ` : '';
    return `<div class="subtitulo">Confirme — ${tipoTexto}</div>
        <div class="grade-campos">
            ${linhaConfirma('Motorista', 'confMotorista', d.motorista_nome)}
            ${linhaConfirma('CPF', 'confCpf', d.motorista_cpf)}
            ${linhaConfirma('CNH validade', 'confCnhValidade', d.cnh_validade)}
            ${linhaConfirma('CRLV ano', 'confCrlvAno', d.crlv_ano)}
            ${campoSelectUf('UF do CRLV', 'confCrlvUf', d.crlv_uf || '')}
            ${linhaConfirma('RNTC', 'confCrlvRntc', d.crlv_rntc)}
            ${linhaConfirma('Tipo de veículo', 'confCrlvTipoVeiculo', d.crlv_tipo_veiculo)}
            ${linhaConfirma('Placa', 'confPlaca', state.placa)}
            ${linhaFinal}
        </div>
        ${origensExpedicao}
        <button class="btn-primario" style="max-width:320px;margin:0 auto" onclick="confirmarDados()">Confirmar dados</button>`;
}
async function confirmarDados() {
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
    } catch (e) { mostrarErroTela(e.message); }
}

// -------------------- ajudante (expedicao e recebimento) --------------------

function telaAjudante() {
    return `<div id="ajudantePergunta">
            <div class="titulo">Possui ajudante?</div>
            <div class="grupo-botoes-linha">
                <button class="btn-primario" onclick="mostrarCamposAjudante()">Sim</button>
                <button class="btn-fantasma" onclick="finalizarSemAjudante()">Não</button>
            </div>
        </div>
        <div id="ajudanteCampos" style="display:none">
            <div class="subtitulo">Dados do ajudante</div>
            <div class="grade-campos">
                ${campo('Nome completo', 'ajudanteNome', '')}
                ${campo('CPF', 'ajudanteCpf', '')}
            </div>
            <button class="btn-primario" style="max-width:320px;margin:16px auto 0" onclick="confirmarAjudante()">Confirmar</button>
        </div>`;
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
async function salvarAjudanteEAvancar(nome, cpf) {
    try {
        await api('atendimento.php', 'salvar-etapa', { id_atendimento: state.idAtendimento, etapa: 'ajudante', dados: { nome, cpf } });
        ir(state.tipo === 'expedicao' ? 'exp_impressao' : 'rec_impressao');
    } catch (e) { mostrarErroTela(e.message); }
}

// -------------------- impressao da senha (expedicao e recebimento) --------------------

function telaImpressao() {
    return `<div id="impressaoConteudo"><div class="subtitulo">Enviando seus dados...</div></div>`;
}
async function processarImpressao() {
    const el = document.getElementById('impressaoConteudo');
    try {
        const dados = await api('atendimento.php', 'finalizar', { id_atendimento: state.idAtendimento });
        // Formato de retorno de sucesso do Talent (Portaria/Checkin) NAO e
        // documentado no manual oficial (pendencia registrada em
        // docs/manual_talent.md) — senha/protocolo podem vir ausentes
        // (null). Nesse caso mostra mensagem generica de check-in
        // registrado, em vez de exibir "undefined"/valor vazio como senha.
        const blocoSenha = dados.senha
            ? `<div class="senha-caixa"><div class="rotulo">SENHA</div><div class="valor">${escapeHtml(dados.senha)}</div></div>
               <div class="subtitulo">Retire o comprovante na bandeja abaixo</div>`
            : `<div class="subtitulo">Seu check-in foi registrado com sucesso.</div>`;
        el.innerHTML = `<div class="subtitulo">${dados.senha ? 'Imprimindo sua senha' : 'Tudo certo!'}</div>
            ${blocoSenha}
            <button class="btn-fantasma" style="max-width:320px;margin:0 auto" onclick="novoAtendimento()">Novo atendimento</button>`;
    } catch (e) {
        if (e.status === 202) {
            el.innerHTML = `<div class="subtitulo">Seu atendimento foi recebido — a senha será processada em instantes.</div>
                <button class="btn-fantasma" style="max-width:320px;margin:0 auto" onclick="novoAtendimento()">Novo atendimento</button>`;
        } else {
            el.innerHTML = `<div class="subtitulo">${escapeHtml(e.message)}</div>
                <button class="btn-primario" style="max-width:320px;margin:0 auto" onclick="processarImpressao()">Tentar novamente</button>`;
        }
    }
}

// -------------------- recebimento: placa + quantidade de notas --------------------

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
    try {
        const dados = await api('atendimento.php', 'iniciar', { tipo: 'recebimento', placa });
        state.idAtendimento = dados.id_atendimento;
        if (excedeLimite) {
            await api('atendimento.php', 'bloquear-excesso-notas', { id_atendimento: state.idAtendimento });
            ir('rec_bloqueado');
        } else {
            state.notaOrdem = 0;
            state.notasImagens = [];
            state.previewNotaAtual = null;
            state.capturaNotaEmAndamento = false;
            state.finalizandoDigitalizacao = false;
            state.clienteIdentificado = false;
            ocrFila = [];
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

// -------------------- recebimento: digitalizacao das notas --------------------
// captura fica fluida: cada nota carrega e volta pra mesma tela pronta pra proxima.
// a identificacao do cliente roda em segundo plano em cada chamada de /nota.php;
// "Finalizar digitalizacao" decide se pula a tela de confirmacao do cliente.

function telaDigitaliza() {
    const miniaturas = (state.notasImagens || []).map(img =>
        `<div class="miniatura"><img src="${img}" alt="Nota digitalizada"></div>`
    ).join('');
    return `<div class="subtitulo">Notas digitalizadas: <span id="contadorNotas">${state.notaOrdem}</span> de 5</div>
        <div class="caixa-scanner" id="caixaScanner">
            <video id="videoScanner" autoplay playsinline></video>
            <div class="guia-scanner"></div>
        </div>
        <img id="previaNota" class="previa-nota" style="display:none" alt="Nota capturada">
        <div class="status-scanner" id="scannerStatus">Conectando ao scanner...</div>
        <div class="miniaturas" id="miniaturas">${miniaturas}</div>
        <div class="grupo-botoes" id="controlesScanner">
            <button class="btn-fantasma" id="btnCapturarNota" onclick="capturarPreviaNota()" disabled>Capturar nota</button>
        </div>
        <button class="btn-primario" id="btnFinalizarDigitalizacao" style="max-width:320px;margin:0 auto" onclick="finalizarDigitalizacao()">Finalizar digitalização</button>`;
}

// -------------------- scanner Netum SD-2000 (dispositivo de video USB) --------------------
// o SD-2000 e um scanner de documentos exposto como dispositivo de video (getUserMedia),
// nao um leitor HID de codigo de barras — por isso essa tela nao usa habilitarLeitorScanner().

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
// true quando o video ja foi revelado (visibility:visible + "Scanner pronto")
// nesta sessao de stream — garante que a sequencia so roda uma vez por
// abertura (evita "piscar" o video de novo se o polling recalcular depois).
let scannerVideoRevelado = false;
// id do requestAnimationFrame pendente da revelacao (ver revelarVideoScannerQuandoPronto)
// — cancelado em pararCameraScanner() pra evitar que um callback tardio de uma
// sessao anterior mexa no video/status de uma sessao nova aberta logo em seguida.
let scannerRevelarFrameId = null;

function mostrarStatusScanner(msg, isErro) {
    const el = document.getElementById('scannerStatus');
    if (!el) return;
    el.textContent = msg;
    el.classList.toggle('erro', !!isErro);
    el.classList.toggle('ok', !isErro && (msg === 'Scanner pronto' || msg === 'Documento salvo' || msg === 'Cliente identificado'));
}

// CSS aspect-ratio nao e respeitado de forma confiavel dentro do container
// flex/column .tela nesse navegador (confirmado por inspecao real: valor
// certo aplicado via JS, mas caixa renderizada em 16:9 em vez de 4:3) — por
// isso a altura e calculada em PIXELS (clientWidth * videoHeight / videoWidth)
// e escrita diretamente como style.height em #caixaScanner/#previaNota.
// Mantem o mesmo nome/assinatura usada pelos call sites (onloadedmetadata,
// listener de resize, polling): so guarda a resolucao real do video e agenda
// o recalculo via requestAnimationFrame (agrupado com o ResizeObserver do
// container, ver abrirStreamScanner).
function ajustarProporcaoScanner(largura, altura) {
    if (!largura || !altura) return;
    scannerVideoLarguraAtual = largura;
    scannerVideoAlturaAtual = altura;
    agendarRecalculoAlturaScanner();
}

// agrupa/debounce as escritas no DOM via requestAnimationFrame — tanto o
// polling (mudanca de resolucao do video) quanto o ResizeObserver (mudanca
// de clientWidth do container) passam por aqui, cancelando qualquer frame
// pendente anterior antes de agendar um novo.
function agendarRecalculoAlturaScanner() {
    if (scannerFrameId) {
        cancelAnimationFrame(scannerFrameId);
    }
    scannerFrameId = requestAnimationFrame(() => {
        scannerFrameId = null;
        aplicarAlturaScanner();
    });
}

// calcula altura = clientWidth * videoHeight / videoWidth e escreve
// style.height em #caixaScanner/#previaNota — so escreve no DOM se a largura
// do container OU a altura calculada mudaram desde a ultima aplicacao
// (evita reflow desnecessario a cada tick do polling/observer sem mudanca real).
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

// ponto unico de entrada para revelar o <video> do scanner: aplica a altura
// real (aplicarAlturaScanner, sincrono), aguarda um requestAnimationFrame (pra
// garantir que o navegador ja processou o novo style.height) e so entao torna
// o video visivel e muda o status pra "Scanner pronto". So executa a sequencia
// completa na PRIMEIRA vez nesta sessao de stream (guarda via
// scannerVideoRevelado) — chamadas seguintes (polling/resize recalculando a
// proporcao depois) sao no-op, pra nao "piscar" o video de novo.
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
    btn.disabled = !prontoVideo || !scannerVideoPronto || state.notaOrdem >= 5;
}

async function iniciarCameraScanner() {
    // dispara o carregamento do Worker/modelo de idioma em paralelo ao
    // motorista posicionando a primeira nota (sem await — nao bloqueia a
    // abertura da tela nem a conexao com o scanner)
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

// resolucao alvo (ideal) solicitada ao driver do scanner: o navegador/driver
// entrega o maximo que o hardware suportar, nunca mais que isso — constraints
// "ideal" nao lancam OverconstrainedError por si so (diferente de exact/min)
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
        // video fica oculto ate a altura real ser aplicada (revelarVideoScannerQuandoPronto),
        // eliminando o "flash" de frame sem layout correto assim que o stream conecta.
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

        // ResizeObserver do container: recalcula a altura sempre que o
        // clientWidth de #caixaScanner mudar (ex: redimensionamento da tela,
        // mudanca de orientacao) — escrita real agrupada via
        // requestAnimationFrame em agendarRecalculoAlturaScanner().
        const caixaObservada = document.getElementById('caixaScanner');
        if (caixaObservada && typeof ResizeObserver !== 'undefined') {
            scannerResizeObserver = new ResizeObserver(() => {
                agendarRecalculoAlturaScanner();
            });
            scannerResizeObserver.observe(caixaObservada);
        }

        // monitoramento ativo (polling) das dimensoes reais do video: o evento
        // "resize" do HTMLVideoElement e conhecido por ser inconsistente para
        // streams ao vivo (srcObject/MediaStream) em varias versoes de Chromium
        // — comprovado em teste fisico (guia visual presa em 4:3 mesmo apos o
        // driver renegociar a resolucao final). O polling garante que a guia
        // visual acompanhe a resolucao real do video mesmo sem o evento disparar.
        scannerPollingIntervalId = setInterval(() => {
            if (!video.videoWidth || !video.videoHeight) return;
            if (video.videoWidth === scannerUltimaLarguraAplicada && video.videoHeight === scannerUltimaAlturaAplicada) return;
            ajustarProporcaoScanner(video.videoWidth, video.videoHeight);
            scannerUltimaLarguraAplicada = video.videoWidth;
            scannerUltimaAlturaAplicada = video.videoHeight;
            revelarVideoScannerQuandoPronto();
            atualizarBotaoCaptura();
        }, 250);

        // diagnostico: label, resolucao solicitada vs entregue, e capabilities (se suportado)
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

        // cameras UVC frequentemente entregam primeiro um modo de resolucao
        // inicial (disparando loadedmetadata com essas dimensoes) e so depois
        // renegociam para a resolucao final pedida via "ideal", disparando um
        // evento resize no <video> — sem escutar esse evento, a guia visual
        // fica presa na proporcao inicial errada mesmo com a captura final
        // correta (que le videoWidth/videoHeight "ao vivo" no momento do clique)
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
    } catch (e) { /* falha ao enumerar aqui nao pode travar a tela */ }
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

// limite seguro de tamanho do JPEG (client-side) para o scanner de notas.
// margem abaixo do padrao do backend (NOTA_IMAGEM_MAX_BYTES, .env, padrao 5MB
// / 5242880 bytes) — isto e uma ESTIMATIVA client-side (tamanho do base64 x
// 0.75, descontando o prefixo data:image/jpeg;base64,), o valor real do
// backend pode mudar se o .env for alterado; nao ha sincronizacao automatica.
const SCANNER_NOTA_LIMITE_BYTES = 4.5 * 1024 * 1024; // 4.5MB

// captura dedicada do scanner de notas (rec_digitaliza) — usa a resolucao
// REAL do video (sem downscale fixo de 900px como capturarFotoBase64,
// usada por CNH/CRLV) e comprime so o necessario para respeitar o limite.
function capturarFotoScannerNota(video) {
    const canvas = document.createElement('canvas');
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);

    const qualidades = [0.92, 0.85, 0.75, 0.65, 0.5, 0.4];
    let dataUrl = null;
    let tamanhoBytes = Infinity;
    for (const qualidade of qualidades) {
        dataUrl = canvas.toDataURL('image/jpeg', qualidade);
        tamanhoBytes = Math.round((dataUrl.length - 'data:image/jpeg;base64,'.length) * 0.75);
        if (tamanhoBytes <= SCANNER_NOTA_LIMITE_BYTES) break;
    }

    // ultimo recurso: se mesmo no piso de qualidade ainda exceder o limite,
    // reduz moderadamente a escala do canvas (90%) e tenta novamente
    let escalaAtual = canvas.width;
    let alturaAtual = canvas.height;
    while (tamanhoBytes > SCANNER_NOTA_LIMITE_BYTES && escalaAtual > 600) {
        escalaAtual = Math.round(escalaAtual * 0.9);
        alturaAtual = Math.round(alturaAtual * 0.9);
        const canvasMenor = document.createElement('canvas');
        canvasMenor.width = escalaAtual;
        canvasMenor.height = alturaAtual;
        canvasMenor.getContext('2d').drawImage(video, 0, 0, escalaAtual, alturaAtual);
        dataUrl = canvasMenor.toDataURL('image/jpeg', 0.4);
        tamanhoBytes = Math.round((dataUrl.length - 'data:image/jpeg;base64,'.length) * 0.75);
    }

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
    if (state.notaOrdem >= 5) return;
    mostrarStatusScanner('Capturando imagem...');
    const imagem = capturarFotoScannerNota(video);
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
    const btnUsar = document.getElementById('btnUsarImagem');
    const btnRefazer = document.getElementById('btnRefazer');
    if (btnUsar) btnUsar.disabled = true;
    if (btnRefazer) btnRefazer.disabled = true;
    mostrarStatusScanner('Enviando documento...');
    const imagem = state.previewNotaAtual;
    const ordem = state.notaOrdem + 1;
    try {
        const resultado = await api('nota.php', 'processar', { id_atendimento: state.idAtendimento, ordem, imagem, chave: null });
        if (resultado.cliente_identificado) state.clienteIdentificado = true;
        state.notaOrdem = ordem;
        state.notasImagens.push(imagem);
        state.previewNotaAtual = null;
        processarOcrNota(imagem, ordem);
        const contador = document.getElementById('contadorNotas');
        if (contador) contador.textContent = state.notaOrdem;
        const miniaturas = document.getElementById('miniaturas');
        if (miniaturas) miniaturas.innerHTML += `<div class="miniatura"><img src="${imagem}" alt="Nota digitalizada"></div>`;
        mostrarStatusScanner('Documento salvo');
        voltarParaVideoAoVivo();
        if (state.notaOrdem >= 5) {
            const btn = document.getElementById('btnCapturarNota');
            if (btn) btn.disabled = true;
            mostrarStatusScanner('Limite de 5 notas atingido');
        }
    } catch (e) {
        const msg = (e instanceof TypeError)
            ? 'Erro ao enviar o documento. Verifique a conexão e tente novamente.'
            : (e.message || 'Erro ao salvar o documento.');
        mostrarStatusScanner(msg, true);
        mostrarErroTela(msg);
        if (btnUsar) btnUsar.disabled = false;
        if (btnRefazer) btnRefazer.disabled = false;
    }
    state.capturaNotaEmAndamento = false;
}

async function finalizarDigitalizacao() {
    if (state.finalizandoDigitalizacao) return;
    state.finalizandoDigitalizacao = true;
    const btn = document.getElementById('btnFinalizarDigitalizacao');
    if (btn) btn.disabled = true;
    try {
        const dados = await api('atendimento.php', 'concluir-digitalizacao', { id_atendimento: state.idAtendimento });
        ir(dados.proxima_tela);
    } catch (e) {
        const msg = e.message || 'Erro ao concluir a digitalização.';
        mostrarStatusScanner(msg, true);
        mostrarErroTela(msg);
        if (btn) btn.disabled = false;
    }
    state.finalizandoDigitalizacao = false;
}

// -------------------- recebimento: identificacao de cliente via OCR local (Tesseract.js) --------------------
// CORRECAO (2026-09-04): o desenho anterior rodava Tesseract.createWorker()
// de DENTRO de um Web Worker customizado (assets/ocr-worker.js) — mas o
// Tesseract.js JA GERENCIA seu proprio Worker internamente (e exatamente o
// que Tesseract.createWorker() faz: cria um Worker dedicado, via blob URL,
// para rodar o reconhecimento fora da thread principal). Isso resultava num
// Worker aninhado (Worker dentro de Worker): o worker interno do
// Tesseract.js tentava importScripts('tesseract/worker.min.js') com caminho
// relativo, que nao resolve a partir do contexto de um blob worker aninhado
// (WorkerGlobalScope de um worker criado dinamicamente via blob nao tem a
// mesma base URL de assets/ocr-worker.js) — confirmado em teste fisico real
// (SyntaxError: Failed to execute 'importScripts' ... URL
// 'tesseract/worker.min.js' is invalid), quebrando 100% do OCR em producao.
// Correcao: Tesseract.createWorker() agora e chamado DIRETAMENTE na thread
// principal (aqui), com os caminhos relativos resolvendo normalmente a
// partir da propria pagina (public/totem/index.php). Isso nao bloqueia a UI/
// captura: Tesseract.createWorker() e worker.recognize() sao Promises,
// executadas pelo worker INTERNO do Tesseract.js (a parte pesada), chamadas
// sem await no fluxo de captura (mesmo padrao fire-and-forget de antes). A
// extracao de candidatos (extrairCandidatos()) e so processamento de string
// (regex), rapida, sem necessidade de Worker nenhum. A imagem nunca sai do
// totem/navegador — so os candidatos textuais extraidos (CNPJ/razao social)
// sao enviados ao backend (nota.php?acao=identificar-cliente). Fila interna
// processa as notas em ordem (nota por nota); assim que o backend confirma
// IDENTIFICADA, a fila e esvaziada e nenhuma nota seguinte dispara OCR/
// chamada de identificacao (early-stop por atendimento).
//
// AJUSTE (2026-09-04, diagnostico com imagem real): antes de chamar
// worker.recognize(), uma copia rotacionada em 270 graus (rotacionarImagem270())
// e gerada em memoria e usada SO para o OCR — a imagem original, ja salva
// via confirmarUsoImagemNota(), nunca e alterada. Extracao de chave de
// acesso (44 digitos) removida do processo (nunca validou em 14 combinacoes
// testadas) — chave_ocr sempre enviado como null ao backend.

let tesseractWorkerPromise = null;
let ocrFila = [];
let ocrProcessando = false;

// CNPJ: 14 digitos, aceitando mascara padrao (99.999.999/9999-99) ou
// separadores/espacos soltos que o OCR as vezes insere no lugar da mascara.
const CNPJ_REGEX = /\d{2}[.\s]?\d{3}[.\s]?\d{3}[/\s]?\d{4}[-\s]?\d{2}/g;

// Extraida do antigo ocr-worker.js (mesma logica/heuristica, nao alterada)
// — so processamento de string, roda direto na thread principal.
// NOTA (2026-09-04): extracao de chave de acesso (44 digitos) REMOVIDA —
// diagnostico real (14 combinacoes/2 imagens) mostrou que ela nunca valida
// via OCR (1 unico digito errado entre 44 ja invalida o resultado), mesmo
// quando CNPJ solto e razao social ja saem corretos. Chave deixou de fazer
// parte do processo de identificacao (ver handoff da demanda
// recebimento-leitura-notas).
function extrairCandidatos(texto) {
    const textoSeguro = texto || '';

    // CNPJs candidatos (dedupe, mantendo ordem de aparicao no texto).
    const cnpjsCandidatos = [];
    let m;
    CNPJ_REGEX.lastIndex = 0;
    while ((m = CNPJ_REGEX.exec(textoSeguro)) !== null) {
        const digitos = m[0].replace(/\D/g, '');
        if (digitos.length === 14) cnpjsCandidatos.push(digitos);
    }
    const cnpjsUnicos = [...new Set(cnpjsCandidatos)];

    // Razao social candidata: heuristica simples (ver observacao no
    // cabecalho do arquivo) — primeira linha reconhecida, entre as 20
    // primeiras, predominantemente alfabetica (sem digitos, tamanho
    // plausivel para um nome de empresa), onde costuma aparecer o nome do
    // emitente no layout padrao de DANFE.
    const linhas = textoSeguro.split(/\r?\n/).map(l => l.trim()).filter(Boolean);
    let razaoSocialCandidata = null;
    for (const linha of linhas.slice(0, 20)) {
        const letras = (linha.match(/[A-Za-zÀ-ÖØ-öø-ÿ]/g) || []).length;
        const digitosNaLinha = (linha.match(/\d/g) || []).length;
        if (linha.length >= 6 && linha.length <= 80 && digitosNaLinha === 0 && letras >= linha.length * 0.6) {
            razaoSocialCandidata = linha;
            break;
        }
    }

    return { cnpjsCandidatos: cnpjsUnicos, razaoSocialCandidata };
}

// Gera uma COPIA rotacionada em 270 graus da imagem, usada exclusivamente
// para o OCR/Tesseract.js — NUNCA persistida nem enviada ao backend como a
// "nota" (a imagem original, ja salva via confirmarUsoImagemNota(), segue
// intocada). Rotacao fixa: diagnostico real (2 imagens diferentes, 4
// rotacoes cada) confirmou que 0/90/180 graus nunca produzem CNPJ valido, e
// 270 graus sempre produz. 270/90 graus trocam largura/altura — por isso o
// canvas de destino usa img.height/img.width invertidos.
function rotacionarImagem270(imagemDataUrl) {
    return new Promise((resolve, reject) => {
        const img = new Image();
        img.onload = () => {
            const canvas = document.createElement('canvas');
            canvas.width = img.height;
            canvas.height = img.width;
            const ctx = canvas.getContext('2d');
            ctx.translate(canvas.width / 2, canvas.height / 2);
            ctx.rotate(270 * Math.PI / 180);
            ctx.drawImage(img, -img.width / 2, -img.height / 2);
            resolve(canvas);
        };
        img.onerror = () => reject(new Error('Falha ao carregar imagem para rotacao de OCR'));
        img.src = imagemDataUrl;
    });
}

// Cria (uma unica vez) e reaproveita o worker interno do Tesseract.js —
// evita recarregar o modelo de idioma a cada nota. Chamado diretamente na
// thread principal (nao ha mais Worker customizado — ver comentario acima).
// Chamado a partir de iniciarCameraScanner() pra comecar a carregar o
// modelo de idioma em paralelo ao motorista posicionando a primeira nota.
function iniciarOcrWorker() {
    if (!tesseractWorkerPromise) {
        try {
            // oem=1 (LSTM_ONLY) — coerente com so termos vendorizado as
            // variantes de core "*-lstm" (sem o core legado). Caminhos
            // relativos a propria pagina (public/totem/index.php), onde
            // assets/tesseract/tesseract.min.js ja e carregado via <script>.
            tesseractWorkerPromise = Tesseract.createWorker('por', 1, {
                workerPath: 'assets/tesseract/worker.min.js',
                corePath: 'assets/tesseract',
                langPath: 'assets/tesseract',
                gzip: true,
            });
        } catch (e) {
            console.warn('[OCR] falha ao iniciar Tesseract.js', e);
            tesseractWorkerPromise = null;
        }
    }
    return tesseractWorkerPromise;
}

// Enfileira uma nota para OCR — chamado logo apos confirmarUsoImagemNota()
// salvar a imagem com sucesso, sem await (fire-and-forget do ponto de vista
// da UI, nao bloqueia a captura da proxima nota).
function processarOcrNota(imagem, ordem) {
    if (state.clienteIdentificado) return; // early-stop: cliente ja identificado neste atendimento
    ocrFila.push({ imagem, ordem, idAtendimento: state.idAtendimento });
    processarProximaOcrDaFila();
}

// Processa a fila nota por nota (nao dispara reconhecimentos em paralelo —
// evita competir por CPU/memoria). Mesmo em caso de erro real de OCR
// (imagem ilegivel, falha ao baixar o modelo de idioma, worker indisponivel
// etc.) chama identificarClienteNota com candidatos vazios, para a nota
// sempre sair de PENDENTE (o backend ja trata candidatos vazios como
// NAO_IDENTIFICADA, sem erro) — corrige o bug de nota presa em PENDENTE.
async function processarProximaOcrDaFila() {
    if (ocrProcessando) return;
    if (state.clienteIdentificado) { ocrFila = []; return; }
    const proxima = ocrFila.shift();
    if (!proxima) return;
    ocrProcessando = true;
    const deAtendimentoAtual = proxima.idAtendimento === state.idAtendimento;
    try {
        const workerPromise = iniciarOcrWorker();
        if (!workerPromise) throw new Error('Tesseract.js indisponivel');
        const worker = await workerPromise;
        // rotacao fixa de 270 graus, so na copia em memoria usada pro OCR —
        // a imagem original (proxima.imagem) ja foi salva intocada por
        // confirmarUsoImagemNota() antes desta chamada.
        const imagemRotacionada = await rotacionarImagem270(proxima.imagem);
        const resultado = await worker.recognize(imagemRotacionada);
        const texto = (resultado && resultado.data && resultado.data.text) || '';
        const { cnpjsCandidatos, razaoSocialCandidata } = extrairCandidatos(texto);
        if (deAtendimentoAtual && !state.clienteIdentificado) {
            identificarClienteNota(proxima.ordem, cnpjsCandidatos, razaoSocialCandidata);
        }
    } catch (e) {
        console.warn('[OCR] falha ao processar nota via Tesseract.js', e);
        if (deAtendimentoAtual && !state.clienteIdentificado) {
            identificarClienteNota(proxima.ordem, [], null);
        }
    } finally {
        ocrProcessando = false;
        processarProximaOcrDaFila();
    }
}

// Chama o endpoint de identificacao. Retry simples (2 tentativas extras,
// backoff curto) so para falha de rede (TypeError do fetch) — nunca para
// resposta de negocio (NAO_IDENTIFICADA/ERRO), que e tratada como
// "segue sem identificar automaticamente", sem alarme ao motorista.
async function identificarClienteNota(ordem, cnpjsCandidatos, razaoSocialCandidata) {
    const backoffMs = [1000, 2000];
    for (let tentativa = 0; tentativa <= backoffMs.length; tentativa++) {
        try {
            const resultado = await api('nota.php', 'identificar-cliente', {
                id_atendimento: state.idAtendimento,
                ordem,
                chave_ocr: null, // extracao de chave removida do processo (ver extrairCandidatos)
                cnpjs_candidatos: cnpjsCandidatos,
                razao_social_candidata: razaoSocialCandidata,
            });
            if ((resultado.status === 'IDENTIFICADA' || resultado.ja_identificado_no_atendimento) && !state.clienteIdentificado) {
                state.clienteIdentificado = true;
                ocrFila = [];
                mostrarStatusScanner('Cliente identificado');
            }
            return;
        } catch (e) {
            const erroDeRede = e instanceof TypeError;
            if (!erroDeRede || tentativa === backoffMs.length) {
                if (erroDeRede) console.warn('[OCR] falha de rede ao identificar cliente, tentativas esgotadas', e);
                return; // tratado como equivalente a NAO_IDENTIFICADA/ERRO, sem alarme ao motorista
            }
            await new Promise(resolve => setTimeout(resolve, backoffMs[tentativa]));
        }
    }
}

// -------------------- recebimento: confirmacao do cliente (autocomplete) --------------------

let clienteSelecionado = null;

function telaCliente() {
    clienteSelecionado = null;
    return `<div class="subtitulo">Cliente não identificado — digite o nome ou CNPJ</div>
        <input class="kb-input" id="inputCliente" placeholder="Digite para buscar" style="font-size:16px;padding:12px;border:2px solid #0b2a45;border-radius:8px;width:100%;max-width:420px;margin:0 auto">
        <div class="lista-sugestoes" id="listaSugestoes"></div>
        <button class="btn-primario" style="max-width:320px;margin:0 auto" onclick="confirmarCliente()">Avançar</button>`;
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
    } catch (e) { /* autocomplete nao pode travar a tela */ }
}
async function confirmarCliente() {
    const nome = (clienteSelecionado && clienteSelecionado.nome) || document.getElementById('inputCliente').value.trim();
    const cnpj = (clienteSelecionado && clienteSelecionado.cnpj) || null;
    if (!nome) return mostrarErroTela('Digite ou selecione um cliente');
    try {
        await api('atendimento.php', 'salvar-etapa', { id_atendimento: state.idAtendimento, etapa: 'cliente', dados: { nome, cnpj } });
        state.dados = Object.assign({}, state.dados, { cliente_nome: nome, cliente_cnpj: cnpj });
        // ATENCAO (achado durante a implementacao desta demanda, registrado
        // como pendencia no handoff): a etapa antiga 'rec_cnh' nao existe
        // mais no backend (ETAPAS_UPLOAD/ETAPAS_PERMITIDAS_PROCESSAMENTO so
        // conhecem 'rec_cnh_frente'/'rec_cnh_verso'/'rec_crlv') — apontar
        // para 'rec_cnh_frente' e necessario para nao levar a uma tela morta,
        // mas AtendimentoController::salvarEtapa (case 'cliente') NAO
        // atualiza etapa_atual no banco: o proximo upload em
        // documento.php?acao=upload vai falhar a checagem de etapa exata
        // enquanto isso nao for corrigido no backend (fora do escopo deste
        // sub-agente, so frontend).
        ir('rec_cnh_frente');
    } catch (e) { mostrarErroTela(e.message); }
}

// ===================================================================
// RECEBIMENTO — captura/validacao de CNH e CRLV via VIO Decode (QR code)
// (REPLANEJAMENTO 2026-09-09 da demanda expedicao-vio-cnh-crlv: VIO Decode
// passa a valer tambem para o Recebimento). Telas e funcoes NOVAS E
// DEDICADAS (prefixo recCam/rec, mesmo padrao ja usado para expCam/exp) —
// NAO reaproveitam telaCaptura/iniciarCamera/capturarFotoBase64/
// capturarDocumento/habilitarLeitorScanner (leitor HID antigo, semantica
// diferente sem QR, mantido intocado e exclusivo da etapa legada 'rec_cnh').
// Diferenca intencional em relacao a Expedicao: aqui a CNH tem duas etapas
// de UPLOAD separadas no backend (rec_cnh_frente / rec_cnh_verso), nao uma
// unica etapa combinada — a leitura do QR e o disparo do processamento em
// segundo plano so acontecem na etapa do VERSO (mesmo lugar onde o QR
// costuma estar). Recebimento NAO tem ordem de coleta — nenhuma UI/logica
// deste bloco pressupoe isso.
// ===================================================================

// -------------------- recebimento: camera dedicada (Netum, mesmo deviceId salvo do scanner de notas) --------------------

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
        // mesma chave de localStorage ja usada pelo scanner de notas e pela
        // Expedicao (totem_scanner_deviceId) — mesmo hardware fisico (Netum)
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
        const video = document.getElementById('recCamVideo');
        if (!video) { stream.getTracks().forEach(t => t.stop()); recCamStream = null; return; }
        video.srcObject = stream;
        video.onloadedmetadata = () => {
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
    if (!['rec_cnh_frente', 'rec_cnh_verso', 'rec_crlv'].includes(state.tela)) return;
    try {
        const devices = await navigator.mediaDevices.enumerateDevices();
        const aindaConectado = devices.some(d => d.kind === 'videoinput' && d.deviceId === recCamDeviceId);
        if (!aindaConectado) {
            recCamVideoPronto = false;
            recCamMostrarStatus('A câmera foi desconectada. Reconecte o dispositivo e tente novamente.', true);
            recCamAtualizarBotao();
        }
    } catch (e) { /* falha ao enumerar aqui nao pode travar a tela */ }
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

// -------------------- recebimento: telas de captura (frente/verso CNH, CRLV) --------------------

function telaRecCaptura(titulo) {
    return `<div class="titulo">${titulo}</div>
        <div class="caixa-scanner" id="recCamCaixa">
            <video id="recCamVideo" autoplay playsinline></video>
        </div>
        <img id="recCamPreview" class="previa-nota" style="display:none" alt="Foto capturada">
        <div class="status-scanner" id="recCamStatus">Conectando à câmera...</div>
        <div class="status-leitura" id="recBgStatus" style="display:none"></div>
        <div class="grupo-botoes" id="recCamControles">
            <button class="btn-primario" id="recCamBtnCapturar" onclick="recCapturarFoto()" disabled>Capturar</button>
        </div>`;
}
function telaRecCnhFrente() { return telaRecCaptura('Fotografe a frente da CNH'); }
function telaRecCnhVerso() { return telaRecCaptura('Fotografe o verso da CNH (o QR code geralmente fica aqui)'); }
function telaRecCrlv() { return telaRecCaptura('Fotografe o CRLV completo'); }

// Indicador DISCRETO e NAO BLOQUANTE (nunca modal, nunca header fixo) de que
// a CNH ainda esta sendo validada em segundo plano enquanto o motorista ja
// esta fotografando o CRLV — mesmo espirito de exibirIndicadorProcessamentoCnh()
// da Expedicao.
function exibirIndicadorProcessamentoCnhRec() {
    const el = document.getElementById('recBgStatus');
    if (!el || !state.rec.cnhPromise) return;
    el.textContent = 'Validando CNH em segundo plano...';
    el.style.display = 'block';
    state.rec.cnhPromise.then(resultado => {
        if (!el.isConnected) return; // a tela ja pode ter mudado
        el.textContent = resultado.pode_avancar
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

function recCapturarFoto() {
    const video = document.getElementById('recCamVideo');
    if (!video || video.readyState < 2 || !video.videoWidth || !video.videoHeight) {
        recCamMostrarStatus('Aguarde o vídeo carregar.', true);
        return;
    }
    recCamMostrarStatus('Capturando imagem...');
    const canvas = recCamCapturarFrame(video);
    state.rec.previewCanvas = canvas;
    state.rec.previewImg = canvas.toDataURL('image/jpeg', 0.85);
    recExibirPreview();
}

function recExibirPreview() {
    const caixa = document.getElementById('recCamCaixa');
    if (caixa) caixa.style.display = 'none';
    const img = document.getElementById('recCamPreview');
    if (img) { img.src = state.rec.previewImg; img.style.display = 'block'; }
    const controles = document.getElementById('recCamControles');
    if (controles) {
        controles.innerHTML = `
            <button class="btn-primario" id="recCamBtnUsar" onclick="recConfirmarFoto()">Usar foto</button>
            <button class="btn-fantasma" id="recCamBtnRefazer" onclick="recRefazerFoto()">Refazer</button>`;
    }
}

function recVoltarParaVideoAoVivo() {
    const img = document.getElementById('recCamPreview');
    if (img) img.style.display = 'none';
    const caixa = document.getElementById('recCamCaixa');
    if (caixa) caixa.style.display = '';
    const controles = document.getElementById('recCamControles');
    if (controles) controles.innerHTML = `<button class="btn-primario" id="recCamBtnCapturar" onclick="recCapturarFoto()">Capturar</button>`;
    recCamAtualizarBotao();
}

function recRefazerFoto() {
    state.rec.previewImg = null;
    state.rec.previewCanvas = null;
    recVoltarParaVideoAoVivo();
    recCamMostrarStatus(recCamVideoPronto ? 'Câmera pronta' : 'Conectando à câmera...');
}

// oferece ao motorista as duas saidas quando o QR nao pode ser lido — nunca
// trava sem saida (mesmo espirito de expOferecerFallback)
function recOferecerFallback(tipo) {
    const rotulo = tipo === 'cnh' ? 'CNH' : 'CRLV';
    const telaManual = tipo === 'cnh' ? 'rec_cnh_manual' : 'rec_crlv_manual';
    abrirModal(`
        <div class="titulo">Não foi possível validar a ${rotulo}</div>
        <div class="subtitulo">Você pode tentar capturar novamente ou preencher os dados manualmente.</div>
        <button class="btn-primario" onclick="fecharModal(); recRefazerFoto();">Tentar novamente</button>
        <button class="btn-fantasma" onclick="fecharModal(); ir('${telaManual}');">Preencher dados manualmente</button>
    `);
}

// botao "Capturar"/"Usar foto" desabilitado durante qualquer chamada de rede
// em andamento — reabilitado so apos a resposta
async function recConfirmarFoto() {
    if (state.rec.emAndamento) return;
    state.rec.emAndamento = true;
    const btnUsar = document.getElementById('recCamBtnUsar');
    const btnRefazer = document.getElementById('recCamBtnRefazer');
    if (btnUsar) btnUsar.disabled = true;
    if (btnRefazer) btnRefazer.disabled = true;
    try {
        if (state.tela === 'rec_cnh_frente') {
            await recProcessarCnhFrente();
        } else if (state.tela === 'rec_cnh_verso') {
            await recProcessarCnhVerso();
        } else if (state.tela === 'rec_crlv') {
            await recProcessarCrlv();
        }
    } finally {
        state.rec.emAndamento = false;
        if (btnUsar) btnUsar.disabled = false;
        if (btnRefazer) btnRefazer.disabled = false;
    }
}

// Diferenca intencional em relacao a Expedicao: a frente da CNH do
// Recebimento e um UPLOAD/etapa PROPRIA (rec_cnh_frente), sem leitura de QR
// (o QR normalmente esta no verso) — so envia a foto e avanca.
async function recProcessarCnhFrente() {
    const frenteImg = state.rec.previewImg;
    recCamMostrarStatus('Enviando documento...');
    try {
        await api('documento.php', 'upload', {
            id_atendimento: state.idAtendimento,
            tipo: 'cnh_frente',
            imagem: frenteImg,
        });
    } catch (e) {
        recCamMostrarStatus(e.message, true);
        mostrarErroTela(e.message);
        return;
    }

    await tentarAvancarEtapaDocumentos('rec_cnh_manual', 'rec_crlv_manual');
}

async function recProcessarCnhVerso() {
    const versoImg = state.rec.previewImg;
    recCamMostrarStatus('Enviando documento...');
    try {
        await api('documento.php', 'upload', {
            id_atendimento: state.idAtendimento,
            tipo: 'cnh_verso',
            imagem: versoImg,
        });
    } catch (e) {
        recCamMostrarStatus(e.message, true);
        mostrarErroTela(e.message);
        return;
    }

    recCamMostrarStatus('Lendo QR code...');
    const qr = await recLerQrDaImagemCapturada();
    if (!qr.ok) {
        recOferecerFallback('cnh');
        return;
    }

    // ASSINCRONO (mesmo padrao da Expedicao): dispara a validacao em segundo
    // plano SEM aguardar e libera a captura do CRLV imediatamente.
    state.rec.cnhPromise = iniciarProcessamentoDocumento(state.idAtendimento, 'cnh', qr.binaryData);
    recCamMostrarStatus('CNH enviada para validação');

    await tentarAvancarEtapaDocumentos('rec_cnh_manual', 'rec_crlv_manual');
}

async function recProcessarCrlv() {
    const crlvImg = state.rec.previewImg;
    recCamMostrarStatus('Enviando documento...');
    try {
        await api('documento.php', 'upload', {
            id_atendimento: state.idAtendimento,
            tipo: 'crlv',
            imagem: crlvImg,
        });
    } catch (e) {
        recCamMostrarStatus(e.message, true);
        mostrarErroTela(e.message);
        return;
    }

    recCamMostrarStatus('Lendo QR code...');
    const qr = await recLerQrDaImagemCapturada();
    if (!qr.ok) {
        recOferecerFallback('crlv');
        return;
    }

    // ASSINCRONO — dispara a validacao do CRLV em segundo plano e segue
    // direto para a tela de espera (rec_aguarde_documentos so aparece a
    // partir daqui, nunca entre CNH e CRLV).
    state.rec.crlvPromise = iniciarProcessamentoDocumento(state.idAtendimento, 'crlv', qr.binaryData);
    recCamMostrarStatus('CRLV enviado para validação');

    await tentarAvancarEtapaDocumentos('rec_cnh_manual', 'rec_crlv_manual');
}

// leitura de QR reaproveitando o MESMO Web Worker ja criado para a
// Expedicao (obterQrWorker()/lerQrDoCanvas(), genericos, sem estado
// especifico de exp/rec)
async function recLerQrDaImagemCapturada() {
    if (!state.rec.previewCanvas) return { ok: false };
    try {
        return await lerQrDoCanvas(state.rec.previewCanvas);
    } catch (e) {
        return { ok: false };
    }
}

// -------------------- recebimento: preenchimento manual (fallback do QR) --------------------

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

    // validacao client-side leve, so para feedback rapido — a validacao real
    // e sempre no backend (preencher-manual)
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

// -------------------- recebimento: tela de espera apos CRLV confirmado --------------------

function telaRecAguardeDocumentos() {
    return `<div class="titulo">Estamos validando seus documentos. Aguarde.</div>`;
}

async function processarAguardeDocumentosRec() {
    const resultado = await aguardarDocumentos(state.idAtendimento, state.rec.cnhPromise, state.rec.crlvPromise);
    state.rec.cnhPromise = null;
    state.rec.crlvPromise = null;
    if (resultado.cnh.pode_avancar) state.rec.cnhOrigem = resultado.cnh.aviso_trial ? 'VIO_TRIAL' : 'VIO_VALIDADO';
    if (resultado.crlv.pode_avancar) state.rec.crlvOrigem = resultado.crlv.aviso_trial ? 'VIO_TRIAL' : 'VIO_VALIDADO';
    if (state.tela !== 'rec_aguarde_documentos') return; // usuario ja saiu da tela (ex.: cancelou)
    await tentarAvancarEtapaDocumentos('rec_cnh_manual', 'rec_crlv_manual');
}

// -------------------- inicializacao --------------------

function iniciarApp() {
    document.getElementById('app').innerHTML = `
        <div class="barra-cancelar" id="barraCancelar" style="display:none">
            <button class="btn-cancelar" onclick="confirmarCancelar()">✕ Cancelar atendimento</button>
        </div>
        <div class="tela" id="tela"></div>
        <div class="teclado" id="teclado"></div>
        <div class="modal-fundo" id="modalFundo"><div class="modal-caixa" id="modalCaixa"></div></div>
        <div id="toastErro" style="display:none;position:fixed;bottom:16px;left:16px;right:16px;background:#a32d2d;color:#fff;padding:12px 16px;border-radius:8px;font-size:14px;text-align:center;z-index:60"></div>
    `;
    montarTeclado();
    document.getElementById('tela').addEventListener('focusin', e => {
        if (e.target.classList.contains('kb-input')) abrirTeclado(e.target);
    });
    ir('home');
}

iniciarApp();
