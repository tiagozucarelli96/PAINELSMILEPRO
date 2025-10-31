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
    
    .badge-prioridade {
        font-size: 0.75rem;
        padding: 0.25rem 0.5rem;
        border-radius: 4px;
        color: white;
        font-weight: 600;
        display: inline-block;
    }
    
    .badge-media {
        background: #3b82f6;
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
            
            <div class="form-group">
                <label for="filtro-prioridade">Prioridade</label>
                <select id="filtro-prioridade">
                    <option value="">Todas</option>
                    <option value="baixa">Baixa</option>
                    <option value="media">Média</option>
                    <option value="alta">Alta</option>
                    <option value="urgente">Urgente</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="filtro-categoria">Categoria</label>
                <input type="text" id="filtro-categoria" placeholder="Buscar categoria...">
            </div>
            
            <div class="form-group">
                <label for="filtro-sort">Ordenar por</label>
                <select id="filtro-sort">
                    <option value="prazo">Prazo</option>
                    <option value="data_criacao">Data de Criação</option>
                    <option value="prioridade">Prioridade</option>
                    <option value="progresso">Progresso</option>
                    <option value="status">Status</option>
                </select>
                                </div>
            
            <div class="form-group">
                <label for="filtro-order">Ordem</label>
                <select id="filtro-order">
                    <option value="ASC">Crescente</option>
                    <option value="DESC">Decrescente</option>
                </select>
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
        
        <form id="createForm" enctype="multipart/form-data">
            <div class="form-group">
                <label for="descricao">Descrição *</label>
                <textarea id="descricao" name="descricao" rows="4" required></textarea>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
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
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group">
                    <label for="prioridade">Prioridade</label>
                    <select id="prioridade" name="prioridade">
                        <option value="media">Média</option>
                        <option value="baixa">Baixa</option>
                        <option value="alta">Alta</option>
                        <option value="urgente">Urgente</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="categoria">Categoria</label>
                    <input type="text" id="categoria" name="categoria" placeholder="Ex: Marketing, Vendas...">
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group">
                    <label for="progresso">Progresso: <span id="progresso-valor">0</span>%</label>
                    <input type="range" id="progresso" name="progresso" min="0" max="100" value="0" oninput="document.getElementById('progresso-valor').textContent = this.value">
                </div>
                
                <div class="form-group">
                    <label for="etapa">Etapa</label>
                    <select id="etapa" name="etapa">
                        <option value="planejamento">Planejamento</option>
                        <option value="execucao">Execução</option>
                        <option value="revisao">Revisão</option>
                        <option value="concluida">Concluída</option>
                    </select>
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group">
                    <label for="whatsapp">WhatsApp (opcional)</label>
                    <input type="text" id="whatsapp" name="whatsapp" placeholder="(11) 99999-9999">
                </div>
                
                <div class="form-group">
                    <label for="tipo_referencia">Módulo de Referência</label>
                    <select id="tipo_referencia" name="tipo_referencia">
                        <option value="">Nenhum</option>
                        <option value="comercial">Comercial</option>
                        <option value="logistico">Logístico</option>
                        <option value="financeiro">Financeiro</option>
                        <option value="rh">RH</option>
                        <option value="outro">Outro</option>
                    </select>
                </div>
            </div>
            
            <div class="form-group">
                <label for="referencia_externa">Referência Externa (ID do módulo)</label>
                <input type="text" id="referencia_externa" name="referencia_externa" placeholder="Ex: ID do pedido, operação...">
            </div>
            
            <div class="form-group">
                <label for="anexos">Anexos (opcional)</label>
                <input type="file" id="anexos" name="anexos[]" multiple accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                <small style="color: #6b7280; font-size: 0.875rem;">Você pode selecionar múltiplos arquivos</small>
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
    
    const getPrioridadeBadge = (prioridade) => {
        if (!prioridade || prioridade === 'media') return '<span class="badge badge-prioridade badge-media">Média</span>';
        const cores = {
            'baixa': '#10b981',
            'media': '#3b82f6',
            'alta': '#f59e0b',
            'urgente': '#ef4444'
        };
        const labels = {
            'baixa': 'Baixa',
            'media': 'Média',
            'alta': 'Alta',
            'urgente': 'Urgente'
        };
        return `<span class="badge badge-prioridade" style="background: ${cores[prioridade] || cores.media}">${labels[prioridade] || 'Média'}</span>`;
    };
    
    const getProgressoBar = (progresso) => {
        const valor = progresso || 0;
        return `
            <div style="margin: 0.5rem 0;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 0.25rem;">
                    <span style="font-size: 0.75rem; color: #6b7280;">Progresso</span>
                    <span style="font-size: 0.75rem; color: #6b7280; font-weight: 600;">${valor}%</span>
                </div>
                <div style="background: #e5e7eb; border-radius: 4px; height: 8px; overflow: hidden;">
                    <div style="background: #3b82f6; height: 100%; width: ${valor}%; transition: width 0.3s;"></div>
                </div>
            </div>
        `;
    };
    
    container.innerHTML = `
        <div class="demandas-grid">
            ${demandas.map(demanda => `
                <div class="demanda-card ${demanda.status_real}">
                    <div class="demanda-header">
                        <div style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
                            <span class="badge badge-${demanda.status_real}">${demanda.status_real}</span>
                            ${getPrioridadeBadge(demanda.prioridade)}
                            ${demanda.categoria ? `<span style="font-size: 0.75rem; padding: 0.25rem 0.5rem; background: #f3f4f6; border-radius: 4px; color: #6b7280;">${demanda.categoria}</span>` : ''}
                        </div>
                        <span style="font-size: 0.875rem; color: #6b7280;">
                            ${new Date(demanda.prazo).toLocaleDateString('pt-BR')}
                        </span>
                    </div>
                    
                    ${demanda.progresso !== undefined && demanda.progresso !== null ? getProgressoBar(demanda.progresso) : ''}
                    ${demanda.etapa && demanda.etapa !== 'planejamento' ? `<div style="font-size: 0.75rem; color: #6b7280; margin-bottom: 0.5rem;">📌 Etapa: ${demanda.etapa}</div>` : ''}
                    
                    <div class="demanda-descricao">
                        ${demanda.descricao.length > 100 ? 
                            demanda.descricao.substring(0, 100) + '...' : 
                            demanda.descricao}
                    </div>
                    
                    <div class="demanda-meta">
                        <span>👤 ${demanda.responsavel_nome || 'Sem responsável'}</span>
                        <span>📅 Criado em ${new Date(demanda.data_criacao).toLocaleDateString('pt-BR')}</span>
                        ${demanda.whatsapp ? `<span>📱 ${demanda.whatsapp}</span>` : ''}
                        ${demanda.referencia_externa && demanda.tipo_referencia ? `<span>🔗 ${demanda.tipo_referencia}: ${demanda.referencia_externa}</span>` : ''}
                    </div>
                    
                    <div class="demanda-actions">
                        <button class="btn btn-outline" onclick="verDetalhes(${demanda.id})">👁️ Ver</button>
                        <button class="btn btn-outline" onclick="editarDemanda(${demanda.id})">✏️ Editar</button>
                        ${demanda.status_real === 'concluida' ? 
                            `<button class="btn btn-warning" onclick="reabrirDemanda(${demanda.id})">🔄 Reabrir</button>` :
                            `<button class="btn btn-success" onclick="concluirDemanda(${demanda.id})">✅ Concluir</button>`
                        }
                        <button class="btn btn-outline" onclick="arquivarDemanda(${demanda.id})" style="color: #6b7280;">📦 Arquivar</button>
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
        ate_data: document.getElementById('filtro-data').value,
        prioridade: document.getElementById('filtro-prioridade').value,
        categoria: document.getElementById('filtro-categoria').value,
        sort_by: document.getElementById('filtro-sort').value,
        order: document.getElementById('filtro-order').value
    };
    
    carregarDemandas();
}

function limparFiltros() {
    // Limpar todos os campos do formulário
    const fields = ['filtro-status', 'filtro-responsavel', 'filtro-texto', 'filtro-data', 
                    'filtro-prioridade', 'filtro-categoria', 'filtro-sort', 'filtro-order'];
    
    fields.forEach(fieldId => {
        const field = document.getElementById(fieldId);
        if (field) {
            if (field.tagName === 'SELECT') {
                field.selectedIndex = 0;
            } else {
                field.value = '';
            }
        }
    });
    
    filtros = {};
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
            
            const getPrioridadeBadge = (prioridade) => {
                if (!prioridade || prioridade === 'media') return '<span class="badge badge-prioridade badge-media">Média</span>';
                const cores = {'baixa': '#10b981', 'media': '#3b82f6', 'alta': '#f59e0b', 'urgente': '#ef4444'};
                const labels = {'baixa': 'Baixa', 'media': 'Média', 'alta': 'Alta', 'urgente': 'Urgente'};
                return `<span class="badge badge-prioridade" style="background: ${cores[prioridade] || cores.media}">${labels[prioridade] || 'Média'}</span>`;
            };
            
            const getProgressoBar = (progresso) => {
                const valor = progresso || 0;
                return `<div style="background: #e5e7eb; border-radius: 4px; height: 12px; overflow: hidden; margin: 0.5rem 0;"><div style="background: #3b82f6; height: 100%; width: ${valor}%;"></div></div><div style="text-align: center; font-size: 0.875rem; color: #6b7280;">${valor}%</div>`;
            };
            
            modalTitulo.textContent = `Demanda #${demanda.id}`;
            modalConteudo.innerHTML = `
                <div style="margin-bottom: 2rem;">
                    <p><strong>Descrição:</strong></p>
                    <p style="margin-bottom: 1rem; line-height: 1.5; padding: 1rem; background: #f9fafb; border-radius: 6px;">${demanda.descricao || 'Sem descrição'}</p>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                        <div><strong>Prazo:</strong> ${new Date(demanda.prazo).toLocaleDateString('pt-BR')}</div>
                        <div><strong>Status:</strong> <span class="badge badge-${demanda.status_real || demanda.status || 'pendente'}">${demanda.status_real || demanda.status || 'pendente'}</span></div>
                        <div><strong>Responsável:</strong> ${demanda.responsavel_nome || 'Sem responsável'}</div>
                        <div><strong>Criado por:</strong> ${demanda.criador_nome || 'Sistema'}</div>
                        ${demanda.prioridade ? `<div><strong>Prioridade:</strong> ${getPrioridadeBadge(demanda.prioridade)}</div>` : ''}
                        ${demanda.categoria ? `<div><strong>Categoria:</strong> ${demanda.categoria}</div>` : ''}
                        ${demanda.etapa ? `<div><strong>Etapa:</strong> ${demanda.etapa}</div>` : ''}
                        ${demanda.referencia_externa && demanda.tipo_referencia ? `<div><strong>Referência:</strong> ${demanda.tipo_referencia} - ${demanda.referencia_externa}</div>` : ''}
                    </div>
                    
                    ${demanda.progresso !== undefined && demanda.progresso !== null ? `<div><strong>Progresso:</strong>${getProgressoBar(demanda.progresso)}</div>` : ''}
                    ${demanda.whatsapp ? `<p><strong>WhatsApp:</strong> ${demanda.whatsapp}</p>` : ''}
                </div>
                
                <hr style="margin: 2rem 0; border: none; border-top: 1px solid #e5e7eb;">
                
                <div class="comentarios-section" style="margin-bottom: 2rem;">
                    <h3 style="margin-bottom: 1rem;">💬 Comentários</h3>
                    <div id="comentarios-list">
                        ${demanda.comentarios && demanda.comentarios.length > 0 ? 
                            demanda.comentarios.map(comentario => `
                                <div class="comentario" style="background: #f9fafb; padding: 1rem; border-radius: 6px; margin-bottom: 0.5rem;">
                                    <div class="comentario-header" style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                                        <span class="comentario-autor" style="font-weight: 600;">${comentario.autor_nome || 'Anônimo'}</span>
                                        <span class="comentario-data" style="font-size: 0.875rem; color: #6b7280;">${new Date(comentario.data_criacao).toLocaleString('pt-BR')}</span>
                                    </div>
                                    <div class="comentario-texto">${comentario.mensagem}</div>
                                </div>
                            `).join('') : 
                            '<p style="color: #6b7280;">Nenhum comentário ainda.</p>'
                        }
                    </div>
                    <div style="margin-top: 1rem;">
                        <textarea id="novo-comentario" placeholder="Digite seu comentário..." rows="3" style="width: 100%; padding: 0.75rem; border: 1px solid #d1d5db; border-radius: 6px; font-family: inherit; resize: vertical;"></textarea>
                        <button class="btn btn-primary" onclick="adicionarComentario(${demanda.id})" style="margin-top: 0.5rem;">Adicionar Comentário</button>
                    </div>
                </div>
                
                <hr style="margin: 2rem 0; border: none; border-top: 1px solid #e5e7eb;">
                
                <div class="anexos-section">
                    <h3 style="margin-bottom: 1rem;">📎 Anexos</h3>
                    <div id="anexos-list">
                        ${demanda.anexos && demanda.anexos.length > 0 ? 
                            demanda.anexos.map(anexo => `
                                <div class="anexo-item" style="display: flex; align-items: center; gap: 1rem; padding: 0.75rem; background: #f9fafb; border-radius: 6px; margin-bottom: 0.5rem;">
                                    <span class="anexo-icon" style="font-size: 1.5rem;">${anexo.mime_type && anexo.mime_type.startsWith('image/') ? '🖼️' : '📄'}</span>
                                    <span style="flex: 1;">${anexo.nome_original}</span>
                                    <button class="btn btn-outline" onclick="downloadAnexo(${anexo.id})">Download</button>
                                    <button class="btn btn-outline" onclick="deletarAnexo(${anexo.id}, ${demanda.id})" style="color: #ef4444;">🗑️</button>
                                </div>
                            `).join('') : 
                            '<p style="color: #6b7280;">Nenhum anexo.</p>'
                        }
                    </div>
                    <div style="margin-top: 1rem;">
                        <input type="file" id="novo-anexo" multiple accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" style="margin-bottom: 0.5rem;">
                        <button class="btn btn-primary" onclick="adicionarAnexoModal(${demanda.id})">Adicionar Anexo</button>
                    </div>
                </div>
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

function editarDemanda(id) {
    const demanda = demandas.find(d => d.id === id);
    if (!demanda) {
        alert('Demanda não encontrada');
        return;
    }
    
    // Criar modal de edição (reutilizar estrutura do modal de criação)
    const editModal = document.createElement('div');
    editModal.id = 'editModal';
    editModal.className = 'modal';
    editModal.innerHTML = `
        <div class="modal-content">
            <div class="modal-header">
                <h2>Editar Demanda #${id}</h2>
                <span class="close" onclick="document.getElementById('editModal').remove()">&times;</span>
            </div>
            <form id="editForm">
                <div class="form-group">
                    <label for="edit-descricao">Descrição *</label>
                    <textarea id="edit-descricao" name="descricao" rows="4" required>${demanda.descricao || ''}</textarea>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div class="form-group">
                        <label for="edit-prazo">Prazo *</label>
                        <input type="date" id="edit-prazo" name="prazo" value="${demanda.prazo}" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit-responsavel_id">Responsável *</label>
                        <select id="edit-responsavel_id" name="responsavel_id" required>
                            <option value="">Selecione...</option>
                        </select>
                    </div>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div class="form-group">
                        <label for="edit-prioridade">Prioridade</label>
                        <select id="edit-prioridade" name="prioridade">
                            <option value="baixa" ${demanda.prioridade === 'baixa' ? 'selected' : ''}>Baixa</option>
                            <option value="media" ${(!demanda.prioridade || demanda.prioridade === 'media') ? 'selected' : ''}>Média</option>
                            <option value="alta" ${demanda.prioridade === 'alta' ? 'selected' : ''}>Alta</option>
                            <option value="urgente" ${demanda.prioridade === 'urgente' ? 'selected' : ''}>Urgente</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit-categoria">Categoria</label>
                        <input type="text" id="edit-categoria" name="categoria" value="${demanda.categoria || ''}" placeholder="Ex: Marketing, Vendas...">
                    </div>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div class="form-group">
                        <label for="edit-progresso">Progresso: <span id="edit-progresso-valor">${demanda.progresso || 0}</span>%</label>
                        <input type="range" id="edit-progresso" name="progresso" min="0" max="100" value="${demanda.progresso || 0}" oninput="document.getElementById('edit-progresso-valor').textContent = this.value">
                    </div>
                    
                    <div class="form-group">
                        <label for="edit-etapa">Etapa</label>
                        <select id="edit-etapa" name="etapa">
                            <option value="planejamento" ${(!demanda.etapa || demanda.etapa === 'planejamento') ? 'selected' : ''}>Planejamento</option>
                            <option value="execucao" ${demanda.etapa === 'execucao' ? 'selected' : ''}>Execução</option>
                            <option value="revisao" ${demanda.etapa === 'revisao' ? 'selected' : ''}>Revisão</option>
                            <option value="concluida" ${demanda.etapa === 'concluida' ? 'selected' : ''}>Concluída</option>
                        </select>
                    </div>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div class="form-group">
                        <label for="edit-whatsapp">WhatsApp</label>
                        <input type="text" id="edit-whatsapp" name="whatsapp" value="${demanda.whatsapp || ''}" placeholder="(11) 99999-9999">
                    </div>
                    
                    <div class="form-group">
                        <label for="edit-tipo_referencia">Módulo de Referência</label>
                        <select id="edit-tipo_referencia" name="tipo_referencia">
                            <option value="">Nenhum</option>
                            <option value="comercial" ${demanda.tipo_referencia === 'comercial' ? 'selected' : ''}>Comercial</option>
                            <option value="logistico" ${demanda.tipo_referencia === 'logistico' ? 'selected' : ''}>Logístico</option>
                            <option value="financeiro" ${demanda.tipo_referencia === 'financeiro' ? 'selected' : ''}>Financeiro</option>
                            <option value="rh" ${demanda.tipo_referencia === 'rh' ? 'selected' : ''}>RH</option>
                            <option value="outro" ${demanda.tipo_referencia === 'outro' ? 'selected' : ''}>Outro</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="edit-referencia_externa">Referência Externa</label>
                    <input type="text" id="edit-referencia_externa" name="referencia_externa" value="${demanda.referencia_externa || ''}" placeholder="Ex: ID do pedido...">
                </div>
                
                <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                    <button type="button" class="btn btn-outline" onclick="document.getElementById('editModal').remove()">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Salvar Alterações</button>
                </div>
            </form>
        </div>
    `;
    
    document.body.appendChild(editModal);
    editModal.style.display = 'block';
    
    // Preencher select de responsáveis
    const responsavelSelect = document.getElementById('edit-responsavel_id');
    <?php foreach ($usuarios as $usuario): ?>
        const opt = document.createElement('option');
        opt.value = <?= $usuario['id'] ?>;
        opt.textContent = <?= json_encode($usuario['nome']) ?>;
        if (demanda.responsavel_id == <?= $usuario['id'] ?>) opt.selected = true;
        responsavelSelect.appendChild(opt);
    <?php endforeach; ?>
    
    // Submeter formulário
    document.getElementById('editForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        const data = {
            descricao: formData.get('descricao'),
            prazo: formData.get('prazo'),
            responsavel_id: parseInt(formData.get('responsavel_id')),
            whatsapp: formData.get('whatsapp') || null,
            prioridade: formData.get('prioridade') || 'media',
            categoria: formData.get('categoria') || null,
            progresso: parseInt(formData.get('progresso')) || 0,
            etapa: formData.get('etapa') || 'planejamento',
            referencia_externa: formData.get('referencia_externa') || null,
            tipo_referencia: formData.get('tipo_referencia') || null
        };
        
        fetch(`demandas_api.php?action=editar&id=${id}`, {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(data)
        })
        .then(response => {
            return response.text().then(text => {
                try {
                    const data = JSON.parse(text);
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
            if (data.success) {
                editModal.remove();
                carregarDemandas();
                alert('✅ Demanda atualizada com sucesso!');
            } else {
                alert('❌ Erro ao atualizar: ' + (data.error || 'Erro desconhecido'));
            }
        })
        .catch(error => {
            console.error('Erro ao atualizar:', error);
            alert('❌ Erro ao conectar com o servidor.');
        });
    });
    
    // Fechar ao clicar fora
    editModal.onclick = function(event) {
        if (event.target === editModal) {
            editModal.remove();
        }
    };
}

function arquivarDemanda(id) {
    if (!confirm('Deseja realmente arquivar esta demanda?')) {
        return;
    }
    
    fetch(`demandas_api.php?action=arquivar&id=${id}`, {
        method: 'DELETE',
        headers: {
            'Accept': 'application/json'
        },
        credentials: 'same-origin'
    })
    .then(response => {
        return response.text().then(text => {
            try {
                const data = JSON.parse(text);
                if (data.success) return data;
                if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                return data;
            } catch (e) {
                if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                return JSON.parse(text);
            }
        });
    })
    .then(data => {
        if (data.success) {
            carregarDemandas();
            alert('✅ Demanda arquivada com sucesso!');
        } else {
            alert('❌ Erro ao arquivar: ' + (data.error || 'Erro desconhecido'));
        }
    })
    .catch(error => {
        console.error('Erro ao arquivar:', error);
        alert('❌ Erro ao arquivar demanda.');
    });
}

function adicionarComentario(demandaId) {
    const mensagem = document.getElementById('novo-comentario').value.trim();
    if (!mensagem) {
        alert('Por favor, digite um comentário');
        return;
    }
    
    fetch(`demandas_api.php?action=comentario&id=${demandaId}`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ mensagem })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            document.getElementById('novo-comentario').value = '';
            verDetalhes(demandaId); // Recarregar modal
        } else {
            alert('Erro ao adicionar comentário: ' + (data.error || 'Erro desconhecido'));
        }
    })
    .catch(error => {
        console.error('Erro ao adicionar comentário:', error);
        alert('Erro ao adicionar comentário.');
    });
}

function adicionarAnexoModal(demandaId) {
    const input = document.getElementById('novo-anexo');
    const files = input.files;
    
    if (files.length === 0) {
        alert('Por favor, selecione pelo menos um arquivo');
        return;
    }
    
    Array.from(files).forEach(file => {
        const formData = new FormData();
        formData.append('arquivo', file);
        
        fetch(`demandas_api.php?action=anexo&id=${demandaId}`, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                input.value = '';
                verDetalhes(demandaId); // Recarregar modal
            } else {
                alert('Erro ao adicionar anexo: ' + (data.error || 'Erro desconhecido'));
            }
        })
        .catch(error => {
            console.error('Erro ao adicionar anexo:', error);
            alert('Erro ao adicionar anexo.');
        });
    });
}

function deletarAnexo(anexoId, demandaId) {
    if (!confirm('Deseja realmente excluir este anexo?')) {
        return;
    }
    
    fetch(`demandas_api.php?action=deletar_anexo&id=${anexoId}`, {
        method: 'DELETE',
        headers: {
            'Accept': 'application/json'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            verDetalhes(demandaId); // Recarregar modal
        } else {
            alert('Erro ao excluir anexo: ' + (data.error || 'Erro desconhecido'));
        }
    })
    .catch(error => {
        console.error('Erro ao excluir anexo:', error);
        alert('Erro ao excluir anexo.');
    });
}

function downloadAnexo(id) {
    fetch(`demandas_api.php?action=anexo&id=${id}`)
        .then(response => {
            return response.text().then(text => {
                try {
                    const data = JSON.parse(text);
                    if (data.success) {
                        return data;
                    }
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return data;
                } catch (e) {
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return JSON.parse(text);
                }
            });
        })
        .then(data => {
            if (data.success || data.url) {
                window.open(data.url || data, '_blank');
            } else {
                alert('Erro ao baixar arquivo: ' + (data.error || 'Erro desconhecido'));
            }
        })
        .catch(error => {
            console.error('Erro ao baixar anexo:', error);
            alert('Erro ao baixar arquivo.');
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
    
    // Validação client-side
    if (!formData.get('descricao') || !formData.get('prazo') || !formData.get('responsavel_id')) {
        alert('Por favor, preencha todos os campos obrigatórios (Descrição, Prazo e Responsável)');
        return;
    }
    
    // Criar objeto JSON com dados (anexos serão enviados separadamente se houver)
    const anexosFiles = formData.getAll('anexos[]');
    const hasAnexos = anexosFiles.length > 0 && anexosFiles[0].size > 0;
    
    let requestPromise;
    
    if (hasAnexos) {
        // Enviar FormData completo (com arquivos)
        requestPromise = fetch('demandas_api.php', {
            method: 'POST',
            body: formData
        });
    } else {
        // Enviar JSON (mais rápido)
        const data = {
            descricao: formData.get('descricao'),
            prazo: formData.get('prazo'),
            responsavel_id: parseInt(formData.get('responsavel_id')),
            whatsapp: formData.get('whatsapp') || '',
            prioridade: formData.get('prioridade') || 'media',
            categoria: formData.get('categoria') || null,
            progresso: parseInt(formData.get('progresso')) || 0,
            etapa: formData.get('etapa') || 'planejamento',
            referencia_externa: formData.get('referencia_externa') || null,
            tipo_referencia: formData.get('tipo_referencia') || null
        };
        
        requestPromise = fetch('demandas_api.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(data)
        });
    }
    
    requestPromise
    .then(response => {
        console.log('Response status:', response.status);
        return response.text().then(text => {
            try {
                const data = JSON.parse(text);
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
            closeCreateModal();
            carregarDemandas();
            alert('✅ Demanda criada com sucesso!');
            
            // Se houver anexos e demanda foi criada, enviar anexos
            if (hasAnexos && data.data && data.data.id) {
                enviarAnexos(data.data.id, anexosFiles);
            }
        } else {
            const errorMsg = data.error || 'Erro desconhecido';
            alert('❌ Erro ao criar demanda: ' + errorMsg);
        }
    })
    .catch(error => {
        console.error('Erro na requisição:', error);
        alert('❌ Erro ao conectar com o servidor. Verifique o console para mais detalhes.');
    });
});

function enviarAnexos(demandaId, arquivos) {
    Array.from(arquivos).forEach(arquivo => {
        if (arquivo.size === 0) return;
        
        const formDataAnexo = new FormData();
        formDataAnexo.append('arquivo', arquivo);
        
        fetch(`demandas_api.php?action=anexo&id=${demandaId}`, {
            method: 'POST',
            body: formDataAnexo
        })
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                console.error('Erro ao enviar anexo:', data.error);
            }
        })
        .catch(error => console.error('Erro ao enviar anexo:', error));
    });
        }
    </script>

<?php endSidebar(); ?>
