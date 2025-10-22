<?php
// test_anexos.php
// Teste completo do sistema de anexos

session_start();
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/lc_anexos_helper.php';

echo "<!DOCTYPE html><html lang='pt-BR'><head><meta charset='UTF-8'><title>Teste Sistema de Anexos</title>";
echo "<style>body{font-family: sans-serif; margin: 20px; background-color: #f4f7f6; color: #333;}";
echo "h1, h2 {color: #2c3e50;} .success {color: green;} .error {color: red;} .warning {color: orange;}";
echo "pre {background-color: #eee; padding: 10px; border-radius: 5px;}</style></head><body>";
echo "<h1>🧪 Teste do Sistema de Anexos</h1>";

if (!$pdo) {
    echo "<p class='error'>❌ Erro de conexão com o banco de dados: " . htmlspecialchars($db_error) . "</p>";
    exit;
}

echo "<h2>1. 🔄 Aplicando SQL de Anexos</h2>";
try {
    $sql_script_path = __DIR__ . '/../sql/012_sistema_anexos.sql';
    if (file_exists($sql_script_path)) {
        $sql_commands = file_get_contents($sql_script_path);
        $pdo->exec($sql_commands);
        echo "<p class='success'>✅ Script de anexos executado com sucesso.</p>";
    } else {
        echo "<p class='error'>❌ Arquivo SQL não encontrado: <code>{$sql_script_path}</code></p>";
    }
} catch (PDOException $e) {
    echo "<p class='error'>❌ Erro ao executar script SQL: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "<h2>2. 🧪 Teste de Criação de Solicitação com Anexos</h2>";
try {
    // Criar uma solicitação de teste
    $stmt = $pdo->prepare("
        INSERT INTO lc_solicitacoes_pagamento 
        (beneficiario_tipo, freelancer_id, valor, observacoes, pix_tipo, pix_chave, status, origem)
        VALUES ('freelancer', 1, 150.75, 'Teste de anexos', 'cpf', '12345678901', 'aguardando', 'teste')
        ON CONFLICT DO NOTHING
    ");
    
    $stmt->execute();
    $solicitacao_id = $pdo->lastInsertId();
    
    if ($solicitacao_id) {
        echo "<p class='success'>✅ Solicitação de teste criada (ID: {$solicitacao_id})</p>";
    } else {
        // Buscar ID existente
        $stmt = $pdo->query("SELECT id FROM lc_solicitacoes_pagamento WHERE observacoes = 'Teste de anexos' LIMIT 1");
        $solicitacao_id = $stmt->fetchColumn();
        echo "<p class='warning'>⚠️ Usando solicitação existente (ID: {$solicitacao_id})</p>";
    }
    
} catch (Exception $e) {
    echo "<p class='error'>❌ Erro ao criar solicitação: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<h2>3. 📁 Teste de Upload de Anexos</h2>";
try {
    if (isset($solicitacao_id) && $solicitacao_id) {
        $anexos_manager = new LcAnexosManager($pdo);
        
        // Simular arquivo de teste
        $arquivo_teste = [
            'name' => 'documento_teste.pdf',
            'type' => 'application/pdf',
            'tmp_name' => '/tmp/teste.pdf',
            'error' => UPLOAD_ERR_OK,
            'size' => 1024
        ];
        
        // Criar arquivo temporário para teste
        $temp_file = tempnam(sys_get_temp_dir(), 'test_anexo');
        file_put_contents($temp_file, 'Conteúdo de teste do anexo');
        $arquivo_teste['tmp_name'] = $temp_file;
        
        // Testar upload
        $resultado = $anexos_manager->fazerUpload(
            $arquivo_teste,
            $solicitacao_id,
            1, // usuario_id
            'interno'
        );
        
        if ($resultado['sucesso']) {
            echo "<p class='success'>✅ Upload de anexo simulado com sucesso</p>";
            echo "<p><strong>Anexo ID:</strong> {$resultado['anexo_id']}</p>";
            echo "<p><strong>Nome do arquivo:</strong> {$resultado['nome_arquivo']}</p>";
            echo "<p><strong>Tamanho:</strong> {$resultado['tamanho']} bytes</p>";
        } else {
            echo "<p class='error'>❌ Erro no upload: " . implode(', ', $resultado['erros']) . "</p>";
        }
        
        // Limpar arquivo temporário
        unlink($temp_file);
        
    } else {
        echo "<p class='warning'>⚠️ Nenhuma solicitação disponível para teste</p>";
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ Erro no teste de upload: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<h2>4. 🔍 Teste de Busca de Anexos</h2>";
try {
    if (isset($solicitacao_id) && $solicitacao_id) {
        $anexos_manager = new LcAnexosManager($pdo);
        $anexos = $anexos_manager->buscarAnexos($solicitacao_id);
        
        if (!empty($anexos)) {
            echo "<p class='success'>✅ Busca de anexos bem-sucedida</p>";
            echo "<p><strong>Total de anexos:</strong> " . count($anexos) . "</p>";
            
            foreach ($anexos as $anexo) {
                echo "<div style='background: #f8f9fa; padding: 10px; margin: 5px 0; border-radius: 5px;'>";
                echo "<strong>Nome:</strong> " . htmlspecialchars($anexo['nome_original']) . "<br>";
                echo "<strong>Tamanho:</strong> " . $anexo['tamanho_formatado'] . "<br>";
                echo "<strong>Tipo:</strong> " . $anexo['tipo_mime'] . "<br>";
                echo "<strong>Comprovante:</strong> " . ($anexo['eh_comprovante'] ? 'Sim' : 'Não') . "<br>";
                echo "<strong>Criado em:</strong> " . $anexo['criado_em'];
                echo "</div>";
            }
        } else {
            echo "<p class='warning'>⚠️ Nenhum anexo encontrado</p>";
        }
    } else {
        echo "<p class='warning'>⚠️ Nenhuma solicitação disponível para teste</p>";
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ Erro na busca de anexos: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<h2>5. 🔒 Teste de Permissões de Download</h2>";
try {
    if (isset($solicitacao_id) && $solicitacao_id) {
        $anexos_manager = new LcAnexosManager($pdo);
        
        // Buscar primeiro anexo
        $stmt = $pdo->prepare("SELECT id FROM lc_anexos_pagamentos WHERE solicitacao_id = ? LIMIT 1");
        $stmt->execute([$solicitacao_id]);
        $anexo_id = $stmt->fetchColumn();
        
        if ($anexo_id) {
            // Testar permissão para usuário logado
            $pode_baixar = $anexos_manager->verificarPermissaoDownload($anexo_id, 1); // usuario_id = 1
            echo "<p><strong>Permissão de download (usuário 1):</strong> " . ($pode_baixar ? '✅ Permitido' : '❌ Negado') . "</p>";
            
            // Testar permissão para token público
            $pode_baixar_token = $anexos_manager->verificarPermissaoDownload($anexo_id, null, 'token_teste');
            echo "<p><strong>Permissão de download (token):</strong> " . ($pode_baixar_token ? '✅ Permitido' : '❌ Negado') . "</p>";
            
        } else {
            echo "<p class='warning'>⚠️ Nenhum anexo encontrado para teste de permissões</p>";
        }
    } else {
        echo "<p class='warning'>⚠️ Nenhuma solicitação disponível para teste</p>";
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ Erro no teste de permissões: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<h2>6. 📊 Estatísticas</h2>";
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM lc_anexos_pagamentos");
    $total_anexos = $stmt->fetchColumn();
    echo "<p>Total de anexos: <strong>{$total_anexos}</strong></p>";
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM lc_anexos_pagamentos WHERE eh_comprovante = true");
    $comprovantes = $stmt->fetchColumn();
    echo "<p>Comprovantes: <strong>{$comprovantes}</strong></p>";
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM lc_anexos_miniaturas");
    $miniaturas = $stmt->fetchColumn();
    echo "<p>Miniaturas geradas: <strong>{$miniaturas}</strong></p>";
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM lc_anexos_logs_download");
    $downloads = $stmt->fetchColumn();
    echo "<p>Downloads registrados: <strong>{$downloads}</strong></p>";
    
} catch (Exception $e) {
    echo "<p class='error'>❌ Erro ao buscar estatísticas: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<h2>7. 🧹 Teste de Limpeza</h2>";
try {
    // Remover anexos de teste
    $stmt = $pdo->prepare("DELETE FROM lc_anexos_pagamentos WHERE solicitacao_id IN (SELECT id FROM lc_solicitacoes_pagamento WHERE observacoes = 'Teste de anexos')");
    $stmt->execute();
    $anexos_removidos = $stmt->rowCount();
    
    $stmt = $pdo->prepare("DELETE FROM lc_solicitacoes_pagamento WHERE observacoes = 'Teste de anexos'");
    $stmt->execute();
    $solicitacoes_removidas = $stmt->rowCount();
    
    echo "<p class='success'>✅ Limpeza concluída</p>";
    echo "<p><strong>Anexos removidos:</strong> {$anexos_removidos}</p>";
    echo "<p><strong>Solicitações removidas:</strong> {$solicitacoes_removidas}</p>";
    
} catch (Exception $e) {
    echo "<p class='error'>❌ Erro na limpeza: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<p><a href='pagamentos_painel.php' style='color: #1e40af; text-decoration: none; padding: 10px 20px; background: #e0f2fe; border-radius: 5px;'>📊 Painel Financeiro</a></p>";
echo "<p><a href='fornecedores.php' style='color: #1e40af; text-decoration: none; padding: 10px 20px; background: #e0f2fe; border-radius: 5px;'>🏢 Fornecedores</a></p>";
echo "<p><a href='lc_index.php' style='color: #1e40af; text-decoration: none; padding: 10px 20px; background: #e0f2fe; border-radius: 5px;'>🏠 Voltar para lc_index.php</a></p>";

echo "</body></html>";
?>
