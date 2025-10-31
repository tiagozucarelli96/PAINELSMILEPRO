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
    
    // Debug logs (remover depois)
    error_log("DEMANDAS_API: Method = $method");
    error_log("DEMANDAS_API: GET = " . json_encode($_GET));
    error_log("DEMANDAS_API: PATH_INFO = " . ($_SERVER['PATH_INFO'] ?? 'não definido'));
    
    // NOVA ABORDAGEM: Usar query parameters em vez de PATH_INFO
    $action = $_GET['action'] ?? '';
    $id = $_GET['id'] ?? null;
    
    // GET - Listar ou obter detalhes
    if ($method === 'GET') {
        error_log("DEMANDAS_API: Processando GET - action=$action, id=$id");
        if (empty($action) && empty($id)) {
            // GET sem parâmetros = listar todas
            error_log("DEMANDAS_API: Chamando listarDemandas()");
            listarDemandas($pdo);
            exit; // IMPORTANTE: sair após processar
        } elseif ($action === 'detalhes' && $id) {
            // GET ?action=detalhes&id=X
            obterDemanda($pdo, $id);
        } elseif ($action === 'anexo' && $id) {
            // GET ?action=anexo&id=X
            downloadAnexo($pdo, $id);
            exit;
        } else {
            // GET com filtros = listar com filtros
            error_log("DEMANDAS_API: Chamando listarDemandas() com filtros");
            listarDemandas($pdo);
            exit; // IMPORTANTE: sair após processar
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
        error_log("DEMANDAS_API: Método não suportado: $method");
        // FALLBACK: Se chegou aqui e é GET sem action, listar
        if ($method === 'GET' && empty($action)) {
            error_log("DEMANDAS_API: FALLBACK - Listando demandas");
            listarDemandas($pdo);
            exit;
        }
        
        http_response_code(405);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false, 
            'error' => 'Método não permitido',
            'debug' => [
                'method' => $method,
                'action' => $action,
                'id' => $id,
                'get' => $_GET,
                'path_info' => $_SERVER['PATH_INFO'] ?? 'não definido',
                'request_uri' => $_SERVER['REQUEST_URI'] ?? 'não definido'
            ]
        ]);
        exit;
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

function listarDemandas($pdo) {
    try {
        ob_clean(); // Limpar qualquer output anterior
        header('Content-Type: application/json; charset=utf-8');
        
        $filtros = [
            'status' => $_GET['status'] ?? '',
            'responsavel' => $_GET['responsavel'] ?? '',
            'texto' => $_GET['texto'] ?? '',
            'ate_data' => $_GET['ate_data'] ?? ''
        ];
        
        error_log("DEMANDAS_API: listarDemandas() - Filtros: " . json_encode($filtros));
        
        $where = ['1=1'];
        $params = [];
        
        if ($filtros['status']) {
            $where[] = 'd.status = ?';
            $params[] = $filtros['status'];
        }
        
        if ($filtros['responsavel']) {
            $where[] = 'd.responsavel_id = ?';
            $params[] = $filtros['responsavel'];
        }
        
        if ($filtros['texto']) {
            $where[] = 'd.descricao ILIKE ?';
            $params[] = '%' . $filtros['texto'] . '%';
        }
        
        if ($filtros['ate_data']) {
            $where[] = 'd.prazo <= ?';
            $params[] = $filtros['ate_data'];
        }
        
        $sql = "
            SELECT 
                d.*,
                r.nome as responsavel_nome,
                c.nome as criador_nome,
                CASE 
                    WHEN d.status = 'concluida' THEN 'concluida'
                    WHEN d.prazo < CURRENT_DATE THEN 'vencida'
                    ELSE 'pendente'
                END as status_real
            FROM demandas d
            LEFT JOIN usuarios r ON d.responsavel_id = r.id
            LEFT JOIN usuarios c ON d.criador_id = c.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY d.prazo ASC, d.data_criacao DESC
        ";
        
        error_log("DEMANDAS_API: Executando SQL: " . substr($sql, 0, 200) . "...");
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $demandas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("DEMANDAS_API: Demandas encontradas: " . count($demandas));
        
        $response = [
            'success' => true, 
            'data' => $demandas,
            'count' => count($demandas)
        ];
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        error_log("DEMANDAS_API: Resposta enviada com sucesso");
        
    } catch (PDOException $e) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Erro ao buscar demandas: ' . $e->getMessage()
        ]);
    }
}

function obterDemanda($pdo, $id) {
    $stmt = $pdo->prepare("
        SELECT 
            d.*,
            r.nome as responsavel_nome,
            c.nome as criador_nome
        FROM demandas d
        LEFT JOIN usuarios r ON d.responsavel_id = r.id
        LEFT JOIN usuarios c ON d.criador_id = c.id
        WHERE d.id = ?
    ");
    $stmt->execute([$id]);
    $demanda = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$demanda) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Demanda não encontrada']);
        return;
    }
    
    $stmt = $pdo->prepare("
        SELECT 
            dc.*,
            u.nome as autor_nome
        FROM demandas_comentarios dc
        LEFT JOIN usuarios u ON dc.autor_id = u.id
        WHERE dc.demanda_id = ?
        ORDER BY dc.data_criacao ASC
    ");
    $stmt->execute([$id]);
    $comentarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->prepare("
        SELECT * FROM demandas_arquivos 
        WHERE demanda_id = ?
        ORDER BY criado_em ASC
    ");
    $stmt->execute([$id]);
    $anexos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $demanda['comentarios'] = $comentarios;
    $demanda['anexos'] = $anexos;
    
    echo json_encode(['success' => true, 'data' => $demanda]);
}

function criarDemanda($pdo) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        $data = $_POST;
    }
    
    $descricao = $data['descricao'] ?? '';
    $prazo = $data['prazo'] ?? '';
    $responsavel_id = isset($data['responsavel_id']) ? (int)$data['responsavel_id'] : 0;
    $whatsapp = $data['whatsapp'] ?? '';
    
    if (!$descricao || !$prazo || !$responsavel_id) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Dados obrigatórios: descricao, prazo, responsavel_id'
        ]);
        exit;
    }
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO demandas (descricao, prazo, responsavel_id, criador_id, whatsapp) 
            VALUES (?, ?, ?, ?, ?)
            RETURNING id
        ");
        $stmt->execute([
            $descricao,
            $prazo,
            $responsavel_id,
            $_SESSION['user_id'] ?? 1,
            $whatsapp ?: null
        ]);
        
        $id = $stmt->fetchColumn();
        echo json_encode(['success' => true, 'data' => ['id' => $id]]);
        
    } catch (PDOException $e) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Erro ao inserir no banco: ' . $e->getMessage()
        ]);
    }
}

function editarDemanda($pdo, $id) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $campos = [];
    $params = [];
    
    if (isset($data['descricao'])) {
        $campos[] = 'descricao = ?';
        $params[] = $data['descricao'];
    }
    
    if (isset($data['prazo'])) {
        $campos[] = 'prazo = ?';
        $params[] = $data['prazo'];
    }
    
    if (isset($data['responsavel_id'])) {
        $campos[] = 'responsavel_id = ?';
        $params[] = $data['responsavel_id'];
    }
    
    if (isset($data['whatsapp'])) {
        $campos[] = 'whatsapp = ?';
        $params[] = $data['whatsapp'];
    }
    
    if (empty($campos)) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Nenhum campo para atualizar']);
        return;
    }
    
    $params[] = $id;
    
    $stmt = $pdo->prepare("
        UPDATE demandas 
        SET " . implode(', ', $campos) . "
        WHERE id = ?
    ");
    $stmt->execute($params);
    
    echo json_encode(['success' => true]);
}

function concluirDemanda($pdo, $id) {
    $stmt = $pdo->prepare("
        UPDATE demandas 
        SET status = 'concluida', data_conclusao = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$id]);
    
    echo json_encode(['success' => true]);
}

function reabrirDemanda($pdo, $id) {
    $stmt = $pdo->prepare("
        UPDATE demandas 
        SET status = 'pendente', data_conclusao = NULL
        WHERE id = ?
    ");
    $stmt->execute([$id]);
    
    echo json_encode(['success' => true]);
}

function adicionarComentario($pdo, $id) {
    $data = json_decode(file_get_contents('php://input'), true);
    $mensagem = $data['mensagem'] ?? '';
    
    if (!$mensagem) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Mensagem obrigatória']);
        return;
    }
    
    $stmt = $pdo->prepare("
        INSERT INTO demandas_comentarios (demanda_id, autor_id, mensagem) 
        VALUES (?, ?, ?)
        RETURNING id
    ");
    $stmt->execute([$id, $_SESSION['user_id'] ?? 1, $mensagem]);
    
    $comentarioId = $stmt->fetchColumn();
    echo json_encode(['success' => true, 'data' => ['id' => $comentarioId]]);
}

function adicionarAnexo($pdo, $id) {
    if (!isset($_FILES['file'])) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Nenhum arquivo enviado']);
        return;
    }
    
    try {
        $uploader = new MagaluUpload();
        $result = $uploader->upload($_FILES['file']);
        
        $stmt = $pdo->prepare("
            INSERT INTO demandas_arquivos (demanda_id, nome_original, mime_type, tamanho_bytes, chave_storage) 
            VALUES (?, ?, ?, ?, ?)
            RETURNING id
        ");
        $stmt->execute([
            $id,
            $result['nome_original'],
            $result['mime_type'],
            $result['tamanho_bytes'],
            $result['chave_storage']
        ]);
        
        $arquivoId = $stmt->fetchColumn();
        echo json_encode(['success' => true, 'data' => ['id' => $arquivoId]]);
        
    } catch (Exception $e) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function downloadAnexo($pdo, $arquivoId) {
    $stmt = $pdo->prepare("
        SELECT * FROM demandas_arquivos 
        WHERE id = ?
    ");
    $stmt->execute([$arquivoId]);
    $arquivo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$arquivo) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Arquivo não encontrado']);
        return;
    }
    
    header('Content-Type: application/json');
    echo json_encode(['url' => 'https://magalu-cloud-url/' . $arquivo['chave_storage']]);
}

function deletarAnexo($pdo, $arquivoId) {
    $stmt = $pdo->prepare("
        SELECT chave_storage FROM demandas_arquivos 
        WHERE id = ?
    ");
    $stmt->execute([$arquivoId]);
    $arquivo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$arquivo) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Arquivo não encontrado']);
        return;
    }
    
    $stmt = $pdo->prepare("DELETE FROM demandas_arquivos WHERE id = ?");
    $stmt->execute([$arquivoId]);
    
    $uploader = new MagaluUpload();
    $uploader->delete($arquivo['chave_storage']);
    
    echo json_encode(['success' => true]);
}
