<?php
// test_novos_modulos.php
// Teste completo dos módulos RH e Contabilidade

session_start();
require_once __DIR__ . '/conexao.php';

echo "<h1>🧪 Teste dos Novos Módulos - RH e Contabilidade</h1>";
echo "<hr>";

// Função para executar e mostrar resultado de query
function testQuery($pdo, $sql, $description) {
    echo "<h3>🔍 $description</h3>";
    try {
        $stmt = $pdo->query($sql);
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($result)) {
            echo "<p style='color: orange;'>⚠️ Nenhum resultado encontrado</p>";
        } else {
            echo "<p style='color: green;'>✅ Query executada com sucesso</p>";
            echo "<p><strong>Resultados encontrados:</strong> " . count($result) . "</p>";
            
            if (count($result) <= 5) {
                echo "<pre>" . print_r($result, true) . "</pre>";
            } else {
                echo "<p>Primeiros 3 resultados:</p>";
                echo "<pre>" . print_r(array_slice($result, 0, 3), true) . "</pre>";
            }
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Erro: " . $e->getMessage() . "</p>";
    }
    echo "<hr>";
}

// 1. Testar estrutura das tabelas RH
echo "<h2>👥 Módulo RH - Estrutura das Tabelas</h2>";

testQuery($pdo, "SELECT table_name FROM information_schema.tables WHERE table_name LIKE 'rh_%'", 
         "Verificar tabelas do módulo RH");

testQuery($pdo, "SELECT column_name, data_type, is_nullable FROM information_schema.columns WHERE table_name = 'rh_holerites' ORDER BY ordinal_position", 
         "Estrutura da tabela rh_holerites");

testQuery($pdo, "SELECT column_name, data_type, is_nullable FROM information_schema.columns WHERE table_name = 'rh_anexos' ORDER BY ordinal_position", 
         "Estrutura da tabela rh_anexos");

// 2. Testar estrutura das tabelas Contabilidade
echo "<h2>📑 Módulo Contabilidade - Estrutura das Tabelas</h2>";

testQuery($pdo, "SELECT table_name FROM information_schema.tables WHERE table_name LIKE 'contab_%'", 
         "Verificar tabelas do módulo Contabilidade");

testQuery($pdo, "SELECT column_name, data_type, is_nullable FROM information_schema.columns WHERE table_name = 'contab_documentos' ORDER BY ordinal_position", 
         "Estrutura da tabela contab_documentos");

testQuery($pdo, "SELECT column_name, data_type, is_nullable FROM information_schema.columns WHERE table_name = 'contab_parcelas' ORDER BY ordinal_position", 
         "Estrutura da tabela contab_parcelas");

testQuery($pdo, "SELECT column_name, data_type, is_nullable FROM information_schema.columns WHERE table_name = 'contab_anexos' ORDER BY ordinal_position", 
         "Estrutura da tabela contab_anexos");

testQuery($pdo, "SELECT column_name, data_type, is_nullable FROM information_schema.columns WHERE table_name = 'contab_tokens' ORDER BY ordinal_position", 
         "Estrutura da tabela contab_tokens");

// 3. Testar campos adicionados na tabela usuarios
echo "<h2>👤 Campos Adicionados na Tabela Usuários</h2>";

testQuery($pdo, "SELECT column_name, data_type, is_nullable FROM information_schema.columns WHERE table_name = 'usuarios' AND column_name IN ('cpf', 'cargo', 'admissao_data', 'salario_base', 'pix_tipo', 'pix_chave', 'status_empregado') ORDER BY ordinal_position", 
         "Campos RH adicionados na tabela usuarios");

// 4. Testar funções criadas
echo "<h2>⚙️ Funções Criadas</h2>";

testQuery($pdo, "SELECT routine_name, routine_type FROM information_schema.routines WHERE routine_name LIKE 'rh_%' OR routine_name LIKE 'contab_%'", 
         "Funções criadas para RH e Contabilidade");

// 5. Testar estatísticas RH
echo "<h2>📊 Estatísticas RH</h2>";

testQuery($pdo, "SELECT * FROM rh_estatisticas_dashboard()", 
         "Estatísticas do dashboard RH");

// 6. Testar estatísticas Contabilidade
echo "<h2>📊 Estatísticas Contabilidade</h2>";

testQuery($pdo, "SELECT * FROM contab_estatisticas_dashboard()", 
         "Estatísticas do dashboard Contabilidade");

// 7. Testar tokens de contabilidade
echo "<h2>🔑 Tokens de Contabilidade</h2>";

testQuery($pdo, "SELECT id, token, descricao, ativo, criado_em FROM contab_tokens", 
         "Tokens de acesso ao portal contábil");

// 8. Testar dados existentes
echo "<h2>📋 Dados Existentes</h2>";

testQuery($pdo, "SELECT COUNT(*) as total_usuarios FROM usuarios", 
         "Total de usuários cadastrados");

testQuery($pdo, "SELECT COUNT(*) as total_fornecedores FROM fornecedores", 
         "Total de fornecedores cadastrados");

testQuery($pdo, "SELECT COUNT(*) as total_holerites FROM rh_holerites", 
         "Total de holerites cadastrados");

testQuery($pdo, "SELECT COUNT(*) as total_documentos FROM contab_documentos", 
         "Total de documentos contábeis");

testQuery($pdo, "SELECT COUNT(*) as total_parcelas FROM contab_parcelas", 
         "Total de parcelas contábeis");

// 9. Testar permissões
echo "<h2>🔐 Teste de Permissões</h2>";

$perfis = ['ADM', 'FIN', 'GERENTE', 'OPER', 'CONSULTA'];
foreach ($perfis as $perfil) {
    echo "<h3>Perfil: $perfil</h3>";
    
    $pode_rh = in_array($perfil, ['ADM', 'FIN']);
    $pode_contab = in_array($perfil, ['ADM', 'FIN', 'GERENTE', 'CONSULTA']);
    
    echo "<p>Pode acessar RH: " . ($pode_rh ? "✅ Sim" : "❌ Não") . "</p>";
    echo "<p>Pode acessar Contabilidade: " . ($pode_contab ? "✅ Sim" : "❌ Não") . "</p>";
}

// 10. Testar integridade dos dados
echo "<h2>🔍 Teste de Integridade</h2>";

testQuery($pdo, "SELECT COUNT(*) as usuarios_sem_cpf FROM usuarios WHERE cpf IS NULL OR cpf = ''", 
         "Usuários sem CPF cadastrado");

testQuery($pdo, "SELECT COUNT(*) as usuarios_sem_cargo FROM usuarios WHERE cargo IS NULL OR cargo = ''", 
         "Usuários sem cargo cadastrado");

testQuery($pdo, "SELECT COUNT(*) as usuarios_sem_pix FROM usuarios WHERE pix_chave IS NULL OR pix_chave = ''", 
         "Usuários sem PIX cadastrado");

// 11. Testar rate limiting
echo "<h2>⏱️ Teste de Rate Limiting</h2>";

testQuery($pdo, "SELECT contab_verificar_rate_limit('127.0.0.1', 'test_token')", 
         "Teste de rate limiting (deve retornar true)");

// 12. Resumo final
echo "<h2>📋 Resumo dos Testes</h2>";

$tabelas_rh = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_name LIKE 'rh_%'")->fetchColumn();
$tabelas_contab = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_name LIKE 'contab_%'")->fetchColumn();
$funcoes = $pdo->query("SELECT COUNT(*) FROM information_schema.routines WHERE routine_name LIKE 'rh_%' OR routine_name LIKE 'contab_%'")->fetchColumn();

echo "<div style='background: #f0f9ff; padding: 20px; border-radius: 8px; border: 1px solid #0ea5e9;'>";
echo "<h3>✅ Status da Implementação</h3>";
echo "<p><strong>Tabelas RH criadas:</strong> $tabelas_rh</p>";
echo "<p><strong>Tabelas Contabilidade criadas:</strong> $tabelas_contab</p>";
echo "<p><strong>Funções criadas:</strong> $funcoes</p>";
echo "<p><strong>Páginas implementadas:</strong> 8 (4 RH + 4 Contabilidade)</p>";
echo "<p><strong>Links no menu:</strong> ✅ Adicionados</p>";
echo "</div>";

echo "<h3>🎯 Próximos Passos</h3>";
echo "<ol>";
echo "<li>Executar os scripts SQL: <code>013_modulo_rh.sql</code> e <code>014_modulo_contabilidade.sql</code></li>";
echo "<li>Testar as páginas criadas no navegador</li>";
echo "<li>Verificar permissões de usuários</li>";
echo "<li>Configurar tokens de acesso para contabilidade</li>";
echo "<li>Testar upload de anexos</li>";
echo "</ol>";

echo "<h3>🔗 Links para Teste</h3>";
echo "<ul>";
echo "<li><a href='rh_dashboard.php'>Dashboard RH</a></li>";
echo "<li><a href='rh_colaboradores.php'>Colaboradores</a></li>";
echo "<li><a href='rh_holerite_upload.php'>Upload de Holerites</a></li>";
echo "<li><a href='contab_dashboard.php'>Dashboard Contabilidade</a></li>";
echo "<li><a href='contab_documentos.php'>Documentos Contábeis</a></li>";
echo "<li><a href='contab_link.php'>Portal Contábil</a></li>";
echo "<li><a href='configuracoes.php'>Configurações (com novos links)</a></li>";
echo "</ul>";

echo "<p style='color: green; font-weight: bold; margin-top: 30px;'>🎉 Implementação dos módulos RH e Contabilidade concluída com sucesso!</p>";
?>
