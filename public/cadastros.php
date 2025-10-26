<?php
// cadastros.php — Página do módulo Cadastros com cards internos
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$nomeUser = $_SESSION['nome'] ?? 'Usuário';
?>

<div class="page-container">
    <div class="page-header">
        <h1 class="page-title">📝 Cadastros</h1>
        <p class="page-subtitle">Gestão de cadastros básicos</p>
    </div>
    
    <div class="cards-grid">
        <!-- Usuários e Permissões -->
        <div class="card">
            <div class="card-header">
                <div class="card-icon">👥</div>
                <div class="card-title">Usuários e Permissões</div>
                <div class="card-subtitle">Gerenciar usuários e permissões</div>
            </div>
            <div class="card-content">
                <div class="card-item" onclick="loadSubPage('usuarios')">
                    <div class="item-icon">👤</div>
                    <div class="item-text">Usuários</div>
                    <div class="item-arrow">→</div>
                </div>
                <div class="card-item" onclick="loadSubPage('usuarios')">
                    <div class="item-icon">🔒</div>
                    <div class="item-text">Permissões</div>
                    <div class="item-arrow">→</div>
                </div>
                <div class="card-item" onclick="loadSubPage('usuarios')">
                    <div class="item-icon">👔</div>
                    <div class="item-text">Perfis</div>
                    <div class="item-arrow">→</div>
                </div>
            </div>
        </div>
        
        <!-- Cadastros Básicos -->
        <div class="card">
            <div class="card-header">
                <div class="card-icon">📋</div>
                <div class="card-title">Cadastros Básicos</div>
                <div class="card-subtitle">Categorias, insumos e unidades</div>
            </div>
            <div class="card-content">
                <div class="card-item" onclick="loadSubPage('config_categorias')">
                    <div class="item-icon">📁</div>
                    <div class="item-text">Categorias</div>
                    <div class="item-arrow">→</div>
                </div>
                <div class="card-item" onclick="loadSubPage('config_insumos')">
                    <div class="item-icon">📦</div>
                    <div class="item-text">Insumos</div>
                    <div class="item-arrow">→</div>
                </div>
                <div class="card-item" onclick="loadSubPage('config_insumos')">
                    <div class="item-icon">📏</div>
                    <div class="item-text">Unidades</div>
                    <div class="item-arrow">→</div>
                </div>
                <div class="card-item" onclick="loadSubPage('config_fornecedores')">
                    <div class="item-icon">🏢</div>
                    <div class="item-text">Fornecedores</div>
                    <div class="item-arrow">→</div>
                </div>
            </div>
        </div>
        
        <!-- Configurações do Sistema -->
        <div class="card">
            <div class="card-header">
                <div class="card-icon">⚙️</div>
                <div class="card-title">Configurações do Sistema</div>
                <div class="card-subtitle">Configurações gerais</div>
            </div>
            <div class="card-content">
                <div class="card-item" onclick="loadSubPage('configuracoes')">
                    <div class="item-icon">🔧</div>
                    <div class="item-text">Configurações</div>
                    <div class="item-arrow">→</div>
                </div>
                <div class="card-item" onclick="loadSubPage('verificacao_completa_erros')">
                    <div class="item-icon">🔍</div>
                    <div class="item-text">Diagnóstico</div>
                    <div class="item-arrow">→</div>
                </div>
                <div class="card-item" onclick="loadSubPage('configuracoes')">
                    <div class="item-icon">🔗</div>
                    <div class="item-text">Integrações</div>
                    <div class="item-arrow">→</div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.page-container {
    padding: 20px;
    max-width: 1400px;
    margin: 0 auto;
}

.page-header {
    text-align: center;
    margin-bottom: 40px;
}

.page-title {
    font-size: 2.5em;
    font-weight: 700;
    color: #1e3a8a;
    margin-bottom: 10px;
}

.page-subtitle {
    font-size: 1.2em;
    color: #64748b;
}

.cards-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
    gap: 30px;
}

.card {
    background: white;
    border-radius: 15px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
    overflow: hidden;
    transition: all 0.3s ease;
}

.card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
}

.card-header {
    background: linear-gradient(135deg, #1e3a8a, #1e40af);
    color: white;
    padding: 25px;
    text-align: center;
}

.card-icon {
    font-size: 2.5em;
    margin-bottom: 15px;
}

.card-title {
    font-size: 1.4em;
    font-weight: 600;
    margin-bottom: 8px;
}

.card-subtitle {
    font-size: 0.9em;
    opacity: 0.8;
}

.card-content {
    padding: 20px;
}

.card-item {
    display: flex;
    align-items: center;
    padding: 15px;
    margin-bottom: 10px;
    background: #f8fafc;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.3s ease;
}

.card-item:hover {
    background: #e2e8f0;
    transform: translateX(5px);
}

.card-item:last-child {
    margin-bottom: 0;
}

.item-icon {
    font-size: 1.5em;
    margin-right: 15px;
    width: 30px;
    text-align: center;
}

.item-text {
    flex: 1;
    font-weight: 500;
    color: #1e293b;
}

.item-arrow {
    color: #64748b;
    font-weight: bold;
    font-size: 1.2em;
}

@media (max-width: 768px) {
    .cards-grid {
        grid-template-columns: 1fr;
        gap: 20px;
    }
    
    .page-title {
        font-size: 2em;
    }
}
</style>

<script>
function loadSubPage(page) {
    // Fazer requisição para a sub-página
    fetch(`index.php?page=${page}`)
        .then(response => response.text())
        .then(html => {
            // Extrair apenas o conteúdo da página
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const content = doc.querySelector('#pageContent') || doc.body;
            
            if (content) {
                document.getElementById('pageContent').innerHTML = content.innerHTML;
            } else {
                document.getElementById('pageContent').innerHTML = html;
            }
        })
        .catch(error => {
            console.error('Erro ao carregar página:', error);
            document.getElementById('pageContent').innerHTML = '<div style="text-align: center; padding: 50px; color: #dc2626;"><div style="font-size: 24px; margin-bottom: 20px;">❌</div><div>Erro ao carregar página</div></div>';
        });
}
</script>
