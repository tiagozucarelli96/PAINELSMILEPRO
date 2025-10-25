<?php
// test_modal_usuarios.php - Teste do modal de usuários
session_start();
require_once __DIR__ . '/conexao.php';

// Simular sessão de admin
$_SESSION['logado'] = true;
$_SESSION['perm_usuarios'] = true;
$_SESSION['perfil'] = 'ADM';

echo "<!DOCTYPE html>
<html>
<head>
    <title>Teste Modal Usuários</title>
    <style>
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); }
        .modal.active { display: flex; align-items: center; justify-content: center; }
        .modal-content { background: white; padding: 20px; border-radius: 8px; max-width: 500px; width: 90%; }
        .close-btn { float: right; font-size: 24px; cursor: pointer; }
        button { padding: 10px 20px; margin: 5px; cursor: pointer; }
    </style>
</head>
<body>
    <h1>Teste Modal Usuários</h1>
    <button onclick='openModal()'>Abrir Modal</button>
    <button onclick='openModal(123)'>Abrir Modal com ID</button>
    
    <div class='modal' id='userModal'>
        <div class='modal-content'>
            <div class='modal-header'>
                <h2 id='modalTitle'>Novo Usuário</h2>
                <button class='close-btn' onclick='closeModal()'>&times;</button>
            </div>
            <form id='userForm'>
                <input type='hidden' id='userId' value='0'>
                <p>Nome: <input type='text' name='nome' id='userName'></p>
                <p>Email: <input type='email' name='email' id='userEmail'></p>
                <button type='button' onclick='closeModal()'>Cancelar</button>
                <button type='submit'>Salvar</button>
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
                // Simular carregamento de dados
                document.getElementById('userName').value = 'Usuário ' + userId;
                document.getElementById('userEmail').value = 'user' + userId + '@test.com';
            } else {
                title.textContent = 'Novo Usuário';
                userIdInput.value = '0';
                document.getElementById('userName').value = '';
                document.getElementById('userEmail').value = '';
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
    </script>
</body>
</html>";
?>
