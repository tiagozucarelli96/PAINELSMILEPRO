<?php
/**
 * CORREÇÃO DE COLUNAS REAIS FALTANTES
 * Foca apenas nas colunas que realmente existem e estão faltando
 */

require_once 'public/conexao.php';

header('Content-Type: text/html; charset=utf-8');
echo "<h1>🔧 CORREÇÃO DE COLUNAS REAIS FALTANTES</h1>";

if (!isset($pdo) || !$pdo) {
    echo "<p style='color: red;'>❌ Erro: PDO não inicializado.</p>";
    exit;
}

echo "<h2>1. 🔍 Identificando colunas reais faltantes...</h2>";

// Colunas específicas que sabemos que estão faltando baseado nos erros
$colunas_faltantes = [
    'usuarios' => [
        'cor_agenda' => 'VARCHAR(7) DEFAULT \'#3B82F6\'',
        'agenda_lembrete_padrao' => 'INTEGER DEFAULT 15',
        'agenda_lembrete_padrao_min' => 'INTEGER DEFAULT 15',
        'agenda_notificacao_email' => 'BOOLEAN DEFAULT true',
        'agenda_notificacao_browser' => 'BOOLEAN DEFAULT true',
        'agenda_mostrar_finalizados' => 'BOOLEAN DEFAULT false',
        'telefone' => 'VARCHAR(20)',
        'celular' => 'VARCHAR(20)',
        'cpf' => 'VARCHAR(14)',
        'rg' => 'VARCHAR(20)',
        'endereco' => 'TEXT',
        'cidade' => 'VARCHAR(100)',
        'estado' => 'VARCHAR(2)',
        'cep' => 'VARCHAR(10)',
        'data_nascimento' => 'DATE',
        'data_admissao' => 'DATE',
        'salario' => 'DECIMAL(10,2)',
        'cargo' => 'VARCHAR(100)',
        'departamento' => 'VARCHAR(100)',
        'observacoes' => 'TEXT',
        'foto' => 'VARCHAR(255)',
        'ultimo_acesso' => 'TIMESTAMP',
        'ip_ultimo_acesso' => 'VARCHAR(45)',
        'user_agent' => 'TEXT',
        'timezone' => 'VARCHAR(50) DEFAULT \'America/Sao_Paulo\'',
        'idioma' => 'VARCHAR(5) DEFAULT \'pt-BR\''
    ],
    'eventos' => [
        'descricao' => 'TEXT',
        'status' => 'VARCHAR(20) DEFAULT \'ativo\'',
        'observacoes' => 'TEXT',
        'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP'
    ],
    'comercial_campos_padrao' => [
        'criado_em' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        'ativo' => 'BOOLEAN DEFAULT true'
    ],
    'lc_geracoes' => [
        'criado_em' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        'status' => 'VARCHAR(20) DEFAULT \'ativo\''
    ]
];

echo "<h2>2. 🚀 Aplicando correções...</h2>";

$sql_correcoes = "-- Correções para colunas reais faltantes\n";
$sucessos = 0;
$erros = 0;

foreach ($colunas_faltantes as $tabela => $colunas) {
    echo "<h3>📋 Tabela: $tabela</h3>";
    
    foreach ($colunas as $coluna => $definicao) {
        try {
            // Verificar se coluna já existe
            $stmt = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = '$tabela' AND column_name = '$coluna'");
            if ($stmt->fetchColumn()) {
                echo "<p style='color: blue;'>ℹ️ Coluna '$coluna' já existe na tabela '$tabela'</p>";
                continue;
            }
            
            // Adicionar coluna
            $sql = "ALTER TABLE $tabela ADD COLUMN IF NOT EXISTS $coluna $definicao;";
            $pdo->exec($sql);
            
            echo "<p style='color: green;'>✅ Coluna '$coluna' adicionada à tabela '$tabela'</p>";
            $sql_correcoes .= $sql . "\n";
            $sucessos++;
            
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ Erro ao adicionar coluna '$coluna' na tabela '$tabela': " . htmlspecialchars($e->getMessage()) . "</p>";
            $erros++;
        }
    }
}

// Salvar SQL de correções
file_put_contents('CORRECAO_COLUNAS_REAIS.sql', $sql_correcoes);

echo "<h2>3. 🔍 Verificando outras colunas importantes...</h2>";

// Verificar se existem outras colunas importantes que podem estar faltando
$verificacoes_especiais = [
    "SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'usuarios' AND column_name LIKE '%agenda%'",
    "SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'eventos' AND column_name = 'descricao'",
    "SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'comercial_campos_padrao' AND column_name = 'criado_em'"
];

foreach ($verificacoes_especiais as $i => $query) {
    try {
        $stmt = $pdo->query($query);
        $resultado = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($resultado)) {
            echo "<p style='color: orange;'>⚠️ Verificação $i: Colunas importantes não encontradas</p>";
        } else {
            echo "<p style='color: green;'>✅ Verificação $i: " . count($resultado) . " colunas encontradas</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Erro na verificação $i: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
}

echo "<h2>4. 📊 Resumo Final</h2>";
echo "<div style='background: #f0f9ff; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
echo "<h3>📈 Estatísticas:</h3>";
echo "<p>• <strong>Colunas adicionadas com sucesso:</strong> $sucessos</p>";
echo "<p>• <strong>Erros encontrados:</strong> $erros</p>";
echo "<p>• <strong>SQL salvo em:</strong> CORRECAO_COLUNAS_REAIS.sql</p>";
echo "</div>";

if ($erros === 0) {
    echo "<div style='background: #d1fae5; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
    echo "<h3 style='color: #065f46;'>🎉 SUCESSO TOTAL!</h3>";
    echo "<p style='color: #065f46;'>✅ Todas as colunas importantes foram adicionadas!</p>";
    echo "<p><strong>Status:</strong> Sistema deve estar funcionando agora!</p>";
    echo "</div>";
} else {
    echo "<div style='background: #fee2e2; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
    echo "<h3 style='color: #991b1b;'>⚠️ ALGUNS ERROS ENCONTRADOS</h3>";
    echo "<p style='color: #991b1b;'>❌ $erros erros foram encontrados durante a correção.</p>";
    echo "<p>Verifique os logs acima para mais detalhes.</p>";
    echo "</div>";
}

echo "<h2>💾 Correção concluída</h2>";
?>
