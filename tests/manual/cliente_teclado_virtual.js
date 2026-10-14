// Regressao: autocomplete de cliente (rec_cliente) com teclado virtual e fisico.
// Sem banco/API reais: cliente.php e mockado. Uso: node tests/manual/cliente_teclado_virtual.js
const http = require('http');
const fs = require('fs');
const path = require('path');
const os = require('os');
let puppeteer;
try { puppeteer = require('puppeteer'); } catch (e) {
    puppeteer = require(path.join(process.env.APPDATA || os.homedir(), 'npm', 'node_modules', 'puppeteer'));
}
const A = path.join(__dirname, '..', '..', 'public', 'totem', 'assets');
const CHROME = process.env.CHROME || (fs.existsSync('C:/Program Files/Google/Chrome/Application/chrome.exe') ? 'C:/Program Files/Google/Chrome/Application/chrome.exe' : undefined);
const INDEX = `<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><link rel="stylesheet" href="/assets/app.css"></head><body data-totem-token="TESTE" data-totem-nome="qa"><div id="app"></div><script type="application/json" id="lgpd-termo-dados">{"versao":"t","hash":"t","texto":"t"}</script><script type="application/json" id="assets-versoes">{}</script><script src="/assets/app.js"></script></body></html>`;
let passou = 0, falhou = 0; const termos = []; const salvos = [];
const UM = [{ nome: 'CARGILL S.A.', cnpj: '11111111000191' }];
let resposta = () => ({ clientes: UM, atraso: 0 });
const ok = (c, t) => { if (c) passou++; else { falhou++; console.log('FALHA: ' + t); } };
const srv = http.createServer((req, res) => {
    const u = new URL(req.url, 'http://x');
    if (u.pathname === '/') { res.setHeader('Content-Type', 'text/html; charset=utf-8'); return res.end(INDEX); }
    if (u.pathname === '/assets/app.js') { res.setHeader('Content-Type', 'text/javascript'); return res.end(fs.readFileSync(path.join(A, 'app.js'))); }
    if (u.pathname === '/assets/app.css') { res.setHeader('Content-Type', 'text/css'); return res.end(fs.readFileSync(path.join(A, 'app.css'))); }
    if (u.pathname === '/api/cliente.php') {
        const termo = u.searchParams.get('termo');
        termos.push(termo);
        const r = resposta(termo);
        res.setHeader('Content-Type', 'application/json');
        return setTimeout(() => res.end(JSON.stringify({ sucesso: true, dados: { clientes: r.clientes } })), r.atraso || 0);
    }
    if (u.pathname === '/api/atendimento.php') {
        let b = ''; req.on('data', c => b += c); req.on('end', () => {
            salvos.push({ acao: u.searchParams.get('acao'), corpo: JSON.parse(b || '{}') });
            res.setHeader('Content-Type', 'application/json'); res.end(JSON.stringify({ sucesso: true, dados: {} }));
        });
        return;
    }
    res.statusCode = 404; res.end('x');
});
const esperar = ms => new Promise(r => setTimeout(r, ms));
(async () => {
    await new Promise(r => srv.listen(0, '127.0.0.1', r));
    const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'new', args: ['--no-sandbox'] });
    const page = await browser.newPage();
    await page.setViewport({ width: 768, height: 1366 });
    const erros = []; page.on('pageerror', e => erros.push(e.message));
    await page.goto(`http://127.0.0.1:${srv.address().port}/`);
    const itens = () => page.$$eval('#listaSugestoes .item', n => n.map(x => x.textContent));
    const valor = () => page.$eval('#inputCliente', e => e.value);
    const tecla = async t => { const b = await page.evaluateHandle(t => [...document.querySelectorAll('#teclado .tecla')].find(x => x.textContent.trim() === t), t); await b.asElement().click(); };
    const abrir = async () => {
        await page.evaluate(() => { ir('rec_cliente'); });
        await page.click('#inputCliente');
        await page.waitForSelector('#teclado.aberto');
    };

    // Teclado virtual: 3 letras
    await abrir();
    await tecla('C'); await tecla('A');
    await esperar(500);
    ok((await itens()).length === 0 && termos.length === 0, 'com 2 letras nao busca');
    await tecla('R'); await esperar(600);
    ok(await valor() === 'CAR', 'valor CAR pelo teclado virtual');
    let it = await itens();
    ok(it.length === 1 && it[0] === 'CARGILL S.A.', 'virtual: lista aparece com 3 letras');
    ok(termos.length === 1 && termos[0] === 'CAR', 'virtual: 1 unica chamada com termo CAR (sem duplicar)');
    // Backspace volta a <3 limpa lista
    await tecla('⌫'); await esperar(500);
    ok(await valor() === 'CA' && (await itens()).length === 0, 'virtual: backspace para 2 letras limpa a lista');
    ok(termos.length === 1, 'virtual: backspace nao busca com <3');
    // digita de novo e seleciona
    await tecla('G'); await esperar(600);
    ok((await itens()).length === 1 && termos[termos.length - 1] === 'CAG', 'virtual: volta a buscar ao reatingir 3');
    await page.click('#listaSugestoes .item'); 
    ok(await valor() === 'CARGILL S.A.', 'selecionar sugestao preenche o campo');

    // Teclado fisico
    termos.length = 0;
    await abrir();
    await page.type('#inputCliente', 'WIN'); await esperar(600);
    ok((await itens()).length === 1 && termos.length === 1 && termos[0] === 'WIN', 'fisico: lista aparece e 1 chamada');
    await page.keyboard.press('Backspace'); await esperar(500);
    ok((await itens()).length === 0, 'fisico: backspace para 2 letras limpa a lista');

    // Regra: no maximo 1 sugestao, sem selecao automatica, toque confirma
    const sel = () => page.evaluate(() => clienteSelecionado);
    const digitar = async t => { await abrir(); await page.type('#inputCliente', t); await esperar(600); };
    resposta = () => ({ clientes: [] });
    await digitar('ZZZ');
    ok((await itens()).length === 0, 'regra: 0 clientes -> nenhuma sugestao');
    resposta = () => ({ clientes: [UM[0], { nome: 'CARREFOUR', cnpj: '22222222000191' }] });
    await digitar('CAR');
    ok((await itens()).length === 0, 'regra: 2 clientes (backend antigo) -> nenhuma sugestao');
    resposta = () => ({ clientes: 'x' });
    await digitar('CAR');
    ok((await itens()).length === 0, 'regra: formato inesperado -> nenhuma sugestao');
    resposta = () => ({ clientes: UM });
    await digitar('CAR');
    ok((await itens()).length === 1, 'regra: 1 cliente -> exatamente 1 sugestao');
    ok(await page.$eval('#listaSugestoes .item', e => e.offsetParent !== null && e.getBoundingClientRect().height > 0), 'regra: sugestao visivel');
    ok(await valor() === 'CAR' && (await sel()) === null, 'regra: sem selecao automatica (campo = digitado, clienteSelecionado nulo)');
    await page.click('#listaSugestoes .item');
    const s1 = await sel();
    ok(await valor() === 'CARGILL S.A.' && s1 && s1.cnpj === '11111111000191', 'regra: toque preenche campo e clienteSelecionado');
    salvos.length = 0;
    await page.evaluate(() => confirmarCliente()); await esperar(300);
    ok(salvos.length === 1 && salvos[0].acao === 'salvar-etapa' && salvos[0].corpo.etapa === 'cliente' && salvos[0].corpo.dados.nome === 'CARGILL S.A.' && salvos[0].corpo.dados.cnpj === '11111111000191', 'regra: Avancar chama salvar-etapa etapa cliente {nome,cnpj}');
    ok(await page.evaluate(() => state.tela) === 'rec_cnh_qr', 'regra: segue para rec_cnh_qr');
    // resposta antiga fora de ordem nao sobrescreve a nova
    resposta = t => t === 'ABC' ? { clientes: [{ nome: 'ANTIGO ABC', cnpj: '1' }], atraso: 900 } : { clientes: [{ nome: 'NOVO ABCD', cnpj: '2' }], atraso: 50 };
    await abrir();
    await page.type('#inputCliente', 'ABC'); await esperar(400);
    await page.type('#inputCliente', 'D'); await esperar(1500);
    it = await itens();
    ok(it.length === 1 && it[0] === 'NOVO ABCD', 'resposta antiga fora de ordem nao sobrescreve: ' + it.join('|'));
    // HTML no nome e escapado
    resposta = () => ({ clientes: [{ nome: '<img src=x onerror="window.__xss=1"><b>X</b>', cnpj: '"><script>window.__xss=2</script>' }] });
    await digitar('XSS');
    ok((await itens()).length === 1 && await page.$$eval('#listaSugestoes .item *', n => n.length) === 0 && !(await page.evaluate(() => window.__xss || 0)), 'html no nome escapado (__xss = 0)');
    resposta = () => ({ clientes: UM });

    // Outro campo (nao cliente) continua digitando pelo teclado virtual sem erro
    await page.evaluate(() => ir('exp_placa'));
    await page.click('.kb-input'); await page.waitForSelector('#teclado.aberto');
    await tecla('A'); await tecla('1');
    ok(await page.$eval('.kb-input', e => e.value) === 'A1', 'outro campo: teclado virtual segue funcionando');
    ok(erros.length === 0, 'sem erros de pagina: ' + erros.join('|'));

    await browser.close(); srv.close();
    console.log(`cliente_teclado_virtual: ${passou} ok, ${falhou} falhas`);
    process.exit(falhou ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
