<?php
/**
 * financeiro.php
 * Modulo financeiro geral: entradas vindas dos eventos e saidas manuais/OFX.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/eventos_financeiro_helper.php';

if (empty($_SESSION['perm_financeiro']) && empty($_SESSION['perm_superadmin'])) {
    header('Location: index.php?page=dashboard');
    exit;
}

$userId = (int)($_SESSION['id'] ?? $_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? 0);
$errors = [];
$messages = [];

function financeiro_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    eventos_financeiro_ensure_schema($pdo);
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS financeiro_despesas (
            id BIGSERIAL PRIMARY KEY,
            data_movimento DATE NOT NULL,
            descricao TEXT NOT NULL,
            valor NUMERIC(12,2) NOT NULL DEFAULT 0,
            banco VARCHAR(120) NULL,
            conta VARCHAR(120) NULL,
            categoria VARCHAR(120) NULL,
            centro_custo VARCHAR(120) NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pendente',
            origem VARCHAR(30) NOT NULL DEFAULT 'manual',
            ofx_fitid VARCHAR(180) NULL,
            ofx_payload JSONB NULL,
            created_by INTEGER NULL,
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMP NOT NULL DEFAULT NOW()
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_financeiro_despesas_data ON financeiro_despesas(data_movimento)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_financeiro_despesas_status ON financeiro_despesas(status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_financeiro_despesas_ofx_fitid ON financeiro_despesas(banco, conta, ofx_fitid) WHERE ofx_fitid IS NOT NULL");
    $pdo->exec("ALTER TABLE IF EXISTS financeiro_despesas ADD COLUMN IF NOT EXISTS destinatario VARCHAR(180) NULL");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS financeiro_categorias (
            id BIGSERIAL PRIMARY KEY,
            nome VARCHAR(180) NOT NULL,
            grupo VARCHAR(120) NOT NULL DEFAULT 'Geral',
            tipo VARCHAR(20) NOT NULL DEFAULT 'despesa',
            ordem INTEGER NOT NULL DEFAULT 0,
            ativo BOOLEAN NOT NULL DEFAULT TRUE,
            descricao TEXT NULL,
            created_by INTEGER NULL,
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
            UNIQUE (tipo, grupo, nome)
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_financeiro_categorias_ativo ON financeiro_categorias(tipo, ativo, grupo, ordem, nome)");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS financeiro_ofx_descricoes (
            id BIGSERIAL PRIMARY KEY,
            descricao_banco TEXT NOT NULL,
            descricao_normalizada VARCHAR(220) NOT NULL,
            destinatario VARCHAR(180) NULL,
            categoria VARCHAR(120) NULL,
            centro_custo VARCHAR(120) NULL,
            banco VARCHAR(120) NULL,
            conta VARCHAR(120) NULL,
            tipo_movimento VARCHAR(30) NULL,
            uso_count INTEGER NOT NULL DEFAULT 0,
            ultimo_uso_em TIMESTAMP NULL,
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMP NOT NULL DEFAULT NOW()
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_financeiro_ofx_descricoes_norm ON financeiro_ofx_descricoes(descricao_normalizada)");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_financeiro_ofx_descricoes_unique ON financeiro_ofx_descricoes(descricao_normalizada, COALESCE(banco, ''), COALESCE(conta, ''))");
    $done = true;
}

function financeiro_money($value): float
{
    return eventos_financeiro_money($value);
}

function financeiro_status_label(string $status): string
{
    return [
        'pendente' => 'Pendente',
        'pago' => 'Pago',
        'conciliado' => 'Conciliado',
        'cancelado' => 'Cancelado',
        'vencido' => 'Vencido',
    ][$status] ?? ucfirst($status);
}

function financeiro_modo_label(string $forma): string
{
    return [
        'pix_asaas' => 'PIX Asaas',
        'pix' => 'Pix',
        'cartao_credito' => 'Cartao de credito',
        'dinheiro' => 'Dinheiro',
        'cartao_debito' => 'Cartao de debito',
        'nao_informado' => 'Nao informado',
        'cartao' => 'Cartao',
        'transferencia' => 'Transferencia',
        'outro' => 'Outro',
    ][$forma] ?? ucfirst(str_replace('_', ' ', $forma));
}

function financeiro_ofx_tag(string $block, string $tag): string
{
    if (preg_match('/<' . preg_quote($tag, '/') . '>([^<\r\n]*)/i', $block, $m)) {
        return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    return '';
}

function financeiro_parse_ofx_date(string $value): ?string
{
    $digits = preg_replace('/\D+/', '', $value);
    if (strlen($digits) < 8) {
        return null;
    }
    $ymd = substr($digits, 0, 8);
    $dt = DateTimeImmutable::createFromFormat('Ymd', $ymd);
    return $dt ? $dt->format('Y-m-d') : null;
}

function financeiro_parse_ofx_amount(string $value): float
{
    $value = str_replace(',', '.', trim($value));
    return round((float)$value, 2);
}

function financeiro_normalizar_descricao(string $descricao): string
{
    $descricao = trim($descricao);
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $descricao);
    if ($ascii !== false) {
        $descricao = $ascii;
    }
    $descricao = strtoupper($descricao);
    $descricao = preg_replace('/[^A-Z0-9 ]+/', ' ', $descricao);
    $descricao = preg_replace('/\s+/', ' ', (string)$descricao);
    return trim((string)$descricao);
}

function financeiro_destinatario_from_descricao(string $descricao): string
{
    $texto = financeiro_normalizar_descricao($descricao);
    $texto = preg_replace('/^(PIX|TED|DOC|TEF|PAGAMENTO|PGTO|TRANSFERENCIA|TRANSF|COMPRA|DEBITO|ENVIO|ENVIADO|RECEBIDO)\s+/i', '', $texto);
    $texto = preg_replace('/^(PIX|TED|DOC)\s+(ENVIADO|RECEBIDO|PARA|DE)\s+/i', '', (string)$texto);
    $texto = preg_replace('/\s+/', ' ', (string)$texto);
    return trim((string)$texto);
}

function financeiro_tipo_movimento_ofx(string $trnType, float $amount): string
{
    $type = strtoupper(trim($trnType));
    if ($type !== '') {
        if (in_array($type, ['CREDIT', 'DEP', 'DIRECTDEP', 'INT', 'DIV'], true)) {
            return 'receita';
        }
        if (in_array($type, ['DEBIT', 'PAYMENT', 'CHECK', 'FEE', 'SRVCHG', 'ATM', 'POS', 'XFER'], true)) {
            return 'despesa';
        }
    }
    return $amount < 0 ? 'despesa' : 'receita';
}

function financeiro_detectar_parcela(string $descricao): string
{
    if (preg_match('/\b(\d{1,2})\s*\/\s*(\d{1,2})\b/', $descricao, $m)) {
        return (int)$m[1] . '/' . (int)$m[2];
    }
    if (preg_match('/\bPARC(?:ELA)?\s*(\d{1,2})\s*(?:DE|\/)\s*(\d{1,2})\b/i', $descricao, $m)) {
        return (int)$m[1] . '/' . (int)$m[2];
    }
    return '';
}

function financeiro_parse_ofx(string $content): array
{
    $content = str_replace(["\r\n", "\r"], "\n", $content);
    preg_match_all('/<STMTTRN>(.*?)<\/STMTTRN>/is', $content, $matches);
    $items = [];
    foreach ($matches[1] ?? [] as $idx => $block) {
        $amount = financeiro_parse_ofx_amount(financeiro_ofx_tag($block, 'TRNAMT'));
        $date = financeiro_parse_ofx_date(financeiro_ofx_tag($block, 'DTPOSTED') ?: financeiro_ofx_tag($block, 'DTUSER'));
        $name = financeiro_ofx_tag($block, 'NAME');
        $memo = financeiro_ofx_tag($block, 'MEMO');
        $trnType = financeiro_ofx_tag($block, 'TRNTYPE');
        $fitid = financeiro_ofx_tag($block, 'FITID');
        $description = trim($memo !== '' ? $memo : $name);
        $tipo = financeiro_tipo_movimento_ofx($trnType, $amount);

        if ($date === null || abs($amount) < 0.01) {
            continue;
        }

        $items[] = [
            'data' => $date,
            'descricao' => $description !== '' ? $description : 'Lancamento OFX',
            'descricao_normalizada' => financeiro_normalizar_descricao($description !== '' ? $description : 'Lancamento OFX'),
            'destinatario' => financeiro_destinatario_from_descricao($description !== '' ? $description : 'Lancamento OFX'),
            'valor_original' => $amount,
            'valor' => abs($amount),
            'tipo' => $tipo,
            'trn_type' => $trnType,
            'parcela_sugerida' => financeiro_detectar_parcela($description),
            'fitid' => $fitid !== '' ? $fitid : 'ofx_' . sha1($date . '|' . $amount . '|' . $description . '|' . $idx),
        ];
    }
    return $items;
}

function financeiro_ofx_duplicate(PDO $pdo, array $item, string $banco, string $conta): bool
{
    $stmt = $pdo->prepare("
        SELECT 1
        FROM financeiro_despesas
        WHERE COALESCE(banco, '') = COALESCE(:banco, '')
          AND COALESCE(conta, '') = COALESCE(:conta, '')
          AND (
            (ofx_fitid IS NOT NULL AND ofx_fitid = :ofx_fitid)
            OR (
                data_movimento = :data_movimento
                AND ABS(valor - :valor) < 0.01
                AND financeiro_despesas.descricao = :descricao
            )
          )
        LIMIT 1
    ");
    $stmt->execute([
        ':banco' => $banco !== '' ? $banco : null,
        ':conta' => $conta !== '' ? $conta : null,
        ':ofx_fitid' => $item['fitid'],
        ':data_movimento' => $item['data'],
        ':valor' => $item['valor'],
        ':descricao' => $item['descricao'],
    ]);
    return (bool)$stmt->fetchColumn();
}

function financeiro_salvar_descricao_banco(PDO $pdo, array $item, string $banco, string $conta): void
{
    $lookup = $pdo->prepare("
        SELECT id
        FROM financeiro_ofx_descricoes
        WHERE descricao_normalizada = :descricao_normalizada
          AND COALESCE(banco, '') = COALESCE(:banco, '')
          AND COALESCE(conta, '') = COALESCE(:conta, '')
        LIMIT 1
    ");
    $lookup->execute([
        ':descricao_normalizada' => $item['descricao_normalizada'],
        ':banco' => $banco !== '' ? $banco : null,
        ':conta' => $conta !== '' ? $conta : null,
    ]);
    $id = (int)($lookup->fetchColumn() ?: 0);
    if ($id > 0) {
        $stmt = $pdo->prepare("
            UPDATE financeiro_ofx_descricoes
            SET descricao_banco = :descricao_banco,
                destinatario = COALESCE(destinatario, :destinatario),
                tipo_movimento = :tipo_movimento,
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            ':id' => $id,
            ':descricao_banco' => $item['descricao'],
            ':destinatario' => $item['destinatario'] ?: null,
            ':tipo_movimento' => $item['tipo'],
        ]);
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO financeiro_ofx_descricoes
            (descricao_banco, descricao_normalizada, destinatario, banco, conta, tipo_movimento, updated_at)
        VALUES
            (:descricao_banco, :descricao_normalizada, :destinatario, :banco, :conta, :tipo_movimento, NOW())
    ");
    $stmt->execute([
        ':descricao_banco' => $item['descricao'],
        ':descricao_normalizada' => $item['descricao_normalizada'],
        ':destinatario' => $item['destinatario'] ?: null,
        ':banco' => $banco !== '' ? $banco : null,
        ':conta' => $conta !== '' ? $conta : null,
        ':tipo_movimento' => $item['tipo'],
    ]);
}

function financeiro_sugerir_descricao(PDO $pdo, string $descricaoNormalizada, string $banco, string $conta): array
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM financeiro_ofx_descricoes
        WHERE descricao_normalizada = :descricao
          AND COALESCE(banco, '') = COALESCE(:banco, '')
          AND COALESCE(conta, '') = COALESCE(:conta, '')
        LIMIT 1
    ");
    $stmt->execute([
        ':descricao' => $descricaoNormalizada,
        ':banco' => $banco !== '' ? $banco : null,
        ':conta' => $conta !== '' ? $conta : null,
    ]);
    $exact = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($exact && trim((string)($exact['categoria'] ?? '')) !== '') {
        return ['match' => 'igual', 'row' => $exact];
    }

    $stmt = $pdo->prepare("
        SELECT *
        FROM financeiro_ofx_descricoes
        WHERE COALESCE(categoria, '') <> ''
          AND (COALESCE(banco, '') = COALESCE(:banco, '') OR COALESCE(banco, '') = '')
        ORDER BY ultimo_uso_em DESC NULLS LAST, updated_at DESC
        LIMIT 500
    ");
    $stmt->execute([':banco' => $banco !== '' ? $banco : null]);
    $best = null;
    $bestScore = 0.0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        similar_text($descricaoNormalizada, (string)$row['descricao_normalizada'], $score);
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $row;
        }
    }
    return ($best && $bestScore >= 82.0) ? ['match' => 'parecida', 'score' => $bestScore, 'row' => $best] : ['match' => '', 'row' => null];
}

function financeiro_atualizar_aprendizado(PDO $pdo, array $item, string $banco, string $conta, string $categoria, string $centroCusto): void
{
    financeiro_salvar_descricao_banco($pdo, $item, $banco, $conta);
    $stmt = $pdo->prepare("
        UPDATE financeiro_ofx_descricoes
        SET categoria = :categoria,
            centro_custo = :centro_custo,
            destinatario = :destinatario,
            uso_count = uso_count + 1,
            ultimo_uso_em = NOW(),
            updated_at = NOW()
        WHERE descricao_normalizada = :descricao_normalizada
          AND COALESCE(banco, '') = COALESCE(:banco, '')
          AND COALESCE(conta, '') = COALESCE(:conta, '')
    ");
    $stmt->execute([
        ':categoria' => $categoria !== '' ? $categoria : null,
        ':centro_custo' => $centroCusto !== '' ? $centroCusto : null,
        ':destinatario' => trim((string)($item['destinatario'] ?? '')) ?: null,
        ':descricao_normalizada' => $item['descricao_normalizada'],
        ':banco' => $banco !== '' ? $banco : null,
        ':conta' => $conta !== '' ? $conta : null,
    ]);
}

function financeiro_listar_receitas(PDO $pdo, array $filters): array
{
    eventos_financeiro_ensure_schema($pdo);
    $where = ["r.status <> 'cancelado'"];
    $params = [];

    if (($filters['status'] ?? '') !== '') {
        $where[] = 'r.status = :status';
        $params[':status'] = $filters['status'];
    }
    if (($filters['unidade'] ?? '') !== '') {
        $where[] = 'COALESCE(r.unidade, e.space_visivel, \'\') = :unidade';
        $params[':unidade'] = $filters['unidade'];
    }
    if (($filters['q'] ?? '') !== '') {
        $where[] = '(r.descricao ILIKE :q OR e.nome_evento ILIKE :q)';
        $params[':q'] = '%' . $filters['q'] . '%';
    }

    $stmt = $pdo->prepare("
        SELECT r.*,
               e.nome_evento,
               e.space_visivel AS evento_unidade,
               e.data_evento::text AS data_evento
        FROM eventos_financeiro_receitas r
        LEFT JOIN logistica_eventos_espelho e ON e.id = r.evento_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY COALESCE(r.vencimento, r.created_at::date) DESC, r.id DESC
        LIMIT 500
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function financeiro_listar_despesas(PDO $pdo, array $filters): array
{
    financeiro_ensure_schema($pdo);
    $where = ["status <> 'cancelado'"];
    $params = [];

    if (($filters['status'] ?? '') !== '') {
        $where[] = 'status = :status';
        $params[':status'] = $filters['status'];
    }
    if (($filters['banco'] ?? '') !== '') {
        $where[] = 'COALESCE(banco, \'\') = :banco';
        $params[':banco'] = $filters['banco'];
    }
    if (($filters['categoria'] ?? '') !== '') {
        $where[] = 'COALESCE(categoria, \'\') = :categoria';
        $params[':categoria'] = $filters['categoria'];
    }
    if (($filters['q'] ?? '') !== '') {
        $where[] = 'descricao ILIKE :q';
        $params[':q'] = '%' . $filters['q'] . '%';
    }

    $stmt = $pdo->prepare("
        SELECT *
        FROM financeiro_despesas
        WHERE " . implode(' AND ', $where) . "
        ORDER BY data_movimento DESC, id DESC
        LIMIT 500
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function financeiro_resumo(PDO $pdo): array
{
    financeiro_ensure_schema($pdo);
    $receitasTotal = (float)$pdo->query("SELECT COALESCE(SUM(valor), 0) FROM eventos_financeiro_receitas WHERE status <> 'cancelado'")->fetchColumn();
    $recebido = (float)$pdo->query("SELECT COALESCE(SUM(valor), 0) FROM eventos_financeiro_receitas WHERE status = 'pago'")->fetchColumn();
    $despesasTotal = (float)$pdo->query("SELECT COALESCE(SUM(valor), 0) FROM financeiro_despesas WHERE status <> 'cancelado'")->fetchColumn();
    $despesasPagas = (float)$pdo->query("SELECT COALESCE(SUM(valor), 0) FROM financeiro_despesas WHERE status IN ('pago', 'conciliado')")->fetchColumn();
    return [
        'receitas' => $receitasTotal,
        'recebido' => $recebido,
        'despesas' => $despesasTotal,
        'despesas_pagas' => $despesasPagas,
        'saldo_previsto' => $receitasTotal - $despesasTotal,
        'saldo_realizado' => $recebido - $despesasPagas,
        'a_receber' => max(0, $receitasTotal - $recebido),
        'a_pagar' => max(0, $despesasTotal - $despesasPagas),
    ];
}

financeiro_ensure_schema($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'add_despesa') {
        $descricao = trim((string)($_POST['descricao'] ?? ''));
        $valor = financeiro_money($_POST['valor'] ?? 0);
        $data = trim((string)($_POST['data_movimento'] ?? ''));
        $status = (string)($_POST['status'] ?? 'pendente');
        $status = in_array($status, ['pendente', 'pago', 'conciliado'], true) ? $status : 'pendente';

        if ($descricao === '') {
            $errors[] = 'Informe a descricao da despesa.';
        } elseif ($valor <= 0) {
            $errors[] = 'Informe um valor maior que zero.';
        } elseif ($data === '') {
            $errors[] = 'Informe a data da despesa.';
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO financeiro_despesas
                    (data_movimento, descricao, valor, banco, conta, categoria, centro_custo, status, origem, created_by)
                VALUES
                    (:data_movimento, :descricao, :valor, :banco, :conta, :categoria, :centro_custo, :status, 'manual', :created_by)
            ");
            $stmt->execute([
                ':data_movimento' => $data,
                ':descricao' => $descricao,
                ':valor' => $valor,
                ':banco' => trim((string)($_POST['banco'] ?? '')) ?: null,
                ':conta' => trim((string)($_POST['conta'] ?? '')) ?: null,
                ':categoria' => trim((string)($_POST['categoria'] ?? '')) ?: null,
                ':centro_custo' => trim((string)($_POST['centro_custo'] ?? '')) ?: null,
                ':status' => $status,
                ':created_by' => $userId > 0 ? $userId : null,
            ]);
            $messages[] = 'Despesa lancada com sucesso.';
        }
    }

    if ($action === 'preview_ofx') {
        $banco = trim((string)($_POST['banco'] ?? ''));
        $conta = trim((string)($_POST['conta'] ?? ''));
        if (empty($_FILES['ofx_file']['tmp_name']) || !is_uploaded_file($_FILES['ofx_file']['tmp_name'])) {
            $errors[] = 'Selecione um arquivo OFX.';
        } else {
            $content = (string)file_get_contents($_FILES['ofx_file']['tmp_name']);
            $items = [];
            $parsedItems = financeiro_parse_ofx($content);
            $normalizedCounts = [];
            foreach ($parsedItems as $parsedItem) {
                $norm = (string)($parsedItem['descricao_normalizada'] ?? '');
                if ($norm !== '') {
                    $normalizedCounts[$norm] = ($normalizedCounts[$norm] ?? 0) + 1;
                }
            }

            foreach ($parsedItems as $item) {
                financeiro_salvar_descricao_banco($pdo, $item, $banco, $conta);
                $suggestion = financeiro_sugerir_descricao($pdo, (string)$item['descricao_normalizada'], $banco, $conta);
                $suggestionRow = is_array($suggestion['row'] ?? null) ? $suggestion['row'] : [];
                $item['banco'] = $banco;
                $item['conta'] = $conta;
                $item['categoria_sugerida'] = (string)($suggestionRow['categoria'] ?? '');
                $item['centro_custo_sugerido'] = (string)($suggestionRow['centro_custo'] ?? '');
                $item['destinatario'] = (string)($suggestionRow['destinatario'] ?? $item['destinatario'] ?? '');
                $item['sugestao_match'] = (string)($suggestion['match'] ?? '');
                $item['duplicado'] = financeiro_ofx_duplicate($pdo, $item, $banco, $conta);
                $item['recorrencia_sugerida'] = (($normalizedCounts[(string)$item['descricao_normalizada']] ?? 0) > 1);
                $items[] = $item;
            }

            if (!$items) {
                $errors[] = 'Nenhuma transacao valida encontrada no OFX.';
            } else {
                $_SESSION['financeiro_ofx_preview'] = [
                    'banco' => $banco,
                    'conta' => $conta,
                    'centro_custo_padrao' => trim((string)($_POST['centro_custo'] ?? '')),
                    'items' => $items,
                    'created_at' => time(),
                ];
                $activeTab = 'despesas';
                $messages[] = count($items) . ' transacao(oes) lida(s). Revise a pre-visualizacao antes de importar.';
            }
        }
    }

    if ($action === 'cancel_ofx_preview') {
        unset($_SESSION['financeiro_ofx_preview']);
        $messages[] = 'Pre-visualizacao OFX descartada.';
    }

    if ($action === 'confirm_ofx') {
        $preview = $_SESSION['financeiro_ofx_preview'] ?? null;
        $items = is_array($preview['items'] ?? null) ? $preview['items'] : [];
        $selected = is_array($_POST['selected'] ?? null) ? array_map('intval', $_POST['selected']) : [];
        $descricoes = is_array($_POST['descricao'] ?? null) ? $_POST['descricao'] : [];
        $categoriasPost = is_array($_POST['categoria'] ?? null) ? $_POST['categoria'] : [];
        $centrosPost = is_array($_POST['centro_custo'] ?? null) ? $_POST['centro_custo'] : [];
        $banco = trim((string)($preview['banco'] ?? ''));
        $conta = trim((string)($preview['conta'] ?? ''));
        $imported = 0;
        $blocked = 0;

        if (!$items) {
            $errors[] = 'A pre-visualizacao OFX expirou. Envie o arquivo novamente.';
        } elseif (!$selected) {
            $errors[] = 'Selecione pelo menos uma transacao para importar.';
        } else {
            foreach ($selected as $idx) {
                if (!isset($items[$idx]) || !is_array($items[$idx])) {
                    continue;
                }
                $item = $items[$idx];
                $item['descricao'] = trim((string)($descricoes[$idx] ?? $item['descricao']));
                $categoria = trim((string)($categoriasPost[$idx] ?? $item['categoria_sugerida'] ?? ''));
                $centroCusto = trim((string)($centrosPost[$idx] ?? $item['centro_custo_sugerido'] ?? $preview['centro_custo_padrao'] ?? ''));

                if (($item['tipo'] ?? '') !== 'despesa' || financeiro_ofx_duplicate($pdo, $item, $banco, $conta)) {
                    $blocked++;
                    continue;
                }

                $stmt = $pdo->prepare("
                    INSERT INTO financeiro_despesas
                        (data_movimento, descricao, valor, banco, conta, categoria, centro_custo, status, origem, ofx_fitid, ofx_payload, destinatario, created_by)
                    VALUES
                        (:data_movimento, :descricao, :valor, :banco, :conta, :categoria, :centro_custo, 'conciliado', 'ofx', :ofx_fitid, CAST(:payload AS JSONB), :destinatario, :created_by)
                ");
                $stmt->execute([
                    ':data_movimento' => $item['data'],
                    ':descricao' => $item['descricao'],
                    ':valor' => $item['valor'],
                    ':banco' => $banco !== '' ? $banco : null,
                    ':conta' => $conta !== '' ? $conta : null,
                    ':categoria' => $categoria !== '' ? $categoria : null,
                    ':centro_custo' => $centroCusto !== '' ? $centroCusto : null,
                    ':ofx_fitid' => $item['fitid'],
                    ':payload' => json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ':destinatario' => trim((string)($item['destinatario'] ?? '')) ?: null,
                    ':created_by' => $userId > 0 ? $userId : null,
                ]);
                financeiro_atualizar_aprendizado($pdo, $item, $banco, $conta, $categoria, $centroCusto);
                $imported++;
            }
            unset($_SESSION['financeiro_ofx_preview']);
            $messages[] = $imported . ' despesa(s) importada(s) do OFX. ' . $blocked . ' transacao(oes) bloqueada(s) por duplicidade ou tipo incompatível.';
        }
    }
}

$filters = [
    'status' => trim((string)($_GET['status'] ?? '')),
    'unidade' => trim((string)($_GET['unidade'] ?? '')),
    'banco' => trim((string)($_GET['banco'] ?? '')),
    'categoria' => trim((string)($_GET['categoria'] ?? '')),
    'q' => trim((string)($_GET['q'] ?? '')),
];
$activeTab = (string)($_GET['tab'] ?? 'receitas');
$activeTab = in_array($activeTab, ['receitas', 'despesas'], true) ? $activeTab : 'receitas';
if (!empty($_SESSION['financeiro_ofx_preview'])) {
    $activeTab = 'despesas';
}

$resumo = financeiro_resumo($pdo);
$receitas = financeiro_listar_receitas($pdo, $filters);
$despesas = financeiro_listar_despesas($pdo, $filters);

$unidades = [];
try {
    $stmt = $pdo->query("
        SELECT DISTINCT COALESCE(r.unidade, e.space_visivel) AS nome
        FROM eventos_financeiro_receitas r
        LEFT JOIN logistica_eventos_espelho e ON e.id = r.evento_id
        WHERE TRIM(COALESCE(r.unidade, e.space_visivel, '')) <> ''
        ORDER BY 1
    ");
    $unidades = array_values(array_filter(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [])));
} catch (Throwable $e) {
    $unidades = [];
}

$bancos = [];
try {
    $stmt = $pdo->query("SELECT DISTINCT banco FROM financeiro_despesas WHERE TRIM(COALESCE(banco, '')) <> '' ORDER BY banco");
    $bancos = array_values(array_filter(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [])));
} catch (Throwable $e) {
    $bancos = [];
}

$categorias = [];
try {
    $stmt = $pdo->query("
        SELECT nome, grupo
        FROM financeiro_categorias
        WHERE ativo IS TRUE AND tipo IN ('despesa', 'ambos')
        ORDER BY grupo ASC, ordem ASC, nome ASC
    ");
    $categorias = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$categorias) {
        $stmt = $pdo->query("SELECT DISTINCT categoria AS nome, 'Usadas' AS grupo FROM financeiro_despesas WHERE TRIM(COALESCE(categoria, '')) <> '' ORDER BY categoria");
        $categorias = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) {
    $categorias = [];
}

$ofxPreview = is_array($_SESSION['financeiro_ofx_preview'] ?? null) ? $_SESSION['financeiro_ofx_preview'] : null;
$ofxPreviewItems = is_array($ofxPreview['items'] ?? null) ? $ofxPreview['items'] : [];

includeSidebar('Financeiro');
?>

<style>
.finance-page{max-width:1540px;margin:0 auto;padding:1.5rem;background:#f7f9fc;color:#334155}
.finance-top{display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;margin-bottom:1rem}
.finance-title{margin:0;font-size:2rem;color:#1e3a8a;font-weight:900}
.finance-subtitle{margin:.3rem 0 0;color:#64748b}
.finance-actions{display:flex;gap:.7rem;flex-wrap:wrap;justify-content:flex-end}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:.45rem;border:0;border-radius:8px;padding:.72rem 1rem;font-weight:900;text-decoration:none;cursor:pointer;white-space:nowrap;font:inherit}
.btn-blue{background:#5ebfd4;color:#fff}.btn-green{background:#20c985;color:#fff}.btn-red{background:#e94c42;color:#fff}.btn-slate{background:#e2e8f0;color:#334155}.btn-yellow{background:#f4c44e;color:#fff}.btn-ghost{background:#fff;color:#334155;border:1px solid #dbe3ef}
.alert{padding:.85rem 1rem;border-radius:8px;margin-bottom:1rem;background:#fff;border-left:4px solid #5ebfd4;box-shadow:0 8px 20px rgba(15,23,42,.04)}.alert-error{border-left-color:#e05a43}.alert-success{border-left-color:#58c786}
.summary-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:1rem;margin:1.25rem 0}
.summary-card{background:#fff;border:1px solid #e2e8f0;border-left:4px solid #5ebfd4;box-shadow:0 14px 34px rgba(15,23,42,.07);border-radius:8px;padding:1.15rem;min-height:116px}
.summary-card h3{margin:0 0 .6rem;font-size:.82rem;text-transform:uppercase;color:#64748b}.summary-card .value{font-size:1.55rem;font-weight:900;color:#334155}.summary-card .hint{margin-top:.45rem;color:#64748b;font-size:.84rem}
.summary-card.receitas{border-left-color:#58c786}.summary-card.despesas{border-left-color:#e05a43}.summary-card.receber{border-left-color:#5ebfd4}.summary-card.saldo{border-left-color:#f4c44e}
.finance-shell{background:#fff;border:1px solid #e2e8f0;box-shadow:0 16px 38px rgba(15,23,42,.08);border-radius:10px;overflow:hidden}
.tabs{display:grid;grid-template-columns:1fr 1fr;background:#eef1f5;border-bottom:1px solid #dbe3ef}
.tab-link{display:flex;align-items:center;justify-content:center;gap:.45rem;padding:1rem;text-decoration:none;color:#64748b;font-weight:900;border-top:3px solid transparent}
.tab-link.active{background:#5ebfd4;color:#fff;border-top-color:#20c985}
.panel{padding:1.25rem}
.filters{display:grid;grid-template-columns:140px 1fr 1fr 1fr auto;gap:.7rem;align-items:end;margin-bottom:1rem}
label{font-weight:800;color:#475569;font-size:.84rem}input,select,textarea{width:100%;border:1px solid #cbd5e1;border-radius:8px;padding:.72rem .78rem;font:inherit;background:#fff}textarea{min-height:86px}
.filter-button{height:43px}.table-wrap{overflow:auto;border:1px solid #e2e8f0;border-radius:8px}.finance-table{width:100%;border-collapse:collapse;background:#fff;min-width:1060px}.finance-table th{background:#64727f;color:#fff;text-align:left;padding:.85rem;font-size:.8rem;text-transform:uppercase}.finance-table td{border-bottom:1px solid #e2e8f0;padding:.85rem;vertical-align:middle}
.badge{display:inline-flex;border-radius:999px;padding:.28rem .65rem;font-size:.76rem;font-weight:900}.badge-pendente{background:#fef3c7;color:#92400e}.badge-pago,.badge-conciliado{background:#dcfce7;color:#166534}.badge-vencido{background:#fee2e2;color:#991b1b}.badge-cancelado{background:#e2e8f0;color:#475569}.badge-receita{background:#58c786;color:#fff}.badge-despesa{background:#e05a43;color:#fff}
.wallet{font-weight:900;color:#475569}.wallet small{display:block;color:#64748b;font-weight:700;margin-top:.25rem}.muted{color:#64748b;font-size:.88rem}.money{font-weight:900;color:#374151}.money.out{color:#b42318}.event-name{font-weight:900;color:#1d4ed8}.event-meta{display:block;color:#64748b;font-size:.82rem;margin-top:.25rem}
.ofx-preview{border:1px solid #bae6fd;background:#f0f9ff;border-radius:10px;margin-bottom:1rem;overflow:hidden}.ofx-preview-head{display:flex;justify-content:space-between;gap:1rem;align-items:center;flex-wrap:wrap;padding:1rem;border-bottom:1px solid #bae6fd}.ofx-preview-title{margin:0;color:#075985;font-size:1.05rem;font-weight:900}.ofx-preview-meta{color:#0f766e;font-weight:800;font-size:.86rem}.ofx-preview-actions{display:flex;gap:.6rem;flex-wrap:wrap}.ofx-table{width:100%;border-collapse:collapse;min-width:1180px;background:#fff}.ofx-table th,.ofx-table td{border-bottom:1px solid #e2e8f0;padding:.72rem;text-align:left;vertical-align:middle}.ofx-table th{background:#e0f2fe;color:#075985;font-size:.76rem;text-transform:uppercase}.ofx-table tr.duplicate{background:#fff7ed}.ofx-table input[type="text"],.ofx-table select{padding:.55rem .62rem;border-radius:7px}.ofx-status{display:inline-flex;border-radius:999px;padding:.24rem .55rem;font-size:.74rem;font-weight:900;background:#dcfce7;color:#166534}.ofx-status.warn{background:#fed7aa;color:#9a3412}.ofx-status.info{background:#e0f2fe;color:#075985}.ofx-small{display:block;color:#64748b;font-size:.78rem;margin-top:.25rem}.ofx-editable-desc{min-width:260px}
.modal-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:1000;display:none;align-items:center;justify-content:center;padding:1rem}.modal-backdrop.open{display:flex}
.modal{width:min(860px,100%);max-height:calc(100vh - 2rem);overflow:auto;background:#fff;border-radius:12px;box-shadow:0 24px 70px rgba(15,23,42,.28)}
.modal-header{display:flex;justify-content:space-between;gap:1rem;align-items:center;padding:1rem 1.15rem;border-bottom:1px solid #e2e8f0}.modal-title{margin:0;color:#1e293b;font-weight:900}.modal-close{width:38px;height:38px;border:0;border-radius:999px;background:#f1f5f9;color:#334155;font-size:1.25rem;cursor:pointer}
.modal-body{padding:1rem;display:grid;gap:1rem}.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.9rem}.field.full{grid-column:1/-1}.modal-actions{display:flex;justify-content:flex-end;gap:.7rem;padding:1rem;border-top:1px solid #e2e8f0}
@media(max-width:1050px){.summary-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.filters{grid-template-columns:1fr 1fr}.finance-top{flex-direction:column}.finance-actions{justify-content:flex-start}.form-grid{grid-template-columns:1fr}}
@media(max-width:650px){.summary-grid{grid-template-columns:1fr}.filters{grid-template-columns:1fr}.panel{padding:1rem .75rem}.finance-page{padding:1rem}}
</style>

<div class="finance-page">
    <div class="finance-top">
        <div>
            <h1 class="finance-title">Financeiro</h1>
            <p class="finance-subtitle">Entradas dos eventos, despesas operacionais e importacao de OFX bancario.</p>
        </div>
        <div class="finance-actions">
            <button class="btn btn-green" type="button" data-open-modal="despesa-modal">+ Nova despesa</button>
            <button class="btn btn-blue" type="button" data-open-modal="ofx-modal">Importar OFX</button>
        </div>
    </div>

    <?php foreach ($errors as $error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endforeach; ?>
    <?php foreach ($messages as $message): ?><div class="alert alert-success"><?= h($message) ?></div><?php endforeach; ?>

    <div class="summary-grid">
        <div class="summary-card receitas"><h3>Receitas</h3><div class="value"><?= h(format_currency($resumo['receitas'])) ?></div><div class="hint">Lancadas nos eventos</div></div>
        <div class="summary-card despesas"><h3>Despesas</h3><div class="value"><?= h(format_currency($resumo['despesas'])) ?></div><div class="hint">Manuais e OFX</div></div>
        <div class="summary-card receber"><h3>A receber / pagar</h3><div class="value"><?= h(format_currency($resumo['a_receber'])) ?></div><div class="hint">A pagar: <?= h(format_currency($resumo['a_pagar'])) ?></div></div>
        <div class="summary-card saldo"><h3>Saldo previsto</h3><div class="value"><?= h(format_currency($resumo['saldo_previsto'])) ?></div><div class="hint">Realizado: <?= h(format_currency($resumo['saldo_realizado'])) ?></div></div>
    </div>

    <section class="finance-shell">
        <div class="tabs">
            <a class="tab-link <?= $activeTab === 'receitas' ? 'active' : '' ?>" href="index.php?page=financeiro&tab=receitas">↑ Receitas</a>
            <a class="tab-link <?= $activeTab === 'despesas' ? 'active' : '' ?>" href="index.php?page=financeiro&tab=despesas">↓ Despesas</a>
        </div>

        <div class="panel">
            <form method="get" class="filters">
                <input type="hidden" name="page" value="financeiro">
                <input type="hidden" name="tab" value="<?= h($activeTab) ?>">
                <div>
                    <label>Status</label>
                    <select name="status">
                        <option value="">Todos</option>
                        <?php foreach (['pendente', 'pago', 'conciliado', 'vencido'] as $status): ?>
                            <option value="<?= h($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>><?= h(financeiro_status_label($status)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label><?= $activeTab === 'receitas' ? 'Unidade' : 'Banco' ?></label>
                    <?php if ($activeTab === 'receitas'): ?>
                        <select name="unidade">
                            <option value="">Todas unidades</option>
                            <?php foreach ($unidades as $unidade): ?><option value="<?= h($unidade) ?>" <?= $filters['unidade'] === $unidade ? 'selected' : '' ?>><?= h($unidade) ?></option><?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <select name="banco">
                            <option value="">Todos bancos</option>
                            <?php foreach ($bancos as $banco): ?><option value="<?= h($banco) ?>" <?= $filters['banco'] === $banco ? 'selected' : '' ?>><?= h($banco) ?></option><?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>
                <div>
                    <label>Categoria</label>
                    <?php if ($activeTab === 'despesas'): ?>
                        <select name="categoria">
                            <option value="">Todas categorias</option>
                            <?php
                            $grupoAtual = null;
                            foreach ($categorias as $categoria):
                                $nomeCategoria = (string)($categoria['nome'] ?? '');
                                $grupoCategoria = (string)($categoria['grupo'] ?? 'Geral');
                                if ($grupoAtual !== $grupoCategoria):
                                    if ($grupoAtual !== null): ?></optgroup><?php endif; ?>
                                    <optgroup label="<?= h($grupoCategoria) ?>">
                                    <?php $grupoAtual = $grupoCategoria; ?>
                                <?php endif; ?>
                                <option value="<?= h($nomeCategoria) ?>" <?= $filters['categoria'] === $nomeCategoria ? 'selected' : '' ?>><?= h($nomeCategoria) ?></option>
                            <?php endforeach; if ($grupoAtual !== null): ?></optgroup><?php endif; ?>
                        </select>
                    <?php else: ?>
                        <select disabled><option>Todas categorias</option></select>
                    <?php endif; ?>
                </div>
                <div>
                    <label>Pesquisar</label>
                    <input name="q" value="<?= h($filters['q']) ?>" placeholder="Descricao ou evento">
                </div>
                <button class="btn btn-slate filter-button" type="submit">Filtrar</button>
            </form>

            <?php if ($activeTab === 'receitas'): ?>
                <div class="table-wrap">
                    <table class="finance-table">
                        <thead><tr><th>Acoes</th><th>Data</th><th>Descricao</th><th>Responsavel</th><th>Valor</th><th>Categoria/Banco</th><th>Status</th><th>Link</th></tr></thead>
                        <tbody>
                        <?php foreach ($receitas as $receita): ?>
                            <?php
                            $status = (string)($receita['status'] ?? 'pendente');
                            $modo = (string)($receita['modo_pagamento'] ?? $receita['forma_pagamento'] ?? '');
                            $unidade = (string)($receita['unidade'] ?? $receita['evento_unidade'] ?? '');
                            ?>
                            <tr>
                                <td><span class="badge badge-receita">↑</span></td>
                                <td><strong><?= h(brDateOnly((string)($receita['vencimento'] ?? ''))) ?></strong></td>
                                <td>
                                    <?= h((string)$receita['descricao']) ?>
                                    <?php if ((int)($receita['parcelas_total'] ?? 1) > 1): ?><span class="badge"><?= (int)$receita['parcela_numero'] ?>/<?= (int)$receita['parcelas_total'] ?></span><?php endif; ?>
                                </td>
                                <td><span class="event-name"><?= h((string)($receita['nome_evento'] ?? 'Evento')) ?></span><span class="event-meta"><?= h($unidade) ?></span></td>
                                <td><span class="money"><?= h(format_currency($receita['valor'])) ?></span></td>
                                <td><span class="wallet">Receita<small><?= h(financeiro_modo_label($modo)) ?> · <?= h(ucfirst((string)($receita['carteira'] ?? 'manual'))) ?></small></span></td>
                                <td><span class="badge badge-<?= h($status) ?>"><?= h(financeiro_status_label($status)) ?></span></td>
                                <td>
                                    <?php if (!empty($receita['asaas_invoice_url'])): ?>
                                        <a href="<?= h((string)$receita['asaas_invoice_url']) ?>" target="_blank" rel="noopener">Abrir</a>
                                    <?php else: ?>
                                        <a href="index.php?page=eventos_financeiro&evento_id=<?= (int)$receita['evento_id'] ?>">Evento</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$receitas): ?><tr><td colspan="8" class="muted">Nenhuma receita encontrada.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <?php if ($ofxPreviewItems): ?>
                    <section class="ofx-preview">
                        <div class="ofx-preview-head">
                            <div>
                                <h2 class="ofx-preview-title">Pre-visualizacao OFX</h2>
                                <div class="ofx-preview-meta">
                                    Banco: <?= h((string)($ofxPreview['banco'] ?? '')) ?: 'Nao informado' ?> ·
                                    Conta: <?= h((string)($ofxPreview['conta'] ?? '')) ?: 'Nao informada' ?> ·
                                    <?= count($ofxPreviewItems) ?> transacao(oes)
                                </div>
                            </div>
                            <div class="ofx-preview-actions">
                                <form method="post">
                                    <input type="hidden" name="action" value="cancel_ofx_preview">
                                    <button class="btn btn-ghost" type="submit">Descartar pre-visualizacao</button>
                                </form>
                            </div>
                        </div>
                        <form method="post">
                            <input type="hidden" name="action" value="confirm_ofx">
                            <div class="table-wrap" style="border:0;border-radius:0">
                                <table class="ofx-table">
                                    <thead>
                                        <tr>
                                            <th><input type="checkbox" data-select-all-ofx checked></th>
                                            <th>Data</th>
                                            <th>Descricao</th>
                                            <th>Valor</th>
                                            <th>Categoria</th>
                                            <th>Status</th>
                                            <th>Editar antes de importar</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($ofxPreviewItems as $idx => $item): ?>
                                        <?php
                                        $isDespesa = (string)($item['tipo'] ?? '') === 'despesa';
                                        $isDuplicate = !empty($item['duplicado']);
                                        $canImport = $isDespesa && !$isDuplicate;
                                        $suggestedCategory = (string)($item['categoria_sugerida'] ?? '');
                                        ?>
                                        <tr class="<?= $isDuplicate ? 'duplicate' : '' ?>">
                                            <td>
                                                <input type="checkbox" name="selected[]" value="<?= (int)$idx ?>" <?= $canImport ? 'checked' : 'disabled' ?> data-ofx-row-check>
                                            </td>
                                            <td><strong><?= h(brDateOnly((string)$item['data'])) ?></strong></td>
                                            <td>
                                                <input class="ofx-editable-desc" type="text" name="descricao[<?= (int)$idx ?>]" value="<?= h((string)$item['descricao']) ?>">
                                                <span class="ofx-small">Destinatario: <?= h((string)($item['destinatario'] ?: 'Nao identificado')) ?></span>
                                                <?php if (!empty($item['parcela_sugerida'])): ?>
                                                    <span class="ofx-small">Possivel parcela: <?= h((string)$item['parcela_sugerida']) ?></span>
                                                <?php endif; ?>
                                                <?php if (!empty($item['recorrencia_sugerida'])): ?>
                                                    <span class="ofx-small">Possivel recorrencia no arquivo</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><span class="money <?= $isDespesa ? 'out' : '' ?>"><?= h(format_currency($item['valor'])) ?></span><span class="ofx-small"><?= h((string)$item['tipo']) ?></span></td>
                                            <td>
                                                <select name="categoria[<?= (int)$idx ?>]" <?= $canImport ? '' : 'disabled' ?>>
                                                    <option value="">Selecionar categoria</option>
                                                    <?php
                                                    $grupoAtual = null;
                                                    foreach ($categorias as $categoria):
                                                        $nomeCategoria = (string)($categoria['nome'] ?? '');
                                                        $grupoCategoria = (string)($categoria['grupo'] ?? 'Geral');
                                                        if ($grupoAtual !== $grupoCategoria):
                                                            if ($grupoAtual !== null): ?></optgroup><?php endif; ?>
                                                            <optgroup label="<?= h($grupoCategoria) ?>">
                                                            <?php $grupoAtual = $grupoCategoria; ?>
                                                        <?php endif; ?>
                                                        <option value="<?= h($nomeCategoria) ?>" <?= $suggestedCategory === $nomeCategoria ? 'selected' : '' ?>><?= h($nomeCategoria) ?></option>
                                                    <?php endforeach; if ($grupoAtual !== null): ?></optgroup><?php endif; ?>
                                                </select>
                                                <?php if (!empty($item['sugestao_match'])): ?>
                                                    <span class="ofx-small">Sugestao <?= h((string)$item['sugestao_match']) ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($isDuplicate): ?>
                                                    <span class="ofx-status warn">Possivel duplicidade</span>
                                                <?php elseif (!$isDespesa): ?>
                                                    <span class="ofx-status info">Nao e despesa</span>
                                                <?php else: ?>
                                                    <span class="ofx-status">Pronto para importar</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <input type="text" name="centro_custo[<?= (int)$idx ?>]" value="<?= h((string)($item['centro_custo_sugerido'] ?? $ofxPreview['centro_custo_padrao'] ?? '')) ?>" placeholder="Centro de custo" <?= $canImport ? '' : 'disabled' ?>>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="modal-actions">
                                <button class="btn btn-blue" type="submit">Importar selecionadas</button>
                            </div>
                        </form>
                    </section>
                <?php endif; ?>

                <div class="table-wrap">
                    <table class="finance-table">
                        <thead><tr><th>Acoes</th><th>Data</th><th>Descricao</th><th>Origem</th><th>Valor</th><th>Categoria/Banco</th><th>Centro de custo</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($despesas as $despesa): ?>
                            <?php $status = (string)($despesa['status'] ?? 'pendente'); ?>
                            <tr>
                                <td><span class="badge badge-despesa">↓</span></td>
                                <td><strong><?= h(brDateOnly((string)$despesa['data_movimento'])) ?></strong></td>
                                <td><?= h((string)$despesa['descricao']) ?></td>
                                <td><?= h(strtoupper((string)$despesa['origem'])) ?></td>
                                <td><span class="money out"><?= h(format_currency($despesa['valor'])) ?></span></td>
                                <td><span class="wallet"><?= h((string)($despesa['categoria'] ?: 'Nao informado')) ?><small><?= h((string)($despesa['banco'] ?: 'Sem banco')) ?><?= !empty($despesa['conta']) ? ' · ' . h((string)$despesa['conta']) : '' ?></small></span></td>
                                <td><?= h((string)($despesa['centro_custo'] ?: '-')) ?></td>
                                <td><span class="badge badge-<?= h($status) ?>"><?= h(financeiro_status_label($status)) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$despesas): ?><tr><td colspan="8" class="muted">Nenhuma despesa encontrada.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<div class="modal-backdrop" id="despesa-modal" role="dialog" aria-modal="true" aria-labelledby="despesa-title">
    <div class="modal">
        <div class="modal-header">
            <h2 class="modal-title" id="despesa-title">Nova despesa</h2>
            <button class="modal-close" type="button" data-close-modal>×</button>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="add_despesa">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="field full"><label>Descricao</label><input name="descricao" required placeholder="Ex: Pagamento fornecedor"></div>
                    <div><label>Data</label><input type="date" name="data_movimento" value="<?= h(date('Y-m-d')) ?>" required></div>
                    <div><label>Valor</label><input name="valor" inputmode="decimal" placeholder="R$ 0,00" required></div>
                    <div><label>Banco</label><input name="banco" placeholder="Ex: Santander"></div>
                    <div><label>Conta</label><input name="conta" placeholder="Ex: Conta principal"></div>
                    <div><label>Categoria</label><select name="categoria">
                        <option value="">Selecionar categoria</option>
                        <?php
                        $grupoAtual = null;
                        foreach ($categorias as $categoria):
                            $nomeCategoria = (string)($categoria['nome'] ?? '');
                            $grupoCategoria = (string)($categoria['grupo'] ?? 'Geral');
                            if ($grupoAtual !== $grupoCategoria):
                                if ($grupoAtual !== null): ?></optgroup><?php endif; ?>
                                <optgroup label="<?= h($grupoCategoria) ?>">
                                <?php $grupoAtual = $grupoCategoria; ?>
                            <?php endif; ?>
                            <option value="<?= h($nomeCategoria) ?>"><?= h($nomeCategoria) ?></option>
                        <?php endforeach; if ($grupoAtual !== null): ?></optgroup><?php endif; ?>
                    </select></div>
                    <div><label>Centro de custo</label><input name="centro_custo" placeholder="Ex: Eventos"></div>
                    <div><label>Status</label><select name="status"><option value="pendente">Pendente</option><option value="pago">Pago</option><option value="conciliado">Conciliado</option></select></div>
                </div>
            </div>
            <div class="modal-actions">
                <button class="btn btn-ghost" type="button" data-close-modal>Cancelar</button>
                <button class="btn btn-green" type="submit">Salvar despesa</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-backdrop" id="ofx-modal" role="dialog" aria-modal="true" aria-labelledby="ofx-title">
    <div class="modal">
        <div class="modal-header">
            <h2 class="modal-title" id="ofx-title">Importar OFX bancario</h2>
            <button class="modal-close" type="button" data-close-modal>×</button>
        </div>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="preview_ofx">
            <div class="modal-body">
                <div class="form-grid">
                    <div><label>Banco</label><input name="banco" placeholder="Ex: Santander" required></div>
                    <div><label>Conta</label><input name="conta" placeholder="Ex: 0358 Smile"></div>
                    <div><label>Centro de custo padrao</label><input name="centro_custo" placeholder="Ex: Administrativo"></div>
                    <div class="field full"><label>Arquivo OFX</label><input type="file" name="ofx_file" accept=".ofx,.OFX,application/x-ofx,text/plain,application/octet-stream" required></div>
                    <div class="field full muted">O arquivo sera lido primeiro em uma pre-visualizacao. Nenhuma transacao sera lancada sem sua confirmacao.</div>
                </div>
            </div>
            <div class="modal-actions">
                <button class="btn btn-ghost" type="button" data-close-modal>Cancelar</button>
                <button class="btn btn-blue" type="submit">Pre-visualizar OFX</button>
            </div>
        </form>
    </div>
</div>

<script>
document.querySelectorAll('[data-open-modal]').forEach((button) => {
    button.addEventListener('click', () => {
        document.getElementById(button.dataset.openModal)?.classList.add('open');
    });
});
document.querySelectorAll('[data-close-modal]').forEach((button) => {
    button.addEventListener('click', () => button.closest('.modal-backdrop')?.classList.remove('open'));
});
document.querySelectorAll('.modal-backdrop').forEach((modal) => {
    modal.addEventListener('click', (event) => {
        if (event.target === modal) modal.classList.remove('open');
    });
});
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        document.querySelectorAll('.modal-backdrop.open').forEach((modal) => modal.classList.remove('open'));
    }
});
document.querySelector('[data-select-all-ofx]')?.addEventListener('change', (event) => {
    document.querySelectorAll('[data-ofx-row-check]').forEach((checkbox) => {
        if (!checkbox.disabled) checkbox.checked = event.target.checked;
    });
});
</script>

<?php endSidebar(); ?>
