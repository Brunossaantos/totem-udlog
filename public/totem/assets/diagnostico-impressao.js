// ===================================================================
// Totem UDLOG — DIAGNOSTICO de impressao de etiqueta de teste
// (demanda impressao-etiqueta-teste, /01-implementacao)
//
// Modulo TOTALMENTE ISOLADO das telas reais exp_impressao/rec_impressao e
// de qualquer fluxo de atendimento real (nunca chama atendimento.php,
// documento.php, AtendimentoController, nem toca state.idAtendimento/
// state.tela do app.js). So consome:
//   - GET /api/impressao-teste.php?acao=gerar-etiqueta        (token do TOTEM)
//   - GET /api/impressao-teste.php?acao=configuracao-servico-local (token do TOTEM)
//   - GET  {IMPRESSAO_LOCAL_URL}/saude                         (sem auth)
//   - GET  {IMPRESSAO_LOCAL_URL}/impressoras                   (token do SERVICO LOCAL)
//   - POST {IMPRESSAO_LOCAL_URL}/imprimir                      (token do SERVICO LOCAL)
//
// Nenhum dado pessoal em nenhuma tela deste fluxo.
//
// Ponto de acesso: toque longo (long-press) num ponto discreto da tela
// inicial (home) do app.js — ver hook registrado no fim deste arquivo.
// ATENCAO: isso e uma ESCOLHA DE IMPLEMENTACAO desta etapa, reaproveitando a
// SUGESTAO registrada em docs/handoffs/2026-09-11-impressao-etiqueta-teste.md
// (secao "Pendencias"), NAO uma decisao de UX/produto formalmente confirmada
// pelo usuario. Ver observacao no relatorio desta etapa.
// ===================================================================

const DIAG_CHAVE_IMPRESSORA = 'totem_impressora_nome';
const DIAG_LONG_PRESS_MS = 2500;
const DIAG_FRONTEND_TIMEOUT_MS_FALLBACK = 35000; // defensivo: so usado se o backend nao devolver frontend_timeout_ms

const diagState = {
    ativo: false,
    tela: 'conectando', // conectando | selecionar_impressora | preparando | imprimindo | concluido | erro | indeterminado
    urlServicoLocal: null,
    tokenServicoLocal: null,
    frontendTimeoutMs: DIAG_FRONTEND_TIMEOUT_MS_FALLBACK,
    impressoras: [],
    mensagemErro: '',
    mensagemIndeterminado: '',
    etiqueta: null, // { pdf_base64, identificador, ... }
    resultadoImpressao: null, // { status, identificador, mensagem? }
};

// -------------------- comunicacao: backend PHP (token do TOTEM) --------------------

async function diagApiTotem(acao) {
    const res = await fetch(`${API_BASE}impressao-teste.php?acao=${acao}`, {
        method: 'GET',
        headers: { 'Authorization': `Bearer ${TOKEN}` },
    });
    const json = await res.json();
    if (!json.sucesso) {
        const erro = new Error(json.erro || 'Erro desconhecido');
        erro.status = res.status;
        throw erro;
    }
    return json.dados;
}

// -------------------- comunicacao: servico local Node.js (token PROPRIO, nunca o token do totem) --------------------

// timeoutMs opcional: quando informado, usa AbortController para o front
// desistir da requisicao por conta propria (nunca deixa o fetch pendente
// indefinidamente), independente do backend responder ou nao.
async function diagFetchServicoLocal(caminho, opcoes, timeoutMs) {
    const url = `${diagState.urlServicoLocal}${caminho}`;
    const headers = Object.assign({}, (opcoes && opcoes.headers) || {});
    if (caminho !== '/saude') {
        headers['Authorization'] = `Bearer ${diagState.tokenServicoLocal}`;
    }

    let controller = null;
    let temporizador = null;
    const opcoesFinais = Object.assign({}, opcoes, { headers });
    if (timeoutMs) {
        controller = new AbortController();
        opcoesFinais.signal = controller.signal;
        temporizador = setTimeout(() => controller.abort(), timeoutMs);
    }

    try {
        const res = await fetch(url, opcoesFinais);
        let json = null;
        try { json = await res.json(); } catch (e) { /* resposta sem corpo JSON valido */ }
        return { ok: res.ok, status: res.status, dados: json, abortou: false };
    } catch (e) {
        if (e && e.name === 'AbortError') {
            return { ok: false, status: 0, dados: null, abortou: true };
        }
        throw e;
    } finally {
        if (temporizador) clearTimeout(temporizador);
    }
}

// -------------------- navegacao interna (isolada de ir()/state do app.js) --------------------

function diagIr(tela) {
    diagState.tela = tela;
    diagRender();
}

function diagSair() {
    diagState.ativo = false;
    // devolve o app real para a tela inicial, sem tocar em nenhum outro
    // estado de atendimento (o diagnostico nunca inicia atendimento real)
    document.getElementById('diagOverlay').remove();
}

// -------------------- entrada no fluxo --------------------

function diagAbrir() {
    if (diagState.ativo) return;
    diagState.ativo = true;
    diagState.mensagemErro = '';
    diagState.mensagemIndeterminado = '';
    diagState.etiqueta = null;
    diagState.resultadoImpressao = null;

    const overlay = document.createElement('div');
    overlay.id = 'diagOverlay';
    overlay.className = 'diag-overlay';
    document.body.appendChild(overlay);

    diagIr('conectando');
    diagIniciarFluxo();
}

async function diagIniciarFluxo() {
    try {
        const cfg = await diagApiTotem('configuracao-servico-local');
        diagState.urlServicoLocal = cfg.url;
        diagState.tokenServicoLocal = cfg.token;
        // defensivo: se o backend nao devolver frontend_timeout_ms, usa o
        // fallback fixo — nao deve acontecer, mas nao pode travar se faltar
        diagState.frontendTimeoutMs = (typeof cfg.frontend_timeout_ms === 'number' && cfg.frontend_timeout_ms > 0)
            ? cfg.frontend_timeout_ms
            : DIAG_FRONTEND_TIMEOUT_MS_FALLBACK;
    } catch (e) {
        diagState.mensagemErro = 'Servico de impressao local nao configurado: ' + e.message;
        diagIr('erro');
        return;
    }

    const saude = await diagFetchServicoLocal('/saude', { method: 'GET' });
    if (!saude.ok) {
        diagState.mensagemErro = 'Nao foi possivel conectar ao servico local de impressao. Verifique se ele esta em execucao no totem.';
        diagIr('erro');
        return;
    }

    const impressoraSalva = localStorage.getItem(DIAG_CHAVE_IMPRESSORA);
    if (impressoraSalva) {
        // ainda assim confirma que a impressora salva continua existindo
        // antes de seguir direto para a impressao (item 3 do escopo)
        const listaOk = await diagCarregarImpressoras(false);
        if (!listaOk) return;
        if (diagState.impressoras.some(i => i.nome === impressoraSalva)) {
            diagExecutarImpressaoTeste(impressoraSalva);
        } else {
            localStorage.removeItem(DIAG_CHAVE_IMPRESSORA);
            diagIr('selecionar_impressora');
        }
        return;
    }

    const listaOk = await diagCarregarImpressoras(true);
    if (!listaOk) return;
    diagIr('selecionar_impressora');
}

// retorna true se a lista foi carregada com sucesso
async function diagCarregarImpressoras(irParaSelecaoAoFalhar) {
    const resp = await diagFetchServicoLocal('/impressoras', { method: 'GET' });
    if (!resp.ok) {
        diagState.mensagemErro = (resp.dados && resp.dados.erro) || 'Nao foi possivel listar as impressoras.';
        diagIr('erro');
        return false;
    }
    diagState.impressoras = (resp.dados && resp.dados.impressoras) || [];
    if (diagState.impressoras.length === 0) {
        diagState.mensagemErro = 'Nenhuma impressora encontrada pelo servico local.';
        diagIr('erro');
        return false;
    }
    return true;
}

function diagSelecionarImpressora(nome) {
    localStorage.setItem(DIAG_CHAVE_IMPRESSORA, nome);
    diagExecutarImpressaoTeste(nome);
}

function diagTrocarImpressora() {
    localStorage.removeItem(DIAG_CHAVE_IMPRESSORA);
    diagIr('conectando');
    diagCarregarImpressoras(true).then(ok => { if (ok) diagIr('selecionar_impressora'); });
}

// -------------------- fluxo de impressao de teste --------------------

async function diagExecutarImpressaoTeste(nomeImpressora) {
    diagIr('preparando');
    let etiqueta;
    try {
        etiqueta = await diagApiTotem('gerar-etiqueta');
    } catch (e) {
        diagState.mensagemErro = 'Nao foi possivel gerar a etiqueta de teste: ' + e.message;
        diagIr('erro');
        return;
    }
    diagState.etiqueta = etiqueta;

    diagIr('imprimindo');
    let resp;
    try {
        resp = await diagFetchServicoLocal('/imprimir', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                pdf_base64: etiqueta.pdf_base64,
                impressora: nomeImpressora,
                identificador: etiqueta.identificador,
            }),
        }, diagState.frontendTimeoutMs);
    } catch (e) {
        // qualquer falha de rede nao tratada tambem nao pode deixar a tela
        // presa em "imprimindo" — trata como indeterminado, nunca como sucesso
        diagState.mensagemIndeterminado = 'Nao foi possivel confirmar se a etiqueta foi impressa (falha de comunicacao). Verifique fisicamente a impressora antes de tentar novamente.';
        diagIr('indeterminado');
        return;
    }

    if (resp.abortou) {
        // AbortController do front disparou antes de qualquer resposta chegar —
        // nao e possivel confirmar se imprimiu
        diagState.mensagemIndeterminado = 'O tempo de espera pela confirmacao da impressora foi excedido. Nao e possivel confirmar se a etiqueta foi impressa. Verifique fisicamente a impressora antes de tentar novamente.';
        diagIr('indeterminado');
        return;
    }

    if (resp.ok && resp.dados && (resp.dados.status === 'impresso' || resp.dados.status === 'ja_impresso')) {
        diagState.resultadoImpressao = resp.dados;
        diagIr('concluido');
        return;
    }

    if (resp.status === 504 || (resp.dados && resp.dados.status === 'indeterminado')) {
        // backend nao conseguiu confirmar o resultado da impressao (timeout
        // no processo, ou reenvio do mesmo identificador ja timeoutado)
        diagState.mensagemIndeterminado = (resp.dados && (resp.dados.erro || resp.dados.mensagem))
            || 'Nao foi possivel confirmar se a etiqueta foi impressa. Verifique fisicamente a impressora antes de tentar novamente.';
        diagIr('indeterminado');
        return;
    }

    const mensagemErro = (resp.dados && resp.dados.erro) || 'Falha ao imprimir a etiqueta de teste.';
    // erro especificamente relacionado a impressora nao encontrada/invalida —
    // limpa a selecao salva e reabre a tela de selecao (item 3 do escopo)
    if (/impressora/i.test(mensagemErro)) {
        localStorage.removeItem(DIAG_CHAVE_IMPRESSORA);
        diagState.mensagemErro = mensagemErro + ' Selecione a impressora novamente.';
        const listaOk = await diagCarregarImpressoras(true);
        if (listaOk) diagIr('selecionar_impressora');
        return;
    }

    diagState.mensagemErro = mensagemErro;
    diagIr('erro');
}

function diagTentarNovamente() {
    const impressoraSalva = localStorage.getItem(DIAG_CHAVE_IMPRESSORA);
    if (impressoraSalva) {
        diagExecutarImpressaoTeste(impressoraSalva);
    } else {
        diagIniciarFluxo();
    }
}

// -------------------- render --------------------

function diagRender() {
    const overlay = document.getElementById('diagOverlay');
    if (!overlay) return;
    let corpo = '';
    switch (diagState.tela) {
        case 'conectando':
            corpo = `<div class="titulo">Conectando ao servico de impressao...</div>`;
            break;
        case 'selecionar_impressora':
            corpo = diagTelaSelecionarImpressora();
            break;
        case 'preparando':
            corpo = `<div class="titulo">Preparando etiqueta de teste...</div>`;
            break;
        case 'imprimindo':
            corpo = `<div class="titulo">Imprimindo etiqueta de teste...</div>`;
            break;
        case 'concluido':
            corpo = diagTelaConcluido();
            break;
        case 'erro':
            corpo = diagTelaErro();
            break;
        case 'indeterminado':
            corpo = diagTelaIndeterminado();
            break;
    }
    overlay.innerHTML = `
        <div class="diag-caixa">
            <div class="diag-cabecalho">
                <span>Diagnostico — impressao de etiqueta de teste</span>
                <button class="btn-fantasma" onclick="diagSair()">Fechar</button>
            </div>
            <div class="diag-corpo">${corpo}</div>
        </div>
    `;
    const lista = overlay.querySelector('#diagListaImpressoras');
    if (lista) {
        lista.querySelectorAll('[data-impressora]').forEach(btn => {
            btn.addEventListener('click', () => diagSelecionarImpressora(btn.dataset.impressora));
        });
    }
}

function diagTelaSelecionarImpressora() {
    const botoes = diagState.impressoras.map(imp => `
        <button class="btn-fantasma" data-impressora="${escapeHtml(imp.nome)}">${escapeHtml(imp.nome)}${imp.padrao ? ' (padrao)' : ''}</button>
    `).join('');
    return `<div class="titulo">Selecione a impressora</div>
        <div class="subtitulo">Essa escolha sera lembrada neste totem</div>
        <div class="grupo-botoes" id="diagListaImpressoras">${botoes}</div>`;
}

function diagTelaConcluido() {
    const r = diagState.resultadoImpressao || {};
    const msg = r.status === 'ja_impresso'
        ? (r.mensagem || 'Esta etiqueta de teste ja havia sido impressa.')
        : 'Etiqueta de teste enviada para impressao com sucesso.';
    return `<div class="titulo">Concluido</div>
        <div class="subtitulo">${escapeHtml(msg)}</div>
        <div class="grupo-botoes">
            <button class="btn-primario" onclick="diagTentarNovamente()">Imprimir outra etiqueta de teste</button>
            <button class="btn-fantasma" onclick="diagTrocarImpressora()">Trocar impressora</button>
            <button class="btn-fantasma" onclick="diagSair()">Fechar</button>
        </div>`;
}

function diagTelaIndeterminado() {
    return `<div class="titulo">Nao foi possivel confirmar a impressao</div>
        <div class="subtitulo">${escapeHtml(diagState.mensagemIndeterminado)}</div>
        <div class="grupo-botoes">
            <button class="btn-primario" onclick="diagTentarNovamente()">Tentar novamente</button>
            <button class="btn-fantasma" onclick="diagTrocarImpressora()">Trocar impressora</button>
            <button class="btn-fantasma" onclick="diagSair()">Fechar</button>
        </div>`;
}

function diagTelaErro() {
    return `<div class="titulo">Nao foi possivel concluir</div>
        <div class="subtitulo">${escapeHtml(diagState.mensagemErro)}</div>
        <div class="grupo-botoes">
            <button class="btn-primario" onclick="diagTentarNovamente()">Tentar novamente</button>
            <button class="btn-fantasma" onclick="diagTrocarImpressora()">Trocar impressora</button>
            <button class="btn-fantasma" onclick="diagSair()">Fechar</button>
        </div>`;
}

// -------------------- ponto de acesso: toque longo na tela inicial --------------------
// SUGESTAO reaproveitada do handoff de planejamento, NAO decisao de UX
// confirmada (ver comentario no topo do arquivo).

(function diagRegistrarGatilhoLongPress() {
    let temporizador = null;

    function cancelar() {
        clearTimeout(temporizador);
        temporizador = null;
    }

    function iniciar(e) {
        if (!e.target || e.target.id !== 'diagHotspot') return;
        cancelar();
        temporizador = setTimeout(() => { diagAbrir(); }, DIAG_LONG_PRESS_MS);
    }

    // Delegacao no document (o hotspot e recriado a cada renderTela() da
    // tela inicial) — so dispara quando o toque comeca dentro do elemento
    // discreto #diagHotspot (ver telaHome() em app.js), nunca em qualquer
    // ponto da tela. Script carregado no fim do <body> (depois de app.js) —
    // o DOM ja esta disponivel neste ponto, sem necessidade de aguardar
    // DOMContentLoaded.
    document.addEventListener('touchstart', iniciar, { passive: true });
    document.addEventListener('mousedown', iniciar);
    ['touchend', 'touchmove', 'touchcancel', 'mouseup', 'mouseleave'].forEach(evento => {
        document.addEventListener(evento, cancelar);
    });
})();
