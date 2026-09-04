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
};

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
const IDLE_MS = 45000;
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
        case 'exp_cnh': tela.innerHTML = telaCaptura('Posicione a CNH no leitor'); iniciarCamera(); habilitarLeitorScanner(); break;
        case 'exp_crlv': tela.innerHTML = telaCaptura('Posicione o CRLV no leitor'); iniciarCamera(); habilitarLeitorScanner(); break;
        case 'exp_confirma': tela.innerHTML = telaConfirma('retirada de carga'); break;
        case 'exp_ajudante': tela.innerHTML = telaAjudante(); break;
        case 'exp_impressao': tela.innerHTML = telaImpressao(); processarImpressao(); break;
        case 'rec_placa_qtd': tela.innerHTML = telaRecPlacaQtd(); break;
        case 'rec_bloqueado': tela.innerHTML = telaBloqueado(); break;
        case 'rec_digitaliza': tela.innerHTML = telaDigitaliza(); iniciarCameraScanner(); break;
        case 'rec_cliente': tela.innerHTML = telaCliente(); habilitarAutocompleteCliente(); break;
        case 'rec_cnh': tela.innerHTML = telaCaptura('Posicione a CNH no leitor'); iniciarCamera(); habilitarLeitorScanner(); break;
        case 'rec_crlv': tela.innerHTML = telaCaptura('Posicione o CRLV no leitor'); iniciarCamera(); habilitarLeitorScanner(); break;
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
    });
    ir('home');
}

// -------------------- tela: inicio --------------------

function telaHome() {
    return `<div class="titulo">Selecione o tipo de atendimento</div>
        <div class="grupo-botoes">
            <button class="tile tile-principal" onclick="selecionarTipo('expedicao')">Expedição</button>
            <button class="tile tile-secundaria" onclick="selecionarTipo('recebimento')">Recebimento</button>
        </div>`;
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
        <button class="btn-primario" style="max-width:320px;margin:0 auto" onclick="avancarDados()">Avançar</button>`;
}
function avancarDados() {
    state.dados.cliente_nome = document.getElementById('campoCliente').value;
    state.dados.cliente_cnpj = document.getElementById('campoCnpj').value;
    ir('exp_cnh');
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

// -------------------- confirmacao dos dados (expedicao e recebimento) --------------------

function linhaConfirma(rotulo, id, valor) {
    return `<div class="linha-confirma">${rotulo}: <input class="kb-input" id="${id}" value="${escapeHtml(valor)}"></div>`;
}
function telaConfirma(tipoTexto) {
    const d = state.dados || {};
    const linhaFinal = state.tipo === 'expedicao'
        ? linhaConfirma('Ordem de coleta', 'confOc', d.numero)
        : linhaConfirma('Cliente', 'confCliente', d.cliente_nome);
    return `<div class="subtitulo">Confirme — ${tipoTexto}</div>
        <div class="grade-campos">
            ${linhaConfirma('Motorista', 'confMotorista', d.motorista_nome)}
            ${linhaConfirma('CPF', 'confCpf', d.motorista_cpf)}
            ${linhaConfirma('CNH validade', 'confCnhValidade', d.cnh_validade)}
            ${linhaConfirma('CRLV ano', 'confCrlvAno', d.crlv_ano)}
            ${linhaConfirma('Placa', 'confPlaca', state.placa)}
            ${linhaFinal}
        </div>
        <button class="btn-primario" style="max-width:320px;margin:0 auto" onclick="confirmarDados()">Confirmar dados</button>`;
}
async function confirmarDados() {
    const dados = {
        motorista_nome: document.getElementById('confMotorista').value,
        motorista_cpf: document.getElementById('confCpf').value,
        cnh_validade: document.getElementById('confCnhValidade').value,
        crlv_ano: document.getElementById('confCrlvAno').value,
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
        el.innerHTML = `<div class="subtitulo">Imprimindo sua senha</div>
            <div class="senha-caixa"><div class="rotulo">SENHA</div><div class="valor">${escapeHtml(dados.senha)}</div></div>
            <div class="subtitulo">Retire o comprovante na bandeja abaixo</div>
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
    el.classList.toggle('ok', !isErro && (msg === 'Scanner pronto' || msg === 'Documento salvo'));
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
        ir('rec_cnh');
    } catch (e) { mostrarErroTela(e.message); }
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
