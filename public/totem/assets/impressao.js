// ===================================================================
// Totem UDLOG — IMPRESSAO REAL pos-Talent (expedicao e recebimento) —
// demanda talent-doctos-finalizacao-checkin, extraido para arquivo proprio
// na demanda impressao-arquitetura-producao-ux (/01-implementacao,
// 2026-09-15). Maquina de estados PROPRIA deste arquivo, NUNCA
// reaproveitando diagnostico-impressao.js (modulo de teste isolado — so
// referencia de padrao: mesma chave de localStorage (totem_impressora_nome),
// mesmo uso de AbortController para timeout, nunca retry automatico).
// telaImpressao()/processarImpressao() mantidos como pontos de entrada
// (chamados por renderTela() em app.js, casos exp_impressao/rec_impressao).
//
// Depende de globals ja definidos em app.js (mesmo escopo global, sem
// modulos/build step): API_BASE, TOKEN, api(), escapeHtml(), state,
// novoAtendimento(). Carregado DEPOIS de app.js em index.php.
//
// Campos devolvidos por atendimento.php?acao=finalizar em caso de sucesso,
// confirmado com o backend-especialista: `senha` (numero de acesso, valor
// de nrRegAcesso do Talent) e `protocolo` (sempre null). `motorista_nome`
// nao vem do backend — usa fallback para state.dados.motorista_nome.
// ===================================================================

const IMPR_CHAVE_IMPRESSORA = 'totem_impressora_nome'; // MESMA chave ja usada por diagnostico-impressao.js
const IMPR_TIMEOUT_FALLBACK_MS = 35000;

const imprState = {
    tela: 'enviando', // enviando | erro_finalizar | selecionar_impressora | preparando | imprimindo | concluido | erro | indeterminado | sem_papel
    urlServicoLocal: null,
    tokenServicoLocal: null,
    frontendTimeoutMs: IMPR_TIMEOUT_FALLBACK_MS,
    impressoras: [],
    mensagemErro: '',
    mensagemIndeterminado: '',
    mensagemApi: '', // motivo (texto puro, nao confiavel) devolvido pela Talent no erro do finalizar
    resultadoFinalizar: null, // { nrRegAcesso, motoristaNome }
    etiqueta: null,
    retornoSemProcessar: false, // true ao voltar da correcao do ajudante: reexibe erro_finalizar sem chamar finalizar
    etiquetaPendente: 'motorista', // motorista | ajudante: qual etiqueta ainda precisa ser impressa
    motoristaImpressa: false, // true quando a etiqueta do motorista ja saiu com sucesso
    temEtiquetaAjudante: false, // tem_etiqueta_ajudante da resposta de gerar-etiqueta do motorista
    processando: false, // trava de reentrancia contra toque duplo/chamadas concorrentes (item 3 da demanda impressao-arquitetura-producao-ux)
};

// Reinicia o controle das duas etiquetas (novo atendimento/cancelar/novo finalizar).
function imprResetarEtiquetas() {
    imprState.etiquetaPendente = 'motorista';
    imprState.motoristaImpressa = false;
    imprState.temEtiquetaAjudante = false;
}

function telaImpressao() {
    return `<div id="imprConteudo">${imprRenderCorpo()}</div>`;
}

// -------------------- traducao de mensagens tecnicas (nunca exibir e.message bruto ao motorista) --------------------
// Item 1 da demanda impressao-arquitetura-producao-ux: mensagens tecnicas
// (URL, token, stack, resposta bruta do servico local) NUNCA chegam a tela
// do motorista — sempre vao so para console.error. Esta funcao centraliza a
// traducao para os cenarios conhecidos.

function imprMensagemAmigavel(contexto, erro) {
    // 'contexto' identifica em qual etapa o erro ocorreu, para escolher a
    // mensagem mais util sem expor detalhe tecnico algum.
    console.error(`[impressao] erro em ${contexto}:`, erro);
    switch (contexto) {
        case 'configuracao_servico':
            return 'Serviço de impressão indisponível no momento. Chame o atendente.';
        case 'servico_offline':
            return 'Não foi possível conectar à impressora. Chame o atendente.';
        case 'listar_impressoras':
            return 'Não foi possível conectar à impressora. Chame o atendente.';
        case 'impressora_nao_permitida':
            return 'Essa impressora não está disponível. Chame o atendente.';
        case 'gerar_etiqueta':
            return 'Serviço de impressão indisponível no momento. Chame o atendente.';
        case 'indeterminado':
            return 'Não foi possível confirmar a impressão. Chame o atendente para verificar.';
        case 'finalizar':
            return 'Não foi possível concluir o check-in agora. Chame o atendente.';
        default:
            return 'Falha de conexão. Tente novamente ou chame o atendente.';
    }
}

// Ponto de entrada de renderTela() para exp/rec_impressao. Ao voltar da correcao
// do ajudante (Voltar, sem salvar) so reexibe a tela de erro, sem novo finalizar.
function entrarTelaImpressao() {
    if (imprState.retornoSemProcessar) {
        imprState.retornoSemProcessar = false;
        imprRender();
        return;
    }
    processarImpressao();
}

async function processarImpressao() {
    Object.assign(imprState, {
        tela: 'enviando',
        mensagemErro: '',
        mensagemIndeterminado: '',
        mensagemApi: '',
        resultadoFinalizar: null,
        etiqueta: null,
    });
    imprResetarEtiquetas();
    imprRender();
    await imprFinalizar();
}

// -------------------- passo 1: finalizar() (ja existe, so consumido aqui) --------------------

async function imprFinalizar() {
    try {
        const dados = await api('atendimento.php', 'finalizar', { id_atendimento: state.idAtendimento });
        imprState.resultadoFinalizar = {
            nrRegAcesso: dados.senha ?? null,
            motoristaNome: dados.motorista_nome ?? (state.dados && state.dados.motorista_nome) ?? null,
        };
        await imprIniciarImpressao();
    } catch (e) {
        // Inclui o codigo TALENT_CHECKIN_DESATIVADO (esperado nesta etapa,
        // .env ainda nao ativado) e qualquer outro erro de finalizar() —
        // sempre tratado como estado de erro claro/recuperavel, nunca como
        // sucesso, nunca trava a tela.
        imprState.mensagemErro = imprMensagemAmigavel('finalizar', e);
        imprState.mensagemApi = imprMensagemApiDe(e);
        imprState.tela = 'erro_finalizar';
        imprRender();
    }
}

// Motivo da Talent (dados.mensagem_api) vindo do finalizar: so string nao vazia,
// limitada a 300 chars; sempre exibido via textContent (nunca innerHTML).
function imprMensagemApiDe(erro) {
    const d = erro && erro.dados;
    const m = d && typeof d === 'object' ? d.mensagem_api : null;
    if (typeof m !== 'string') return '';
    return m.trim().slice(0, 300);
}

// -------------------- comunicacao: backend PHP (token do TOTEM) --------------------

async function imprApiConfiguracaoServicoLocal() {
    const res = await fetch(`${API_BASE}impressao.php?acao=configuracao-servico-local`, {
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

function imprApiGerarEtiqueta(reimpressao, destinatario) {
    const corpo = { id_atendimento: state.idAtendimento };
    if (destinatario === 'ajudante') corpo.destinatario = 'ajudante';
    if (reimpressao) corpo.reimpressao = 1;
    return api('impressao.php', 'gerar-etiqueta', corpo);
}

// -------------------- comunicacao: servico local Node.js (token PROPRIO, nunca o do totem) --------------------
// Implementacao PROPRIA (nao importa/chama nada de diagnostico-impressao.js),
// mesmo padrao: AbortController para desistir por conta propria do fetch,
// nunca retry automatico embutido aqui.

async function imprFetchServicoLocal(caminho, opcoes, timeoutMs) {
    const url = `${imprState.urlServicoLocal}${caminho}`;
    const headers = Object.assign({}, (opcoes && opcoes.headers) || {});
    if (caminho !== '/saude') {
        headers['Authorization'] = `Bearer ${imprState.tokenServicoLocal}`;
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

// -------------------- passos 2-4: impressora + gerar etiqueta + imprimir --------------------

async function imprIniciarImpressao() {
    if (imprState.processando) return; // trava de reentrancia — item 3
    imprState.processando = true;
    imprState.tela = 'preparando';
    imprRender();

    try {
        let cfg;
        try {
            cfg = await imprApiConfiguracaoServicoLocal();
        } catch (e) {
            imprState.mensagemErro = imprMensagemAmigavel('configuracao_servico', e);
            imprState.tela = 'erro';
            imprRender();
            return;
        }
        imprState.urlServicoLocal = cfg.url;
        imprState.tokenServicoLocal = cfg.token;
        imprState.frontendTimeoutMs = (typeof cfg.frontend_timeout_ms === 'number' && cfg.frontend_timeout_ms > 0)
            ? cfg.frontend_timeout_ms
            : IMPR_TIMEOUT_FALLBACK_MS;

        const impressoraSalva = localStorage.getItem(IMPR_CHAVE_IMPRESSORA);
        if (!impressoraSalva) {
            const listaOk = await imprCarregarImpressoras();
            if (!listaOk) return;
            imprState.tela = 'selecionar_impressora';
            imprRender();
            return;
        }
        await imprExecutarImpressaoInterno(impressoraSalva, false);
    } finally {
        imprState.processando = false;
    }
}

async function imprCarregarImpressoras() {
    try {
        const resp = await imprFetchServicoLocal('/impressoras', { method: 'GET' });
        if (!resp.ok) {
            imprState.mensagemErro = imprMensagemAmigavel('listar_impressoras', (resp.dados && resp.dados.erro) || 'falha ao listar impressoras');
            imprState.tela = 'erro';
            imprRender();
            return false;
        }
        imprState.impressoras = (resp.dados && resp.dados.impressoras) || [];
        if (imprState.impressoras.length === 0) {
            imprState.mensagemErro = imprMensagemAmigavel('listar_impressoras', 'nenhuma impressora encontrada');
            imprState.tela = 'erro';
            imprRender();
            return false;
        }
        return true;
    } catch (e) {
        imprState.mensagemErro = imprMensagemAmigavel('servico_offline', e);
        imprState.tela = 'erro';
        imprRender();
        return false;
    }
}

function imprSelecionarImpressora(nome) {
    if (imprState.processando) return; // trava de reentrancia — item 3
    localStorage.setItem(IMPR_CHAVE_IMPRESSORA, nome);
    imprExecutarImpressao(nome, false);
}

// Ponto de entrada publico (disparado por clique) — aplica a trava de
// reentrancia e sempre libera em finally, mesmo em excecao nao tratada.
async function imprExecutarImpressao(nomeImpressora, reimpressao) {
    if (imprState.processando) return; // trava de reentrancia — item 3
    imprState.processando = true;
    try {
        await imprExecutarImpressaoInterno(nomeImpressora, reimpressao);
    } finally {
        imprState.processando = false;
    }
}

// Implementacao real, reaproveitada tanto pelo ponto de entrada publico
// (imprExecutarImpressao) quanto pelo fluxo interno ja protegido pela trava
// de imprIniciarImpressao (evita trava dupla/aninhada no mesmo fluxo).
async function imprExecutarImpressaoInterno(nomeImpressora, reimpressao) {
    // Imprime a etiqueta pendente; se for a do motorista e houver ajudante,
    // imprime em seguida a do ajudante (outro papel). So apos as duas, concluido.
    // Uma retentativa imprime SOMENTE a etiqueta pendente (nunca repete a do
    // motorista ja impressa).
    while (true) {
        const dest = imprState.etiquetaPendente;
        const impressa = await imprImprimirUmaEtiqueta(nomeImpressora, reimpressao, dest);
        if (!impressa) return;
        if (dest === 'motorista') {
            imprState.motoristaImpressa = true;
            if (imprState.temEtiquetaAjudante) {
                imprState.etiquetaPendente = 'ajudante';
                continue;
            }
        }
        imprState.tela = 'concluido';
        imprRender();
        return;
    }
}

// Gera + envia UMA etiqueta. Devolve true se impressa; false se parou em
// algum estado (erro/sem_papel/indeterminado/selecionar_impressora), ja renderizado.
async function imprImprimirUmaEtiqueta(nomeImpressora, reimpressao, dest) {
    imprState.tela = 'preparando';
    imprRender();

    let etiqueta;
    try {
        etiqueta = await imprApiGerarEtiqueta(reimpressao, dest);
    } catch (e) {
        const msgGerar = imprMensagemAmigavel('gerar_etiqueta', e);
        imprState.mensagemErro = dest === 'ajudante'
            ? 'Não foi possível imprimir a etiqueta do ajudante. Chame o atendimento.'
            : msgGerar;
        imprState.tela = 'erro';
        imprRender();
        return false;
    }
    imprState.etiqueta = etiqueta;
    if (dest === 'motorista') imprState.temEtiquetaAjudante = !!(etiqueta && etiqueta.tem_etiqueta_ajudante === true);

    imprState.tela = 'imprimindo';
    imprRender();

    let resp;
    try {
        resp = await imprFetchServicoLocal('/imprimir', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                pdf_base64: etiqueta.pdf_base64,
                impressora: nomeImpressora,
                identificador: etiqueta.identificador,
            }),
        }, imprState.frontendTimeoutMs);
    } catch (e) {
        // falha de rede nao tratada nunca pode virar "sucesso" — trata como
        // indeterminado, igual ao padrao ja aprovado no diagnostico
        imprState.mensagemIndeterminado = imprMensagemAmigavel('indeterminado', e);
        imprState.tela = 'indeterminado';
        imprRender();
        return false;
    }

    if (resp.abortou) {
        imprState.mensagemIndeterminado = imprMensagemAmigavel('indeterminado', 'timeout aguardando confirmacao do servico local');
        imprState.tela = 'indeterminado';
        imprRender();
        return false;
    }

    // Falta de papel: o servico cancelou o job (nao imprime depois), com
    // qualquer HTTP. Estado proprio, sem limite de tentativas, so por clique.
    if (resp.dados && resp.dados.status === 'sem_papel') {
        imprState.tela = 'sem_papel';
        imprRender();
        return false;
    }

    if (resp.ok && resp.dados && (resp.dados.status === 'impresso' || resp.dados.status === 'ja_impresso')) {
        return true;
    }

    if (resp.status === 504 || (resp.dados && resp.dados.status === 'indeterminado')) {
        imprMensagemAmigavel('indeterminado', (resp.dados && (resp.dados.erro || resp.dados.mensagem)) || 'resposta indeterminada do servico local');
        imprState.mensagemIndeterminado = 'Não foi possível confirmar a impressão. Chame o atendente para verificar.';
        imprState.tela = 'indeterminado';
        imprRender();
        return false;
    }

    const mensagemErroTecnica = (resp.dados && resp.dados.erro) || 'Falha ao imprimir a etiqueta.';
    if (/impressora/i.test(mensagemErroTecnica)) {
        // caminho reativo: mensagem do servico local menciona a impressora
        // (ex.: removida da allowlist) — limpa a selecao salva e reabre a
        // tela de selecao, mas SEM nunca expor o texto tecnico bruto.
        localStorage.removeItem(IMPR_CHAVE_IMPRESSORA);
        imprState.mensagemErro = imprMensagemAmigavel('impressora_nao_permitida', mensagemErroTecnica);
        const listaOk = await imprCarregarImpressoras();
        if (listaOk) { imprState.tela = 'selecionar_impressora'; imprRender(); }
        return false;
    }

    imprState.mensagemErro = imprMensagemAmigavel('erro_impressao', mensagemErroTecnica);
    imprState.tela = 'erro';
    imprRender();
    return false;
}

// "Tentar novamente"/"Imprimir novamente" — SEMPRE acao manual explicita
// (nunca automatica), SEMPRE reexecuta so a impressao (gerar-etiqueta +
// imprimir, novo identificador/job a cada vez) — NUNCA rechama
// atendimento.php?acao=finalizar (ja confirmado com sucesso antes de chegar
// aqui). Marca reimpressao=1 (exceto na primeira tentativa automatica logo
// apos o sucesso de finalizar(), feita por imprIniciarImpressao()).
function imprTentarNovamente() {
    if (imprState.processando) return; // trava de reentrancia — item 3
    // "Imprimir novamente" apos concluido reimprime o conjunto (motorista e, se houver, ajudante)
    if (imprState.tela === 'concluido') imprResetarEtiquetas();
    const impressoraSalva = localStorage.getItem(IMPR_CHAVE_IMPRESSORA);
    if (impressoraSalva) {
        imprExecutarImpressao(impressoraSalva, true);
    } else {
        imprIniciarImpressao();
    }
}

// -------------------- render --------------------

function imprRender() {
    const el = document.getElementById('imprConteudo');
    if (!el) return;
    el.innerHTML = imprRenderCorpo();
    if (imprState.tela === 'erro_finalizar') imprPreencherMotivoApi();
    // Nos estados que aguardam o atendente (sem papel / erro com "Tentar
    // novamente") a inatividade fica suspensa: fecha aviso aberto e rearma.
    if (imprInatividadeSuspensa() && idleEstado === 'aviso') {
        fecharAvisoInatividade();
        reiniciarIdle();
    }
    const lista = el.querySelector('#imprListaImpressoras');
    if (lista) {
        lista.querySelectorAll('[data-impressora]').forEach(btn => {
            btn.addEventListener('click', () => imprSelecionarImpressora(btn.dataset.impressora));
        });
    }
}

// Indicador visual de processamento (item 2 da demanda): spinner CSS
// reaproveitado nos 4 momentos de espera (consultando configuracao,
// consultando impressoras, enviando impressao, aguardando resultado).
// Classes/animacao definidas em app.css (.impr-spinner/@keyframes impr-girar),
// sem cor nova — usa as cores ja existentes no projeto.
function imprTelaCarregando(mensagem) {
    return `<div class="impr-spinner" aria-hidden="true"></div>
        <div class="subtitulo">${escapeHtml(mensagem)}</div>`;
}

// true quando a tela de impressao esta aguardando acao do atendente/motorista
// (sem papel, erro, indeterminado ou erro ao finalizar). Consultada por app.js
// (mostrarInatividade) para suspender o timer de inatividade, sem teto.
function imprInatividadeSuspensa() {
    // Correcao do ajudante (a partir de erro_finalizar): o motorista aguarda o atendente.
    if ((state.tela === 'exp_ajudante' || state.tela === 'rec_ajudante') && state.ajudanteCorrecao) return true;
    return (state.tela === 'exp_impressao' || state.tela === 'rec_impressao')
        && (imprState.tela === 'sem_papel' || imprState.tela === 'erro'
            || imprState.tela === 'indeterminado' || imprState.tela === 'erro_finalizar');
}

function imprRenderCorpo() {
    switch (imprState.tela) {
        case 'sem_papel': return imprTelaSemPapel();
        case 'enviando': return imprTelaCarregando('Enviando seus dados...');
        case 'erro_finalizar': return imprTelaErroFinalizar();
        case 'selecionar_impressora': return imprTelaSelecionarImpressora();
        case 'preparando': return imprTelaCarregando('Preparando etiqueta...');
        case 'imprimindo': return imprTelaCarregando(imprTextoImprimindo());
        case 'concluido': return imprTelaConcluido();
        case 'erro': return imprTelaErro();
        case 'indeterminado': return imprTelaIndeterminado();
        default: return '';
    }
}

function imprTextoImprimindo() {
    if (imprState.etiquetaPendente === 'ajudante') return 'Imprimindo etiqueta do ajudante...';
    if (imprState.temEtiquetaAjudante) return 'Imprimindo etiqueta do motorista...';
    return 'Imprimindo...';
}

// Linha extra quando a etiqueta do ajudante falhou depois da do motorista.
function imprLinhaAjudantePendente() {
    return (imprState.etiquetaPendente === 'ajudante' && imprState.motoristaImpressa)
        ? '<div class="subtitulo">A etiqueta do ajudante ainda não foi impressa.</div>'
        : '';
}

function imprTelaErroFinalizar() {
    // Com motivo da Talent: SO o bloco do motivo (o texto vai por textContent em
    // imprPreencherMotivoApi) + orientacao fixa; sem a frase generica.
    const cabecalho = imprState.mensagemApi
        ? `<div class="titulo">Motivo informado pelo sistema:</div>
        <div class="impr-motivo-api" id="imprMotivoApi" role="alert"></div>
        <div class="subtitulo">Chame o atendimento.</div>`
        : `<div class="titulo">${escapeHtml(imprState.mensagemErro)}</div>`;
    const btnAjudante = imprMensagemIndicaAjudante(imprState.mensagemApi)
        ? '<button class="btn-primario impr-btn-alvo" id="imprBtnCorrigirAjudante" onclick="imprCorrigirAjudante()">Corrigir dados do ajudante</button>'
        : '';
    return `${cabecalho}
        <div class="grupo-botoes">
            ${btnAjudante}
            <button class="btn-primario impr-btn-alvo" onclick="processarImpressao()">Tentar novamente</button>
            <button class="btn-fantasma impr-btn-alvo" onclick="novoAtendimento()">Novo atendimento</button>
        </div>`;
}

// Criterio por TEXTO (sem acentos, minusculas, contem "ajudante"): FRAGIL, pois a
// Talent nao documenta um codigo de erro para falha de ajudante; se a mensagem
// mudar de redacao o botao some (a tela segue com "Tentar novamente").
function imprMensagemIndicaAjudante(msg) {
    if (typeof msg !== 'string' || msg === '') return false;
    return msg.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().includes('ajudante');
}

// Abre a tela do ajudante do fluxo atual em modo correcao (so por toque).
function imprCorrigirAjudante() {
    if (state.tipo !== 'expedicao' && state.tipo !== 'recebimento') return;
    state.ajudanteCorrecao = true;
    ir(state.tipo === 'expedicao' ? 'exp_ajudante' : 'rec_ajudante');
}

function imprPreencherMotivoApi() {
    const el = document.getElementById('imprMotivoApi');
    if (el) el.textContent = imprState.mensagemApi;
}

function imprTelaSelecionarImpressora() {
    const botoes = imprState.impressoras.map(imp => `
        <button class="btn-fantasma impr-btn-alvo" data-impressora="${escapeHtml(imp.nome)}">${escapeHtml(imp.nome)}${imp.padrao ? ' (padrão)' : ''}</button>
    `).join('');
    return `<div class="titulo">Selecione a impressora</div>
        <div class="subtitulo">Essa escolha será lembrada neste totem</div>
        <div class="grupo-botoes" id="imprListaImpressoras">${botoes}</div>
        <div class="grupo-botoes">
            <button class="btn-fantasma impr-btn-alvo" onclick="novoAtendimento()">Novo atendimento</button>
        </div>`;
}

function imprTelaConcluido() {
    const r = imprState.resultadoFinalizar || {};
    // NUNCA CPF/CNH nesta tela — so numero de acesso + nome do motorista.
    const blocoAcesso = r.nrRegAcesso
        ? `<div class="senha-caixa"><div class="rotulo">Número de acesso</div><div class="valor">${escapeHtml(r.nrRegAcesso)}</div></div>`
        : `<div class="subtitulo">Check-in registrado com sucesso.</div>`;
    const nomeMotorista = r.motoristaNome
        ? `<div class="subtitulo">Motorista: ${escapeHtml(r.motoristaNome)}</div>`
        : '';
    return `<div class="subtitulo">Tudo certo!</div>
        ${blocoAcesso}
        ${nomeMotorista}
        <div class="subtitulo">${imprState.temEtiquetaAjudante ? 'Retire as duas etiquetas.' : 'Retire o comprovante na bandeja abaixo'}</div>
        <div class="grupo-botoes">
            <button class="btn-fantasma impr-btn-alvo" onclick="imprTentarNovamente()">Imprimir novamente</button>
            <button class="btn-primario impr-btn-alvo" onclick="novoAtendimento()">Novo atendimento</button>
        </div>`;
}

function imprTelaErro() {
    return `<div class="titulo">${escapeHtml(imprState.mensagemErro)}</div>
        ${imprLinhaAjudantePendente()}
        <div class="grupo-botoes">
            <button class="btn-primario impr-btn-alvo" onclick="imprTentarNovamente()">Tentar novamente</button>
            <button class="btn-fantasma impr-btn-alvo" onclick="novoAtendimento()">Novo atendimento</button>
        </div>`;
}

function imprTelaSemPapel() {
    return `<div class="impr-alerta" role="alert">
            <div class="impr-alerta-titulo">Falta de papel na impressora.</div>
            <div class="impr-alerta-texto">Chame o atendimento.</div>
        </div>
        ${imprLinhaAjudantePendente()}
        <div class="grupo-botoes">
            <button class="btn-primario impr-btn-alvo" onclick="imprTentarNovamente()">Tentar novamente</button>
        </div>`;
}

function imprTelaIndeterminado() {
    return `<div class="titulo">Não foi possível confirmar a impressão</div>
        <div class="subtitulo">${escapeHtml(imprState.mensagemIndeterminado)}</div>
        ${imprLinhaAjudantePendente()}
        <div class="grupo-botoes">
            <button class="btn-primario impr-btn-alvo" onclick="imprTentarNovamente()">Tentar novamente</button>
            <button class="btn-fantasma impr-btn-alvo" onclick="novoAtendimento()">Novo atendimento</button>
        </div>`;
}
