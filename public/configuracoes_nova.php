<?php
// configuracoes.php — Página do módulo Configurações com cards internos
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$nomeUser = $_SESSION['nome'] ?? 'Usuário';
?>

<div class="page-container">
    <div class="page-header">
        <h1 class="page-title">⚙️ Configurações</h1>
        <p class="page-subtitle">Configurações do sistema</p>
    </div>
    
    <div class="cards-grid">
        <!-- Integrações -->
        <div class="card">
            <div class="card-header">
                <div class="card-icon">🔗</div>
                <div class="card-title">Integrações</div>
                <div class="card-subtitle">Configurar integrações externas</div>
            </div>
            <div class="card-content">
                <div class="card-item" onclick="window.location.href='index.php?page=configuracoes'">
                    <div class="item-icon">📧</div>
                    <div class="item-text">E-mail SMTP</div>
                    <div class="item-arrow">→</div>
                </div>
                <div class="card-item" onclick="window.location.href='index.php?page=webhook_me_eventos'">
                    <div class="item-icon">🔗</div>
                    <div class="item-text">ME Eventos</div>
                    <div class="item-arrow">→</div>
                </div>
                <div class="card-item" onclick="window.location.href='index.php?page=configuracoes'">
                    <div class="item-icon">💰</div>
                    <div class="item-text">ASAAS PIX</div>
                    <div class="item-arrow">→</div>
                </div>
            </div>
        </div>
        
        <!-- Diagnóstico & Manutenção -->
        <div class="card">
            <div class="card-header">
                <div class="card-icon">🔧</div>
                <div class="card-title">Diagnóstico & Manutenção</div>
                <div class="card-subtitle">Monitoramento do sistema</div>
            </div>
            <div class="card-content">
                <div class="card-item" onclick="window.location.href='index.php?page=verificacao_completa_erros'">
                    <div class="item-icon">🔍</div>
                    <div class="item-text">Verificação Completa</div>
                    <div class="item-arrow">→</div>
                </div>
                <div class="card-item" onclick="window.location.href='index.php?page=verificacao_completa_erros'">
                    <div class="item-icon">📊</div>
                    <div class="item-text">Logs do Sistema</div>
                    <div class="item-arrow">→</div>
                </div>
                <div class="card-item" onclick="window.location.href='index.php?page=verificacao_completa_erros'">
                    <div class="item-icon">🛠️</div>
                    <div class="item-text">Manutenção</div>
                    <div class="item-arrow">→</div>
                </div>
            </div>
        </div>
        
        <!-- Configurações Gerais -->
        <div class="card">
            <div class="card-header">
                <div class="card-icon">⚙️</div>
                <div class="card-title">Configurações Gerais</div>
                <div class="card-subtitle">Configurações básicas</div>
            </div>
            <div class="card-content">
                <div class="card-item" onclick="window.location.href='index.php?page=configuracoes'">
                    <div class="item-icon">🏢</div>
                    <div class="item-text">Empresa</div>
                    <div class="item-arrow">→</div>
                </div>
                <div class="card-item" onclick="window.location.href='index.php?page=configuracoes'">
                    <div class="item-icon">🎨</div>
                    <div class="item-text">Aparência</div>
                    <div class="item-arrow">→</div>
                </div>
                <div class="card-item" onclick="window.location.href='index.php?page=configuracoes'">
                    <div class="item-icon">🔒</div>
                    <div class="item-text">Segurança</div>
                    <div class="item-arrow">→</div>
                </div>
            </div>
        </div>
        
        <!-- Backup & Restauração -->
        <div class="card">
            <div class="card-header">
                <div class="card-icon">💾</div>
                <div class="card-title">Backup & Restauração</div>
                <div class="card-subtitle">Gestão de dados</div>
            </div>
            <div class="card-content">
                <div class="card-item" onclick="window.location.href='index.php?page=configuracoes'">
                    <div class="item-icon">💾</div>
                    <div class="item-text">Backup Manual</div>
                    <div class="item-arrow">→</div>
                </div>
                <div class="card-item" onclick="window.location.href='index.php?page=configuracoes'">
                    <div class="item-icon">🔄</div>
                    <div class="item-text">Restaurar</div>
                    <div class="item-arrow">→</div>
                </div>
                <div class="card-item" onclick="window.location.href='index.php?page=configuracoes'">
                    <div class="item-icon">📅</div>
                    <div class="item-text">Backup Automático</div>
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


