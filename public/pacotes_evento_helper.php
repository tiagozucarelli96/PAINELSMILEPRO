<?php
/**
 * pacotes_evento_helper.php
 * Cadastro de pacotes do evento + vínculo com reunião de eventos.
 */

require_once __DIR__ . '/conexao.php';

/**
 * Garante estrutura de pacotes de evento e vínculo na reunião.
 */
function pacotes_evento_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) {
        return;
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS logistica_pacotes_evento (
                id BIGSERIAL PRIMARY KEY,
                nome VARCHAR(180) NOT NULL,
                descricao TEXT NULL,
                oculto BOOLEAN NOT NULL DEFAULT FALSE,
                created_by_user_id INTEGER NULL,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
                deleted_at TIMESTAMP NULL,
                deleted_by_user_id INTEGER NULL
            )
        ");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_pacotes_evento ADD COLUMN IF NOT EXISTS nome VARCHAR(180)");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_pacotes_evento ADD COLUMN IF NOT EXISTS descricao TEXT NULL");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_pacotes_evento ADD COLUMN IF NOT EXISTS oculto BOOLEAN NOT NULL DEFAULT FALSE");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_pacotes_evento ADD COLUMN IF NOT EXISTS created_by_user_id INTEGER NULL");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_pacotes_evento ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NOT NULL DEFAULT NOW()");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_pacotes_evento ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT NOW()");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_pacotes_evento ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_pacotes_evento ADD COLUMN IF NOT EXISTS deleted_by_user_id INTEGER NULL");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_logistica_pacotes_evento_nome ON logistica_pacotes_evento(lower(nome))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_logistica_pacotes_evento_oculto ON logistica_pacotes_evento(oculto, deleted_at)");
    } catch (Throwable $e) {
        error_log('pacotes_evento_ensure_schema tabela pacotes: ' . $e->getMessage());
    }

    try {
        $pdo->exec("ALTER TABLE IF EXISTS eventos_reunioes ADD COLUMN IF NOT EXISTS pacote_evento_id BIGINT NULL");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_reunioes_pacote_evento_id ON eventos_reunioes(pacote_evento_id)");
    } catch (Throwable $e) {
        error_log('pacotes_evento_ensure_schema vínculo reunião: ' . $e->getMessage());
    }

    $done = true;
}

/**
 * Lista pacotes do evento.
 */
function pacotes_evento_listar(PDO $pdo, bool $incluir_ocultos = false): array {
    pacotes_evento_ensure_schema($pdo);
    $where_ocultos = $incluir_ocultos ? '' : 'AND COALESCE(oculto, FALSE) = FALSE';

    try {
        $stmt = $pdo->prepare("
            SELECT *
            FROM logistica_pacotes_evento
            WHERE deleted_at IS NULL
              {$where_ocultos}
            ORDER BY lower(nome) ASC, id ASC
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('pacotes_evento_listar: ' . $e->getMessage());
        return [];
    }

    foreach ($rows as &$row) {
        $row['id'] = (int)($row['id'] ?? 0);
        $row['nome'] = trim((string)($row['nome'] ?? ''));
        $row['descricao'] = trim((string)($row['descricao'] ?? ''));
        $row['oculto'] = !empty($row['oculto']);
    }
    unset($row);

    return $rows;
}

/**
 * Busca um pacote do evento por ID.
 */
function pacotes_evento_get(PDO $pdo, int $pacote_id): ?array {
    pacotes_evento_ensure_schema($pdo);
    if ($pacote_id <= 0) {
        return null;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT *
            FROM logistica_pacotes_evento
            WHERE id = :id
              AND deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([':id' => $pacote_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        error_log('pacotes_evento_get: ' . $e->getMessage());
        return null;
    }

    if (!$row) {
        return null;
    }

    $row['id'] = (int)($row['id'] ?? 0);
    $row['nome'] = trim((string)($row['nome'] ?? ''));
    $row['descricao'] = trim((string)($row['descricao'] ?? ''));
    $row['oculto'] = !empty($row['oculto']);
    return $row;
}

/**
 * Cria ou atualiza pacote.
 */
function pacotes_evento_salvar(
    PDO $pdo,
    int $pacote_id,
    string $nome,
    ?string $descricao = null,
    bool $oculto = false,
    int $user_id = 0
): array {
    pacotes_evento_ensure_schema($pdo);

    $nome_limpo = trim($nome);
    if ($nome_limpo === '') {
        return ['ok' => false, 'error' => 'Nome do pacote é obrigatório.'];
    }
    if (function_exists('mb_strlen') && mb_strlen($nome_limpo, 'UTF-8') > 180) {
        return ['ok' => false, 'error' => 'Nome do pacote muito longo (máximo 180 caracteres).'];
    }
    $descricao_limpa = trim((string)($descricao ?? ''));
    if (function_exists('mb_strlen') && mb_strlen($descricao_limpa, 'UTF-8') > 2000) {
        $descricao_limpa = mb_substr($descricao_limpa, 0, 2000, 'UTF-8');
    }

    try {
        if ($pacote_id > 0) {
            $stmt = $pdo->prepare("
                UPDATE logistica_pacotes_evento
                SET nome = :nome,
                    descricao = :descricao,
                    oculto = :oculto,
                    updated_at = NOW()
                WHERE id = :id
                  AND deleted_at IS NULL
                RETURNING *
            ");
            $stmt->execute([
                ':nome' => $nome_limpo,
                ':descricao' => $descricao_limpa !== '' ? $descricao_limpa : null,
                ':oculto' => $oculto ? 1 : 0,
                ':id' => $pacote_id,
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$row) {
                return ['ok' => false, 'error' => 'Pacote não encontrado para atualização.'];
            }
            return ['ok' => true, 'mode' => 'updated', 'pacote' => $row];
        }

        $stmt = $pdo->prepare("
            INSERT INTO logistica_pacotes_evento
                (nome, descricao, oculto, created_by_user_id, created_at, updated_at)
            VALUES
                (:nome, :descricao, :oculto, :created_by_user_id, NOW(), NOW())
            RETURNING *
        ");
        $stmt->execute([
            ':nome' => $nome_limpo,
            ':descricao' => $descricao_limpa !== '' ? $descricao_limpa : null,
            ':oculto' => $oculto ? 1 : 0,
            ':created_by_user_id' => $user_id > 0 ? $user_id : null,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        return ['ok' => true, 'mode' => 'created', 'pacote' => $row];
    } catch (Throwable $e) {
        error_log('pacotes_evento_salvar: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Erro ao salvar pacote do evento.'];
    }
}

/**
 * Oculta ou reexibe pacote.
 */
function pacotes_evento_alterar_oculto(PDO $pdo, int $pacote_id, bool $oculto): array {
    pacotes_evento_ensure_schema($pdo);
    if ($pacote_id <= 0) {
        return ['ok' => false, 'error' => 'Pacote inválido.'];
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE logistica_pacotes_evento
            SET oculto = :oculto,
                updated_at = NOW()
            WHERE id = :id
              AND deleted_at IS NULL
            RETURNING *
        ");
        $stmt->execute([
            ':oculto' => $oculto ? 1 : 0,
            ':id' => $pacote_id,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) {
            return ['ok' => false, 'error' => 'Pacote não encontrado.'];
        }
        return ['ok' => true, 'pacote' => $row];
    } catch (Throwable $e) {
        error_log('pacotes_evento_alterar_oculto: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Erro ao alterar visibilidade do pacote.'];
    }
}

/**
 * Exclui pacote (soft delete).
 */
function pacotes_evento_excluir(PDO $pdo, int $pacote_id, int $user_id = 0): array {
    pacotes_evento_ensure_schema($pdo);
    if ($pacote_id <= 0) {
        return ['ok' => false, 'error' => 'Pacote inválido.'];
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE logistica_pacotes_evento
            SET deleted_at = NOW(),
                deleted_by_user_id = :user_id,
                updated_at = NOW()
            WHERE id = :id
              AND deleted_at IS NULL
            RETURNING *
        ");
        $stmt->execute([
            ':id' => $pacote_id,
            ':user_id' => $user_id > 0 ? $user_id : null,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) {
            return ['ok' => false, 'error' => 'Pacote não encontrado.'];
        }

        // Desvincula pacote já removido das reuniões.
        $stmt_clear = $pdo->prepare("
            UPDATE eventos_reunioes
            SET pacote_evento_id = NULL,
                updated_at = NOW()
            WHERE pacote_evento_id = :pacote_id
        ");
        $stmt_clear->execute([':pacote_id' => $pacote_id]);

        return ['ok' => true, 'pacote' => $row];
    } catch (Throwable $e) {
        error_log('pacotes_evento_excluir: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Erro ao excluir pacote do evento.'];
    }
}

/**
 * Atualiza pacote selecionado na organização do evento.
 */
function eventos_reuniao_atualizar_pacote_evento(PDO $pdo, int $meeting_id, ?int $pacote_evento_id): array {
    pacotes_evento_ensure_schema($pdo);
    if ($meeting_id <= 0) {
        return ['ok' => false, 'error' => 'Reunião inválida.'];
    }

    $pacote_id = ($pacote_evento_id !== null && $pacote_evento_id > 0) ? (int)$pacote_evento_id : null;
    if ($pacote_id !== null) {
        $pkg = pacotes_evento_get($pdo, $pacote_id);
        if (!$pkg) {
            return ['ok' => false, 'error' => 'Pacote do evento não encontrado.'];
        }
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE eventos_reunioes
            SET pacote_evento_id = :pacote_evento_id,
                updated_at = NOW()
            WHERE id = :id
            RETURNING *
        ");
        $stmt->execute([
            ':pacote_evento_id' => $pacote_id,
            ':id' => $meeting_id,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) {
            return ['ok' => false, 'error' => 'Reunião não encontrada.'];
        }
        return ['ok' => true, 'reuniao' => $row];
    } catch (Throwable $e) {
        error_log('eventos_reuniao_atualizar_pacote_evento: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Erro ao salvar pacote do evento.'];
    }
}
