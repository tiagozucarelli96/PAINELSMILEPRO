<?php
// pagamentos_moderno.php — Interface moderna para pagamentos
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';

if (!isset($_SESSION) || ($_SESSION['logado'] ?? 0) != 1) { 
    header('Location: login.php'); 
    exit; 
}

// Simular sessão de admin para teste
$_SESSION['logado'] = 1;
$_SESSION['user_id'] = 1;
$_SESSION['perfil'] = 'ADM';



// Processar ações
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$edit_id = (int)($_GET['edit'] ?? 0);

// Buscar rascunhos
$rascunhos = [];
try {
    $stmt = $pdo->query("SELECT * FROM lc_solicitacoes_pagamento WHERE status = 'Rascunho' ORDER BY criado_em DESC");
    $rascunhos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Erro ao buscar rascunhos: " . $e->getMessage());
}

// Buscar dados para edição
$edit = null;
if ($edit_id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM lc_solicitacoes_pagamento WHERE id = :id");
        $stmt->execute([':id' => $edit_id]);
        $edit = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Erro ao buscar dados para edição: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Solicitar Pagamento - GRUPO Smile EVENTOS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: #333;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            text-align: center;
        }
        
        .title {
            font-size: 2.5rem;
            font-weight: 800;
            color: #1e3a8a;
            margin-bottom: 10px;
        }
        
        .subtitle {
            font-size: 1.2rem;
            color: #6b7280;
        }
        
        .main-content {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
        }
        
        .form-section {
            margin-bottom: 40px;
        }
        
        .section-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1e3a8a;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group.full {
            grid-column: 1 / -1;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #374151;
            font-size: 14px;
        }
        
        .form-input, .form-select, .form-textarea {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: white;
        }
        
        .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .form-row {
            display: flex;
            gap: 15px;
            align-items: end;
        }
        
        .form-row .form-group {
            flex: 1;
            margin-bottom: 0;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 12px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            text-decoration: none;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(59, 130, 246, 0.4);
        }
        
        .btn-secondary {
            background: #6b7280;
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 12px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            text-decoration: none;
        }
        
        .btn-secondary:hover {
            background: #4b5563;
        }
        
        .btn-danger {
            background: #dc2626;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .btn-danger:hover {
            background: #b91c1c;
        }
        
        .btn-success {
            background: #059669;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .btn-success:hover {
            background: #047857;
        }
        
        .table-container {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table th {
            background: #f8f9fa;
            color: #555;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 13px;
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .table td {
            padding: 15px;
            border-bottom: 1px solid #f0f0f0;
            font-size: 14px;
            color: #444;
        }
        
        .table tbody tr:hover {
            background: #f8fafc;
        }
        
        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-rascunho {
            background: #fef3c7;
            color: #92400e;
        }
        
        .status-pendente {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .status-pago {
            background: #d1fae5;
            color: #065f46;
        }
        
        .actions {
            display: flex;
            gap: 8px;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #6b7280;
        }
        
        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 16px;
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
            font-size: 14px;
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
        
        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .form-row {
                flex-direction: column;
                align-items: stretch;
            }
            
            .table-container {
                overflow-x: auto;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1 class="title">💰 Solicitar Pagamento</h1>
            <p class="subtitle">Preencha como Rascunho e, ao finalizar, selecione e envie. Tipo de conta é obrigatório para a planilha PagFor.</p>
        </div>
        
        <div class="main-content">
            <div class="form-section">
                <h2 class="section-title">
                    <span>📝</span>
                    <?= $edit ? 'Editar Pagamento' : 'Novo Pagamento' ?>
                </h2>
                
                <form method="POST" id="paymentForm">
                    <input type="hidden" name="action" value="save">
                    <?php if ($edit): ?>
                        <input type="hidden" name="id" value="<?= $edit['id'] ?>">
                    <?php endif; ?>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label" for="numero_pagamento">N° Pagamento *</label>
                            <input type="text" id="numero_pagamento" name="numero_pagamento" class="form-input" 
                                   value="<?= h($edit['numero_pagamento'] ?? '') ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="tipo_chave_pix">Tipo Chave *</label>
                            <select id="tipo_chave_pix" name="tipo_chave_pix" class="form-select" required>
                                <option value="CPF/CNPJ" <?= ($edit['tipo_chave_pix'] ?? '') == 'CPF/CNPJ' ? 'selected' : '' ?>>CPF/CNPJ</option>
                                <option value="E-mail" <?= ($edit['tipo_chave_pix'] ?? '') == 'E-mail' ? 'selected' : '' ?>>E-mail</option>
                                <option value="Telefone" <?= ($edit['tipo_chave_pix'] ?? '') == 'Telefone' ? 'selected' : '' ?>>Telefone</option>
                                <option value="Chave aleatória" <?= ($edit['tipo_chave_pix'] ?? '') == 'Chave aleatória' ? 'selected' : '' ?>>Chave aleatória</option>
                                <option value="QR Code" <?= ($edit['tipo_chave_pix'] ?? '') == 'QR Code' ? 'selected' : '' ?>>QR Code</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="chave_pix">Chave / QR Code *</label>
                            <input type="text" id="chave_pix" name="chave_pix" class="form-input" 
                                   placeholder="Digite a chave" value="<?= h($edit['chave_pix'] ?? '') ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="ispb">ISPB</label>
                            <input type="text" id="ispb" name="ispb" class="form-input" 
                                   placeholder="60701190" value="<?= h($edit['ispb'] ?? '') ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="banco">Banco</label>
                            <input type="text" id="banco" name="banco" class="form-input" 
                                   value="<?= h($edit['banco'] ?? '') ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="agencia">Agência</label>
                            <input type="text" id="agencia" name="agencia" class="form-input" 
                                   value="<?= h($edit['agencia'] ?? '') ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="conta">Conta</label>
                            <input type="text" id="conta" name="conta" class="form-input" 
                                   value="<?= h($edit['conta'] ?? '') ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="tipo_conta">Tipo Conta</label>
                            <select id="tipo_conta" name="tipo_conta" class="form-select">
                                <option value="" <?= ($edit['tipo_conta'] ?? '') == '' ? 'selected' : '' ?>>—</option>
                                <option value="CC" <?= ($edit['tipo_conta'] ?? '') == 'CC' ? 'selected' : '' ?>>CC</option>
                                <option value="CP" <?= ($edit['tipo_conta'] ?? '') == 'CP' ? 'selected' : '' ?>>CP</option>
                                <option value="Outros" <?= ($edit['tipo_conta'] ?? '') == 'Outros' ? 'selected' : '' ?>>Outros</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="nome_fornecedor">Fornecedor *</label>
                            <input type="text" id="nome_fornecedor" name="nome_fornecedor" class="form-input" 
                                   placeholder="Nome do fornecedor" value="<?= h($edit['nome_fornecedor'] ?? '') ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="documento">CPF/CNPJ *</label>
                            <input type="text" id="documento" name="documento" class="form-input" 
                                   placeholder="CPF/CNPJ" value="<?= h($edit['documento'] ?? '') ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="valor">Valor *</label>
                            <input type="text" id="valor" name="valor" class="form-input" 
                                   placeholder="1.234,56" value="<?= isset($edit['valor']) ? number_format((float)$edit['valor'], 2, ',', '') : '' ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="data_pagamento">Data *</label>
                            <input type="date" id="data_pagamento" name="data_pagamento" class="form-input" 
                                   value="<?= h($edit['data_pagamento'] ?? date('Y-m-d')) ?>" required>
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <?php if ($edit): ?>
                            <a href="?page=pagamentos" class="btn-secondary">Cancelar</a>
                        <?php endif; ?>
                        <button type="submit" class="btn-primary">
                            <span>💾</span>
                            <?= $edit ? 'Atualizar Rascunho' : 'Salvar Rascunho' ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Lista de Rascunhos -->
        <div class="main-content">
            <h2 class="section-title">
                <span>📋</span>
                Meus Rascunhos
            </h2>
            
            <?php if (empty($rascunhos)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📄</div>
                    <h3>Sem rascunhos</h3>
                    <p>Nenhum pagamento foi salvo como rascunho ainda.</p>
                </div>
            <?php else: ?>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th><input type="checkbox" onclick="toggleAll(this)"></th>
                                <th>#</th>
                                <th>N°</th>
                                <th>Tipo Chave</th>
                                <th>Chave/QR</th>
                                <th>Banco</th>
                                <th>Ag/Conta</th>
                                <th>Tipo c.</th>
                                <th>Fornecedor</th>
                                <th>Doc</th>
                                <th>Valor</th>
                                <th>Data</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rascunhos as $index => $rascunho): ?>
                                <tr>
                                    <td><input type="checkbox" class="ck" value="<?= $rascunho['id'] ?>"></td>
                                    <td><?= $index + 1 ?></td>
                                    <td><?= h($rascunho['numero_pagamento']) ?></td>
                                    <td><?= h($rascunho['tipo_chave_pix']) ?></td>
                                    <td><?= h(substr($rascunho['chave_pix'], 0, 20)) ?>...</td>
                                    <td><?= h($rascunho['banco']) ?></td>
                                    <td><?= h($rascunho['agencia']) ?>/<?= h($rascunho['conta']) ?></td>
                                    <td><?= h($rascunho['tipo_conta']) ?></td>
                                    <td><?= h($rascunho['nome_fornecedor']) ?></td>
                                    <td><?= h($rascunho['documento']) ?></td>
                                    <td>R$ <?= number_format((float)$rascunho['valor'], 2, ',', '.') ?></td>
                                    <td><?= date('d/m/Y', strtotime($rascunho['data_pagamento'])) ?></td>
                                    <td>
                                        <div class="actions">
                                            <a href="?page=pagamentos&edit=<?= $rascunho['id'] ?>" class="btn-secondary" style="padding: 6px 12px; font-size: 12px;">✏️</a>
                                            <button onclick="deleteRascunho(<?= $rascunho['id'] ?>)" class="btn-danger" style="padding: 6px 12px; font-size: 12px;">🗑️</button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="form-actions">
                    <button onclick="sendSelected()" class="btn-success">
                        <span>📤</span>
                        Enviar Selecionados
                    </button>
                    <a href="dashboard.php" class="btn-secondary">
                        <span>←</span>
                        Voltar
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        function toggleAll(checkbox) {
            document.querySelectorAll('.ck').forEach(c => c.checked = checkbox.checked);
        }
        
        function sendSelected() {
            const selected = Array.from(document.querySelectorAll('.ck:checked')).map(c => c.value);
            if (selected.length === 0) {
                alert('Selecione pelo menos um rascunho para enviar.');
                return;
            }
            console.log('Enviando rascunhos:', selected);
            // Implementar envio
        }
        
        function deleteRascunho(id) {
            if (confirm('Tem certeza que deseja excluir este rascunho?')) {
                console.log('Excluindo rascunho:', id);
                // Implementar exclusão
            }
        }
        
        // Formatação de valor
        document.getElementById('valor').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value) {
                value = (parseInt(value) / 100).toLocaleString('pt-BR', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
                e.target.value = value;
            }
        });
    </script>
</body>
</html>
