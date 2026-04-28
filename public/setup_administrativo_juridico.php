<?php
/**
 * Setup idempotente para módulo Jurídico (Administrativo).
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';

if (!function_exists('setupAdministrativoJuridico')) {
    function administrativoJuridicoDescricaoPadraoPasta(string $nomeUsuario): string
    {
        return 'Pasta automática do colaborador ' . $nomeUsuario . '.';
    }

    function administrativoJuridicoGarantirPastaColaborador(PDO $pdo, int $usuarioId): ?int
    {
        if ($usuarioId <= 0) {
            return null;
        }

        $stmtUsuario = $pdo->prepare(
            'SELECT id, nome
             FROM usuarios
             WHERE id = :id
             LIMIT 1'
        );
        $stmtUsuario->execute([':id' => $usuarioId]);
        $usuario = $stmtUsuario->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$usuario) {
            return null;
        }

        $nomeUsuario = trim((string)($usuario['nome'] ?? ''));
        if ($nomeUsuario === '') {
            return null;
        }

        $descricaoPadrao = administrativoJuridicoDescricaoPadraoPasta($nomeUsuario);

        $stmtPastaUsuario = $pdo->prepare(
            'SELECT id, nome
             FROM administrativo_juridico_pastas
             WHERE usuario_empresa_id = :usuario_id
             LIMIT 1'
        );
        $stmtPastaUsuario->execute([':usuario_id' => $usuarioId]);
        $pastaUsuario = $stmtPastaUsuario->fetch(PDO::FETCH_ASSOC) ?: null;

        $stmtPastaNome = $pdo->prepare(
            'SELECT id, usuario_empresa_id, descricao
             FROM administrativo_juridico_pastas
             WHERE LOWER(nome) = LOWER(:nome)
             LIMIT 1'
        );
        $stmtPastaNome->execute([':nome' => $nomeUsuario]);
        $pastaMesmoNome = $stmtPastaNome->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($pastaMesmoNome) {
            $pastaMesmoNomeId = (int)($pastaMesmoNome['id'] ?? 0);
            $pastaMesmoNomeUsuarioId = isset($pastaMesmoNome['usuario_empresa_id']) ? (int)$pastaMesmoNome['usuario_empresa_id'] : 0;

            if ($pastaMesmoNomeUsuarioId > 0 && $pastaMesmoNomeUsuarioId !== $usuarioId) {
                if ($pastaUsuario) {
                    return (int)$pastaUsuario['id'];
                }

                error_log('Administrativo jurídico - conflito de pasta para colaborador ' . $usuarioId . ' com nome ' . $nomeUsuario);
                return null;
            }

            if ($pastaUsuario && (int)$pastaUsuario['id'] !== $pastaMesmoNomeId) {
                $stmtDesvincular = $pdo->prepare(
                    'UPDATE administrativo_juridico_pastas
                     SET usuario_empresa_id = NULL,
                         atualizado_em = NOW()
                     WHERE id = :id'
                );
                $stmtDesvincular->execute([':id' => (int)$pastaUsuario['id']]);
            }

            $stmtVincular = $pdo->prepare(
                'UPDATE administrativo_juridico_pastas
                 SET usuario_empresa_id = :usuario_id,
                     descricao = COALESCE(NULLIF(descricao, \'\'), :descricao),
                     atualizado_em = NOW()
                 WHERE id = :id'
            );
            $stmtVincular->execute([
                ':usuario_id' => $usuarioId,
                ':descricao' => $descricaoPadrao,
                ':id' => $pastaMesmoNomeId,
            ]);

            return $pastaMesmoNomeId;
        }

        if ($pastaUsuario) {
            $stmtRenomear = $pdo->prepare(
                'UPDATE administrativo_juridico_pastas
                 SET nome = :nome,
                     descricao = COALESCE(NULLIF(descricao, \'\'), :descricao),
                     atualizado_em = NOW()
                 WHERE id = :id'
            );
            $stmtRenomear->execute([
                ':nome' => $nomeUsuario,
                ':descricao' => $descricaoPadrao,
                ':id' => (int)$pastaUsuario['id'],
            ]);

            return (int)$pastaUsuario['id'];
        }

        $stmtCriar = $pdo->prepare(
            'INSERT INTO administrativo_juridico_pastas (nome, descricao, usuario_empresa_id, criado_por_usuario_id)
             VALUES (:nome, :descricao, :usuario_id, NULL)
             RETURNING id'
        );
        $stmtCriar->execute([
            ':nome' => $nomeUsuario,
            ':descricao' => $descricaoPadrao,
            ':usuario_id' => $usuarioId,
        ]);

        return (int)$stmtCriar->fetchColumn();
    }

    function administrativoJuridicoSincronizarPastasColaboradores(PDO $pdo): void
    {
        $stmt = $pdo->query(
            'SELECT id
             FROM usuarios
             WHERE ativo IS DISTINCT FROM FALSE
             ORDER BY id ASC'
        );

        $usuariosIds = $stmt ? ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];
        foreach ($usuariosIds as $usuarioId) {
            administrativoJuridicoGarantirPastaColaborador($pdo, (int)$usuarioId);
        }
    }

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

            $pdo->exec(
                "
                ALTER TABLE administrativo_juridico_pastas
                ADD COLUMN IF NOT EXISTS usuario_juridico_id BIGINT NULL
                "
            );

            $pdo->exec(
                "
                ALTER TABLE administrativo_juridico_pastas
                ADD COLUMN IF NOT EXISTS usuario_empresa_id BIGINT NULL
                "
            );

            $pdo->exec(
                "
                DO $$
                BEGIN
                    IF NOT EXISTS (
                        SELECT 1
                        FROM pg_constraint
                        WHERE conname = 'fk_admin_juridico_pastas_usuario_juridico'
                    ) THEN
                        ALTER TABLE administrativo_juridico_pastas
                        ADD CONSTRAINT fk_admin_juridico_pastas_usuario_juridico
                        FOREIGN KEY (usuario_juridico_id)
                        REFERENCES administrativo_juridico_usuarios(id)
                        ON DELETE SET NULL;
                    END IF;
                END
                $$;
                "
            );

            $pdo->exec(
                "
                DO $$
                BEGIN
                    IF NOT EXISTS (
                        SELECT 1
                        FROM pg_constraint
                        WHERE conname = 'fk_admin_juridico_pastas_usuario_empresa'
                    ) THEN
                        ALTER TABLE administrativo_juridico_pastas
                        ADD CONSTRAINT fk_admin_juridico_pastas_usuario_empresa
                        FOREIGN KEY (usuario_empresa_id)
                        REFERENCES usuarios(id)
                        ON DELETE SET NULL;
                    END IF;
                END
                $$;
                "
            );

            $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_admin_juridico_pastas_nome_unique ON administrativo_juridico_pastas (LOWER(nome))');
            $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_admin_juridico_usuarios_nome_unique ON administrativo_juridico_usuarios (LOWER(nome))');
            $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_admin_juridico_pastas_usuario_unique ON administrativo_juridico_pastas(usuario_juridico_id) WHERE usuario_juridico_id IS NOT NULL');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_admin_juridico_pastas_usuario_juridico ON administrativo_juridico_pastas(usuario_juridico_id)');
            $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_admin_juridico_pastas_usuario_empresa_unique ON administrativo_juridico_pastas(usuario_empresa_id) WHERE usuario_empresa_id IS NOT NULL');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_admin_juridico_pastas_usuario_empresa ON administrativo_juridico_pastas(usuario_empresa_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_admin_juridico_arquivos_pasta ON administrativo_juridico_arquivos(pasta_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_admin_juridico_arquivos_criado_em ON administrativo_juridico_arquivos(criado_em DESC)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_admin_juridico_usuarios_ativo ON administrativo_juridico_usuarios(ativo)');

            administrativoJuridicoSincronizarPastasColaboradores($pdo);
        } catch (Exception $e) {
            error_log('Erro setupAdministrativoJuridico: ' . $e->getMessage());
        }
    }
}

if (isset($pdo) && $pdo instanceof PDO) {
    setupAdministrativoJuridico($pdo);
}
