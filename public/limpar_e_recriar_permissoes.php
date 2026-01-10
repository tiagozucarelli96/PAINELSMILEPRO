<?php
/**
 * Script para limpar e recriar permissões do zero
 * ATENÇÃO: Remove TODAS as colunas de permissões e cria apenas as essenciais
 */

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';

// Verificar permissão
if (empty($_SESSION['logado']) || empty($_SESSION['perm_configuracoes'])) {
    die('Acesso negado');
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Limpar e Recriar Permissões</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; }
        .success { color: green; padding: 10px; background: #e8f5e9; border-radius: 4px; margin: 10px 0; }
        .error { color: red; padding: 10px; background: #ffebee; border-radius: 4px; margin: 10px 0; }
        .info { color: #1976d2; padding: 10px; background: #e3f2fd; border-radius: 4px; margin: 10px 0; }
        pre { background: #f5f5f5; padding: 10px; border-radius: 4px; overflow-x: auto; }
        button { padding: 10px 20px; background: #2196F3; color: white; border: none; border-radius: 4px; cursor: pointer; }
        button:hover { background: #1976D2; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Limpar e Recriar Permissões</h1>
        
        <?php
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirmar'])) {
            try {
                echo '<div class="info"><strong>Iniciando processo...</strong></div>';
                
                // PASSO 1: Listar colunas de permissões existentes
                echo '<h3>Passo 1: Listando colunas de permissões existentes</h3>';
                $stmt = $pdo->query("
                    SELECT column_name
                    FROM information_schema.columns
                    WHERE table_schema = 'public'
                    AND table_name = 'usuarios'
                    AND column_name LIKE 'perm_%'
                    ORDER BY column_name
                ");
                $permCols = $stmt->fetchAll(PDO::FETCH_COLUMN);
                echo '<div class="info">Encontradas ' . count($permCols) . ' colunas de permissões</div>';
                if (!empty($permCols)) {
                    echo '<pre>' . implode("\n", $permCols) . '</pre>';
                }
                
                // PASSO 2: Remover todas as colunas de permissões
                echo '<h3>Passo 2: Removendo colunas de permissões</h3>';
                $removed = 0;
                foreach ($permCols as $col) {
                    try {
                        $pdo->exec("ALTER TABLE usuarios DROP COLUMN IF EXISTS \"$col\"");
                        echo '<div class="success">✓ Coluna removida: ' . htmlspecialchars($col) . '</div>';
                        $removed++;
                    } catch (Exception $e) {
                        echo '<div class="error">✗ Erro ao remover ' . htmlspecialchars($col) . ': ' . $e->getMessage() . '</div>';
                    }
                }
                echo '<div class="info"><strong>Total removido: ' . $removed . ' colunas</strong></div>';
                
                // PASSO 3: Criar apenas permissões essenciais
                echo '<h3>Passo 3: Criando permissões essenciais</h3>';
                $essentials = [
                    'perm_agenda',
                    'perm_comercial',
                    // 'perm_logistico', // REMOVIDO: Módulo desativado
                    'perm_configuracoes',
                    'perm_cadastros',
                    'perm_financeiro',
                    'perm_administrativo',
                    'perm_banco_smile',
                    'perm_usuarios'
                ];
                
                $created = 0;
                foreach ($essentials as $perm) {
                    try {
                        $pdo->exec("ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS $perm BOOLEAN DEFAULT FALSE");
                        echo '<div class="success">✓ Coluna criada: ' . $perm . '</div>';
                        $created++;
                    } catch (Exception $e) {
                        echo '<div class="error">✗ Erro ao criar ' . $perm . ': ' . $e->getMessage() . '</div>';
                    }
                }
                echo '<div class="info"><strong>Total criado: ' . $created . ' colunas</strong></div>';
                
                // PASSO 4: Verificar resultado
                echo '<h3>Passo 4: Verificando resultado</h3>';
                $stmt = $pdo->query("
                    SELECT column_name 
                    FROM information_schema.columns 
                    WHERE table_schema = 'public' 
                    AND table_name = 'usuarios' 
                    AND column_name LIKE 'perm_%'
                    ORDER BY column_name
                ");
                $finalPerms = $stmt->fetchAll(PDO::FETCH_COLUMN);
                echo '<div class="info">Total de permissões após recriação: ' . count($finalPerms) . '</div>';
                echo '<pre>' . implode("\n", $finalPerms) . '</pre>';
                
                echo '<div class="success"><h2>✅ Processo concluído com sucesso!</h2></div>';
                echo '<p><a href="index.php?page=usuarios">← Voltar para Usuários</a></p>';
                
            } catch (Exception $e) {
                echo '<div class="error"><h2>❌ Erro durante o processo:</h2><p>' . htmlspecialchars($e->getMessage()) . '</p></div>';
            }
        } else {
            // Mostrar formulário de confirmação
            $stmt = $pdo->query("
                SELECT column_name
                FROM information_schema.columns
                WHERE table_schema = 'public'
                AND table_name = 'usuarios'
                AND column_name LIKE 'perm_%'
                ORDER BY column_name
            ");
            $permCols = $stmt->fetchAll(PDO::FETCH_COLUMN);
            ?>
            
            <div class="info">
                <h3>⚠️ Atenção!</h3>
                <p>Este processo irá:</p>
                <ul>
                    <li>Remover <strong>TODAS</strong> as <?= count($permCols) ?> colunas de permissões existentes</li>
                    <li>Criar apenas as 10 permissões essenciais do sistema</li>
                </ul>
                <p><strong>Os dados de permissões dos usuários serão perdidos!</strong></p>
            </div>
            
            <?php if (!empty($permCols)): ?>
            <h3>Colunas que serão removidas:</h3>
            <pre><?= htmlspecialchars(implode("\n", $permCols)) ?></pre>
            <?php endif; ?>
            
            <form method="POST">
                <button type="submit" name="confirmar" onclick="return confirm('Tem certeza? Esta ação não pode ser desfeita!')">
                    Confirmar e Executar
                </button>
                <a href="index.php?page=usuarios" style="margin-left: 10px;">Cancelar</a>
            </form>
            <?php
        }
        ?>
    </div>
</body>
</html>

