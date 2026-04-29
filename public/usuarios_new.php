<?php
/**
 * Sistema de Usuários - Versão Nova e Limpa
 * Refatorado completamente do zero
 */

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/helpers.php';

// Garantir que $pdo está disponível
if (!isset($pdo)) {
    global $pdo;
}

function ensureRhCargosTable(PDO $pdo): void {
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS rh_cargos (
            id SERIAL PRIMARY KEY,
            nome VARCHAR(100) NOT NULL,
            descricao TEXT,
            salario_base DECIMAL(10,2) DEFAULT 0,
            ativo BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT NOW(),
            updated_at TIMESTAMP DEFAULT NOW()
        )
    ");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_rh_cargos_nome_unique ON rh_cargos (LOWER(nome))");

    $initialized = true;
}

function fetchRhCargos(PDO $pdo): array {
    ensureRhCargosTable($pdo);
    $stmt = $pdo->query("SELECT id, nome, descricao FROM rh_cargos WHERE ativo = TRUE ORDER BY nome ASC");
    return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

function ensureUsuarioSpacesTable(PDO $pdo): void {
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS usuarios_spaces_visiveis (
            usuario_id INTEGER NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
            space_visivel VARCHAR(120) NOT NULL,
            created_at TIMESTAMP DEFAULT NOW(),
            PRIMARY KEY (usuario_id, space_visivel)
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_usuarios_spaces_visiveis_space ON usuarios_spaces_visiveis (space_visivel)");

    $initialized = true;
}

function fetchSpacesVisiveis(PDO $pdo): array {
    $stmt = $pdo->query("
        SELECT DISTINCT TRIM(space_visivel) AS nome
        FROM logistica_me_locais
        WHERE TRIM(COALESCE(space_visivel, '')) <> ''
        ORDER BY TRIM(space_visivel) ASC
    ");
    return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

function fetchUsuarioSpaces(PDO $pdo, int $usuarioId): array {
    ensureUsuarioSpacesTable($pdo);
    $stmt = $pdo->prepare("SELECT space_visivel FROM usuarios_spaces_visiveis WHERE usuario_id = :usuario_id ORDER BY space_visivel");
    $stmt->execute([':usuario_id' => $usuarioId]);
    return array_values(array_filter(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [])));
}

function saveUsuarioSpaces(PDO $pdo, int $usuarioId, array $spaces): void {
    ensureUsuarioSpacesTable($pdo);

    $normalizadas = [];
    foreach ($spaces as $space) {
        $nome = trim((string)$space);
        if ($nome !== '') {
            $normalizadas[$nome] = $nome;
        }
    }

    $pdo->prepare("DELETE FROM usuarios_spaces_visiveis WHERE usuario_id = :usuario_id")
        ->execute([':usuario_id' => $usuarioId]);

    if (empty($normalizadas)) {
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO usuarios_spaces_visiveis (usuario_id, space_visivel)
        VALUES (:usuario_id, :space_visivel)
        ON CONFLICT (usuario_id, space_visivel) DO NOTHING
    ");

    foreach ($normalizadas as $space) {
        $stmt->execute([
            ':usuario_id' => $usuarioId,
            ':space_visivel' => $space,
        ]);
    }
}

// ============================================
// PROCESSAMENTO DE AÇÕES (ANTES DE QUALQUER OUTPUT)
// ============================================

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$user_id = (int)($_POST['user_id'] ?? $_GET['id'] ?? 0);

// AJAX: Retornar dados do usuário
if ($action === 'get_user' && $user_id > 0) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    
    if (empty($_SESSION['logado']) || empty($_SESSION['perm_configuracoes'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Sessão expirada. Recarregue a página.']);
        exit;
    }
    
    try {
        // Buscar todas as colunas dinamicamente
        $sql = "SELECT * FROM usuarios WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            // Converter booleanos para true/false
            foreach ($user as $key => $value) {
                if (strpos($key, 'perm_') === 0) {
                    $user[$key] = (bool)($value ?? false);
                }
            }

            $user['spaces_visiveis'] = fetchUsuarioSpaces($pdo, $user_id);
            
            
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => true, 'user' => $user], JSON_UNESCAPED_UNICODE);
        } else {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Usuário não encontrado']);
        }
    } catch (Exception $e) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Erro: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'get_cargos') {
    while (ob_get_level() > 0) { ob_end_clean(); }

    if (empty($_SESSION['logado']) || empty($_SESSION['perm_configuracoes'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Sessão expirada. Recarregue a página.']);
        exit;
    }

    try {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true, 'cargos' => fetchRhCargos($pdo)], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Erro: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'save_cargo') {
    while (ob_get_level() > 0) { ob_end_clean(); }

    if (empty($_SESSION['logado']) || empty($_SESSION['perm_configuracoes'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Sessão expirada. Recarregue a página.']);
        exit;
    }

    try {
        ensureRhCargosTable($pdo);

        $cargoId = (int)($_POST['cargo_id'] ?? 0);
        $nome = trim((string)($_POST['nome'] ?? ''));
        $descricao = trim((string)($_POST['descricao'] ?? ''));

        if ($nome === '') {
            throw new Exception('Nome do cargo é obrigatório');
        }

        $stmtCheck = $pdo->prepare("
            SELECT id
            FROM rh_cargos
            WHERE LOWER(nome) = LOWER(:nome) AND id <> :id
            LIMIT 1
        ");
        $stmtCheck->execute([
            ':nome' => $nome,
            ':id' => $cargoId,
        ]);

        if ($stmtCheck->fetchColumn()) {
            throw new Exception('Já existe um cargo com este nome');
        }

        if ($cargoId > 0) {
            $stmt = $pdo->prepare("
                UPDATE rh_cargos
                SET nome = :nome,
                    descricao = :descricao,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':id' => $cargoId,
                ':nome' => $nome,
                ':descricao' => $descricao !== '' ? $descricao : null,
            ]);
            $message = 'Cargo atualizado com sucesso!';
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO rh_cargos (nome, descricao, ativo)
                VALUES (:nome, :descricao, TRUE)
            ");
            $stmt->execute([
                ':nome' => $nome,
                ':descricao' => $descricao !== '' ? $descricao : null,
            ]);
            $message = 'Cargo criado com sucesso!';
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'message' => $message,
            'cargos' => fetchRhCargos($pdo),
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Erro: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'delete_cargo') {
    while (ob_get_level() > 0) { ob_end_clean(); }

    if (empty($_SESSION['logado']) || empty($_SESSION['perm_configuracoes'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Sessão expirada. Recarregue a página.']);
        exit;
    }

    try {
        ensureRhCargosTable($pdo);

        $cargoId = (int)($_POST['cargo_id'] ?? 0);
        if ($cargoId <= 0) {
            throw new Exception('Cargo inválido');
        }

        $stmtNome = $pdo->prepare("SELECT nome FROM rh_cargos WHERE id = :id LIMIT 1");
        $stmtNome->execute([':id' => $cargoId]);
        $cargoNome = (string)($stmtNome->fetchColumn() ?: '');

        if ($cargoNome === '') {
            throw new Exception('Cargo não encontrado');
        }

        $stmtUso = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE cargo = :cargo");
        $stmtUso->execute([':cargo' => $cargoNome]);
        if ((int)$stmtUso->fetchColumn() > 0) {
            throw new Exception('Não é possível excluir este cargo porque ele está vinculado a usuários');
        }

        $stmtDelete = $pdo->prepare("DELETE FROM rh_cargos WHERE id = :id");
        $stmtDelete->execute([':id' => $cargoId]);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'message' => 'Cargo excluído com sucesso!',
            'cargos' => fetchRhCargos($pdo),
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Erro: ' . $e->getMessage()]);
    }
    exit;
}

// Salvar usuário
if ($action === 'save') {
    // Limpar qualquer output buffer
    while (ob_get_level() > 0) { 
        ob_end_clean(); 
    }
    
    // Verificar sessão
    if (empty($_SESSION['logado']) || empty($_SESSION['perm_configuracoes'])) {
        header('Location: index.php?page=usuarios&error=' . urlencode('Sessão expirada ou sem permissão'));
        exit;
    }
    
    try {
        require_once __DIR__ . '/usuarios_save_robust.php';
        $manager = new UsuarioSaveManager($pdo);
        
        $data = $_POST;
        
        // Validar campos obrigatórios
        if (empty($data['nome'])) {
            throw new Exception('Nome é obrigatório');
        }
        if (empty($data['email'])) {
            throw new Exception('Email é obrigatório');
        }
        
        // Se login vazio, usar email (garantir que sempre tenha valor)
        if (empty($data['login']) && !empty($data['email'])) {
            $data['login'] = $data['email'];
        }
        
        // Garantir que login não está vazio após trim
        if (isset($data['login'])) {
            $data['login'] = trim($data['login']);
            if (empty($data['login']) && !empty($data['email'])) {
                $data['login'] = trim($data['email']);
            }
        }
        
        // Validar senha para novos usuários
        if ($user_id === 0 && empty($data['senha'])) {
            throw new Exception('Senha é obrigatória para novos usuários');
        }
        
        $result = $manager->save($data, $user_id);

        $savedUserId = (int)($result['id'] ?? $user_id);
        if ($savedUserId > 0) {
            saveUsuarioSpaces($pdo, $savedUserId, $_POST['spaces_visiveis'] ?? []);
        }
        
        if (!empty($result['success'])) {
            header('Location: index.php?page=usuarios&success=' . urlencode($result['message'] ?? 'Usuário salvo com sucesso!'));
            exit;
        }

        throw new Exception($result['message'] ?? 'Erro ao salvar usuário');
    } catch (Exception $e) {
        error_log("Erro ao salvar usuário: " . $e->getMessage());
        header('Location: index.php?page=usuarios&error=' . urlencode('Erro: ' . $e->getMessage()));
    }
    exit;
}

// Excluir usuário
if ($action === 'delete' && $user_id > 0) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    
    try {
        if ($user_id == ($_SESSION['usuario_id'] ?? 0)) {
            header('Location: index.php?page=usuarios&error=' . urlencode('Você não pode excluir seu próprio usuário!'));
            exit;
        }
        
        $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id = :id");
        $stmt->execute([':id' => $user_id]);
        
        header('Location: index.php?page=usuarios&success=' . urlencode('Usuário excluído com sucesso!'));
    } catch (Exception $e) {
        header('Location: index.php?page=usuarios&error=' . urlencode('Erro: ' . $e->getMessage()));
    }
    exit;
}

// ============================================
// VERIFICAÇÃO DE PERMISSÕES
// ============================================

if (empty($_SESSION['logado']) || empty($_SESSION['perm_configuracoes'])) {
    includeSidebar('Configurações');
    echo '<div style="padding: 2rem; text-align: center;">
            <h2 style="color: #dc2626;">Acesso Negado</h2>
            <p>Você não tem permissão para acessar esta página.</p>
            <a href="index.php?page=dashboard" style="color: #1e3a8a;">Voltar ao Dashboard</a>
          </div>';
    endSidebar();
    exit;
}

try {
    $cargos = fetchRhCargos($pdo);
} catch (Exception $e) {
    error_log("Erro ao carregar cargos: " . $e->getMessage());
    $cargos = [];
}

try {
    $unidadesDisponiveis = fetchSpacesVisiveis($pdo);
} catch (Exception $e) {
    error_log("Erro ao carregar unidades: " . $e->getMessage());
    $unidadesDisponiveis = [];
}

// ============================================
// BUSCAR USUÁRIOS
// ============================================

$search = trim($_GET['search'] ?? '');
$sql = "SELECT id, nome, login, email, cargo, ativo, foto, created_at";
$params = [];

// Buscar todas as colunas de permissões que existem no banco
$existing_perms = [];

// Garantir que $pdo está disponível
if (!isset($pdo) || !$pdo) {
    error_log("ERRO CRÍTICO: \$pdo não está disponível!");
    try {
        require_once __DIR__ . '/conexao.php';
    } catch (Exception $e) {
        error_log("Erro ao carregar conexao.php: " . $e->getMessage());
    }
}

try {
    // Primeiro tentar com schema atual
    error_log("Buscando permissões - Estratégia 1: Com schema atual");
    $stmt = $pdo->query("SELECT column_name FROM information_schema.columns 
                         WHERE table_schema = current_schema() AND table_name = 'usuarios' 
                         AND column_name LIKE 'perm_%' 
                         ORDER BY column_name");
    $perms_array = $stmt->fetchAll(PDO::FETCH_COLUMN);
    error_log("Estratégia 1 retornou: " . count($perms_array) . " permissões");
    
    // Se não encontrar, tentar sem especificar schema
    if (empty($perms_array)) {
        error_log("Tentando buscar permissões sem especificar schema...");
        $stmt = $pdo->query("SELECT column_name FROM information_schema.columns 
                             WHERE table_name = 'usuarios' 
                             AND column_name LIKE 'perm_%' 
                             ORDER BY column_name");
        $perms_array = $stmt->fetchAll(PDO::FETCH_COLUMN);
        error_log("Estratégia 2 retornou: " . count($perms_array) . " permissões");
    }
    
    // Se ainda não encontrar, tentar buscar diretamente da tabela
    if (empty($perms_array)) {
        error_log("Tentando buscar colunas diretamente da tabela usuarios...");
        $stmt = $pdo->query("SELECT column_name FROM information_schema.columns 
                             WHERE table_name = 'usuarios' 
                             ORDER BY column_name");
        $all_cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
        error_log("Total de colunas encontradas: " . count($all_cols));
        $perms_array = array_filter($all_cols, function($col) {
            return strpos($col, 'perm_') === 0;
        });
        $perms_array = array_values($perms_array); // Reindexar array
        error_log("Estratégia 3 retornou: " . count($perms_array) . " permissões");
    }
    
    if (!empty($perms_array)) {
        $existing_perms = array_flip($perms_array);
        error_log("SUCCESS: Permissões encontradas: " . count($existing_perms) . " - Primeiras 3: " . implode(', ', array_slice($perms_array, 0, 3)));
        error_log("DEBUG: existing_perms é array? " . (is_array($existing_perms) ? 'SIM' : 'NÃO'));
        error_log("DEBUG: existing_perms está vazio? " . (empty($existing_perms) ? 'SIM' : 'NÃO'));
        error_log("DEBUG: count de existing_perms: " . count($existing_perms));
        
        // Adicionar colunas de permissões ao SELECT
        foreach ($perms_array as $perm) {
            $sql .= ", $perm";
        }
    } else {
        error_log("AVISO: Nenhuma permissão encontrada no banco de dados após todas as tentativas");
        error_log("DEBUG: perms_array está vazio, count: " . count($perms_array ?? []));
    }
} catch (Exception $e) {
    error_log("ERRO ao verificar permissões: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    $existing_perms = [];
} catch (Error $e) {
    error_log("ERRO FATAL ao verificar permissões: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    $existing_perms = [];
}

$sql .= " FROM usuarios WHERE 1=1";

if ($search) {
    $sql .= " AND (nome ILIKE :search OR login ILIKE :search OR email ILIKE :search)";
    $params[':search'] = "%$search%";
}

$sql .= " ORDER BY nome ASC";

try {
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Garantir superadmin para usuário admin (se existir e coluna disponível)
try {
    if (!empty($_SESSION['id'])) {
        $stmt_admin = $pdo->prepare("SELECT login FROM usuarios WHERE id = :id");
        $stmt_admin->execute([':id' => (int)$_SESSION['id']]);
        $login_admin = $stmt_admin->fetchColumn();

        if ($login_admin === 'admin') {
            $pdo->exec("ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS perm_superadmin BOOLEAN DEFAULT FALSE");
            $stmt_set = $pdo->prepare("UPDATE usuarios SET perm_superadmin = TRUE WHERE id = :id");
            $stmt_set->execute([':id' => (int)$_SESSION['id']]);
            $_SESSION['perm_superadmin'] = true;
        }
    }
} catch (Exception $e) {
    error_log("Erro ao garantir superadmin: " . $e->getMessage());
}
} catch (Exception $e) {
    $usuarios = [];
    $error_msg = "Erro ao buscar usuários: " . $e->getMessage();
}

// ============================================
// INICIAR OUTPUT
// ============================================

// Garantir que $existing_perms está definido e disponível
if (!isset($existing_perms) || !is_array($existing_perms) || empty($existing_perms)) {
    error_log("AVISO: existing_perms não está definido ou está vazio antes de ob_start(), recriando...");
    try {
        // Tentar múltiplas estratégias
        $perms_array = [];
        
        // Estratégia 1: Com schema 'public'
        $stmt = $pdo->query("SELECT column_name FROM information_schema.columns 
                             WHERE table_schema = 'public' AND table_name = 'usuarios' 
                             AND column_name LIKE 'perm_%' 
                             ORDER BY column_name");
        $perms_array = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Estratégia 2: Sem especificar schema
        if (empty($perms_array)) {
            $stmt = $pdo->query("SELECT column_name FROM information_schema.columns 
                                 WHERE table_name = 'usuarios' 
                                 AND column_name LIKE 'perm_%' 
                                 ORDER BY column_name");
            $perms_array = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
        
        // Estratégia 3: Buscar todas as colunas e filtrar
        if (empty($perms_array)) {
            $stmt = $pdo->query("SELECT column_name FROM information_schema.columns 
                                 WHERE table_name = 'usuarios' 
                                 ORDER BY column_name");
            $all_cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $perms_array = array_values(array_filter($all_cols, function($col) {
                return strpos($col, 'perm_') === 0;
            }));
        }
        
        if (!empty($perms_array)) {
            $existing_perms = array_flip($perms_array);
            error_log("Permissões recriadas: " . count($existing_perms) . " - Primeiras: " . implode(', ', array_slice($perms_array, 0, 3)));
        } else {
            $existing_perms = [];
            error_log("AVISO: Nenhuma permissão encontrada no banco após todas as estratégias!");
        }
    } catch (Exception $e) {
        error_log("Erro ao recriar existing_perms: " . $e->getMessage());
        $existing_perms = [];
    }
}

ob_start();
?>

<style>
    * {
        box-sizing: border-box;
    }
    
    .usuarios-page {
        padding: 2rem;
        max-width: 1400px;
        margin: 0 auto;
    }
    
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 2rem;
        flex-wrap: wrap;
        gap: 1rem;
    }
    
    .page-title {
        font-size: 1.875rem;
        font-weight: 700;
        color: #1e293b;
        margin: 0;
    }
    
    .page-subtitle {
        color: #64748b;
        font-size: 0.875rem;
        margin-top: 0.25rem;
    }

    .header-actions {
        display: flex;
        gap: 0.75rem;
        flex-wrap: wrap;
    }
    
    .btn-primary {
        background: #1e3a8a;
        color: white;
        border: none;
        padding: 0.75rem 1.5rem;
        border-radius: 8px;
        font-size: 0.875rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .btn-primary:hover {
        background: #2563eb;
        transform: translateY(-1px);
    }
    
    .search-bar {
        display: flex;
        gap: 0.75rem;
        margin-bottom: 2rem;
    }
    
    .search-input {
        flex: 1;
        padding: 0.75rem 1rem;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        font-size: 0.875rem;
    }
    
    .search-input:focus {
        outline: none;
        border-color: #1e3a8a;
        box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
    }
    
    .btn-search {
        background: #1e3a8a;
        color: white;
        border: none;
        padding: 0.75rem 1.5rem;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
    }
    
    .alert {
        padding: 1rem;
        border-radius: 8px;
        margin-bottom: 1.5rem;
    }
    
    .alert-success {
        background: #d1fae5;
        color: #065f46;
        border: 1px solid #6ee7b7;
    }
    
    .alert-error {
        background: #fee2e2;
        color: #991b1b;
        border: 1px solid #fca5a5;
    }
    
    .users-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
        gap: 1.5rem;
    }
    
    .user-card {
        background: white;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        padding: 1.5rem;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        transition: all 0.2s;
        display: flex;
        flex-direction: column;
    }
    
    .user-card:hover {
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        border-color: #1e3a8a;
    }
    
    .user-header {
        display: flex;
        align-items: flex-start;
        gap: 1rem;
        margin-bottom: 1rem;
        padding-bottom: 1rem;
        border-bottom: 1px solid #e5e7eb;
    }
    
    .user-avatar {
        width: 56px;
        height: 56px;
        border-radius: 50%;
        background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
        font-weight: 700;
        flex-shrink: 0;
        overflow: hidden;
        margin-top: 2px;
    }

    .user-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .user-info {
        min-width: 0;
    }
    
    .user-info h3 {
        margin: 0 0 0.25rem 0;
        font-size: 1.125rem;
        font-weight: 600;
        color: #1e293b;
    }
    
    .user-info p {
        margin: 0;
        color: #64748b;
        font-size: 0.875rem;
    }
    
    .user-details {
        flex: 1;
        margin-bottom: 1rem;
    }
    
    .detail-item {
        display: flex;
        justify-content: space-between;
        padding: 0.5rem 0;
        font-size: 0.875rem;
        border-bottom: 1px solid #f1f5f9;
    }
    
    .detail-item:last-child {
        border-bottom: none;
    }
    
    .detail-label {
        color: #64748b;
        font-weight: 500;
    }
    
    .detail-value {
        color: #1e293b;
        font-weight: 600;
    }
    
    .user-actions {
        display: flex;
        gap: 0.75rem;
        margin-top: auto;
        padding-top: 1rem;
        border-top: 1px solid #e5e7eb;
    }
    
    .btn-action {
        flex: 1;
        padding: 0.625rem 1rem;
        border: none;
        border-radius: 6px;
        font-size: 0.875rem;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
    }
    
    .btn-edit {
        background: #1e3a8a;
        color: white;
    }
    
    .btn-edit:hover {
        background: #2563eb;
    }
    
    .btn-delete {
        background: #dc2626;
        color: white;
    }
    
    .btn-delete:hover {
        background: #b91c1c;
    }
    
    /* Modal */
    .modal-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        z-index: 1000;
        align-items: center;
        justify-content: center;
        padding: 2rem;
    }
    
    .modal-overlay.active {
        display: flex;
    }
    
    .modal-content {
        background: white;
        border-radius: 12px;
        width: 100%;
        max-width: 600px;
        max-height: 90vh;
        overflow-y: auto;
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
    }
    
    .modal-header {
        padding: 1.5rem;
        border-bottom: 1px solid #e5e7eb;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .modal-title {
        font-size: 1.25rem;
        font-weight: 600;
        color: #1e293b;
        margin: 0;
    }
    
    .modal-close {
        background: none;
        border: none;
        font-size: 1.5rem;
        color: #64748b;
        cursor: pointer;
        padding: 0;
        width: 32px;
        height: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 6px;
    }
    
    .modal-close:hover {
        background: #f1f5f9;
        color: #1e293b;
    }
    
    .modal-body {
        padding: 1.5rem;
    }
    
    .form-group {
        margin-bottom: 1.25rem;
    }
    
    .form-label {
        display: block;
        font-weight: 600;
        color: #374151;
        margin-bottom: 0.5rem;
        font-size: 0.875rem;
    }
    
    .form-input {
        width: 100%;
        padding: 0.75rem;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        font-size: 0.875rem;
    }
    
    .form-input:focus {
        outline: none;
        border-color: #1e3a8a;
        box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
    }
    
    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
    }
    
    .permissions-section {
        margin-top: 2rem;
        padding-top: 1.5rem;
        border-top: 1px solid #e5e7eb;
    }
    
    .permissions-title {
        font-size: 1rem;
        font-weight: 600;
        color: #1e293b;
        margin-bottom: 1rem;
    }
    
    .permissions-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 0.75rem;
    }
    
    .permission-item {
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .permission-item input[type="checkbox"] {
        width: 18px;
        height: 18px;
        cursor: pointer;
    }
    
    .modal-footer {
        padding: 1.5rem;
        border-top: 1px solid #e5e7eb;
        display: flex;
        justify-content: flex-end;
        gap: 0.75rem;
    }
    
    .btn-secondary {
        background: #f1f5f9;
        color: #475569;
        border: none;
        padding: 0.75rem 1.5rem;
        border-radius: 6px;
        font-weight: 500;
        cursor: pointer;
    }
    
    .btn-secondary:hover {
        background: #e2e8f0;
    }

    .cargo-list {
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
    }

    .cargo-item {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 1rem;
        padding: 1rem;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        background: #f8fafc;
    }

    .cargo-item strong {
        display: block;
        color: #1e293b;
        margin-bottom: 0.25rem;
    }

    .cargo-item p {
        margin: 0;
        color: #64748b;
        font-size: 0.875rem;
    }

    .cargo-actions {
        display: flex;
        gap: 0.5rem;
        flex-shrink: 0;
    }

    .btn-small {
        border: none;
        border-radius: 6px;
        padding: 0.5rem 0.75rem;
        font-size: 0.8125rem;
        font-weight: 600;
        cursor: pointer;
    }

    .btn-small-edit {
        background: #dbeafe;
        color: #1d4ed8;
    }

    .btn-small-delete {
        background: #fee2e2;
        color: #b91c1c;
    }

    .empty-state {
        padding: 1rem;
        border: 1px dashed #cbd5e1;
        border-radius: 10px;
        color: #64748b;
        text-align: center;
        background: #f8fafc;
    }
    
    /* Estilos para abas */
    .modal-tabs {
        display: flex;
        border-bottom: 2px solid #e5e7eb;
        margin-bottom: 1.5rem;
    }
    
    .modal-tab {
        padding: 0.75rem 1.5rem;
        background: none;
        border: none;
        border-bottom: 3px solid transparent;
        cursor: pointer;
        font-size: 0.875rem;
        font-weight: 600;
        color: #64748b;
        transition: all 0.2s;
        position: relative;
        top: 2px;
    }
    
    .modal-tab:hover {
        color: #1e3a8a;
        background: #f1f5f9;
    }
    
    .modal-tab.active {
        color: #1e3a8a;
        border-bottom-color: #1e3a8a;
    }
    
    .modal-tab-content {
        display: none;
    }
    
    .modal-tab-content.active {
        display: block;
    }
    
    /* Estilos para busca de CEP */
    .cep-search-group {
        display: flex;
        gap: 0.5rem;
        align-items: flex-end;
    }
    
    .cep-search-group .form-group {
        flex: 1;
        margin-bottom: 0;
    }
    
    .btn-buscar-cep {
        background: #1e3a8a;
        color: white;
        border: none;
        padding: 0.75rem 1rem;
        border-radius: 6px;
        font-size: 0.875rem;
        font-weight: 600;
        cursor: pointer;
        white-space: nowrap;
        transition: all 0.2s;
    }
    
    .btn-buscar-cep:hover {
        background: #2563eb;
    }
    
    .btn-buscar-cep:disabled {
        background: #94a3b8;
        cursor: not-allowed;
    }

    .foto-upload-section {
        display: flex;
        align-items: center;
        gap: 1rem;
        margin-bottom: 1.5rem;
        padding: 0.875rem;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        background: #f8fafc;
    }

    .foto-preview {
        width: 88px;
        height: 88px;
        border-radius: 50%;
        border: 2px solid #cbd5e1;
        background: #e2e8f0;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.75rem;
        color: #64748b;
        cursor: pointer;
        position: relative;
        overflow: hidden;
        flex-shrink: 0;
    }

    .foto-preview img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: none;
    }

    .foto-edit-overlay {
        position: absolute;
        inset: 0;
        background: rgba(15, 23, 42, 0.6);
        color: #fff;
        font-size: 0.72rem;
        display: none;
        align-items: center;
        justify-content: center;
        text-align: center;
        padding: 6px;
    }

    .foto-actions {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
        flex: 1;
    }

    .btn-foto {
        background: #1e3a8a;
        color: #fff;
        border: none;
        border-radius: 8px;
        padding: 0.55rem 0.75rem;
        font-size: 0.82rem;
        font-weight: 700;
        cursor: pointer;
        width: fit-content;
    }

    .foto-status {
        display: none;
        font-size: 0.8rem;
        font-weight: 600;
    }

    .foto-uploading {
        display: none;
        align-items: center;
        gap: 0.5rem;
        color: #1e3a8a;
        font-size: 0.82rem;
        font-weight: 600;
    }

    .foto-editor-modal {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(2, 6, 23, 0.76);
        z-index: 2000;
        align-items: center;
        justify-content: center;
        padding: 1rem;
    }

    .foto-editor-content {
        width: min(760px, 100%);
        height: min(860px, calc(100vh - 2rem));
        background: #fff;
        border-radius: 12px;
        overflow: hidden;
        display: flex;
        flex-direction: column;
    }

    .foto-editor-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        padding: 0.9rem 1rem;
        border-bottom: 1px solid #e2e8f0;
    }

    .foto-editor-body {
        flex: 1 1 auto;
        min-height: 0;
        padding: 1rem;
        overflow: hidden;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #0f172a;
    }

    .foto-editor-body img {
        display: block;
        max-width: 100%;
        max-height: 100%;
    }

    .foto-editor-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        padding: 0.9rem 1rem 1rem;
        border-top: 1px solid #e2e8f0;
        justify-content: flex-end;
    }

    .btn-editor {
        border: none;
        border-radius: 8px;
        padding: 0.5rem 0.75rem;
        font-size: 0.82rem;
        font-weight: 700;
        cursor: pointer;
        background: #e2e8f0;
        color: #1e293b;
    }

    .btn-editor-primary {
        background: #1e3a8a;
        color: #fff;
    }

    @media (max-width: 640px) {
        .foto-editor-modal {
            padding: 0.75rem;
        }

        .header-actions {
            width: 100%;
        }

        .header-actions button {
            flex: 1;
            justify-content: center;
        }

        .foto-editor-content {
            width: 100%;
            height: calc(100vh - 1.5rem);
            border-radius: 10px;
        }

        .foto-editor-body {
            padding: 0.75rem;
        }

        .foto-editor-actions {
            justify-content: stretch;
        }

        .btn-editor {
            flex: 1 1 calc(50% - 0.25rem);
            text-align: center;
        }

        .cargo-item {
            flex-direction: column;
            align-items: stretch;
        }

        .cargo-actions {
            justify-content: flex-end;
        }
    }
</style>

<div class="usuarios-page">
    <div class="page-header">
        <div>
            <h1 class="page-title">Usuários e Colaboradores</h1>
            <p class="page-subtitle">Gerencie usuários, permissões e colaboradores do sistema</p>
        </div>
        <div class="header-actions">
            <button class="btn-secondary" type="button" onclick="openCargoModal()">
                <span>🏷️</span>
                <span>Cargos</span>
            </button>
            <button class="btn-primary" type="button" onclick="openModal(0)">
                <span>+</span>
                <span>Novo Usuário</span>
            </button>
        </div>
    </div>
    
    <?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success">
        <?= htmlspecialchars($_GET['success']) ?>
    </div>
    <?php endif; ?>
    
    <?php if (isset($_GET['error'])): ?>
    <div class="alert alert-error">
        <?= htmlspecialchars($_GET['error']) ?>
    </div>
    <?php endif; ?>
    
    <form method="GET" action="index.php" class="search-bar">
        <input type="hidden" name="page" value="usuarios">
        <input type="text" name="search" class="search-input" 
               placeholder="Pesquisar por nome, login ou email..." 
               value="<?= htmlspecialchars($search) ?>">
        <button type="submit" class="btn-search">Buscar</button>
    </form>
    
    <div class="users-grid">
        <?php 
        // Definir permissões válidas da sidebar + superadmin
        $valid_perms_for_count = [
            'perm_superadmin', 'perm_agenda', 'perm_demandas', 'perm_comercial', 'perm_eventos', 'perm_eventos_realizar', 'perm_logistico',
            'perm_configuracoes', 'perm_cadastros', 'perm_financeiro', 'perm_administrativo', 'perm_vendas_administracao',
            'perm_portao'
        ];
        
        foreach ($usuarios as $user): 
            $permissoes_ativas = [];
            foreach ($valid_perms_for_count as $perm) {
                if (!empty($user[$perm])) {
                    $permissoes_ativas[] = $perm;
                }
            }
        ?>
        <div class="user-card">
            <div class="user-header">
                <div class="user-avatar">
                    <?php if (!empty($user['foto'])): ?>
                        <img src="<?= h($user['foto']) ?>" alt="Foto de <?= h($user['nome'] ?? 'Usuário') ?>">
                    <?php else: ?>
                        <?= strtoupper(substr($user['nome'] ?? 'U', 0, 1)) ?>
                    <?php endif; ?>
                </div>
                <div class="user-info">
                    <h3><?= h($user['nome'] ?? 'Sem nome') ?></h3>
                    <p><?= h($user['login'] ?? $user['email'] ?? 'Sem login') ?></p>
                </div>
            </div>
            
            <div class="user-details">
                <div class="detail-item">
                    <span class="detail-label">Email</span>
                    <span class="detail-value">
                        <?php 
                        $email = $user['email'] ?? '';
                        if (!empty($email)) {
                            echo h($email);
                        } else {
                            echo '<span style="color: #94a3b8; font-style: italic;">Não informado</span>';
                        }
                        ?>
                    </span>
                </div>
                
                <?php if (!empty($user['cargo'])): ?>
                <div class="detail-item">
                    <span class="detail-label">Cargo</span>
                    <span class="detail-value"><?= h($user['cargo']) ?></span>
                </div>
                <?php endif; ?>
                
                <div class="detail-item">
                    <span class="detail-label">Status</span>
                    <span class="detail-value" style="color: <?= ($user['ativo'] ?? true) ? '#059669' : '#dc2626' ?>">
                        <?= ($user['ativo'] ?? true) ? 'Ativo' : 'Inativo' ?>
                    </span>
                </div>
                
                <?php if (count($permissoes_ativas) > 0): ?>
                <div class="detail-item">
                    <span class="detail-label">Permissões</span>
                    <span class="detail-value"><?= count($permissoes_ativas) ?> ativas</span>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="user-actions">
                <button class="btn-action btn-edit" onclick="openModal(<?= $user['id'] ?>)">
                    <span>✏️</span>
                    <span>Editar</span>
                </button>
                <button class="btn-action btn-delete" onclick="deleteUser(<?= $user['id'] ?>)">
                    <span>🗑️</span>
                    <span>Excluir</span>
                </button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Modal -->
<div id="userModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title" id="modalTitle">Novo Usuário</h2>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <form id="userForm" method="POST" action="index.php?page=usuarios" onsubmit="console.log('Formulário sendo submetido!'); return true;">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="user_id" id="userId" value="0">
            
            <div class="modal-body">
                <!-- Abas -->
                <div class="modal-tabs">
                    <button type="button" class="modal-tab active" onclick="switchTab('usuario')" data-tab="usuario">
                        👤 Usuário
                    </button>
                    <button type="button" class="modal-tab" onclick="switchTab('dados')" data-tab="dados">
                        📋 Dados Pessoais
                    </button>
                    <button type="button" class="modal-tab" onclick="switchTab('unidade')" data-tab="unidade">
                        🏢 Unidade
                    </button>
                </div>
                
                <!-- Aba Usuário -->
                <div id="tab-usuario" class="modal-tab-content active">
                    <div class="foto-upload-section">
                        <div class="foto-preview" id="fotoPreview" title="Clique para escolher ou editar foto">
                            <img id="fotoPreviewImg" src="" alt="Pré-visualização da foto">
                            <span id="fotoPreviewText">Sem foto</span>
                            <div class="foto-edit-overlay" id="fotoEditOverlay">Editar foto</div>
                        </div>
                        <div class="foto-actions">
                            <button type="button" class="btn-foto" id="btnSelecionarFoto">Selecionar foto</button>
                            <input type="file" id="fotoInput" accept="image/*" style="display:none;">
                            <input type="hidden" name="foto" id="fotoUrl" value="">
                            <input type="hidden" id="fotoAtual" value="">
                            <input type="hidden" id="fotoEditada" value="">
                            <div class="foto-uploading" id="fotoUploading">⏳ Enviando foto...</div>
                            <div class="foto-status" id="fotoStatus"></div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Nome *</label>
                        <input type="text" name="nome" class="form-input" required>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Login *</label>
                            <input type="text" name="login" class="form-input" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Email *</label>
                            <input type="email" name="email" class="form-input" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" id="senhaLabel">Senha *</label>
                        <input type="password" name="senha" id="senhaInput" class="form-input" required autocomplete="new-password">
                        <input type="hidden" name="alterar_senha" id="alterarSenhaInput" value="0">
                        <label id="alterarSenhaToggleWrap" style="display: none; margin-top: 0.6rem; color: #475569; font-size: 0.85rem;">
                            <input type="checkbox" id="alterarSenhaToggle" value="1">
                            Alterar senha deste usuário
                        </label>
                        <small style="color: #64748b; font-size: 0.75rem; display: none;" id="senhaHint">(deixe em branco para não alterar)</small>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Cargo</label>
                        <select name="cargo" id="cargoSelect" class="form-input">
                            <option value="">Selecione um cargo</option>
                            <?php foreach ($cargos as $cargo): ?>
                            <option value="<?= h($cargo['nome'] ?? '') ?>"><?= h($cargo['nome'] ?? '') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                
                <?php
                // DEBUG: Verificar se $existing_perms está disponível e não vazio
                if (!isset($existing_perms) || !is_array($existing_perms) || empty($existing_perms)) {
                    // Se não estiver disponível ou vazio, buscar novamente com múltiplas estratégias
                    try {
                        $perms_array_debug = [];
                        
                        // Estratégia 1: Com schema 'public'
                        $stmt_debug = $pdo->query("SELECT column_name FROM information_schema.columns 
                                                     WHERE table_schema = 'public' AND table_name = 'usuarios' 
                                                     AND column_name LIKE 'perm_%' 
                                                     ORDER BY column_name");
                        $perms_array_debug = $stmt_debug->fetchAll(PDO::FETCH_COLUMN);
                        
                        // Estratégia 2: Sem especificar schema
                        if (empty($perms_array_debug)) {
                            $stmt_debug = $pdo->query("SELECT column_name FROM information_schema.columns 
                                                         WHERE table_name = 'usuarios' 
                                                         AND column_name LIKE 'perm_%' 
                                                         ORDER BY column_name");
                            $perms_array_debug = $stmt_debug->fetchAll(PDO::FETCH_COLUMN);
                        }
                        
                        // Estratégia 3: Buscar todas e filtrar
                        if (empty($perms_array_debug)) {
                            $stmt_debug = $pdo->query("SELECT column_name FROM information_schema.columns 
                                                         WHERE table_name = 'usuarios' 
                                                         ORDER BY column_name");
                            $all_cols_debug = $stmt_debug->fetchAll(PDO::FETCH_COLUMN);
                            $perms_array_debug = array_values(array_filter($all_cols_debug, function($col) {
                                return strpos($col, 'perm_') === 0;
                            }));
                        }
                        
                        if (!empty($perms_array_debug)) {
                            $existing_perms = array_flip($perms_array_debug);
                            error_log("Permissões encontradas no modal: " . count($existing_perms));
                        } else {
                            $existing_perms = [];
                            error_log("Erro: Nenhuma permissão encontrada no modal após todas as estratégias");
                        }
                    } catch (Exception $e) {
                        error_log("Erro ao buscar permissões no modal: " . $e->getMessage());
                        $existing_perms = [];
                    }
                }

                // Garantir que perm_superadmin exista no banco (auto-heal seguro)
                if (!isset($existing_perms['perm_superadmin'])) {
                    try {
                        $pdo->exec("ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS perm_superadmin BOOLEAN DEFAULT FALSE");
                        $existing_perms['perm_superadmin'] = true;
                    } catch (Exception $e) {
                        error_log("Erro ao adicionar perm_superadmin: " . $e->getMessage());
                    }
                }

                if (!isset($existing_perms['perm_portao'])) {
                    try {
                        $pdo->exec("ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS perm_portao BOOLEAN DEFAULT FALSE");
                        $existing_perms['perm_portao'] = true;
                    } catch (Exception $e) {
                        error_log("Erro ao adicionar perm_portao: " . $e->getMessage());
                    }
                }

                if (!isset($existing_perms['perm_eventos_realizar'])) {
                    try {
                        $pdo->exec("ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS perm_eventos_realizar BOOLEAN DEFAULT FALSE");
                        // Compatibilidade: ao criar a coluna, replica perm_eventos para evitar perda de acesso.
                        $pdo->exec("UPDATE usuarios SET perm_eventos_realizar = COALESCE(perm_eventos, FALSE)");
                        $existing_perms['perm_eventos_realizar'] = true;
                    } catch (Exception $e) {
                        error_log("Erro ao adicionar perm_eventos_realizar: " . $e->getMessage());
                    }
                }

                if (!isset($existing_perms['perm_vendas_administracao'])) {
                    try {
                        $pdo->exec("ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS perm_vendas_administracao BOOLEAN DEFAULT FALSE");
                        $existing_perms['perm_vendas_administracao'] = true;
                    } catch (Exception $e) {
                        error_log("Erro ao adicionar perm_vendas_administracao: " . $e->getMessage());
                    }
                }
                
                // Mapeamento de permissões exibidas no cadastro de usuários.
                $perm_labels = [
                    'perm_agenda' => '📅 Agenda',
                    'perm_demandas' => '📝 Demandas',
                    'perm_comercial' => '📋 Comercial',
                    'perm_eventos' => '🎉 Eventos',
                    'perm_eventos_realizar' => '✅ Realizar evento',
                    'perm_logistico' => '📦 Logística',
                    'perm_configuracoes' => '⚙️ Configurações',
                    'perm_cadastros' => '📝 Cadastros',
                    'perm_financeiro' => '💰 Financeiro',
                    'perm_administrativo' => '👥 Administrativo',
                    'perm_vendas_administracao' => '🛡️ Administrativo / Vendas',
                    'perm_portao' => '🔓 Portao',
                ];
                
                $system_perms = [
                    'perm_agenda',
                    'perm_demandas',
                    'perm_comercial',
                    'perm_eventos',
                    'perm_eventos_realizar',
                    'perm_logistico',
                    'perm_configuracoes',
                    'perm_cadastros',
                    'perm_financeiro',
                    'perm_administrativo',
                    'perm_vendas_administracao',
                    'perm_portao'
                ];
                
                // Filtrar apenas permissões existentes no banco.
                $available_perms = [];
                if (!empty($existing_perms) && is_array($existing_perms)) {
                    foreach ($system_perms as $perm) {
                        if (isset($existing_perms[$perm]) && isset($perm_labels[$perm])) {
                            $available_perms[$perm] = $perm_labels[$perm];
                        }
                    }
                }
                ?>
                
                <div class="permissions-section">
                    <h3 class="permissions-title">Permissões Especiais</h3>
                    <div class="permissions-grid">
                        <div class="permission-item">
                            <input type="hidden" name="perm_superadmin" value="0">
                            <input type="checkbox" name="perm_superadmin" id="perm_perm_superadmin" value="1">
                            <label for="perm_perm_superadmin">⭐ Superadmin (bypass total)</label>
                        </div>
                    </div>
                </div>

                <?php if (!empty($available_perms)): ?>
                <div class="permissions-section">
                    <h3 class="permissions-title">Permissões do Sistema</h3>
                    <div class="permissions-grid">
                        <?php foreach ($available_perms as $perm => $label): ?>
                        <div class="permission-item">
                            <input type="hidden" name="<?= htmlspecialchars($perm) ?>" value="0">
                            <input type="checkbox" name="<?= htmlspecialchars($perm) ?>" id="perm_<?= htmlspecialchars($perm) ?>" value="1">
                            <label for="perm_<?= htmlspecialchars($perm) ?>"><?= htmlspecialchars($label) ?></label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php else: ?>
                <div class="permissions-section">
                    <p style="color: #dc2626; font-size: 0.875rem; padding: 1rem; background: #fee2e2; border-radius: 6px;">
                        <strong>⚠️ Nenhuma permissão encontrada no banco de dados.</strong><br>
                        <small>Verifique se as colunas de permissões foram criadas corretamente.</small>
                    </p>
                    <p style="color: #64748b; font-size: 0.75rem; margin-top: 0.5rem;">
                        <strong>Debug Info:</strong><br>
                        - existing_perms está <?= isset($existing_perms) ? '<strong style="color: green;">DEFINIDO</strong>' : '<strong style="color: red;">NÃO DEFINIDO</strong>' ?><br>
                        - É array: <?= isset($existing_perms) && is_array($existing_perms) ? '<strong style="color: green;">SIM</strong>' : '<strong style="color: red;">NÃO</strong>' ?><br>
                        - Count: <?= isset($existing_perms) && is_array($existing_perms) ? '<strong>' . count($existing_perms) . '</strong>' : 'N/A' ?><br>
                        - available_perms count: <?= count($available_perms) ?><br>
                        <?php if (isset($existing_perms) && is_array($existing_perms) && count($existing_perms) > 0): ?>
                        - Primeiras 3 permissões: <?= implode(', ', array_slice(array_keys($existing_perms), 0, 3)) ?>
                        <?php endif; ?>
                    </p>
                </div>
                <?php endif; ?>
                </div>
                
                <!-- Aba Dados Pessoais -->
                <div id="tab-dados" class="modal-tab-content">
                    <div class="form-group">
                        <label class="form-label">Nome Completo</label>
                        <input type="text" name="nome_completo" class="form-input" placeholder="Nome completo do colaborador">
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">CPF</label>
                            <input type="text" name="cpf" id="cpfInput" class="form-input" placeholder="000.000.000-00" maxlength="14">
                        </div>
                        <div class="form-group">
                            <label class="form-label">RG</label>
                            <input type="text" name="rg" class="form-input" placeholder="00.000.000-0">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Telefone</label>
                            <input type="text" name="telefone" class="form-input" placeholder="(00) 0000-0000">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Celular</label>
                            <input type="text" name="celular" class="form-input" placeholder="(00) 00000-0000">
                        </div>
                    </div>
                    
                    <div style="margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid #e5e7eb;">
                        <h4 style="font-size: 1rem; font-weight: 600; color: #1e293b; margin-bottom: 1rem;">Endereço</h4>
                        
                        <div class="cep-search-group">
                            <div class="form-group">
                                <label class="form-label">CEP</label>
                                <input type="text" name="endereco_cep" id="cepInput" class="form-input" placeholder="00000-000" maxlength="9">
                            </div>
                            <button type="button" class="btn-buscar-cep" onclick="buscarCEP()" id="btnBuscarCEP">
                                🔍 Buscar CEP
                            </button>
                        </div>
                        
                        <div class="form-group" style="margin-top: 1rem;">
                            <label class="form-label">Logradouro</label>
                            <input type="text" name="endereco_logradouro" id="enderecoLogradouro" class="form-input" placeholder="Rua, Avenida, etc">
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Número</label>
                                <input type="text" name="endereco_numero" id="enderecoNumero" class="form-input" placeholder="123">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Complemento</label>
                                <input type="text" name="endereco_complemento" id="enderecoComplemento" class="form-input" placeholder="Apto, Bloco, etc">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Bairro</label>
                            <input type="text" name="endereco_bairro" id="enderecoBairro" class="form-input" placeholder="Nome do bairro">
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Cidade</label>
                                <input type="text" name="endereco_cidade" id="enderecoCidade" class="form-input" placeholder="Nome da cidade">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Estado (UF)</label>
                                <input type="text" name="endereco_estado" id="enderecoEstado" class="form-input" placeholder="SP" maxlength="2" style="text-transform: uppercase;">
                            </div>
                        </div>
                    </div>
                </div>

                <div id="tab-unidade" class="modal-tab-content">
                    <div class="form-group">
                        <label class="form-label">Spaces visíveis vinculados</label>
                        <p style="margin: 0 0 1rem; color: #64748b; font-size: 0.875rem;">
                            Selecione um ou mais spaces visíveis cadastrados em Logística > Conexão.
                        </p>
                        <div id="unidadesCheckboxList" class="permissions-grid">
                            <?php if (empty($unidadesDisponiveis)): ?>
                            <div class="empty-state" style="grid-column: 1 / -1;">Nenhum space visível disponível no sistema.</div>
                            <?php else: ?>
                                <?php foreach ($unidadesDisponiveis as $unidade): ?>
                                <label class="permission-item" style="align-items: flex-start;">
                                    <input type="checkbox" name="spaces_visiveis[]" value="<?= h($unidade['nome'] ?? '') ?>">
                                    <span><?= h($unidade['nome'] ?? '') ?></span>
                                </label>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal()">Cancelar</button>
                <button type="submit" class="btn-primary">Salvar</button>
            </div>
        </form>
    </div>
</div>

<div id="cargoModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">Gerenciar Cargos</h2>
            <button type="button" class="modal-close" onclick="closeCargoModal()">&times;</button>
        </div>
        <div class="modal-body">
            <form id="cargoForm" onsubmit="saveCargo(event)">
                <input type="hidden" id="cargoId" value="0">
                <div class="form-group">
                    <label class="form-label">Nome do cargo</label>
                    <input type="text" id="cargoNome" class="form-input" placeholder="Ex.: Coordenador Financeiro">
                </div>
                <div class="form-group">
                    <label class="form-label">Descrição</label>
                    <textarea id="cargoDescricao" class="form-input" rows="3" placeholder="Opcional"></textarea>
                </div>
                <div class="modal-footer" style="padding: 1rem 0 0; border-top: none;">
                    <button type="button" class="btn-secondary" onclick="resetCargoForm()">Limpar</button>
                    <button type="submit" class="btn-primary">Salvar cargo</button>
                </div>
            </form>

            <div style="margin-top: 1.5rem;">
                <h3 style="margin: 0 0 0.75rem; color: #1e293b; font-size: 1rem;">Cargos cadastrados</h3>
                <div id="cargoList" class="cargo-list">
                    <?php if (empty($cargos)): ?>
                    <div class="empty-state">Nenhum cargo cadastrado.</div>
                    <?php else: ?>
                        <?php foreach ($cargos as $cargo): ?>
                        <div class="cargo-item" data-cargo-id="<?= (int)($cargo['id'] ?? 0) ?>" data-cargo-nome="<?= h($cargo['nome'] ?? '') ?>" data-cargo-descricao="">
                            <div>
                                <strong><?= h($cargo['nome'] ?? '') ?></strong>
                            </div>
                            <div class="cargo-actions">
                                <button type="button" class="btn-small btn-small-edit" onclick="editCargoFromElement(this)">Editar</button>
                                <button type="button" class="btn-small btn-small-delete" onclick="deleteCargoFromElement(this)">Excluir</button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal editor/crop de foto -->
<div id="fotoEditorModal" class="foto-editor-modal">
    <div class="foto-editor-content">
        <div class="foto-editor-header">
            <h3 style="margin:0;font-size:1rem;color:#1e293b;">Editar foto</h3>
            <button type="button" class="modal-close" onclick="fecharEditorFoto()">&times;</button>
        </div>
        <div class="foto-editor-body">
            <img id="fotoEditorImg" src="" alt="Editor de foto">
        </div>
        <div class="foto-editor-actions">
            <button type="button" class="btn-editor" onclick="fotoEditorZoomOut()">- Zoom</button>
            <button type="button" class="btn-editor" onclick="fotoEditorZoomIn()">+ Zoom</button>
            <button type="button" class="btn-editor" onclick="fotoEditorRotate()">↻ Girar</button>
            <button type="button" class="btn-editor" onclick="fotoEditorReset()">Resetar</button>
            <button type="button" class="btn-editor" onclick="fecharEditorFoto()">Cancelar</button>
            <button type="button" class="btn-editor btn-editor-primary" onclick="aplicarEdicaoFoto()">Aplicar</button>
        </div>
    </div>
</div>

<script>
let cargoOptions = <?= json_encode($cargos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
let unidadesOptions = <?= json_encode($unidadesDisponiveis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function populateCargoSelect(selectedValue = '') {
    const cargoSelect = document.getElementById('cargoSelect');
    if (!cargoSelect) return;

    cargoSelect.innerHTML = '<option value="">Selecione um cargo</option>';

    cargoOptions.forEach(cargo => {
        const option = document.createElement('option');
        option.value = cargo.nome || '';
        option.textContent = cargo.nome || '';
        if ((cargo.nome || '') === selectedValue) {
            option.selected = true;
        }
        cargoSelect.appendChild(option);
    });

    if (!selectedValue) {
        cargoSelect.value = '';
    }
}

function renderUnidadesCheckboxes(selectedIds = []) {
    const container = document.getElementById('unidadesCheckboxList');
    if (!container) return;

    const selectedSet = new Set((selectedIds || []).map(value => String(value)));

    if (!Array.isArray(unidadesOptions) || unidadesOptions.length === 0) {
        container.innerHTML = '<div class="empty-state" style="grid-column: 1 / -1;">Nenhum space visível disponível no sistema.</div>';
        return;
    }

    container.innerHTML = unidadesOptions.map(unidade => `
        <label class="permission-item" style="align-items: flex-start;">
            <input type="checkbox" name="spaces_visiveis[]" value="${escapeHtml(unidade.nome || '')}" ${selectedSet.has(String(unidade.nome || '')) ? 'checked' : ''}>
            <span>${escapeHtml(unidade.nome || '')}</span>
        </label>
    `).join('');
}

function renderCargoList() {
    const cargoList = document.getElementById('cargoList');
    if (!cargoList) return;

    if (!Array.isArray(cargoOptions) || cargoOptions.length === 0) {
        cargoList.innerHTML = '<div class="empty-state">Nenhum cargo cadastrado.</div>';
        return;
    }

    cargoList.innerHTML = cargoOptions.map(cargo => `
        <div class="cargo-item" data-cargo-id="${Number(cargo.id || 0)}" data-cargo-nome="${escapeHtml(cargo.nome || '')}" data-cargo-descricao="${escapeHtml(cargo.descricao || '')}">
            <div>
                <strong>${escapeHtml(cargo.nome || '')}</strong>
                ${cargo.descricao ? `<p>${escapeHtml(cargo.descricao)}</p>` : ''}
            </div>
            <div class="cargo-actions">
                <button type="button" class="btn-small btn-small-edit" onclick="editCargoFromElement(this)">Editar</button>
                <button type="button" class="btn-small btn-small-delete" onclick="deleteCargoFromElement(this)">Excluir</button>
            </div>
        </div>
    `).join('');
}

async function refreshCargoOptions(selectedValue = '') {
    try {
        const response = await fetch('index.php?page=usuarios&action=get_cargos', {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            credentials: 'same-origin'
        });
        const data = await response.json();
        if (data.success) {
            cargoOptions = Array.isArray(data.cargos) ? data.cargos : [];
        }
    } catch (error) {
        console.error('Erro ao atualizar cargos:', error);
    }

    renderCargoList();
    populateCargoSelect(selectedValue);
}

function openCargoModal() {
    document.getElementById('cargoModal').classList.add('active');
    resetCargoForm();
    refreshCargoOptions();
}

function closeCargoModal() {
    document.getElementById('cargoModal').classList.remove('active');
}

function resetCargoForm() {
    const cargoId = document.getElementById('cargoId');
    const cargoNome = document.getElementById('cargoNome');
    const cargoDescricao = document.getElementById('cargoDescricao');
    if (cargoId) cargoId.value = '0';
    if (cargoNome) cargoNome.value = '';
    if (cargoDescricao) cargoDescricao.value = '';
}

function editCargoFromElement(button) {
    const item = button.closest('.cargo-item');
    if (!item) return;

    const cargoId = document.getElementById('cargoId');
    const cargoNome = document.getElementById('cargoNome');
    const cargoDescricao = document.getElementById('cargoDescricao');

    if (cargoId) cargoId.value = item.dataset.cargoId || '0';
    if (cargoNome) cargoNome.value = item.dataset.cargoNome || '';
    if (cargoDescricao) cargoDescricao.value = item.dataset.cargoDescricao || '';
}

async function deleteCargoFromElement(button) {
    const item = button.closest('.cargo-item');
    if (!item) return;

    const cargoId = Number(item.dataset.cargoId || 0);
    const cargoNome = item.dataset.cargoNome || '';
    if (cargoId <= 0) return;

    if (!confirm(`Excluir o cargo "${cargoNome}"?`)) {
        return;
    }

    const formData = new FormData();
    formData.append('action', 'delete_cargo');
    formData.append('cargo_id', String(cargoId));

    try {
        const response = await fetch('index.php?page=usuarios', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            credentials: 'same-origin'
        });
        const data = await response.json();
        if (!data.success) {
            throw new Error(data.message || 'Erro ao excluir cargo');
        }

        cargoOptions = Array.isArray(data.cargos) ? data.cargos : [];
        renderCargoList();
        populateCargoSelect('');
        resetCargoForm();
        alert(data.message || 'Cargo excluído com sucesso!');
    } catch (error) {
        alert(error.message || 'Erro ao excluir cargo');
    }
}

async function saveCargo(event) {
    event.preventDefault();

    const cargoId = document.getElementById('cargoId');
    const cargoNome = document.getElementById('cargoNome');
    const cargoDescricao = document.getElementById('cargoDescricao');

    const nome = (cargoNome?.value || '').trim();
    if (!nome) {
        alert('Informe o nome do cargo.');
        return;
    }

    const formData = new FormData();
    formData.append('action', 'save_cargo');
    formData.append('cargo_id', cargoId?.value || '0');
    formData.append('nome', nome);
    formData.append('descricao', cargoDescricao?.value || '');

    try {
        const response = await fetch('index.php?page=usuarios', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            credentials: 'same-origin'
        });
        const data = await response.json();
        if (!data.success) {
            throw new Error(data.message || 'Erro ao salvar cargo');
        }

        cargoOptions = Array.isArray(data.cargos) ? data.cargos : [];
        renderCargoList();
        populateCargoSelect(nome);
        resetCargoForm();
        alert(data.message || 'Cargo salvo com sucesso!');
    } catch (error) {
        alert(error.message || 'Erro ao salvar cargo');
    }
}

// Função para trocar entre abas
function switchTab(tabName) {
    // Esconder todas as abas
    document.querySelectorAll('.modal-tab-content').forEach(content => {
        content.classList.remove('active');
    });
    
    // Remover active de todos os botões
    document.querySelectorAll('.modal-tab').forEach(tab => {
        tab.classList.remove('active');
    });
    
    // Mostrar aba selecionada
    const tabContent = document.getElementById('tab-' + tabName);
    const tabButton = document.querySelector(`[data-tab="${tabName}"]`);
    
    if (tabContent) {
        tabContent.classList.add('active');
    }
    if (tabButton) {
        tabButton.classList.add('active');
    }
}

// Função para buscar CEP
async function buscarCEP() {
    const cepInput = document.getElementById('cepInput');
    const btnBuscar = document.getElementById('btnBuscarCEP');
    const cep = cepInput.value.replace(/\D/g, '');
    
    if (cep.length !== 8) {
        alert('Por favor, digite um CEP válido (8 dígitos)');
        return;
    }
    
    // Desabilitar botão durante busca
    btnBuscar.disabled = true;
    btnBuscar.textContent = 'Buscando...';
    
    try {
        const response = await fetch(`buscar_cep_endpoint.php?cep=${cep}`);
        const data = await response.json();
        
        if (data.success && data.data) {
            // Preencher campos automaticamente
            document.getElementById('enderecoLogradouro').value = data.data.logradouro || '';
            document.getElementById('enderecoBairro').value = data.data.bairro || '';
            document.getElementById('enderecoCidade').value = data.data.cidade || '';
            document.getElementById('enderecoEstado').value = data.data.estado || '';
            document.getElementById('enderecoComplemento').value = data.data.complemento || '';
            
            // Focar no campo número
            document.getElementById('enderecoNumero').focus();
        } else {
            alert(data.message || 'CEP não encontrado');
        }
    } catch (error) {
        console.error('Erro ao buscar CEP:', error);
        alert('Erro ao buscar CEP. Tente novamente.');
    } finally {
        btnBuscar.disabled = false;
        btnBuscar.textContent = '🔍 Buscar CEP';
    }
}

// Formatar CEP
document.addEventListener('DOMContentLoaded', function() {
    const cepInput = document.getElementById('cepInput');
    if (cepInput) {
        let cepAutoTimeout = null;
        let lastCepAuto = '';

        cepInput.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length > 5) {
                value = value.substring(0, 5) + '-' + value.substring(5, 8);
            }
            e.target.value = value;

            // Auto-busca quando completar 8 dígitos (evita depender do botão)
            const digits = value.replace(/\D/g, '');
            if (digits.length === 8 && digits !== lastCepAuto) {
                lastCepAuto = digits;
                clearTimeout(cepAutoTimeout);
                cepAutoTimeout = setTimeout(() => buscarCEP(), 350);
            }
        });
        
        // Buscar CEP ao pressionar Enter
        cepInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                buscarCEP();
            }
        });
    }
    
    // Formatar CPF
    const cpfInput = document.getElementById('cpfInput');
    if (cpfInput) {
        cpfInput.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length > 11) value = value.substring(0, 11);
            if (value.length > 9) {
                value = value.substring(0, 3) + '.' + value.substring(3, 6) + '.' + value.substring(6, 9) + '-' + value.substring(9);
            } else if (value.length > 6) {
                value = value.substring(0, 3) + '.' + value.substring(3, 6) + '.' + value.substring(6);
            } else if (value.length > 3) {
                value = value.substring(0, 3) + '.' + value.substring(3);
            }
            e.target.value = value;
        });
    }
    
    // Formatar telefone
    const telefoneInputs = document.querySelectorAll('input[name="telefone"], input[name="celular"]');
    telefoneInputs.forEach(input => {
        input.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            const isCelular = e.target.name === 'celular' || value.length > 10;
            if (isCelular && value.length > 11) value = value.substring(0, 11);
            if (!isCelular && value.length > 10) value = value.substring(0, 10);
            
            if (value.length > 6) {
                if (isCelular) {
                    value = '(' + value.substring(0, 2) + ') ' + value.substring(2, 7) + '-' + value.substring(7);
                } else {
                    value = '(' + value.substring(0, 2) + ') ' + value.substring(2, 6) + '-' + value.substring(6);
                }
            } else if (value.length > 2) {
                value = '(' + value.substring(0, 2) + ') ' + value.substring(2);
            } else if (value.length > 0) {
                value = '(' + value;
            }
            e.target.value = value;
        });
    });
    
    // Unificar Nome e Nome Completo: ao alterar um, atualizar o outro automaticamente
    (function() {
        var syncingNome = false;
        var nomeInput = document.querySelector('#userForm [name="nome"]');
        var nomeCompletoInput = document.querySelector('#userForm [name="nome_completo"]');
        if (nomeInput && nomeCompletoInput) {
            nomeInput.addEventListener('input', function() {
                if (syncingNome) return;
                syncingNome = true;
                nomeCompletoInput.value = nomeInput.value;
                syncingNome = false;
            });
            nomeCompletoInput.addEventListener('input', function() {
                if (syncingNome) return;
                syncingNome = true;
                nomeInput.value = nomeCompletoInput.value;
                syncingNome = false;
            });
        }
    })();
});

function openModal(userId = 0) {
    const modal = document.getElementById('userModal');
    const form = document.getElementById('userForm');
    const title = document.getElementById('modalTitle');
    const userIdInput = document.getElementById('userId');
    const senhaLabel = document.getElementById('senhaLabel');
    const senhaInput = document.getElementById('senhaInput');
    const senhaHint = document.getElementById('senhaHint');
    const alterarSenhaInput = document.getElementById('alterarSenhaInput');
    const alterarSenhaToggle = document.getElementById('alterarSenhaToggle');
    const alterarSenhaToggleWrap = document.getElementById('alterarSenhaToggleWrap');
    
    if (!modal || !form || !title || !userIdInput) {
        console.error('Elementos do modal não encontrados');
        alert('Erro: Elementos do modal não encontrados. Recarregue a página.');
        return;
    }
    
    userId = parseInt(userId) || 0;
    
    if (userId > 0) {
        form.reset();
        title.textContent = 'Editar Usuário';
        userIdInput.value = userId;
        form.dataset.mode = 'edit';
        
        if (alterarSenhaInput) alterarSenhaInput.value = '0';
        if (alterarSenhaToggle) alterarSenhaToggle.checked = false;
        if (alterarSenhaToggleWrap) alterarSenhaToggleWrap.style.display = 'inline-flex';
        if (senhaLabel) senhaLabel.textContent = 'Nova senha';
        if (senhaInput) {
            senhaInput.value = '';
            senhaInput.disabled = true;
            senhaInput.removeAttribute('required');
        }
        if (senhaHint) senhaHint.style.display = 'block';
        
        // Mostrar modal PRIMEIRO (antes de carregar dados)
        modal.classList.add('active');
        
        loadUserData(userId);
    } else {
        title.textContent = 'Novo Usuário';
        userIdInput.value = 0;
        form.dataset.mode = 'create';
        form.reset();
        
        if (alterarSenhaInput) alterarSenhaInput.value = '1';
        if (alterarSenhaToggle) alterarSenhaToggle.checked = false;
        if (alterarSenhaToggleWrap) alterarSenhaToggleWrap.style.display = 'none';
        if (senhaLabel) senhaLabel.textContent = 'Senha *';
        if (senhaInput) {
            senhaInput.disabled = false;
            senhaInput.value = '';
            senhaInput.setAttribute('required', 'required');
        }
        if (senhaHint) senhaHint.style.display = 'none';

        refreshCargoOptions('');
        renderUnidadesCheckboxes([]);
        // Limpar preview da foto e editor
        updateFotoPreview('');
        fotoOriginalBlob = null;
        if (fotoCropper) {
            fotoCropper.destroy();
            fotoCropper = null;
        }
        
        // Limpar todos os checkboxes
        form.querySelectorAll('input[type="checkbox"]').forEach(cb => {
            cb.checked = false;
        });
        // Limpar todos os inputs de texto
        form.querySelectorAll('input[type="text"], input[type="email"], input[type="password"]').forEach(input => {
            input.value = '';
        });
        // Limpar foto atual
        const fotoAtualInput = document.getElementById('fotoAtual');
        if (fotoAtualInput) fotoAtualInput.value = '';
        const fotoUrlInput = document.getElementById('fotoUrl');
        if (fotoUrlInput) fotoUrlInput.value = '';
        const fotoEditadaInput = document.getElementById('fotoEditada');
        if (fotoEditadaInput) fotoEditadaInput.value = '';
        
        // Mostrar modal PRIMEIRO
        modal.classList.add('active');
        
        // AGORA tentar registrar listeners de foto quando modal já estiver visível
        setTimeout(() => {
            console.log('🔍 Tentando registrar listeners de foto ao abrir modal...');
            const fotoInput = document.getElementById('fotoInput');
            const btnSelecionarFoto = document.getElementById('btnSelecionarFoto');
            
            console.log('Elementos encontrados:', {
                fotoInput: !!fotoInput,
                btnSelecionarFoto: !!btnSelecionarFoto,
                modal: !!modal
            });
            
            if (fotoInput && btnSelecionarFoto) {
                console.log('✅ Elementos encontrados! Registrando listeners...');
                
                // Registrar botão se ainda não tiver listener
                if (btnSelecionarFoto.getAttribute('listener') !== 'attached') {
                    console.log('Registrando botão Selecionar Foto...');
                    btnSelecionarFoto.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        console.log('🔘 Botão Selecionar Foto clicado!');
                        const fotoInputNow = document.getElementById('fotoInput');
                        if (fotoInputNow) {
                            console.log('Abrindo seletor de arquivo...');
                            fotoInputNow.click();
                        } else {
                            console.error('❌ fotoInput não encontrado ao clicar!');
                        }
                    });
                    btnSelecionarFoto.setAttribute('listener', 'attached');
                    console.log('✅ Botão registrado com sucesso!');
                }
                
                // Registrar input file se ainda não tiver listener
                if (!fotoListenersJaRegistrados) {
                    console.log('Registrando input file...');
                    initFotoListeners(true); // Forçar registro
                }
            } else {
                console.warn('⚠️ Elementos não encontrados ao abrir modal:', {
                    fotoInput: !!fotoInput,
                    btnSelecionarFoto: !!btnSelecionarFoto
                });
                // Tentar novamente após mais delay
                setTimeout(() => {
                    const fotoInput2 = document.getElementById('fotoInput');
                    const btnSelecionarFoto2 = document.getElementById('btnSelecionarFoto');
                    if (fotoInput2 && btnSelecionarFoto2) {
                        console.log('✅ Elementos encontrados na segunda tentativa!');
                        if (btnSelecionarFoto2.getAttribute('listener') !== 'attached') {
                            btnSelecionarFoto2.addEventListener('click', function(e) {
                                e.preventDefault();
                                e.stopPropagation();
                                console.log('🔘 Botão clicado (segunda tentativa)');
                                fotoInput2.click();
                            });
                            btnSelecionarFoto2.setAttribute('listener', 'attached');
                        }
                        if (!fotoListenersJaRegistrados) {
                            initFotoListeners(true);
                        }
                    }
                }, 300);
            }
            
            if (!previewListenersJaRegistrados) {
                console.log('Tentando registrar listeners de preview...');
                initPreviewListeners();
            }
        }, 200); // Aumentar delay para garantir que modal está renderizado
    }
}

function updateFotoPreview(fotoPath) {
    console.log('updateFotoPreview chamado com:', fotoPath ? 'path fornecido' : 'sem path');
    const previewImg = document.getElementById('fotoPreviewImg');
    const previewText = document.getElementById('fotoPreviewText');
    const preview = document.getElementById('fotoPreview');
    const overlay = document.getElementById('fotoEditOverlay');
    
    console.log('Elementos encontrados:', {
        previewImg: !!previewImg,
        previewText: !!previewText,
        preview: !!preview,
        overlay: !!overlay
    });
    
    if (fotoPath && previewImg && previewText && preview) {
        console.log('Atualizando preview com foto...');
        previewImg.src = fotoPath;
        previewImg.style.display = 'block';
        previewText.style.display = 'none';
        preview.style.backgroundImage = 'url(' + fotoPath + ')';
        preview.style.backgroundSize = 'cover';
        preview.style.backgroundPosition = 'center';
        if (overlay) overlay.style.display = 'none'; // Esconder overlay inicialmente
        console.log('✅ Preview atualizado com sucesso');
    } else {
        console.log('Limpando preview...');
        if (previewImg) previewImg.style.display = 'none';
        if (previewText) previewText.style.display = 'block';
        if (preview) {
            preview.style.backgroundImage = 'none';
            preview.style.background = '#f8fafc';
        }
        if (overlay) overlay.style.display = 'none';
    }
}

(() => {
    const form = document.getElementById('userForm');
    const senhaInput = document.getElementById('senhaInput');
    const alterarSenhaInput = document.getElementById('alterarSenhaInput');
    const alterarSenhaToggle = document.getElementById('alterarSenhaToggle');

    if (!form || !senhaInput || !alterarSenhaInput || !alterarSenhaToggle) {
        return;
    }

    alterarSenhaToggle.addEventListener('change', () => {
        const enabled = alterarSenhaToggle.checked;
        alterarSenhaInput.value = enabled ? '1' : '0';
        senhaInput.value = '';

        if (form.dataset.mode === 'edit') {
            senhaInput.disabled = !enabled;
            if (enabled) {
                senhaInput.setAttribute('required', 'required');
                senhaInput.focus();
            } else {
                senhaInput.removeAttribute('required');
            }
        }
    });

    form.addEventListener('submit', () => {
        if (form.dataset.mode === 'edit' && alterarSenhaInput.value !== '1') {
            senhaInput.value = '';
            senhaInput.disabled = true;
        }
    });
})();

function closeModal() {
    document.getElementById('userModal').classList.remove('active');
}

function loadUserData(userId) {
    const modal = document.getElementById('userModal');
    const form = document.getElementById('userForm');
    
    if (!modal || !form) {
        console.error('Modal ou formulário não encontrado');
        return;
    }
    
    // Verificar se o modal-body existe e tem conteúdo
    const modalBody = form.querySelector('.modal-body');
    if (!modalBody) {
        console.error('modal-body não encontrado no formulário!');
        alert('Erro: Estrutura do modal inválida. Recarregue a página.');
        return;
    }
    
    // Mostrar loading
    const originalBody = modalBody.innerHTML;
    console.log('[EDITAR] originalBody capturado, tamanho:', originalBody.length, 'caracteres');
    console.log('[EDITAR] originalBody contém fotoInput?', originalBody.includes('id="fotoInput"'));
    console.log('[EDITAR] originalBody contém btnSelecionarFoto?', originalBody.includes('id="btnSelecionarFoto"'));
    
    modalBody.innerHTML = '<div style="padding: 2rem; text-align: center; color: #64748b;">Carregando dados do usuário...</div>';
    
    fetch('index.php?page=usuarios&action=get_user&id=' + userId, {
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
        },
        credentials: 'same-origin'
    })
    .then(response => {
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            return response.text().then(text => {
                throw new Error('Resposta não é JSON: ' + text.substring(0, 100));
            });
        }
        return response.json();
    })
    .then(data => {
        // IMPORTANTE: Salvar referência do arquivo antes de restaurar HTML (se houver)
        const fotoInputAntes = document.getElementById('fotoInput');
        let arquivoAnterior = null;
        if (fotoInputAntes && fotoInputAntes.files && fotoInputAntes.files.length > 0) {
            arquivoAnterior = fotoInputAntes.files[0];
            console.log('[EDITAR] 💾 Arquivo encontrado antes de restaurar HTML, salvando referência:', arquivoAnterior.name, 'tamanho:', arquivoAnterior.size);
        }
        
        // Verificar se originalBody tem os elementos necessários
        if (!originalBody || originalBody.length === 0) {
            console.error('[EDITAR] ❌ originalBody está vazio ou não existe!');
            alert('Erro: Não foi possível restaurar o formulário. Recarregue a página.');
            return;
        }
        
        // Verificar se originalBody contém os elementos necessários
        if (!originalBody.includes('id="fotoInput"')) {
            console.error('[EDITAR] ❌ originalBody não contém fotoInput!');
            console.log('[EDITAR] Primeiros 500 caracteres do originalBody:', originalBody.substring(0, 500));
        }
        if (!originalBody.includes('id="btnSelecionarFoto"')) {
            console.error('[EDITAR] ❌ originalBody não contém btnSelecionarFoto!');
        }
        
        // Restaurar formulário
        const modalBody = form.querySelector('.modal-body');
        if (!modalBody) {
            console.error('[EDITAR] ❌ modal-body não encontrado após carregar dados!');
            return;
        }
        
        modalBody.innerHTML = originalBody;
        console.log('[EDITAR] ✅ HTML restaurado, tamanho:', originalBody.length, 'caracteres');
        
        // Verificar imediatamente se elementos foram criados
        setTimeout(() => {
            const fotoInputTeste = document.getElementById('fotoInput');
            const btnSelecionarFotoTeste = document.getElementById('btnSelecionarFoto');
            console.log('[EDITAR] Verificação imediata após restaurar HTML:', {
                fotoInput: !!fotoInputTeste,
                btnSelecionarFoto: !!btnSelecionarFotoTeste,
                modalBody: !!modalBody
            });
        }, 10);
        
        // Restaurar arquivo se houver
        if (arquivoAnterior) {
            setTimeout(() => {
                const fotoInputDepois = document.getElementById('fotoInput');
                if (fotoInputDepois) {
                    try {
                        const dataTransfer = new DataTransfer();
                        dataTransfer.items.add(arquivoAnterior);
                        fotoInputDepois.files = dataTransfer.files;
                        console.log('[EDITAR] ✅ Arquivo restaurado após HTML ser recriado:', fotoInputDepois.files.length, 'arquivo(s)');
                    } catch (error) {
                        console.error('[EDITAR] ❌ Erro ao restaurar arquivo:', error);
                    }
                }
            }, 50);
        }
        
        if (data.success && data.user) {
            const user = data.user;
            
            // Preencher campos básicos (aba Usuário)
            const nomeInput = form.querySelector('[name="nome"]');
            const loginInput = form.querySelector('[name="login"]');
            const emailInput = form.querySelector('[name="email"]');
            const cargoInput = form.querySelector('[name="cargo"]');
            const fotoAtualInput = document.getElementById('fotoAtual');
            
            // Unificar nomes: mesmo valor em "Nome" e "Nome Completo"
            const nomeUnificado = (user.nome || user.nome_completo || '').trim();
            if (nomeInput) nomeInput.value = nomeUnificado;
            
            if (loginInput) loginInput.value = user.login || user.email || '';
            if (emailInput) emailInput.value = user.email || '';
            populateCargoSelect(user.cargo || '');
            if (cargoInput) cargoInput.value = user.cargo || '';
            if (fotoAtualInput) fotoAtualInput.value = user.foto || '';
            const fotoUrlInput = document.getElementById('fotoUrl');
            if (fotoUrlInput) fotoUrlInput.value = user.foto || '';
            
            // Preencher campos de dados pessoais (aba Dados)
            const nomeCompletoInput = form.querySelector('[name="nome_completo"]');
            const cpfInput = form.querySelector('[name="cpf"]');
            const rgInput = form.querySelector('[name="rg"]');
            const telefoneInput = form.querySelector('[name="telefone"]');
            const celularInput = form.querySelector('[name="celular"]');
            const cepInput = form.querySelector('[name="endereco_cep"]');
            const logradouroInput = form.querySelector('[name="endereco_logradouro"]');
            const numeroInput = form.querySelector('[name="endereco_numero"]');
            const complementoInput = form.querySelector('[name="endereco_complemento"]');
            const bairroInput = form.querySelector('[name="endereco_bairro"]');
            const cidadeInput = form.querySelector('[name="endereco_cidade"]');
            const estadoInput = form.querySelector('[name="endereco_estado"]');
            
            if (nomeCompletoInput) nomeCompletoInput.value = nomeUnificado;
            if (cpfInput) cpfInput.value = user.cpf || '';
            if (rgInput) rgInput.value = user.rg || '';
            if (telefoneInput) telefoneInput.value = user.telefone || '';
            if (celularInput) celularInput.value = user.celular || '';
            if (cepInput) cepInput.value = user.endereco_cep || '';
            if (logradouroInput) logradouroInput.value = user.endereco_logradouro || '';
            if (numeroInput) numeroInput.value = user.endereco_numero || '';
            if (complementoInput) complementoInput.value = user.endereco_complemento || '';
            if (bairroInput) bairroInput.value = user.endereco_bairro || '';
            if (cidadeInput) cidadeInput.value = user.endereco_cidade || '';
            if (estadoInput) estadoInput.value = user.endereco_estado || '';
            renderUnidadesCheckboxes(Array.isArray(user.spaces_visiveis) ? user.spaces_visiveis : []);
            
            // Atualizar preview da foto
            if (user.foto) {
                updateFotoPreview(user.foto);
            } else {
                updateFotoPreview('');
            }
            
            // Permissões - marcar checkboxes
            form.querySelectorAll('input[type="checkbox"]').forEach(cb => {
                const name = cb.name;
                // Converter valor para boolean
                const value = user[name];
                cb.checked = value === true || value === 1 || value === '1' || value === 't' || value === 'true';
            });
            
            // IMPORTANTE: HTML foi restaurado (innerHTML = originalBody), então os elementos foram RECRIADOS
            // Precisamos resetar as flags e registrar os listeners DEPOIS que o DOM foi atualizado
            // Função auxiliar para tentar registrar listeners com múltiplas tentativas
            function tentarRegistrarListenersFotoEditar(tentativa = 1, maxTentativas = 10) {
                const fotoInput = document.getElementById('fotoInput');
                const btnSelecionarFoto = document.getElementById('btnSelecionarFoto');
                
                console.log(`[EDITAR] Tentativa ${tentativa}/${maxTentativas} - Elementos encontrados:`, {
                    fotoInput: !!fotoInput,
                    btnSelecionarFoto: !!btnSelecionarFoto,
                    modal: !!modal
                });
                
                if (fotoInput && btnSelecionarFoto) {
                    console.log('[EDITAR] ✅ Elementos encontrados! Registrando listeners...');
                    
                    // Resetar flags porque elementos foram recriados
                    fotoListenersJaRegistrados = false;
                    previewListenersJaRegistrados = false;
                    
                    // Registrar botão (sempre registrar porque elementos foram recriados)
                    if (btnSelecionarFoto.getAttribute('listener') !== 'attached') {
                        console.log('[EDITAR] Registrando botão Selecionar Foto...');
                        btnSelecionarFoto.addEventListener('click', function(e) {
                            e.preventDefault();
                            e.stopPropagation();
                            console.log('[EDITAR] 🔘 Botão Selecionar Foto clicado!');
                            const fotoInputNow = document.getElementById('fotoInput');
                            if (fotoInputNow) {
                                console.log('[EDITAR] Abrindo seletor de arquivo...');
                                fotoInputNow.click();
                            } else {
                                console.error('[EDITAR] ❌ fotoInput não encontrado ao clicar!');
                            }
                        });
                        btnSelecionarFoto.setAttribute('listener', 'attached');
                        console.log('[EDITAR] ✅ Botão registrado com sucesso!');
                    } else {
                        console.log('[EDITAR] Botão já tem listener, mas re-registrando...');
                        // Mesmo assim, re-registrar para garantir
                        btnSelecionarFoto.addEventListener('click', function(e) {
                            e.preventDefault();
                            e.stopPropagation();
                            console.log('[EDITAR] 🔘 Botão Selecionar Foto clicado (re-registrado)!');
                            const fotoInputNow = document.getElementById('fotoInput');
                            if (fotoInputNow) {
                                fotoInputNow.click();
                            }
                        });
                    }
                    
                    // Registrar input file (sempre registrar porque elementos foram recriados)
                    console.log('[EDITAR] Registrando input file...');
                    initFotoListeners(true); // Forçar registro
                    
                    // Registrar preview listeners
                    console.log('[EDITAR] Registrando listeners de preview...');
                    initPreviewListeners();
                    
                    return true; // Sucesso
                } else {
                    if (tentativa < maxTentativas) {
                        console.warn(`[EDITAR] ⚠️ Elementos não encontrados (tentativa ${tentativa}), tentando novamente em ${tentativa * 100}ms...`);
                        setTimeout(() => {
                            tentarRegistrarListenersFotoEditar(tentativa + 1, maxTentativas);
                        }, tentativa * 100); // Delay crescente (100ms, 200ms, 300ms, etc.)
                    } else {
                        console.error('[EDITAR] ❌ Elementos não encontrados após todas as tentativas!');
                    }
                    return false;
                }
            }
            
            // Usar requestAnimationFrame + múltiplas tentativas
            requestAnimationFrame(() => {
                setTimeout(() => {
                    tentarRegistrarListenersFotoEditar(1, 10);
                }, 100);
            });
        } else {
            alert('Erro ao carregar usuário: ' + (data.message || 'Usuário não encontrado'));
            form.querySelector('.modal-body').innerHTML = originalBody;
        }
    })
    .catch(error => {
        console.error('Erro:', error);
        alert('Erro ao carregar dados do usuário: ' + error.message);
        form.querySelector('.modal-body').innerHTML = originalBody;
    });
}

function deleteUser(userId) {
    if (!confirm('Tem certeza que deseja excluir este usuário?\n\nEsta ação não pode ser desfeita.')) {
        return;
    }
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'index.php?page=usuarios';
    
    const actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = 'action';
    actionInput.value = 'delete';
    form.appendChild(actionInput);
    
    const userIdInput = document.createElement('input');
    userIdInput.type = 'hidden';
    userIdInput.name = 'user_id';
    userIdInput.value = userId;
    form.appendChild(userIdInput);
    
    document.body.appendChild(form);
    form.submit();
}

// Variáveis globais para o editor de foto
let fotoCropper = null;
let fotoOriginalBlob = null;

// Carregar biblioteca Cropper.js via CDN
function loadCropperLibrary() {
    return new Promise((resolve, reject) => {
        // Verificar se já está carregado
        if (window.Cropper) {
            resolve();
            return;
        }
        
        // Carregar CSS
        const cssLink = document.createElement('link');
        cssLink.rel = 'stylesheet';
        cssLink.href = 'https://cdn.jsdelivr.net/npm/cropperjs@1.5.13/dist/cropper.min.css';
        document.head.appendChild(cssLink);
        
        // Carregar JS
        const jsScript = document.createElement('script');
        jsScript.src = 'https://cdn.jsdelivr.net/npm/cropperjs@1.5.13/dist/cropper.min.js';
        jsScript.onload = () => resolve();
        jsScript.onerror = () => reject(new Error('Erro ao carregar Cropper.js'));
        document.body.appendChild(jsScript);
    });
}

// Função para inicializar event listeners da foto
let fotoListenersJaRegistrados = false;

function initFotoListeners(force = false) {
    // Evitar registrar múltiplas vezes, mas permitir forçar se necessário
    if (fotoListenersJaRegistrados && !force) {
        console.log('Listeners de foto já registrados');
        return;
    }
    
    const fotoInput = document.getElementById('fotoInput');
    const btnSelecionarFoto = document.getElementById('btnSelecionarFoto');
    
    if (!fotoInput || !btnSelecionarFoto) {
        // Não mostrar warning se não estiver no modal (página recarregada)
        const modal = document.getElementById('userModal');
        const isModalOpen = modal && modal.classList.contains('active');
        if (!isModalOpen) {
            // Modal não está aberto, provavelmente página recarregada - não mostrar warning
            return;
        }
        
        // Modal está aberto mas elementos não encontrados - mostrar warning apenas uma vez
        if (!window._fotoListenersWarningShown) {
            console.warn('Elementos não encontrados ainda (modal pode estar carregando):', {
                fotoInput: !!fotoInput,
                btnSelecionarFoto: !!btnSelecionarFoto,
                modalActive: isModalOpen
            });
            window._fotoListenersWarningShown = true;
            // Resetar após 2 segundos
            setTimeout(() => {
                window._fotoListenersWarningShown = false;
            }, 2000);
        }
        return;
    }
    
    // Se já foram registrados, não registrar novamente
    if (fotoListenersJaRegistrados && !force) {
        return;
    }
    
    console.log('✅ Elementos encontrados, registrando listeners...');
    fotoListenersJaRegistrados = true;
    
    // Registrar botão de selecionar foto (já foi verificado acima)
    if (btnSelecionarFoto) {
        // Verificar se já tem listener para evitar duplicação
        if (btnSelecionarFoto.getAttribute('listener') === 'attached') {
            console.log('Botão já tem listener, pulando...');
        } else {
            // Remover listener anterior se existir (clonando e substituindo)
            try {
                const newBtn = btnSelecionarFoto.cloneNode(true);
                btnSelecionarFoto.parentNode.replaceChild(newBtn, btnSelecionarFoto);
                const btnSelecionarFotoNew = document.getElementById('btnSelecionarFoto');
                
                if (btnSelecionarFotoNew) {
                    btnSelecionarFotoNew.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        console.log('🔘 Botão Selecionar Foto clicado (initFotoListeners)');
                        const fotoInputNow = document.getElementById('fotoInput');
                        if (fotoInputNow) {
                            console.log('Abrindo seletor de arquivo...');
                            fotoInputNow.click();
                        } else {
                            console.error('❌ fotoInput não encontrado ao clicar no botão!');
                        }
                    });
                    btnSelecionarFotoNew.setAttribute('listener', 'attached');
                    console.log('✅ Botão Selecionar Foto registrado em initFotoListeners');
                }
            } catch (error) {
                console.error('Erro ao registrar botão:', error);
                // Fallback: tentar registrar diretamente
                btnSelecionarFoto.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    console.log('🔘 Botão clicado (fallback)');
                    fotoInput.click();
                });
                btnSelecionarFoto.setAttribute('listener', 'attached');
            }
        }
    }
    
    // NOVA ABORDAGEM: Upload via AJAX imediatamente quando foto é selecionada (como Trello)
    fotoInput.addEventListener('change', async function(e) {
        console.log('🔔 EVENTO CHANGE DISPARADO - NOVA ABORDAGEM AJAX');
        
        const file = e.target.files[0];
        if (!file) {
            console.log('Nenhum arquivo selecionado');
            const fotoAtual = document.getElementById('fotoAtual');
            if (fotoAtual && fotoAtual.value) {
                updateFotoPreview(fotoAtual.value);
            } else {
                updateFotoPreview('');
            }
            return;
        }
        
        console.log('✅ Arquivo encontrado:', file.name, 'tipo:', file.type, 'tamanho:', file.size, 'bytes');
        
        // Validar tipo
        if (!file.type.match('image.*')) {
            alert('Por favor, selecione uma imagem (JPG, PNG ou GIF)');
            e.target.value = '';
            return;
        }
        
        // Validar tamanho (10MB - mesmo do Trello)
        if (file.size > 10 * 1024 * 1024) {
            alert('Arquivo muito grande. Tamanho máximo: 10MB');
            e.target.value = '';
            return;
        }
        
        // MOSTRAR PREVIEW IMEDIATAMENTE
        const reader = new FileReader();
        reader.onload = function(e) {
            const previewUrl = e.target.result;
            updateFotoPreview(previewUrl);
        };
        reader.readAsDataURL(file);
        
        // MOSTRAR INDICADOR DE UPLOAD
        const fotoUploading = document.getElementById('fotoUploading');
        if (fotoUploading) {
            fotoUploading.style.display = 'flex';
        }
        
        // FAZER UPLOAD VIA AJAX IMEDIATAMENTE (como Trello)
        const formData = new FormData();
        formData.append('foto', file);
        const userIdInput = document.getElementById('userId');
        const editingUserId = userIdInput ? parseInt(userIdInput.value || '0', 10) : 0;
        if (editingUserId > 0) {
            formData.append('user_id', String(editingUserId));
        }
        
        console.log('📤 Iniciando upload AJAX para endpoint dedicado...');
        
        try {
            // Usar rota via index.php para garantir que passa por todas as verificações
            const response = await fetch('index.php?page=upload_foto_usuario_endpoint', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            
            // Verificar status HTTP
            if (!response.ok) {
                const text = await response.text();
                console.error('❌ Resposta HTTP não OK! Status:', response.status);
                console.error('❌ Resposta (primeiros 500 chars):', text.substring(0, 500));
                throw new Error('Erro no servidor. Status: ' + response.status);
            }
            
            // Tentar fazer parse do JSON
            let result;
            let responseText = '';
            try {
                const contentType = response.headers.get('content-type');
                responseText = await response.text();
                console.log('📥 Resposta recebida, tamanho:', responseText.length, 'chars');
                console.log('📥 Content-Type:', contentType);
                console.log('📥 Primeiros 200 chars:', responseText.substring(0, 200));
                
                if (!contentType || !contentType.includes('application/json')) {
                    // Se não for JSON, tentar fazer parse mesmo assim (pode ter charset extra)
                    console.warn('⚠️ Content-Type não é JSON, mas tentando fazer parse:', contentType);
                    result = JSON.parse(responseText);
                } else {
                    result = JSON.parse(responseText);
                }
                console.log('✅ JSON parseado com sucesso:', result);
            } catch (parseError) {
                // Se o parse falhar, tentar extrair JSON da resposta
                console.warn('⚠️ Erro ao fazer parse do JSON, tentando extrair JSON da resposta:', parseError.message);
                console.warn('⚠️ Texto da resposta (primeiros 500 chars):', responseText.substring(0, 500));
                
                // Tentar encontrar JSON na resposta (pode ter output extra antes/depois)
                const jsonMatch = responseText.match(/\{[\s\S]*\}/);
                if (jsonMatch) {
                    try {
                        result = JSON.parse(jsonMatch[0]);
                        console.log('✅ JSON extraído com sucesso da resposta!', result);
                    } catch (e) {
                        console.error('❌ Erro ao fazer parse do JSON extraído:', e);
                        console.error('❌ JSON extraído (primeiros 200 chars):', jsonMatch[0].substring(0, 200));
                        throw new Error('Erro ao processar resposta do servidor: ' + e.message);
                    }
                } else {
                    console.error('❌ Nenhum JSON encontrado na resposta');
                    console.error('❌ Resposta completa (primeiros 1000 chars):', responseText.substring(0, 1000));
                    throw new Error('Resposta do servidor não contém JSON válido');
                }
            }
            
            // Log detalhado do resultado
            console.log('📊 Resultado processado:', {
                success: result?.success,
                hasData: !!result?.data,
                hasUrl: !!result?.data?.url,
                url: result?.data?.url?.substring(0, 100) + '...'
            });
            
            // Esconder indicador de upload
            if (fotoUploading) {
                fotoUploading.style.display = 'none';
            }
            
            // Verificar se upload foi bem-sucedido
            if (result && result.success && result.data && result.data.url) {
                console.log('✅ Upload bem-sucedido! URL:', result.data.url);
                
                // Salvar URL no campo hidden (será enviado quando salvar usuário)
                const fotoUrlInput = document.getElementById('fotoUrl');
                if (fotoUrlInput) {
                    fotoUrlInput.value = result.data.url;
                    console.log('✅ URL salva no campo hidden:', result.data.url);
                } else {
                    console.error('❌ Campo fotoUrl não encontrado!');
                }
                
                // Atualizar preview com URL do Magalu
                updateFotoPreview(result.data.url);
                
                // Mostrar mensagem de sucesso
                const fotoStatus = document.getElementById('fotoStatus');
                if (fotoStatus) {
                    fotoStatus.style.display = 'block';
                    fotoStatus.style.color = '#10b981';
                    fotoStatus.textContent = '✅ Foto enviada com sucesso!';
                    setTimeout(() => {
                        fotoStatus.style.display = 'none';
                    }, 3000);
                }
                
                // Limpar input file (já foi enviado)
                e.target.value = '';
                
                console.log('✅ Foto processada e pronta para salvar!');
            } else if (result && result.data && result.data.url) {
                // Fallback: se tiver URL mesmo sem success=true, considerar sucesso
                console.log('✅ Upload bem-sucedido (fallback)! URL:', result.data.url);
                
                const fotoUrlInput = document.getElementById('fotoUrl');
                if (fotoUrlInput) {
                    fotoUrlInput.value = result.data.url;
                }
                
                updateFotoPreview(result.data.url);
                
                const fotoStatus = document.getElementById('fotoStatus');
                if (fotoStatus) {
                    fotoStatus.style.display = 'block';
                    fotoStatus.style.color = '#10b981';
                    fotoStatus.textContent = '✅ Foto enviada com sucesso!';
                    setTimeout(() => {
                        fotoStatus.style.display = 'none';
                    }, 3000);
                }
                
                e.target.value = '';
            } else {
                console.error('❌ Upload falhou:', result?.error || result || 'Erro desconhecido');
                alert('Erro ao fazer upload da foto: ' + (result?.error || result || 'Erro desconhecido'));
                updateFotoPreview('');
                e.target.value = '';
            }
        } catch (error) {
            console.error('❌ Erro no upload AJAX:', error);
            alert('Erro ao fazer upload da foto: ' + error.message);
            
            // Esconder indicador de upload
            if (fotoUploading) {
                fotoUploading.style.display = 'none';
            }
            
            updateFotoPreview('');
            e.target.value = '';
        }
    });
    
    console.log('✅ Event listener de foto registrado com sucesso');
}

// Inicializar quando DOM estiver pronto - múltiplas tentativas
function iniciarFotoListeners() {
    // Tentar imediatamente
    initFotoListeners();
    
    // Tentar após delays
    setTimeout(initFotoListeners, 100);
    setTimeout(initFotoListeners, 500);
    setTimeout(initFotoListeners, 1000);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', iniciarFotoListeners);
} else {
    iniciarFotoListeners();
}

window.addEventListener('load', () => {
    setTimeout(initFotoListeners, 200);
});

// Abrir editor de foto
function abrirEditorFoto(imageSrc) {
    const modal = document.getElementById('fotoEditorModal');
    const img = document.getElementById('fotoEditorImg');
    
    if (!modal || !img) return;
    
    img.src = imageSrc;
    modal.style.display = 'flex';
    
    // Aguardar imagem carregar antes de inicializar cropper
    img.onload = function() {
        // Destruir cropper anterior se existir
        if (fotoCropper) {
            fotoCropper.destroy();
        }
        
        // Inicializar Cropper com configuração circular
        fotoCropper = new Cropper(img, {
            aspectRatio: 1, // Quadrado/circular
            viewMode: 1,
            dragMode: 'move',
            autoCropArea: 0.8,
            restore: false,
            guides: true,
            center: true,
            highlight: false,
            cropBoxMovable: true,
            cropBoxResizable: true,
            toggleDragModeOnDblclick: false,
            minCropBoxWidth: 100,
            minCropBoxHeight: 100,
            ready: function() {
                // Garantir que o crop seja circular
                this.cropper.setAspectRatio(1);
            }
        });
    };
}

// Fechar editor de foto
function fecharEditorFoto() {
    const modal = document.getElementById('fotoEditorModal');
    if (modal) {
        modal.style.display = 'none';
    }
    
    if (fotoCropper) {
        fotoCropper.destroy();
        fotoCropper = null;
    }
}

// Zoom in
function fotoEditorZoomIn() {
    if (fotoCropper) {
        fotoCropper.zoom(0.1);
    }
}

// Zoom out
function fotoEditorZoomOut() {
    if (fotoCropper) {
        fotoCropper.zoom(-0.1);
    }
}

// Rotacionar
function fotoEditorRotate() {
    if (fotoCropper) {
        fotoCropper.rotate(90);
    }
}

// Resetar
function fotoEditorReset() {
    if (fotoCropper) {
        fotoCropper.reset();
    }
}

// Aplicar edição e atualizar preview
function aplicarEdicaoFoto() {
    if (!fotoCropper) {
        fecharEditorFoto();
        return;
    }
    
    // Obter canvas com a imagem cortada (circular)
    const canvas = fotoCropper.getCroppedCanvas({
        width: 400,
        height: 400,
        imageSmoothingEnabled: true,
        imageSmoothingQuality: 'high'
    });
    
    if (canvas) {
        // Converter canvas para blob
        canvas.toBlob(function(blob) {
            if (blob) {
                // Criar URL do blob para preview
                const blobUrl = URL.createObjectURL(blob);
                updateFotoPreview(blobUrl);
                console.log('Preview atualizado após edição');
                
                // Salvar blob para upload
                fotoOriginalBlob = blob;
                
                // IMPORTANTE: Converter blob para File e garantir que seja enviado corretamente
                const file = new File([blob], 'foto_usuario.jpg', { type: 'image/jpeg', lastModified: Date.now() });
                console.log('📸 File criado a partir do blob:', {
                    name: file.name,
                    type: file.type,
                    size: file.size,
                    lastModified: file.lastModified,
                    isFile: file instanceof File,
                    isBlob: file instanceof Blob
                });
                
                // Criar DataTransfer para substituir o arquivo do input
                try {
                    const dataTransfer = new DataTransfer();
                    const added = dataTransfer.items.add(file);
                    console.log('📸 Arquivo adicionado ao DataTransfer:', added);
                    
                    const fotoInput = document.getElementById('fotoInput');
                    if (fotoInput) {
                        // IMPORTANTE: Substituir files diretamente
                        fotoInput.files = dataTransfer.files;
                        
                        console.log('✅ Arquivo atualizado no input file. Total:', fotoInput.files.length, 'arquivo(s)');
                        console.log('✅ Verificação após atualizar:', {
                            hasFiles: !!fotoInput.files,
                            filesLength: fotoInput.files?.length || 0,
                            firstFile: fotoInput.files?.[0] ? {
                                name: fotoInput.files[0].name,
                                type: fotoInput.files[0].type,
                                size: fotoInput.files[0].size,
                                isFile: fotoInput.files[0] instanceof File,
                                isBlob: fotoInput.files[0] instanceof Blob
                            } : null,
                            inputValue: fotoInput.value || '(vazio)'
                        });
                        
                        // Disparar evento change para garantir que o formulário detecte o arquivo
                        const changeEvent = new Event('change', { bubbles: true, cancelable: true });
                        fotoInput.dispatchEvent(changeEvent);
                        console.log('✅ Evento change disparado no fotoInput');
                        
                        // Verificação final: garantir que o arquivo está realmente no input
                        setTimeout(() => {
                            const fotoInputCheck = document.getElementById('fotoInput');
                            if (fotoInputCheck && fotoInputCheck.files && fotoInputCheck.files.length > 0) {
                                console.log('✅ VERIFICAÇÃO FINAL: Arquivo ainda está no input após 100ms:', {
                                    name: fotoInputCheck.files[0].name,
                                    size: fotoInputCheck.files[0].size
                                });
                            } else {
                                console.error('❌ VERIFICAÇÃO FINAL: Arquivo PERDIDO do input após 100ms!');
                            }
                        }, 100);
                    } else {
                        console.error('❌ fotoInput não encontrado ao tentar atualizar arquivo!');
                    }
                } catch (error) {
                    console.error('❌ Erro ao atualizar arquivo no input:', error);
                    console.error('❌ Stack trace:', error.stack);
                    
                    // Fallback: tentar método alternativo usando FormData
                    console.log('⚠️ Tentando método alternativo: salvar blob em FormData...');
                    const fotoInput = document.getElementById('fotoInput');
                    if (fotoInput) {
                        // Criar um novo input file e substituir
                        const newInput = document.createElement('input');
                        newInput.type = 'file';
                        newInput.name = 'foto';
                        newInput.id = 'fotoInput';
                        newInput.accept = 'image/*';
                        newInput.style.display = 'none';
                        
                        // Tentar adicionar o arquivo ao novo input
                        const form = document.getElementById('userForm');
                        if (form && fotoInput.parentNode) {
                            fotoInput.parentNode.replaceChild(newInput, fotoInput);
                            console.log('⚠️ Input substituído, mas arquivo pode não estar disponível');
                        }
                    }
                }
                
                // Salvar também como base64 no campo hidden para backup (não usado mais, mas mantido para debug)
                const fotoEditadaInput = document.getElementById('fotoEditada');
                if (fotoEditadaInput) {
                    canvas.toBlob(function(blob) {
                        const reader = new FileReader();
                        reader.onload = function() {
                            fotoEditadaInput.value = reader.result.substring(0, 100) + '...'; // Apenas primeiros 100 chars para debug
                            console.log('✅ Foto editada salva no campo hidden (primeiros 100 chars)');
                        };
                        reader.readAsDataURL(blob);
                    }, 'image/jpeg', 0.9);
                }
            } else {
                console.error('Erro: blob é null');
            }
            
            fecharEditorFoto();
        }, 'image/jpeg', 0.9);
    } else {
        console.error('Erro: canvas é null');
        fecharEditorFoto();
    }
}

// Função para inicializar eventos do preview
let previewListenersJaRegistrados = false;

function initPreviewListeners() {
    if (previewListenersJaRegistrados) {
        return;
    }
    
    const fotoPreview = document.getElementById('fotoPreview');
    if (!fotoPreview) {
        return;
    }
    
    previewListenersJaRegistrados = true;
    
    fotoPreview.addEventListener('mouseenter', function() {
        const overlay = document.getElementById('fotoEditOverlay');
        if (overlay) {
            const previewImg = document.getElementById('fotoPreviewImg');
            if (previewImg && previewImg.style.display !== 'none') {
                overlay.style.display = 'flex';
            }
        }
    });
    
    fotoPreview.addEventListener('mouseleave', function() {
        const overlay = document.getElementById('fotoEditOverlay');
        if (overlay) overlay.style.display = 'none';
    });
    
    fotoPreview.addEventListener('click', async function() {
        const fotoAtualInput = document.getElementById('fotoAtual');
        const previewImg = document.getElementById('fotoPreviewImg');
        
        if (previewImg && previewImg.src && previewImg.style.display !== 'none') {
            // Carregar biblioteca se necessário
            try {
                await loadCropperLibrary();
                abrirEditorFoto(previewImg.src);
            } catch (error) {
                console.error('Erro ao abrir editor:', error);
            }
        } else {
            // Se não houver foto, abrir seletor de arquivo
            const fotoInput = document.getElementById('fotoInput');
            if (fotoInput) {
                fotoInput.click();
            }
        }
    });
}

// Inicializar preview listeners
function iniciarPreviewListeners() {
    initPreviewListeners();
    setTimeout(initPreviewListeners, 100);
    setTimeout(initPreviewListeners, 500);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', iniciarPreviewListeners);
} else {
    iniciarPreviewListeners();
}

window.addEventListener('load', () => {
    renderCargoList();
    populateCargoSelect('');
    renderUnidadesCheckboxes([]);
    setTimeout(initPreviewListeners, 200);
});

// Validação simplificada - foto já foi enviada via AJAX

// Fechar modal de usuário ao clicar fora
const userModal = document.getElementById('userModal');
if (userModal) {
    userModal.addEventListener('click', function(e) {
        if (e.target === this) {
            closeModal();
        }
    });
}

const cargoModal = document.getElementById('cargoModal');
if (cargoModal) {
    cargoModal.addEventListener('click', function(e) {
        if (e.target === this) {
            closeCargoModal();
        }
    });
}

// Fechar editor de foto ao clicar fora ou pressionar ESC
const fotoEditorModal = document.getElementById('fotoEditorModal');
if (fotoEditorModal) {
    fotoEditorModal.addEventListener('click', function(e) {
        if (e.target === this) {
            fecharEditorFoto();
        }
    });
    
    // Fechar com ESC
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && fotoEditorModal.style.display === 'flex') {
            fecharEditorFoto();
        }
    });
}
</script>

<?php
$conteudo = ob_get_clean();
includeSidebar('Configurações');
echo $conteudo;
endSidebar();
?>
