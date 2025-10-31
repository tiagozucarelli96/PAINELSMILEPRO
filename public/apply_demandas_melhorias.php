<?php
/**
 * apply_demandas_melhorias.php
 * Script para aplicar melhorias no banco de dados (pode rodar em produção também)
 */

require_once __DIR__ . '/conexao.php';

header('Content-Type: text/html; charset=utf-8');
echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Aplicar Melhorias - Demandas</title></head><body>";
echo "<h1>🔧 Aplicando Melhorias no Sistema de Demandas</h1>";

try {
    $pdo = $GLOBALS['pdo'];
    
    $sql_file = __DIR__ . '/../sql/017_demandas_melhorias.sql';
    
    if (!file_exists($sql_file)) {
        throw new Exception("Arquivo SQL não encontrado: $sql_file");
    }
    
    $sql_content = file_get_contents($sql_file);
    
    // Dividir em comandos individuais (separados por ;)
    $commands = array_filter(
        array_map('trim', explode(';', $sql_content)),
        function($cmd) {
            return !empty($cmd) && !preg_match('/^--/', $cmd);
        }
    );
    
    $executados = 0;
    $erros = [];
    
    foreach ($commands as $command) {
        if (empty(trim($command))) continue;
        
        try {
            $pdo->exec($command);
            $executados++;
        } catch (PDOException $e) {
            // Ignorar erros de "já existe" (ALTER TABLE IF NOT EXISTS, CREATE INDEX IF NOT EXISTS)
            if (strpos($e->getMessage(), 'already exists') === false && 
                strpos($e->getMessage(), 'duplicate') === false) {
                $erros[] = [
                    'comando' => substr($command, 0, 100) . '...',
                    'erro' => $e->getMessage()
                ];
            }
        }
    }
    
    echo "<p>✅ <strong>Concluído!</strong></p>";
    echo "<p>Comandos executados: $executados</p>";
    
    if (!empty($erros)) {
        echo "<h2>⚠️ Erros (podem ser ignoráveis se já existir):</h2>";
        echo "<ul>";
        foreach ($erros as $erro) {
            echo "<li><strong>Comando:</strong> {$erro['comando']}<br>";
            echo "<strong>Erro:</strong> {$erro['erro']}</li>";
        }
        echo "</ul>";
    }
    
    // Verificar se campos foram criados
    echo "<h2>🔍 Verificação:</h2>";
    try {
        $stmt = $pdo->query("
            SELECT column_name, data_type 
            FROM information_schema.columns 
            WHERE table_name = 'demandas' 
            AND column_name IN ('prioridade', 'categoria', 'progresso', 'etapa', 'arquivado')
            ORDER BY column_name
        ");
        $campos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($campos) > 0) {
            echo "<p>✅ Campos adicionados com sucesso:</p><ul>";
            foreach ($campos as $campo) {
                echo "<li><strong>{$campo['column_name']}</strong> ({$campo['data_type']})</li>";
            }
            echo "</ul>";
        } else {
            echo "<p>⚠️ Nenhum campo novo encontrado. Pode ser que já existam ou houve erro na criação.</p>";
        }
    } catch (PDOException $e) {
        echo "<p>⚠️ Não foi possível verificar os campos: " . $e->getMessage() . "</p>";
    }
    
    echo "<p><a href='index.php?page=demandas'>← Voltar para Demandas</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ <strong>Erro:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><a href='index.php?page=demandas'>← Voltar para Demandas</a></p>";
}

echo "</body></html>";

