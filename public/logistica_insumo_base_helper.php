<?php

if (!function_exists('logistica_mb_strtolower')) {
    function logistica_mb_strtolower(string $value): string {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }
        return strtolower($value);
    }
}

if (!function_exists('logistica_normalize_insumo_base_key')) {
    function logistica_normalize_insumo_base_key(string $nome): string {
        $normalized = trim(preg_replace('/\s+/', ' ', $nome) ?? '');
        if ($normalized === '') {
            return '';
        }
        return logistica_mb_strtolower($normalized);
    }
}

if (!function_exists('logistica_ensure_insumo_base_schema')) {
    function logistica_ensure_insumo_base_schema(PDO $pdo): void {
        static $done = false;
        if ($done) {
            return;
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS logistica_insumos_base (
                id SERIAL PRIMARY KEY,
                nome_base VARCHAR(200) NOT NULL,
                chave_nome VARCHAR(220) NOT NULL UNIQUE,
                ativo BOOLEAN DEFAULT TRUE,
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW()
            )
        ");

        $pdo->exec("ALTER TABLE logistica_insumos ADD COLUMN IF NOT EXISTS insumo_base_id INTEGER");

        $pdo->exec("
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1
                    FROM pg_constraint
                    WHERE conname = 'fk_logistica_insumos_insumo_base_id'
                ) THEN
                    ALTER TABLE logistica_insumos
                    ADD CONSTRAINT fk_logistica_insumos_insumo_base_id
                    FOREIGN KEY (insumo_base_id)
                    REFERENCES logistica_insumos_base(id);
                END IF;
            END $$;
        ");

        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_logistica_insumos_insumo_base_id ON logistica_insumos (insumo_base_id)");

        $pdo->exec("
            INSERT INTO logistica_insumos_base (nome_base, chave_nome)
            SELECT DISTINCT
                TRIM(i.nome_oficial) AS nome_base,
                LOWER(REGEXP_REPLACE(TRIM(i.nome_oficial), '\\s+', ' ', 'g')) AS chave_nome
            FROM logistica_insumos i
            WHERE COALESCE(TRIM(i.nome_oficial), '') <> ''
            ON CONFLICT (chave_nome) DO NOTHING
        ");

        $pdo->exec("
            UPDATE logistica_insumos i
            SET insumo_base_id = b.id
            FROM logistica_insumos_base b
            WHERE i.insumo_base_id IS NULL
              AND b.chave_nome = LOWER(REGEXP_REPLACE(TRIM(i.nome_oficial), '\\s+', ' ', 'g'))
        ");

        $done = true;
    }
}

if (!function_exists('logistica_fetch_insumo_base_options')) {
    function logistica_fetch_insumo_base_options(PDO $pdo, bool $onlyActive = true): array {
        $sql = "SELECT id, nome_base, chave_nome FROM logistica_insumos_base";
        if ($onlyActive) {
            $sql .= " WHERE ativo IS TRUE";
        }
        $sql .= " ORDER BY nome_base";
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('logistica_find_or_create_insumo_base')) {
    function logistica_find_or_create_insumo_base(PDO $pdo, string $nomeBase): ?array {
        $nome = trim(preg_replace('/\s+/', ' ', $nomeBase) ?? '');
        $chave = logistica_normalize_insumo_base_key($nome);
        if ($chave === '') {
            return null;
        }

        $stmt = $pdo->prepare("
            INSERT INTO logistica_insumos_base (nome_base, chave_nome, ativo, created_at, updated_at)
            VALUES (:nome_base, :chave_nome, TRUE, NOW(), NOW())
            ON CONFLICT (chave_nome)
            DO UPDATE SET
                nome_base = EXCLUDED.nome_base,
                ativo = TRUE,
                updated_at = NOW()
            RETURNING id, nome_base
        ");
        $stmt->execute([
            ':nome_base' => $nome,
            ':chave_nome' => $chave
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return [
                'id' => (int)$row['id'],
                'nome_base' => (string)$row['nome_base']
            ];
        }

        return null;
    }
}

if (!function_exists('logistica_compose_insumo_display_name')) {
    function logistica_compose_insumo_display_name(?string $nomeBase, ?string $nomeOficial): string {
        $base = trim((string)$nomeBase);
        $oficial = trim((string)$nomeOficial);
        if ($base === '') {
            return $oficial;
        }
        if ($oficial === '') {
            return $base;
        }
        if (logistica_mb_strtolower($base) === logistica_mb_strtolower($oficial)) {
            return $oficial;
        }
        return $base . ' (' . $oficial . ')';
    }
}
