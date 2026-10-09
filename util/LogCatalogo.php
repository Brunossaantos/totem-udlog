<?php

namespace Util;

/**
 * Catalogo FECHADO do log central (tb_log_sistema, migration 023). Quem registra
 * so escolhe uma CATEGORIA daqui: origem (aba), nivel, MENSAGEM e janela de
 * deduplicacao sao sempre os do catalogo, nunca do chamador. Nenhuma categoria
 * aceita texto livre: o contexto de cada uma e uma lista de chaves permitidas
 * (as genericas id_atendimento, id_totem, tipo, excecao, http e as de dominio
 * abaixo, cada qual com regra fechada).
 *
 * Campos de cada categoria:
 *  - origem:   'API'|'RECEBIMENTO'|'EXPEDICAO'|'CRON'|'GESTAO' ou 'por_tipo' (a
 *              aba sai do tipo do atendimento; sem atendimento nem tipo = API);
 *  - nivel:    'INFO'|'AVISO'|'ERRO';
 *  - mensagem: texto FIXO (ate 160 caracteres);
 *  - janela:   segundos do balde de deduplicacao;
 *  - contexto: chaves de contexto aceitas (qualquer outra descarta o evento);
 *  - interna:  (opcional) so a propria infraestrutura de log pode usar;
 *  - throttle_escrita: (opcional) evento que um anonimo dispara sem ids (401, recusa
 *              4xx): no web, no maximo 1 tentativa de escrita no banco por categoria
 *              por 60 s por servidor (ver Util\LogSistema).
 *
 * Eventos que um anonimo consegue disparar (token de totem invalido, 401) NAO
 * listam id_totem nem id_atendimento: uma linha por janela, sem cardinalidade
 * controlada por quem ataca. id_totem so entra em categorias chamadas DEPOIS de
 * o totem ter sido autenticado.
 */
final class LogCatalogo
{
    public const NIVEIS = ['INFO', 'AVISO', 'ERRO'];

    public const ORIGENS = ['API', 'RECEBIMENTO', 'EXPEDICAO', 'CRON', 'GESTAO'];

    public const JANELA_PADRAO = 300;

    /** Chaves genericas do contexto (regras fixas em Util\LogSistema). */
    public const CHAVES_GERAIS = ['id_atendimento', 'id_totem', 'tipo', 'excecao', 'http'];

    /** Motivos de catalogo (valor de `motivo`). */
    public const MOTIVOS = [
        'https_obrigatorio', 'indisponivel', 'muitas_tentativas', 'nao_autorizado',
        'arquivo_muito_grande', 'corpo_invalido', 'dados_invalidos', 'pdf_invalido',
        'metodo_nao_permitido', 'auditoria_indisponivel', 'erro_banco', 'timeout',
        'falha_inesperada', 'config_ausente', 'config_invalida', 'lock_ocupado',
        'categoria_desconhecida', 'chave_desconhecida', 'valor_invalido',
        'categoria_interna', 'teto_diario', 'teto_total', 'teto_categoria',
        'baixa_pendente', 'pendencia_nao_registrada',
        'caminho_invalido', 'tamanho_invalido', 'nao_e_pdf', 'sha256_divergente',
        'leitura_falhou', 'prefixo_cnpj_divergente',
    ];

    public const CATEGORIAS_ERRO_TALENT = [
        'erro_validacao', 'erro_autenticacao', 'nao_encontrado', 'conflito',
        'erro_servidor', 'timeout', 'erro_indeterminado', 'erro_conexao',
        'erro_http', 'resposta_ilegivel', 'erro_montagem_payload',
    ];

    public const JOBS = [
        'abandonar_atendimentos', 'limpar_notas_quarentena', 'limpar_anexos_oc',
        'limpar_rate_limit_ocr', 'limpar_logs_gestao',
    ];

    /**
     * Regras das chaves de dominio: ['enum', lista] ou ['int', min, max].
     * `alvo` e preenchida so internamente (nome de categoria do catalogo).
     */
    public const DOMINIO = [
        'motivo' => ['enum', self::MOTIVOS],
        'categoria_erro' => ['enum', self::CATEGORIAS_ERRO_TALENT],
        'documento' => ['enum', ['cnh', 'crlv']],
        'job' => ['enum', self::JOBS],
        'logs_apagados' => ['int', 0, 999999999],
        'auditoria_apagados' => ['int', 0, 999999999],
        'lotes' => ['int', 0, 99999],
        'itens' => ['int', 0, 99999],
        'falhas' => ['int', 0, 99999],
    ];

    /** Ordem fixa das chaves no `detalhe` (apos classe, sqlstate e http). */
    public const ORDEM_DOMINIO = ['alvo', 'job', 'motivo', 'categoria_erro', 'documento', 'logs_apagados', 'auditoria_apagados', 'lotes', 'itens', 'falhas'];

    public const CATEGORIAS = [
        'totem_nao_autorizado' => [
            'origem' => 'API', 'nivel' => 'AVISO',
            'mensagem' => 'Requisição à API com token de totem informado e inválido.',
            'janela' => 900, 'contexto' => [], 'throttle_escrita' => true,
        ],
        'oc_consulta_falhou' => [
            'origem' => 'EXPEDICAO', 'nivel' => 'ERRO',
            'mensagem' => 'Falha ao consultar a ordem de coleta.',
            'janela' => 300, 'contexto' => ['id_atendimento', 'id_totem', 'excecao', 'http', 'motivo'],
        ],
        'oc_baixa_falhou' => [
            'origem' => 'EXPEDICAO', 'nivel' => 'ERRO',
            'mensagem' => 'Falha ao dar baixa na ordem de coleta.',
            'janela' => 300, 'contexto' => ['id_atendimento', 'id_totem', 'excecao', 'http', 'motivo'],
        ],
        'banco_coletas_indisponivel' => [
            'origem' => 'por_tipo', 'nivel' => 'ERRO',
            'mensagem' => 'Banco de gestão de coletas indisponível.',
            'janela' => 300, 'contexto' => ['id_atendimento', 'id_totem', 'tipo', 'excecao'],
        ],
        'n8n_anexo_recusado' => [
            'origem' => 'API', 'nivel' => 'AVISO',
            'mensagem' => 'Anexo de ordem de coleta recusado pela API.',
            'janela' => 300, 'contexto' => ['http', 'motivo'], 'throttle_escrita' => true,
        ],
        'n8n_anexo_erro' => [
            'origem' => 'API', 'nivel' => 'ERRO',
            'mensagem' => 'Erro ao processar o anexo de ordem de coleta.',
            'janela' => 300, 'contexto' => ['excecao', 'http', 'motivo'],
        ],
        'talent_erro_reprocessavel' => [
            'origem' => 'por_tipo', 'nivel' => 'ERRO',
            'mensagem' => 'Talent recusou o check-in (erro reprocessável).',
            'janela' => 300, 'contexto' => ['id_atendimento', 'id_totem', 'tipo', 'excecao', 'http', 'categoria_erro'],
        ],
        'talent_indeterminado' => [
            'origem' => 'por_tipo', 'nivel' => 'ERRO',
            'mensagem' => 'Envio ao Talent com resultado indeterminado.',
            'janela' => 300, 'contexto' => ['id_atendimento', 'id_totem', 'tipo', 'excecao', 'http', 'categoria_erro'],
        ],
        'vio_falha_integracao' => [
            'origem' => 'por_tipo', 'nivel' => 'ERRO',
            'mensagem' => 'Falha na integração de leitura de documento (VIO).',
            'janela' => 300, 'contexto' => ['id_atendimento', 'id_totem', 'tipo', 'excecao', 'http', 'documento', 'motivo'],
        ],
        'vio_erro_interno' => [
            'origem' => 'por_tipo', 'nivel' => 'ERRO',
            'mensagem' => 'Erro interno no processamento de documento (VIO).',
            'janela' => 300, 'contexto' => ['id_atendimento', 'id_totem', 'tipo', 'excecao', 'documento', 'motivo'],
        ],
        'erro_banco_pdo' => [
            'origem' => 'por_tipo', 'nivel' => 'ERRO',
            'mensagem' => 'Falha de banco de dados.',
            'janela' => 300, 'contexto' => ['id_atendimento', 'id_totem', 'tipo', 'excecao'],
        ],
        'erro_tecnico' => [
            'origem' => 'por_tipo', 'nivel' => 'ERRO',
            'mensagem' => 'Erro técnico inesperado.',
            'janela' => 300, 'contexto' => ['id_atendimento', 'id_totem', 'tipo', 'excecao', 'http', 'motivo'],
        ],
        'rate_limit_ocr_excedido' => [
            'origem' => 'RECEBIMENTO', 'nivel' => 'AVISO',
            'mensagem' => 'Limite de leitura de notas excedido pelo totem.',
            'janela' => 600, 'contexto' => ['id_atendimento', 'id_totem'],
        ],
        'etiqueta_pdf_falhou' => [
            'origem' => 'por_tipo', 'nivel' => 'ERRO',
            'mensagem' => 'Falha ao gerar o PDF da etiqueta.',
            'janela' => 300, 'contexto' => ['id_atendimento', 'id_totem', 'tipo', 'excecao'],
        ],
        'etiqueta_config_invalida' => [
            'origem' => 'por_tipo', 'nivel' => 'ERRO',
            'mensagem' => 'Configuração da etiqueta inválida.',
            'janela' => 3600, 'contexto' => ['id_atendimento', 'id_totem', 'tipo', 'motivo'],
        ],
        'impressao_config_falhou' => [
            'origem' => 'por_tipo', 'nivel' => 'ERRO',
            'mensagem' => 'Falha ao ler a configuração do serviço de impressão.',
            'janela' => 300, 'contexto' => ['id_atendimento', 'id_totem', 'tipo', 'excecao', 'motivo'],
        ],
        'cron_resumo' => [
            'origem' => 'CRON', 'nivel' => 'INFO',
            'mensagem' => 'Rotina agendada concluída.',
            'janela' => 300, 'contexto' => ['job', 'logs_apagados', 'auditoria_apagados', 'lotes', 'itens', 'falhas'],
        ],
        'cron_falhou' => [
            'origem' => 'CRON', 'nivel' => 'ERRO',
            'mensagem' => 'Rotina agendada falhou.',
            'janela' => 300, 'contexto' => ['excecao', 'job', 'motivo', 'logs_apagados', 'auditoria_apagados', 'lotes', 'itens', 'falhas'],
        ],
        'gestao_erro_interno' => [
            'origem' => 'GESTAO', 'nivel' => 'ERRO',
            'mensagem' => 'Erro interno na Gestão Totem.',
            'janela' => 300, 'contexto' => ['excecao', 'http', 'motivo'],
        ],
        'auditoria_falhou' => [
            'origem' => 'GESTAO', 'nivel' => 'ERRO',
            'mensagem' => 'Falha ao gravar a trilha de auditoria.',
            'janela' => 300, 'contexto' => ['excecao'],
        ],
        'log_parametro_invalido' => [
            'origem' => 'API', 'nivel' => 'AVISO',
            'mensagem' => 'Chamada de log descartada por parâmetro inválido.',
            'janela' => 300, 'contexto' => [], 'interna' => true,
        ],
        'log_suprimido' => [
            'origem' => 'API', 'nivel' => 'AVISO',
            'mensagem' => 'Registros de log suprimidos pelo teto de volume.',
            'janela' => 900, 'contexto' => [], 'interna' => true,
        ],
    ];

    /** @return array<string,mixed>|null */
    public static function obter(string $categoria): ?array
    {
        return self::CATEGORIAS[$categoria] ?? null;
    }
}
