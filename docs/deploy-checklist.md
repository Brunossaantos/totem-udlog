# Checklist de deploy — totem-udlog

Este documento reúne pontos a **validar na prática** na hospedagem
(Hostgator/cPanel) e no mini PC físico que roda o Chromium em modo kiosk.
Nada aqui foi aplicado ou confirmado ainda — é um roteiro de verificação,
não uma descrição do estado atual do ambiente. Onde a topologia real
(domínio, HTTPS, componente local) não está documentada, isso é registrado
como pendência, nunca suposto.

## 1. Hostgator / cPanel (aplicação PHP + banco)

- [ ] `composer install` executado no servidor.
- [ ] `.env` criado a partir de `.env.example` e preenchido (nunca versionado,
      nunca dentro do `public_html`).
- [ ] `sql/schema.sql` aplicado no MariaDB.
- [ ] `STORAGE_PATH` apontando para um diretório **fora** do `public_html`
      (LGPD — fotos de CNH/CPF/CRLV/notas fiscais não podem ficar
      acessíveis por URL direta).
- [ ] Ao menos um totem cadastrado em `tb_totem` (`codigo` + `token_api`).
- [ ] AutoSSL/HTTPS ativo no domínio usado pelo totem (Let's Encrypt via
      cPanel) — necessário tanto para a aplicação em geral quanto pelo
      motivo específico da seção 2 abaixo.
- [ ] Cron do `cron/reenviar-fila.php` configurado via cron do cPanel (não
      há processo persistente nem fila externa nesta hospedagem).
- [ ] `NOTA_IMAGEM_MAX_BYTES` definido em `.env` (padrão 5 MB, ver
      `.env.example`) — confirmar se o `php.ini` do plano de hospedagem
      (`upload_max_filesize`/`post_max_size`/`memory_limit`) comporta esse
      tamanho de payload em base64 antes de considerar o limite efetivo.
- [ ] `IMPRESSORAS_PERMITIDAS` e `IMPRESSAO_TIMEOUT_MS` em `.env`
      conferem valor-a-valor com `impressorasPermitidas`/`timeoutMs` em
      `config/config.json` do serviço local no mini PC (não há
      sincronização automática entre os dois ambientes — ver
      `servico-impressao-local/README.md`, seção 2.5.1).

### 1.X Serviço local de impressão (mini PC Windows)

Checklist operacional resumido para o `servico-impressao-local/`
(Node.js standalone, fora do Hostgator). Para o detalhamento completo de
cada passo, ver `servico-impressao-local/README.md` — este item não
duplica o manual, só serve de lista de verificação.

- [ ] **Node.js instalado.** Versão mínima `>=22.17.1` (ver `engines` em
      `servico-impressao-local/package.json` e seção 2.1 do README),
      baixado do instalador oficial `.msi` de 64 bits em
      https://nodejs.org/dist/v22.17.1/. Confirmar com `node --version` e
      `npm --version`.
- [ ] **Driver da impressora instalado.** Epson Advanced Printer Driver 6
      para a `EPSON TM-T88VII` instalado e testado com página de teste do
      próprio Windows antes de qualquer teste via este serviço (README
      seção 2.2).
- [ ] **Código do serviço copiado e dependências instaladas.** Pasta
      `servico-impressao-local/` copiada para o mini PC (ex.
      `C:\udlog\servico-impressao-local`) e `npm install` executado
      dentro dela (README seção 2.3).
- [ ] **Token do serviço gerado com segurança.** Gerado com
      `node -e "console.log(require('crypto').randomBytes(32).toString('hex'))"`
      — nunca reaproveitar token do totem, do Trello ou do Talent (README
      seção 2.4).
- [ ] **`config/config.json` criado a partir de `config/config.example.json`.**
      Nunca commitado (já coberto por `config/config.json` em
      `servico-impressao-local/.gitignore`). Preencher, no mínimo:
  - [ ] `token`: o valor gerado no passo acima.
  - [ ] `impressorasPermitidas`: allowlist por nome exato de driver — hoje
        só `["EPSON TM-T88VII Receipt"]` está autorizada. Nunca incluir
        impressora virtual/interativa (`Microsoft Print to PDF`, `Fax`,
        etc.) — pode travar a tela do totem esperando interação humana.
  - [ ] `timeoutMs`: default `30000` (30s) se omitido.
  - [ ] `origensPermitidas`: **permanece vazio/fail-closed por enquanto**
        — a URL real de produção do totem ainda não foi definida; não
        decidir esse valor aqui, só manter vazio até a pendência ser
        resolvida (README seção 2.5 e seção 6).
  - [ ] Espelhar manualmente `IMPRESSORAS_PERMITIDAS`,
        `IMPRESSAO_TIMEOUT_MS` e `IMPRESSAO_FRONTEND_TIMEOUT_MS` no `.env`
        do backend PHP — não há sincronização automática entre os dois
        ambientes (README seção 2.5.1, e item acima nesta mesma seção 1).
- [ ] **Inicialização automática configurada.** Rodar
      `npm run instalar-servico-windows` (PowerShell como Administrador,
      README seção 3) e confirmar em `services.msc` que
      `UDLOG Servico Impressao Local` aparece "Em execução" com
      inicialização "Automático".
- [ ] **Diagnóstico e validação no mini PC de produção** (README seção 4):
  - [ ] `GET /saude` responde `200` com `status: ok`.
  - [ ] `GET /impressoras` (com `Authorization` correto) responde `200` e
        lista **somente** a `EPSON TM-T88VII Receipt` — nenhuma
        impressora fora da allowlist.
  - [ ] Impressora física conectada, com driver correto e testada com
        página de teste do Windows, antes de qualquer uso real via
        totem.
- [ ] **Parada manual do serviço (teste/depuração) sempre por PID
      específico, nunca por nome de processo.** `taskkill /IM node.exe`
      mataria qualquer processo Node.js da máquina, não só este serviço.
      Usar `Get-Process node` para achar o PID certo e encerrar com
      `taskkill /PID <pid especifico> /T /F` — ver README seção 4.6 para
      o passo a passo completo.

- [ ] **Deploy ATÔMICO obrigatório para a demanda
      impressao-arquitetura-producao-ux (2026-09-15) — backend e
      front-end sobem juntos, na mesma janela, nunca separados.** Esta
      mudança tem os dois lados acoplados por contrato de endpoint:
  - Backend novo: `util/ConfiguracaoServicoImpressao.php` (classe
    compartilhada), `app/Controller/ImpressaoAtendimentoController.php`
    (novo método `configuracaoServicoLocal()`) e `public/api/impressao.php`
    (nova acao/rota `impressao.php?acao=configuracao-servico-local`).
  - Front-end novo/alterado: `public/totem/assets/impressao.js` (novo
    arquivo), `public/totem/assets/app.js` (bloco de impressao extraido
    para o arquivo acima), `public/totem/assets/app.css` (novas classes)
    e `public/totem/index.php` (novo `<script>` apontando para
    `impressao.js`).
  - **Nunca publicar um lado sem o outro.** Se o front-end novo for
    publicado antes do endpoint `impressao.php?acao=configuracao-servico-local`
    existir em producao, a tela de impressao real quebra (chamada a um
    endpoint inexistente) ate o backend ser publicado. Da mesma forma,
    publicar o backend novo sem o front-end atualizado deixa o totem
    ainda chamando o fluxo antigo, sem se beneficiar da mudanca e com
    risco de incompatibilidade de contrato entre as duas versoes. Os
    arquivos acima devem ser enviados ao servidor na mesma operacao de
    deploy.
- [ ] **Fluxo real de impressao usa o endpoint de producao, nao o de
      teste/diagnostico.** Desde a demanda impressao-arquitetura-producao-ux
      (2026-09-15), o front-end de producao (public/totem/assets/impressao.js)
      chama impressao.php?acao=configuracao-servico-local (producao) —
      NUNCA mais impressao-teste.php?acao=configuracao-servico-local
      (que continua existindo, mas exclusivo da tela de diagnostico,
      acessada por toque longo). Ao validar um deploy novo, confirmar
      via DevTools/Network que o fluxo real de impressao do atendimento
      nao faz nenhuma chamada a impressao-teste.php.

## 2. Chromium kiosk e scanner Netum SD-2000 (mini PC)

Pontos levantados no planejamento da digitalização de notas fiscais via
Netum SD-2000 (`docs/handoffs/2026-09-03-recebimento-scanner-netum-sd2000.md`).
Todos exigem validação no equipamento físico — nenhum foi confirmado ainda.

- [ ] **Contexto seguro do navegador.** `getUserMedia`/`enumerateDevices`
      só funcionam em contexto seguro (HTTPS ou `localhost`). Confirmar que
      a URL apontada pelo Chromium kiosk (`https://SEUDOMINIO/totem/?totem=...`,
      conforme `README.md`) já é servida por HTTPS válido. A topologia real
      de acesso do totem (domínio Hostgator direto vs. algum componente
      local no mini PC) não está documentada — pendência registrada no
      handoff da demanda.
- [ ] **Permissão de câmera persistente para a origem do kiosk.** Verificar
      se a permissão de câmera concedida ao domínio do totem persiste entre
      reinícios do Chromium, considerando que o kiosk roda com
      `--user-data-dir` fixo (sem modo incógnito). Se a permissão não
      persistir sozinha, avaliar (sem aplicar de antemão) a política de
      Chromium `VideoCaptureAllowedUrls` como opção — não usar nenhuma outra
      flag ou política que não tenha sido levantada no handoff.
- [ ] **NetumScan Pro fechado durante a operação.** Se o software
      proprietário do fabricante (NetumScan Pro) estiver instalado no mini
      PC, garantir que ele não inicia automaticamente com o Windows nem
      roda em paralelo ao kiosk — o SD-2000 é exposto como dispositivo de
      vídeo único e pode ficar "ocupado" por outro processo que já tenha
      aberto o stream.
- [ ] **Diagnóstico se o Netum não aparecer como `videoinput`:**
  - [ ] Checar no Gerenciador de Dispositivos do Windows se o SD-2000 está
        usando driver UVC padrão ou um driver proprietário do fabricante
        (o `enumerateDevices` do navegador só enxerga dispositivos expostos
        via UVC/classe de vídeo padrão do SO).
  - [ ] Checar Configurações de Privacidade > Câmera do Windows, em
        particular a opção "permitir que apps de área de trabalho acessem
        sua câmera".

## 3. Fora de escopo deste checklist

- Não provisionar nem simular nenhum item acima remotamente — isso depende
  de acesso físico ao mini PC e/ou ao painel Hostgator, feito por quem tiver
  essas credenciais.
- Não inventar flags de Chromium ou políticas de Windows além das citadas
  acima; qualquer nova necessidade identificada na prática deve ser
  registrada como pendência em `ia_development_state.md`, não aplicada por
  conta própria.
