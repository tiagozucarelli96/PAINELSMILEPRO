<?php
/**
 * Cria a tabela contabilidade_holerites_individual (holerite por funcionário).
 * Execução idempotente: pode ser chamado várias vezes.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/conexao.php';

function tabelaHoleritesIndividualExiste(PDO $pdo): bool {
    $stmt = $pdo->query("
        SELECT 1 FROM information_schema.tables 
        WHERE table_schema = current_schema() AND table_name = 'contabilidade_holerites_individual'
    ");
    return (bool) $stmt->fetchColumn();
}

if (!isset($pdo) || !$pdo instanceof PDO) {
    return;
}

try {
    if (tabelaHoleritesIndividualExiste($pdo)) {
        return;
    }
    $pdo->exec("
        CREATE TABLE contabilidade_holerites_individual (
            id SERIAL PRIMARY KEY,
            usuario_id INTEGER NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
            mes_competencia VARCHAR(7) NOT NULL,
            arquivo_url TEXT,
            arquivo_nome VARCHAR(255),
            chave_storage TEXT,
            criado_em TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
        )
    ");
    error_log("Tabela contabilidade_holerites_individual criada com sucesso.");
} catch (Exception $e) {
    error_log("Erro ao criar contabilidade_holerites_individual: " . $e->getMessage());
}
