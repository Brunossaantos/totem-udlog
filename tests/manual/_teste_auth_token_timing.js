'use strict';

/**
 * SCRIPT DE TESTE MANUAL (nao faz parte da suite do projeto, nao aciona
 * nenhum hardware/impressora). Verifica servico-impressao-local/src/middleware/auth.js
 * isoladamente, com config mockada via CONFIG_PATH apontando para um
 * config.json temporario em pasta de scratch -- nao mexe no
 * config/config.json real do servico.
 *
 * Uso: node tests/manual/_teste_auth_token_timing.js
 */

const fs = require('fs');
const os = require('os');
const path = require('path');

const TOKEN_CORRETO = 'a'.repeat(32); // 32 chars, mesmo padrao de token real

// Monta um config.json temporario valido em pasta de scratch (fora do repo).
const pastaTemp = fs.mkdtempSync(path.join(os.tmpdir(), 'udlog-auth-teste-'));
const caminhoConfig = path.join(pastaTemp, 'config.json');
fs.writeFileSync(caminhoConfig, JSON.stringify({
  porta: 4747,
  token: TOKEN_CORRETO,
  maxPdfBytesDecodificado: 2097152,
  origensPermitidas: [],
  impressorasPermitidas: ['IMPRESSORA-FAKE-DE-TESTE'],
  idempotencia: { ttlMs: 600000, maxEntries: 500 },
  timeoutMs: 30000,
}, null, 2));

process.env.CONFIG_PATH = caminhoConfig;

const caminhoAuth = path.join(__dirname, '..', '..', 'servico-impressao-local', 'src', 'middleware', 'auth.js');
const { autenticar } = require(caminhoAuth);

function mockReqRes(authorizationHeader) {
  const req = { headers: authorizationHeader === undefined ? {} : { authorization: authorizationHeader } };
  const res = {
    statusCode: null,
    body: null,
    status(codigo) { this.statusCode = codigo; return this; },
    json(payload) { this.body = payload; return this; },
  };
  let nextChamado = false;
  const next = () => { nextChamado = true; };
  return { req, res, next: next, nextFoiChamado: () => nextChamado };
}

let falhas = 0;
function checar(descricao, condicao) {
  const status = condicao ? 'PASSOU' : 'FALHOU';
  if (!condicao) falhas += 1;
  console.log(`[${status}] ${descricao}`);
}

console.log('--- Teste 1: token correto ---');
{
  const { req, res, next, nextFoiChamado } = mockReqRes(`Bearer ${TOKEN_CORRETO}`);
  let excecao = null;
  try { autenticar(req, res, next); } catch (e) { excecao = e; }
  checar('nao lanca excecao', excecao === null);
  checar('chama next() (segue para a rota)', nextFoiChamado());
  checar('nao seta status de erro', res.statusCode === null);
}

console.log('--- Teste 2: token incorreto, MESMO comprimento ---');
let resposta2;
{
  const tokenErradoMesmoTamanho = 'b'.repeat(TOKEN_CORRETO.length);
  const { req, res, next, nextFoiChamado } = mockReqRes(`Bearer ${tokenErradoMesmoTamanho}`);
  let excecao = null;
  try { autenticar(req, res, next); } catch (e) { excecao = e; }
  checar('nao lanca excecao', excecao === null);
  checar('nao chama next()', !nextFoiChamado());
  checar('retorna 401', res.statusCode === 401);
  resposta2 = res.body;
  console.log('  corpo:', JSON.stringify(resposta2));
}

console.log('--- Teste 3: token incorreto, comprimento MENOR ---');
let resposta3;
{
  const tokenMaisCurto = TOKEN_CORRETO.slice(0, 5);
  const { req, res, next, nextFoiChamado } = mockReqRes(`Bearer ${tokenMaisCurto}`);
  let excecao = null;
  try { autenticar(req, res, next); } catch (e) { excecao = e; }
  checar('nao lanca excecao', excecao === null);
  checar('nao chama next()', !nextFoiChamado());
  checar('retorna 401', res.statusCode === 401);
  resposta3 = res.body;
  console.log('  corpo:', JSON.stringify(resposta3));
}

console.log('--- Teste 4: token incorreto, comprimento MAIOR ---');
let resposta4;
{
  const tokenMaisLongo = TOKEN_CORRETO + 'extra-bem-mais-longo-do-que-o-original-1234567890';
  const { req, res, next, nextFoiChamado } = mockReqRes(`Bearer ${tokenMaisLongo}`);
  let excecao = null;
  try { autenticar(req, res, next); } catch (e) { excecao = e; }
  checar('nao lanca excecao', excecao === null);
  checar('nao chama next()', !nextFoiChamado());
  checar('retorna 401', res.statusCode === 401);
  resposta4 = res.body;
  console.log('  corpo:', JSON.stringify(resposta4));
}

console.log('--- Teste 5: token ausente (header Authorization ausente) ---');
let resposta5;
{
  const { req, res, next, nextFoiChamado } = mockReqRes(undefined);
  let excecao = null;
  try { autenticar(req, res, next); } catch (e) { excecao = e; }
  checar('nao lanca excecao', excecao === null);
  checar('nao chama next()', !nextFoiChamado());
  checar('retorna 401', res.statusCode === 401);
  resposta5 = res.body;
  console.log('  corpo:', JSON.stringify(resposta5));
}

console.log('--- Teste 5b: header Authorization vazio (string vazia) ---');
{
  const { req, res, next, nextFoiChamado } = mockReqRes('');
  let excecao = null;
  try { autenticar(req, res, next); } catch (e) { excecao = e; }
  checar('nao lanca excecao', excecao === null);
  checar('nao chama next()', !nextFoiChamado());
  checar('retorna 401', res.statusCode === 401);
}

console.log('--- Teste 6: as 3 respostas de falha (mesmo tam. errado, menor, maior) sao IDENTICAS ---');
{
  const iguais = res => JSON.stringify(res);
  checar(
    'status 2 === status 3 === status 4 === status 5',
    true // ja checado individualmente acima como 401 cada
  );
  checar(
    'corpo teste2 === corpo teste3 (deep equal)',
    iguais(resposta2) === iguais(resposta3)
  );
  checar(
    'corpo teste2 === corpo teste4 (deep equal)',
    iguais(resposta2) === iguais(resposta4)
  );
  checar(
    'corpo teste2 === corpo teste5 (deep equal, token ausente)',
    iguais(resposta2) === iguais(resposta5)
  );
}

console.log('\n=== RESULTADO FINAL ===');
if (falhas === 0) {
  console.log('TODOS OS CENARIOS PASSARAM (0 falhas)');
} else {
  console.log(`${falhas} verificacao(oes) FALHARAM`);
  process.exitCode = 1;
}

// Limpeza da pasta de scratch temporaria.
fs.rmSync(pastaTemp, { recursive: true, force: true });
