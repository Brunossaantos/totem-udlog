<?php

namespace App\Controller;

use App\Rn\TotemGestaoRn;
use Util\GestaoHttp;
use Util\TotemUrlBase;

/**
 * Totens da Gestao Totem (SO admin; o guard esta em GestaoContexto): listar,
 * criar, ativar/desativar e regerar a URL (demanda gestao-totem, F2). Os metodos
 * devolvem o resultado de pagina que `gestaoRenderizar()` imprime, ou redirecionam
 * (PRG com ?msg=codigo).
 *
 * Fail-closed: sem TOTEM_URL_BASE valida no ambiente a gestao de totens responde
 * 503 (nunca monta URL a partir do cabecalho Host). O token do quiosque nunca
 * passa por este controller.
 */
final class GestaoTotemController
{
    private TotemGestaoRn $rn;

    public function __construct(private GestaoContexto $ctx)
    {
        $base = TotemUrlBase::doAmbiente($_ENV);
        if ($base === null) {
            error_log('gestao: totem_url_base_invalida (TOTEM_URL_BASE ausente ou fora do formato)');
            GestaoHttp::negar(
                503,
                'URL dos totens não configurada',
                'A gestão de totens não pode montar o endereço do quiosque porque a URL base não está configurada neste servidor. Nada foi alterado. Avise quem administra o sistema.',
                [],
                GestaoHttp::ACAO_INICIO
            );
        }
        $this->rn = new TotemGestaoRn($ctx->pdo, $base);
    }

    /**
     * totens.php: GET lista. POST acao=ativar|desativar|regerar-url + id_totem.
     *  - desativar: confirmar_atendimento=1 (so exigido se ha atendimento recente);
     *  - regerar-url: versao_url (a `url_versao` que a tela mostrou) + nome_confirmacao.
     *
     * @return array{view:string,dados:array<string,mixed>,status:int}
     */
    public function listar(): array
    {
        if ($this->ctx->auth->metodoSeguro()) {
            return $this->pagina(200);
        }

        $acao = GestaoContexto::post('acao');
        $alvo = GestaoContexto::inteiroPositivo(GestaoContexto::post('id_totem'));
        $versao = GestaoContexto::inteiroPositivo(GestaoContexto::post('versao_url'));
        if ($alvo === null || !in_array($acao, ['ativar', 'desativar', 'regerar-url'], true) || ($acao === 'regerar-url' && $versao === null)) {
            return $this->pagina(400, ['tipo' => 'erro', 'texto' => 'Não foi possível entender o pedido. Nada foi alterado. Recarregue a página e tente de novo.']);
        }
        $admin = $this->ctx->idUsuario();
        $ip = $this->ctx->ip();

        if ($acao === 'regerar-url') {
            $r = $this->rn->regerarUrl($admin, $alvo, (int) $versao, GestaoContexto::post('nome_confirmacao'), $ip);
            if (!$r['ok']) {
                $this->voltar((string) ($r['codigo'] ?? 'erro_interno'));
            }
            $this->irParaUrl($alvo, 'url_regerada');
        }

        $r = $this->rn->definirAtivo($admin, $alvo, $acao === 'ativar', GestaoContexto::post('confirmar_atendimento') === '1', $ip);
        if (!$r['ok']) {
            $this->voltar((string) ($r['codigo'] ?? 'erro_interno'));
        }
        $this->voltar(($r['sem_mudanca'] ?? false) ? 'totem_sem_mudanca' : ($acao === 'ativar' ? 'totem_ativado' : 'totem_desativado'));

        return [];
    }

    /**
     * totem-form.php: GET mostra o formulario (empresas ativas). POST cria o totem.
     *
     * @return array{view:string,dados:array<string,mixed>,status:int}
     */
    public function formulario(): array
    {
        if ($this->ctx->auth->metodoSeguro()) {
            return $this->formularioView(200, ['empresa' => '', 'nome' => ''], []);
        }

        $empresaTexto = GestaoContexto::post('id_empresa');
        $nome = GestaoContexto::post('nome');
        $r = $this->rn->criar($this->ctx->idUsuario(), GestaoContexto::inteiroPositivo($empresaTexto), $nome, $this->ctx->ip());
        if ($r['ok']) {
            $this->irParaUrl((int) $r['id'], 'totem_criado');
        }
        if (($r['codigo'] ?? '') === 'validacao') {
            return $this->formularioView(422, ['empresa' => $empresaTexto, 'nome' => $nome], $r['erros'] ?? []);
        }
        $this->voltar((string) ($r['codigo'] ?? 'erro_interno'));

        return [];
    }

    /**
     * totem-url.php (GET, ?id=): mostra a URL completa do totem (so admin) com o
     * botao de copiar. E o destino do PRG depois de criar e de regerar.
     *
     * @return array{view:string,dados:array<string,mixed>,status:int}
     */
    public function url(): array
    {
        $id = GestaoContexto::inteiroPositivo(GestaoContexto::query('id'));
        $totem = $id !== null ? $this->rn->obter($id) : null;
        if ($totem === null) {
            $this->voltar('totem_nao_encontrado');
        }

        return [
            'view' => 'totem-url',
            'status' => 200,
            'titulo' => 'URL do totem',
            'dados' => [
                'totem' => $totem,
            ],
        ];
    }

    /**
     * Cada linha de `totens` traz: id_totem, nome, empresa_nome, ativo, criado_em,
     * criado_por_nome, criado_por_login, url (completa), legado (bool), url_versao,
     * url_regerada_em, atualizado_em, atendimentos_recentes (int). Nunca o token.
     *
     * @param array{tipo:string,texto:string}|null $flashLocal
     */
    private function pagina(int $status, ?array $flashLocal = null): array
    {
        return [
            'view' => 'totens',
            'status' => $status,
            'flash' => $flashLocal,
            'dados' => [
                'totens' => $this->rn->listar(),
                'janela_atendimento_min' => \App\Dao\TotemGestaoDao::JANELA_ATENDIMENTO_MIN,
            ],
        ];
    }

    /**
     * @param array{empresa:string,nome:string} $valores
     * @param array<string,string> $erros
     */
    private function formularioView(int $status, array $valores, array $erros): array
    {
        return [
            'view' => 'totem-form',
            'status' => $status,
            'titulo' => 'Novo totem',
            'dados' => [
                'empresas' => $this->rn->empresasAtivas(),
                'valores' => $valores,
                'erros' => $erros,
            ],
        ];
    }

    private function irParaUrl(int $idTotem, string $codigo): void
    {
        GestaoHttp::redirecionar('/gestao/totem-url.php?id=' . $idTotem . '&msg=' . rawurlencode($codigo));
    }

    private function voltar(string $codigo): void
    {
        $codigo = isset(GestaoContexto::MENSAGENS[$codigo]) ? $codigo : 'erro_interno';
        GestaoHttp::redirecionar(GestaoContexto::CAMINHO_TOTENS . '?msg=' . rawurlencode($codigo));
    }
}
