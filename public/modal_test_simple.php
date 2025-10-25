<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teste Modal Simples</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; }
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
        }
        .close-btn { 
            position: absolute; 
            top: 15px; 
            right: 20px; 
            font-size: 24px; 
            cursor: pointer; 
            color: #666;
        }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
        .form-group input, .form-group select { 
            width: 100%; 
            padding: 10px; 
            border: 1px solid #ddd; 
            border-radius: 5px; 
        }
        .btn { 
            padding: 10px 20px; 
            margin: 5px; 
            border: none; 
            border-radius: 5px; 
            cursor: pointer; 
        }
        .btn-primary { background: #3b82f6; color: white; }
        .btn-secondary { background: #6b7280; color: white; }
    </style>
</head>
<body>
    <h1>Teste Modal de Usuários</h1>
    <p>Este é um teste para verificar se o modal está funcionando corretamente.</p>
    
    <button class="btn btn-primary" onclick="openModal()">➕ Novo Usuário</button>
    <button class="btn btn-primary" onclick="openModal(123)">✏️ Editar Usuário (ID: 123)</button>
    
    <div class="modal" id="userModal">
        <div class="modal-content">
            <div class="close-btn" onclick="closeModal()">&times;</div>
            <h2 id="modalTitle">Novo Usuário</h2>
            
            <form id="userForm">
                <input type="hidden" id="userId" value="0">
                
                <div class="form-group">
                    <label for="nome">Nome:</label>
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
                    <label for="status">Status:</label>
                    <select id="status" name="status">
                        <option value="ativo">Ativo</option>
                        <option value="inativo">Inativo</option>
                    </select>
                </div>
                
                <div style="margin-top: 20px;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancelar</button>
                    <button type="submit" class="btn btn-primary">💾 Salvar</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function openModal(userId = 0) {
            console.log('Abrindo modal para userId:', userId);
            const modal = document.getElementById('userModal');
            const title = document.getElementById('modalTitle');
            const userIdInput = document.getElementById('userId');
            
            if (userId > 0) {
                title.textContent = 'Editar Usuário';
                userIdInput.value = userId;
                // Simular dados do usuário
                document.getElementById('nome').value = 'Usuário ' + userId;
                document.getElementById('email').value = 'user' + userId + '@test.com';
                document.getElementById('cargo').value = 'Cargo ' + userId;
                document.getElementById('status').value = 'ativo';
            } else {
                title.textContent = 'Novo Usuário';
                userIdInput.value = '0';
                document.getElementById('userForm').reset();
            }
            
            modal.classList.add('active');
            console.log('Modal aberto');
        }
        
        function closeModal() {
            console.log('Fechando modal');
            document.getElementById('userModal').classList.remove('active');
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
            console.log('Dados do formulário:', Object.fromEntries(formData));
            alert('Formulário enviado! (Teste)');
            closeModal();
        });
    </script>
</body>
</html>
