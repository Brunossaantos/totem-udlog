/*
 * Gestão Totem - comportamento da interface (vanilla, sem dependências).
 * CSP da gestão: script-src 'self' (sem inline, sem eval). Regras:
 *  - todo texto dinâmico entra por textContent/setAttribute (NUNCA innerHTML);
 *  - o HTML funciona sem JS (as ações são formulários com CSRF); aqui só há
 *    melhorias: recolher menu, mostrar senha, confirmação, copiar, flash,
 *    indicador de requisitos e anti duplo clique.
 */
(function () {
    'use strict';

    var SVG_NS = 'http://www.w3.org/2000/svg';
    var CHAVE_SIDEBAR = 'gestao.sidebar';

    /** Ícone do sprite (símbolos definidos em layout_topo.php). */
    function icone(nome) {
        var svg = document.createElementNS(SVG_NS, 'svg');
        svg.setAttribute('class', 'gestao-icone');
        svg.setAttribute('aria-hidden', 'true');
        svg.setAttribute('focusable', 'false');
        var uso = document.createElementNS(SVG_NS, 'use');
        uso.setAttribute('href', '#i-' + nome);
        svg.appendChild(uso);
        return svg;
    }

    function criar(tag, classe, texto) {
        var el = document.createElement(tag);
        if (classe) { el.className = classe; }
        if (texto !== undefined) { el.textContent = texto; }
        return el;
    }

    function lerPreferencia() {
        try { return window.localStorage.getItem(CHAVE_SIDEBAR); } catch (e) { return null; }
    }

    function gravarPreferencia(valor) {
        try { window.localStorage.setItem(CHAVE_SIDEBAR, valor); } catch (e) { /* preferência só de UI */ }
    }

    /* ------------------------------------------------------------------ */
    /* Sidebar recolhível                                                  */
    /* ------------------------------------------------------------------ */
    function iniciarSidebar() {
        var app = document.getElementById('gestao-app');
        var sidebar = document.getElementById('gestao-sidebar');
        if (!app || !sidebar) { return; }

        var botao = criar('button', 'gestao-recolher');
        botao.type = 'button';
        botao.id = 'gestao-recolher';
        botao.setAttribute('aria-controls', 'gestao-sidebar');
        botao.appendChild(icone('recolher'));
        var rotulo = criar('span', 'gestao-recolher__rotulo', 'Recolher menu');
        botao.appendChild(rotulo);
        sidebar.appendChild(botao);

        function aplicar(recolhida) {
            app.classList.toggle('gestao-app--recolhida', recolhida);
            botao.setAttribute('aria-expanded', recolhida ? 'false' : 'true');
            var texto = recolhida ? 'Expandir menu' : 'Recolher menu';
            rotulo.textContent = texto;
            botao.setAttribute('aria-label', texto);
            botao.title = texto;
            var links = sidebar.querySelectorAll('.gestao-menu__link');
            for (var i = 0; i < links.length; i++) {
                if (recolhida) {
                    links[i].setAttribute('aria-label', links[i].getAttribute('title') || links[i].textContent);
                } else {
                    links[i].removeAttribute('aria-label');
                }
            }
        }

        var pref = lerPreferencia();
        var inicial = pref === 'recolhida' ? true : pref === 'expandida' ? false : window.innerWidth < 1100;
        aplicar(inicial);

        botao.addEventListener('click', function () {
            var agora = !app.classList.contains('gestao-app--recolhida');
            aplicar(agora);
            gravarPreferencia(agora ? 'recolhida' : 'expandida');
        });
    }

    /* ------------------------------------------------------------------ */
    /* Mostrar/ocultar senha                                               */
    /* ------------------------------------------------------------------ */
    function iniciarMostrarSenha() {
        var campos = document.querySelectorAll('input[data-alternar-senha]');
        Array.prototype.forEach.call(campos, function (campo) {
            var rotulo = criar('label', 'gestao-mostrar');
            var caixa = document.createElement('input');
            caixa.type = 'checkbox';
            caixa.setAttribute('aria-controls', campo.id);
            rotulo.appendChild(caixa);
            rotulo.appendChild(criar('span', '', 'Mostrar senha'));
            // a mensagem de erro do campo fica IMEDIATAMENTE abaixo dele; o toggle vem depois dela
            var erro = document.getElementById('erro-' + campo.id);
            var ancora = erro && erro.parentNode === campo.parentNode ? erro : campo;
            ancora.insertAdjacentElement('afterend', rotulo);
            caixa.addEventListener('change', function () {
                campo.type = caixa.checked ? 'text' : 'password';
            });
        });
        // volta a ocultar ao enviar (o valor já foi digitado; nada fica visível na página seguinte)
        window.addEventListener('pageshow', function () {
            Array.prototype.forEach.call(campos, function (campo) { campo.type = 'password'; });
            var caixas = document.querySelectorAll('.gestao-mostrar input');
            Array.prototype.forEach.call(caixas, function (c) { c.checked = false; });
        });
    }

    /* ------------------------------------------------------------------ */
    /* Requisitos da nova senha (mínimo 12, máximo 72 bytes, != login)      */
    /* ------------------------------------------------------------------ */
    function iniciarRequisitos() {
        var campo = document.querySelector('input[data-requisitos]');
        if (!campo) { return; }
        var lista = document.getElementById(campo.getAttribute('data-requisitos'));
        var form = campo.form;
        if (!lista) { return; }
        var login = form ? (form.getAttribute('data-login') || '').toLowerCase() : '';
        var codificador = typeof TextEncoder === 'function' ? new TextEncoder() : null;

        var itens = {};
        Array.prototype.forEach.call(lista.querySelectorAll('[data-requisito]'), function (li) {
            var texto = li.textContent;
            li.textContent = '';
            // estado inicial NEUTRO: só a instrução (sem prefixo); o prefixo aparece ao digitar
            var estado = criar('span', 'gestao-requisitos__estado', '');
            estado.hidden = true;
            var rotulo = criar('span', '', texto);
            li.appendChild(icone('ponto'));
            li.appendChild(estado);
            li.appendChild(rotulo);
            itens[li.getAttribute('data-requisito')] = { li: li, estado: estado };
        });

        /** atendido: true | false | null (neutro, campo vazio). */
        function marcar(chave, atendido) {
            var item = itens[chave];
            if (!item) { return; }
            item.li.classList.toggle('gestao-requisitos__item--ok', atendido === true);
            item.li.classList.toggle('gestao-requisitos__item--falha', atendido === false);
            item.estado.hidden = atendido === null;
            item.estado.textContent = atendido === null ? '' : (atendido ? 'Atendido:' : 'Não atendido:');
            var antigo = item.li.querySelector('svg');
            var novo = icone(atendido === null ? 'ponto' : (atendido ? 'ok' : 'inativo'));
            item.li.replaceChild(novo, antigo);
        }

        function avaliar() {
            var valor = campo.value;
            var vazio = valor.length === 0;
            var bytes = codificador ? codificador.encode(valor).length : valor.length;
            marcar('minimo', vazio ? null : Array.from(valor).length >= 12);
            marcar('maximo', vazio ? null : bytes <= 72);
            marcar('login', vazio ? null : valor.toLowerCase() !== login);
        }
        campo.addEventListener('input', avaliar);
        avaliar();
    }

    /* ------------------------------------------------------------------ */
    /* Diálogo de confirmação (<dialog> nativo)                            */
    /* ------------------------------------------------------------------ */
    var dialogo = null;

    function montarDialogo() {
        var d = document.createElement('dialog');
        d.className = 'gestao-dialogo';
        d.id = 'gestao-dialogo';
        d.setAttribute('aria-labelledby', 'gestao-dialogo-titulo');
        d.setAttribute('aria-describedby', 'gestao-dialogo-texto');

        var corpo = criar('div', 'gestao-dialogo__corpo');
        var titulo = criar('h2', 'gestao-dialogo__titulo');
        titulo.id = 'gestao-dialogo-titulo';
        var texto = criar('p', 'gestao-dialogo__texto');
        texto.id = 'gestao-dialogo-texto';
        var alvo = criar('p', 'gestao-dialogo__alvo');
        var acoes = criar('div', 'gestao-dialogo__acoes');
        // confirmação extra (só quando o formulário da ação traz o campo): digitar o nome do totem
        var blocoNome = criar('div', 'gestao-campo gestao-dialogo__extra');
        blocoNome.hidden = true;
        var rotuloNome = criar('label', 'gestao-campo__rotulo', 'Para confirmar, digite o nome do totem');
        rotuloNome.setAttribute('for', 'gestao-dialogo-nome');
        var entradaNome = document.createElement('input');
        entradaNome.type = 'text';
        entradaNome.id = 'gestao-dialogo-nome';
        entradaNome.className = 'gestao-campo__entrada';
        entradaNome.maxLength = 100;
        entradaNome.setAttribute('autocomplete', 'off');
        entradaNome.setAttribute('autocapitalize', 'characters');
        entradaNome.setAttribute('spellcheck', 'false');
        entradaNome.setAttribute('aria-describedby', 'gestao-dialogo-nome-estado');
        var estadoNome = criar('p', 'gestao-dialogo__estado');
        estadoNome.id = 'gestao-dialogo-nome-estado';
        estadoNome.setAttribute('role', 'status');
        estadoNome.setAttribute('aria-live', 'polite');
        blocoNome.appendChild(rotuloNome);
        blocoNome.appendChild(entradaNome);
        blocoNome.appendChild(estadoNome);

        // confirmação extra: marcar que há atendimento em andamento e que desativa mesmo assim
        var rotuloCaixa = criar('label', 'gestao-dialogo__caixa');
        rotuloCaixa.hidden = true;
        rotuloCaixa.setAttribute('for', 'gestao-dialogo-atendimento');
        var entradaCaixa = document.createElement('input');
        entradaCaixa.type = 'checkbox';
        entradaCaixa.id = 'gestao-dialogo-atendimento';
        var textoCaixa = criar('span', 'gestao-dialogo__caixa-texto');
        rotuloCaixa.appendChild(entradaCaixa);
        rotuloCaixa.appendChild(textoCaixa);

        var cancelar = criar('button', 'gestao-botao gestao-botao--secundario', 'Cancelar');
        cancelar.type = 'button';
        cancelar.id = 'gestao-dialogo-cancelar';
        var confirmar = criar('button', 'gestao-botao gestao-botao--primario');
        confirmar.type = 'button';
        confirmar.id = 'gestao-dialogo-confirmar';
        acoes.appendChild(cancelar);
        acoes.appendChild(confirmar);
        corpo.appendChild(titulo);
        corpo.appendChild(texto);
        corpo.appendChild(alvo);
        corpo.appendChild(blocoNome);
        corpo.appendChild(rotuloCaixa);
        corpo.appendChild(acoes);
        d.appendChild(corpo);
        document.body.appendChild(d);

        var obj = {
            el: d, titulo: titulo, texto: texto, alvo: alvo, cancelar: cancelar, confirmar: confirmar, origem: null,
            blocoNome: blocoNome, entradaNome: entradaNome, estadoNome: estadoNome, nomeEsperado: null,
            rotuloCaixa: rotuloCaixa, entradaCaixa: entradaCaixa, textoCaixa: textoCaixa
        };

        // foco preso entre os controles visíveis e habilitados (Tab / Shift+Tab)
        d.addEventListener('keydown', function (e) {
            if (e.key !== 'Tab') { return; }
            var lista = [entradaNome, entradaCaixa, cancelar, confirmar].filter(function (el) {
                return !el.disabled && el.getClientRects().length > 0;
            });
            if (lista.length === 0) { return; }
            var ativo = document.activeElement;
            var dentro = lista.indexOf(ativo) !== -1;
            if (e.shiftKey && (!dentro || ativo === lista[0])) { e.preventDefault(); lista[lista.length - 1].focus(); }
            else if (!e.shiftKey && (!dentro || ativo === lista[lista.length - 1])) { e.preventDefault(); lista[0].focus(); }
        });
        // clique no fundo escurecido fecha (equivale a Cancelar)
        d.addEventListener('click', function (e) { if (e.target === d) { d.close('cancelar'); } });
        cancelar.addEventListener('click', function () { d.close('cancelar'); });
        entradaNome.addEventListener('input', function () { atualizarConfirmacao(obj); });
        entradaCaixa.addEventListener('change', function () { atualizarConfirmacao(obj); });
        // qualquer fechamento sem "confirmar" devolve os campos do formulário ao estado inicial
        d.addEventListener('close', function () {
            if (d.returnValue !== 'confirmar') { limparCamposOrigem(obj); }
        });

        dialogo = obj;
        return dialogo;
    }

    /** Comparação do nome do totem: sem espaços/tabs nas pontas e sem diferenciar maiúsculas (igual ao servidor). */
    function normalizarNome(valor) {
        return String(valor).replace(/^[ \t]+|[ \t]+$/g, '').toLowerCase();
    }

    /** Habilita "confirmar" só quando as confirmações extras (nome certo, caixa marcada) foram dadas. */
    function atualizarConfirmacao(d) {
        var liberado = true;
        if (d.nomeEsperado !== null) {
            var digitado = normalizarNome(d.entradaNome.value);
            var confere = digitado !== '' && digitado === normalizarNome(d.nomeEsperado);
            liberado = liberado && confere;
            d.estadoNome.textContent = digitado === '' ? '' : (confere ? 'O nome confere.' : 'O nome ainda não confere.');
        }
        if (!d.rotuloCaixa.hidden) {
            liberado = liberado && d.entradaCaixa.checked;
        }
        d.confirmar.disabled = !liberado;
    }

    function camposDoFormulario(botao) {
        var form = botao.form;
        return {
            nome: form ? form.querySelector('input[name="nome_confirmacao"]') : null,
            caixa: form ? form.querySelector('input[name="confirmar_atendimento"]') : null
        };
    }

    function limparCamposOrigem(d) {
        d.entradaNome.value = '';
        d.entradaCaixa.checked = false;
        if (d.origem) {
            var campos = camposDoFormulario(d.origem);
            if (campos.nome) { campos.nome.value = ''; }
            if (campos.caixa) { campos.caixa.checked = false; }
        }
    }

    function pedirConfirmacao(botao) {
        var d = dialogo || montarDialogo();
        var destrutivo = botao.getAttribute('data-confirmar-destrutivo') === '1';
        d.origem = botao;

        d.titulo.textContent = '';
        if (destrutivo) { d.titulo.appendChild(icone('alerta')); }
        d.titulo.appendChild(document.createTextNode(botao.getAttribute('data-confirmar-titulo') || 'Confirmar'));
        d.texto.textContent = botao.getAttribute('data-confirmar') || '';
        d.alvo.textContent = botao.getAttribute('data-confirmar-alvo') || '';
        d.alvo.hidden = d.alvo.textContent === '';
        d.confirmar.textContent = '';
        if (destrutivo) { d.confirmar.appendChild(icone('alerta')); }
        d.confirmar.appendChild(criar('span', '', botao.getAttribute('data-confirmar-rotulo') || 'Confirmar'));
        // destrutivo: fundo #3A3A3A + texto branco + ícone de alerta; "Cancelar" segue secundário azul
        d.confirmar.className = 'gestao-botao ' + (destrutivo ? 'gestao-botao--destrutivo-cheio' : 'gestao-botao--primario');
        d.el.classList.toggle('gestao-dialogo--destrutivo', destrutivo);

        // confirmações extras que o formulário pede (nome do totem; caixa de atendimento em andamento)
        var campos = camposDoFormulario(botao);
        d.nomeEsperado = campos.nome ? (botao.getAttribute('data-confirmar-alvo') || '') : null;
        d.blocoNome.hidden = !campos.nome;
        d.entradaNome.value = '';
        d.estadoNome.textContent = '';
        d.rotuloCaixa.hidden = !campos.caixa;
        d.entradaCaixa.checked = false;
        if (campos.caixa) {
            var rotuloOriginal = campos.caixa.closest('label');
            d.textoCaixa.textContent = rotuloOriginal ? rotuloOriginal.textContent.trim() : 'Confirmo.';
        }
        d.confirmar.disabled = false;
        atualizarConfirmacao(d);

        d.confirmar.onclick = function () {
            var origem = d.origem;
            if (d.confirmar.disabled) { return; }
            // o servidor revalida: o que foi digitado/marcado no diálogo vai nos campos reais do formulário
            if (campos.nome) { campos.nome.value = d.entradaNome.value; }
            if (campos.caixa) { campos.caixa.checked = true; }
            d.el.close('confirmar');
            if (origem && origem.form) {
                if (typeof origem.form.requestSubmit === 'function') { origem.form.requestSubmit(origem); }
                else { origem.form.submit(); }
            }
        };
        d.el.returnValue = '';
        d.el.showModal();
        d.cancelar.focus(); // foco inicial no botão seguro
    }

    function iniciarConfirmacao() {
        var teste = document.createElement('dialog');
        if (typeof teste.showModal !== 'function') { return; } // sem <dialog>: envia direto (o servidor valida)
        // com diálogo disponível, a confirmação extra (nome / caixa) acontece nele: some do formulário da linha
        Array.prototype.forEach.call(document.querySelectorAll('.gestao-confirmacao-inline'), function (el) {
            el.classList.add('gestao-confirmacao-inline--oculta');
        });
        document.addEventListener('click', function (e) {
            var alvo = e.target instanceof Element ? e.target.closest('button[data-confirmar]') : null;
            if (!alvo || alvo.disabled) { return; }
            e.preventDefault();
            pedirConfirmacao(alvo);
        });
    }

    /* ------------------------------------------------------------------ */
    /* Copiar senha temporária                                             */
    /* ------------------------------------------------------------------ */
    function selecionar(el) {
        var sel = window.getSelection();
        var faixa = document.createRange();
        faixa.selectNodeContents(el);
        sel.removeAllRanges();
        sel.addRange(faixa);
    }

    function copiarFallback(el) {
        try {
            selecionar(el);
            return document.execCommand('copy') === true;
        } catch (e) {
            return false;
        }
    }

    function iniciarCopiar() {
        var blocos = document.querySelectorAll('[data-copiar-de]');
        Array.prototype.forEach.call(blocos, function (bloco) {
            var fonte = document.getElementById(bloco.getAttribute('data-copiar-de'));
            if (!fonte) { return; }
            var botao = criar('button', 'gestao-botao gestao-botao--secundario');
            botao.type = 'button';
            // padrão = senha temporária; a tela da URL do totem passa os próprios valores por data-copiar-*
            var idBotao = bloco.getAttribute('data-copiar-id');
            botao.id = idBotao && /^[a-z][a-z0-9-]{0,40}$/.test(idBotao) ? idBotao : 'btn-copiar-senha';
            var objeto = bloco.getAttribute('data-copiar-objeto') === 'a URL' ? 'a URL' : 'a senha';
            var rotuloAria = bloco.getAttribute('data-copiar-aria');
            if (rotuloAria) { botao.setAttribute('aria-label', rotuloAria); }
            botao.appendChild(icone('copiar'));
            botao.appendChild(criar('span', '', bloco.getAttribute('data-copiar-rotulo') || 'Copiar'));
            // sem JS fica o link alternativo (ex.: "Abrir URL" na lista); com JS ele dá lugar ao botão
            Array.prototype.forEach.call(bloco.querySelectorAll('[data-copiar-alternativa]'), function (alt) {
                if (alt.parentNode) { alt.parentNode.removeChild(alt); }
            });
            var status = criar('span', 'gestao-copiar-status');
            // ids únicos: o primeiro bloco da página usa "copiar-status" (telas com um só botão); os demais, derivados do botão
            status.id = document.getElementById('copiar-status') ? 'copiar-status-' + botao.id : 'copiar-status';
            status.setAttribute('role', 'status');
            status.setAttribute('aria-live', 'polite');
            bloco.appendChild(botao);
            bloco.appendChild(status);

            function resultado(ok) {
                selecionar(fonte);
                status.textContent = ok ? 'Copiado.' : 'Não foi possível copiar. Selecione ' + objeto + ' e use Ctrl+C.';
            }
            botao.addEventListener('click', function () {
                var texto = fonte.textContent;
                if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
                    navigator.clipboard.writeText(texto).then(function () { resultado(true); }, function () { resultado(copiarFallback(fonte)); });
                } else {
                    resultado(copiarFallback(fonte));
                }
            });
        });
    }

    /* ------------------------------------------------------------------ */
    /* Flash: nenhum tipo some sozinho (WCAG 2.2.1); só "Dispensar" remove  */
    /* ------------------------------------------------------------------ */
    function iniciarFlash() {
        var flash = document.getElementById('gestao-flash');
        // a mensagem vem de ?msg=codigo: tira da URL para não reaparecer ao recarregar
        try {
            var url = new URL(window.location.href);
            if (url.searchParams.has('msg')) {
                url.searchParams.delete('msg');
                window.history.replaceState(null, '', url.pathname + url.search + url.hash);
            }
        } catch (e) { /* sem History API: ignora */ }
        if (!flash) { return; }

        function remover() {
            if (flash.parentNode) { flash.parentNode.removeChild(flash); }
        }
        var fechar = criar('button', 'gestao-flash__fechar');
        fechar.type = 'button';
        fechar.id = 'gestao-flash-fechar';
        fechar.appendChild(icone('fechar'));
        fechar.appendChild(criar('span', '', 'Dispensar'));
        fechar.addEventListener('click', function () {
            remover();
            var principal = document.getElementById('conteudo');
            if (principal) { principal.focus(); }
        });
        flash.appendChild(fechar);

    }

    /* ------------------------------------------------------------------ */
    /* Anti duplo clique                                                   */
    /* ------------------------------------------------------------------ */
    function iniciarAntiDuploClique() {
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!(form instanceof HTMLFormElement) || e.defaultPrevented) { return; }
            if (form.getAttribute('aria-busy') === 'true') {
                e.preventDefault();
                return;
            }
            form.setAttribute('aria-busy', 'true');
            // desabilita DEPOIS que o navegador montou os dados (senão o botão "acao" some do POST)
            window.setTimeout(function () {
                var botoes = form.querySelectorAll('button:not([type="button"]), input[type="submit"]');
                Array.prototype.forEach.call(botoes, function (b) {
                    if (!b.disabled) {
                        b.disabled = true;
                        b.setAttribute('data-gestao-desabilitado', '1');
                    }
                });
            }, 0);
        });
        // voltar pelo histórico (bfcache) não pode deixar o formulário travado
        window.addEventListener('pageshow', function (e) {
            if (!e.persisted) { return; }
            Array.prototype.forEach.call(document.querySelectorAll('form[aria-busy]'), function (f) { f.removeAttribute('aria-busy'); });
            Array.prototype.forEach.call(document.querySelectorAll('[data-gestao-desabilitado]'), function (b) {
                b.disabled = false;
                b.removeAttribute('data-gestao-desabilitado');
            });
        });
    }

    function iniciar() {
        iniciarSidebar();
        iniciarMostrarSenha();
        iniciarRequisitos();
        iniciarConfirmacao();
        iniciarCopiar();
        iniciarFlash();
        iniciarAntiDuploClique();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
}());
