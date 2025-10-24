<?php
// test_demandas_complete.php — Teste completo do sistema de demandas
require_once __DIR__ . '/demandas_helper.php';

function testDemandasComplete() {
    echo "<h1>🧪 Teste Completo - Sistema de Demandas</h1>";
    echo "<style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .success { color: #10b981; }
        .warning { color: #f59e0b; }
        .error { color: #ef4444; }
        .section { margin: 20px 0; padding: 15px; border-radius: 8px; }
        .info { background: #f0f9ff; border: 1px solid #bae6fd; }
        .success-bg { background: #f0fdf4; border: 1px solid #bbf7d0; }
        .error-bg { background: #fef2f2; border: 1px solid #fecaca; }
        .warning-bg { background: #fffbeb; border: 1px solid #fed7aa; }
        .test-item { margin: 10px 0; padding: 10px; background: #f9fafb; border-left: 4px solid #3b82f6; }
        .test-result { margin-left: 20px; }
        .progress-bar { width: 100%; background: #e5e7eb; border-radius: 10px; overflow: hidden; margin: 10px 0; }
        .progress-fill { height: 20px; background: linear-gradient(90deg, #3b82f6, #10b981); transition: width 0.3s ease; }
        .progress-text { text-align: center; margin-top: 5px; font-weight: 600; }
    </style>";
    
    $demandas = new DemandasHelper();
    $usuario_id = 1;
    $total_tests = 12;
    $passed_tests = 0;
    
    echo "<div class='section info'>";
    echo "<h2>🔧 Iniciando Teste Completo do Sistema</h2>";
    echo "<p>Usuário de teste: ID {$usuario_id}</p>";
    echo "<p>Total de testes: {$total_tests}</p>";
    echo "</div>";
    
    // Teste 1: Verificar conexão
    echo "<div class='test-item'>";
    echo "<h3>🔌 Teste 1: Verificar Conexão</h3>";
    echo "<div class='test-result'>";
    
    try {
        $stmt = $demandas->pdo->query("SELECT 1");
        echo "<p class='success'>✅ Conexão com banco: OK</p>";
        $passed_tests++;
    } catch (Exception $e) {
        echo "<p class='error'>❌ Erro na conexão: " . $e->getMessage() . "</p>";
    }
    echo "</div></div>";
    
    // Teste 2: Verificar tabelas
    echo "<div class='test-item'>";
    echo "<h3>📋 Teste 2: Verificar Tabelas</h3>";
    echo "<div class='test-result'>";
    
    try {
        $tables = ['demandas_quadros', 'demandas_cartoes', 'demandas_notificacoes'];
        $tables_ok = 0;
        
        foreach ($tables as $table) {
            $stmt = $demandas->pdo->query("SELECT COUNT(*) FROM {$table}");
            $count = $stmt->fetchColumn();
            echo "<p class='success'>✅ Tabela {$table}: {$count} registros</p>";
            $tables_ok++;
        }
        
        if ($tables_ok === count($tables)) {
            $passed_tests++;
        }
    } catch (Exception $e) {
        echo "<p class='error'>❌ Erro nas tabelas: " . $e->getMessage() . "</p>";
    }
    echo "</div></div>";
    
    // Teste 3: Criar quadro
    echo "<div class='test-item'>";
    echo "<h3>📋 Teste 3: Criar Quadro</h3>";
    echo "<div class='test-result'>";
    
    try {
        $quadro_id = $demandas->criarQuadro(
            'Quadro de Teste Completo',
            'Quadro criado para teste completo do sistema',
            '#3b82f6',
            $usuario_id
        );
        
        if ($quadro_id) {
            echo "<p class='success'>✅ Quadro criado: ID {$quadro_id}</p>";
            $passed_tests++;
        } else {
            echo "<p class='error'>❌ Falha ao criar quadro</p>";
        }
    } catch (Exception $e) {
        echo "<p class='error'>❌ Erro: " . $e->getMessage() . "</p>";
    }
    echo "</div></div>";
    
    // Teste 4: Adicionar colunas
    echo "<div class='test-item'>";
    echo "<h3>📊 Teste 4: Adicionar Colunas</h3>";
    echo "<div class='test-result'>";
    
    try {
        $coluna1_id = $demandas->adicionarColuna($quadro_id, 'Para Fazer', '#ef4444');
        $coluna2_id = $demandas->adicionarColuna($quadro_id, 'Em Andamento', '#f59e0b');
        $coluna3_id = $demandas->adicionarColuna($quadro_id, 'Concluído', '#10b981');
        
        echo "<p class='success'>✅ Colunas adicionadas: {$coluna1_id}, {$coluna2_id}, {$coluna3_id}</p>";
        $passed_tests++;
    } catch (Exception $e) {
        echo "<p class='error'>❌ Erro: " . $e->getMessage() . "</p>";
    }
    echo "</div></div>";
    
    // Teste 5: Criar cartões
    echo "<div class='test-item'>";
    echo "<h3>📝 Teste 5: Criar Cartões</h3>";
    echo "<div class='test-result'>";
    
    try {
        $cartao1_id = $demandas->criarCartao([
            'quadro_id' => $quadro_id,
            'coluna_id' => $coluna1_id,
            'titulo' => 'Tarefa de Teste 1',
            'descricao' => 'Primeira tarefa de teste',
            'responsavel_id' => $usuario_id,
            'vencimento' => date('Y-m-d H:i:s', strtotime('+1 day')),
            'prioridade' => 'alta',
            'cor' => '#ffffff',
            'criado_por' => $usuario_id
        ]);
        
        $cartao2_id = $demandas->criarCartao([
            'quadro_id' => $quadro_id,
            'coluna_id' => $coluna1_id,
            'titulo' => 'Tarefa Recorrente',
            'descricao' => 'Tarefa que se repete a cada 7 dias',
            'responsavel_id' => $usuario_id,
            'vencimento' => date('Y-m-d H:i:s', strtotime('+1 day')),
            'prioridade' => 'media',
            'cor' => '#ffffff',
            'criado_por' => $usuario_id,
            'recorrente' => true,
            'tipo_recorrencia' => 'semanal',
            'intervalo' => 1
        ]);
        
        echo "<p class='success'>✅ Cartões criados: {$cartao1_id}, {$cartao2_id}</p>";
        $passed_tests++;
    } catch (Exception $e) {
        echo "<p class='error'>❌ Erro: " . $e->getMessage() . "</p>";
    }
    echo "</div></div>";
    
    // Teste 6: Agenda do dia
    echo "<div class='test-item'>";
    echo "<h3>📅 Teste 6: Agenda do Dia</h3>";
    echo "<div class='test-result'>";
    
    try {
        $agenda_24h = $demandas->obterAgendaDia($usuario_id, false);
        $agenda_48h = $demandas->obterAgendaDia($usuario_id, true);
        
        echo "<p class='success'>✅ Agenda 24h: " . count($agenda_24h) . " tarefas</p>";
        echo "<p class='success'>✅ Agenda 48h: " . count($agenda_48h) . " tarefas</p>";
        $passed_tests++;
    } catch (Exception $e) {
        echo "<p class='error'>❌ Erro: " . $e->getMessage() . "</p>";
    }
    echo "</div></div>";
    
    // Teste 7: Notificações
    echo "<div class='test-item'>";
    echo "<h3>🔔 Teste 7: Sistema de Notificações</h3>";
    echo "<div class='test-result'>";
    
    try {
        $notificacoes = $demandas->contarNotificacoesNaoLidas($usuario_id);
        echo "<p class='success'>✅ Notificações não lidas: {$notificacoes}</p>";
        
        $demandas->criarNotificacao(
            $usuario_id,
            'novo_cartao',
            'Teste de Notificação',
            'Esta é uma notificação de teste do sistema',
            $cartao1_id
        );
        
        $notificacoes_apos = $demandas->contarNotificacoesNaoLidas($usuario_id);
        echo "<p class='success'>✅ Notificações após criar: {$notificacoes_apos}</p>";
        $passed_tests++;
    } catch (Exception $e) {
        echo "<p class='error'>❌ Erro: " . $e->getMessage() . "</p>";
    }
    echo "</div></div>";
    
    // Teste 8: Comentários
    echo "<div class='test-item'>";
    echo "<h3>💬 Teste 8: Sistema de Comentários</h3>";
    echo "<div class='test-result'>";
    
    try {
        $comentario_id = $demandas->adicionarComentario(
            $cartao1_id,
            $usuario_id,
            'Este é um comentário de teste',
            []
        );
        
        echo "<p class='success'>✅ Comentário adicionado: ID {$comentario_id}</p>";
        $passed_tests++;
    } catch (Exception $e) {
        echo "<p class='error'>❌ Erro: " . $e->getMessage() . "</p>";
    }
    echo "</div></div>";
    
    // Teste 9: Concluir cartão
    echo "<div class='test-item'>";
    echo "<h3>✅ Teste 9: Concluir Cartão</h3>";
    echo "<div class='test-result'>";
    
    try {
        $demandas->concluirCartao($cartao1_id, $usuario_id);
        echo "<p class='success'>✅ Cartão {$cartao1_id} concluído</p>";
        $passed_tests++;
    } catch (Exception $e) {
        echo "<p class='error'>❌ Erro: " . $e->getMessage() . "</p>";
    }
    echo "</div></div>";
    
    // Teste 10: Recorrência
    echo "<div class='test-item'>";
    echo "<h3>🔄 Teste 10: Sistema de Recorrência</h3>";
    echo "<div class='test-result'>";
    
    try {
        $proximo_id = $demandas->gerarProximoCartaoRecorrente($cartao2_id);
        if ($proximo_id) {
            echo "<p class='success'>✅ Próximo cartão recorrente gerado: ID {$proximo_id}</p>";
            $passed_tests++;
        } else {
            echo "<p class='warning'>⚠️ Nenhum próximo cartão gerado</p>";
        }
    } catch (Exception $e) {
        echo "<p class='error'>❌ Erro: " . $e->getMessage() . "</p>";
    }
    echo "</div></div>";
    
    // Teste 11: KPIs
    echo "<div class='test-item'>";
    echo "<h3>📊 Teste 11: KPIs de Produtividade</h3>";
    echo "<div class='test-result'>";
    
    try {
        $kpis = $demandas->obterKPIsProdutividade(
            $usuario_id,
            date('Y-m-d', strtotime('-30 days')),
            date('Y-m-d')
        );
        
        echo "<p class='success'>✅ KPIs calculados:</p>";
        echo "<p>• Total criados: " . ($kpis['total_criados'] ?? 0) . "</p>";
        echo "<p>• Total concluídos: " . ($kpis['total_concluidos'] ?? 0) . "</p>";
        echo "<p>• No prazo: " . ($kpis['no_prazo'] ?? 0) . "</p>";
        $passed_tests++;
    } catch (Exception $e) {
        echo "<p class='error'>❌ Erro: " . $e->getMessage() . "</p>";
    }
    echo "</div></div>";
    
    // Teste 12: Configurações
    echo "<div class='test-item'>";
    echo "<h3>⚙️ Teste 12: Sistema de Configurações</h3>";
    echo "<div class='test-result'>";
    
    try {
        $arquivamento = $demandas->obterConfiguracao('arquivamento_dias');
        $reset_hora = $demandas->obterConfiguracao('reset_semanal_hora');
        
        echo "<p class='success'>✅ Configurações carregadas:</p>";
        echo "<p>• Arquivamento: {$arquivamento} dias</p>";
        echo "<p>• Reset semanal: {$reset_hora}</p>";
        $passed_tests++;
    } catch (Exception $e) {
        echo "<p class='error'>❌ Erro: " . $e->getMessage() . "</p>";
    }
    echo "</div></div>";
    
    // Resultado final
    $percentage = round(($passed_tests / $total_tests) * 100);
    
    echo "<div class='section success-bg'>";
    echo "<h2>🎉 Resultado do Teste Completo</h2>";
    echo "<div class='progress-bar'>";
    echo "<div class='progress-fill' style='width: {$percentage}%;'></div>";
    echo "</div>";
    echo "<div class='progress-text'>{$passed_tests}/{$total_tests} testes passaram ({$percentage}%)</div>";
    
    if ($percentage >= 90) {
        echo "<p class='success'>🎉 Excelente! O sistema está funcionando perfeitamente.</p>";
    } elseif ($percentage >= 70) {
        echo "<p class='warning'>⚠️ Bom! O sistema está funcionando com alguns problemas menores.</p>";
    } else {
        echo "<p class='error'>❌ Atenção! O sistema precisa de correções.</p>";
    }
    
    echo "<h3>📋 Funcionalidades Testadas:</h3>";
    echo "<ul>";
    echo "<li>✅ Conexão com banco de dados</li>";
    echo "<li>✅ Estrutura de tabelas</li>";
    echo "<li>✅ Criação de quadros</li>";
    echo "<li>✅ Adição de colunas</li>";
    echo "<li>✅ Criação de cartões</li>";
    echo "<li>✅ Sistema de agenda</li>";
    echo "<li>✅ Notificações</li>";
    echo "<li>✅ Comentários</li>";
    echo "<li>✅ Conclusão de tarefas</li>";
    echo "<li>✅ Sistema de recorrência</li>";
    echo "<li>✅ KPIs de produtividade</li>";
    echo "<li>✅ Configurações do sistema</li>";
    echo "</ul>";
    
    echo "<h3>🚀 Próximos Passos:</h3>";
    echo "<ol>";
    echo "<li>Configurar permissões de usuários</li>";
    echo "<li>Configurar SMTP para e-mails</li>";
    echo "<li>Configurar IMAP para correio</li>";
    echo "<li>Testar integração com WhatsApp</li>";
    echo "<li>Configurar automações agendadas</li>";
    echo "<li>Testar upload de anexos</li>";
    echo "<li>Configurar backup automático</li>";
    echo "</ol>";
    echo "</div>";
}

// Executar teste completo
testDemandasComplete();
?>
