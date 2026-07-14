<?php
/**
 * eventos_financeiro_helper.php
 * Financeiro isolado da Agenda Geral.
 */

require_once __DIR__ . '/core/helpers.php';

function eventos_financeiro_documento_valido(string $documento): bool
{
    $digits = preg_replace('/\D+/', '', $documento);
    if (strlen($digits) === 11) {
        if (preg_match('/^(\d)\1{10}$/', $digits)) {
            return false;
        }
        for ($t = 9; $t < 11; $t++) {
            $sum = 0;
            for ($i = 0; $i < $t; $i++) {
                $sum += (int)$digits[$i] * (($t + 1) - $i);
            }
            $digit = ((10 * $sum) % 11) % 10;
            if ((int)$digits[$t] !== $digit) {
                return false;
            }
        }
        return true;
    }

    if (strlen($digits) === 14) {
        if (preg_match('/^(\d)\1{13}$/', $digits)) {
            return false;
        }
        $weightsFirst = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $weightsSecond = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int)$digits[$i] * $weightsFirst[$i];
        }
        $digit = $sum % 11 < 2 ? 0 : 11 - ($sum % 11);
        if ((int)$digits[12] !== $digit) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 13; $i++) {
            $sum += (int)$digits[$i] * $weightsSecond[$i];
        }
        $digit = $sum % 11 < 2 ? 0 : 11 - ($sum % 11);
        return (int)$digits[13] === $digit;
    }

    return false;
}

function eventos_financeiro_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("
        SELECT EXISTS (
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = current_schema() AND table_name = :table
        )
    ");
    $stmt->execute([':table' => $table]);
    return (bool)$stmt->fetchColumn();
}

function eventos_financeiro_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("
        SELECT EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = current_schema() AND table_name = :table AND column_name = :column
        )
    ");
    $stmt->execute([':table' => $table, ':column' => $column]);
    return (bool)$stmt->fetchColumn();
}

function eventos_financeiro_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

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
    $pdo->exec("ALTER TABLE IF EXISTS logistica_pacotes_evento ADD COLUMN IF NOT EXISTS categoria VARCHAR(20) NOT NULL DEFAULT 'Pacote'");
    $pdo->exec("ALTER TABLE IF EXISTS logistica_pacotes_evento ADD COLUMN IF NOT EXISTS valor_venda NUMERIC(12,2) NULL");
    $pdo->exec("ALTER TABLE IF EXISTS logistica_pacotes_evento ADD COLUMN IF NOT EXISTS valor_pacote NUMERIC(12,2) NULL");
    $pdo->exec("ALTER TABLE IF EXISTS logistica_pacotes_evento ADD COLUMN IF NOT EXISTS pessoas_base INTEGER NULL");
    $pdo->exec("ALTER TABLE IF EXISTS logistica_pacotes_evento ADD COLUMN IF NOT EXISTS valor_convidado_adicional NUMERIC(12,2) NULL");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS eventos_financeiro_pedidos (
            id BIGSERIAL PRIMARY KEY,
            evento_id BIGINT NOT NULL REFERENCES logistica_eventos_espelho(id) ON DELETE CASCADE,
            pacote_evento_id BIGINT NULL REFERENCES logistica_pacotes_evento(id) ON DELETE SET NULL,
            descricao TEXT NOT NULL,
            valor NUMERIC(12,2) NOT NULL DEFAULT 0,
            created_by INTEGER NULL,
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMP NOT NULL DEFAULT NOW()
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS eventos_financeiro_receitas (
            id BIGSERIAL PRIMARY KEY,
            evento_id BIGINT NOT NULL REFERENCES logistica_eventos_espelho(id) ON DELETE CASCADE,
            descricao TEXT NOT NULL,
            forma_pagamento VARCHAR(30) NOT NULL,
            valor NUMERIC(12,2) NOT NULL DEFAULT 0,
            parcela_numero INTEGER NOT NULL DEFAULT 1,
            parcelas_total INTEGER NOT NULL DEFAULT 1,
            vencimento DATE NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pendente',
            asaas_payment_id VARCHAR(120) NULL,
            asaas_invoice_url TEXT NULL,
            asaas_payload JSONB NULL,
            pixgo_payment_id VARCHAR(120) NULL,
            pixgo_payment_url TEXT NULL,
            pixgo_qr_code TEXT NULL,
            pixgo_qr_image_url TEXT NULL,
            pixgo_expires_at TIMESTAMPTZ NULL,
            pixgo_idempotency_key VARCHAR(120) NULL,
            pixgo_payload JSONB NULL,
            carteira VARCHAR(20) NOT NULL DEFAULT 'manual',
            modo_pagamento VARCHAR(30) NULL,
            unidade VARCHAR(120) NULL,
            parcelamento_grupo VARCHAR(80) NULL,
            pago_em TIMESTAMP NULL,
            created_by INTEGER NULL,
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMP NOT NULL DEFAULT NOW()
        )
    ");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_financeiro_pedidos_evento ON eventos_financeiro_pedidos(evento_id)");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_financeiro_pedidos ADD COLUMN IF NOT EXISTS quantidade INTEGER NOT NULL DEFAULT 1");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_financeiro_pedidos ADD COLUMN IF NOT EXISTS valor_base NUMERIC(12,2) NOT NULL DEFAULT 0");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_financeiro_pedidos ADD COLUMN IF NOT EXISTS valor_adicional NUMERIC(12,2) NOT NULL DEFAULT 0");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_financeiro_pedidos ADD COLUMN IF NOT EXISTS desconto NUMERIC(12,2) NOT NULL DEFAULT 0");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_financeiro_pedidos ADD COLUMN IF NOT EXISTS data_venda DATE NULL");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_financeiro_pedidos ADD COLUMN IF NOT EXISTS detalhes_html TEXT NULL");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_financeiro_pedidos ADD COLUMN IF NOT EXISTS pre_contrato_id BIGINT NULL");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_financeiro_pedidos ADD COLUMN IF NOT EXISTS origem VARCHAR(40) NULL");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_financeiro_pedidos ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_financeiro_pedidos ADD COLUMN IF NOT EXISTS deleted_by INTEGER NULL");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_financeiro_pedidos_evento_ativos ON eventos_financeiro_pedidos(evento_id, deleted_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_financeiro_pedidos_pre_contrato ON eventos_financeiro_pedidos(pre_contrato_id, deleted_at)");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_financeiro_receitas ADD COLUMN IF NOT EXISTS carteira VARCHAR(20) NOT NULL DEFAULT 'manual'");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_financeiro_receitas ADD COLUMN IF NOT EXISTS modo_pagamento VARCHAR(30) NULL");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_financeiro_receitas ADD COLUMN IF NOT EXISTS unidade VARCHAR(120) NULL");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_financeiro_receitas ADD COLUMN IF NOT EXISTS parcelamento_grupo VARCHAR(80) NULL");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_financeiro_receitas ADD COLUMN IF NOT EXISTS pixgo_payment_id VARCHAR(120) NULL");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_financeiro_receitas ADD COLUMN IF NOT EXISTS pixgo_payment_url TEXT NULL");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_financeiro_receitas ADD COLUMN IF NOT EXISTS pixgo_qr_code TEXT NULL");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_financeiro_receitas ADD COLUMN IF NOT EXISTS pixgo_qr_image_url TEXT NULL");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_financeiro_receitas ADD COLUMN IF NOT EXISTS pixgo_expires_at TIMESTAMPTZ NULL");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_financeiro_receitas ADD COLUMN IF NOT EXISTS pixgo_idempotency_key VARCHAR(120) NULL");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_financeiro_receitas ADD COLUMN IF NOT EXISTS pixgo_payload JSONB NULL");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_financeiro_receitas_evento ON eventos_financeiro_receitas(evento_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_financeiro_receitas_asaas ON eventos_financeiro_receitas(asaas_payment_id)");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_eventos_financeiro_receitas_pixgo ON eventos_financeiro_receitas(pixgo_payment_id) WHERE pixgo_payment_id IS NOT NULL");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_financeiro_receitas_unidade ON eventos_financeiro_receitas(unidade, status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_financeiro_receitas_grupo ON eventos_financeiro_receitas(parcelamento_grupo)");
    $done = true;
}

function eventos_financeiro_money($value): float
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return 0.0;
    }
    $raw = str_replace(['R$', ' '], '', $raw);
    if (preg_match('/,\d{1,2}$/', $raw) === 1) {
        $raw = str_replace('.', '', $raw);
        $raw = str_replace(',', '.', $raw);
    }
    return round((float)$raw, 2);
}

function eventos_financeiro_split_parcelas(float $valorTotal, int $parcelas): array
{
    $parcelas = max(1, $parcelas);
    $totalCents = (int)round($valorTotal * 100);
    $base = intdiv($totalCents, $parcelas);
    $resto = $totalCents - ($base * $parcelas);
    $valores = [];
    for ($i = 1; $i <= $parcelas; $i++) {
        $cents = $base + ($i <= $resto ? 1 : 0);
        $valores[] = $cents / 100;
    }
    return $valores;
}

function eventos_financeiro_evento(PDO $pdo, int $eventoId): ?array
{
    $pdo->exec("ALTER TABLE IF EXISTS logistica_eventos_espelho ADD COLUMN IF NOT EXISTS foto_evento_url TEXT NULL");
    $hasHoraFim = eventos_financeiro_column_exists($pdo, 'logistica_eventos_espelho', 'hora_fim');
    $hasFotoEvento = eventos_financeiro_column_exists($pdo, 'logistica_eventos_espelho', 'foto_evento_url');
    $hasClienteCadastro = eventos_financeiro_column_exists($pdo, 'logistica_eventos_espelho', 'cliente_cadastro_id');
    $hasClientes = eventos_financeiro_table_exists($pdo, 'comercial_cadastro_clientes');
    $selectHoraFim = $hasHoraFim ? "COALESCE(TO_CHAR(e.hora_fim, 'HH24:MI'), '') AS hora_fim," : "'' AS hora_fim,";
    $selectFotoEvento = $hasFotoEvento ? "COALESCE(NULLIF(TRIM(e.foto_evento_url), ''), '') AS foto_evento_url," : "'' AS foto_evento_url,";
    $selectClienteId = $hasClienteCadastro ? "COALESCE(e.cliente_cadastro_id, 0) AS cliente_cadastro_id," : "0 AS cliente_cadastro_id,";
    $joinCliente = ($hasClienteCadastro && $hasClientes) ? "LEFT JOIN comercial_cadastro_clientes c ON c.id = e.cliente_cadastro_id" : "";
    $clienteExpr = static function (string $column) use ($pdo, $hasClientes): string {
        if (!$hasClientes || !eventos_financeiro_column_exists($pdo, 'comercial_cadastro_clientes', $column)) {
            return "''";
        }
        return "COALESCE(NULLIF(TRIM(c.{$column}), ''), '')";
    };
    $clienteSelects = ($hasClienteCadastro && $hasClientes) ? "
               {$clienteExpr('nome_completo')} AS cliente_nome,
               {$clienteExpr('email')} AS cliente_email,
               {$clienteExpr('telefone_whatsapp')} AS cliente_telefone,
               {$clienteExpr('documento_tipo')} AS cliente_documento_tipo,
               {$clienteExpr('documento_numero')} AS cliente_documento_numero,
               {$clienteExpr('rg')} AS cliente_rg,
    " : "
               '' AS cliente_nome,
               '' AS cliente_email,
               '' AS cliente_telefone,
               '' AS cliente_documento_tipo,
               '' AS cliente_documento_numero,
               '' AS cliente_rg,
    ";

    $stmt = $pdo->prepare("
        SELECT e.id,
               e.me_event_id,
               {$selectFotoEvento}
               {$selectClienteId}
               {$clienteSelects}
               e.data_evento::text AS data_evento,
               COALESCE(TO_CHAR(e.hora_inicio, 'HH24:MI'), '') AS hora_inicio,
               {$selectHoraFim}
               COALESCE(e.nome_evento, 'Evento') AS nome_evento,
               COALESCE(e.localevento, 'Local não informado') AS local_evento,
               COALESCE(e.space_visivel, 'Não mapeado') AS space_visivel,
               COALESCE(e.unidade_interna_id, 0) AS unidade_interna_id,
               COALESCE(e.convidados, 0) AS convidados
        FROM logistica_eventos_espelho e
        {$joinCliente}
        WHERE e.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $eventoId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    return is_array($row) ? $row : null;
}

function eventos_financeiro_resumo(PDO $pdo, int $eventoId): array
{
    eventos_financeiro_ensure_schema($pdo);
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(valor), 0) FROM eventos_financeiro_pedidos WHERE evento_id = :evento_id AND deleted_at IS NULL");
    $stmt->execute([':evento_id' => $eventoId]);
    $contratado = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(valor), 0) FROM eventos_financeiro_receitas WHERE evento_id = :evento_id AND status <> 'cancelado'");
    $stmt->execute([':evento_id' => $eventoId]);
    $receitas = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(valor), 0) FROM eventos_financeiro_receitas WHERE evento_id = :evento_id AND status = 'pago'");
    $stmt->execute([':evento_id' => $eventoId]);
    $recebido = (float)$stmt->fetchColumn();

    $faltaReceber = max(0, $receitas - $recebido);
    $resultado = $contratado - $receitas;

    return [
        'contratado' => $contratado,
        'receitas' => $receitas,
        'recebido' => $recebido,
        'falta_receber' => $faltaReceber,
        'resultado' => $resultado,
    ];
}

function eventos_financeiro_listar_pedidos(PDO $pdo, int $eventoId): array
{
    eventos_financeiro_ensure_schema($pdo);
    $stmt = $pdo->prepare("
        SELECT p.*,
               pe.nome AS pacote_nome,
               pe.categoria AS pacote_categoria,
               pe.pessoas_base AS pacote_pessoas_base,
               pe.valor_convidado_adicional AS pacote_valor_convidado_adicional
        FROM eventos_financeiro_pedidos p
        LEFT JOIN logistica_pacotes_evento pe ON pe.id = p.pacote_evento_id
        WHERE p.evento_id = :evento_id
          AND p.deleted_at IS NULL
        ORDER BY COALESCE(p.data_venda, p.created_at::date) DESC, p.id DESC
    ");
    $stmt->execute([':evento_id' => $eventoId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function eventos_financeiro_listar_receitas(PDO $pdo, int $eventoId): array
{
    eventos_financeiro_ensure_schema($pdo);
    $stmt = $pdo->prepare("
        SELECT *
        FROM eventos_financeiro_receitas
        WHERE evento_id = :evento_id
        ORDER BY COALESCE(vencimento, created_at::date) ASC, id ASC
    ");
    $stmt->execute([':evento_id' => $eventoId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function eventos_financeiro_salvar_pedido(PDO $pdo, int $eventoId, int $pacoteId, string $descricao, float $valor, int $userId): array
{
    return eventos_financeiro_salvar_pedido_detalhado($pdo, $eventoId, 0, $pacoteId, $descricao, $valor, 1, 0.0, 0.0, null, '', $userId);
}

function eventos_financeiro_salvar_pedido_detalhado(
    PDO $pdo,
    int $eventoId,
    int $pedidoId,
    int $pacoteId,
    string $descricao,
    float $valorBase,
    int $quantidade,
    float $valorAdicional,
    float $desconto,
    ?string $dataVenda,
    string $detalhesHtml,
    int $userId
): array
{
    eventos_financeiro_ensure_schema($pdo);
    $descricao = trim($descricao);
    $detalhesHtml = trim($detalhesHtml);
    $quantidade = max(1, $quantidade);
    $valorBase = round(max(0, $valorBase), 2);
    $valorAdicional = round(max(0, $valorAdicional), 2);
    $desconto = round(max(0, $desconto), 2);

    $pacote = [];
    if ($pacoteId > 0) {
        $stmt = $pdo->prepare("
            SELECT nome, descricao, categoria, COALESCE(pessoas_base, 0) AS pessoas_base,
                   COALESCE(valor_pacote, valor_venda, 0) AS valor_padrao,
                   COALESCE(valor_convidado_adicional, 0) AS adicional_padrao
            FROM logistica_pacotes_evento
            WHERE id = :id
              AND deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([':id' => $pacoteId]);
        $pacote = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        if ($descricao === '') {
            $descricao = trim((string)($pacote['nome'] ?? ''));
        }
        if ($valorBase <= 0 && isset($pacote['valor_padrao'])) {
            $valorBase = (float)$pacote['valor_padrao'];
        }
        if ($valorAdicional <= 0 && isset($pacote['adicional_padrao'])) {
            $valorAdicional = (float)$pacote['adicional_padrao'];
        }
        if ($detalhesHtml === '' && trim((string)($pacote['descricao'] ?? '')) !== '') {
            $detalhesHtml = trim((string)$pacote['descricao']);
        }
    }
    if ($descricao === '') {
        return ['ok' => false, 'error' => 'Informe a descrição ou selecione um pacote.'];
    }
    $pacoteCategoria = trim((string)($pacote['categoria'] ?? ''));
    $pessoasBase = max(0, (int)($pacote['pessoas_base'] ?? 0));
    if (strcasecmp($pacoteCategoria, 'Pacote') === 0 && $pessoasBase > 0) {
        $convidadosAdicionais = max(0, $quantidade - $pessoasBase);
        $valor = round(max(0, $valorBase + ($convidadosAdicionais * $valorAdicional) - $desconto), 2);
    } else {
        $valor = round(max(0, ($valorBase * $quantidade) + $valorAdicional - $desconto), 2);
    }
    if ($valor <= 0) {
        return ['ok' => false, 'error' => 'Informe um valor maior que zero.'];
    }

    if ($pedidoId > 0) {
        $stmt = $pdo->prepare("
            UPDATE eventos_financeiro_pedidos
            SET pacote_evento_id = :pacote_evento_id,
                descricao = :descricao,
                valor = :valor,
                quantidade = :quantidade,
                valor_base = :valor_base,
                valor_adicional = :valor_adicional,
                desconto = :desconto,
                data_venda = CAST(NULLIF(:data_venda, '') AS DATE),
                detalhes_html = :detalhes_html,
                updated_at = NOW()
            WHERE id = :id
              AND evento_id = :evento_id
              AND deleted_at IS NULL
        ");
        $stmt->execute([
            ':id' => $pedidoId,
            ':evento_id' => $eventoId,
            ':pacote_evento_id' => $pacoteId > 0 ? $pacoteId : null,
            ':descricao' => $descricao,
            ':valor' => $valor,
            ':quantidade' => $quantidade,
            ':valor_base' => $valorBase,
            ':valor_adicional' => $valorAdicional,
            ':desconto' => $desconto,
            ':data_venda' => $dataVenda ?: '',
            ':detalhes_html' => $detalhesHtml !== '' ? $detalhesHtml : null,
        ]);
        return ['ok' => true, 'mode' => 'updated'];
    }

    $stmt = $pdo->prepare("
        INSERT INTO eventos_financeiro_pedidos
            (evento_id, pacote_evento_id, descricao, valor, quantidade, valor_base, valor_adicional, desconto, data_venda, detalhes_html, created_by)
        VALUES
            (:evento_id, :pacote_evento_id, :descricao, :valor, :quantidade, :valor_base, :valor_adicional, :desconto,
             CAST(NULLIF(:data_venda, '') AS DATE), :detalhes_html, :created_by)
    ");
    $stmt->execute([
        ':evento_id' => $eventoId,
        ':pacote_evento_id' => $pacoteId > 0 ? $pacoteId : null,
        ':descricao' => $descricao,
        ':valor' => $valor,
        ':quantidade' => $quantidade,
        ':valor_base' => $valorBase,
        ':valor_adicional' => $valorAdicional,
        ':desconto' => $desconto,
        ':data_venda' => $dataVenda ?: '',
        ':detalhes_html' => $detalhesHtml !== '' ? $detalhesHtml : null,
        ':created_by' => $userId > 0 ? $userId : null,
    ]);
    return ['ok' => true];
}

function eventos_financeiro_excluir_pedido(PDO $pdo, int $eventoId, int $pedidoId, int $userId): bool
{
    eventos_financeiro_ensure_schema($pdo);
    if ($eventoId <= 0 || $pedidoId <= 0) {
        return false;
    }
    $stmt = $pdo->prepare("
        DELETE FROM eventos_financeiro_pedidos
        WHERE id = :id
          AND evento_id = :evento_id
    ");
    $stmt->execute([
        ':id' => $pedidoId,
        ':evento_id' => $eventoId,
    ]);
    return $stmt->rowCount() > 0;
}

function eventos_financeiro_sincronizar_pedido_pre_contrato(PDO $pdo, array $preContrato, int $meEventId, int $userId = 0): array
{
    eventos_financeiro_ensure_schema($pdo);
    require_once __DIR__ . '/agenda_eventos_sync_helper.php';
    require_once __DIR__ . '/pacotes_evento_helper.php';

    $preContratoId = (int)($preContrato['id'] ?? 0);
    $pacoteId = (int)($preContrato['pacote_evento_id'] ?? 0);
    if ($preContratoId <= 0 || $meEventId <= 0 || $pacoteId <= 0) {
        return ['ok' => false, 'error' => 'Pré-contrato, evento ME ou pacote ausente.'];
    }

    $payloadAgenda = [
        'id' => $meEventId,
        'nomeevento' => (string)($preContrato['nome_evento'] ?? $preContrato['nome_noivos'] ?? $preContrato['nome_completo'] ?? 'Evento'),
        'dataevento' => (string)($preContrato['data_evento'] ?? ''),
        'horaevento' => (string)($preContrato['horario_inicio'] ?? ''),
        'horatermino' => (string)($preContrato['horario_termino'] ?? ''),
        'localevento' => (string)($preContrato['unidade'] ?? ''),
        'convidados' => (int)($preContrato['num_convidados'] ?? 0),
        'nomeCliente' => (string)($preContrato['nome_completo'] ?? ''),
        'telefone' => (string)($preContrato['telefone'] ?? ''),
    ];
    $agendaSync = agenda_eventos_sync_me_payload($pdo, $payloadAgenda, 'pre_contrato_aprovado');
    if (empty($agendaSync['ok'])) {
        return ['ok' => false, 'error' => (string)($agendaSync['error'] ?? 'Não foi possível sincronizar o evento na Agenda Geral.')];
    }

    $stmtEvento = $pdo->prepare("
        SELECT id
        FROM logistica_eventos_espelho
        WHERE me_event_id = :me_event_id
        LIMIT 1
    ");
    $stmtEvento->execute([':me_event_id' => $meEventId]);
    $eventoId = (int)$stmtEvento->fetchColumn();
    if ($eventoId <= 0) {
        return ['ok' => false, 'error' => 'Evento aprovado não encontrado no espelho da Agenda Geral.'];
    }

    $pacote = pacotes_evento_get($pdo, $pacoteId);
    if (!$pacote) {
        return ['ok' => false, 'error' => 'Pacote do pré-contrato não encontrado.'];
    }

    $convidados = max(0, (int)($preContrato['num_convidados'] ?? 0));
    $valorNegociado = round((float)($preContrato['valor_negociado'] ?? 0), 2);
    $desconto = round(max(0, (float)($preContrato['desconto'] ?? 0)), 2);
    $valorTotal = round((float)($preContrato['valor_total'] ?? 0), 2);
    $valorResolvido = 0.0;
    $preco = pacotes_evento_resolver_preco($pdo, $pacoteId, (string)($preContrato['data_evento'] ?? ''), $convidados);
    if (!empty($preco['ok'])) {
        $valorResolvido = round((float)($preco['valor'] ?? 0), 2);
    }

    $valorBase = $valorNegociado > 0 ? $valorNegociado : ($valorResolvido > 0 ? $valorResolvido : $valorTotal);
    $valorPedido = round(max(0, $valorBase - $desconto), 2);
    if ($valorPedido <= 0 && $valorTotal > 0) {
        $valorPedido = $valorTotal;
        $valorBase = $valorTotal + $desconto;
    }
    if ($valorPedido <= 0) {
        return ['ok' => false, 'error' => 'Valor do pacote aprovado não encontrado.'];
    }

    $descricao = trim((string)($pacote['nome'] ?? ''));
    if ($descricao === '') {
        $descricao = trim((string)($preContrato['pacote_contratado'] ?? 'Pacote aprovado'));
    }

    $detalhes = trim((string)($pacote['descricao'] ?? ''));
    $meta = [
        'Origem: pré-contrato aprovado',
        'Convidados: ' . $convidados,
    ];
    if ((int)($pacote['pessoas_base'] ?? 0) > 0) {
        $meta[] = 'Convidados base do pacote: ' . (int)$pacote['pessoas_base'];
    }
    $detalhesHtml = '<p>' . htmlspecialchars(implode(' · ', $meta), ENT_QUOTES, 'UTF-8') . '</p>' . ($detalhes !== '' ? $detalhes : '');

    $stmtPedido = $pdo->prepare("
        SELECT id
        FROM eventos_financeiro_pedidos
        WHERE evento_id = :evento_id
          AND pre_contrato_id = :pre_contrato_id
          AND deleted_at IS NULL
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmtPedido->execute([
        ':evento_id' => $eventoId,
        ':pre_contrato_id' => $preContratoId,
    ]);
    $pedidoId = (int)$stmtPedido->fetchColumn();

    if ($pedidoId > 0) {
        $stmt = $pdo->prepare("
            UPDATE eventos_financeiro_pedidos
            SET pacote_evento_id = :pacote_evento_id,
                descricao = :descricao,
                valor = :valor,
                quantidade = 1,
                valor_base = :valor_base,
                valor_adicional = 0,
                desconto = :desconto,
                data_venda = CAST(NULLIF(:data_venda, '') AS DATE),
                detalhes_html = :detalhes_html,
                origem = 'pre_contrato_aprovado',
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            ':id' => $pedidoId,
            ':pacote_evento_id' => $pacoteId,
            ':descricao' => $descricao,
            ':valor' => $valorPedido,
            ':valor_base' => $valorBase,
            ':desconto' => $desconto,
            ':data_venda' => (string)($preContrato['aprovado_em'] ?? $preContrato['data_venda'] ?? date('Y-m-d')),
            ':detalhes_html' => $detalhesHtml,
        ]);
        return ['ok' => true, 'mode' => 'updated', 'evento_id' => $eventoId, 'pedido_id' => $pedidoId];
    }

    $stmt = $pdo->prepare("
        INSERT INTO eventos_financeiro_pedidos
            (evento_id, pacote_evento_id, descricao, valor, quantidade, valor_base, valor_adicional, desconto,
             data_venda, detalhes_html, pre_contrato_id, origem, created_by)
        VALUES
            (:evento_id, :pacote_evento_id, :descricao, :valor, 1, :valor_base, 0, :desconto,
             CAST(NULLIF(:data_venda, '') AS DATE), :detalhes_html, :pre_contrato_id, 'pre_contrato_aprovado', :created_by)
        RETURNING id
    ");
    $stmt->execute([
        ':evento_id' => $eventoId,
        ':pacote_evento_id' => $pacoteId,
        ':descricao' => $descricao,
        ':valor' => $valorPedido,
        ':valor_base' => $valorBase,
        ':desconto' => $desconto,
        ':data_venda' => (string)($preContrato['aprovado_em'] ?? $preContrato['data_venda'] ?? date('Y-m-d')),
        ':detalhes_html' => $detalhesHtml,
        ':pre_contrato_id' => $preContratoId,
        ':created_by' => $userId > 0 ? $userId : null,
    ]);

    return ['ok' => true, 'mode' => 'created', 'evento_id' => $eventoId, 'pedido_id' => (int)$stmt->fetchColumn()];
}

function eventos_financeiro_salvar_receita_manual(PDO $pdo, int $eventoId, string $descricao, string $forma, float $valor, ?string $vencimento, string $status, int $userId): array
{
    eventos_financeiro_ensure_schema($pdo);
    $formas = ['cartao', 'dinheiro', 'transferencia', 'outro'];
    $statusValidos = ['pendente', 'pago'];
    if (!in_array($forma, $formas, true)) {
        return ['ok' => false, 'error' => 'Forma de pagamento inválida.'];
    }
    if (!in_array($status, $statusValidos, true)) {
        $status = 'pendente';
    }
    if ($valor <= 0) {
        return ['ok' => false, 'error' => 'Informe um valor maior que zero.'];
    }
    $descricao = trim($descricao) ?: 'Receita do evento';

    $stmt = $pdo->prepare("
        INSERT INTO eventos_financeiro_receitas
            (evento_id, descricao, forma_pagamento, valor, vencimento, status, pago_em, created_by)
        VALUES
            (:evento_id, :descricao, :forma_pagamento, :valor, CAST(NULLIF(:vencimento, '') AS DATE), :status,
             CASE WHEN :status = 'pago' THEN NOW() ELSE NULL END, :created_by)
    ");
    $stmt->execute([
        ':evento_id' => $eventoId,
        ':descricao' => $descricao,
        ':forma_pagamento' => $forma,
        ':valor' => $valor,
        ':vencimento' => $vencimento ?: '',
        ':status' => $status,
        ':created_by' => $userId > 0 ? $userId : null,
    ]);
    return ['ok' => true];
}

function eventos_financeiro_normalizar_modo(string $modo, string $carteira): string
{
    $modo = trim($modo);
    $validosManual = ['pix', 'cartao_credito', 'dinheiro', 'cartao_debito', 'nao_informado'];
    if (in_array($carteira, ['asaas', 'pixgo'], true)) {
        return 'pix';
    }
    return in_array($modo, $validosManual, true) ? $modo : 'nao_informado';
}

function eventos_financeiro_salvar_receitas_manual_lote(PDO $pdo, int $eventoId, array $dados, int $userId): array
{
    eventos_financeiro_ensure_schema($pdo);
    $descricaoBase = trim((string)($dados['descricao'] ?? 'Receita do evento')) ?: 'Receita do evento';
    $unidade = trim((string)($dados['unidade'] ?? ''));
    $modo = eventos_financeiro_normalizar_modo((string)($dados['modo_pagamento'] ?? ''), 'manual');
    $status = (string)($dados['status_pagamento'] ?? 'pendente');
    $status = in_array($status, ['pendente', 'pago'], true) ? $status : 'pendente';
    $vencimentos = is_array($dados['parcela_vencimento'] ?? null) ? $dados['parcela_vencimento'] : [];
    $valores = is_array($dados['parcela_valor'] ?? null) ? $dados['parcela_valor'] : [];
    $totalParcelas = max(1, count($valores));
    $grupo = 'manual_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));

    if (empty($valores)) {
        return ['ok' => false, 'error' => 'Informe pelo menos uma parcela.'];
    }

    $pdo->beginTransaction();
    try {
        foreach ($valores as $index => $valorRaw) {
            $valor = eventos_financeiro_money($valorRaw);
            if ($valor <= 0) {
                throw new RuntimeException('Todas as parcelas precisam ter valor maior que zero.');
            }
            $numero = $index + 1;
            $descricao = $totalParcelas > 1 ? $descricaoBase . " ({$numero}/{$totalParcelas})" : $descricaoBase;
            $vencimento = trim((string)($vencimentos[$index] ?? ''));
            $stmt = $pdo->prepare("
                INSERT INTO eventos_financeiro_receitas
                    (evento_id, descricao, forma_pagamento, carteira, modo_pagamento, unidade, parcelamento_grupo,
                     valor, parcela_numero, parcelas_total, vencimento, status, pago_em, created_by)
                VALUES
                    (:evento_id, :descricao, :forma_pagamento, 'manual', :modo_pagamento, :unidade, :parcelamento_grupo,
                     :valor, :parcela_numero, :parcelas_total, CAST(NULLIF(:vencimento, '') AS DATE), :status,
                     CASE WHEN :status = 'pago' THEN NOW() ELSE NULL END, :created_by)
            ");
            $stmt->execute([
                ':evento_id' => $eventoId,
                ':descricao' => $descricao,
                ':forma_pagamento' => $modo,
                ':modo_pagamento' => $modo,
                ':unidade' => $unidade !== '' ? $unidade : null,
                ':parcelamento_grupo' => $grupo,
                ':valor' => $valor,
                ':parcela_numero' => $numero,
                ':parcelas_total' => $totalParcelas,
                ':vencimento' => $vencimento,
                ':status' => $status,
                ':created_by' => $userId > 0 ? $userId : null,
            ]);
        }
        $pdo->commit();
        return ['ok' => true, 'created' => $totalParcelas];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function eventos_financeiro_status_from_asaas(string $status): string
{
    $status = strtoupper(trim($status));
    if (in_array($status, ['RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH'], true)) {
        return 'pago';
    }
    if (in_array($status, ['OVERDUE'], true)) {
        return 'vencido';
    }
    if (in_array($status, ['DELETED', 'REFUNDED', 'REFUND_REQUESTED', 'CHARGEBACK_REQUESTED', 'CHARGEBACK_DISPUTE', 'AWAITING_CHARGEBACK_REVERSAL'], true)) {
        return 'cancelado';
    }
    return 'pendente';
}

function eventos_financeiro_status_from_pixgo(string $status, string $event = ''): string
{
    $status = strtolower(trim($status));
    $event = strtolower(trim($event));
    if ($event === 'payment.refunded') {
        return 'cancelado';
    }
    if ($event === 'payment.expired') {
        return 'vencido';
    }
    if ($event === 'payment.completed') {
        return 'pago';
    }
    if ($status === 'completed') {
        return 'pago';
    }
    if ($status === 'expired') {
        return 'vencido';
    }
    if (in_array($status, ['cancelled', 'canceled', 'refunded'], true)) {
        return 'cancelado';
    }
    return 'pendente';
}

function eventos_financeiro_pixgo_payment_url(string $paymentId): string
{
    require_once __DIR__ . '/config_env.php';
    return rtrim((string)APP_URL, '/') . '/pixgo_pagamento.php?payment=' . rawurlencode($paymentId);
}

function eventos_financeiro_criar_pix_pixgo(PDO $pdo, int $eventoId, array $evento, array $dados, int $userId): array
{
    eventos_financeiro_ensure_schema($pdo);
    require_once __DIR__ . '/pixgo_helper.php';

    $valores = is_array($dados['parcela_valor'] ?? null) ? $dados['parcela_valor'] : [];
    if (count($valores) !== 1) {
        return ['ok' => false, 'error' => 'A PixGo aceita somente cobrança à vista. O QR Code expira em cerca de 20 minutos.'];
    }
    $valor = eventos_financeiro_money($valores[0] ?? 0);
    if ($valor < 10) {
        return ['ok' => false, 'error' => 'A cobrança mínima da PixGo é R$ 10,00.'];
    }

    $documento = preg_replace('/\D+/', '', (string)($dados['cliente_cpf_cnpj'] ?? $evento['cliente_documento_numero'] ?? ''));
    if (!eventos_financeiro_documento_valido($documento)) {
        return ['ok' => false, 'error' => 'Informe um CPF/CNPJ válido do pagador para gerar a cobrança PixGo.'];
    }
    $nome = trim((string)($dados['cliente_nome'] ?? $evento['cliente_nome'] ?? ''));
    if ($nome === '') {
        return ['ok' => false, 'error' => 'Informe o nome do pagador para gerar a cobrança PixGo.'];
    }

    $descricao = trim((string)($dados['descricao'] ?? 'Receita do evento')) ?: 'Receita do evento';
    $unidade = trim((string)($dados['unidade'] ?? ($evento['space_visivel'] ?? '')));
    $vencimento = trim((string)(($dados['parcela_vencimento'][0] ?? null) ?: ($dados['primeiro_vencimento'] ?? date('Y-m-d'))));
    $grupo = 'pixgo_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));

    $stmt = $pdo->prepare("
        INSERT INTO eventos_financeiro_receitas
            (evento_id, descricao, forma_pagamento, carteira, modo_pagamento, unidade, parcelamento_grupo,
             valor, parcela_numero, parcelas_total, vencimento, status, created_by)
        VALUES
            (:evento_id, :descricao, 'pix_pixgo', 'pixgo', 'pix', :unidade, :grupo,
             :valor, 1, 1, CAST(NULLIF(:vencimento, '') AS DATE), 'pendente', :created_by)
        RETURNING id
    ");
    $stmt->execute([
        ':evento_id' => $eventoId,
        ':descricao' => $descricao,
        ':unidade' => $unidade !== '' ? $unidade : null,
        ':grupo' => $grupo,
        ':valor' => $valor,
        ':vencimento' => $vencimento,
        ':created_by' => $userId > 0 ? $userId : null,
    ]);
    $receitaId = (int)$stmt->fetchColumn();
    $idempotencyKey = 'smile-evento-' . $receitaId . '-' . bin2hex(random_bytes(8));

    try {
        $pixgo = new PixGoHelper();
        $payment = $pixgo->createPayment([
            'amount' => $valor,
            'description' => $descricao . ' - ' . (string)($evento['nome_evento'] ?? 'Evento'),
            'external_id' => 'evento_financeiro_receita:' . $receitaId,
            'receiver_name' => $nome,
            'receiver_cpf' => $documento,
            'receiver_email' => (string)($dados['cliente_email'] ?? $evento['cliente_email'] ?? ''),
            'receiver_phone' => (string)($dados['cliente_telefone'] ?? $evento['cliente_telefone'] ?? ''),
            'idempotency_key' => $idempotencyKey,
        ]);
        $paymentId = trim((string)($payment['payment_id'] ?? $payment['id'] ?? ''));
        if ($paymentId === '') {
            throw new RuntimeException('A PixGo não retornou o identificador da cobrança.');
        }
        $paymentUrl = eventos_financeiro_pixgo_payment_url($paymentId);
        $stmt = $pdo->prepare("
            UPDATE eventos_financeiro_receitas
            SET pixgo_payment_id = :payment_id,
                pixgo_payment_url = :payment_url,
                pixgo_qr_code = :qr_code,
                pixgo_qr_image_url = :qr_image_url,
                pixgo_expires_at = CAST(NULLIF(:expires_at, '') AS TIMESTAMPTZ),
                pixgo_idempotency_key = :idempotency_key,
                pixgo_payload = CAST(:payload AS JSONB),
                status = :status,
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            ':payment_id' => $paymentId,
            ':payment_url' => $paymentUrl,
            ':qr_code' => $payment['qr_code'] ?? null,
            ':qr_image_url' => $payment['qr_image_url'] ?? null,
            ':expires_at' => $payment['expires_at'] ?? '',
            ':idempotency_key' => $idempotencyKey,
            ':payload' => json_encode($payment, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':status' => eventos_financeiro_status_from_pixgo((string)($payment['status'] ?? 'pending')),
            ':id' => $receitaId,
        ]);
        return ['ok' => true, 'created' => 1, 'payment_url' => $paymentUrl];
    } catch (Throwable $e) {
        $stmt = $pdo->prepare("UPDATE eventos_financeiro_receitas SET status = 'cancelado', pixgo_idempotency_key = :key, updated_at = NOW() WHERE id = :id");
        $stmt->execute([':key' => $idempotencyKey, ':id' => $receitaId]);
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function eventos_financeiro_criar_pix_asaas(PDO $pdo, int $eventoId, array $evento, array $dados, int $userId): array
{
    eventos_financeiro_ensure_schema($pdo);
    require_once __DIR__ . '/asaas_helper.php';

    $valoresPost = is_array($dados['parcela_valor'] ?? null) ? $dados['parcela_valor'] : [];
    $vencimentosPost = is_array($dados['parcela_vencimento'] ?? null) ? $dados['parcela_vencimento'] : [];
    $valorTotal = eventos_financeiro_money($dados['valor_total'] ?? 0);
    $parcelas = max(1, min(24, (int)($dados['parcelas'] ?? count($valoresPost) ?: 1)));
    $primeiroVencimento = trim((string)($dados['primeiro_vencimento'] ?? date('Y-m-d')));
    $descricaoBase = trim((string)($dados['descricao'] ?? 'Receita do evento')) ?: 'Receita do evento';
    $unidade = trim((string)($dados['unidade'] ?? ($evento['space_visivel'] ?? '')));
    if (!empty($valoresPost)) {
        $valores = array_map('eventos_financeiro_money', $valoresPost);
        $parcelas = count($valores);
        $valorTotal = array_sum($valores);
    } else {
        $valores = eventos_financeiro_split_parcelas($valorTotal, $parcelas);
    }
    if ($valorTotal <= 0) {
        return ['ok' => false, 'error' => 'Informe um valor maior que zero.'];
    }

    $customerName = trim((string)($dados['cliente_nome'] ?? $evento['nome_evento'] ?? 'Cliente'));
    $customerEmail = trim((string)($dados['cliente_email'] ?? ''));
    $customerPhone = trim((string)($dados['cliente_telefone'] ?? ''));
    $customerCpf = preg_replace('/\D+/', '', (string)($dados['cliente_cpf_cnpj'] ?? ''));
    if ($customerCpf === '') {
        return ['ok' => false, 'error' => 'Informe o CPF/CNPJ do cliente para gerar cobrança no Asaas.'];
    }
    if (!eventos_financeiro_documento_valido($customerCpf)) {
        return ['ok' => false, 'error' => 'O CPF/CNPJ informado é inválido. Corrija o documento antes de gerar cobrança no Asaas.'];
    }

    $asaas = new AsaasHelper();
    $created = 0;
    $grupo = 'asaas_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
    try {
        $customer = $asaas->createCustomer([
            'name' => $customerName,
            'email' => $customerEmail,
            'phone' => $customerPhone,
            'cpf_cnpj' => $customerCpf,
            'external_reference' => 'evento:' . (int)($evento['me_event_id'] ?? $eventoId),
        ]);
        $customerId = (string)($customer['id'] ?? '');
        if ($customerId === '') {
            return ['ok' => false, 'error' => 'Não foi possível criar o cliente no Asaas.'];
        }

        foreach ($valores as $index => $valorParcela) {
            $numero = $index + 1;
            $vencimento = trim((string)($vencimentosPost[$index] ?? ''));
            if ($vencimento === '') {
                $vencimento = date('Y-m-d', strtotime($primeiroVencimento . ' +' . ($index * 30) . ' days'));
            }
            $descricao = $parcelas > 1 ? $descricaoBase . " ({$numero}/{$parcelas})" : $descricaoBase;

            $stmt = $pdo->prepare("
                INSERT INTO eventos_financeiro_receitas
                    (evento_id, descricao, forma_pagamento, carteira, modo_pagamento, unidade, parcelamento_grupo,
                     valor, parcela_numero, parcelas_total, vencimento, status, created_by)
                VALUES
                    (:evento_id, :descricao, 'pix_asaas', 'asaas', 'pix', :unidade, :parcelamento_grupo,
                     :valor, :parcela_numero, :parcelas_total, :vencimento, 'pendente', :created_by)
                RETURNING id
            ");
            $stmt->execute([
                ':evento_id' => $eventoId,
                ':descricao' => $descricao,
                ':unidade' => $unidade !== '' ? $unidade : null,
                ':parcelamento_grupo' => $grupo,
                ':valor' => $valorParcela,
                ':parcela_numero' => $numero,
                ':parcelas_total' => $parcelas,
                ':vencimento' => $vencimento,
                ':created_by' => $userId > 0 ? $userId : null,
            ]);
            $receitaId = (int)$stmt->fetchColumn();
            $externalReference = 'evento_financeiro_receita:' . $receitaId;

            $payment = $asaas->createPixPayment([
                'customer_id' => $customerId,
                'value' => $valorParcela,
                'due_date' => $vencimento,
                'description' => $descricao . ' - ' . (string)($evento['nome_evento'] ?? 'Evento'),
                'external_reference' => $externalReference,
            ]);

            $statusLocal = eventos_financeiro_status_from_asaas((string)($payment['status'] ?? ''));
            $stmt = $pdo->prepare("
                UPDATE eventos_financeiro_receitas
                SET asaas_payment_id = :asaas_payment_id,
                    asaas_invoice_url = :asaas_invoice_url,
                    asaas_payload = :asaas_payload,
                    status = :status,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':asaas_payment_id' => $payment['id'] ?? null,
                ':asaas_invoice_url' => $payment['invoiceUrl'] ?? $payment['bankSlipUrl'] ?? null,
                ':asaas_payload' => json_encode($payment, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':status' => $statusLocal,
                ':id' => $receitaId,
            ]);
            $created++;
        }
        return ['ok' => true, 'created' => $created];
    } catch (Throwable $e) {
        if ($created > 0) {
            return [
                'ok' => true,
                'created' => $created,
                'warning' => 'Parte das cobranças foi criada, mas houve falha na sequência: ' . $e->getMessage(),
            ];
        }
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function eventos_financeiro_atualizar_asaas_payment(PDO $pdo, string $paymentId, array $paymentData = []): bool
{
    eventos_financeiro_ensure_schema($pdo);
    if ($paymentId === '') {
        return false;
    }
    $status = eventos_financeiro_status_from_asaas((string)($paymentData['status'] ?? ''));
    $stmt = $pdo->prepare("
        UPDATE eventos_financeiro_receitas
        SET status = :status,
            pago_em = CASE WHEN :status = 'pago' THEN COALESCE(pago_em, NOW()) ELSE pago_em END,
            asaas_payload = CASE WHEN :payload <> '' THEN CAST(:payload AS JSONB) ELSE asaas_payload END,
            updated_at = NOW()
        WHERE asaas_payment_id = :payment_id
    ");
    $stmt->execute([
        ':status' => $status,
        ':payload' => $paymentData ? json_encode($paymentData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
        ':payment_id' => $paymentId,
    ]);
    return $stmt->rowCount() > 0;
}

function eventos_formatura_financeiro_atualizar_asaas_payment(PDO $pdo, string $paymentId, array $paymentData = []): bool
{
    if ($paymentId === '') {
        return false;
    }

    $existsStmt = $pdo->query("SELECT to_regclass('public.eventos_formatura_financeiro')");
    if (!$existsStmt || !$existsStmt->fetchColumn()) {
        return false;
    }
    $pdo->exec("ALTER TABLE IF EXISTS eventos_formatura_financeiro ADD COLUMN IF NOT EXISTS asaas_payment_id VARCHAR(120) NULL");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_formatura_financeiro ADD COLUMN IF NOT EXISTS asaas_payload JSONB NULL");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_formatura_financeiro ADD COLUMN IF NOT EXISTS pago_em TIMESTAMP NULL");

    $status = eventos_financeiro_status_from_asaas((string)($paymentData['status'] ?? ''));
    $stmt = $pdo->prepare("
        UPDATE eventos_formatura_financeiro
        SET status = :status,
            pago_em = CASE WHEN :status_pago = 'pago' THEN COALESCE(pago_em, NOW()) ELSE pago_em END,
            asaas_payload = CASE WHEN :payload <> '' THEN CAST(:payload AS JSONB) ELSE asaas_payload END,
            updated_at = NOW()
        WHERE asaas_payment_id = :payment_id
    ");
    $stmt->execute([
        ':status' => $status,
        ':status_pago' => $status,
        ':payload' => $paymentData ? json_encode($paymentData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
        ':payment_id' => $paymentId,
    ]);
    return $stmt->rowCount() > 0;
}

function eventos_financeiro_atualizar_pixgo_payment(PDO $pdo, string $paymentId, array $paymentData = [], string $event = ''): bool
{
    eventos_financeiro_ensure_schema($pdo);
    if ($paymentId === '') {
        return false;
    }
    $status = eventos_financeiro_status_from_pixgo((string)($paymentData['status'] ?? ''), $event);
    $stmt = $pdo->prepare("
        UPDATE eventos_financeiro_receitas
        SET status = :status,
            pago_em = CASE
                WHEN :status_pago = 'pago' THEN COALESCE(pago_em, NOW())
                WHEN :status_cancelado = 'cancelado' THEN NULL
                ELSE pago_em
            END,
            pixgo_payload = CASE WHEN :payload <> '' THEN CAST(:payload AS JSONB) ELSE pixgo_payload END,
            updated_at = NOW()
        WHERE pixgo_payment_id = :payment_id
    ");
    $stmt->execute([
        ':status' => $status,
        ':status_pago' => $status,
        ':status_cancelado' => $status,
        ':payload' => $paymentData ? json_encode($paymentData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
        ':payment_id' => $paymentId,
    ]);
    return $stmt->rowCount() > 0;
}

function eventos_formatura_financeiro_atualizar_pixgo_payment(PDO $pdo, string $paymentId, array $paymentData = [], string $event = ''): bool
{
    if ($paymentId === '') {
        return false;
    }
    $existsStmt = $pdo->query("SELECT to_regclass('eventos_formatura_financeiro')");
    if (!$existsStmt || !$existsStmt->fetchColumn()) {
        return false;
    }
    $pdo->exec("ALTER TABLE IF EXISTS eventos_formatura_financeiro ADD COLUMN IF NOT EXISTS pixgo_payment_id VARCHAR(120) NULL");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_formatura_financeiro ADD COLUMN IF NOT EXISTS pixgo_payload JSONB NULL");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_formatura_financeiro ADD COLUMN IF NOT EXISTS pago_em TIMESTAMP NULL");
    $status = eventos_financeiro_status_from_pixgo((string)($paymentData['status'] ?? ''), $event);
    $stmt = $pdo->prepare("
        UPDATE eventos_formatura_financeiro
        SET status = :status,
            pago_em = CASE
                WHEN :status_pago = 'pago' THEN COALESCE(pago_em, NOW())
                WHEN :status_cancelado = 'cancelado' THEN NULL
                ELSE pago_em
            END,
            pixgo_payload = CASE WHEN :payload <> '' THEN CAST(:payload AS JSONB) ELSE pixgo_payload END,
            updated_at = NOW()
        WHERE pixgo_payment_id = :payment_id
    ");
    $stmt->execute([
        ':status' => $status,
        ':status_pago' => $status,
        ':status_cancelado' => $status,
        ':payload' => $paymentData ? json_encode($paymentData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
        ':payment_id' => $paymentId,
    ]);
    return $stmt->rowCount() > 0;
}
