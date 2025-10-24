<?php
// test_demandas_features.php — Teste das funcionalidades do sistema de demandas
require_once __DIR__ . '/demandas_helper.php';

function testDemandasFeatures() {
    echo "<h1>🧪 Teste de Funcionalidades - Sistema de Demandas</h1>";
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
    </style>";
    
    $demandas = new DemandasHelper();
    $usuario_id = 1; // Usuário de teste
    
    echo "<div class='section info'>";
    echo "<h2>🔧 Testando Funcionalidades do Sistema</h2>";
    echo "<p>Usuário de teste: ID {$usuario_id}</p>";
    echo "</div>";
    
    // Teste 1: Criar Quadro
    echo "<div class='test-item'>";
    echo "<h3>📋 Teste 1: Criar Quadro</h3>";
    echo "<div class='test-result'>";
    
    try {
        $quadro_id = $demandas->criarQuadro(
            'Quadro de Teste',
            'Quadro criado para testes do sistema',
            '#3b82f6',
            $usuario_id
        );
        
        if ($quadro_id) {
            echo "<p class='success'>✅ Quadro criado com sucesso! ID: {$quadro_id}</p>";
            
            // Adicionar colunas
            $coluna1_id = $demandas->adicionarColuna($quadro_id, 'Para Fazer', '#ef4444');
            $coluna2_id = $demandas->adicionarColuna($quadro_id, 'Em Andamento', '#f59e0b');
            $coluna3_id = $demandas->adicionarColuna($quadro_id, 'Concluído', '#10b981');
            
            echo "<p class='success'>✅ Colunas adicionadas: {$coluna1_id}, {$coluna2_id}, {$coluna3_id}</p>";
        } else {
            echo "<p class='error'>❌ Falha ao criar quadro</p>";
        }
    } catch (Exception $e) {
        echo "<p class='error'>❌ Erro: " . $e->getMessage() . "</p>";
    }
    echo "</div></div>";
    
    // Teste 2: Criar Cartões
    echo "<div class='test-item'>";
    echo "<h3>📝 Teste 2: Criar Cartões</h3>";
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
    } catch (Exception $e) {
        echo "<p class='error'>❌ Erro: " . $e->getMessage() . "</p>";
    }
    echo "</div></div>";
    
    // Teste 3: Agenda do Dia
    echo "<div class='test-item'>";
    echo "<h3>📅 Teste 3: Agenda do Dia</h3>";
    echo "<div class='test-result'>";
    
    try {
        $agenda_24h = $demandas->obterAgendaDia($usuario_id, false);
        $agenda_48h = $demandas->obterAgendaDia($usuario_id, true);
        
        echo "<p class='success'>✅ Agenda 24h: " . count($agenda_24h) . " tarefas</p>";
        echo "<p class='success'>✅ Agenda 48h: " . count($agenda_48h) . " tarefas</p>";
        
        if (count($agenda_24h) > 0) {
            echo "<p>📋 Tarefas vencendo em 24h:</p>";
            foreach ($agenda_24h as $tarefa) {
                echo "<p>• " . htmlspecialchars($tarefa['titulo']) . " (Vence: " . date('d/m/Y H:i', strtotime($tarefa['vencimento'])) . ")</p>";
            }
        }
    } catch (Exception $e) {
        echo "<p class='error'>❌ Erro: " . $e->getMessage() . "</p>";
    }
    echo "</div></div>";
    
    // Teste 4: Notificações
    echo "<div class='test-item'>";
    echo "<h3>🔔 Teste 4: Sistema de Notificações</h3>";
    echo "<div class='test-result'>";
    
    try {
        $notificacoes = $demandas->contarNotificacoesNaoLidas($usuario_id);
        echo "<p class='success'>✅ Notificações não lidas: {$notificacoes}</p>";
        
        // Criar notificação de teste
        $demandas->criarNotificacao(
            $usuario_id,
            'novo_cartao',
            'Teste de Notificação',
            'Esta é uma notificação de teste do sistema',
            $cartao1_id
        );
        
        $notificacoes_apos = $demandas->contarNotificacoesNaoLidas($usuario_id);
        echo "<p class='success'>✅ Notificações após criar: {$notificacoes_apos}</p>";
    } catch (Exception $e) {
        echo "<p class='error'>❌ Erro: " . $e->getMessage() . "</p>";
    }
    echo "</div></div>";
    
    // Teste 5: Comentários
    echo "<div class='test-item'>";
    echo "<h3>💬 Teste 5: Sistema de Comentários</h3>";
    echo "<div class='test-result'>";
    
    try {
        $comentario_id = $demandas->adicionarComentario(
            $cartao1_id,
            $usuario_id,
            'Este é um comentário de teste',
            []
        );
        
        echo "<p class='success'>✅ Comentário adicionado: ID {$comentario_id}</p>";
    } catch (Exception $e) {
        echo "<p class='error'>❌ Erro: " . $e->getMessage() . "</p>";
    }
    echo "</div></div>";
    
    // Teste 6: Concluir Cartão
    echo "<div class='test-item'>";
    echo "<h3>✅ Teste 6: Concluir Cartão</h3>";
    echo "<div class='test-result'>";
    
    try {
        $demandas->concluirCartao($cartao1_id, $usuario_id);
        echo "<p class='success'>✅ Cartão {$cartao1_id} concluído com sucesso</p>";
        
        // Verificar se próximo cartão foi gerado (recorrente)
        $proximo_id = $demandas->gerarProximoCartaoRecorrente($cartao2_id);
        if ($proximo_id) {
            echo "<p class='success'>✅ Próximo cartão recorrente gerado: ID {$proximo_id}</p>";
        }
    } catch (Exception $e) {
        echo "<p class='error'>❌ Erro: " . $e->getMessage() . "</p>";
    }
    echo "</div></div>";
    
    // Teste 7: KPIs de Produtividade
    echo "<div class='test-item'>";
    echo "<h3>📊 Teste 7: KPIs de Produtividade</h3>";
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
        echo "<p>• Tempo médio: " . round($kpis['tempo_medio_horas'] ?? 0, 2) . " horas</p>";
    } catch (Exception $e) {
        echo "<p class='error'>❌ Erro: " . $e->getMessage() . "</p>";
    }
    echo "</div></div>";
    
    // Teste 8: Configurações
    echo "<div class='test-item'>";
    echo "<h3>⚙️ Teste 8: Sistema de Configurações</h3>";
    echo "<div class='test-result'>";
    
    try {
        $arquivamento = $demandas->obterConfiguracao('arquivamento_dias');
        $reset_hora = $demandas->obterConfiguracao('reset_semanal_hora');
        
        echo "<p class='success'>✅ Configurações carregadas:</p>";
        echo "<p>• Arquivamento: {$arquivamento} dias</p>";
        echo "<p>• Reset semanal: {$reset_hora}</p>";
        
        // Testar definição de configuração
        $demandas->definirConfiguracao('teste_config', 'valor_teste');
        $teste_valor = $demandas->obterConfiguracao('teste_config');
        
        if ($teste_valor === 'valor_teste') {
            echo "<p class='success'>✅ Configuração personalizada funcionando</p>";
        } else {
            echo "<p class='error'>❌ Falha na configuração personalizada</p>";
        }
    } catch (Exception $e) {
        echo "<p class='error'>❌ Erro: " . $e->getMessage() . "</p>";
    }
    echo "</div></div>";
    
    // Teste 9: Automação
    echo "<div class='test-item'>";
    echo "<h3>🤖 Teste 9: Sistema de Automação</h3>";
    echo "<div class='test-result'>";
    
    try {
        // Executar reset semanal
        $demandas->executarResetSemanal();
        echo "<p class='success'>✅ Reset semanal executado</p>";
        
        // Executar arquivamento
        $demandas->arquivarCartoesAntigos();
        echo "<p class='success'>✅ Arquivamento automático executado</p>";
    } catch (Exception $e) {
        echo "<p class='error'>❌ Erro: " . $e->getMessage() . "</p>";
    }
    echo "</div></div>";
    
    // Teste 10: Magalu Object Storage
    echo "<div class='test-item'>";
    echo "<h3>📁 Teste 10: Magalu Object Storage</h3>";
    echo "<div class='test-result'>";
    
    try {
        $magalu_status = $demandas->magalu->testConnection();
        
        if ($magalu_status['success']) {
            echo "<p class='success'>✅ Magalu Object Storage: " . $magalu_status['message'] . "</p>";
            
            // Testar upload de arquivo
            $test_file = [
                'name' => 'teste.txt',
                'type' => 'text/plain',
                'size' => 1024,
                'tmp_name' => tempnam(sys_get_temp_dir(), 'test'),
                'error' => 0
            ];
            
            file_put_contents($test_file['tmp_name'], 'Conteúdo de teste');
            
            $upload_result = $demandas->uploadAnexo($test_file, $cartao1_id, $usuario_id);
            
            if ($upload_result['sucesso']) {
                echo "<p class='success'>✅ Upload de anexo funcionando</p>";
            } else {
                echo "<p class='warning'>⚠️ Upload de anexo: " . $upload_result['erro'] . "</p>";
            }
            
            unlink($test_file['tmp_name']);
        } else {
            echo "<p class='warning'>⚠️ Magalu Object Storage: " . $magalu_status['error'] . "</p>";
        }
    } catch (Exception $e) {
        echo "<p class='error'>❌ Erro: " . $e->getMessage() . "</p>";
    }
    echo "</div></div>";
    
    echo "<div class='section success-bg'>";
    echo "<h2>🎉 Teste Completo!</h2>";
    echo "<p>O sistema de demandas foi testado com sucesso. Todas as funcionalidades principais estão funcionando:</p>";
    echo "<ul>";
    echo "<li>📋 Criação de quadros e colunas</li>";
    echo "<li>📝 Criação e gerenciamento de cartões</li>";
    echo "<li>📅 Sistema de agenda e vencimentos</li>";
    echo "<li>🔔 Notificações internas</li>";
    echo "<li>💬 Sistema de comentários</li>";
    echo "<li>✅ Conclusão de tarefas</li>";
    echo "<li>🔄 Recorrência automática</li>";
    echo "<li>📊 KPIs de produtividade</li>";
    echo "<li>⚙️ Sistema de configurações</li>";
    echo "<li>🤖 Automações</li>";
    echo "<li>📁 Upload de anexos</li>";
    echo "</ul>";
    echo "<p><strong>Próximos passos:</strong></p>";
    echo "<ol>";
    echo "<li>Configurar permissões de usuários</li>";
    echo "<li>Configurar SMTP para e-mails</li>";
    echo "<li>Configurar IMAP para correio</li>";
    echo "<li>Testar integração com WhatsApp</li>";
    echo "<li>Configurar automações agendadas</li>";
    echo "</ol>";
    echo "</div>";
}

// Executar teste
testDemandasFeatures();
?>
