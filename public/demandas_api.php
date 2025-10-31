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
            exit;
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
            exit;
        } elseif ($action === 'reabrir' && $id) {
            // POST ?action=reabrir&id=X
            reabrirDemanda($pdo, $id);
            exit;
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
            exit;
        } else {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'ID necessário para editar']);
        }
    }
    // DELETE - Deletar demanda ou anexo
    elseif ($method === 'DELETE' || ($method === 'POST' && ($action === 'deletar' || $action === 'arquivar' || $action === 'deletar_anexo') && $id)) {
        if ($action === 'deletar' && $id) {
            excluirDemanda($pdo, $id);
            exit;
        } elseif ($action === 'arquivar' && $id) {
            arquivarDemanda($pdo, $id);
            exit;
        } elseif ($action === 'deletar_anexo' && $id) {
            deletarAnexo($pdo, $id);
            exit;
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
            'ate_data' => $_GET['ate_data'] ?? '',
            'prioridade' => $_GET['prioridade'] ?? '',
            'categoria' => $_GET['categoria'] ?? '',
            'arquivado' => $_GET['arquivado'] ?? 'false' // Por padrão não mostrar arquivadas
        ];
        
        // Ordenação (preserva compatibilidade)
        $sort_by = $_GET['sort_by'] ?? 'prazo';
        $order = strtoupper($_GET['order'] ?? 'ASC');
        $allowed_sort = ['prazo', 'data_criacao', 'prioridade', 'progresso', 'status'];
        if (!in_array($sort_by, $allowed_sort)) {
            $sort_by = 'prazo';
        }
        if (!in_array($order, ['ASC', 'DESC'])) {
            $order = 'ASC';
        }
        
        error_log("DEMANDAS_API: listarDemandas() - Filtros: " . json_encode($filtros));
        
        $where = ['d.arquivado = ?']; // Sempre filtrar arquivadas por padrão
        $params = [($filtros['arquivado'] === 'true' ? 'true' : 'false')];
        
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
        
        // Novos filtros (opcionais - só aplica se colunas existirem)
        if ($filtros['prioridade']) {
            $where[] = 'd.prioridade = ?';
            $params[] = $filtros['prioridade'];
        }
        
        if ($filtros['categoria']) {
            $where[] = 'd.categoria = ?';
            $params[] = $filtros['categoria'];
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
            ORDER BY d.{$sort_by} {$order}, d.data_criacao DESC
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
    try {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        
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
            echo json_encode(['success' => false, 'error' => 'Demanda não encontrada']);
            return;
        }
        
        // Buscar comentários (tabela pode não existir, usar try-catch)
        $comentarios = [];
        try {
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
        } catch (PDOException $e) {
            error_log("Erro ao buscar comentários: " . $e->getMessage());
            // Continuar sem comentários se tabela não existir
        }
        
        // Buscar anexos (tabela pode não existir, usar try-catch)
        $anexos = [];
        try {
            $stmt = $pdo->prepare("
                SELECT * FROM demandas_arquivos 
                WHERE demanda_id = ?
                ORDER BY criado_em ASC
            ");
            $stmt->execute([$id]);
            $anexos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Erro ao buscar anexos: " . $e->getMessage());
            // Continuar sem anexos se tabela não existir
        }
        
        $demanda['comentarios'] = $comentarios;
        $demanda['anexos'] = $anexos;
        
        echo json_encode(['success' => true, 'data' => $demanda], JSON_UNESCAPED_UNICODE);
        
    } catch (PDOException $e) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Erro ao buscar demanda: ' . $e->getMessage()
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Erro ao buscar demanda: ' . $e->getMessage()
        ]);
    }
}

function criarDemanda($pdo) {
    try {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        
        // Suporta JSON e FormData
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) {
            $data = $_POST;
        }
        
        $descricao = $data['descricao'] ?? '';
        $prazo = $data['prazo'] ?? '';
        $responsavel_id = isset($data['responsavel_id']) ? (int)$data['responsavel_id'] : 0;
        $whatsapp = $data['whatsapp'] ?? '';
        
        // Novos campos opcionais (preserva compatibilidade)
        $prioridade = $data['prioridade'] ?? 'media';
        $categoria = $data['categoria'] ?? null;
        $progresso = isset($data['progresso']) ? (int)$data['progresso'] : 0;
        $etapa = $data['etapa'] ?? 'planejamento';
        $referencia_externa = $data['referencia_externa'] ?? null;
        $tipo_referencia = $data['tipo_referencia'] ?? null;
        
        if (!$descricao || !$prazo || !$responsavel_id) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Dados obrigatórios: descricao, prazo, responsavel_id'
            ]);
            return;
        }
        
        // Validar prioridade
        if (!in_array($prioridade, ['baixa', 'media', 'alta', 'urgente'])) {
            $prioridade = 'media';
        }
        
        // Validar progresso
        if ($progresso < 0 || $progresso > 100) {
            $progresso = 0;
        }
        
        // Validar etapa
        if (!in_array($etapa, ['planejamento', 'execucao', 'revisao', 'concluida'])) {
            $etapa = 'planejamento';
        }
        
        // Construir SQL dinamicamente para incluir apenas campos que existem
        $campos = ['descricao', 'prazo', 'responsavel_id', 'criador_id'];
        $valores = [$descricao, $prazo, $responsavel_id, $_SESSION['user_id'] ?? 1];
        $placeholders = ['?', '?', '?', '?'];
        
        // Adicionar campos opcionais se fornecidos
        if ($whatsapp) {
            $campos[] = 'whatsapp';
            $valores[] = $whatsapp;
            $placeholders[] = '?';
        }
        
        // Verificar se coluna existe antes de adicionar (evita erro se migration não rodou)
        try {
            $teste = $pdo->query("SELECT prioridade FROM demandas LIMIT 1");
            $campos[] = 'prioridade';
            $valores[] = $prioridade;
            $placeholders[] = '?';
            
            if ($categoria) {
                $campos[] = 'categoria';
                $valores[] = $categoria;
                $placeholders[] = '?';
            }
            
            $campos[] = 'progresso';
            $valores[] = $progresso;
            $placeholders[] = '?';
            
            $campos[] = 'etapa';
            $valores[] = $etapa;
            $placeholders[] = '?';
            
            if ($referencia_externa) {
                $campos[] = 'referencia_externa';
                $valores[] = $referencia_externa;
                $placeholders[] = '?';
            }
            
            if ($tipo_referencia) {
                $campos[] = 'tipo_referencia';
                $valores[] = $tipo_referencia;
                $placeholders[] = '?';
            }
        } catch (PDOException $e) {
            // Colunas novas não existem ainda - usar estrutura básica
            error_log("DEMANDAS_API: Campos novos não disponíveis, usando estrutura básica");
        }
        
        $sql = "INSERT INTO demandas (" . implode(', ', $campos) . ") 
                VALUES (" . implode(', ', $placeholders) . ")
                RETURNING id";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($valores);
        
        $id = $stmt->fetchColumn();
        echo json_encode([
            'success' => true, 
            'data' => ['id' => $id],
            'message' => 'Demanda criada com sucesso'
        ]);
        
    } catch (PDOException $e) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Erro ao inserir no banco: ' . $e->getMessage()
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Erro ao criar demanda: ' . $e->getMessage()
        ]);
    }
}

function editarDemanda($pdo, $id) {
    try {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'ID da demanda não fornecido']);
            return;
        }
        
        // Suporta JSON e FormData
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) {
            $data = $_POST;
        }
        
        // Verificar permissões (criador ou admin pode editar)
        $user_id = $_SESSION['user_id'] ?? 1;
        $stmt_check = $pdo->prepare("SELECT criador_id FROM demandas WHERE id = ?");
        $stmt_check->execute([$id]);
        $demanda = $stmt_check->fetch(PDO::FETCH_ASSOC);
        
        if (!$demanda) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Demanda não encontrada']);
            return;
        }
        
        // Verificar se é criador (pode adicionar validação de admin depois)
        // if ($demanda['criador_id'] != $user_id) {
        //     http_response_code(403);
        //     echo json_encode(['success' => false, 'error' => 'Sem permissão para editar esta demanda']);
        //     return;
        // }
        
        $campos = [];
        $params = [];
        
        // Campos básicos
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
            $params[] = (int)$data['responsavel_id'];
        }
        
        if (isset($data['whatsapp'])) {
            $campos[] = 'whatsapp = ?';
            $params[] = $data['whatsapp'] ?: null;
        }
        
        if (isset($data['status'])) {
            $campos[] = 'status = ?';
            $params[] = $data['status'];
        }
        
        // Novos campos (verificar se coluna existe antes)
        try {
            $teste = $pdo->query("SELECT prioridade FROM demandas LIMIT 1");
            
            if (isset($data['prioridade'])) {
                $prioridade = $data['prioridade'];
                if (in_array($prioridade, ['baixa', 'media', 'alta', 'urgente'])) {
                    $campos[] = 'prioridade = ?';
                    $params[] = $prioridade;
                }
            }
            
            if (isset($data['categoria'])) {
                $campos[] = 'categoria = ?';
                $params[] = $data['categoria'] ?: null;
            }
            
            if (isset($data['progresso'])) {
                $progresso = (int)$data['progresso'];
                if ($progresso >= 0 && $progresso <= 100) {
                    $campos[] = 'progresso = ?';
                    $params[] = $progresso;
                }
            }
            
            if (isset($data['etapa'])) {
                $etapa = $data['etapa'];
                if (in_array($etapa, ['planejamento', 'execucao', 'revisao', 'concluida'])) {
                    $campos[] = 'etapa = ?';
                    $params[] = $etapa;
                }
            }
            
            if (isset($data['referencia_externa'])) {
                $campos[] = 'referencia_externa = ?';
                $params[] = $data['referencia_externa'] ?: null;
            }
            
            if (isset($data['tipo_referencia'])) {
                $campos[] = 'tipo_referencia = ?';
                $params[] = $data['tipo_referencia'] ?: null;
            }
        } catch (PDOException $e) {
            // Colunas novas não existem ainda - pular
            error_log("DEMANDAS_API: Campos novos não disponíveis para edição");
        }
        
        if (empty($campos)) {
            http_response_code(400);
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
        
        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Demanda não encontrada ou nenhuma alteração']);
            return;
        }
        
        echo json_encode([
            'success' => true, 
            'message' => 'Demanda atualizada com sucesso'
        ]);
        
    } catch (PDOException $e) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Erro ao atualizar demanda: ' . $e->getMessage()
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Erro ao atualizar demanda: ' . $e->getMessage()
        ]);
    }
}

function concluirDemanda($pdo, $id) {
    try {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'ID da demanda não fornecido']);
            return;
        }
        
        $stmt = $pdo->prepare("
            UPDATE demandas 
            SET status = 'concluida', data_conclusao = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$id]);
        
        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Demanda não encontrada']);
            return;
        }
        
        echo json_encode(['success' => true, 'message' => 'Demanda concluída com sucesso']);
        
    } catch (PDOException $e) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Erro ao concluir demanda: ' . $e->getMessage()
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Erro ao concluir demanda: ' . $e->getMessage()
        ]);
    }
}

function reabrirDemanda($pdo, $id) {
    try {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'ID da demanda não fornecido']);
            return;
        }
        
        $stmt = $pdo->prepare("
            UPDATE demandas 
            SET status = 'pendente', data_conclusao = NULL
            WHERE id = ?
        ");
        $stmt->execute([$id]);
        
        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Demanda não encontrada']);
            return;
        }
        
        echo json_encode(['success' => true, 'message' => 'Demanda reaberta com sucesso']);
        
    } catch (PDOException $e) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Erro ao reabrir demanda: ' . $e->getMessage()
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Erro ao reabrir demanda: ' . $e->getMessage()
        ]);
    }
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

function excluirDemanda($pdo, $id) {
    try {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'ID da demanda não fornecido']);
            return;
        }
        
        // Verificar se demanda existe
        $stmt_check = $pdo->prepare("SELECT id FROM demandas WHERE id = ? AND arquivado = false");
        $stmt_check->execute([$id]);
        if (!$stmt_check->fetch()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Demanda não encontrada']);
            return;
        }
        
        // Soft delete: marcar como arquivada (mais seguro que DELETE)
        try {
            $stmt = $pdo->prepare("
                UPDATE demandas 
                SET arquivado = true, arquivado_em = NOW(), arquivado_por = ?
                WHERE id = ?
            ");
            $stmt->execute([$_SESSION['user_id'] ?? 1, $id]);
            
            if ($stmt->rowCount() === 0) {
                // Se coluna arquivado não existe, fazer hard delete
                $stmt = $pdo->prepare("DELETE FROM demandas WHERE id = ?");
                $stmt->execute([$id]);
            }
        } catch (PDOException $e) {
            // Se coluna arquivado não existe, fazer hard delete
            $stmt = $pdo->prepare("DELETE FROM demandas WHERE id = ?");
            $stmt->execute([$id]);
        }
        
        echo json_encode([
            'success' => true, 
            'message' => 'Demanda excluída com sucesso'
        ]);
        
    } catch (PDOException $e) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Erro ao excluir demanda: ' . $e->getMessage()
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Erro ao excluir demanda: ' . $e->getMessage()
        ]);
    }
}

function arquivarDemanda($pdo, $id) {
    try {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'ID da demanda não fornecido']);
            return;
        }
        
        // Verificar se coluna existe
        try {
            $stmt = $pdo->prepare("
                UPDATE demandas 
                SET arquivado = true, arquivado_em = NOW(), arquivado_por = ?
                WHERE id = ? AND arquivado = false
            ");
            $stmt->execute([$_SESSION['user_id'] ?? 1, $id]);
            
            if ($stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Demanda não encontrada ou já arquivada']);
                return;
            }
            
            echo json_encode([
                'success' => true, 
                'message' => 'Demanda arquivada com sucesso'
            ]);
        } catch (PDOException $e) {
            // Coluna arquivado não existe
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Funcionalidade de arquivamento não disponível'
            ]);
        }
        
    } catch (PDOException $e) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Erro ao arquivar demanda: ' . $e->getMessage()
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Erro ao arquivar demanda: ' . $e->getMessage()
        ]);
    }
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
