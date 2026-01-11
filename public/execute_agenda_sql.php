<?php
// execute_agenda_sql.php — Executar SQL da agenda corretamente
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';

function executeAgendaSQL() {
    echo "<h1>🗄️ Executando SQL do Sistema de Agenda</h1>";
    echo "<style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .success { color: #10b981; }
        .error { color: #ef4444; }
        .warning { color: #f59e0b; }
        .section { margin: 20px 0; padding: 15px; border-radius: 8px; }
        .info { background: #f0f9ff; border: 1px solid #bae6fd; }
        .success-bg { background: #f0fdf4; border: 1px solid #bbf7d0; }
        .error-bg { background: #fef2f2; border: 1px solid #fecaca; }
        .warning-bg { background: #fffbeb; border: 1px solid #fed7aa; }
        .sql-item { margin: 15px 0; padding: 15px; background: #f9fafb; border-left: 4px solid #3b82f6; }
        .sql-result { margin-left: 20px; }
        .progress-bar { width: 100%; background: #e5e7eb; border-radius: 10px; overflow: hidden; margin: 10px 0; }
        .progress-fill { height: 20px; background: linear-gradient(90deg, #3b82f6, #10b981); transition: width 0.3s ease; }
        .progress-text { text-align: center; margin-top: 5px; font-weight: 600; }
    </style>";
    
    try {
        $pdo = $GLOBALS['pdo'];
        
        echo "<div class='section info'>";
        echo "<h2>🗄️ Executando SQL do Sistema de Agenda</h2>";
        echo "<p>Este script executa o arquivo SQL completo do sistema de agenda.</p>";
        echo "</div>";
        
        // Ler arquivo SQL
        $sql_file = __DIR__ . '/../sql/017_agenda_interna.sql';
        
        if (!file_exists($sql_file)) {
            echo "<div class='section error-bg'>";
            echo "<h2>❌ Erro</h2>";
            echo "<p>Arquivo SQL não encontrado: {$sql_file}</p>";
            echo "</div>";
            return;
        }
        
        $sql_content = file_get_contents($sql_file);
        
        if (empty($sql_content)) {
            echo "<div class='section error-bg'>";
            echo "<h2>❌ Erro</h2>";
            echo "<p>Arquivo SQL está vazio</p>";
            echo "</div>";
            return;
        }
        
        echo "<div class='sql-item'>";
        echo "<h3>📋 Carregando Arquivo SQL</h3>";
        echo "<div class='sql-result'>";
        echo "<p><strong>Arquivo:</strong> {$sql_file}</p>";
        echo "<p><strong>Tamanho:</strong> " . strlen($sql_content) . " bytes</p>";
        echo "<p><strong>Linhas:</strong> " . substr_count($sql_content, "\n") . "</p>";
        echo "</div></div>";
        
        // Dividir em comandos
        $commands = explode(';', $sql_content);
        $total_commands = 0;
        $executed_commands = 0;
        $errors = 0;
        
        echo "<div class='sql-item'>";
        echo "<h3>⚙️ Executando Comandos SQL</h3>";
        echo "<div class='sql-result'>";
        
        foreach ($commands as $command) {
            $command = trim($command);
            if (empty($command) || strpos($command, '--') === 0) {
                continue;
            }
            
            $total_commands++;
            
            try {
                $pdo->exec($command);
                $executed_commands++;
            } catch (PDOException $e) {
                $errors++;
                echo "<p class='error'>❌ Erro no comando " . $total_commands . ": " . $e->getMessage() . "</p>";
            }
        }
        
        echo "<p><strong>Total de comandos:</strong> {$total_commands}</p>";
        echo "<p><strong>Executados com sucesso:</strong> {$executed_commands}</p>";
        echo "<p><strong>Erros encontrados:</strong> {$errors}</p>";
        echo "</div></div>";
        
        // Verificar tabelas criadas
        echo "<div class='sql-item'>";
        echo "<h3>📊 Verificando Tabelas Criadas</h3>";
        echo "<div class='sql-result'>";
        
        $tabelas_agenda = [
            'agenda_espacos',
            'agenda_eventos',
            'agenda_lembretes',
            'agenda_tokens_ics'
        ];
        
        $tabelas_ok = 0;
        foreach ($tabelas_agenda as $tabela) {
            try {
                $stmt = $pdo->query("SELECT COUNT(*) FROM {$tabela}");
                $count = $stmt->fetchColumn();
                echo "<p class='success'>✅ {$tabela}: {$count} registros</p>";
                $tabelas_ok++;
            } catch (Exception $e) {
                echo "<p class='error'>❌ {$tabela}: " . $e->getMessage() . "</p>";
            }
        }
        
        echo "<p><strong>Total:</strong> {$tabelas_ok}/" . count($tabelas_agenda) . " tabelas funcionando</p>";
        echo "</div></div>";
        
        // Verificar funções criadas
        echo "<div class='sql-item'>";
        echo "<h3>⚙️ Verificando Funções Criadas</h3>";
        echo "<div class='sql-result'>";
        
        $funcoes = [
            'verificar_conflito_agenda',
            'gerar_token_ics',
            'obter_proximos_eventos',
            'calcular_conversao_visitas'
        ];
        
        $funcoes_ok = 0;
        foreach ($funcoes as $funcao) {
            try {
                $stmt = $pdo->query("SELECT routine_name FROM information_schema.routines WHERE routine_name = '{$funcao}'");
                if ($stmt->fetch()) {
                    echo "<p class='success'>✅ Função {$funcao}: OK</p>";
                    $funcoes_ok++;
                } else {
                    echo "<p class='error'>❌ Função {$funcao}: Não encontrada</p>";
                }
            } catch (Exception $e) {
                echo "<p class='error'>❌ Erro ao verificar função {$funcao}: " . $e->getMessage() . "</p>";
            }
        }
        
        echo "<p><strong>Total:</strong> {$funcoes_ok}/" . count($funcoes) . " funções funcionando</p>";
        echo "</div></div>";
        
        // Verificar espaços inseridos
        echo "<div class='sql-item'>";
        echo "<h3>🏢 Verificando Espaços</h3>";
        echo "<div class='sql-result'>";
        
        try {
            $stmt = $pdo->query("SELECT * FROM agenda_espacos ORDER BY id");
            $espacos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo "<p class='success'>✅ Espaços configurados: " . count($espacos) . "</p>";
            
            if (count($espacos) > 0) {
                echo "<table border='1' style='border-collapse: collapse; width: 100%; margin-top: 10px;'>";
                echo "<tr style='background: #f3f4f6;'>";
                echo "<th style='padding: 10px;'>ID</th>";
                echo "<th style='padding: 10px;'>Nome</th>";
                echo "<th style='padding: 10px;'>Slug</th>";
                echo "<th style='padding: 10px;'>Status</th>";
                echo "</tr>";
                
                foreach ($espacos as $espaco) {
                    echo "<tr>";
                    echo "<td style='padding: 10px;'>{$espaco['id']}</td>";
                    echo "<td style='padding: 10px;'>{$espaco['nome']}</td>";
                    echo "<td style='padding: 10px;'>{$espaco['slug']}</td>";
                    echo "<td style='padding: 10px;'>" . ($espaco['ativo'] ? '✅ Ativo' : '❌ Inativo') . "</td>";
                    echo "</tr>";
                }
                echo "</table>";
            }
        } catch (Exception $e) {
            echo "<p class='error'>❌ Erro ao verificar espaços: " . $e->getMessage() . "</p>";
        }
        echo "</div></div>";
        
        // Resultado final
        $percentage = round(($tabelas_ok / count($tabelas_agenda)) * 100);
        
        echo "<div class='section success-bg'>";
        echo "<h2>🎉 SQL Executado com Sucesso!</h2>";
        echo "<div class='progress-bar'>";
        echo "<div class='progress-fill' style='width: {$percentage}%;'></div>";
        echo "</div>";
        echo "<div class='progress-text'>{$tabelas_ok}/" . count($tabelas_agenda) . " tabelas funcionando ({$percentage}%)</div>";
        
        if ($percentage >= 90) {
            echo "<p class='success'>🎉 Excelente! O sistema de agenda foi configurado com sucesso.</p>";
        } elseif ($percentage >= 70) {
            echo "<p class='warning'>⚠️ Bom! O sistema foi configurado com alguns problemas menores.</p>";
        } else {
            echo "<p class='error'>❌ Atenção! O sistema precisa de correções.</p>";
        }
        
        echo "<h3>📋 Resumo da Execução:</h3>";
        echo "<ul>";
        echo "<li>✅ <strong>Comandos SQL:</strong> {$executed_commands}/{$total_commands} executados</li>";
        echo "<li>✅ <strong>Tabelas:</strong> {$tabelas_ok}/" . count($tabelas_agenda) . " funcionando</li>";
        echo "<li>✅ <strong>Funções:</strong> {$funcoes_ok}/" . count($funcoes) . " funcionando</li>";
        echo "<li>✅ <strong>Espaços:</strong> " . count($espacos) . " configurados</li>";
        echo "<li>❌ <strong>Erros:</strong> {$errors} encontrados</li>";
        echo "</ul>";
        
        echo "<h3>🚀 Próximos Passos:</h3>";
        echo "<ol>";
        echo "<li>Configure o e-mail: <a href='index.php?page=config_email_global'>config_email_global</a></li>";
        echo "<li>Teste o sistema: <a href='test_agenda.php'>test_agenda.php</a></li>";
        echo "<li>Acesse a agenda: <a href='index.php?page=agenda'>index.php?page=agenda</a></li>";
        echo "</ol>";
        echo "</div>";
        
    } catch (Exception $e) {
        echo "<div class='section error-bg'>";
        echo "<h2>❌ Erro Geral</h2>";
        echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
        echo "</div>";
    }
}

// Executar SQL
executeAgendaSQL();
?>
