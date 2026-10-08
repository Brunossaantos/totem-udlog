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
let passou = 0, falhou = 0; const termos = [];
const ok = (c, t) => { if (c) passou++; else { falhou++; console.log('FALHA: ' + t); } };
const srv = http.createServer((req, res) => {
    const u = new URL(req.url, 'http://x');
    if (u.pathname === '/') { res.setHeader('Content-Type', 'text/html; charset=utf-8'); return res.end(INDEX); }
    if (u.pathname === '/assets/app.js') { res.setHeader('Content-Type', 'text/javascript'); return res.end(fs.readFileSync(path.join(A, 'app.js'))); }
    if (u.pathname === '/assets/app.css') { res.setHeader('Content-Type', 'text/css'); return res.end(fs.readFileSync(path.join(A, 'app.css'))); }
    if (u.pathname === '/api/cliente.php') {
        termos.push(u.searchParams.get('termo'));
        res.setHeader('Content-Type', 'application/json');
        return res.end(JSON.stringify({ sucesso: true, dados: { clientes: [{ nome: 'CARGILL S.A.', cnpj: '11111111000191' }, { nome: 'CARREFOUR', cnpj: '22222222000191' }] } }));
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
    ok(it.length === 2 && it[0] === 'CARGILL S.A.', 'virtual: lista aparece com 3 letras');
    ok(termos.length === 1 && termos[0] === 'CAR', 'virtual: 1 unica chamada com termo CAR (sem duplicar)');
    // Backspace volta a <3 limpa lista
    await tecla('⌫'); await esperar(500);
    ok(await valor() === 'CA' && (await itens()).length === 0, 'virtual: backspace para 2 letras limpa a lista');
    ok(termos.length === 1, 'virtual: backspace nao busca com <3');
    // digita de novo e seleciona
    await tecla('G'); await esperar(600);
    ok((await itens()).length === 2 && termos[termos.length - 1] === 'CAG', 'virtual: volta a buscar ao reatingir 3');
    await page.click('#listaSugestoes .item'); 
    ok(await valor() === 'CARGILL S.A.', 'selecionar sugestao preenche o campo');

    // Teclado fisico
    termos.length = 0;
    await abrir();
    await page.type('#inputCliente', 'WIN'); await esperar(600);
    ok((await itens()).length === 2 && termos.length === 1 && termos[0] === 'WIN', 'fisico: lista aparece e 1 chamada');
    await page.keyboard.press('Backspace'); await esperar(500);
    ok((await itens()).length === 0, 'fisico: backspace para 2 letras limpa a lista');

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
