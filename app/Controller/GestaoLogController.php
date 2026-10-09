<?php

namespace App\Controller;

use App\Dao\LogSistemaDao;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use Util\GestaoHttp;
use Util\LogCatalogo;

/**
 * Tela de logs da Gestao Totem (demanda gestao-totem, F3c; SO admin, o guard esta
 * em GestaoContexto, e SO GET). Somente leitura: sem exportacao, sem edicao, sem
 * exclusao e sem "revelar" (os logs nao tem dado pessoal: mensagem fixa do catalogo,
 * `detalhe` por allowlist). Abrir a tela NAO e auditado.
 *
 * Todo filtro vem da query, e validado por WHITELIST aqui (aba, nivel, categoria
 * da aba no catalogo, totem existente, datas em parse estrito dentro de 90 dias) e
 * so entao chega ao LogSistemaDao, que valida de novo. Valor invalido e IGNORADO
 * (volta ao padrao); nunca vira SQL nem URL. Nao ha busca de texto livre.
 * Os links da tela (abas, atalhos, paginacao, voltar) sao montados SO a partir
 * dos filtros ja validados.
 *
 * Nunca expoe `codigo` nem `token_api` do totem (so id, nome e empresa).
 */
final class GestaoLogController
{
    public const POR_PAGINA = 50;

    public const CAMINHO_LOGS = '/gestao/logs.php';

    public const CAMINHO_LOG = '/gestao/log.php';

    /** slug da URL => origem do banco, rotulo e descricao curta. */
    public const ABAS = [
        'api' => [
            'origem' => 'API',
            'rotulo' => 'API',
            'descricao' => 'Falhas das integrações e da infraestrutura (Talent, VIO, n8n, banco de dados, serviço de impressão) que não pertencem a um atendimento.',
        ],
        'recebimento' => [
            'origem' => 'RECEBIMENTO',
            'rotulo' => 'Recebimento',
            'descricao' => 'Erros e avisos de atendimentos de recebimento.',
        ],
        'expedicao' => [
            'origem' => 'EXPEDICAO',
            'rotulo' => 'Expedição',
            'descricao' => 'Erros e avisos de atendimentos de expedição.',
        ],
        'cron' => [
            'origem' => 'CRON',
            'rotulo' => 'Cron',
            'descricao' => 'Rotinas automáticas (cron) e suas falhas.',
        ],
        'gestao' => [
            'origem' => 'GESTAO',
            'rotulo' => 'Gestão',
            'descricao' => 'Ações e falhas da própria Gestão Totem.',
        ],
    ];

    public const ABA_PADRAO = 'api';

    /** Abas cujo registro pode ter totem (Cron e Gestao nunca tem). */
    public const ABAS_COM_TOTEM = ['api', 'recebimento', 'expedicao'];

    public const NIVEIS = ['ERRO' => 'Erro', 'AVISO' => 'Aviso', 'INFO' => 'Info'];

    private LogSistemaDao $dao;

    public function __construct(private GestaoContexto $ctx)
    {
        $this->dao = new LogSistemaDao($ctx->pdo);
    }

    /**
     * logs.php (GET): lista paginada da aba.
     *
     * @return array{view:string,dados:array<string,mixed>,status:int}
     */
    public function listar(): array
    {
        $hoje = $this->hoje();
        try {
            $totens = $this->dao->totensParaFiltro();
            $f = $this->lerFiltros($totens, $hoje);

            $abas = $this->dao->contarPorAba($this->filtrosDePeriodo($f));
            $resultado = $this->dao->listar($this->filtrosDao($f), $f['pagina'], self::POR_PAGINA);
            $total = (int) $resultado['total'];
            $paginas = max(1, (int) ceil($total / self::POR_PAGINA));
            if ($f['pagina'] > $paginas) {
                // teto de pagina: volta para a ultima que existe
                GestaoHttp::redirecionar(self::CAMINHO_LOGS . '?' . $this->query($f, ['pagina' => $paginas]));
            }
        } catch (Throwable $e) {
            error_log('gestao: logs_carga_falhou ' . get_class($e));

            return $this->paginaComErro();
        }

        $comTotem = in_array($f['aba'], self::ABAS_COM_TOTEM, true);
        $nomesTotem = [];
        foreach ($totens as $t) {
            $nomesTotem[(int) $t['id_totem']] = $this->rotuloTotem($t['nome'], $t['empresa_nome'] ?? null);
        }
        $opcoesTotem = [];
        foreach ($totens as $t) {
            $opcoesTotem[] = ['valor' => (string) (int) $t['id_totem'], 'rotulo' => $nomesTotem[(int) $t['id_totem']]];
        }

        $itens = [];
        foreach ($resultado['itens'] as $linha) {
            $idTotem = $linha['id_totem'] === null ? null : (int) $linha['id_totem'];
            $linha['nivel_slug'] = strtolower((string) $linha['nivel']);
            $linha['nivel_rotulo'] = self::NIVEIS[(string) $linha['nivel']] ?? 'Info';
            $linha['totem_rotulo'] = $idTotem === null ? 'Sem totem' : ($nomesTotem[$idTotem] ?? 'Totem removido');
            $itens[] = $linha;
        }

        $primeiro = $total === 0 ? 0 : ($f['pagina'] - 1) * self::POR_PAGINA + 1;
        $ultimo = $total === 0 ? 0 : $primeiro + count($itens) - 1;

        $abasView = [];
        foreach (self::ABAS as $slug => $def) {
            $abasView[] = [
                'slug' => $slug,
                'rotulo' => $def['rotulo'],
                'total' => (int) ($abas[$def['origem']] ?? 0),
                'href' => self::CAMINHO_LOGS . '?' . $this->query($f, [], ['aba' => $slug, 'nivel' => '', 'totem' => '', 'categoria' => '', 'pagina' => 1]),
                'ativa' => $slug === $f['aba'],
            ];
        }

        $atalhos = [];
        foreach ($this->periodosAtalho($hoje) as $id => [$rotulo, $de, $ate]) {
            $atalhos[] = [
                'id' => $id,
                'rotulo' => $rotulo,
                'href' => self::CAMINHO_LOGS . '?' . $this->query($f, [], ['de' => $de, 'ate' => $ate, 'pagina' => 1]),
                'ativo' => $f['de'] === $de && $f['ate'] === $ate,
            ];
        }

        $temFiltros = $f['nivel'] !== '' || $f['totem'] !== '' || $f['categoria'] !== '' || $f['de'] !== '' || $f['ate'] !== '';

        return [
            'view' => 'logs',
            'status' => 200,
            'titulo' => 'Logs',
            'dados' => [
                'aba' => $f['aba'],
                'abas' => $abasView,
                'abaDescricao' => self::ABAS[$f['aba']]['descricao'],
                'filtros' => $f,
                'errosFiltro' => $f['erros'],
                'niveis' => self::NIVEIS,
                'mostrarTotem' => $comTotem,
                'opcoesTotem' => $opcoesTotem,
                'categoriasAba' => $this->categoriasDaAba($f['aba']),
                'atalhos' => $atalhos,
                'hojeIso' => $hoje->format('Y-m-d'),
                'limiteIso' => $hoje->modify('-' . LogSistemaDao::RETENCAO_DIAS . ' days')->format('Y-m-d'),
                'urlLimpar' => self::CAMINHO_LOGS . '?aba=' . rawurlencode($f['aba']),
                'itens' => $itens,
                'total' => $total,
                'temFiltros' => $temFiltros,
                'primeiro' => $primeiro,
                'ultimo' => $ultimo,
                'pagina' => $f['pagina'],
                'paginas' => $paginas,
                'urlAnterior' => $f['pagina'] > 1 ? self::CAMINHO_LOGS . '?' . $this->query($f, ['pagina' => $f['pagina'] - 1]) : null,
                'urlProxima' => $f['pagina'] < $paginas ? self::CAMINHO_LOGS . '?' . $this->query($f, ['pagina' => $f['pagina'] + 1]) : null,
                'queryVolta' => $this->query($f),
                'erroCarga' => false,
            ],
        ];
    }

    /**
     * log.php (GET, ?id=): detalhe de um registro em pagina propria. Registro
     * inexistente (ou id invalido) volta para a lista com a mensagem fixa.
     *
     * @return array{view:string,dados:array<string,mixed>,status:int}
     */
    public function detalhe(): array
    {
        $hoje = $this->hoje();
        $id = GestaoContexto::inteiroPositivo(GestaoContexto::query('id'));
        try {
            $f = $this->lerFiltros($this->dao->totensParaFiltro(), $hoje);
            $registro = $id === null ? null : $this->dao->buscarPorId($id);
        } catch (Throwable $e) {
            error_log('gestao: log_detalhe_falhou ' . get_class($e));

            return [
                'view' => 'log',
                'status' => 500,
                'titulo' => 'Detalhe do registro',
                'dados' => ['erroCarga' => true, 'registro' => null, 'urlVoltar' => self::CAMINHO_LOGS],
            ];
        }
        if ($registro === null) {
            GestaoHttp::redirecionar(self::CAMINHO_LOGS . '?' . $this->query($f, ['msg' => 'log_nao_encontrado']));
        }

        // Sem aba na query, o "Voltar" cai na aba do proprio registro.
        $slugDoRegistro = '';
        foreach (self::ABAS as $slug => $def) {
            if ($def['origem'] === (string) $registro['origem']) {
                $slugDoRegistro = $slug;
            }
        }
        if (!isset(self::ABAS[GestaoContexto::query('aba')]) && $slugDoRegistro !== '') {
            $f['aba'] = $slugDoRegistro;
        }
        $comTotem = $slugDoRegistro !== '' && in_array($slugDoRegistro, self::ABAS_COM_TOTEM, true);
        $totem = 'Sem totem';
        if ($registro['id_totem'] !== null) {
            $totem = $registro['totem_nome'] === null ? 'Totem removido' : $this->rotuloTotem($registro['totem_nome'], $registro['totem_empresa'] ?? null);
        }

        return [
            'view' => 'log',
            'status' => 200,
            'titulo' => 'Detalhe do registro',
            'dados' => [
                'erroCarga' => false,
                'registro' => $registro,
                'nivelSlug' => strtolower((string) $registro['nivel']),
                'nivelRotulo' => self::NIVEIS[(string) $registro['nivel']] ?? 'Info',
                'abaRotulo' => $slugDoRegistro !== '' ? self::ABAS[$slugDoRegistro]['rotulo'] : (string) $registro['origem'],
                'mostrarTotem' => $comTotem,
                'totemRotulo' => $totem,
                'tecnico' => $this->paresTecnicos($registro['detalhe'] ?? null),
                'urlVoltar' => self::CAMINHO_LOGS . '?' . $this->query($f),
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Filtros (whitelist)
    // ------------------------------------------------------------------

    /**
     * @param list<array<string,mixed>> $totens
     * @return array{aba:string,nivel:string,totem:string,categoria:string,de:string,ate:string,pagina:int,erros:array<string,string>}
     */
    private function lerFiltros(array $totens, DateTimeImmutable $hoje): array
    {
        $aba = GestaoContexto::query('aba');
        $aba = isset(self::ABAS[$aba]) ? $aba : self::ABA_PADRAO;

        $nivel = GestaoContexto::query('nivel');
        $nivel = isset(self::NIVEIS[$nivel]) ? $nivel : '';

        $categoria = GestaoContexto::query('categoria');
        $categoria = in_array($categoria, $this->categoriasDaAba($aba), true) ? $categoria : '';

        $totem = '';
        if (in_array($aba, self::ABAS_COM_TOTEM, true)) {
            $texto = GestaoContexto::query('totem');
            if ($texto === 'sem') {
                $totem = 'sem';
            } else {
                $idTotem = GestaoContexto::inteiroPositivo($texto);
                foreach ($totens as $t) {
                    if ($idTotem !== null && (int) $t['id_totem'] === $idTotem) {
                        $totem = (string) $idTotem;
                        break;
                    }
                }
            }
        }

        $erros = [];
        $limite = $hoje->modify('-' . LogSistemaDao::RETENCAO_DIAS . ' days');
        $de = $this->lerData('de', $hoje, $limite, $erros, 'A data inicial');
        $ate = $this->lerData('ate', $hoje, $limite, $erros, 'A data final');
        if ($de !== null && $ate !== null && $de > $ate) {
            $de = null;
            $ate = null;
            $erros['ate'] = 'A data final não pode ser anterior à inicial. O período foi ignorado.';
            unset($erros['de']);
        }

        $pagina = GestaoContexto::inteiroPositivo(GestaoContexto::query('pagina')) ?? 1;

        return [
            'aba' => $aba,
            'nivel' => $nivel,
            'totem' => $totem,
            'categoria' => $categoria,
            'de' => $de === null ? '' : $de->format('Y-m-d'),
            'ate' => $ate === null ? '' : $ate->format('Y-m-d'),
            'pagina' => $pagina,
            'erros' => $erros,
        ];
    }

    /**
     * Data estrita AAAA-MM-DD dentro da janela de 90 dias (nao futura). Invalida
     * vira null com mensagem em $erros[$campo]; vazia vira null sem mensagem.
     *
     * @param array<string,string> $erros
     */
    private function lerData(string $campo, DateTimeImmutable $hoje, DateTimeImmutable $limite, array &$erros, string $rotulo): ?DateTimeImmutable
    {
        $texto = GestaoContexto::query($campo);
        if ($texto === '') {
            return null;
        }
        $data = null;
        if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $texto) === 1) {
            $tentativa = DateTimeImmutable::createFromFormat('!Y-m-d', $texto, $hoje->getTimezone());
            if ($tentativa !== false && $tentativa->format('Y-m-d') === $texto) {
                $data = $tentativa;
            }
        }
        if ($data === null) {
            $erros[$campo] = $rotulo . ' é inválida e foi ignorada. Use o formato AAAA-MM-DD.';

            return null;
        }
        if ($data > $hoje) {
            $erros[$campo] = $rotulo . ' não pode ser futura. Ela foi ignorada.';

            return null;
        }
        if ($data < $limite) {
            $erros[$campo] = $rotulo . ' não pode ser anterior a ' . $limite->format('d/m/Y') . ' (os logs são mantidos por 90 dias). Ela foi ignorada.';

            return null;
        }

        return $data;
    }

    /** Categorias do catalogo que podem aparecer na aba (inclui as `por_tipo`). @return list<string> */
    private function categoriasDaAba(string $aba): array
    {
        $origem = self::ABAS[$aba]['origem'];
        $porTipo = in_array($aba, ['api', 'recebimento', 'expedicao'], true);
        $lista = [];
        foreach (LogCatalogo::CATEGORIAS as $codigo => $def) {
            if ($def['origem'] === $origem || ($porTipo && $def['origem'] === 'por_tipo')) {
                $lista[] = (string) $codigo;
            }
        }
        sort($lista);

        return $lista;
    }

    /** @param array<string,mixed> $f @return array<string,mixed> so o periodo (contagem por aba) */
    private function filtrosDePeriodo(array $f): array
    {
        $dao = [];
        if ($f['de'] !== '') {
            $dao['de'] = $f['de'];
        }
        if ($f['ate'] !== '') {
            $dao['ate'] = $f['ate'];
        }

        return $dao;
    }

    /** @param array<string,mixed> $f @return array<string,mixed> */
    private function filtrosDao(array $f): array
    {
        $dao = ['origem' => self::ABAS[$f['aba']]['origem']] + $this->filtrosDePeriodo($f);
        if ($f['nivel'] !== '') {
            $dao['nivel'] = $f['nivel'];
        }
        if ($f['categoria'] !== '') {
            $dao['categoria'] = $f['categoria'];
        }
        if ($f['totem'] === 'sem') {
            $dao['sem_totem'] = true;
        } elseif ($f['totem'] !== '') {
            $dao['id_totem'] = (int) $f['totem'];
        }

        return $dao;
    }

    /**
     * Query string (sem "?") so com filtros validados, em ordem fixa, omitindo o
     * que e padrao. $troca substitui valores; $sobrescreve idem (alias semantico).
     *
     * @param array<string,mixed> $f
     * @param array<string,mixed> $extra chaves adicionais/substituicoes (pagina, msg)
     * @param array<string,mixed> $troca substituicoes de filtros
     */
    private function query(array $f, array $extra = [], array $troca = []): string
    {
        $v = array_merge($f, $troca, $extra);
        $q = ['aba' => $v['aba']];
        foreach (['nivel', 'de', 'ate', 'totem', 'categoria'] as $chave) {
            if (($v[$chave] ?? '') !== '') {
                $q[$chave] = (string) $v[$chave];
            }
        }
        if ((int) ($v['pagina'] ?? 1) > 1) {
            $q['pagina'] = (string) (int) $v['pagina'];
        }
        if (isset($extra['msg']) && isset(GestaoContexto::MENSAGENS[$extra['msg']])) {
            $q['msg'] = $extra['msg'];
        }

        return http_build_query($q, '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array<string,array{0:string,1:string,2:string}> id => [rotulo, de, ate] */
    private function periodosAtalho(DateTimeImmutable $hoje): array
    {
        $fim = $hoje->format('Y-m-d');

        return [
            'periodo-hoje' => ['Hoje', $fim, $fim],
            'periodo-7d' => ['Últimos 7 dias', $hoje->modify('-6 days')->format('Y-m-d'), $fim],
            'periodo-90d' => ['Últimos 90 dias', $hoje->modify('-89 days')->format('Y-m-d'), $fim],
        ];
    }

    private function hoje(): DateTimeImmutable
    {
        // mesmo fuso (-03:00) da sessao do banco e da retencao
        return new DateTimeImmutable('today', new DateTimeZone('-03:00'));
    }

    private function rotuloTotem(mixed $nome, mixed $empresa): string
    {
        $nome = (string) $nome;

        return $empresa === null || $empresa === '' ? $nome : $nome . ' (' . $empresa . ')';
    }

    /**
     * `detalhe` ("chave=valor;chave=valor", ja por allowlist na gravacao) em pares.
     * A view escapa tudo; aqui so se separa.
     *
     * @return list<array{0:string,1:string}>
     */
    private function paresTecnicos(mixed $detalhe): array
    {
        if (!is_string($detalhe) || $detalhe === '') {
            return [];
        }
        $pares = [];
        foreach (explode(';', $detalhe) as $parte) {
            $pos = strpos($parte, '=');
            $pares[] = $pos === false ? ['', $parte] : [substr($parte, 0, $pos), substr($parte, $pos + 1)];
        }

        return $pares;
    }

    /** @return array{view:string,dados:array<string,mixed>,status:int} */
    private function paginaComErro(): array
    {
        return [
            'view' => 'logs',
            'status' => 500,
            'titulo' => 'Logs',
            'dados' => ['erroCarga' => true],
        ];
    }
}
