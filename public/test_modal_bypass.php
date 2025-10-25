<?php
// test_modal_bypass.php - Teste do modal sem autenticação
session_start();

// Simular sessão completa
$_SESSION['logado'] = true;
$_SESSION['perfil'] = 'ADM';
$_SESSION['perm_usuarios'] = 1;

// Incluir apenas o HTML e JavaScript do modal, sem as verificações de permissão
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teste Modal - Bypass Auth</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; }
        .header { background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .users-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; }
        .user-card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .user-name { font-weight: bold; color: #1e3a8a; margin-bottom: 10px; }
        .user-info { color: #666; margin-bottom: 5px; }
        .user-actions { margin-top: 15px; }
        .btn { padding: 8px 16px; margin: 5px; border: none; border-radius: 5px; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-edit { background: #3b82f6; color: white; }
        .btn-delete { background: #ef4444; color: white; }
        .btn-primary { background: #1e3a8a; color: white; }
        
        .modal { 
            display: none; 
            position: fixed; 
            z-index: 1000; 
            left: 0; 
            top: 0; 
            width: 100%; 
            height: 100%; 
            background: rgba(0, 0, 0, 0.5); 
        }
        .modal.active { 
            display: flex; 
            align-items: center; 
            justify-content: center; 
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
        }
        .modal-header { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            margin-bottom: 20px; 
            padding-bottom: 15px; 
            border-bottom: 1px solid #e5e7eb; 
        }
        .modal-title { 
            font-size: 24px; 
            font-weight: 600; 
            color: #1e3a8a; 
            margin: 0; 
        }
        .close-btn { 
            font-size: 24px; 
            cursor: pointer; 
            color: #666; 
            background: none; 
            border: none; 
        }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 600; color: #374151; }
        .form-group input, .form-group select { 
            width: 100%; 
            padding: 12px; 
            border: 1px solid #d1d5db; 
            border-radius: 8px; 
            font-size: 16px; 
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
            border-top: 1px solid #e5e7eb; 
        }
        .btn-cancel { background: #6b7280; color: white; }
        .btn-save { background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%); color: white; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>👥 Teste Modal de Usuários</h1>
            <p>Este é um teste para verificar se o modal está funcionando corretamente.</p>
            <button class="btn btn-primary" onclick="openModal()">➕ Novo Usuário</button>
        </div>
        
        <div class="users-grid">
            <div class="user-card">
                <div class="user-name">João Silva</div>
                <div class="user-info">📧 joao@test.com</div>
                <div class="user-info">💼 Gerente</div>
                <div class="user-info">📱 (11) 99999-9999</div>
                <div class="user-actions">
                    <button class="btn btn-edit" onclick="openModal(1)">✏️ Editar</button>
                    <button class="btn btn-delete" onclick="deleteUser(1)">🗑️ Excluir</button>
                </div>
            </div>
            
            <div class="user-card">
                <div class="user-name">Maria Santos</div>
                <div class="user-info">📧 maria@test.com</div>
                <div class="user-info">💼 Operadora</div>
                <div class="user-info">📱 (11) 88888-8888</div>
                <div class="user-actions">
                    <button class="btn btn-edit" onclick="openModal(2)">✏️ Editar</button>
                    <button class="btn btn-delete" onclick="deleteUser(2)">🗑️ Excluir</button>
                </div>
            </div>
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
                <input type="hidden" id="userId" value="0">
                
                <div class="form-group">
                    <label for="nome">Nome Completo:</label>
                    <input type="text" id="nome" name="nome" required>
                </div>
                
                <div class="form-group">
                    <label for="email">Email:</label>
                    <input type="email" id="email" name="email" required>
                </div>
                
                <div class="form-group">
                    <label for="cargo">Cargo:</label>
                    <input type="text" id="cargo" name="cargo">
                </div>
                
                <div class="form-group">
                    <label for="telefone">Telefone:</label>
                    <input type="text" id="telefone" name="telefone">
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
            console.log('Abrindo modal para userId:', userId);
            const modal = document.getElementById('userModal');
            const title = document.getElementById('modalTitle');
            const userIdInput = document.getElementById('userId');
            
            if (userId > 0) {
                title.textContent = 'Editar Usuário';
                userIdInput.value = userId;
                // Simular dados do usuário
                document.getElementById('nome').value = userId === 1 ? 'João Silva' : 'Maria Santos';
                document.getElementById('email').value = userId === 1 ? 'joao@test.com' : 'maria@test.com';
                document.getElementById('cargo').value = userId === 1 ? 'Gerente' : 'Operadora';
                document.getElementById('telefone').value = userId === 1 ? '(11) 99999-9999' : '(11) 88888-8888';
                document.getElementById('status').value = 'ativo';
            } else {
                title.textContent = 'Novo Usuário';
                userIdInput.value = '0';
                document.getElementById('userForm').reset();
            }
            
            modal.classList.add('active');
            console.log('Modal aberto');
        }
        
        // Fechar modal
        function closeModal() {
            console.log('Fechando modal');
            document.getElementById('userModal').classList.remove('active');
        }
        
        // Excluir usuário
        function deleteUser(userId) {
            if (confirm('Tem certeza que deseja excluir este usuário?')) {
                console.log('Excluindo usuário:', userId);
                alert('Usuário excluído! (Teste)');
            }
        }
        
        // Fechar modal ao clicar fora
        document.getElementById('userModal').addEventListener('click', function(e) {
            if (e.target === this) {
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
            const data = Object.fromEntries(formData);
            console.log('Dados do formulário:', data);
            alert('Formulário enviado! (Teste)\nDados: ' + JSON.stringify(data, null, 2));
            closeModal();
        });
        
        // Teste de teclado
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
            }
        });
    </script>
</body>
</html>
