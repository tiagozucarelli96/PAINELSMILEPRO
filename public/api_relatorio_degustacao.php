<?php
/**
 * API Endpoint para buscar dados do relatório de degustação
 * Aceita degustacao_id via GET ou POST
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/lc_permissions_enhanced.php';
require_once __DIR__ . '/core/helpers.php';

// Verificar permissões
if (!lc_can_access_comercial()) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Permissão negada']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$pdo = $GLOBALS['pdo'];
$degustacao_id = 0;

// Obter degustacao_id de GET ou POST
if (isset($_GET['degustacao_id']) && !empty($_GET['degustacao_id'])) {
    $degustacao_id = (int)$_GET['degustacao_id'];
} elseif (isset($_POST['degustacao_id']) && !empty($_POST['degustacao_id'])) {
    $degustacao_id = (int)$_POST['degustacao_id'];
}

if ($degustacao_id <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'degustacao_id é obrigatório'
    ]);
    exit;
}

try {
    // Buscar degustação
    $stmt = $pdo->prepare("SELECT * FROM comercial_degustacoes WHERE id = :id");
    $stmt->execute([':id' => $degustacao_id]);
    $degustacao = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$degustacao) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Degustação não encontrada'
        ]);
        exit;
    }
    
    // Verificar qual coluna usar para inscrições
    $check_col = $pdo->query("
        SELECT column_name 
        FROM information_schema.columns 
        WHERE table_name = 'comercial_inscricoes' 
        AND column_name IN ('degustacao_id', 'event_id')
        LIMIT 1
    ");
    $coluna_id = $check_col->fetchColumn() ?: 'degustacao_id';
    
    // Buscar inscritos confirmados
    $stmt = $pdo->prepare("
        SELECT id, nome, email, qtd_pessoas, tipo_festa
        FROM comercial_inscricoes
        WHERE {$coluna_id} = :degustacao_id 
        AND status = 'confirmado'
        ORDER BY nome ASC
    ");
    $stmt->execute([':degustacao_id' => $degustacao_id]);
    $inscritos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'degustacao' => $degustacao,
        'inscritos' => $inscritos,
        'total_inscritos' => count($inscritos),
        'total_pessoas' => array_sum(array_column($inscritos, 'qtd_pessoas'))
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erro ao buscar dados: ' . $e->getMessage()
    ]);
}

