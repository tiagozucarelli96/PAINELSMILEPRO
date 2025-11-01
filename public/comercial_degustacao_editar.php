<?php
// comercial_degustacao_editar.php — Editor de degustações com Form Builder
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/lc_permissions_enhanced.php';

// Garantir que $pdo está disponível
if (!isset($pdo)) {
    global $pdo;
}
// Garantir acesso ao $pdo global se necessário
if (!isset($pdo) || !($pdo instanceof PDO)) {
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        $pdo = $GLOBALS['pdo'];
    } else {
        require_once __DIR__ . '/conexao.php';
    }
}

// Verificar permissões
if (!lc_can_edit_degustacoes()) {
    header('Location: index.php?page=dashboard&error=permission_denied');
    exit;
}

$event_id = (int)($_GET['id'] ?? 0);
$is_edit = $event_id > 0;

error_log("=== EDITAR DEGUSTAÇÃO ===");
error_log("event_id: $event_id, is_edit: " . ($is_edit ? 'true' : 'false'));

// Buscar degustação para edição
$degustacao = null;
if ($is_edit && $event_id > 0) {
    try {
    $stmt = $pdo->prepare("SELECT * FROM comercial_degustacoes WHERE id = :id");
    $stmt->execute([':id' => $event_id]);
    $degustacao = $stmt->fetch(PDO::FETCH_ASSOC);
        
        error_log("Degustação encontrada: " . ($degustacao ? 'SIM' : 'NÃO'));
        if ($degustacao) {
            error_log("Nome: " . ($degustacao['nome'] ?? 'N/A'));
            error_log("Token público: " . (isset($degustacao['token_publico']) ? 'SIM' : 'NÃO'));
        }
    
    if (!$degustacao) {
            error_log("Degustação não encontrada no banco com ID: $event_id");
            header('Location: index.php?page=comercial_degustacoes&error=not_found');
        exit;
    }
    } catch (Exception $e) {
        error_log("Erro ao buscar degustação: " . $e->getMessage());
        header('Location: index.php?page=comercial_degustacoes&error=' . urlencode('Erro ao buscar degustação: ' . $e->getMessage()));
        exit;
    }
} else {
    error_log("Modo de criação - não é edição");
}

// Buscar campos padrão
$campos_padrao = [];
$stmt = $pdo->query("SELECT campos_json FROM comercial_campos_padrao WHERE ativo = TRUE ORDER BY criado_em DESC LIMIT 1");
$result = $stmt->fetch(PDO::FETCH_ASSOC);
if ($result && $result['campos_json']) {
    $campos_padrao = json_decode($result['campos_json'], true) ?: [];
}

// Processar formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST)) {
    try {
        // Debug: log do que está chegando
        error_log("=== PROCESSANDO FORMULÁRIO ===");
        error_log("POST data: " . json_encode($_POST));
        
        $nome = trim($_POST['nome'] ?? '');
        $data = $_POST['data'] ?? '';
        $hora_inicio = $_POST['hora_inicio'] ?? '';
        $hora_fim = $_POST['hora_fim'] ?? '';
        $local = trim($_POST['local'] ?? '');
        $capacidade = (int)($_POST['capacidade'] ?? 50);
        $data_limite = $_POST['data_limite'] ?? '';
        $lista_espera = isset($_POST['lista_espera']) ? 1 : 0;
        
        error_log("Nome: $nome, Data: $data, Local: $local");
        
        // Preços
        $preco_casamento = (float)($_POST['preco_casamento'] ?? 150.00);
        $incluidos_casamento = (int)($_POST['incluidos_casamento'] ?? 2);
        $preco_15anos = (float)($_POST['preco_15anos'] ?? 180.00);
        $incluidos_15anos = (int)($_POST['incluidos_15anos'] ?? 3);
        $preco_extra = (float)($_POST['preco_extra'] ?? 50.00);
        
        // Textos
        $instrutivo_html = $_POST['instrutivo_html'] ?? '';
        $email_confirmacao_html = $_POST['email_confirmacao_html'] ?? '';
        $msg_sucesso_html = $_POST['msg_sucesso_html'] ?? '';
        
        // Form Builder
        $campos_json = $_POST['campos_json'] ?? '[]';
        $usar_como_padrao = isset($_POST['usar_como_padrao']) ? 1 : 0;
        
        // Validar campos obrigatórios
        // Se local_custom foi enviado e não está vazio, usar ele; senão usar local do select
        if (!empty($_POST['local_custom']) && trim($_POST['local_custom']) !== '') {
            $local = trim($_POST['local_custom']);
        }
        
        // Se local ainda está vazio, tentar pegar do campo 'local' normal (do select)
        if (empty($local)) {
            $local = trim($_POST['local'] ?? '');
        }
        
        // Validar local
        if (empty($local)) {
            throw new Exception("Selecione ou digite um local");
        }
        
        if (!$nome || !$data || !$hora_inicio || !$hora_fim || !$data_limite) {
            throw new Exception("Preencha todos os campos obrigatórios");
        }
        
        // Construir data_evento combinando data + hora_inicio (para coluna data_evento TIMESTAMP)
        // IMPORTANTE: Sempre construir no PHP para evitar ambiguidade de tipos no PostgreSQL
        $data_evento = null;
        if ($data && $hora_inicio) {
            try {
                // Combinar data (YYYY-MM-DD) + hora_inicio (HH:MM) em TIMESTAMP
                $data_evento = $data . ' ' . $hora_inicio . ':00'; // Adicionar segundos
            } catch (Exception $e) {
                error_log("Erro ao construir data_evento: " . $e->getMessage());
                // Fallback: usar data com hora 00:00:00
                $data_evento = $data . ' 00:00:00';
            }
        } else {
            // Fallback seguro se não tiver data ou hora
            $data_evento = $data ? $data . ' 00:00:00' : date('Y-m-d H:i:s');
        }
        
        // Verificar se é edição e obter o ID
        $is_edit_mode = isset($_POST['event_id']) && (int)$_POST['event_id'] > 0;
        $edit_id = $is_edit_mode ? (int)$_POST['event_id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);
        
        error_log("Modo edição detectado: " . ($is_edit_mode ? 'SIM' : 'NÃO') . ", ID: $edit_id");
        
        if ($is_edit_mode && $edit_id > 0) {
            // Atualizar degustação existente
            // IMPORTANTE: A tabela usa 'titulo', 'nome' e 'data_evento', vamos atualizar todos
            $sql = "UPDATE comercial_degustacoes SET 
                    nome = :nome, titulo = :nome, data = :data, 
                    data_evento = :data_evento::timestamp,
                    hora_inicio = :hora_inicio, hora_fim = :hora_fim,
                    local = :local, capacidade = :capacidade, data_limite = :data_limite, lista_espera = :lista_espera,
                    preco_casamento = :preco_casamento, incluidos_casamento = :incluidos_casamento,
                    preco_15anos = :preco_15anos, incluidos_15anos = :incluidos_15anos, preco_extra = :preco_extra,
                    instrutivo_html = :instrutivo_html, email_confirmacao_html = :email_confirmacao_html,
                    msg_sucesso_html = :msg_sucesso_html, campos_json = :campos_json
                    WHERE id = :id";
            
            $params = [
                ':nome' => $nome, ':data' => $data, ':data_evento' => $data_evento,
                ':hora_inicio' => $hora_inicio, ':hora_fim' => $hora_fim,
                ':local' => $local, ':capacidade' => $capacidade, ':data_limite' => $data_limite, ':lista_espera' => $lista_espera,
                ':preco_casamento' => $preco_casamento, ':incluidos_casamento' => $incluidos_casamento,
                ':preco_15anos' => $preco_15anos, ':incluidos_15anos' => $incluidos_15anos, ':preco_extra' => $preco_extra,
                ':instrutivo_html' => $instrutivo_html, ':email_confirmacao_html' => $email_confirmacao_html,
                ':msg_sucesso_html' => $msg_sucesso_html, ':campos_json' => $campos_json, ':id' => $edit_id
            ];
        } else {
            // Criar nova degustação
            // IMPORTANTE: A tabela tem 'titulo' e 'data_evento' como NOT NULL, então precisamos inserir em ambos
            // Construir data_evento no PHP evita ambiguidade de tipos no PostgreSQL
            $sql = "INSERT INTO comercial_degustacoes 
                    (nome, titulo, data, data_evento, hora_inicio, hora_fim, local, capacidade, data_limite, lista_espera,
                     preco_casamento, incluidos_casamento, preco_15anos, incluidos_15anos, preco_extra,
                     instrutivo_html, email_confirmacao_html, msg_sucesso_html, campos_json, status, criado_por)
                    VALUES 
                    (:nome, :nome, :data, :data_evento::timestamp,
                     :hora_inicio, :hora_fim, :local, :capacidade, :data_limite, :lista_espera,
                     :preco_casamento, :incluidos_casamento, :preco_15anos, :incluidos_15anos, :preco_extra,
                     :instrutivo_html, :email_confirmacao_html, :msg_sucesso_html, :campos_json, 'rascunho', :criado_por)";
            
            $params = [
                ':nome' => $nome, ':data' => $data, ':data_evento' => $data_evento,
                ':hora_inicio' => $hora_inicio, ':hora_fim' => $hora_fim,
                ':local' => $local, ':capacidade' => $capacidade, ':data_limite' => $data_limite, ':lista_espera' => $lista_espera,
                ':preco_casamento' => $preco_casamento, ':incluidos_casamento' => $incluidos_casamento,
                ':preco_15anos' => $preco_15anos, ':incluidos_15anos' => $incluidos_15anos, ':preco_extra' => $preco_extra,
                ':instrutivo_html' => $instrutivo_html, ':email_confirmacao_html' => $email_confirmacao_html,
                ':msg_sucesso_html' => $msg_sucesso_html, ':campos_json' => $campos_json,
                ':criado_por' => $_SESSION['id_usuario'] ?? $_SESSION['id'] ?? 1
            ];
        }
        
        error_log("Executando SQL: $sql");
        error_log("Params: " . json_encode($params));
        
        $stmt = $pdo->prepare($sql);
        if (!$stmt->execute($params)) {
            $errorInfo = $stmt->errorInfo();
            error_log("Erro PDO: " . json_encode($errorInfo));
            throw new Exception("Erro ao salvar no banco de dados: " . ($errorInfo[2] ?? 'Erro desconhecido'));
        }
        
        error_log("Salvamento bem-sucedido!");
        
        if (!$is_edit_mode) {
            $event_id = (int)$pdo->lastInsertId();
            error_log("Nova degustação criada com ID: $event_id");
        } else {
            error_log("Degustação atualizada com ID: $edit_id");
        }
        
        // Salvar como padrão se solicitado
        if ($usar_como_padrao) {
            $stmt = $pdo->prepare("INSERT INTO comercial_campos_padrao (campos_json, criado_por) VALUES (:campos_json, :criado_por)");
            $stmt->execute([
                ':campos_json' => $campos_json,
                ':criado_por' => $_SESSION['id_usuario'] ?? $_SESSION['id'] ?? 1
            ]);
        }
        
        // Redirecionar após sucesso - IMPORTANTE: limpar output antes
        $success_msg = $is_edit_mode ? "Degustação atualizada com sucesso!" : "Degustação criada com sucesso!";
        $redirect_url = 'index.php?page=comercial_degustacoes&success=' . urlencode($success_msg);
        
        error_log("Redirecionando para: $redirect_url");
        
        // Limpar qualquer output antes do redirecionamento
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        header('Location: ' . $redirect_url);
        exit();
        
    } catch (Exception $e) {
        $error_message = "Erro: " . $e->getMessage();
        error_log("ERRO ao processar formulário: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        
        // IMPORTANTE: Se houve erro e estamos em modo edição, recarregar a degustação
        // porque ela pode não ter sido carregada antes do processamento do POST
        if ($is_edit && $event_id > 0 && !isset($degustacao)) {
            error_log("Recarregando degustação após erro no POST...");
            try {
                $stmt = $pdo->prepare("SELECT * FROM comercial_degustacoes WHERE id = :id");
                $stmt->execute([':id' => $event_id]);
                $degustacao = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($degustacao) {
                    error_log("Degustação recarregada com sucesso após erro");
                } else {
                    error_log("Falha ao recarregar degustação após erro");
                }
            } catch (Exception $e2) {
                error_log("Erro ao recarregar degustação: " . $e2->getMessage());
            }
        }
    }
}

// Buscar token público se for edição
$token_publico = '';
if ($is_edit && isset($degustacao) && $degustacao) {
    $token_publico = $degustacao['token_publico'] ?? '';
}

// Criar conteúdo da página
ob_start();
?>

<style>
        .editor-container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }
        
        .page-title {
            font-size: 28px;
            font-weight: 700;
            color: #1e3a8a;
            margin: 0;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-secondary {
            background: #6b7280;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .tabs {
            display: flex;
            border-bottom: 2px solid #e5e7eb;
            margin-bottom: 30px;
        }
        
        .tab {
            padding: 12px 24px;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            font-weight: 600;
            color: #6b7280;
            transition: all 0.2s;
        }
        
        .tab.active {
            color: #1e3a8a;
            border-bottom-color: #1e3a8a;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
        }
        
        .form-group.full-width {
            grid-column: 1 / -1;
        }
        
        .form-label {
            font-weight: 600;
            color: #374151;
            margin-bottom: 5px;
        }
        
        .form-input {
            padding: 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 16px;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .form-textarea {
            padding: 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 16px;
            min-height: 120px;
            resize: vertical;
        }
        
        .form-checkbox {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .form-checkbox input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: #3b82f6;
        }
        
        .form-builder {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 20px;
            background: #f8fafc;
        }
        
        .field-item {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .field-info {
            flex: 1;
        }
        
        .field-label {
            font-weight: 600;
            color: #1f2937;
        }
        
        .field-type {
            font-size: 14px;
            color: #6b7280;
        }
        
        .field-actions {
            display: flex;
            gap: 10px;
        }
        
        .btn-sm {
            padding: 6px 12px;
            border-radius: 4px;
            font-size: 12px;
            cursor: pointer;
            border: none;
        }
        
        .btn-edit {
            background: #3b82f6;
            color: white;
        }
        
        .btn-delete {
            background: #ef4444;
            color: white;
        }
        
        .btn-add {
            background: #10b981;
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            width: 100%;
        }
        
        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
        }
        
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
        }
        
        .modal.active {
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .modal-content {
            background: white;
            border-radius: 12px;
            padding: 30px;
            max-width: 500px;
            width: 90%;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .modal-title {
            font-size: 20px;
            font-weight: 700;
            color: #1e3a8a;
            margin: 0;
        }
        
        .close-btn {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #6b7280;
        }
    </style>
    
    <style>
        /* Modais customizados - substituem alert/confirm nativos */
        .custom-alert-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10000;
            animation: fadeIn 0.2s;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes slideUp {
            from {
                transform: translateY(20px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        .custom-alert {
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
            padding: 0;
            max-width: 400px;
            width: 90%;
            animation: slideUp 0.3s;
            overflow: hidden;
        }
        
        .custom-alert-header {
            padding: 1.5rem;
            background: #3b82f6;
            color: white;
            font-weight: 600;
            font-size: 1.1rem;
        }
        
        .custom-alert-body {
            padding: 1.5rem;
            color: #374151;
            line-height: 1.6;
        }
        
        .custom-alert-actions {
            padding: 1rem 1.5rem;
            display: flex;
            gap: 0.75rem;
            justify-content: flex-end;
            border-top: 1px solid #e5e7eb;
        }
        
        .custom-alert-btn {
            padding: 0.625rem 1.25rem;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
            font-size: 0.875rem;
        }
        
        .custom-alert-btn-primary {
            background: #3b82f6;
            color: white;
        }
        
        .custom-alert-btn-primary:hover {
            background: #2563eb;
        }
        
        .custom-alert-btn-secondary {
            background: #f3f4f6;
            color: #374151;
        }
        
        .custom-alert-btn-secondary:hover {
            background: #e5e7eb;
        }
    </style>

<div class="editor-container">
            <!-- Header -->
            <div class="page-header">
                <h1 class="page-title"><?= $is_edit ? '✏️ Editar' : '➕ Nova' ?> Degustação</h1>
                <?php 
                // Debug info
                error_log("=== RENDERIZANDO PÁGINA ===");
                error_log("is_edit: " . ($is_edit ? 'true' : 'false'));
                error_log("event_id: " . ($_GET['id'] ?? 'N/A'));
                error_log("degustacao existe: " . (isset($degustacao) ? 'SIM' : 'NÃO'));
                if (isset($degustacao)) {
                    error_log("degustacao['id']: " . ($degustacao['id'] ?? 'N/A'));
                    error_log("degustacao['nome']: " . ($degustacao['nome'] ?? 'N/A'));
                }
                ?>
                <?php if ($is_edit && isset($degustacao['id'])): ?>
                    <div style="font-size: 0.875rem; color: #6b7280; margin-top: 0.5rem;">
                        ID: <?= (int)$degustacao['id'] ?> | Status: <?= h($degustacao['status'] ?? 'N/A') ?>
                    </div>
                <?php elseif ($is_edit): ?>
                    <div style="font-size: 0.875rem; color: #ef4444; margin-top: 0.5rem;">
                        ⚠️ AVISO: Modo edição detectado, mas degustação não carregada. ID na URL: <?= htmlspecialchars($_GET['id'] ?? 'N/A') ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <?php if ($is_edit && !isset($degustacao)): ?>
                <div class="alert alert-error" style="margin-bottom: 20px; padding: 1rem; background: #fee2e2; border: 1px solid #fca5a5; border-radius: 8px; color: #991b1b;">
                    ⚠️ Erro: Degustação não encontrada ou não foi possível carregar os dados.
                    <br><small>Verifique se o ID está correto na URL: id=<?= htmlspecialchars($_GET['id'] ?? 'N/A') ?></small>
                    <br><small>Debug: event_id = <?= $event_id ?>, is_edit = <?= $is_edit ? 'true' : 'false' ?></small>
                </div>
            <?php endif; ?>
            
            <!-- Mensagens -->
            <?php if (isset($_GET['success'])): ?>
                <div class="alert alert-success">
                    ✅ <?= h(urldecode($_GET['success'])) ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['error'])): ?>
                <div class="alert alert-error">
                    ❌ <?= h(urldecode($_GET['error'])) ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($error_message)): ?>
                <div class="alert alert-error">
                    ❌ <?= h($error_message) ?>
                </div>
            <?php endif; ?>
            
            <!-- Tabs -->
            <div class="tabs">
                <div class="tab active" onclick="showTab('geral')">📋 Geral</div>
                <div class="tab" onclick="showTab('campos')">🔧 Campos</div>
                <div class="tab" onclick="showTab('textos')">📝 Textos</div>
            </div>
            
            <form method="POST" id="degustacaoForm" action="" onsubmit="return prepararLocalAntesDoSubmit(event)">
                <!-- Tab Geral -->
                <div id="geral" class="tab-content active">
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Nome da Degustação *</label>
                            <input type="text" name="nome" class="form-input" required 
                                   value="<?= ($is_edit && isset($degustacao)) ? h($degustacao['nome'] ?? $degustacao['titulo'] ?? '') : '' ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Data *</label>
                            <input type="date" name="data" class="form-input" required 
                                   value="<?= ($is_edit && isset($degustacao)) ? $degustacao['data'] : '' ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Hora Início *</label>
                            <input type="time" name="hora_inicio" class="form-input" required 
                                   value="<?= ($is_edit && isset($degustacao)) ? $degustacao['hora_inicio'] : '' ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Hora Fim *</label>
                            <input type="time" name="hora_fim" class="form-input" required 
                                   value="<?= ($is_edit && isset($degustacao)) ? $degustacao['hora_fim'] : '' ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Local *</label>
                            <select name="local" id="localSelect" class="form-input" required>
                                <option value="">Selecione um local...</option>
                                <option value="Espaço Garden: R. Padre Eugênio, 511 - Jardim Jacinto, Jacareí - SP, 12322-690" <?= ($is_edit && isset($degustacao) && $degustacao['local'] === 'Espaço Garden: R. Padre Eugênio, 511 - Jardim Jacinto, Jacareí - SP, 12322-690') ? 'selected' : '' ?>>Espaço Garden: R. Padre Eugênio, 511 - Jardim Jacinto, Jacareí - SP, 12322-690</option>
                                <option value="Espaço Cristal: R. Padre Eugênio, 511 - Jardim Jacinto, Jacareí - SP, 12322-690" <?= ($is_edit && isset($degustacao) && $degustacao['local'] === 'Espaço Cristal: R. Padre Eugênio, 511 - Jardim Jacinto, Jacareí - SP, 12322-690') ? 'selected' : '' ?>>Espaço Cristal: R. Padre Eugênio, 511 - Jardim Jacinto, Jacareí - SP, 12322-690</option>
                            </select>
                            <input type="text" name="local_custom" id="localCustom" class="form-input" 
                                   style="margin-top: 10px; display: none;" 
                                   placeholder="Ou digite um local personalizado...">
                            <small style="color: #6b7280; margin-top: 5px; display: block;">
                                <a href="javascript:void(0)" onclick="document.getElementById('localCustom').style.display='block'; document.getElementById('localCustom').required=true; document.getElementById('localSelect').required=false; document.getElementById('localCustom').focus();" style="color: #3b82f6; text-decoration: underline;">Ou digite um local personalizado</a>
                            </small>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Capacidade *</label>
                            <input type="number" name="capacidade" class="form-input" required min="1" 
                                   value="<?= ($is_edit && isset($degustacao)) ? $degustacao['capacidade'] : '50' ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Data Limite de Inscrição *</label>
                            <input type="date" name="data_limite" id="dataLimite" class="form-input" required 
                                   value="<?= ($is_edit && isset($degustacao)) ? $degustacao['data_limite'] : '' ?>">
                            <small style="color: #6b7280; margin-top: 5px; display: block;">
                                ⚠️ Após esta data, o link público será bloqueado para novas inscrições, mas a degustação permanecerá ativa
                            </small>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Aceitar Lista de Espera</label>
                            <div class="form-checkbox">
                                <input type="checkbox" name="lista_espera" id="lista_espera" 
                                       <?= ($is_edit && isset($degustacao) && $degustacao['lista_espera']) || !$is_edit ? 'checked' : '' ?>>
                                <label for="lista_espera">Permitir lista de espera quando lotado</label>
                            </div>
                        </div>
                    </div>
                    
                    <h3 style="margin-top: 30px; margin-bottom: 20px; color: #1e3a8a;">💰 Preços</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Preço Casamento (R$)</label>
                            <input type="number" name="preco_casamento" class="form-input" step="0.01" min="0" 
                                   value="<?= ($is_edit && isset($degustacao)) ? $degustacao['preco_casamento'] : '150.00' ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Pessoas Incluídas (Casamento)</label>
                            <input type="number" name="incluidos_casamento" class="form-input" min="1" 
                                   value="<?= ($is_edit && isset($degustacao)) ? $degustacao['incluidos_casamento'] : '2' ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Preço 15 Anos (R$)</label>
                            <input type="number" name="preco_15anos" class="form-input" step="0.01" min="0" 
                                   value="<?= ($is_edit && isset($degustacao)) ? $degustacao['preco_15anos'] : '180.00' ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Pessoas Incluídas (15 Anos)</label>
                            <input type="number" name="incluidos_15anos" class="form-input" min="1" 
                                   value="<?= ($is_edit && isset($degustacao)) ? $degustacao['incluidos_15anos'] : '3' ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Preço por Pessoa Extra (R$)</label>
                            <input type="number" name="preco_extra" class="form-input" step="0.01" min="0" 
                                   value="<?= ($is_edit && isset($degustacao)) ? $degustacao['preco_extra'] : '50.00' ?>">
                        </div>
                    </div>
                </div>
                
                <!-- Tab Campos -->
                <div id="campos" class="tab-content">
                    <div class="form-builder">
                        <h3 style="margin-bottom: 20px; color: #1e3a8a;">🔧 Form Builder</h3>
                        <div id="fieldsList">
                            <!-- Campos serão carregados aqui via JavaScript -->
                        </div>
                        <button type="button" class="btn-add" onclick="openFieldModal()">
                            ➕ Adicionar Campo
                        </button>
                        
                        <div class="form-checkbox" style="margin-top: 20px;">
                            <input type="checkbox" name="usar_como_padrao" id="usar_como_padrao">
                            <label for="usar_como_padrao">Usar como padrão para próximas degustações</label>
                        </div>
                    </div>
                </div>
                
                <!-- Tab Textos -->
                <div id="textos" class="tab-content">
                    <div class="form-group full-width">
                        <label class="form-label">Instruções do Dia (HTML)</label>
                        <textarea name="instrutivo_html" class="form-textarea" placeholder="Instruções que aparecerão no topo da página pública..."><?= ($is_edit && isset($degustacao)) ? h($degustacao['instrutivo_html']) : '' ?></textarea>
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">E-mail de Confirmação (HTML)</label>
                        <textarea name="email_confirmacao_html" class="form-textarea" placeholder="Conteúdo do e-mail de confirmação..."><?= ($is_edit && isset($degustacao)) ? h($degustacao['email_confirmacao_html']) : '' ?></textarea>
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">Mensagem de Sucesso (HTML)</label>
                        <textarea name="msg_sucesso_html" class="form-textarea" placeholder="Mensagem exibida após inscrição..."><?= ($is_edit && isset($degustacao)) ? h($degustacao['msg_sucesso_html']) : '' ?></textarea>
                    </div>
                </div>
                
                <!-- Campos ocultos -->
                <?php if ($is_edit && isset($degustacao['id'])): ?>
                <input type="hidden" name="event_id" id="event_id" value="<?= (int)$degustacao['id'] ?>">
                <?php endif; ?>
                <input type="hidden" name="campos_json" id="camposJson" value="<?= $is_edit && isset($degustacao['campos_json']) ? h($degustacao['campos_json']) : '[]' ?>">
                
                <!-- Ações -->
                <div class="form-actions">
                    <button type="submit" class="btn-primary">💾 Salvar</button>
                    <?php if ($is_edit): ?>
                    <?php 
                    // Buscar token_publico se não estiver definido
                    if (empty($token_publico) && isset($degustacao['token_publico'])) {
                        $token_publico = $degustacao['token_publico'];
                    }
                    ?>
                    <?php if (!empty($token_publico)): 
                        // Gerar URL completa
                        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                        $host = $_SERVER['HTTP_HOST'] ?? 'painelsmilepro-production.up.railway.app';
                        $public_url = $protocol . '://' . $host . '/index.php?page=comercial_degust_public&t=' . urlencode($token_publico);
                    ?>
                    <div style="margin-top: 15px; padding: 15px; background: #f8fafc; border-radius: 8px; border: 1px solid #e5e7eb;">
                        <label style="display: block; font-size: 14px; font-weight: 600; color: #374151; margin-bottom: 8px;">🔗 Link Público para Divulgação:</label>
                        <div style="display: flex; gap: 8px; align-items: center;">
                            <input type="text" 
                                   id="link-publico-editar" 
                                   value="<?= htmlspecialchars($public_url, ENT_QUOTES, 'UTF-8') ?>" 
                                   readonly 
                                   style="flex: 1; padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; background: white; font-family: monospace; color: #1f2937;">
                            <button type="button" 
                                    onclick="copiarLinkPublicoEditar()" 
                                    class="btn-secondary"
                                    style="white-space: nowrap;">
                                📋 Copiar
                            </button>
                            <a href="<?= htmlspecialchars($public_url, ENT_QUOTES, 'UTF-8') ?>" 
                               class="btn-secondary" 
                               target="_blank"
                               style="white-space: nowrap;">
                                🔗 Abrir
                            </a>
                        </div>
                    </div>
                    <?php else: ?>
                    <span style="color: #6b7280; font-size: 14px; padding: 8px;">
                        ℹ️ Link público será gerado automaticamente ao publicar
                    </span>
                    <?php endif; ?>
                    <?php endif; ?>
                    <a href="index.php?page=comercial_degustacoes" class="btn-secondary">❌ Cancelar</a>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Modal de Campo -->
    <div class="modal" id="fieldModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Adicionar Campo</h3>
                <button class="close-btn" onclick="closeFieldModal()">&times;</button>
            </div>
            
            <div class="form-group">
                <label class="form-label">Tipo de Campo</label>
                <select id="fieldType" class="form-input" onchange="updateFieldForm()">
                    <option value="texto">Texto</option>
                    <option value="email">E-mail</option>
                    <option value="celular">Celular</option>
                    <option value="cpf_cnpj">CPF/CNPJ</option>
                    <option value="numero">Número</option>
                    <option value="data">Data</option>
                    <option value="select">Seleção</option>
                    <option value="checkbox">Checkbox</option>
                    <option value="radio">Radio</option>
                    <option value="textarea">Textarea</option>
                </select>
            </div>
            
            <div class="form-group">
                <label class="form-label">Label do Campo</label>
                <input type="text" id="fieldLabel" class="form-input" placeholder="Ex: Nome completo">
            </div>
            
            <div class="form-group">
                <label class="form-label">Nome do Campo (ID)</label>
                <input type="text" id="fieldName" class="form-input" placeholder="Ex: nome_completo">
            </div>
            
            <div class="form-group">
                <label class="form-label">Obrigatório</label>
                <div class="form-checkbox">
                    <input type="checkbox" id="fieldRequired">
                    <label for="fieldRequired">Campo obrigatório</label>
                </div>
            </div>
            
            <div id="fieldOptions" style="display: none;">
                <div class="form-group">
                    <label class="form-label">Opções (uma por linha)</label>
                    <textarea id="fieldOptionsText" class="form-textarea" placeholder="Opção 1&#10;Opção 2&#10;Opção 3"></textarea>
                </div>
            </div>
            
            <div class="form-actions">
                <button type="button" class="btn-secondary" onclick="closeFieldModal()">Cancelar</button>
                <button type="button" class="btn-primary" onclick="addField()">Adicionar</button>
            </div>
        </div>
    </div>
    
    <script>
        // Função auxiliar para escape HTML
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Modal customizado de alerta
        function customAlert(mensagem, titulo = 'Aviso') {
            return new Promise((resolve) => {
                const overlay = document.createElement('div');
                overlay.className = 'custom-alert-overlay';
                overlay.innerHTML = `
                    <div class="custom-alert">
                        <div class="custom-alert-header">${escapeHtml(titulo)}</div>
                        <div class="custom-alert-body">${escapeHtml(mensagem)}</div>
                        <div class="custom-alert-actions">
                            <button class="custom-alert-btn custom-alert-btn-primary" onclick="this.closest('.custom-alert-overlay').remove(); resolveCustomAlert()">OK</button>
                        </div>
                    </div>
                `;
                
                document.body.appendChild(overlay);
                
                overlay.addEventListener('click', (e) => {
                    if (e.target === overlay) {
                        overlay.remove();
                        resolveCustomAlert();
                    }
                });
                
                window.resolveCustomAlert = () => {
                    overlay.remove();
                    resolve();
                };
            });
        }
        
        // Modal customizado de confirmação
        async function customConfirm(mensagem, titulo = 'Confirmar') {
            return new Promise((resolve) => {
                const overlay = document.createElement('div');
                overlay.className = 'custom-alert-overlay';
                overlay.innerHTML = `
                    <div class="custom-alert">
                        <div class="custom-alert-header">${escapeHtml(titulo)}</div>
                        <div class="custom-alert-body">${escapeHtml(mensagem)}</div>
                        <div class="custom-alert-actions">
                            <button class="custom-alert-btn custom-alert-btn-secondary" onclick="resolveCustomConfirm(false)">Cancelar</button>
                            <button class="custom-alert-btn custom-alert-btn-primary" onclick="resolveCustomConfirm(true)">Confirmar</button>
                        </div>
                    </div>
                `;
                
                document.body.appendChild(overlay);
                
                overlay.addEventListener('click', (e) => {
                    if (e.target === overlay) {
                        overlay.remove();
                        resolve(false);
                    }
                });
                
                window.resolveCustomConfirm = (resultado) => {
                    overlay.remove();
                    resolve(resultado);
                };
            });
        }
        
        // Função SIMPLES para preparar local antes do submit
        // IMPORTANTE: Esta função NÃO bloqueia o submit - apenas prepara os campos
        window.prepararLocalAntesDoSubmit = function(e) {
            try {
                const select = document.getElementById('localSelect');
                const custom = document.getElementById('localCustom');
                
                if (!select || !custom) {
                    return true; // Prosseguir normalmente
                }
                
                const selectValue = select.value || '';
                const customValue = custom.value ? custom.value.trim() : '';
                const customVisible = custom.style.display !== 'none';
                
                // Se custom está visível e tem valor, usar ele
                if (customVisible && customValue) {
                    // Adicionar input hidden com valor
                    const hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'local';
                    hidden.value = customValue;
                    e.target.appendChild(hidden);
                    
                    // Desabilitar select
                    select.disabled = true;
                    select.required = false;
                    select.name = 'local_disabled';
                    
                    // Desabilitar custom
                    custom.disabled = true;
                    custom.name = 'local_custom_disabled';
                } else if (selectValue) {
                    // Se select tem valor, desabilitar custom
                    custom.disabled = true;
                    custom.required = false;
                    custom.name = 'local_custom_disabled';
                }
                
                // SEMPRE retornar true - nunca bloquear
                return true;
            } catch (err) {
                console.error('Erro em prepararLocalAntesDoSubmit:', err);
                return true; // Em caso de erro, prosseguir
            }
        };
        
        // Esperar DOM estar pronto antes de executar código
        document.addEventListener('DOMContentLoaded', function() {
            // Garantir que modal está fechado inicialmente
            const modal = document.getElementById('fieldModal');
            if (modal) {
                modal.classList.remove('active');
            }
        });
        
        // Variável global para armazenar os campos do formulário
        let fields = [];
        
        // Carregar campos iniciais se estiver editando
        <?php if ($is_edit && isset($degustacao) && isset($degustacao['campos_json'])): ?>
        try {
            const camposJsonStr = <?= json_encode($degustacao['campos_json']) ?>;
            if (camposJsonStr && camposJsonStr !== '[]' && camposJsonStr !== '' && camposJsonStr !== null) {
                const parsed = JSON.parse(camposJsonStr);
                fields = Array.isArray(parsed) ? parsed : [];
                console.log('Campos carregados:', fields.length);
            }
        } catch (e) {
            console.warn('Erro ao parsear campos_json:', e);
            fields = [];
        }
        <?php endif; ?>
        
        function showTab(tabName) {
            // Esconder todas as tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Mostrar tab selecionada
            const tabElement = document.getElementById(tabName);
            if (tabElement) {
                tabElement.classList.add('active');
            }
            if (event && event.target) {
            event.target.classList.add('active');
            }
        }
        
        function openFieldModal() {
            const modal = document.getElementById('fieldModal');
            if (modal) {
                modal.classList.add('active');
                // Focar no primeiro campo
                const firstInput = modal.querySelector('input, select');
                if (firstInput) {
                    setTimeout(() => firstInput.focus(), 100);
                }
            }
        }
        
        function closeFieldModal() {
            const modal = document.getElementById('fieldModal');
            if (modal) {
                modal.classList.remove('active');
            }
            // Limpar formulário
            const fieldType = document.getElementById('fieldType');
            const fieldLabel = document.getElementById('fieldLabel');
            const fieldName = document.getElementById('fieldName');
            const fieldRequired = document.getElementById('fieldRequired');
            const fieldOptionsText = document.getElementById('fieldOptionsText');
            
            if (fieldType) fieldType.value = 'texto';
            if (fieldLabel) fieldLabel.value = '';
            if (fieldName) fieldName.value = '';
            if (fieldRequired) fieldRequired.checked = false;
            if (fieldOptionsText) fieldOptionsText.value = '';
            updateFieldForm();
        }
        
        function updateFieldForm() {
            const fieldType = document.getElementById('fieldType').value;
            const optionsDiv = document.getElementById('fieldOptions');
            
            if (['select', 'radio', 'checkbox'].includes(fieldType)) {
                optionsDiv.style.display = 'block';
            } else {
                optionsDiv.style.display = 'none';
            }
        }
        
        function addField() {
            const type = document.getElementById('fieldType').value;
            const label = document.getElementById('fieldLabel').value;
            const name = document.getElementById('fieldName').value;
            const required = document.getElementById('fieldRequired').checked;
            const options = document.getElementById('fieldOptionsText').value.split('\n').filter(o => o.trim());
            
            if (!label || !name) {
                customAlert('Preencha o label e nome do campo', '⚠️ Validação');
                return;
            }
            
            const field = {
                id: Date.now(),
                type: type,
                label: label,
                name: name,
                required: required,
                options: options
            };
            
            fields.push(field);
            renderFields();
            closeFieldModal();
        }
        
        function removeField(fieldId) {
            fields = fields.filter(f => f.id !== fieldId);
            renderFields();
        }
        
        function renderFields() {
            const container = document.getElementById('fieldsList');
            if (!container) {
                console.warn('Container fieldsList não encontrado');
                return;
            }
            
            container.innerHTML = '';
            
            if (fields && Array.isArray(fields)) {
            fields.forEach(field => {
                const fieldDiv = document.createElement('div');
                fieldDiv.className = 'field-item';
                fieldDiv.innerHTML = `
                    <div class="field-info">
                            <div class="field-label">${escapeHtml(field.label || '')} ${field.required ? '*' : ''}</div>
                            <div class="field-type">${escapeHtml(field.type || '')}</div>
                    </div>
                    <div class="field-actions">
                            <button type="button" class="btn-sm btn-delete" onclick="removeField(${field.id || 0})">🗑️</button>
                    </div>
                `;
                container.appendChild(fieldDiv);
            });
            }
            
            // Atualizar campo oculto
            const camposJson = document.getElementById('camposJson');
            if (camposJson) {
                camposJson.value = JSON.stringify(fields || []);
            }
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        
        // Aguardar DOM estar completamente pronto
        function waitForElement(selector, callback, maxAttempts = 50) {
            let attempts = 0;
            const checkElement = () => {
                const element = document.getElementById(selector);
                if (element) {
                    callback();
                } else if (attempts < maxAttempts) {
                    attempts++;
                    setTimeout(checkElement, 100);
                } else {
                    console.warn('Elemento ' + selector + ' não encontrado após espera');
                }
            };
            checkElement();
        }
        
        // Aguardar DOM estar pronto
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() {
                setTimeout(() => initializeForm(), 100);
            });
        } else {
            setTimeout(() => initializeForm(), 100);
        }
        
        function initializeForm() {
            // Garantir que modal está fechado primeiro
            const modal = document.getElementById('fieldModal');
            if (modal) {
                modal.classList.remove('active');
            }
            
            // Garantir que elementos existem antes de usar
            // Aguardar um pouco mais para garantir que tabs estão renderizadas
            setTimeout(function() {
                waitForElement('fieldsList', function() {
                    // Verificar se a tab existe antes de renderizar (não precisa estar visível)
                    const fieldsList = document.getElementById('fieldsList');
                    
                    if (fieldsList) {
                        // Renderizar campos já carregados (carregamento é feito no início do script)
        renderFields();
                    }
                });
            }, 200);
            
            // Se local atual não está nas opções, mostrar campo customizado
            const localAtual = <?= ($is_edit && isset($degustacao)) ? json_encode($degustacao['local'] ?? '') : 'null' ?>;
            const select = document.getElementById('localSelect');
            const localCustom = document.getElementById('localCustom');
            
            if (localAtual && select && localCustom) {
                // Verificar se local está selecionado no select
                let foundInSelect = false;
                for (let i = 0; i < select.options.length; i++) {
                    if (select.options[i].value === localAtual) {
                        foundInSelect = true;
                        select.selectedIndex = i;
                        break;
                    }
                }
                
                if (!foundInSelect && localAtual) {
                    // Se local atual não está na lista, mostrar campo customizado
                    localCustom.style.display = 'block';
                    localCustom.value = localAtual;
                    localCustom.required = true;
                    select.required = false;
                }
            }
            
            // Handler para link de local customizado
            if (localCustom) {
                localCustom.addEventListener('input', function() {
                    if (this.value.trim() && select) {
                        select.value = '';
                    }
                });
            }
            
            // Função prepararLocalAntesDoSubmit já está definida antes do script
            // e é chamada via onsubmit no formulário
        }
        
        // Função para copiar link público na página de edição
        function copiarLinkPublicoEditar() {
            const input = document.getElementById('link-publico-editar');
            if (!input) {
                customAlert('Campo de link não encontrado', 'Erro');
                return;
            }
            
            input.select();
            input.setSelectionRange(0, 99999); // Para dispositivos móveis
            
            try {
                document.execCommand('copy');
                // Feedback visual
                const btn = event.target;
                const originalText = btn.textContent;
                btn.textContent = '✅ Copiado!';
                btn.style.background = '#10b981';
                btn.style.color = 'white';
                
                setTimeout(() => {
                    btn.textContent = originalText;
                    btn.style.background = '';
                    btn.style.color = '';
                }, 2000);
            } catch (err) {
                // Fallback para navegadores modernos
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(input.value).then(() => {
                        const btn = event.target;
                        const originalText = btn.textContent;
                        btn.textContent = '✅ Copiado!';
                        btn.style.background = '#10b981';
                        btn.style.color = 'white';
                        
                        setTimeout(() => {
                            btn.textContent = originalText;
                            btn.style.background = '';
                            btn.style.color = '';
                        }, 2000);
                    }).catch(() => {
                        customAlert('Erro ao copiar link', 'Erro');
                    });
                } else {
                    customAlert('Erro ao copiar link. Seu navegador pode não suportar esta ação.', 'Erro');
                }
            }
        }
    </script>
<?php
$conteudo = ob_get_clean();
require_once __DIR__ . '/sidebar_integration.php';
includeSidebar($is_edit ? 'Editar Degustação' : 'Nova Degustação');
echo $conteudo;
endSidebar();
?>
