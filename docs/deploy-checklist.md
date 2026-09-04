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
