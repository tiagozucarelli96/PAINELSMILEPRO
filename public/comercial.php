<?php
// comercial.php — Página do módulo Comercial com cards internos
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$nomeUser = $_SESSION['nome'] ?? 'Usuário';
?>

<div class="page-container">
    <div class="page-header">
        <h1 class="page-title">📋 Comercial</h1>
        <p class="page-subtitle">Gestão comercial e vendas</p>
    </div>
    
    <div class="cards-grid">
        <!-- Contratos & Clientes -->
        <div class="card">
            <div class="card-header">
                <div class="card-icon">📋</div>
                <div class="card-title">Contratos & Clientes</div>
                <div class="card-subtitle">Gerenciar contratos e clientes</div>
            </div>
            <div class="card-content">
                <div class="card-item" onclick="window.location.href='index.php?page=comercial_degustacoes'">
                    <div class="item-icon">📄</div>
                    <div class="item-text">Degustações</div>
                    <div class="item-arrow">→</div>
                </div>
                <div class="card-item" onclick="window.location.href='index.php?page=comercial_clientes'">
                    <div class="item-icon">👥</div>
                    <div class="item-text">Clientes</div>
                    <div class="item-arrow">→</div>
                </div>
                <div class="card-item" onclick="window.location.href='index.php?page=comercial_degust_inscricoes'">
                    <div class="item-icon">📝</div>
                    <div class="item-text">Inscrições</div>
                    <div class="item-arrow">→</div>
                </div>
            </div>
        </div>

        <!-- Solicitar Pagamento -->
        <div class="card">
            <div class="card-header" style="background: linear-gradient(135deg, #16a34a, #15803d);">
                <div class="card-icon">💳</div>
                <div class="card-title">Solicitar pagamento</div>
                <div class="card-subtitle">Link PixGo sem cobrança antecipada</div>
            </div>
            <div class="card-content">
                <div class="card-item" onclick="window.location.href='index.php?page=comercial_solicitar_pagamento'">
                    <div class="item-icon">🔗</div>
                    <div class="item-text">Gerar link de pagamento</div>
                    <div class="item-arrow">→</div>
                </div>
            </div>
        </div>
        
        <!-- Comunicação & Propostas -->
        <div class="card">
            <div class="card-header">
                <div class="card-icon">💬</div>
                <div class="card-title">Comunicação & Propostas</div>
                <div class="card-subtitle">Gestão de comunicação</div>
            </div>
            <div class="card-content">
                <div class="card-item" onclick="window.location.href='index.php?page=comercial_degust_inscricoes'">
                    <div class="item-icon">📧</div>
                    <div class="item-text">E-mails</div>
                    <div class="item-arrow">→</div>
                </div>
                <div class="card-item" onclick="window.location.href='index.php?page=comercial_degustacoes'">
                    <div class="item-icon">📋</div>
                    <div class="item-text">Propostas</div>
                    <div class="item-arrow">→</div>
                </div>
                <div class="card-item" onclick="window.location.href='index.php?page=comercial_clientes'">
                    <div class="item-icon">📊</div>
                    <div class="item-text">Relatórios</div>
                    <div class="item-arrow">→</div>
                </div>
            </div>
        </div>
        
        <!-- Indicadores de Vendas -->
        <div class="card">
            <div class="card-header">
                <div class="card-icon">📊</div>
                <div class="card-title">Indicadores de Vendas</div>
                <div class="card-subtitle">Métricas e análises</div>
            </div>
            <div class="card-content">
                <div class="card-item" onclick="window.location.href='index.php?page=comercial_clientes'">
                    <div class="item-icon">📈</div>
                    <div class="item-text">Vendas</div>
                    <div class="item-arrow">→</div>
                </div>
                <div class="card-item" onclick="window.location.href='index.php?page=comercial_degustacoes'">
                    <div class="item-icon">🎯</div>
                    <div class="item-text">Conversões</div>
                    <div class="item-arrow">→</div>
                </div>
                <div class="card-item" onclick="window.location.href='index.php?page=comercial_degust_inscricoes'">
                    <div class="item-icon">📋</div>
                    <div class="item-text">Leads</div>
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
