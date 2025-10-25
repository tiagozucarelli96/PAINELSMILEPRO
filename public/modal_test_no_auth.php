<?php
// modal_test_no_auth.php - Teste do modal sem autenticação
// Este arquivo bypassa completamente o sistema de autenticação

// Simular sessão completa
session_start();
$_SESSION['logado'] = true;
$_SESSION['perfil'] = 'ADM';
$_SESSION['perm_usuarios'] = 1;
$_SESSION['perm_pagamentos'] = 1;
$_SESSION['perm_tarefas'] = 1;
$_SESSION['perm_demandas'] = 1;
$_SESSION['perm_portao'] = 1;
$_SESSION['perm_banco_smile'] = 1;
$_SESSION['perm_banco_smile_admin'] = 1;
$_SESSION['perm_notas_fiscais'] = 1;
$_SESSION['perm_estoque_logistico'] = 1;
$_SESSION['perm_dados_contrato'] = 1;
$_SESSION['perm_uso_fiorino'] = 1;

// Incluir conexão com banco
require_once __DIR__ . '/conexao.php';

// Simular dados de usuários
$usuarios = [
    1 => [
        'id' => 1,
        'nome' => 'João Silva',
        'email' => 'joao.silva@empresa.com',
        'cargo' => 'Gerente de Vendas',
        'telefone' => '(11) 99999-9999',
        'cidade' => 'São Paulo',
        'status' => 'ativo'
    ],
    2 => [
        'id' => 2,
        'nome' => 'Maria Santos',
        'email' => 'maria.santos@empresa.com',
        'cargo' => 'Operadora de Estoque',
        'telefone' => '(11) 88888-8888',
        'cidade' => 'São Paulo',
        'status' => 'ativo'
    ],
    3 => [
        'id' => 3,
        'nome' => 'Pedro Costa',
        'email' => 'pedro.costa@empresa.com',
        'cargo' => 'Assistente Administrativo',
        'telefone' => '(11) 77777-7777',
        'cidade' => 'São Paulo',
        'status' => 'inativo'
    ]
];

// Endpoint AJAX para buscar dados do usuário
if (isset($_GET['action']) && $_GET['action'] === 'get_user' && isset($_GET['id'])) {
    $userId = (int)$_GET['id'];
    if (isset($usuarios[$userId])) {
        echo json_encode(['success' => true, 'user' => $usuarios[$userId]]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Usuário não encontrado']);
    }
    exit;
}

// Endpoint AJAX para salvar usuário
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save') {
    $userId = (int)($_POST['user_id'] ?? 0);
    $userData = [
        'nome' => $_POST['nome'] ?? '',
        'email' => $_POST['email'] ?? '',
        'cargo' => $_POST['cargo'] ?? '',
        'telefone' => $_POST['telefone'] ?? '',
        'cidade' => $_POST['cidade'] ?? '',
        'status' => $_POST['status'] ?? 'ativo'
    ];
    
    if ($userId > 0) {
        // Atualizar usuário existente
        $usuarios[$userId] = array_merge(['id' => $userId], $userData);
        echo json_encode(['success' => true, 'message' => 'Usuário atualizado com sucesso!']);
    } else {
        // Criar novo usuário
        $newId = max(array_keys($usuarios)) + 1;
        $usuarios[$newId] = array_merge(['id' => $newId], $userData);
        echo json_encode(['success' => true, 'message' => 'Usuário criado com sucesso!']);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teste Modal - Sem Autenticação</title>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            padding: 20px; 
            background: #f5f5f5; 
            margin: 0;
        }
        .container { 
            max-width: 1200px; 
            margin: 0 auto; 
            background: white; 
            padding: 20px; 
            border-radius: 8px; 
            box-shadow: 0 2px 4px rgba(0,0,0,0.1); 
        }
        .header { 
            text-align: center; 
            margin-bottom: 30px; 
            padding-bottom: 20px; 
            border-bottom: 2px solid #e5e7eb; 
        }
        .btn { 
            padding: 12px 24px; 
            margin: 10px; 
            border: none; 
            border-radius: 6px; 
            cursor: pointer; 
            font-size: 16px; 
            font-weight: 600; 
            transition: all 0.3s ease; 
        }
        .btn-primary { 
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%); 
            color: white; 
        }
        .btn-primary:hover { 
            transform: translateY(-2px); 
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3); 
        }
        .btn-secondary { 
            background: #6b7280; 
            color: white; 
        }
        .btn-secondary:hover { 
            background: #4b5563; 
        }
        .users-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); 
            gap: 20px; 
            margin: 20px 0; 
        }
        .user-card { 
            background: white; 
            padding: 20px; 
            border-radius: 8px; 
            box-shadow: 0 2px 4px rgba(0,0,0,0.1); 
            border: 1px solid #e5e7eb; 
        }
        .user-name { 
            font-weight: bold; 
            color: #1e3a8a; 
            margin-bottom: 10px; 
            font-size: 18px; 
        }
        .user-info { 
            color: #666; 
            margin-bottom: 8px; 
            font-size: 14px; 
        }
        .user-actions { 
            margin-top: 15px; 
            display: flex; 
            gap: 10px; 
        }
        .btn-edit { 
            background: #3b82f6; 
            color: white; 
            padding: 8px 16px; 
            font-size: 14px; 
        }
        .btn-delete { 
            background: #ef4444; 
            color: white; 
            padding: 8px 16px; 
            font-size: 14px; 
        }
        
        .modal { 
            display: none; 
            position: fixed; 
            z-index: 1000; 
            left: 0; 
            top: 0; 
            width: 100%; 
            height: 100%; 
            background: rgba(0, 0, 0, 0.5); 
            backdrop-filter: blur(4px); 
        }
        .modal.active { 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            animation: fadeIn 0.3s ease; 
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        .modal-content { 
            background: white; 
            padding: 30px; 
            border-radius: 12px; 
            max-width: 600px; 
            width: 90%; 
            position: relative; 
            max-height: 90vh; 
            overflow-y: auto; 
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04); 
            animation: slideIn 0.3s ease; 
        }
        @keyframes slideIn {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        .modal-header { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            margin-bottom: 25px; 
            padding-bottom: 15px; 
            border-bottom: 2px solid #e5e7eb; 
        }
        .modal-title { 
            font-size: 24px; 
            font-weight: 700; 
            color: #1e3a8a; 
            margin: 0; 
        }
        .close-btn { 
            font-size: 28px; 
            cursor: pointer; 
            color: #666; 
            background: none; 
            border: none; 
            padding: 5px; 
            border-radius: 50%; 
            width: 40px; 
            height: 40px; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            transition: all 0.3s ease; 
        }
        .close-btn:hover { 
            background: #f3f4f6; 
            color: #374151; 
        }
        .form-group { 
            margin-bottom: 20px; 
        }
        .form-group label { 
            display: block; 
            margin-bottom: 8px; 
            font-weight: 600; 
            color: #374151; 
            font-size: 14px; 
        }
        .form-group input, .form-group select { 
            width: 100%; 
            padding: 12px; 
            border: 2px solid #e5e7eb; 
            border-radius: 8px; 
            font-size: 16px; 
            transition: all 0.3s ease; 
            box-sizing: border-box; 
        }
        .form-group input:focus, .form-group select:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        .form-actions { 
            display: flex; 
            gap: 15px; 
            justify-content: flex-end; 
            margin-top: 30px; 
            padding-top: 20px; 
            border-top: 2px solid #e5e7eb; 
        }
        .btn-cancel { 
            background: #6b7280; 
            color: white; 
            padding: 12px 24px; 
            font-size: 16px; 
        }
        .btn-save { 
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%); 
            color: white; 
            padding: 12px 24px; 
            font-size: 16px; 
        }
        .btn-cancel:hover, .btn-save:hover { 
            transform: translateY(-1px); 
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15); 
        }
        .status-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 8px;
        }
        .status-ativo { background: #10b981; }
        .status-inativo { background: #ef4444; }
        .debug-info {
            background: #f3f4f6;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>👥 Sistema de Usuários - Teste Modal</h1>
            <p>Teste direto do modal sem autenticação</p>
            <button class="btn btn-primary" onclick="openModal()">➕ Novo Usuário</button>
        </div>
        
        <div class="debug-info">
            <strong>🔍 Debug Info:</strong><br>
            Sessão: <?= $_SESSION['logado'] ? 'Ativa' : 'Inativa' ?><br>
            Perfil: <?= $_SESSION['perfil'] ?? 'Não definido' ?><br>
            Perm Usuários: <?= $_SESSION['perm_usuarios'] ?? 'Não definido' ?><br>
            Total de usuários: <?= count($usuarios) ?>
        </div>
        
        <div class="users-grid">
            <?php foreach ($usuarios as $user): ?>
            <div class="user-card">
                <div class="user-name">
                    <span class="status-indicator status-<?= $user['status'] ?>"></span>
                    <?= htmlspecialchars($user['nome']) ?>
                </div>
                <div class="user-info">📧 <?= htmlspecialchars($user['email']) ?></div>
                <div class="user-info">💼 <?= htmlspecialchars($user['cargo']) ?></div>
                <div class="user-info">📱 <?= htmlspecialchars($user['telefone']) ?></div>
                <div class="user-info">🏢 <?= htmlspecialchars($user['cidade']) ?></div>
                <div class="user-actions">
                    <button class="btn btn-edit" onclick="openModal(<?= $user['id'] ?>)">✏️ Editar</button>
                    <button class="btn btn-delete" onclick="deleteUser(<?= $user['id'] ?>)">🗑️ Excluir</button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <!-- Modal -->
    <div class="modal" id="userModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" id="modalTitle">Novo Usuário</h2>
                <button class="close-btn" onclick="closeModal()">&times;</button>
            </div>
            
            <form id="userForm">
                <input type="hidden" id="userId" name="user_id" value="0">
                <input type="hidden" name="action" value="save">
                
                <div class="form-group">
                    <label for="nome">Nome Completo:</label>
                    <input type="text" id="nome" name="nome" required placeholder="Digite o nome completo">
                </div>
                
                <div class="form-group">
                    <label for="email">Email:</label>
                    <input type="email" id="email" name="email" required placeholder="usuario@empresa.com">
                </div>
                
                <div class="form-group">
                    <label for="cargo">Cargo:</label>
                    <input type="text" id="cargo" name="cargo" placeholder="Digite o cargo">
                </div>
                
                <div class="form-group">
                    <label for="telefone">Telefone:</label>
                    <input type="text" id="telefone" name="telefone" placeholder="(11) 99999-9999">
                </div>
                
                <div class="form-group">
                    <label for="cidade">Cidade:</label>
                    <input type="text" id="cidade" name="cidade" placeholder="São Paulo">
                </div>
                
                <div class="form-group">
                    <label for="status">Status:</label>
                    <select id="status" name="status">
                        <option value="ativo">Ativo</option>
                        <option value="inativo">Inativo</option>
                    </select>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-cancel" onclick="closeModal()">Cancelar</button>
                    <button type="submit" class="btn btn-save">💾 Salvar</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        // Abrir modal
        function openModal(userId = 0) {
            console.log('🚀 Abrindo modal para userId:', userId);
            const modal = document.getElementById('userModal');
            const title = document.getElementById('modalTitle');
            const userIdInput = document.getElementById('userId');
            
            if (userId > 0) {
                title.textContent = 'Editar Usuário';
                userIdInput.value = userId;
                
                // Carregar dados do usuário via AJAX
                fetch('?action=get_user&id=' + userId)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            document.getElementById('nome').value = data.user.nome || '';
                            document.getElementById('email').value = data.user.email || '';
                            document.getElementById('cargo').value = data.user.cargo || '';
                            document.getElementById('telefone').value = data.user.telefone || '';
                            document.getElementById('cidade').value = data.user.cidade || '';
                            document.getElementById('status').value = data.user.status || 'ativo';
                            console.log('✅ Dados carregados:', data.user);
                        } else {
                            console.error('❌ Erro ao carregar usuário:', data.message);
                            alert('Erro ao carregar dados do usuário: ' + data.message);
                        }
                    })
                    .catch(error => {
                        console.error('❌ Erro na requisição:', error);
                        alert('Erro ao carregar dados do usuário');
                    });
            } else {
                title.textContent = 'Novo Usuário';
                userIdInput.value = '0';
                document.getElementById('userForm').reset();
                console.log('🆕 Formulário limpo para novo usuário');
            }
            
            modal.classList.add('active');
            console.log('✅ Modal aberto com sucesso');
            
            // Focar no primeiro campo
            setTimeout(() => {
                document.getElementById('nome').focus();
            }, 100);
        }
        
        // Fechar modal
        function closeModal() {
            console.log('🔒 Fechando modal');
            const modal = document.getElementById('userModal');
            modal.classList.remove('active');
            console.log('✅ Modal fechado');
        }
        
        // Excluir usuário
        function deleteUser(userId) {
            if (confirm('Tem certeza que deseja excluir este usuário?')) {
                console.log('🗑️ Excluindo usuário:', userId);
                alert('Usuário excluído com sucesso! (Teste)');
                // Recarregar a página para atualizar a lista
                location.reload();
            }
        }
        
        // Fechar modal ao clicar fora
        document.getElementById('userModal').addEventListener('click', function(e) {
            if (e.target === this) {
                console.log('🖱️ Clicou fora do modal - fechando');
                closeModal();
            }
        });
        
        // Prevenir fechamento do modal ao clicar no conteúdo
        document.querySelector('.modal-content').addEventListener('click', function(e) {
            e.stopPropagation();
        });
        
        // Teste de formulário
        document.getElementById('userForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            
            console.log('📝 Formulário enviado:', Object.fromEntries(formData));
            
            // Enviar dados via AJAX
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    closeModal();
                    // Recarregar a página para atualizar a lista
                    setTimeout(() => location.reload(), 1000);
                } else {
                    alert('Erro: ' + data.message);
                }
            })
            .catch(error => {
                console.error('❌ Erro na requisição:', error);
                alert('Erro ao salvar usuário');
            });
        });
        
        // Fechar modal com tecla ESC
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                console.log('⌨️ Tecla ESC pressionada - fechando modal');
                closeModal();
            }
        });
        
        // Log de inicialização
        console.log('🚀 Sistema de usuários carregado');
        console.log('👥 Usuários disponíveis:', <?= count($usuarios) ?>);
        console.log('🔧 Modal configurado e pronto para uso');
    </script>
</body>
</html>
