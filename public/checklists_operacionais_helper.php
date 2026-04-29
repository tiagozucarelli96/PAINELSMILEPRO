<?php
/**
 * checklists_operacionais_helper.php
 * Helpers do modulo interno de checklists operacionais.
 */

require_once __DIR__ . '/eventos_reuniao_helper.php';

function checklists_operacionais_allowed_field_types(): array
{
    return ['text', 'textarea', 'yesno', 'select', 'number', 'photo', 'text_photo', 'note', 'divider'];
}

function checklists_operacionais_field_id(string $prefix = 'cof'): string
{
    try {
        return $prefix . '_' . bin2hex(random_bytes(4));
    } catch (Throwable $e) {
        return $prefix . '_' . substr(md5(uniqid((string)mt_rand(), true)), 0, 8);
    }
}

function checklists_operacionais_ensure_rh_cargos(PDO $pdo): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS rh_cargos (
            id SERIAL PRIMARY KEY,
            nome VARCHAR(100) NOT NULL,
            descricao TEXT,
            salario_base DECIMAL(10,2) DEFAULT 0,
            ativo BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT NOW(),
            updated_at TIMESTAMP DEFAULT NOW()
        )
    ");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_rh_cargos_nome_unique ON rh_cargos (LOWER(nome))");
    $initialized = true;
}

function checklists_operacionais_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS checklists_operacionais_modelos (
                id BIGSERIAL PRIMARY KEY,
                nome VARCHAR(180) NOT NULL,
                ativo BOOLEAN NOT NULL DEFAULT TRUE,
                cargos_json JSONB NOT NULL DEFAULT '[]'::jsonb,
                unidades_json JSONB NOT NULL DEFAULT '[]'::jsonb,
                pacotes_json JSONB NOT NULL DEFAULT '[]'::jsonb,
                blocos_json JSONB NOT NULL DEFAULT '[]'::jsonb,
                schema_json JSONB NOT NULL DEFAULT '[]'::jsonb,
                created_by_user_id INTEGER NULL,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
                deleted_at TIMESTAMP NULL
            )
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_checklists_operacionais_ativo ON checklists_operacionais_modelos(ativo, updated_at DESC)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_checklists_operacionais_deleted ON checklists_operacionais_modelos(deleted_at)");
    } catch (Throwable $e) {
        error_log('checklists_operacionais_ensure_schema: ' . $e->getMessage());
    }

    $done = true;
}

function checklists_operacionais_listar_cargos(PDO $pdo): array
{
    checklists_operacionais_ensure_rh_cargos($pdo);
    try {
        $stmt = $pdo->query("SELECT id, nome FROM rh_cargos WHERE ativo = TRUE ORDER BY nome ASC");
        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $e) {
        error_log('checklists_operacionais_listar_cargos: ' . $e->getMessage());
        return [];
    }
}

function checklists_operacionais_listar_unidades(PDO $pdo): array
{
    try {
        $stmt = $pdo->query("
            SELECT DISTINCT TRIM(space_visivel) AS nome
            FROM logistica_me_locais
            WHERE TRIM(COALESCE(space_visivel, '')) <> ''
            ORDER BY TRIM(space_visivel) ASC
        ");
        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $e) {
        error_log('checklists_operacionais_listar_unidades: ' . $e->getMessage());
        return [];
    }
}

function checklists_operacionais_listar_pacotes(PDO $pdo): array
{
    if (!eventos_reuniao_has_table($pdo, 'logistica_pacotes_evento')) {
        return [];
    }

    try {
        $has_deleted_at = eventos_reuniao_has_column($pdo, 'logistica_pacotes_evento', 'deleted_at');
        $where = $has_deleted_at ? 'WHERE deleted_at IS NULL' : '';
        $stmt = $pdo->query("
            SELECT id, nome
            FROM logistica_pacotes_evento
            {$where}
            ORDER BY nome ASC, id ASC
        ");
        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $e) {
        error_log('checklists_operacionais_listar_pacotes: ' . $e->getMessage());
        return [];
    }
}

function checklists_operacionais_normalizar_lista_texto(array $items): array
{
    $normalized = [];
    foreach ($items as $item) {
        $value = trim((string)$item);
        if ($value === '') {
            continue;
        }
        $normalized[$value] = $value;
    }
    return array_values($normalized);
}

function checklists_operacionais_normalizar_lista_ids(array $items): array
{
    $normalized = [];
    foreach ($items as $item) {
        $value = (int)$item;
        if ($value <= 0) {
            continue;
        }
        $normalized[$value] = $value;
    }
    return array_values($normalized);
}

function checklists_operacionais_normalizar_blocos(array $blocks): array
{
    $normalized = [];
    $order = 1;

    foreach ($blocks as $block) {
        if (!is_array($block)) {
            continue;
        }
        $label = trim((string)($block['label'] ?? ''));
        if ($label === '') {
            continue;
        }
        $id = trim((string)($block['id'] ?? ''));
        if ($id === '') {
            $id = checklists_operacionais_field_id('cob');
        }
        $normalized[] = [
            'id' => $id,
            'label' => $label,
            'order' => $order++,
        ];
    }

    return $normalized;
}

function checklists_operacionais_normalizar_schema(array $schema, array $blocks): array
{
    $allowed_types = checklists_operacionais_allowed_field_types();
    $fillable_types = ['text', 'textarea', 'yesno', 'select', 'number', 'photo', 'text_photo'];
    $block_ids = [];
    foreach ($blocks as $block) {
        if (!is_array($block)) {
            continue;
        }
        $block_id = trim((string)($block['id'] ?? ''));
        if ($block_id !== '') {
            $block_ids[$block_id] = true;
        }
    }

    $normalized = [];
    foreach ($schema as $item) {
        if (!is_array($item)) {
            continue;
        }

        $type = strtolower(trim((string)($item['type'] ?? 'text')));
        if (!in_array($type, $allowed_types, true)) {
            $type = 'text';
        }

        $label = trim((string)($item['label'] ?? ''));
        $info_only = !empty($item['info_only']) || in_array($type, ['note', 'divider'], true);
        $required = !empty($item['required']) && !$info_only && in_array($type, $fillable_types, true);
        $require_photo = !empty($item['require_photo']) || in_array($type, ['photo', 'text_photo'], true);

        $options = [];
        if ($type === 'select' && !empty($item['options']) && is_array($item['options'])) {
            foreach ($item['options'] as $opt) {
                $text = trim((string)$opt);
                if ($text !== '') {
                    $options[] = $text;
                }
            }
        }

        $content_html = '';
        if ($type === 'note') {
            $content_html = trim((string)($item['content_html'] ?? ''));
        }

        if ($type !== 'divider' && $label === '' && !($type === 'note' && $content_html !== '')) {
            continue;
        }

        $block_id = trim((string)($item['block_id'] ?? ''));
        if ($block_id !== '' && !isset($block_ids[$block_id])) {
            $block_id = '';
        }

        $id = trim((string)($item['id'] ?? ''));
        if ($id === '') {
            $id = checklists_operacionais_field_id('cof');
        }

        $normalized[] = [
            'id' => $id,
            'type' => $type,
            'label' => $label,
            'required' => $required,
            'options' => $type === 'select' ? $options : [],
            'require_photo' => $require_photo,
            'info_only' => $info_only,
            'block_id' => $block_id,
            'content_html' => $type === 'note' ? $content_html : '',
        ];
    }

    return $normalized;
}

function checklists_operacionais_tem_campo_util(array $schema): bool
{
    $fillable_types = ['text', 'textarea', 'yesno', 'select', 'number', 'photo', 'text_photo'];
    foreach ($schema as $field) {
        if (!is_array($field)) {
            continue;
        }
        $type = strtolower(trim((string)($field['type'] ?? '')));
        $label = trim((string)($field['label'] ?? ''));
        if (in_array($type, $fillable_types, true) && $label !== '') {
            return true;
        }
    }
    return false;
}

function checklists_operacionais_listar(PDO $pdo, bool $include_inactive = true): array
{
    checklists_operacionais_ensure_schema($pdo);
    $where = ['deleted_at IS NULL'];
    if (!$include_inactive) {
        $where[] = 'ativo = TRUE';
    }

    $stmt = $pdo->query("
        SELECT id, nome, ativo, cargos_json, unidades_json, pacotes_json, blocos_json, schema_json, created_by_user_id, created_at, updated_at
        FROM checklists_operacionais_modelos
        WHERE " . implode(' AND ', $where) . "
        ORDER BY updated_at DESC, id DESC
        LIMIT 300
    ");
    $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

    foreach ($rows as &$row) {
        $row['cargos'] = json_decode((string)($row['cargos_json'] ?? '[]'), true);
        $row['unidades'] = json_decode((string)($row['unidades_json'] ?? '[]'), true);
        $row['pacotes'] = json_decode((string)($row['pacotes_json'] ?? '[]'), true);
        $row['blocos'] = json_decode((string)($row['blocos_json'] ?? '[]'), true);
        $row['schema'] = json_decode((string)($row['schema_json'] ?? '[]'), true);
        $row['cargos'] = is_array($row['cargos']) ? $row['cargos'] : [];
        $row['unidades'] = is_array($row['unidades']) ? $row['unidades'] : [];
        $row['pacotes'] = is_array($row['pacotes']) ? $row['pacotes'] : [];
        $row['blocos'] = is_array($row['blocos']) ? $row['blocos'] : [];
        $row['schema'] = is_array($row['schema']) ? $row['schema'] : [];
    }
    unset($row);

    return $rows;
}

function checklists_operacionais_salvar(PDO $pdo, array $payload, int $user_id, ?int $model_id = null): array
{
    checklists_operacionais_ensure_schema($pdo);

    $nome = trim((string)($payload['nome'] ?? ''));
    if ((function_exists('mb_strlen') ? mb_strlen($nome) : strlen($nome)) < 3) {
        return ['ok' => false, 'error' => 'Nome do checklist deve ter ao menos 3 caracteres.'];
    }

    $blocos = checklists_operacionais_normalizar_blocos(is_array($payload['blocos'] ?? null) ? $payload['blocos'] : []);
    $schema = checklists_operacionais_normalizar_schema(is_array($payload['schema'] ?? null) ? $payload['schema'] : [], $blocos);
    if (empty($schema) || !checklists_operacionais_tem_campo_util($schema)) {
        return ['ok' => false, 'error' => 'Adicione ao menos um campo preenchível antes de salvar.'];
    }

    $cargos = checklists_operacionais_normalizar_lista_texto(is_array($payload['cargos'] ?? null) ? $payload['cargos'] : []);
    $unidades = checklists_operacionais_normalizar_lista_texto(is_array($payload['unidades'] ?? null) ? $payload['unidades'] : []);
    $pacotes = checklists_operacionais_normalizar_lista_ids(is_array($payload['pacotes'] ?? null) ? $payload['pacotes'] : []);
    $ativo = !empty($payload['ativo']);

    $blocos_json = json_encode($blocos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $schema_json = json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $cargos_json = json_encode($cargos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $unidades_json = json_encode($unidades, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $pacotes_json = json_encode($pacotes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($blocos_json === false || $schema_json === false || $cargos_json === false || $unidades_json === false || $pacotes_json === false) {
        return ['ok' => false, 'error' => 'Não foi possível serializar os dados do checklist.'];
    }

    try {
        if ($model_id !== null && $model_id > 0) {
            $stmt = $pdo->prepare("
                UPDATE checklists_operacionais_modelos
                SET nome = :nome,
                    ativo = :ativo,
                    cargos_json = CAST(:cargos_json AS jsonb),
                    unidades_json = CAST(:unidades_json AS jsonb),
                    pacotes_json = CAST(:pacotes_json AS jsonb),
                    blocos_json = CAST(:blocos_json AS jsonb),
                    schema_json = CAST(:schema_json AS jsonb),
                    updated_at = NOW()
                WHERE id = :id
                  AND deleted_at IS NULL
                RETURNING id, nome, ativo, cargos_json, unidades_json, pacotes_json, blocos_json, schema_json, created_by_user_id, created_at, updated_at
            ");
            $stmt->execute([
                ':id' => $model_id,
                ':nome' => $nome,
                ':ativo' => $ativo ? 1 : 0,
                ':cargos_json' => $cargos_json,
                ':unidades_json' => $unidades_json,
                ':pacotes_json' => $pacotes_json,
                ':blocos_json' => $blocos_json,
                ':schema_json' => $schema_json,
            ]);
            $mode = 'updated';
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO checklists_operacionais_modelos
                    (nome, ativo, cargos_json, unidades_json, pacotes_json, blocos_json, schema_json, created_by_user_id, created_at, updated_at)
                VALUES
                    (:nome, :ativo, CAST(:cargos_json AS jsonb), CAST(:unidades_json AS jsonb), CAST(:pacotes_json AS jsonb), CAST(:blocos_json AS jsonb), CAST(:schema_json AS jsonb), :user_id, NOW(), NOW())
                RETURNING id, nome, ativo, cargos_json, unidades_json, pacotes_json, blocos_json, schema_json, created_by_user_id, created_at, updated_at
            ");
            $stmt->execute([
                ':nome' => $nome,
                ':ativo' => $ativo ? 1 : 0,
                ':cargos_json' => $cargos_json,
                ':unidades_json' => $unidades_json,
                ':pacotes_json' => $pacotes_json,
                ':blocos_json' => $blocos_json,
                ':schema_json' => $schema_json,
                ':user_id' => $user_id > 0 ? $user_id : null,
            ]);
            $mode = 'created';
        }
    } catch (Throwable $e) {
        error_log('checklists_operacionais_salvar: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Erro ao salvar checklist operacional.'];
    }

    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) {
        return ['ok' => false, 'error' => 'Checklist não encontrado após salvar.'];
    }

    $row['cargos'] = $cargos;
    $row['unidades'] = $unidades;
    $row['pacotes'] = $pacotes;
    $row['blocos'] = $blocos;
    $row['schema'] = $schema;

    return ['ok' => true, 'mode' => $mode, 'model' => $row];
}

function checklists_operacionais_toggle_ativo(PDO $pdo, int $model_id, bool $ativo): array
{
    checklists_operacionais_ensure_schema($pdo);
    if ($model_id <= 0) {
        return ['ok' => false, 'error' => 'Checklist inválido.'];
    }

    $stmt = $pdo->prepare("
        UPDATE checklists_operacionais_modelos
        SET ativo = :ativo, updated_at = NOW()
        WHERE id = :id
          AND deleted_at IS NULL
    ");
    $stmt->execute([
        ':id' => $model_id,
        ':ativo' => $ativo ? 1 : 0,
    ]);

    if ($stmt->rowCount() <= 0) {
        return ['ok' => false, 'error' => 'Checklist não encontrado.'];
    }

    return ['ok' => true];
}

function checklists_operacionais_duplicar(PDO $pdo, int $model_id, int $user_id): array
{
    checklists_operacionais_ensure_schema($pdo);
    if ($model_id <= 0) {
        return ['ok' => false, 'error' => 'Checklist inválido.'];
    }

    $stmt = $pdo->prepare("
        SELECT nome, ativo, cargos_json, unidades_json, pacotes_json, blocos_json, schema_json
        FROM checklists_operacionais_modelos
        WHERE id = :id
          AND deleted_at IS NULL
        LIMIT 1
    ");
    $stmt->execute([':id' => $model_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) {
        return ['ok' => false, 'error' => 'Checklist não encontrado.'];
    }

    $payload = [
        'nome' => trim((string)($row['nome'] ?? 'Checklist')) . ' (copia)',
        'ativo' => !empty($row['ativo']),
        'cargos' => json_decode((string)($row['cargos_json'] ?? '[]'), true),
        'unidades' => json_decode((string)($row['unidades_json'] ?? '[]'), true),
        'pacotes' => json_decode((string)($row['pacotes_json'] ?? '[]'), true),
        'blocos' => json_decode((string)($row['blocos_json'] ?? '[]'), true),
        'schema' => json_decode((string)($row['schema_json'] ?? '[]'), true),
    ];

    return checklists_operacionais_salvar($pdo, $payload, $user_id, null);
}
