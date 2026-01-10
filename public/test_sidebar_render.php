<?php
/**
 * Teste de Renderização da Sidebar
 * Simula como a sidebar seria renderizada para diferentes usuários
 */

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';

// Verificar se está logado
if (empty($_SESSION['logado']) || empty($_SESSION['id'])) {
    die("❌ ERRO: Você precisa estar logado para executar este teste. <a href='login.php'>Fazer Login</a>");
}

$usuario_id = $_SESSION['id'] ?? $_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? null;

// Carregar permissões
require_once __DIR__ . '/permissoes_boot.php';

// Função para simular a verificação da sidebar
function testarSidebar($permissoes_sessao) {
    $modulos = [
        'Dashboard' => null, // Sempre visível
        'Agenda' => 'perm_agenda',
        'Demandas' => 'perm_demandas',
        'Comercial' => 'perm_comercial',
        'Logístico' => 'perm_logistico',
        'Configurações' => 'perm_configuracoes',
        'Cadastros' => 'perm_cadastros',
        'Financeiro' => 'perm_financeiro',
        'Administrativo' => 'perm_administrativo',
        'RH' => 'perm_rh',
        'Banco Smile' => 'perm_banco_smile'
    ];
    
    $resultado = [];
    
    foreach ($modulos as $modulo => $perm) {
        if ($perm === null) {
            // Dashboard sempre aparece
            $resultado[$modulo] = [
                'aparece' => true,
                'motivo' => 'Sempre visível (sem verificação de permissão)'
            ];
        } else {
            $valor = $permissoes_sessao[$perm] ?? null;
            $aparece = !empty($valor);
            
            $resultado[$modulo] = [
                'aparece' => $aparece,
                'perm' => $perm,
                'valor' => $valor,
                'motivo' => $aparece 
                    ? "Permissão {$perm} está ativa (TRUE)" 
                    : "Permissão {$perm} está inativa ou não existe (FALSE/NULL)"
            ];
        }
    }
    
    return $resultado;
}

// Testar com as permissões atuais
$permissoes_atual = [];
$permissoes_sidebar = [
    'perm_agenda', 'perm_demandas', 'perm_comercial', 'perm_logistico',
    'perm_configuracoes', 'perm_cadastros', 'perm_financeiro',
    'perm_administrativo', 'perm_rh', 'perm_banco_smile'
];

foreach ($permissoes_sidebar as $perm) {
    $permissoes_atual[$perm] = $_SESSION[$perm] ?? null;
}

$resultado_teste = testarSidebar($permissoes_atual);

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teste de Renderização da Sidebar</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #f5f5f5;
            padding: 2rem;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 2rem;
        }
        .sidebar-simulada {
            background: #1e293b;
            border-radius: 12px;
            padding: 1.5rem;
            color: white;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .sidebar-simulada h3 {
            margin-bottom: 1.5rem;
            font-size: 1.125rem;
            border-bottom: 1px solid rgba(255,255,255,0.2);
            padding-bottom: 0.75rem;
        }
        .nav-item {
            display: block;
            padding: 0.75rem;
            margin: 0.5rem 0;
            border-radius: 6px;
            text-decoration: none;
            color: white;
            transition: all 0.2s;
        }
        .nav-item.visible {
            background: rgba(16,185,129,0.2);
            border-left: 3px solid #10b981;
        }
        .nav-item.hidden {
            background: rgba(220,38,38,0.1);
            border-left: 3px solid #dc2626;
            opacity: 0.5;
            text-decoration: line-through;
        }
        .nav-item-icon {
            margin-right: 0.5rem;
        }
        .resultado {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #1e3a8a;
            margin-bottom: 1rem;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }
        th {
            background: #1e3a8a;
            color: white;
            padding: 0.75rem;
            text-align: left;
        }
        td {
            padding: 0.75rem;
            border-bottom: 1px solid #e5e7eb;
        }
        .status-ok { color: #059669; font-weight: 600; }
        .status-error { color: #dc2626; font-weight: 600; }
        .code {
            background: #f3f4f6;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-family: monospace;
            font-size: 0.875rem;
        }
        .info-box {
            background: #eff6ff;
            border-left: 4px solid #1e3a8a;
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Sidebar Simulada -->
        <div class="sidebar-simulada">
            <h3>📋 Sidebar (Simulação)</h3>
            <?php
            $icones = [
                'Dashboard' => '🏠',
                'Agenda' => '📅',
                'Demandas' => '📝',
                'Comercial' => '📋',
                'Logístico' => '📦',
                'Configurações' => '⚙️',
                'Cadastros' => '📝',
                'Financeiro' => '💰',
                'Administrativo' => '👥',
                'RH' => '👔',
                'Banco Smile' => '🏦'
            ];
            
            foreach ($resultado_teste as $modulo => $info) {
                $classe = $info['aparece'] ? 'visible' : 'hidden';
                $icone = $icones[$modulo] ?? '📌';
                $status_text = $info['aparece'] ? '✅' : '❌';
                
                echo "<div class='nav-item {$classe}'>";
                echo "<span class='nav-item-icon'>{$icone}</span>";
                echo "<strong>{$modulo}</strong> {$status_text}";
                echo "</div>";
            }
            ?>
        </div>
        
        <!-- Resultado Detalhado -->
        <div class="resultado">
            <h1>🔍 Teste de Renderização da Sidebar</h1>
            
            <div class="info-box">
                <strong>Usuário:</strong> <?= htmlspecialchars($_SESSION['nome'] ?? 'Desconhecido') ?><br>
                <strong>ID:</strong> <?= htmlspecialchars($usuario_id) ?><br>
                <strong>Data/Hora:</strong> <?= date('d/m/Y H:i:s') ?>
            </div>
            
            <h2 style="margin-top: 2rem; color: #374151;">Resultado do Teste</h2>
            
            <table>
                <tr>
                    <th>Módulo</th>
                    <th>Permissão</th>
                    <th>Valor na Sessão</th>
                    <th>Aparece na Sidebar?</th>
                    <th>Motivo</th>
                </tr>
                <?php
                foreach ($resultado_teste as $modulo => $info) {
                    $status = $info['aparece'] 
                        ? "<span class='status-ok'>✅ SIM</span>" 
                        : "<span class='status-error'>❌ NÃO</span>";
                    
                    $perm_text = $info['perm'] ?? '<em>Nenhuma</em>';
                    $valor_text = isset($info['valor']) 
                        ? ($info['valor'] ? '<span class="status-ok">TRUE</span>' : '<span class="status-error">FALSE</span>')
                        : '<em>NULL</em>';
                    
                    echo "<tr>";
                    echo "<td><strong>{$modulo}</strong></td>";
                    echo "<td><span class='code'>{$perm_text}</span></td>";
                    echo "<td>{$valor_text}</td>";
                    echo "<td>{$status}</td>";
                    echo "<td style='font-size: 0.875rem; color: #6b7280;'>{$info['motivo']}</td>";
                    echo "</tr>";
                }
                ?>
            </table>
            
            <div style="margin-top: 2rem; padding: 1rem; background: #f9fafb; border-radius: 8px;">
                <h3 style="margin-bottom: 1rem; color: #374151;">📊 Estatísticas</h3>
                <?php
                $total = count($resultado_teste);
                $visiveis = count(array_filter($resultado_teste, fn($r) => $r['aparece']));
                $ocultos = $total - $visiveis;
                
                echo "<div style='display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem;'>";
                echo "<div style='text-align: center; padding: 1rem; background: white; border-radius: 8px;'>";
                echo "<div style='font-size: 2rem; font-weight: 700; color: #1e3a8a;'>{$total}</div>";
                echo "<div style='color: #6b7280; font-size: 0.875rem;'>Total de Módulos</div>";
                echo "</div>";
                echo "<div style='text-align: center; padding: 1rem; background: white; border-radius: 8px;'>";
                echo "<div style='font-size: 2rem; font-weight: 700; color: #059669;'>{$visiveis}</div>";
                echo "<div style='color: #6b7280; font-size: 0.875rem;'>Visíveis</div>";
                echo "</div>";
                echo "<div style='text-align: center; padding: 1rem; background: white; border-radius: 8px;'>";
                echo "<div style='font-size: 2rem; font-weight: 700; color: #dc2626;'>{$ocultos}</div>";
                echo "<div style='color: #6b7280; font-size: 0.875rem;'>Ocultos</div>";
                echo "</div>";
                echo "</div>";
                ?>
            </div>
            
            <div style="margin-top: 2rem; padding: 1rem; background: #fef3c7; border-left: 4px solid #d97706; border-radius: 4px;">
                <strong>💡 Dica:</strong> Compare a sidebar simulada (à esquerda) com a sidebar real do sistema. 
                Elas devem corresponder exatamente. Se houver diferenças, verifique o código da sidebar.
            </div>
            
            <div style="margin-top: 1rem;">
                <a href="test_permissoes_sidebar.php" style="color: #1e3a8a; text-decoration: underline;">← Voltar para Teste Completo</a> | 
                <a href="index.php?page=dashboard" style="color: #1e3a8a; text-decoration: underline;">Ver Sidebar Real →</a>
            </div>
        </div>
    </div>
</body>
</html>

