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
    
    // Detectar rota baseada no método e URI
    // Se for GET sem path específico, listar demandas
    if ($method === 'GET') {
        // Verificar se há ID na URL via PATH_INFO
        $pathInfo = $_SERVER['PATH_INFO'] ?? '';
        
        if (empty($pathInfo)) {
            // GET sem path = listar todas
            listarDemandas($pdo);
            exit;
        }
        
        $pathParts = array_filter(explode('/', trim($pathInfo, '/')));
        $pathParts = array_values($pathParts);
        
        if (count($pathParts) === 1 && is_numeric($pathParts[0])) {
            // GET /{id} - Detalhes
            obterDemanda($pdo, $pathParts[0]);
            exit;
        } elseif (count($pathParts) === 2 && $pathParts[0] === 'anexos' && is_numeric($pathParts[1])) {
            // GET /anexos/{arquivo_id} - Download
            downloadAnexo($pdo, $pathParts[1]);
            exit;
        } else {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Rota não encontrada']);
            exit;
        }
    }
    
    // Para outros métodos, usar roteamento normal
    $path = $_SERVER['PATH_INFO'] ?? '';
    $pathParts = array_filter(explode('/', trim($path, '/')));
    $pathParts = array_values($pathParts);
    
    switch ($method) {
        case 'GET':
            // Já processado acima
            break;
            
        case 'POST':
            if (empty($pathParts) || count($pathParts) === 0) {
                // POST /demandas - Criar
                criarDemanda($pdo);
            } elseif (count($pathParts) === 2 && is_numeric($pathParts[0])) {
                if ($pathParts[1] === 'concluir') {
                    concluirDemanda($pdo, $pathParts[0]);
                } elseif ($pathParts[1] === 'reabrir') {
                    reabrirDemanda($pdo, $pathParts[0]);
                } elseif ($pathParts[1] === 'comentarios') {
                    adicionarComentario($pdo, $pathParts[0]);
                } elseif ($pathParts[1] === 'anexos') {
                    adicionarAnexo($pdo, $pathParts[0]);
                } else {
                    http_response_code(404);
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'error' => 'Rota não encontrada']);
                }
            } else {
                http_response_code(400);
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Rota inválida']);
            }
            break;
            
        case 'PATCH':
            if (is_numeric($pathParts[0])) {
                // PATCH /demandas/{id} - Editar
                editarDemanda($pdo, $pathParts[0]);
            }
            break;
            
        case 'DELETE':
            if ($pathParts[0] === 'anexos' && is_numeric($pathParts[1])) {
                // DELETE /demandas/anexos/{arquivo_id}
                deletarAnexo($pdo, $pathParts[1]);
            }
            break;
            
        default:
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

function listarDemandas($pdo) {
    try {
        $filtros = [
            'status' => $_GET['status'] ?? '',
            'responsavel' => $_GET['responsavel'] ?? '',
            'texto' => $_GET['texto'] ?? '',
            'ate_data' => $_GET['ate_data'] ?? ''
        ];
        
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
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $demandas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true, 
            'data' => $demandas,
            'count' => count($demandas)
        ]);
        
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
    // Buscar demanda
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
        echo json_encode(['error' => 'Demanda não encontrada']);
        return;
    }
    
    // Buscar comentários
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
    
    // Buscar anexos
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
        // Tentar pegar de $_POST se JSON não funcionar
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
            'error' => 'Dados obrigatórios: descricao, prazo, responsavel_id',
            'debug' => [
                'descricao' => $descricao ? 'preenchido' : 'vazio',
                'prazo' => $prazo ? 'preenchido' : 'vazio',
                'responsavel_id' => $responsavel_id,
                'recebido' => $data
            ]
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
        echo json_encode(['error' => 'Nenhum campo para atualizar']);
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
        echo json_encode(['error' => 'Mensagem obrigatória']);
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
        echo json_encode(['error' => 'Nenhum arquivo enviado']);
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
        echo json_encode(['error' => $e->getMessage()]);
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
        echo json_encode(['error' => 'Arquivo não encontrado']);
        return;
    }
    
    // TODO: Implementar download real do Magalu Cloud
    header('Content-Type: ' . $arquivo['mime_type']);
    header('Content-Disposition: attachment; filename="' . $arquivo['nome_original'] . '"');
    
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
        echo json_encode(['error' => 'Arquivo não encontrado']);
        return;
    }
    
    // Deletar do banco
    $stmt = $pdo->prepare("DELETE FROM demandas_arquivos WHERE id = ?");
    $stmt->execute([$arquivoId]);
    
    // TODO: Deletar do Magalu Cloud
    $uploader = new MagaluUpload();
    $uploader->delete($arquivo['chave_storage']);
    
    echo json_encode(['success' => true]);
}
