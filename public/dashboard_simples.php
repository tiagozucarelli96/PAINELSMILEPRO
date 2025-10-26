<?php
// dashboard_simples.php — Dashboard simples e funcional
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/conexao.php';

// Buscar métricas básicas
$stats = [];
try {
    $stats['usuarios'] = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE ativo = true")->fetchColumn();
    
    // Buscar eventos da ME Eventos (webhooks)
    $mes_atual = date('Y-m');
    $stmt = $pdo->prepare("SELECT eventos_ativos, eventos_criados, eventos_excluidos, contratos_fechados, leads_total, leads_negociacao, vendas_realizadas FROM me_eventos_stats WHERE mes_ano = ?");
    $stmt->execute([$mes_atual]);
    $me_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($me_stats) {
        $stats['eventos'] = $me_stats['eventos_ativos'] ?? 0;
        $stats['contratos_fechados'] = $me_stats['contratos_fechados'] ?? 0;
        $stats['leads_total'] = $me_stats['leads_total'] ?? 0;
        $stats['leads_negociacao'] = $me_stats['leads_negociacao'] ?? 0;
        $stats['vendas_realizadas'] = $me_stats['vendas_realizadas'] ?? 0;
    } else {
        $stats['eventos'] = 0;
        $stats['contratos_fechados'] = 0;
        $stats['leads_total'] = 0;
        $stats['leads_negociacao'] = 0;
        $stats['vendas_realizadas'] = 0;
    }
    
    $stats['fornecedores'] = $pdo->query("SELECT COUNT(*) FROM fornecedores WHERE ativo = true")->fetchColumn();
    $stats['insumos'] = $pdo->query("SELECT COUNT(*) FROM lc_insumos WHERE ativo = true")->fetchColumn();
} catch (Exception $e) {
    $stats = ['usuarios' => 0, 'eventos' => 0, 'fornecedores' => 0, 'insumos' => 0, 'contratos_fechados' => 0, 'leads_total' => 0, 'leads_negociacao' => 0, 'vendas_realizadas' => 0];
}

$nomeUser = $_SESSION['nome'] ?? 'Usuário';
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Principal - GRUPO Smile EVENTOS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: #f8fafc;
            color: #1e293b;
            overflow-x: hidden;
        }
        
        /* Layout Principal */
        .app-container {
            display: flex;
            min-height: 100vh;
        }
        
        /* Sidebar */
        .sidebar {
            width: 280px;
            background: linear-gradient(180deg, #1e3a8a 0%, #1e40af 100%);
            color: white;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
            box-shadow: 4px 0 20px rgba(0, 0, 0, 0.1);
        }
        
        .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            text-align: center;
        }
        
        .sidebar-logo {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 10px 0;
        }
        
        .sidebar-logo img {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
        }
        
        .user-info {
            margin-top: 15px;
            text-align: center;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 8px;
            font-weight: 600;
            font-size: 16px;
        }
        
        .user-name {
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 4px;
        }
        
        .user-plan {
            font-size: 12px;
            color: rgba(255, 255, 255, 0.7);
            background: rgba(255, 255, 255, 0.1);
            padding: 4px 8px;
            border-radius: 12px;
            display: inline-block;
        }
        
        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            min-height: 100vh;
            background: #f8fafc;
        }
        
        /* Dashboard */
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
        
        .payment-button {
            position: fixed;
            bottom: 30px;
            right: 30px;
            background: linear-gradient(135deg, #1e3a8a, #1e40af);
            color: white;
            border: none;
            padding: 20px 25px;
            border-radius: 50px;
            font-size: 16px;
            font-weight: 700;
            box-shadow: 0 6px 25px rgba(30, 58, 138, 0.4);
            cursor: pointer;
            transition: all 0.3s ease;
            z-index: 1000;
        }
        
        .payment-button:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 30px rgba(30, 58, 138, 0.5);
        }
        
        /* Responsivo */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .dashboard-cards {
                grid-template-columns: repeat(2, 1fr);
                gap: 15px;
            }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <div class="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <img src="logo-smile.png" alt="Smile EVENTOS">
                </div>
                <div class="user-info">
                    <div class="user-avatar"><?= strtoupper(substr($nomeUser, 0, 2)) ?></div>
                    <div class="user-name"><?= htmlspecialchars($nomeUser) ?></div>
                    <div class="user-plan">ADMINISTRADOR</div>
                </div>
            </div>
        </div>
        
        <div class="main-content">
            <div class="dashboard-container">
                <!-- Header -->
                <div class="dashboard-header">
                    <h1 class="dashboard-title">🎉 Dashboard Principal</h1>
                    <p class="dashboard-subtitle">Bem-vindo ao sistema Smile EVENTOS</p>
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

                <!-- Resumo do Sistema -->
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
            </div>
        </div>
    </div>

    <!-- Botão Solicitar Pagamento -->
    <button class="payment-button" onclick="alert('Funcionalidade em desenvolvimento!')">
        💸 Solicitar Pagamento
    </button>

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
</body>
</html>
