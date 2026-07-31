<?php
declare(strict_types=1);

function painel_password_reset_ensure_schema(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS usuarios_password_resets (
            id BIGSERIAL PRIMARY KEY,
            usuario_id BIGINT NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
            token_hash CHAR(64) NOT NULL UNIQUE,
            expires_at TIMESTAMPTZ NOT NULL,
            used_at TIMESTAMPTZ NULL,
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
        )
    SQL);
    $pdo->exec(
        'CREATE INDEX IF NOT EXISTS idx_usuarios_password_resets_validade '
        . 'ON usuarios_password_resets (usuario_id, expires_at DESC)'
    );
}

function painel_password_reset_issue(PDO $pdo, int $usuarioId, int $validadeMinutos = 30): array
{
    if ($usuarioId <= 0) {
        throw new InvalidArgumentException('Usuário inválido para redefinição de senha.');
    }

    painel_password_reset_ensure_schema($pdo);
    $validadeMinutos = max(5, min(120, $validadeMinutos));
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);

    $pdo->beginTransaction();
    try {
        $stmtInvalidate = $pdo->prepare(
            'UPDATE usuarios_password_resets '
            . 'SET used_at = NOW() '
            . 'WHERE usuario_id = :usuario_id AND used_at IS NULL'
        );
        $stmtInvalidate->execute([':usuario_id' => $usuarioId]);

        $stmtInsert = $pdo->prepare(
            'INSERT INTO usuarios_password_resets (usuario_id, token_hash, expires_at) '
            . "VALUES (:usuario_id, :token_hash, NOW() + (:validade || ' minutes')::interval) "
            . 'RETURNING expires_at'
        );
        $stmtInsert->execute([
            ':usuario_id' => $usuarioId,
            ':token_hash' => $tokenHash,
            ':validade' => (string)$validadeMinutos,
        ]);
        $expiresAt = (string)$stmtInsert->fetchColumn();
        $pdo->commit();

        return [
            'token' => $token,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function painel_password_reset_lookup(PDO $pdo, string $token, bool $lock = false): ?array
{
    if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
        return null;
    }

    painel_password_reset_ensure_schema($pdo);
    $sql = <<<'SQL'
        SELECT r.id,
               r.usuario_id,
               r.expires_at,
               r.used_at,
               u.nome,
               u.email,
               COALESCE(u.ativo, TRUE) AS ativo
        FROM usuarios_password_resets r
        INNER JOIN usuarios u ON u.id = r.usuario_id
        WHERE r.token_hash = :token_hash
          AND r.used_at IS NULL
          AND r.expires_at > NOW()
        LIMIT 1
    SQL;
    if ($lock) {
        $sql .= ' FOR UPDATE OF r';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':token_hash' => hash('sha256', $token)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    return is_array($row) ? $row : null;
}

function painel_password_reset_password_column(PDO $pdo): ?string
{
    $stmt = $pdo->query(<<<'SQL'
        SELECT column_name
        FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'usuarios'
          AND column_name IN ('senha', 'senha_hash', 'password', 'pass')
        ORDER BY CASE column_name
            WHEN 'senha' THEN 1
            WHEN 'senha_hash' THEN 2
            WHEN 'password' THEN 3
            ELSE 4
        END
        LIMIT 1
    SQL);
    $column = $stmt ? $stmt->fetchColumn() : false;
    return is_string($column) && $column !== '' ? $column : null;
}

function painel_password_reset_consume(PDO $pdo, string $token, string $novaSenha): array
{
    $passwordColumn = painel_password_reset_password_column($pdo);
    if ($passwordColumn === null) {
        throw new RuntimeException('Não foi possível localizar o campo de senha do usuário.');
    }

    $pdo->beginTransaction();
    try {
        $reset = painel_password_reset_lookup($pdo, $token, true);
        if (!$reset || empty($reset['ativo'])) {
            throw new RuntimeException('Este link é inválido, expirou ou já foi utilizado.');
        }

        $hasUpdatedAt = (bool)$pdo->query(<<<'SQL'
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = current_schema()
              AND table_name = 'usuarios'
              AND column_name = 'updated_at'
            LIMIT 1
        SQL)->fetchColumn();

        $sql = 'UPDATE usuarios SET ' . $passwordColumn . ' = :password_hash';
        if ($hasUpdatedAt) {
            $sql .= ', updated_at = NOW()';
        }
        $sql .= ' WHERE id = :usuario_id';
        $stmtUpdate = $pdo->prepare($sql);
        $stmtUpdate->execute([
            ':password_hash' => password_hash($novaSenha, PASSWORD_DEFAULT),
            ':usuario_id' => (int)$reset['usuario_id'],
        ]);

        $stmtUse = $pdo->prepare(
            'UPDATE usuarios_password_resets '
            . 'SET used_at = NOW() '
            . 'WHERE usuario_id = :usuario_id AND used_at IS NULL'
        );
        $stmtUse->execute([':usuario_id' => (int)$reset['usuario_id']]);
        $pdo->commit();

        return $reset;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function painel_password_reset_revoke(PDO $pdo, string $tokenHash): void
{
    $stmt = $pdo->prepare(
        'UPDATE usuarios_password_resets SET used_at = NOW() '
        . 'WHERE token_hash = :token_hash AND used_at IS NULL'
    );
    $stmt->execute([':token_hash' => $tokenHash]);
}
