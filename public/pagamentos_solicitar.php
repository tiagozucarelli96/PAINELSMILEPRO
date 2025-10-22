<?php
// pagamentos_solicitar.php
// Página para solicitar pagamentos (Gerentes)

session_start();
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/lc_permissions_helper.php';
require_once __DIR__ . '/lc_anexos_helper.php';

// Verificar permissões
$perfil = lc_get_user_perfil();
if (!in_array($perfil, ['ADM', 'FIN', 'GERENTE'])) {
    header('Location: dashboard.php?erro=permissao_negada');
    exit;
}

$sucesso = $_GET['sucesso'] ?? null;
$erro = $_GET['erro'] ?? null;

// Processar formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $beneficiario_tipo = $_POST['beneficiario_tipo'] ?? '';
        $valor = floatval($_POST['valor'] ?? 0);
        $data_desejada = $_POST['data_desejada'] ?: null;
        $observacoes = trim($_POST['observacoes'] ?? '');
        
        // Validações básicas
        if (empty($beneficiario_tipo) || !in_array($beneficiario_tipo, ['freelancer', 'fornecedor'])) {
            throw new Exception('Tipo de beneficiário inválido');
        }
        
        if ($valor <= 0) {
            throw new Exception('Valor deve ser maior que zero');
        }
        
        $freelancer_id = null;
        $fornecedor_id = null;
        $pix_tipo = null;
        $pix_chave = null;
        
        if ($beneficiario_tipo === 'freelancer') {
            $freelancer_id = intval($_POST['freelancer_id'] ?? 0);
            if ($freelancer_id <= 0) {
                throw new Exception('Selecione um freelancer');
            }
            
            // Buscar dados do freelancer
            $stmt = $pdo->prepare("SELECT pix_tipo, pix_chave FROM lc_freelancers WHERE id = ? AND ativo = true");
            $stmt->execute([$freelancer_id]);
            $freelancer = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$freelancer) {
                throw new Exception('Freelancer não encontrado');
            }
            
            $pix_tipo = $freelancer['pix_tipo'];
            $pix_chave = $freelancer['pix_chave'];
            
        } elseif ($beneficiario_tipo === 'fornecedor') {
            $fornecedor_id = intval($_POST['fornecedor_id'] ?? 0);
            if ($fornecedor_id <= 0) {
                throw new Exception('Selecione um fornecedor');
            }
            
            // Buscar dados do fornecedor
            $stmt = $pdo->prepare("SELECT pix_tipo, pix_chave FROM fornecedores WHERE id = ? AND ativo = true");
            $stmt->execute([$fornecedor_id]);
            $fornecedor = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$fornecedor) {
                throw new Exception('Fornecedor não encontrado');
            }
            
            $pix_tipo = $fornecedor['pix_tipo'];
            $pix_chave = $fornecedor['pix_chave'];
        }
        
        // Criar solicitação
        $stmt = $pdo->prepare("
            INSERT INTO lc_solicitacoes_pagamento 
            (criador_id, beneficiario_tipo, freelancer_id, fornecedor_id, valor, data_desejada, observacoes, pix_tipo, pix_chave)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $_SESSION['usuario_id'] ?? null,
            $beneficiario_tipo,
            $freelancer_id,
            $fornecedor_id,
            $valor,
            $data_desejada,
            $observacoes,
            $pix_tipo,
            $pix_chave
        ]);
        
        $solicitacao_id = $pdo->lastInsertId();
        
        // Processar anexos se houver
        $anexos_manager = new LcAnexosManager($pdo);
        $anexos_processados = 0;
        
        if (isset($_FILES['anexos']) && is_array($_FILES['anexos']['name'])) {
            for ($i = 0; $i < count($_FILES['anexos']['name']); $i++) {
                if ($_FILES['anexos']['error'][$i] === UPLOAD_ERR_OK) {
                    $arquivo = [
                        'name' => $_FILES['anexos']['name'][$i],
                        'type' => $_FILES['anexos']['type'][$i],
                        'tmp_name' => $_FILES['anexos']['tmp_name'][$i],
                        'error' => $_FILES['anexos']['error'][$i],
                        'size' => $_FILES['anexos']['size'][$i]
                    ];
                    
                    $resultado = $anexos_manager->fazerUpload(
                        $arquivo, 
                        $solicitacao_id, 
                        $_SESSION['usuario_id'], 
                        'interno'
                    );
                    
                    if ($resultado['sucesso']) {
                        $anexos_processados++;
                    }
                }
            }
        }
        
        // Criar evento na timeline
        $mensagem_timeline = 'Solicitação criada';
        if ($anexos_processados > 0) {
            $mensagem_timeline .= " com {$anexos_processados} anexo(s)";
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO lc_timeline_pagamentos (solicitacao_id, autor_id, tipo_evento, mensagem)
            VALUES (?, ?, 'criacao', ?)
        ");
        $stmt->execute([$solicitacao_id, $_SESSION['usuario_id'] ?? null, $mensagem_timeline]);
        
        header('Location: pagamentos_minhas.php?sucesso=solicitacao_criada&id=' . $solicitacao_id);
        exit;
        
    } catch (Exception $e) {
        $erro = $e->getMessage();
    }
}

// Buscar freelancers ativos
$freelancers = [];
try {
    $stmt = $pdo->query("SELECT id, nome_completo, cpf, pix_tipo, pix_chave FROM lc_freelancers WHERE ativo = true ORDER BY nome_completo");
    $freelancers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Tabela pode não existir ainda
}

// Buscar fornecedores ativos
$fornecedores = [];
try {
    $stmt = $pdo->query("SELECT id, nome, cnpj, pix_tipo, pix_chave FROM fornecedores WHERE ativo = true ORDER BY nome");
    $fornecedores = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Tabela pode não existir ainda
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Solicitar Pagamento - Sistema Smile</title>
    <link rel="stylesheet" href="estilo.css">
    <link rel="stylesheet" href="css/smile-ui.css">
    <style>
        .form-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .form-section {
            background: white;
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .beneficiario-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 30px;
        }
        
        .tab-button {
            flex: 1;
            padding: 15px;
            border: 2px solid #e1e5e9;
            background: white;
            border-radius: 8px;
            cursor: pointer;
            text-align: center;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .tab-button.active {
            border-color: #1e40af;
            background: #1e40af;
            color: white;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .pix-info {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 15px;
            margin-top: 10px;
            font-size: 14px;
        }
        
        .pix-info strong {
            color: #1e40af;
        }
        
        /* Estilos para anexos */
        .anexos-container {
            margin-top: 10px;
        }
        
        .anexos-dropzone {
            border: 2px dashed #d1d5db;
            border-radius: 8px;
            padding: 30px;
            text-align: center;
            background: #f9fafb;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .anexos-dropzone:hover {
            border-color: #1e40af;
            background: #f0f9ff;
        }
        
        .anexos-dropzone.dragover {
            border-color: #1e40af;
            background: #dbeafe;
        }
        
        .dropzone-content {
            pointer-events: none;
        }
        
        .dropzone-icon {
            font-size: 32px;
            margin-bottom: 10px;
        }
        
        .dropzone-info {
            font-size: 14px;
            color: #64748b;
            margin-top: 5px;
        }
        
        .anexos-lista {
            margin-top: 20px;
            padding: 15px;
            background: #f8fafc;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }
        
        .anexos-lista h4 {
            margin: 0 0 15px 0;
            color: #374151;
            font-size: 16px;
        }
        
        .anexo-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px;
            background: white;
            border-radius: 6px;
            margin-bottom: 8px;
            border: 1px solid #e5e7eb;
        }
        
        .anexo-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .anexo-icon {
            font-size: 20px;
        }
        
        .anexo-detalhes {
            display: flex;
            flex-direction: column;
        }
        
        .anexo-nome {
            font-weight: 500;
            color: #374151;
        }
        
        .anexo-tamanho {
            font-size: 12px;
            color: #64748b;
        }
        
        .anexo-remover {
            background: #dc2626;
            color: white;
            border: none;
            border-radius: 4px;
            padding: 4px 8px;
            cursor: pointer;
            font-size: 12px;
        }
        
        .anexo-remover:hover {
            background: #b91c1c;
        }
        
        .anexo-erro {
            color: #dc2626;
            font-size: 12px;
            margin-top: 5px;
        }
    </style>
</head>
<body>
    <div class="smile-container">
        <div class="smile-card">
            <div class="smile-card-header">
                <h1>💰 Solicitar Pagamento</h1>
                <p>Crie uma nova solicitação de pagamento para freelancer ou fornecedor</p>
            </div>
            
            <div class="smile-card-body">
                <!-- Mensagens -->
                <?php if ($sucesso): ?>
                    <div class="smile-alert smile-alert-success">
                        ✅ <?= htmlspecialchars($sucesso) ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($erro): ?>
                    <div class="smile-alert smile-alert-danger">
                        ❌ <?= htmlspecialchars($erro) ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <div class="form-section">
                        <h3>👤 Tipo de Beneficiário</h3>
                        <div class="beneficiario-tabs">
                            <div class="tab-button active" data-tab="freelancer">
                                👨‍💼 Freelancer
                            </div>
                            <div class="tab-button" data-tab="fornecedor">
                                🏢 Fornecedor
                            </div>
                        </div>
                        
                        <input type="hidden" name="beneficiario_tipo" id="beneficiario_tipo" value="freelancer">
                        
                        <!-- Freelancer Tab -->
                        <div class="tab-content active" id="freelancer-tab">
                            <div class="smile-form-group">
                                <label for="freelancer_id">Freelancer *</label>
                                <select name="freelancer_id" id="freelancer_id" class="smile-form-control" required>
                                    <option value="">Selecione um freelancer</option>
                                    <?php foreach ($freelancers as $freelancer): ?>
                                        <option value="<?= $freelancer['id'] ?>" 
                                                data-pix-tipo="<?= htmlspecialchars($freelancer['pix_tipo']) ?>"
                                                data-pix-chave="<?= htmlspecialchars($freelancer['pix_chave']) ?>">
                                            <?= htmlspecialchars($freelancer['nome_completo']) ?> 
                                            (<?= htmlspecialchars($freelancer['cpf']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="pix-info" id="freelancer-pix-info" style="display: none;">
                                    <strong>PIX:</strong> <span id="freelancer-pix-details"></span>
                                </div>
                            </div>
                            
                            <div style="text-align: center; margin: 20px 0;">
                                <a href="freelancer_cadastro.php" class="smile-btn smile-btn-secondary">
                                    ➕ Novo Freelancer
                                </a>
                            </div>
                        </div>
                        
                        <!-- Fornecedor Tab -->
                        <div class="tab-content" id="fornecedor-tab">
                            <div class="smile-form-group">
                                <label for="fornecedor_id">Fornecedor *</label>
                                <select name="fornecedor_id" id="fornecedor_id" class="smile-form-control">
                                    <option value="">Selecione um fornecedor</option>
                                    <?php foreach ($fornecedores as $fornecedor): ?>
                                        <option value="<?= $fornecedor['id'] ?>" 
                                                data-pix-tipo="<?= htmlspecialchars($fornecedor['pix_tipo']) ?>"
                                                data-pix-chave="<?= htmlspecialchars($fornecedor['pix_chave']) ?>">
                                            <?= htmlspecialchars($fornecedor['nome']) ?> 
                                            <?php if ($fornecedor['cnpj']): ?>
                                                (<?= htmlspecialchars($fornecedor['cnpj']) ?>)
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="pix-info" id="fornecedor-pix-info" style="display: none;">
                                    <strong>PIX:</strong> <span id="fornecedor-pix-details"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <h3>💰 Dados do Pagamento</h3>
                        
                        <div class="smile-form-group">
                            <label for="valor">Valor (R$) *</label>
                            <input type="number" name="valor" id="valor" step="0.01" min="0.01" 
                                   class="smile-form-control" required>
                        </div>
                        
                        <div class="smile-form-group">
                            <label for="data_desejada">Data Desejada (opcional)</label>
                            <input type="date" name="data_desejada" id="data_desejada" 
                                   class="smile-form-control">
                        </div>
                        
                        <div class="smile-form-group">
                            <label for="observacoes">Observações</label>
                            <textarea name="observacoes" id="observacoes" rows="4" 
                                      class="smile-form-control" 
                                      placeholder="Descreva o motivo do pagamento, referência, etc."></textarea>
                        </div>
                        
                        <!-- Seção de Anexos -->
                        <div class="smile-form-group">
                            <label>Anexos (opcional)</label>
                            <div class="anexos-container">
                                <div class="anexos-dropzone" id="anexos-dropzone">
                                    <div class="dropzone-content">
                                        <div class="dropzone-icon">📎</div>
                                        <p>Arraste arquivos aqui ou clique para selecionar</p>
                                        <p class="dropzone-info">PDF, JPG, PNG. Máx 10 MB cada, até 5 arquivos</p>
                                    </div>
                                    <input type="file" name="anexos[]" id="anexos-input" multiple 
                                           accept=".pdf,.jpg,.jpeg,.png" style="display: none;">
                                </div>
                                
                                <div class="anexos-lista" id="anexos-lista" style="display: none;">
                                    <h4>Arquivos selecionados:</h4>
                                    <div class="anexos-items" id="anexos-items"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div style="display: flex; gap: 15px; justify-content: flex-end; margin-top: 30px;">
                        <a href="pagamentos_minhas.php" class="smile-btn smile-btn-secondary">
                            Cancelar
                        </a>
                        <button type="submit" class="smile-btn smile-btn-primary">
                            💰 Enviar Solicitação
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Tabs functionality
        document.querySelectorAll('.tab-button').forEach(button => {
            button.addEventListener('click', function() {
                const tab = this.dataset.tab;
                
                // Update active tab button
                document.querySelectorAll('.tab-button').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                
                // Update active tab content
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                document.getElementById(tab + '-tab').classList.add('active');
                
                // Update hidden input
                document.getElementById('beneficiario_tipo').value = tab;
                
                // Clear selections
                document.getElementById('freelancer_id').value = '';
                document.getElementById('fornecedor_id').value = '';
                document.getElementById('freelancer-pix-info').style.display = 'none';
                document.getElementById('fornecedor-pix-info').style.display = 'none';
            });
        });
        
        // Freelancer selection
        document.getElementById('freelancer_id').addEventListener('change', function() {
            const option = this.options[this.selectedIndex];
            const pixInfo = document.getElementById('freelancer-pix-info');
            const pixDetails = document.getElementById('freelancer-pix-details');
            
            if (option.value && option.dataset.pixTipo && option.dataset.pixChave) {
                pixDetails.textContent = `${option.dataset.pixTipo.toUpperCase()}: ${option.dataset.pixChave}`;
                pixInfo.style.display = 'block';
            } else {
                pixInfo.style.display = 'none';
            }
        });
        
        // Fornecedor selection
        document.getElementById('fornecedor_id').addEventListener('change', function() {
            const option = this.options[this.selectedIndex];
            const pixInfo = document.getElementById('fornecedor-pix-info');
            const pixDetails = document.getElementById('fornecedor-pix-details');
            
            if (option.value && option.dataset.pixTipo && option.dataset.pixChave) {
                pixDetails.textContent = `${option.dataset.pixTipo.toUpperCase()}: ${option.dataset.pixChave}`;
                pixInfo.style.display = 'block';
            } else {
                pixInfo.style.display = 'none';
            }
        });
        
        // Sistema de anexos
        const anexosSelecionados = [];
        const maxAnexos = 5;
        const maxTamanho = 10 * 1024 * 1024; // 10 MB
        const maxTamanhoTotal = 25 * 1024 * 1024; // 25 MB
        
        const dropzone = document.getElementById('anexos-dropzone');
        const inputFile = document.getElementById('anexos-input');
        const listaAnexos = document.getElementById('anexos-lista');
        const itemsAnexos = document.getElementById('anexos-items');
        
        // Configurar dropzone
        dropzone.addEventListener('click', () => inputFile.click());
        dropzone.addEventListener('dragover', handleDragOver);
        dropzone.addEventListener('dragleave', handleDragLeave);
        dropzone.addEventListener('drop', handleDrop);
        
        inputFile.addEventListener('change', handleFileSelect);
        
        function handleDragOver(e) {
            e.preventDefault();
            dropzone.classList.add('dragover');
        }
        
        function handleDragLeave(e) {
            e.preventDefault();
            dropzone.classList.remove('dragover');
        }
        
        function handleDrop(e) {
            e.preventDefault();
            dropzone.classList.remove('dragover');
            const files = Array.from(e.dataTransfer.files);
            processarArquivos(files);
        }
        
        function handleFileSelect(e) {
            const files = Array.from(e.target.files);
            processarArquivos(files);
        }
        
        function processarArquivos(files) {
            files.forEach(file => {
                if (anexosSelecionados.length >= maxAnexos) {
                    alert('Limite de 5 anexos atingido');
                    return;
                }
                
                if (file.size > maxTamanho) {
                    alert(`Arquivo ${file.name} excede 10 MB`);
                    return;
                }
                
                const tamanhoTotal = anexosSelecionados.reduce((sum, anexo) => sum + anexo.size, 0);
                if (tamanhoTotal + file.size > maxTamanhoTotal) {
                    alert('Limite de 25 MB total atingido');
                    return;
                }
                
                const extensao = file.name.split('.').pop().toLowerCase();
                if (!['pdf', 'jpg', 'jpeg', 'png'].includes(extensao)) {
                    alert(`Tipo não permitido: ${file.name}. Use PDF/JPG/PNG`);
                    return;
                }
                
                anexosSelecionados.push(file);
                renderizarAnexos();
            });
        }
        
        function renderizarAnexos() {
            if (anexosSelecionados.length === 0) {
                listaAnexos.style.display = 'none';
                return;
            }
            
            listaAnexos.style.display = 'block';
            itemsAnexos.innerHTML = '';
            
            anexosSelecionados.forEach((file, index) => {
                const item = document.createElement('div');
                item.className = 'anexo-item';
                item.innerHTML = `
                    <div class="anexo-info">
                        <div class="anexo-icon">${getFileIcon(file.name)}</div>
                        <div class="anexo-detalhes">
                            <div class="anexo-nome">${file.name}</div>
                            <div class="anexo-tamanho">${formatFileSize(file.size)}</div>
                        </div>
                    </div>
                    <button type="button" class="anexo-remover" onclick="removerAnexo(${index})">Remover</button>
                `;
                itemsAnexos.appendChild(item);
            });
        }
        
        function getFileIcon(filename) {
            const ext = filename.split('.').pop().toLowerCase();
            switch (ext) {
                case 'pdf': return '📄';
                case 'jpg':
                case 'jpeg': return '🖼️';
                case 'png': return '🖼️';
                default: return '📎';
            }
        }
        
        function formatFileSize(bytes) {
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / 1048576).toFixed(1) + ' MB';
        }
        
        function removerAnexo(index) {
            anexosSelecionados.splice(index, 1);
            renderizarAnexos();
        }
        
        // Form validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const beneficiarioTipo = document.getElementById('beneficiario_tipo').value;
            const freelancerId = document.getElementById('freelancer_id').value;
            const fornecedorId = document.getElementById('fornecedor_id').value;
            
            if (beneficiarioTipo === 'freelancer' && !freelancerId) {
                e.preventDefault();
                alert('Selecione um freelancer');
                return;
            }
            
            if (beneficiarioTipo === 'fornecedor' && !fornecedorId) {
                e.preventDefault();
                alert('Selecione um fornecedor');
                return;
            }
        });
    </script>
</body>
</html>
