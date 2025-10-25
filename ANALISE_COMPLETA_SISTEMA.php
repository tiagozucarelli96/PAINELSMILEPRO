<?php
// ANALISE_COMPLETA_SISTEMA.php
// Análise completa para identificar TODOS os problemas

echo "<h1>🔍 ANÁLISE COMPLETA DO SISTEMA</h1>";

// 1. Testar conexão
echo "<h2>1. 🔌 Teste de Conexão</h2>";
try {
    require_once __DIR__ . '/public/conexao.php';
    echo "<p style='color: green;'>✅ Conexão com banco funcionando</p>";
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Erro de conexão: " . $e->getMessage() . "</p>";
    exit;
}

// 2. Testar todas as páginas principais
echo "<h2>2. 📄 Teste de Páginas Principais</h2>";

$paginas_teste = [
    'index.php' => 'Página principal',
    'dashboard.php' => 'Dashboard',
    'login.php' => 'Login',
    'usuarios.php' => 'Usuários',
    'configuracoes.php' => 'Configurações',
    'lc_index.php' => 'Lista de Compras',
    'fichas_tecnicas.php' => 'Fichas Técnicas',
    'config_insumos.php' => 'Config Insumos',
    'config_fornecedores.php' => 'Config Fornecedores',
    'config_categorias.php' => 'Config Categorias',
    'config_fichas.php' => 'Config Fichas',
    'config_itens.php' => 'Config Itens',
    'config_itens_fixos.php' => 'Config Itens Fixos',
    'config_arredondamentos.php' => 'Config Arredondamentos',
    'lc_config_avancadas.php' => 'LC Config Avançadas',
    'lc_config_helper.php' => 'LC Config Helper',
    'lc_excluir.php' => 'LC Excluir',
    'lc_ver.php' => 'LC Ver',
    'lc_pdf.php' => 'LC PDF',
    'gerar_lista_compras.php' => 'Gerar Lista Compras',
    'lista_compras_gerar.php' => 'Lista Compras Gerar',
    'lista_compras_submit.php' => 'Lista Compras Submit',
    'lista_compras.php' => 'Lista Compras',
    'lista_compras_lixeira.php' => 'Lista Compras Lixeira',
    'historico.php' => 'Histórico',
    'pagamentos.php' => 'Pagamentos',
    'admin_pagamentos.php' => 'Admin Pagamentos',
    'banco_smile.php' => 'Banco Smile',
    'banco_smile_admin.php' => 'Banco Smile Admin',
    'notas_fiscais.php' => 'Notas Fiscais',
    'tarefas.php' => 'Tarefas',
    'demandas.php' => 'Demandas',
    'estoque_logistico.php' => 'Estoque Logístico',
    'uso_fiorino.php' => 'Uso Fiorino',
    'ver.php' => 'Ver',
    'usuario_editar.php' => 'Usuário Editar',
    'usuario_novo.php' => 'Usuário Novo'
];

$paginas_ok = 0;
$paginas_erro = 0;
$problemas_paginas = [];

foreach ($paginas_teste as $arquivo => $descricao) {
    $caminho = __DIR__ . '/public/' . $arquivo;
    if (file_exists($caminho)) {
        // Verificar se o arquivo tem includes que podem falhar
        $conteudo = file_get_contents($caminho);
        
        // Verificar includes problemáticos
        $includes_problematicos = [];
        if (strpos($conteudo, 'require_once') !== false || strpos($conteudo, 'include_once') !== false) {
            preg_match_all('/(?:require_once|include_once)\s+[\'"]([^\'"]+)[\'"]/', $conteudo, $matches);
            foreach ($matches[1] as $include) {
                if (!file_exists(__DIR__ . '/public/' . $include)) {
                    $includes_problematicos[] = $include;
                }
            }
        }
        
        if (empty($includes_problematicos)) {
            echo "<p style='color: green;'>✅ $arquivo - $descricao</p>";
            $paginas_ok++;
        } else {
            echo "<p style='color: orange;'>⚠️ $arquivo - $descricao (includes problemáticos: " . implode(', ', $includes_problematicos) . ")</p>";
            $problemas_paginas[$arquivo] = $includes_problematicos;
            $paginas_erro++;
        }
    } else {
        echo "<p style='color: red;'>❌ $arquivo - $descricao (arquivo não encontrado)</p>";
        $paginas_erro++;
    }
}

// 3. Testar funcionalidades do banco
echo "<h2>3. 🗄️ Teste de Funcionalidades do Banco</h2>";

$funcionalidades_teste = [
    'usuarios' => [
        'tabela' => 'usuarios',
        'colunas_obrigatorias' => ['id', 'nome', 'email', 'perfil', 'perm_agenda_ver', 'perm_agenda_meus', 'perm_agenda_relatorios'],
        'teste_query' => 'SELECT COUNT(*) FROM usuarios'
    ],
    'eventos' => [
        'tabela' => 'eventos',
        'colunas_obrigatorias' => ['id', 'titulo', 'descricao', 'data_inicio', 'data_fim', 'status'],
        'teste_query' => 'SELECT COUNT(*) FROM eventos'
    ],
    'fornecedores' => [
        'tabela' => 'fornecedores',
        'colunas_obrigatorias' => ['id', 'nome', 'email', 'telefone', 'status'],
        'teste_query' => 'SELECT COUNT(*) FROM fornecedores'
    ],
    'lc_categorias' => [
        'tabela' => 'lc_categorias',
        'colunas_obrigatorias' => ['id', 'nome', 'descricao', 'ativo'],
        'teste_query' => 'SELECT COUNT(*) FROM lc_categorias'
    ],
    'lc_unidades' => [
        'tabela' => 'lc_unidades',
        'colunas_obrigatorias' => ['id', 'nome', 'sigla', 'ativo'],
        'teste_query' => 'SELECT COUNT(*) FROM lc_unidades'
    ],
    'lc_fichas' => [
        'tabela' => 'lc_fichas',
        'colunas_obrigatorias' => ['id', 'nome', 'descricao', 'ativo'],
        'teste_query' => 'SELECT COUNT(*) FROM lc_fichas'
    ],
    'comercial_campos_padrao' => [
        'tabela' => 'comercial_campos_padrao',
        'colunas_obrigatorias' => ['id', 'campos_json', 'ativo', 'criado_em'],
        'teste_query' => 'SELECT COUNT(*) FROM comercial_campos_padrao'
    ]
];

$funcionalidades_ok = 0;
$funcionalidades_erro = 0;
$problemas_funcionalidades = [];

foreach ($funcionalidades_teste as $nome => $config) {
    try {
        // Testar se a tabela existe e tem dados
        $stmt = $pdo->query($config['teste_query']);
        $count = $stmt->fetchColumn();
        
        // Testar colunas obrigatórias
        $colunas_faltantes = [];
        foreach ($config['colunas_obrigatorias'] as $coluna) {
            try {
                $stmt = $pdo->query("SELECT $coluna FROM {$config['tabela']} LIMIT 1");
                $stmt->fetch();
            } catch (Exception $e) {
                if (strpos($e->getMessage(), 'does not exist') !== false) {
                    $colunas_faltantes[] = $coluna;
                }
            }
        }
        
        if (empty($colunas_faltantes)) {
            echo "<p style='color: green;'>✅ $nome - $count registros, todas as colunas OK</p>";
            $funcionalidades_ok++;
        } else {
            echo "<p style='color: red;'>❌ $nome - colunas faltantes: " . implode(', ', $colunas_faltantes) . "</p>";
            $problemas_funcionalidades[$nome] = $colunas_faltantes;
            $funcionalidades_erro++;
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ $nome - ERRO: " . $e->getMessage() . "</p>";
        $problemas_funcionalidades[$nome] = $e->getMessage();
        $funcionalidades_erro++;
    }
}

// 4. Testar funções PostgreSQL
echo "<h2>4. 🔧 Teste de Funções PostgreSQL</h2>";

$funcoes_teste = [
    'obter_proximos_eventos' => 'SELECT * FROM obter_proximos_eventos(1, 24)',
    'obter_eventos_hoje' => 'SELECT * FROM obter_eventos_hoje(1)',
    'obter_eventos_semana' => 'SELECT * FROM obter_eventos_semana(1)'
];

$funcoes_ok = 0;
$funcoes_erro = 0;
$problemas_funcoes = [];

foreach ($funcoes_teste as $nome => $sql) {
    try {
        $stmt = $pdo->query($sql);
        $resultado = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "<p style='color: green;'>✅ $nome - " . count($resultado) . " registros</p>";
        $funcoes_ok++;
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ $nome - ERRO: " . $e->getMessage() . "</p>";
        $problemas_funcoes[$nome] = $e->getMessage();
        $funcoes_erro++;
    }
}

// 5. Resumo final
echo "<h2>5. 📊 Resumo Final</h2>";

echo "<div style='background: #f0f9ff; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
echo "<h3>📈 Estatísticas Gerais:</h3>";
echo "<p>• <strong>Páginas testadas:</strong> " . count($paginas_teste) . "</p>";
echo "<p>• <strong>Páginas funcionando:</strong> $paginas_ok</p>";
echo "<p>• <strong>Páginas com problema:</strong> $paginas_erro</p>";
echo "<p>• <strong>Funcionalidades testadas:</strong> " . count($funcionalidades_teste) . "</p>";
echo "<p>• <strong>Funcionalidades funcionando:</strong> $funcionalidades_ok</p>";
echo "<p>• <strong>Funcionalidades com problema:</strong> $funcionalidades_erro</p>";
echo "<p>• <strong>Funções testadas:</strong> " . count($funcoes_teste) . "</p>";
echo "<p>• <strong>Funções funcionando:</strong> $funcoes_ok</p>";
echo "<p>• <strong>Funções com problema:</strong> $funcoes_erro</p>";
echo "</div>";

// 6. Listar problemas específicos
if (!empty($problemas_paginas) || !empty($problemas_funcionalidades) || !empty($problemas_funcoes)) {
    echo "<div style='background: #fee2e2; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
    echo "<h3 style='color: #991b1b;'>⚠️ PROBLEMAS IDENTIFICADOS</h3>";
    
    if (!empty($problemas_paginas)) {
        echo "<h4>📄 Problemas em Páginas:</h4>";
        foreach ($problemas_paginas as $pagina => $problemas) {
            echo "<p>• <strong>$pagina:</strong> " . implode(', ', $problemas) . "</p>";
        }
    }
    
    if (!empty($problemas_funcionalidades)) {
        echo "<h4>🗄️ Problemas em Funcionalidades:</h4>";
        foreach ($problemas_funcionalidades as $funcionalidade => $problemas) {
            if (is_array($problemas)) {
                echo "<p>• <strong>$funcionalidade:</strong> colunas faltantes: " . implode(', ', $problemas) . "</p>";
            } else {
                echo "<p>• <strong>$funcionalidade:</strong> $problemas</p>";
            }
        }
    }
    
    if (!empty($problemas_funcoes)) {
        echo "<h4>🔧 Problemas em Funções:</h4>";
        foreach ($problemas_funcoes as $funcao => $problema) {
            echo "<p>• <strong>$funcao:</strong> $problema</p>";
        }
    }
    
    echo "</div>";
} else {
    echo "<div style='background: #d1fae5; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
    echo "<h3 style='color: #065f46;'>🎉 SISTEMA 100% FUNCIONAL!</h3>";
    echo "<p style='color: #065f46;'>✅ Nenhum problema identificado!</p>";
    echo "</div>";
}

// 7. Próximos passos
echo "<h2>6. 🚀 Próximos Passos</h2>";
echo "<div style='background: #fef3c7; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
echo "<h3>📋 Ações Necessárias:</h3>";

if ($paginas_erro > 0) {
    echo "<p>1. 🔧 Corrigir problemas em $paginas_erro página(s)</p>";
}
if ($funcionalidades_erro > 0) {
    echo "<p>2. 🗄️ Corrigir problemas em $funcionalidades_erro funcionalidade(s)</p>";
}
if ($funcoes_erro > 0) {
    echo "<p>3. 🔧 Corrigir problemas em $funcoes_erro função(ões)</p>";
}

if ($paginas_erro == 0 && $funcionalidades_erro == 0 && $funcoes_erro == 0) {
    echo "<p>✅ <strong>Sistema 100% funcional - Nenhuma ação necessária!</strong></p>";
}

echo "</div>";
?>
