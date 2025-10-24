<?php
// ANALISE_COMPLETA_BANCO.php
// Análise completa de todas as dependências do banco de dados

session_start();
require_once __DIR__ . '/public/conexao.php';

echo "<h1>🔍 ANÁLISE COMPLETA DO BANCO DE DADOS</h1>";
echo "<p>Identificando todas as tabelas, colunas e funções necessárias...</p>";

try {
    // 1. Analisar arquivos PHP para encontrar referências
    echo "<h2>1. 📝 Analisando Referências no Código</h2>";
    
    $arquivos_php = glob(__DIR__ . '/public/*.php');
    $referencias_encontradas = [];
    
    foreach ($arquivos_php as $arquivo) {
        $conteudo = file_get_contents($arquivo);
        $nome_arquivo = basename($arquivo);
        
        // Padrões para encontrar referências a tabelas e colunas
        $padroes = [
            '/SELECT.*?FROM\s+(\w+)/i',
            '/INSERT\s+INTO\s+(\w+)/i',
            '/UPDATE\s+(\w+)/i',
            '/DELETE\s+FROM\s+(\w+)/i',
            '/JOIN\s+(\w+)/i',
            '/FROM\s+(\w+)/i'
        ];
        
        foreach ($padroes as $padrao) {
            preg_match_all($padrao, $conteudo, $matches);
            foreach ($matches[1] as $match) {
                $tabela = trim($match);
                if (!isset($referencias_encontradas[$tabela])) {
                    $referencias_encontradas[$tabela] = [];
                }
                if (!in_array($nome_arquivo, $referencias_encontradas[$tabela])) {
                    $referencias_encontradas[$tabela][] = $nome_arquivo;
                }
            }
        }
    }
    
    echo "<h3>📊 Tabelas Referenciadas no Código:</h3>";
    foreach ($referencias_encontradas as $tabela => $arquivos) {
        echo "<p><strong>$tabela</strong> - Referenciada em: " . implode(', ', $arquivos) . "</p>";
    }
    
    // 2. Verificar tabelas existentes
    echo "<h2>2. 🗄️ Verificação de Tabelas Existentes</h2>";
    
    $stmt = $pdo->query("
        SELECT table_name 
        FROM information_schema.tables 
        WHERE table_schema = 'smilee12_painel_smile' 
        ORDER BY table_name
    ");
    $tabelas_existentes = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "<h3>✅ Tabelas Existentes (" . count($tabelas_existentes) . "):</h3>";
    foreach ($tabelas_existentes as $tabela) {
        echo "<p>• $tabela</p>";
    }
    
    // 3. Identificar tabelas faltantes
    echo "<h2>3. ❌ Tabelas Faltantes</h2>";
    
    $tabelas_faltantes = [];
    foreach ($referencias_encontradas as $tabela => $arquivos) {
        if (!in_array($tabela, $tabelas_existentes)) {
            $tabelas_faltantes[] = $tabela;
        }
    }
    
    if (empty($tabelas_faltantes)) {
        echo "<p style='color: green;'>✅ Todas as tabelas referenciadas existem!</p>";
    } else {
        echo "<h3>❌ Tabelas que precisam ser criadas:</h3>";
        foreach ($tabelas_faltantes as $tabela) {
            echo "<p style='color: red;'>• $tabela</p>";
        }
    }
    
    // 4. Verificar colunas de permissões
    echo "<h2>4. 🔐 Verificação de Colunas de Permissões</h2>";
    
    $colunas_permissoes = [
        'perm_agenda_ver', 'perm_agenda_meus', 'perm_agenda_relatorios',
        'perm_agenda_editar', 'perm_agenda_criar', 'perm_agenda_excluir',
        'perm_demandas_ver', 'perm_demandas_editar', 'perm_demandas_criar',
        'perm_demandas_excluir', 'perm_demandas_ver_produtividade',
        'perm_comercial_ver', 'perm_comercial_deg_editar', 'perm_comercial_deg_inscritos',
        'perm_comercial_conversao', 'perm_gerir_eventos_outros', 'perm_forcar_conflito'
    ];
    
    $stmt = $pdo->query("
        SELECT column_name 
        FROM information_schema.columns 
        WHERE table_name = 'usuarios' 
        AND table_schema = 'smilee12_painel_smile'
    ");
    $colunas_existentes = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $colunas_faltantes = [];
    foreach ($colunas_permissoes as $coluna) {
        if (!in_array($coluna, $colunas_existentes)) {
            $colunas_faltantes[] = $coluna;
        }
    }
    
    if (empty($colunas_faltantes)) {
        echo "<p style='color: green;'>✅ Todas as colunas de permissões existem!</p>";
    } else {
        echo "<h3>❌ Colunas de permissões faltantes:</h3>";
        foreach ($colunas_faltantes as $coluna) {
            echo "<p style='color: red;'>• $coluna</p>";
        }
    }
    
    // 5. Verificar funções PostgreSQL
    echo "<h2>5. 🔧 Verificação de Funções PostgreSQL</h2>";
    
    $funcoes_necessarias = [
        'obter_proximos_eventos',
        'obter_eventos_hoje', 
        'obter_eventos_semana',
        'verificar_conflito_agenda',
        'calcular_conversao_visitas',
        'gerar_token_ics'
    ];
    
    $stmt = $pdo->query("
        SELECT routine_name 
        FROM information_schema.routines 
        WHERE routine_schema = 'smilee12_painel_smile'
        AND routine_type = 'FUNCTION'
    ");
    $funcoes_existentes = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $funcoes_faltantes = [];
    foreach ($funcoes_necessarias as $funcao) {
        if (!in_array($funcao, $funcoes_existentes)) {
            $funcoes_faltantes[] = $funcao;
        }
    }
    
    if (empty($funcoes_faltantes)) {
        echo "<p style='color: green;'>✅ Todas as funções necessárias existem!</p>";
    } else {
        echo "<h3>❌ Funções faltantes:</h3>";
        foreach ($funcoes_faltantes as $funcao) {
            echo "<p style='color: red;'>• $funcao</p>";
        }
    }
    
    // 6. Gerar script de correção
    echo "<h2>6. 🛠️ Script de Correção Automática</h2>";
    
    $script_correcao = "-- SCRIPT DE CORREÇÃO AUTOMÁTICA\n";
    $script_correcao .= "-- Gerado em: " . date('Y-m-d H:i:s') . "\n\n";
    
    // Adicionar colunas de permissões faltantes
    if (!empty($colunas_faltantes)) {
        $script_correcao .= "-- Adicionar colunas de permissões faltantes\n";
        foreach ($colunas_faltantes as $coluna) {
            $script_correcao .= "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS $coluna BOOLEAN DEFAULT false;\n";
        }
        $script_correcao .= "\n";
    }
    
    // Adicionar tabelas faltantes
    if (!empty($tabelas_faltantes)) {
        $script_correcao .= "-- Criar tabelas faltantes\n";
        foreach ($tabelas_faltantes as $tabela) {
            $script_correcao .= "-- TODO: Criar tabela $tabela\n";
        }
        $script_correcao .= "\n";
    }
    
    // Adicionar funções faltantes
    if (!empty($funcoes_faltantes)) {
        $script_correcao .= "-- Criar funções faltantes\n";
        foreach ($funcoes_faltantes as $funcao) {
            $script_correcao .= "-- TODO: Criar função $funcao\n";
        }
        $script_correcao .= "\n";
    }
    
    // Salvar script
    file_put_contents(__DIR__ . '/CORRECAO_AUTOMATICA.sql', $script_correcao);
    echo "<p style='color: green;'>✅ Script de correção salvo em: CORRECAO_AUTOMATICA.sql</p>";
    
    // 7. Resumo final
    echo "<h2>7. 📋 Resumo Final</h2>";
    echo "<div style='background: #f0f9ff; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
    echo "<h3>📊 Estatísticas:</h3>";
    echo "<p>• <strong>Tabelas referenciadas:</strong> " . count($referencias_encontradas) . "</p>";
    echo "<p>• <strong>Tabelas existentes:</strong> " . count($tabelas_existentes) . "</p>";
    echo "<p>• <strong>Tabelas faltantes:</strong> " . count($tabelas_faltantes) . "</p>";
    echo "<p>• <strong>Colunas de permissões faltantes:</strong> " . count($colunas_faltantes) . "</p>";
    echo "<p>• <strong>Funções faltantes:</strong> " . count($funcoes_faltantes) . "</p>";
    echo "</div>";
    
    if (empty($tabelas_faltantes) && empty($colunas_faltantes) && empty($funcoes_faltantes)) {
        echo "<div style='background: #d1fae5; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
        echo "<h3 style='color: #065f46;'>🎉 SISTEMA COMPLETO!</h3>";
        echo "<p style='color: #065f46;'>✅ Todas as dependências do banco estão satisfeitas!</p>";
        echo "</div>";
    } else {
        echo "<div style='background: #fee2e2; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
        echo "<h3 style='color: #991b1b;'>⚠️ CORREÇÕES NECESSÁRIAS</h3>";
        echo "<p style='color: #991b1b;'>❌ Existem dependências faltantes que precisam ser corrigidas.</p>";
        echo "<p><a href='CORRECAO_AUTOMATICA.sql' target='_blank'>📄 Ver Script de Correção</a></p>";
        echo "</div>";
    }
    
} catch (Exception $e) {
    echo "<div style='background: #fee2e2; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
    echo "<h3 style='color: #991b1b;'>❌ ERRO</h3>";
    echo "<p style='color: #991b1b;'>Erro durante a análise: " . $e->getMessage() . "</p>";
    echo "</div>";
}
?>
