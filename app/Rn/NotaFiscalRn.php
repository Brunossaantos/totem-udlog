<?php

namespace App\Rn;

use App\Dao\AtendimentoNotaDao;
use App\Dao\ClienteDao;
use Util\CnpjValidador;
use Util\RazaoSocialMatcher;

class NotaFiscalRn
{
    // Limite maximo de CNPJs candidatos aceitos por chamada de
    // identificar-cliente — mitigacao contra uso do endpoint como "oraculo"
    // de existencia de CNPJ/dados de cliente (achado do security-especialista
    // na etapa de planejamento). Mesma ordem de grandeza do limite de 5
    // notas por atendimento, com folga.
    private const MAX_CNPJS_CANDIDATOS = 10;

    public function __construct(
        private AtendimentoNotaDao $notaDao,
        private ClienteDao $clienteDao
    ) {}

    public function processarLeitura(int $idAtendimento, int $ordem, string $arquivo, ?string $chave): array
    {
        $cnpjEmitente = null;
        $clienteIdentificado = false;
        $cliente = null;

        if ($chave && $this->chaveValida($chave)) {
            $decodificada = $this->decodificarChave($chave);
            if ($decodificada['dv_ok']) {
                $cnpjEmitente = $decodificada['cnpj_emitente'];
                $cliente = $this->clienteDao->buscarPorCnpj($cnpjEmitente);
                $clienteIdentificado = $cliente !== null;
            }
        }

        $this->notaDao->inserir($idAtendimento, $ordem, $arquivo, $chave, $cnpjEmitente, $clienteIdentificado);

        return ['cliente_identificado' => $clienteIdentificado, 'cliente' => $cliente];
    }

    public function algumaNotaIdentificouCliente(int $idAtendimento): bool
    {
        return $this->notaDao->algumaIdentificada($idAtendimento);
    }

    public function contarNotas(int $idAtendimento): int
    {
        return $this->notaDao->contarPorAtendimento($idAtendimento);
    }

    public function ordemJaRegistrada(int $idAtendimento, int $ordem): bool
    {
        return $this->notaDao->existeOrdem($idAtendimento, $ordem);
    }

    /**
     * Busca a nota de uma ordem especifica dentro de um atendimento (usada
     * pelo endpoint identificar-cliente para validar posse/existencia antes
     * de aceitar o resultado — IDOR).
     */
    public function buscarNotaDaOrdem(int $idAtendimento, int $ordem): ?array
    {
        return $this->notaDao->buscarPorAtendimentoEOrdem($idAtendimento, $ordem);
    }

    /**
     * Endpoint identificar-cliente (demanda recebimento-leitura-notas).
     * Sequencia completa conforme handoff (passo 0 = early-stop por
     * atendimento, depois CNPJ exato > fuzzy de razao social). IDOR/posse
     * do atendimento e da nota ja devem ter sido validados pelo chamador
     * (Controller) antes desta chamada.
     *
     * Decisao aprovada em 2026-09-04 (diagnostico real de OCR): a chave de
     * acesso (44 digitos) foi removida do processo de identificacao de
     * cliente — extracao de 44 digitos consecutivos via OCR se mostrou
     * estruturalmente fragil (nunca validou em 14 combinacoes testadas com
     * imagens reais, mesmo com CNPJ solto e razao social ja corretos). O
     * parametro $chaveOcr e mantido na assinatura so por compatibilidade
     * com o payload do front-end (que pode enviar null ou omitir o campo),
     * mas e ignorado por completo — nao processado, nao validado, nao
     * usado para nada nesta funcao.
     *
     * @param array<int, string> $cnpjsCandidatos strings brutas, com ou sem mascara
     */
    public function identificarCliente(
        int $idAtendimento,
        int $idNota,
        ?string $chaveOcr,
        array $cnpjsCandidatos,
        ?string $razaoSocialCandidata
    ): array {
        // Passo 0 — early-stop: se o ATENDIMENTO ja tem cliente identificado
        // (nota anterior ou chamada duplicada), responde imediatamente, sem
        // nenhuma nova consulta de negocio (CNPJ exato/fuzzy) — so uma
        // consulta local pontual pra popular o campo "cliente" da resposta.
        $jaIdentificada = $this->notaDao->algumaNotaComStatusIdentificada($idAtendimento);
        if ($jaIdentificada !== null) {
            $cliente = null;
            if (!empty($jaIdentificada['cnpj_emitente'])) {
                try {
                    $cliente = $this->mapClienteLocal($this->clienteDao->buscarPorCnpj($jaIdentificada['cnpj_emitente']));
                } catch (\Throwable $e) {
                    // falha tecnica so nessa consulta pontual (so serve pra
                    // popular a resposta, nao decide nada) — nao vira ERRO,
                    // so fica sem "cliente" detalhado na resposta.
                    error_log('identificar-cliente: falha ao popular cliente do short-circuit: ' . $e->getMessage());
                }
            }

            // marca tambem a nota atual, se ainda nao estava marcada (recomendacao
            // do handoff, nao trava — mantem status_ocr consistente no atendimento)
            if ((int) $jaIdentificada['id_nota'] !== $idNota) {
                $this->notaDao->atualizarResultadoOcr($idNota, 'IDENTIFICADA', $jaIdentificada['cnpj_emitente'], true);
            }

            return [
                'status'                         => 'IDENTIFICADA',
                'cliente'                        => $cliente,
                'ja_identificado_no_atendimento' => true,
            ];
        }

        // Passo 4/5 — normaliza/valida cada CNPJ candidato (texto solto
        // reconhecido pelo OCR — UNICA fonte de CNPJ candidato, chave de
        // acesso removida do processo) e exclui os da UDLOG. Limite de itens
        // aceitos por requisicao aplicado ANTES de processar.
        $cnpjsCandidatos = array_slice($cnpjsCandidatos, 0, self::MAX_CNPJS_CANDIDATOS);

        $candidatosValidos = [];
        foreach ($cnpjsCandidatos as $bruto) {
            if (!is_string($bruto)) {
                continue;
            }
            $normalizado = CnpjValidador::normalizarEValidar($bruto);
            if ($normalizado !== null && !CnpjValidador::ehUdlog($normalizado)) {
                $candidatosValidos[] = $normalizado;
            }
        }
        // remove duplicados preservando a ordem recebida em cnpjs_candidatos
        $candidatosValidos = array_values(array_unique($candidatosValidos));

        $status = 'NAO_IDENTIFICADA';
        $cliente = null;
        $cnpjPersistir = null;
        $erroTecnico = false;

        // Passo 6 — CNPJ exato contra a tabela local de clientes (tb_cliente,
        // via ClienteDao), na ordem recebida. Ate 2026-09-08 essa consulta
        // era contra a API externa (App\Rn\ClienteApiClient) — trocada por
        // consulta local (mesma tabela ja usada por processarLeitura acima
        // e por public/api/cliente.php), decisao aprovada pelo usuario.
        foreach ($candidatosValidos as $cnpj) {
            try {
                $encontrado = $this->clienteDao->buscarPorCnpj($cnpj);
            } catch (\Throwable $e) {
                error_log('identificar-cliente: falha tecnica ao consultar tabela local de clientes por CNPJ: ' . $e->getMessage());
                $erroTecnico = true;
                break;
            }
            if ($encontrado !== null) {
                $status = 'IDENTIFICADA';
                $cliente = $this->mapClienteLocal($encontrado);
                $cnpjPersistir = $cnpj;
                break;
            }
        }

        // Passo 7 — sem match de CNPJ e sem erro tecnico: fuzzy de razao social
        // contra a listagem local (ClienteDao::listarParaFuzzy — ja vem com
        // razao_social_normalizada pre-computada no banco, ver o comentario
        // daquele metodo). O TEXTO CANDIDATO do OCR continua sendo
        // normalizado em tempo real dentro de RazaoSocialMatcher::
        // melhorCandidato (isso nao muda) — so a listagem de clientes e que
        // deixa de ser normalizada do zero a cada chamada.
        if ($status === 'NAO_IDENTIFICADA' && !$erroTecnico && $razaoSocialCandidata !== null && trim($razaoSocialCandidata) !== '') {
            try {
                $listagem = $this->clienteDao->listarParaFuzzy();
                $resultadoFuzzy = RazaoSocialMatcher::melhorCandidato($razaoSocialCandidata, $listagem);
                if ($resultadoFuzzy['identificado']) {
                    $status = 'IDENTIFICADA';
                    $cnpjPersistir = CnpjValidador::normalizarEValidar((string) ($resultadoFuzzy['cliente']['cnpj'] ?? '')) ?? $cnpjPersistir;
                    $cliente = $this->mapClienteLocal($resultadoFuzzy['cliente']);
                }
            } catch (\Throwable $e) {
                error_log('identificar-cliente: falha tecnica ao consultar listagem local de clientes: ' . $e->getMessage());
                $erroTecnico = true;
            }
        }

        // Passo 8 — falha tecnica prevalece sobre NAO_IDENTIFICADA.
        if ($erroTecnico) {
            $status = 'ERRO';
        }

        // Passo 9 — persiste o resultado desta chamada.
        $this->notaDao->atualizarResultadoOcr($idNota, $status, $cnpjPersistir, $status === 'IDENTIFICADA');

        // Passo 10 — ja_identificado_no_atendimento calculado APOS a resolucao
        // desta chamada (true se esta propria chamada identificou, ou se, por
        // corrida/outra nota, o atendimento ja estava identificado nesse meio-tempo).
        $jaIdentificadoNoAtendimento = $status === 'IDENTIFICADA'
            || $this->notaDao->algumaNotaComStatusIdentificada($idAtendimento) !== null;

        return [
            'status'                         => $status,
            'cliente'                        => $cliente,
            'ja_identificado_no_atendimento' => $jaIdentificadoNoAtendimento,
        ];
    }

    /**
     * Normaliza o shape do cliente vindo de tb_cliente local
     * (id_cliente/nome/cnpj/...) para o mesmo shape usado antes pela API
     * externa (id/razao_social/cnpj), preservando o contrato de resposta do
     * endpoint identificar-cliente mesmo com a troca de fonte de dado.
     * Usado tanto no resultado do CNPJ exato/early-stop (ClienteDao::
     * buscarPorCnpj, "nome" = razao social original) quanto no resultado do
     * fuzzy (ClienteDao::listarParaFuzzy, que tambem carrega "nome" junto
     * do campo "razao_social" pre-normalizado usado so para o calculo do
     * score) — em ambos os casos "nome" e a razao social original, exibida
     * ao chamador em vez da versao normalizada.
     */
    private function mapClienteLocal(?array $clienteLocal): ?array
    {
        if ($clienteLocal === null) {
            return null;
        }

        return [
            'id'           => $clienteLocal['id_cliente'] ?? null,
            'razao_social' => $clienteLocal['nome'] ?? null,
            'cnpj'         => $clienteLocal['cnpj'] ?? null,
        ];
    }

    public function chaveValida(string $chave): bool
    {
        return strlen($chave) === 44 && ctype_digit($chave);
    }

    // decodificacao 100% local, sem custo e sem chamada externa
    public function decodificarChave(string $chave): array
    {
        $aamm = substr($chave, 2, 4);
        $dvInformado = (int) substr($chave, 43, 1);
        $dvCalculado = $this->calcularDV(substr($chave, 0, 43));

        return [
            'uf'            => substr($chave, 0, 2),
            'emissao'       => substr($aamm, 2, 2) . '/20' . substr($aamm, 0, 2),
            'cnpj_emitente' => substr($chave, 6, 14),
            'serie'         => (int) substr($chave, 22, 3),
            'numero_nf'     => (int) substr($chave, 25, 9),
            'dv_ok'         => $dvInformado === $dvCalculado,
        ];
    }

    private function calcularDV(string $chave43): int
    {
        $peso = 2;
        $soma = 0;
        for ($i = strlen($chave43) - 1; $i >= 0; $i--) {
            $soma += (int) $chave43[$i] * $peso;
            $peso = $peso === 9 ? 2 : $peso + 1;
        }
        $resto = $soma % 11;
        return ($resto === 0 || $resto === 1) ? 0 : 11 - $resto;
    }
}
