<?php
/**
 * comercial_cliente_sync_helper.php
 * Sincroniza clientes vindos da ME Eventos com o cadastro comercial local.
 */

if (!function_exists('comercial_cliente_sync_table_exists')) {
    function comercial_cliente_sync_table_exists(PDO $pdo, string $table): bool
    {
        try {
            $stmt = $pdo->prepare('SELECT to_regclass(:table)');
            $stmt->execute([':table' => $table]);
            return trim((string)$stmt->fetchColumn()) !== '';
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('comercial_cliente_sync_column_exists')) {
    function comercial_cliente_sync_column_exists(PDO $pdo, string $table, string $column): bool
    {
        try {
            $stmt = $pdo->prepare("
                SELECT 1
                FROM pg_attribute
                WHERE attrelid = to_regclass(:table)
                  AND attname = :column
                  AND NOT attisdropped
                LIMIT 1
            ");
            $stmt->execute([':table' => $table, ':column' => $column]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('comercial_cliente_sync_pick')) {
    function comercial_cliente_sync_pick(array $data, array $keys, string $default = ''): string
    {
        foreach ($keys as $key) {
            $value = null;
            if (strpos($key, '.') !== false) {
                $parts = explode('.', $key);
                $current = $data;
                foreach ($parts as $part) {
                    if (!is_array($current)) {
                        $current = null;
                        break;
                    }
                    if (array_key_exists($part, $current)) {
                        $current = $current[$part];
                        continue;
                    }
                    $matched = false;
                    foreach ($current as $candidateKey => $candidateValue) {
                        if (is_string($candidateKey) && strcasecmp($candidateKey, $part) === 0) {
                            $current = $candidateValue;
                            $matched = true;
                            break;
                        }
                    }
                    if (!$matched) {
                        $current = null;
                        break;
                    }
                }
                $value = $current;
            } elseif (array_key_exists($key, $data)) {
                $value = $data[$key];
            } else {
                foreach ($data as $candidateKey => $candidateValue) {
                    if (is_string($candidateKey) && strcasecmp($candidateKey, $key) === 0) {
                        $value = $candidateValue;
                        break;
                    }
                }
            }

            if (is_scalar($value)) {
                $text = trim((string)$value);
                if ($text !== '') {
                    return $text;
                }
            }
        }

        return $default;
    }
}

if (!function_exists('comercial_cliente_sync_digits')) {
    function comercial_cliente_sync_digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?: '';
    }
}

if (!function_exists('comercial_cliente_sync_is_list')) {
    function comercial_cliente_sync_is_list(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }
}

if (!function_exists('comercial_cliente_sync_data_payload')) {
    function comercial_cliente_sync_data_payload($payload): array
    {
        if (!is_array($payload)) {
            return [];
        }

        $current = $payload;
        for ($i = 0; $i < 4; $i++) {
            if (!isset($current['data']) || !is_array($current['data'])) {
                break;
            }

            $data = $current['data'];
            if ($data === []) {
                return [];
            }

            $current = comercial_cliente_sync_is_list($data) ? ($data[0] ?? []) : $data;
            if (!is_array($current)) {
                return [];
            }
        }

        if (isset($current['cliente']) && is_array($current['cliente'])) {
            $current = $current['cliente'];
        } elseif (isset($current['client']) && is_array($current['client'])) {
            $current = $current['client'];
        }

        if (comercial_cliente_sync_is_list($current)) {
            $first = $current[0] ?? [];
            return is_array($first) ? $first : [];
        }

        return $current;
    }
}

if (!function_exists('comercial_cliente_sync_put_value')) {
    function comercial_cliente_sync_put_value(array &$target, string $key, string $value): void
    {
        $value = trim($value);
        if ($value !== '') {
            $target[$key] = $value;
        }
    }
}

if (!function_exists('comercial_cliente_sync_normalize_me_client')) {
    function comercial_cliente_sync_normalize_me_client(array $client, int $meClientId): array
    {
        if ($client === []) {
            return [];
        }

        $nome = comercial_cliente_sync_pick($client, ['nome', 'nomecliente', 'nomeCliente', 'name', 'razaosocial', 'razao_social', 'cliente.nome']);
        $email = comercial_cliente_sync_pick($client, ['email', 'emailcliente', 'emailCliente', 'email2', 'mail', 'cliente.email']);
        $telefone = comercial_cliente_sync_pick($client, ['celular', 'telefone', 'telefone2', 'telefonecliente', 'telefoneCliente', 'whatsapp', 'phone', 'mobile', 'cliente.telefone', 'cliente.celular']);
        $ddi = comercial_cliente_sync_pick($client, ['ddicelular', 'dditelefone', 'ddi']);
        if ($ddi !== '' && $telefone !== '' && strpos($telefone, $ddi) !== 0) {
            $telefone = trim($ddi . ' ' . $telefone);
        }
        $cpf = comercial_cliente_sync_pick($client, ['cpf', 'cpfcliente', 'cpfCliente', 'documento', 'cliente.cpf']);
        $cnpj = comercial_cliente_sync_pick($client, ['cnpj', 'cnpjpj', 'cnpjcliente', 'cnpjCliente', 'cliente.cnpj']);

        $normalized = [
            'idcliente' => (string)$meClientId,
            'cliente' => array_merge($client, ['id' => $meClientId]),
        ];
        comercial_cliente_sync_put_value($normalized, 'nomecliente', $nome);
        comercial_cliente_sync_put_value($normalized, 'emailcliente', $email);
        comercial_cliente_sync_put_value($normalized, 'telefonecliente', $telefone);
        comercial_cliente_sync_put_value($normalized, 'cpf', $cpf);
        comercial_cliente_sync_put_value($normalized, 'cnpj', $cnpj);
        comercial_cliente_sync_put_value($normalized, 'rg', comercial_cliente_sync_pick($client, ['rg', 'rgcliente', 'cliente.rg']));
        comercial_cliente_sync_put_value($normalized, 'cepcliente', comercial_cliente_sync_pick($client, ['cep', 'cepcliente', 'cliente.cep']));
        comercial_cliente_sync_put_value($normalized, 'logradourocliente', comercial_cliente_sync_pick($client, ['endereco', 'logradouro', 'rua', 'cliente.endereco', 'cliente.logradouro']));
        comercial_cliente_sync_put_value($normalized, 'numerocliente', comercial_cliente_sync_pick($client, ['numero', 'numeroendereco', 'cliente.numero']));
        comercial_cliente_sync_put_value($normalized, 'complementocliente', comercial_cliente_sync_pick($client, ['complemento', 'cliente.complemento']));
        comercial_cliente_sync_put_value($normalized, 'bairrocliente', comercial_cliente_sync_pick($client, ['bairro', 'cliente.bairro']));
        comercial_cliente_sync_put_value($normalized, 'cidadecliente', comercial_cliente_sync_pick($client, ['cidade', 'cliente.cidade']));
        comercial_cliente_sync_put_value($normalized, 'estadocliente', comercial_cliente_sync_pick($client, ['estado', 'uf', 'cliente.uf']));

        return $normalized;
    }
}

if (!function_exists('comercial_cliente_sync_enrich_payload_with_me_client')) {
    function comercial_cliente_sync_enrich_payload_with_me_client(array $eventoData): array
    {
        if (!function_exists('eventos_me_request')) {
            return $eventoData;
        }

        $meClientId = (int)comercial_cliente_sync_pick($eventoData, ['idcliente', 'idCliente', 'id_cliente', 'id_client', 'cliente.id', 'client_id', 'clienteId'], '0');
        if ($meClientId <= 0) {
            return $eventoData;
        }

        static $clientCache = [];
        if (!array_key_exists($meClientId, $clientCache)) {
            try {
                $result = eventos_me_request('GET', '/api/v1/clients/' . $meClientId);
                $clientData = [];
                if (!empty($result['ok']) && is_array($result['data'] ?? null)) {
                    $clientData = comercial_cliente_sync_data_payload($result['data']);
                }
                $clientCache[$meClientId] = comercial_cliente_sync_normalize_me_client($clientData, $meClientId);
            } catch (Throwable $e) {
                error_log('comercial_cliente_sync_enrich_payload_with_me_client: ' . $e->getMessage());
                $clientCache[$meClientId] = [];
            }
        }

        if (empty($clientCache[$meClientId])) {
            return $eventoData;
        }

        return array_replace_recursive($eventoData, $clientCache[$meClientId]);
    }
}

if (!function_exists('comercial_cliente_sync_ensure_schema')) {
    function comercial_cliente_sync_ensure_schema(PDO $pdo): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        if (function_exists('painel_runtime_schema_setup_enabled') && !painel_runtime_schema_setup_enabled()) {
            return;
        }

        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS comercial_cadastro_clientes (
                    id BIGSERIAL PRIMARY KEY,
                    tipo_pessoa VARCHAR(2) NOT NULL DEFAULT 'PF',
                    nome_completo VARCHAR(180) NOT NULL,
                    email VARCHAR(180) NOT NULL DEFAULT '',
                    telefone_whatsapp VARCHAR(40) NOT NULL DEFAULT '',
                    documento_tipo VARCHAR(8) NOT NULL DEFAULT 'CPF',
                    documento_numero VARCHAR(20) NOT NULL DEFAULT '',
                    rg VARCHAR(30) NULL,
                    cep VARCHAR(12) NULL,
                    endereco_logradouro VARCHAR(180) NULL,
                    endereco_numero VARCHAR(30) NULL,
                    endereco_complemento VARCHAR(120) NULL,
                    endereco_bairro VARCHAR(120) NULL,
                    endereco_cidade VARCHAR(120) NULL,
                    endereco_estado VARCHAR(2) NULL,
                    origem_cliente VARCHAR(60) NULL,
                    responsavel_usuario_id INTEGER NULL,
                    tipo_interesse VARCHAR(40) NULL,
                    data_desejada DATE NULL,
                    unidade_interesse VARCHAR(120) NULL,
                    observacoes TEXT NULL,
                    me_cliente_id BIGINT NULL,
                    ultimo_me_event_id BIGINT NULL,
                    origem_importacao VARCHAR(40) NULL,
                    imported_at TIMESTAMP NULL,
                    ativo BOOLEAN NOT NULL DEFAULT TRUE,
                    created_by INTEGER NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
                )
            ");

            $alterColumns = [
                "ADD COLUMN IF NOT EXISTS me_cliente_id BIGINT NULL",
                "ADD COLUMN IF NOT EXISTS ultimo_me_event_id BIGINT NULL",
                "ADD COLUMN IF NOT EXISTS origem_importacao VARCHAR(40) NULL",
                "ADD COLUMN IF NOT EXISTS imported_at TIMESTAMP NULL",
                "ADD COLUMN IF NOT EXISTS email VARCHAR(180) NOT NULL DEFAULT ''",
                "ADD COLUMN IF NOT EXISTS telefone_whatsapp VARCHAR(40) NOT NULL DEFAULT ''",
                "ADD COLUMN IF NOT EXISTS documento_numero VARCHAR(20) NOT NULL DEFAULT ''",
            ];
            foreach ($alterColumns as $alterColumn) {
                $pdo->exec("ALTER TABLE IF EXISTS comercial_cadastro_clientes {$alterColumn}");
            }

            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_comercial_cadastro_clientes_me_cliente ON comercial_cadastro_clientes(me_cliente_id)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_comercial_cadastro_clientes_me_event ON comercial_cadastro_clientes(ultimo_me_event_id)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_comercial_cadastro_clientes_documento ON comercial_cadastro_clientes(documento_numero)");

            if (comercial_cliente_sync_table_exists($pdo, 'logistica_eventos_espelho')) {
                $pdo->exec("ALTER TABLE logistica_eventos_espelho ADD COLUMN IF NOT EXISTS cliente_cadastro_id BIGINT NULL");
                $pdo->exec("CREATE INDEX IF NOT EXISTS idx_logistica_eventos_cliente_cadastro ON logistica_eventos_espelho(cliente_cadastro_id)");
            }
            $ensured = true;
        } catch (Throwable $e) {
            error_log('comercial_cliente_sync_ensure_schema: ' . $e->getMessage());
        }
    }
}

if (!function_exists('comercial_cliente_sync_payload')) {
    function comercial_cliente_sync_payload(array $eventoData, bool $allowEventNameFallback = true): array
    {
        $meEventId = (int)comercial_cliente_sync_pick($eventoData, ['id', 'id_evento', 'idevento'], '0');
        $meClienteId = (int)comercial_cliente_sync_pick($eventoData, ['idcliente', 'idCliente', 'id_cliente', 'id_client', 'cliente.id', 'client_id', 'clienteId'], '0');

        $nome = comercial_cliente_sync_pick($eventoData, [
            'nomecliente',
            'nomeCliente',
            'client_name',
            'cliente.nome',
            'cliente_nome',
            'responsavel',
        ]);
        if ($nome === '' && $allowEventNameFallback) {
            $nomeEvento = comercial_cliente_sync_pick($eventoData, ['nomeevento', 'nome_evento', 'nome']);
            $nome = preg_replace('/\s*-\s*(15 anos|casamento|infantil|evento).*$/i', '', $nomeEvento) ?: $nomeEvento;
            $nome = trim((string)$nome);
        }

        $email = comercial_cliente_sync_pick($eventoData, [
            'emailcliente',
            'emailCliente',
            'client_email',
            'cliente.email',
            'email',
        ]);
        $telefone = comercial_cliente_sync_pick($eventoData, [
            'celular',
            'telefone',
            'telefone2',
            'whatsapp',
            'client_phone',
            'client_whatsapp',
            'phone',
            'mobile',
            'cliente.telefone',
            'cliente.celular',
        ]);
        $ddi = comercial_cliente_sync_pick($eventoData, ['ddicelular', 'dditelefone', 'ddi', 'client_phone_ddi']);
        if ($ddi !== '' && $telefone !== '' && strpos($telefone, $ddi) !== 0) {
            $telefone = trim($ddi . ' ' . $telefone);
        }

        $documento = comercial_cliente_sync_digits(comercial_cliente_sync_pick($eventoData, [
            'cpf',
            'cpfcliente',
            'cpfCliente',
            'client_cpf',
            'cliente.cpf',
            'cnpj',
            'cnpjcliente',
            'cliente.cnpj',
            'documento',
        ]));
        $documentoTipo = strlen($documento) === 14 ? 'CNPJ' : 'CPF';
        $tipoPessoa = $documentoTipo === 'CNPJ' ? 'PJ' : 'PF';

        return [
            'me_event_id' => $meEventId,
            'me_cliente_id' => $meClienteId,
            'tipo_pessoa' => $tipoPessoa,
            'nome_completo' => $nome,
            'email' => $email,
            'telefone_whatsapp' => $telefone,
            'documento_tipo' => $documentoTipo,
            'documento_numero' => $documento,
            'rg' => comercial_cliente_sync_pick($eventoData, ['rg', 'rgcliente', 'rgCliente', 'cliente.rg']),
            'cep' => comercial_cliente_sync_digits(comercial_cliente_sync_pick($eventoData, ['cepcliente', 'cliente.cep', 'client_zip', 'cep'])),
            'endereco_logradouro' => comercial_cliente_sync_pick($eventoData, ['logradourocliente', 'cliente.logradouro', 'cliente.endereco', 'client_street']),
            'endereco_numero' => comercial_cliente_sync_pick($eventoData, ['numerocliente', 'cliente.numero', 'client_number']),
            'endereco_complemento' => comercial_cliente_sync_pick($eventoData, ['complementocliente', 'cliente.complemento', 'client_complement']),
            'endereco_bairro' => comercial_cliente_sync_pick($eventoData, ['bairrocliente', 'cliente.bairro', 'client_neighborhood']),
            'endereco_cidade' => comercial_cliente_sync_pick($eventoData, ['cidadecliente', 'cliente.cidade', 'client_city']),
            'endereco_estado' => strtoupper(substr(comercial_cliente_sync_pick($eventoData, ['estadocliente', 'ufcliente', 'cliente.uf', 'client_state']), 0, 2)),
            'tipo_interesse' => comercial_cliente_sync_pick($eventoData, ['tipoevento', 'tipoEvento', 'tipo']),
            'origem_cliente' => 'ME Eventos',
        ];
    }
}

if (!function_exists('comercial_cliente_sync_upsert_from_event')) {
    function comercial_cliente_sync_upsert_from_event(PDO $pdo, array $eventoData, string $origem = 'me_webhook', bool $allowEventNameFallback = true): array
    {
        comercial_cliente_sync_ensure_schema($pdo);

        $eventoData = comercial_cliente_sync_enrich_payload_with_me_client($eventoData);
        $payload = comercial_cliente_sync_payload($eventoData, $allowEventNameFallback);
        if ((int)$payload['me_event_id'] <= 0) {
            return ['ok' => false, 'error' => 'Evento ME sem id.'];
        }

        if (trim((string)$payload['nome_completo']) === '') {
            return ['ok' => false, 'error' => 'Evento ME sem nome de cliente.'];
        }

        $telefoneDigits = comercial_cliente_sync_digits((string)$payload['telefone_whatsapp']);
        $existingId = 0;

        try {
            $stmt = $pdo->prepare("
                WITH input AS (
                    SELECT
                        CAST(:me_cliente_id AS BIGINT) AS me_cliente_id,
                        CAST(:documento_numero AS TEXT) AS documento_numero,
                        CAST(:email AS TEXT) AS email,
                        CAST(:telefone_digits AS TEXT) AS telefone_digits
                )
                SELECT c.id
                FROM comercial_cadastro_clientes c
                CROSS JOIN input i
                WHERE c.ativo IS TRUE
                  AND (
                    (i.me_cliente_id > 0 AND c.me_cliente_id = i.me_cliente_id)
                    OR (i.documento_numero <> '' AND c.documento_numero = i.documento_numero)
                    OR (i.email <> '' AND LOWER(c.email) = LOWER(i.email))
                    OR (i.telefone_digits <> '' AND regexp_replace(COALESCE(c.telefone_whatsapp, ''), '\\D', '', 'g') = i.telefone_digits)
                  )
                ORDER BY
                    CASE WHEN i.me_cliente_id > 0 AND c.me_cliente_id = i.me_cliente_id THEN 0 ELSE 1 END,
                    c.updated_at DESC NULLS LAST,
                    c.id DESC
                LIMIT 1
            ");
            $stmt->execute([
                ':me_cliente_id' => (int)$payload['me_cliente_id'],
                ':documento_numero' => (string)$payload['documento_numero'],
                ':email' => (string)$payload['email'],
                ':telefone_digits' => $telefoneDigits,
            ]);
            $existingId = (int)($stmt->fetchColumn() ?: 0);

            if ($existingId > 0) {
                $stmtUpdate = $pdo->prepare("
                    UPDATE comercial_cadastro_clientes
                    SET tipo_pessoa = COALESCE(NULLIF(:tipo_pessoa, ''), tipo_pessoa),
                        nome_completo = COALESCE(NULLIF(:nome_completo, ''), nome_completo),
                        email = COALESCE(NULLIF(:email, ''), email),
                        telefone_whatsapp = COALESCE(NULLIF(:telefone_whatsapp, ''), telefone_whatsapp),
                        documento_tipo = COALESCE(NULLIF(:documento_tipo, ''), documento_tipo),
                        documento_numero = COALESCE(NULLIF(:documento_numero, ''), documento_numero),
                        rg = COALESCE(NULLIF(:rg, ''), rg),
                        cep = COALESCE(NULLIF(:cep, ''), cep),
                        endereco_logradouro = COALESCE(NULLIF(:endereco_logradouro, ''), endereco_logradouro),
                        endereco_numero = COALESCE(NULLIF(:endereco_numero, ''), endereco_numero),
                        endereco_complemento = COALESCE(NULLIF(:endereco_complemento, ''), endereco_complemento),
                        endereco_bairro = COALESCE(NULLIF(:endereco_bairro, ''), endereco_bairro),
                        endereco_cidade = COALESCE(NULLIF(:endereco_cidade, ''), endereco_cidade),
                        endereco_estado = COALESCE(NULLIF(:endereco_estado, ''), endereco_estado),
                        tipo_interesse = COALESCE(NULLIF(:tipo_interesse, ''), tipo_interesse),
                        me_cliente_id = CASE WHEN :me_cliente_id_case > 0 THEN :me_cliente_id_value ELSE me_cliente_id END,
                        ultimo_me_event_id = :me_event_id,
                        origem_importacao = :origem,
                        imported_at = NOW(),
                        updated_at = NOW()
                    WHERE id = :id
                    RETURNING id
                ");
                $stmtUpdate->execute([
                    ':id' => $existingId,
                    ':tipo_pessoa' => (string)$payload['tipo_pessoa'],
                    ':nome_completo' => (string)$payload['nome_completo'],
                    ':email' => (string)$payload['email'],
                    ':telefone_whatsapp' => (string)$payload['telefone_whatsapp'],
                    ':documento_tipo' => (string)$payload['documento_tipo'],
                    ':documento_numero' => (string)$payload['documento_numero'],
                    ':rg' => (string)$payload['rg'],
                    ':cep' => (string)$payload['cep'],
                    ':endereco_logradouro' => (string)$payload['endereco_logradouro'],
                    ':endereco_numero' => (string)$payload['endereco_numero'],
                    ':endereco_complemento' => (string)$payload['endereco_complemento'],
                    ':endereco_bairro' => (string)$payload['endereco_bairro'],
                    ':endereco_cidade' => (string)$payload['endereco_cidade'],
                    ':endereco_estado' => (string)$payload['endereco_estado'],
                    ':tipo_interesse' => (string)$payload['tipo_interesse'],
                    ':me_cliente_id_case' => (int)$payload['me_cliente_id'],
                    ':me_cliente_id_value' => (int)$payload['me_cliente_id'],
                    ':me_event_id' => (int)$payload['me_event_id'],
                    ':origem' => $origem,
                ]);
                $clienteId = (int)($stmtUpdate->fetchColumn() ?: $existingId);
            } else {
                $stmtInsert = $pdo->prepare("
                    INSERT INTO comercial_cadastro_clientes (
                        tipo_pessoa, nome_completo, email, telefone_whatsapp, documento_tipo, documento_numero, rg,
                        cep, endereco_logradouro, endereco_numero, endereco_complemento, endereco_bairro,
                        endereco_cidade, endereco_estado, origem_cliente, tipo_interesse, me_cliente_id,
                        ultimo_me_event_id, origem_importacao, imported_at, created_at, updated_at
                    ) VALUES (
                        :tipo_pessoa, :nome_completo, :email, :telefone_whatsapp, :documento_tipo, :documento_numero, :rg,
                        :cep, :endereco_logradouro, :endereco_numero, :endereco_complemento, :endereco_bairro,
                        :endereco_cidade, :endereco_estado, :origem_cliente, :tipo_interesse, :me_cliente_id,
                        :me_event_id, :origem, NOW(), NOW(), NOW()
                    )
                    RETURNING id
                ");
                $stmtInsert->execute([
                    ':tipo_pessoa' => (string)$payload['tipo_pessoa'],
                    ':nome_completo' => (string)$payload['nome_completo'],
                    ':email' => (string)$payload['email'],
                    ':telefone_whatsapp' => (string)$payload['telefone_whatsapp'],
                    ':documento_tipo' => (string)$payload['documento_tipo'],
                    ':documento_numero' => (string)$payload['documento_numero'],
                    ':rg' => (string)$payload['rg'] !== '' ? (string)$payload['rg'] : null,
                    ':cep' => (string)$payload['cep'] !== '' ? (string)$payload['cep'] : null,
                    ':endereco_logradouro' => (string)$payload['endereco_logradouro'] !== '' ? (string)$payload['endereco_logradouro'] : null,
                    ':endereco_numero' => (string)$payload['endereco_numero'] !== '' ? (string)$payload['endereco_numero'] : null,
                    ':endereco_complemento' => (string)$payload['endereco_complemento'] !== '' ? (string)$payload['endereco_complemento'] : null,
                    ':endereco_bairro' => (string)$payload['endereco_bairro'] !== '' ? (string)$payload['endereco_bairro'] : null,
                    ':endereco_cidade' => (string)$payload['endereco_cidade'] !== '' ? (string)$payload['endereco_cidade'] : null,
                    ':endereco_estado' => (string)$payload['endereco_estado'] !== '' ? (string)$payload['endereco_estado'] : null,
                    ':origem_cliente' => 'ME Eventos',
                    ':tipo_interesse' => (string)$payload['tipo_interesse'] !== '' ? (string)$payload['tipo_interesse'] : null,
                    ':me_cliente_id' => (int)$payload['me_cliente_id'] > 0 ? (int)$payload['me_cliente_id'] : null,
                    ':me_event_id' => (int)$payload['me_event_id'],
                    ':origem' => $origem,
                ]);
                $clienteId = (int)($stmtInsert->fetchColumn() ?: 0);
            }

            if ($clienteId > 0 && comercial_cliente_sync_table_exists($pdo, 'logistica_eventos_espelho')) {
                $stmtLink = $pdo->prepare("
                    UPDATE logistica_eventos_espelho
                    SET cliente_cadastro_id = :cliente_id,
                        updated_at = NOW()
                    WHERE me_event_id = :me_event_id
                ");
                $stmtLink->execute([
                    ':cliente_id' => $clienteId,
                    ':me_event_id' => (int)$payload['me_event_id'],
                ]);
            }

            return ['ok' => true, 'cliente_id' => $clienteId, 'me_event_id' => (int)$payload['me_event_id']];
        } catch (Throwable $e) {
            error_log('comercial_cliente_sync_upsert_from_event: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Falha ao sincronizar cliente.'];
        }
    }
}

if (!function_exists('comercial_cliente_sync_future_from_local')) {
    function comercial_cliente_sync_future_from_local(PDO $pdo, int $limit = 500): array
    {
        comercial_cliente_sync_ensure_schema($pdo);
        if (!comercial_cliente_sync_table_exists($pdo, 'logistica_eventos_espelho')) {
            return ['ok' => false, 'processed' => 0, 'linked' => 0, 'error' => 'Espelho de eventos ausente.'];
        }
        if (!comercial_cliente_sync_column_exists($pdo, 'logistica_eventos_espelho', 'cliente_cadastro_id')) {
            return ['ok' => false, 'processed' => 0, 'linked' => 0, 'error' => 'Coluna cliente_cadastro_id ausente.'];
        }

        $limit = max(20, min(1000, $limit));
        $processed = 0;
        $linked = 0;

        try {
            $hasWebhook = comercial_cliente_sync_table_exists($pdo, 'me_eventos_webhook');
            $hasReunioes = comercial_cliente_sync_table_exists($pdo, 'eventos_reunioes');
            $webhookJoin = $hasWebhook
                ? "
                    LEFT JOIN LATERAL (
                        SELECT webhook_data
                        FROM me_eventos_webhook w
                        WHERE w.evento_id = e.me_event_id::text
                        ORDER BY w.recebido_em DESC NULLS LAST, w.id DESC
                        LIMIT 1
                    ) w ON TRUE
                "
                : '';
            $webhookSelect = $hasWebhook ? 'w.webhook_data' : 'NULL::text AS webhook_data';
            $reuniaoJoin = $hasReunioes
                ? "
                    LEFT JOIN LATERAL (
                        SELECT me_event_snapshot
                        FROM eventos_reunioes r
                        WHERE r.me_event_id = e.me_event_id
                        ORDER BY r.updated_at DESC NULLS LAST, r.id DESC
                        LIMIT 1
                    ) r ON TRUE
                "
                : '';
            $reuniaoSelect = $hasReunioes ? 'r.me_event_snapshot' : 'NULL::jsonb AS me_event_snapshot';

            $stmt = $pdo->prepare("
                SELECT e.*, {$webhookSelect}, {$reuniaoSelect}
                FROM logistica_eventos_espelho e
                {$webhookJoin}
                {$reuniaoJoin}
                WHERE COALESCE(e.arquivado, FALSE) = FALSE
                  AND e.data_evento >= CURRENT_DATE
                  AND e.cliente_cadastro_id IS NULL
                ORDER BY e.data_evento ASC, e.id ASC
                LIMIT {$limit}
            ");
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($rows as $row) {
                $processed++;
                $payload = [];
                $decoded = json_decode((string)($row['webhook_data'] ?? ''), true);
                if (is_array($decoded)) {
                    $payload = $decoded['data'][0] ?? $decoded['data'] ?? $decoded;
                    if (!is_array($payload)) {
                        $payload = [];
                    }
                }
                $snapshot = json_decode((string)($row['me_event_snapshot'] ?? ''), true);
                if (is_array($snapshot)) {
                    $payload = array_replace_recursive($payload, $snapshot);
                }

                $payload = array_merge($payload, [
                    'id' => (string)($row['me_event_id'] ?? ''),
                    'nomeevento' => (string)($row['nome_evento'] ?? ''),
                    'dataevento' => (string)($row['data_evento'] ?? ''),
                    'localevento' => (string)($row['localevento'] ?? ''),
                    'telefone' => (string)($row['telefone_cliente'] ?? ''),
                    'whatsapp' => (string)($row['whatsapp_cliente'] ?? ''),
                ]);

                $result = comercial_cliente_sync_upsert_from_event($pdo, $payload, 'me_local_backfill', false);
                if (!empty($result['ok'])) {
                    $linked++;
                }
            }

            return ['ok' => true, 'processed' => $processed, 'linked' => $linked];
        } catch (Throwable $e) {
            error_log('comercial_cliente_sync_future_from_local: ' . $e->getMessage());
            return ['ok' => false, 'processed' => $processed, 'linked' => $linked, 'error' => 'Falha no backfill local.'];
        }
    }
}
