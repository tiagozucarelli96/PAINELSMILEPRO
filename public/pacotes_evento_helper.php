<?php
/**
 * pacotes_evento_helper.php
 * Cadastro de pacotes do evento + vínculo com reunião de eventos.
 */

require_once __DIR__ . '/conexao.php';

function pacotes_evento_schema_marker_is_fresh(): bool
{
    $ttl = max(300, (int)(painel_env('PACOTES_EVENTO_SCHEMA_TTL_SECONDS', '3600') ?? '3600'));
    $mtime = @filemtime('/tmp/pacotes_evento_schema_ready_v2');
    if ($mtime === false) {
        return false;
    }

    return (time() - $mtime) < $ttl;
}

function pacotes_evento_touch_schema_marker(): void
{
    @touch('/tmp/pacotes_evento_schema_ready_v2');
}

function pacotes_evento_parse_money($value): float
{
    if (is_float($value) || is_int($value)) {
        return round((float)$value, 2);
    }

    $raw = trim((string)$value);
    if ($raw === '') {
        return 0.0;
    }

    $raw = preg_replace('/[^0-9,.\-]/', '', $raw) ?? '';
    if ($raw === '' || $raw === '-' || $raw === ',' || $raw === '.') {
        return 0.0;
    }

    $lastComma = strrpos($raw, ',');
    $lastDot = strrpos($raw, '.');
    if ($lastComma !== false && ($lastDot === false || $lastComma > $lastDot)) {
        $raw = str_replace('.', '', $raw);
        $raw = str_replace(',', '.', $raw);
    } else {
        $raw = str_replace(',', '', $raw);
    }

    return round((float)$raw, 2);
}

function pacotes_evento_format_money($value): string
{
    return number_format((float)($value ?? 0), 2, ',', '.');
}

function pacotes_evento_slug(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($converted) && $converted !== '') {
            $value = $converted;
        }
    }
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
    return trim($value, '_');
}

/**
 * Garante estrutura de pacotes de evento e vínculo na reunião.
 */
function pacotes_evento_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) {
        return;
    }

    if (!painel_runtime_schema_setup_enabled()) {
        $done = true;
        return;
    }

    if (pacotes_evento_schema_marker_is_fresh()) {
        $done = true;
        return;
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS logistica_pacotes_evento (
                id BIGSERIAL PRIMARY KEY,
                nome VARCHAR(180) NOT NULL,
                descricao TEXT NULL,
                categoria VARCHAR(20) NOT NULL DEFAULT 'Pacote',
                valor_venda NUMERIC(12,2) NULL,
                valor_pacote NUMERIC(12,2) NULL,
                pessoas_base INTEGER NULL,
                valor_convidado_adicional NUMERIC(12,2) NULL,
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
        $pdo->exec("ALTER TABLE IF EXISTS logistica_pacotes_evento ADD COLUMN IF NOT EXISTS categoria VARCHAR(20) NOT NULL DEFAULT 'Pacote'");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_pacotes_evento ADD COLUMN IF NOT EXISTS valor_venda NUMERIC(12,2) NULL");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_pacotes_evento ADD COLUMN IF NOT EXISTS valor_pacote NUMERIC(12,2) NULL");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_pacotes_evento ADD COLUMN IF NOT EXISTS pessoas_base INTEGER NULL");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_pacotes_evento ADD COLUMN IF NOT EXISTS valor_convidado_adicional NUMERIC(12,2) NULL");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_pacotes_evento ADD COLUMN IF NOT EXISTS tipo_evento_real VARCHAR(60) NULL");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_pacotes_evento ADD COLUMN IF NOT EXISTS modelo_preco VARCHAR(30) NOT NULL DEFAULT 'simples'");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_pacotes_evento ADD COLUMN IF NOT EXISTS oculto BOOLEAN NOT NULL DEFAULT FALSE");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_pacotes_evento ADD COLUMN IF NOT EXISTS created_by_user_id INTEGER NULL");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_pacotes_evento ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NOT NULL DEFAULT NOW()");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_pacotes_evento ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT NOW()");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_pacotes_evento ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_pacotes_evento ADD COLUMN IF NOT EXISTS deleted_by_user_id INTEGER NULL");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_logistica_pacotes_evento_nome ON logistica_pacotes_evento(lower(nome))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_logistica_pacotes_evento_oculto ON logistica_pacotes_evento(oculto, deleted_at)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_logistica_pacotes_evento_categoria ON logistica_pacotes_evento(categoria, deleted_at)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_logistica_pacotes_evento_tipo_real ON logistica_pacotes_evento(tipo_evento_real, deleted_at)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_logistica_pacotes_evento_modelo_preco ON logistica_pacotes_evento(modelo_preco, deleted_at)");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS logistica_pacote_preco_variacoes (
                id BIGSERIAL PRIMARY KEY,
                pacote_evento_id BIGINT NOT NULL REFERENCES logistica_pacotes_evento(id) ON DELETE CASCADE,
                nome VARCHAR(120) NOT NULL,
                codigo VARCHAR(80) NULL,
                dias_semana VARCHAR(30) NULL,
                inclui_feriado BOOLEAN NOT NULL DEFAULT FALSE,
                inclui_vespera_feriado BOOLEAN NOT NULL DEFAULT FALSE,
                ativo BOOLEAN NOT NULL DEFAULT TRUE,
                ordem INTEGER NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_pacote_preco_variacoes ADD COLUMN IF NOT EXISTS codigo VARCHAR(80) NULL");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_pacote_preco_variacoes ADD COLUMN IF NOT EXISTS dias_semana VARCHAR(30) NULL");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_pacote_preco_variacoes ADD COLUMN IF NOT EXISTS inclui_feriado BOOLEAN NOT NULL DEFAULT FALSE");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_pacote_preco_variacoes ADD COLUMN IF NOT EXISTS inclui_vespera_feriado BOOLEAN NOT NULL DEFAULT FALSE");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_pacote_preco_variacoes ADD COLUMN IF NOT EXISTS ativo BOOLEAN NOT NULL DEFAULT TRUE");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_pacote_preco_variacoes ADD COLUMN IF NOT EXISTS ordem INTEGER NOT NULL DEFAULT 0");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_logistica_pacote_preco_variacoes_pacote ON logistica_pacote_preco_variacoes(pacote_evento_id, ativo, ordem)");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS logistica_pacote_preco_faixas (
                id BIGSERIAL PRIMARY KEY,
                variacao_id BIGINT NOT NULL REFERENCES logistica_pacote_preco_variacoes(id) ON DELETE CASCADE,
                pessoas INTEGER NOT NULL,
                valor NUMERIC(12,2) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
                UNIQUE (variacao_id, pessoas)
            )
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_logistica_pacote_preco_faixas_variacao ON logistica_pacote_preco_faixas(variacao_id, pessoas)");
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
    pacotes_evento_touch_schema_marker();
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
        $row['categoria'] = trim((string)($row['categoria'] ?? 'Pacote'));
        $row['tipo_evento_real'] = trim((string)($row['tipo_evento_real'] ?? ''));
        $row['modelo_preco'] = trim((string)($row['modelo_preco'] ?? 'simples'));
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
    $row['categoria'] = trim((string)($row['categoria'] ?? 'Pacote'));
    $row['tipo_evento_real'] = trim((string)($row['tipo_evento_real'] ?? ''));
    $row['modelo_preco'] = trim((string)($row['modelo_preco'] ?? 'simples'));
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
    int $user_id = 0,
    array $options = []
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
    $categoria = trim((string)($options['categoria'] ?? 'Pacote'));
    if ($categoria === '') {
        $categoria = 'Pacote';
    }
    $tipo_evento_real = trim((string)($options['tipo_evento_real'] ?? ''));
    $modelo_preco = (string)($options['modelo_preco'] ?? 'simples');
    if (!in_array($modelo_preco, ['simples', 'tabela'], true)) {
        $modelo_preco = 'simples';
    }
    $valor_pacote = pacotes_evento_parse_money($options['valor_pacote'] ?? 0);
    $pessoas_base = max(0, (int)($options['pessoas_base'] ?? 0));
    $valor_convidado_adicional = pacotes_evento_parse_money($options['valor_convidado_adicional'] ?? 0);

    try {
        if ($pacote_id > 0) {
            $stmt = $pdo->prepare("
                UPDATE logistica_pacotes_evento
                SET nome = :nome,
                    descricao = :descricao,
                    categoria = :categoria,
                    tipo_evento_real = :tipo_evento_real,
                    modelo_preco = :modelo_preco,
                    valor_pacote = :valor_pacote,
                    pessoas_base = :pessoas_base,
                    valor_convidado_adicional = :valor_convidado_adicional,
                    oculto = :oculto,
                    updated_at = NOW()
                WHERE id = :id
                  AND deleted_at IS NULL
                RETURNING *
            ");
            $stmt->execute([
                ':nome' => $nome_limpo,
                ':descricao' => $descricao_limpa !== '' ? $descricao_limpa : null,
                ':categoria' => $categoria,
                ':tipo_evento_real' => $tipo_evento_real !== '' ? $tipo_evento_real : null,
                ':modelo_preco' => $modelo_preco,
                ':valor_pacote' => $valor_pacote,
                ':pessoas_base' => $pessoas_base > 0 ? $pessoas_base : null,
                ':valor_convidado_adicional' => $valor_convidado_adicional,
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
                (nome, descricao, categoria, tipo_evento_real, modelo_preco, valor_pacote, pessoas_base, valor_convidado_adicional, oculto, created_by_user_id, created_at, updated_at)
            VALUES
                (:nome, :descricao, :categoria, :tipo_evento_real, :modelo_preco, :valor_pacote, :pessoas_base, :valor_convidado_adicional, :oculto, :created_by_user_id, NOW(), NOW())
            RETURNING *
        ");
        $stmt->execute([
            ':nome' => $nome_limpo,
            ':descricao' => $descricao_limpa !== '' ? $descricao_limpa : null,
            ':categoria' => $categoria,
            ':tipo_evento_real' => $tipo_evento_real !== '' ? $tipo_evento_real : null,
            ':modelo_preco' => $modelo_preco,
            ':valor_pacote' => $valor_pacote,
            ':pessoas_base' => $pessoas_base > 0 ? $pessoas_base : null,
            ':valor_convidado_adicional' => $valor_convidado_adicional,
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

function pacotes_evento_tipos_evento_listar(PDO $pdo): array
{
    try {
        $stmt = $pdo->query("
            SELECT tipo_key, label
            FROM eventos_tipos_reais_config
            WHERE COALESCE(ativo, TRUE) = TRUE
            ORDER BY ordem ASC, label ASC
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $rows = [];
    }

    if (empty($rows)) {
        $rows = [
            ['tipo_key' => 'infantil', 'label' => 'Infantil'],
            ['tipo_key' => 'casamento', 'label' => 'Casamento'],
            ['tipo_key' => '15anos', 'label' => '15 anos'],
            ['tipo_key' => 'formatura', 'label' => 'Formatura'],
        ];
    }

    $out = [];
    foreach ($rows as $row) {
        $key = trim((string)($row['tipo_key'] ?? ''));
        $label = trim((string)($row['label'] ?? ''));
        if ($key === '') {
            continue;
        }
        $out[$key] = $label !== '' ? $label : $key;
    }
    return $out;
}

function pacotes_evento_preco_variacoes_listar(PDO $pdo, int $pacote_id): array
{
    pacotes_evento_ensure_schema($pdo);
    if ($pacote_id <= 0) {
        return [];
    }

    $stmt = $pdo->prepare("
        SELECT *
        FROM logistica_pacote_preco_variacoes
        WHERE pacote_evento_id = :pacote_id
        ORDER BY ordem ASC, id ASC
    ");
    $stmt->execute([':pacote_id' => $pacote_id]);
    $variacoes = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stmtFaixas = $pdo->prepare("
        SELECT *
        FROM logistica_pacote_preco_faixas
        WHERE variacao_id = :variacao_id
        ORDER BY pessoas ASC
    ");

    foreach ($variacoes as &$variacao) {
        $variacao['id'] = (int)($variacao['id'] ?? 0);
        $variacao['pacote_evento_id'] = (int)($variacao['pacote_evento_id'] ?? 0);
        $variacao['ativo'] = !empty($variacao['ativo']);
        $variacao['inclui_feriado'] = !empty($variacao['inclui_feriado']);
        $variacao['inclui_vespera_feriado'] = !empty($variacao['inclui_vespera_feriado']);
        $stmtFaixas->execute([':variacao_id' => $variacao['id']]);
        $variacao['faixas'] = $stmtFaixas->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    unset($variacao);

    return $variacoes;
}

function pacotes_evento_preco_variacoes_salvar(PDO $pdo, int $pacote_id, array $variacoes): array
{
    pacotes_evento_ensure_schema($pdo);
    if ($pacote_id <= 0) {
        return ['ok' => false, 'error' => 'Pacote inválido para salvar valores.'];
    }

    try {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM logistica_pacote_preco_variacoes WHERE pacote_evento_id = :pacote_id")
            ->execute([':pacote_id' => $pacote_id]);

        $insertVariacao = $pdo->prepare("
            INSERT INTO logistica_pacote_preco_variacoes
                (pacote_evento_id, nome, codigo, dias_semana, inclui_feriado, inclui_vespera_feriado, ativo, ordem, created_at, updated_at)
            VALUES
                (:pacote_evento_id, :nome, :codigo, :dias_semana, :inclui_feriado, :inclui_vespera_feriado, :ativo, :ordem, NOW(), NOW())
            RETURNING id
        ");
        $insertFaixa = $pdo->prepare("
            INSERT INTO logistica_pacote_preco_faixas
                (variacao_id, pessoas, valor, created_at, updated_at)
            VALUES
                (:variacao_id, :pessoas, :valor, NOW(), NOW())
        ");

        foreach ($variacoes as $index => $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $nome = trim((string)($raw['nome'] ?? ''));
            $dias = $raw['dias_semana'] ?? [];
            if (!is_array($dias)) {
                $dias = array_filter(array_map('trim', explode(',', (string)$dias)));
            }
            $dias = array_values(array_intersect(array_map('intval', $dias), [1, 2, 3, 4, 5, 6, 7]));
            sort($dias);
            $faixasRaw = $raw['faixas'] ?? [];
            $faixasValidas = [];
            foreach ($faixasRaw as $faixa) {
                if (!is_array($faixa)) {
                    continue;
                }
                $pessoas = max(0, (int)($faixa['pessoas'] ?? 0));
                $valor = pacotes_evento_parse_money($faixa['valor'] ?? 0);
                if ($pessoas <= 0 || $valor <= 0) {
                    continue;
                }
                $faixasValidas[$pessoas] = $valor;
            }
            if ($nome === '' && empty($dias) && empty($faixasValidas)) {
                continue;
            }
            if ($nome === '') {
                $nome = 'Variação ' . ((int)$index + 1);
            }

            $insertVariacao->execute([
                ':pacote_evento_id' => $pacote_id,
                ':nome' => $nome,
                ':codigo' => pacotes_evento_slug($nome),
                ':dias_semana' => implode(',', $dias),
                ':inclui_feriado' => !empty($raw['inclui_feriado']) ? 1 : 0,
                ':inclui_vespera_feriado' => !empty($raw['inclui_vespera_feriado']) ? 1 : 0,
                ':ativo' => !isset($raw['ativo']) || !empty($raw['ativo']) ? 1 : 0,
                ':ordem' => (int)($raw['ordem'] ?? ($index + 1)),
            ]);
            $variacaoId = (int)$insertVariacao->fetchColumn();
            foreach ($faixasValidas as $pessoas => $valor) {
                $insertFaixa->execute([
                    ':variacao_id' => $variacaoId,
                    ':pessoas' => $pessoas,
                    ':valor' => $valor,
                ]);
            }
        }

        $pdo->commit();
        return ['ok' => true];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('pacotes_evento_preco_variacoes_salvar: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Erro ao salvar tabela de valores.'];
    }
}

function pacotes_evento_resolver_preco(
    PDO $pdo,
    int $pacote_id,
    string $data_evento,
    int $convidados,
    bool $eh_feriado = false,
    bool $eh_vespera_feriado = false
): array {
    $pacote = pacotes_evento_get($pdo, $pacote_id);
    if (!$pacote) {
        return ['ok' => false, 'error' => 'Pacote não encontrado.'];
    }

    $modelo = (string)($pacote['modelo_preco'] ?? 'simples');
    if ($modelo !== 'tabela') {
        $base = (float)($pacote['valor_pacote'] ?? 0);
        $pessoasBase = max(0, (int)($pacote['pessoas_base'] ?? 0));
        $adicional = (float)($pacote['valor_convidado_adicional'] ?? 0);
        $extras = $pessoasBase > 0 ? max(0, $convidados - $pessoasBase) : 0;
        return [
            'ok' => true,
            'modelo' => 'simples',
            'pacote_id' => $pacote_id,
            'valor' => round($base + ($extras * $adicional), 2),
            'pessoas_referencia' => $pessoasBase,
            'variacao' => null,
            'faixa' => null,
        ];
    }

    try {
        $dt = new DateTime($data_evento);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Data do evento inválida.'];
    }
    $diaSemana = (int)$dt->format('N');
    $variacoes = pacotes_evento_preco_variacoes_listar($pdo, $pacote_id);
    $candidatas = [];
    foreach ($variacoes as $index => $variacao) {
        if (empty($variacao['ativo'])) {
            continue;
        }
        $dias = array_filter(array_map('intval', explode(',', (string)($variacao['dias_semana'] ?? ''))));
        $matchDia = in_array($diaSemana, $dias, true);
        $matchFeriado = $eh_feriado && !empty($variacao['inclui_feriado']);
        $matchVespera = $eh_vespera_feriado && !empty($variacao['inclui_vespera_feriado']);
        $prioridade = $matchVespera ? 3 : ($matchFeriado ? 2 : ($matchDia ? 1 : 0));
        if ($prioridade <= 0) {
            continue;
        }

        $candidatas[] = [
            'prioridade' => $prioridade,
            'ordem' => (int)$index,
            'variacao' => $variacao,
        ];
    }

    usort($candidatas, static function (array $a, array $b): int {
        $byPriority = (int)$b['prioridade'] <=> (int)$a['prioridade'];
        return $byPriority !== 0 ? $byPriority : ((int)$a['ordem'] <=> (int)$b['ordem']);
    });

    foreach ($candidatas as $candidata) {
        $variacao = $candidata['variacao'];
        $faixas = $variacao['faixas'] ?? [];
        usort($faixas, static fn(array $a, array $b): int => (int)$a['pessoas'] <=> (int)$b['pessoas']);
        foreach ($faixas as $faixa) {
            if ((int)($faixa['pessoas'] ?? 0) >= $convidados) {
                return [
                    'ok' => true,
                    'modelo' => 'tabela',
                    'pacote_id' => $pacote_id,
                    'valor' => round((float)$faixa['valor'], 2),
                    'pessoas_referencia' => (int)$faixa['pessoas'],
                    'variacao_id' => (int)($variacao['id'] ?? 0),
                    'variacao' => $variacao,
                    'faixa' => $faixa,
                ];
            }
        }
    }

    return ['ok' => false, 'error' => 'Nenhuma faixa de preço encontrada para data e convidados informados.'];
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
