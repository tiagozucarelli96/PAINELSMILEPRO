<?php
/**
 * Script de teste para o cron de demandas fixas
 * Execute: php test_cron_fixas.php
 * OU acesse via web: http://localhost:8000/test_cron_fixas.php
 */

// Simular ambiente de produção
$_GET['token'] = getenv('CRON_TOKEN') ?: 'test_token';
$_SERVER['REQUEST_METHOD'] = 'GET';

echo "🧪 Testando Cron de Demandas Fixas\n";
echo "===================================\n\n";

// Verificar timezone
date_default_timezone_set('America/Sao_Paulo');
echo "🕐 Timezone configurado: " . date_default_timezone_get() . "\n";
echo "📅 Data/Hora atual: " . date('Y-m-d H:i:s') . " (Brasília)\n";
echo "📆 Dia da semana: " . date('w') . " (0=domingo, 6=sábado)\n";
echo "📆 Dia do mês: " . date('j') . "\n\n";

// Incluir o script do cron
require_once __DIR__ . '/public/conexao.php';

// Verificar conexão
if (!isset($GLOBALS['pdo'])) {
    echo "❌ Erro: Não foi possível conectar ao banco de dados\n";
    exit(1);
}

echo "✅ Conexão com banco estabelecida\n\n";

// Verificar se tabelas existem
$pdo = $GLOBALS['pdo'];

try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM demandas_fixas");
    $total_fixas = $stmt->fetchColumn();
    echo "📋 Total de demandas fixas cadastradas: $total_fixas\n";
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM demandas_fixas WHERE ativo = TRUE");
    $fixas_ativas = $stmt->fetchColumn();
    echo "✅ Demandas fixas ativas: $fixas_ativas\n\n";
    
    // Listar demandas fixas ativas
    if ($fixas_ativas > 0) {
        echo "📝 Demandas Fixas Ativas:\n";
        echo "-------------------------\n";
        $stmt = $pdo->query("
            SELECT df.*, db.nome as board_nome, dl.nome as lista_nome
            FROM demandas_fixas df
            JOIN demandas_boards db ON db.id = df.board_id
            JOIN demandas_listas dl ON dl.id = df.lista_id
            WHERE df.ativo = TRUE
            ORDER BY df.id
        ");
        
        while ($fixa = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $periodicidade_text = $fixa['periodicidade'];
            if ($fixa['periodicidade'] === 'semanal') {
                $dias = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
                $periodicidade_text .= " (" . $dias[$fixa['dia_semana']] . ")";
            } elseif ($fixa['periodicidade'] === 'mensal') {
                $periodicidade_text .= " (dia " . $fixa['dia_mes'] . ")";
            }
            
            echo "  • ID: {$fixa['id']} | {$fixa['titulo']}\n";
            echo "    Quadro: {$fixa['board_nome']} | Lista: {$fixa['lista_nome']}\n";
            echo "    Periodicidade: $periodicidade_text\n\n";
        }
    }
    
    // Verificar última execução
    echo "📊 Histórico de Gerações:\n";
    echo "-------------------------\n";
    $stmt = $pdo->query("
        SELECT dfl.*, df.titulo
        FROM demandas_fixas_log dfl
        JOIN demandas_fixas df ON df.id = dfl.demanda_fixa_id
        ORDER BY dfl.dia_gerado DESC, dfl.gerado_em DESC
        LIMIT 10
    ");
    
    $ultimas_geracoes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($ultimas_geracoes) > 0) {
        foreach ($ultimas_geracoes as $log) {
            echo "  • {$log['titulo']} - Gerado em: {$log['dia_gerado']}\n";
        }
    } else {
        echo "  (Nenhuma geração registrada ainda)\n";
    }
    
    echo "\n";
    
    // Executar o cron agora
    echo "🚀 Executando cron agora...\n";
    echo "===========================\n\n";
    
    // Capturar output do cron (sem headers)
    ob_start();
    
    // Simular execução direta do cron sem headers HTTP
    $_GET['token'] = getenv('CRON_TOKEN') ?: 'test_token';
    
    // Recriar o contexto do cron manualmente
    $cron_token = getenv('CRON_TOKEN') ?: '';
    $request_token = $_GET['token'] ?? '';
    
    if (!empty($cron_token) && $request_token !== $cron_token) {
        echo json_encode(['error' => 'Token inválido']) . "\n";
        ob_end_flush();
        exit;
    }
    
    // Executar lógica do cron (sem header HTTP)
    try {
        $hoje = new DateTime();
        $dia_semana = (int)$hoje->format('w');
        $dia_mes = (int)$hoje->format('j');
        
        $stmt = $pdo->query("
            SELECT df.*, db.nome as board_nome, dl.nome as lista_nome
            FROM demandas_fixas df
            JOIN demandas_boards db ON db.id = df.board_id
            JOIN demandas_listas dl ON dl.id = df.lista_id
            WHERE df.ativo = TRUE
        ");
        $fixas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $gerados = 0;
        $erros = [];
        
        foreach ($fixas as $fixa) {
            $deve_gerar = false;
            
            switch ($fixa['periodicidade']) {
                case 'diaria':
                    $deve_gerar = true;
                    break;
                case 'semanal':
                    if ($fixa['dia_semana'] === $dia_semana) {
                        $deve_gerar = true;
                    }
                    break;
                case 'mensal':
                    if ($fixa['dia_mes'] === $dia_mes) {
                        $deve_gerar = true;
                    }
                    break;
            }
            
            if (!$deve_gerar) continue;
            
            $stmt_check = $pdo->prepare("
                SELECT id FROM demandas_fixas_log 
                WHERE demanda_fixa_id = :fixa_id 
                AND dia_gerado = CURRENT_DATE
            ");
            $stmt_check->execute([':fixa_id' => $fixa['id']]);
            
            if ($stmt_check->fetch()) {
                continue;
            }
            
            $stmt_pos = $pdo->prepare("
                SELECT COALESCE(MAX(posicao), 0) + 1 as nova_pos 
                FROM demandas_cards 
                WHERE lista_id = :lista_id
            ");
            $stmt_pos->execute([':lista_id' => $fixa['lista_id']]);
            $posicao = (int)$stmt_pos->fetch(PDO::FETCH_ASSOC)['nova_pos'];
            
            try {
                $pdo->beginTransaction();
                
                $stmt_card = $pdo->prepare("
                    INSERT INTO demandas_cards 
                    (lista_id, titulo, descricao, status, prioridade, posicao, criador_id)
                    VALUES (:lista_id, :titulo, :descricao, 'pendente', 'media', :posicao, 1)
                    RETURNING id
                ");
                $stmt_card->execute([
                    ':lista_id' => $fixa['lista_id'],
                    ':titulo' => $fixa['titulo'],
                    ':descricao' => $fixa['descricao'],
                    ':posicao' => $posicao
                ]);
                
                $card = $stmt_card->fetch(PDO::FETCH_ASSOC);
                $card_id = (int)$card['id'];
                
                $stmt_log = $pdo->prepare("
                    INSERT INTO demandas_fixas_log 
                    (demanda_fixa_id, card_id, dia_gerado)
                    VALUES (:fixa_id, :card_id, CURRENT_DATE)
                ");
                $stmt_log->execute([
                    ':fixa_id' => $fixa['id'],
                    ':card_id' => $card_id
                ]);
                
                $pdo->commit();
                $gerados++;
                
                echo "  ✅ Card criado: '{$fixa['titulo']}' (ID: $card_id)\n";
                
            } catch (PDOException $e) {
                $pdo->rollBack();
                $erros[] = [
                    'fixa_id' => $fixa['id'],
                    'titulo' => $fixa['titulo'],
                    'erro' => $e->getMessage()
                ];
                echo "  ❌ Erro ao criar card '{$fixa['titulo']}': {$e->getMessage()}\n";
            }
        }
        
        $resultado = [
            'success' => true,
            'gerados' => $gerados,
            'total_fixas' => count($fixas),
            'erros' => $erros
        ];
        
    } catch (Exception $e) {
        $resultado = [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
    
    $output = ob_get_clean();
    
    // Exibir resultado
    if (isset($resultado)) {
        if ($resultado['success']) {
            echo "\n✅ Resultado Final:\n";
            echo "   Cards gerados: {$resultado['gerados']}\n";
            echo "   Total de fixas verificadas: {$resultado['total_fixas']}\n";
            if (!empty($resultado['erros'])) {
                echo "   ⚠️ Erros: " . count($resultado['erros']) . "\n";
                foreach ($resultado['erros'] as $erro) {
                    echo "      - {$erro['titulo']}: {$erro['erro']}\n";
                }
            }
            if ($resultado['gerados'] === 0 && $resultado['total_fixas'] === 0) {
                echo "\n💡 Não há demandas fixas cadastradas ou ativas.\n";
                echo "   Crie uma demanda fixa na interface primeiro!\n";
            }
        } else {
            echo "\n❌ Erro: " . ($resultado['error'] ?? 'Erro desconhecido') . "\n";
        }
    } else {
        echo $output;
    }
    
} catch (PDOException $e) {
    echo "❌ Erro de banco de dados: " . $e->getMessage() . "\n";
    echo "\n💡 Verifique se as tabelas existem:\n";
    echo "   - demandas_fixas\n";
    echo "   - demandas_fixas_log\n";
    echo "   - demandas_boards\n";
    echo "   - demandas_listas\n";
    echo "   - demandas_cards\n";
    exit(1);
} catch (Exception $e) {
    echo "❌ Erro: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n✅ Teste concluído!\n";

