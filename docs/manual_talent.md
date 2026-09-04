# manual_talent — API do Talent

> Cole aqui a documentação oficial dessa API. Enquanto este arquivo não
> tiver os dados reais preenchidos abaixo, `app/Rn/TalentClient.php`
> continua com o formato-placeholder e nenhum sub-agente deve tratar esse
> placeholder como contrato confirmado.
>
> Já confirmado nesta conversa: os anexos (CNH, CRLV, notas fiscais) vão
> como **JSON com os arquivos em base64** — não é multipart/form-data.

## URL base

<!-- ex: https://api.talent.com.br/v1 -->

## Autenticação

<!-- tipo (Bearer token, API key, OAuth), header exato, onde obter a credencial -->

## Endpoint: enviar atendimento (final do fluxo, retorna a senha)

- Método e caminho:
- Formato do corpo da requisição (JSON real, incluindo como o campo de
  anexos em base64 é nomeado e estruturado):
- Exemplo de resposta de sucesso (nomes exatos dos campos — senha, protocolo, etc.):
- Formato de erro:

## Endpoint: clientes (se existir, para sincronizar `tb_cliente`)

- Método e caminho:
- Exemplo de resposta:

## Limites e observações

<!-- rate limit, timeout recomendado, tamanho máximo de payload/anexo, ambiente de teste/sandbox -->
