<?php

namespace App\Controller;

use Util\UploadHelper;
use Util\Resposta;
use App\Dao\AtendimentoDao;

class DocumentoController
{
    public function __construct(private AtendimentoDao $atendimentoDao) {}

    public function upload(array $entrada): void
    {
        $idAtendimento = (int) ($entrada['id_atendimento'] ?? 0);
        $tipo = $entrada['tipo'] ?? null; // 'cnh' | 'crlv'
        $imagemBase64 = $entrada['imagem'] ?? null;

        if (!$idAtendimento || !in_array($tipo, ['cnh', 'crlv'], true) || !$imagemBase64) {
            Resposta::erro('Dados incompletos');
        }

        $atendimento = $this->atendimentoDao->buscarPorId($idAtendimento);
        if (!$atendimento) {
            Resposta::erro('Atendimento nao encontrado', 404);
        }

        UploadHelper::salvarImagemBase64($imagemBase64, $atendimento['pasta_documentos'], "{$tipo}.jpg");

        Resposta::sucesso(['ok' => true]);
    }
}
