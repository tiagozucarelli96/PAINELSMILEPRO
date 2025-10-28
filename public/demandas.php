<?php
// demandas.php — Sistema principal de demandas
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/demandas_helper.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/lc_permissions_helper.php';

// Verificar permissões
if (!lc_can_access_demandas()) {
    header('Location: index.php?page=dashboard');
    exit;
}

$demandas = new DemandasHelper();
$usuario_id = $_SESSION['user_id'] ?? 1;

// Processar ações
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    
    switch ($acao) {
        case 'criar_quadro':
            $nome = $_POST['nome'] ?? '';
            $descricao = $_POST['descricao'] ?? '';
            $cor = $_POST['cor'] ?? '#3b82f6';
            
            if ($nome && lc_can_create_quadros()) {
                $quadro_id = $demandas->criarQuadro($nome, $descricao, $cor, $usuario_id);
                header('Location: demandas_quadro.php?id=' . $quadro_id);
                exit;
            }
            break;
            
        case 'concluir_cartao':
            $cartao_id = $_POST['cartao_id'] ?? 0;
            if ($cartao_id) {
                $demandas->concluirCartao($cartao_id, $usuario_id);
            }
            break;
    }
}

// Obter quadros do usuário
$quadros = $demandas->obterQuadrosUsuario($usuario_id);

// Renderizar página completa usando sidebar_integration
includeSidebar('Demandas');
?>

<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    
    /* Layout Principal */
    .page-container {
        padding: 2rem;
        max-width: 1400px;
        margin: 0 auto;
    }
    
    .header-actions {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 2rem;
        padding-bottom: 1rem;
        border-bottom: 2px solid #e5e7eb;
    }
    
    .header-actions h1 {
        font-size: 2rem;
        color: #1f2937;
        font-weight: 600;
        margin: 0;
    }
    
    .section-title {
        font-size: 1.5rem;
        font-weight: 600;
        color: #1f2937;
        margin: 2rem 0 1rem 0;
    }
    
    .header-actions > div {
        display: flex;
        gap: 1rem;
        align-items: center;
    }
    
    /* Botões */
    .btn {
        padding: 0.75rem 1.5rem;
        border-radius: 8px;
        font-weight: 500;
        cursor: pointer;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        transition: all 0.2s;
        border: none;
    }
    
    .btn-primary {
        background: #3b82f6;
        color: white;
    }
    
    .btn-primary:hover {
        background: #2563eb;
    }
    
    .btn-outline {
        background: white;
        color: #3b82f6;
        border: 2px solid #3b82f6;
    }
    
    .btn-outline:hover {
        background: #eff6ff;
    }
    
    /* Agenda Section */
    .agenda-section {
        background: white;
        padding: 1.5rem;
        border-radius: 12px;
        margin-bottom: 2rem;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }
    
    .agenda-title {
        font-size: 1.25rem;
        font-weight: 600;
        margin-bottom: 1rem;
        color: #1f2937;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .agenda-item {
        background: #f9fafb;
        padding: 1rem;
        border-radius: 8px;
        margin-bottom: 0.75rem;
        border-left: 4px solid #3b82f6;
    }
    
    .agenda-item-title {
        font-weight: 500;
        margin-bottom: 0.5rem;
        color: #1f2937;
    }
    
    .agenda-item-meta {
        font-size: 0.875rem;
        color: #6b7280;
    }
    
    .agenda-actions {
        margin-top: 1rem;
        padding-top: 1rem;
        border-top: 1px solid #e5e7eb;
        display: flex;
        gap: 1rem;
    }
    
    /* Quadros Section */
    .quadros-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: 1.5rem;
        margin-top: 1rem;
    }
    
    .quadro-card {
        background: white;
        border-radius: 12px;
        padding: 1.5rem;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        transition: transform 0.2s, box-shadow 0.2s;
        border: 2px solid #e5e7eb;
    }
    
    .quadro-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    }
    
    .quadro-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1rem;
        padding-bottom: 1rem;
        border-bottom: 2px solid #e5e7eb;
    }
    
    .quadro-nome {
        font-size: 1.25rem;
        font-weight: 600;
        color: #1f2937;
    }
    
    .quadro-cor {
        width: 32px;
        height: 32px;
        border-radius: 8px;
    }
    
    .quadro-stats {
        display: flex;
        gap: 2rem;
        margin-bottom: 1rem;
    }
    
    .stat {
        text-align: center;
    }
    
    .stat-number {
        font-size: 1.5rem;
        font-weight: 600;
        color: #3b82f6;
    }
    
    .stat-label {
        font-size: 0.875rem;
        color: #6b7280;
        margin-top: 0.25rem;
    }
    
    .quadro-actions {
        display: flex;
        gap: 0.5rem;
    }
    
    .quadro-actions .btn {
        flex: 1;
        justify-content: center;
    }
    
    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 3rem 2rem;
        background: white;
        border-radius: 12px;
        margin-top: 1rem;
    }
    
    .empty-state-icon {
        font-size: 4rem;
        margin-bottom: 1rem;
    }
    
    .empty-state h3 {
        font-size: 1.25rem;
        margin-bottom: 0.5rem;
        color: #1f2937;
    }
    
    .empty-state p {
        color: #6b7280;
        margin-bottom: 1.5rem;
    }
    
    /* Modal */
    .modal {
        display: none;
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.5);
        justify-content: center;
        align-items: center;
    }
    
    .modal-content {
        background: white;
        padding: 2rem;
        border-radius: 12px;
        max-width: 500px;
        width: 90%;
        position: relative;
    }
    
    .close-button {
        position: absolute;
        right: 1rem;
        top: 1rem;
        font-size: 2rem;
        cursor: pointer;
        color: #6b7280;
    }
    
    .form-group {
        margin-bottom: 1.5rem;
    }
    
    .form-group label {
        display: block;
        margin-bottom: 0.5rem;
        font-weight: 500;
        color: #1f2937;
    }
    
    .form-group input,
    .form-group textarea,
    .form-group select {
        width: 100%;
        padding: 0.75rem;
        border: 2px solid #e5e7eb;
        border-radius: 8px;
        font-size: 1rem;
    }
    
    .form-actions {
        display: flex;
        gap: 1rem;
        justify-content: flex-end;
        margin-top: 2rem;
    }
    
    /* Notification Badge */
    .notification-badge {
        position: relative;
    }
    
    .notification-badge::after {
        content: attr(data-count);
        position: absolute;
        top: -8px;
        right: -8px;
        background: #ef4444;
        color: white;
        font-size: 0.75rem;
        padding: 2px 6px;
        border-radius: 10px;
        min-width: 20px;
        text-align: center;
    }
</style>

<div class="page-container">
    <div class="header-actions">
        <h1>📋 Meus Quadros</h1>
        <div>
            <?php if (lc_can_create_quadros()): ?>
                <button class="btn btn-primary" onclick="openCreateQuadroModal()">➕ Novo Quadro</button>
            <?php endif; ?>
        </div>
    </div>

            
    <?php if (count($quadros) > 0): ?>
        <div class="quadros-grid">
            <?php foreach ($quadros as $quadro): ?>
                <div class="quadro-card">
                    <div class="quadro-header">
                        <h3 class="quadro-nome"><?= htmlspecialchars($quadro['nome']) ?></h3>
                        <div class="quadro-cor" style="background-color: <?= htmlspecialchars($quadro['cor']) ?>"></div>
                    </div>
                    
                    <div class="quadro-stats">
                        <div class="stat">
                            <div class="stat-number"><?= $quadro['total_cartoes'] ?></div>
                            <div class="stat-label">Total</div>
                        </div>
                        <div class="stat">
                            <div class="stat-number"><?= $quadro['cartoes_pendentes'] ?? 0 ?></div>
                            <div class="stat-label">Pendentes</div>
                        </div>
                    </div>
                    
                    <div class="quadro-actions">
                        <a href="demandas_quadro.php?id=<?= $quadro['id'] ?>" class="btn btn-primary">👁️ Abrir</a>
                        <a href="demandas_quadro.php?id=<?= $quadro['id'] ?>&action=settings" class="btn btn-outline">⚙️ Configurar</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="empty-state">
            <div class="empty-state-icon">📋</div>
            <h3>Nenhum quadro encontrado</h3>
            <p>Você ainda não foi convidado para nenhum quadro ou não criou nenhum.</p>
            <?php if (lc_can_create_quadros()): ?>
                <button class="btn btn-primary" onclick="openCreateQuadroModal()">➕ Criar Primeiro Quadro</button>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

    <!-- Modal Criar Quadro -->
    <div id="createQuadroModal" class="modal">
        <div class="modal-content">
            <span class="close-button" onclick="closeCreateQuadroModal()">&times;</span>
            <h2>Criar Novo Quadro</h2>
            
            <form method="POST">
                <input type="hidden" name="acao" value="criar_quadro">
                
                <div class="form-group">
                    <label for="nome">Nome do Quadro</label>
                    <input type="text" id="nome" name="nome" required>
                </div>
                
                <div class="form-group">
                    <label for="descricao">Descrição (opcional)</label>
                    <textarea id="descricao" name="descricao" rows="3"></textarea>
                </div>
                
                <div class="form-group">
                    <label for="cor">Cor</label>
                    <select id="cor" name="cor">
                        <option value="#3b82f6">Azul</option>
                        <option value="#10b981">Verde</option>
                        <option value="#f59e0b">Amarelo</option>
                        <option value="#ef4444">Vermelho</option>
                        <option value="#8b5cf6">Roxo</option>
                        <option value="#06b6d4">Ciano</option>
                    </select>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-outline" onclick="closeCreateQuadroModal()">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Criar Quadro</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openCreateQuadroModal() {
            document.getElementById('createQuadroModal').style.display = 'flex';
        }

        function closeCreateQuadroModal() {
            document.getElementById('createQuadroModal').style.display = 'none';
        }

        function toggle48h() {
            // Implementar toggle para mostrar próximas 48h
            location.href = 'demandas_agenda.php?periodo=48h';
        }

        // Fechar modal ao clicar fora
        window.onclick = function(event) {
            const modal = document.getElementById('createQuadroModal');
            if (event.target === modal) {
                closeCreateQuadroModal();
            }
        }
    </script>
</div>

<?php endSidebar(); ?>