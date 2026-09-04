<?php

namespace App\Rn;

use App\Dao\FilaEnvioDao;

class TalentRn
{
    public function __construct(
        private TalentClient $talentClient,
        private FilaEnvioDao $filaEnvioDao,
        private string $caminhoBase
    ) {}

    public function montarPayload(array $atendimento, array $notas): array
    {
        return [
            'codigo_atendimento' => $atendimento['codigo_publico'],
            'tipo'               => $atendimento['tipo'],
            'placa'              => $atendimento['placa'],
            'motorista'          => [
                'nome' => $atendimento['motorista_nome'],
                'cpf'  => $atendimento['motorista_cpf'],
            ],
            'cliente'            => [
                'nome' => $atendimento['cliente_nome'],
                'cnpj' => $atendimento['cliente_cnpj'],
            ],
            'ajudante'           => $atendimento['possui_ajudante']
                ? ['nome' => $atendimento['ajudante_nome'], 'cpf' => $atendimento['ajudante_cpf']]
                : null,
            // campo especifico esperado pelo Talent: anexos em base64
            'anexos'             => $this->montarAnexos($atendimento, $notas),
        ];
    }

    public function enviar(array $payload): array
    {
        return $this->talentClient->enviarAtendimento($payload);
    }

    public function registrarFalhaParaReenvio(int $idAtendimento, string $erro): void
    {
        $this->filaEnvioDao->registrarFalha($idAtendimento, $erro);
    }

    private function montarAnexos(array $atendimento, array $notas): array
    {
        $pasta = rtrim($this->caminhoBase, '/') . '/' . $atendimento['pasta_documentos'];

        $anexos = array_filter([
            $this->anexarArquivo($pasta . '/cnh.jpg', 'cnh'),
            $this->anexarArquivo($pasta . '/crlv.jpg', 'crlv'),
        ]);

        foreach ($notas as $nota) {
            $anexo = $this->anexarArquivo($pasta . '/' . $nota['arquivo'], 'nota_fiscal');
            if ($anexo) {
                $anexos[] = $anexo;
            }
        }

        return array_values($anexos);
    }

    private function anexarArquivo(string $caminho, string $tipo): ?array
    {
        if (!is_file($caminho)) {
            return null; // nao trava o envio se faltar um anexo — loga e segue
        }

        return [
            'tipo'     => $tipo,
            'nome'     => basename($caminho),
            'mime'     => mime_content_type($caminho),
            'conteudo' => base64_encode(file_get_contents($caminho)),
        ];
    }
}
