<?php
// comercial_degustacao_editar.php — Editor de degustações com Form Builder
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/lc_permissions_enhanced.php';

// Verificar permissões
if (!lc_can_edit_degustacoes()) {
    header('Location: dashboard.php?error=permission_denied');
    exit;
}

$event_id = (int)($_GET['id'] ?? 0);
$is_edit = $event_id > 0;

// Buscar degustação para edição
$degustacao = null;
if ($is_edit) {
    $stmt = $pdo->prepare("SELECT * FROM comercial_degustacoes WHERE id = :id");
    $stmt->execute([':id' => $event_id]);
    $degustacao = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$degustacao) {
        header('Location: comercial_degustacoes.php?error=not_found');
        exit;
    }
}

// Buscar campos padrão
$campos_padrao = [];
$stmt = $pdo->query("SELECT campos_json FROM comercial_campos_padrao WHERE ativo = TRUE ORDER BY criado_em DESC LIMIT 1");
$result = $stmt->fetch(PDO::FETCH_ASSOC);
if ($result && $result['campos_json']) {
    $campos_padrao = json_decode($result['campos_json'], true) ?: [];
}

// Processar formulário
if ($_POST) {
    try {
        $nome = trim($_POST['nome'] ?? '');
        $data = $_POST['data'] ?? '';
        $hora_inicio = $_POST['hora_inicio'] ?? '';
        $hora_fim = $_POST['hora_fim'] ?? '';
        $local = trim($_POST['local'] ?? '');
        $capacidade = (int)($_POST['capacidade'] ?? 50);
        $data_limite = $_POST['data_limite'] ?? '';
        $lista_espera = isset($_POST['lista_espera']) ? 1 : 0;
        
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
        if (!$nome || !$data || !$hora_inicio || !$hora_fim || !$local || !$data_limite) {
            throw new Exception("Preencha todos os campos obrigatórios");
        }
        
        if ($is_edit) {
            // Atualizar degustação existente
            $sql = "UPDATE comercial_degustacoes SET 
                    nome = :nome, data = :data, hora_inicio = :hora_inicio, hora_fim = :hora_fim,
                    local = :local, capacidade = :capacidade, data_limite = :data_limite, lista_espera = :lista_espera,
                    preco_casamento = :preco_casamento, incluidos_casamento = :incluidos_casamento,
                    preco_15anos = :preco_15anos, incluidos_15anos = :incluidos_15anos, preco_extra = :preco_extra,
                    instrutivo_html = :instrutivo_html, email_confirmacao_html = :email_confirmacao_html,
                    msg_sucesso_html = :msg_sucesso_html, campos_json = :campos_json
                    WHERE id = :id";
            
            $params = [
                ':nome' => $nome, ':data' => $data, ':hora_inicio' => $hora_inicio, ':hora_fim' => $hora_fim,
                ':local' => $local, ':capacidade' => $capacidade, ':data_limite' => $data_limite, ':lista_espera' => $lista_espera,
                ':preco_casamento' => $preco_casamento, ':incluidos_casamento' => $incluidos_casamento,
                ':preco_15anos' => $preco_15anos, ':incluidos_15anos' => $incluidos_15anos, ':preco_extra' => $preco_extra,
                ':instrutivo_html' => $instrutivo_html, ':email_confirmacao_html' => $email_confirmacao_html,
                ':msg_sucesso_html' => $msg_sucesso_html, ':campos_json' => $campos_json, ':id' => $event_id
            ];
        } else {
            // Criar nova degustação
            $sql = "INSERT INTO comercial_degustacoes 
                    (nome, data, hora_inicio, hora_fim, local, capacidade, data_limite, lista_espera,
                     preco_casamento, incluidos_casamento, preco_15anos, incluidos_15anos, preco_extra,
                     instrutivo_html, email_confirmacao_html, msg_sucesso_html, campos_json, status, criado_por)
                    VALUES 
                    (:nome, :data, :hora_inicio, :hora_fim, :local, :capacidade, :data_limite, :lista_espera,
                     :preco_casamento, :incluidos_casamento, :preco_15anos, :incluidos_15anos, :preco_extra,
                     :instrutivo_html, :email_confirmacao_html, :msg_sucesso_html, :campos_json, 'rascunho', :criado_por)";
            
            $params = [
                ':nome' => $nome, ':data' => $data, ':hora_inicio' => $hora_inicio, ':hora_fim' => $hora_fim,
                ':local' => $local, ':capacidade' => $capacidade, ':data_limite' => $data_limite, ':lista_espera' => $lista_espera,
                ':preco_casamento' => $preco_casamento, ':incluidos_casamento' => $incluidos_casamento,
                ':preco_15anos' => $preco_15anos, ':incluidos_15anos' => $incluidos_15anos, ':preco_extra' => $preco_extra,
                ':instrutivo_html' => $instrutivo_html, ':email_confirmacao_html' => $email_confirmacao_html,
                ':msg_sucesso_html' => $msg_sucesso_html, ':campos_json' => $campos_json,
                ':criado_por' => $_SESSION['id_usuario'] ?? $_SESSION['id'] ?? 1
            ];
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        if (!$is_edit) {
            $event_id = $pdo->lastInsertId();
        }
        
        // Salvar como padrão se solicitado
        if ($usar_como_padrao) {
            $stmt = $pdo->prepare("INSERT INTO comercial_campos_padrao (campos_json, criado_por) VALUES (:campos_json, :criado_por)");
            $stmt->execute([
                ':campos_json' => $campos_json,
                ':criado_por' => $_SESSION['id_usuario'] ?? $_SESSION['id'] ?? 1
            ]);
        }
        
        $success_message = $is_edit ? "Degustação atualizada com sucesso!" : "Degustação criada com sucesso!";
        
    } catch (Exception $e) {
        $error_message = "Erro: " . $e->getMessage();
    }
}

// Buscar token público se for edição
$token_publico = '';
if ($is_edit && $degustacao) {
    $token_publico = $degustacao['token_publico'];
}


?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $is_edit ? 'Editar' : 'Nova' ?> Degustação - GRUPO Smile EVENTOS</title>
    <link rel="stylesheet" href="estilo.css">
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
</head>
<body>
    <?php if (is_file(__DIR__.'/sidebar.php')) { include __DIR__.'/sidebar.php'; } ?>
    
    <div class="main-content">
        <div class="editor-container">
            <!-- Header -->
            <div class="page-header">
                <h1 class="page-title"><?= $is_edit ? '✏️ Editar' : '➕ Nova' ?> Degustação</h1>
                <a href="comercial_degustacoes.php" class="btn-secondary">
                    ← Voltar
                </a>
            </div>
            
            <!-- Mensagens -->
            <?php if (isset($success_message)): ?>
                <div class="alert alert-success">
                    ✅ <?= h($success_message) ?>
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
            
            <form method="POST" id="degustacaoForm">
                <!-- Tab Geral -->
                <div id="geral" class="tab-content active">
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Nome da Degustação *</label>
                            <input type="text" name="nome" class="form-input" required 
                                   value="<?= $is_edit ? h($degustacao['nome']) : '' ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Data *</label>
                            <input type="date" name="data" class="form-input" required 
                                   value="<?= $is_edit ? $degustacao['data'] : '' ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Hora Início *</label>
                            <input type="time" name="hora_inicio" class="form-input" required 
                                   value="<?= $is_edit ? $degustacao['hora_inicio'] : '' ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Hora Fim *</label>
                            <input type="time" name="hora_fim" class="form-input" required 
                                   value="<?= $is_edit ? $degustacao['hora_fim'] : '' ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Local *</label>
                            <input type="text" name="local" class="form-input" required 
                                   value="<?= $is_edit ? h($degustacao['local']) : '' ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Capacidade *</label>
                            <input type="number" name="capacidade" class="form-input" required min="1" 
                                   value="<?= $is_edit ? $degustacao['capacidade'] : '50' ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Data Limite de Inscrição *</label>
                            <input type="date" name="data_limite" class="form-input" required 
                                   value="<?= $is_edit ? $degustacao['data_limite'] : '' ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Aceitar Lista de Espera</label>
                            <div class="form-checkbox">
                                <input type="checkbox" name="lista_espera" id="lista_espera" 
                                       <?= ($is_edit && $degustacao['lista_espera']) || !$is_edit ? 'checked' : '' ?>>
                                <label for="lista_espera">Permitir lista de espera quando lotado</label>
                            </div>
                        </div>
                    </div>
                    
                    <h3 style="margin-top: 30px; margin-bottom: 20px; color: #1e3a8a;">💰 Preços</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Preço Casamento (R$)</label>
                            <input type="number" name="preco_casamento" class="form-input" step="0.01" min="0" 
                                   value="<?= $is_edit ? $degustacao['preco_casamento'] : '150.00' ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Pessoas Incluídas (Casamento)</label>
                            <input type="number" name="incluidos_casamento" class="form-input" min="1" 
                                   value="<?= $is_edit ? $degustacao['incluidos_casamento'] : '2' ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Preço 15 Anos (R$)</label>
                            <input type="number" name="preco_15anos" class="form-input" step="0.01" min="0" 
                                   value="<?= $is_edit ? $degustacao['preco_15anos'] : '180.00' ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Pessoas Incluídas (15 Anos)</label>
                            <input type="number" name="incluidos_15anos" class="form-input" min="1" 
                                   value="<?= $is_edit ? $degustacao['incluidos_15anos'] : '3' ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Preço por Pessoa Extra (R$)</label>
                            <input type="number" name="preco_extra" class="form-input" step="0.01" min="0" 
                                   value="<?= $is_edit ? $degustacao['preco_extra'] : '50.00' ?>">
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
                        <textarea name="instrutivo_html" class="form-textarea" placeholder="Instruções que aparecerão no topo da página pública..."><?= $is_edit ? h($degustacao['instrutivo_html']) : '' ?></textarea>
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">E-mail de Confirmação (HTML)</label>
                        <textarea name="email_confirmacao_html" class="form-textarea" placeholder="Conteúdo do e-mail de confirmação..."><?= $is_edit ? h($degustacao['email_confirmacao_html']) : '' ?></textarea>
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">Mensagem de Sucesso (HTML)</label>
                        <textarea name="msg_sucesso_html" class="form-textarea" placeholder="Mensagem exibida após inscrição..."><?= $is_edit ? h($degustacao['msg_sucesso_html']) : '' ?></textarea>
                    </div>
                </div>
                
                <!-- Campos ocultos -->
                <input type="hidden" name="campos_json" id="camposJson" value="<?= $is_edit ? h($degustacao['campos_json']) : '[]' ?>">
                
                <!-- Ações -->
                <div class="form-actions">
                    <button type="submit" class="btn-primary">💾 Salvar</button>
                    <?php if ($is_edit): ?>
                    <a href="comercial_degust_public.php?t=<?= $token_publico ?>" class="btn-secondary" target="_blank">
                        🔗 Ver Link Público
                    </a>
                    <?php endif; ?>
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
        let fields = <?= $is_edit ? $degustacao['campos_json'] : '[]' ?>;
        
        function showTab(tabName) {
            // Esconder todas as tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Mostrar tab selecionada
            document.getElementById(tabName).classList.add('active');
            event.target.classList.add('active');
        }
        
        function openFieldModal() {
            document.getElementById('fieldModal').classList.add('active');
        }
        
        function closeFieldModal() {
            document.getElementById('fieldModal').classList.remove('active');
            // Limpar formulário
            document.getElementById('fieldType').value = 'texto';
            document.getElementById('fieldLabel').value = '';
            document.getElementById('fieldName').value = '';
            document.getElementById('fieldRequired').checked = false;
            document.getElementById('fieldOptionsText').value = '';
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
                alert('Preencha o label e nome do campo');
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
            container.innerHTML = '';
            
            fields.forEach(field => {
                const fieldDiv = document.createElement('div');
                fieldDiv.className = 'field-item';
                fieldDiv.innerHTML = `
                    <div class="field-info">
                        <div class="field-label">${field.label} ${field.required ? '*' : ''}</div>
                        <div class="field-type">${field.type}</div>
                    </div>
                    <div class="field-actions">
                        <button type="button" class="btn-sm btn-delete" onclick="removeField(${field.id})">🗑️</button>
                    </div>
                `;
                container.appendChild(fieldDiv);
            });
            
            // Atualizar campo oculto
            document.getElementById('camposJson').value = JSON.stringify(fields);
        }
        
        // Carregar campos iniciais
        renderFields();
    </script>
</body>
</html>
