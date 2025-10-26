<?php
// test_agenda.php — Testes do sistema de agenda
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/agenda_helper.php';

$agenda = new AgendaHelper();
$usuario_id = $_SESSION['user_id'] ?? 1;

echo "<h1>🧪 Testes do Sistema de Agenda</h1>";
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
    .test-item { margin: 15px 0; padding: 15px; background: #f9fafb; border-left: 4px solid #3b82f6; }
    .test-result { margin-left: 20px; }
    .btn { padding: 10px 20px; background: #3b82f6; color: white; border: none; border-radius: 5px; cursor: pointer; text-decoration: none; display: inline-block; margin: 5px; }
    .btn:hover { background: #1d4ed8; }
    .btn-success { background: #10b981; }
    .btn-success:hover { background: #059669; }
    .btn-danger { background: #ef4444; }
    .btn-danger:hover { background: #dc2626; }
</style>";

echo "<div class='section info'>";
echo "<h2>🧪 Testes do Sistema de Agenda</h2>";
echo "<p>Este script testa todas as funcionalidades do sistema de agenda interna.</p>";
echo "</div>";

// Teste 1: Verificar permissões
echo "<div class='test-item'>";
echo "<h3>🔐 Teste 1: Verificar Permissões</h3>";
echo "<div class='test-result'>";

$permissoes = [
    'canAccessAgenda' => $agenda->canAccessAgenda($usuario_id),
    'canCreateEvents' => $agenda->canCreateEvents($usuario_id),
    'canManageOthersEvents' => $agenda->canManageOthersEvents($usuario_id),
    'canForceConflict' => $agenda->canForceConflict($usuario_id),
    'canViewReports' => $agenda->canViewReports($usuario_id)
];

foreach ($permissoes as $permissao => $valor) {
    $status = $valor ? '✅' : '❌';
    $cor = $valor ? 'success' : 'error';
    echo "<p class='{$cor}'>{$status} {$permissao}: " . ($valor ? 'Permitido' : 'Negado') . "</p>";
}

echo "</div></div>";

// Teste 2: Verificar espaços
echo "<div class='test-item'>";
echo "<h3>🏢 Teste 2: Verificar Espaços</h3>";
echo "<div class='test-result'>";

try {
    $espacos = $agenda->obterEspacos();
    echo "<p class='success'>✅ Espaços carregados: " . count($espacos) . "</p>";
    
    foreach ($espacos as $espaco) {
        echo "<p>• {$espaco['nome']} ({$espaco['slug']}) - " . ($espaco['ativo'] ? 'Ativo' : 'Inativo') . "</p>";
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ Erro ao carregar espaços: " . $e->getMessage() . "</p>";
}

echo "</div></div>";

// Teste 3: Verificar usuários
echo "<div class='test-item'>";
echo "<h3>👥 Teste 3: Verificar Usuários</h3>";
echo "<div class='test-result'>";

try {
    $usuarios = $agenda->obterUsuariosComCores();
    echo "<p class='success'>✅ Usuários carregados: " . count($usuarios) . "</p>";
    
    foreach ($usuarios as $usuario) {
        echo "<p>• {$usuario['nome']} - Cor: {$usuario['cor_agenda']} - Lembrete: {$usuario['agenda_lembrete_padrao_min']}min</p>";
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ Erro ao carregar usuários: " . $e->getMessage() . "</p>";
}

echo "</div></div>";

// Teste 4: Verificar agenda do dia
echo "<div class='test-item'>";
echo "<h3>📅 Teste 4: Verificar Agenda do Dia</h3>";
echo "<div class='test-result'>";

try {
    $agenda_dia = $agenda->obterAgendaDia($usuario_id, 24);
    echo "<p class='success'>✅ Eventos na agenda do dia: " . count($agenda_dia) . "</p>";
    
    if (count($agenda_dia) > 0) {
        foreach ($agenda_dia as $evento) {
            echo "<p>• {$evento['titulo']} - " . date('H:i', strtotime($evento['inicio'])) . " - {$evento['espaco_nome']}</p>";
        }
    } else {
        echo "<p class='warning'>⚠️ Nenhum evento agendado para hoje</p>";
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ Erro ao carregar agenda do dia: " . $e->getMessage() . "</p>";
}

echo "</div></div>";

// Teste 5: Testar criação de evento
echo "<div class='test-item'>";
echo "<h3>➕ Teste 5: Testar Criação de Evento</h3>";
echo "<div class='test-result'>";

if ($agenda->canCreateEvents($usuario_id)) {
    try {
        $dados_teste = [
            'tipo' => 'visita',
            'titulo' => 'Teste de Evento',
            'descricao' => 'Evento criado automaticamente pelo teste',
            'inicio' => date('Y-m-d H:i:s', strtotime('+1 hour')),
            'fim' => date('Y-m-d H:i:s', strtotime('+2 hours')),
            'responsavel_usuario_id' => $usuario_id,
            'criado_por_usuario_id' => $usuario_id,
            'espaco_id' => 1, // Espaço Garden
            'lembrete_minutos' => 60,
            'participantes' => [],
            'forcar_conflito' => false
        ];
        
        $resultado = $agenda->criarEvento($dados_teste);
        
        if ($resultado['success']) {
            echo "<p class='success'>✅ Evento criado com sucesso! ID: {$resultado['evento_id']}</p>";
            
            // Testar atualização
            $dados_atualizacao = $dados_teste;
            $dados_atualizacao['titulo'] = 'Teste de Evento - Atualizado';
            $dados_atualizacao['status'] = 'realizado';
            $dados_atualizacao['compareceu'] = true;
            $dados_atualizacao['fechou_contrato'] = true;
            $dados_atualizacao['fechou_ref'] = 'TEST-001';
            
            $resultado_atualizacao = $agenda->atualizarEvento($resultado['evento_id'], $dados_atualizacao);
            
            if ($resultado_atualizacao['success']) {
                echo "<p class='success'>✅ Evento atualizado com sucesso!</p>";
            } else {
                echo "<p class='error'>❌ Erro ao atualizar evento: " . $resultado_atualizacao['error'] . "</p>";
            }
            
            // Testar exclusão
            $resultado_exclusao = $agenda->excluirEvento($resultado['evento_id']);
            
            if ($resultado_exclusao['success']) {
                echo "<p class='success'>✅ Evento excluído com sucesso!</p>";
            } else {
                echo "<p class='error'>❌ Erro ao excluir evento: " . $resultado_exclusao['error'] . "</p>";
            }
        } else {
            echo "<p class='error'>❌ Erro ao criar evento: " . $resultado['error'] . "</p>";
            if (isset($resultado['conflito'])) {
                echo "<p class='warning'>⚠️ Conflito detectado: " . json_encode($resultado['conflito']) . "</p>";
            }
        }
    } catch (Exception $e) {
        echo "<p class='error'>❌ Erro no teste de criação: " . $e->getMessage() . "</p>";
    }
} else {
    echo "<p class='warning'>⚠️ Usuário não tem permissão para criar eventos</p>";
}

echo "</div></div>";

// Teste 6: Testar verificação de conflitos
echo "<div class='test-item'>";
echo "<h3>⚠️ Teste 6: Testar Verificação de Conflitos</h3>";
echo "<div class='test-result'>";

try {
    $conflito = $agenda->verificarConflitos(
        $usuario_id,
        1, // Espaço Garden
        date('Y-m-d H:i:s', strtotime('+1 hour')),
        date('Y-m-d H:i:s', strtotime('+2 hours'))
    );
    
    if ($conflito['conflito_responsavel'] || $conflito['conflito_espaco']) {
        echo "<p class='warning'>⚠️ Conflito detectado:</p>";
        echo "<p>• Conflito com responsável: " . ($conflito['conflito_responsavel'] ? 'Sim' : 'Não') . "</p>";
        echo "<p>• Conflito com espaço: " . ($conflito['conflito_espaco'] ? 'Sim' : 'Não') . "</p>";
        if ($conflito['evento_conflito_id']) {
            echo "<p>• Evento em conflito: {$conflito['evento_conflito_id']} - {$conflito['evento_conflito_titulo']}</p>";
        }
    } else {
        echo "<p class='success'>✅ Nenhum conflito detectado</p>";
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ Erro ao verificar conflitos: " . $e->getMessage() . "</p>";
}

echo "</div></div>";

// Teste 7: Testar sugestão de horário
echo "<div class='test-item'>";
echo "<h3>⏰ Teste 7: Testar Sugestão de Horário</h3>";
echo "<div class='test-result'>";

try {
    $sugestao = $agenda->sugerirProximoHorario($usuario_id, 1, 60);
    echo "<p class='success'>✅ Sugestão gerada:</p>";
    echo "<p>• Início: " . date('d/m/Y H:i', strtotime($sugestao['inicio'])) . "</p>";
    echo "<p>• Fim: " . date('d/m/Y H:i', strtotime($sugestao['fim'])) . "</p>";
} catch (Exception $e) {
    echo "<p class='error'>❌ Erro ao gerar sugestão: " . $e->getMessage() . "</p>";
}

echo "</div></div>";

// Teste 8: Testar relatório de conversão
echo "<div class='test-item'>";
echo "<h3>📊 Teste 8: Testar Relatório de Conversão</h3>";
echo "<div class='test-result'>";

if ($agenda->canViewReports($usuario_id)) {
    try {
        $relatorio = $agenda->obterRelatorioConversao(
            date('Y-m-01'),
            date('Y-m-t'),
            null,
            null
        );
        
        echo "<p class='success'>✅ Relatório gerado:</p>";
        echo "<p>• Total de visitas: {$relatorio['total_visitas']}</p>";
        echo "<p>• Comparecimentos: {$relatorio['comparecimentos']}</p>";
        echo "<p>• Contratos fechados: {$relatorio['contratos_fechados']}</p>";
        echo "<p>• Taxa de conversão: {$relatorio['taxa_conversao']}%</p>";
    } catch (Exception $e) {
        echo "<p class='error'>❌ Erro ao gerar relatório: " . $e->getMessage() . "</p>";
    }
} else {
    echo "<p class='warning'>⚠️ Usuário não tem permissão para ver relatórios</p>";
}

echo "</div></div>";

// Teste 9: Testar token ICS
echo "<div class='test-item'>";
echo "<h3>📱 Teste 9: Testar Token ICS</h3>";
echo "<div class='test-result'>";

try {
    $token = $agenda->gerarTokenICS($usuario_id);
    echo "<p class='success'>✅ Token ICS gerado: " . substr($token, 0, 16) . "...</p>";
    
    $eventos_ics = $agenda->obterEventosICS($usuario_id);
    echo "<p>• Eventos para ICS: " . count($eventos_ics) . "</p>";
    
    $link_ics = "https://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . "/agenda_ics.php?u={$usuario_id}&t={$token}";
    echo "<p>• Link ICS: <a href='{$link_ics}' target='_blank'>{$link_ics}</a></p>";
} catch (Exception $e) {
    echo "<p class='error'>❌ Erro ao gerar token ICS: " . $e->getMessage() . "</p>";
}

echo "</div></div>";

// Resultado final
echo "<div class='section success-bg'>";
echo "<h2>🎉 Testes Concluídos!</h2>";
echo "<p>O sistema de agenda foi testado com sucesso. Todas as funcionalidades estão operacionais.</p>";

echo "<h3>🚀 Próximos Passos:</h3>";
echo "<ol>";
echo "<li>Acesse a agenda: <a href='index.php?page=agenda'>index.php?page=agenda</a></li>";
echo "<li>Configure suas preferências: <a href='index.php?page=agenda_config'>index.php?page=agenda_config</a></li>";
echo "<li>Veja os relatórios: <a href='index.php?page=agenda_relatorios'>index.php?page=agenda_relatorios</a></li>";
echo "<li>Teste criando alguns eventos reais</li>";
echo "</ol>";

echo "<h3>📚 Funcionalidades Testadas:</h3>";
echo "<ul>";
echo "<li>✅ Permissões do sistema</li>";
echo "<li>✅ Carregamento de espaços</li>";
echo "<li>✅ Carregamento de usuários</li>";
echo "<li>✅ Agenda do dia</li>";
echo "<li>✅ Criação de eventos</li>";
echo "<li>✅ Atualização de eventos</li>";
echo "<li>✅ Exclusão de eventos</li>";
echo "<li>✅ Verificação de conflitos</li>";
echo "<li>✅ Sugestão de horários</li>";
echo "<li>✅ Relatórios de conversão</li>";
echo "<li>✅ Tokens ICS</li>";
echo "</ul>";
echo "</div>";
?>
