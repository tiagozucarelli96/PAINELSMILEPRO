<?php
// demandas_api.php - Controller REST para demandas

// Iniciar output buffering antes de qualquer output
ob_start();

// Desabilitar output de erros para não quebrar JSON
ini_set('display_errors', 0);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Verificar se usuário está logado
if (!isset($_SESSION['logado']) || $_SESSION['logado'] != 1) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Não autorizado']);
    exit;
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/upload_magalu.php';

// Limpar qualquer output anterior e garantir que apenas JSON é retornado
ob_clean();
header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = $GLOBALS['pdo'];
    $method = $_SERVER['REQUEST_METHOD'];
    
    // NOVA ABORDAGEM: Usar query parameters em vez de PATH_INFO
    $action = $_GET['action'] ?? '';
    $id = $_GET['id'] ?? null;
    
    // GET - Listar ou obter detalhes
    if ($method === 'GET') {
        if (empty($action) && empty($id)) {
            // GET sem parâmetros = listar todas
            listarDemandas($pdo);
        } elseif ($action === 'detalhes' && $id) {
            // GET ?action=detalhes&id=X
            obterDemanda($pdo, $id);
        } elseif ($action === 'anexo' && $id) {
            // GET ?action=anexo&id=X
            downloadAnexo($pdo, $id);
        } else {
            // GET com filtros = listar com filtros
            listarDemandas($pdo);
        }
    }
    // POST - Criar ou ações
    elseif ($method === 'POST') {
        if (empty($action) && empty($id)) {
            // POST sem parâmetros = criar demanda
            criarDemanda($pdo);
        } elseif ($action === 'concluir' && $id) {
            // POST ?action=concluir&id=X
            concluirDemanda($pdo, $id);
        } elseif ($action === 'reabrir' && $id) {
            // POST ?action=reabrir&id=X
            reabrirDemanda($pdo, $id);
        } elseif ($action === 'comentario' && $id) {
            // POST ?action=comentario&id=X
            adicionarComentario($pdo, $id);
        } elseif ($action === 'anexo' && $id) {
            // POST ?action=anexo&id=X
            adicionarAnexo($pdo, $id);
        } else {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Parâmetros inválidos']);
        }
    }
    // PATCH - Editar
    elseif ($method === 'PATCH' || ($method === 'POST' && $action === 'editar' && $id)) {
        if ($id) {
            editarDemanda($pdo, $id);
        } else {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'ID necessário para editar']);
        }
    }
    // DELETE - Deletar anexo
    elseif ($method === 'DELETE' || ($method === 'POST' && $action === 'deletar_anexo' && $id)) {
        if ($action === 'deletar_anexo' && $id) {
            deletarAnexo($pdo, $id);
        } else {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Parâmetros inválidos']);
        }
    }
    else {
        http_response_code(405);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Método não permitido']);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}

// ... (resto das funções permanecem iguais)
