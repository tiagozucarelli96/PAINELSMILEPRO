<?php
// demandas.php - UI principal do sistema de demandas
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';

// Verificar se usuário está logado
if (!isset($_SESSION['logado']) || $_SESSION['logado'] != 1) {
    header('Location: login.php');
    exit;
}

$pdo = $GLOBALS['pdo'];
$usuario_id = $_SESSION['user_id'] ?? 1;

// Buscar usuários para filtros
$stmt = $pdo->prepare("SELECT id, nome FROM usuarios ORDER BY nome");
$stmt->execute();
$usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

includeSidebar('Demandas');
?>

<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    
    .page-container {
        padding: 2rem;
        max-width: 100%;
    }
    
    .header-actions {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 2rem;
    }
    
    .header-actions h1 {
        font-size: 2rem;
        color: #1f2937;
    }
    
    .filters {
        background: white;
        padding: 1.5rem;
        border-radius: 12px;
        margin-bottom: 2rem;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }
    
    .filters-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
        margin-bottom: 1rem;
    }
    
    .form-group {
        display: flex;
        flex-direction: column;
    }
    
    .form-group label {
        font-weight: 500;
        margin-bottom: 0.5rem;
        color: #374151;
    }
    
    .form-group input,
    .form-group select {
        padding: 0.75rem;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        font-size: 0.875rem;
    }
    
    .btn {
        padding: 0.75rem 1.5rem;
        border-radius: 6px;
        font-weight: 500;
        cursor: pointer;
        text-decoration: none;
        display: inline-block;
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
        border: 1px solid #3b82f6;
    }
    
    .btn-outline:hover {
        background: #f8fafc;
    }
    
    .btn-success {
        background: #10b981;
        color: white;
    }
    
    .btn-warning {
        background: #f59e0b;
        color: white;
    }
    
    .btn-danger {
        background: #ef4444;
        color: white;
    }
    
    .demandas-grid {
        display: grid;
        gap: 1rem;
    }
    
    .demanda-card {
        background: white;
        border-radius: 12px;
        padding: 1.5rem;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        transition: all 0.2s;
        border-left: 4px solid #3b82f6;
    }
    
    .demanda-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    }
    
    .demanda-card.vencida {
        border-left-color: #ef4444;
    }
    
    .demanda-card.concluida {
        border-left-color: #10b981;
        opacity: 0.7;
    }
    
    .demanda-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 1rem;
    }
    
    .demanda-descricao {
        font-size: 1rem;
        color: #1f2937;
        margin-bottom: 0.5rem;
        line-height: 1.5;
    }
    
    .demanda-meta {
        display: flex;
        gap: 1rem;
        font-size: 0.875rem;
        color: #6b7280;
        margin-bottom: 1rem;
    }
    
    .badge {
        padding: 0.25rem 0.75rem;
        border-radius: 12px;
        font-size: 0.75rem;
        font-weight: 500;
    }
    
    .badge-pendente {
        background: #fef3c7;
        color: #92400e;
    }
    
    .badge-vencida {
        background: #fee2e2;
        color: #991b1b;
    }
    
    .badge-concluida {
        background: #d1fae5;
        color: #065f46;
    }
    
    .demanda-actions {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
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
    }
    
    .modal-content {
        background: white;
        margin: 5% auto;
        padding: 2rem;
        border-radius: 12px;
        width: 90%;
        max-width: 600px;
        max-height: 80vh;
        overflow-y: auto;
    }
    
    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.5rem;
    }
    
    .close {
        font-size: 1.5rem;
        cursor: pointer;
        color: #6b7280;
    }
    
    .comentarios-section {
        margin-top: 2rem;
        padding-top: 1.5rem;
        border-top: 1px solid #e5e7eb;
    }
    
    .comentario {
        background: #f9fafb;
        padding: 1rem;
        border-radius: 8px;
        margin-bottom: 1rem;
    }
    
    .comentario-header {
        display: flex;
        justify-content: space-between;
        margin-bottom: 0.5rem;
    }
    
    .comentario-autor {
        font-weight: 500;
        color: #1f2937;
    }
    
    .comentario-data {
        font-size: 0.875rem;
        color: #6b7280;
    }
    
    .comentario-texto {
        color: #374151;
        line-height: 1.5;
    }
    
    .anexos-section {
        margin-top: 1.5rem;
        padding-top: 1.5rem;
        border-top: 1px solid #e5e7eb;
    }
    
    .anexo-item {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.5rem;
        background: #f9fafb;
        border-radius: 6px;
        margin-bottom: 0.5rem;
    }
    
    .anexo-icon {
        font-size: 1.25rem;
    }
    
    .empty-state {
        text-align: center;
        padding: 3rem;
        color: #6b7280;
    }
    
    .empty-state-icon {
        font-size: 3rem;
        margin-bottom: 1rem;
    }
</style>

<div class="page-container">
    <div class="header-actions">
        <h1>📋 Demandas</h1>
        <button class="btn btn-primary" onclick="openCreateModal()">➕ Nova Demanda</button>
    </div>
    
    <!-- Filtros -->
    <div class="filters">
        <div class="filters-row">
            <div class="form-group">
                <label for="filtro-status">Status</label>
                <select id="filtro-status">
                    <option value="">Todos</option>
                    <option value="pendente">Pendente</option>
                    <option value="vencida">Vencida</option>
                    <option value="concluida">Concluída</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="filtro-responsavel">Responsável</label>
                <select id="filtro-responsavel">
                    <option value="">Todos</option>
                    <?php foreach ($usuarios as $usuario): ?>
                        <option value="<?= $usuario['id'] ?>"><?= htmlspecialchars($usuario['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label for="filtro-texto">Buscar texto</label>
                <input type="text" id="filtro-texto" placeholder="Digite para buscar...">
            </div>
            
            <div class="form-group">
                <label for="filtro-data">Até data</label>
                <input type="date" id="filtro-data">
            </div>
        </div>
        
        <button class="btn btn-outline" onclick="aplicarFiltros()">🔍 Filtrar</button>
        <button class="btn btn-outline" onclick="limparFiltros()">🗑️ Limpar</button>
    </div>
    
    <!-- Lista de demandas -->
    <div id="demandas-container">
        <div class="empty-state">
            <div class="empty-state-icon">⏳</div>
            <div>Carregando demandas...</div>
        </div>
    </div>
</div>

<!-- Modal de detalhes -->
<div id="detailModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="modal-titulo">Detalhes da Demanda</h2>
            <span class="close" onclick="closeDetailModal()">&times;</span>
        </div>
        
        <div id="modal-conteudo">
            <!-- Conteúdo carregado via AJAX -->
        </div>
    </div>
</div>

<!-- Modal de criação -->
<div id="createModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Nova Demanda</h2>
            <span class="close" onclick="closeCreateModal()">&times;</span>
        </div>
        
        <form id="createForm">
            <div class="form-group">
                <label for="descricao">Descrição *</label>
                <textarea id="descricao" name="descricao" rows="4" required></textarea>
            </div>
            
            <div class="form-group">
                <label for="prazo">Prazo *</label>
                <input type="date" id="prazo" name="prazo" required>
            </div>
            
            <div class="form-group">
                <label for="responsavel_id">Responsável *</label>
                <select id="responsavel_id" name="responsavel_id" required>
                    <option value="">Selecione...</option>
                    <?php foreach ($usuarios as $usuario): ?>
                        <option value="<?= $usuario['id'] ?>"><?= htmlspecialchars($usuario['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label for="whatsapp">WhatsApp (opcional)</label>
                <input type="text" id="whatsapp" name="whatsapp" placeholder="(11) 99999-9999">
            </div>
            
            <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                <button type="button" class="btn btn-outline" onclick="closeCreateModal()">Cancelar</button>
                <button type="submit" class="btn btn-primary">Criar Demanda</button>
            </div>
        </form>
    </div>
</div>

<script>
let demandas = [];
let filtros = {};

// Carregar demandas ao inicializar
document.addEventListener('DOMContentLoaded', function() {
    carregarDemandas();
});

function carregarDemandas() {
    const params = new URLSearchParams(filtros);
    
    fetch(`demandas_api.php?${params}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                demandas = data.data;
                renderizarDemandas();
            } else {
                console.error('Erro ao carregar demandas:', data.error);
            }
        })
        .catch(error => {
            console.error('Erro:', error);
        });
}

function renderizarDemandas() {
    const container = document.getElementById('demandas-container');
    
    if (demandas.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <div class="empty-state-icon">📋</div>
                <h3>Nenhuma demanda encontrada</h3>
                <p>Não há demandas que correspondam aos filtros aplicados.</p>
            </div>
        `;
        return;
    }
    
    container.innerHTML = `
        <div class="demandas-grid">
            ${demandas.map(demanda => `
                <div class="demanda-card ${demanda.status_real}">
                    <div class="demanda-header">
                        <span class="badge badge-${demanda.status_real}">${demanda.status_real}</span>
                        <span style="font-size: 0.875rem; color: #6b7280;">
                            ${new Date(demanda.prazo).toLocaleDateString('pt-BR')}
                        </span>
                    </div>
                    
                    <div class="demanda-descricao">
                        ${demanda.descricao.length > 100 ? 
                            demanda.descricao.substring(0, 100) + '...' : 
                            demanda.descricao}
                    </div>
                    
                    <div class="demanda-meta">
                        <span>👤 ${demanda.responsavel_nome || 'Sem responsável'}</span>
                        <span>📅 Criado em ${new Date(demanda.data_criacao).toLocaleDateString('pt-BR')}</span>
                        ${demanda.whatsapp ? `<span>📱 ${demanda.whatsapp}</span>` : ''}
                    </div>
                    
                    <div class="demanda-actions">
                        <button class="btn btn-outline" onclick="verDetalhes(${demanda.id})">👁️ Ver</button>
                        ${demanda.status_real === 'concluida' ? 
                            `<button class="btn btn-warning" onclick="reabrirDemanda(${demanda.id})">🔄 Reabrir</button>` :
                            `<button class="btn btn-success" onclick="concluirDemanda(${demanda.id})">✅ Concluir</button>`
                        }
                    </div>
                </div>
            `).join('')}
        </div>
    `;
}

function aplicarFiltros() {
    filtros = {
        status: document.getElementById('filtro-status').value,
        responsavel: document.getElementById('filtro-responsavel').value,
        texto: document.getElementById('filtro-texto').value,
        ate_data: document.getElementById('filtro-data').value
    };
    
    carregarDemandas();
}

function limparFiltros() {
    document.getElementById('filtro-status').value = '';
    document.getElementById('filtro-responsavel').value = '';
    document.getElementById('filtro-texto').value = '';
    document.getElementById('filtro-data').value = '';
    
    filtros = {};
    carregarDemandas();
}

function verDetalhes(id) {
    fetch(`demandas_api.php/${id}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const demanda = data.data;
                
                document.getElementById('modal-titulo').textContent = `Demanda #${demanda.id}`;
                document.getElementById('modal-conteudo').innerHTML = `
                    <div>
                        <p><strong>Descrição:</strong></p>
                        <p style="margin-bottom: 1rem; line-height: 1.5;">${demanda.descricao}</p>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                            <div><strong>Prazo:</strong> ${new Date(demanda.prazo).toLocaleDateString('pt-BR')}</div>
                            <div><strong>Status:</strong> <span class="badge badge-${demanda.status_real}">${demanda.status_real}</span></div>
                            <div><strong>Responsável:</strong> ${demanda.responsavel_nome || 'Sem responsável'}</div>
                            <div><strong>Criado por:</strong> ${demanda.criador_nome || 'Sistema'}</div>
                        </div>
                        
                        ${demanda.whatsapp ? `<p><strong>WhatsApp:</strong> ${demanda.whatsapp}</p>` : ''}
                    </div>
                    
                    ${demanda.comentarios && demanda.comentarios.length > 0 ? `
                        <div class="comentarios-section">
                            <h3>Comentários</h3>
                            ${demanda.comentarios.map(comentario => `
                                <div class="comentario">
                                    <div class="comentario-header">
                                        <span class="comentario-autor">${comentario.autor_nome}</span>
                                        <span class="comentario-data">${new Date(comentario.data_criacao).toLocaleString('pt-BR')}</span>
                                    </div>
                                    <div class="comentario-texto">${comentario.mensagem}</div>
                                </div>
                            `).join('')}
                        </div>
                    ` : ''}
                    
                    ${demanda.anexos && demanda.anexos.length > 0 ? `
                        <div class="anexos-section">
                            <h3>Anexos</h3>
                            ${demanda.anexos.map(anexo => `
                                <div class="anexo-item">
                                    <span class="anexo-icon">${anexo.mime_type.startsWith('image/') ? '🖼️' : '📄'}</span>
                                    <span>${anexo.nome_original}</span>
                                    <button class="btn btn-outline" onclick="downloadAnexo(${anexo.id})">Download</button>
                                </div>
                            `).join('')}
                        </div>
                    ` : ''}
                `;
                
                document.getElementById('detailModal').style.display = 'block';
            }
        })
        .catch(error => {
            console.error('Erro ao carregar detalhes:', error);
        });
}

function concluirDemanda(id) {
    fetch(`demandas_api.php/${id}/concluir`, { method: 'POST' })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                carregarDemandas();
                alert('Demanda concluída com sucesso!');
            } else {
                alert('Erro ao concluir demanda: ' + data.error);
            }
        });
}

function reabrirDemanda(id) {
    fetch(`demandas_api.php/${id}/reabrir`, { method: 'POST' })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                carregarDemandas();
                alert('Demanda reaberta com sucesso!');
            } else {
                alert('Erro ao reabrir demanda: ' + data.error);
            }
        });
}

function downloadAnexo(id) {
    fetch(`demandas_api.php/anexos/${id}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                window.open(data.url, '_blank');
            } else {
                alert('Erro ao baixar arquivo: ' + data.error);
            }
        });
}

function openCreateModal() {
    document.getElementById('createModal').style.display = 'block';
}

function closeCreateModal() {
    document.getElementById('createModal').style.display = 'none';
    document.getElementById('createForm').reset();
}

function closeDetailModal() {
    document.getElementById('detailModal').style.display = 'none';
}

// Fechar modais ao clicar fora
window.onclick = function(event) {
    const createModal = document.getElementById('createModal');
    const detailModal = document.getElementById('detailModal');
    
    if (event.target === createModal) {
        closeCreateModal();
    }
    if (event.target === detailModal) {
        closeDetailModal();
    }
}

// Formulário de criação
document.getElementById('createForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const data = Object.fromEntries(formData);
    
    fetch('demandas_api.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(data)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            closeCreateModal();
            carregarDemandas();
            alert('Demanda criada com sucesso!');
        } else {
            alert('Erro ao criar demanda: ' + data.error);
        }
    })
    .catch(error => {
        console.error('Erro:', error);
        alert('Erro ao criar demanda');
    });
});
</script>

<?php endSidebar(); ?>
