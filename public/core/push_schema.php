<?php
// core/push_schema.php — Garantir schema do Web Push (compatível com versões antigas)
declare(strict_types=1);

/**
 * Garante que a tabela `sistema_notificacoes_navegador` tenha as colunas usadas pelo sistema de push.
 * - Compatível com schema antigo (que tinha só endpoint/chaves/ativo)
 * - Evita que o fluxo de consentimento “falhe silenciosamente” por falta de colunas
 */
function push_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;

    // 1) Garantir que a tabela exista (mínimo)
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sistema_notificacoes_navegador (
                id BIGSERIAL PRIMARY KEY,
                usuario_id BIGINT NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
                endpoint TEXT NOT NULL,
                chave_publica TEXT,
                chave_autenticacao TEXT,
                consentimento_permitido BOOLEAN NOT NULL DEFAULT FALSE,
                data_autorizacao TIMESTAMP,
                ativo BOOLEAN NOT NULL DEFAULT TRUE,
                criado_em TIMESTAMP NOT NULL DEFAULT NOW(),
                atualizado_em TIMESTAMP NOT NULL DEFAULT NOW(),
                UNIQUE(usuario_id, endpoint)
            )
        ");
    } catch (Throwable $e) {
        // Se não conseguir criar (permissão), ainda assim tentar seguir — endpoints vão reportar erro.
    }

    // 2) Garantir colunas novas (ALTER ... IF NOT EXISTS)
    // Obs.: não dependemos de schema fixo (public/current_schema); usamos ALTER direto.
    try {
        $pdo->exec("
            ALTER TABLE sistema_notificacoes_navegador
                ADD COLUMN IF NOT EXISTS usuario_id BIGINT REFERENCES usuarios(id) ON DELETE CASCADE,
                ADD COLUMN IF NOT EXISTS endpoint TEXT,
                ADD COLUMN IF NOT EXISTS chave_publica TEXT,
                ADD COLUMN IF NOT EXISTS chave_autenticacao TEXT,
                ADD COLUMN IF NOT EXISTS consentimento_permitido BOOLEAN NOT NULL DEFAULT FALSE,
                ADD COLUMN IF NOT EXISTS data_autorizacao TIMESTAMP,
                ADD COLUMN IF NOT EXISTS ativo BOOLEAN NOT NULL DEFAULT TRUE,
                ADD COLUMN IF NOT EXISTS criado_em TIMESTAMP NOT NULL DEFAULT NOW(),
                ADD COLUMN IF NOT EXISTS atualizado_em TIMESTAMP NOT NULL DEFAULT NOW()
        ");
    } catch (Throwable $e) {
        // Ignorar — em versões antigas pode falhar por endpoint NOT NULL sem default.
    }

    // 3) Garantir constraint UNIQUE(usuario_id, endpoint)
    try {
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_sistema_notificacoes_navegador_usuario_endpoint ON sistema_notificacoes_navegador(usuario_id, endpoint)");
    } catch (Throwable $e) {
        // Ignorar
    }

    // 4) Índices úteis
    try {
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sistema_notificacoes_navegador_usuario ON sistema_notificacoes_navegador(usuario_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sistema_notificacoes_navegador_ativo_consent ON sistema_notificacoes_navegador(ativo, consentimento_permitido)");
    } catch (Throwable $e) {
        // Ignorar
    }

    $done = true;
}

