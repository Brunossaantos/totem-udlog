<?php

use App\Controller\GestaoContexto;

if (!function_exists('h')) {
    function h(mixed $valor): string
    {
        return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('gestaoIcone')) {
    function gestaoIcone(string $nome, string $classe = ''): string
    {
        $nome = preg_replace('/[^a-z-]/', '', $nome) ?? '';

        return '<svg class="gestao-icone' . ($classe !== '' ? ' ' . h($classe) : '') . '" aria-hidden="true" focusable="false"><use href="#i-' . $nome . '"></use></svg>';
    }
}

if (!function_exists('gestaoSprite')) {
    function gestaoSprite(): string
    {
        $icones = [
            'painel' => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/>',
            'totens' => '<rect x="5" y="2" width="14" height="16" rx="2"/><path d="M9 22h6M12 18v4"/>',
            'atendimentos' => '<path d="M9 4h6v3H9z"/><path d="M15 5h2a2 2 0 0 1 2 2v13a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h2"/><path d="M9 13h6M9 17h4"/>',
            'ordens' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/>',
            'anexos' => '<path d="M21 11.5l-8.5 8.5a5 5 0 0 1-7-7l9-9a3.3 3.3 0 0 1 4.7 4.7l-9 9a1.7 1.7 0 0 1-2.4-2.4l8.3-8.3"/>',
            'logs' => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
            'usuarios' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/>',
            'conta' => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
            'sair' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
            'alerta' => '<path d="M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
            'ok' => '<circle cx="12" cy="12" r="10"/><path d="M8 12l3 3 5-6"/>',
            'inativo' => '<circle cx="12" cy="12" r="10"/><path d="M8 12h8"/>',
            'copiar' => '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
            'mais' => '<path d="M12 5v14M5 12h14"/>',
            'editar' => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
            'chave' => '<circle cx="8" cy="15" r="4"/><path d="M10.8 12.2L21 2M16 7l3 3"/>',
            'fechar' => '<path d="M18 6L6 18M6 6l12 12"/>',
            'recolher' => '<path d="M11 17l-5-5 5-5M18 17l-5-5 5-5"/>',
            'info' => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>',
            'erro' => '<circle cx="12" cy="12" r="10"/><path d="M15 9l-6 6M9 9l6 6"/>',
            'externo' => '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6M15 3h6v6M10 14L21 3"/>',
            'ponto' => '<circle cx="12" cy="12" r="7"/>',
            'clientes' => '<path d="M3 21h18M5 21V7l7-4 7 4v14"/><path d="M9 9h.01M15 9h.01M9 13h.01M15 13h.01M10 21v-4h4v4"/>',
            'empresas' => '<rect x="4" y="2" width="16" height="20" rx="1"/><path d="M9 22v-4h6v4M8 6h.01M12 6h.01M16 6h.01M8 10h.01M12 10h.01M16 10h.01M8 14h.01M12 14h.01M16 14h.01"/>',
            'excluir' => '<path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6M10 11v6M14 11v6"/>',
            'desbloquear' => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/>',
        ];
        $saida = '<svg class="gestao-sprite" width="0" height="0" aria-hidden="true" focusable="false"><defs>';
        foreach ($icones as $id => $corpo) {
            $saida .= '<symbol id="i-' . $id . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $corpo . '</symbol>';
        }

        return $saida . '</defs></svg>';
    }
}

if (!function_exists('gestaoData')) {
    function gestaoData(?string $valor, string $vazio = ''): string
    {
        if ($valor === null || $valor === '') {
            return h($vazio);
        }
        if (preg_match('/\A(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/', $valor, $m) !== 1) {
            return h($valor);
        }

        return '<time datetime="' . h($m[1] . '-' . $m[2] . '-' . $m[3] . 'T' . $m[4] . ':' . $m[5]) . '">' . h($m[3] . '/' . $m[2] . '/' . $m[1] . ' ' . $m[4] . ':' . $m[5]) . '</time>';
    }
}

if (!function_exists('gestaoCnpj')) {
    function gestaoCnpj(?string $valor): string
    {
        $valor = (string) $valor;
        if (preg_match('/\A\d{14}\z/D', $valor) !== 1) {
            return h($valor);
        }

        return h(substr($valor, 0, 2) . '.' . substr($valor, 2, 3) . '.' . substr($valor, 5, 3) . '/' . substr($valor, 8, 4) . '-' . substr($valor, 12, 2));
    }
}

if (!function_exists('gestaoHora')) {
    function gestaoHora(?string $valor, string $vazio = ''): string
    {
        if ($valor === null || $valor === '') {
            return h($vazio);
        }
        if (preg_match('/\A(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/', $valor, $m) !== 1) {
            return h($valor);
        }

        return '<time datetime="' . h($m[1] . '-' . $m[2] . '-' . $m[3] . 'T' . $m[4] . ':' . $m[5]) . '">' . h($m[4] . ':' . $m[5]) . '</time>';
    }
}

if (!function_exists('gestaoPagina')) {
    function gestaoPagina(string $titulo, string $itemMenu, string $perfilMinimo, array $opcoes = []): GestaoContexto
    {
        return GestaoContexto::iniciar($titulo, $itemMenu, $perfilMinimo, $opcoes);
    }
}

if (!function_exists('gestaoPaginaPublica')) {
    function gestaoPaginaPublica(string $titulo, array $opcoes = []): GestaoContexto
    {
        return GestaoContexto::iniciar($titulo, '', null, $opcoes);
    }
}

if (!function_exists('gestaoCsrfInput')) {
    function gestaoCsrfInput(GestaoContexto $ctx): string
    {
        return '<input type="hidden" name="csrf_token" value="' . h($ctx->auth->csrfToken()) . '">';
    }
}

if (!function_exists('gestaoAsset')) {
    function gestaoAsset(string $nome): string
    {
        $caminho = __DIR__ . '/../../../public/gestao/assets/' . $nome;

        return '/gestao/assets/' . rawurlencode($nome) . '?v=' . (int) @filemtime($caminho);
    }
}

if (!function_exists('gestaoRenderizar')) {
    function gestaoRenderizar(GestaoContexto $ctx, array $resultado): void
    {
        $view = (string) ($resultado['view'] ?? '');
        if (preg_match('/\A[a-z][a-z0-9-]*\z/D', $view) !== 1 || !is_file(__DIR__ . '/' . $view . '.php')) {
            error_log('gestao: view_invalida');
            http_response_code(500);
            echo 'Erro interno.';
            exit;
        }
        http_response_code((int) ($resultado['status'] ?? 200));
        header('Content-Type: text/html; charset=UTF-8');
        foreach (($resultado['cabecalhos'] ?? []) as $linha) {
            header($linha);
        }

        $tituloPagina = (string) ($resultado['titulo'] ?? $ctx->titulo);
        $flash = array_key_exists('flash', $resultado) && $resultado['flash'] !== null ? $resultado['flash'] : $ctx->flash();
        $menu = $ctx->menu();
        $semMenu = $menu === [];
        $usuarioLogado = $ctx->sessao === null ? null : [
            'login' => (string) $ctx->sessao['login'],
            'nome' => (string) $ctx->sessao['nome'],
            'perfil' => (string) $ctx->sessao['perfil'],
        ];
        $dados = $resultado['dados'] ?? [];

        (static function () use ($ctx, $view, $tituloPagina, $flash, $menu, $semMenu, $usuarioLogado, $dados): void {
            extract($dados, EXTR_SKIP);
            require __DIR__ . '/layout_topo.php';
            require __DIR__ . '/' . $view . '.php';
            require __DIR__ . '/layout_base.php';
        })();
    }
}
