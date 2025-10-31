<?php
/**
 * demandas_trello.php
 * Interface estilo Trello para sistema de Demandas
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';

if (!isset($_SESSION['logado']) || $_SESSION['logado'] != 1) {
    header('Location: login.php');
    exit;
}

$pdo = $GLOBALS['pdo'];
$usuario_id = (int)($_SESSION['user_id'] ?? 1);
$is_admin = isset($_SESSION['permissao']) && strpos($_SESSION['permissao'], 'admin') !== false;

// Buscar usuários para menções e atribuições
$stmt = $pdo->prepare("SELECT id, nome, email FROM usuarios ORDER BY nome");
$stmt->execute();
$usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar quadros disponíveis
$board_id = isset($_GET['board_id']) ? (int)$_GET['board_id'] : null;

includeSidebar('Demandas Trello');
?>

<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    
    body {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        background: #f4f5f7;
        color: #172b4d;
    }
    
    .trello-container {
        padding: 1.5rem;
        height: calc(100vh - 60px);
        overflow-x: auto;
        overflow-y: hidden;
    }
    
    .trello-header {
        background: white;
        padding: 1rem 1.5rem;
        margin-bottom: 1rem;
        border-radius: 8px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .trello-header h1 {
        font-size: 1.5rem;
        color: #172b4d;
    }
    
    .header-buttons {
        display: flex;
        gap: 0.75rem;
        align-items: center;
    }
    
    .btn {
        padding: 0.5rem 1rem;
        border-radius: 6px;
        font-weight: 500;
        cursor: pointer;
        border: none;
        font-size: 0.875rem;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
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
        border: 1px solid #3b82f6;
    }
    
    .btn-outline:hover {
        background: #eff6ff;
    }
    
    .notificacoes-badge {
        position: relative;
        cursor: pointer;
    }
    
    .notificacoes-count {
        position: absolute;
        top: -8px;
        right: -8px;
        background: #ef4444;
        color: white;
        border-radius: 10px;
        padding: 2px 6px;
        font-size: 0.75rem;
        font-weight: 600;
        min-width: 20px;
        text-align: center;
    }
    
    .boards-list {
        display: flex;
        gap: 1rem;
        margin-bottom: 1.5rem;
        overflow-x: auto;
        padding-bottom: 0.5rem;
    }
    
    .board-card {
        background: white;
        padding: 1rem;
        border-radius: 8px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        cursor: pointer;
        min-width: 200px;
        transition: transform 0.2s;
    }
    
    .board-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    }
    
    .board-card.active {
        border: 2px solid #3b82f6;
    }
    
    .trello-board {
        display: flex;
        gap: 1rem;
        height: calc(100vh - 250px);
        overflow-x: auto;
        overflow-y: hidden;
        padding-bottom: 1rem;
    }
    
    .trello-list {
        background: #ebecf0;
        border-radius: 8px;
        padding: 0.75rem;
        min-width: 300px;
        max-width: 300px;
        display: flex;
        flex-direction: column;
        height: fit-content;
        max-height: 100%;
    }
    
    .list-header {
        font-weight: 600;
        padding: 0.75rem;
        margin-bottom: 0.5rem;
        color: #172b4d;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .list-count {
        background: rgba(0,0,0,0.1);
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 0.75rem;
    }
    
    .cards-container {
        flex: 1;
        overflow-y: auto;
        min-height: 50px;
    }
    
    .card-item {
        background: white;
        border-radius: 6px;
        padding: 0.75rem;
        margin-bottom: 0.5rem;
        cursor: pointer;
        box-shadow: 0 1px 2px rgba(0,0,0,0.1);
        transition: all 0.2s;
        position: relative;
    }
    
    .card-item:hover {
        box-shadow: 0 2px 4px rgba(0,0,0,0.15);
        transform: translateY(-1px);
    }
    
    .card-item.vencido {
        border-left: 4px solid #ef4444;
    }
    
    .card-item.proximo-vencimento {
        border-left: 4px solid #f59e0b;
    }
    
    .card-item.concluido {
        opacity: 0.7;
        background: #f0f9ff;
    }
    
    .card-titulo {
        font-weight: 500;
        margin-bottom: 0.5rem;
        color: #172b4d;
    }
    
    .card-meta {
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 0.75rem;
        color: #6b7280;
        margin-top: 0.5rem;
    }
    
    .card-prazo {
        padding: 2px 6px;
        border-radius: 4px;
        font-size: 0.7rem;
    }
    
    .card-prazo.vencido {
        background: #fee2e2;
        color: #991b1b;
    }
    
    .card-prazo.proximo {
        background: #fef3c7;
        color: #92400e;
    }
    
    .card-usuarios {
        display: flex;
        gap: 0.25rem;
    }
    
    .avatar {
        width: 24px;
        height: 24px;
        border-radius: 50%;
        background: #3b82f6;
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.7rem;
        font-weight: 600;
    }
    
    .card-badges {
        display: flex;
        gap: 0.5rem;
        margin-top: 0.5rem;
        flex-wrap: wrap;
    }
    
    .badge {
        padding: 2px 6px;
        border-radius: 4px;
        font-size: 0.7rem;
        font-weight: 500;
    }
    
    /* Sistema de Alertas Customizados */
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
    
    .custom-alert-btn-danger {
        background: #ef4444;
        color: white;
    }
    
    .custom-alert-btn-danger:hover {
        background: #dc2626;
    }
    
    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }
    
    @keyframes slideUp {
        from {
            opacity: 0;
            transform: translateY(20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    .badge-comentarios {
        background: #eff6ff;
        color: #1e40af;
    }
    
    .badge-anexos {
        background: #f0f9ff;
        color: #0369a1;
    }
    
    .badge-prioridade {
        color: white;
        font-weight: 600;
    }
    
    .prioridade-baixa { background: #10b981; }
    .prioridade-media { background: #3b82f6; }
    .prioridade-alta { background: #f59e0b; }
    .prioridade-urgente { background: #ef4444; }
    
    .add-card-btn {
        background: transparent;
        border: none;
        color: #6b7280;
        padding: 0.75rem;
        width: 100%;
        text-align: left;
        cursor: pointer;
        border-radius: 6px;
        transition: background 0.2s;
    }
    
    .add-card-btn:hover {
        background: rgba(0,0,0,0.05);
    }
    
    .modal {
        display: none;
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.5);
        overflow-y: auto;
    }
    
    .modal-content {
        background: white;
        margin: 2rem auto;
        padding: 2rem;
        border-radius: 12px;
        max-width: 700px;
        width: 90%;
        box-shadow: 0 10px 25px rgba(0,0,0,0.2);
    }
    
    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.5rem;
    }
    
    .modal-header h2 {
        font-size: 1.5rem;
        color: #172b4d;
    }
    
    .close {
        font-size: 2rem;
        font-weight: 300;
        color: #6b7280;
        cursor: pointer;
        line-height: 1;
    }
    
    .close:hover {
        color: #172b4d;
    }
    
    .form-group {
        margin-bottom: 1rem;
    }
    
    .form-group label {
        display: block;
        margin-bottom: 0.5rem;
        font-weight: 500;
        color: #374151;
    }
    
    .form-group input,
    .form-group textarea,
    .form-group select {
        width: 100%;
        padding: 0.75rem;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        font-size: 0.875rem;
        font-family: inherit;
    }
    
    .form-group textarea {
        resize: vertical;
        min-height: 100px;
    }
    
    .comentarios-section {
        margin-top: 2rem;
        padding-top: 2rem;
        border-top: 1px solid #e5e7eb;
    }
    
    .comentario-item {
        background: #f9fafb;
        padding: 1rem;
        border-radius: 8px;
        margin-bottom: 1rem;
    }
    
    .comentario-header {
        display: flex;
        justify-content: space-between;
        margin-bottom: 0.5rem;
        font-size: 0.875rem;
    }
    
    .comentario-autor {
        font-weight: 600;
        color: #172b4d;
    }
    
    .comentario-data {
        color: #6b7280;
    }
    
    .comentario-texto {
        color: #374151;
        line-height: 1.5;
    }
    
    .anexos-list {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
        margin-top: 1rem;
    }
    
    .anexo-item {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.75rem;
        background: #f9fafb;
        border-radius: 6px;
    }
    
    .sortable-ghost {
        opacity: 0.4;
    }
    
    .sortable-drag {
        opacity: 0.8;
    }
    
    .empty-state {
        text-align: center;
        padding: 2rem;
        color: #6b7280;
    }
</style>

<div class="trello-container">
    <div class="trello-header">
        <div>
            <h1>📋 Demandas</h1>
            <div id="board-selector"></div>
        </div>
        <div class="header-buttons">
            <button class="btn btn-outline" onclick="abrirModalNovoQuadro()">
                ➕ Novo Quadro
            </button>
            <button class="btn btn-outline" onclick="abrirModalNovaLista()">
                📋 Nova Lista
            </button>
            <button class="btn btn-outline" onclick="abrirModalDemandasFixas()">
                📅 Demandas Fixas
            </button>
            <button class="btn btn-primary" onclick="abrirModalNovoCard()">
                ➕ Novo Card
            </button>
            <div class="notificacoes-badge" onclick="toggleNotificacoes()">
                🔔
                <span id="notificacoes-count" class="notificacoes-count" style="display: none;">0</span>
            </div>
        </div>
    </div>
    
    <div id="trello-board" class="trello-board">
        <div class="empty-state">
            <p>Selecione ou crie um quadro para começar</p>
        </div>
    </div>
</div>

<!-- Modal: Novo Quadro -->
<div id="modal-novo-quadro" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Novo Quadro</h2>
            <span class="close" onclick="fecharModal('modal-novo-quadro')">&times;</span>
        </div>
        <form id="form-novo-quadro">
            <div class="form-group">
                <label for="quadro-nome">Nome *</label>
                <input type="text" id="quadro-nome" required>
            </div>
            <div class="form-group">
                <label for="quadro-descricao">Descrição</label>
                <textarea id="quadro-descricao" rows="3"></textarea>
            </div>
            <div class="form-group">
                <label for="quadro-cor">Cor</label>
                <input type="color" id="quadro-cor" value="#3b82f6">
            </div>
            <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                <button type="button" class="btn btn-outline" onclick="fecharModal('modal-novo-quadro')">Cancelar</button>
                <button type="submit" class="btn btn-primary">Criar Quadro</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Nova Lista -->
<div id="modal-nova-lista" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Nova Lista</h2>
            <span class="close" onclick="fecharModal('modal-nova-lista')">&times;</span>
        </div>
        <form id="form-nova-lista">
            <div class="form-group">
                <label for="lista-nome">Nome *</label>
                <input type="text" id="lista-nome" required>
            </div>
            <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                <button type="button" class="btn btn-outline" onclick="fecharModal('modal-nova-lista')">Cancelar</button>
                <button type="submit" class="btn btn-primary">Criar Lista</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Editar Card -->
<div id="modal-editar-card" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Editar Card</h2>
            <span class="close" onclick="fecharModal('modal-editar-card')">&times;</span>
        </div>
        <form id="form-editar-card">
            <input type="hidden" id="edit-card-id">
            <div class="form-group">
                <label for="edit-card-titulo">Título *</label>
                <input type="text" id="edit-card-titulo" required>
            </div>
            <div class="form-group">
                <label for="edit-card-descricao">Descrição</label>
                <textarea id="edit-card-descricao" rows="4"></textarea>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group">
                    <label for="edit-card-prazo">Prazo</label>
                    <input type="date" id="edit-card-prazo">
                </div>
                <div class="form-group">
                    <label for="edit-card-prioridade">Prioridade</label>
                    <select id="edit-card-prioridade">
                        <option value="baixa">Baixa</option>
                        <option value="media">Média</option>
                        <option value="alta">Alta</option>
                        <option value="urgente">Urgente</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label for="edit-card-categoria">Categoria</label>
                <input type="text" id="edit-card-categoria">
            </div>
            <div class="form-group">
                <label for="edit-card-usuarios">Responsáveis</label>
                <select id="edit-card-usuarios" multiple style="min-height: 100px;">
                    <?php foreach ($usuarios as $user): ?>
                        <option value="<?= $user['id'] ?>"><?= htmlspecialchars($user['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
                <small style="color: #6b7280; font-size: 0.75rem;">Mantenha Ctrl/Cmd pressionado para selecionar múltiplos</small>
            </div>
            <div class="form-group">
                <label for="edit-card-anexo">📎 Adicionar Anexo</label>
                <input type="file" id="edit-card-anexo" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx">
                <button type="button" class="btn btn-outline" onclick="adicionarAnexoNoEditar()" style="margin-top: 0.5rem;">➕ Adicionar Arquivo</button>
                <small style="color: #6b7280; font-size: 0.75rem; display: block; margin-top: 0.5rem;">Formatos: PDF, imagens, Word, Excel (máx. 10MB)</small>
            </div>
            <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                <button type="button" class="btn btn-outline" onclick="fecharModal('modal-editar-card')">Cancelar</button>
                <button type="submit" class="btn btn-primary">Salvar</button>
                <button type="button" class="btn" onclick="deletarCardAtual()" style="background: #ef4444; color: white;">🗑️ Deletar</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Novo Card -->
<div id="modal-novo-card" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Novo Card</h2>
            <span class="close" onclick="fecharModal('modal-novo-card')">&times;</span>
        </div>
        <form id="form-novo-card">
            <div class="form-group">
                <label for="card-titulo">Título *</label>
                <input type="text" id="card-titulo" required>
            </div>
            <div class="form-group">
                <label for="card-lista">Lista *</label>
                <select id="card-lista" required></select>
            </div>
            <div class="form-group">
                <label for="card-descricao">Descrição</label>
                <textarea id="card-descricao"></textarea>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group">
                    <label for="card-prazo">Prazo</label>
                    <input type="date" id="card-prazo">
                </div>
                <div class="form-group">
                    <label for="card-prioridade">Prioridade</label>
                    <select id="card-prioridade">
                        <option value="media">Média</option>
                        <option value="baixa">Baixa</option>
                        <option value="alta">Alta</option>
                        <option value="urgente">Urgente</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label for="card-categoria">Categoria</label>
                <input type="text" id="card-categoria" placeholder="Ex: Marketing, Vendas...">
            </div>
            <div class="form-group">
                <label for="card-usuarios">Responsáveis</label>
                <select id="card-usuarios" multiple style="min-height: 100px;">
                    <?php foreach ($usuarios as $user): ?>
                        <option value="<?= $user['id'] ?>"><?= htmlspecialchars($user['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
                <small style="color: #6b7280; font-size: 0.75rem;">Mantenha Ctrl/Cmd pressionado para selecionar múltiplos</small>
            </div>
            <div class="form-group">
                <label for="card-anexo">📎 Anexar Arquivo (opcional)</label>
                <input type="file" id="card-anexo" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx">
                <small style="color: #6b7280; font-size: 0.75rem;">Formatos: PDF, imagens, Word, Excel (máx. 10MB)</small>
            </div>
            <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                <button type="button" class="btn btn-outline" onclick="fecharModal('modal-novo-card')">Cancelar</button>
                <button type="submit" class="btn btn-primary">Criar Card</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Ver Card -->
<div id="modal-ver-card" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="modal-card-titulo">Card</h2>
            <span class="close" onclick="fecharModal('modal-ver-card')">&times;</span>
        </div>
        <div id="modal-card-content">
            <!-- Preenchido via JS -->
        </div>
    </div>
</div>

<!-- Notificações Dropdown -->
<div id="notificacoes-dropdown" style="display: none; position: fixed; top: 70px; right: 20px; background: white; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); min-width: 300px; max-width: 400px; max-height: 500px; overflow-y: auto; z-index: 1000; padding: 1rem;">
    <h3 style="margin-bottom: 1rem; font-size: 1rem;">Notificações</h3>
    <div id="notificacoes-list"></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
<script>
// Estado global
let boards = [];
let currentBoardId = null;
let lists = [];
let cards = {};
let notificacoes = [];
let usuarios = <?= json_encode($usuarios) ?>;

// API Base
const API_BASE = 'demandas_trello_api.php';

// Inicialização
document.addEventListener('DOMContentLoaded', function() {
    carregarQuadros();
    carregarNotificacoes();
    setInterval(carregarNotificacoes, 30000); // Atualizar a cada 30s
    
    // Forms
    document.getElementById('form-novo-card').addEventListener('submit', function(e) {
        e.preventDefault();
        criarCard();
    });
    
    document.getElementById('form-novo-quadro').addEventListener('submit', function(e) {
        e.preventDefault();
        criarQuadro();
    });
    
    document.getElementById('form-nova-lista').addEventListener('submit', function(e) {
        e.preventDefault();
        criarLista();
    });
    
    document.getElementById('form-editar-card').addEventListener('submit', function(e) {
        e.preventDefault();
        salvarEdicaoCard();
    });
});

// ============================================
// QUADROS
// ============================================

async function carregarQuadros() {
    try {
        const response = await fetch(`${API_BASE}?action=quadros`);
        const data = await response.json();
        
        if (data.success) {
            boards = data.data;
            renderizarQuadros();
            
            // Se houver board_id na URL, selecionar
            const urlParams = new URLSearchParams(window.location.search);
            const boardId = urlParams.get('board_id');
            if (boardId) {
                selecionarQuadro(parseInt(boardId));
            } else if (boards.length > 0) {
                selecionarQuadro(boards[0].id);
            }
        }
    } catch (error) {
        console.error('Erro ao carregar quadros:', error);
    }
}

function renderizarQuadros() {
    const container = document.getElementById('board-selector');
    if (boards.length === 0) {
        container.innerHTML = '<p style="color: #6b7280; margin-top: 0.5rem;">Nenhum quadro encontrado</p>';
        return;
    }
    
    container.innerHTML = '<div class="boards-list">' + 
        boards.map(board => `
            <div class="board-card ${currentBoardId === board.id ? 'active' : ''}" 
                 onclick="selecionarQuadro(${board.id})">
                <div style="font-weight: 600; margin-bottom: 0.5rem;">${board.nome}</div>
                <div style="font-size: 0.75rem; color: #6b7280;">
                    ${board.total_listas || 0} listas • ${board.total_cards || 0} cards
                </div>
            </div>
        `).join('') + '</div>';
}

function selecionarQuadro(boardId) {
    currentBoardId = boardId;
    renderizarQuadros();
    carregarListas(boardId);
    window.history.replaceState({}, '', `?page=demandas&board_id=${boardId}`);
}

// ============================================
// LISTAS E CARDS
// ============================================

async function carregarListas(boardId) {
    try {
        const response = await fetch(`${API_BASE}?action=listas&id=${boardId}`);
        const data = await response.json();
        
        if (data.success) {
            lists = data.data;
            await carregarTodosCards();
            renderizarBoard();
        }
    } catch (error) {
        console.error('Erro ao carregar listas:', error);
    }
}

async function carregarTodosCards() {
    cards = {};
    for (const lista of lists) {
        try {
            const response = await fetch(`${API_BASE}?action=cards&id=${lista.id}`);
            const data = await response.json();
            
            if (data.success) {
                cards[lista.id] = data.data;
            }
        } catch (error) {
            console.error(`Erro ao carregar cards da lista ${lista.id}:`, error);
            cards[lista.id] = [];
        }
    }
}

function renderizarBoard() {
    const container = document.getElementById('trello-board');
    
    if (lists.length === 0) {
        container.innerHTML = '<div class="empty-state"><p>Nenhuma lista encontrada neste quadro</p></div>';
        return;
    }
    
    container.innerHTML = lists.map(lista => `
        <div class="trello-list" data-lista-id="${lista.id}">
            <div class="list-header">
                <span>${lista.nome}</span>
                <div style="display: flex; gap: 0.5rem; align-items: center;">
                    <span class="list-count">${cards[lista.id]?.length || 0}</span>
                    <button onclick="deletarLista(${lista.id})" style="background: transparent; border: none; color: #6b7280; cursor: pointer; font-size: 0.875rem;" title="Deletar lista">🗑️</button>
                </div>
            </div>
            <div class="cards-container" id="cards-${lista.id}">
                ${renderizarCards(lista.id)}
            </div>
            <button class="add-card-btn" onclick="abrirModalNovoCard(${lista.id})">
                ➕ Adicionar card
            </button>
        </div>
    `).join('');
    
    // Inicializar Sortable.js para cada lista
    lists.forEach(lista => {
        new Sortable(document.getElementById(`cards-${lista.id}`), {
            group: 'shared',
            animation: 150,
            onEnd: function(evt) {
                const cardId = parseInt(evt.item.dataset.cardId);
                const novaListaId = parseInt(evt.to.closest('.trello-list').dataset.listaId);
                const novaPosicao = evt.newIndex;
                moverCard(cardId, novaListaId, novaPosicao);
            }
        });
    });
}

function renderizarCards(listaId) {
    const cardsList = cards[listaId] || [];
    
    if (cardsList.length === 0) {
        return '<div class="empty-state" style="padding: 1rem; font-size: 0.875rem;">Nenhum card</div>';
    }
    
    return cardsList.map(card => {
        const hoje = new Date();
        const prazo = card.prazo ? new Date(card.prazo) : null;
        let prazoClass = '';
        let prazoText = '';
        
        if (prazo) {
            const diffDays = Math.ceil((prazo - hoje) / (1000 * 60 * 60 * 24));
            if (diffDays < 0) {
                prazoClass = 'vencido';
                prazoText = 'Vencido';
            } else if (diffDays <= 3) {
                prazoClass = 'proximo';
                prazoText = `Em ${diffDays} dias`;
            } else {
                prazoText = prazo.toLocaleDateString('pt-BR');
            }
        }
        
        const cardClass = [
            card.status === 'concluido' ? 'concluido' : '',
            prazoClass === 'vencido' ? 'vencido' : '',
            prazoClass === 'proximo' ? 'proximo-vencimento' : ''
        ].filter(Boolean).join(' ');
        
        return `
            <div class="card-item ${cardClass}" 
                 data-card-id="${card.id}"
                 onclick="verCard(${card.id})">
                <div class="card-titulo">${card.titulo}</div>
                ${card.descricao ? `<div style="font-size: 0.75rem; color: #6b7280; margin: 0.5rem 0;">${card.descricao.substring(0, 100)}${card.descricao.length > 100 ? '...' : ''}</div>` : ''}
                <div class="card-badges">
                    ${card.prioridade && card.prioridade !== 'media' ? `<span class="badge badge-prioridade prioridade-${card.prioridade}">${card.prioridade}</span>` : ''}
                    ${card.total_comentarios > 0 ? `<span class="badge badge-comentarios">💬 ${card.total_comentarios}</span>` : ''}
                    ${card.total_anexos > 0 ? `<span class="badge badge-anexos">📎 ${card.total_anexos}</span>` : ''}
                </div>
                <div class="card-meta">
                    ${prazo ? `<span class="card-prazo ${prazoClass}">📅 ${prazoText}</span>` : ''}
                    <div class="card-usuarios">
                        ${(card.usuarios || []).slice(0, 3).map(u => 
                            `<div class="avatar" title="${u.nome}">${u.nome.charAt(0).toUpperCase()}</div>`
                        ).join('')}
                        ${(card.usuarios || []).length > 3 ? `<div class="avatar">+${(card.usuarios || []).length - 3}</div>` : ''}
                    </div>
                </div>
            </div>
        `;
    }).join('');
}

// ============================================
// CRIAÇÃO E EDIÇÃO
// ============================================

async function criarCard(listaIdPredefinida = null) {
    const titulo = document.getElementById('card-titulo').value;
    const listaId = listaIdPredefinida || parseInt(document.getElementById('card-lista').value);
    const descricao = document.getElementById('card-descricao').value;
    const prazo = document.getElementById('card-prazo').value || null;
    const prioridade = document.getElementById('card-prioridade').value;
    const categoria = document.getElementById('card-categoria').value || null;
    const usuarios = Array.from(document.getElementById('card-usuarios').selectedOptions).map(opt => parseInt(opt.value));
    const anexoInput = document.getElementById('card-anexo');
    const temAnexo = anexoInput && anexoInput.files[0];
    
    try {
        // Criar card primeiro
        const response = await fetch(`${API_BASE}?action=criar_card`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                lista_id: listaId,
                titulo,
                descricao,
                prazo,
                prioridade,
                categoria,
                usuarios
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            const cardId = data.data.id;
            
            // Se houver anexo, fazer upload
            if (temAnexo) {
                const formData = new FormData();
                formData.append('arquivo', anexoInput.files[0]);
                
                try {
                    const anexoResponse = await fetch(`${API_BASE}?action=anexo&id=${cardId}`, {
                        method: 'POST',
                        body: formData
                    });
                    
                    const anexoData = await anexoResponse.json();
                    if (!anexoData.success) {
                        console.error('Erro ao anexar arquivo:', anexoData.error);
                        // Não falhar a criação do card se o anexo falhar
                    }
                } catch (anexoError) {
                    console.error('Erro ao anexar arquivo:', anexoError);
                }
            }
            
            fecharModal('modal-novo-card');
            document.getElementById('form-novo-card').reset();
            await carregarTodosCards();
            renderizarBoard();
            mostrarToast('✅ Card criado com sucesso!' + (temAnexo ? ' Arquivo anexado.' : ''));
        } else {
            customAlert('Erro: ' + (data.error || 'Erro desconhecido'), '❌ Erro');
        }
        } catch (error) {
        console.error('Erro ao criar card:', error);
        customAlert('Erro ao criar card', '❌ Erro');
    }
}

async function moverCard(cardId, novaListaId, novaPosicao) {
    try {
        await fetch(`${API_BASE}?action=mover_card&id=${cardId}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                nova_lista_id: novaListaId,
                nova_posicao: novaPosicao
            })
        });
        
        // Recarregar cards
        await carregarTodosCards();
        renderizarBoard();
    } catch (error) {
        console.error('Erro ao mover card:', error);
    }
}

async function verCard(cardId) {
    try {
        const response = await fetch(`${API_BASE}?action=card&id=${cardId}`);
        const data = await response.json();
        
        if (data.success) {
            const card = data.data;
            document.getElementById('modal-card-titulo').textContent = card.titulo;
            
            const hoje = new Date();
            const prazo = card.prazo ? new Date(card.prazo) : null;
            let prazoHtml = '';
            
            if (prazo) {
                const diffDays = Math.ceil((prazo - hoje) / (1000 * 60 * 60 * 24));
                let prazoClass = '';
                if (diffDays < 0) {
                    prazoClass = 'vencido';
                    prazoHtml = `<p><strong>Prazo:</strong> <span class="card-prazo vencido">Vencido há ${Math.abs(diffDays)} dias</span></p>`;
                } else if (diffDays <= 3) {
                    prazoClass = 'proximo';
                    prazoHtml = `<p><strong>Prazo:</strong> <span class="card-prazo proximo">Em ${diffDays} dias (${prazo.toLocaleDateString('pt-BR')})</span></p>`;
                } else {
                    prazoHtml = `<p><strong>Prazo:</strong> ${prazo.toLocaleDateString('pt-BR')}</p>`;
                }
            }
            
            document.getElementById('modal-card-content').innerHTML = `
                <div>
                    ${prazoHtml}
                    ${card.descricao ? `<p style="margin: 1rem 0; line-height: 1.6;">${card.descricao}</p>` : ''}
                    <p><strong>Status:</strong> ${card.status}</p>
                    <p><strong>Prioridade:</strong> ${card.prioridade || 'Média'}</p>
                    ${card.categoria ? `<p><strong>Categoria:</strong> ${card.categoria}</p>` : ''}
                    <p><strong>Criado por:</strong> ${card.criador_nome || 'Desconhecido'}</p>
                    <p><strong>Responsáveis:</strong> ${(card.usuarios || []).map(u => u.nome).join(', ') || 'Nenhum'}</p>
                </div>
                
                <div class="comentarios-section">
                    <h3>💬 Comentários</h3>
                    <div id="comentarios-list">
                        ${(card.comentarios || []).map(c => `
                            <div class="comentario-item">
                                <div class="comentario-header">
                                    <span class="comentario-autor">${c.autor_nome || 'Anônimo'}</span>
                                    <span class="comentario-data">${new Date(c.criado_em).toLocaleString('pt-BR')}</span>
                                </div>
                                <div class="comentario-texto">${c.mensagem}</div>
                            </div>
                        `).join('')}
                    </div>
                    <div style="margin-top: 1rem;">
                        <textarea id="novo-comentario" placeholder="Digite seu comentário... Use @usuario para mencionar" rows="3" style="width: 100%; padding: 0.75rem; border: 1px solid #d1d5db; border-radius: 6px;"></textarea>
                        <button class="btn btn-primary" onclick="adicionarComentario(${card.id})" style="margin-top: 0.5rem;">Adicionar</button>
                    </div>
                </div>
                
                <div class="comentarios-section">
                    <h3>📎 Anexos</h3>
                    <div class="anexos-list">
                        ${(card.anexos || []).map(a => `
                            <div class="anexo-item">
                                <span>📄 ${a.nome_original}</span>
                                <div style="display: flex; gap: 0.5rem;">
                                    <button class="btn btn-outline" onclick="downloadAnexo(${a.id})">Download</button>
                                    <button class="btn" onclick="deletarAnexoTrello(${a.id}, ${card.id})" style="background: #ef4444; color: white;">🗑️</button>
                                </div>
                            </div>
                        `).join('')}
                    </div>
                    <div style="margin-top: 1rem; padding: 1rem; background: #f9fafb; border-radius: 6px; border: 2px dashed #d1d5db;">
                        <label style="display: block; font-weight: 600; margin-bottom: 0.5rem; color: #374151;">➕ Adicionar Novo Anexo</label>
                        <input type="file" id="novo-anexo" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx" style="margin-bottom: 0.75rem; width: 100%; padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 4px;">
                        <button class="btn btn-primary" onclick="adicionarAnexo(${card.id})" style="width: 100%;">📎 Anexar Arquivo</button>
                        <small style="display: block; margin-top: 0.5rem; color: #6b7280; font-size: 0.75rem;">Formatos aceitos: PDF, imagens, Word, Excel (máx. 10MB)</small>
                    </div>
                </div>
                
                <div style="margin-top: 2rem; display: flex; gap: 1rem;">
                    <button class="btn btn-primary" onclick="editarCard(${card.id})">✏️ Editar</button>
                    ${card.status === 'concluido' 
                        ? `<button class="btn btn-outline" onclick="reabrirCard(${card.id})">🔄 Reabrir</button>`
                        : `<button class="btn btn-primary" onclick="concluirCard(${card.id})">✅ Concluir</button>`
                    }
                    <button class="btn" onclick="deletarCardConfirmado(${card.id})" style="background: #ef4444; color: white;">🗑️ Deletar</button>
                    <button class="btn btn-outline" onclick="fecharModal('modal-ver-card')">Fechar</button>
                </div>
            `;
            
            document.getElementById('modal-ver-card').style.display = 'block';
        }
    } catch (error) {
        console.error('Erro ao carregar card:', error);
        customAlert('Erro ao carregar card', '❌ Erro');
    }
}

// ============================================
// COMENTÁRIOS E ANEXOS
// ============================================

async function adicionarComentario(cardId) {
    const mensagem = document.getElementById('novo-comentario').value.trim();
    if (!mensagem) {
        customAlert('Digite um comentário', '⚠️ Atenção');
        return;
    }
    
    try {
        const response = await fetch(`${API_BASE}?action=comentario&id=${cardId}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ mensagem })
        });
        
        const data = await response.json();
        
        if (data.success) {
            document.getElementById('novo-comentario').value = '';
            verCard(cardId); // Recarregar
            mostrarToast('✅ Comentário adicionado!');
        } else {
            customAlert('Erro: ' + (data.error || 'Erro desconhecido'), '❌ Erro');
        }
    } catch (error) {
        console.error('Erro ao adicionar comentário:', error);
        customAlert('Erro ao adicionar comentário', '❌ Erro');
    }
}

async function adicionarAnexo(cardId) {
    const input = document.getElementById('novo-anexo');
    if (!input.files[0]) {
        customAlert('Selecione um arquivo', '⚠️ Atenção');
        return;
    }
    
    const formData = new FormData();
    formData.append('arquivo', input.files[0]);
    
    try {
        const response = await fetch(`${API_BASE}?action=anexo&id=${cardId}`, {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            input.value = '';
            verCard(cardId); // Recarregar
            mostrarToast('✅ Anexo adicionado!');
        } else {
            customAlert('Erro: ' + (data.error || 'Erro desconhecido'), '❌ Erro');
        }
    } catch (error) {
        console.error('Erro ao adicionar anexo:', error);
        customAlert('Erro ao adicionar anexo', '❌ Erro');
    }
}

async function downloadAnexo(anexoId) {
    try {
        const response = await fetch(`${API_BASE}?action=anexo&id=${anexoId}`);
        const data = await response.json();
        
        if (data.success && data.url) {
            window.open(data.url, '_blank');
        } else {
            customAlert('Erro ao baixar arquivo: ' + (data.error || 'Erro desconhecido'), '❌ Erro');
        }
    } catch (error) {
        console.error('Erro:', error);
        customAlert('Erro ao baixar arquivo', '❌ Erro');
    }
}

async function deletarAnexoTrello(anexoId, cardId) {
    const confirmado = await customConfirm('Deseja realmente excluir este anexo?', '⚠️ Confirmar Exclusão');
    if (!confirmado) {
        return;
    }
    
    try {
        const response = await fetch(`${API_BASE}?action=deletar_anexo&id=${anexoId}`, {
            method: 'DELETE'
        });
        
        const data = await response.json();
        
        if (data.success) {
            verCard(cardId); // Recarregar modal
            mostrarToast('✅ Anexo deletado!');
        } else {
            customAlert('Erro: ' + (data.error || 'Erro desconhecido'), '❌ Erro');
        }
    } catch (error) {
        console.error('Erro:', error);
        alert('Erro ao deletar anexo');
    }
}

// ============================================
// AÇÕES
// ============================================

async function concluirCard(cardId) {
    try {
        const response = await fetch(`${API_BASE}?action=concluir&id=${cardId}`, { method: 'POST' });
        const data = await response.json();
        
        if (data.success) {
            fecharModal('modal-ver-card');
            await carregarTodosCards();
            renderizarBoard();
            mostrarToast('✅ Card concluído!');
        }
    } catch (error) {
        console.error('Erro:', error);
    }
}

async function reabrirCard(cardId) {
    try {
        const response = await fetch(`${API_BASE}?action=reabrir&id=${cardId}`, { method: 'POST' });
        const data = await response.json();
        
        if (data.success) {
            fecharModal('modal-ver-card');
            await carregarTodosCards();
            renderizarBoard();
            mostrarToast('✅ Card reaberto!');
        }
    } catch (error) {
        console.error('Erro:', error);
    }
}

// ============================================
// NOTIFICAÇÕES
// ============================================

async function carregarNotificacoes() {
    try {
        const response = await fetch(`${API_BASE}?action=notificacoes`);
        const data = await response.json();
        
        if (data.success) {
            notificacoes = data.data;
            const naoLidas = data.nao_lidas || 0;
            
            const countEl = document.getElementById('notificacoes-count');
            if (naoLidas > 0) {
                countEl.textContent = naoLidas;
                countEl.style.display = 'block';
            } else {
                countEl.style.display = 'none';
            }
            
            renderizarNotificacoes();
        }
    } catch (error) {
        console.error('Erro ao carregar notificações:', error);
    }
}

function renderizarNotificacoes() {
    const container = document.getElementById('notificacoes-list');
    
    if (notificacoes.length === 0) {
        container.innerHTML = '<p style="color: #6b7280; text-align: center; padding: 1rem;">Nenhuma notificação</p>';
        return;
    }
    
    container.innerHTML = notificacoes.map(notif => `
        <div style="padding: 0.75rem; border-bottom: 1px solid #e5e7eb; cursor: pointer; ${notif.lida ? 'opacity: 0.6;' : 'background: #eff6ff;'}"
             onclick="marcarNotificacaoLida(${notif.id})">
            <div style="font-weight: 500; margin-bottom: 0.25rem;">${notif.mensagem}</div>
            <div style="font-size: 0.75rem; color: #6b7280;">${new Date(notif.criada_em).toLocaleString('pt-BR')}</div>
        </div>
    `).join('');
}

function toggleNotificacoes() {
    const dropdown = document.getElementById('notificacoes-dropdown');
    dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';
}

async function marcarNotificacaoLida(notifId) {
    try {
        await fetch(`${API_BASE}?action=marcar_notificacao&id=${notifId}`, { method: 'POST' });
        await carregarNotificacoes();
        
        // Se tiver referencia_id, abrir o card
        const notif = notificacoes.find(n => n.id === notifId);
        if (notif && notif.referencia_id) {
            verCard(notif.referencia_id);
        }
    } catch (error) {
        console.error('Erro:', error);
    }
}

// ============================================
// QUADROS E LISTAS
// ============================================

async function criarQuadro() {
    const nome = document.getElementById('quadro-nome').value.trim();
    const descricao = document.getElementById('quadro-descricao').value;
    const cor = document.getElementById('quadro-cor').value;
    
    if (!nome) {
        customAlert('Nome é obrigatório', '⚠️ Atenção');
        return;
    }
    
    try {
        const response = await fetch(`${API_BASE}?action=criar_quadro`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ nome, descricao, cor })
        });
        
        const data = await response.json();
        
        if (data.success) {
            fecharModal('modal-novo-quadro');
            document.getElementById('form-novo-quadro').reset();
            await carregarQuadros();
            selecionarQuadro(data.data.id);
            mostrarToast('✅ Quadro criado com sucesso!');
        } else {
            customAlert('Erro: ' + (data.error || 'Erro desconhecido'), '❌ Erro');
        }
    } catch (error) {
        console.error('Erro:', error);
        customAlert('Erro ao criar quadro', '❌ Erro');
    }
}

async function criarLista() {
    if (!currentBoardId) {
        customAlert('Selecione um quadro primeiro', '⚠️ Atenção');
        return;
    }
    
    const nome = document.getElementById('lista-nome').value.trim();
    if (!nome) {
        customAlert('Nome é obrigatório', '⚠️ Atenção');
        return;
    }
    
    try {
        const response = await fetch(`${API_BASE}?action=criar_lista&id=${currentBoardId}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ nome })
        });
        
        const data = await response.json();
        
        if (data.success) {
            fecharModal('modal-nova-lista');
            document.getElementById('form-nova-lista').reset();
            await carregarListas(currentBoardId);
            mostrarToast('✅ Lista criada com sucesso!');
        } else {
            customAlert('Erro: ' + (data.error || 'Erro desconhecido'), '❌ Erro');
        }
    } catch (error) {
        console.error('Erro:', error);
        customAlert('Erro ao criar lista', '❌ Erro');
    }
}

async function deletarLista(listaId) {
    const confirmado = await customConfirm('Tem certeza? Todos os cards desta lista serão deletados.', '⚠️ Confirmar Exclusão');
    if (!confirmado) {
        return;
    }
    
    try {
        const response = await fetch(`${API_BASE}?action=deletar_lista&id=${listaId}`, {
            method: 'DELETE'
        });
        
        const data = await response.json();
        
        if (data.success) {
            await carregarListas(currentBoardId);
            mostrarToast('✅ Lista deletada!');
        } else {
            customAlert('Erro: ' + (data.error || 'Erro desconhecido'), '❌ Erro');
        }
    } catch (error) {
        console.error('Erro:', error);
        customAlert('Erro ao deletar lista', '❌ Erro');
    }
}

// ============================================
// EDIÇÃO DE CARDS
// ============================================

let cardEditando = null;

async function editarCard(cardId) {
    try {
        const response = await fetch(`${API_BASE}?action=card&id=${cardId}`);
        const data = await response.json();
        
        if (data.success) {
            const card = data.data;
            cardEditando = cardId;
            
            document.getElementById('edit-card-id').value = card.id;
            document.getElementById('edit-card-titulo').value = card.titulo;
            document.getElementById('edit-card-descricao').value = card.descricao || '';
            document.getElementById('edit-card-prazo').value = card.prazo || '';
            document.getElementById('edit-card-prioridade').value = card.prioridade || 'media';
            document.getElementById('edit-card-categoria').value = card.categoria || '';
            
            // Selecionar usuários
            const selectUsuarios = document.getElementById('edit-card-usuarios');
            Array.from(selectUsuarios.options).forEach(opt => opt.selected = false);
            (card.usuarios || []).forEach(u => {
                const option = selectUsuarios.querySelector(`option[value="${u.id}"]`);
                if (option) option.selected = true;
            });
            
            fecharModal('modal-ver-card');
            document.getElementById('modal-editar-card').style.display = 'block';
        }
    } catch (error) {
        console.error('Erro:', error);
        customAlert('Erro ao carregar card', '❌ Erro');
    }
}

async function salvarEdicaoCard() {
    const cardId = parseInt(document.getElementById('edit-card-id').value);
    const titulo = document.getElementById('edit-card-titulo').value.trim();
    const descricao = document.getElementById('edit-card-descricao').value;
    const prazo = document.getElementById('edit-card-prazo').value || null;
    const prioridade = document.getElementById('edit-card-prioridade').value;
    const categoria = document.getElementById('edit-card-categoria').value || null;
    const usuarios = Array.from(document.getElementById('edit-card-usuarios').selectedOptions).map(opt => parseInt(opt.value));
    
    try {
        const response = await fetch(`${API_BASE}?action=atualizar_card&id=${cardId}`, {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                titulo,
                descricao,
                prazo,
                prioridade,
                categoria,
                usuarios
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            fecharModal('modal-editar-card');
            await carregarTodosCards();
            renderizarBoard();
            mostrarToast('✅ Card atualizado!');
        } else {
            customAlert('Erro: ' + (data.error || 'Erro desconhecido'), '❌ Erro');
        }
    } catch (error) {
        console.error('Erro:', error);
        customAlert('Erro ao atualizar card', '❌ Erro');
    }
}

async function deletarCardAtual() {
    const cardId = parseInt(document.getElementById('edit-card-id').value);
    deletarCardConfirmado(cardId);
}

async function deletarCardConfirmado(cardId) {
    const confirmado = await customConfirm('Tem certeza que deseja deletar este card? Esta ação não pode ser desfeita.', '⚠️ Confirmar Exclusão');
    if (!confirmado) {
        return;
    }
    
    try {
        const response = await fetch(`${API_BASE}?action=deletar_card&id=${cardId}`, {
            method: 'DELETE'
        });
        
        const data = await response.json();
        
        if (data.success) {
            fecharModal('modal-ver-card');
            fecharModal('modal-editar-card');
            await carregarTodosCards();
            renderizarBoard();
            mostrarToast('✅ Card deletado!');
        } else {
            customAlert('Erro: ' + (data.error || 'Erro desconhecido'), '❌ Erro');
        }
    } catch (error) {
        console.error('Erro:', error);
        customAlert('Erro ao deletar card', '❌ Erro');
    }
}

// Função para adicionar anexo no modal de edição
async function adicionarAnexoNoEditar() {
    const anexoInput = document.getElementById('edit-card-anexo');
    const cardId = parseInt(document.getElementById('edit-card-id').value);
    
    if (!anexoInput || !anexoInput.files[0]) {
        customAlert('Selecione um arquivo primeiro', '⚠️ Atenção');
        return;
    }
    
    if (!cardId) {
        customAlert('Erro: ID do card não encontrado', '❌ Erro');
        return;
    }
    
    const formData = new FormData();
    formData.append('arquivo', anexoInput.files[0]);
    
    try {
        const response = await fetch(`${API_BASE}?action=anexo&id=${cardId}`, {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            anexoInput.value = '';
            mostrarToast('✅ Anexo adicionado!');
            // Recarregar o card para mostrar o anexo
            await verCard(cardId);
            // Reabrir modal de edição
            setTimeout(() => editarCard(cardId), 500);
        } else {
            customAlert('Erro: ' + (data.error || 'Erro desconhecido'), '❌ Erro');
        }
    } catch (error) {
        console.error('Erro ao adicionar anexo:', error);
        customAlert('Erro ao adicionar anexo', '❌ Erro');
    }
}

// ============================================
// MODAIS E UTILITÁRIOS
// ============================================

function abrirModalNovoQuadro() {
    document.getElementById('modal-novo-quadro').style.display = 'block';
}

function abrirModalNovaLista() {
    if (!currentBoardId) {
        customAlert('Selecione um quadro primeiro', '⚠️ Atenção');
        return;
    }
    document.getElementById('modal-nova-lista').style.display = 'block';
}

function abrirModalNovoCard(listaIdPredefinida = null) {
    if (!currentBoardId) {
        customAlert('Selecione um quadro primeiro', '⚠️ Atenção');
        return;
    }
    
    // Preencher select de listas
    const select = document.getElementById('card-lista');
    select.innerHTML = lists.map(l => 
        `<option value="${l.id}" ${listaIdPredefinida === l.id ? 'selected' : ''}>${l.nome}</option>`
    ).join('');
    
    document.getElementById('modal-novo-card').style.display = 'block';
}

function abrirModalDemandasFixas() {
    window.open('index.php?page=demandas_fixas', '_blank');
}

function fecharModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

// ============================================
// SISTEMA DE ALERTAS CUSTOMIZADOS
// ============================================

function customAlert(mensagem, titulo = 'Aviso') {
    return new Promise((resolve) => {
        const overlay = document.createElement('div');
        overlay.className = 'custom-alert-overlay';
        overlay.innerHTML = `
            <div class="custom-alert">
                <div class="custom-alert-header">${titulo}</div>
                <div class="custom-alert-body">${mensagem}</div>
                <div class="custom-alert-actions">
                    <button class="custom-alert-btn custom-alert-btn-primary" onclick="this.closest('.custom-alert-overlay').remove(); resolveCustomAlert()">OK</button>
                </div>
            </div>
        `;
        
        document.body.appendChild(overlay);
        
        // Fechar ao clicar fora
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

function customConfirm(mensagem, titulo = 'Confirmar') {
    return new Promise((resolve) => {
        const overlay = document.createElement('div');
        overlay.className = 'custom-alert-overlay';
        overlay.innerHTML = `
            <div class="custom-alert">
                <div class="custom-alert-header">${titulo}</div>
                <div class="custom-alert-body">${mensagem}</div>
                <div class="custom-alert-actions">
                    <button class="custom-alert-btn custom-alert-btn-secondary" onclick="resolveCustomConfirm(false)">Cancelar</button>
                    <button class="custom-alert-btn custom-alert-btn-primary" onclick="resolveCustomConfirm(true)">Confirmar</button>
                </div>
            </div>
        `;
        
        document.body.appendChild(overlay);
        
        // Fechar ao clicar fora (resolve como false)
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

function mostrarToast(mensagem) {
    // Toast simples
    const toast = document.createElement('div');
    toast.style.cssText = 'position: fixed; bottom: 20px; right: 20px; background: #10b981; color: white; padding: 1rem 1.5rem; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); z-index: 10000;';
    toast.textContent = mensagem;
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transition = 'opacity 0.3s';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// Fechar modais ao clicar fora
window.onclick = function(event) {
    const modals = ['modal-novo-card', 'modal-ver-card', 'modal-editar-card', 'modal-novo-quadro', 'modal-nova-lista'];
    modals.forEach(id => {
        const modal = document.getElementById(id);
        if (event.target === modal) {
            fecharModal(id);
        }
    });
    
    // Fechar dropdown de notificações
    const dropdown = document.getElementById('notificacoes-dropdown');
    if (!event.target.closest('.notificacoes-badge') && !event.target.closest('#notificacoes-dropdown')) {
        dropdown.style.display = 'none';
    }
}
</script>

<?php endSidebar(); ?>

