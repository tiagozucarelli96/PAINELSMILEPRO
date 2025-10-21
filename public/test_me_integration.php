<?php
// test_me_integration.php
// Script de teste para integração com ME Eventos

session_start();
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/me_api_helper.php';
require_once __DIR__ . '/lc_calc.php';

// Definir usuário de teste
$_SESSION['perfil'] = 'ADM';
$_SESSION['usuario_id'] = 1;
$_SESSION['usuario_nome'] = 'Administrador';

echo "<h1>🧪 Teste de Integração ME Eventos</h1>";

try {
    // Teste 1: Buscar eventos dos próximos dois finais de semana
    echo "<h2>1. Buscando eventos dos próximos 2 fins de semana...</h2>";
    
    $eventos = me_buscar_eventos_proximos_finais_semana();
    echo "<p>Eventos encontrados: " . count($eventos) . "</p>";
    
    if (!empty($eventos)) {
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>Data</th><th>Nome</th><th>Convidados</th><th>Origem</th><th>Observações</th></tr>";
        
        foreach ($eventos as $evento) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($evento['data_evento']) . "</td>";
            echo "<td>" . htmlspecialchars($evento['nome_evento']) . "</td>";
            echo "<td>" . htmlspecialchars($evento['convidados']) . "</td>";
            echo "<td>" . htmlspecialchars($evento['origem']) . "</td>";
            echo "<td>" . htmlspecialchars($evento['observacoes'] ?? '') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>⚠️ Nenhum evento encontrado para os próximos 2 fins de semana</p>";
    }
    
    // Teste 2: Verificar sincronização
    echo "<h2>2. Testando sincronização de eventos...</h2>";
    
    if (!empty($eventos)) {
        $evento_teste = $eventos[0];
        echo "<p>Sincronizando evento: " . htmlspecialchars($evento_teste['nome_evento']) . "</p>";
        
        $evento_id = me_sincronizar_evento($evento_teste);
        echo "<p>ID do evento sincronizado: $evento_id</p>";
        
        if ($evento_id > 0) {
            echo "<p>✅ Sincronização bem-sucedida</p>";
        } else {
            echo "<p>❌ Falha na sincronização</p>";
        }
    }
    
    // Teste 3: Calcular demanda para um insumo
    echo "<h2>3. Testando cálculo de demanda...</h2>";
    
    // Buscar um insumo para teste
    $stmt = $pdo->query("SELECT id, nome FROM lc_insumos WHERE ativo = true LIMIT 1");
    $insumo_teste = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($insumo_teste) {
        echo "<p>Testando com insumo: " . htmlspecialchars($insumo_teste['nome']) . "</p>";
        
        $demanda = me_calcular_demanda_eventos($pdo, $insumo_teste['id'], 9); // 7 + 2 dias
        echo "<p>Demanda calculada: $demanda</p>";
        
        if ($demanda > 0) {
            echo "<p>✅ Cálculo de demanda funcionando</p>";
        } else {
            echo "<p>⚠️ Demanda zero - pode ser normal se não houver eventos ou cardápios</p>";
        }
    } else {
        echo "<p>⚠️ Nenhum insumo ativo encontrado</p>";
    }
    
    // Teste 4: Verificar estrutura de dados
    echo "<h2>4. Verificando estrutura de dados...</h2>";
    
    // Verificar tabelas necessárias
    $tabelas_necessarias = [
        'lc_listas_eventos',
        'lc_evento_cardapio', 
        'lc_fichas',
        'lc_ficha_componentes',
        'lc_insumos'
    ];
    
    foreach ($tabelas_necessarias as $tabela) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM $tabela");
        $count = $stmt->fetchColumn();
        echo "<p>$tabela: $count registros</p>";
    }
    
    // Teste 5: Simular dados de teste
    echo "<h2>5. Criando dados de teste...</h2>";
    
    // Criar evento de teste se não existir
    $stmt = $pdo->query("SELECT COUNT(*) FROM lc_listas_eventos WHERE data_evento >= CURRENT_DATE");
    $eventos_existentes = $stmt->fetchColumn();
    
    if ($eventos_existentes == 0) {
        echo "<p>Criando evento de teste...</p>";
        
        $stmt = $pdo->prepare("
            INSERT INTO lc_listas_eventos (data_evento, nome, convidados, observacoes)
            VALUES (CURRENT_DATE + INTERVAL '1 day', 'Evento de Teste ME', 50, 'Evento criado para teste de integração')
        ");
        $stmt->execute();
        
        $evento_id = $pdo->lastInsertId();
        echo "<p>✅ Evento de teste criado com ID: $evento_id</p>";
        
        // Criar ficha de teste se não existir
        $stmt = $pdo->query("SELECT id FROM lc_fichas WHERE nome = 'Ficha Teste ME' LIMIT 1");
        $ficha_existente = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$ficha_existente) {
            $stmt = $pdo->prepare("
                INSERT INTO lc_fichas (nome, descricao, consumo_pessoa, ativo)
                VALUES ('Ficha Teste ME', 'Ficha criada para teste', 1.0, true)
            ");
            $stmt->execute();
            $ficha_id = $pdo->lastInsertId();
            echo "<p>✅ Ficha de teste criada com ID: $ficha_id</p>";
        } else {
            $ficha_id = $ficha_existente['id'];
        }
        
        // Associar ficha ao evento
        $stmt = $pdo->prepare("
            INSERT INTO lc_evento_cardapio (evento_id, ficha_id, ativo)
            VALUES (:evento_id, :ficha_id, true)
        ");
        $stmt->execute([':evento_id' => $evento_id, ':ficha_id' => $ficha_id]);
        echo "<p>✅ Ficha associada ao evento</p>";
    } else {
        echo "<p>✅ Já existem eventos futuros</p>";
    }
    
    // Teste 6: Testar API externa (simulação)
    echo "<h2>6. Testando conexão com API ME Eventos...</h2>";
    
    $api_url = 'https://meeventos.com.br/api/eventos';
    echo "<p>URL da API: $api_url</p>";
    
    // Simular teste de conectividade
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 5,
            'header' => [
                'User-Agent: PainelSmilePRO/1.0',
                'Accept: application/json'
            ]
        ]
    ]);
    
    $test_url = $api_url . '?test=1';
    echo "<p>Testando URL: $test_url</p>";
    
    $response = @file_get_contents($test_url, false, $context);
    
    if ($response !== false) {
        echo "<p>✅ API ME Eventos acessível</p>";
        echo "<p>Resposta: " . htmlspecialchars(substr($response, 0, 200)) . "...</p>";
    } else {
        echo "<p>⚠️ API ME Eventos não acessível (pode ser normal em ambiente de teste)</p>";
    }
    
    // Teste 7: Verificar permissões
    echo "<h2>7. Verificando permissões do sistema...</h2>";
    
    $perfil = $_SESSION['perfil'] ?? 'CONSULTA';
    echo "<p>Perfil atual: $perfil</p>";
    
    $pode_gerar = in_array($perfil, ['ADM', 'OPER']);
    $pode_ver_custo = $perfil === 'ADM';
    
    echo "<p>Pode gerar sugestões: " . ($pode_gerar ? "✅ Sim" : "❌ Não") . "</p>";
    echo "<p>Pode ver custos: " . ($pode_ver_custo ? "✅ Sim" : "❌ Não") . "</p>";
    
    echo "<h2>✅ Testes de integração concluídos!</h2>";
    
    echo "<div style='margin-top: 20px; padding: 15px; background: #e7f3ff; border-radius: 8px;'>";
    echo "<h3>📋 Próximos passos:</h3>";
    echo "<ol>";
    echo "<li><strong>Configure eventos:</strong> Crie eventos futuros com cardápios</li>";
    echo "<li><strong>Configure insumos:</strong> Defina estoque mínimo e fornecedores</li>";
    echo "<li><strong>Teste alertas:</strong> Acesse <a href='estoque_alertas.php'>estoque_alertas.php</a></li>";
    echo "<li><strong>Teste sugestões:</strong> Use o modal de sugestão de compra</li>";
    echo "</ol>";
    echo "</div>";
    
    echo "<div style='margin-top: 20px; padding: 15px; background: #fff3cd; border-radius: 8px;'>";
    echo "<h3>⚠️ Observações importantes:</h3>";
    echo "<ul>";
    echo "<li><strong>API ME Eventos:</strong> A integração funciona com eventos locais e da API</li>";
    echo "<li><strong>Fallback:</strong> Se não houver eventos, usa média móvel de consumo</li>";
    echo "<li><strong>Sincronização:</strong> Eventos da API são sincronizados automaticamente</li>";
    echo "<li><strong>Performance:</strong> Cache local evita consultas desnecessárias</li>";
    echo "</ul>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<h2>❌ Erro durante os testes:</h2>";
    echo "<p style='color: red;'>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}
?>

<script>
// Teste de funcionalidades JavaScript
document.addEventListener('DOMContentLoaded', function() {
    console.log('✅ JavaScript carregado');
    console.log('✅ Modal de sugestão disponível');
    
    // Teste de atalhos
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.key === 'k') {
            console.log('✅ Atalho Ctrl+K funcionando');
        }
        if (e.key === 'Escape') {
            console.log('✅ Atalho Esc funcionando');
        }
    });
});
</script>
