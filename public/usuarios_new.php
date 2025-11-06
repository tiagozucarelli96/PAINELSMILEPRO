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
        // Buscar todas as colunas dinamicamente, incluindo foto
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
            
            // Debug: verificar foto
            error_log("DEBUG GET_USER: Foto do usuário ID $user_id: " . ($user['foto'] ?? 'NULL'));
            
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

// Upload de foto (AJAX)
if ($action === 'upload_foto') {
    while (ob_get_level() > 0) { ob_end_clean(); }
    
    if (empty($_SESSION['logado']) || empty($_SESSION['perm_configuracoes'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Acesso negado']);
        exit;
    }
    
    require_once __DIR__ . '/upload_foto_usuario.php';
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
        
        // Processar upload de foto se houver
        if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
            require_once __DIR__ . '/magalu_integration_helper.php';
            
            try {
                $magaluHelper = new MagaluIntegrationHelper();
                
                // Usar user_id temporário (0 se novo usuário, será atualizado depois)
                $tempUserId = $user_id > 0 ? $user_id : 999999; // ID temporário para novos usuários
                
                $resultado = $magaluHelper->uploadFotoUsuario($_FILES['foto'], $tempUserId);
                
                if ($resultado['sucesso']) {
                    // Salvar URL do Magalu no banco
                    $data['foto'] = $resultado['url'];
                    error_log("DEBUG FOTO: Foto salva no Magalu com URL: " . $data['foto']);
                    
                    // Se estiver editando e tinha foto anterior, remover do Magalu
                    if ($user_id > 0 && !empty($data['foto_atual']) && $data['foto_atual'] !== $data['foto']) {
                        // Verificar se é URL do Magalu (não local)
                        if (strpos($data['foto_atual'], 'magaluobjects.com') !== false || strpos($data['foto_atual'], 'http') === 0) {
                            try {
                                $magaluHelper->removerFotoUsuario($data['foto_atual']);
                                error_log("DEBUG FOTO: Foto anterior removida do Magalu");
                            } catch (Exception $e) {
                                error_log("AVISO FOTO: Erro ao remover foto anterior do Magalu: " . $e->getMessage());
                            }
                        }
                    }
                } else {
                    error_log("ERRO FOTO: Falha no upload para Magalu: " . ($resultado['erro'] ?? 'Erro desconhecido'));
                    throw new Exception('Erro ao fazer upload da foto: ' . ($resultado['erro'] ?? 'Erro desconhecido'));
                }
            } catch (Exception $e) {
                error_log("ERRO FOTO: Exceção ao processar upload: " . $e->getMessage());
                throw new Exception('Erro ao processar foto: ' . $e->getMessage());
            }
        } elseif (!empty($data['foto_atual'])) {
            // Manter foto atual se não houver novo upload
            $data['foto'] = $data['foto_atual'];
            error_log("DEBUG FOTO: Mantendo foto atual: " . $data['foto']);
        } else {
            // Se não houver foto e não houver foto_atual, garantir que não será enviado
            unset($data['foto']);
        }
        
        // Debug: verificar se foto está em $data antes de salvar
        error_log("DEBUG FOTO FINAL: Antes de salvar, data['foto'] = " . (isset($data['foto']) ? $data['foto'] : 'NÃO DEFINIDO'));
        
        $result = $manager->save($data, $user_id);
        
        // Debug: verificar se salvou e atualizar foto se for novo usuário
        if ($result['success'] && !empty($data['foto']) && $user_id === 0) {
            // Se foi um novo usuário, atualizar a foto com o ID correto
            try {
                $newUserId = $pdo->lastInsertId();
                if ($newUserId && strpos($data['foto'], 'magaluobjects.com') !== false) {
                    // Extrair key atual e recriar com ID correto
                    require_once __DIR__ . '/magalu_integration_helper.php';
                    $magaluHelper = new MagaluIntegrationHelper();
                    
                    // A URL já está salva, mas podemos atualizar a key se necessário
                    // Por enquanto, apenas logamos
                    error_log("DEBUG FOTO: Novo usuário criado com ID $newUserId, foto: " . $data['foto']);
                }
            } catch (Exception $e) {
                error_log("DEBUG FOTO: Erro ao processar foto de novo usuário: " . $e->getMessage());
            }
        }
        
        // Verificar foto no banco
        if ($result['success'] && !empty($data['foto'])) {
            error_log("DEBUG FOTO: Tentando verificar se foto foi salva para usuário ID " . ($user_id > 0 ? $user_id : 'NOVO'));
            try {
                $checkId = $user_id > 0 ? $user_id : $pdo->lastInsertId();
                if ($checkId) {
                    $stmtCheck = $pdo->prepare("SELECT foto FROM usuarios WHERE id = :id");
                    $stmtCheck->execute([':id' => $checkId]);
                    $fotoCheck = $stmtCheck->fetch(PDO::FETCH_ASSOC);
                    error_log("DEBUG FOTO: Foto no banco após salvar: " . ($fotoCheck['foto'] ?? 'NULL'));
                }
            } catch (Exception $e) {
                error_log("DEBUG FOTO: Erro ao verificar foto no banco: " . $e->getMessage());
            }
        }
        
        if ($result['success']) {
            $redirectUrl = 'index.php?page=usuarios&success=' . urlencode($user_id > 0 ? 'Usuário atualizado com sucesso!' : 'Usuário criado com sucesso!');
            header('Location: ' . $redirectUrl);
        } else {
            header('Location: index.php?page=usuarios&error=' . urlencode($result['message'] ?? 'Erro ao salvar'));
        }
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

// ============================================
// BUSCAR USUÁRIOS
// ============================================

$search = trim($_GET['search'] ?? '');
$sql = "SELECT id, nome, login, email, cargo, ativo, created_at";
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
    // Primeiro tentar com schema 'public'
    error_log("Buscando permissões - Estratégia 1: Com schema 'public'");
    $stmt = $pdo->query("SELECT column_name FROM information_schema.columns 
                         WHERE table_schema = 'public' AND table_name = 'usuarios' 
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
        align-items: center;
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
/* Estilos para o editor de foto */
.modal-foto-editor {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.75);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10000;
    padding: 1rem;
}

.modal-foto-editor-content {
    background: white;
    border-radius: 12px;
    max-width: 90vw;
    max-height: 90vh;
    width: 800px;
    display: flex;
    flex-direction: column;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
}

.modal-foto-editor-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1.5rem;
    border-bottom: 1px solid #e5e7eb;
}

.modal-foto-editor-header h3 {
    margin: 0;
    font-size: 1.25rem;
    font-weight: 600;
    color: #1e293b;
}

.btn-close-foto-editor {
    background: none;
    border: none;
    font-size: 2rem;
    color: #64748b;
    cursor: pointer;
    padding: 0;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 4px;
    transition: all 0.2s;
}

.btn-close-foto-editor:hover {
    background: #f1f5f9;
    color: #1e293b;
}

.modal-foto-editor-body {
    padding: 1.5rem;
    overflow: auto;
    flex: 1;
}

.modal-foto-editor-footer {
    display: flex;
    justify-content: flex-end;
    gap: 0.75rem;
    padding: 1.5rem;
    border-top: 1px solid #e5e7eb;
}

.foto-editor-controls .btn-sm {
    padding: 0.5rem 1rem;
    font-size: 0.875rem;
}

/* Estilos do Cropper.js customizados */
#fotoEditorContainer .cropper-container {
    max-width: 100%;
}

#fotoEditorContainer .cropper-view-box {
    border-radius: 50% !important;
    outline: none !important;
}

#fotoEditorContainer .cropper-face {
    border-radius: 50% !important;
}

/* Hover no preview */
#fotoPreview:hover #fotoEditOverlay {
    display: flex !important;
}

#fotoPreview {
    transition: transform 0.2s;
}

#fotoPreview:hover {
    transform: scale(1.05);
}
</style>

<!-- CSS do Cropper.js será carregado dinamicamente via JavaScript -->

<div class="usuarios-page">
    <div class="page-header">
        <div>
            <h1 class="page-title">Usuários e Colaboradores</h1>
            <p class="page-subtitle">Gerencie usuários, permissões e colaboradores do sistema</p>
        </div>
        <button class="btn-primary" onclick="openModal(0)">
            <span>+</span>
            <span>Novo Usuário</span>
        </button>
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
        <?php foreach ($usuarios as $user): 
            $permissoes_ativas = [];
            foreach ($existing_perms as $perm => $val) {
                if (!empty($user[$perm])) {
                    $permissoes_ativas[] = $perm;
                }
            }
        ?>
        <div class="user-card">
            <div class="user-header">
                <div class="user-avatar" style="background-image: <?= !empty($user['foto']) ? "url('" . htmlspecialchars($user['foto']) . "')" : 'none' ?>; background-size: cover; background-position: center; <?= !empty($user['foto']) ? 'color: transparent;' : '' ?>">
                    <?php if (empty($user['foto'])): ?>
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
        <form id="userForm" method="POST" action="index.php?page=usuarios" enctype="multipart/form-data" onsubmit="console.log('Formulário sendo submetido!'); return validarFormFoto(event);">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="user_id" id="userId" value="0">
            
            <div class="modal-body">
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
                    <input type="password" name="senha" id="senhaInput" class="form-input" required>
                    <small style="color: #64748b; font-size: 0.75rem; display: none;" id="senhaHint">(deixe em branco para não alterar)</small>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Cargo</label>
                    <input type="text" name="cargo" class="form-input">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Foto do Perfil</label>
                    <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                        <!-- Preview da foto (thumbnail) -->
                        <div id="fotoPreview" style="width: 120px; height: 120px; border-radius: 50%; border: 2px solid #e5e7eb; background: #f8fafc; display: flex; align-items: center; justify-content: center; overflow: hidden; margin-bottom: 0.5rem; cursor: pointer; position: relative;">
                            <img id="fotoPreviewImg" src="" alt="Preview" style="width: 100%; height: 100%; object-fit: cover; display: none;">
                            <span id="fotoPreviewText" style="color: #94a3b8; font-size: 2rem;">👤</span>
                            <div id="fotoEditOverlay" style="position: absolute; inset: 0; background: rgba(0,0,0,0.5); display: none; align-items: center; justify-content: center; color: white; font-size: 0.75rem; border-radius: 50%;">
                                ✏️ Editar
                            </div>
                        </div>
                        
                        <!-- Input de arquivo (oculto) -->
                        <input type="file" name="foto" id="fotoInput" accept="image/*" class="form-input" style="padding: 0.5rem; display: none;">
                        <button type="button" id="btnSelecionarFoto" class="btn btn-secondary" style="width: auto; padding: 0.5rem 1rem; font-size: 0.875rem;">
                            <span>📷</span>
                            <span>Selecionar Foto</span>
                        </button>
                        <small style="color: #64748b; font-size: 0.75rem;">Formatos aceitos: JPG, PNG, GIF. Tamanho máximo: 2MB</small>
                        <input type="hidden" name="foto_atual" id="fotoAtual">
                        <input type="hidden" name="foto_editada" id="fotoEditada">
                    </div>
                </div>
                
                <!-- Modal de Edição de Imagem -->
                <div id="fotoEditorModal" class="modal-foto-editor" style="display: none;">
                    <div class="modal-foto-editor-content">
                        <div class="modal-foto-editor-header">
                            <h3>Editar Foto de Perfil</h3>
                            <button type="button" onclick="fecharEditorFoto()" class="btn-close-foto-editor">×</button>
                        </div>
                        <div class="modal-foto-editor-body">
                            <div id="fotoEditorContainer" style="max-width: 100%; max-height: 500px; margin: 0 auto;">
                                <img id="fotoEditorImg" style="max-width: 100%; display: block;">
                            </div>
                            <div class="foto-editor-controls" style="margin-top: 1rem; display: flex; gap: 0.5rem; justify-content: center; flex-wrap: wrap;">
                                <button type="button" onclick="fotoEditorZoomIn()" class="btn btn-secondary btn-sm">🔍+ Zoom</button>
                                <button type="button" onclick="fotoEditorZoomOut()" class="btn btn-secondary btn-sm">🔍- Zoom</button>
                                <button type="button" onclick="fotoEditorRotate()" class="btn btn-secondary btn-sm">🔄 Girar</button>
                                <button type="button" onclick="fotoEditorReset()" class="btn btn-secondary btn-sm">↺ Resetar</button>
                            </div>
                        </div>
                        <div class="modal-foto-editor-footer">
                            <button type="button" onclick="fecharEditorFoto()" class="btn btn-secondary">Cancelar</button>
                            <button type="button" onclick="aplicarEdicaoFoto()" class="btn btn-primary">Aplicar Alterações</button>
                        </div>
                    </div>
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
                
                // Mapeamento de permissões com labels
                $perm_labels = [
                    'perm_agenda' => '📅 Agenda',
                    'perm_comercial' => '📋 Comercial',
                    'perm_logistico' => '📦 Logístico',
                    'perm_configuracoes' => '⚙️ Configurações',
                    'perm_cadastros' => '📝 Cadastros',
                    'perm_financeiro' => '💰 Financeiro',
                    'perm_administrativo' => '👥 Administrativo',
                    'perm_rh' => '👔 RH',
                    'perm_banco_smile' => '🏦 Banco Smile',
                    'perm_banco_smile_admin' => '🏦 Admin Banco Smile',
                    'perm_usuarios' => '👥 Usuários',
                    'perm_pagamentos' => '💳 Pagamentos',
                    'perm_tarefas' => '📋 Tarefas',
                    'perm_demandas' => '📋 Demandas',
                    'perm_portao' => '🚪 Portão',
                    'perm_notas_fiscais' => '📄 Notas Fiscais',
                    'perm_estoque_logistico' => '📦 Estoque',
                    'perm_dados_contrato' => '📋 Contratos',
                    'perm_uso_fiorino' => '🚐 Fiorino',
                    'perm_agenda_ver' => '👁️ Ver Agenda',
                    'perm_agenda_editar' => '✏️ Editar Agenda',
                    'perm_agenda_criar' => '➕ Criar Agenda',
                    'perm_agenda_excluir' => '🗑️ Excluir Agenda',
                    'perm_agenda_meus' => '📋 Meus Eventos',
                    'perm_agenda_relatorios' => '📊 Relatórios Agenda',
                    'perm_comercial_ver' => '👁️ Ver Comercial',
                    'perm_comercial_deg_editar' => '✏️ Editar Degustações',
                    'perm_comercial_deg_inscritos' => '👥 Inscritos',
                    'perm_comercial_conversao' => '💰 Conversão',
                    'perm_demandas_ver' => '👁️ Ver Demandas',
                    'perm_demandas_editar' => '✏️ Editar Demandas',
                    'perm_demandas_criar' => '➕ Criar Demandas',
                    'perm_demandas_excluir' => '🗑️ Excluir Demandas',
                    'perm_demandas_ver_produtividade' => '📊 Produtividade',
                    'perm_forcar_conflito' => '⚡ Forçar Conflito',
                    'perm_gerir_eventos_outros' => '👥 Eventos de Outros',
                    'perm_lista' => '📋 Lista',
                ];
                
                // Filtrar apenas permissões que existem no banco
                $available_perms = [];
                if (!empty($existing_perms) && is_array($existing_perms)) {
                    foreach ($existing_perms as $perm => $val) {
                        if (isset($perm_labels[$perm])) {
                            $available_perms[$perm] = $perm_labels[$perm];
                        } else {
                            // Se não tiver label, usar o nome da permissão formatado
                            $label = str_replace('perm_', '', $perm);
                            $label = ucwords(str_replace('_', ' ', $label));
                            $available_perms[$perm] = $label;
                        }
                    }
                }
                ?>
                
                <?php if (!empty($available_perms)): ?>
                <div class="permissions-section">
                    <h3 class="permissions-title">Permissões do Sistema</h3>
                    <div class="permissions-grid">
                        <?php foreach ($available_perms as $perm => $label): ?>
                        <div class="permission-item">
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
            
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal()">Cancelar</button>
                <button type="submit" class="btn-primary">Salvar</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(userId = 0) {
    const modal = document.getElementById('userModal');
    const form = document.getElementById('userForm');
    const title = document.getElementById('modalTitle');
    const userIdInput = document.getElementById('userId');
    
    if (!modal || !form || !title || !userIdInput) {
        console.error('Elementos do modal não encontrados');
        alert('Erro: Elementos do modal não encontrados. Recarregue a página.');
        return;
    }
    
    userId = parseInt(userId) || 0;
    
    if (userId > 0) {
        title.textContent = 'Editar Usuário';
        userIdInput.value = userId;
        
        // Ajustar label e required da senha
        const senhaLabel = document.getElementById('senhaLabel');
        const senhaInput = document.getElementById('senhaInput');
        const senhaHint = document.getElementById('senhaHint');
        if (senhaLabel) senhaLabel.textContent = 'Senha (deixe em branco para não alterar)';
        if (senhaInput) senhaInput.removeAttribute('required');
        if (senhaHint) senhaHint.style.display = 'block';
        
        loadUserData(userId);
    } else {
        title.textContent = 'Novo Usuário';
        userIdInput.value = 0;
        
        // Ajustar label e required da senha
        const senhaLabel = document.getElementById('senhaLabel');
        const senhaInput = document.getElementById('senhaInput');
        const senhaHint = document.getElementById('senhaHint');
        if (senhaLabel) senhaLabel.textContent = 'Senha *';
        if (senhaInput) senhaInput.setAttribute('required', 'required');
        if (senhaHint) senhaHint.style.display = 'none';
        
        form.reset();
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
        
        // Tentar re-inicializar listeners de foto quando modal abrir (caso não tenham sido encontrados antes)
        setTimeout(() => {
            console.log('Tentando registrar listeners de foto ao abrir modal...');
            // Sempre tentar registrar novamente quando modal abre (pode estar dentro do modal)
            const fotoInput = document.getElementById('fotoInput');
            const btnSelecionarFoto = document.getElementById('btnSelecionarFoto');
            if (fotoInput && btnSelecionarFoto) {
                if (!fotoListenersJaRegistrados) {
                    console.log('Elementos encontrados ao abrir modal, registrando listeners...');
                    initFotoListeners(true); // Forçar registro
                } else {
                    console.log('Listeners já registrados, mas verificando botão...');
                    // Verificar se o botão tem listener (pode ter sido perdido)
                    if (!btnSelecionarFoto.onclick && btnSelecionarFoto.getAttribute('listener') !== 'attached') {
                        console.log('Re-registrando botão...');
                        btnSelecionarFoto.addEventListener('click', function(e) {
                            e.preventDefault();
                            e.stopPropagation();
                            console.log('Botão Selecionar Foto clicado (re-registrado)');
                            if (fotoInput) {
                                fotoInput.click();
                            }
                        });
                        btnSelecionarFoto.setAttribute('listener', 'attached');
                    }
                }
            } else {
                console.warn('Elementos não encontrados ao abrir modal:', {
                    fotoInput: !!fotoInput,
                    btnSelecionarFoto: !!btnSelecionarFoto
                });
            }
            if (!previewListenersJaRegistrados) {
                console.log('Tentando registrar listeners de preview ao abrir modal...');
                initPreviewListeners();
            }
        }, 100);
        
        // Mostrar modal
        modal.classList.add('active');
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
    
    // Mostrar loading
    const originalBody = form.querySelector('.modal-body').innerHTML;
    form.querySelector('.modal-body').innerHTML = '<div style="padding: 2rem; text-align: center; color: #64748b;">Carregando dados do usuário...</div>';
    
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
        // Restaurar formulário
        form.querySelector('.modal-body').innerHTML = originalBody;
        
        if (data.success && data.user) {
            const user = data.user;
            
            // Preencher campos básicos
            const nomeInput = form.querySelector('[name="nome"]');
            const loginInput = form.querySelector('[name="login"]');
            const emailInput = form.querySelector('[name="email"]');
            const cargoInput = form.querySelector('[name="cargo"]');
            const fotoAtualInput = document.getElementById('fotoAtual');
            
            if (nomeInput) nomeInput.value = user.nome || '';
            if (loginInput) loginInput.value = user.login || user.email || '';
            if (emailInput) emailInput.value = user.email || '';
            if (cargoInput) cargoInput.value = user.cargo || '';
            if (fotoAtualInput) fotoAtualInput.value = user.foto || '';
            
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
            
            modal.classList.add('active');
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
        console.warn('Elementos não encontrados ainda:', {
            fotoInput: !!fotoInput,
            btnSelecionarFoto: !!btnSelecionarFoto
        });
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
        // Remover listener anterior se existir
        const newBtn = btnSelecionarFoto.cloneNode(true);
        btnSelecionarFoto.parentNode.replaceChild(newBtn, btnSelecionarFoto);
        const btnSelecionarFotoNew = document.getElementById('btnSelecionarFoto');
        
        btnSelecionarFotoNew.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            console.log('🔘 Botão Selecionar Foto clicado');
            const fotoInputNow = document.getElementById('fotoInput');
            if (fotoInputNow) {
                console.log('Abrindo seletor de arquivo...');
                fotoInputNow.click();
            } else {
                console.error('fotoInput não encontrado ao clicar no botão!');
            }
        });
        btnSelecionarFotoNew.setAttribute('listener', 'attached');
        console.log('✅ Botão Selecionar Foto registrado');
    }
    
    // Registrar evento change do input file
    fotoInput.addEventListener('change', async function(e) {
        console.log('🔔 EVENTO CHANGE DISPARADO no fotoInput!');
        console.log('Event target:', e.target);
        console.log('Files:', e.target.files);
        console.log('Files length:', e.target.files?.length || 0);
        console.log('Arquivo selecionado:', e.target.files[0]?.name || 'nenhum');
        
        const file = e.target.files[0];
        if (!file) {
            console.warn('⚠️ Nenhum arquivo selecionado');
            return;
        }
        
        console.log('✅ Arquivo encontrado:', file.name, 'tipo:', file.type, 'tamanho:', file.size, 'bytes');
        console.log('Processando arquivo...');
        
        // Validar tipo
        if (!file.type.match('image.*')) {
            alert('Por favor, selecione uma imagem');
            e.target.value = '';
            return;
        }
        
        // Validar tamanho (2MB)
        if (file.size > 2 * 1024 * 1024) {
            alert('Arquivo muito grande. Tamanho máximo: 2MB');
            e.target.value = '';
            return;
        }
        
        // Salvar blob original
        fotoOriginalBlob = file;
        
        // MOSTRAR PREVIEW IMEDIATAMENTE antes de abrir editor
        const reader = new FileReader();
        reader.onload = function(e) {
            const previewUrl = e.target.result;
            console.log('Arquivo lido com sucesso, atualizando preview...');
            updateFotoPreview(previewUrl);
            console.log('✅ Preview atualizado com sucesso');
            
            // Agora tentar abrir o editor
            loadCropperLibrary()
                .then(() => {
                    console.log('Cropper.js carregado, abrindo editor...');
                    abrirEditorFoto(previewUrl);
                })
                .catch(error => {
                    console.error('Erro ao carregar editor:', error);
                    // Se o editor falhar, a foto já está no preview e no input
                    console.log('Foto carregada no input, mas editor não disponível');
                });
        };
        reader.onerror = function() {
            console.error('Erro ao ler arquivo');
            alert('Erro ao ler o arquivo selecionado');
        };
        reader.readAsDataURL(file);
        } else {
            console.log('Nenhum arquivo selecionado');
            // Restaurar foto atual se houver
            const fotoAtual = document.getElementById('fotoAtual');
            if (fotoAtual && fotoAtual.value) {
                updateFotoPreview(fotoAtual.value);
            } else {
                updateFotoPreview('');
            }
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
                
                // Criar um File a partir do blob para substituir o input file
                const file = new File([blob], 'foto_usuario.jpg', { type: 'image/jpeg' });
                
                // Criar DataTransfer para substituir o arquivo do input
                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(file);
                const fotoInput = document.getElementById('fotoInput');
                if (fotoInput) {
                    fotoInput.files = dataTransfer.files;
                    console.log('Arquivo atualizado no input file. Total:', fotoInput.files.length, 'arquivo(s)');
                }
                
                // Salvar também como base64 no campo hidden para backup
                canvas.toBlob(function(blob) {
                    const reader = new FileReader();
                    reader.onload = function() {
                        const fotoEditadaInput = document.getElementById('fotoEditada');
                        if (fotoEditadaInput) {
                            fotoEditadaInput.value = reader.result;
                        }
                    };
                    reader.readAsDataURL(blob);
                }, 'image/jpeg', 0.9);
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
    setTimeout(initPreviewListeners, 200);
});

// Validar formulário antes de submeter (garantir que foto está no input)
function validarFormFoto(event) {
    console.log('=== VALIDAÇÃO DE FORMULÁRIO ===');
    const fotoInput = document.getElementById('fotoInput');
    const fotoAtual = document.getElementById('fotoAtual');
    const form = event.target;
    
    console.log('Formulário encontrado:', !!form);
    console.log('fotoInput encontrado:', !!fotoInput);
    console.log('fotoAtual encontrado:', !!fotoAtual);
    
    if (fotoInput) {
        console.log('fotoInput.files:', fotoInput.files);
        console.log('fotoInput.files.length:', fotoInput.files?.length || 0);
        if (fotoInput.files && fotoInput.files.length > 0) {
            console.log('Arquivo no input:', fotoInput.files[0].name, 'tipo:', fotoInput.files[0].type, 'tamanho:', fotoInput.files[0].size, 'bytes');
        }
    }
    
    if (fotoAtual) {
        console.log('fotoAtual.value:', fotoAtual.value || '(vazio)');
    }
    
    // Verificar se há foto selecionada ou foto atual
    if (fotoInput && fotoInput.files && fotoInput.files.length > 0) {
        console.log('✅ Formulário sendo submetido COM foto:', fotoInput.files[0].name, 'tamanho:', fotoInput.files[0].size, 'bytes');
        console.log('✅ Formulário tem enctype multipart/form-data:', form.enctype === 'multipart/form-data');
        return true; // Permitir submit
    } else if (fotoAtual && fotoAtual.value) {
        console.log('✅ Formulário sendo submetido mantendo foto atual:', fotoAtual.value);
        return true; // Permitir submit (mantendo foto atual)
    } else {
        console.log('⚠️ Formulário sendo submetido sem foto (opcional)');
        return true; // Permitir submit mesmo sem foto (foto é opcional)
    }
}

// Fechar modal de usuário ao clicar fora
const userModal = document.getElementById('userModal');
if (userModal) {
    userModal.addEventListener('click', function(e) {
        if (e.target === this) {
            closeModal();
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

