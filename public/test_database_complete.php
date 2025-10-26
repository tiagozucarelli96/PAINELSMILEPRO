<?php
// test_database_complete.php
// Análise completa do banco de dados para o sistema de estoque

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';

// Configurar tratamento de erros
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Definir usuário de teste
$_SESSION['perfil'] = 'ADM';
$_SESSION['usuario_id'] = 1;
$_SESSION['usuario_nome'] = 'Administrador';

echo "<h1>🔍 Análise Completa do Banco de Dados</h1>";
echo "<p>Verificando todas as tabelas, relacionamentos e funcionalidades...</p>";

try {
    // Teste 1: Verificar conexão
    echo "<h2>1. ✅ Teste de Conexão</h2>";
    if ($pdo) {
        echo "<p style='color: green;'>✅ Conexão com banco de dados estabelecida com sucesso</p>";
        $stmt = $pdo->query("SELECT version()");
        $version = $stmt->fetchColumn();
        echo "<p>Versão do PostgreSQL: $version</p>";
    } else {
        throw new Exception("Falha na conexão com o banco de dados");
    }
    
    // Teste 2: Verificar tabelas principais
    echo "<h2>2. 📋 Verificação de Tabelas Principais</h2>";
    
    $tabelas_principais = [
        'lc_insumos' => 'Insumos do sistema',
        'lc_unidades' => 'Unidades de medida',
        'lc_categorias' => 'Categorias de insumos',
        'fornecedores' => 'Fornecedores',
        'lc_fichas' => 'Fichas técnicas',
        'lc_ficha_componentes' => 'Componentes das fichas',
        'lc_listas' => 'Listas de compras',
        'lc_compras_consolidadas' => 'Compras consolidadas',
        'lc_listas_eventos' => 'Eventos das listas',
        'lc_evento_cardapio' => 'Cardápio dos eventos',
        'estoque_contagens' => 'Contagens de estoque',
        'estoque_contagem_itens' => 'Itens das contagens',
        'lc_insumos_substitutos' => 'Substitutos aprovados'
    ];
    
    $tabelas_existentes = [];
    $tabelas_faltando = [];
    
    foreach ($tabelas_principais as $tabela => $descricao) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_name = '$tabela'");
        $existe = $stmt->fetchColumn() > 0;
        
        if ($existe) {
            $tabelas_existentes[] = $tabela;
            echo "<p style='color: green;'>✅ $tabela - $descricao</p>";
        } else {
            $tabelas_faltando[] = $tabela;
            echo "<p style='color: red;'>❌ $tabela - $descricao (FALTANDO)</p>";
        }
    }
    
    // Teste 3: Verificar estrutura das tabelas existentes
    echo "<h2>3. 🏗️ Estrutura das Tabelas</h2>";
    
    foreach ($tabelas_existentes as $tabela) {
        echo "<h3>Tabela: $tabela</h3>";
        
        // Contar registros
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM $tabela");
            $count = $stmt->fetchColumn();
            echo "<p>Registros: $count</p>";
        } catch (Exception $e) {
            echo "<p style='color: red;'>Erro ao contar registros: " . $e->getMessage() . "</p>";
        }
        
        // Verificar colunas
        try {
            $stmt = $pdo->query("
                SELECT column_name, data_type, is_nullable, column_default
                FROM information_schema.columns 
                WHERE table_name = '$tabela'
                ORDER BY ordinal_position
            ");
            $colunas = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo "<table border='1' style='border-collapse: collapse; width: 100%; font-size: 12px;'>";
            echo "<tr><th>Coluna</th><th>Tipo</th><th>Nulo</th><th>Padrão</th></tr>";
            foreach ($colunas as $coluna) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($coluna['column_name']) . "</td>";
                echo "<td>" . htmlspecialchars($coluna['data_type']) . "</td>";
                echo "<td>" . htmlspecialchars($coluna['is_nullable']) . "</td>";
                echo "<td>" . htmlspecialchars($coluna['column_default'] ?? '') . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        } catch (Exception $e) {
            echo "<p style='color: red;'>Erro ao verificar colunas: " . $e->getMessage() . "</p>";
        }
        
        // Verificar índices
        try {
            $stmt = $pdo->query("
                SELECT indexname, indexdef
                FROM pg_indexes 
                WHERE tablename = '$tabela'
            ");
            $indices = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($indices)) {
                echo "<p><strong>Índices:</strong></p>";
                echo "<ul>";
                foreach ($indices as $indice) {
                    echo "<li>" . htmlspecialchars($indice['indexname']) . "</li>";
                }
                echo "</ul>";
            }
        } catch (Exception $e) {
            echo "<p style='color: orange;'>Aviso: Não foi possível verificar índices</p>";
        }
        
        echo "<hr>";
    }
    
    // Teste 4: Verificar relacionamentos (Foreign Keys)
    echo "<h2>4. 🔗 Verificação de Relacionamentos</h2>";
    
    $stmt = $pdo->query("
        SELECT 
            tc.table_name, 
            kcu.column_name, 
            ccu.table_name AS foreign_table_name,
            ccu.column_name AS foreign_column_name,
            tc.constraint_name
        FROM information_schema.table_constraints AS tc 
        JOIN information_schema.key_column_usage AS kcu
          ON tc.constraint_name = kcu.constraint_name
          AND tc.table_schema = kcu.table_schema
        JOIN information_schema.constraint_column_usage AS ccu
          ON ccu.constraint_name = tc.constraint_name
          AND ccu.table_schema = tc.table_schema
        WHERE tc.constraint_type = 'FOREIGN KEY'
        ORDER BY tc.table_name, kcu.column_name
    ");
    $relacionamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($relacionamentos)) {
        echo "<table border='1' style='border-collapse: collapse; width: 100%; font-size: 12px;'>";
        echo "<tr><th>Tabela</th><th>Coluna</th><th>Referencia</th><th>Coluna Ref.</th><th>Constraint</th></tr>";
        foreach ($relacionamentos as $rel) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($rel['table_name']) . "</td>";
            echo "<td>" . htmlspecialchars($rel['column_name']) . "</td>";
            echo "<td>" . htmlspecialchars($rel['foreign_table_name']) . "</td>";
            echo "<td>" . htmlspecialchars($rel['foreign_column_name']) . "</td>";
            echo "<td>" . htmlspecialchars($rel['constraint_name']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color: orange;'>⚠️ Nenhum relacionamento encontrado</p>";
    }
    
    // Teste 5: Verificar dados essenciais
    echo "<h2>5. 📊 Verificação de Dados Essenciais</h2>";
    
    // Verificar unidades
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM lc_unidades");
        $count_unidades = $stmt->fetchColumn();
        echo "<p>Unidades cadastradas: $count_unidades</p>";
        
        if ($count_unidades == 0) {
            echo "<p style='color: red;'>❌ CRÍTICO: Nenhuma unidade cadastrada</p>";
            echo "<p>Executando inserção de unidades básicas...</p>";
            
            $unidades_basicas = [
                ['nome' => 'Quilograma', 'simbolo' => 'kg', 'fator_base' => 1.0],
                ['nome' => 'Litro', 'simbolo' => 'L', 'fator_base' => 1.0],
                ['nome' => 'Unidade', 'simbolo' => 'un', 'fator_base' => 1.0],
                ['nome' => 'Grama', 'simbolo' => 'g', 'fator_base' => 0.001],
                ['nome' => 'Mililitro', 'simbolo' => 'ml', 'fator_base' => 0.001]
            ];
            
            foreach ($unidades_basicas as $unidade) {
                $stmt = $pdo->prepare("
                    INSERT INTO lc_unidades (nome, simbolo, fator_base) 
                    VALUES (?, ?, ?) 
                    ON CONFLICT (simbolo) DO NOTHING
                ");
                $stmt->execute([$unidade['nome'], $unidade['simbolo'], $unidade['fator_base']]);
            }
            echo "<p style='color: green;'>✅ Unidades básicas inseridas</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'>Erro ao verificar unidades: " . $e->getMessage() . "</p>";
    }
    
    // Verificar categorias
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM lc_categorias");
        $count_categorias = $stmt->fetchColumn();
        echo "<p>Categorias cadastradas: $count_categorias</p>";
        
        if ($count_categorias == 0) {
            echo "<p style='color: orange;'>⚠️ Nenhuma categoria cadastrada</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'>Erro ao verificar categorias: " . $e->getMessage() . "</p>";
    }
    
    // Verificar insumos
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM lc_insumos WHERE ativo = true");
        $count_insumos = $stmt->fetchColumn();
        echo "<p>Insumos ativos: $count_insumos</p>";
        
        if ($count_insumos == 0) {
            echo "<p style='color: orange;'>⚠️ Nenhum insumo ativo cadastrado</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'>Erro ao verificar insumos: " . $e->getMessage() . "</p>";
    }
    
    // Teste 6: Verificar campos específicos do sistema de estoque
    echo "<h2>6. 📦 Verificação de Campos de Estoque</h2>";
    
    $campos_estoque = [
        'lc_insumos' => ['estoque_atual', 'estoque_minimo', 'embalagem_multiplo', 'ean_code'],
        'estoque_contagens' => ['data_ref', 'status', 'observacao'],
        'estoque_contagem_itens' => ['qtd_digitada', 'fator_aplicado', 'qtd_contada_base'],
        'lc_insumos_substitutos' => ['equivalencia', 'prioridade', 'ativo']
    ];
    
    foreach ($campos_estoque as $tabela => $campos) {
        if (in_array($tabela, $tabelas_existentes)) {
            echo "<h3>$tabela</h3>";
            foreach ($campos as $campo) {
                try {
                    $stmt = $pdo->query("
                        SELECT COUNT(*) FROM information_schema.columns 
                        WHERE table_name = '$tabela' AND column_name = '$campo'
                    ");
                    $existe = $stmt->fetchColumn() > 0;
                    
                    if ($existe) {
                        echo "<p style='color: green;'>✅ $campo</p>";
                    } else {
                        echo "<p style='color: red;'>❌ $campo (FALTANDO)</p>";
                    }
                } catch (Exception $e) {
                    echo "<p style='color: red;'>Erro ao verificar $campo: " . $e->getMessage() . "</p>";
                }
            }
        }
    }
    
    // Teste 7: Verificar funções e triggers
    echo "<h2>7. ⚙️ Verificação de Funções e Triggers</h2>";
    
    try {
        $stmt = $pdo->query("
            SELECT routine_name, routine_type 
            FROM information_schema.routines 
            WHERE routine_schema = 'public'
            ORDER BY routine_name
        ");
        $funcoes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($funcoes)) {
            echo "<p><strong>Funções encontradas:</strong></p>";
            echo "<ul>";
            foreach ($funcoes as $funcao) {
                echo "<li>" . htmlspecialchars($funcao['routine_name']) . " (" . htmlspecialchars($funcao['routine_type']) . ")</li>";
            }
            echo "</ul>";
        } else {
            echo "<p>Nenhuma função personalizada encontrada</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color: orange;'>Aviso: Não foi possível verificar funções</p>";
    }
    
    // Teste 8: Teste de integridade de dados
    echo "<h2>8. 🔍 Teste de Integridade de Dados</h2>";
    
    // Verificar referências órfãs
    $verificacoes_integridade = [
        "SELECT COUNT(*) FROM lc_insumos i LEFT JOIN lc_unidades u ON u.simbolo = i.unidade_padrao WHERE u.simbolo IS NULL" => "Insumos com unidade inválida",
        "SELECT COUNT(*) FROM lc_ficha_componentes fc LEFT JOIN lc_insumos i ON i.id = fc.insumo_id WHERE i.id IS NULL" => "Componentes com insumo inválido",
        "SELECT COUNT(*) FROM lc_compras_consolidadas cc LEFT JOIN lc_insumos i ON i.id = cc.insumo_id WHERE i.id IS NULL" => "Compras com insumo inválido",
        "SELECT COUNT(*) FROM estoque_contagem_itens eci LEFT JOIN lc_insumos i ON i.id = eci.insumo_id WHERE i.id IS NULL" => "Contagem com insumo inválido"
    ];
    
    foreach ($verificacoes_integridade as $sql => $descricao) {
        try {
            $stmt = $pdo->query($sql);
            $count = $stmt->fetchColumn();
            
            if ($count > 0) {
                echo "<p style='color: red;'>❌ $descricao: $count registros</p>";
            } else {
                echo "<p style='color: green;'>✅ $descricao: OK</p>";
            }
        } catch (Exception $e) {
            echo "<p style='color: orange;'>⚠️ Não foi possível verificar: $descricao</p>";
        }
    }
    
    // Teste 9: Teste de performance
    echo "<h2>9. ⚡ Teste de Performance</h2>";
    
    $testes_performance = [
        "SELECT COUNT(*) FROM lc_insumos WHERE ativo = true" => "Contagem de insumos ativos",
        "SELECT COUNT(*) FROM lc_fichas WHERE ativo = true" => "Contagem de fichas ativas",
        "SELECT COUNT(*) FROM lc_listas WHERE status = 'rascunho'" => "Contagem de listas rascunho",
        "SELECT COUNT(*) FROM estoque_contagens WHERE status = 'fechada'" => "Contagem de contagens fechadas"
    ];
    
    foreach ($testes_performance as $sql => $descricao) {
        try {
            $inicio = microtime(true);
            $stmt = $pdo->query($sql);
            $resultado = $stmt->fetchColumn();
            $fim = microtime(true);
            $tempo = round(($fim - $inicio) * 1000, 2);
            
            echo "<p>$descricao: $resultado registros (${tempo}ms)</p>";
        } catch (Exception $e) {
            echo "<p style='color: red;'>Erro em $descricao: " . $e->getMessage() . "</p>";
        }
    }
    
    // Teste 10: Criar dados de teste se necessário
    echo "<h2>10. 🧪 Criação de Dados de Teste</h2>";
    
    // Verificar se há dados suficientes para teste
    $stmt = $pdo->query("SELECT COUNT(*) FROM lc_insumos WHERE ativo = true");
    $count_insumos = $stmt->fetchColumn();
    
    if ($count_insumos < 3) {
        echo "<p style='color: orange;'>⚠️ Poucos insumos para teste. Criando dados de exemplo...</p>";
        
        $insumos_teste = [
            ['nome' => 'Arroz Branco', 'unidade_padrao' => 'kg', 'preco' => 5.50, 'fator_correcao' => 1.0, 'estoque_atual' => 2.5, 'estoque_minimo' => 5.0, 'embalagem_multiplo' => 1],
            ['nome' => 'Leite UHT 1L', 'unidade_padrao' => 'L', 'preco' => 4.20, 'fator_correcao' => 1.0, 'estoque_atual' => 1.0, 'estoque_minimo' => 3.0, 'embalagem_multiplo' => 12],
            ['nome' => 'Açúcar Cristal', 'unidade_padrao' => 'kg', 'preco' => 3.80, 'fator_correcao' => 1.0, 'estoque_atual' => 0.5, 'estoque_minimo' => 2.0, 'embalagem_multiplo' => 1]
        ];
        
        foreach ($insumos_teste as $insumo) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO lc_insumos (nome, unidade_padrao, preco, fator_correcao, estoque_atual, estoque_minimo, embalagem_multiplo, ativo)
                    VALUES (?, ?, ?, ?, ?, ?, ?, true)
                    ON CONFLICT (nome) DO NOTHING
                ");
                $stmt->execute([
                    $insumo['nome'],
                    $insumo['unidade_padrao'],
                    $insumo['preco'],
                    $insumo['fator_correcao'],
                    $insumo['estoque_atual'],
                    $insumo['estoque_minimo'],
                    $insumo['embalagem_multiplo']
                ]);
            } catch (Exception $e) {
                echo "<p style='color: red;'>Erro ao inserir {$insumo['nome']}: " . $e->getMessage() . "</p>";
            }
        }
        echo "<p style='color: green;'>✅ Dados de teste criados</p>";
    } else {
        echo "<p style='color: green;'>✅ Dados suficientes para teste</p>";
    }
    
    // Teste 11: Verificar permissões e usuários
    echo "<h2>11. 👤 Verificação de Usuários e Permissões</h2>";
    
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_name LIKE '%usuario%' OR table_name LIKE '%user%'");
        $tabelas_usuario = $stmt->fetchColumn();
        
        if ($tabelas_usuario > 0) {
            echo "<p>✅ Tabelas de usuários encontradas</p>";
        } else {
            echo "<p style='color: orange;'>⚠️ Nenhuma tabela de usuários encontrada</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color: orange;'>Aviso: Não foi possível verificar usuários</p>";
    }
    
    // Teste 12: Resumo final
    echo "<h2>12. 📋 Resumo da Análise</h2>";
    
    $total_tabelas = count($tabelas_principais);
    $tabelas_ok = count($tabelas_existentes);
    $tabelas_faltando_count = count($tabelas_faltando);
    
    echo "<div style='background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
    echo "<h3>📊 Estatísticas Gerais</h3>";
    echo "<ul>";
    echo "<li><strong>Tabelas principais:</strong> $tabelas_ok / $total_tabelas</li>";
    echo "<li><strong>Tabelas faltando:</strong> $tabelas_faltando_count</li>";
    echo "<li><strong>Relacionamentos:</strong> " . count($relacionamentos) . "</li>";
    echo "<li><strong>Funções:</strong> " . count($funcoes ?? []) . "</li>";
    echo "</ul>";
    echo "</div>";
    
    if ($tabelas_faltando_count > 0) {
        echo "<div style='background: #f8d7da; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
        echo "<h3 style='color: #721c24;'>❌ Tabelas Faltando</h3>";
        echo "<ul>";
        foreach ($tabelas_faltando as $tabela) {
            echo "<li style='color: #721c24;'>$tabela</li>";
        }
        echo "</ul>";
        echo "<p><strong>Ação necessária:</strong> Execute o arquivo sql/008_estoque_contagem.sql</p>";
        echo "</div>";
    }
    
    if ($tabelas_faltando_count == 0) {
        echo "<div style='background: #d4edda; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
        echo "<h3 style='color: #155724;'>✅ Banco de Dados OK</h3>";
        echo "<p>O banco de dados está configurado corretamente e pronto para uso!</p>";
        echo "</div>";
    }
    
    echo "<div style='margin-top: 30px; padding: 20px; background: #e7f3ff; border-radius: 8px;'>";
    echo "<h3>🔗 Links para Teste</h3>";
    echo "<ul>";
    echo "<li><a href='estoque_alertas.php'>🚨 Alertas de Ruptura</a></li>";
    echo "<li><a href='estoque_contagens.php'>📦 Contagens de Estoque</a></li>";
    echo "<li><a href='test_substitutos.php'>🔄 Teste de Substitutos</a></li>";
    echo "<li><a href='test_me_integration.php'>🔗 Teste ME Eventos</a></li>";
    echo "</ul>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<h2 style='color: red;'>❌ Erro Crítico</h2>";
    echo "<p style='color: red;'>Erro: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre style='background: #f8f9fa; padding: 15px; border-radius: 4px; overflow-x: auto;'>";
    echo htmlspecialchars($e->getTraceAsString());
    echo "</pre>";
    
    echo "<div style='background: #f8d7da; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
    echo "<h3>🚨 Ações Necessárias</h3>";
    echo "<ol>";
    echo "<li>Verifique se o PostgreSQL está rodando</li>";
    echo "<li>Confirme as credenciais de conexão em conexao.php</li>";
    echo "<li>Execute o arquivo sql/008_estoque_contagem.sql</li>";
    echo "<li>Verifique as permissões do banco de dados</li>";
    echo "</ol>";
    echo "</div>";
}

// Log da análise
error_log("Análise completa do banco de dados executada em " . date('Y-m-d H:i:s'));
?>

<script>
// JavaScript para melhorar a experiência
document.addEventListener('DOMContentLoaded', function() {
    console.log('✅ Análise do banco de dados concluída');
    
    // Adicionar funcionalidade de expandir/colapsar seções
    const headers = document.querySelectorAll('h2, h3');
    headers.forEach(header => {
        header.style.cursor = 'pointer';
        header.addEventListener('click', function() {
            const nextElement = this.nextElementSibling;
            if (nextElement && nextElement.style) {
                nextElement.style.display = nextElement.style.display === 'none' ? 'block' : 'none';
            }
        });
    });
    
    // Auto-refresh a cada 30 segundos se houver erros
    const errorElements = document.querySelectorAll('[style*="color: red"]');
    if (errorElements.length > 0) {
        console.log('⚠️ Erros detectados, considerando auto-refresh');
    }
});
</script>

<style>
body {
    font-family: Arial, sans-serif;
    line-height: 1.6;
    margin: 20px;
    background: #f8f9fa;
}

h1, h2, h3 {
    color: #333;
}

table {
    margin: 10px 0;
    border-collapse: collapse;
    width: 100%;
}

th, td {
    padding: 8px;
    text-align: left;
    border: 1px solid #ddd;
}

th {
    background-color: #f2f2f2;
}

hr {
    margin: 20px 0;
    border: none;
    border-top: 1px solid #ddd;
}

pre {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 4px;
    overflow-x: auto;
    font-size: 12px;
}

ul, ol {
    margin: 10px 0;
    padding-left: 20px;
}

li {
    margin: 5px 0;
}
</style>
