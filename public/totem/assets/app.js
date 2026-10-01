// ===================================================================
// Totem UDLOG — front-end real (Expedicao e Recebimento)
// Le o token do totem em data-totem-token (definido pelo index.php)
// ===================================================================

const TOKEN = document.body.dataset.totemToken;
const API_BASE = '/api/';

// -------------------- instrumentacao de tempo (demanda captura-notas-sem-interrupcao, impl. 1) --------------------
// Ativada EXCLUSIVAMENTE por ?medir=1 na URL (lida uma vez, aqui). Sem a flag:
// marca()/medirNovaSeq()/medirLimpar() sao return imediato, nenhum DOM, nenhum
// listener, nenhum timer. So em memoria (sem localStorage/sessionStorage/
// cookie/fetch/beacon). Eventos: lista fechada abaixo; nada dinamico (sem
// imagem, texto de OCR, CNPJ, razao social, numero da nota, idAtendimento,
// mensagem de excecao). marca() nunca lanca.
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
let medirSeqContador = 0;   // contador sequencial (nota_seq), so em memoria
let medirPiso = 0;          // seqs <= piso pertencem a atendimento ja limpo: eventos tardios ignorados
let medirSeqPrevia = 0;     // nota_seq da captura em previa (0 = nenhuma / medicao desligada)
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
    } catch (e) { /* silencioso: instrumentacao nunca interfere no fluxo */ }
}

function medirLimpar() {
    if (!MEDIR) return;
    try {
        medirBuffer = [];
        medirPiso = medirSeqContador;
        medirSeqPrevia = 0;
        medirAtualizarPainel();
    } catch (e) { /* silencioso */ }
}

// Agrupa o buffer por nota_seq: { seq: { nome_evento: t } } (ultimo valor de cada evento).
// Alem disso guarda, em ordem cronologica, TODAS as ocorrencias de usar_click
// (usar_lista) e de falha_upload (falha_upload_lista): nenhum clique sobrescreve o anterior.
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

// Ambiente da medicao: SOMENTE numeros (sem userAgent, hostname, URL, caminho).
function medirAmbiente() {
    const num = v => (typeof v === 'number' && isFinite(v) && v > 0 ? Math.round(v) : null);
    let vw = null;
    let vh = null;
    try {
        const video = document.getElementById('videoScanner');
        if (video) { vw = num(video.videoWidth); vh = num(video.videoHeight); }
    } catch (e) { /* silencioso */ }
    return {
        screen_width: num(window.screen && window.screen.width),
        screen_height: num(window.screen && window.screen.height),
        viewport_width: num(window.innerWidth),
        viewport_height: num(window.innerHeight),
        video_width: vw,
        video_height: vh
    };
}

// notas[]: por nota_seq. tempo_total = liberado - cap_click (principal);
// tentativas_upload = numero de usar_click (null se nenhum). Primeiro usar_click = inicial.
// tempo_humano_inicial = 1o usar_click - cap_click; tempo_retry = ultimo usar_click -
// ultima falha_upload anterior a ele (so com retry, senao null); tempo_tecnico_sucesso =
// liberado - ultimo usar_click. Campos sem os eventos necessarios ficam null.
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

// JSON sanitizado (so numeros, null e nomes fixos) usado no download local.
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

// Atualiza o painel SO se ele estiver na tela atual; nunca falha se nao existe.
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
    } catch (e) { /* silencioso */ }
}

// Fallback SEM textarea: arquivo JSON local (Blob + <a download> temporario,
// invisivel e removido logo depois). Sem rede, storage ou backend.
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

// Remove referencias do painel ao sair da tela (o DOM some junto com a tela) e
// devolve o alinhamento original de #tela. Chamado por ir().
function medirDesmontarPainel() {
    if (!MEDIR || !medirPainelEls) return;
    try { medirPainelEls.tela.style.justifyContent = ''; } catch (e) { /* silencioso */ }
    medirPainelEls = null;
}

// Painel NO FLUXO da tela (rec_digitaliza, so com ?medir=1): <details> recolhido
// ao final de #tela, depois de Finalizar. Ao expandir empurra o conteudo; sem
// fixed/absolute/sticky/z-index. #tela tem justify-content:center + overflow-y:auto,
// o que deixaria o topo inalcancavel quando o conteudo estoura: enquanto expandido,
// o alinhamento passa a flex-start (rolagem normal alcanca topo e rodape).
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

// Fonte UNICA do texto/versao/hash do termo LGPD — renderizada no servidor
// por public/totem/index.php (App\Content\TermoLgpd) e injetada aqui via
// <script type="application/json" id="lgpd-termo-dados">, nunca duplicada
// em JS (demanda tela-inicial-lgpd-totem, 2026-09-24).
const termoLgpdDados = JSON.parse(
    document.getElementById('lgpd-termo-dados')?.textContent || '{"versao":"","hash":"","texto":""}'
);

const state = {
    // Tela inicial passa a ser 'lgpd' — gate obrigatorio de ciencia/aceite
    // ANTES de qualquer atendimento (demanda tela-inicial-lgpd-totem,
    // 2026-09-24). 'home' continua existindo, so passa a ser a tela
    // SEGUINTE, nao mais a primeira.
    tela: 'lgpd',
    // Controle de fluxo em memoria — NUNCA gravado em localStorage/
    // sessionStorage/cookie, para garantir que qualquer refresh sempre
    // volta para a tela LGPD com o checkbox desmarcado, por construcao.
    lgpdAceito: false,
    // Token de aceite BRUTO (64 hex) recebido de lgpd.php?acao=aceitar —
    // vive so em memoria durante a sessao do atendimento atual, NUNCA
    // persistido. Enviado no payload de atendimento.php?acao=iniciar e
    // limpo logo apos o uso (token e de uso unico de qualquer forma).
    lgpdTokenAceite: null,
    tipo: null,
    idAtendimento: null,
    placa: '',
    ordens: [],
    dados: {},
    // Notas do Recebimento, na ordem de captura (a POSICAO na lista e o que o
    // motorista ve como "Nota N de M"). Cada entrada:
    // { uid, ordem, idNota, imagem, numero, confirmado, origem, estado,
    //   sugestao, sugestaoOcr, ocrConcluido, manualOverride, destaque }
    // uid: identidade local imutavel (state.notaUidSeq, por atendimento) — TODA
    // busca/closure usa o uid, nunca a ordem. idNota/ordem: valores devolvidos
    // pelo servidor (nota.php?acao=processar); idNota e a chave nas chamadas
    // definir-numero/identificar-cliente/excluir. imagem: miniatura so em memoria.
    notaUidSeq: 0,
    notasNumeros: [],
    previewNotaAtual: null,
    capturaNotaEmAndamento: false,
    finalizandoDigitalizacao: false,
    // true durante a chamada excluir (travada na mesma tick do primeiro toque)
    excluindoNota: false,
    ultimaLeituraQr: null,
    // estado dedicado a captura/validacao de CNH/CRLV da Expedicao via VIO
    // Decode (demanda expedicao-vio-cnh-crlv) — nao compartilhado com Recebimento.
    // cnhPromise/crlvPromise: Promise fire-and-forget viva em memoria do
    // iniciar-processamento em segundo plano (REPLANEJAMENTO 2026-09-09,
    // ciclo assincrono) — perdida ao recarregar a pagina (limitacao aceita,
    // ver docs/handoffs/2026-09-08-expedicao-vio-cnh-crlv.md), nesse caso a
    // tela de espera recorre a polling (ver aguardarDocumentos()).
    // cnhUltimoStatus/crlvUltimoStatus (raw, resposta bruta ALLOWLIST de
    // documento.php — nunca inferido) e cnhAoAtualizar/crlvAoAtualizar
    // (callback da tela ATUALMENTE visivel, redirecionado conforme o
    // motorista navega entre a captura do CRLV e a tela de espera) — demanda
    // migracao-vio-api-br-com-cache, 2026-09-25, ver
    // atualizarStatusDocumento()/rotuloStatusProcessamento().
    exp: { previewImg: null, previewCanvas: null, qrTentativas: { cnh: 0, crlv: 0 }, motivos: { cnh: null, crlv: null }, reescaneio: { cnh: false, crlv: false }, reprovacaoAberta: null, cnhOrigem: null, crlvOrigem: null, emAndamento: false, cnhPromise: null, crlvPromise: null, cnhUltimoStatus: null, crlvUltimoStatus: null, cnhAoAtualizar: null, crlvAoAtualizar: null },
    // mesmo padrao para Recebimento (REPLANEJAMENTO 2026-09-09 estendeu a
    // validacao VIO Decode tambem para o Recebimento) — telas/estado NOVOS E
    // DEDICADOS, nao compartilhados com a Expedicao nem com o fluxo antigo
    // de rec_cnh/rec_crlv (semantica diferente, sem QR).
    rec: { previewImg: null, previewCanvas: null, qrTentativas: { cnh: 0, crlv: 0 }, motivos: { cnh: null, crlv: null }, reescaneio: { cnh: false, crlv: false }, reprovacaoAberta: null, cnhOrigem: null, crlvOrigem: null, emAndamento: false, cnhPromise: null, crlvPromise: null, cnhUltimoStatus: null, crlvUltimoStatus: null, cnhAoAtualizar: null, crlvAoAtualizar: null },
};

function estadoExpVazio() {
    return { previewImg: null, previewCanvas: null, qrTentativas: { cnh: 0, crlv: 0 }, motivos: { cnh: null, crlv: null }, reescaneio: { cnh: false, crlv: false }, reprovacaoAberta: null, cnhOrigem: null, crlvOrigem: null, emAndamento: false, cnhPromise: null, crlvPromise: null, cnhUltimoStatus: null, crlvUltimoStatus: null, cnhAoAtualizar: null, crlvAoAtualizar: null };
}

function estadoRecVazio() {
    return { previewImg: null, previewCanvas: null, qrTentativas: { cnh: 0, crlv: 0 }, motivos: { cnh: null, crlv: null }, reescaneio: { cnh: false, crlv: false }, reprovacaoAberta: null, cnhOrigem: null, crlvOrigem: null, emAndamento: false, cnhPromise: null, crlvPromise: null, cnhUltimoStatus: null, crlvUltimoStatus: null, cnhAoAtualizar: null, crlvAoAtualizar: null };
}

// -------------------- comunicacao com a API --------------------

// opcoes.timeoutMs (opcional): aborta a requisicao apos esse prazo (AbortError, sem
// status). So o upload da nota e a listagem usam; as demais chamadas seguem sem prazo.
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
        // detalhes estruturados do corpo de erro (ex.: 422 NOTAS_SEM_NUMERO);
        // quem consome valida o formato, nunca exibe o conteudo cru
        if (json.codigo !== undefined) erro.codigo = json.codigo;
        if (json.dados !== undefined) erro.dados = json.dados;
        throw erro;
    }
    return json.dados;
}

function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

// Versao (filemtime) de assets que o JS nao consegue versionar sozinho, vinda
// do index.php no bloco <script type="application/json" id="assets-versoes">.
// Fallback seguro: bloco ausente/invalido = URL sem ?v (nunca lanca erro).
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

// Pasta versionada do Tesseract.js (arquivos identificados pelo SHA-256 em
// assets/vendor/MANIFEST.sha256). Relativa a pagina (public/totem/index.php).
const TESSERACT_VENDOR_PATH = 'assets/vendor/tesseract-5.1.1';

// Toast de erro: FALLBACK para telas sem area de erro propria. Fica sob a barra
// Cancelar (topo), nunca sobre os botoes principais, e nao recebe toque
// (pointer-events:none em app.css). O contêiner (role=alert, aria-live
// assertive) ja existe vazio e recolhido quando o texto entra. Na revisao das
// notas a mensagem vai SO inline em #revisaoErro (sem toast duplicado).
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

// Maquina de estado explicita de inatividade (correcao do achado BLOQUEANTE
// do /02-testes de confirmacao, Etapa 1, 2026-09-15). Antes havia UMA unica
// variavel idleTimer reaproveitada tanto para o timer principal (180s) quanto
// para o timer de abandono (30s) — clearTimeout() no listener global era
// incondicional, entao qualquer toque dentro do proprio overlay de aviso
// cancelava o timer de abandono e reagendava 180s, sem fechar o overlay
// (divergencia entre UI e comportamento real). Agora:
// - idleTimerPrincipal: EXCLUSIVO do periodo de monitoramento normal (180s).
// - idleTimerAbandono: EXCLUSIVO do periodo de aviso aberto, aguardando
//   resposta (30s). So e criado por mostrarInatividade() e so e limpo por
//   fecharAvisoInatividade() (chamada exclusivamente pelo botao "Continuar",
//   por ir()/novoAtendimento(), ou pelo proprio disparo do timer de abandono).
// - idleEstado: 'normal' | 'aviso' | 'inativo' — 'inativo' cobre state.tela
//   === 'home' (nenhum monitoramento ativo). O listener global consulta esse
//   estado ANTES de agir: em 'aviso', nao mexe em timer nenhum (nenhum toque
//   fora do botao "Continuar" cancela/estende o prazo de 30s).
let idleTimerPrincipal = null;
let idleTimerAbandono = null;
let idleEstado = 'inativo';
const IDLE_MS = 180000;
const IDLE_ABANDONO_MS = 30000;
// Teto da suspensao do aviso por trabalho ativo em notas: contado a partir do
// primeiro disparo do timer de inatividade com trabalho ativo. Passado o teto,
// o aviso aparece normalmente (recognize() travado nao segura o totem).
const INATIVIDADE_TETO_SUSPENSAO_MS = 120000;
// Instante (performance.now(), relogio monotonico: nao sofre salto de relogio
// do sistema) do inicio da suspensao corrente; 0 = sem suspensao.
let suspensaoInicioMs = 0;

// Reinicia o timer de monitoramento NORMAL (180s). So deve ser chamada
// quando idleEstado !== 'aviso' (o listener global ja garante isso). Nunca
// mexe em idleTimerAbandono.
function reiniciarIdle() {
    clearTimeout(idleTimerPrincipal);
    suspensaoInicioMs = 0;
    // 'lgpd' tratada com a MESMA condicao ja existente de 'home' (demanda
    // tela-inicial-lgpd-totem, 2026-09-24) — nenhum monitoramento de
    // inatividade antes do motorista sequer iniciar o atendimento.
    if (state.tela === 'home' || state.tela === 'lgpd') {
        idleEstado = 'inativo';
        return;
    }
    idleEstado = 'normal';
    idleTimerPrincipal = setTimeout(mostrarInatividade, IDLE_MS);
}

// Geracao do atendimento: incrementada em novoAtendimento() (cancelamento/novo
// atendimento) e no reset de iniciarRecebimento(). Toda operacao assincrona de
// notas (upload, OCR, identificar-cliente, salvar numero, concluir) captura o
// valor ao iniciar e descarta o resultado se ele mudou (ver atendimentoVigente()).
let atendimentoGeracao = 0;

// true enquanto houver trabalho do sistema sobre as notas: upload em andamento,
// OCR em fila ou em execucao, identificar-cliente em voo.
function trabalhoAtivoNotas() {
    return !!state.capturaNotaEmAndamento
        || ocrProcessando
        || ocrFila.length > 0
        || identificacoesEmVoo > 0;
}

// Chamada quando um trabalho termina: se foi o ultimo, reinicia o tempo completo
// de inatividade (nunca mexe no aviso aberto).
function notificarFimDeTrabalhoNotas() {
    if (idleEstado === 'aviso' || trabalhoAtivoNotas()) return;
    reiniciarIdle();
}

// Aviso de inatividade em overlay PROPRIO e independente (achado bloqueante
// do /02-testes de confirmacao, demanda talent-doctos-finalizacao-checkin).
// Antes chamava abrirModal(), que reescreve o MESMO #modalCaixa/#modalFundo
// usado pelo modal obrigatorio de numero da nota (rec_digitaliza) — o timer de
// inatividade roda mesmo com esse modal aberto e, ao disparar, destruia o
// numero digitado/teclado e travava o motorista (numeroModalAberta nunca era
// resetada, "Continuar" so escondia o overlay sem restaurar nada). Agora usa
// #modalInatividadeFundo/Caixa (ver iniciarApp()), elemento PROPRIO, nunca
// toca em #modalCaixa/#modalFundo nem em #modalConfirmCancelNotaFundo/Caixa —
// por isso fica numa camada acima de TODOS os outros overlays (ver
// .modal-fundo-inatividade em app.css) e qualquer modal que esteja aberto por
// baixo (nenhum, numero da nota manual/sugestao, ou a confirmacao de
// cancelamento empilhada sobre ele) continua exatamente como estava.
function mostrarInatividade() {
    // OCR/upload/identificacao em curso contam como atividade do sistema: nao
    // mostra o aviso nem cancela o atendimento. O tempo completo (IDLE_MS)
    // reinicia quando o ultimo trabalho terminar (ver notificarFimDeTrabalhoNotas()).
    // A suspensao vale no maximo INATIVIDADE_TETO_SUSPENSAO_MS; ao expirar, o
    // aviso aparece normalmente mesmo com trabalho ativo.
    if (trabalhoAtivoNotas()) {
        // || 1: performance.now() nunca fica em 0 aqui (passaram >= IDLE_MS desde a
        // carga), mas o 0 e o sentinela de "sem suspensao" e nao pode ser um instante.
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
    // timer de abandono EXCLUSIVO (30s) — variavel propria, nunca compartilhada
    // com o timer principal. So e cancelado por fecharAvisoInatividade().
    clearTimeout(idleTimerAbandono);
    idleTimerAbandono = setTimeout(() => {
        idleTimerAbandono = null;
        fecharAvisoInatividade();
        cancelarESair();
    }, IDLE_ABANDONO_MS);
}

// Unico caminho acionado pelo botao "Continuar": fecha o aviso, cancela o
// timer de abandono e volta ao monitoramento normal (180s).
function continuarAposAvisoInatividade() {
    fecharAvisoInatividade();
    reiniciarIdle();
}

// Fecha o overlay de inatividade E cancela o timer de abandono (30s), se
// houver — garante que nenhum callback atrasado de um timer ja "encerrado"
// ainda dispare depois (sem callback fantasma). Nunca toca em #modalCaixa/
// #modalFundo nem em #modalConfirmCancelNotaFundo/Caixa, entao qualquer modal
// aberto por baixo (numero da nota manual/sugestao, confirmacao de
// cancelamento) permanece intacto, sem nenhuma destruicao/recriacao.
function fecharAvisoInatividade() {
    document.getElementById('modalInatividadeFundo').classList.remove('aberto');
    clearTimeout(idleTimerAbandono);
    idleTimerAbandono = null;
}

// So reinicia o monitoramento em resposta a interacao do usuario quando o
// aviso de inatividade NAO estiver aberto. Enquanto idleEstado === 'aviso',
// nenhum toque fora do botao "Continuar" (que chama continuarAposAvisoInatividade
// diretamente, sem passar por aqui) pode cancelar/estender o prazo de 30s.
['click', 'touchstart', 'keydown'].forEach(evento => document.addEventListener(evento, () => {
    if (idleEstado === 'aviso') return;
    reiniciarIdle();
}));

// -------------------- modal generico --------------------

// Esvazia a caixa de um modal: tira o src de toda <img> (a foto da nota nunca fica
// retida em DOM oculto nem aparece por um instante no proximo modal) e remove o
// conteudo inteiro (os handlers ligados aos botoes saem junto com os elementos).
function limparCaixaModal(id) {
    const caixa = document.getElementById(id);
    if (!caixa) return;
    caixa.querySelectorAll('img').forEach(img => { img.removeAttribute('src'); });
    caixa.innerHTML = '';
}

function abrirModal(html) {
    limparCaixaModal('modalCaixa'); // troca de modal com o anterior ainda aberto (ex.: Corrigir)
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
        try { await api('atendimento.php', 'cancelar', { id_atendimento: state.idAtendimento }); } catch (e) { /* segue mesmo se falhar */ }
    }
    novoAtendimento();
}

// -------------------- navegacao --------------------

function ir(tela) {
    medirDesmontarPainel();
    pararCamera();
    state.tela = tela;
    // Sem .barra-cancelar em 'lgpd' (mesma decisao ja tomada para 'home':
    // botao cancelar aparece em toda tela do fluxo EXCETO a inicial —
    // demanda tela-inicial-lgpd-totem, 2026-09-24).
    document.getElementById('barraCancelar').style.display = (tela === 'home' || tela === 'lgpd') ? 'none' : 'block';
    fecharTeclado();
    fecharModal();
    fecharConfirmacaoExcluirNota();
    if (tela === 'rec_revisao_numeros') esconderToastErro(); // revisao: mensagens so inline
    // toda troca de tela (cancelar, voltar ao inicio, novo atendimento) fecha
    // tambem o aviso de inatividade, se estiver aberto — evita overlay orfao
    // visivel por cima da tela nova. fecharAvisoInatividade() sempre limpa o
    // timer de abandono (idleTimerAbandono, 30s) mesmo que nao estivesse
    // ativo (clearTimeout(null) e um no-op seguro); reiniciarIdle() logo
    // abaixo sempre limpa o timer principal (idleTimerPrincipal, 180s) e so
    // reagenda se a tela de destino nao for 'home' — nenhum callback
    // fantasma de timer anterior sobrevive a navegacao.
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
        case 'exp_confirma': tela.innerHTML = telaConfirma('retirada de carga'); break;
        case 'exp_ajudante': tela.innerHTML = telaAjudante(); break;
        case 'exp_impressao': tela.innerHTML = telaImpressao(); processarImpressao(); break;
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
        case 'rec_confirma': tela.innerHTML = telaConfirma('entrega de carga'); break;
        case 'rec_ajudante': tela.innerHTML = telaAjudante(); break;
        case 'rec_impressao': tela.innerHTML = telaImpressao(); processarImpressao(); break;
    }
}

function novoAtendimento() {
    // Encerra qualquer polling de status-processamento em andamento (ver
    // pollGeracao/pollarAteTerminal) — cobre tanto "novo atendimento" quanto
    // cancelamento (cancelarESair() sempre chama esta funcao ao final),
    // garantindo que nenhuma chamada de status-processamento (que pode
    // custar um GET real contra a vio.api.br) continue "solta" depois que o
    // atendimento deixou de existir/estar em andamento.
    pollGeracao++;
    // invalida upload/OCR/identificacao/salvar em andamento do atendimento anterior
    atendimentoGeracao++;
    suspensaoInicioMs = 0;
    Object.assign(state, {
        // Reseta o gate LGPD junto com o restante do estado — cancelar,
        // encerrar ou iniciar um novo atendimento sempre exige nova ciencia
        // e um novo token de aceite (demanda tela-inicial-lgpd-totem,
        // 2026-09-24).
        tela: 'lgpd', lgpdAceito: false, lgpdTokenAceite: null,
        tipo: null, idAtendimento: null, placa: '',
        ordens: [], dados: {}, notaUidSeq: 0, notasNumeros: [], previewNotaAtual: null,
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

// -------------------- tela: LGPD (gate obrigatorio antes de 'home') --------------------

// Foco salvo antes de abrir o modal do termo completo — devolvido ao
// fechar (nunca altera o estado do checkbox).
let focoAnteriorModalLgpd = null;

function telaLgpd() {
    // Estrutura de wrappers alinhada ao prototipo docs/indexTotem.html
    // (.content-panel/.intro/.actions/.consent-area), namespace .lgpd-*
    // dedicado -- transplante literal de CSS, rodada 2026-09-25 "transplante
    // literal" (ver handoff). Mapeamento de CONTEUDO (texto nao foi
    // alterado, so a classe/role visual de cada elemento existente):
    // - texto "Seja bem-vindo" (antes .titulo, 28px) agora e o eyebrow
    //   (pequeno, azul, uppercase) -- mesmo texto do .eyebrow do prototipo.
    // - texto "Vamos iniciar seu atendimento." (antes .subtitulo, 18px)
    //   agora e o H1 grande -- mesmo papel do .h1 do prototipo.
    // - paragrafo de resumo (existente, sem equivalente literal no
    //   prototipo) passa a usar a tipografia do .subtitle do prototipo.
    // ORDEM interativa (consentimento antes da acao) MANTIDA como nas
    // rodadas anteriores -- prototipo tem o botao ANTES da area de
    // consentimento, mas essa e uma decisao de fluxo ja aprovada
    // anteriormente e fora do escopo desta demanda (so visual), nao foi
    // invertida.
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
        // Marcar o checkbox so HABILITA o botao — nunca avanca de tela
        // sozinho (avanco so ocorre por clique explicito, ver aceitarLgpd()).
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
    // Fechar o modal NUNCA altera o estado do checkbox/botao.
    document.getElementById('modalLgpdFundo').classList.remove('aberto');
    if (focoAnteriorModalLgpd && typeof focoAnteriorModalLgpd.focus === 'function') {
        focoAnteriorModalLgpd.focus();
    }
    focoAnteriorModalLgpd = null;
}

async function aceitarLgpd() {
    const checkbox = document.getElementById('lgpdCheckbox');
    const btnContinuar = document.getElementById('lgpdBtnContinuar');
    // Nunca confia SO no atributo disabled do DOM (um clique disparado
    // programaticamente/via devtools nao pode ter efeito) — revalida o
    // estado real do checkbox e do state antes de qualquer chamada.
    if (!checkbox || !checkbox.checked || !state.lgpdAceito || btnContinuar.disabled) return;

    btnContinuar.disabled = true;
    try {
        const dados = await api('lgpd.php', 'aceitar', {});
        // Token BRUTO de uso unico — so em memoria, nunca localStorage/
        // sessionStorage/cookie. So avanca para 'home' DEPOIS do sucesso
        // desta chamada.
        state.lgpdTokenAceite = dados.token_aceite;
        ir('home');
    } catch (e) {
        // Falha na emissao do aceite (rede/5xx/etc.): permanece na tela
        // LGPD, mensagem sanitizada (nunca detalhe tecnico), botao volta a
        // ficar habilitado para nova tentativa (checkbox continua marcado).
        btnContinuar.disabled = false;
        btnContinuar.removeAttribute('aria-disabled');
        mostrarErroTela(e.message || 'Não foi possível confirmar sua ciência agora. Tente novamente.');
    }
}

// Chamada quando atendimento.php?acao=iniciar responde 409 (aceite de
// privacidade invalido/expirado/ja usado — ex.: motorista demorou mais de
// 10 minutos entre aceitar e confirmar a placa). Volta o motorista para a
// tela LGPD (nao para uma tela de erro generica de placa), ja que o aceite
// expirou e ele precisa reafirmar ciencia — decisao registrada no handoff
// desta implementacao.
function voltarParaLgpdPorAceiteExpirado(mensagem) {
    state.lgpdTokenAceite = null;
    state.lgpdAceito = false;
    ir('lgpd');
    mostrarErroTela(mensagem || 'Aceite de privacidade inválido ou expirado. Confirme novamente.');
}

// -------------------- tela: inicio --------------------

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
        const dados = await api('atendimento.php', 'iniciar', { tipo: 'expedicao', placa, token_aceite: state.lgpdTokenAceite });
        state.lgpdTokenAceite = null; // token de uso unico, ja consumido pelo backend
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

// A area de documentos tem a mesma regra visual do scanner de notas: a
// largura e definida pelo CSS e a altura e derivada da resolucao REAL do
// stream. Isso e estritamente de layout: nao toca canvas, QR, JPEG ou upload.
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

// -------------------- expedicao: telas de captura de QR (CNH, CRLV) --------------------

function telaExpQr(titulo, tipo) {
    return `<div class="titulo">${titulo}</div>
        <div class="subtitulo">Aproxime o QR code do guia e mantenha-o bem iluminado.</div>
        <div class="caixa-scanner" id="expCamCaixa">
            <video id="expCamVideo" autoplay playsinline></video>
            <div class="guia-scanner" aria-hidden="true"></div>
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
        canvas = expCamCapturarFrame(video);
        const qr = await lerQrDoCanvas(canvas);
        if (!qr || !qr.ok) {
            expLimparFrameQr();
            const tentativa = ++state.exp.qrTentativas[tipo];
            if (tentativa >= 3) {
                ir(tipo === 'cnh' ? 'exp_cnh_manual' : 'exp_crlv_manual');
                return;
            }
            expCamMostrarStatus(`Não encontramos o QR. Aproxime e tente novamente. Tentativa ${tentativa} de 3.`, true);
            return;
        }

        // O resultado do jsQR nunca e enviado. Ele apenas impede chamada de
        // rede quando o frame nao contem QR; o backend recebe o JPEG sozinho.
        const imagemQr = canvas.toDataURL('image/jpeg', 0.85);
        expLimparFrameQr();
        canvas = null;
        // Em re-escaneio apos reprovacao o contador acumula (3 tentativas por documento).
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
// Indicador DISCRETO e NAO BLOQUANTE (nunca modal, nunca header fixo) de que
// a CNH ainda esta sendo validada em segundo plano enquanto o motorista ja
// esta fotografando o CRLV — item 2 do escopo do REPLANEJAMENTO 2026-09-09.
// So aparece se ainda houver uma Promise viva (senao a CNH ja terminou ou a
// pagina foi recarregada, e nesse caso nada e mostrado aqui — a tela de
// espera de exp_aguarde_documentos e quem trata o caso de reload).
function exibirIndicadorProcessamentoCnh() {
    const el = document.getElementById('expBgStatus');
    if (!el || !state.exp.cnhPromise) return;
    // A partir da demanda migracao-vio-api-br-com-cache (2026-09-25), o texto
    // exibido aqui vem do status_processamento EXPLICITO do backend (nunca
    // inferido) via atualizarStatusDocumento()/rotuloStatusProcessamento() —
    // redireciona o callback ja associado a este documento (ver
    // processamento do documento) para esta tela enquanto ela estiver visivel.
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
        if (!alvo) return; // a tela ja pode ter mudado
        alvo.textContent = resultado.pode_avancar
            ? 'CNH validada'
            : 'CNH ainda pendente — será solicitado preenchimento manual se necessário';
    });
}

// -------------------- expedicao: tela de espera apos CRLV confirmado --------------------
// So existe DEPOIS do CRLV (nunca entre CNH e CRLV) — texto fixo pedido no
// escopo original. Os dois status abaixo (id dedicado por documento) exibem
// os estados intermediarios EXPLICITOS do backend (ENVIANDO/PROCESSANDO_
// LEITURA/PROCESSANDO_COMPARACAO) — demanda migracao-vio-api-br-com-cache,
// 2026-09-25, item 4 do escopo (nunca inferidos no front).
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
    // Origem SEMPRE vinda explicitamente do backend (nunca inferida a partir
    // de aviso_trial — a vio.api.br nao tem esse conceito e ja podia vir
    // VIO_CACHE, achado corrigido nesta demanda).
    if (resultado.cnh.pode_avancar) state.exp.cnhOrigem = resultado.cnh.origem || 'VIO_VALIDADO';
    if (resultado.crlv.pode_avancar) state.exp.crlvOrigem = resultado.crlv.origem || 'VIO_VALIDADO';
    if (state.tela !== 'exp_aguarde_documentos') return; // usuario ja saiu da tela (ex.: cancelou)
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
        qrWorker = new Worker(urlAssetComVersao('assets/qr-worker.js', 'qrWorker'));
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

// ===================================================================
// PROCESSAMENTO ASSINCRONO DE CNH/CRLV (VIO Decode) — Expedicao E Recebimento
// (REPLANEJAMENTO 2026-09-09). Reaproveitado pelos dois fluxos por ser
// mecanica pura de rede/tempo, sem nenhuma logica de tela/estado especifica
// de Expedicao ou Recebimento — os endpoints documento.php?acao=iniciar-
// -processamento/status-processamento e atendimento.php?acao=avancar-etapa-
// -documentos ja sao agnosticos de tipo_atendimento. Mesmo espirito de
// reaproveitamento ja usado para obterQrWorker().
// ===================================================================

const PROCESSAMENTO_TIMEOUT_MS = 125000; // backend marca INDETERMINADO em 120 s (DURACAO_MAXIMA_PROCESSAMENTO_VIO_API_BR_SEGUNDOS) + margem
const PROCESSAMENTO_POLL_INTERVAL_MS = 2000;

// Geracao de polling (demanda migracao-vio-api-br-com-cache, 2026-09-25) —
// incrementada em novoAtendimento() (cobre tambem o caminho de cancelar,
// ver cancelarESair()). Todo loop de polling em andamento (pollarAteTerminal)
// guarda a geracao vigente no momento em que foi disparado e para de fazer
// QUALQUER nova chamada de rede assim que ela mudar — necessario porque,
// desde esta demanda, statusProcessamento() pode custar um GET real contra a
// vio.api.br a cada chamada (nao e mais leitura pura do banco); sem isso um
// cancelamento no meio do processamento deixaria o polling "solto" fazendo
// chamadas pagas para um atendimento que ja nao existe mais (item 11 do
// escopo desta rodada).
let pollGeracao = 0;

const TEXTO_LENDO_DOCUMENTO = 'Lendo documento... trazendo os dados.';

// Traduz o status_processamento EXPLICITO retornado por documento.php (nunca
// inferido no front — mesma divida tecnica ja identificada e corrigida nesta
// demanda para o campo `origem`) num texto SEMPRE generico para o motorista.
// Nunca inclui detalhe tecnico/motivo de reprovacao (item 9 do escopo).
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

// Repassa o status bruto mais recente de um documento para quem estiver
// "escutando" no momento (state.exp/rec.[cnh|crlv]AoAtualizar — redirecionado
// pela tela atualmente visivel: indicador discreto na captura do CRLV ou a
// tela de espera final) — evita qualquer polling duplicado so para exibicao
// (item 4 do escopo: um UNICO polling real, cujo resultado alimenta a UI).
function atualizarStatusDocumento(prefixo, doc, status) {
    const alvo = state[prefixo];
    if (!alvo) return;
    alvo[doc + 'UltimoStatus'] = status;
    const handler = alvo[doc + 'AoAtualizar'];
    if (typeof handler === 'function') handler(status);
}

// Dispara iniciar-processamento SEM aguardar (fire-and-forget) — quem chama
// guarda a Promise retornada (state.exp.cnhPromise/crlvPromise ou
// state.rec.cnhPromise/crlvPromise) para usar depois via aguardarDocumentos(),
// mas NUNCA usa await no ponto de disparo (nenhum await bloqueante entre
// confirmar a foto e liberar a proxima captura).
//
// ATUALIZADO na demanda migracao-vio-api-br-com-cache (2026-09-25): o
// endpoint iniciar-processamento agora e so o INICIO do processamento
// assincrono (ENVIANDO/PROCESSANDO_LEITURA) — so e terminal de imediato em
// dois casos: documento ja aprovado antes, ou cache-hit (origem VIO_CACHE,
// que nunca deve mostrar tela de progresso falsa, item 10 do escopo). Fora
// isso, esta Promise so resolve de fato apos pollarAteTerminal(); a Promise
// retornada aqui SEMPRE representa o resultado FINAL do documento, nunca so
// a confirmacao de envio (correcao de uma suposicao antiga que nao valia
// mais para o novo backend assincrono).
function iniciarProcessamentoDocumento(idAtendimento, tipo, imagemQrBase64, aoAtualizar) {
    const minhaGeracao = pollGeracao;
    return api('documento.php', 'iniciar-processamento', {
        id_atendimento: idAtendimento,
        tipo,
        // QR local e somente um gate de UX. O backend recebe exclusivamente
        // o JPEG do mesmo frame e nao recebe os bytes do QR nem qualquer valor QR.
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
        // Mensagem SEMPRE generica aqui — nunca propaga e.message (detalhe
        // tecnico do backend) para o motorista; caminho tratado separado do
        // mostrarErroTela(e.message) genérico usado em outros pontos da SPA
        // (item 9 do escopo desta demanda).
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

// Faz polling em status-processamento a cada 2s (nunca dispara nova chamada
// de iniciar-processamento) ate o documento chegar a estado terminal ou o
// timeout de 125s esgotar. `geracao` e o valor de pollGeracao capturado no
// INICIO da tentativa (ver iniciarProcessamentoDocumento) — se o atendimento
// for cancelado/encerrado enquanto este loop roda (pollGeracao mudou), o
// loop para IMEDIATAMENTE, sem nenhuma nova chamada de rede solta (item 11
// do escopo). `aoAtualizar` (opcional): chamado a CADA resposta, mesmo nao
// terminal, so para EXIBICAO do estado intermediario explicito do backend —
// nunca usado para decidir aprovacao.
//
// CORRECAO DE COMENTARIO (demanda migracao-vio-api-br-com-cache, 2026-09-25):
// a versao anterior deste comentario descrevia "apos recarregar a pagina no
// meio do processo" como o cenario de uso — isso NUNCA acontece na pratica.
// `state.idAtendimento` (e todo o `state` da SPA) e, por design deliberado,
// NUNCA persistido em localStorage/sessionStorage/cookie — um F5 real
// sempre reinicia a SPA do zero, de volta a tela de aceite LGPD, sem nenhum
// `id_atendimento` conhecido para pollar. Este projeto NAO implementa
// retomada visual da SPA apos reinicio do navegador/kiosk — isso e uma
// decisao de produto explicita, nao uma lacuna tecnica. O ID EXTERNO da
// vio.api.br (guardado so no backend) sobrevive a qualquer reinicio do
// navegador; o que NAO sobrevive e a tela/estado visual do atendimento em
// si, que sempre reinicia do zero.
async function pollarAteTerminal(idAtendimento, tipo, geracao, aoAtualizar) {
    const inicio = performance.now(); // monotonico: timeout imune a salto de relogio
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

// Aguarda CNH E CRLV chegarem a estado terminal — usa a Promise viva em
// memoria de cada documento, se existir (caminho normal, sem reload), com
// timeout de 125s; senao recorre a polling (ver pollarAteTerminal). Cada
// documento e resolvido de forma independente (um pode ter Promise viva
// enquanto o outro depende de polling, embora isso normalmente nao ocorra na
// pratica, ja que um reload de pagina perde as duas Promises ao mesmo tempo).
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

// Tenta avancar a etapa (documento.php ja revalida CNH/CRLV aprovados no
// gate correspondente — front nunca decide sozinho). Se o gate ainda nao
// estiver liberado (algum documento pendente/reprovado), consulta o status
// de cada um e abre o preenchimento manual SOMENTE do que ainda nao foi
// aprovado — preserva o outro documento ja aprovado, nunca pede para
// recapturar/reprocessar o que ja passou (item 5 do escopo). Generico o
// suficiente para Expedicao e Recebimento (so recebe os nomes de tela manual
// de cada fluxo).
// O backend devolve o nome da ETAPA/tela logica (exp_cnh, exp_crlv, rec_cnh,
// rec_crlv...), mas no fluxo QR-only as telas do front sao *_qr. Sem esta
// traducao, ir('exp_crlv') nao casava nenhum case de renderTela() e a tela
// ficava parada em "QR lido. Enviando para validacao..." (bug 2026-10-01).
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

// -------------------- reprovacao de CNH/CRLV: modal com motivo e re-escaneio --------------------
// `motivo_usuario` (allowlist do backend) so vem na resposta do poll que efetivou o
// terminal reprovado; por isso e guardado aqui assim que o resultado chega. Valor
// fora da allowlist ou null mantem o fluxo manual anterior.
const MENSAGENS_REPROVACAO_DOCUMENTO = {
    placa_divergente: 'A placa do documento é diferente da placa informada no atendimento.',
    rntrc_ausente: 'O documento não possui RNTRC. Escaneie outro documento ou preencha manualmente.',
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

// Abre o modal do documento reprovado. Devolve false (sem abrir) se nao houver
// motivo conhecido, se o atendimento/tela nao for mais do fluxo ou se ja ha
// modal de reprovacao aberto (evita duplicidade).
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

// "Cancelar atendimento" dentro do modal (o overlay cobre a barra fixa). Reusa a
// confirmacao padrao; "Continuar" devolve o motorista ao mesmo modal de reprovacao.
function cancelarPelaReprovacao(prefixo, doc) {
    abrirModal(`
        <div class="titulo">Cancelar atendimento?</div>
        <div class="subtitulo">Os dados digitados serão perdidos.</div>
        <button class="btn-alerta" onclick="fecharModal(); cancelarESair();">Sim, cancelar</button>
        <button class="btn-fantasma" onclick="abrirModalReprovacaoDocumento('${prefixo}', '${doc}')">Continuar atendimento</button>
    `);
}

// Destino de um documento nao aprovado: modal (se ha motivo conhecido) ou manual.
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
            // fonte de verdade = dados persistidos no atendimento (VIO ou manual);
            // so sobrescreve com valor nao vazio (preserva ex.: numero da ordem)
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
    // VIO_API_BR incluida (rodada corretiva de 2026-09-26 da demanda
    // migracao-vio-api-br-com-cache) — origem da validacao em tempo real via
    // vio.api.br, valor novo que substitui VIO_VALIDADO nas aprovacoes
    // automaticas novas (VIO_VALIDADO passa a ser so historico do fluxo
    // Serpro antigo). Mesmo padrao de texto literal ja usado para as demais
    // origens, sem detalhe tecnico adicional.
    if (origem === 'VIO_API_BR') return 'VIO_API_BR';
    // VIO_CACHE incluida (demanda migracao-vio-api-br-com-cache, 2026-09-25)
    // — sem isso, um documento aprovado via cache-hit apareceria aqui como
    // "Não validado", incoerente com pode_avancar=true.
    if (origem === 'VIO_CACHE') return 'VIO_CACHE';
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

// A maquina de estados de impressao real (telaImpressao/processarImpressao
// e as funcoes imprState/imprFinalizar/imprIniciarImpressao/etc.) foi
// extraida para public/totem/assets/impressao.js na demanda
// impressao-arquitetura-producao-ux (/01-implementacao, 2026-09-15).
// telaImpressao()/processarImpressao() continuam sendo os pontos de
// entrada chamados por renderTela() (casos exp_impressao/rec_impressao),
// agora definidos naquele arquivo global (carregado antes deste ponto de
// uso, ver <script> em index.php).

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
    let dados;
    try {
        // Isolado num try/catch proprio: o 409 de "aceite invalido/expirado"
        // so pode vir DESTA chamada (acao=iniciar) — as chamadas seguintes
        // (bloquear-excesso-notas/salvar-etapa) tambem podem responder 409,
        // mas por motivos completamente diferentes (nao relacionados a
        // LGPD), entao nao devem ser tratadas como aceite expirado.
        dados = await api('atendimento.php', 'iniciar', { tipo: 'recebimento', placa, token_aceite: state.lgpdTokenAceite });
        state.lgpdTokenAceite = null; // token de uso unico, ja consumido pelo backend
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

// -------------------- recebimento: digitalizacao das notas --------------------
// captura fica fluida: cada nota carrega e volta pra mesma tela pronta pra proxima.
// a identificacao do cliente roda em segundo plano para cada nota capturada (uma
// chamada por nota); quem decide o cliente do atendimento e o BACKEND, que devolve
// a proxima tela em concluir-digitalizacao (ver concluirDigitalizacaoRevisao()).

// true quando a captura foi aberta porque a ultima nota foi excluida (mensagem
// curta no indicador ate a primeira nova captura).
let avisoCapturaVazia = false;

const NOTAS_LIMITE = 5;

// Busca/posicao SEMPRE por uid (identidade imutavel), nunca pela ordem.
function buscarNotaPorUid(uid) {
    return (state.notasNumeros || []).find(n => n.uid === uid) || null;
}
function posicaoNotaPorUid(uid) {
    return (state.notasNumeros || []).findIndex(n => n.uid === uid) + 1; // 0 = inexistente
}

// Identificador da nota nas chamadas ao backend: id_nota (imutavel); a ordem so
// vai como fallback quando o servidor nao devolveu id_nota. Nunca os dois juntos.
function identificadorNotaApi(nota) {
    if (!nota) return null;
    if (Number.isInteger(nota.idNota) && nota.idNota > 0) return { id_nota: nota.idNota };
    if (Number.isInteger(nota.ordem) && nota.ordem > 0) return { ordem: nota.ordem };
    return null;
}

// Miniaturas da tela de captura (demanda captura-notas-sem-interrupcao, impl. 2):
// durante a captura nenhum numero e confirmado (a confirmacao acontece so na
// tela de revisao), entao as miniaturas nao mostram selo de estado. Nota
// recuperada do servidor (sem foto em memoria) mostra so um bloco neutro.
function renderMiniaturasNotas() {
    return (state.notasNumeros || []).map(n => n.imagem
        ? `<div class="miniatura"><img src="${n.imagem}" alt="Nota digitalizada"></div>`
        : '<div class="miniatura miniatura-sem-foto" title="Foto indisponível" role="img" aria-label="Foto indisponível"></div>'
    ).join('');
}

// -------------------- recebimento: identidade da nota no servidor (uid), retry e reconciliacao --------------------
// uid LOCAL (state.notaUidSeq, numerico) continua sendo a chave de DOM/handlers/OCR
// (imutavel). O uid do SERVIDOR (nota.clientUid, string) e outra coisa: gerado ANTES
// do upload, enviado no processar e usado so para idempotencia (retry com o mesmo
// valor nunca cria segunda nota) e para casar notas em nota.php?acao=listar.
const NOTA_CLIENT_UID_REGEX = /^[A-Za-z0-9_-]{8,64}$/;
const NOTA_UPLOAD_TIMEOUT_MS = 90000;
const NOTA_LISTAR_TIMEOUT_MS = 20000;

// clientUid ja usados NESTE atendimento (nunca reutiliza, nem apos excluir); ids de
// notas excluidas localmente (listar defasado nao as ressuscita); upload ambiguo
// pendente ({ clientUid, imagem, geracao, idAtendimento }); aviso do 409.
let clientUidsUsados = new Set();
let idNotasExcluidas = new Set();
let uploadPendente = null;
let avisoOcrEmAndamento = false;
let reconciliacaoEmCurso = null;
// true depois do concluir-digitalizacao aceito: OCR/identificacao tardios sao descartados
// (nenhum identificar-cliente depois de concluir)
let digitalizacaoConcluida = false;

function resetarControleNotasServidor() {
    clientUidsUsados = new Set();
    idNotasExcluidas = new Set();
    uploadPendente = null;
    avisoOcrEmAndamento = false;
    reconciliacaoEmCurso = null;
    digitalizacaoConcluida = false;
}

// Gera um uid unico por nota: crypto.randomUUID quando existe, senao
// crypto.getRandomValues (32 hex); sem crypto algum, fallback Math.random + relogio
// + contador (so unicidade, o uid nao e segredo e e escopado ao atendimento).
// Sempre confere a regex do contrato e a ausencia de colisao local.
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
        // estado do numero: 'pendente' (OCR nao terminou) | 'sugerido' | 'sem_sugestao' | 'confirmado'
        estado: 'pendente', sugestao: null, sugestaoOcr: null,
        ocrConcluido: false, manualOverride: false, destaque: false,
        aguardando: false, // 409 OCR_EM_ANDAMENTO apontou esta nota (destaque neutro, nao e erro)
        recuperada: false, // criada a partir de listar (sem foto em memoria)
    }, campos);
}

// Valida o corpo de listar: so itens bem formados (id_nota inteiro > 0, ordem 1..5,
// uid null ou conforme a regex, numero_definido booleano); nunca usa campo extra.
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

// Reconcilia o estado local com o servidor (nota.php?acao=listar, so leitura): toda
// nota ativa do atendimento passa a ter item local visivel (corrigivel e excluivel).
// So ADICIONA ou ATUALIZA ordem/idNota; nunca remove item local. pendente (opcional):
// upload ambiguo cujo uid, se achado, vira nota completa (foto em memoria + OCR);
// qualquer outra nota desconhecida vira item recuperado, SEM foto (nao ha endpoint de
// imagem), sem numero (listar nao devolve o numero) e com a identificacao de cliente
// encerrada com candidatos vazios (para nao ficar PENDENTE no servidor).
// Retorna true se a listagem foi lida e aplicada; false em qualquer falha.
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
                if (idNotasExcluidas.has(srv.id_nota)) return; // excluida localmente: listar defasado
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

// Upload ambiguo anterior (resposta perdida/timeout/5xx) ainda pendente: resolve
// via listar ANTES de seguir (nova captura, Finalizar). Falha de listar mantem o
// pendente para outra tentativa; geracao antiga e descartada.
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

// "Finalizar digitalizacao" habilitado com >= 1 nota capturada e sem upload em
// andamento (o numero de cada nota so e confirmado na tela de revisao).
function atualizarBotaoFinalizarDigitalizacao() {
    const btnFinalizar = document.getElementById('btnFinalizarDigitalizacao');
    if (btnFinalizar) {
        btnFinalizar.disabled = (state.notasNumeros || []).length === 0
            || !!state.capturaNotaEmAndamento || !!state.finalizandoDigitalizacao;
    }
}

// Contador simples de notas capturadas + miniaturas + botao Finalizar — chamado
// quando uma nota e capturada (ver confirmarUsoImagemNota()).
function atualizarIndicadorNumerosNota() {
    const indicador = document.getElementById('indicadorNumerosNota');
    if (indicador) indicador.textContent = textoIndicadorNotas();
    const miniaturas = document.getElementById('miniaturas');
    if (miniaturas) miniaturas.innerHTML = renderMiniaturasNotas();
    atualizarBotaoFinalizarDigitalizacao();
}

// -------------------- recebimento: revisao unica dos numeros das notas --------------------
// Tela unica (ate 5 cartoes) aberta por "Finalizar digitalizacao". Usa a imagem
// JA em memoria (nota.imagem) so como miniatura; nada e gravado em
// localStorage/sessionStorage/banco. Estado por nota (state.notasNumeros[i]):
//   estado: 'pendente' (OCR nao terminou) -> 'sugerido' | 'sem_sugestao' (OCR terminou)
//           -> 'confirmado' (salvo via definir-numero; tambem alcancavel direto de
//           qualquer estado por digitacao manual); ocrConcluido: OCR de numero terminou;
//           sugestaoOcr: ultimo resultado do OCR (mesmo se ignorado);
//           manualOverride: motorista abriu a digitacao com OCR pendente — resultado
//           tardio do OCR e ignorado (o Voltar desfaz e recalcula o estado);
//           destaque: o backend (422) apontou esta nota sem numero.
// Cartoes identificados por uid; o rotulo "Nota N de M" vem da POSICAO na lista.
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
    cartao._imagem = nota.imagem; // para reconciliar: recria o cartao se a imagem mudou
    const uid = nota.uid;
    cartao.addEventListener('click', () => tocarCartaoNota(uid));
    let img;
    if (nota.imagem) {
        img = document.createElement('img');
        img.className = 'rev-miniatura';
        img.src = nota.imagem; // imagem ja em memoria (nao duplicada em storage)
    } else {
        // nota recuperada do servidor: nao ha endpoint de imagem, so um bloco neutro
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
        seloIcone.innerHTML = REV_ICONES.sem_sugestao; // constante interna, sem dado da nota
        seloTexto.textContent = 'Falta o número';
    } else if (nota.aguardando) {
        // 409 OCR_EM_ANDAMENTO: destaque neutro (azul), o numero da nota segue visivel
        selo.className = 'rev-selo rev-selo-aguardando';
        cartao.className = 'rev-cartao rev-cartao-' + estado + ' rev-cartao-aguardando';
        seloIcone.innerHTML = REV_ICONES.pendente; // constante interna
        seloTexto.textContent = 'Leitura em andamento';
    } else {
        selo.className = 'rev-selo rev-selo-' + estado;
        cartao.className = 'rev-cartao rev-cartao-' + estado;
        seloIcone.innerHTML = REV_ICONES[estado] || REV_ICONES.pendente; // constante interna, sem dado da nota
        seloTexto.textContent = textoSelo;
    }
}

// Junta posicoes em texto: "2", "2 e 4", "1, 2 e 4".
function juntarPosicoesNotas(posicoes) {
    if (posicoes.length <= 1) return String(posicoes[0]);
    return posicoes.slice(0, -1).join(', ') + ' e ' + posicoes[posicoes.length - 1];
}

// Mensagem FIXA do destaque de notas sem numero (422): N = posicao na lista, nunca
// texto do backend. '' quando nenhuma nota esta destacada.
function mensagemNotasDestacadas() {
    const posicoes = [];
    (state.notasNumeros || []).forEach((n, i) => { if (n.destaque && !n.confirmado) posicoes.push(i + 1); });
    if (posicoes.length === 0) return '';
    if (posicoes.length === 1) return 'Falta o número da Nota ' + posicoes[0] + '. Toque na nota para digitar.';
    return 'Faltam os números das Notas ' + juntarPosicoesNotas(posicoes) + '. Toque em cada nota para digitar.';
}

// Atualiza cartoes (reconciliando o DOM por uid: remove o cartao cuja nota nao
// existe mais, recria se a imagem mudou, mantem a ordem da lista), linha de
// leitura, motivo, botoes Adicionar/Continuar. msgErro (opcional): erro ao
// concluir a digitalizacao; sem ele, vale a mensagem do destaque (422), se houver.
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
        // aviso NEUTRO do 409 (nao e erro): texto fixo, so enquanto o aviso valer
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

// "Adicionar outra nota": volta a captura SEM tocar no estado das notas (ir() so
// para a camera, troca a tela e a reabre; nao chama iniciarRecebimento nem
// novoAtendimento). Some com 5 notas (ver atualizarRevisaoNumeros).
function adicionarOutraNota() {
    if (numeroModalAberta || state.excluindoNota || state.finalizandoDigitalizacao) return;
    if ((state.notasNumeros || []).length >= NOTAS_LIMITE) return;
    avisoCapturaVazia = false;
    ir('rec_digitaliza');
}

// Faixa curta "Nota N excluida." dentro da revisao (role=status), some sozinha.
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
    btn.disabled = !prontoVideo || !scannerVideoPronto || (state.notasNumeros || []).length >= NOTAS_LIMITE;
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
    marca('cap_canvas_ini', medirSeqPrevia);
    const canvas = document.createElement('canvas');
    // dimensoes trocadas (largura/altura invertidas) porque o conteudo e
    // desenhado ja rotacionado 270 graus a partir do video nativo (landscape)
    // -- mesma rotacao fixa que o diagnostico com imagem real (2026-09-04)
    // confirmou como correta para a camera do totem (ver antiga
    // rotacionarImagem270(), removida). Agora a imagem nasce em pe: e a
    // UNICA fonte de verdade para previa, envio/persistencia
    // (confirmarUsoImagemNota()) e OCR (processarOcrNota() nao rotaciona mais,
    // pra evitar rotacao dupla).
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

    // ultimo recurso: se mesmo no piso de qualidade ainda exceder o limite,
    // reduz moderadamente a escala do canvas (90%) e tenta novamente
    // -- fator aplicado tanto ao canvas de destino (ja com dimensoes trocadas)
    // quanto ao recorte desenhado do video nativo, preservando a mesma
    // rotacao de 270 graus em cada tentativa.
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
        marca('falha_captura', medirSeqPrevia); // sem detalhe do erro; relanca o mesmo erro
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
    atualizarBotaoFinalizarDigitalizacao(); // sem upload em andamento para finalizar
    const geracao = atendimentoGeracao; // atendimento desta captura (ver atendimentoVigente())
    const idAtendimentoCapturado = state.idAtendimento;
    const btnUsar = document.getElementById('btnUsarImagem');
    const btnRefazer = document.getElementById('btnRefazer');
    if (btnUsar) btnUsar.disabled = true;
    if (btnRefazer) btnRefazer.disabled = true;
    mostrarStatusScanner('Enviando documento...');
    const imagem = state.previewNotaAtual;
    const notaSeq = medirSeqPrevia; // nota_seq desta captura (numerico; 0 sem ?medir=1)
    marca('usar_click', notaSeq); // uma vez por captura: apos a guarda de duplo clique
    try {
        // upload ambiguo anterior de OUTRA captura (resposta perdida): confere no
        // servidor (listar) antes de gerar um uid novo, para nunca deixar nota orfa
        if (uploadPendente && uploadPendente.imagem !== imagem) {
            await resolverUploadPendente();
            if (geracao !== atendimentoGeracao) return;
            atualizarIndicadorNumerosNota();
            if (state.notasNumeros.length >= NOTAS_LIMITE) {
                // a nota do envio anterior ocupou a ultima vaga: nao envia esta
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
        // uid da nota no servidor: gerado ANTES do upload e mantido enquanto a MESMA
        // captura for reenviada apos falha ambigua (retry nunca cria segunda nota)
        if (!uploadPendente || uploadPendente.imagem !== imagem || uploadPendente.geracao !== geracao) {
            uploadPendente = { clientUid: gerarClientUidNota(), imagem, geracao, idAtendimento: idAtendimentoCapturado };
        }
        const pend = uploadPendente;
        marca('upload_ini', notaSeq);
        // a ordem NAO e enviada: o servidor aloca a primeira livre e devolve
        // id_nota e ordem (a nota so entra na lista apos essa resposta)
        const resultado = await api('nota.php', 'processar', { id_atendimento: idAtendimentoCapturado, imagem, chave: null, uid: pend.clientUid }, { timeoutMs: NOTA_UPLOAD_TIMEOUT_MS });
        marca('upload_fim', notaSeq);
        // cancelamento/novo atendimento durante o upload: descarta sem tocar no
        // estado do atendimento novo (o upload ja enviado segue o backend atual).
        if (geracao !== atendimentoGeracao) return;
        // uid de resposta que nao case com o enviado: nao usa a resposta (nota alheia)
        if (resultado && resultado.uid !== undefined && resultado.uid !== pend.clientUid) {
            if (uploadPendente === pend) uploadPendente = null;
            const divergente = new Error('uid divergente');
            divergente.status = 400;
            throw divergente;
        }
        // reaproveitada:true (retry apos resposta perdida) e sucesso: mesma nota, sem erro
        if (uploadPendente === pend) uploadPendente = null;
        // uid local criado so aqui, apos a guarda de geracao (nunca reutilizado no atendimento)
        const nota = novaNotaLocal({
            clientUid: pend.clientUid,
            ordem: Number.isInteger(resultado.ordem) ? resultado.ordem : null,
            idNota: Number.isInteger(resultado.id_nota) && resultado.id_nota > 0 ? resultado.id_nota : null,
            imagem,
        });
        state.notasNumeros.push(nota);
        avisoCapturaVazia = false;
        state.previewNotaAtual = null;
        processarOcrNota(imagem, nota.uid, notaSeq); // passada unica: numero + candidatos de cliente
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
        // 400 (limite de 5, ordem ou imagem): mensagem fixa; o estado das notas
        // nao e alterado (a nota so entra na lista na resposta de sucesso)
        // resposta recusada pelo servidor (4xx) = a nota NAO foi criada: o uid pendente
        // e descartado (a proxima tentativa gera outro). Sem status (rede, timeout,
        // resposta ilegivel) ou 5xx = resultado AMBIGUO: o uid pendente e mantido e o
        // "Usar imagem" seguinte reenvia a MESMA captura com o MESMO uid (idempotente).
        if (uploadPendente && Number.isInteger(e.status) && e.status >= 400 && e.status < 500) uploadPendente = null;
        const msg = (e instanceof TypeError || !Number.isInteger(e.status))
            ? 'Erro ao enviar o documento. Verifique a conexão e tente novamente.'
            : (e.status === 400 ? 'Não foi possível salvar esta nota. Toque em Refazer e tente de novo.' : (e.message || 'Erro ao salvar o documento.'));
        mostrarStatusScanner(msg, true); // erro de upload so em #scannerStatus (sem toast duplicado)
        if (btnUsar) btnUsar.disabled = false;
        if (btnRefazer) btnRefazer.disabled = false;
    }
    state.capturaNotaEmAndamento = false;
    atualizarBotaoFinalizarDigitalizacao();
    notificarFimDeTrabalhoNotas();
}

// "Finalizar digitalizacao" (tela de captura): nao valida mais numero nenhum —
// abre a tela unica de revisao (rec_revisao_numeros).
async function finalizarDigitalizacao() {
    if (state.finalizandoDigitalizacao || state.capturaNotaEmAndamento) return;
    if ((state.notasNumeros || []).length === 0) return;
    if (uploadPendente) {
        // upload ambiguo nao resolvido: confere no servidor antes de abrir a revisao
        // (nota que o servidor tem e o front nao conhece entra na lista). Falha de
        // listar nao bloqueia: a reconciliacao se repete no 422/409.
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

// Valida dados.ordens_pendentes do 422: array de inteiros de 1 a 5 (qualquer outra
// coisa e ignorada). Devolve array (possivelmente vazio) de ordens unicas.
function ordensPendentesValidas(dados) {
    return ordensValidasDe(dados, 'ordens_pendentes');
}
// Mesmo formato para dados.ordens_em_processamento (409 OCR_EM_ANDAMENTO).
function ordensValidasDe(dados, chave) {
    const bruto = dados && dados[chave];
    if (!Array.isArray(bruto)) return [];
    const ordens = [];
    bruto.forEach(o => {
        if (Number.isInteger(o) && o >= 1 && o <= NOTAS_LIMITE && !ordens.includes(o)) ordens.push(o);
    });
    return ordens;
}

// 422 NOTAS_SEM_NUMERO: marca como pendentes (destaque) as notas locais cuja ordem
// o servidor apontou, permanece na revisao e rola ate a primeira. A mensagem e
// SEMPRE o texto fixo de mensagemNotasDestacadas() (nunca texto do backend).
// Retorna true se alguma nota local foi destacada.
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
            // sem override residual: o estado e reconstruido so a partir do ultimo
            // resultado do OCR (sugestaoOcr/ocrConcluido), nunca fica em Lendo...
            n.manualOverride = false;
            recalcularEstadoOcrNota(n);
            algum = true;
        } else if (!n.confirmado && n.manualOverride && n.uid !== numeroModalOverrideUid) {
            // nenhuma outra nota nao confirmada pode ficar com override orfao
            // (nenhum modal aberto o mantem: a revisao esta sob o Continuar)
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

// "Continuar" da revisao: caminho ja existente de finalizar a digitalizacao
// (concluir-digitalizacao + avanco de etapa). So com TODAS as notas confirmadas.
async function concluirDigitalizacaoRevisao() {
    if (state.finalizandoDigitalizacao || state.excluindoNota) return;
    if ((state.notasNumeros || []).length === 0 || (state.notasNumeros || []).some(n => !n.confirmado)) return;
    state.finalizandoDigitalizacao = true;
    const geracao = atendimentoGeracao;
    const idAtendimentoCapturado = state.idAtendimento;
    limparAvisoOcrEmAndamento(); // nova tentativa do motorista: some o aviso do 409 anterior
    atualizarRevisaoNumeros();
    try {
        const dados = await api('atendimento.php', 'concluir-digitalizacao', { id_atendimento: idAtendimentoCapturado });
        if (geracao !== atendimentoGeracao) return; // cancelado durante a chamada
        state.finalizandoDigitalizacao = false;
        digitalizacaoConcluida = true;
        // O backend decide: 'rec_cnh' (etapa unica; o front traduz para a tela
        // de QR da CNH) ou 'rec_cliente' (confirmacao manual). Valor ausente/
        // desconhecido = confirmacao manual (nunca pula o cliente por engano).
        ir(dados.proxima_tela === 'rec_cnh' ? 'rec_cnh_qr' : 'rec_cliente');
    } catch (e) {
        if (geracao !== atendimentoGeracao) return;
        const ehNotasSemNumero = e.status === 422 && e.codigo === 'NOTAS_SEM_NUMERO';
        // 409 OCR_EM_ANDAMENTO e da acao concluir-digitalizacao (o 409 antigo
        // NUMERO_NOTA_DUPLICADO e do definir-numero, outra rota, tratado em
        // mensagemErroSalvarNumero). Qualquer outro 409 cai no erro generico abaixo.
        const ehOcrEmAndamento = e.status === 409 && e.codigo === 'OCR_EM_ANDAMENTO';
        if (ehNotasSemNumero || ehOcrEmAndamento) {
            // o servidor pode ter nota que o front nao conhece (resposta do processar
            // perdida): reconcilia via listar ANTES de mapear ordens e liberar o botao
            await reconciliarNotasComServidor();
            if (geracao !== atendimentoGeracao) return;
            state.finalizandoDigitalizacao = false;
            if (ehNotasSemNumero && tratarNotasSemNumero(e.dados)) return;
            if (ehOcrEmAndamento) { tratarOcrEmAndamento(e.dados); return; }
            atualizarRevisaoNumeros('Não foi possível continuar. Tente novamente.');
            return;
        }
        state.finalizandoDigitalizacao = false;
        atualizarRevisaoNumeros('Não foi possível continuar. Tente novamente.'); // inline em #revisaoErro
    }
}

// 409 OCR_EM_ANDAMENTO: o servidor ainda le o cliente de alguma nota. A revisao
// continua aberta, a etapa NAO avanca, o botao Continuar volta a valer (so por
// toque do motorista: nenhum retry automatico) e as notas apontadas ganham um
// destaque NEUTRO (azul, nunca vermelho). Passados 120 s do upload da nota o
// servidor para de bloquear, entao o motorista nunca fica preso. Mensagem fixa,
// nunca o texto do backend; nada de dados do 409 e logado ou medido.
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

// -------------------- recebimento: identificacao de cliente via OCR local (Tesseract.js) --------------------
// CORRECAO (2026-09-04): o desenho anterior rodava Tesseract.createWorker()
// de DENTRO de um Web Worker customizado (assets/ocr-worker.js) — mas o
// Tesseract.js JA GERENCIA seu proprio Worker internamente (e exatamente o
// que Tesseract.createWorker() faz: cria um Worker dedicado, via blob URL,
// para rodar o reconhecimento fora da thread principal). Isso resultava num
// Worker aninhado (Worker dentro de Worker): o worker interno do
// Tesseract.js tentava importScripts() do worker com caminho
// relativo, que nao resolve a partir do contexto de um blob worker aninhado
// (WorkerGlobalScope de um worker criado dinamicamente via blob nao tem a
// mesma base URL de assets/ocr-worker.js) — confirmado em teste fisico real
// (SyntaxError: Failed to execute 'importScripts' ... URL
// '...worker.min.js' is invalid), quebrando 100% do OCR em producao.
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
// processa as notas em ordem (nota por nota); o OCR e a identificacao rodam
// para TODA nota (sem early-stop; o backend decide o cliente do atendimento).
//
// AJUSTE (2026-09-04, diagnostico com imagem real): confirmado que a imagem
// crua do video precisa de rotacao fixa de 270 graus para o OCR reconhecer
// o texto (0/90/180 graus nunca produzem CNPJ valido). Extracao de chave de
// acesso (44 digitos) removida do processo (nunca validou em 14 combinacoes
// testadas) — chave_ocr sempre enviado como null ao backend.
//
// AJUSTE (2026-09-28): essa rotacao de 270 graus agora e aplicada diretamente
// em capturarFotoScannerNota() (a imagem ja nasce em pe, salva/enviada assim)
// — o OCR passou a usar a MESMA imagem, sem rotacionar de novo (a antiga
// rotacionarImagem270(), que gerava uma copia so para o OCR, foi removida).

let tesseractWorkerPromise = null;
let ocrFila = [];
let ocrProcessando = false;

// CNPJ: 14 digitos, aceitando mascara padrao (99.999.999/9999-99) ou
// separadores/espacos soltos que o OCR as vezes insere no lugar da mascara.
// AJUSTE (2026-09-28, diagnostico com 3 notas reais novas — nota_02 confirmou
// ser exatamente o caso da LDC ja registrado como pendencia): o regex antigo
// tolerava so 0 ou 1 caractere de separacao, e so os caracteres "." ou
// espaco/tab/quebra-de-linha (\s). Duas falhas reais confirmadas: (1) o OCR
// as vezes usa VIRGULA no lugar do ponto entre os 2 primeiros grupos de
// digitos (ex.: "00,831.373/0002-95"), o que o regex antigo nunca aceitava —
// caso real da propria LDC, nao capturado antes; (2) como \s tambem casa
// quebra de linha, o regex antigo por vezes atravessava duas linhas
// distintas da tabela do DANFE (ex.: fim de uma inscricao estadual + inicio
// de outro campo numerico na linha seguinte) e "engolia" digitos de campos
// nao relacionados, gerando falso candidato de 14 digitos e impedindo o
// CNPJ real (que ficava dividido entre as duas correspondencias) de ser
// capturado corretamente — caso real observado com o CNPJ do destinatario
// de uma das 3 notas de calibracao. Correcao pontual: tolera de 0 a 2
// caracteres de separacao (nao so 0 ou 1), incluindo virgula, mas restrito a
// espaco/tab (nunca quebra de linha) — mantendo a tolerancia a variacoes de
// OCR sem voltar a atravessar linhas da tabela.
const CNPJ_REGEX = /\d{2}[.,\t ]{0,2}\d{3}[.,\t ]{0,2}\d{3}[/,\t ]{0,2}\d{4}[-,\t ]{0,2}\d{2}/g;

// Palavras que nunca aparecem DENTRO de uma razao social, mas sao comuns em
// cabecalhos/rotulos do layout padrao de DANFE — qualquer token da linha
// (normalizado, sem acento) igual a uma destas descarta a linha inteira como
// candidata. Heuristica, nao lista exaustiva/contrato formal.
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

// Sufixos/termos tipicos de razao social de empresa brasileira — usados so
// como bonus de pontuacao entre candidatas plausiveis (heuristica, nunca
// obrigatorio).
const OCR_TERMOS_EMPRESA = [
    's/a', 'sa', 'ltda', 'eireli', 'me', 'industria', 'indústria', 'comercio',
    'comércio', 'transporte', 'transportes', 'armazens', 'armazéns',
    'armazem', 'armazém', 'logistica', 'logística', 'company', 'international',
    'agroindustria', 'agroindústria', 'quimicos', 'químicos',
];

function ocrNormalizarTexto(s) {
    return s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}

// Extraida do antigo ocr-worker.js — so processamento de string, roda direto
// na thread principal.
// NOTA (2026-09-04): extracao de chave de acesso (44 digitos) REMOVIDA —
// diagnostico real (14 combinacoes/2 imagens) mostrou que ela nunca valida
// via OCR (1 unico digito errado entre 44 ja invalida o resultado), mesmo
// quando CNPJ solto e razao social ja saem corretos. Chave deixou de fazer
// parte do processo de identificacao (ver handoff da demanda
// recebimento-leitura-notas).
// AJUSTE (2026-09-28, diagnostico com 3 notas reais): a heuristica antiga de
// razao social (primeira linha 100% alfabetica, sem nenhum digito, entre as
// 20 primeiras) falhava em 2 das 3 notas reais — pegava linha de ruido do
// topo da imagem numa delas, e o texto do logo/cabecalho ("... DOCUMENTO
// AUXILIAR DA") na outra — porque no layout real do DANFE a razao social do
// emitente as vezes aparece na MESMA linha de outros dados (CNPJ/IE/chave de
// acesso), nunca isolada como a heuristica antiga assumia. Nova heuristica
// (ainda heuristica, nao contrato formal): para cada linha (ate a 40a),
// extrai o PREFIXO alfabetico (tokens ate o primeiro que contenha algum
// digito) em vez de exigir a linha inteira livre de digitos — permite captar
// a razao social mesmo colada a dados numericos na mesma linha. Descarta
// candidatas com token puramente simbolico (ruido de OCR de elementos
// graficos), com predominancia de "palavras" de 1-2 caracteres, ou que
// contenham alguma palavra tipica de cabecalho/rotulo de DANFE
// (OCR_PALAVRAS_CABECALHO_DANFE). Entre as candidatas restantes, pontua e
// escolhe a mais plausivel (mais tokens, presenca de termos tipicos de razao
// social como "LTDA"/"S/A"/"COMERCIO"), penalizando (sem excluir por
// completo) candidatas que apareçam depois da secao "DESTINATARIO/
// REMETENTE" do DANFE — essas tendem a ser a contraparte do emitente (o
// cliente a identificar), nao o proprio emitente; a penalidade (em vez de
// exclusao total) preserva o caso real em que o emitente e repetido mais
// abaixo, em "informacoes do local de retirada".
// AJUSTE (2026-09-28, diagnostico com as mesmas 3 notas reais, campo que
// ainda falhava): 1 das 3 razoes sociais (nota da Louis Dreyfus Company)
// so aparecia, na imagem real, colada na MESMA linha reconhecida a um
// token de cabecalho ("DANFE") — a regra acima descartava a LINHA INTEIRA
// so por conter esse token, jogando fora a razao social correta junto, e
// o processo acabava escolhendo uma 2a ocorrencia do nome da empresa mais
// abaixo no canhoto, reconhecida de forma bem mais distorcida pelo OCR.
// Correcao pontual: ao encontrar um token de cabecalho dentro da linha,
// em vez de descartar a linha inteira, tenta o PREFIXO de tokens antes
// dele como candidata (nunca o sufixo — nao houve caso real do padrao
// inverso ate agora). So aceita esse prefixo se tiver pelo menos 2 tokens
// e NENHUM token curto (<=2 letras) — criterio mais estrito que o da
// linha inteira (que tolera ate 50% de tokens curtos), porque um prefixo
// cortado no meio de uma linha e mais sujeito a ser fragmento/lixo de OCR
// do que uma linha inteira ja validada de uma vez; diagnostico real
// confirmou que essa regra mais estrita rejeita corretamente um prefixo
// truncado de outra nota (contendo tokens de 2 letras remanescentes de
// palavra quebrada) que teria sido lixo. Candidata aceita nessas condicoes
// recebe um bonus de pontuacao (equivalente a 2 termos tipicos de razao
// social baterem) — nome colado a um rotulo de cabecalho e um sinal
// estrutural forte de que e o proprio emitente, pois o layout padrao do
// DANFE poe o nome do emitente junto da caixa "DOCUMENTO AUXILIAR DA NOTA
// FISCAL ELETRONICA/DANFE".
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
        // descarta tokens formados so por simbolos (ex.: "+", "|") — nomes
        // de empresa reais nao trazem esse tipo de token solto, mas ruido de
        // OCR de elementos graficos do layout sim; "-" isolado e tolerado
        // (conector comum, ex.: "S/A - Logistica").
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
            if (idxCabecalho < 2) continue; // sem prefixo plausivel antes do token de cabecalho
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

// -------------------- recebimento: heuristica de extracao do NUMERO DA NOTA (OCR) --------------------
// Demanda talent-doctos-finalizacao-checkin. Heuristica, NAO contrato formal
// — procura o numero individual da nota (nunca a serie, nunca a chave de 44
// digitos) por proximidade textual de rotulos comuns de DANFE ("Nº"/"N°"/
// "NUMERO"/"Nº."). So retorna confianca alta quando encontra um numero
// plausivel (1 a 9 digitos, apos remover pontuacao) perto de um desses
// rotulos, excluindo explicitamente comprimentos de CNPJ (14) e de chave de
// acesso (44). Qualquer outro caso cai no preenchimento manual obrigatorio
// (ver aplicarResultadoOcrNumero()/tocarCartaoNota()).
// AJUSTE (2026-09-28, diagnostico com 3 notas reais): o grupo numerico
// tolerava espaco/tab interno (junto com o ponto), o que por vezes fazia o
// regex atravessar, na MESMA linha, o numero da nota e um campo numerico
// vizinho separado por espaco (ex.: "Nº 000.029.553 11.1 /2" — o "11"
// seguinte era engolido junto, estourando para 11 digitos e sendo descartado
// pelo filtro de faixa, quando o correto — 9 digitos — era so "000.029.553").
// Retirado \s do grupo (mantido so o ponto como separador tolerado) —
// corrige esse atravessamento sem reabrir o CNPJ_REGEX (que e outro regex,
// ja corrigido em rodada anterior).
const NUMERO_NOTA_REGEX_ROTULO = /N[º°ºoO]\.?\s*[:\-]?\s*(\d[\d.]{0,12}\d|\d)/;

function extrairNumeroNota(texto) {
    const textoSeguro = texto || '';
    const linhas = textoSeguro.split(/\r?\n/);
    // Coleta TODAS as correspondencias validas (nao para na primeira) —
    // diagnostico real mostrou o OCR por vezes quebrando "Nº 000.029.553" em
    // duas linhas ("Nº 000.0" numa linha, "29.553" na seguinte), fazendo a
    // 1a linha gerar uma correspondencia truncada/errada mesmo havendo, mais
    // abaixo no mesmo texto, uma 2a ocorrencia completa e correta (o DANFE
    // repete o numero da nota em mais de um ponto do layout). Entre as
    // correspondencias validas, prefere a mais completa (maior quantidade de
    // digitos) — uma correspondencia truncada por quebra de linha
    // naturalmente tem menos digitos que a ocorrencia completa.
    const candidatos = [];
    for (const linha of linhas) {
        const m = linha.match(NUMERO_NOTA_REGEX_ROTULO);
        if (!m) continue;
        const bruto = m[1].replace(/[.\s]/g, '');
        if (!/^\d+$/.test(bruto)) continue;
        if (bruto.length === 44) continue; // chave de acesso, nunca numero de nota
        if (bruto.length === 14) continue; // provavel CNPJ confundido com rotulo
        if (bruto.length < 1 || bruto.length > 9) continue; // faixa plausivel de numero de nota
        candidatos.push(bruto);
    }
    if (candidatos.length === 0) return { numero: null, confiancaAlta: false };
    let melhor = candidatos[0];
    for (const candidato of candidatos) {
        if (candidato.length > melhor.length) melhor = candidato;
    }
    return { numero: melhor, confiancaAlta: true };
}

// Serializa as chamadas worker.recognize() do Tesseract.js — o worker e unico e
// reaproveitado (iniciarOcrWorker()) e chamar recognize() concorrentemente nele
// nao e seguro. Com a passada unica ha uma unica fila (ocrFila), mas o mutex e
// mantido como defesa.
let filaExecucaoTesseract = Promise.resolve();
function executarReconhecimentoSerializado(fn) {
    const execucao = filaExecucaoTesseract.then(fn, fn);
    filaExecucaoTesseract = execucao.catch(() => {});
    return execucao;
}

// true se o atendimento em que o item foi enfileirado ainda e o atual (mesmo id
// E mesma geracao: cancelamento/novo atendimento incrementa a geracao).
function atendimentoVigente(item) {
    return !!item && item.geracao === atendimentoGeracao
        && item.idAtendimento != null && item.idAtendimento === state.idAtendimento;
}

// Grava o resultado do OCR de numero no estado da nota (sem abrir modal).
// Ignora o resultado se a nota ja foi confirmada ou se o motorista digitou/abriu
// o campo manual (manualOverride) — nunca troca o valor sob o dedo.
function aplicarResultadoOcrNumero(uid, sugestao) {
    const entrada = buscarNotaPorUid(uid);
    if (!entrada) return;
    entrada.ocrConcluido = true;
    // ultimo resultado do OCR, guardado MESMO quando ignorado abaixo: o Voltar dos
    // modais usa isso para recalcular o estado da nota (recalcularEstadoOcrNota).
    entrada.sugestaoOcr = sugestao || null;
    if (entrada.manualOverride || entrada.confirmado) return;
    entrada.sugestao = sugestao || null;
    entrada.estado = entrada.sugestao ? 'sugerido' : 'sem_sugestao';
    if (state.tela === 'rec_revisao_numeros') atualizarRevisaoNumeros();
}

// OCR de PASSADA UNICA por nota: um unico worker.recognize() cujo texto alimenta
// extrairNumeroNota (estado da nota, prioridade) e extrairCandidatos (cliente).
// Enfileirado logo apos confirmarUsoImagemNota() salvar a imagem, sem await
// (nao bloqueia a captura da proxima nota). O OCR roda para TODAS as notas e a
// chamada identificarClienteNota tambem (uma por nota). O item da fila carrega o
// uid da nota: o resultado e aplicado por uid (nota excluida = descartado).
// Marcas ?medir=1: ocr_fila_num/ocr_ini_num/ocr_fim_num/falha_ocr_num/
// res_aplic_num/res_descart_num descrevem a passada de OCR; ocr_ini_cli/
// ocr_fim_cli cercam so a chamada de identificacao (ocr_cli_ms = tempo da
// identificacao; fila_cli_ms fica sempre 'na'; eventos *_fila_cli, res_*_cli e
// falha_ocr_cli nao sao mais emitidos).
function processarOcrNota(imagem, uid, medirSeq) {
    marca('ocr_fila_num', medirSeq);
    // idAtendimento/geracao CAPTURADOS no enfileiramento (nunca lidos tarde)
    ocrFila.push({ imagem, uid, idAtendimento: state.idAtendimento, geracao: atendimentoGeracao, medirSeq });
    processarProximaOcrDaFila();
}

// Processa a fila nota por nota (sem reconhecimentos em paralelo). Mesmo em caso
// de erro real de OCR (imagem ilegivel, modelo de idioma, worker indisponivel)
// a nota vai a sem_sugestao e chama identificarClienteNota com candidatos vazios,
// para a nota sempre sair de PENDENTE (o backend trata candidatos vazios como
// NAO_IDENTIFICADA, sem erro).
async function processarProximaOcrDaFila() {
    if (ocrProcessando) return;
    const proxima = ocrFila.shift();
    if (!proxima) return;
    ocrProcessando = true;
    let numeroSugerido = null;
    let cnpjsCandidatos = [];
    let razaoSocialCandidata = null;
    try {
        // Cada etapa tem try PROPRIO: a falha de uma nunca apaga o resultado da
        // outra (numero e candidatos de cliente sao independentes).
        let texto = '';
        try {
            const workerPromise = iniciarOcrWorker();
            if (!workerPromise) throw new Error('Tesseract.js indisponivel');
            const worker = await workerPromise;
            // proxima.imagem ja nasce rotacionada 270 graus (em pe) em
            // capturarFotoScannerNota() — nao rotaciona de novo aqui.
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
            razaoSocialCandidata = null; // numero ja extraido fica PRESERVADO
        }
    } finally {
        ocrProcessando = false;
        try {
            concluirOcrDaNota(proxima, numeroSugerido, cnpjsCandidatos, razaoSocialCandidata);
        } finally {
            // sempre executados, qualquer que seja o destino da nota
            processarProximaOcrDaFila();
            notificarFimDeTrabalhoNotas();
        }
    }
}

// Destino do resultado da passada de OCR (chamada so do finally acima). Aplicar o
// numero e disparar a identificacao de cliente tem try/catch SEPARADOS: falha de
// um nao impede o outro. guard reavaliado DEPOIS do await do recognize (id e
// geracao capturados no enfileiramento); nota ja excluida (uid inexistente) =
// resultado descartado, nunca aplicado a outra nota.
function concluirOcrDaNota(proxima, numeroSugerido, cnpjsCandidatos, razaoSocialCandidata) {
    if (digitalizacaoConcluida || !atendimentoVigente(proxima) || !buscarNotaPorUid(proxima.uid)) {
        marca('res_descart_num', proxima.medirSeq);
        return;
    }
    // 1) numero primeiro: estado da nota gravado antes de qualquer chamada de rede
    try {
        aplicarResultadoOcrNumero(proxima.uid, numeroSugerido);
        marca('res_aplic_num', proxima.medirSeq);
    } catch (e) {
        logFalhaOcr('aplicar', e);
    }
    // 2) cliente em segundo plano (sem await), para toda nota
    try {
        const identificacao = identificarClienteNota(proxima.uid, cnpjsCandidatos, razaoSocialCandidata, proxima.idAtendimento, proxima.geracao, proxima.medirSeq);
        if (identificacao && typeof identificacao.catch === 'function') {
            identificacao.catch(e => logFalhaOcr('identificar', e));
        }
    } catch (e) {
        logFalhaOcr('identificar', e);
    }
}

// Log tecnico do OCR SEM dado pessoal: so o nome da etapa e o NOME do erro
// (nunca a mensagem, o objeto de erro ou o texto reconhecido da nota).
function logFalhaOcr(etapa, e) {
    const nome = (e && typeof e.name === 'string') ? e.name.slice(0, 40) : 'Erro';
    console.warn('[OCR] falha em ' + etapa + ': ' + nome);
}

// -------------------- recebimento: confirmacao/preenchimento do numero da nota --------------------
// Nunca mais de um modal aberto por vez — cada nota tem confirmacao rapida
// (confianca alta) ou preenchimento manual obrigatorio (confianca baixa/ausente),
// aberto por toque no cartao da tela de revisao. Preserva as notas ja
// aprovadas: so mexe na entrada de state.notasNumeros correspondente ao "uid",
// nunca nas demais. Todas as closures capturam o UID (identidade imutavel).
let numeroModalAberta = false;
// Uid da nota cujo manualOverride FOI LIGADO pela abertura do modal corrente
// (tocarCartaoNota, nota ainda 'pendente'); null se a abertura nao ligou nada.
// So o Voltar desfaz; confirmar mantem (a nota passa a 'confirmado').
let numeroModalOverrideUid = null;

// Confirmacao de cancelamento EMPILHADA sobre o modal de numero da nota
// (achado bloqueante do /03-revisao, demanda talent-doctos-finalizacao-checkin).
// O botao "Cancelar atendimento" DENTRO do modal obrigatorio de numero da nota
// (abrirModalNumeroNotaManual) NUNCA deve chamar confirmarCancelar() direto,
// pois essa funcao usa abrirModal(), que SOBRESCREVE o innerHTML do MESMO
// #modalCaixa ja aberto — destruindo o numero ja digitado, a nota pendente,
// o teclado numerico dedicado e qual variante do modal (manual/sugestao)
// estava em uso, alem de nunca restaurar nada se o motorista tocasse em
// "Continuar atendimento" (numeroModalAberta ficava true para sempre, sem
// nenhum modal visivel — motorista travado). Em vez disso, esta confirmacao
// usa um elemento PROPRIO, independente, empilhado por cima (#modalConfirm
// CancelNotaFundo/Caixa, ver iniciarApp()) que nunca toca em #modalCaixa/
// #modalFundo. Como o modal de numero da nota nunca e fechado nem reescrito
// enquanto essa confirmacao esta aberta, "Continuar atendimento" so precisa
// fechar a propria confirmacao — o modal de numero da nota (e numeroModal
// Aberta, que permanece true o tempo todo) continuam exatamente como estavam,
// sem nenhuma restauracao manual necessaria. Ao confirmar ("Sim, cancelar"),
// reaproveita EXATAMENTE a mesma logica de encerramento que confirmarCancelar()
// ja dispara (fecharModal() + cancelarESair()) — sem duplicar.
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

// Modais de numero so abrem a partir da tela de revisao (rec_revisao_numeros),
// por toque no cartao da nota — nunca durante a captura. numeroModalAberta
// impede abrir dois ao mesmo tempo (um modal por toque). Tocar numa nota
// destacada (422) remove o destaque dela.
function tocarCartaoNota(uid) {
    if (numeroModalAberta || state.finalizandoDigitalizacao || state.excluindoNota) return;
    const entrada = buscarNotaPorUid(uid);
    if (!entrada) return;
    if (entrada.destaque || entrada.aguardando) {
        entrada.destaque = false;
        if (entrada.aguardando) {
            // destaque neutro do 409 sai ao abrir a nota; sem mais notas apontadas, some o aviso
            entrada.aguardando = false;
            if (!state.notasNumeros.some(n => n.aguardando)) avisoOcrEmAndamento = false;
        }
        atualizarRevisaoNumeros();
    }
    numeroModalAberta = true;
    if (entrada.estado === 'confirmado') {
        abrirModalNumeroNotaManual(uid, entrada.numero, ''); // correcao de numero ja confirmado
    } else if (entrada.estado === 'sugerido' && entrada.sugestao) {
        abrirModalNumeroNotaSugestao(uid, entrada.sugestao);
    } else {
        // 'pendente' (OCR ainda lendo): digitacao manual passa a valer e o
        // resultado tardio do OCR desta nota e ignorado — ate o motorista
        // confirmar ou tocar em Voltar (que desfaz; ver voltarModalNumeroNota).
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

// Recalcula o estado de uma nota NAO confirmada a partir do ultimo resultado do
// OCR (sugestaoOcr), usando ocrConcluido para saber se o OCR ja terminou.
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

// VOLTAR dos modais de numero (conferir e digitar): fecha SEM chamar api e SEM
// alterar numero, confirmado nem origem; o valor digitado e descartado. Se a
// abertura deste modal ligou o manualOverride (nota pendente), desfaz e recalcula
// o estado a partir do ultimo OCR — senao o OCR tardio ficaria ignorado e o
// cartao em Lendo... para sempre. Foco volta ao cartao da nota.
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

// Desabilita Voltar e Excluir nota enquanto um salvar esta em voo (o fechamento
// pelo salvar nao pode atingir outro modal aberto depois).
function definirSaidasModalNotaDesabilitadas(desabilitado) {
    ['btnVoltarModalNota', 'btnExcluirNota'].forEach(id => {
        const btn = document.getElementById(id);
        if (btn) btn.disabled = desabilitado;
    });
}

function mensagemErroSalvarNumero(e) {
    if (e && e.status === 409) return 'Este número já foi usado em outra nota. Confira o número na nota e corrija.';
    if (e && e.status === 404) return 'Não encontramos esta nota. Toque em Voltar e confira a lista.';
    if (e && e.status === 400 && e.message) return e.message; // numero invalido (mensagem do backend)
    return 'Não foi possível salvar. Toque para tentar de novo.';
}

// Topo dos modais de numero: identificacao "Nota N de M" (N = posicao na lista)
// + miniatura da nota (nota.imagem, em memoria; o src e atribuido por DOM, nunca
// gravado em storage).
function cabecalhoModalNota(uid) {
    const total = (state.notasNumeros || []).length;
    const nota = buscarNotaPorUid(uid);
    // nota recuperada do servidor (sem foto em memoria): bloco de texto no lugar da miniatura
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

// Excluir nota: SO dentro dos modais de numero (nunca no cartao). Separado do
// Confirmar/teclado por um divisor e folga; o toque abre a confirmacao propria.
function blocoExcluirModalNota(uid) {
    return `<div class="nota-modal-divisor" role="separator"></div>
        <button type="button" class="btn-excluir-nota" id="btnExcluirNota" aria-label="Excluir Nota ${escapeHtml(posicaoNotaPorUid(uid))}">Excluir nota</button>`;
}
// Secao propria do atendimento, separada do grupo da nota por linha e folga.
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

// Fim de um salvar (definir-numero) cuja nota nao existe mais (excluida): ignora
// com seguranca, sem confirmar nada, e fecha o modal.
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
        if (geracao !== atendimentoGeracao) return; // atendimento cancelado durante o salvar
        if (!buscarNotaPorUid(uid)) { encerrarSalvarDeNotaInexistente(); return; }
        marcarNumeroNotaConfirmado(uid, sugestao, 'OCR');
        fecharModalNumeroNotaAtual();
    } catch (e) {
        if (geracao !== atendimentoGeracao) return;
        if (!buscarNotaPorUid(uid)) { encerrarSalvarDeNotaInexistente(); return; }
        // NUMERO_NOTA_DUPLICADO (HTTP 409), nota nao encontrada (404) ou erro de
        // validacao — NUNCA fecha o modal nem descarta a imagem ja capturada; cai
        // para o campo manual, com o erro exibido inline, mesma nota.
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

// Teclado numerico DEDICADO ao modal de numero da nota fiscal — NAO reaproveita
// montarTeclado()/#teclado (QWERTY, global, outras telas) nem campoAtivo. Fica
// embutido no proprio modal (grid 0-9 + Apagar + Confirmar) com alvos grandes
// (~64px) para uso com dedo/luva. O botao "Confirmar" aciona exatamente o
// mesmo fluxo ja existente de salvar (salvarNumeroNotaManual -> nota.php?
// acao=definir-numero), sem reimplementar validacao alguma.
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
    if (!valor) return; // botao ja fica desabilitado nesse caso — validacao defensiva
    const nota = buscarNotaPorUid(uid);
    if (!nota) { encerrarSalvarDeNotaInexistente(); return; }
    if (btn) btn.disabled = true;
    definirSaidasModalNotaDesabilitadas(true);
    const geracao = atendimentoGeracao;
    try {
        await salvarNumeroNota(nota, valor, 'MANUAL');
        if (geracao !== atendimentoGeracao) return; // atendimento cancelado durante o salvar
        if (!buscarNotaPorUid(uid)) { encerrarSalvarDeNotaInexistente(); return; }
        marcarNumeroNotaConfirmado(uid, valor, 'MANUAL');
        fecharModalNumeroNotaAtual();
    } catch (e) {
        if (geracao !== atendimentoGeracao) return;
        if (!buscarNotaPorUid(uid)) { encerrarSalvarDeNotaInexistente(); return; }
        // erro INLINE, sem fechar o modal, mantendo o foco no campo (item do
        // escopo: nunca perde a imagem ja capturada nem fecha o modal aqui)
        const msg = mensagemErroSalvarNumero(e);
        if (erroEl) { erroEl.textContent = msg; erroEl.style.display = 'block'; }
        if (btn) btn.disabled = false;
        definirSaidasModalNotaDesabilitadas(false);
    }
}

function salvarNumeroNota(nota, numero, origem) {
    // normalizacao final (zero a esquerda, so digitos) e SEMPRE do backend
    // (nota.php?acao=definir-numero) — o front so garante nao-vazio. A nota vai
    // por id_nota (nunca junto com a ordem).
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

// -------------------- recebimento: excluir nota --------------------
// Confirmacao em overlay PROPRIO (#modalConfirmExcluirNotaFundo/Caixa, z-index 56:
// acima do modal do numero, abaixo do aviso de inatividade). Nao usa abrirModal(),
// entao o modal do numero permanece intacto por baixo. Toque fora e Esc nao fazem
// nada (nao ha listener no fundo nem de teclado).
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
    limparCaixaModal('modalConfirmExcluirNotaCaixa'); // sem HTML nem handlers da nota anterior
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

// Sequencia: (1) uid existe e nao ha excluindoNota/finalizando/captura;
// (2) id_nota presente; (3) TRAVA na mesma tick; (4) await excluir com id e
// geracao capturados; (5) reavalia o guard; (6) SO ENTAO remove do estado.
async function executarExclusaoNota(uid) {
    if (state.excluindoNota) return; // segundo clique = no-op
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
        if (geracao !== atendimentoGeracao) return; // cancelado durante a chamada: estado ja foi reiniciado
        if (!dados || (dados.excluida !== true && dados.ja_excluida !== true)) throw new Error('resposta invalida');
        concluirExclusaoNota(uid, dados);
    } catch (e) {
        if (geracao !== atendimentoGeracao) return;
        // erro de rede, 404, 500 ou 503: a nota PERMANECE
        state.excluindoNota = false;
        definirBotoesExclusaoHabilitados(true);
        mostrarErroExclusaoNota(EXCLUIR_NOTA_MSG_ERRO);
        atualizarRevisaoNumeros();
    }
}

// Aplica a exclusao confirmada pelo backend (excluida ou ja_excluida): remove a
// nota por uid, purga a ocrFila, fecha os modais e atualiza a revisao.
function concluirExclusaoNota(uid, dados) {
    const posicao = posicaoNotaPorUid(uid);
    if (posicao > 0) {
        const removida = state.notasNumeros[posicao - 1];
        if (removida && Number.isInteger(removida.idNota)) idNotasExcluidas.add(removida.idNota);
        state.notasNumeros.splice(posicao - 1, 1);
        if (avisoOcrEmAndamento && !state.notasNumeros.some(n => n.aguardando) && removida && removida.aguardando) avisoOcrEmAndamento = false;
    }
    ocrFila = ocrFila.filter(item => item.uid !== uid);
    // resultado de OCR em execucao e identificacoes em voo desta nota sao
    // descartados pelo guard por uid (buscarNotaPorUid) ao terminarem
    state.excluindoNota = false;
    fecharConfirmacaoExcluirNota();
    numeroModalAberta = false;
    numeroModalOverrideUid = null;
    fecharModal();
    if (state.notasNumeros.length === 0) {
        avisoCapturaVazia = true;
        ir('rec_digitaliza'); // sem notas: volta sozinho a captura (contador 0, Finalizar desabilitado)
        notificarFimDeTrabalhoNotas();
        return;
    }
    atualizarRevisaoNumeros();
    if (posicao > 0) mostrarFaixaExclusaoNota(posicao);
    const primeiro = document.querySelector('#revisaoLista .rev-cartao');
    if (primeiro) primeiro.focus();
    notificarFimDeTrabalhoNotas();
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
            // TESSERACT_VENDOR_PATH/tesseract.min.js ja e carregado via <script>.
            // cacheMethod 'none' (Tesseract.js 5.1.1, worker.min.js): nao le nem
            // grava a traineddata no IndexedDB do navegador, entao uma traineddata
            // antiga em cache nunca substitui o arquivo versionado do servidor.
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

// Chama o endpoint de identificacao, UMA vez por nota (id_nota; sem early-stop).
// Retry simples (2 tentativas extras, backoff curto) so para falha de rede
// (TypeError do fetch) — nunca para resposta de negocio (NAO_IDENTIFICADA/ERRO)
// nem para 404 (nota ja excluida) ou 429 (rate limit): esses sao tratados como
// "nao identificada", sem alarme ao motorista e sem retry. Quem decide o cliente
// do atendimento e o backend (concluir-digitalizacao).
// idAtendimento/geracao vem CAPTURADOS no enfileiramento do OCR (medirSeq: so medicao); o guard e
// reavaliado antes de CADA chamada api() (inclusive retries) e antes de escrever
// estado, para nunca chamar a API com id nulo ou do atendimento novo. O guard
// por uid descarta a identificacao de uma nota que ja foi excluida.
let identificacoesEmVoo = 0;
async function identificarClienteNota(uid, cnpjsCandidatos, razaoSocialCandidata, idAtendimentoCapturado, geracaoCapturada, medirSeq) {
    const alvo = { idAtendimento: idAtendimentoCapturado, geracao: geracaoCapturada };
    const backoffMs = [1000, 2000];
    identificacoesEmVoo++;
    marca('ocr_ini_cli', medirSeq); // so ?medir=1: inicio da identificacao
    try {
        for (let tentativa = 0; tentativa <= backoffMs.length; tentativa++) {
            if (digitalizacaoConcluida || !atendimentoVigente(alvo)) return;
            const ident = identificadorNotaApi(buscarNotaPorUid(uid));
            if (!ident) return; // nota excluida (ou sem identificador): nada a identificar
            try {
                await api('nota.php', 'identificar-cliente', Object.assign({
                    id_atendimento: idAtendimentoCapturado,
                    chave_ocr: null, // extracao de chave removida do processo (ver extrairCandidatos)
                    cnpjs_candidatos: cnpjsCandidatos,
                    razao_social_candidata: razaoSocialCandidata,
                }, ident));
                return; // o resultado nao e lido no front: o cliente e decidido pelo backend ao concluir
            } catch (e) {
                const erroDeRede = e instanceof TypeError;
                if (!erroDeRede || tentativa === backoffMs.length) {
                    if (erroDeRede) logFalhaOcr('identificar', e); // rede, tentativas esgotadas
                    return; // tratado como equivalente a NAO_IDENTIFICADA/ERRO, sem alarme ao motorista
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

// -------------------- recebimento: confirmacao do cliente (autocomplete) --------------------

let clienteSelecionado = null;

// Texto neutro e IGUAL para NAO_IDENTIFICADO e ANOMALIA: nunca mostra CNPJ, razao
// social nem qualquer dado de candidatos das notas, e o campo nao e pre-preenchido.
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
    } catch (e) { /* autocomplete nao pode travar a tela */ }
}
async function confirmarCliente() {
    const nome = (clienteSelecionado && clienteSelecionado.nome) || document.getElementById('inputCliente').value.trim();
    const cnpj = (clienteSelecionado && clienteSelecionado.cnpj) || null;
    if (!nome) return mostrarErroTela('Digite ou selecione um cliente');
    try {
        await api('atendimento.php', 'salvar-etapa', { id_atendimento: state.idAtendimento, etapa: 'cliente', dados: { nome, cnpj } });
        state.dados = Object.assign({}, state.dados, { cliente_nome: nome, cliente_cnpj: cnpj });
        // AtendimentoController::salvarEtapa (case 'cliente') ja atualiza
        // etapa_atual para a etapa unica 'rec_cnh' no backend; o front traduz
        // direto para a tela de QR da CNH.
        ir('rec_cnh_qr');
    } catch (e) { mostrarErroTela(e.message); }
}

// ===================================================================
// RECEBIMENTO — captura/validacao de CNH e CRLV via VIO Decode (QR code)
// (REPLANEJAMENTO 2026-09-09 da demanda expedicao-vio-cnh-crlv: VIO Decode
// passa a valer tambem para o Recebimento). Telas e funcoes NOVAS E
// DEDICADAS (prefixo recCam/rec, mesmo padrao ja usado para expCam/exp).
// A CNH do Recebimento vive numa UNICA etapa no backend ('rec_cnh'), validada
// por QR exclusivo (id_atendimento, tipo, imagem_qr_base64). Recebimento NAO
// tem ordem de coleta — nenhuma UI/logica deste bloco pressupoe isso.
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

// -------------------- recebimento: telas de captura de QR (CNH, CRLV) --------------------

function telaRecQr(titulo, tipo) {
    return `<div class="titulo">${titulo}</div>
        <div class="subtitulo">Aproxime o QR code do guia e mantenha-o bem iluminado.</div>
        <div class="caixa-scanner" id="recCamCaixa">
            <video id="recCamVideo" autoplay playsinline></video>
            <div class="guia-scanner" aria-hidden="true"></div>
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
        canvas = recCamCapturarFrame(video);
        const qr = await lerQrDoCanvas(canvas);
        if (!qr || !qr.ok) {
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
// Indicador DISCRETO e NAO BLOQUANTE (nunca modal, nunca header fixo) de que
// a CNH ainda esta sendo validada em segundo plano enquanto o motorista ja
// esta fotografando o CRLV — mesmo espirito de exibirIndicadorProcessamentoCnh()
// da Expedicao.
function exibirIndicadorProcessamentoCnhRec() {
    const el = document.getElementById('recBgStatus');
    if (!el || !state.rec.cnhPromise) return;
    // Texto vindo do status_processamento EXPLICITO do backend (mesmo padrao
    // de exibirIndicadorProcessamentoCnh() da Expedicao, ver comentario la —
    // demanda migracao-vio-api-br-com-cache, 2026-09-25).
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
        if (!alvo) return; // a tela ja pode ter mudado
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

// Estados intermediarios EXPLICITOS do backend (ENVIANDO/PROCESSANDO_LEITURA/
// PROCESSANDO_COMPARACAO) — demanda migracao-vio-api-br-com-cache,
// 2026-09-25, item 4 do escopo (nunca inferidos no front), mesmo padrao ja
// usado em telaExpAguardeDocumentos().
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
    // Origem SEMPRE vinda explicitamente do backend (nunca inferida a partir
    // de aviso_trial — ver mesma correcao em processarAguardeDocumentosExp()).
    if (resultado.cnh.pode_avancar) state.rec.cnhOrigem = resultado.cnh.origem || 'VIO_VALIDADO';
    if (resultado.crlv.pode_avancar) state.rec.crlvOrigem = resultado.crlv.origem || 'VIO_VALIDADO';
    if (state.tela !== 'rec_aguarde_documentos') return; // usuario ja saiu da tela (ex.: cancelou)
    registrarMotivosReprovacao('rec', resultado);
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
        <div class="modal-fundo modal-fundo-confirma-nota" id="modalConfirmCancelNotaFundo"><div class="modal-caixa" id="modalConfirmCancelNotaCaixa"></div></div>
        <div class="modal-fundo modal-fundo-confirma-excluir" id="modalConfirmExcluirNotaFundo"><div class="modal-caixa" id="modalConfirmExcluirNotaCaixa" role="dialog" aria-modal="true"></div></div>
        <div class="modal-fundo modal-fundo-inatividade" id="modalInatividadeFundo"><div class="modal-caixa" id="modalInatividadeCaixa"></div></div>
        <div id="toastErro" class="toast-erro" role="alert" aria-live="assertive" aria-atomic="true"></div>
    `;
    montarTeclado();
    document.getElementById('tela').addEventListener('focusin', e => {
        if (e.target.classList.contains('kb-input')) abrirTeclado(e.target);
    });
    // 'lgpd' e a primeira tela real da SPA — nenhum outro codigo monta/
    // mostra 'home' antes deste ponto, entao nao ha flash de 'home'
    // durante o carregamento (demanda tela-inicial-lgpd-totem, 2026-09-24).
    ir('lgpd');
}

iniciarApp();
