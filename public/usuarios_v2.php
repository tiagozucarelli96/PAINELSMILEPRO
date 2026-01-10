<?php
// usuarios_v2.php
// Página de usuários com modal moderno e integração RH

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';

if (empty($_SESSION['logado']) || empty($_SESSION['perm_usuarios'])) {
    http_response_code(403);
    echo "Acesso negado.";
    exit;
}

// Buscar usuários
$usuarios = [];
try {
    $stmt = $pdo->query("
        SELECT id, nome, login, email, cargo, cpf, admissao_data, salario_base, 
               pix_tipo, pix_chave, status_empregado, perfil, ativo,
               perm_tarefas, perm_lista, perm_demandas, perm_pagamentos, perm_usuarios, perm_portao,
               perm_banco_smile, perm_banco_smile_admin, perm_notas_fiscais,
               perm_dados_contrato, perm_uso_fiorino, // REMOVIDO: perm_estoque_logistico
               criado_em, atualizado_em
        FROM usuarios 
        ORDER BY nome
    ");
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $erro = "Erro ao buscar usuários: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Usuários & Permissões - Sistema Smile</title>
    <link rel="stylesheet" href="estilo.css">
    <link rel="stylesheet" href="css/smile-ui.css">
    <style>
        .usuarios-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .usuarios-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding: 20px;
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
            border-radius: 12px;
        }
        
        .usuarios-title {
            font-size: 2rem;
            font-weight: 700;
            margin: 0;
        }
        
        .usuarios-actions {
            display: flex;
            gap: 10px;
        }
        
        .usuarios-grid {
            display: grid;
            gap: 20px;
        }
        
        .usuario-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            border: 1px solid #e5e7eb;
            transition: all 0.3s ease;
        }
        
        .usuario-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .usuario-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }
        
        .usuario-info {
            flex: 1;
        }
        
        .usuario-nome {
            font-size: 18px;
            font-weight: 600;
            color: #1e40af;
            margin: 0 0 5px 0;
        }
        
        .usuario-cargo {
            color: #64748b;
            font-size: 14px;
            margin: 0 0 10px 0;
        }
        
        .usuario-status {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .status-ativo {
            background: #dcfce7;
            color: #166534;
        }
        
        .status-inativo {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .usuario-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .detail-item {
            display: flex;
            flex-direction: column;
        }
        
        .detail-label {
            font-size: 12px;
            color: #64748b;
            margin-bottom: 2px;
        }
        
        .detail-value {
            font-size: 14px;
            color: #374151;
            font-weight: 500;
        }
        
        .permissions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 10px;
            margin-top: 15px;
            padding: 15px;
            background: #f8fafc;
            border-radius: 8px;
        }
        
        .permission-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .permission-checkbox {
            width: 16px;
            height: 16px;
            pointer-events: none;
        }
        
        .permission-label {
            font-size: 12px;
            color: #374151;
        }
        
        .usuario-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .action-btn {
            padding: 6px 12px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 12px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .btn-primary {
            background: #1e40af;
            color: white;
        }
        
        .btn-primary:hover {
            background: #1e3a8a;
        }
        
        .btn-secondary {
            background: #6b7280;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #4b5563;
        }
        
        .btn-outline {
            background: transparent;
            color: #1e40af;
            border: 1px solid #1e40af;
        }
        
        .btn-outline:hover {
            background: #1e40af;
            color: white;
        }
        
        .btn-danger {
            background: #dc2626;
            color: white;
        }
        
        .btn-danger:hover {
            background: #b91c1c;
        }
        
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        
        .modal-content {
            background-color: white;
            margin: 2% auto;
            padding: 0;
            border-radius: 12px;
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px;
            border-bottom: 1px solid #e5e7eb;
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
            border-radius: 12px 12px 0 0;
        }
        
        .modal-title {
            font-size: 20px;
            font-weight: 600;
            margin: 0;
        }
        
        .close {
            color: white;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            opacity: 0.8;
        }
        
        .close:hover {
            opacity: 1;
        }
        
        .modal-body {
            padding: 30px;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
        }
        
        .form-label {
            font-weight: 500;
            color: #374151;
            margin-bottom: 5px;
        }
        
        .form-input {
            padding: 10px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #1e40af;
            box-shadow: 0 0 0 3px rgba(30, 64, 175, 0.1);
        }
        
        .permissions-section {
            margin-top: 30px;
            padding: 20px;
            background: #f8fafc;
            border-radius: 8px;
        }
        
        .permissions-grid-modal {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .permission-item-modal {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            background: white;
            border-radius: 6px;
            border: 1px solid #e5e7eb;
        }
        
        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            padding: 20px;
            border-top: 1px solid #e5e7eb;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #64748b;
        }
        
        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 20px;
        }
        
        .empty-state-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 10px;
        }
        
        .empty-state-text {
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="usuarios-container">
        <!-- Header -->
        <div class="usuarios-header">
            <h1 class="usuarios-title">👥 Usuários & Permissões</h1>
            <div class="usuarios-actions">
                <button onclick="abrirModal('criar')" class="smile-btn smile-btn-primary">
                    + Novo Usuário
                </button>
            </div>
        </div>
        
        <!-- Lista de Usuários -->
        <?php if (!empty($usuarios)): ?>
        <div class="usuarios-grid">
            <?php foreach ($usuarios as $usuario): ?>
            <div class="usuario-card">
                <div class="usuario-header">
                    <div class="usuario-info">
                        <h3 class="usuario-nome"><?= htmlspecialchars($usuario['nome']) ?></h3>
                        <p class="usuario-cargo"><?= htmlspecialchars($usuario['cargo'] ?? 'Cargo não informado') ?></p>
                    </div>
                    <span class="usuario-status <?= $usuario['ativo'] ? 'status-ativo' : 'status-inativo' ?>">
                        <?= $usuario['ativo'] ? 'Ativo' : 'Inativo' ?>
                    </span>
                </div>
                
                <div class="usuario-details">
                    <div class="detail-item">
                        <span class="detail-label">Login</span>
                        <span class="detail-value"><?= htmlspecialchars($usuario['login']) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Email</span>
                        <span class="detail-value"><?= htmlspecialchars($usuario['email'] ?? 'Não informado') ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">CPF</span>
                        <span class="detail-value">
                            <?= $usuario['cpf'] ? substr($usuario['cpf'], 0, 3) . '.***.***-' . substr($usuario['cpf'], -2) : 'Não informado' ?>
                        </span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Perfil</span>
                        <span class="detail-value"><?= htmlspecialchars($usuario['perfil'] ?? 'OPER') ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Admissão</span>
                        <span class="detail-value">
                            <?= $usuario['admissao_data'] ? date('d/m/Y', strtotime($usuario['admissao_data'])) : 'Não informado' ?>
                        </span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">PIX</span>
                        <span class="detail-value">
                            <?= $usuario['pix_tipo'] ? $usuario['pix_tipo'] . ': ' . substr($usuario['pix_chave'], 0, 10) . '...' : 'Não cadastrado' ?>
                        </span>
                    </div>
                </div>
                
                <div class="permissions-grid">
                    <?php
                    $permissoes_labels = [
                        'perm_tarefas' => 'Tarefas',
                        'perm_lista' => 'Lista de Compras',
                        'perm_demandas' => 'Solicitar Pagamento',
                        'perm_pagamentos' => 'Pagamentos',
                        'perm_usuarios' => 'Usuários',
                        'perm_portao' => 'Portão',
                        'perm_banco_smile' => 'Banco Smile',
                        'perm_banco_smile_admin' => 'Banco Smile Admin',
                        'perm_notas_fiscais' => 'Notas Fiscais',
                        // 'perm_estoque_logistico' => 'Estoque Logístico', // REMOVIDO: Módulo desativado
                        'perm_dados_contrato' => 'Dados do Contrato',
                        'perm_uso_fiorino' => 'Uso Fiorino'
                    ];
                    
                    foreach ($permissoes_labels as $perm => $label):
                        $tem_permissao = $usuario[$perm] ?? false;
                    ?>
                    <div class="permission-item">
                        <input type="checkbox" class="permission-checkbox" <?= $tem_permissao ? 'checked' : '' ?> disabled>
                        <span class="permission-label"><?= $label ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="usuario-actions">
                    <button onclick="abrirModal('editar', <?= $usuario['id'] ?>)" class="action-btn btn-primary">
                        ✏️ Editar
                    </button>
                    <button onclick="abrirModal('visualizar', <?= $usuario['id'] ?>)" class="action-btn btn-outline">
                        👁️ Ver Detalhes
                    </button>
                    <?php if ($usuario['id'] != $_SESSION['id']): ?>
                    <button onclick="excluirUsuario(<?= $usuario['id'] ?>)" class="action-btn btn-danger">
                        🗑️ Excluir
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty-state">
            <div class="empty-state-icon">👥</div>
            <div class="empty-state-title">Nenhum usuário encontrado</div>
            <div class="empty-state-text">
                <button onclick="abrirModal('criar')" class="smile-btn smile-btn-primary">
                    Cadastrar primeiro usuário
                </button>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Modal -->
    <div id="modal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="modal-title">Usuário</h3>
                <span class="close" onclick="fecharModal()">&times;</span>
            </div>
            <div class="modal-body">
                <form id="usuario-form">
                    <input type="hidden" name="acao" id="form-acao">
                    <input type="hidden" name="usuario_id" id="form-usuario-id">
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Nome Completo *</label>
                            <input type="text" name="nome" id="form-nome" class="form-input" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Login *</label>
                            <input type="text" name="login" id="form-login" class="form-input" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" id="form-email" class="form-input">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Senha <?= $acao === 'criar' ? '*' : '(deixe em branco para manter)' ?></label>
                            <input type="password" name="senha" id="form-senha" class="form-input" <?= $acao === 'criar' ? 'required' : '' ?>>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Cargo</label>
                            <input type="text" name="cargo" id="form-cargo" class="form-input">
                        </div>
                        <div class="form-group">
                            <label class="form-label">CPF</label>
                            <input type="text" name="cpf" id="form-cpf" class="form-input" placeholder="000.000.000-00">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Data de Admissão</label>
                            <input type="date" name="admissao_data" id="form-admissao-data" class="form-input">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Salário Base (R$)</label>
                            <input type="number" name="salario_base" id="form-salario-base" class="form-input" step="0.01" min="0">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Tipo PIX</label>
                            <select name="pix_tipo" id="form-pix-tipo" class="form-input">
                                <option value="">Selecione</option>
                                <option value="CPF">CPF</option>
                                <option value="CNPJ">CNPJ</option>
                                <option value="EMAIL">Email</option>
                                <option value="TELEFONE">Telefone</option>
                                <option value="ALEATORIA">Chave Aleatória</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Chave PIX</label>
                            <input type="text" name="pix_chave" id="form-pix-chave" class="form-input">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Status Empregado</label>
                            <select name="status_empregado" id="form-status-empregado" class="form-input">
                                <option value="ativo">Ativo</option>
                                <option value="inativo">Inativo</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Perfil</label>
                            <select name="perfil" id="form-perfil" class="form-input">
                                <option value="ADM">Administrador</option>
                                <option value="FIN">Financeiro</option>
                                <option value="GERENTE">Gerente</option>
                                <option value="OPER">Operador</option>
                                <option value="CONSULTA">Consulta</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="permissions-section">
                        <h4 style="margin: 0 0 15px 0; color: #1e40af;">🔐 Permissões do Sistema</h4>
                        <div class="permissions-grid-modal">
                            <?php foreach ($permissoes_labels as $perm => $label): ?>
                            <div class="permission-item-modal">
                                <input type="checkbox" name="<?= $perm ?>" id="form-<?= $perm ?>" class="permission-checkbox">
                                <label for="form-<?= $perm ?>" class="permission-label"><?= $label ?></label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="fecharModal()" class="smile-btn smile-btn-outline">Cancelar</button>
                <button type="button" onclick="salvarUsuario()" class="smile-btn smile-btn-primary" id="btn-salvar">
                    Salvar
                </button>
            </div>
        </div>
    </div>
    
    <script>
        let modalAcao = '';
        let modalUsuarioId = null;
        
        function abrirModal(acao, usuarioId = null) {
            modalAcao = acao;
            modalUsuarioId = usuarioId;
            
            const modal = document.getElementById('modal');
            const titulo = document.getElementById('modal-title');
            const form = document.getElementById('usuario-form');
            const btnSalvar = document.getElementById('btn-salvar');
            
            // Limpar formulário
            form.reset();
            
            // Configurar título e botão
            switch (acao) {
                case 'criar':
                    titulo.textContent = 'Novo Usuário';
                    btnSalvar.textContent = 'Criar Usuário';
                    document.getElementById('form-acao').value = 'criar';
                    break;
                case 'editar':
                    titulo.textContent = 'Editar Usuário';
                    btnSalvar.textContent = 'Salvar Alterações';
                    document.getElementById('form-acao').value = 'editar';
                    document.getElementById('form-usuario-id').value = usuarioId;
                    carregarUsuario(usuarioId);
                    break;
                case 'visualizar':
                    titulo.textContent = 'Detalhes do Usuário';
                    btnSalvar.style.display = 'none';
                    document.getElementById('form-acao').value = 'visualizar';
                    carregarUsuario(usuarioId);
                    // Desabilitar campos para visualização
                    form.querySelectorAll('input, select').forEach(campo => {
                        campo.disabled = true;
                    });
                    break;
            }
            
            modal.style.display = 'block';
        }
        
        function fecharModal() {
            document.getElementById('modal').style.display = 'none';
            // Reabilitar campos
            document.getElementById('usuario-form').querySelectorAll('input, select').forEach(campo => {
                campo.disabled = false;
            });
        }
        
        function carregarUsuario(usuarioId) {
            fetch('usuarios_modal.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `acao=buscar&usuario_id=${usuarioId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.usuario) {
                    const usuario = data.usuario;
                    
                    // Preencher campos básicos
                    document.getElementById('form-nome').value = usuario.nome || '';
                    document.getElementById('form-login').value = usuario.login || '';
                    document.getElementById('form-email').value = usuario.email || '';
                    document.getElementById('form-cargo').value = usuario.cargo || '';
                    document.getElementById('form-cpf').value = usuario.cpf || '';
                    document.getElementById('form-admissao-data').value = usuario.admissao_data || '';
                    document.getElementById('form-salario-base').value = usuario.salario_base || '';
                    document.getElementById('form-pix-tipo').value = usuario.pix_tipo || '';
                    document.getElementById('form-pix-chave').value = usuario.pix_chave || '';
                    document.getElementById('form-status-empregado').value = usuario.status_empregado || 'ativo';
                    document.getElementById('form-perfil').value = usuario.perfil || 'OPER';
                    
                    // Preencher permissões
                    const permissoes = [
                        'perm_tarefas', 'perm_lista', 'perm_demandas', 'perm_pagamentos', 
                        'perm_usuarios', 'perm_portao', 'perm_banco_smile', 'perm_banco_smile_admin',
                        'perm_notas_fiscais', 'perm_dados_contrato', 'perm_uso_fiorino' // REMOVIDO: perm_estoque_logistico
                    ];
                    
                    permissoes.forEach(perm => {
                        const checkbox = document.getElementById(`form-${perm}`);
                        if (checkbox) {
                            checkbox.checked = usuario[perm] == 1;
                        }
                    });
                } else {
                    alert('Erro ao carregar usuário: ' + (data.message || 'Erro desconhecido'));
                }
            })
            .catch(error => {
                console.error('Erro:', error);
                alert('Erro ao carregar usuário');
            });
        }
        
        function salvarUsuario() {
            const form = document.getElementById('usuario-form');
            const formData = new FormData(form);
            
            fetch('usuarios_modal.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    fecharModal();
                    location.reload(); // Recarregar página para mostrar mudanças
                } else {
                    alert('Erro: ' + (data.message || 'Erro desconhecido'));
                }
            })
            .catch(error => {
                console.error('Erro:', error);
                alert('Erro ao salvar usuário');
            });
        }
        
        function excluirUsuario(usuarioId) {
            if (confirm('Tem certeza que deseja excluir este usuário?')) {
                // Implementar exclusão
                alert('Funcionalidade de exclusão será implementada');
            }
        }
        
        // Fechar modal ao clicar fora
        window.onclick = function(event) {
            const modal = document.getElementById('modal');
            if (event.target === modal) {
                fecharModal();
            }
        }
    </script>
</body>
</html>
