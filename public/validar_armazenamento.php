<?php
// validar_armazenamento.php — Script de validação da separação banco vs Magalu
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['logado']) || empty($_SESSION['perm_administrativo'])) {
    die('Acesso negado');
}

require_once __DIR__ . '/conexao.php';

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Validação de Armazenamento - Banco vs Magalu</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f5f5;
            padding: 2rem;
        }
        .container { max-width: 1200px; margin: 0 auto; }
        .header {
            background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%);
            color: white;
            padding: 2rem;
            border-radius: 12px;
            margin-bottom: 2rem;
        }
        .header h1 { font-size: 1.5rem; font-weight: 700; }
        .section {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .section-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1e40af;
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid #e5e7eb;
        }
        .status-ok { color: #059669; font-weight: 600; }
        .status-error { color: #dc2626; font-weight: 600; }
        .status-warning { color: #d97706; font-weight: 600; }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }
        th {
            background: #1e40af;
            color: white;
            padding: 0.75rem;
            text-align: left;
            font-weight: 600;
        }
        td {
            padding: 0.75rem;
            border-bottom: 1px solid #e5e7eb;
        }
        tr:hover { background: #f8fafc; }
        .badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.875rem;
            font-weight: 500;
        }
        .badge-ok { background: #d1fae5; color: #065f46; }
        .badge-error { background: #fee2e2; color: #991b1b; }
        .summary {
            background: #eff6ff;
            border-left: 4px solid #1e40af;
            padding: 1.5rem;
            border-radius: 8px;
            margin-top: 1rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔍 Validação de Armazenamento - Banco de Dados vs Magalu Cloud</h1>
            <p style="margin-top: 0.5rem; opacity: 0.9;">Verificação da separação correta entre dados estruturados e arquivos</p>
        </div>

        <?php
        $erros = [];
        $avisos = [];
        $sucessos = [];

        // 1. Verificar tabelas do banco
        echo '<div class="section">';
        echo '<h2 class="section-title">1. Verificação das Tabelas do Banco de Dados</h2>';
        
        $tabelas_contabilidade = [
            'contabilidade_acesso',
            'contabilidade_sessoes',
            'contabilidade_parcelamentos',
            'contabilidade_guias',
            'contabilidade_holerites',
            'contabilidade_honorarios',
            'contabilidade_conversas',
            'contabilidade_conversas_mensagens',
            'contabilidade_colaboradores_documentos',
            'sistema_email_config',
            'sistema_notificacoes_pendentes',
            'sistema_notificacoes_navegador'
        ];
        
        echo '<table>';
        echo '<thead><tr><th>Tabela</th><th>Status</th><th>Campos de Arquivo</th><th>Observação</th></tr></thead>';
        echo '<tbody>';
        
        foreach ($tabelas_contabilidade as $tabela) {
            try {
                $stmt = $pdo->query("
                    SELECT column_name, data_type, character_maximum_length
                    FROM information_schema.columns
                    WHERE table_name = '$tabela'
                    ORDER BY ordinal_position
                ");
                $colunas = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $tem_arquivo = false;
                $campos_arquivo = [];
                
                foreach ($colunas as $col) {
                    $nome = strtolower($col['column_name']);
                    if (strpos($nome, 'arquivo') !== false || strpos($nome, 'anexo') !== false) {
                        $tem_arquivo = true;
                        $campos_arquivo[] = $col['column_name'];
                        
                        // Verificar se é URL/texto (correto) ou BLOB (errado)
                        if (strpos($col['data_type'], 'bytea') !== false || 
                            strpos($col['data_type'], 'blob') !== false) {
                            $erros[] = "Tabela $tabela: Campo {$col['column_name']} é BLOB/BYTEA (deveria ser TEXT/VARCHAR para URL)";
                        }
                    }
                }
                
                $status = $tem_arquivo ? 
                    '<span class="badge badge-ok">✅ Referências</span>' : 
                    '<span class="badge badge-ok">✅ Sem arquivos</span>';
                
                $campos_str = $tem_arquivo ? implode(', ', $campos_arquivo) : '-';
                
                echo "<tr>";
                echo "<td><strong>$tabela</strong></td>";
                echo "<td>$status</td>";
                echo "<td>$campos_str</td>";
                echo "<td>" . ($tem_arquivo ? 'Apenas referências (URL/nome)' : 'Apenas dados estruturados') . "</td>";
                echo "</tr>";
                
                $sucessos[] = "Tabela $tabela: Estrutura correta";
                
            } catch (Exception $e) {
                $erros[] = "Erro ao verificar tabela $tabela: " . $e->getMessage();
                echo "<tr>";
                echo "<td><strong>$tabela</strong></td>";
                echo "<td><span class='badge badge-error'>❌ Erro</span></td>";
                echo "<td>-</td>";
                echo "<td>Erro: " . htmlspecialchars($e->getMessage()) . "</td>";
                echo "</tr>";
            }
        }
        
        echo '</tbody></table>';
        echo '</div>';

        // 2. Verificar código PHP
        echo '<div class="section">';
        echo '<h2 class="section-title">2. Verificação do Código PHP</h2>';
        
        $arquivos_verificar = [
            'contabilidade_guias.php',
            'contabilidade_holerites.php',
            'contabilidade_honorarios.php',
            'contabilidade_conversas.php',
            'contabilidade_colaboradores.php',
            'magalu_integration_helper.php'
        ];
        
        echo '<table>';
        echo '<thead><tr><th>Arquivo</th><th>Upload Magalu</th><th>Salvamento Banco</th><th>Status</th></tr></thead>';
        echo '<tbody>';
        
        foreach ($arquivos_verificar as $arquivo) {
            $caminho = __DIR__ . '/' . $arquivo;
            
            if (!file_exists($caminho)) {
                $erros[] = "Arquivo não encontrado: $arquivo";
                echo "<tr>";
                echo "<td><strong>$arquivo</strong></td>";
                echo "<td>-</td>";
                echo "<td>-</td>";
                echo "<td><span class='badge badge-error'>❌ Não encontrado</span></td>";
                echo "</tr>";
                continue;
            }
            
            $conteudo = file_get_contents($caminho);
            
            // Verificar se usa uploadContabilidade
            $usa_magalu = strpos($conteudo, 'uploadContabilidade') !== false;
            
            // Verificar se salva apenas referências no banco
            $salva_url = strpos($conteudo, 'arquivo_url') !== false || 
                        strpos($conteudo, 'anexo_url') !== false;
            
            // Verificar se NÃO salva conteúdo binário
            $salva_binario = strpos($conteudo, 'file_get_contents') !== false &&
                            (strpos($conteudo, 'INSERT') !== false || strpos($conteudo, 'UPDATE') !== false);
            
            $status = '✅ OK';
            $status_class = 'badge-ok';
            
            if (!$usa_magalu && ($salva_url || strpos($conteudo, 'arquivo') !== false)) {
                $status = '⚠️ Verificar';
                $status_class = 'badge-error';
                $avisos[] = "$arquivo: Pode não estar usando Magalu corretamente";
            }
            
            if ($salva_binario) {
                $status = '❌ Erro';
                $status_class = 'badge-error';
                $erros[] = "$arquivo: Pode estar salvando conteúdo binário no banco";
            }
            
            echo "<tr>";
            echo "<td><strong>$arquivo</strong></td>";
            echo "<td>" . ($usa_magalu ? '✅ Sim' : '❌ Não') . "</td>";
            echo "<td>" . ($salva_url ? '✅ Apenas URLs' : '⚠️ Verificar') . "</td>";
            echo "<td><span class='badge $status_class'>$status</span></td>";
            echo "</tr>";
            
            if ($usa_magalu && $salva_url && !$salva_binario) {
                $sucessos[] = "$arquivo: Código correto";
            }
        }
        
        echo '</tbody></table>';
        echo '</div>';

        // 3. Resumo
        echo '<div class="section">';
        echo '<h2 class="section-title">3. Resumo da Validação</h2>';
        
        echo '<div class="summary">';
        echo '<h3 style="margin-bottom: 1rem; color: #1e40af;">📊 Estatísticas</h3>';
        echo '<p><strong>✅ Sucessos:</strong> ' . count($sucessos) . '</p>';
        echo '<p><strong>⚠️ Avisos:</strong> ' . count($avisos) . '</p>';
        echo '<p><strong>❌ Erros:</strong> ' . count($erros) . '</p>';
        echo '</div>';
        
        if (!empty($sucessos)) {
            echo '<h3 style="margin-top: 1.5rem; margin-bottom: 0.5rem; color: #059669;">✅ Validações Bem-Sucedidas</h3>';
            echo '<ul style="list-style: none; padding: 0;">';
            foreach ($sucessos as $sucesso) {
                echo '<li style="padding: 0.5rem; background: #d1fae5; margin-bottom: 0.25rem; border-radius: 6px;">✅ ' . htmlspecialchars($sucesso) . '</li>';
            }
            echo '</ul>';
        }
        
        if (!empty($avisos)) {
            echo '<h3 style="margin-top: 1.5rem; margin-bottom: 0.5rem; color: #d97706;">⚠️ Avisos</h3>';
            echo '<ul style="list-style: none; padding: 0;">';
            foreach ($avisos as $aviso) {
                echo '<li style="padding: 0.5rem; background: #fef3c7; margin-bottom: 0.25rem; border-radius: 6px;">⚠️ ' . htmlspecialchars($aviso) . '</li>';
            }
            echo '</ul>';
        }
        
        if (!empty($erros)) {
            echo '<h3 style="margin-top: 1.5rem; margin-bottom: 0.5rem; color: #dc2626;">❌ Erros Encontrados</h3>';
            echo '<ul style="list-style: none; padding: 0;">';
            foreach ($erros as $erro) {
                echo '<li style="padding: 0.5rem; background: #fee2e2; margin-bottom: 0.25rem; border-radius: 6px;">❌ ' . htmlspecialchars($erro) . '</li>';
            }
            echo '</ul>';
        }
        
        if (empty($erros) && empty($avisos)) {
            echo '<div style="background: #d1fae5; padding: 1.5rem; border-radius: 8px; margin-top: 1rem; text-align: center;">';
            echo '<h3 style="color: #065f46; margin-bottom: 0.5rem;">🎉 Validação Completa!</h3>';
            echo '<p style="color: #047857;">O sistema está 100% conforme a regra fundamental de separação entre banco de dados e Magalu Cloud Storage.</p>';
            echo '</div>';
        }
        
        echo '</div>';
        ?>
    </div>
</body>
</html>
