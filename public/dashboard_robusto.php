<?php
// dashboard_robusto.php — Dashboard robusto com tratamento de erros
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';

// Inicializar variáveis com valores padrão
$stats = [
    'usuarios' => 0,
    'eventos' => 0,
    'fornecedores' => 0,
    'insumos' => 0,
    'contratos_fechados' => 0,
    'leads_total' => 0,
    'leads_negociacao' => 0,
    'vendas_realizadas' => 0
];
$usuarios_com_email = [];
$erros = [];

try {
    // Testar conexão
    if (!$pdo) {
        throw new Exception("Conexão com banco de dados falhou");
    }
    
    // Buscar usuários ativos
    try {
        $stats['usuarios'] = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE ativo = true")->fetchColumn();
    } catch (Exception $e) {
        $erros[] = "Erro ao buscar usuários: " . $e->getMessage();
    }
    
    // Buscar fornecedores ativos
    try {
        $stats['fornecedores'] = $pdo->query("SELECT COUNT(*) FROM fornecedores WHERE ativo = true")->fetchColumn();
    } catch (Exception $e) {
        $erros[] = "Erro ao buscar fornecedores: " . $e->getMessage();
    }
    
    // Buscar insumos
    try {
        $stats['insumos'] = $pdo->query("SELECT COUNT(*) FROM lc_insumos WHERE ativo = true")->fetchColumn();
    } catch (Exception $e) {
        $erros[] = "Erro ao buscar insumos: " . $e->getMessage();
    }
    
    // Buscar usuários com email
    try {
        $stmt = $pdo->query("SELECT nome, email FROM usuarios WHERE ativo = true AND email IS NOT NULL AND email != '' ORDER BY nome LIMIT 10");
        $usuarios_com_email = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $erros[] = "Erro ao buscar usuários com email: " . $e->getMessage();
    }
    
    // Buscar dados da ME Eventos
    try {
        $mes_atual = date('Y-m');
        $stmt = $pdo->prepare("SELECT eventos_ativos, contratos_fechados, leads_total, leads_negociacao, vendas_realizadas FROM me_eventos_stats WHERE mes_ano = ?");
        $stmt->execute([$mes_atual]);
        $me_stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($me_stats) {
            $stats['eventos'] = $me_stats['eventos_ativos'] ?? 0;
            $stats['contratos_fechados'] = $me_stats['contratos_fechados'] ?? 0;
            $stats['leads_total'] = $me_stats['leads_total'] ?? 0;
            $stats['leads_negociacao'] = $me_stats['leads_negociacao'] ?? 0;
            $stats['vendas_realizadas'] = $me_stats['vendas_realizadas'] ?? 0;
        }
    } catch (Exception $e) {
        $erros[] = "Erro ao buscar dados ME Eventos: " . $e->getMessage();
    }
    
} catch (Exception $e) {
    $erros[] = "Erro geral: " . $e->getMessage();
}

$nomeUser = $_SESSION['nome'] ?? 'Usuário';
?>

<div class="dashboard-container">
    <!-- Header -->
    <div class="dashboard-header">
        <h1 class="dashboard-title">🎉 Dashboard Principal</h1>
        <p class="dashboard-subtitle">Bem-vindo ao sistema Smile EVENTOS</p>
        
        <?php if (!empty($erros)): ?>
        <div style="background: #fef2f2; border: 1px solid #fecaca; color: #dc2626; padding: 15px; border-radius: 8px; margin-top: 20px;">
            <h4>⚠️ Avisos do Sistema:</h4>
            <ul style="margin: 10px 0; padding-left: 20px;">
                <?php foreach ($erros as $erro): ?>
                <li><?= htmlspecialchars($erro) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
    </div>

    <!-- Cards Principais -->
    <div class="dashboard-cards">
        <div class="dashboard-card">
            <div class="card-icon">👥</div>
            <div class="card-value"><?= $stats['usuarios'] ?></div>
            <div class="card-label">Usuários Ativos</div>
            <div class="card-source">Sistema Interno</div>
        </div>
        
        <div class="dashboard-card">
            <div class="card-icon">🎉</div>
            <div class="card-value"><?= $stats['eventos'] ?></div>
            <div class="card-label">Eventos Ativos</div>
            <div class="card-source">ME Eventos</div>
        </div>
        
        <div class="dashboard-card">
            <div class="card-icon">🏢</div>
            <div class="card-value"><?= $stats['fornecedores'] ?></div>
            <div class="card-label">Fornecedores Ativos</div>
            <div class="card-source">Sistema Interno</div>
        </div>
        
        <div class="dashboard-card">
            <div class="card-icon">📦</div>
            <div class="card-value"><?= $stats['insumos'] ?></div>
            <div class="card-label">Insumos Cadastrados</div>
            <div class="card-source">Sistema Interno</div>
        </div>
    </div>

    <!-- Resumo Comercial -->
    <div class="dashboard-card">
        <h3 style="color: #1e3a8a; margin-bottom: 20px; font-size: 1.5em;">📊 Resumo Comercial</h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
            <div style="text-align: center;">
                <div style="font-size: 2em; color: #1e3a8a; font-weight: bold;"><?= $stats['leads_total'] ?></div>
                <div style="color: #64748b;">Leads do Mês</div>
            </div>
            <div style="text-align: center;">
                <div style="font-size: 2em; color: #1e3a8a; font-weight: bold;"><?= $stats['leads_negociacao'] ?></div>
                <div style="color: #64748b;">Em Negociação</div>
            </div>
            <div style="text-align: center;">
                <div style="font-size: 2em; color: #1e3a8a; font-weight: bold;"><?= $stats['contratos_fechados'] ?></div>
                <div style="color: #64748b;">Contratos Fechados</div>
            </div>
            <div style="text-align: center;">
                <div style="font-size: 2em; color: #1e3a8a; font-weight: bold;"><?= $stats['vendas_realizadas'] ?></div>
                <div style="color: #64748b;">Vendas Realizadas</div>
            </div>
        </div>
    </div>

    <!-- Usuários com Email -->
    <?php if (!empty($usuarios_com_email)): ?>
    <div class="dashboard-card">
        <h3 style="color: #1e3a8a; margin-bottom: 20px; font-size: 1.5em;">📧 Usuários com Email</h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 15px;">
            <?php foreach ($usuarios_com_email as $usuario): ?>
            <div style="background: #f8fafc; padding: 15px; border-radius: 8px; border-left: 4px solid #1e3a8a;">
                <div style="font-weight: 600; color: #1e3a8a; margin-bottom: 5px;">
                    <?= htmlspecialchars($usuario['nome']) ?>
                </div>
                <div style="color: #64748b; font-size: 14px;">
                    📧 <?= htmlspecialchars($usuario['email']) ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Informações de Debug -->
    <div class="dashboard-card">
        <h3 style="color: #1e3a8a; margin-bottom: 20px; font-size: 1.5em;">🔧 Informações do Sistema</h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
            <div style="text-align: center;">
                <div style="font-size: 1.5em; color: #1e3a8a; font-weight: bold;"><?= date('Y-m') ?></div>
                <div style="color: #64748b;">Mês Atual</div>
            </div>
            <div style="text-align: center;">
                <div style="font-size: 1.5em; color: #1e3a8a; font-weight: bold;"><?= count($erros) ?></div>
                <div style="color: #64748b;">Avisos</div>
            </div>
            <div style="text-align: center;">
                <div style="font-size: 1.5em; color: #1e3a8a; font-weight: bold;"><?= $pdo ? 'OK' : 'ERRO' ?></div>
                <div style="color: #64748b;">Conexão DB</div>
            </div>
            <div style="text-align: center;">
                <div style="font-size: 1.5em; color: #1e3a8a; font-weight: bold;"><?= session_status() === PHP_SESSION_ACTIVE ? 'OK' : 'ERRO' ?></div>
                <div style="color: #64748b;">Sessão</div>
            </div>
        </div>
    </div>
</div>

<style>
/* Dashboard Styles */
.dashboard-container {
    padding: 20px;
    max-width: 1400px;
    margin: 0 auto;
}

.dashboard-header {
    background: white;
    padding: 30px;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
    margin-bottom: 30px;
    text-align: center;
}

.dashboard-title {
    font-size: 2.5em;
    font-weight: 700;
    color: #1e3a8a;
    margin-bottom: 10px;
}

.dashboard-subtitle {
    font-size: 1.2em;
    color: #64748b;
}

.dashboard-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 25px;
    margin-bottom: 40px;
}

.dashboard-card {
    background: white;
    padding: 30px;
    border-radius: 15px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
    border-left: 5px solid #1e3a8a;
    transition: all 0.3s ease;
    text-align: center;
}

.dashboard-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
}

.card-icon {
    width: 60px;
    height: 60px;
    background: linear-gradient(135deg, #1e3a8a, #1e40af);
    border-radius: 15px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    color: white;
    margin: 0 auto 20px;
}

.card-value {
    font-size: 3em;
    font-weight: 800;
    color: #1e3a8a;
    margin-bottom: 10px;
}

.card-label {
    font-size: 1.1em;
    color: #64748b;
    font-weight: 600;
}

.card-source {
    font-size: 0.9em;
    color: #94a3b8;
    margin-top: 8px;
}

/* Responsivo */
@media (max-width: 768px) {
    .dashboard-cards {
        grid-template-columns: repeat(2, 1fr);
        gap: 15px;
    }
    
    .dashboard-title {
        font-size: 2em;
    }
}
</style>

<script>
// Animar cards ao carregar
document.addEventListener('DOMContentLoaded', function() {
    const cards = document.querySelectorAll('.dashboard-card');
    cards.forEach((card, index) => {
        setTimeout(() => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            card.style.transition = 'all 0.6s ease';
            
            setTimeout(() => {
                card.style.opacity = '1';
                card.style.transform = 'translateY(0)';
            }, 100);
        }, index * 100);
    });
});
</script>
