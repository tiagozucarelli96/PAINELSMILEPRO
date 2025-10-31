<?php
/**
 * demandas_trello_api.php
 * API REST para sistema de Demandas estilo Trello
 */

ob_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verificar autenticação
// Verificar múltiplas variáveis de sessão possíveis (compatível com login.php)
$usuario_id_session = $_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? $_SESSION['id'] ?? null;
$logado = $_SESSION['logado'] ?? $_SESSION['logged_in'] ?? null;

// Aceitar se apenas 'logado' estiver definido (compatível com login.php que define $_SESSION['id'])
if (empty($usuario_id_session) && isset($_SESSION['logado']) && $_SESSION['logado'] == 1 && isset($_SESSION['id'])) {
    $usuario_id_session = $_SESSION['id'];
}

if (empty($usuario_id_session) || !$logado || (int)$logado !== 1) {
    http_response_code(401);
    header('Content-Type: application/json');
    ob_clean();
    echo json_encode([
        'success' => false, 
        'error' => 'Não autenticado'
    ]);
    exit;
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/upload_magalu.php';

$pdo = $GLOBALS['pdo'];
$usuario_id = (int)$usuario_id_session;
$is_admin = isset($_SESSION['permissao']) && strpos($_SESSION['permissao'], 'admin') !== false;

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;

ob_clean();
header('Content-Type: application/json; charset=utf-8');

// ============================================
// FUNÇÕES PRINCIPAIS
// ============================================

/**
 * Listar todos os quadros disponíveis ao usuário
 */
function listarQuadros($pdo, $usuario_id, $is_admin) {
    try {
        if ($is_admin) {
            // Admin vê todos os quadros
            $stmt = $pdo->query("
                SELECT db.*, 
                       u.nome as criador_nome,
                       COUNT(DISTINCT dl.id) as total_listas,
                       COUNT(DISTINCT dc.id) as total_cards
                FROM demandas_boards db
                LEFT JOIN usuarios u ON u.id = db.criado_por
                LEFT JOIN demandas_listas dl ON dl.board_id = db.id
                LEFT JOIN demandas_cards dc ON dc.lista_id = dl.id
                WHERE db.ativo = TRUE
                GROUP BY db.id, u.nome
                ORDER BY db.criado_em DESC
            ");
        } else {
            // Usuário vê apenas quadros criados por ele ou que tem cards atribuídos
            $stmt = $pdo->prepare("
                SELECT DISTINCT db.*, 
                       u.nome as criador_nome,
                       COUNT(DISTINCT dl.id) as total_listas,
                       COUNT(DISTINCT dc.id) as total_cards
                FROM demandas_boards db
                LEFT JOIN usuarios u ON u.id = db.criado_por
                LEFT JOIN demandas_listas dl ON dl.board_id = db.id
                LEFT JOIN demandas_cards dc ON dc.lista_id = dl.id
                LEFT JOIN demandas_cards_usuarios dcu ON dcu.card_id = dc.id
                WHERE db.ativo = TRUE
                  AND (db.criado_por = :user_id OR dcu.usuario_id = :user_id)
                GROUP BY db.id, u.nome
                ORDER BY db.criado_em DESC
            ");
            $stmt->execute([':user_id' => $usuario_id]);
        }
        
        $quadros = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'data' => $quadros
        ]);
        exit;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

/**
 * Listar listas (colunas) de um quadro
 */
function listarListas($pdo, $board_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT dl.*, 
                   COUNT(dc.id) as total_cards
            FROM demandas_listas dl
            LEFT JOIN demandas_cards dc ON dc.lista_id = dl.id
            WHERE dl.board_id = :board_id
            GROUP BY dl.id
            ORDER BY dl.posicao ASC, dl.id ASC
        ");
        $stmt->execute([':board_id' => $board_id]);
        
        $listas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'data' => $listas
        ]);
        exit;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

/**
 * Listar cards de uma lista
 */
function listarCards($pdo, $lista_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT dc.*,
                   u_criador.nome as criador_nome
            FROM demandas_cards dc
            LEFT JOIN usuarios u_criador ON u_criador.id = dc.criador_id
            WHERE dc.lista_id = :lista_id
            ORDER BY dc.posicao ASC, dc.id ASC
        ");
        $stmt->execute([':lista_id' => $lista_id]);
        
        $cards = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Buscar usuários atribuídos e contagem de comentários para cada card
        foreach ($cards as &$card) {
            // Usuários atribuídos
            $stmt_users = $pdo->prepare("
                SELECT u.id, u.nome, u.email
                FROM demandas_cards_usuarios dcu
                JOIN usuarios u ON u.id = dcu.usuario_id
                WHERE dcu.card_id = :card_id
            ");
            $stmt_users->execute([':card_id' => $card['id']]);
            $card['usuarios'] = $stmt_users->fetchAll(PDO::FETCH_ASSOC);
            
            // Contagem de comentários
            $stmt_com = $pdo->prepare("
                SELECT COUNT(*) as total 
                FROM demandas_comentarios_trello 
                WHERE card_id = :card_id
            ");
            $stmt_com->execute([':card_id' => $card['id']]);
            $card['total_comentarios'] = (int)$stmt_com->fetch(PDO::FETCH_ASSOC)['total'];
            
            // Contagem de anexos e buscar primeiro anexo de imagem para preview
            $stmt_anx = $pdo->prepare("
                SELECT COUNT(*) as total 
                FROM demandas_arquivos_trello 
                WHERE card_id = :card_id
            ");
            $stmt_anx->execute([':card_id' => $card['id']]);
            $card['total_anexos'] = (int)$stmt_anx->fetch(PDO::FETCH_ASSOC)['total'];
            
            // Buscar primeiro anexo de imagem para preview (se houver)
            $stmt_preview = $pdo->prepare("
                SELECT id, nome_original, mime_type, chave_storage
                FROM demandas_arquivos_trello 
                WHERE card_id = :card_id 
                AND mime_type LIKE 'image/%'
                ORDER BY id ASC
                LIMIT 1
            ");
            $stmt_preview->execute([':card_id' => $card['id']]);
            $preview = $stmt_preview->fetch(PDO::FETCH_ASSOC);
            if ($preview && !empty($preview['chave_storage'])) {
                // Gerar presigned URL para preview
                $previewUrl = gerarUrlPreview($preview['chave_storage']);
                if ($previewUrl) {
                    $card['preview_imagem'] = [
                        'id' => $preview['id'],
                        'nome' => $preview['nome_original'],
                        'chave_storage' => $preview['chave_storage'],
                        'url_preview' => $previewUrl
                    ];
                }
            }
        }
        
        echo json_encode([
            'success' => true,
            'data' => $cards
        ]);
        exit;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

/**
 * Criar novo card
 */
function criarCard($pdo, $usuario_id, $dados) {
    try {
        $lista_id = (int)($dados['lista_id'] ?? 0);
        $titulo = trim($dados['titulo'] ?? '');
        $descricao = $dados['descricao'] ?? null;
        $prazo = $dados['prazo'] ?? null;
        $prioridade = $dados['prioridade'] ?? 'media';
        $categoria = $dados['categoria'] ?? null;
        $usuarios = $dados['usuarios'] ?? [];
        
        if (empty($lista_id) || empty($titulo)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Lista ID e título são obrigatórios']);
            exit;
        }
        
        // Buscar posição máxima na lista
        $stmt_pos = $pdo->prepare("SELECT COALESCE(MAX(posicao), 0) + 1 as nova_pos FROM demandas_cards WHERE lista_id = :lista_id");
        $stmt_pos->execute([':lista_id' => $lista_id]);
        $posicao = (int)$stmt_pos->fetch(PDO::FETCH_ASSOC)['nova_pos'];
        
        // Criar card
        $stmt = $pdo->prepare("
            INSERT INTO demandas_cards 
            (lista_id, titulo, descricao, prazo, prioridade, categoria, criador_id, posicao)
            VALUES (:lista_id, :titulo, :descricao, :prazo, :prioridade, :categoria, :criador_id, :posicao)
            RETURNING *
        ");
        $stmt->execute([
            ':lista_id' => $lista_id,
            ':titulo' => $titulo,
            ':descricao' => $descricao ?: null,
            ':prazo' => $prazo ?: null,
            ':prioridade' => $prioridade,
            ':categoria' => $categoria ?: null,
            ':criador_id' => $usuario_id,
            ':posicao' => $posicao
        ]);
        
        $card = $stmt->fetch(PDO::FETCH_ASSOC);
        $card_id = (int)$card['id'];
        
        // Buscar board_id para notificações
        $stmt_board = $pdo->prepare("
            SELECT dl.board_id, db.nome as board_nome
            FROM demandas_listas dl
            JOIN demandas_boards db ON db.id = dl.board_id
            WHERE dl.id = :lista_id
        ");
        $stmt_board->execute([':lista_id' => $lista_id]);
        $board_info = $stmt_board->fetch(PDO::FETCH_ASSOC);
        $board_id = $board_info['board_id'] ?? null;
        $board_nome = $board_info['board_nome'] ?? 'Quadro';
        
        // Buscar todos os responsáveis de cards neste board (para notificar sobre novo card)
        $usuarios_notificar_novo_card = [];
        if ($board_id) {
            $stmt_board_users = $pdo->prepare("
                SELECT DISTINCT dcu.usuario_id
                FROM demandas_cards dc
                JOIN demandas_listas dl ON dl.id = dc.lista_id
                JOIN demandas_cards_usuarios dcu ON dcu.card_id = dc.id
                WHERE dl.board_id = :board_id 
                  AND dcu.usuario_id != :criador_id
            ");
            $stmt_board_users->execute([':board_id' => $board_id, ':criador_id' => $usuario_id]);
            while ($user = $stmt_board_users->fetch(PDO::FETCH_ASSOC)) {
                $user_id_board = (int)$user['usuario_id'];
                if ($user_id_board > 0) {
                    $usuarios_notificar_novo_card[] = $user_id_board;
                }
            }
        }
        
        // Atribuir usuários
        $usuarios_atribuidos = [];
        if (!empty($usuarios) && is_array($usuarios)) {
            $stmt_user = $pdo->prepare("
                INSERT INTO demandas_cards_usuarios (card_id, usuario_id)
                VALUES (:card_id, :usuario_id)
                ON CONFLICT (card_id, usuario_id) DO NOTHING
            ");
            
            foreach ($usuarios as $user_id) {
                $user_id = (int)$user_id;
                if ($user_id > 0) {
                    $stmt_user->execute([':card_id' => $card_id, ':usuario_id' => $user_id]);
                    $usuarios_atribuidos[] = $user_id;
                    
                    // Criar notificação de atribuição
                    criarNotificacao($pdo, $user_id, 'tarefa_atribuida', $card_id, "Você foi atribuído ao card: {$titulo}");
                }
            }
        }
        
        // Notificar outros responsáveis do board sobre novo card (exceto criador e já atribuídos)
        foreach ($usuarios_notificar_novo_card as $user_id_notif) {
            if (!in_array($user_id_notif, $usuarios_atribuidos) && $user_id_notif !== $usuario_id) {
                criarNotificacao($pdo, $user_id_notif, 'card_criado', $card_id, "Novo card criado em {$board_nome}: {$titulo}");
            }
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Card criado com sucesso',
            'data' => $card
        ]);
        exit;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

/**
 * Mover card entre listas
 */
function moverCard($pdo, $card_id, $nova_lista_id, $nova_posicao) {
    try {
        $pdo->beginTransaction();
        
        // Atualizar lista e posição do card
        $stmt = $pdo->prepare("
            UPDATE demandas_cards 
            SET lista_id = :lista_id, posicao = :posicao, atualizado_em = NOW()
            WHERE id = :card_id
        ");
        $stmt->execute([
            ':lista_id' => $nova_lista_id,
            ':posicao' => $nova_posicao,
            ':card_id' => $card_id
        ]);
        
        // Reordenar cards na lista antiga e nova (opcional, para manter ordem)
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Card movido com sucesso'
        ]);
        exit;
    } catch (PDOException $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

/**
 * Atualizar card
 */
function atualizarCard($pdo, $card_id, $dados, $usuario_id, $is_admin) {
    try {
        // Verificar permissão: apenas criador, responsável ou admin pode editar
        if (!$is_admin) {
            $stmt_check = $pdo->prepare("
                SELECT dc.criador_id, 
                       COUNT(dcu.usuario_id) as is_responsavel
                FROM demandas_cards dc
                LEFT JOIN demandas_cards_usuarios dcu ON dcu.card_id = dc.id AND dcu.usuario_id = :user_id
                WHERE dc.id = :card_id
                GROUP BY dc.criador_id
            ");
            $stmt_check->execute([':card_id' => $card_id, ':user_id' => $usuario_id]);
            $card = $stmt_check->fetch(PDO::FETCH_ASSOC);
            
            if (!$card || ((int)$card['criador_id'] !== $usuario_id && (int)$card['is_responsavel'] === 0)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Você não tem permissão para editar este card']);
                exit;
            }
        }
        
        $campos = [];
        $valores = [':card_id' => $card_id];
        
        if (isset($dados['titulo'])) {
            $campos[] = 'titulo = :titulo';
            $valores[':titulo'] = $dados['titulo'];
        }
        if (isset($dados['descricao'])) {
            $campos[] = 'descricao = :descricao';
            $valores[':descricao'] = $dados['descricao'] ?: null;
        }
        if (isset($dados['prazo'])) {
            $campos[] = 'prazo = :prazo';
            $valores[':prazo'] = $dados['prazo'] ?: null;
        }
        if (isset($dados['prioridade'])) {
            $campos[] = 'prioridade = :prioridade';
            $valores[':prioridade'] = $dados['prioridade'];
        }
        if (isset($dados['categoria'])) {
            $campos[] = 'categoria = :categoria';
            $valores[':categoria'] = $dados['categoria'] ?: null;
        }
        if (isset($dados['status'])) {
            $campos[] = 'status = :status';
            $valores[':status'] = $dados['status'];
        }
        
        if (empty($campos)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Nenhum campo para atualizar']);
            exit;
        }
        
        $campos[] = 'atualizado_em = NOW()';
        
        $stmt = $pdo->prepare("
            UPDATE demandas_cards 
            SET " . implode(', ', $campos) . "
            WHERE id = :card_id
            RETURNING *
        ");
        $stmt->execute($valores);
        
        $card = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Buscar informações do card para notificações
        $stmt_card_info = $pdo->prepare("
            SELECT dc.titulo, dc.criador_id, dc.lista_id, dl.board_id
            FROM demandas_cards dc
            JOIN demandas_listas dl ON dl.id = dc.lista_id
            WHERE dc.id = :card_id
        ");
        $stmt_card_info->execute([':card_id' => $card_id]);
        $card_info = $stmt_card_info->fetch(PDO::FETCH_ASSOC);
        $titulo_card = $card_info['titulo'] ?? 'Card';
        
        // Atualizar usuários se fornecido
        if (isset($dados['usuarios']) && is_array($dados['usuarios'])) {
            // Remover todos
            $pdo->prepare("DELETE FROM demandas_cards_usuarios WHERE card_id = :card_id")->execute([':card_id' => $card_id]);
            
            // Adicionar novos
            $stmt_user = $pdo->prepare("
                INSERT INTO demandas_cards_usuarios (card_id, usuario_id)
                VALUES (:card_id, :usuario_id)
            ");
            foreach ($dados['usuarios'] as $user_id) {
                $user_id = (int)$user_id;
                if ($user_id > 0) {
                    $stmt_user->execute([':card_id' => $card_id, ':usuario_id' => $user_id]);
                }
            }
        }
        
        // Criar notificações para responsáveis do card (exceto quem fez a alteração)
        $usuarios_notificar = [];
        
        // Incluir criador do card (se não for quem está editando)
        if ($card_info['criador_id'] && (int)$card_info['criador_id'] !== $usuario_id) {
            $usuarios_notificar[] = (int)$card_info['criador_id'];
        }
        
        // Incluir todos os responsáveis atribuídos ao card
        $stmt_responsaveis = $pdo->prepare("
            SELECT DISTINCT usuario_id 
            FROM demandas_cards_usuarios 
            WHERE card_id = :card_id AND usuario_id != :user_id
        ");
        $stmt_responsaveis->execute([':card_id' => $card_id, ':user_id' => $usuario_id]);
        while ($resp = $stmt_responsaveis->fetch(PDO::FETCH_ASSOC)) {
            $user_id_resp = (int)$resp['usuario_id'];
            if ($user_id_resp > 0 && !in_array($user_id_resp, $usuarios_notificar)) {
                $usuarios_notificar[] = $user_id_resp;
            }
        }
        
        // Criar notificações
        foreach ($usuarios_notificar as $user_id_notif) {
            // Montar mensagem descritiva do que foi alterado
            $campos_alterados = [];
            if (isset($dados['titulo'])) $campos_alterados[] = 'título';
            if (isset($dados['descricao'])) $campos_alterados[] = 'descrição';
            if (isset($dados['prazo'])) $campos_alterados[] = 'prazo';
            if (isset($dados['prioridade'])) $campos_alterados[] = 'prioridade';
            if (isset($dados['status'])) $campos_alterados[] = 'status';
            if (isset($dados['categoria'])) $campos_alterados[] = 'categoria';
            if (isset($dados['usuarios'])) $campos_alterados[] = 'responsáveis';
            
            $msg = count($campos_alterados) > 0 
                ? "Card '{$titulo_card}' foi atualizado: " . implode(', ', $campos_alterados)
                : "Card '{$titulo_card}' foi atualizado";
            
            criarNotificacao($pdo, $user_id_notif, 'card_atualizado', $card_id, $msg);
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Card atualizado com sucesso',
            'data' => $card
        ]);
        exit;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

/**
 * Concluir card
 */
function concluirCard($pdo, $card_id) {
    try {
        $stmt = $pdo->prepare("
            UPDATE demandas_cards 
            SET status = 'concluido', atualizado_em = NOW()
            WHERE id = :card_id
        ");
        $stmt->execute([':card_id' => $card_id]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Card concluído com sucesso'
        ]);
        exit;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

/**
 * Reabrir card
 */
function reabrirCard($pdo, $card_id) {
    try {
        $stmt = $pdo->prepare("
            UPDATE demandas_cards 
            SET status = 'pendente', atualizado_em = NOW()
            WHERE id = :card_id
        ");
        $stmt->execute([':card_id' => $card_id]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Card reaberto com sucesso'
        ]);
        exit;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

/**
 * Adicionar comentário (com suporte a @menções)
 */
function adicionarComentario($pdo, $usuario_id, $card_id, $mensagem) {
    try {
        // Extrair menções (@usuario)
        $mencoes = [];
        preg_match_all('/@(\w+)/', $mensagem, $matches);
        
        if (!empty($matches[1])) {
            // Buscar IDs dos usuários mencionados
            $placeholders = implode(',', array_fill(0, count($matches[1]), '?'));
            $stmt_users = $pdo->prepare("SELECT id, nome FROM usuarios WHERE nome IN ($placeholders) OR email IN ($placeholders)");
            $stmt_users->execute(array_merge($matches[1], $matches[1]));
            
            while ($user = $stmt_users->fetch(PDO::FETCH_ASSOC)) {
                $mencoes[] = $user['id'];
                criarNotificacao($pdo, $user['id'], 'mencao', $card_id, "Você foi mencionado em um comentário");
            }
        }
        
        // Inserir comentário
        $stmt = $pdo->prepare("
            INSERT INTO demandas_comentarios_trello (card_id, autor_id, mensagem)
            VALUES (:card_id, :autor_id, :mensagem)
            RETURNING *
        ");
        $stmt->execute([
            ':card_id' => $card_id,
            ':autor_id' => $usuario_id,
            ':mensagem' => $mensagem
        ]);
        
        $comentario = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Buscar nome do autor
        $stmt_user = $pdo->prepare("SELECT nome FROM usuarios WHERE id = :id");
        $stmt_user->execute([':id' => $usuario_id]);
        $comentario['autor_nome'] = $stmt_user->fetch(PDO::FETCH_ASSOC)['nome'] ?? 'Desconhecido';
        
        echo json_encode([
            'success' => true,
            'message' => 'Comentário adicionado',
            'data' => $comentario
        ]);
        exit;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

/**
 * Adicionar anexo
 */
function adicionarAnexo($pdo, $usuario_id, $card_id, $arquivo) {
    try {
        require_once __DIR__ . '/upload_magalu.php';
        
        $uploader = new MagaluUpload();
        $upload_result = $uploader->upload($arquivo, 'demandas_trello');
        
        // Validar que o upload foi bem-sucedido antes de salvar no banco
        if (empty($upload_result['chave_storage'])) {
            throw new Exception('Upload falhou: chave de armazenamento não foi retornada');
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO demandas_arquivos_trello 
            (card_id, nome_original, mime_type, tamanho_bytes, chave_storage, upload_por)
            VALUES (:card_id, :nome, :mime, :tamanho, :chave, :upload_por)
            RETURNING *
        ");
        $stmt->execute([
            ':card_id' => $card_id,
            ':nome' => $upload_result['nome_original'],
            ':mime' => $upload_result['mime_type'],
            ':tamanho' => $upload_result['tamanho_bytes'],
            ':chave' => $upload_result['chave_storage'],
            ':upload_por' => $usuario_id
        ]);
        
        $anexo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Log de sucesso para debug
        error_log("Anexo criado: ID={$anexo['id']}, chave={$upload_result['chave_storage']}, url={$upload_result['url']}");
        
        echo json_encode([
            'success' => true,
            'message' => 'Anexo adicionado com sucesso',
            'data' => $anexo,
            'url' => $upload_result['url'] ?? null
        ]);
        exit;
    } catch (Exception $e) {
        error_log("Erro ao adicionar anexo: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

/**
 * Listar notificações
 */
function listarNotificacoes($pdo, $usuario_id) {
    try {
        // Verificar se coluna referencia_id existe, senão usar alternativa
        $stmt_check = $pdo->query("
            SELECT column_name 
            FROM information_schema.columns 
            WHERE table_name = 'demandas_notificacoes' 
            AND column_name = 'referencia_id'
            LIMIT 1
        ");
        $has_referencia_id = $stmt_check->rowCount() > 0;
        
        if ($has_referencia_id) {
            $stmt = $pdo->prepare("
                SELECT dn.*, 
                       dc.titulo as card_titulo,
                       dc.lista_id
                FROM demandas_notificacoes dn
                LEFT JOIN demandas_cards dc ON dc.id = dn.referencia_id
                WHERE dn.usuario_id = :user_id
                ORDER BY dn.criada_em DESC
                LIMIT 50
            ");
        } else {
            // Tabela antiga sem referencia_id - verificar qual coluna de data existe
            $stmt_check_col = $pdo->query("
                SELECT column_name 
                FROM information_schema.columns 
                WHERE table_name = 'demandas_notificacoes' 
                AND column_name IN ('criada_em', 'criado_em', 'created_at')
                LIMIT 1
            ");
            $date_column = $stmt_check_col->fetchColumn() ?: 'id'; // Fallback para id se nenhuma coluna de data existir
            
            $stmt = $pdo->prepare("
                SELECT dn.*,
                       NULL as card_titulo,
                       NULL as lista_id
                FROM demandas_notificacoes dn
                WHERE dn.usuario_id = :user_id
                ORDER BY dn.{$date_column} DESC
                LIMIT 50
            ");
        }
        $stmt->execute([':user_id' => $usuario_id]);
        
        $notificacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Contar não lidas
        $stmt_count = $pdo->prepare("SELECT COUNT(*) as total FROM demandas_notificacoes WHERE usuario_id = :user_id AND lida = FALSE");
        $stmt_count->execute([':user_id' => $usuario_id]);
        $nao_lidas = (int)$stmt_count->fetch(PDO::FETCH_ASSOC)['total'];
        
        echo json_encode([
            'success' => true,
            'data' => $notificacoes,
            'nao_lidas' => $nao_lidas
        ]);
        exit;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

/**
 * Marcar notificação como lida
 */
function marcarNotificacaoComoLida($pdo, $notificacao_id) {
    try {
        $stmt = $pdo->prepare("UPDATE demandas_notificacoes SET lida = TRUE WHERE id = :id");
        $stmt->execute([':id' => $notificacao_id]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Notificação marcada como lida'
        ]);
        exit;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

/**
 * Criar novo quadro
 */
function criarQuadro($pdo, $usuario_id, $dados) {
    try {
        $nome = trim($dados['nome'] ?? '');
        $descricao = $dados['descricao'] ?? null;
        $cor = $dados['cor'] ?? '#3b82f6';
        
        if (empty($nome)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Nome do quadro é obrigatório']);
            exit;
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO demandas_boards (nome, descricao, criado_por, cor)
            VALUES (:nome, :descricao, :criado_por, :cor)
            RETURNING *
        ");
        $stmt->execute([
            ':nome' => $nome,
            ':descricao' => $descricao ?: null,
            ':criado_por' => $usuario_id,
            ':cor' => $cor
        ]);
        
        $quadro = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$quadro) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Erro ao criar quadro no banco de dados']);
            exit;
        }
        
        // Criar listas padrão
        $listas_padrao = [
            ['nome' => '📋 Para Fazer', 'posicao' => 0],
            ['nome' => '🔄 Em Andamento', 'posicao' => 1],
            ['nome' => '✅ Feito', 'posicao' => 2]
        ];
        
        foreach ($listas_padrao as $lista) {
            try {
                $stmt_lista = $pdo->prepare("
                    INSERT INTO demandas_listas (board_id, nome, posicao)
                    VALUES (:board_id, :nome, :posicao)
                ");
                $stmt_lista->execute([
                    ':board_id' => (int)$quadro['id'],
                    ':nome' => $lista['nome'],
                    ':posicao' => $lista['posicao']
                ]);
            } catch (PDOException $e) {
                error_log("Erro ao criar lista padrão: " . $e->getMessage());
                // Continuar mesmo se falhar criar uma lista
            }
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Quadro criado com sucesso',
            'data' => $quadro
        ]);
        exit;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

/**
 * Criar nova lista
 */
function criarLista($pdo, $board_id, $dados) {
    try {
        $nome = trim($dados['nome'] ?? '');
        
        if (empty($nome)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Nome da lista é obrigatório']);
            exit;
        }
        
        // Buscar posição máxima
        $stmt_pos = $pdo->prepare("SELECT COALESCE(MAX(posicao), 0) + 1 as nova_pos FROM demandas_listas WHERE board_id = :board_id");
        $stmt_pos->execute([':board_id' => $board_id]);
        $posicao = (int)$stmt_pos->fetch(PDO::FETCH_ASSOC)['nova_pos'];
        
        $stmt = $pdo->prepare("
            INSERT INTO demandas_listas (board_id, nome, posicao)
            VALUES (:board_id, :nome, :posicao)
            RETURNING *
        ");
        $stmt->execute([
            ':board_id' => $board_id,
            ':nome' => $nome,
            ':posicao' => $posicao
        ]);
        
        $lista = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'message' => 'Lista criada com sucesso',
            'data' => $lista
        ]);
        exit;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

/**
 * Deletar card
 */
function deletarCard($pdo, $card_id, $usuario_id, $is_admin) {
    try {
        // Verificar permissão: apenas criador ou admin pode deletar
        if (!$is_admin) {
            $stmt_check = $pdo->prepare("SELECT criador_id FROM demandas_cards WHERE id = :id");
            $stmt_check->execute([':id' => $card_id]);
            $card = $stmt_check->fetch(PDO::FETCH_ASSOC);
            
            if (!$card || (int)$card['criador_id'] !== $usuario_id) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Você não tem permissão para deletar este card']);
                exit;
            }
        }
        
        // ANTES de deletar o card, deletar todos os anexos do Magalu Cloud
        require_once __DIR__ . '/upload_magalu.php';
        $uploader = new MagaluUpload();
        
        $stmt_anexos = $pdo->prepare("
            SELECT id, chave_storage 
            FROM demandas_arquivos_trello 
            WHERE card_id = :card_id
        ");
        $stmt_anexos->execute([':card_id' => $card_id]);
        $anexos = $stmt_anexos->fetchAll(PDO::FETCH_ASSOC);
        
        $arquivosDeletados = 0;
        $errosDeletacao = [];
        
        foreach ($anexos as $anexo) {
            if (!empty($anexo['chave_storage'])) {
                try {
                    if ($uploader->delete($anexo['chave_storage'])) {
                        $arquivosDeletados++;
                        error_log("Arquivo deletado do Magalu: {$anexo['chave_storage']}");
                    } else {
                        $errosDeletacao[] = $anexo['chave_storage'];
                        error_log("Erro ao deletar arquivo do Magalu: {$anexo['chave_storage']}");
                    }
                } catch (Exception $e) {
                    $errosDeletacao[] = $anexo['chave_storage'];
                    error_log("Exceção ao deletar arquivo: " . $e->getMessage());
                }
            }
        }
        
        // Deletar o card (cascata vai deletar anexos do banco)
        $stmt = $pdo->prepare("DELETE FROM demandas_cards WHERE id = :id");
        $stmt->execute([':id' => $card_id]);
        
        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Card não encontrado']);
            exit;
        }
        
        $message = 'Card deletado com sucesso';
        if ($arquivosDeletados > 0) {
            $message .= " ({$arquivosDeletados} arquivo(s) removido(s) do Magalu Cloud)";
        }
        if (!empty($errosDeletacao)) {
            $message .= ". Atenção: " . count($errosDeletacao) . " arquivo(s) não puderam ser removidos do storage.";
        }
        
        echo json_encode([
            'success' => true,
            'message' => $message
        ]);
        exit;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

/**
 * Deletar quadro
 */
function deletarQuadro($pdo, $board_id, $usuario_id, $is_admin) {
    try {
        // Verificar permissão: apenas criador ou admin pode deletar
        if (!$is_admin) {
            $stmt_check = $pdo->prepare("SELECT criado_por FROM demandas_boards WHERE id = :id");
            $stmt_check->execute([':id' => $board_id]);
            $board = $stmt_check->fetch(PDO::FETCH_ASSOC);
            
            if (!$board || (int)$board['criado_por'] !== $usuario_id) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Você não tem permissão para deletar este quadro']);
                exit;
            }
        }
        
        $stmt = $pdo->prepare("UPDATE demandas_boards SET ativo = FALSE WHERE id = :id");
        $stmt->execute([':id' => $board_id]);
        
        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Quadro não encontrado']);
            exit;
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Quadro deletado com sucesso'
        ]);
        exit;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

/**
 * Deletar lista
 */
function deletarLista($pdo, $lista_id, $usuario_id, $is_admin) {
    try {
        // Verificar permissão: apenas criador do quadro ou admin pode deletar
        if (!$is_admin) {
            $stmt_check = $pdo->prepare("
                SELECT db.criado_por 
                FROM demandas_listas dl
                JOIN demandas_boards db ON db.id = dl.board_id
                WHERE dl.id = :id
            ");
            $stmt_check->execute([':id' => $lista_id]);
            $lista = $stmt_check->fetch(PDO::FETCH_ASSOC);
            
            if (!$lista || (int)$lista['criado_por'] !== $usuario_id) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Você não tem permissão para deletar esta lista']);
                exit;
            }
        }
        
        $stmt = $pdo->prepare("DELETE FROM demandas_listas WHERE id = :id");
        $stmt->execute([':id' => $lista_id]);
        
        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Lista não encontrada']);
            exit;
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Lista deletada com sucesso'
        ]);
        exit;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

/**
 * Atualizar quadro
 */
function atualizarQuadro($pdo, $board_id, $dados) {
    try {
        $campos = [];
        $valores = [':id' => $board_id];
        
        if (isset($dados['nome'])) {
            $campos[] = 'nome = :nome';
            $valores[':nome'] = trim($dados['nome']);
        }
        if (isset($dados['descricao'])) {
            $campos[] = 'descricao = :descricao';
            $valores[':descricao'] = $dados['descricao'] ?: null;
        }
        if (isset($dados['cor'])) {
            $campos[] = 'cor = :cor';
            $valores[':cor'] = $dados['cor'];
        }
        
        if (empty($campos)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Nenhum campo para atualizar']);
            exit;
        }
        
        $stmt = $pdo->prepare("
            UPDATE demandas_boards 
            SET " . implode(', ', $campos) . "
            WHERE id = :id
            RETURNING *
        ");
        $stmt->execute($valores);
        
        $quadro = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'message' => 'Quadro atualizado com sucesso',
            'data' => $quadro
        ]);
        exit;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

/**
 * Atualizar lista
 */
function atualizarLista($pdo, $lista_id, $dados) {
    try {
        $campos = [];
        $valores = [':id' => $lista_id];
        
        if (isset($dados['nome'])) {
            $campos[] = 'nome = :nome';
            $valores[':nome'] = trim($dados['nome']);
        }
        if (isset($dados['posicao'])) {
            $campos[] = 'posicao = :posicao';
            $valores[':posicao'] = (int)$dados['posicao'];
        }
        
        if (empty($campos)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Nenhum campo para atualizar']);
            exit;
        }
        
        $stmt = $pdo->prepare("
            UPDATE demandas_listas 
            SET " . implode(', ', $campos) . "
            WHERE id = :id
            RETURNING *
        ");
        $stmt->execute($valores);
        
        $lista = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'message' => 'Lista atualizada com sucesso',
            'data' => $lista
        ]);
        exit;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

/**
 * Download de anexo
 */
function downloadAnexo($pdo, $anexo_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT da.*, dc.id as card_id
            FROM demandas_arquivos_trello da
            JOIN demandas_cards dc ON dc.id = da.card_id
            WHERE da.id = :id
        ");
        $stmt->execute([':id' => $anexo_id]);
        $anexo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$anexo) {
            http_response_code(404);
            header('Content-Type: application/json');
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'Anexo não encontrado']);
            exit;
        }
        
        // Se tiver chave de storage, baixar do Magalu Cloud usando AWS SDK
        if (!empty($anexo['chave_storage'])) {
            require_once __DIR__ . '/upload_magalu.php';
            
            // Tentar usar AWS SDK primeiro
            if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
                require_once __DIR__ . '/../vendor/autoload.php';
                
                if (class_exists('Aws\S3\S3Client')) {
                    $bucket = $_ENV['MAGALU_BUCKET'] ?? getenv('MAGALU_BUCKET') ?: 'smilepainel';
                    $region = $_ENV['MAGALU_REGION'] ?? getenv('MAGALU_REGION') ?: 'br-se1';
                    $endpoint = $_ENV['MAGALU_ENDPOINT'] ?? getenv('MAGALU_ENDPOINT') ?: 'https://br-se1.magaluobjects.com';
                    $accessKey = $_ENV['MAGALU_ACCESS_KEY'] ?? getenv('MAGALU_ACCESS_KEY');
                    $secretKey = $_ENV['MAGALU_SECRET_KEY'] ?? getenv('MAGALU_SECRET_KEY');
                    $bucket = strtolower($bucket);
                    
                    // Criar cliente S3
                    $s3Client = new \Aws\S3\S3Client([
                        'region' => $region,
                        'version' => 'latest',
                        'credentials' => [
                            'key' => $accessKey,
                            'secret' => $secretKey,
                        ],
                        'endpoint' => $endpoint,
                        'use_path_style_endpoint' => true,
                    ]);
                    
                    try {
                        // Gerar URL pré-assinada (válida por 1 hora)
                        $cmd = $s3Client->getCommand('GetObject', [
                            'Bucket' => $bucket,
                            'Key' => $anexo['chave_storage'],
                        ]);
                        
                        $presignedUrl = $s3Client->createPresignedRequest($cmd, '+1 hour')->getUri();
                        
                        // Redirecionar para URL pré-assinada
                        header("Location: " . (string)$presignedUrl);
                        exit;
                        
                    } catch (\Aws\Exception\AwsException $e) {
                        error_log("Erro AWS SDK no presigned URL: " . $e->getMessage());
                        // Fallback: tentar baixar diretamente e servir
                        try {
                            $result = $s3Client->getObject([
                                'Bucket' => $bucket,
                                'Key' => $anexo['chave_storage'],
                            ]);
                            
                            // Servir o arquivo
                            header('Content-Type: ' . ($anexo['mime_type'] ?? 'application/octet-stream'));
                            header('Content-Disposition: attachment; filename="' . addslashes($anexo['nome_original']) . '"');
                            header('Content-Length: ' . ($anexo['tamanho_bytes'] ?? $result['ContentLength'] ?? 0));
                            ob_clean();
                            echo $result['Body'];
                            exit;
                            
                        } catch (\Exception $e2) {
                            error_log("Erro ao baixar arquivo: " . $e2->getMessage());
                            http_response_code(500);
                            header('Content-Type: application/json');
                            ob_clean();
                            echo json_encode(['success' => false, 'error' => 'Erro ao baixar arquivo: ' . $e2->getMessage()]);
                            exit;
                        }
                    }
                }
            }
            
            // Fallback: construir URL direta (pode não funcionar se arquivo não for público)
            $bucket = $_ENV['MAGALU_BUCKET'] ?? getenv('MAGALU_BUCKET') ?: 'smilepainel';
            $endpoint = $_ENV['MAGALU_ENDPOINT'] ?? getenv('MAGALU_ENDPOINT') ?: 'https://br-se1.magaluobjects.com';
            $bucket = strtolower($bucket);
            $url = "{$endpoint}/{$bucket}/{$anexo['chave_storage']}";
            
            // Redirecionar para URL do arquivo (pode falhar se não for público)
            header("Location: {$url}");
            exit;
        }
        
        http_response_code(404);
        header('Content-Type: application/json');
        ob_clean();
        echo json_encode(['success' => false, 'error' => 'Arquivo não disponível']);
        exit;
    } catch (PDOException $e) {
        http_response_code(500);
        header('Content-Type: application/json');
        ob_clean();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

/**
 * Deletar anexo
 */
function deletarAnexo($pdo, $anexo_id) {
    try {
        // Buscar info do anexo antes de deletar (para limpar do Magalu)
        $stmt_info = $pdo->prepare("SELECT chave_storage FROM demandas_arquivos_trello WHERE id = :id");
        $stmt_info->execute([':id' => $anexo_id]);
        $anexo = $stmt_info->fetch(PDO::FETCH_ASSOC);
        
        if (!$anexo) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Anexo não encontrado']);
            exit;
        }
        
        // Deletar arquivo físico do Magalu Cloud (se existir)
        if (!empty($anexo['chave_storage'])) {
            try {
                $uploader = new MagaluUpload();
                $uploader->delete($anexo['chave_storage']);
            } catch (Exception $e) {
                // Log do erro mas não impede a deleção do registro
                error_log("Erro ao deletar arquivo do Magalu Cloud: " . $e->getMessage());
            }
        }
        
        // Deletar registro do banco
        $stmt = $pdo->prepare("DELETE FROM demandas_arquivos_trello WHERE id = :id");
        $stmt->execute([':id' => $anexo_id]);
        
        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Anexo não encontrado no banco']);
            exit;
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Anexo deletado com sucesso (arquivo e registro)'
        ]);
        exit;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

/**
 * Helper: Criar notificação
 */
/**
 * Gerar URL de preview de imagem usando presigned URL
 */
function gerarUrlPreview($chave_storage) {
    if (empty($chave_storage)) {
        return null;
    }
    
    // Tentar usar AWS SDK para gerar presigned URL
    if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
        require_once __DIR__ . '/../vendor/autoload.php';
        
        if (class_exists('Aws\S3\S3Client')) {
            try {
                $bucket = $_ENV['MAGALU_BUCKET'] ?? getenv('MAGALU_BUCKET') ?: 'smilepainel';
                $region = $_ENV['MAGALU_REGION'] ?? getenv('MAGALU_REGION') ?: 'br-se1';
                $endpoint = $_ENV['MAGALU_ENDPOINT'] ?? getenv('MAGALU_ENDPOINT') ?: 'https://br-se1.magaluobjects.com';
                $accessKey = $_ENV['MAGALU_ACCESS_KEY'] ?? getenv('MAGALU_ACCESS_KEY');
                $secretKey = $_ENV['MAGALU_SECRET_KEY'] ?? getenv('MAGALU_SECRET_KEY');
                $bucket = strtolower($bucket);
                
                if (!$accessKey || !$secretKey) {
                    return null;
                }
                
                $s3Client = new \Aws\S3\S3Client([
                    'region' => $region,
                    'version' => 'latest',
                    'credentials' => [
                        'key' => $accessKey,
                        'secret' => $secretKey,
                    ],
                    'endpoint' => $endpoint,
                    'use_path_style_endpoint' => true,
                ]);
                
                // Gerar presigned URL válida por 1 hora
                $cmd = $s3Client->getCommand('GetObject', [
                    'Bucket' => $bucket,
                    'Key' => $chave_storage,
                ]);
                
                $presignedUrl = $s3Client->createPresignedRequest($cmd, '+1 hour')->getUri();
                return (string)$presignedUrl;
                
            } catch (\Exception $e) {
                error_log("Erro ao gerar URL de preview: " . $e->getMessage());
                return null;
            }
        }
    }
    
    // Fallback: URL direta (pode não funcionar se arquivo não for público)
    $bucket = $_ENV['MAGALU_BUCKET'] ?? getenv('MAGALU_BUCKET') ?: 'smilepainel';
    $endpoint = $_ENV['MAGALU_ENDPOINT'] ?? getenv('MAGALU_ENDPOINT') ?: 'https://br-se1.magaluobjects.com';
    $bucket = strtolower($bucket);
    return "{$endpoint}/{$bucket}/{$chave_storage}";
}

function criarNotificacao($pdo, $usuario_id, $tipo, $referencia_id, $mensagem) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO demandas_notificacoes (usuario_id, tipo, referencia_id, mensagem)
            VALUES (:user_id, :tipo, :ref_id, :msg)
        ");
        $stmt->execute([
            ':user_id' => $usuario_id,
            ':tipo' => $tipo,
            ':ref_id' => $referencia_id,
            ':msg' => $mensagem
        ]);
    } catch (PDOException $e) {
        // Ignorar erros de notificação
        error_log("Erro ao criar notificação: " . $e->getMessage());
    }
}

// ============================================
// ROTEAMENTO
// ============================================

try {
    if ($method === 'GET') {
        if ($action === 'quadros') {
            listarQuadros($pdo, $usuario_id, $is_admin);
        } elseif ($action === 'listas' && $id) {
            listarListas($pdo, $id);
        } elseif ($action === 'cards' && $id) {
            listarCards($pdo, $id);
        } elseif ($action === 'notificacoes') {
            listarNotificacoes($pdo, $usuario_id);
        } elseif ($action === 'anexo' && $id) {
            // GET: Download de anexo
            downloadAnexo($pdo, $id);
        } elseif ($action === 'card' && $id) {
            // Detalhes de um card específico
            $stmt = $pdo->prepare("
                SELECT dc.*, 
                       u_criador.nome as criador_nome,
                       dl.nome as lista_nome,
                       db.nome as board_nome
                FROM demandas_cards dc
                LEFT JOIN usuarios u_criador ON u_criador.id = dc.criador_id
                LEFT JOIN demandas_listas dl ON dl.id = dc.lista_id
                LEFT JOIN demandas_boards db ON db.id = dl.board_id
                WHERE dc.id = :id
            ");
            $stmt->execute([':id' => $id]);
            $card = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$card) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Card não encontrado']);
                exit;
            }
            
            // Usuários atribuídos
            $stmt_users = $pdo->prepare("
                SELECT u.id, u.nome, u.email
                FROM demandas_cards_usuarios dcu
                JOIN usuarios u ON u.id = dcu.usuario_id
                WHERE dcu.card_id = :card_id
            ");
            $stmt_users->execute([':card_id' => $id]);
            $card['usuarios'] = $stmt_users->fetchAll(PDO::FETCH_ASSOC);
            
            // Comentários
            $stmt_com = $pdo->prepare("
                SELECT dc.*, u.nome as autor_nome
                FROM demandas_comentarios_trello dc
                LEFT JOIN usuarios u ON u.id = dc.autor_id
                WHERE dc.card_id = :card_id
                ORDER BY dc.criado_em ASC
            ");
            $stmt_com->execute([':card_id' => $id]);
            $card['comentarios'] = $stmt_com->fetchAll(PDO::FETCH_ASSOC);
            
            // Anexos
            $stmt_anx = $pdo->prepare("
                SELECT da.*, u.nome as upload_nome
                FROM demandas_arquivos_trello da
                LEFT JOIN usuarios u ON u.id = da.upload_por
                WHERE da.card_id = :card_id
                ORDER BY da.criado_em DESC
            ");
            $stmt_anx->execute([':card_id' => $id]);
            $card['anexos'] = $stmt_anx->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'data' => $card]);
            exit;
        }
    } elseif ($method === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        
        if ($action === 'criar_quadro') {
            criarQuadro($pdo, $usuario_id, $data);
        } elseif ($action === 'criar_lista' && $id) {
            criarLista($pdo, $id, $data);
        } elseif ($action === 'criar_card') {
            criarCard($pdo, $usuario_id, $data);
        } elseif ($action === 'mover_card' && $id) {
            moverCard($pdo, $id, $data['nova_lista_id'] ?? null, $data['nova_posicao'] ?? 0);
        } elseif ($action === 'concluir' && $id) {
            concluirCard($pdo, $id);
        } elseif ($action === 'reabrir' && $id) {
            reabrirCard($pdo, $id);
        } elseif ($action === 'comentario' && $id) {
            adicionarComentario($pdo, $usuario_id, $id, $data['mensagem'] ?? '');
        } elseif ($action === 'anexo' && $id) {
            if (isset($_FILES['arquivo'])) {
                adicionarAnexo($pdo, $usuario_id, $id, $_FILES['arquivo']);
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Arquivo não fornecido']);
                exit;
            }
        } elseif ($action === 'marcar_notificacao' && $id) {
            marcarNotificacaoComoLida($pdo, $id);
        }
    } elseif ($method === 'PATCH') {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if ($action === 'atualizar_card' && $id) {
            atualizarCard($pdo, $id, $data, $usuario_id, $is_admin);
        } elseif ($action === 'atualizar_quadro' && $id) {
            atualizarQuadro($pdo, $id, $data);
        } elseif ($action === 'atualizar_lista' && $id) {
            atualizarLista($pdo, $id, $data);
        }
    } elseif ($method === 'DELETE') {
        if ($action === 'deletar_card' && $id) {
            deletarCard($pdo, $id, $usuario_id, $is_admin);
        } elseif ($action === 'deletar_quadro' && $id) {
            deletarQuadro($pdo, $id, $usuario_id, $is_admin);
        } elseif ($action === 'deletar_lista' && $id) {
            deletarLista($pdo, $id, $usuario_id, $is_admin);
        } elseif ($action === 'deletar_anexo' && $id) {
            deletarAnexo($pdo, $id);
        }
    }
    
    // Rota não encontrada
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Rota não encontrada']);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

