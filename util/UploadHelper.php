<?php

namespace Util;

class UploadHelper
{
    /**
     * Limite maximo por lado (largura OU altura), em pixels. Acima disso a
     * imagem eh rejeitada ANTES de qualquer decodificacao completa via GD —
     * evita alocar buffer de pixels desproporcional (bomba de descompressao)
     * mesmo que o arquivo em bytes esteja dentro de NOTA_IMAGEM_MAX_BYTES.
     * Valor confirmado pelo usuario (nao e variavel de .env por decisao
     * explicita desta demanda).
     */
    private const JPEG_DIMENSAO_MAXIMA_PX = 5000;

    /**
     * Limite maximo de area total (largura x altura), em pixels. Reforca o
     * limite por lado acima: uma imagem poderia ter, por exemplo,
     * 4999x4999px (dentro do limite por lado) mas ainda assim exigir um
     * buffer de pixels grande demais — este segundo limite cobre esse caso.
     */
    private const JPEG_AREA_MAXIMA_PX = 13000000;

    /**
     * Pasta do atendimento: AAAA-MM-DD/PLACA_HHMMSS — nunca eh usada pra localizar
     * o registro no banco, so serve pra navegacao humana no File Manager.
     */
    public static function montarPasta(string $placa): string
    {
        $agora = new \DateTime('now', new \DateTimeZone('America/Sao_Paulo'));
        $placaLimpa = preg_replace('/[^A-Za-z0-9]/', '', $placa);

        return $agora->format('Y-m-d') . '/' . strtoupper($placaLimpa) . '_' . $agora->format('His');
    }

    /**
     * Salva uma imagem enviada como data URL base64. Nesta fase so JPEG e
     * aceito (unico mimetype usado hoje em todo o totem — nota fiscal, CNH e
     * CRLV). Nome de arquivo e sempre definido pelo servidor (nunca vem do
     * cliente) — quem chama esta funcao ja monta $nomeArquivo com valores
     * controlados no backend (ex: sprintf('nota_%02d.jpg', $ordem)).
     */
    public static function salvarImagemBase64(string $base64, string $pastaRelativa, string $nomeArquivo): string
    {
        // Etapa 1: prefixo do data URL — so delimitador de string, nunca fonte
        // de verdade de tipo (isso e reforcado nas etapas seguintes).
        if (!preg_match('#^data:image/jpeg;base64,#', $base64)) {
            throw new \RuntimeException('Formato de imagem nao suportado: apenas JPEG e aceito');
        }

        // Etapa 1 (cont.): Base64 decodificado em modo estrito — rejeita
        // qualquer caractere fora do alfabeto Base64 valido.
        $base64Limpo = preg_replace('#^data:image/jpeg;base64,#', '', $base64);
        $binario = base64_decode($base64Limpo, true);

        if ($binario === false || $binario === '') {
            throw new \RuntimeException('Falha ao decodificar imagem');
        }

        // Etapa 2: limite de bytes ja existente, preservado sem alteracao de
        // valor/variavel.
        $maxBytes = isset($_ENV['NOTA_IMAGEM_MAX_BYTES']) && $_ENV['NOTA_IMAGEM_MAX_BYTES'] !== ''
            ? (int) $_ENV['NOTA_IMAGEM_MAX_BYTES']
            : 5 * 1024 * 1024;

        if (strlen($binario) > $maxBytes) {
            throw new \RuntimeException('Imagem excede o tamanho maximo permitido');
        }

        // Etapa 3: marcador inicial JPEG (SOI + primeiro byte do proximo
        // marcador). Mantido como \xFF\xD8\xFF (nao so \xFF\xD8) porque um
        // JPEG real sempre tem outro marcador logo em seguida ao SOI (ex.
        // APP0/APP1/DQT, todos comecando em \xFF) — exigir o terceiro byte
        // \xFF reduz falso-negativo de forma barata sem custo de compatibilidade
        // conhecido (todo gerador JPEG real, incluindo canvas.toDataURL e
        // libjpeg do Netum, emite esse terceiro byte).
        if (substr($binario, 0, 3) !== "\xFF\xD8\xFF") {
            throw new \RuntimeException('Conteudo da imagem nao e um JPEG valido');
        }

        // Etapa 4 e 5: marcador final JPEG (EOI, FF D9) deve ser EXATAMENTE
        // os 2 ultimos bytes do binario decodificado — nao "em algum lugar
        // perto do fim". Isso cobre tanto o truncamento (sem EOI no fim)
        // quanto o caso de bytes adicionais apos o EOI real (o EOI "esta la"
        // mas nao e o ultimo byte, o que tambem e rejeitado por esta checagem
        // estrita de posicao).
        if (substr($binario, -2) !== "\xFF\xD9") {
            throw new \RuntimeException('Conteudo da imagem nao e um JPEG valido');
        }

        // Etapa 6, 7 e 8: getimagesizefromstring usado apenas para
        // identificar tipo real (nunca o MIME informado pelo cliente) e
        // dimensoes — nao decide validade estrutural sozinho (isso ja foi
        // coberto pelas etapas 3-5, e a integridade completa do conteudo
        // ainda sera confirmada na decodificacao via GD, etapas 12-14).
        $info = @getimagesizefromstring($binario);
        if ($info === false || $info[2] !== IMAGETYPE_JPEG || $info[0] <= 0 || $info[1] <= 0) {
            throw new \RuntimeException('Conteudo da imagem nao e um JPEG valido');
        }

        $largura = $info[0];
        $altura = $info[1];

        // Etapa 9: limite de dimensao por lado, verificado ANTES de qualquer
        // decodificacao completa.
        if ($largura > self::JPEG_DIMENSAO_MAXIMA_PX || $altura > self::JPEG_DIMENSAO_MAXIMA_PX) {
            throw new \RuntimeException('Imagem excede a dimensao maxima permitida');
        }

        // Etapa 10 e 11: limite de area total, calculado de forma defensiva.
        // Neste ponto $largura e $altura ja passaram pelo limite por lado
        // (etapa 9, no maximo JPEG_DIMENSAO_MAXIMA_PX cada), entao o produto
        // nunca chega perto de um valor astronomico antes mesmo desta
        // checagem — em PHP inteiros muito grandes viram float
        // automaticamente (nao ha overflow silencioso de int como em C), mas
        // ainda assim comparamos via divisao (area maxima / altura) em vez de
        // multiplicar cegamente largura * altura, evitando qualquer
        // multiplicacao desnecessaria de numeros grandes antes da checagem.
        if ($largura > self::JPEG_AREA_MAXIMA_PX / $altura) {
            throw new \RuntimeException('Imagem excede a dimensao maxima permitida');
        }

        // Tratamento fail-closed do GD: sem fallback para validacao so
        // estrutural se a extensao ou a funcao de decodificacao nao
        // estiverem disponiveis — falha sempre fechada.
        if (!extension_loaded('gd') || !function_exists('imagecreatefromstring')) {
            throw new \RuntimeException('Conteudo da imagem nao e um JPEG valido');
        }

        // Etapas 12-14: decodificacao completa via GD, SOMENTE apos todas as
        // checagens estruturais e de dimensao acima terem passado. Serve
        // como camada ADICIONAL de validacao (decodificacao real dos dados
        // de scan) — util contra truncamento sem EOI colado e outras formas
        // de corrupcao que o GD efetivamente rejeita.
        //
        // Risco residual aceito pelo usuario em 2026-09-17
        // (docs/handoffs/2026-09-17-validacao-jpeg-segura.md): nesta
        // instalacao de GD (bundled, PHP 8.0.30, Windows),
        // imagecreatefromstring() decodifica SILENCIOSAMENTE — sem retornar
        // false nem emitir warning — um JPEG truncado no meio do stream de
        // scan quando um EOI (\xFF\xD9) foi colado manualmente no ultimo
        // byte do arquivo (bypass deliberado da checagem estrutural das
        // etapas 4-5). Ja foi testado e confirmado que a diretiva nativa
        // 'gd.jpeg_ignore_warning' NAO tem efeito perceptivel contra esse
        // vetor nesta build — por isso essa tentativa foi removida (nao ha
        // mitigacao nativa do GD disponivel para esse caso especifico). A
        // barreira efetiva contra o bug ORIGINAL (truncamento acidental,
        // sem EOI) continua sendo a checagem estrutural exata das etapas
        // 4-5, que permanece inalterada.
        //
        // Qualquer warning emitido pelo GD durante a decodificacao (ex.
        // dados de scan incompletos que o GD de fato rejeita) e capturado
        // por um error handler temporario que so marca uma flag — nunca
        // registra o conteudo do warning (pode conter detalhe tecnico do
        // parser). O handler anterior e restaurado em um bloco finally,
        // cobrindo tanto o caminho de sucesso quanto qualquer excecao
        // lancada durante a decodificacao/validacao subsequente.
        $houveWarningGd = false;
        $imagemGd = false;

        set_error_handler(static function () use (&$houveWarningGd): bool {
            $houveWarningGd = true;
            return true; // impede que o warning padrao do PHP seja exibido/logado
        });

        try {
            $imagemGd = imagecreatefromstring($binario);

            if ($imagemGd === false || $houveWarningGd) {
                throw new \RuntimeException('Conteudo da imagem nao e um JPEG valido');
            }
        } finally {
            // Etapa 14: libera imediatamente o recurso/objeto GD decodificado,
            // SE ele tiver sido criado com sucesso — nao mantemos mais de uma
            // imagem GD em memoria simultaneamente. No PHP 8.0.30 o GD ja
            // retorna um objeto GdImage (nao mais resource), mas
            // imagedestroy() continua definido e seguro de chamar nesta
            // versao (so passa a ser depreciado em versoes futuras do PHP).
            if ($imagemGd !== false) {
                imagedestroy($imagemGd);
            }

            restore_error_handler();
        }

        // Etapa 15: o arquivo gravado em disco eh o JPEG ORIGINAL aprovado
        // ($binario, os bytes originais decodificados do base64) — sem
        // recompressao e sem remocao de EXIF. imagecreatefromstring() acima
        // serviu somente para VALIDAR a integridade, nao para gerar o
        // conteudo persistido.
        $pastaCompleta = rtrim($_ENV['STORAGE_PATH'], '/') . '/' . $pastaRelativa;

        if (!is_dir($pastaCompleta)) {
            if (!mkdir($pastaCompleta, 0750, true) && !is_dir($pastaCompleta)) {
                throw new \RuntimeException('Falha ao criar diretorio de armazenamento');
            }
        }

        // $nomeArquivo e sempre definido pelo servidor (sprintf com int validado
        // ou literal fixo) — nunca monta o caminho com dado bruto do cliente
        $caminhoArquivo = $pastaCompleta . '/' . $nomeArquivo;

        if (file_put_contents($caminhoArquivo, $binario) === false) {
            throw new \RuntimeException('Falha ao gravar a imagem no armazenamento');
        }

        return $caminhoArquivo;
    }
}
