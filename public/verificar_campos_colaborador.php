<?php
/**
 * Script para verificar se os campos de colaborador estão corretos no banco e no código
 */

if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Verificar se está logado
if (empty($_SESSION['logado']) || empty($_SESSION['perm_configuracoes'])) {
    die("❌ ERRO: Você precisa estar logado e ter permissão de configurações.");
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificar Campos Colaborador</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #f5f5f5;
            padding: 2rem;
        }
        .container {
            max-width: 1200px;
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
        h2 {
            color: #374151;
            margin-top: 2rem;
            margin-bottom: 1rem;
            font-size: 1.25rem;
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
        .warning {
            background: #fef3c7;
            color: #92400e;
            padding: 1rem;
            border-radius: 8px;
            border-left: 4px solid #f59e0b;
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
        tr:hover {
            background: #f9fafb;
        }
        .status-ok {
            color: #059669;
            font-weight: 600;
        }
        .status-error {
            color: #dc2626;
            font-weight: 600;
        }
        .status-warning {
            color: #f59e0b;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Verificação de Campos Colaborador</h1>
        
        <?php
        $erros = [];
        $avisos = [];
        $sucessos = [];
        
        // Lista de campos esperados
        $camposEsperados = [
            'nome_completo' => 'Nome Completo',
            'cpf' => 'CPF',
            'rg' => 'RG',
            'telefone' => 'Telefone',
            'celular' => 'Celular',
            'endereco_cep' => 'CEP',
            'endereco_logradouro' => 'Logradouro',
            'endereco_numero' => 'Número',
            'endereco_complemento' => 'Complemento',
            'endereco_bairro' => 'Bairro',
            'endereco_cidade' => 'Cidade',
            'endereco_estado' => 'Estado (UF)'
        ];
        
        // 1. Verificar colunas no banco de dados
        echo "<h2>1. Verificação no Banco de Dados</h2>";
        
        try {
            $stmt = $pdo->query("
                SELECT column_name, data_type, is_nullable, character_maximum_length
                FROM information_schema.columns 
                WHERE table_schema = 'smilee12_painel_smile' 
                AND table_name = 'usuarios' 
                AND column_name IN ('" . implode("', '", array_keys($camposEsperados)) . "')
                ORDER BY column_name
            ");
            $colunasBanco = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $colunasBancoMap = [];
            foreach ($colunasBanco as $col) {
                $colunasBancoMap[$col['column_name']] = $col;
            }
            
            echo "<table>";
            echo "<tr><th>Campo</th><th>Status</th><th>Tipo</th><th>Nullable</th><th>Tamanho Máx</th></tr>";
            
            foreach ($camposEsperados as $campo => $nome) {
                if (isset($colunasBancoMap[$campo])) {
                    $col = $colunasBancoMap[$campo];
                    echo "<tr>";
                    echo "<td><strong>$nome</strong><br><small style='color: #64748b;'>$campo</small></td>";
                    echo "<td class='status-ok'>✅ Existe</td>";
                    echo "<td>" . htmlspecialchars($col['data_type']) . "</td>";
                    echo "<td>" . ($col['is_nullable'] === 'YES' ? 'Sim' : 'Não') . "</td>";
                    echo "<td>" . ($col['character_maximum_length'] ?? 'N/A') . "</td>";
                    echo "</tr>";
                    $sucessos[] = "Campo $campo existe no banco";
                } else {
                    echo "<tr>";
                    echo "<td><strong>$nome</strong><br><small style='color: #64748b;'>$campo</small></td>";
                    echo "<td class='status-error'>❌ Não encontrado</td>";
                    echo "<td colspan='3'>-</td>";
                    echo "</tr>";
                    $erros[] = "Campo $campo não encontrado no banco de dados";
                }
            }
            echo "</table>";
            
        } catch (Exception $e) {
            echo "<div class='error'>Erro ao verificar banco: " . htmlspecialchars($e->getMessage()) . "</div>";
            $erros[] = "Erro ao verificar banco: " . $e->getMessage();
        }
        
        // 2. Verificar código de salvamento
        echo "<h2>2. Verificação no Código de Salvamento</h2>";
        
        $arquivoSave = __DIR__ . '/usuarios_save_robust.php';
        if (file_exists($arquivoSave)) {
            $conteudoSave = file_get_contents($arquivoSave);
            
            echo "<table>";
            echo "<tr><th>Campo</th><th>Status no Código</th></tr>";
            
            foreach ($camposEsperados as $campo => $nome) {
                $encontrado = strpos($conteudoSave, "'$campo'") !== false || 
                             strpos($conteudoSave, "\"$campo\"") !== false ||
                             strpos($conteudoSave, "'$campo'") !== false;
                
                if ($encontrado) {
                    echo "<tr>";
                    echo "<td><strong>$nome</strong><br><small style='color: #64748b;'>$campo</small></td>";
                    echo "<td class='status-ok'>✅ Encontrado</td>";
                    echo "</tr>";
                    $sucessos[] = "Campo $campo encontrado no código de salvamento";
                } else {
                    echo "<tr>";
                    echo "<td><strong>$nome</strong><br><small style='color: #64748b;'>$campo</small></td>";
                    echo "<td class='status-error'>❌ Não encontrado</td>";
                    echo "</tr>";
                    $erros[] = "Campo $campo não encontrado no código de salvamento";
                }
            }
            echo "</table>";
        } else {
            echo "<div class='error'>Arquivo usuarios_save_robust.php não encontrado!</div>";
            $erros[] = "Arquivo de salvamento não encontrado";
        }
        
        // 3. Verificar código de carregamento (usuarios_new.php)
        echo "<h2>3. Verificação no Código de Carregamento</h2>";
        
        $arquivoNew = __DIR__ . '/usuarios_new.php';
        if (file_exists($arquivoNew)) {
            $conteudoNew = file_get_contents($arquivoNew);
            
            // Verificar se usa SELECT *
            $usaSelectAll = strpos($conteudoNew, 'SELECT * FROM usuarios') !== false;
            
            if ($usaSelectAll) {
                echo "<div class='success'>✅ Usa SELECT * - Todos os campos serão carregados automaticamente</div>";
                $sucessos[] = "Código usa SELECT * para carregar todos os campos";
            } else {
                echo "<div class='warning'>⚠️ Não usa SELECT * - Verifique se todos os campos estão no SELECT</div>";
                $avisos[] = "Código não usa SELECT *, pode não carregar todos os campos";
            }
            
            // Verificar se os campos estão sendo populados no JavaScript
            echo "<table>";
            echo "<tr><th>Campo</th><th>Status no JavaScript</th></tr>";
            
            foreach ($camposEsperados as $campo => $nome) {
                $encontrado = strpos($conteudoNew, "name=\"$campo\"") !== false || 
                             strpos($conteudoNew, "name='$campo'") !== false ||
                             strpos($conteudoNew, "[name=\"$campo\"]") !== false ||
                             strpos($conteudoNew, "[name='$campo']") !== false;
                
                if ($encontrado) {
                    echo "<tr>";
                    echo "<td><strong>$nome</strong><br><small style='color: #64748b;'>$campo</small></td>";
                    echo "<td class='status-ok'>✅ Encontrado</td>";
                    echo "</tr>";
                    $sucessos[] = "Campo $campo encontrado no formulário/JavaScript";
                } else {
                    echo "<tr>";
                    echo "<td><strong>$nome</strong><br><small style='color: #64748b;'>$campo</small></td>";
                    echo "<td class='status-error'>❌ Não encontrado</td>";
                    echo "</tr>";
                    $erros[] = "Campo $campo não encontrado no formulário/JavaScript";
                }
            }
            echo "</table>";
        } else {
            echo "<div class='error'>Arquivo usuarios_new.php não encontrado!</div>";
            $erros[] = "Arquivo de interface não encontrado";
        }
        
        // 4. Resumo
        echo "<h2>4. Resumo da Verificação</h2>";
        
        if (!empty($sucessos)) {
            echo "<div class='success'>";
            echo "<strong>✅ Sucessos (" . count($sucessos) . "):</strong><br>";
            echo "Todos os campos esperados foram encontrados no banco e no código.";
            echo "</div>";
        }
        
        if (!empty($avisos)) {
            echo "<div class='warning'>";
            echo "<strong>⚠️ Avisos (" . count($avisos) . "):</strong><br>";
            foreach ($avisos as $aviso) {
                echo "• $aviso<br>";
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
        } else {
            echo "<div class='success' style='margin-top: 1rem;'>";
            echo "<strong>✅ Tudo OK!</strong><br>";
            echo "Todos os campos estão corretamente configurados no banco de dados e no código.";
            echo "</div>";
        }
        ?>
        
        <div style="margin-top: 2rem; padding: 1rem; background: #f9fafb; border-radius: 8px;">
            <strong>📝 Próximos passos:</strong><br>
            1. Se houver erros, corrija-os antes de testar<br>
            2. Teste criando um novo usuário com dados pessoais<br>
            3. Teste editando um usuário existente<br>
            4. Verifique se os dados estão sendo salvos corretamente<br><br>
            <a href="index.php?page=usuarios" style="color: #1e3a8a; text-decoration: underline;">→ Ir para Gerenciamento de Usuários</a>
        </div>
    </div>
</body>
</html>
