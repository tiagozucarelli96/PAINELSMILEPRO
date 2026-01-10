<?php
/**
 * Script para executar SQL de adicionar campos de colaborador na tabela usuarios
 * Execute este arquivo uma vez para adicionar os campos necessários
 */

if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Verificar se está logado e tem permissão
if (empty($_SESSION['logado']) || empty($_SESSION['perm_configuracoes'])) {
    die("❌ ERRO: Você precisa estar logado e ter permissão de configurações para executar este script.");
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Executar SQL - Adicionar Campos Colaborador</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #f5f5f5;
            padding: 2rem;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 2rem;
        }
        h1 {
            color: #1e3a8a;
            margin-bottom: 1rem;
        }
        .success {
            background: #d1fae5;
            color: #065f46;
            padding: 1rem;
            border-radius: 8px;
            border-left: 4px solid #059669;
            margin: 1rem 0;
        }
        .error {
            background: #fee2e2;
            color: #991b1b;
            padding: 1rem;
            border-radius: 8px;
            border-left: 4px solid #dc2626;
            margin: 1rem 0;
        }
        .info {
            background: #eff6ff;
            color: #1e40af;
            padding: 1rem;
            border-radius: 8px;
            border-left: 4px solid #1e3a8a;
            margin: 1rem 0;
        }
        .sql-code {
            background: #f3f4f6;
            padding: 1rem;
            border-radius: 8px;
            font-family: 'Courier New', monospace;
            font-size: 0.875rem;
            margin: 1rem 0;
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }
        th {
            background: #1e3a8a;
            color: white;
            padding: 0.75rem;
            text-align: left;
        }
        td {
            padding: 0.75rem;
            border-bottom: 1px solid #e5e7eb;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔧 Executar SQL - Adicionar Campos Colaborador</h1>
        
        <?php
        $erros = [];
        $sucessos = [];
        $info = [];
        
        try {
            // Lista de comandos SQL a executar
            $sql_commands = [
                "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS cpf VARCHAR(14)" => "Adicionar coluna CPF",
                "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS rg VARCHAR(20)" => "Adicionar coluna RG",
                "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS telefone VARCHAR(20)" => "Adicionar coluna telefone",
                "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS celular VARCHAR(20)" => "Adicionar coluna celular",
                "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS endereco_cep VARCHAR(9)" => "Adicionar coluna endereco_cep",
                "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS endereco_logradouro VARCHAR(255)" => "Adicionar coluna endereco_logradouro",
                "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS endereco_numero VARCHAR(20)" => "Adicionar coluna endereco_numero",
                "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS endereco_complemento VARCHAR(100)" => "Adicionar coluna endereco_complemento",
                "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS endereco_bairro VARCHAR(100)" => "Adicionar coluna endereco_bairro",
                "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS endereco_cidade VARCHAR(100)" => "Adicionar coluna endereco_cidade",
                "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS endereco_estado VARCHAR(2)" => "Adicionar coluna endereco_estado",
                "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS nome_completo VARCHAR(255)" => "Adicionar coluna nome_completo",
            ];
            
            // Executar cada comando
            foreach ($sql_commands as $sql => $descricao) {
                try {
                    $pdo->exec($sql);
                    $sucessos[] = $descricao;
                } catch (PDOException $e) {
                    $erros[] = "$descricao: " . $e->getMessage();
                }
            }
            
            // Criar índices
            $indexes = [
                "CREATE INDEX IF NOT EXISTS idx_usuarios_cpf ON usuarios(cpf) WHERE cpf IS NOT NULL" => "Criar índice em CPF",
                "CREATE INDEX IF NOT EXISTS idx_usuarios_rg ON usuarios(rg) WHERE rg IS NOT NULL" => "Criar índice em RG",
                "CREATE INDEX IF NOT EXISTS idx_usuarios_cep ON usuarios(endereco_cep) WHERE endereco_cep IS NOT NULL" => "Criar índice em CEP",
            ];
            
            foreach ($indexes as $sql => $descricao) {
                try {
                    $pdo->exec($sql);
                    $sucessos[] = $descricao;
                } catch (PDOException $e) {
                    $erros[] = "$descricao: " . $e->getMessage();
                }
            }
            
            // Verificar colunas criadas
            $stmt = $pdo->query("
                SELECT column_name, data_type, is_nullable, column_default 
                FROM information_schema.columns 
                WHERE table_schema = 'public' 
                AND table_name = 'usuarios' 
                AND column_name IN ('cpf', 'rg', 'telefone', 'celular', 'endereco_cep', 'endereco_logradouro', 
                                    'endereco_numero', 'endereco_complemento', 'endereco_bairro', 
                                    'endereco_cidade', 'endereco_estado', 'nome_completo')
                ORDER BY column_name
            ");
            $colunas = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($colunas)) {
                $info[] = "Total de colunas verificadas: " . count($colunas);
            }
            
        } catch (Exception $e) {
            $erros[] = "Erro geral: " . $e->getMessage();
        }
        
        // Exibir resultados
        if (!empty($sucessos)) {
            echo "<div class='success'>";
            echo "<strong>✅ Sucessos (" . count($sucessos) . "):</strong><br>";
            foreach ($sucessos as $sucesso) {
                echo "• $sucesso<br>";
            }
            echo "</div>";
        }
        
        if (!empty($erros)) {
            echo "<div class='error'>";
            echo "<strong>❌ Erros (" . count($erros) . "):</strong><br>";
            foreach ($erros as $erro) {
                echo "• $erro<br>";
            }
            echo "</div>";
        }
        
        if (!empty($info)) {
            echo "<div class='info'>";
            echo "<strong>ℹ️ Informações:</strong><br>";
            foreach ($info as $i) {
                echo "• $i<br>";
            }
            echo "</div>";
        }
        
        // Mostrar colunas criadas
        if (!empty($colunas)) {
            echo "<h2 style='margin-top: 2rem; color: #374151;'>Colunas Verificadas</h2>";
            echo "<table>";
            echo "<tr><th>Coluna</th><th>Tipo</th><th>Nullable</th><th>Default</th></tr>";
            foreach ($colunas as $col) {
                echo "<tr>";
                echo "<td><strong>" . htmlspecialchars($col['column_name']) . "</strong></td>";
                echo "<td>" . htmlspecialchars($col['data_type']) . "</td>";
                echo "<td>" . ($col['is_nullable'] === 'YES' ? 'Sim' : 'Não') . "</td>";
                echo "<td>" . htmlspecialchars($col['column_default'] ?? 'NULL') . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        }
        
        if (empty($erros) && !empty($sucessos)) {
            echo "<div class='success' style='margin-top: 2rem;'>";
            echo "<strong>✅ Script executado com sucesso!</strong><br>";
            echo "Todos os campos foram adicionados à tabela usuarios. Agora você pode usar o modal com abas para cadastrar dados pessoais dos colaboradores.";
            echo "</div>";
        }
        ?>
        
        <div style="margin-top: 2rem; padding: 1rem; background: #f9fafb; border-radius: 8px;">
            <strong>📝 Próximos passos:</strong><br>
            1. Verifique se todas as colunas foram criadas corretamente<br>
            2. Teste o modal de usuários com as novas abas<br>
            3. Teste a busca de CEP<br>
            4. Verifique se os dados estão sendo salvos corretamente<br><br>
            <a href="index.php?page=usuarios" style="color: #1e3a8a; text-decoration: underline;">→ Ir para Gerenciamento de Usuários</a>
        </div>
    </div>
</body>
</html>

