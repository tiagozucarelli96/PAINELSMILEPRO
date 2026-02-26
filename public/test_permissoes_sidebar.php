<?php
/**
 * Script de Teste de Permissões da Sidebar
 * Verifica se todas as permissões estão funcionando corretamente
 */

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';

// Verificar se está logado
if (empty($_SESSION['logado']) || empty($_SESSION['id'])) {
    die("❌ ERRO: Você precisa estar logado para executar este teste. <a href='login.php'>Fazer Login</a>");
}

$usuario_id = $_SESSION['id'] ?? $_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? null;
$nome_usuario = $_SESSION['nome'] ?? 'Usuário Desconhecido';

// Carregar permissões
require_once __DIR__ . '/permissoes_boot.php';

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teste de Permissões - Sidebar</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #f5f5f5;
            padding: 2rem;
            line-height: 1.6;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 2rem;
        }
        h1 {
            color: #1e3a8a;
            margin-bottom: 1rem;
            border-bottom: 3px solid #1e3a8a;
            padding-bottom: 0.5rem;
        }
        h2 {
            color: #374151;
            margin-top: 2rem;
            margin-bottom: 1rem;
            font-size: 1.25rem;
        }
        .info-box {
            background: #eff6ff;
            border-left: 4px solid #1e3a8a;
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-radius: 4px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
            background: white;
        }
        th {
            background: #1e3a8a;
            color: white;
            padding: 0.75rem;
            text-align: left;
            font-weight: 600;
        }
        td {
            padding: 0.75rem;
            border-bottom: 1px solid #e5e7eb;
        }
        tr:hover {
            background: #f9fafb;
        }
        .status-ok {
            color: #059669;
            font-weight: 600;
        }
        .status-error {
            color: #dc2626;
            font-weight: 600;
        }
        .status-warning {
            color: #d97706;
            font-weight: 600;
        }
        .badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.875rem;
            font-weight: 600;
        }
        .badge-true {
            background: #d1fae5;
            color: #065f46;
        }
        .badge-false {
            background: #fee2e2;
            color: #991b1b;
        }
        .badge-missing {
            background: #fef3c7;
            color: #92400e;
        }
        .summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1.5rem;
        }
        .summary-card {
            background: #f9fafb;
            padding: 1rem;
            border-radius: 8px;
            border-left: 4px solid #1e3a8a;
        }
        .summary-card h3 {
            font-size: 0.875rem;
            color: #6b7280;
            margin-bottom: 0.5rem;
        }
        .summary-card .value {
            font-size: 2rem;
            font-weight: 700;
            color: #1e293b;
        }
        .code {
            background: #f3f4f6;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-size: 0.875rem;
        }
        .test-section {
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 2px solid #e5e7eb;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Teste de Permissões da Sidebar</h1>
        
        <div class="info-box">
            <strong>Usuário Testado:</strong> <?= htmlspecialchars($nome_usuario) ?><br>
            <strong>ID do Usuário:</strong> <?= htmlspecialchars($usuario_id) ?><br>
            <strong>Data/Hora:</strong> <?= date('d/m/Y H:i:s') ?>
        </div>

        <?php
        // ============================================
        // TESTE 1: Verificar colunas no banco de dados
        // ============================================
        echo "<div class='test-section'>";
        echo "<h2>1️⃣ Verificação de Colunas no Banco de Dados</h2>";
        
        $permissoes_sidebar = [
            'perm_agenda' => 'Agenda',
            'perm_demandas' => 'Demandas',
            'perm_comercial' => 'Comercial',
            // 'perm_logistico' => 'Logístico', // REMOVIDO: Módulo desativado
            'perm_configuracoes' => 'Configurações',
            'perm_cadastros' => 'Cadastros',
            'perm_financeiro' => 'Financeiro',
            'perm_administrativo' => 'Administrativo',
            'perm_banco_smile' => 'Banco Smile',
            'perm_portao' => 'Portao'
        ];
        
        $colunas_existentes = [];
        $colunas_faltando = [];
        
        try {
            $stmt = $pdo->query("SELECT column_name FROM information_schema.columns 
                                WHERE table_schema = 'public' 
                                AND table_name = 'usuarios' 
                                AND column_name LIKE 'perm_%'
                                ORDER BY column_name");
            $colunas_banco = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($permissoes_sidebar as $perm => $nome) {
                if (in_array($perm, $colunas_banco)) {
                    $colunas_existentes[$perm] = $nome;
                } else {
                    $colunas_faltando[$perm] = $nome;
                }
            }
            
            echo "<table>";
            echo "<tr><th>Permissão</th><th>Módulo</th><th>Status</th></tr>";
            
            foreach ($permissoes_sidebar as $perm => $nome) {
                $status = in_array($perm, $colunas_banco) 
                    ? "<span class='status-ok'>✅ Existe</span>" 
                    : "<span class='status-error'>❌ Faltando</span>";
                
                echo "<tr>";
                echo "<td><span class='code'>{$perm}</span></td>";
                echo "<td>{$nome}</td>";
                echo "<td>{$status}</td>";
                echo "</tr>";
            }
            
            echo "</table>";
            
            if (!empty($colunas_faltando)) {
                echo "<div style='margin-top: 1rem; padding: 1rem; background: #fee2e2; border-radius: 8px;'>";
                echo "<strong class='status-error'>⚠️ ATENÇÃO:</strong> As seguintes colunas não existem no banco de dados:<br>";
                foreach ($colunas_faltando as $perm => $nome) {
                    echo "• <span class='code'>{$perm}</span> ({$nome})<br>";
                }
                echo "</div>";
            }
        } catch (Exception $e) {
            echo "<div class='status-error'>❌ Erro ao verificar colunas: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
        echo "</div>";
        
        // ============================================
        // TESTE 2: Verificar valores no banco de dados
        // ============================================
        echo "<div class='test-section'>";
        echo "<h2>2️⃣ Valores no Banco de Dados (Usuário Atual)</h2>";
        
        try {
            $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id = :id");
            $stmt->execute([':id' => $usuario_id]);
            $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($usuario) {
                echo "<table>";
                echo "<tr><th>Permissão</th><th>Módulo</th><th>Valor no Banco</th><th>Status</th></tr>";
                
                foreach ($permissoes_sidebar as $perm => $nome) {
                    $valor_banco = $usuario[$perm] ?? null;
                    
                    if ($valor_banco === null) {
                        $badge = "<span class='badge badge-missing'>Coluna não existe</span>";
                        $status = "<span class='status-error'>❌</span>";
                    } else {
                        $valor_bool = filter_var($valor_banco, FILTER_VALIDATE_BOOLEAN);
                        $badge = $valor_bool 
                            ? "<span class='badge badge-true'>TRUE</span>" 
                            : "<span class='badge badge-false'>FALSE</span>";
                        $status = $valor_bool ? "<span class='status-ok'>✅</span>" : "<span class='status-warning'>⚠️</span>";
                    }
                    
                    echo "<tr>";
                    echo "<td><span class='code'>{$perm}</span></td>";
                    echo "<td>{$nome}</td>";
                    echo "<td>{$badge}</td>";
                    echo "<td>{$status}</td>";
                    echo "</tr>";
                }
                
                echo "</table>";
            } else {
                echo "<div class='status-error'>❌ Usuário não encontrado no banco de dados!</div>";
            }
        } catch (Exception $e) {
            echo "<div class='status-error'>❌ Erro ao buscar valores: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
        echo "</div>";
        
        // ============================================
        // TESTE 3: Verificar valores na sessão
        // ============================================
        echo "<div class='test-section'>";
        echo "<h2>3️⃣ Valores na Sessão (Após permissoes_boot.php)</h2>";
        
        echo "<table>";
        echo "<tr><th>Permissão</th><th>Módulo</th><th>Valor na Sessão</th><th>Botão Aparece?</th></tr>";
        
        $permissoes_ativas = 0;
        $permissoes_inativas = 0;
        
        foreach ($permissoes_sidebar as $perm => $nome) {
            $valor_sessao = $_SESSION[$perm] ?? null;
            $valor_bool = !empty($valor_sessao);
            
            if ($valor_bool) {
                $permissoes_ativas++;
            } else {
                $permissoes_inativas++;
            }
            
            $badge = $valor_bool 
                ? "<span class='badge badge-true'>TRUE</span>" 
                : "<span class='badge badge-false'>FALSE</span>";
            
            $botao_aparece = $valor_bool 
                ? "<span class='status-ok'>✅ SIM</span>" 
                : "<span class='status-warning'>❌ NÃO</span>";
            
            echo "<tr>";
            echo "<td><span class='code'>{$perm}</span></td>";
            echo "<td>{$nome}</td>";
            echo "<td>{$badge}</td>";
            echo "<td>{$botao_aparece}</td>";
            echo "</tr>";
        }
        
        echo "</table>";
        
        echo "<div class='summary'>";
        echo "<div class='summary-card'>";
        echo "<h3>Permissões Ativas</h3>";
        echo "<div class='value' style='color: #059669;'>{$permissoes_ativas}</div>";
        echo "</div>";
        echo "<div class='summary-card'>";
        echo "<h3>Permissões Inativas</h3>";
        echo "<div class='value' style='color: #dc2626;'>{$permissoes_inativas}</div>";
        echo "</div>";
        echo "<div class='summary-card'>";
        echo "<h3>Total de Módulos</h3>";
        echo "<div class='value'>" . ($permissoes_ativas + $permissoes_inativas) . "</div>";
        echo "</div>";
        echo "</div>";
        
        echo "</div>";
        
        // ============================================
        // TESTE 4: Simular renderização da sidebar
        // ============================================
        echo "<div class='test-section'>";
        echo "<h2>4️⃣ Simulação da Sidebar (Como Apareceria)</h2>";
        
        echo "<div style='background: #1e293b; padding: 1.5rem; border-radius: 8px; color: white;'>";
        echo "<div style='font-weight: 600; margin-bottom: 1rem; font-size: 1.125rem;'>📋 Menu da Sidebar</div>";
        
        // Dashboard sempre aparece
        echo "<div style='padding: 0.5rem; margin: 0.25rem 0; background: rgba(255,255,255,0.1); border-radius: 4px;'>";
        echo "🏠 <strong>Dashboard</strong> <span style='color: #10b981; font-size: 0.875rem;'>(sempre visível)</span>";
        echo "</div>";
        
        // Módulos com permissão
        foreach ($permissoes_sidebar as $perm => $nome) {
            $valor_sessao = $_SESSION[$perm] ?? null;
            $valor_bool = !empty($valor_sessao);
            
            if ($valor_bool) {
                $icone = [
                    'perm_agenda' => '📅',
                    'perm_demandas' => '📝',
                    'perm_comercial' => '📋',
                    // 'perm_logistico' => '📦', // REMOVIDO: Módulo desativado
                    'perm_configuracoes' => '⚙️',
                    'perm_cadastros' => '📝',
                    'perm_financeiro' => '💰',
                    'perm_administrativo' => '👥',
                    'perm_banco_smile' => '🏦',
                    'perm_portao' => '🔓'
                ][$perm] ?? '📌';
                
                echo "<div style='padding: 0.5rem; margin: 0.25rem 0; background: rgba(16,185,129,0.2); border-left: 3px solid #10b981; border-radius: 4px;'>";
                echo "{$icone} <strong>{$nome}</strong> <span style='color: #10b981; font-size: 0.875rem;'>(VISÍVEL)</span>";
                echo "</div>";
            } else {
                echo "<div style='padding: 0.5rem; margin: 0.25rem 0; background: rgba(220,38,38,0.1); border-left: 3px solid #dc2626; border-radius: 4px; opacity: 0.5;'>";
                echo "🚫 <strong>{$nome}</strong> <span style='color: #fca5a5; font-size: 0.875rem;'>(OCULTO)</span>";
                echo "</div>";
            }
        }
        
        echo "</div>";
        echo "</div>";
        
        // ============================================
        // TESTE 5: Verificar consistência
        // ============================================
        echo "<div class='test-section'>";
        echo "<h2>5️⃣ Verificação de Consistência</h2>";
        
        $problemas = [];
        
        foreach ($permissoes_sidebar as $perm => $nome) {
            $valor_banco = $usuario[$perm] ?? null;
            $valor_sessao = $_SESSION[$perm] ?? null;
            
            if ($valor_banco === null) {
                $problemas[] = "Coluna <span class='code'>{$perm}</span> não existe no banco de dados";
            } else {
                $valor_banco_bool = filter_var($valor_banco, FILTER_VALIDATE_BOOLEAN);
                $valor_sessao_bool = !empty($valor_sessao);
                
                if ($valor_banco_bool !== $valor_sessao_bool) {
                    $problemas[] = "Inconsistência em <span class='code'>{$perm}</span>: Banco=" . ($valor_banco_bool ? 'TRUE' : 'FALSE') . ", Sessão=" . ($valor_sessao_bool ? 'TRUE' : 'FALSE');
                }
            }
        }
        
        if (empty($problemas)) {
            echo "<div style='padding: 1rem; background: #d1fae5; border-radius: 8px; border-left: 4px solid #059669;'>";
            echo "<strong class='status-ok'>✅ Tudo OK!</strong> Não foram encontrados problemas de consistência.";
            echo "</div>";
        } else {
            echo "<div style='padding: 1rem; background: #fee2e2; border-radius: 8px; border-left: 4px solid #dc2626;'>";
            echo "<strong class='status-error'>⚠️ Problemas Encontrados:</strong><br><br>";
            foreach ($problemas as $problema) {
                echo "• {$problema}<br>";
            }
            echo "</div>";
        }
        
        echo "</div>";
        
        // ============================================
        // RESUMO FINAL
        // ============================================
        echo "<div class='test-section'>";
        echo "<h2>📊 Resumo Final</h2>";
        
        $total_modulos = count($permissoes_sidebar);
        $modulos_visiveis = $permissoes_ativas + 1; // +1 para Dashboard
        
        echo "<div class='summary'>";
        echo "<div class='summary-card'>";
        echo "<h3>Total de Módulos</h3>";
        echo "<div class='value'>{$total_modulos}</div>";
        echo "</div>";
        echo "<div class='summary-card'>";
        echo "<h3>Módulos Visíveis</h3>";
        echo "<div class='value' style='color: #059669;'>{$modulos_visiveis}</div>";
        echo "</div>";
        echo "<div class='summary-card'>";
        echo "<h3>Módulos Ocultos</h3>";
        echo "<div class='value' style='color: #dc2626;'>{$permissoes_inativas}</div>";
        echo "</div>";
        echo "<div class='summary-card'>";
        echo "<h3>Status Geral</h3>";
        echo "<div class='value' style='color: " . (empty($problemas) ? "#059669" : "#dc2626") . ";'>" . (empty($problemas) ? "✅ OK" : "❌ ERRO") . "</div>";
        echo "</div>";
        echo "</div>";
        
        echo "</div>";
        ?>
        
        <div style="margin-top: 2rem; padding: 1rem; background: #f3f4f6; border-radius: 8px;">
            <strong>ℹ️ Como usar este teste:</strong><br>
            1. Verifique se todas as colunas existem no banco de dados<br>
            2. Confirme que os valores na sessão correspondem aos valores no banco<br>
            3. Veja como a sidebar apareceria para este usuário<br>
            4. Corrija qualquer inconsistência encontrada<br><br>
            <a href="index.php?page=usuarios" style="color: #1e3a8a; text-decoration: underline;">→ Ir para Gerenciamento de Usuários</a>
        </div>
    </div>
</body>
</html>
