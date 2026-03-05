<?php
/**
 * Setup idempotente para módulo Jurídico (Administrativo).
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';

if (!function_exists('setupAdministrativoJuridico')) {
    function setupAdministrativoJuridico(PDO $pdo): void
    {
        try {
            $pdo->exec(
                "
                CREATE TABLE IF NOT EXISTS administrativo_juridico_pastas (
                    id BIGSERIAL PRIMARY KEY,
                    nome VARCHAR(150) NOT NULL,
                    descricao TEXT,
                    criado_por_usuario_id BIGINT REFERENCES usuarios(id) ON DELETE SET NULL,
                    criado_em TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                    atualizado_em TIMESTAMPTZ NOT NULL DEFAULT NOW()
                )
                "
            );

            $pdo->exec(
                "
                CREATE TABLE IF NOT EXISTS administrativo_juridico_arquivos (
                    id BIGSERIAL PRIMARY KEY,
                    pasta_id BIGINT NOT NULL REFERENCES administrativo_juridico_pastas(id) ON DELETE CASCADE,
                    titulo VARCHAR(255) NOT NULL,
                    descricao TEXT,
                    arquivo_nome VARCHAR(255) NOT NULL,
                    arquivo_url TEXT,
                    chave_storage TEXT,
                    mime_type VARCHAR(120),
                    tamanho_bytes BIGINT,
                    criado_por_usuario_id BIGINT REFERENCES usuarios(id) ON DELETE SET NULL,
                    criado_em TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                    atualizado_em TIMESTAMPTZ NOT NULL DEFAULT NOW()
                )
                "
            );

            $pdo->exec(
                "
                CREATE TABLE IF NOT EXISTS administrativo_juridico_usuarios (
                    id BIGSERIAL PRIMARY KEY,
                    nome VARCHAR(120) NOT NULL,
                    email VARCHAR(180),
                    senha_hash TEXT NOT NULL,
                    ativo BOOLEAN NOT NULL DEFAULT TRUE,
                    criado_por_usuario_id BIGINT REFERENCES usuarios(id) ON DELETE SET NULL,
                    criado_em TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                    atualizado_em TIMESTAMPTZ NOT NULL DEFAULT NOW()
                )
                "
            );

            $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_admin_juridico_pastas_nome_unique ON administrativo_juridico_pastas (LOWER(nome))');
            $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_admin_juridico_usuarios_nome_unique ON administrativo_juridico_usuarios (LOWER(nome))');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_admin_juridico_arquivos_pasta ON administrativo_juridico_arquivos(pasta_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_admin_juridico_arquivos_criado_em ON administrativo_juridico_arquivos(criado_em DESC)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_admin_juridico_usuarios_ativo ON administrativo_juridico_usuarios(ativo)');
        } catch (Exception $e) {
            error_log('Erro setupAdministrativoJuridico: ' . $e->getMessage());
        }
    }
}

if (isset($pdo) && $pdo instanceof PDO) {
    setupAdministrativoJuridico($pdo);
}
