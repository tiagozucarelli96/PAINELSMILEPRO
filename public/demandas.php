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
    const container = document.getElementById('demandas-container');
    if (container) {
        container.innerHTML = `
            <div class="empty-state">
                <div class="empty-state-icon">⏳</div>
                <h3>Carregando demandas...</h3>
            </div>
        `;
    }
    carregarDemandas();
});

function carregarDemandas() {
    // Filtrar apenas valores não vazios
    const filtrosLimpos = {};
    Object.keys(filtros).forEach(key => {
        if (filtros[key] && filtros[key] !== '') {
            filtrosLimpos[key] = filtros[key];
        }
    });
    
    const params = new URLSearchParams(filtrosLimpos);
    // Usar caminho absoluto para evitar problemas de roteamento
    const baseUrl = window.location.pathname.replace(/\/[^/]*$/, '/') || '/';
    const url = `${baseUrl}demandas_api.php${params.toString() ? '?' + params.toString() : ''}`;
    console.log('Carregando demandas de:', url);
    console.log('URL completa:', window.location.origin + url);
    
    fetch(url, {
        method: 'GET',
        headers: {
            'Accept': 'application/json'
        },
        credentials: 'same-origin',
        cache: 'no-cache'
    })
    .then(response => {
        console.log('Response status:', response.status);
        console.log('Response headers:', response.headers);
        
        // Tentar parsear JSON mesmo se status não for ok (pode ser 405 mas com dados válidos)
        return response.text().then(text => {
            try {
                const data = JSON.parse(text);
                console.log('Dados parseados:', data);
                
                // Se tem success:true e data, tratar como sucesso mesmo com status 405
                if (data.success && data.data !== undefined) {
                    console.log('✅ Resposta válida encontrada, ignorando status HTTP');
                    return data;
                }
                
                // Se não for ok e não tiver dados válidos, lançar erro
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}, body: ${text.substring(0, 200)}`);
                }
                
                return data;
            } catch (e) {
                // Se não conseguir parsear JSON e status não for ok, lançar erro
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}, body: ${text.substring(0, 200)}`);
                }
                // Se status for ok mas não conseguir parsear, tentar novamente como JSON
                return response.json();
            }
        });
    })
    .then(data => {
        console.log('Dados recebidos da API:', data);
        if (data.success && data.data) {
            demandas = Array.isArray(data.data) ? data.data : [];
            console.log('Demandas carregadas:', demandas.length);
            renderizarDemandas();
        } else {
            console.error('Erro ao carregar demandas:', data.error || 'Resposta inválida');
            console.error('Resposta completa:', data);
            demandas = [];
            renderizarDemandas();
        }
    })
    .catch(error => {
        console.error('Erro ao carregar demandas:', error);
        console.error('Stack trace:', error.stack);
        demandas = [];
        const container = document.getElementById('demandas-container');
        if (container) {
            container.innerHTML = `
                <div class="empty-state">
                    <div class="empty-state-icon">⚠️</div>
                    <h3>Erro ao carregar demandas</h3>
                    <p>Erro: ${error.message}</p>
                    <button class="btn btn-primary" onclick="carregarDemandas()">🔄 Tentar Novamente</button>
                </div>
            `;
        }
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
    // Limpar campos do formulário
    const statusField = document.getElementById('filtro-status');
    const responsavelField = document.getElementById('filtro-responsavel');
    const textoField = document.getElementById('filtro-texto');
    const dataField = document.getElementById('filtro-data');
    
    if (statusField) statusField.value = '';
    if (responsavelField) responsavelField.value = '';
    if (textoField) textoField.value = '';
    if (dataField) dataField.value = '';
    
    // Limpar filtros
    filtros = {};
    
    // Recarregar demandas
    carregarDemandas();
    
    console.log('✅ Filtros limpos');
}

function verDetalhes(id) {
    console.log('Carregando detalhes da demanda:', id);
    
    // NOVA ABORDAGEM: Usar query parameters
    fetch(`demandas_api.php?action=detalhes&id=${id}`, {
        method: 'GET',
        headers: {
            'Accept': 'application/json'
        },
        credentials: 'same-origin',
        cache: 'no-cache'
    })
    .then(response => {
        console.log('Response status:', response.status);
        return response.text().then(text => {
            try {
                const data = JSON.parse(text);
                // Se tem success:true, tratar como sucesso mesmo com status diferente de 200
                if (data.success) {
                    return data;
                }
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}, body: ${text.substring(0, 200)}`);
                }
                return data;
            } catch (e) {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}, body: ${text.substring(0, 200)}`);
                }
                return JSON.parse(text);
            }
        });
    })
    .then(data => {
        console.log('Dados recebidos:', data);
        if (data.success) {
            const demanda = data.data;
            
            const modalTitulo = document.getElementById('modal-titulo');
            const modalConteudo = document.getElementById('modal-conteudo');
            const modal = document.getElementById('detailModal');
            
            if (!modalTitulo || !modalConteudo || !modal) {
                console.error('Elementos do modal não encontrados');
                alert('Erro ao abrir modal de detalhes');
                return;
            }
            
            modalTitulo.textContent = `Demanda #${demanda.id}`;
            modalConteudo.innerHTML = `
                <div>
                    <p><strong>Descrição:</strong></p>
                    <p style="margin-bottom: 1rem; line-height: 1.5;">${demanda.descricao || 'Sem descrição'}</p>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                        <div><strong>Prazo:</strong> ${new Date(demanda.prazo).toLocaleDateString('pt-BR')}</div>
                        <div><strong>Status:</strong> <span class="badge badge-${demanda.status_real || demanda.status || 'pendente'}">${demanda.status_real || demanda.status || 'pendente'}</span></div>
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
                                    <span class="comentario-autor">${comentario.autor_nome || 'Anônimo'}</span>
                                    <span class="comentario-data">${new Date(comentario.data_criacao).toLocaleString('pt-BR')}</span>
                                </div>
                                <div class="comentario-texto">${comentario.mensagem}</div>
                            </div>
                        `).join('')}
                    </div>
                ` : '<div class="comentarios-section"><p>Nenhum comentário ainda.</p></div>'}
                
                ${demanda.anexos && demanda.anexos.length > 0 ? `
                    <div class="anexos-section">
                        <h3>Anexos</h3>
                        ${demanda.anexos.map(anexo => `
                            <div class="anexo-item">
                                <span class="anexo-icon">${anexo.mime_type && anexo.mime_type.startsWith('image/') ? '🖼️' : '📄'}</span>
                                <span>${anexo.nome_original}</span>
                                <button class="btn btn-outline" onclick="downloadAnexo(${anexo.id})">Download</button>
                            </div>
                        `).join('')}
                    </div>
                ` : '<div class="anexos-section"><p>Nenhum anexo.</p></div>'}
            `;
            
            modal.style.display = 'block';
        } else {
            alert('Erro ao carregar detalhes: ' + (data.error || 'Erro desconhecido'));
        }
    })
    .catch(error => {
        console.error('Erro ao carregar detalhes:', error);
        alert('Erro ao carregar detalhes da demanda. Verifique o console para mais informações.');
    });
}

function concluirDemanda(id) {
    if (!confirm('Deseja realmente concluir esta demanda?')) {
        return;
    }
    
    console.log('Concluindo demanda:', id);
    
    fetch(`demandas_api.php?action=concluir&id=${id}`, {
        method: 'POST',
        headers: {
            'Accept': 'application/json'
        },
        credentials: 'same-origin',
        cache: 'no-cache'
    })
    .then(response => {
        console.log('Response status:', response.status);
        return response.text().then(text => {
            try {
                const data = JSON.parse(text);
                // Se tem success:true, tratar como sucesso mesmo com status diferente de 200
                if (data.success) {
                    return data;
                }
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}, body: ${text.substring(0, 200)}`);
                }
                return data;
            } catch (e) {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}, body: ${text.substring(0, 200)}`);
                }
                return JSON.parse(text);
            }
        });
    })
    .then(data => {
        console.log('Response data:', data);
        if (data.success) {
            carregarDemandas();
            alert('✅ Demanda concluída com sucesso!');
        } else {
            alert('❌ Erro ao concluir demanda: ' + (data.error || 'Erro desconhecido'));
        }
    })
    .catch(error => {
        console.error('Erro ao concluir demanda:', error);
        alert('❌ Erro ao conectar com o servidor. Verifique o console para mais detalhes.');
    });
}

function reabrirDemanda(id) {
    if (!confirm('Deseja realmente reabrir esta demanda?')) {
        return;
    }
    
    console.log('Reabrindo demanda:', id);
    
    fetch(`demandas_api.php?action=reabrir&id=${id}`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        }
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            carregarDemandas();
            alert('✅ Demanda reaberta com sucesso!');
        } else {
            alert('❌ Erro ao reabrir demanda: ' + (data.error || 'Erro desconhecido'));
        }
    })
    .catch(error => {
        console.error('Erro ao reabrir demanda:', error);
        alert('❌ Erro ao conectar com o servidor. Verifique o console para mais detalhes.');
    });
}

function downloadAnexo(id) {
    fetch(`demandas_api.php?action=anexo&id=${id}`)
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
    const data = {
        descricao: formData.get('descricao'),
        prazo: formData.get('prazo'),
        responsavel_id: parseInt(formData.get('responsavel_id')),
        whatsapp: formData.get('whatsapp') || ''
    };
    
    console.log('Dados enviados:', data);
    
    // Validação client-side
    if (!data.descricao || !data.prazo || !data.responsavel_id) {
        alert('Por favor, preencha todos os campos obrigatórios (Descrição, Prazo e Responsável)');
        return;
    }
    
    fetch('demandas_api.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(data)
    })
    .then(response => {
        console.log('Response status:', response.status);
        return response.json();
    })
    .then(data => {
        console.log('Response data:', data);
        if (data.success) {
            closeCreateModal();
            carregarDemandas();
            alert('✅ Demanda criada com sucesso!');
        } else {
            const errorMsg = data.error || 'Erro desconhecido';
            const debugInfo = data.debug ? '\nDebug: ' + JSON.stringify(data.debug) : '';
            alert('❌ Erro ao criar demanda: ' + errorMsg + debugInfo);
        }
    })
    .catch(error => {
        console.error('Erro na requisição:', error);
        alert('❌ Erro ao conectar com o servidor. Verifique o console para mais detalhes.');
    });
});
    </script>

<?php endSidebar(); ?>
