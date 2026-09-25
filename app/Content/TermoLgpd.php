<?php

namespace App\Content;

/**
 * Fonte canonica UNICA do texto do Aviso de Privacidade / Termo LGPD
 * exibido na tela inicial do Totem (demanda tela-inicial-lgpd-totem,
 * /01-implementacao, 2026-09-24).
 *
 * ============================================================
 * ATENCAO — VERSAO INICIAL PARA DESENVOLVIMENTO, NAO APROVADA PELO DPO
 * ============================================================
 * O texto abaixo foi adaptado do protótipo nao rastreado
 * docs/indexTotem.html (mesma estrutura de 9 secoes), que por sua vez
 * NUNCA teve aprovacao juridica/DPO/produto confirmada em nenhum lugar do
 * projeto (ver docs/handoffs/2026-09-24-tela-inicial-lgpd-totem.md,
 * pendencia BLOQUEANTE nº 3). Este arquivo destrava a IMPLEMENTACAO
 * tecnica (backend + front-end) da tela e do fluxo de aceite — a
 * ATIVACAO em producao (isto e, exibir este texto de fato a um motorista
 * real e tratar o aceite como valido para fins juridicos) continua
 * BLOQUEADA ate aprovacao formal do DPO (Flavio Carvalho,
 * flavio.carvalho@udlog.com.br) e/ou juridico da UDLOG. Nenhuma alteracao
 * de texto deve ser feita aqui sem: (a) atualizar VERSAO abaixo, e (b)
 * registrar a mudanca em ia_development_state.md.
 *
 * ============================================================
 * Interface para consumo (backend E front-end via server-side render)
 * ============================================================
 *   TermoLgpd::texto(): string   — texto integral (HTML simples, mesmas
 *                                   tags usadas no protótipo: <h3>/<p>/<ul>/<li>/<strong>/<a>).
 *   TermoLgpd::versao(): string  — identificador de versao fixo
 *                                   ('2026-09-24-v1'), definido SOMENTE
 *                                   aqui, nunca aceito do front-end.
 *   TermoLgpd::hash(): string    — SHA-256 (hex, 64 caracteres) do texto
 *                                   retornado por texto(), SEMPRE
 *                                   calculado a partir do proprio texto
 *                                   (nunca hardcoded a mao) — usado para
 *                                   detectar se o termo mudou depois de um
 *                                   aceite ja registrado.
 *
 * public/totem/index.php (fora do escopo desta implementacao de backend)
 * deve incluir esta classe diretamente e injetar texto()/versao()/hash()
 * no HTML renderizado no servidor (ex.: via <script type="application/json">
 * ou atributos data-*), nunca duplicar o texto em JS.
 */
class TermoLgpd
{
    /**
     * Identificador de versao do termo — MUDAR sempre que o texto()
     * abaixo for alterado de forma que afete o conteudo/sentido exibido
     * ao motorista (mesmo alteracoes pequenas de texto juridico contam).
     * Formato livre (nao interpretado por logica alguma), so precisa ser
     * unico e crescente no tempo.
     */
    private const VERSAO = '2026-09-24-v1';

    private static ?string $hashCache = null;

    public static function versao(): string
    {
        return self::VERSAO;
    }

    /**
     * SHA-256 (hex) do texto retornado por texto() — calculado uma unica
     * vez por processo (cache estatico em memoria, nunca persistido em
     * arquivo/config separado) e sempre derivado do proprio conteudo, para
     * garantir que hash() nunca possa divergir de texto().
     */
    public static function hash(): string
    {
        if (self::$hashCache === null) {
            self::$hashCache = hash('sha256', self::texto());
        }

        return self::$hashCache;
    }

    public static function texto(): string
    {
        return <<<'TEXTO'
<p>A UDLOG respeita a sua privacidade e realiza o tratamento de dados pessoais em conformidade com a Lei Geral de Proteção de Dados Pessoais — LGPD (Lei nº 13.709/2018).</p>

<p>Este aviso explica quais informações poderão ser coletadas durante o atendimento neste Totem UDLOG, para quais finalidades serão utilizadas e como você poderá exercer seus direitos.</p>

<h3>1. Dados tratados pelo sistema</h3>

<p>Durante o atendimento de Recebimento ou Expedição, o sistema poderá coletar, digitalizar, consultar ou armazenar os seguintes documentos e informações:</p>

<ul>
  <li>Carteira Nacional de Habilitação — CNH;</li>
  <li>Certificado de Registro e Licenciamento de Veículo — CRLV;</li>
  <li>notas fiscais relacionadas à operação;</li>
  <li>nome do motorista;</li>
  <li>CPF e demais dados presentes na CNH;</li>
  <li>placa e demais informações do veículo presentes no CRLV;</li>
  <li>números de documentos e informações necessárias ao recebimento ou à expedição;</li>
  <li>imagens capturadas dos documentos apresentados;</li>
  <li>dados extraídos de códigos QR e outros elementos de validação documental;</li>
  <li>data, horário, totem utilizado, etapas realizadas, eventuais erros e demais registros técnicos e de auditoria do atendimento.</li>
</ul>

<p>A nota fiscal ou os documentos apresentados poderão conter dados de outras pessoas. Ao apresentá-los, o usuário declara possuir autorização ou fundamento legítimo para utilizá-los na operação realizada.</p>

<h3>2. Finalidades do tratamento</h3>

<p>Os dados serão utilizados exclusivamente para finalidades relacionadas à operação logística realizada por meio deste Totem, incluindo:</p>

<ul>
  <li>identificar o motorista e o veículo;</li>
  <li>validar CNH, CRLV e notas fiscais, de forma manual ou automatizada;</li>
  <li>realizar o atendimento de recebimento ou expedição;</li>
  <li>vincular os documentos à operação correspondente;</li>
  <li>enviar informações necessárias aos sistemas e integrações estritamente necessários à execução do atendimento;</li>
  <li>prevenir fraudes, duplicidades e acessos indevidos;</li>
  <li>garantir a segurança do atendimento e a rastreabilidade das operações realizadas neste Totem;</li>
  <li>atender obrigações legais, regulatórias, contratuais e solicitações de autoridades competentes, quando aplicável;</li>
  <li>solucionar falhas e melhorar o funcionamento do sistema.</li>
</ul>

<p>O tratamento será limitado aos dados necessários para essas finalidades.</p>

<h3>3. Forma de tratamento e decisões manuais</h3>

<p>O sistema poderá realizar leitura e validação automatizada dos documentos e códigos apresentados (incluindo QR Codes da CNH/CRLV).</p>

<p>Quando uma informação não puder ser validada automaticamente, o sistema poderá solicitar o preenchimento manual ou encaminhar o atendimento para análise de um colaborador autorizado.</p>

<p>A utilização de leitura automatizada não elimina a possibilidade de correção ou revisão das informações pelo titular ou pela equipe responsável.</p>

<h3>4. Compartilhamento de dados</h3>

<p>Os dados poderão ser compartilhados somente quando estritamente necessário à execução do atendimento, com:</p>

<ul>
  <li>sistemas internos e operacionais da UDLOG;</li>
  <li>empresas contratantes ou participantes da operação logística;</li>
  <li>fornecedores operacionais e de tecnologia que atuem em nome da UDLOG, mediante obrigações de segurança e confidencialidade;</li>
  <li>serviços de validação documental, quando habilitados;</li>
  <li>autoridades públicas, judiciais ou regulatórias, quando houver obrigação legal ou requisição válida.</li>
</ul>

<p>Os dados não serão comercializados em nenhuma hipótese.</p>

<h3>5. Armazenamento e retenção</h3>

<p>Os dados serão armazenados pelo período necessário para executar a operação, manter sua rastreabilidade, atender obrigações legais ou regulatórias e resguardar o exercício regular de direitos.</p>

<p>Encerradas essas finalidades e os prazos aplicáveis, os dados serão eliminados ou anonimizados, salvo quando sua conservação estiver autorizada ou for exigida pela legislação.</p>

<h3>6. Segurança das informações</h3>

<p>A UDLOG adota medidas técnicas e administrativas destinadas a proteger os dados pessoais contra acesso não autorizado, perda, destruição, alteração, divulgação ou tratamento inadequado, incluindo mecanismos de prevenção a fraude e rastreabilidade das operações.</p>

<p>O acesso às informações é restrito a pessoas, sistemas e fornecedores autorizados que necessitem dos dados para executar as finalidades descritas neste aviso.</p>

<h3>7. Direitos do titular</h3>

<p>Nos termos da LGPD, o titular poderá solicitar, quando aplicável:</p>

<ul>
  <li>confirmação da existência de tratamento;</li>
  <li>acesso aos seus dados;</li>
  <li>correção de informações incompletas, inexatas ou desatualizadas;</li>
  <li>informação sobre o compartilhamento de dados;</li>
  <li>anonimização, bloqueio ou eliminação de dados desnecessários, excessivos ou tratados irregularmente;</li>
  <li>oposição ao tratamento realizado em desconformidade com a LGPD;</li>
  <li>revogação do consentimento, quando o consentimento for a base legal aplicável;</li>
  <li>revisão ou esclarecimentos sobre decisões tomadas de forma automatizada;</li>
  <li>demais direitos previstos na legislação aplicável.</li>
</ul>

<p>Alguns dados poderão ser mantidos mesmo após uma solicitação de eliminação quando sua conservação for necessária para cumprimento de obrigação legal ou regulatória, execução contratual ou exercício regular de direitos.</p>

<h3>8. Controlador e canal do Encarregado (DPO)</h3>

<p>A UDLOG é responsável pelas decisões relacionadas ao tratamento dos dados pessoais realizado por meio deste Totem.</p>

<p>Para solicitar informações, correções ou esclarecimentos sobre o tratamento dos seus dados, entre em contato com o Encarregado pelo Tratamento de Dados Pessoais:</p>

<p><strong>Flavio Carvalho</strong><br>
Tecnologia da Informação — DPO<br>
E-mail: <a href="mailto:flavio.carvalho@udlog.com.br">flavio.carvalho@udlog.com.br</a></p>

<h3>9. Ciência do usuário</h3>

<p>Ao marcar a opção de ciência e selecionar <strong>"Li e estou ciente — Continuar"</strong>, você confirma que recebeu e compreendeu as informações deste Aviso de Privacidade.</p>

<p>Quando o consentimento for a base legal aplicável, a continuidade do atendimento também representará sua manifestação livre, informada e inequívoca para as finalidades específicas apresentadas.</p>

<p>Caso não deseje continuar pelo Totem, procure um colaborador da UDLOG para receber orientação sobre uma forma alternativa de atendimento.</p>
TEXTO;
    }
}
