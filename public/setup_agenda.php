<?php
// setup_agenda.php — Configuração do sistema de agenda
require_once __DIR__ . '/conexao.php';

function setupAgenda() {
    echo "<h1>🗓️ Configuração do Sistema de Agenda</h1>";
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
        .step { margin: 15px 0; padding: 15px; background: #f9fafb; border-left: 4px solid #3b82f6; }
        .step-result { margin-left: 20px; }
        .btn { padding: 10px 20px; background: #3b82f6; color: white; border: none; border-radius: 5px; cursor: pointer; text-decoration: none; display: inline-block; margin: 5px; }
        .btn:hover { background: #1d4ed8; }
        .btn-success { background: #10b981; }
        .btn-success:hover { background: #059669; }
    </style>";
    
    try {
        $pdo = $GLOBALS['pdo'];
        
        echo "<div class='section info'>";
        echo "<h2>🗓️ Configurando Sistema de Agenda Interna</h2>";
        echo "<p>Este script configura o sistema de agenda interna do Painel Smile PRO.</p>";
        echo "</div>";
        
        // Passo 1: Executar SQL
        echo "<div class='step'>";
        echo "<h3>🗄️ Passo 1: Executar SQL do Sistema</h3>";
        echo "<div class='step-result'>";
        
        $sql_file = __DIR__ . '/../sql/017_agenda_interna.sql';
        
        if (!file_exists($sql_file)) {
            echo "<p class='error'>❌ Arquivo SQL não encontrado: {$sql_file}</p>";
            return;
        }
        
        $sql_content = file_get_contents($sql_file);
        
        if (empty($sql_content)) {
            echo "<p class='error'>❌ Arquivo SQL está vazio</p>";
            return;
        }
        
        echo "<p>📋 Carregando arquivo SQL...</p>";
        echo "<p><strong>Arquivo:</strong> {$sql_file}</p>";
        echo "<p><strong>Tamanho:</strong> " . strlen($sql_content) . " bytes</p>";
        
        // Dividir em comandos
        $commands = explode(';', $sql_content);
        $total_commands = 0;
        $executed_commands = 0;
        $errors = 0;
        
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
        
        // Passo 2: Verificar tabelas criadas
        echo "<div class='step'>";
        echo "<h3>📊 Passo 2: Verificar Tabelas Criadas</h3>";
        echo "<div class='step-result'>";
        
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
        
        // Passo 3: Verificar funções criadas
        echo "<div class='step'>";
        echo "<h3>⚙️ Passo 3: Verificar Funções Criadas</h3>";
        echo "<div class='step-result'>";
        
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
        
        // Passo 4: Verificar espaços inseridos
        echo "<div class='step'>";
        echo "<h3>🏢 Passo 4: Verificar Espaços</h3>";
        echo "<div class='step-result'>";
        
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
        
        // Passo 5: Configurar permissões de usuários
        echo "<div class='step'>";
        echo "<h3>👥 Passo 5: Configurar Permissões de Usuários</h3>";
        echo "<div class='step-result'>";
        
        try {
            $stmt = $pdo->query("SELECT id, nome, perfil FROM usuarios WHERE ativo = TRUE");
            $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $permissoes_configuradas = 0;
            foreach ($usuarios as $usuario) {
                $perm_agenda_ver = true;
                $perm_agenda_meus = true;
                $perm_agenda_relatorios = in_array($usuario['perfil'], ['ADM', 'GERENTE']);
                $perm_gerir_eventos_outros = $usuario['perfil'] === 'ADM';
                $perm_forcar_conflito = $usuario['perfil'] === 'ADM';
                
                $stmt = $pdo->prepare("
                    UPDATE usuarios SET 
                        perm_agenda_ver = ?,
                        perm_agenda_meus = ?,
                        perm_agenda_relatorios = ?,
                        perm_gerir_eventos_outros = ?,
                        perm_forcar_conflito = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $perm_agenda_ver,
                    $perm_agenda_meus,
                    $perm_agenda_relatorios,
                    $perm_gerir_eventos_outros,
                    $perm_forcar_conflito,
                    $usuario['id']
                ]);
                $permissoes_configuradas++;
            }
            
            echo "<p class='success'>✅ Permissões configuradas para {$permissoes_configuradas} usuários</p>";
            
            // Mostrar resumo das permissões
            echo "<table border='1' style='border-collapse: collapse; width: 100%; margin-top: 10px;'>";
            echo "<tr style='background: #f3f4f6;'>";
            echo "<th style='padding: 10px;'>Perfil</th>";
            echo "<th style='padding: 10px;'>Ver Agenda</th>";
            echo "<th style='padding: 10px;'>Criar Eventos</th>";
            echo "<th style='padding: 10px;'>Relatórios</th>";
            echo "<th style='padding: 10px;'>Gerenciar Outros</th>";
            echo "<th style='padding: 10px;'>Forçar Conflito</th>";
            echo "</tr>";
            
            $perfis = ['ADM', 'GERENTE', 'OPER', 'CONSULTA'];
            foreach ($perfis as $perfil) {
                $perm_agenda_ver = true;
                $perm_agenda_meus = true;
                $perm_agenda_relatorios = in_array($perfil, ['ADM', 'GERENTE']);
                $perm_gerir_eventos_outros = $perfil === 'ADM';
                $perm_forcar_conflito = $perfil === 'ADM';
                
                echo "<tr>";
                echo "<td style='padding: 10px;'><strong>{$perfil}</strong></td>";
                echo "<td style='padding: 10px;'>" . ($perm_agenda_ver ? '✅' : '❌') . "</td>";
                echo "<td style='padding: 10px;'>" . ($perm_agenda_meus ? '✅' : '❌') . "</td>";
                echo "<td style='padding: 10px;'>" . ($perm_agenda_relatorios ? '✅' : '❌') . "</td>";
                echo "<td style='padding: 10px;'>" . ($perm_gerir_eventos_outros ? '✅' : '❌') . "</td>";
                echo "<td style='padding: 10px;'>" . ($perm_forcar_conflito ? '✅' : '❌') . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        } catch (Exception $e) {
            echo "<p class='error'>❌ Erro ao configurar permissões: " . $e->getMessage() . "</p>";
        }
        echo "</div></div>";
        
        // Resultado final
        $percentage = round(($tabelas_ok / count($tabelas_agenda)) * 100);
        
        echo "<div class='section success-bg'>";
        echo "<h2>🎉 Sistema de Agenda Configurado!</h2>";
        
        if ($percentage >= 90) {
            echo "<p class='success'>🎉 Excelente! O sistema de agenda foi configurado com sucesso.</p>";
        } elseif ($percentage >= 70) {
            echo "<p class='warning'>⚠️ Bom! O sistema foi configurado com alguns problemas menores.</p>";
        } else {
            echo "<p class='error'>❌ Atenção! O sistema precisa de correções.</p>";
        }
        
        echo "<h3>📋 Resumo da Configuração:</h3>";
        echo "<ul>";
        echo "<li>✅ <strong>Comandos SQL:</strong> {$executed_commands}/{$total_commands} executados</li>";
        echo "<li>✅ <strong>Tabelas:</strong> {$tabelas_ok}/" . count($tabelas_agenda) . " funcionando</li>";
        echo "<li>✅ <strong>Funções:</strong> {$funcoes_ok}/" . count($funcoes) . " funcionando</li>";
        echo "<li>✅ <strong>Espaços:</strong> " . count($espacos) . " configurados</li>";
        echo "<li>✅ <strong>Permissões:</strong> {$permissoes_configuradas} usuários configurados</li>";
        echo "<li>❌ <strong>Erros:</strong> {$errors} encontrados</li>";
        echo "</ul>";
        
        echo "<h3>🚀 Próximos Passos:</h3>";
        echo "<ol>";
        echo "<li>Acesse a agenda: <a href='index.php?page=agenda'>index.php?page=agenda</a></li>";
        echo "<li>Configure suas preferências: <a href='index.php?page=agenda_config'>index.php?page=agenda_config</a></li>";
        echo "<li>Veja os relatórios: <a href='index.php?page=agenda_relatorios'>index.php?page=agenda_relatorios</a></li>";
        echo "<li>Teste o sistema criando alguns eventos</li>";
        echo "</ol>";
        
        echo "<h3>📚 Funcionalidades Disponíveis:</h3>";
        echo "<ul>";
        echo "<li>📅 <strong>Agenda Interna:</strong> Calendário com eventos e visitas</li>";
        echo "<li>🏢 <strong>Espaços:</strong> Garden, Diverkids, Cristal, Lisbon</li>";
        echo "<li>👤 <strong>Visitas:</strong> Agendamento de clientes</li>";
        echo "<li>🔒 <strong>Bloqueios:</strong> Bloqueio de horários pessoais</li>";
        echo "<li>🔔 <strong>Lembretes:</strong> Notificações por e-mail</li>";
        echo "<li>📱 <strong>Sincronização:</strong> Exportação ICS para calendários externos</li>";
        echo "<li>📊 <strong>Relatórios:</strong> Conversão de visitas em contratos</li>";
        echo "<li>🔐 <strong>Permissões:</strong> Controle granular por perfil</li>";
        echo "<li>⚡ <strong>Conflitos:</strong> Detecção automática de sobreposições</li>";
        echo "<li>🎨 <strong>Cores:</strong> Identificação visual por usuário</li>";
        echo "</ul>";
        echo "</div>";
        
    } catch (Exception $e) {
        echo "<div class='section error-bg'>";
        echo "<h2>❌ Erro Geral</h2>";
        echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
        echo "</div>";
    }
}

// Executar configuração
setupAgenda();
?>
