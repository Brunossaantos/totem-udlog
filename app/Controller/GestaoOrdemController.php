<?php

namespace App\Controller;

use App\Dao\AuditoriaDao;
use App\Dao\OrdemColetaGestaoDao;
use App\Dao\OrdemColetaGestaoException;
use App\Dao\OrdemColetaPendenteBaixaDao;
use App\Rn\OrdemColetaBaixaRn;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use Util\GestaoHttp;
use Util\LogSistema;
use Util\OrdemColetaArquivoStorage;

/**
 * Ordens de coleta da Gestao Totem (demanda gestao-totem, F4b/F4c/F4d; perfis
 * `usuario` e `admin`, o guard esta em GestaoContexto). Sem mascara de dados
 * (decisao do usuario). Ativar/inativar SEMPRE por id da OC (nunca por numero).
 *
 * Regras de seguranca:
 *  - Todo filtro vem da query/POST e e validado por WHITELIST aqui (aba, cliente,
 *    numero, datas, ordenacao, pagina, `mostrar`), e o DAO valida de novo. Valor
 *    invalido e ignorado; nunca vira SQL nem URL. Links de aba/paginacao/ordenacao/
 *    "Voltar" e o `retorno` dos formularios sao montados SO dos filtros validados
 *    (o `retorno` recebido e reparseado e revalidado; nunca e usado como URL).
 *  - Escrita: auditoria PENDENTE aberta ANTES de qualquer UPDATE externo (falha ao
 *    abrir = 503 e nada e executado); fechamento OK/SEM_EFEITO/ERRO depois. O
 *    `detalhe` da auditoria so tem status e motivos da allowlist (sem numero,
 *    CNPJ, nome, placa).
 *  - PDF: o caminho do arquivo NUNCA e montado aqui (so por
 *    OrdemColetaArquivoStorage::ler), auditoria aberta antes de ler o arquivo,
 *    nenhum byte sai sem passar por ler() (tamanho, %PDF-, sha256).
 *  - Nenhuma mensagem de excecao, caminho, numero de OC ou CNPJ vai para log,
 *    auditoria, URL ou resposta de erro.
 */
final class GestaoOrdemController
{
    public const POR_PAGINA = OrdemColetaGestaoDao::POR_PAGINA;

    public const CAMINHO_ORDENS = '/gestao/ordens.php';

    public const CAMINHO_ORDEM = '/gestao/ordem.php';

    public const PDF_MAX_BYTES = 5 * 1024 * 1024;

    public const DIAS_RETENCAO_PDF = 15;

    /** Motivos fixos de integridade do PDF (valores de `motivo` no catalogo do LogSistema). */
    public const MOTIVOS_INTEGRIDADE_PDF = [
        'caminho_invalido', 'tamanho_invalido', 'nao_e_pdf', 'sha256_divergente', 'leitura_falhou', 'prefixo_cnpj_divergente',
    ];

    /** slug da URL => rotulo, id do link e descricao. */
    public const ABAS = [
        'ativas' => [
            'rotulo' => 'Ativas',
            'id' => 'aba-ativas',
            'descricao' => 'Ordens de coleta ativas, que ainda podem ser usadas em um check-in. Ordens com o alerta têm mais de 15 dias e talvez precisem ser inativadas.',
        ],
        'ativas_15d' => [
            'rotulo' => 'Ativas há mais de 15 dias',
            'id' => 'aba-ativas-15d',
            'descricao' => 'Ordens ainda ativas criadas há mais de 15 dias, da mais antiga para a mais recente. Confira se alguma precisa ser inativada.',
        ],
        'inativas' => [
            'rotulo' => 'Inativas',
            'id' => 'aba-inativas',
            'descricao' => 'Ordens de coleta inativas (já usadas em um check-in ou inativadas à mão).',
        ],
        'baixas' => [
            'rotulo' => 'Baixas pendentes',
            'id' => 'aba-baixas',
            'descricao' => 'Check-ins aceitos pelo Talent em que a baixa da ordem de coleta não foi concluída. Confira a ordem e marque a baixa como resolvida.',
        ],
    ];

    public const ABA_PADRAO = 'ativas';

    public const ORDENS_VALIDAS = ['numero', 'cliente', 'criado_em'];

    public const MOSTRAR = ['pendentes' => 'Pendentes', 'resolvidas' => 'Resolvidas', 'todas' => 'Todas'];

    private OrdemColetaGestaoDao $ordens;

    public function __construct(private GestaoContexto $ctx)
    {
        // o banco externo so e aberto no primeiro uso; os atendimentos vem do banco do totem
        $this->ordens = new OrdemColetaGestaoDao(null, $ctx->pdo);
    }

    // ------------------------------------------------------------------
    // ordens.php (GET): lista
    // ------------------------------------------------------------------

    /** @return array{view:string,dados:array<string,mixed>,status:int,titulo?:string} */
    public function listar(): array
    {
        $hoje = $this->hoje();
        try {
            $clientes = null;
            // cliente validado por consulta direta por id (nunca pela lista limitada do select)
            $f = $this->lerFiltros($_GET, $hoje, true);

            $periodo = $this->filtrosDePeriodo($f);
            $contagens = $this->ordens->contagensPorAba($periodo);
            $baixasDao = new OrdemColetaPendenteBaixaDao($this->ctx->pdo);
            $totalBaixasPendentes = $baixasDao->contar('pendentes', $f['de'] === '' ? null : $f['de'], $f['ate'] === '' ? null : $f['ate']);

            $itens = [];
            $linhasBaixa = [];
            if ($f['aba'] === 'baixas') {
                // "pendentes" ja foi contado acima (mesmo periodo): nao repete
                $total = $f['mostrar'] === 'pendentes'
                    ? $totalBaixasPendentes
                    : $baixasDao->contar($f['mostrar'], $f['de'] === '' ? null : $f['de'], $f['ate'] === '' ? null : $f['ate']);
                $truncado = false;
                $rotuloTotal = number_format($total, 0, ',', '.');
                $paginas = max(1, (int) ceil($total / self::POR_PAGINA));
                if ($f['pagina'] > $paginas) {
                    GestaoHttp::redirecionar(self::CAMINHO_ORDENS . '?' . $this->query($f, ['pagina' => $paginas]));
                }
                $linhasBaixa = $this->linhasBaixa($baixasDao, $f, $hoje);
            } elseif ($f['cliente_invalido']) {
                // cliente de formato invalido ou inexistente: NUNCA alarga a lista (vazio)
                $total = 0;
                $truncado = false;
                $rotuloTotal = '0';
                $paginas = 1;
            } else {
                $filtrosDao = $this->filtrosDao($f);
                if ($f['cliente'] === '' && $f['numero'] === '' && $f['inativada_de'] === '' && $f['inativada_ate'] === '') {
                    // so periodo: e a mesma contagem da aba, ja feita acima
                    $cont = $contagens[$f['aba']];
                } else {
                    $cont = $this->ordens->contar($filtrosDao, $f['aba']);
                }
                $total = (int) $cont['total'];
                $truncado = (bool) $cont['truncado'];
                $rotuloTotal = (string) $cont['rotulo'];
                $paginas = max(1, min(OrdemColetaGestaoDao::MAX_PAGINA, (int) ceil($total / self::POR_PAGINA)));
                if ($f['pagina'] > $paginas) {
                    GestaoHttp::redirecionar(self::CAMINHO_ORDENS . '?' . $this->query($f, ['pagina' => $paginas]));
                }
                $itens = $this->decorarOrdens($this->ordens->listar($filtrosDao, $f['aba'], $f['pagina']), $f, $hoje);
            }
            if ($f['aba'] !== 'baixas') {
                // so as abas com filtro de cliente precisam da lista do select (uma vez por requisicao)
                $clientes = $this->clientesDoSelect($f['cliente']);
            }
        } catch (Throwable $e) {
            $this->registrarFalhaExterno($e);

            return $this->paginaComErro();
        }

        $quantos = $f['aba'] === 'baixas' ? count($linhasBaixa) : count($itens);
        $primeiro = $quantos === 0 ? 0 : ($f['pagina'] - 1) * self::POR_PAGINA + 1;
        $ultimo = $quantos === 0 ? 0 : $primeiro + $quantos - 1;
        $substantivo = $f['aba'] === 'baixas' ? ['baixa', 'baixas'] : ['ordem', 'ordens'];
        if ($total === 0) {
            $contador = $f['aba'] === 'baixas' ? 'Nenhuma baixa.' : 'Nenhuma ordem.';
        } else {
            $contador = 'Exibindo ' . $primeiro . ' a ' . $ultimo . ' de ' . ($truncado ? $rotuloTotal : (string) $total) . ' ' . ($total === 1 && !$truncado ? $substantivo[0] : $substantivo[1]) . '.';
        }

        $abasView = [];
        foreach (self::ABAS as $slug => $def) {
            if ($slug === 'baixas') {
                $rotulo = number_format($totalBaixasPendentes, 0, ',', '.');
                $n = $totalBaixasPendentes;
                $trunc = false;
            } else {
                $rotulo = (string) $contagens[$slug]['rotulo'];
                $n = (int) $contagens[$slug]['total'];
                $trunc = (bool) $contagens[$slug]['truncado'];
            }
            $abasView[] = [
                'slug' => $slug,
                'id' => $def['id'],
                'rotulo' => $def['rotulo'],
                'contagem' => $rotulo,
                'contagem_sr' => $rotulo . ' ' . ($n === 1 && !$trunc ? 'registro' : 'registros'),
                'href' => self::CAMINHO_ORDENS . '?' . $this->queryAba($f, $slug),
                'ativa' => $slug === $f['aba'],
            ];
        }

        $opcoesCliente = [];
        foreach ($clientes ?? [] as $c) {
            $opcoesCliente[] = ['valor' => (string) $c['id'], 'rotulo' => $c['razao_social'] . ' (' . self::formatarCnpj($c['cnpj']) . ')'];
        }

        $temFiltros = $f['cliente'] !== '' || $f['cliente_invalido'] ||$f['numero'] !== '' || $f['de'] !== '' || $f['ate'] !== '' || $f['inativada_de'] !== '' || $f['inativada_ate'] !== '' || ($f['aba'] === 'baixas' && $f['mostrar'] !== 'pendentes');

        return [
            'view' => 'ordens',
            'status' => 200,
            'titulo' => 'Ordens de coleta',
            'dados' => [
                'erroCarga' => false,
                'aba' => $f['aba'],
                'abas' => $abasView,
                'abaDescricao' => self::ABAS[$f['aba']]['descricao'],
                'filtros' => $f,
                'errosFiltro' => $f['erros'],
                'opcoesCliente' => $opcoesCliente,
                'opcoesMostrar' => self::MOSTRAR,
                'itens' => $itens,
                'linhasBaixa' => $linhasBaixa,
                'cabecalhos' => $f['aba'] === 'baixas' ? [] : $this->cabecalhosOrdenacao($f),
                'contador' => $contador,
                'total' => $total,
                'temFiltros' => $temFiltros,
                'pagina' => $f['pagina'],
                'paginas' => $paginas,
                'urlAnterior' => $f['pagina'] > 1 ? self::CAMINHO_ORDENS . '?' . $this->query($f, ['pagina' => $f['pagina'] - 1]) : null,
                'urlProxima' => $f['pagina'] < $paginas ? self::CAMINHO_ORDENS . '?' . $this->query($f, ['pagina' => $f['pagina'] + 1]) : null,
                'urlLimpar' => self::CAMINHO_ORDENS . '?' . $this->queryAba($f, $f['aba'], true),
                'retorno' => $this->query($f),
                'diasRetencaoPdf' => self::DIAS_RETENCAO_PDF,
            ],
        ];
    }

    // ------------------------------------------------------------------
    // ordem.php (GET, ?id=): detalhe
    // ------------------------------------------------------------------

    /** @return array{view:string,dados:array<string,mixed>,status:int,titulo?:string} */
    public function detalhe(): array
    {
        $hoje = $this->hoje();
        $f = $this->lerFiltros($_GET, $hoje, false);
        $id = self::idOc(GestaoContexto::query('id'));
        try {
            $oc = $id === null ? null : $this->ordens->buscarPorId($id);
        } catch (Throwable $e) {
            $this->registrarFalhaExterno($e);

            return [
                'view' => 'ordem',
                'status' => 503,
                'titulo' => 'Ordem de coleta',
                'dados' => ['erroCarga' => true, 'urlVoltar' => self::CAMINHO_ORDENS . '?' . $this->query($f)],
            ];
        }
        if ($oc === null) {
            GestaoHttp::redirecionar(self::CAMINHO_ORDENS . '?' . $this->query($f, ['msg' => 'oc_nao_encontrada']));
        }

        // Aviso + formulario de confirmacao (segundo passo de inativar/ativar), so se a
        // acao ainda faz sentido para o estado atual e ainda ha o que confirmar.
        $confirmacao = null;
        $acaoPedida = GestaoContexto::query('confirmar');
        if (($acaoPedida === 'inativar' && $oc['status'] === 'ATIVA') || ($acaoPedida === 'ativar' && $oc['status'] === 'INATIVA')) {
            try {
                $n = $acaoPedida === 'inativar'
                    ? $this->ordens->atendimentosEmAndamentoDaOc((string) $oc['cnpj'], (string) $oc['numero'])
                    : $this->ordens->atendimentoConcluidoDaOc((string) $oc['cnpj'], (string) $oc['numero']);
            } catch (Throwable $e) {
                $this->registrarFalhaExterno($e);
                $n = 0;
            }
            if ($n > 0) {
                $confirmacao = [
                    'acao' => $acaoPedida,
                    'n' => $n,
                    'texto' => $acaoPedida === 'inativar'
                        ? 'Há ' . $n . ' ' . ($n === 1 ? 'atendimento' : 'atendimentos') . ' em andamento com esta ordem. Confirme para inativar mesmo assim.'
                        : 'Esta ordem já foi usada em um check-in concluído. Confirme para ativar mesmo assim.',
                ];
            }
        }

        $agora = $this->agora();
        $inativadaEm = $this->data($oc['inativada_em'] ?? null);
        $pdf = 'ausente';
        $pdfTexto = 'Esta ordem não tem PDF anexado.';
        $pdfAviso = '';
        if ($oc['tem_pdf']) {
            $pdf = 'disponivel';
            if ($oc['status'] === 'INATIVA' && $inativadaEm !== null) {
                $pdfAviso = 'Este PDF será apagado em ' . $inativadaEm->modify('+' . self::DIAS_RETENCAO_PDF . ' days')->format('d/m/Y') . ' (' . self::DIAS_RETENCAO_PDF . ' dias depois da inativação).';
            } elseif ($oc['status'] === 'ATIVA') {
                $pdfAviso = 'Depois que a ordem for inativada, o PDF dela é apagado em ' . self::DIAS_RETENCAO_PDF . ' dias.';
            }
        } elseif ($oc['status'] === 'INATIVA' && $inativadaEm !== null && $inativadaEm->modify('+' . self::DIAS_RETENCAO_PDF . ' days') <= $agora) {
            $pdf = 'apagado';
            $pdfTexto = 'O PDF foi apagado automaticamente, ' . self::DIAS_RETENCAO_PDF . ' dias depois da inativação.';
        }

        [$idadeTexto, $atencao] = $this->idade($oc, $agora);

        return [
            'view' => 'ordem',
            'status' => 200,
            'titulo' => 'Ordem de coleta',
            'dados' => [
                'erroCarga' => false,
                'ordem' => $oc,
                'situacaoSlug' => $oc['status'] === 'ATIVA' ? 'ativa' : 'inativa',
                'situacaoRotulo' => $oc['status'] === 'ATIVA' ? 'Ativa' : 'Inativa',
                'idadeTexto' => $idadeTexto,
                'idadeAtencao' => $atencao,
                'cnpjCliente' => self::formatarCnpj((string) $oc['cnpj']),
                'cnpjTransportadora' => self::formatarCnpj((string) ($oc['transportadora_cnpj'] ?? '')),
                'confirmacao' => $confirmacao,
                'pdf' => $pdf,
                'pdfTexto' => $pdfTexto,
                'pdfAviso' => $pdfAviso,
                'diasRetencaoPdf' => self::DIAS_RETENCAO_PDF,
                'urlVoltar' => self::CAMINHO_ORDENS . '?' . $this->query($f),
                'retorno' => $this->query($f),
            ],
        ];
    }

    // ------------------------------------------------------------------
    // ordem-status.php (POST): ativar / inativar por id
    // ------------------------------------------------------------------

    public function alterarStatus(): void
    {
        $id = self::idOc(GestaoContexto::post('id_ordem'));
        $acao = GestaoContexto::post('acao');
        $visto = GestaoContexto::post('status_visto');
        if ($id === null || !in_array($acao, ['ativar', 'inativar'], true) || !in_array($visto, ['ATIVA', 'INATIVA'], true)) {
            $this->pedidoInvalido();
        }
        $confirmou = GestaoContexto::post('confirmar') === '1';
        $deDetalhe = GestaoContexto::post('origem') === 'detalhe';
        $f = $this->filtrosDoRetorno();

        $inativar = $acao === 'inativar';
        $de = $inativar ? 'ATIVA' : 'INATIVA';
        $para = $inativar ? 'INATIVA' : 'ATIVA';
        $acaoAuditoria = $inativar ? 'OC_INATIVAR' : 'OC_ATIVAR';
        $base = ['status_de' => $de, 'status_para' => $para];
        $auditoria = new AuditoriaDao($this->ctx->pdo);
        $ip = $this->ctx->ip();
        $usuario = $this->ctx->idUsuario();

        // (a) rele a OC por id
        try {
            $oc = $this->ordens->buscarPorId($id);
        } catch (Throwable $e) {
            $this->registrarFalhaExterno($e);
            $this->auditarEvento($auditoria, $usuario, $acaoAuditoria, $id, 'ERRO', $base + ['motivo_oc' => 'externo_indisponivel'], $ip);
            $this->externoIndisponivel();
        }
        if ($oc === null) {
            $this->auditarEvento($auditoria, $usuario, $acaoAuditoria, $id, 'SEM_EFEITO', $base + ['motivo_oc' => 'oc_inexistente'], $ip);
            $this->voltarLista($f, 'oc_nao_encontrada');
        }

        // (b) a tela estava desatualizada
        if ((string) $oc['status'] !== $visto) {
            $this->auditarEvento($auditoria, $usuario, $acaoAuditoria, $id, 'SEM_EFEITO', $base + ['motivo_oc' => 'estado_mudou'], $ip);
            $this->voltar($deDetalhe, $id, $f, 'oc_estado_mudou');
        }
        // (c) ja esta no estado pedido
        if ((string) $oc['status'] === $para) {
            $this->auditarEvento($auditoria, $usuario, $acaoAuditoria, $id, 'SEM_EFEITO', $base + ['motivo_oc' => 'ja_no_estado'], $ip);
            $this->voltar($deDetalhe, $id, $f, $inativar ? 'oc_sem_efeito_ja_inativa' : 'oc_sem_efeito_ja_ativa');
        }

        // (d) aviso com confirmacao explicita (sem bloquear)
        try {
            $n = $inativar
                ? $this->ordens->atendimentosEmAndamentoDaOc((string) $oc['cnpj'], (string) $oc['numero'])
                : $this->ordens->atendimentoConcluidoDaOc((string) $oc['cnpj'], (string) $oc['numero']);
        } catch (Throwable $e) {
            $this->registrarFalhaExterno($e);
            $this->externoIndisponivel();
        }
        $detalheFinal = $base;
        if ($n > 0) {
            if (!$confirmou) {
                GestaoHttp::redirecionar(self::CAMINHO_ORDEM . '?' . $this->queryDetalhe($id, $f, ['confirmar' => $acao, 'msg' => 'oc_confirmacao_necessaria']));
            }
            $detalheFinal['motivo_oc'] = $inativar ? 'confirmado_andamento' : 'confirmado_ja_baixada';
        }

        // (e) auditoria PENDENTE ANTES de qualquer UPDATE externo
        try {
            $idAuditoria = $auditoria->abrir($usuario, $acaoAuditoria, 'ordem_coleta', $id, $base, $ip);
        } catch (Throwable $e) {
            $this->auditoriaIndisponivel($e);
        }

        // (f) UPDATE atomico por id (CAS no status).
        // Limite conhecido (S6, so documentado): uma excecao DEPOIS de o UPDATE ter sido
        // efetivado (ex.: queda da conexao na releitura) fecha a auditoria como ERRO mesmo
        // com a ordem ja alterada; reler o estado nao distingue "fui eu" de "foi outra pessoa".
        try {
            $resultado = $inativar ? $this->ordens->inativarPorId($id) : $this->ordens->ativarPorId($id);
        } catch (Throwable $e) {
            $this->fecharAuditoria($auditoria, $idAuditoria, 'ERRO', $base + ['motivo_oc' => 'externo_indisponivel']);
            $this->registrarFalhaExterno($e);
            $this->externoIndisponivel();
        }

        // (g) fechamento + (h) PRG
        if ($resultado === OrdemColetaGestaoDao::R_EFETIVADO) {
            $this->fecharAuditoria($auditoria, $idAuditoria, 'OK', $detalheFinal);
            $this->voltar($deDetalhe, $id, $f, $inativar ? 'oc_inativada' : 'oc_ativada');
        }
        if ($resultado === OrdemColetaGestaoDao::R_JA_NO_ESTADO) {
            $this->fecharAuditoria($auditoria, $idAuditoria, 'SEM_EFEITO', $base + ['motivo_oc' => 'ja_no_estado']);
            $this->voltar($deDetalhe, $id, $f, $inativar ? 'oc_sem_efeito_ja_inativa' : 'oc_sem_efeito_ja_ativa');
        }
        if ($resultado === OrdemColetaGestaoDao::R_INEXISTENTE) {
            $this->fecharAuditoria($auditoria, $idAuditoria, 'SEM_EFEITO', $base + ['motivo_oc' => 'oc_inexistente']);
            $this->voltarLista($f, 'oc_nao_encontrada');
        }
        $this->fecharAuditoria($auditoria, $idAuditoria, 'SEM_EFEITO', $base + ['motivo_oc' => 'estado_mudou']);
        $this->voltar($deDetalhe, $id, $f, 'oc_estado_mudou');
    }

    // ------------------------------------------------------------------
    // ordem-baixa.php (POST): marcar baixa pendente como resolvida
    // ------------------------------------------------------------------

    public function resolverBaixa(): void
    {
        $id = GestaoContexto::inteiroPositivo(GestaoContexto::post('id_baixa'));
        if ($id === null) {
            $this->pedidoInvalido();
        }
        $f = $this->filtrosDoRetorno();
        $f['aba'] = 'baixas';
        $auditoria = new AuditoriaDao($this->ctx->pdo);
        $ip = $this->ctx->ip();
        $usuario = $this->ctx->idUsuario();

        try {
            $idAuditoria = $auditoria->abrir($usuario, 'OC_BAIXA_RESOLVER', 'oc_baixa', $id, [], $ip);
        } catch (Throwable $e) {
            $this->auditoriaIndisponivel($e);
        }

        $rn = new OrdemColetaBaixaRn(new OrdemColetaPendenteBaixaDao($this->ctx->pdo), $this->ordens);
        try {
            $r = $rn->resolver($id);
        } catch (Throwable $e) {
            $this->fecharAuditoria($auditoria, $idAuditoria, 'ERRO', []);
            error_log('gestao: oc_baixa_resolver_falhou ' . get_class($e));
            LogSistema::registrar('gestao_erro_interno', ['excecao' => $e, 'http' => 500, 'motivo' => 'erro_banco']);
            $this->voltarLista($f, 'erro_interno');
        }

        switch ($r['resultado']) {
            case OrdemColetaBaixaRn::RESOLVIDA:
                $this->fecharAuditoria($auditoria, $idAuditoria, 'OK', []);
                $this->voltarLista($f, 'oc_baixa_resolvida');
                break;
            case OrdemColetaBaixaRn::JA_RESOLVIDA:
                $this->fecharAuditoria($auditoria, $idAuditoria, 'SEM_EFEITO', ['motivo_oc' => 'ja_no_estado']);
                $this->voltarLista($f, 'oc_baixa_ja_resolvida');
                break;
            case OrdemColetaBaixaRn::INEXISTENTE:
                $this->fecharAuditoria($auditoria, $idAuditoria, 'SEM_EFEITO', ['motivo_oc' => 'oc_inexistente']);
                $this->voltarLista($f, 'oc_baixa_nao_encontrada');
                break;
        }

        $motivo = (string) ($r['motivo'] ?? '');
        if (!in_array($motivo, ['cliente_ausente', 'oc_inexistente', 'oc_ambigua', 'oc_ativa', 'externo_indisponivel'], true)) {
            $this->fecharAuditoria($auditoria, $idAuditoria, 'ERRO', []);
            $this->voltarLista($f, 'erro_interno');
        }
        if ($motivo === 'externo_indisponivel') {
            LogSistema::registrar('banco_coletas_indisponivel');
            $this->fecharAuditoria($auditoria, $idAuditoria, 'ERRO', ['motivo_oc' => $motivo]);
        } else {
            $this->fecharAuditoria($auditoria, $idAuditoria, 'SEM_EFEITO', ['motivo_oc' => $motivo]);
        }
        $this->voltarLista($f, 'oc_baixa_recusada_' . $motivo);
    }

    // ------------------------------------------------------------------
    // ordem-pdf.php (POST): ver o PDF da OC
    // ------------------------------------------------------------------

    public function pdf(): void
    {
        $id = self::idOc(GestaoContexto::post('id_ordem'));
        if ($id === null) {
            $this->pdfNaoEncontrado();
        }
        try {
            $oc = $this->ordens->buscarPorId($id);
            $registro = $oc === null ? null : $this->ordens->arquivoDaOc($id);
        } catch (Throwable $e) {
            $this->registrarFalhaExterno($e);
            $this->externoIndisponivel();
        }
        if ($oc === null || $registro === null) {
            $this->pdfNaoEncontrado();
        }

        $auditoria = new AuditoriaDao($this->ctx->pdo);
        try {
            $idAuditoria = $auditoria->abrir($this->ctx->idUsuario(), 'OC_VER_PDF', 'ordem_coleta', $id, [], $this->ctx->ip());
        } catch (Throwable $e) {
            $this->auditoriaIndisponivel($e);
        }

        // O prefixo de 14 digitos do caminho tem de ser o CNPJ do cliente da OC (c.cnpj):
        // registro adulterado apontando para o PDF de outro cliente e erro de integridade
        // (checado ANTES de tocar no disco).
        $caminhoRegistro = (string) $registro['caminho_relativo'];
        $cnpjOc = (string) $oc['cnpj'];
        if (preg_match('/\A\d{14}\z/D', $cnpjOc) !== 1 || strncmp($caminhoRegistro, $cnpjOc, 14) !== 0 || ($caminhoRegistro[14] ?? '') !== '/') {
            $leitura = ['bytes' => null, 'motivo' => 'prefixo_cnpj_divergente'];
        } else {
            $leitura = (new OrdemColetaArquivoStorage())->ler($caminhoRegistro, self::PDF_MAX_BYTES, (string) $registro['sha256']);
        }
        $bytes = $leitura['bytes'];
        if ($bytes === null) {
            if ($leitura['motivo'] === 'arquivo_ausente') {
                $this->fecharAuditoria($auditoria, $idAuditoria, 'SEM_EFEITO', ['motivo_oc' => 'arquivo_ausente']);
                GestaoHttp::redirecionar(self::CAMINHO_ORDEM . '?id=' . $id . '&msg=oc_pdf_indisponivel');
            }
            // caminho invalido, tamanho, leitura, nao e PDF, sha256 divergente ou prefixo de
            // CNPJ divergente: integridade. So o codigo FIXO do motivo vai para o LogSistema.
            $motivoLog = in_array($leitura['motivo'], self::MOTIVOS_INTEGRIDADE_PDF, true) ? (string) $leitura['motivo'] : 'falha_inesperada';
            $this->fecharAuditoria($auditoria, $idAuditoria, 'ERRO', []);
            error_log('gestao: oc_pdf_integridade');
            LogSistema::registrar('gestao_erro_interno', ['http' => 500, 'motivo' => $motivoLog]);
            GestaoHttp::negar(500, 'PDF indisponível', 'Não foi possível abrir o PDF desta ordem agora. Tente novamente em instantes.', [], GestaoHttp::ACAO_INICIO);
        }

        if (headers_sent()) {
            $this->fecharAuditoria($auditoria, $idAuditoria, 'ERRO', []);
            error_log('gestao: oc_pdf_cabecalhos_ja_enviados');
            LogSistema::registrar('gestao_erro_interno', ['http' => 500, 'motivo' => 'falha_inesperada']);
            exit;
        }
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        http_response_code(200);
        header('Content-Type: application/pdf');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, private');
        header('Pragma: no-cache');
        header('Content-Disposition: attachment; filename="oc-' . $id . '.pdf"');
        header('Content-Length: ' . strlen($bytes));
        header("Content-Security-Policy: default-src 'none'; sandbox; frame-ancestors 'none'");
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Accept-Ranges: none');
        echo $bytes;
        $this->fecharAuditoria($auditoria, $idAuditoria, 'OK', []);
        exit;
    }

    // ------------------------------------------------------------------
    // Filtros (whitelist)
    // ------------------------------------------------------------------

    /** @var list<array{id:int,razao_social:string,cnpj:string}>|null cache da requisicao */
    private ?array $clientesCache = null;

    /**
     * Clientes do select (no maximo MAX_CLIENTES_SELECT, uma consulta por requisicao).
     * Se o cliente filtrado (ja validado) ficou fora do limite, entra no fim da lista
     * por consulta direta, para o select refletir o filtro aplicado.
     *
     * @return list<array{id:int,razao_social:string,cnpj:string}>
     */
    private function clientesDoSelect(string $atual): array
    {
        if ($this->clientesCache === null) {
            $this->clientesCache = $this->ordens->listarClientes();
        }
        $lista = $this->clientesCache;
        if ($atual !== '') {
            foreach ($lista as $c) {
                if ((string) $c['id'] === $atual) {
                    return $lista;
                }
            }
            $c = $this->ordens->clientePorId((int) $atual);
            if ($c !== null) {
                $lista[] = $c;
            }
        }

        return $lista;
    }

    /**
     * Le e valida os filtros de $fonte ($_GET ou o `retorno` reparseado). Valor
     * invalido e IGNORADO (com mensagem no campo, se for numero ou data). O CLIENTE e
     * a excecao: com $verificarCliente ele e conferido por consulta direta por id
     * (sem depender do limite do select); formato invalido ou inexistente NAO e
     * ignorado: vira `cliente_invalido` (mensagem no campo e lista VAZIA, nunca
     * alargada). Sem $verificarCliente so se confere o formato (sem banco).
     *
     * @param array<array-key,mixed> $fonte
     * @return array{aba:string,cliente:string,cliente_invalido:bool,numero:string,de:string,ate:string,inativada_de:string,inativada_ate:string,mostrar:string,ordem:string,dir:string,pagina:int,erros:array<string,string>}
     */
    private function lerFiltros(array $fonte, DateTimeImmutable $hoje, bool $verificarCliente): array
    {
        $texto = static function (string $chave) use ($fonte): string {
            $v = $fonte[$chave] ?? '';

            return is_string($v) ? $v : '';
        };
        $erros = [];

        $aba = $texto('aba');
        $aba = isset(self::ABAS[$aba]) ? $aba : self::ABA_PADRAO;

        $cliente = '';
        $clienteInvalido = false;
        $clienteBruto = $texto('cliente');
        if ($aba !== 'baixas' && $clienteBruto !== '') {
            $formatoOk = preg_match('/\A[1-9][0-9]{0,17}\z/D', $clienteBruto) === 1;
            if ($formatoOk && (!$verificarCliente || $this->ordens->clientePorId((int) $clienteBruto) !== null)) {
                $cliente = $clienteBruto;
            } elseif ($verificarCliente) {
                $clienteInvalido = true;
                $erros['cliente'] = 'O cliente é inválido ou não existe mais. Escolha um cliente da lista.';
            }
        }

        $numero = '';
        $bruto = trim($texto('numero'));
        if ($aba !== 'baixas' && $bruto !== '') {
            if (preg_match('/\A[A-Za-z0-9._\/-]{1,' . OrdemColetaGestaoDao::NUMERO_MAX . '}\z/D', $bruto) === 1) {
                $numero = $bruto;
            } else {
                $erros['numero'] = 'O número é inválido e foi ignorado. Use só letras, números, ponto, barra, hífen ou sublinhado (até ' . OrdemColetaGestaoDao::NUMERO_MAX . ' caracteres).';
            }
        }

        $de = $this->lerData($texto('de'), 'de', 'A data inicial', $erros);
        $ate = $this->lerData($texto('ate'), 'ate', 'A data final', $erros);
        if ($de !== '' && $ate !== '' && $de > $ate) {
            $de = '';
            $ate = '';
            unset($erros['de']);
            $erros['ate'] = 'A data final não pode ser anterior à inicial. O período foi ignorado.';
        }
        $inativadaDe = '';
        $inativadaAte = '';
        if ($aba === 'inativas') {
            $inativadaDe = $this->lerData($texto('inativada_de'), 'inativada_de', 'A data inicial da inativação', $erros);
            $inativadaAte = $this->lerData($texto('inativada_ate'), 'inativada_ate', 'A data final da inativação', $erros);
            if ($inativadaDe !== '' && $inativadaAte !== '' && $inativadaDe > $inativadaAte) {
                $inativadaDe = '';
                $inativadaAte = '';
                unset($erros['inativada_de']);
                $erros['inativada_ate'] = 'A data final da inativação não pode ser anterior à inicial. O período foi ignorado.';
            }
        }

        $mostrar = $texto('mostrar');
        $mostrar = $aba === 'baixas' && isset(self::MOSTRAR[$mostrar]) ? $mostrar : 'pendentes';

        $ordem = $texto('ordem');
        $ordem = in_array($ordem, self::ORDENS_VALIDAS, true) ? $ordem : 'criado_em';
        $dir = strtolower($texto('dir'));
        if ($dir !== 'asc' && $dir !== 'desc') {
            $dir = self::dirPadrao($aba, $ordem);
        }
        if ($aba === 'baixas') {
            $ordem = 'criado_em';
            $dir = 'desc';
        }

        $pagina = GestaoContexto::inteiroPositivo($texto('pagina')) ?? 1;

        return [
            'aba' => $aba, 'cliente' => $cliente, 'cliente_invalido' => $clienteInvalido, 'numero' => $numero,
            'de' => $de, 'ate' => $ate, 'inativada_de' => $inativadaDe, 'inativada_ate' => $inativadaAte,
            'mostrar' => $mostrar, 'ordem' => $ordem, 'dir' => $dir, 'pagina' => $pagina, 'erros' => $erros,
        ];
    }

    /** @param array<string,string> $erros */
    private function lerData(string $texto, string $campo, string $rotulo, array &$erros): string
    {
        if ($texto === '') {
            return '';
        }
        if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $texto) === 1) {
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', $texto);
            if ($d !== false && $d->format('Y-m-d') === $texto && (int) $d->format('Y') >= 1970 && (int) $d->format('Y') <= 2100) {
                return $texto;
            }
        }
        $erros[$campo] = $rotulo . ' é inválida e foi ignorada. Use o formato AAAA-MM-DD.';

        return '';
    }

    /** Direcao padrao: numero e cliente A-Z; criada em, mais recente primeiro (na aba "ativas há mais de 15 dias", mais antiga primeiro). */
    private static function dirPadrao(string $aba, string $ordem): string
    {
        if ($ordem === 'numero' || $ordem === 'cliente') {
            return 'asc';
        }

        return $aba === 'ativas_15d' ? 'asc' : 'desc';
    }

    /** @param array<string,mixed> $f @return array<string,string> */
    private function filtrosDePeriodo(array $f): array
    {
        $r = [];
        if ($f['de'] !== '') {
            $r['de'] = $f['de'];
        }
        if ($f['ate'] !== '') {
            $r['ate'] = $f['ate'];
        }

        return $r;
    }

    /** @param array<string,mixed> $f @return array<string,mixed> */
    private function filtrosDao(array $f): array
    {
        $dao = $this->filtrosDePeriodo($f);
        foreach (['inativada_de', 'inativada_ate', 'numero'] as $k) {
            if ($f[$k] !== '') {
                $dao[$k] = $f[$k];
            }
        }
        if ($f['cliente'] !== '') {
            $dao['cliente_id'] = (int) $f['cliente'];
        }
        $dao['ordem'] = $f['ordem'];
        $dao['dir'] = strtoupper($f['dir']);

        return $dao;
    }

    /**
     * Query string (sem "?") so com filtros validados, em ordem fixa, omitindo o que
     * e padrao. $extra: pagina (substitui) e msg (so codigo conhecido).
     *
     * @param array<string,mixed> $f
     * @param array<string,mixed> $extra
     */
    private function query(array $f, array $extra = []): string
    {
        $v = array_merge($f, $extra);
        $q = ['aba' => (string) $v['aba']];
        foreach (['cliente', 'numero', 'de', 'ate', 'inativada_de', 'inativada_ate'] as $k) {
            if (($v[$k] ?? '') !== '') {
                $q[$k] = (string) $v[$k];
            }
        }
        if ($v['aba'] === 'baixas') {
            if ($v['mostrar'] !== 'pendentes') {
                $q['mostrar'] = (string) $v['mostrar'];
            }
        } elseif ($v['ordem'] !== 'criado_em' || $v['dir'] !== self::dirPadrao((string) $v['aba'], 'criado_em')) {
            $q['ordem'] = (string) $v['ordem'];
            $q['dir'] = (string) $v['dir'];
        }
        if ((int) ($v['pagina'] ?? 1) > 1) {
            $q['pagina'] = (string) (int) $v['pagina'];
        }
        if (isset($extra['msg']) && isset(GestaoContexto::MENSAGENS[$extra['msg']])) {
            $q['msg'] = (string) $extra['msg'];
        }

        return http_build_query($q, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Link de aba: troca a aba e leva so o periodo (a contagem das abas e por periodo);
     * $limpar tambem zera o periodo (botao Limpar filtros).
     *
     * @param array<string,mixed> $f
     */
    private function queryAba(array $f, string $slug, bool $limpar = false): string
    {
        $troca = ['aba' => $slug, 'cliente' => '', 'numero' => '', 'inativada_de' => '', 'inativada_ate' => '', 'mostrar' => 'pendentes', 'ordem' => 'criado_em', 'dir' => self::dirPadrao($slug, 'criado_em'), 'pagina' => 1];
        if ($limpar) {
            $troca['de'] = '';
            $troca['ate'] = '';
        }

        return $this->query(array_merge($f, $troca));
    }

    /** Query do detalhe: id + filtros validados (+ confirmar/msg). @param array<string,mixed> $f @param array<string,string> $extra */
    private function queryDetalhe(int $id, array $f, array $extra = []): string
    {
        $q = 'id=' . $id . '&' . $this->query($f);
        if (isset($extra['confirmar']) && in_array($extra['confirmar'], ['ativar', 'inativar'], true)) {
            $q .= '&confirmar=' . rawurlencode($extra['confirmar']);
        }
        if (isset($extra['msg']) && isset(GestaoContexto::MENSAGENS[$extra['msg']])) {
            $q .= '&msg=' . rawurlencode($extra['msg']);
        }

        return $q;
    }

    /** `retorno` do formulario: reparseado e revalidado (nunca usado como URL). @return array<string,mixed> */
    private function filtrosDoRetorno(): array
    {
        $bruto = GestaoContexto::post('retorno');
        $arr = [];
        if ($bruto !== '' && strlen($bruto) <= 600) {
            parse_str($bruto, $arr);
        }

        return $this->lerFiltros(is_array($arr) ? $arr : [], $this->hoje(), false);
    }

    /**
     * Cabecalhos ordenaveis (numero, cliente, criada em): link que alterna a direcao,
     * `aria-sort` na coluna ativa.
     *
     * @param array<string,mixed> $f
     * @return array<string,array{href:string,aria:string,rotulo:string,ativo:bool}>
     */
    private function cabecalhosOrdenacao(array $f): array
    {
        $rotulos = ['numero' => 'Número', 'cliente' => 'Cliente', 'criado_em' => 'Criada em'];
        $saida = [];
        foreach ($rotulos as $chave => $rotulo) {
            $ativo = $f['ordem'] === $chave;
            $novaDir = $ativo ? ($f['dir'] === 'asc' ? 'desc' : 'asc') : self::dirPadrao($f['aba'], $chave);
            $saida[$chave] = [
                'href' => self::CAMINHO_ORDENS . '?' . $this->query(array_merge($f, ['ordem' => $chave, 'dir' => $novaDir, 'pagina' => 1])),
                'aria' => $ativo ? ($f['dir'] === 'asc' ? 'ascending' : 'descending') : 'none',
                'rotulo' => $rotulo,
                'ativo' => $ativo,
            ];
        }

        return $saida;
    }

    // ------------------------------------------------------------------
    // Montagem de linhas
    // ------------------------------------------------------------------

    /**
     * @param list<array<string,mixed>> $linhas
     * @param array<string,mixed> $f
     * @return list<array<string,mixed>>
     */
    private function decorarOrdens(array $linhas, array $f, DateTimeImmutable $hoje): array
    {
        $agora = $this->agora();
        $retorno = $this->query($f);
        $saida = [];
        foreach ($linhas as $l) {
            [$idadeTexto, $atencao] = $this->idade($l, $agora);
            $l['situacao_slug'] = $l['status'] === 'ATIVA' ? 'ativa' : 'inativa';
            $l['situacao_rotulo'] = $l['status'] === 'ATIVA' ? 'Ativa' : 'Inativa';
            $l['idade_texto'] = $idadeTexto;
            $l['idade_atencao'] = $atencao;
            $l['cnpj_cliente_fmt'] = self::formatarCnpj((string) $l['cnpj']);
            $l['cnpj_transportadora_fmt'] = self::formatarCnpj((string) ($l['transportadora_cnpj'] ?? ''));
            $l['href_abrir'] = self::CAMINHO_ORDEM . '?id=' . (int) $l['id'] . '&' . $retorno;
            $saida[] = $l;
        }

        return $saida;
    }

    /**
     * @param array<string,mixed> $f
     * @return list<array<string,mixed>>
     */
    private function linhasBaixa(OrdemColetaPendenteBaixaDao $dao, array $f, DateTimeImmutable $hoje): array
    {
        $baixas = $dao->listar(
            $f['mostrar'],
            $f['de'] === '' ? null : $f['de'],
            $f['ate'] === '' ? null : $f['ate'],
            self::POR_PAGINA,
            ($f['pagina'] - 1) * self::POR_PAGINA
        );
        $retorno = $this->query($f);
        // UMA consulta para a pagina inteira (nunca uma por linha)
        $pares = [];
        foreach ($baixas as $b) {
            $pares[] = [(string) ($b['cliente_cnpj'] ?? ''), (string) $b['numero_ordem_coleta']];
        }
        $idsLote = $this->ordens->idsPorClienteNumeroLote($pares);
        $saida = [];
        foreach ($baixas as $i => $b) {
            $ids = $idsLote[$i] ?? [];
            $b['resolvida'] = $b['resolvido_em'] !== null;
            $b['oc_estado'] = count($ids) === 1 ? 'localizada' : (count($ids) > 1 ? 'ambigua' : 'nao_localizada');
            $b['href_abrir'] = count($ids) === 1 ? self::CAMINHO_ORDEM . '?id=' . $ids[0] . '&' . $retorno : null;
            $saida[] = $b;
        }

        return $saida;
    }

    /**
     * Idade da OC: "Hoje"/"Há N dias" nas ATIVAS; "Inativada em dd/mm/aaaa" nas
     * inativas. Atencao = ATIVA ha mais de 15 dias (mesmo criterio da aba do banco).
     *
     * @param array<string,mixed> $oc
     * @return array{0:string,1:bool}
     */
    private function idade(array $oc, DateTimeImmutable $agora): array
    {
        if ($oc['status'] !== 'ATIVA') {
            $d = $this->data($oc['inativada_em'] ?? null);

            return [$d === null ? 'Inativada' : 'Inativada em ' . $d->format('d/m/Y'), false];
        }
        $criada = $this->data($oc['criado_em'] ?? null);
        if ($criada === null) {
            return ['', false];
        }
        $segundos = max(0, $agora->getTimestamp() - $criada->getTimestamp());
        $dias = intdiv($segundos, 86400);
        $texto = $dias === 0 ? 'Hoje' : ($dias === 1 ? 'Há 1 dia' : 'Há ' . $dias . ' dias');

        return [$texto, $segundos > OrdemColetaGestaoDao::DIAS_ATIVAS_ANTIGAS * 86400];
    }

    private function data(mixed $valor): ?DateTimeImmutable
    {
        if (!is_string($valor) || preg_match('/\A\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}\z/D', $valor) !== 1) {
            return null;
        }
        try {
            return new DateTimeImmutable($valor, $this->fuso());
        } catch (Throwable) {
            return null;
        }
    }

    /** "00000000000000" => "00.000.000/0000-00"; qualquer outra coisa volta como veio. */
    public static function formatarCnpj(string $cnpj): string
    {
        if (preg_match('/\A\d{14}\z/D', $cnpj) !== 1) {
            return $cnpj;
        }

        return substr($cnpj, 0, 2) . '.' . substr($cnpj, 2, 3) . '.' . substr($cnpj, 5, 3) . '/' . substr($cnpj, 8, 4) . '-' . substr($cnpj, 12, 2);
    }

    // ------------------------------------------------------------------
    // Infra
    // ------------------------------------------------------------------

    /** Id de OC (BIGINT UNSIGNED do banco externo): 1 a 18 digitos, sem zero a esquerda. Fora do int de 64 bits nunca passa. */
    public static function idOc(string $texto): ?int
    {
        if (preg_match('/\A[1-9][0-9]{0,17}\z/D', $texto) !== 1) {
            return null;
        }

        return (int) $texto;
    }

    private function hoje(): DateTimeImmutable
    {
        return new DateTimeImmutable('today', $this->fuso());
    }

    private function agora(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', $this->fuso());
    }

    private function fuso(): DateTimeZone
    {
        // mesmo fuso (-03:00) da sessao dos dois bancos
        return new DateTimeZone('-03:00');
    }

    /** @return array{view:string,dados:array<string,mixed>,status:int,titulo:string} */
    private function paginaComErro(): array
    {
        return [
            'view' => 'ordens',
            'status' => 503,
            'titulo' => 'Ordens de coleta',
            'dados' => ['erroCarga' => true],
        ];
    }

    private function pedidoInvalido(): void
    {
        GestaoHttp::negar(400, 'Requisição inválida', 'Não foi possível entender o pedido. Nada foi alterado. Volte para a lista e tente de novo.', [], GestaoHttp::ACAO_INICIO);
    }

    private function pdfNaoEncontrado(): void
    {
        GestaoHttp::negar(404, 'PDF não encontrado', 'Este PDF não está disponível.', [], GestaoHttp::ACAO_INICIO);
    }

    private function externoIndisponivel(): void
    {
        GestaoHttp::negar(503, 'Ordens de coleta indisponíveis', GestaoContexto::MENSAGENS['oc_banco_indisponivel'][1], [], GestaoHttp::ACAO_INICIO);
    }

    private function auditoriaIndisponivel(Throwable $e): void
    {
        error_log('gestao: oc_auditoria_abrir_falhou ' . get_class($e));
        LogSistema::registrar('auditoria_falhou', ['excecao' => $e]);
        GestaoHttp::negar(503, 'Serviço indisponível', 'Não foi possível registrar a ação na trilha de auditoria agora. Nada foi alterado. Tente novamente em instantes.', [], GestaoHttp::ACAO_INICIO);
    }

    /** Log da falha do banco externo: so a classe da excecao (a conexao ja registra a propria falha). */
    private function registrarFalhaExterno(Throwable $e): void
    {
        error_log('gestao: oc_externo_falhou ' . get_class($e));
        if ($e instanceof OrdemColetaGestaoException) {
            LogSistema::registrar('banco_coletas_indisponivel', ['excecao' => $e]);
        }
    }

    /** @param array<string,string|int> $detalhe */
    private function fecharAuditoria(AuditoriaDao $auditoria, int $idAuditoria, string $resultado, array $detalhe): void
    {
        try {
            $auditoria->fechar($idAuditoria, $resultado, $detalhe);
        } catch (Throwable $e) {
            error_log('gestao: oc_auditoria_fechar_falhou ' . get_class($e));
            LogSistema::registrar('auditoria_falhou', ['excecao' => $e]);
        }
    }

    /**
     * Evento ja concluido que nao chegou a abrir linha PENDENTE (recusa antes do UPDATE).
     * Falha de auditoria aqui nao bloqueia: nada foi alterado.
     *
     * @param array<string,string|int> $detalhe
     */
    private function auditarEvento(AuditoriaDao $auditoria, int $usuario, string $acao, int $idOc, string $resultado, array $detalhe, string $ip): void
    {
        try {
            $auditoria->registrar($usuario, $acao, 'ordem_coleta', $idOc, $resultado, $detalhe, $ip);
        } catch (Throwable $e) {
            error_log('gestao: oc_auditoria_registrar_falhou ' . get_class($e));
            LogSistema::registrar('auditoria_falhou', ['excecao' => $e]);
        }
    }

    /** @param array<string,mixed> $f */
    private function voltarLista(array $f, string $codigo): void
    {
        $codigo = isset(GestaoContexto::MENSAGENS[$codigo]) ? $codigo : 'erro_interno';
        GestaoHttp::redirecionar(self::CAMINHO_ORDENS . '?' . $this->query($f, ['msg' => $codigo]));
    }

    /** @param array<string,mixed> $f */
    private function voltar(bool $deDetalhe, int $id, array $f, string $codigo): void
    {
        if ($deDetalhe) {
            $codigo = isset(GestaoContexto::MENSAGENS[$codigo]) ? $codigo : 'erro_interno';
            GestaoHttp::redirecionar(self::CAMINHO_ORDEM . '?' . $this->queryDetalhe($id, $f, ['msg' => $codigo]));
        }
        $this->voltarLista($f, $codigo);
    }
}
