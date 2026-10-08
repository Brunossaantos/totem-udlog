// Harness de layout/UX/seguranca do front da Gestao Totem (demanda gestao-totem, F1).
// Sobe as paginas REAIS de public/gestao/ por um servidor `php -S` local e descartavel
// (tests/manual/gestao_layout_servidor.php: banco QA qa_qr_exclusivo_<hex> com usuarios
// semeados, removido no fim) e dirige o Chrome headless (puppeteer, ja instalado em
// %APPDATA%\npm; nenhum pacote e instalado).
//   node tests/manual/gestao_layout.js
// Capturas de tela: pasta indicada em GESTAO_CAPTURAS (padrao: tmp do sistema).
// RISCO ACEITO (sem JS): confirmacao de desativar/redefinir, "Copiar" e "Mostrar senha"
// dependem de JS; sem JS as acoes seguem funcionando (formularios com CSRF), sem confirmacao.
// F2 (totens, 2026-10-07): lista/novo totem/URL em 1366x768, 1920x1080 e 1000x700, dialogos (desativar, regerar com
// nome digitado), copiar URL, sem JS, XSS, densidade e estado vazio; o servidor semeia os totens e TOTEM_URL_BASE (so no processo).
// Rodada UX F2 (2026-10-08): "Copiar URL" copia de verdade na lista (data-copiar-*; sem JS vira "Abrir URL"), nota do legado, destaque do atendimento, nota permanente neutra da URL regerada, ajuda na tela da URL, maxlength 24.
// Rodada de correcao de UX (2026-10-06): alvos de 44px, erro antes do toggle, requisitos
// neutros, flash persistente, info, dialogo destrutivo cheio, troca obrigatoria em foco unico.
const fs = require('fs');
const os = require('os');
const net = require('net');
const path = require('path');
const { spawn } = require('child_process');

let puppeteer;
try { puppeteer = require('puppeteer'); } catch (e) {
    puppeteer = require(path.join(process.env.APPDATA || os.homedir(), 'npm', 'node_modules', 'puppeteer'));
}
const RAIZ = path.join(__dirname, '..', '..');
const CAPTURAS = process.env.GESTAO_CAPTURAS || os.tmpdir();
const CHROME = process.env.CHROME || (fs.existsSync('C:/Program Files/Google/Chrome/Application/chrome.exe') ? 'C:/Program Files/Google/Chrome/Application/chrome.exe' : undefined);
const PALETA = ['1,121,173', '58,58,58', '135,135,137', '155,160,165', '176,176,177', '255,255,255'];

let passou = 0, falhou = 0;
function ok(cond, texto) { if (cond) { passou++; } else { falhou++; console.log('FALHA: ' + texto); } }
const espera = ms => new Promise(r => setTimeout(r, ms));

function portaLivre() {
    return new Promise((res, rej) => {
        const s = net.createServer();
        s.listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => res(p)); });
        s.on('error', rej);
    });
}

async function subirServidor() {
    const porta = await portaLivre();
    const proc = spawn(process.env.PHP || 'php', [path.join(__dirname, 'gestao_layout_servidor.php'), String(porta)], { cwd: RAIZ, stdio: ['pipe', 'pipe', 'inherit'] });
    const info = await new Promise((res, rej) => {
        let buf = '';
        const t = setTimeout(() => rej(new Error('servidor nao ficou pronto')), 90000);
        proc.stdout.on('data', d => {
            buf += d.toString();
            const linha = buf.split('\n').find(l => l.startsWith('{'));
            if (linha) { clearTimeout(t); res(JSON.parse(linha)); }
        });
        proc.on('exit', () => rej(new Error('servidor encerrou antes de ficar pronto')));
    });
    return { proc, info, base: 'http://127.0.0.1:' + porta };
}

async function pararServidor(srv) {
    if (!srv) { return; }
    const fim = new Promise(r => srv.proc.on('exit', r));
    try { srv.proc.stdin.write('fim\n'); srv.proc.stdin.end(); } catch (e) { /* ja fechou */ }
    await Promise.race([fim, espera(30000)]);
    try { srv.proc.kill(); } catch (e) { /* ignorado */ }
}

/* ------------------------- funcoes executadas dentro da pagina ------------------------- */
function varrerPaleta(paleta) {
    const permitido = new Set(paleta);
    const fora = [];
    const ignorar = new Set(['HEAD', 'META', 'LINK', 'TITLE', 'SCRIPT', 'STYLE', 'NOSCRIPT']);
    const rgbs = s => { const r = []; const re = /rgba?\(([^)]+)\)/g; let m; while ((m = re.exec(s || ''))) { const p = m[1].split(/[ ,\/]+/).filter(Boolean); r.push({ rgb: p.slice(0, 3).map(Number).join(','), a: p.length > 3 ? Number(p[3]) : 1 }); } return r; };
    const teste = (el, prop, valor, extra) => {
        for (const c of rgbs(valor)) {
            if (c.a === 0) { continue; }
            if (!permitido.has(c.rgb)) { fora.push((el.id ? '#' + el.id : el.tagName + '.' + String(el.className && el.className.baseVal !== undefined ? el.className.baseVal : el.className)) + ' ' + prop + '=' + c.rgb + (extra || '')); }
        }
    };
    const analisar = (el, pseudo) => {
        const cs = getComputedStyle(el, pseudo || null);
        teste(el, 'color', cs.color); teste(el, 'background-color', cs.backgroundColor);
        for (const lado of ['Top', 'Right', 'Bottom', 'Left']) {
            if (cs['border' + lado + 'Style'] !== 'none' && parseFloat(cs['border' + lado + 'Width']) > 0) { teste(el, 'border-' + lado, cs['border' + lado + 'Color']); }
        }
        if (cs.outlineStyle !== 'none' && parseFloat(cs.outlineWidth) > 0) { teste(el, 'outline', cs.outlineColor); }
        if (cs.textDecorationLine !== 'none') { teste(el, 'text-decoration', cs.textDecorationColor); }
        if (cs.boxShadow !== 'none') { teste(el, 'box-shadow', cs.boxShadow); }
        if (el instanceof SVGElement) { if (cs.fill !== 'none') { teste(el, 'fill', cs.fill); } if (cs.stroke !== 'none') { teste(el, 'stroke', cs.stroke); } }
        if (cs.accentColor !== 'auto') { teste(el, 'accent-color', cs.accentColor); }
    };
    for (const el of document.querySelectorAll('body, body *')) {
        if (ignorar.has(el.tagName) || el.closest('.gestao-sprite')) { continue; }
        analisar(el);
        analisar(el, '::before'); analisar(el, '::after');
    }
    document.querySelectorAll('dialog[open]').forEach(d => analisar(d, '::backdrop'));
    analisar(document.documentElement);
    return fora;
}

function varrerContraste() {
    const lum = ([r, g, b]) => { const f = v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b); };
    const num = s => { const m = /rgba?\(([^)]+)\)/.exec(s); if (!m) { return null; } const p = m[1].split(/[ ,\/]+/).filter(Boolean).map(Number); return { c: p.slice(0, 3), a: p.length > 3 ? p[3] : 1 }; };
    const fundo = el => { for (let e = el; e; e = e.parentElement) { const n = num(getComputedStyle(e).backgroundColor); if (n && n.a > 0) { return n.c; } } return [255, 255, 255]; };
    const falhas = []; const secundarios = []; let medidos = 0;
    for (const el of document.querySelectorAll('body *')) {
        if (el.closest('.gestao-sprite, .gestao-sr') || el.classList.contains('gestao-sr')) { continue; }
        const temTexto = Array.from(el.childNodes).some(n => n.nodeType === 3 && n.textContent.trim() !== '');
        if (!temTexto || el.getClientRects().length === 0) { continue; }
        const cs = getComputedStyle(el);
        if (cs.visibility === 'hidden') { continue; }
        const f = num(cs.color); if (!f) { continue; }
        const L1 = lum(f.c), L2 = lum(fundo(el));
        const razao = (Math.max(L1, L2) + 0.05) / (Math.min(L1, L2) + 0.05);
        const rgb = f.c.join(','); const px = parseFloat(cs.fontSize);
        medidos++;
        const nome = (el.id ? '#' + el.id : el.tagName + '.' + el.className) + ' "' + el.textContent.trim().slice(0, 25) + '"';
        if (rgb === '155,160,165') { falhas.push(nome + ' usa #9BA0A5 em texto'); continue; }
        if (rgb === '135,135,137') { secundarios.push({ nome, px, razao }); if (px < 14) { falhas.push(nome + ' #878789 com ' + px + 'px'); } continue; }
        if (razao < 4.5) { falhas.push(nome + ' contraste ' + razao.toFixed(2)); }
    }
    return { falhas, secundarios, medidos };
}

function estadoPagina() {
    const de = document.documentElement;
    const s = document.querySelector('.gestao-sidebar');
    const c = document.querySelector('.gestao-conteudo');
    return {
        scrollW: de.scrollWidth, clientW: de.clientWidth,
        sidebarW: s ? Math.round(s.getBoundingClientRect().width) : null,
        conteudoW: c ? Math.round(c.getBoundingClientRect().width - parseFloat(getComputedStyle(c).paddingLeft) - parseFloat(getComputedStyle(c).paddingRight)) : null,
        conteudoX: c ? Math.round(c.getBoundingClientRect().left) : null,
    };
}

/* ------------------------------------ utilitarios de pagina ------------------------------------ */
async function novaPagina(browser, w, h, rotulo, contadores) {
    const page = await browser.newPage();
    await page.setViewport({ width: w, height: h });
    await page.evaluateOnNewDocument(() => {
        window.__csp = [];
        document.addEventListener('securitypolicyviolation', e => window.__csp.push(e.violatedDirective + ' ' + e.blockedURI));
    });
    page.on('console', m => { if (/Content Security Policy|Refused to/i.test(m.text())) { contadores.csp.push(rotulo + ': ' + m.text()); } });
    page.on('pageerror', e => contadores.erros.push(rotulo + ': ' + e.message));
    page.on('response', r => {
        const u = new URL(r.url());
        if (u.hostname !== '127.0.0.1') { contadores.externos.push(r.url()); }
        if (r.request().resourceType() === 'document' && !r.headers()['content-security-policy']) { contadores.semCsp.push(r.url()); }
    });
    page.on('request', r => { if (!r.url().startsWith('http://127.0.0.1') && !r.url().startsWith('data:')) { contadores.externos.push(r.url()); } });
    return page;
}

async function violacoesCsp(page) { return page.evaluate(() => window.__csp || []); }

async function checagemGeral(page, rotulo, contadores, opcoes) {
    opcoes = opcoes || {};
    const e = await page.evaluate(estadoPagina);
    ok(e.scrollW <= e.clientW, rotulo + ': sem overflow horizontal da pagina (scrollWidth ' + e.scrollW + ' <= ' + e.clientW + ')');
    const fora = await page.evaluate(varrerPaleta, PALETA);
    ok(fora.length === 0, rotulo + ': so cores da paleta UDLOG' + (fora.length ? ' -> ' + fora.slice(0, 6).join(' | ') : ''));
    const ct = await page.evaluate(varrerContraste);
    ok(ct.medidos > 0 && ct.falhas.length === 0, rotulo + ': contraste (>=4.5:1; #878789 so >=14px; #9BA0A5 nunca em texto), ' + ct.medidos + ' textos' + (ct.falhas.length ? ' -> ' + ct.falhas.slice(0, 6).join(' | ') : ''));
    contadores.secundarios = contadores.secundarios.concat(ct.secundarios.map(s => rotulo + ' ' + s.nome + ' ' + s.px + 'px ' + s.razao.toFixed(2) + ':1'));
    const v = await violacoesCsp(page);
    ok(v.length === 0, rotulo + ': zero violacoes de CSP (securitypolicyviolation)' + (v.length ? ' -> ' + v.join(' | ') : ''));
    const inline = await page.evaluate(() => ({
        scripts: Array.from(document.scripts).filter(s => !s.src).length,
        styles: document.querySelectorAll('style').length,
        atributos: document.querySelectorAll('[style]').length,
    }));
    ok(inline.scripts === 0 && inline.styles === 0 && inline.atributos === 0, rotulo + ': DOM sem script/style/atributo style inline');
    const xss = await page.evaluate(() => window.__xss);
    ok(xss === undefined, rotulo + ': nenhum payload XSS executou');
    return e;
}

async function botoesSolidos(page, rotulo) {
    const r = await page.evaluate(() => Array.from(document.querySelectorAll('.gestao-botao')).filter(b => b.getClientRects().length).map(b => {
        const cs = getComputedStyle(b);
        return { t: b.textContent.trim(), cls: b.className, bg: cs.backgroundColor, bw: cs.borderTopWidth, bs: cs.borderTopStyle, bc: cs.borderTopColor, cor: cs.color, h: Math.round(b.getBoundingClientRect().height) };
    }));
    const maus = [];
    for (const b of r) {
        if (b.cls.includes('--primario') && !(b.bg === 'rgb(1, 121, 173)' && b.cor === 'rgb(255, 255, 255)')) { maus.push(b.t + ' primario'); }
        if (b.cls.includes('--secundario') && !(b.bg === 'rgb(255, 255, 255)' && b.bs === 'solid' && parseFloat(b.bw) >= 2 && b.bc === 'rgb(1, 121, 173)')) { maus.push(b.t + ' secundario'); }
        if (b.cls.includes('--destrutivo') && !(parseFloat(b.bw) >= 3 && b.bc === 'rgb(58, 58, 58)' && b.bs === 'solid')) { maus.push(b.t + ' destrutivo'); }
        if (b.h < 44) { maus.push(b.t + ' altura ' + b.h); }
    }
    ok(r.length > 0 && maus.length === 0, rotulo + ': ' + r.length + ' botoes com fundo/borda solidos sem depender de :hover' + (maus.length ? ' -> ' + maus.join(' | ') : ''));
}

async function focoVisivel(page, rotulo, qtdTabs) {
    let bons = 0, total = 0, ruins = [];
    for (let i = 0; i < qtdTabs; i++) {
        await page.keyboard.press('Tab');
        const f = await page.evaluate(() => { const a = document.activeElement; if (!a || a === document.body) { return null; } const cs = getComputedStyle(a); return { t: (a.id || a.tagName) + ' ' + (a.textContent || '').trim().slice(0, 20), w: cs.outlineWidth, c: cs.outlineColor, s: cs.outlineStyle }; });
        if (!f) { continue; }
        total++;
        if (f.s === 'solid' && parseFloat(f.w) >= 3 && f.c === 'rgb(1, 121, 173)') { bons++; } else { ruins.push(f.t + ' ' + f.s + ' ' + f.w + ' ' + f.c); }
    }
    ok(total > 0 && bons === total, rotulo + ': foco visivel 3px #0179AD em ' + total + ' elementos via Tab' + (ruins.length ? ' -> ' + ruins.join(' | ') : ''));
}

async function irPara(page, base, caminho) {
    const r = await page.goto(base + caminho, { waitUntil: 'networkidle0' });
    return r;
}

async function entrar(page, base, login, senha) {
    await irPara(page, base, '/gestao/login.php');
    await page.type('#login', login);
    await page.type('#senha', senha);
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('#btn-entrar')]);
}

async function limparEstado(page, base) {
    const c = await page.createCDPSession();
    await c.send('Network.clearBrowserCookies');
    await c.detach();
    await page.goto(base + '/gestao/login.php', { waitUntil: 'networkidle0' });
    await page.evaluate(() => { try { localStorage.clear(); } catch (e) { /* ignorado */ } });
}

const idsPorLogin = page => page.evaluate(() => {
    const m = {};
    document.querySelectorAll('tr[data-id-usuario]').forEach(tr => { m[tr.querySelector('.col-login').textContent.trim()] = tr.getAttribute('data-id-usuario'); });
    return m;
});

/* ------------------------------------------- cenarios ------------------------------------------- */
async function cenarioViewport(browser, srv, w, h, completo, contadores) {
    const rot = w + 'x' + h;
    const { base, info } = srv;
    const page = await novaPagina(browser, w, h, rot, contadores);
    await limparEstado(page, base);

    // ---- login (publico)
    let resp = await irPara(page, base, '/gestao/login.php');
    ok(resp.status() === 200, rot + ' login: 200');
    let e = await checagemGeral(page, rot + ' login', contadores);
    ok(await page.evaluate(() => document.title === 'Entrar - Gestão Totem' || document.title.endsWith('- Gestão Totem')), rot + ' login: <title> com "Gestão Totem" (UTF-8)');
    ok(await page.evaluate(() => { const i = document.querySelector('.gestao-login__logo'); return i && i.complete && i.naturalWidth > 0 && Math.round(i.getBoundingClientRect().width) === 180; }), rot + ' login: logo UDLOG carregado');
    ok(await page.evaluate(() => Math.round(document.querySelector('.gestao-login').getBoundingClientRect().width) === 400 && Math.abs((document.querySelector('.gestao-login').getBoundingClientRect().left + document.querySelector('.gestao-login').getBoundingClientRect().width / 2) - innerWidth / 2) < 2), rot + ' login: cartao de 400px centralizado');
    ok(await page.evaluate(() => Math.round(document.getElementById('btn-entrar').getBoundingClientRect().height) === 44 && Math.round(document.getElementById('btn-entrar').getBoundingClientRect().width) === Math.round(document.getElementById('senha').getBoundingClientRect().width)), rot + ' login: botao Entrar com largura total e 44px');
    ok(await page.evaluate(() => { const f = document.getElementById('form-login'); const l = document.getElementById('login'); const s = document.getElementById('senha'); return f.getAttribute('autocomplete') === null && l.getAttribute('autocomplete') === 'username' && s.getAttribute('autocomplete') === 'current-password' && document.getElementById('login-ajuda').textContent.includes('bruno.carvalho') && !document.body.textContent.includes('Formato primeiro.segundo') && l.getAttribute('aria-describedby') === 'login-ajuda'; }), rot + ' login: autocomplete username/current-password (sem off), exemplo bruno.carvalho');
    ok(await page.evaluate(() => { const p = document.getElementById('login-esqueceu'); return p && p.textContent === 'Esqueceu a senha? Peça a um administrador.' && !p.querySelector('a') && getComputedStyle(p).fontSize === '14px'; }), rot + ' login: ajuda "Esqueceu a senha? Peca a um administrador." sem link');
    ok(await page.evaluate(() => Math.round(document.querySelector('.gestao-mostrar').getBoundingClientRect().height) >= 44), rot + ' login: area do "Mostrar senha" com >= 44px de altura');
    ok(await page.evaluate(() => !!document.querySelector('link[rel=icon][href*="favicon.svg"]') && getComputedStyle(document.body).fontFamily.includes('Segoe UI')), rot + ' login: favicon referenciado e fonte do sistema');
    await botoesSolidos(page, rot + ' login');
    await page.screenshot({ path: path.join(CAPTURAS, 'login-' + w + '.png') });
    // mostrar senha (JS)
    await page.type('#senha', 'abc123');
    await page.click('.gestao-mostrar input');
    ok(await page.$eval('#senha', i => i.type) === 'text', rot + ' login: "Mostrar senha" revela o campo');
    await page.click('.gestao-mostrar input');
    ok(await page.$eval('#senha', i => i.type) === 'password', rot + ' login: "Mostrar senha" oculta de novo');
    await page.$eval('#senha', i => { i.value = ''; });
    // foco: login recebe foco automatico; Tab percorre
    await page.focus('#login');
    await focoVisivel(page, rot + ' login', 4);

    // ---- login errado com payload XSS no campo login
    await page.$eval('#login', i => { i.value = ''; });
    await page.type('#login', '"><script>window.__xss=3</script>');
    await page.type('#senha', 'senha-errada-123');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('#btn-entrar')]);
    e = await checagemGeral(page, rot + ' login com erro', contadores);
    ok(await page.evaluate(() => { const p = document.getElementById('login-erro'); return p && p.getAttribute('role') === 'alert' && p.textContent === 'Login ou senha incorretos. Confira e tente de novo; se esqueceu a senha, peça a um administrador.' && !!p.parentElement.querySelector('svg'); }), rot + ' login: erro generico com role=alert e icone');
    ok(await page.$eval('#login', i => i.value) === '"><script>window.__xss=3</script>' && await page.evaluate(() => !document.querySelector('script:not([src])')), rot + ' login: XSS no campo login volta literal e nao executa');
    await page.screenshot({ path: path.join(CAPTURAS, 'login-erro-' + w + '.png') });

    // ---- admin: usuarios
    await limparEstado(page, base);
    await entrar(page, base, 'ana.admin', info.senha);
    ok(page.url().endsWith('/gestao/usuarios.php'), rot + ' admin entra em /gestao/usuarios.php');
    e = await checagemGeral(page, rot + ' usuarios', contadores);
    ok(e.sidebarW === 248, rot + ' usuarios: sidebar fixa visivel de 248px (' + e.sidebarW + ')');
    ok(await page.evaluate(() => { const s = document.querySelector('.gestao-sidebar'); const r = s.getBoundingClientRect(); return r.left === 0 && r.height >= innerHeight - 1 && getComputedStyle(s).position === 'sticky'; }), rot + ' usuarios: sidebar ocupa a altura toda e acompanha a rolagem');
    ok(await page.evaluate(() => { const l = document.querySelector('.gestao-menu__link[aria-current="page"]'); const cs = getComputedStyle(l); window.__ativo = l && [l.textContent.trim(), cs.backgroundColor, cs.color, l.getBoundingClientRect().height].join('|'); return l && l.textContent.trim() === 'Usuários' && cs.backgroundColor === 'rgb(1, 121, 173)' && cs.color === 'rgb(255, 255, 255)' && l.getBoundingClientRect().height >= 44; }), rot + ' usuarios: item ativo com aria-current, fundo #0179AD, texto branco e 44px');
    ok(await page.evaluate(() => Array.from(document.querySelectorAll('.gestao-menu__link')).every(l => l.getBoundingClientRect().height >= 44 && l.querySelector('svg.gestao-icone'))), rot + ' usuarios: itens do menu com 44px e icone SVG');
    ok(await page.evaluate(() => { const i = document.querySelector('.gestao-marca__logo'); return i.complete && i.naturalWidth > 0 && Math.round(i.getBoundingClientRect().width) === 140; }), rot + ' usuarios: logo de 140px na sidebar');
    ok(await page.evaluate(() => { const h = document.querySelector('h1'); const cs = getComputedStyle(h); return cs.fontSize === '24px' && cs.fontWeight === '600'; }), rot + ' usuarios: titulo 24px/600');
    ok(await page.evaluate(() => { const l = document.querySelector('.gestao-pular'); return l && l.getAttribute('href') === '#conteudo' && l.getBoundingClientRect().top < 0; }), rot + ' usuarios: link "Pular para o conteudo" presente (fora da tela ate receber foco)');
    const tab = await page.evaluate(() => {
        const t = document.getElementById('tabela-usuarios'); const rows = Array.from(t.tBodies[0].rows);
        return { fs: getComputedStyle(t).fontSize, alturas: rows.map(r => Math.round(r.getBoundingClientRect().height)), cabecalhos: Array.from(t.tHead.rows[0].cells).map(c => c.textContent.trim()), situacoes: Array.from(t.querySelectorAll('.col-situacao')).map(c => c.textContent.replace(/\s+/g, ' ').trim()), icones: Array.from(t.querySelectorAll('.col-situacao')).every(c => c.querySelector('svg')), wrap: (() => { const w = document.querySelector('.gestao-tabela-wrap'); return { sw: w.scrollWidth, cw: w.clientWidth }; })() };
    });
    ok(tab.fs === '14px' && tab.alturas.every(a => a >= 44), rot + ' usuarios: tabela 14px com linhas >= 44px (' + tab.alturas.join(',') + ')');
    ok(JSON.stringify(tab.cabecalhos) === JSON.stringify(['Nome', 'Login', 'Perfil', 'Situação', 'Último acesso', 'Ações']), rot + ' usuarios: cabecalhos da tabela');
    ok(tab.situacoes.some(s => s.startsWith('Ativo')) && tab.situacoes.some(s => s.startsWith('Inativo')) && tab.icones, rot + ' usuarios: situacao em texto "Ativo"/"Inativo" + icone');
    ok(tab.wrap.sw <= tab.wrap.cw + 1, rot + ' usuarios: tabela cabe na largura util (' + tab.wrap.sw + '/' + tab.wrap.cw + ')');
    if (w >= 1600) {
        ok(e.conteudoW <= 1600 + 1, rot + ' usuarios: conteudo limitado a ~1600px e centralizado (largura ' + e.conteudoW + ')');
    }
    // ---- ajustes de UX: acoes da tabela
    const acoes = await page.evaluate(() => {
        const linhas = Array.from(document.querySelectorAll('#tabela-usuarios tbody tr[data-id-usuario]'));
        const r = el => el.getBoundingClientRect();
        let altMin = 999, gapMin = 999, destrutivoSeparado = true, destrutivoDistinto = true;
        let nomeMin = 9999;
        const linhaDe = login => linhas.find(l => l.querySelector('.col-login').textContent.trim() === login);
        for (const l of linhas) {
            const bs = Array.from(l.querySelectorAll('.col-acoes .gestao-botao')).filter(b => b.getClientRects().length);
            bs.forEach(b => { altMin = Math.min(altMin, Math.round(r(b).height)); });
            for (let i = 0; i < bs.length; i++) for (let j = i + 1; j < bs.length; j++) {
                const a = r(bs[i]), b = r(bs[j]);
                const mesmaLinha = Math.abs(a.top - b.top) < 4;
                if (mesmaLinha) { gapMin = Math.min(gapMin, Math.round(b.left - a.right)); }
                else if (b.top >= a.bottom - 1) { gapMin = Math.min(gapMin, Math.round(b.top - a.bottom)); }
            }
            const d = l.querySelector('button[value="desativar"]');
            if (d) {
                const grupo = d.closest('.gestao-acoes-grupo');
                const outros = Array.from(l.querySelectorAll('.col-acoes .gestao-botao')).filter(b => b !== d);
                const cs = getComputedStyle(d);
                destrutivoSeparado = destrutivoSeparado && grupo.classList.contains('gestao-acoes-grupo--destrutivo') && !outros.some(o => grupo.contains(o));
                destrutivoDistinto = destrutivoDistinto && cs.borderTopWidth === '3px' && cs.borderTopColor === 'rgb(58, 58, 58)' && outros.every(o => getComputedStyle(o).borderTopColor === 'rgb(1, 121, 173)');
                const vizinho = outros.filter(o => Math.abs(r(o).top - r(d).top) < 4).map(o => Math.round(r(d).left - r(o).right));
                if (vizinho.length && Math.min(...vizinho) < 24) { destrutivoSeparado = false; }
            }
            nomeMin = Math.min(nomeMin, Math.round(r(l.querySelector('.col-nome')).width));
        }
        const proprio = linhaDe('ana.admin');
        const bloq = linhaDe('bloq.usuario');
        const bloqEl = bloq && bloq.querySelector('.gestao-situacao__bloqueio');
        const desb = bloq && bloq.querySelector('button[value="desbloquear"]');
        return {
            altMin, gapMin, destrutivoSeparado, destrutivoDistinto, nomeMin,
            dicaProprio: proprio && proprio.querySelector('.col-acoes').textContent.replace(/\s+/g, ' ').includes('Para trocar sua senha, use Minha conta.'),
            proprioSemForm: proprio && !proprio.querySelector('form'),
            bloqueio: bloqEl && { texto: bloqEl.textContent.replace(/\s+/g, ' ').trim(), icone: !!bloqEl.querySelector('svg'), peso: getComputedStyle(bloqEl).fontWeight, hora: !!bloqEl.querySelector('time') },
            desbloquear: desb && { h: Math.round(r(desb).height), sec: desb.classList.contains('gestao-botao--secundario'), semConfirmar: !desb.hasAttribute('data-confirmar'), mesmoForm: !!desb.closest('.gestao-acoes-grupo') && !!desb.form.querySelector('button[value="redefinir-senha"]'), acao: desb.value },
            desbloquearOutros: linhas.filter(l => l !== bloq && l.querySelector('button[value="desbloquear"]')).length,
        };
    });
    ok(acoes.altMin >= 44, rot + ' usuarios: botoes de acao com altura >= 44px (min ' + acoes.altMin + ')');
    ok(acoes.gapMin >= 12, rot + ' usuarios: espaco >= 12px entre acoes vizinhas (min ' + acoes.gapMin + ')');
    ok(acoes.destrutivoSeparado, rot + ' usuarios: "Desativar" em grupo proprio, afastado (>= 24px) das demais acoes');
    ok(acoes.destrutivoDistinto, rot + ' usuarios: "Desativar" com peso distinto (borda 3px #3A3A3A) das demais (secundarias azuis)');
    // grupo destrutivo: sem separador (nem vertical nem horizontal), ultimo botao, linhas densas
    const sep = await page.evaluate(() => {
        const r = el => el.getBoundingClientRect();
        const out = { linhas: 0, falhas: [], altMax: 0, umaLinha: 0, duasLinhas: 0, gapMin: 999, quebrou: 0 };
        for (const l of document.querySelectorAll('#tabela-usuarios tbody tr[data-id-usuario]')) {
            const g = l.querySelector('.gestao-acoes-grupo--destrutivo'); if (!g) { continue; }
            out.linhas++;
            const login = l.querySelector('.col-login').textContent.trim();
            const cs = getComputedStyle(g), linha = g.parentElement;
            const todos = Array.from(l.querySelectorAll('.col-acoes .gestao-botao')).filter(b => b.getClientRects().length);
            const d = l.querySelector('button[value="desativar"]');
            const ultimo = todos[todos.length - 1] === d;
            const semSep = ['Top', 'Right', 'Bottom', 'Left'].every(s => parseFloat(cs['border' + s + 'Width']) === 0) && cs.boxShadow === 'none' && cs.backgroundImage === 'none';
            const bd = getComputedStyle(d);
            const distinto = parseFloat(bd.borderTopWidth) >= 2 && bd.borderTopColor === 'rgb(58, 58, 58)' && !!d.querySelector('svg') && d.textContent.trim() === 'Desativar';
            const outros = todos.filter(b => b !== d);
            const tops = new Set(outros.concat([d]).map(b => Math.round(r(b).top)));
            let gap = 999; const ant = outros[outros.length - 1];
            if (ant) { gap = Math.abs(r(ant).top - r(d).top) < 4 ? Math.round(r(d).left - r(ant).right) : Math.round(r(d).top - r(ant).bottom); }
            out.gapMin = Math.min(out.gapMin, gap);
            const abaixo = tops.size > 1;
            if (abaixo) { out.quebrou++; }
            const rowsBtn = new Set(todos.map(b => Math.round(r(b).top))).size;
            if (rowsBtn > 1) { out.duasLinhas++; } else { out.umaLinha++; }
            // sem fio solto: se o grupo quebrou, ele comeca no inicio da linha e nao tem borda
            const sozinho = outros.every(b => Math.abs(r(b).top - r(d).top) >= 4);
            const alinhado = !sozinho || Math.abs(r(d).left - r(outros[0]).left) <= 12.5; // recuo de 12px = a margem de afastamento do botao
            out.altMax = Math.max(out.altMax, Math.round(r(l).height));
            if (!(ultimo && semSep && distinto && rowsBtn <= 2 && gap >= 12 && alinhado)) { out.falhas.push(login + ' ultimo=' + ultimo + ' semSep=' + semSep + ' distinto=' + distinto + ' linhasBtn=' + rowsBtn + ' gap=' + gap + ' alinhado=' + alinhado); }
        }
        const prop = Array.from(document.querySelectorAll('#tabela-usuarios tbody tr[data-id-usuario]')).filter(l => l.querySelector('button[value="desativar"]') && !l.querySelector('button[value="desbloquear"]') && !l.querySelector('.gestao-situacao__nota') && !/^xss\./.test(l.querySelector('.col-login').textContent.trim())).map(l => Math.round(r(l).height));
        out.alturasAtivos = prop;
        return out;
    });
    ok(sep.linhas > 0 && sep.falhas.length === 0, rot + ' usuarios: "Desativar" e o ultimo botao, sem separador (bordas/sombra do grupo = 0), borda >= 2px #3A3A3A + icone + rotulo, gap >= 12px, max 2 linhas de botoes (' + sep.linhas + ' linhas; 1 linha: ' + sep.umaLinha + ', 2 linhas: ' + sep.duasLinhas + ', gap min ' + sep.gapMin + ')' + (sep.falhas.length ? ' -> ' + sep.falhas.join(' | ') : ''));
    ok(sep.alturasAtivos.length > 0 && sep.alturasAtivos.every(a => a <= 70), rot + ' usuarios: linha de usuario ativo sem bloqueio/nota (fora os de nome XSS de 3 linhas) com altura <= 70px (' + sep.alturasAtivos.join(',') + ')');
    ok(acoes.nomeMin >= 160, rot + ' usuarios: coluna Nome com largura minima >= 160px (' + acoes.nomeMin + ')');
    ok(acoes.dicaProprio && acoes.proprioSemForm, rot + ' usuarios: linha do proprio usuario com a dica "Para trocar sua senha, use Minha conta." (sem formulario)');
    ok(acoes.bloqueio && /^Bloqueado até \d{2}:\d{2}$/.test(acoes.bloqueio.texto) && acoes.bloqueio.icone && parseInt(acoes.bloqueio.peso, 10) >= 700 && acoes.bloqueio.hora, rot + ' usuarios: situacao "Bloqueado ate HH:MM" com icone de alerta, texto e peso 700 (' + (acoes.bloqueio && acoes.bloqueio.texto) + ')');
    ok(acoes.desbloquear && acoes.desbloquear.h >= 44 && acoes.desbloquear.sec && acoes.desbloquear.semConfirmar && acoes.desbloquear.mesmoForm && acoes.desbloquear.acao === 'desbloquear' && acoes.desbloquearOutros === 0, rot + ' usuarios: "Desbloquear" so na linha bloqueada, secundario, 44px, sem confirmacao, no mesmo grupo de acoes');
    await botoesSolidos(page, rot + ' usuarios');
    await page.evaluate(() => { document.activeElement && document.activeElement.blur(); window.scrollTo(0, 0); });
    await page.keyboard.press('Tab');
    ok(await page.evaluate(() => document.activeElement.className === 'gestao-pular' && getComputedStyle(document.activeElement).top === '16px'), rot + ' usuarios: primeiro Tab foca o link "Pular para o conteudo" e ele aparece');
    await page.keyboard.press('Enter');
    ok(await page.evaluate(() => location.hash === '#conteudo'), rot + ' usuarios: "Pular" leva ao conteudo');
    await focoVisivel(page, rot + ' usuarios', 8);
    await page.screenshot({ path: path.join(CAPTURAS, 'usuarios-' + w + '.png') });

    // XSS por nome de usuario
    const ids = await idsPorLogin(page);
    ok(await page.evaluate(() => {
        const tx = Array.from(document.querySelectorAll('.col-nome')).map(c => c.textContent);
        return tx.some(t => t.includes('<img src=x onerror=window.__xss=1>')) && tx.some(t => t.includes('"><script>window.__xss=2</script>')) && !document.querySelector('#tabela-usuarios img') && !document.querySelector('#tabela-usuarios script');
    }), rot + ' usuarios: nomes com <img onerror>/<script> aparecem literais, sem elementos injetados');

    // sidebar recolhivel
    await page.click('#gestao-recolher');
    let rec = await page.evaluate(() => ({ w: Math.round(document.querySelector('.gestao-sidebar').getBoundingClientRect().width), exp: document.getElementById('gestao-recolher').getAttribute('aria-expanded'), pref: localStorage.getItem('gestao.sidebar'), rotulosOcultos: Array.from(document.querySelectorAll('.gestao-menu__rotulo')).every(r => r.getBoundingClientRect().width <= 1), aria: Array.from(document.querySelectorAll('.gestao-menu__link')).every(l => l.getAttribute('aria-label') && l.getAttribute('title')), icones: Array.from(document.querySelectorAll('.gestao-menu__link svg')).every(s => s.getBoundingClientRect().width > 0), mini: getComputedStyle(document.querySelector('.gestao-marca__mini')).display, miniSrc: document.querySelector('.gestao-marca__mini').getAttribute('src'), miniAlt: document.querySelector('.gestao-marca__mini').getAttribute('alt'), miniW: Math.round(document.querySelector('.gestao-marca__mini').getBoundingClientRect().width), miniOk: document.querySelector('.gestao-marca__mini').naturalWidth > 0 }));
    ok(rec.w === 64 && rec.exp === 'false' && rec.pref === 'recolhida' && rec.rotulosOcultos && rec.aria && rec.icones && rec.mini === 'block', rot + ' usuarios: sidebar recolhe para 64px (icones + tooltip title + aria-label, preferencia em localStorage)');
    ok(/\/udlog-leao\.png(\?|$)/.test(rec.miniSrc) && rec.miniAlt === '' && rec.miniW === 40 && rec.miniOk, rot + ' usuarios: mini marca recolhida = leao UDLOG (udlog-leao.png, alt vazio, 40px, carregou)');
    e = await checagemGeral(page, rot + ' usuarios recolhida', contadores);
    await espera(500); // transicao do glifo (0,15 s) terminou
    const glifo = await page.evaluate(() => { const b = document.getElementById('gestao-recolher').getBoundingClientRect(); const i = document.querySelector('#gestao-recolher svg').getBoundingClientRect(); return { dentro: i.left >= b.left && i.right <= b.right && i.top >= b.top && i.bottom <= b.bottom, giro: getComputedStyle(document.querySelector('#gestao-recolher svg')).transform }; });
    ok(glifo.dentro, rot + ' usuarios recolhida: glifo do botao recolher inteiro dentro do botao apos a transicao (transform ' + glifo.giro + ')');
    await page.screenshot({ path: path.join(CAPTURAS, 'usuarios-recolhida-' + w + '.png') });
    await page.reload({ waitUntil: 'networkidle0' });
    ok((await page.evaluate(estadoPagina)).sidebarW === 64, rot + ' usuarios: preferencia recolhida persiste apos recarregar');
    await page.click('#gestao-recolher');
    ok((await page.evaluate(estadoPagina)).sidebarW === 248 && await page.evaluate(() => localStorage.getItem('gestao.sidebar')) === 'expandida', rot + ' usuarios: expande de novo para 248px');

    // dialogo de confirmacao: abrir, foco inicial, foco preso, Esc
    await page.click('tr[data-id-usuario="' + ids['carla.usuario'] + '"] button[value="desativar"]');
    ok(await page.evaluate(() => { const d = document.getElementById('gestao-dialogo'); return d && d.open && document.activeElement.id === 'gestao-dialogo-cancelar'; }), rot + ' dialogo: abre e o foco inicial esta no botao seguro (Cancelar)');
    ok(await page.evaluate(() => { const d = document.getElementById('gestao-dialogo'); return d.querySelector('h2').textContent.includes('Desativar') && d.querySelector('.gestao-dialogo__alvo').textContent === 'Carla Usuario (carla.usuario)' && d.getAttribute('aria-labelledby') === 'gestao-dialogo-titulo' && getComputedStyle(d).borderTopWidth === '3px'; }), rot + ' dialogo: titulo, alvo e borda grossa de acao destrutiva (sem vermelho)');
    ok(await page.evaluate(() => { const c = document.getElementById('gestao-dialogo-confirmar'); const x = document.getElementById('gestao-dialogo-cancelar'); const cc = getComputedStyle(c), cx = getComputedStyle(x); return c.classList.contains('gestao-botao--destrutivo-cheio') && cc.backgroundColor === 'rgb(58, 58, 58)' && cc.color === 'rgb(255, 255, 255)' && !!c.querySelector('svg') && c.textContent.trim() === 'Desativar' && x.classList.contains('gestao-botao--secundario') && cx.backgroundColor === 'rgb(255, 255, 255)' && cx.color === 'rgb(1, 121, 173)' && cx.borderTopColor === 'rgb(1, 121, 173)' && Math.round(c.getBoundingClientRect().height) >= 44; }), rot + ' dialogo: confirmar destrutivo cheio (#3A3A3A, texto branco, icone de alerta) e Cancelar secundario azul');
    await checagemGeral(page, rot + ' dialogo aberto', contadores);
    let presoOk = true;
    for (let i = 0; i < 7; i++) { await page.keyboard.press('Tab'); presoOk = presoOk && await page.evaluate(() => document.getElementById('gestao-dialogo').contains(document.activeElement)); }
    for (let i = 0; i < 3; i++) { await page.keyboard.down('Shift'); await page.keyboard.press('Tab'); await page.keyboard.up('Shift'); presoOk = presoOk && await page.evaluate(() => document.getElementById('gestao-dialogo').contains(document.activeElement)); }
    ok(presoOk, rot + ' dialogo: foco preso dentro do dialogo (Tab e Shift+Tab)');
    // foco sobre o botao destrutivo cheio (#3A3A3A): o anel fica FORA do botao, sobre o fundo branco do dialogo
    await page.focus('#gestao-dialogo-confirmar');
    const fd = await page.evaluate(() => { const lum = ([r, g, b]) => { const f = v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b); }; const num = s => /rgba?\(([^)]+)\)/.exec(s)[1].split(/[ ,\/]+/).filter(Boolean).slice(0, 3).map(Number); const razao = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); }; const c = document.getElementById('gestao-dialogo-confirmar'); const cs = getComputedStyle(c); const d = getComputedStyle(document.getElementById('gestao-dialogo')); return { w: parseFloat(cs.outlineWidth), a: razao(num(cs.outlineColor), num(d.backgroundColor)), b: razao(num(cs.outlineColor), num(cs.backgroundColor)) }; });
    ok(fd.w >= 3 && fd.a >= 3, rot + ' dialogo: foco no botao destrutivo cheio: anel fora do botao (offset 2px), anel/fundo branco do dialogo ' + fd.a.toFixed(2) + ':1 (>= 3:1); anel/botao #3A3A3A ' + fd.b.toFixed(2) + ':1 so informativo (separados pelo vao de 2px)');
    await page.screenshot({ path: path.join(CAPTURAS, 'dialogo-' + w + '.png') });
    await page.keyboard.press('Escape');
    ok(await page.evaluate(id => { const d = document.getElementById('gestao-dialogo'); const b = document.querySelector('tr[data-id-usuario="' + id + '"] button[value="desativar"]'); return !d.open && document.activeElement === b; }, ids['carla.usuario']), rot + ' dialogo: Esc fecha, nada e enviado e o foco volta ao botao de origem');
    ok((await page.evaluate(id => document.querySelector('tr[data-id-usuario="' + id + '"] .col-situacao').textContent, ids['carla.usuario'])).includes('Ativo'), rot + ' dialogo: usuario continua Ativo apos Esc');

    // ---- 403 para perfil usuario (pagina de erro com gestao-erro)
    if (completo) {
        // (feito mais abaixo, em outra pagina)
    }
    await page.close();
}

async function cenarioCompleto(browser, srv, contadores) {
    const w = 1366, h = 768, rot = '1366x768 fluxo';
    const { base, info } = srv;
    const ctx = browser.defaultBrowserContext();
    await ctx.overridePermissions(base, ['clipboard-read', 'clipboard-write', 'clipboard-sanitized-write']);
    const page = await novaPagina(browser, w, h, rot, contadores);
    const posts = [];
    page.on('request', r => { if (r.method() === 'POST') { posts.push(r.url()); } });
    await limparEstado(page, base);

    // ---- 403 como perfil usuario
    await entrar(page, base, 'otavio.usuario', info.senha);
    ok(page.url().endsWith('/gestao/conta.php'), rot + ' perfil usuario entra em Minha conta');
    ok(await page.evaluate(() => Array.from(document.querySelectorAll('.gestao-menu__link')).map(l => l.textContent.trim()).join(',') === 'Minha conta'), rot + ' perfil usuario ve so "Minha conta" no menu');
    await checagemGeral(page, rot + ' conta', contadores);
    let r = await page.goto(base + '/gestao/usuarios.php', { waitUntil: 'networkidle0' });
    ok(r.status() === 403, rot + ' erro: usuario em /gestao/usuarios.php => 403');
    const erro = await page.evaluate(() => { const m = document.querySelector('main.gestao-erro'); const cs = getComputedStyle(m); return m && { h1: getComputedStyle(m.querySelector('h1')).fontSize, borda: cs.borderTopWidth, logo: m.querySelector('img').naturalWidth > 0, link: m.querySelector('a').getAttribute('href') }; });
    ok(erro && erro.h1 === '24px' && erro.borda === '3px' && erro.logo && erro.link === '/gestao/', rot + ' erro 403: pagina gestao-erro estilizada, com logo e botao Voltar ao início (usuario logado)');
    ok(await page.evaluate(() => { const a = document.getElementById('gestao-erro-acao'); const cs = a && getComputedStyle(a); return a && a.getAttribute('data-acao') === 'inicio' && a.classList.contains('gestao-botao--primario') && cs.backgroundColor === 'rgb(1, 121, 173)' && cs.color === 'rgb(255, 255, 255)' && Math.round(a.getBoundingClientRect().height) >= 44 && a.textContent === 'Voltar ao início' && document.querySelector('h1').textContent === 'Acesso negado'; }), rot + ' erro 403 (perfil): #gestao-erro-acao data-acao=inicio como botao primario 44px');
    await checagemGeral(page, rot + ' erro 403', contadores);
    await page.screenshot({ path: path.join(CAPTURAS, 'erro-403.png') });

    // ---- Minha conta: requisitos e erro de servidor
    await irPara(page, base, '/gestao/conta.php');
    const req = async () => page.evaluate(() => Array.from(document.querySelectorAll('[data-requisito]')).map(li => li.getAttribute('data-requisito') + ':' + li.querySelector('.gestao-requisitos__estado').textContent));
    ok(JSON.stringify(await req()) === JSON.stringify(['minimo:', 'maximo:', 'login:']), rot + ' conta: requisitos iniciam NEUTROS (sem "Pendente:"/"Atendido:")');
    ok(await page.evaluate(() => Array.from(document.querySelectorAll('[data-requisito]')).map(li => li.textContent.trim()).join('|') === 'Mínimo de 12 caracteres|Máximo de 72 caracteres (acentos contam mais)|Diferente do login' && !document.querySelector('.gestao-requisitos__item--ok, .gestao-requisitos__item--falha') && document.getElementById('titulo-requisitos').textContent === 'A nova senha precisa ter:'), rot + ' conta: instrucoes com os textos do backend e sem estado marcado');
    await page.type('#senha_nova', 'curta');
    ok(JSON.stringify(await req()) === JSON.stringify(['minimo:Não atendido:', 'maximo:Atendido:', 'login:Atendido:']), rot + ' conta: "curta" => minimo Nao atendido, resto Atendido');
    await page.$eval('#senha_nova', i => { i.value = ''; i.dispatchEvent(new Event('input', { bubbles: true })); });
    ok(JSON.stringify(await req()) === JSON.stringify(['minimo:', 'maximo:', 'login:']), rot + ' conta: campo apagado volta ao estado neutro');
    await page.type('#senha_nova', 'curta');
    await page.$eval('#senha_nova', i => { i.value = ''; });
    await page.type('#senha_nova', 'otavio.usuario');
    ok(JSON.stringify(await req()) === JSON.stringify(['minimo:Atendido:', 'maximo:Atendido:', 'login:Não atendido:']), rot + ' conta: igual ao login => "Diferente do login" Nao atendido');
    await page.$eval('#senha_nova', i => { i.value = ''; });
    await page.type('#senha_nova', '€'.repeat(30));
    ok(JSON.stringify(await req()) === JSON.stringify(['minimo:Atendido:', 'maximo:Não atendido:', 'login:Atendido:']), rot + ' conta: 90 bytes (30 caracteres) => "Maximo de 72" Nao atendido');
    await page.$eval('#senha_nova', i => { i.value = ''; });
    await page.type('#senha_nova', 'Uma-Senha-Longa-Valida-7');
    ok(JSON.stringify(await req()) === JSON.stringify(['minimo:Atendido:', 'maximo:Atendido:', 'login:Atendido:']), rot + ' conta: senha valida => tudo Atendido');
    await page.screenshot({ path: path.join(CAPTURAS, 'conta.png') });
    await page.type('#senha_atual', 'errada-errada-1');
    await page.type('#senha_confirmacao', 'Outra-Coisa-Diferente-1');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('#btn-trocar-senha')]);
    const campoErro = await page.evaluate(() => { const s = document.querySelector('.gestao-campo__erro'); return s ? { icone: !!s.querySelector('svg'), role: s.getAttribute('role'), inv: !!document.querySelector('[aria-invalid="true"]'), borda: getComputedStyle(document.querySelector('.gestao-campo--erro .gestao-campo__entrada')).borderTopWidth, texto: s.textContent.trim().length } : null; });
    ok(campoErro && campoErro.icone && campoErro.role === 'alert' && campoErro.inv && campoErro.borda === '3px' && campoErro.texto > 0, rot + ' conta: erro de campo com icone, texto, aria-invalid e borda grossa');
    ok(await page.evaluate(() => {
        const ordem = id => { const c = document.getElementById(id); const e = document.getElementById('erro-' + id); const t = c.parentElement.querySelector('.gestao-mostrar'); const rc = c.getBoundingClientRect(), re = e.getBoundingClientRect(), rt = t.getBoundingClientRect(); const desc = (c.getAttribute('aria-describedby') || '').split(' '); return re.top >= rc.bottom - 1 && re.top < rt.top && re.bottom <= rt.top + 1 && re.top - rc.bottom < 16 && desc.includes('erro-' + id) && c.getAttribute('aria-invalid') === 'true' && Math.round(rt.height) >= 44; };
        const comErro = ['senha_atual', 'senha_nova', 'senha_confirmacao'].filter(id => document.getElementById('erro-' + id));
        return comErro.length > 0 && comErro.every(ordem);
    }), rot + ' conta: erro do campo IMEDIATAMENTE abaixo do campo, antes do toggle "Mostrar senha" (>= 44px), referenciado por aria-describedby');
    ok(await page.evaluate(() => { const c = document.getElementById('senha_nova'); const e = document.getElementById('erro-senha_nova'); const d = (c.getAttribute('aria-describedby') || '').split(' '); return !e || (d.includes('lista-requisitos') && d[0] === 'erro-senha_nova'); }), rot + ' conta: campo da nova senha descreve erro e requisitos por aria-describedby');
    await checagemGeral(page, rot + ' conta com erros', contadores);
    await page.screenshot({ path: path.join(CAPTURAS, 'conta-erro.png') });

    // ---- troca obrigatoria: layout reduzido sem menu
    await limparEstado(page, base);
    await entrar(page, base, 'davi.pendente', info.senha);
    ok(await page.evaluate(() => !document.querySelector('.gestao-sidebar') && !!document.getElementById('aviso-troca-obrigatoria') && !!document.getElementById('gestao-sair') && document.body.classList.contains('gestao--sem-menu')), rot + ' troca obrigatoria: layout reduzido (sem menu), aviso e botao Sair');
    ok(await page.evaluate(() => {
        const h1 = document.getElementById('gestao-titulo').textContent;
        const cartoes = document.querySelectorAll('.gestao-cartao');
        const f = document.getElementById('form-trocar-senha');
        const b = document.getElementById('btn-trocar-senha');
        const av = document.getElementById('aviso-troca-obrigatoria');
        return h1 === 'Troca de senha obrigatória' && document.title.startsWith('Troca de senha obrigatória') && cartoes.length === 1 && cartoes[0].id === 'conta-senha' && !document.getElementById('conta-perfil') && b.textContent.trim() === 'Salvar nova senha' && /obrigat/i.test(av.textContent) && f.getBoundingClientRect().top < innerHeight * 0.8 && getComputedStyle(cartoes[0]).borderTopWidth === '3px' && Math.round(b.getBoundingClientRect().height) >= 44;
    }), rot + ' troca obrigatoria: formulario e o foco unico (sem "Meu perfil"), titulo/aviso de troca obrigatoria, botao "Salvar nova senha"');
    await checagemGeral(page, rot + ' troca obrigatoria', contadores);
    await page.screenshot({ path: path.join(CAPTURAS, 'troca-obrigatoria.png') });

    // ---- admin: fluxos mutaveis
    await limparEstado(page, base);
    await entrar(page, base, 'ana.admin', info.senha);
    let ids = await idsPorLogin(page);

    // flash de erro persiste e se dispensa
    await irPara(page, base, '/gestao/usuarios.php?msg=ultimo_admin');
    ok(await page.evaluate(() => { const f = document.getElementById('gestao-flash'); return f && f.getAttribute('role') === 'alert' && f.getAttribute('aria-live') === 'polite' && !!f.querySelector('svg') && f.textContent.includes('Erro:') && !!document.getElementById('gestao-flash-fechar') && !location.search.includes('msg='); }), rot + ' flash erro: role=alert/aria-live polite, icone + "Erro:", botao Dispensar e URL limpa');
    await checagemGeral(page, rot + ' flash erro', contadores);
    await page.screenshot({ path: path.join(CAPTURAS, 'flash-erro.png') });
    // foco do Dispensar sobre #3A3A3A: anel branco (>= 3:1 calculado) e nao o azul (~2,35:1)
    await page.keyboard.press('Tab'); await page.focus('#gestao-flash-fechar');
    const fe = await page.evaluate(() => {
        const lum = ([r, g, b]) => { const f = v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b); };
        const num = s => /rgba?\(([^)]+)\)/.exec(s)[1].split(/[ ,\/]+/).filter(Boolean).slice(0, 3).map(Number);
        const razao = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };
        const a = document.activeElement; const cs = getComputedStyle(a); const fundo = num(getComputedStyle(document.getElementById('gestao-flash')).backgroundColor);
        return { id: a.id, w: parseFloat(cs.outlineWidth), s: cs.outlineStyle, cor: cs.outlineColor, r: razao(num(cs.outlineColor), fundo), azul: razao([1, 121, 173], fundo), fundo: fundo.join(',') };
    });
    ok(fe.id === 'gestao-flash-fechar' && fe.fundo === '58,58,58' && fe.s === 'solid' && fe.w >= 3 && fe.r >= 3, rot + ' flash erro: foco (' + fe.id + ') do Dispensar com anel ' + fe.cor + ' sobre #3A3A3A = ' + fe.r.toFixed(2) + ':1 (>= 3:1; o azul daria ' + fe.azul.toFixed(2) + ':1)');
    await page.screenshot({ path: path.join(CAPTURAS, 'flash-erro-foco.png') });
    await espera(7000);
    ok(await page.evaluate(() => !!document.getElementById('gestao-flash')), rot + ' flash erro: persiste apos 7 s');
    await page.click('#gestao-flash-fechar');
    ok(await page.evaluate(() => !document.getElementById('gestao-flash') && document.activeElement.id === 'conteudo'), rot + ' flash erro: Dispensar remove e devolve o foco ao conteudo');
    // msg fora do catalogo / com HTML nao e ecoada
    await irPara(page, base, '/gestao/usuarios.php?msg=%3Cimg%20src%3Dx%20onerror%3Dwindow.__xss%3D4%3E');
    ok(await page.evaluate(() => !document.getElementById('gestao-flash') && window.__xss === undefined), rot + ' flash: ?msg= com HTML fora do catalogo e ignorado');

    // desativar com confirmacao + flash de sucesso some em ~6 s
    ids = await idsPorLogin(page);
    await page.click('tr[data-id-usuario="' + ids['carla.usuario'] + '"] button[value="desativar"]');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('#gestao-dialogo-confirmar')]);
    ok(await page.evaluate(id => { const f = document.getElementById('gestao-flash'); return f && f.getAttribute('role') === 'status' && f.textContent.includes('desativado') && document.querySelector('tr[data-id-usuario="' + id + '"] .col-situacao').textContent.includes('Inativo') && document.querySelector('tr[data-id-usuario="' + id + '"]').classList.contains('gestao-tabela__linha--inativa'); }, ids['carla.usuario']), rot + ' desativar: confirmar envia o POST (botao acao preservado), flash de sucesso role=status e linha Inativa');
    await checagemGeral(page, rot + ' flash sucesso', contadores);
    await page.screenshot({ path: path.join(CAPTURAS, 'flash-sucesso.png') });
    const topoAntes = await page.evaluate(() => document.querySelector('#tabela-usuarios').getBoundingClientRect().top);
    await espera(7000);
    ok(await page.evaluate(() => !!document.getElementById('gestao-flash') && !!document.getElementById('gestao-flash-fechar')), rot + ' flash sucesso: PERSISTE apos 7 s (WCAG 2.2.1) ate "Dispensar"');
    ok(await page.evaluate(t => Math.abs(document.querySelector('#tabela-usuarios').getBoundingClientRect().top - t) < 1, topoAntes), rot + ' flash sucesso: o layout nao se desloca com o passar do tempo');
    ok(await page.evaluate(() => Math.round(document.getElementById('gestao-flash-fechar').getBoundingClientRect().height) >= 44), rot + ' flash: botao Dispensar com >= 44px');
    await page.click('#gestao-flash-fechar');
    ok(await page.evaluate(() => !document.getElementById('gestao-flash')), rot + ' flash sucesso: Dispensar remove');
    // info (neutro): icone + rotulo "Informacao:" + borda propria, nunca so cor; tambem persiste
    await irPara(page, base, '/gestao/usuarios.php?msg=sem_mudanca');
    ok(await page.evaluate(() => { const f = document.getElementById('gestao-flash'); const cs = f && getComputedStyle(f); return f && f.classList.contains('gestao-flash--info') && f.getAttribute('role') === 'status' && f.querySelector('.gestao-flash__tipo').textContent === 'Informação:' && !!f.querySelector('svg') && cs.borderTopStyle === 'dashed' && cs.borderLeftWidth === '6px' && cs.backgroundColor === 'rgb(255, 255, 255)'; }), rot + ' flash info: classe --info, icone + "Informação:" + borda pontilhada com barra lateral (nao so cor)');
    await checagemGeral(page, rot + ' flash info', contadores);
    await page.screenshot({ path: path.join(CAPTURAS, 'flash-info.png') });
    await espera(7000);
    ok(await page.evaluate(() => !!document.getElementById('gestao-flash')), rot + ' flash info: persiste apos 7 s');
    await page.click('#gestao-flash-fechar');
    ids = await idsPorLogin(page);
    // inativa: ativar nao pede confirmacao
    ok(await page.evaluate(id => { const b = document.querySelector('tr[data-id-usuario="' + id + '"] button[value="ativar"]'); return b && !b.hasAttribute('data-confirmar'); }, ids['carla.usuario']), rot + ' usuarios: inativo mostra "Ativar" (sem confirmacao)');

    // 403 de pagina expirada (CSRF invalido): botao do contrato novo, primario, "Recarregar a pagina"
    await page.evaluate(id => { const f = document.querySelector('tr[data-id-usuario="' + id + '"] button[value="ativar"]').form; f.querySelector('input[name=csrf_token]').value = 'token-invalido'; }, ids['carla.usuario']);
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('tr[data-id-usuario="' + ids['carla.usuario'] + '"] button[value="ativar"]')]);
    ok(await page.evaluate(() => { const a = document.getElementById('gestao-erro-acao'); const cs = a && getComputedStyle(a); return document.querySelector('h1').textContent === 'Página expirada' && a && a.getAttribute('data-acao') === 'recarregar' && a.classList.contains('gestao-botao--primario') && cs.backgroundColor === 'rgb(1, 121, 173)' && a.textContent === 'Recarregar a página' && /^\/gestao\/usuarios\.php$/.test(a.getAttribute('href')); }), rot + ' erro 403 (pagina expirada): titulo "Página expirada" e #gestao-erro-acao data-acao=recarregar como botao primario');
    await checagemGeral(page, rot + ' erro 403 expirada', contadores);
    await page.screenshot({ path: path.join(CAPTURAS, 'erro-403-expirada.png') });
    await irPara(page, base, '/gestao/usuarios.php');

    // redefinir senha: confirmacao, tela da senha, copiar (clipboard) e aviso
    await page.click('tr[data-id-usuario="' + ids['zeca.alvo'] + '"] button[value="redefinir-senha"]');
    ok(await page.evaluate(() => { const d = document.getElementById('gestao-dialogo'); return d.open && d.querySelector('h2').textContent === 'Redefinir senha' && !d.classList.contains('gestao-dialogo--destrutivo') && document.activeElement.id === 'gestao-dialogo-cancelar'; }), rot + ' redefinir: dialogo de confirmacao com foco no botao seguro');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('#gestao-dialogo-confirmar')]);
    ok(await page.evaluate(() => /Anote agora: esta senha n[ãa]o ser[áa] exibida de novo/.test(document.getElementById('senha-temporaria-aviso').textContent) && document.getElementById('senha-temporaria-aviso').getAttribute('role') === 'alert'), rot + ' senha temporaria: aviso "Anote agora: esta senha nao sera exibida de novo"');
    ok(await page.evaluate(() => { const p = document.getElementById('senha-temporaria-saida'); const v = document.getElementById('senha-temporaria-valor'); const b = document.getElementById('btn-voltar-usuarios'); return p && p.textContent === 'Ao sair desta tela, a senha não poderá ser vista de novo.' && p.parentElement === b.parentElement && getComputedStyle(v).userSelect === 'all' && document.getElementById('senha-temporaria-aviso').textContent.startsWith('Anote agora'); }), rot + ' senha temporaria: frase "Ao sair desta tela..." junto do botao Voltar, "Anote agora" mantido e senha com user-select: all');
    const senhaTemp = await page.$eval('#senha-temporaria-valor', c => c.textContent);
    ok(senhaTemp.length >= 12 && await page.evaluate(() => { const c = document.getElementById('senha-temporaria-valor'); const cs = getComputedStyle(c); return parseFloat(cs.fontSize) >= 20 && cs.borderTopWidth === '3px'; }), rot + ' senha temporaria: valor em destaque (fonte grande, borda grossa)');
    await checagemGeral(page, rot + ' senha temporaria', contadores);
    await page.screenshot({ path: path.join(CAPTURAS, 'senha-temporaria.png') });
    await page.bringToFront();
    await page.click('#btn-copiar-senha');
    await espera(300);
    const copiado = await page.evaluate(() => navigator.clipboard.readText());
    ok(copiado === senhaTemp && await page.$eval('#copiar-status', s => s.textContent) === 'Copiado.' && await page.$eval('#copiar-status', s => s.getAttribute('aria-live')) === 'polite', rot + ' senha temporaria: "Copiar" usa navigator.clipboard e confirma por texto (aria-live)');
    // fallback sem navigator.clipboard
    await page.evaluate(() => { Object.defineProperty(navigator, 'clipboard', { value: undefined, configurable: true }); document.getElementById('copiar-status').textContent = ''; });
    await page.click('#btn-copiar-senha');
    await espera(200);
    const st = await page.$eval('#copiar-status', s => s.textContent);
    ok((st === 'Copiado.' || st.startsWith('Não foi possível')) && await page.evaluate(() => getSelection().toString() === document.getElementById('senha-temporaria-valor').textContent), rot + ' senha temporaria: fallback (execCommand) seleciona a senha e informa o resultado (' + st + ')');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('#btn-voltar-usuarios')]);

    // formulario: erro de servidor (login duplicado)
    await irPara(page, base, '/gestao/usuario-form.php');
    ok(await page.evaluate(() => document.getElementById('gestao-titulo').textContent === 'Novo usuário' && document.querySelector('#usuario-form-cartao h2').textContent !== 'Novo usuário' && document.title.startsWith('Novo usuário')), rot + ' usuario-form: titulo do topo "Novo usuário" e titulo do cartao diferente (sem duplicar)');
    await checagemGeral(page, rot + ' usuario-form', contadores);
    await page.screenshot({ path: path.join(CAPTURAS, 'usuario-form.png') });
    await page.type('#login', 'ana.admin');
    await page.type('#nome', 'Nome Qualquer');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('#btn-salvar-usuario')]);
    ok(await page.evaluate(() => { const s = document.querySelector('.gestao-campo__erro'); return s && !!s.querySelector('svg') && !!document.querySelector('[aria-invalid="true"]'); }), rot + ' usuario-form: erro de validacao do servidor com icone/texto/aria-invalid');
    await checagemGeral(page, rot + ' usuario-form com erro', contadores);
    await page.screenshot({ path: path.join(CAPTURAS, 'usuario-form-erro.png') });

    // nome com HTML e rejeitado pelo servidor e volta LITERAL no campo (sem executar)
    await irPara(page, base, '/gestao/usuario-form.php');
    await page.type('#login', 'novo.usuario');
    await page.type('#nome', '"><img src=x onerror=window.__xss=5>');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('#btn-salvar-usuario')]);
    ok(await page.evaluate(() => document.getElementById('nome').value === '"><img src=x onerror=window.__xss=5>' && !document.querySelector('main img[src="x"]') && window.__xss === undefined && !!document.querySelector('#erro-nome')), rot + ' usuario-form: nome com HTML volta literal no campo, rejeitado pelo servidor, sem executar');
    // criar usuario valido + anti duplo clique
    await page.$eval('#nome', i => { i.value = ''; });
    await page.type('#nome', 'Nova Pessoa');
    const antes = posts.filter(u => u.includes('usuario-form.php')).length;
    const nav = page.waitForNavigation({ waitUntil: 'networkidle0' });
    const busy = await page.evaluate(() => { const f = document.getElementById('form-usuario'); f.requestSubmit(); f.requestSubmit(); f.requestSubmit(); return f.getAttribute('aria-busy'); });
    await nav;
    const depois = posts.filter(u => u.includes('usuario-form.php')).length;
    ok(busy === 'true' && depois - antes === 1, rot + ' anti duplo clique: 3 envios seguidos geram 1 POST e o formulario fica aria-busy (' + (depois - antes) + ' POST)');
    ok(await page.evaluate(() => document.getElementById('senha-temporaria-usuario').textContent.includes('Nova Pessoa (novo.usuario)') && document.querySelector('h2').textContent === 'Usuário criado'), rot + ' usuario criado: tela da senha temporaria exibida');
    await page.screenshot({ path: path.join(CAPTURAS, 'usuario-criado.png') });
    await page.close();
}

async function cenarioEstreito(browser, srv, contadores) {
    const rot = '1000x700';
    const page = await novaPagina(browser, 1000, 700, rot, contadores);
    await limparEstado(page, srv.base);
    await entrar(page, srv.base, 'ana.admin', srv.info.senha);
    const e = await checagemGeral(page, rot + ' usuarios', contadores);
    ok(e.sidebarW === 64 && await page.$eval('#gestao-recolher', b => b.getAttribute('aria-expanded')) === 'false', rot + ': < 1100px a sidebar inicia recolhida (64px)');
    const q = await page.evaluate(() => {
        const r = el => el.getBoundingClientRect();
        const out = { total: 0, quebrou: 0, falhas: [], altMax: 0 };
        for (const l of document.querySelectorAll('#tabela-usuarios tbody tr[data-id-usuario]')) {
            const d = l.querySelector('button[value="desativar"]'); if (!d) { continue; }
            out.total++;
            const g = d.closest('.gestao-acoes-grupo--destrutivo'), cs = getComputedStyle(g);
            const outros = Array.from(l.querySelectorAll('.col-acoes .gestao-botao')).filter(b => b !== d && b.getClientRects().length);
            const abaixo = outros.every(b => r(d).top >= r(b).bottom - 1);
            const naMesma = outros.some(b => Math.abs(r(b).top - r(d).top) < 4);
            if (abaixo && !naMesma) { out.quebrou++; if (Math.abs(r(d).left - r(outros[0]).left) > 12.5) { out.falhas.push('desalinhado'); } }
            if (!['Top', 'Right', 'Bottom', 'Left'].every(x => parseFloat(cs['border' + x + 'Width']) === 0)) { out.falhas.push('borda no grupo'); }
            if (new Set(outros.concat([d]).map(b => Math.round(r(b).top))).size > 3) { out.falhas.push('mais de 3 linhas'); }
            out.altMax = Math.max(out.altMax, Math.round(r(l).height));
        }
        return out;
    });
    ok(q.total > 0 && q.falhas.length === 0, rot + ' usuarios: grupo destrutivo sem borda/fio solto; quando quebra vai para a linha de baixo alinhado a esquerda (ate 3 linhas de botoes so na linha bloqueada, 4 acoes em ~255px; ' + q.quebrou + '/' + q.total + ' linhas quebradas, altura max ' + q.altMax + ')');
    await page.screenshot({ path: path.join(CAPTURAS, 'usuarios-1000.png') });
    await page.close();
}

async function cenarioSemJs(browser, srv, contadores) {
    const rot = 'sem JS';
    const page = await novaPagina(browser, 1366, 768, rot, contadores);
    await limparEstado(page, srv.base);
    await page.setJavaScriptEnabled(false);
    await entrar(page, srv.base, 'ana.admin', srv.info.senha);
    ok(page.url().endsWith('/gestao/usuarios.php'), rot + ': login por formulario funciona sem JS');
    ok(await page.evaluate(() => !document.getElementById('gestao-recolher') && !document.querySelector('.gestao-mostrar') && !!document.querySelector('.gestao-sidebar') && document.querySelector('.gestao-sidebar').getBoundingClientRect().width === 248), rot + ': pagina completa sem JS (sidebar 248px, sem controles que dependem de JS)');
    const e = await page.evaluate(estadoPagina);
    ok(e.scrollW <= e.clientW, rot + ': sem overflow horizontal');
    const ids = await idsPorLogin(page);
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('tr[data-id-usuario="' + ids['xss.dois'] + '"] button[value="desativar"]')]);
    ok(await page.evaluate(id => document.getElementById('gestao-flash') && document.getElementById('gestao-flash').textContent.includes('desativado') && document.querySelector('tr[data-id-usuario="' + id + '"] .col-situacao').textContent.includes('Inativo'), ids['xss.dois']), rot + ': "Desativar" e um formulario com CSRF e funciona sem JS (sem confirmacao, o servidor valida)');
    ok(await page.evaluate(() => !!document.getElementById('gestao-flash') && !document.getElementById('gestao-flash-fechar')), rot + ': flash aparece sem JS');
    await page.screenshot({ path: path.join(CAPTURAS, 'sem-js.png') });
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('#gestao-sair')]);
    ok(page.url().endsWith('/gestao/login.php'), rot + ': logout por formulario funciona sem JS');
    await page.close();
}

/* ============================================================================================
 * Gestao Totem F2 (totens): lista, novo totem, URL do totem, dialogos, copiar, sem JS, vazio.
 * Semente do servidor: GUICHE-04 e DOCA-02 (ativos), BALCAO-01 (atendimento recente), BALCAO-09
 * (inativo), ABCDEFGHIJKLMNOPQRSTUVWX (nome 24 + empresa 16: URL mais longa), RECEPCAO-01 (legado)
 * e um legado/empresa com nome hostil (<img onerror>). TOTEM_URL_BASE so no processo do servidor.
 * ============================================================================================ */
const HOSTIL = '<img src=x onerror=window.__xss=7>';

const idsTotem = page => page.evaluate(() => {
    const m = {};
    document.querySelectorAll('tr[data-id-totem]').forEach(tr => { m[tr.querySelector('.col-nome').textContent.trim()] = tr.getAttribute('data-id-totem'); });
    return m;
});

const sel = id => 'tr[data-id-totem="' + id + '"]';

async function esvaziarTotens(srv) {
    const pronto = new Promise((res, rej) => {
        const t = setTimeout(() => rej(new Error('servidor nao confirmou o esvaziamento')), 20000);
        const h = d => { if (d.toString().includes('ESVAZIADO')) { clearTimeout(t); srv.proc.stdout.off('data', h); res(); } };
        srv.proc.stdout.on('data', h);
    });
    srv.proc.stdin.write('esvaziar\n');
    await pronto;
}

const estadoDialogo = page => page.evaluate(() => {
    const d = document.getElementById('gestao-dialogo');
    const vis = el => !!el && el.getClientRects().length > 0;
    return {
        aberto: d.open, foco: document.activeElement.id,
        titulo: d.querySelector('h2').textContent.trim(),
        texto: d.querySelector('.gestao-dialogo__texto').textContent,
        alvo: d.querySelector('.gestao-dialogo__alvo').textContent,
        nomeVisivel: vis(document.getElementById('gestao-dialogo-nome')),
        caixaVisivel: vis(document.getElementById('gestao-dialogo-atendimento')),
        confirmarDesab: document.getElementById('gestao-dialogo-confirmar').disabled,
        estado: document.getElementById('gestao-dialogo-nome-estado').textContent,
        caixaTexto: document.querySelector('.gestao-dialogo__caixa-texto').textContent,
    };
});

async function limparCampo(page, seletor) {
    await page.$eval(seletor, i => { i.value = ''; i.dispatchEvent(new Event('input', { bubbles: true })); });
}

/** Checagens estaticas das 3 telas em um viewport (lista, novo totem, URL). */
async function cenarioTotensViewport(browser, srv, w, h, contadores) {
    const rot = w + 'x' + h + ' totens';
    const { base, info } = srv;
    const page = await novaPagina(browser, w, h, rot, contadores);
    await limparEstado(page, base);
    await entrar(page, base, 'ana.admin', info.senha);
    await irPara(page, base, '/gestao/totens.php');

    // ---------------- lista ----------------
    await checagemGeral(page, rot + ' lista', contadores);
    ok(await page.evaluate(() => { const a = document.querySelector('.gestao-menu__link[aria-current="page"]'); return a && a.textContent.trim() === 'Totens' && document.getElementById('gestao-titulo').textContent === 'Totens' && document.title.startsWith('Totens'); }), rot + ' lista: menu "Totens" atual e titulo "Totens"');
    const ids = await idsTotem(page);
    ok(Object.keys(ids).length === 7 && !!ids['GUICHE-04'] && !!ids['DOCA-02'] && !!ids['BALCAO-01'] && !!ids['BALCAO-09'] && !!ids['RECEPCAO-01'] && !!ids['ABCDEFGHIJKLMNOPQRSTUVWX'] && !!ids[HOSTIL], rot + ' lista: 7 totens semeados (ativos, inativo, legado, atendimento recente, URL longa, nome hostil)');
    const t = await page.evaluate(() => {
        const r = el => el.getBoundingClientRect();
        const tab = document.getElementById('tabela-totens');
        const linhas = {};
        for (const tr of tab.tBodies[0].querySelectorAll('tr[data-id-totem]')) {
            const nome = tr.querySelector('.col-nome').textContent.trim();
            const code = tr.querySelector('code.gestao-totem-url');
            const td = tr.querySelector('.col-url');
            const copiar = td.querySelector('.gestao-url-copiar button');
            const bs = Array.from(tr.querySelectorAll('.col-acoes .gestao-botao')).filter(b => b.getClientRects().length);
            let gapMin = 999;
            for (let i = 0; i < bs.length; i++) for (let j = i + 1; j < bs.length; j++) {
                const a = r(bs[i]), b = r(bs[j]);
                if (Math.abs(a.top - b.top) < 4) { gapMin = Math.min(gapMin, Math.round(b.left - a.right)); } else if (b.top >= a.bottom - 1) { gapMin = Math.min(gapMin, Math.round(b.top - a.bottom)); }
            }
            const d = tr.querySelector('button[value="desativar"]');
            const cs = getComputedStyle(tr.querySelector('.col-nome'));
            const cd = getComputedStyle(tr.querySelector('td:first-child'));
            const form = tr.querySelector('form.gestao-form-regerar');
            const lab = form && form.querySelector('label.gestao-confirmacao-inline');
            const inp = form && form.querySelector('input[name="nome_confirmacao"]');
            const cx = tr.querySelector('input[name="confirmar_atendimento"]');
            linhas[nome] = {
                id: tr.getAttribute('data-id-totem'), altura: Math.round(r(tr).height), inativa: tr.classList.contains('gestao-tabela__linha--inativa'),
                situacao: tr.querySelector('.col-situacao').textContent.replace(/\s+/g, ' ').trim(), situacaoIcone: !!tr.querySelector('.col-situacao svg'),
                nomeItalico: cs.fontStyle === 'italic', bordaEsq: cd.borderLeftStyle + ' ' + cd.borderLeftWidth,
                url: code.textContent, urlFonte: getComputedStyle(code).fontFamily, urlCabe: code.scrollWidth <= code.clientWidth + 1 && r(code).right <= r(td).right + 1 && r(code).left >= r(td).left - 1,
                urlNota: (td.querySelector('.gestao-situacao__nota') || {}).textContent || '',
                copiar: { id: copiar.id, tipo: copiar.type, aria: copiar.getAttribute('aria-label'), altura: Math.round(r(copiar).height), texto: copiar.textContent.trim(), semSobrepor: r(copiar).left >= r(code).right - 1 || r(copiar).top >= r(code).bottom - 1, alternativa: !!td.querySelector('[data-copiar-alternativa]'), dataDe: td.querySelector('.gestao-url-copiar').getAttribute('data-copiar-de') === code.id, statusId: (td.querySelector('.gestao-copiar-status') || {}).id || '', urlFonte: parseFloat(getComputedStyle(code).fontSize), quebra: getComputedStyle(code).overflowWrap },
                destaque: (() => { const n = tr.querySelector('.col-situacao .gestao-situacao__nota--destaque'); return n && { icone: !!n.querySelector('svg'), peso: getComputedStyle(n).fontWeight, cor: getComputedStyle(n).color }; })(),
                botoes: bs.map(b => b.textContent.trim()), alturas: bs.map(b => Math.round(r(b).height)), gapMin,
                desativarUltimo: !d || bs[bs.length - 1] === d,
                desativarBorda: d ? [getComputedStyle(d).borderTopWidth, getComputedStyle(d).borderTopColor, !!d.querySelector('svg')].join('|') : null,
                regerar: form ? { campos: Array.from(form.querySelectorAll('input[type=hidden]')).map(i => i.name).join(','), requerido: !!inp.required, rotuloOculto: getComputedStyle(lab).display === 'none', entradaOculta: getComputedStyle(inp).display === 'none', destrutivo: form.querySelector('button').getAttribute('data-confirmar-destrutivo') } : null,
                caixa: cx ? { oculta: getComputedStyle(cx.closest('label')).display === 'none', requerida: cx.required } : null,
                ativarSemConfirmar: tr.querySelector('button[value="ativar"]') ? !tr.querySelector('button[value="ativar"]').hasAttribute('data-confirmar') : null,
            };
        }
        return {
            cabecalhos: Array.from(tab.tHead.rows[0].cells).map(c => c.textContent.trim()),
            wrap: { sw: document.querySelector('.gestao-tabela-wrap').scrollWidth, cw: document.querySelector('.gestao-tabela-wrap').clientWidth },
            linhas, imgs: tab.querySelectorAll('img, script').length, novo: (() => { const a = document.getElementById('btn-novo-totem'); return a && { href: a.getAttribute('href'), h: Math.round(r(a).height), texto: a.textContent.trim() }; })(),
        };
    });
    const L = t.linhas;
    ok(JSON.stringify(t.cabecalhos) === JSON.stringify(['Nome', 'Empresa', 'Situação', 'Criado em', 'URL do quiosque', 'Ações']), rot + ' lista: cabecalhos Nome, Empresa, Situacao, Criado em, URL do quiosque, Acoes');
    ok(t.novo && t.novo.href === '/gestao/totem-form.php' && t.novo.h >= 44 && t.novo.texto === 'Novo totem', rot + ' lista: botao "Novo totem" com 44px');
    ok(t.wrap.sw <= t.wrap.cw + 1, rot + ' lista: tabela cabe na largura util sem rolagem interna (' + t.wrap.sw + '/' + t.wrap.cw + ')');
    ok(L['GUICHE-04'].situacao.startsWith('Ativo') && L['GUICHE-04'].situacaoIcone && L['BALCAO-09'].situacao === 'Inativo' && L['BALCAO-09'].situacaoIcone, rot + ' lista: situacao "Ativo"/"Inativo" em texto + icone');
    ok(L['BALCAO-09'].inativa && L['BALCAO-09'].nomeItalico && L['BALCAO-09'].bordaEsq === 'dotted 5px' && !L['GUICHE-04'].inativa && !L['GUICHE-04'].nomeItalico, rot + ' lista: totem inativo distinto por texto "Inativo", italico e barra pontilhada (nao so cor)');
    ok(L['BALCAO-01'].situacao.includes('Atendimento em andamento') && !L['GUICHE-04'].situacao.includes('Atendimento'), rot + ' lista: nota "Atendimento em andamento" so no totem com atendimento recente');
    ok(L['BALCAO-01'].destaque && L['BALCAO-01'].destaque.icone && parseInt(L['BALCAO-01'].destaque.peso, 10) >= 700 && L['BALCAO-01'].destaque.cor === 'rgb(58, 58, 58)' && !L['GUICHE-04'].destaque, rot + ' lista: atendimento em andamento destacado com icone + negrito (sem cor nova)');
    ok(/^https:\/\/totem\.udlog\.online\/totem\/\?totem=GUICHE-04-MAUAI-[A-Z2-7]{16}$/.test(L['GUICHE-04'].url) && L['RECEPCAO-01'].url === 'https://totem.udlog.online/totem/?totem=RECEPCAO-01' && /-MAUAII-[A-Z2-7]{16}$/.test(L['DOCA-02'].url), rot + ' lista: URL em code no formato BASE?totem=NOME-EMPRESA-HASH16 (legado com a URL antiga)');
    ok(L['RECEPCAO-01'].urlNota === 'Endereço fixo; continua valendo até ser regerado.' && L[HOSTIL].urlNota === 'Endereço fixo; continua valendo até ser regerado.' && L['GUICHE-04'].urlNota === '' && L['BALCAO-09'].urlNota === '', rot + ' lista: nota "Endereço fixo; continua valendo até ser regerado." so nos legados');
    ok(Object.values(L).every(l => l.urlCabe && /Consolas|Courier|monospace/.test(l.urlFonte)), rot + ' lista: URL (monoespacada) quebra linha sem estourar a celula, inclusive a mais longa (' + L['ABCDEFGHIJKLMNOPQRSTUVWX'].url.length + ' caracteres)');
    ok(Object.values(L).every(l => l.copiar.id === 'btn-copiar-url-' + l.id && l.copiar.tipo === 'button' && l.copiar.altura >= 44 && l.copiar.texto === 'Copiar URL' && /^Copiar a URL de /.test(l.copiar.aria) && l.copiar.semSobrepor && !l.copiar.alternativa && l.copiar.dataDe), rot + ' lista: botao "Copiar URL" por linha (data-copiar-de/id, aria-label com o nome), 44px, sem sobrepor a URL, link alternativo trocado pelo JS');
    ok(Object.values(L).every(l => l.copiar.urlFonte >= 14 && l.copiar.quebra === 'anywhere') && new Set(Object.values(L).map(l => l.copiar.statusId)).size === Object.keys(L).length, rot + ' lista: URL com fonte >= 14px e overflow-wrap:anywhere; ids do status de copia unicos');
    ok(Object.values(L).every(l => l.alturas.every(a => a >= 44) && l.gapMin >= 12), rot + ' lista: botoes de acao com altura >= 44px e espaco >= 12px entre acoes');
    ok(Object.values(L).every(l => l.desativarUltimo) && Object.values(L).filter(l => l.desativarBorda).every(l => l.desativarBorda === '3px|rgb(58, 58, 58)|true'), rot + ' lista: "Desativar" e o ultimo botao, borda 3px #3A3A3A + icone (sem cor de perigo)');
    ok(Object.values(L).every(l => l.botoes.includes('Ativar') === l.inativa && l.botoes.includes('Desativar') === !l.inativa && l.botoes.includes('Regerar URL')) && L['BALCAO-09'].ativarSemConfirmar === true, rot + ' lista: inativo mostra "Ativar" (sem dialogo) e nao "Desativar"; ativo mostra "Desativar"; todos "Regerar URL"');
    ok(Object.values(L).every(l => l.regerar && l.regerar.campos === 'csrf_token,id_totem,versao_url' && l.regerar.requerido && l.regerar.rotuloOculto && l.regerar.entradaOculta && l.regerar.destrutivo === '1'), rot + ' lista: formulario "Regerar URL" (csrf, id_totem, versao_url, nome_confirmacao required) com os campos inline ocultos pelo JS e dialogo destrutivo');
    ok(L['BALCAO-01'].caixa && L['BALCAO-01'].caixa.oculta && L['BALCAO-01'].caixa.requerida && !L['GUICHE-04'].caixa, rot + ' lista: caixa "Desativar mesmo assim" so no totem com atendimento recente (obrigatoria; oculta pelo JS)');
    if (w >= 1366) {
        const tipicas = ['GUICHE-04', 'DOCA-02', 'BALCAO-09'].map(n => L[n].altura);
        ok(tipicas.every(a => a <= 100), rot + ' lista: densidade, linhas tipicas <= 100px (botao Copiar URL em linha propria, URL a 14px) (' + tipicas.join(',') + ')');
        ok(L['RECEPCAO-01'].altura <= 125 && L['ABCDEFGHIJKLMNOPQRSTUVWX'].altura <= 125 && L['BALCAO-01'].altura <= 125, rot + ' lista: linhas com nota/URL maxima/atendimento ate 125px (' + [L['RECEPCAO-01'].altura, L['ABCDEFGHIJKLMNOPQRSTUVWX'].altura, L['BALCAO-01'].altura].join(',') + ')');
    }
    ok(t.imgs === 0 && await page.evaluate(h => Array.from(document.querySelectorAll('.col-empresa')).some(c => c.textContent === '<img src=x onerror=window.__xss=8>') && Array.from(document.querySelectorAll('.col-nome')).some(c => c.textContent.trim() === h), HOSTIL), rot + ' lista: nome do totem e empresa hostis aparecem LITERAIS (sem img/script injetado)');
    await botoesSolidos(page, rot + ' lista');
    await page.evaluate(() => { document.activeElement && document.activeElement.blur(); window.scrollTo(0, 0); });
    await focoVisivel(page, rot + ' lista', 24);
    await page.evaluate(() => { document.activeElement && document.activeElement.blur(); window.scrollTo(0, 0); });
    await page.screenshot({ path: path.join(CAPTURAS, 'totens-' + w + '.png') });

    // ---------------- novo totem ----------------
    await irPara(page, base, '/gestao/totem-form.php');
    await checagemGeral(page, rot + ' novo totem', contadores);
    const f = await page.evaluate(() => {
        const r = el => el.getBoundingClientRect();
        const sl = document.getElementById('id_empresa'), nome = document.getElementById('nome');
        const previa = document.querySelector('#ajuda-url-totem .gestao-totem-previa');
        return {
            titulo: document.getElementById('gestao-titulo').textContent, cartao: document.querySelector('#totem-form-cartao h2').textContent,
            opcoes: Array.from(sl.options).map(o => o.textContent), campos: Array.from(document.querySelectorAll('#form-totem input:not([type=hidden]), #form-totem select')).map(c => c.name).join(','),
            alturas: [r(sl).height, r(nome).height, r(document.getElementById('btn-salvar-totem')).height, r(document.getElementById('btn-cancelar-totem')).height].map(Math.round),
            rotulos: Array.from(document.querySelectorAll('#form-totem label')).map(l => l.textContent.trim() + '>' + l.getAttribute('for')).join('|'),
            ajuda: document.getElementById('ajuda-url-totem').textContent.replace(/\s+/g, ' ').trim(), previa: previa && previa.textContent, previaBorda: previa && getComputedStyle(previa).borderTopStyle, previaFonte: previa && getComputedStyle(previa).fontFamily,
            salvar: document.getElementById('btn-salvar-totem').textContent.trim(), cancelar: document.getElementById('btn-cancelar-totem').getAttribute('href'), gap: Math.round(r(document.getElementById('btn-cancelar-totem')).left - r(document.getElementById('btn-salvar-totem')).right),
        };
    });
    ok(f.titulo === 'Novo totem' && f.cartao !== 'Novo totem' && f.campos === 'id_empresa,nome', rot + ' novo totem: titulo do topo e SOMENTE 2 campos (empresa + nome)');
    ok(f.opcoes.length === 5 && f.opcoes[0] === 'Escolha a empresa' && f.opcoes.includes('Maua I') && f.opcoes.includes('<img src=x onerror=window.__xss=8>') && !f.opcoes.includes('Empresa Inativa'), rot + ' novo totem: select so com empresas ativas (nome hostil da empresa literal)');
    ok(f.rotulos === 'Empresa>id_empresa|Nome do totem>nome', rot + ' novo totem: rotulos associados aos campos');
    ok(await page.evaluate(() => document.getElementById('nome').maxLength === 24), rot + ' novo totem: maxlength do nome = 24 (igual ao limite do servidor)');
    ok(f.alturas.every(a => a >= 44) && f.gap >= 12 && f.salvar === 'Criar totem' && f.cancelar === '/gestao/totens.php', rot + ' novo totem: campos e botoes com >= 44px, gap >= 12px, "Criar totem" e Cancelar (' + f.alturas.join(',') + ')');
    ok(f.previa === 'NOME-EMPRESA-<16 caracteres gerados ao salvar>' && f.previaBorda === 'dashed' && /Consolas|Courier|monospace/.test(f.previaFonte) && f.ajuda.includes('A URL do quiosque terá este formato'), rot + ' novo totem: dica com a previa textual NOME-EMPRESA-<16 caracteres gerados ao salvar> (sem hash inventado)');
    await botoesSolidos(page, rot + ' novo totem');
    if (w === 1366) { await page.evaluate(() => { document.activeElement && document.activeElement.blur(); }); await page.screenshot({ path: path.join(CAPTURAS, 'totem-form.png') }); }
    await page.focus('#id_empresa');
    await focoVisivel(page, rot + ' novo totem', 4);

    // ---------------- URL do totem (ainda NAO regerado) ----------------
    await irPara(page, base, '/gestao/totem-url.php?id=' + ids['GUICHE-04']);
    await checagemGeral(page, rot + ' URL', contadores);
    const u = await page.evaluate(() => {
        const r = el => el.getBoundingClientRect();
        const c = document.getElementById('totem-url-valor'), cs = getComputedStyle(c), cartao = document.getElementById('totem-url-cartao');
        const b = document.getElementById('btn-copiar-url');
        return {
            titulo: document.getElementById('gestao-titulo').textContent, nome: document.getElementById('totem-url-nome').textContent, empresa: document.getElementById('totem-url-empresa').textContent, situacao: document.getElementById('totem-url-situacao').textContent.trim(),
            url: c.textContent, fonte: cs.fontSize, familia: cs.fontFamily, borda: cs.borderTopWidth + ' ' + cs.borderTopColor, sel: cs.userSelect, larg: r(c).width, cartaoL: r(cartao).width,
            cabe: c.scrollWidth <= c.clientWidth + 1 && r(c).right <= r(cartao).right,
            botao: b && { texto: b.textContent.trim(), h: Math.round(r(b).height), tipo: b.type, antigo: !!document.getElementById('btn-copiar-senha') },
            aviso: document.getElementById('totem-url-aviso').textContent.trim(), avisoBorda: getComputedStyle(document.getElementById('totem-url-aviso')).borderTopWidth, avisoIcone: !!document.querySelector('#totem-url-aviso svg'),
            regerada: !!document.getElementById('totem-url-regerada'), inativo: !!document.getElementById('totem-url-inativo'),
            voltar: (() => { const a = document.getElementById('btn-voltar-totens'); return a && [a.getAttribute('href'), Math.round(r(a).height), a.textContent.trim()].join('|'); })(),
            dd: document.querySelector('dd[data-copiar-de="totem-url-valor"]') !== null && document.querySelector('dd[data-copiar-de]').contains(c),
        };
    });
    ok(u.titulo === 'URL do totem' && u.nome === 'GUICHE-04' && u.empresa === 'Maua I' && u.situacao === 'Ativo' && u.dd, rot + ' URL: titulo "URL do totem", totem, empresa e situacao');
    ok(/^https:\/\/totem\.udlog\.online\/totem\/\?totem=GUICHE-04-MAUAI-[A-Z2-7]{16}$/.test(u.url) && parseFloat(u.fonte) >= 20 && u.borda === '3px rgb(58, 58, 58)' && u.sel === 'all' && /Consolas|Courier|monospace/.test(u.familia) && u.cabe && u.larg >= u.cartaoL * 0.8, rot + ' URL: caixa grande em destaque (fonte monoespacada ' + u.fonte + ', borda 3px, user-select all = um clique seleciona tudo, ocupa a largura do cartao)');
    ok(u.botao && u.botao.texto === 'Copiar URL' && u.botao.h >= 44 && u.botao.tipo === 'button' && !u.botao.antigo, rot + ' URL: botao #btn-copiar-url "Copiar URL" com 44px (JS generico com rotulo/id proprios)');
    ok(u.aviso === 'Guarde esta URL: ela é a chave de acesso do totem; qualquer pessoa com ela abre o totem.' && u.avisoBorda === '3px' && u.avisoIcone, rot + ' URL: aviso "Guarde esta URL..." em caixa com icone e borda 3px');
    ok(await page.evaluate(() => { const a = document.getElementById('totem-url-ajuda'); const c = document.getElementById('totem-url-valor'); return a && !!a.querySelector('svg') && a.textContent.trim() === 'Não envie esta URL por e-mail ou mensagem; use-a apenas no mini PC do totem.' && getComputedStyle(c).overflowWrap === 'anywhere' && getComputedStyle(c).wordBreak === 'normal'; }), rot + ' URL: linha de ajuda curta sobre o segredo da URL e quebra com overflow-wrap:anywhere');
    ok(!u.regerada && !u.inativo && u.voltar === '/gestao/totens.php|44|Voltar para totens', rot + ' URL: totem nunca regerado nao mostra o aviso de URL antiga; botao "Voltar para totens" com 44px');
    await botoesSolidos(page, rot + ' URL');
    await page.focus('#btn-copiar-url');
    await focoVisivel(page, rot + ' URL', 3);
    if (w === 1366) {
        await page.evaluate(() => { document.activeElement && document.activeElement.blur(); window.scrollTo(0, 0); });
        await page.screenshot({ path: path.join(CAPTURAS, 'totem-url.png') });
        // totem inativo: aviso proprio
        await irPara(page, base, '/gestao/totem-url.php?id=' + ids['BALCAO-09']);
        ok(await page.evaluate(() => { const a = document.getElementById('totem-url-inativo'); return a && !!a.querySelector('svg') && a.textContent.includes('desativado') && document.getElementById('totem-url-situacao').textContent.trim() === 'Inativo'; }), rot + ' URL: totem inativo mostra "Inativo" e o aviso de que a URL nao abre');
        await checagemGeral(page, rot + ' URL inativo', contadores);
        // id inexistente: volta para a lista com mensagem
        await irPara(page, base, '/gestao/totem-url.php?id=999999');
        ok(page.url().endsWith('/gestao/totens.php') && await page.evaluate(() => document.getElementById('gestao-flash').textContent.includes('Totem não encontrado')), rot + ' URL: id inexistente volta para a lista com "Totem nao encontrado"');
    }
    await page.close();
}

/** Fluxos com JS (1366x768): dialogos, regerar com nome, desativar com atendimento, copiar URL, criar totem. */
async function cenarioTotensFluxo(browser, srv, contadores) {
    const w = 1366, h = 768, rot = '1366x768 totens-fluxo';
    const { base, info } = srv;
    const ctx = browser.defaultBrowserContext();
    await ctx.overridePermissions(base, ['clipboard-read', 'clipboard-write', 'clipboard-sanitized-write']);
    const page = await novaPagina(browser, w, h, rot, contadores);
    const posts = [];
    page.on('request', r => { if (r.method() === 'POST') { posts.push(r.url()); } });
    const postsTotens = () => posts.filter(u => u.includes('/gestao/totens.php')).length;
    await limparEstado(page, base);
    await entrar(page, base, 'ana.admin', info.senha);
    await irPara(page, base, '/gestao/totens.php');
    const ids = await idsTotem(page);
    const urlDe = (pg, id) => pg.$eval(sel(id) + ' code.gestao-totem-url', c => c.textContent);

    // ---- dialogo "Desativar" sem atendimento (DOCA-02): efeito imediato, sem extras
    await page.click(sel(ids['DOCA-02']) + ' button[value="desativar"]');
    let d = await estadoDialogo(page);
    ok(d.aberto && d.foco === 'gestao-dialogo-cancelar' && d.titulo === 'Desativar totem' && d.alvo === 'DOCA-02' && /agora/.test(d.texto) && !/interrompido/.test(d.texto) && !d.nomeVisivel && !d.caixaVisivel && !d.confirmarDesab, rot + ' desativar: dialogo com efeito imediato ("agora"), alvo DOCA-02, foco inicial em Cancelar, sem confirmacao extra');
    ok(await page.evaluate(() => { const c = document.getElementById('gestao-dialogo-confirmar'), cs = getComputedStyle(c); return c.classList.contains('gestao-botao--destrutivo-cheio') && cs.backgroundColor === 'rgb(58, 58, 58)' && cs.color === 'rgb(255, 255, 255)' && !!c.querySelector('svg') && c.textContent.trim() === 'Desativar' && getComputedStyle(document.getElementById('gestao-dialogo')).borderTopWidth === '3px'; }), rot + ' desativar: confirmar no estilo destrutivo cheio (#3A3A3A, texto branco, icone) e dialogo com borda 3px');
    await checagemGeral(page, rot + ' dialogo desativar', contadores);
    await page.keyboard.press('Escape');
    ok(await page.evaluate(id => !document.getElementById('gestao-dialogo').open && document.activeElement === document.querySelector('tr[data-id-totem="' + id + '"] button[value="desativar"]'), ids['DOCA-02']) && postsTotens() === 0, rot + ' desativar: Esc fecha, nada e enviado e o foco volta ao botao de origem');

    // ---- dialogo "Desativar" com atendimento recente (BALCAO-01): caixa obrigatoria + aviso
    await page.click(sel(ids['BALCAO-01']) + ' button[value="desativar"]');
    d = await estadoDialogo(page);
    ok(d.aberto && d.foco === 'gestao-dialogo-cancelar' && d.caixaVisivel && !d.nomeVisivel && d.confirmarDesab && /atendimento em andamento será interrompido/.test(d.texto) && /Há atendimento em andamento nos últimos 30 minutos\. Desativar mesmo assim\./.test(d.caixaTexto), rot + ' desativar com atendimento: aviso de interrupcao, caixa obrigatoria no dialogo e "Desativar" desabilitado ate marcar');
    ok(await page.evaluate(() => { const c = document.getElementById('gestao-dialogo-confirmar'), cs = getComputedStyle(c); const cx = document.getElementById('gestao-dialogo-atendimento'); return cs.borderTopStyle === 'dashed' && cs.backgroundColor === 'rgb(255, 255, 255)' && Math.round(cx.closest('label').getBoundingClientRect().height) >= 44 && Math.round(cx.getBoundingClientRect().width) >= 24; }), rot + ' desativar com atendimento: botao desabilitado distinto (tracejado, nao so cor) e area da caixa >= 44px');
    await page.screenshot({ path: path.join(CAPTURAS, 'dialogo-desativar.png') });
    await checagemGeral(page, rot + ' dialogo desativar atendimento', contadores);
    await page.click('#gestao-dialogo-atendimento');
    ok(!(await estadoDialogo(page)).confirmarDesab, rot + ' desativar com atendimento: marcar a caixa habilita "Desativar"');
    await page.click('#gestao-dialogo-atendimento');
    ok((await estadoDialogo(page)).confirmarDesab, rot + ' desativar com atendimento: desmarcar desabilita de novo');
    await page.click('#gestao-dialogo-cancelar');
    ok(await page.evaluate(id => !document.getElementById('gestao-dialogo').open && !document.querySelector('tr[data-id-totem="' + id + '"] input[name="confirmar_atendimento"]').checked, ids['BALCAO-01']) && postsTotens() === 0, rot + ' desativar com atendimento: Cancelar nao marca nada no formulario nem envia');
    await page.click(sel(ids['BALCAO-01']) + ' button[value="desativar"]');
    await page.click('#gestao-dialogo-atendimento');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('#gestao-dialogo-confirmar')]);
    ok(await page.evaluate(id => { const f = document.getElementById('gestao-flash'); const tr = document.querySelector('tr[data-id-totem="' + id + '"]'); return f && f.getAttribute('role') === 'status' && f.textContent.includes('Totem desativado') && tr.classList.contains('gestao-tabela__linha--inativa') && tr.querySelector('.col-situacao').textContent.trim().startsWith('Inativo'); }, ids['BALCAO-01']) && postsTotens() === 1, rot + ' desativar com atendimento: confirmar (caixa marcada) envia 1 POST, flash de sucesso e linha Inativa');
    await checagemGeral(page, rot + ' lista apos desativar', contadores);
    // reativar: sem dialogo
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click(sel(ids['BALCAO-01']) + ' button[value="ativar"]')]);
    ok(await page.evaluate(id => document.getElementById('gestao-flash').textContent.includes('Totem ativado') && !document.querySelector('tr[data-id-totem="' + id + '"]').classList.contains('gestao-tabela__linha--inativa') && !document.getElementById('gestao-dialogo'), ids['BALCAO-01']), rot + ' ativar: sem dialogo, flash "Totem ativado" e a linha volta a Ativo');
    await page.click('#gestao-flash-fechar');

    // ---- dialogo "Regerar URL": exige o nome do totem (GUICHE-04)
    const urlAntes = await urlDe(page, ids['GUICHE-04']);
    const postsAntes = postsTotens();
    await page.click(sel(ids['GUICHE-04']) + ' button[value="regerar-url"]');
    d = await estadoDialogo(page);
    ok(d.aberto && d.foco === 'gestao-dialogo-cancelar' && d.titulo === 'Regerar URL' && d.alvo === 'GUICHE-04' && d.nomeVisivel && !d.caixaVisivel && d.confirmarDesab && /endereço anterior deixa de funcionar agora/.test(d.texto) && d.texto === 'O endereço anterior deixa de funcionar agora. O token do totem não muda; para bloquear um totem, desative-o. Atualize a URL no .bat do mini PC e reinicie o PC.' && !/ao lado|caixa/i.test(d.texto), rot + ' regerar: dialogo destrutivo com alvo GUICHE-04, campo do nome, foco inicial em Cancelar e "Regerar URL" desabilitado');
    ok(await page.evaluate(() => { const c = document.getElementById('gestao-dialogo-confirmar'); const i = document.getElementById('gestao-dialogo-nome'); const l = document.querySelector('label[for="gestao-dialogo-nome"]'); return c.classList.contains('gestao-botao--destrutivo-cheio') && !!c.querySelector('svg') && i.getAttribute('autocomplete') === 'off' && Math.round(i.getBoundingClientRect().height) >= 44 && l.textContent.includes('digite o nome do totem') && document.getElementById('gestao-dialogo').getAttribute('aria-labelledby') === 'gestao-dialogo-titulo'; }), rot + ' regerar: estilo destrutivo cheio, campo rotulado, 44px, autocomplete off');
    await page.type('#gestao-dialogo-nome', 'guiche-0');
    d = await estadoDialogo(page);
    ok(d.confirmarDesab && d.estado === 'O nome ainda não confere.', rot + ' regerar: nome incompleto mantem o botao desabilitado e avisa por texto');
    await page.type('#gestao-dialogo-nome', '4');
    d = await estadoDialogo(page);
    ok(!d.confirmarDesab && d.estado === 'O nome confere.', rot + ' regerar: "guiche-04" (minusculas) confere sem diferenciar maiusculas e habilita o botao');
    await limparCampo(page, '#gestao-dialogo-nome');
    await page.type('#gestao-dialogo-nome', '  GuIcHe-04 ');
    ok(!(await estadoDialogo(page)).confirmarDesab, rot + ' regerar: espacos nas pontas e caixa mista tambem conferem (igual ao servidor)');
    await limparCampo(page, '#gestao-dialogo-nome');
    await page.type('#gestao-dialogo-nome', 'DOCA-02');
    ok((await estadoDialogo(page)).confirmarDesab, rot + ' regerar: nome de OUTRO totem nao habilita');
    await limparCampo(page, '#gestao-dialogo-nome');
    d = await estadoDialogo(page);
    ok(d.confirmarDesab && d.estado === '', rot + ' regerar: campo vazio mantem desabilitado e sem mensagem');
    await page.type('#gestao-dialogo-nome', '<GUICHE-04>');
    ok((await estadoDialogo(page)).confirmarDesab && await page.evaluate(() => !document.querySelector('#gestao-dialogo img, #gestao-dialogo script')), rot + ' regerar: texto com simbolos nunca confere e nao injeta elemento (textContent)');
    await limparCampo(page, '#gestao-dialogo-nome');
    await page.type('#gestao-dialogo-nome', 'guiche-04');
    await checagemGeral(page, rot + ' dialogo regerar', contadores);
    await page.screenshot({ path: path.join(CAPTURAS, 'dialogo-regerar.png') });
    // foco preso (campo + Cancelar + Regerar)
    let preso = true;
    await page.focus('#gestao-dialogo-nome');
    for (let i = 0; i < 7; i++) { await page.keyboard.press('Tab'); preso = preso && await page.evaluate(() => document.getElementById('gestao-dialogo').contains(document.activeElement)); }
    for (let i = 0; i < 5; i++) { await page.keyboard.down('Shift'); await page.keyboard.press('Tab'); await page.keyboard.up('Shift'); preso = preso && await page.evaluate(() => document.getElementById('gestao-dialogo').contains(document.activeElement)); }
    ok(preso, rot + ' regerar: foco preso dentro do dialogo (Tab e Shift+Tab, com o campo do nome)');
    await page.focus('#gestao-dialogo-nome');
    const ordem = [];
    for (let i = 0; i < 4; i++) { await page.keyboard.press('Tab'); ordem.push(await page.evaluate(() => document.activeElement.id)); }
    ok(ordem.join(',') === 'gestao-dialogo-cancelar,gestao-dialogo-confirmar,gestao-dialogo-nome,gestao-dialogo-cancelar', rot + ' regerar: ordem do Tab campo > Cancelar > Regerar > campo (' + ordem.join(',') + ')');
    await page.focus('#gestao-dialogo-nome');
    const fn = await page.evaluate(() => { const c = getComputedStyle(document.getElementById('gestao-dialogo-nome')); return c.outlineStyle + ' ' + c.outlineWidth + ' ' + c.outlineColor; });
    ok(fn === 'solid 3px rgb(1, 121, 173)', rot + ' regerar: foco visivel no campo do nome (' + fn + ')');
    await page.keyboard.press('Escape');
    ok(await page.evaluate(id => !document.getElementById('gestao-dialogo').open && document.activeElement === document.querySelector('tr[data-id-totem="' + id + '"] button[value="regerar-url"]') && document.querySelector('tr[data-id-totem="' + id + '"] input[name="nome_confirmacao"]').value === '', ids['GUICHE-04']) && postsTotens() === postsAntes, rot + ' regerar: Esc fecha, devolve o foco, nao envia nada e deixa o campo do formulario vazio');
    await page.click(sel(ids['GUICHE-04']) + ' button[value="regerar-url"]');
    ok((await page.$eval('#gestao-dialogo-nome', i => i.value)) === '' && (await estadoDialogo(page)).confirmarDesab, rot + ' regerar: ao reabrir o campo vem vazio e o botao desabilitado');
    await page.evaluate(() => document.getElementById('gestao-dialogo-confirmar').click());
    ok(await page.evaluate(() => document.getElementById('gestao-dialogo').open) && postsTotens() === postsAntes, rot + ' regerar: clique no botao desabilitado nao fecha nem envia');
    await page.type('#gestao-dialogo-nome', 'Guiche-04');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('#gestao-dialogo-confirmar')]);
    ok(page.url().includes('/gestao/totem-url.php?id=' + ids['GUICHE-04']) && postsTotens() === postsAntes + 1, rot + ' regerar: confirmar com o nome certo envia 1 POST e vai para a tela da URL (PRG)');
    const novaUrl = await page.$eval('#totem-url-valor', c => c.textContent);
    ok(novaUrl !== urlAntes && /\?totem=GUICHE-04-MAUAI-[A-Z2-7]{16}$/.test(novaUrl) && novaUrl.split('-').pop() !== urlAntes.split('-').pop(), rot + ' regerar: a URL nova tem outro HASH16 (a antiga deixou de ser a do totem)');
    ok(await page.evaluate(() => { const f = document.getElementById('gestao-flash'); const r = document.getElementById('totem-url-regerada'); return f && f.textContent.includes('URL regerada') && r && /^O endereço anterior deixou de funcionar \(URL regerada em .+\)\. O acesso do totem em si \(token\) não foi alterado; para bloquear um totem, desative-o\.$/.test(r.textContent.trim()) && !!r.querySelector('svg') && getComputedStyle(r).borderTopWidth === '1px' && getComputedStyle(r).borderTopColor === 'rgb(176, 176, 177)' && getComputedStyle(f).borderTopWidth !== getComputedStyle(r).borderTopWidth &&  document.getElementById('totem-url-aviso').textContent.includes('Guarde esta URL'); }), rot + ' regerar: tela da URL com flash "URL regerada", nota permanente neutra (borda 1px #B0B0B1, distinta do flash) "O endereco anterior deixou de funcionar..." e o aviso de guarda');
    await checagemGeral(page, rot + ' URL regerada', contadores);
    await page.screenshot({ path: path.join(CAPTURAS, 'totem-url-regerada.png') });

    // ---- copiar URL: clique seleciona, clipboard e fallback
    await page.click('#totem-url-valor');
    ok(await page.evaluate(() => getSelection().toString().trim() === document.getElementById('totem-url-valor').textContent), rot + ' copiar URL: UM clique na caixa seleciona a URL inteira');
    await page.bringToFront();
    await page.click('#btn-copiar-url');
    await espera(300);
    const copiado = await page.evaluate(() => navigator.clipboard.readText());
    ok(copiado === novaUrl && await page.$eval('#copiar-status', s => s.textContent) === 'Copiado.' && await page.$eval('#copiar-status', s => s.getAttribute('aria-live')) === 'polite', rot + ' copiar URL: botao "Copiar URL" usa navigator.clipboard e confirma por texto (aria-live)');
    await page.evaluate(() => { Object.defineProperty(navigator, 'clipboard', { value: undefined, configurable: true }); document.getElementById('copiar-status').textContent = ''; getSelection().removeAllRanges(); });
    await page.click('#btn-copiar-url');
    await espera(200);
    const st = await page.$eval('#copiar-status', s => s.textContent);
    ok((st === 'Copiado.' || st === 'Não foi possível copiar. Selecione a URL e use Ctrl+C.') && await page.evaluate(() => getSelection().toString() === document.getElementById('totem-url-valor').textContent), rot + ' copiar URL: fallback (execCommand) seleciona a URL e informa o resultado (' + st + ')');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('#btn-voltar-totens')]);
    ok(page.url().endsWith('/gestao/totens.php') && (await urlDe(page, ids['GUICHE-04'])) === novaUrl, rot + ' voltar: a lista mostra a URL nova');

    // ---- copiar URL pela lista
    await irPara(page, base, '/gestao/totens.php');
    await page.bringToFront();
    await page.click('#btn-copiar-url-' + ids['GUICHE-04']);
    await espera(300);
    const copiadoLista = await page.evaluate(() => navigator.clipboard.readText());
    ok(copiadoLista === novaUrl && await page.$eval(sel(ids['GUICHE-04']) + ' .gestao-copiar-status', s => s.textContent) === 'Copiado.' && !page.url().includes('totem-url.php') && postsTotens() === postsAntes + 1, rot + ' copiar URL (lista): o botao copia a URL da linha para a area de transferencia, mostra "Copiado." e nao sai da lista');

    // ---- criar totem: erro de servidor, XSS literal, sucesso
    await irPara(page, base, '/gestao/totem-form.php');
    const maua2 = await page.evaluate(() => Array.from(document.getElementById('id_empresa').options).find(o => o.textContent === 'Maua II').value);
    await page.select('#id_empresa', maua2);
    await page.type('#nome', 'A');
    const [r422] = await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('#btn-salvar-totem')]);
    ok(r422.status() === 422 && await page.evaluate(() => { const e = document.getElementById('erro-nome'); const n = document.getElementById('nome'); return e && e.getAttribute('role') === 'alert' && !!e.querySelector('svg') && e.textContent.includes('Erro:') && n.getAttribute('aria-invalid') === 'true' && n.getAttribute('aria-describedby') === 'erro-nome' && getComputedStyle(n).borderTopWidth === '3px' && n.value === 'A' && !document.getElementById('erro-empresa'); }), rot + ' novo totem: nome invalido => 422 com erro por icone + texto "Erro:", role=alert, aria-invalid e borda 3px');
    ok(await page.$eval('#id_empresa', s => s.value) === maua2, rot + ' novo totem: a empresa escolhida volta selecionada');
    await checagemGeral(page, rot + ' novo totem com erro', contadores);
    await page.screenshot({ path: path.join(CAPTURAS, 'totem-form-erro.png') });
    await limparCampo(page, '#nome');
    await page.evaluate(() => document.getElementById('nome').removeAttribute('maxlength')); // simula POST sem o limite do navegador
    await page.type('#nome', '"><img src=x onerror=window.__xss=9>');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('#btn-salvar-totem')]);
    ok(await page.evaluate(() => document.getElementById('nome').value === '"><img src=x onerror=window.__xss=9>' && !document.querySelector('main img[src="x"]') && window.__xss === undefined && !!document.getElementById('erro-nome')), rot + ' novo totem: nome hostil volta LITERAL no campo, rejeitado, sem executar');
    await limparCampo(page, '#nome');
    await page.type('#nome', 'Painel 07');
    const antesC = posts.filter(u => u.includes('totem-form.php')).length;
    const navC = page.waitForNavigation({ waitUntil: 'networkidle0' });
    const busy = await page.evaluate(() => { const f = document.getElementById('form-totem'); f.requestSubmit(); f.requestSubmit(); f.requestSubmit(); return f.getAttribute('aria-busy'); });
    await navC;
    ok(busy === 'true' && posts.filter(u => u.includes('totem-form.php')).length - antesC === 1, rot + ' novo totem: anti duplo clique, 3 envios geram 1 POST');
    ok(await page.evaluate(() => { const f = document.getElementById('gestao-flash'); return location.pathname === '/gestao/totem-url.php' && f && f.textContent.includes('Totem criado e ativo') && document.getElementById('totem-url-nome').textContent === 'PAINEL-07' && /^https:\/\/totem\.udlog\.online\/totem\/\?totem=PAINEL-07-MAUAII-[A-Z2-7]{16}$/.test(document.getElementById('totem-url-valor').textContent) && !document.getElementById('totem-url-regerada'); }), rot + ' novo totem: criado => tela da URL (PRG) com flash, nome normalizado PAINEL-07, URL no padrao e sem aviso de regeracao');
    await page.screenshot({ path: path.join(CAPTURAS, 'totem-url-criado.png') });
    await checagemGeral(page, rot + ' URL criada', contadores);
    await page.close();
}

/** Sem JS: as acoes sao formularios; sem dialogo o servidor valida (nome e confirmacao obrigatorios). */
async function cenarioTotensSemJs(browser, srv, contadores) {
    const rot = 'sem JS totens';
    const { base, info } = srv;
    const page = await novaPagina(browser, 1366, 768, rot, contadores);
    await limparEstado(page, base);
    await page.setJavaScriptEnabled(false);
    await entrar(page, base, 'ana.admin', info.senha);
    await irPara(page, base, '/gestao/totens.php');
    const ids = await idsTotem(page);
    const e = await page.evaluate(estadoPagina);
    ok(e.scrollW <= e.clientW, rot + ' lista: sem overflow horizontal');
    ok(await page.evaluate(id => { const a = document.querySelector('tr[data-id-totem="' + id + '"] a[data-copiar-alternativa]'); return a && a.getAttribute('href') === '/gestao/totem-url.php?id=' + id && a.textContent.trim() === 'Abrir URL' && !document.querySelector('.gestao-url-copiar button'); }, ids['GUICHE-04']), rot + ' lista: sem JS o link "Abrir URL" leva a tela da URL (nao promete copiar)');
    const vis = await page.evaluate(ids => {
        const v = el => !!el && el.getClientRects().length > 0;
        const g = document.querySelector('tr[data-id-totem="' + ids['GUICHE-04'] + '"]'), b = document.querySelector('tr[data-id-totem="' + ids['BALCAO-01'] + '"]');
        return { nome: v(g.querySelector('input[name="nome_confirmacao"]')), rotulo: v(g.querySelector('label.gestao-confirmacao-inline')), caixa: v(b.querySelector('input[name="confirmar_atendimento"]')), dialogo: !!document.getElementById('gestao-dialogo'), altura: Math.round(g.getBoundingClientRect().height), botoes: Array.from(document.querySelectorAll('.gestao-botao')).filter(x => x.getClientRects().length).every(x => Math.round(x.getBoundingClientRect().height) >= 44) };
    }, ids);
    ok(vis.nome && vis.rotulo && vis.caixa && !vis.dialogo && vis.botoes, rot + ' lista: campo do nome e caixa de atendimento ficam VISIVEIS (o servidor valida), sem dialogo, botoes >= 44px (linha do regerar com ' + vis.altura + 'px)');
    await page.screenshot({ path: path.join(CAPTURAS, 'totens-sem-js.png') });
    await checagemGeral(page, rot + ' lista', contadores);
    // desativar com atendimento recente: sem marcar a caixa o navegador nao envia (required)
    const antes = page.url();
    await page.click(sel(ids['BALCAO-01']) + ' button[value="desativar"]');
    await espera(300);
    ok(page.url() === antes && await page.evaluate(id => document.querySelector('tr[data-id-totem="' + id + '"] input[name="confirmar_atendimento"]').validity.valueMissing, ids['BALCAO-01']), rot + ' desativar com atendimento: sem marcar a caixa nada e enviado (required nativo)');
    await page.click(sel(ids['BALCAO-01']) + ' input[name="confirmar_atendimento"]');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click(sel(ids['BALCAO-01']) + ' button[value="desativar"]')]);
    ok(await page.evaluate(id => document.getElementById('gestao-flash').textContent.includes('Totem desativado') && document.querySelector('tr[data-id-totem="' + id + '"] .col-situacao').textContent.trim().startsWith('Inativo') && !document.getElementById('gestao-flash-fechar'), ids['BALCAO-01']), rot + ' desativar: formulario com CSRF funciona sem JS (caixa marcada) e o flash aparece');
    // ativar
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click(sel(ids['BALCAO-09']) + ' button[value="ativar"]')]);
    ok(await page.evaluate(id => document.getElementById('gestao-flash').textContent.includes('Totem ativado') && !document.querySelector('tr[data-id-totem="' + id + '"]').classList.contains('gestao-tabela__linha--inativa'), ids['BALCAO-09']), rot + ' ativar: funciona sem JS');
    // regerar sem o nome: o navegador exige (required)
    await page.click(sel(ids['DOCA-02']) + ' button[value="regerar-url"]');
    await espera(300);
    ok(await page.evaluate(id => document.querySelector('tr[data-id-totem="' + id + '"] input[name="nome_confirmacao"]').validity.valueMissing, ids['DOCA-02']), rot + ' regerar: nome em branco nao e enviado (required nativo)');
    // regerar com nome errado: o servidor recusa e a URL nao muda
    const urlDoca = await page.$eval(sel(ids['DOCA-02']) + ' code.gestao-totem-url', c => c.textContent);
    await page.type(sel(ids['DOCA-02']) + ' input[name="nome_confirmacao"]', 'OUTRO-NOME');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click(sel(ids['DOCA-02']) + ' button[value="regerar-url"]')]);
    ok(await page.evaluate(() => { const f = document.getElementById('gestao-flash'); return f && f.getAttribute('data-tipo') === 'erro' && f.textContent.includes('O nome digitado não confere'); }) && await page.$eval(sel(ids['DOCA-02']) + ' code.gestao-totem-url', c => c.textContent) === urlDoca, rot + ' regerar: nome errado => o SERVIDOR recusa (flash de erro) e a URL nao muda');
    // regerar com o nome certo
    await page.type(sel(ids['DOCA-02']) + ' input[name="nome_confirmacao"]', 'doca-02');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click(sel(ids['DOCA-02']) + ' button[value="regerar-url"]')]);
    ok(page.url().includes('/gestao/totem-url.php?id=' + ids['DOCA-02']) && await page.evaluate(u => document.getElementById('totem-url-valor').textContent !== u && !!document.getElementById('totem-url-regerada') && document.getElementById('gestao-flash').textContent.includes('URL regerada'), urlDoca), rot + ' regerar: nome certo => URL nova e aviso de URL antiga, sem JS');
    const sj = await page.evaluate(() => { const c = document.getElementById('totem-url-valor'); return { sel: getComputedStyle(c).userSelect, botao: !!document.getElementById('btn-copiar-url') || !!document.getElementById('btn-copiar-senha'), fonte: getComputedStyle(c).fontSize }; });
    ok(sj.sel === 'all' && !sj.botao && parseFloat(sj.fonte) >= 20, rot + ' URL: sem JS a URL segue em destaque e selecionavel com um clique (user-select all); o botao Copiar depende de JS (risco aceito)');
    await checagemGeral(page, rot + ' URL', contadores);
    await page.close();
}

/** Estado vazio da lista (esvazia o banco QA do servidor por stdin). */
async function cenarioTotensVazio(browser, srv, contadores) {
    const rot = '1366x768 totens-vazio';
    const { base, info } = srv;
    await esvaziarTotens(srv);
    const page = await novaPagina(browser, 1366, 768, rot, contadores);
    await limparEstado(page, base);
    await entrar(page, base, 'ana.admin', info.senha);
    await irPara(page, base, '/gestao/totens.php');
    await checagemGeral(page, rot, contadores);
    ok(await page.evaluate(() => { const l = document.querySelectorAll('#tabela-totens tbody tr'); const c = document.querySelector('.gestao-tabela__vazio .gestao-estado'); return l.length === 1 && c && c.textContent.trim() === 'Nenhum totem cadastrado. Crie o primeiro.' && !!c.querySelector('svg') && document.querySelector('.gestao-tabela__vazio td').getAttribute('colspan') === '6' && Math.round(document.getElementById('btn-novo-totem').getBoundingClientRect().height) >= 44; }), rot + ': estado vazio "Nenhum totem cadastrado. Crie o primeiro." com icone e botao "Novo totem" (44px) acima');
    await botoesSolidos(page, rot);
    await page.screenshot({ path: path.join(CAPTURAS, 'vazio.png') });
    await page.close();
}

(async () => {
    const contadores = { csp: [], erros: [], externos: [], semCsp: [], secundarios: [] };
    let srv = null, browser = null;
    try {
        srv = await subirServidor();
        console.log('servidor QA em ' + srv.base + ' (banco ' + srv.info.banco + ')');
        browser = await puppeteer.launch({ executablePath: CHROME, headless: 'new', args: ['--no-sandbox', '--disable-gpu'] });
        await cenarioViewport(browser, srv, 1366, 768, true, contadores);
        await cenarioViewport(browser, srv, 1920, 1080, false, contadores);
        await cenarioCompleto(browser, srv, contadores);
        await cenarioEstreito(browser, srv, contadores);
        await cenarioSemJs(browser, srv, contadores);
        // F2 (totens): lista, novo totem e URL em 3 viewports; fluxos com JS; sem JS; estado vazio (ultimo: esvazia o banco QA)
        await cenarioTotensViewport(browser, srv, 1366, 768, contadores);
        await cenarioTotensViewport(browser, srv, 1920, 1080, contadores);
        await cenarioTotensViewport(browser, srv, 1000, 700, contadores);
        await cenarioTotensFluxo(browser, srv, contadores);
        await cenarioTotensSemJs(browser, srv, contadores);
        await cenarioTotensVazio(browser, srv, contadores);
        ok(contadores.csp.length === 0, 'console: nenhuma mensagem de CSP no Chrome' + (contadores.csp.length ? ' -> ' + contadores.csp.slice(0, 3).join(' | ') : ''));
        ok(contadores.erros.length === 0, 'nenhum erro de JavaScript nas paginas' + (contadores.erros.length ? ' -> ' + contadores.erros.slice(0, 3).join(' | ') : ''));
        ok(contadores.externos.length === 0, 'nenhuma requisicao externa (CDN/fonte/imagem)' + (contadores.externos.length ? ' -> ' + contadores.externos.slice(0, 3).join(' | ') : ''));
        ok(contadores.semCsp.length === 0, 'todo documento HTML veio com Content-Security-Policy' + (contadores.semCsp.length ? ' -> ' + contadores.semCsp.slice(0, 3).join(' | ') : ''));
        const unicos = Array.from(new Set(contadores.secundarios));
        console.log('uso de #878789 em texto (>=14px): ' + unicos.length + (unicos.length ? ' ex.: ' + unicos.slice(0, 3).join(' | ') : ''));
    } catch (e) {
        falhou++;
        console.log('ERRO no harness: ' + (e && e.stack || e));
    } finally {
        if (browser) { await browser.close(); }
        await pararServidor(srv);
    }
    console.log('\ngestao_layout: ' + (passou + falhou) + ' verificacoes, ' + falhou + ' falhas (capturas em ' + CAPTURAS + ')');
    process.exit(falhou === 0 ? 0 : 1);
})();
