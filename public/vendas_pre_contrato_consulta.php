<?php
/**
 * Consulta pública de pré-contrato recente para evitar duplicidade em formulários públicos.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/vendas_helper.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Método não permitido']);
        exit;
    }

    $cpf = vendas_normalizar_cpf($_GET['cpf'] ?? '');
    $tipoEvento = trim((string)($_GET['tipo_evento'] ?? ''));
    $memoriaDias = vendas_memoria_pre_contrato_dias();

    if ($cpf === '' || strlen($cpf) !== 11) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'CPF inválido']);
        exit;
    }

    if (!in_array($tipoEvento, ['casamento', '15anos', 'infantil', 'pj'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Tipo de evento inválido']);
        exit;
    }

    $now = time();
    $ultimasConsultas = $_SESSION['vendas_pre_contrato_lookup'] ?? [];
    if (!is_array($ultimasConsultas)) {
        $ultimasConsultas = [];
    }

    $ultimasConsultas = array_values(array_filter($ultimasConsultas, static function ($ts) use ($now) {
        return is_int($ts) && ($now - $ts) < 600;
    }));

    $ultimaConsulta = end($ultimasConsultas);
    if ($ultimaConsulta !== false && ($now - (int)$ultimaConsulta) < 1) {
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => 'Aguarde um instante e tente novamente.']);
        exit;
    }

    if (count($ultimasConsultas) >= 40) {
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => 'Muitas consultas recentes. Tente novamente em alguns minutos.']);
        exit;
    }

    $ultimasConsultas[] = $now;
    $_SESSION['vendas_pre_contrato_lookup'] = $ultimasConsultas;

    $registro = vendas_buscar_pre_contrato_publico_recente($cpf, $tipoEvento, null, $memoriaDias);

    if (!$registro) {
        echo json_encode([
            'success' => true,
            'found' => false,
            'memoria_dias' => $memoriaDias,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $formatarData = static function ($valor): string {
        if (!$valor) {
            return '';
        }
        try {
            return (new DateTime((string)$valor))->format('Y-m-d');
        } catch (Throwable $e) {
            return '';
        }
    };

    $formatarHora = static function ($valor): string {
        if (!$valor) {
            return '';
        }
        try {
            return (new DateTime((string)$valor))->format('H:i');
        } catch (Throwable $e) {
            return '';
        }
    };

    $dados = [
        'id' => (int)$registro['id'],
        'nome_completo' => (string)($registro['nome_completo'] ?? ''),
        'cpf' => vendas_normalizar_cpf($registro['cpf'] ?? ''),
        'rg' => (string)($registro['rg'] ?? ''),
        'telefone' => (string)($registro['telefone'] ?? ''),
        'email' => (string)($registro['email'] ?? ''),
        'cep' => (string)($registro['cep'] ?? ''),
        'endereco_completo' => (string)($registro['endereco_completo'] ?? ''),
        'numero' => (string)($registro['numero'] ?? ''),
        'complemento' => (string)($registro['complemento'] ?? ''),
        'bairro' => (string)($registro['bairro'] ?? ''),
        'cidade' => (string)($registro['cidade'] ?? ''),
        'estado' => (string)($registro['estado'] ?? ''),
        'pais' => (string)($registro['pais'] ?? ''),
        'instagram' => (string)($registro['instagram'] ?? ''),
        'data_evento' => $formatarData($registro['data_evento'] ?? ''),
        'unidade' => (string)($registro['unidade'] ?? ''),
        'horario_inicio' => $formatarHora($registro['horario_inicio'] ?? ''),
        'horario_termino' => $formatarHora($registro['horario_termino'] ?? ''),
        'nome_noivos' => (string)($registro['nome_noivos'] ?? ''),
        'num_convidados' => (string)($registro['num_convidados'] ?? ''),
        'como_conheceu' => (string)($registro['como_conheceu'] ?? ''),
        'como_conheceu_outro' => (string)($registro['como_conheceu_outro'] ?? ''),
        'pacote_contratado' => (string)($registro['pacote_contratado'] ?? ''),
        'itens_adicionais' => (string)($registro['itens_adicionais'] ?? ''),
        'observacoes' => (string)($registro['observacoes'] ?? ''),
    ];

    $criadoEm = '';
    try {
        $criadoEm = (new DateTime((string)($registro['criado_em'] ?? 'now')))->format('d/m/Y H:i');
    } catch (Throwable $e) {
        $criadoEm = '';
    }

    echo json_encode([
        'success' => true,
        'found' => true,
        'memoria_dias' => $memoriaDias,
        'registro' => $dados,
        'resumo' => [
            'id' => (int)$registro['id'],
            'criado_em' => $criadoEm,
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('[VENDAS] Erro na consulta pública de pré-contrato: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Não foi possível consultar seus dados no momento.',
    ], JSON_UNESCAPED_UNICODE);
}
