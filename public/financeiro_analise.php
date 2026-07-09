<?php
/**
 * financeiro_analise.php
 * Analise financeira mensal com performance, comparativo e DRE por categorias.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/sidebar_integration.php';

if (empty($_SESSION['perm_financeiro']) && empty($_SESSION['perm_superadmin'])) {
    header('Location: index.php?page=dashboard');
    exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function fa_request_param(string $name): ?string
{
    if (isset($_GET[$name]) && is_scalar($_GET[$name])) {
        return (string)$_GET[$name];
    }

    $routeParams = $GLOBALS['PAINEL_CURRENT_ROUTE_QUERY'] ?? null;
    if (is_array($routeParams) && isset($routeParams[$name]) && is_scalar($routeParams[$name])) {
        return (string)$routeParams[$name];
    }

    $querySources = [
        (string)($GLOBALS['PAINEL_CURRENT_ROUTE_QUERY_STRING'] ?? ''),
        (string)($_SERVER['QUERY_STRING'] ?? ''),
    ];

    foreach ([
        (string)($GLOBALS['PAINEL_CURRENT_ROUTE_URI'] ?? ''),
        (string)($_SERVER['REQUEST_URI'] ?? ''),
        (string)($_SERVER['HTTP_X_ORIGINAL_URL'] ?? ''),
        (string)($_SERVER['HTTP_X_REWRITE_URL'] ?? ''),
    ] as $uriSource) {
        if ($uriSource === '') {
            continue;
        }
        $querySources[] = (string)(parse_url(str_replace('&amp;', '&', $uriSource), PHP_URL_QUERY) ?? '');
    }

    foreach ($querySources as $queryString) {
        if ($queryString === '') {
            continue;
        }
        parse_str(str_replace('&amp;', '&', $queryString), $parsed);
        if (isset($parsed[$name]) && is_scalar($parsed[$name])) {
            return (string)$parsed[$name];
        }
    }

    return null;
}

function fa_parse_month(string $value): DateTimeImmutable
{
    $value = trim($value);
    if (!preg_match('/^\d{4}-\d{2}$/', $value)) {
        $value = date('Y-m');
    }
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $value . '-01');
    return $dt ?: new DateTimeImmutable('first day of this month');
}

function fa_month_label(DateTimeImmutable $month): string
{
    $months = [
        1 => 'JANEIRO', 2 => 'FEVEREIRO', 3 => 'MARCO', 4 => 'ABRIL',
        5 => 'MAIO', 6 => 'JUNHO', 7 => 'JULHO', 8 => 'AGOSTO',
        9 => 'SETEMBRO', 10 => 'OUTUBRO', 11 => 'NOVEMBRO', 12 => 'DEZEMBRO',
    ];
    return $months[(int)$month->format('n')] . ' - ' . $month->format('Y');
}

function fa_norm(?string $value): string
{
    $value = trim((string)$value);
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if ($ascii !== false) {
        $value = $ascii;
    }
    $value = strtoupper($value);
    $value = preg_replace('/[^A-Z0-9]+/', ' ', $value);
    return trim((string)preg_replace('/\s+/', ' ', (string)$value));
}

function fa_unidade_sql(string $expr): string
{
    $raw = "TRIM(COALESCE({$expr}, ''))";
    $norm = "REGEXP_REPLACE(UPPER({$raw}), '\\s+', ' ', 'g')";
    return "
        CASE
            WHEN {$raw} = '' THEN 'Não Informado'
            WHEN {$norm} IN ('DIVERKIDS', 'DIVER KIDS') THEN 'DIVERKIDS'
            WHEN {$norm} IN ('LISBON 1', 'LISBON I') THEN 'LISBON 1'
            WHEN {$norm} LIKE '%GARDEN%' THEN 'GARDEN'
            WHEN {$norm} = 'CRISTAL' THEN 'CRISTAL'
            WHEN {$norm} = 'GRUPO SMILE' THEN 'GRUPO SMILE'
            WHEN {$norm} IN ('NAO INFORMADO', 'NÃO INFORMADO') THEN 'Não Informado'
            ELSE {$raw}
        END
    ";
}

function fa_in_category(?string $category, array $names): bool
{
    $needle = fa_norm($category);
    foreach ($names as $name) {
        if ($needle === fa_norm($name)) {
            return true;
        }
    }
    return false;
}

function fa_category_bucket(?string $category): string
{
    $category = trim((string)$category);
    if ($category === '') {
        return 'Outras Despesas';
    }

    if (fa_in_category($category, ['IRPJ', 'Simples Nacional'])) {
        return 'Impostos sobre vendas';
    }
    if (fa_in_category($category, ['Taxas bancarias', 'Taxas Cobrança', 'Taxas Cobranca', 'Taxas Maquininhas'])) {
        return 'Taxa de cobrança';
    }
    if (fa_in_category($category, ['Comissao de vendedores', 'Comissão de vendedores'])) {
        return 'Comissões sobre vendas';
    }
    if (fa_in_category($category, ['Devolução Cliente', 'Devolucao Cliente'])) {
        return 'Devolução de vendas';
    }
    if (fa_in_category($category, ['Agua', 'Água e esgoto', 'Agua e esgoto', 'Energia elétrica', 'Energia eletrica', 'Luz'])) {
        return 'Agua e Luz';
    }
    if (fa_in_category($category, [
        'Bebidas', 'Bolo', 'Custos Eventos', 'Decoração', 'Decoracao', 'DJ', 'Doces', 'Fornecedores',
        'Frutas congeladas', 'Gelo', 'Hort Frut', 'Insumos', 'Mini Lanchinhos', 'Pão / Padaria',
        'Pao / Padaria', 'Pao Padaria', 'Salgados', 'Toalhas', 'Aquisição de equipamentos',
        'Aquisicao de equipamentos', 'Aquisição de materiais', 'Aquisicao de materiais'
    ])) {
        return 'Custo de Materiais';
    }
    if (fa_in_category($category, ['Barmen', 'Freelancer', 'Free lancer', 'Free lance'])) {
        return 'Freelancer';
    }
    if (fa_in_category($category, ['Combustível', 'Combustivel', 'Passagem aéreas', 'Passagem aereas', 'Translado'])) {
        return 'Transporte e Logística';
    }
    if (fa_in_category($category, ['Rescisões trabalhistas', 'Rescisoes trabalhistas', 'RH'])) {
        return 'Rescisões Trabalhistas';
    }
    if (fa_in_category($category, ['Material de escritório', 'Material de escritorio'])) {
        return 'Despesas Comerciais';
    }
    if (fa_in_category($category, [
        'Cartório', 'Cartorio', 'Iof', 'IR', 'Juros', 'Limpeza', 'Manutenção de equipamentos',
        'Manutencao de equipamentos', 'Transportes (Uber e etc)'
    ])) {
        return 'Despesas Gerais';
    }
    if (fa_in_category($category, [
        '13º Salário', '13o Salario', 'Aluguel', 'Assistencia Juridica', 'Beneficios (V.A., VT, VR)',
        'Benefícios (V.A., VT, VR)', 'Bonificação Func.', 'Bonificacao Func.', 'Contabilidade',
        'Financiamento Carro', 'INSS', 'Internet', 'IPTU', 'IPVA', 'Medicina do Trabalho', 'Pro-Labore',
        'Publicidade', 'Salário', 'Salario', 'Salarios', 'Seguro Patrimonial', 'Sistemas em geral',
        'Taxa de lixo', 'Telefone', 'Telefone celular', 'Telefone fixo', 'Vale Alimentação',
        'Vale Alimentacao', 'Vale Transporte'
    ])) {
        return 'Despesas Fixas';
    }
    if (fa_in_category($category, ['Empréstimos', 'Emprestimos'])) {
        return 'Despesas Financeiras';
    }

    return 'Outras Despesas';
}

function fa_receita_data_expr(string $dateBase): string
{
    return $dateBase === 'pagamento'
        ? "COALESCE(pago_em::date, vencimento, created_at::date)"
        : "COALESCE(vencimento, created_at::date)";
}

function fa_formatura_exists(PDO $pdo): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    try {
        $exists = trim((string)$pdo->query("SELECT to_regclass('eventos_formatura_financeiro')")->fetchColumn()) !== '';
    } catch (Throwable $e) {
        $exists = false;
    }
    return $exists;
}

function fa_receita_sources(PDO $pdo, string $dateBase): string
{
    $dateExpr = fa_receita_data_expr($dateBase);
    $sources = ["
        SELECT valor, status, forma_pagamento, modo_pagamento, unidade, descricao, {$dateExpr} AS data_ref
        FROM eventos_financeiro_receitas
    "];

    if (fa_formatura_exists($pdo)) {
        $sources[] = "
            SELECT valor, status, forma_pagamento, modo_pagamento, unidade, descricao, {$dateExpr} AS data_ref
            FROM eventos_formatura_financeiro
        ";
    }

    return implode("\nUNION ALL\n", $sources);
}

function fa_status_clause(string $alias, string $status, bool $expense = false): string
{
    if ($status !== 'pago') {
        return "{$alias}.status <> 'cancelado'";
    }
    return $expense ? "{$alias}.status IN ('pago', 'conciliado')" : "{$alias}.status = 'pago'";
}

function fa_summary(PDO $pdo, string $start, string $end, string $dateBase, string $status): array
{
    $receitaSql = fa_receita_sources($pdo, $dateBase);
    $receitaStatus = fa_status_clause('r', $status, false);
    $despesaStatus = fa_status_clause('d', $status, true);
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(valor), 0) AS total,
            COALESCE(SUM(CASE WHEN status = 'pago' THEN valor ELSE 0 END), 0) AS recebido,
            COUNT(*) AS qtd,
            COUNT(*) FILTER (WHERE status = 'pago') AS qtd_pago
        FROM ({$receitaSql}) r
        WHERE {$receitaStatus}
          AND data_ref >= CAST(:start AS DATE)
          AND data_ref < CAST(:end AS DATE)
    ");
    $stmt->execute([':start' => $start, ':end' => $end]);
    $receitas = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(valor), 0) AS total,
            COALESCE(SUM(CASE WHEN status IN ('pago', 'conciliado') THEN valor ELSE 0 END), 0) AS pago,
            COUNT(*) AS qtd,
            COUNT(*) FILTER (WHERE status IN ('pago', 'conciliado')) AS qtd_pago
        FROM financeiro_despesas d
        WHERE {$despesaStatus}
          AND d.data_movimento >= CAST(:start AS DATE)
          AND d.data_movimento < CAST(:end AS DATE)
    ");
    $stmt->execute([':start' => $start, ':end' => $end]);
    $despesas = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $receitaTotal = (float)($receitas['total'] ?? 0);
    $despesaTotal = (float)($despesas['total'] ?? 0);
    $resultado = $receitaTotal - $despesaTotal;

    return [
        'receitas' => $receitaTotal,
        'recebido' => (float)($receitas['recebido'] ?? 0),
        'receitas_qtd' => (int)($receitas['qtd'] ?? 0),
        'despesas' => $despesaTotal,
        'despesas_pagas' => (float)($despesas['pago'] ?? 0),
        'despesas_qtd' => (int)($despesas['qtd'] ?? 0),
        'resultado' => $resultado,
        'margem' => $receitaTotal > 0 ? ($resultado / $receitaTotal) * 100 : 0,
        'a_receber' => max(0, $receitaTotal - (float)($receitas['recebido'] ?? 0)),
        'a_pagar' => max(0, $despesaTotal - (float)($despesas['pago'] ?? 0)),
    ];
}

function fa_delta(float $current, float $previous): array
{
    if (abs($previous) < 0.01) {
        return ['value' => $current > 0 ? 100.0 : 0.0, 'label' => $current > 0 ? '+100,0%' : '0,0%'];
    }
    $pct = (($current - $previous) / abs($previous)) * 100;
    return ['value' => $pct, 'label' => ($pct >= 0 ? '+' : '') . number_format($pct, 1, ',', '.') . '%'];
}

function fa_top_receitas(PDO $pdo, string $start, string $end, string $dateBase, string $status): array
{
    $receitaSql = fa_receita_sources($pdo, $dateBase);
    $receitaStatus = fa_status_clause('r', $status, false);
    $unidadeSql = fa_unidade_sql('unidade');
    $stmt = $pdo->prepare("
        SELECT {$unidadeSql} AS categoria,
               COALESCE(SUM(valor), 0) AS valor,
               COUNT(*) AS qtd
        FROM ({$receitaSql}) r
        WHERE {$receitaStatus}
          AND data_ref >= CAST(:start AS DATE)
          AND data_ref < CAST(:end AS DATE)
        GROUP BY 1
        ORDER BY valor DESC
        LIMIT 8
    ");
    $stmt->execute([':start' => $start, ':end' => $end]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function fa_top_despesas(PDO $pdo, string $start, string $end, string $status): array
{
    $despesaStatus = fa_status_clause('d', $status, true);
    $stmt = $pdo->prepare("
        SELECT COALESCE(NULLIF(categoria, ''), 'Nao Informado') AS categoria,
               COALESCE(SUM(valor), 0) AS valor,
               COUNT(*) AS qtd
        FROM financeiro_despesas d
        WHERE {$despesaStatus}
          AND d.data_movimento >= CAST(:start AS DATE)
          AND d.data_movimento < CAST(:end AS DATE)
        GROUP BY 1
        ORDER BY valor DESC
        LIMIT 10
    ");
    $stmt->execute([':start' => $start, ':end' => $end]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function fa_despesas_por_categoria(PDO $pdo, string $start, string $end, string $status): array
{
    $despesaStatus = fa_status_clause('d', $status, true);
    $stmt = $pdo->prepare("
        SELECT COALESCE(NULLIF(categoria, ''), 'Nao Informado') AS categoria,
               COALESCE(SUM(valor), 0) AS valor,
               COUNT(*) AS qtd
        FROM financeiro_despesas d
        WHERE {$despesaStatus}
          AND d.data_movimento >= CAST(:start AS DATE)
          AND d.data_movimento < CAST(:end AS DATE)
        GROUP BY 1
        ORDER BY valor DESC
    ");
    $stmt->execute([':start' => $start, ':end' => $end]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function fa_build_dre(array $summary, array $despesasCategorias): array
{
    $buckets = [];
    $bucketDetails = [];
    foreach ($despesasCategorias as $row) {
        $bucket = fa_category_bucket((string)$row['categoria']);
        $category = (string)($row['categoria'] ?? 'Nao Informado');
        $value = (float)$row['valor'];
        $buckets[$bucket] = ($buckets[$bucket] ?? 0) + $value;
        if (!isset($bucketDetails[$bucket][$category])) {
            $bucketDetails[$bucket][$category] = ['categoria' => $category, 'valor' => 0.0, 'qtd' => 0];
        }
        $bucketDetails[$bucket][$category]['valor'] += $value;
        $bucketDetails[$bucket][$category]['qtd'] += (int)($row['qtd'] ?? 0);
    }
    foreach ($bucketDetails as $bucket => $items) {
        usort($items, static fn(array $a, array $b): int => $b['valor'] <=> $a['valor']);
        $bucketDetails[$bucket] = $items;
    }

    $deducoes = ($buckets['Impostos sobre vendas'] ?? 0) + ($buckets['Taxa de cobrança'] ?? 0) + ($buckets['Comissões sobre vendas'] ?? 0) + ($buckets['Devolução de vendas'] ?? 0);
    $receitaLiquida = $summary['receitas'] - $deducoes;
    $custos = ($buckets['Agua e Luz'] ?? 0) + ($buckets['Custo de Materiais'] ?? 0) + ($buckets['Freelancer'] ?? 0) + ($buckets['Transporte e Logística'] ?? 0);
    $margemContribuicao = $receitaLiquida - $custos;
    $operacionais = ($buckets['Rescisões Trabalhistas'] ?? 0) + ($buckets['Despesas Comerciais'] ?? 0) + ($buckets['Despesas Gerais'] ?? 0) + ($buckets['Despesas Fixas'] ?? 0) + ($buckets['Outras Despesas'] ?? 0);
    $lucroOperacional = $margemContribuicao - $operacionais;
    $financeiras = $buckets['Despesas Financeiras'] ?? 0;
    $resultadoFinal = $lucroOperacional - $financeiras;

    $line = static function (string $label, string $nature, float $value, int $level = 0, string $bucket = '') use ($bucketDetails): array {
        return [
            'label' => $label,
            'nature' => $nature,
            'value' => $value,
            'level' => $level,
            'details' => $bucket !== '' ? ($bucketDetails[$bucket] ?? []) : [],
        ];
    };

    return [
        $line('Receita Bruta', 'total', $summary['receitas']),
        $line('Receita de Serviços', 'receita', $summary['receitas'], 1),
        $line('Deduções', 'title', -$deducoes),
        $line('Impostos sobre vendas', 'despesa', -($buckets['Impostos sobre vendas'] ?? 0), 1, 'Impostos sobre vendas'),
        $line('Taxa de cobrança', 'despesa', -($buckets['Taxa de cobrança'] ?? 0), 1, 'Taxa de cobrança'),
        $line('Comissões sobre vendas', 'despesa', -($buckets['Comissões sobre vendas'] ?? 0), 1, 'Comissões sobre vendas'),
        $line('Devolução de vendas', 'despesa', -($buckets['Devolução de vendas'] ?? 0), 1, 'Devolução de vendas'),
        $line('Receita Líquida', 'total', $receitaLiquida),
        $line('Custos Operacionais', 'title', -$custos),
        $line('Agua e Luz', 'despesa', -($buckets['Agua e Luz'] ?? 0), 1, 'Agua e Luz'),
        $line('Custo de Materiais', 'despesa', -($buckets['Custo de Materiais'] ?? 0), 1, 'Custo de Materiais'),
        $line('Freelancer', 'despesa', -($buckets['Freelancer'] ?? 0), 1, 'Freelancer'),
        $line('Transporte e Logística', 'despesa', -($buckets['Transporte e Logística'] ?? 0), 1, 'Transporte e Logística'),
        $line('Margem de Contribuição', 'total', $margemContribuicao),
        $line('Despesas Operacionais', 'title', -$operacionais),
        $line('Rescisões Trabalhistas', 'despesa', -($buckets['Rescisões Trabalhistas'] ?? 0), 1, 'Rescisões Trabalhistas'),
        $line('Despesas Comerciais', 'despesa', -($buckets['Despesas Comerciais'] ?? 0), 1, 'Despesas Comerciais'),
        $line('Despesas Gerais', 'despesa', -($buckets['Despesas Gerais'] ?? 0), 1, 'Despesas Gerais'),
        $line('Despesas Fixas', 'despesa', -($buckets['Despesas Fixas'] ?? 0), 1, 'Despesas Fixas'),
        $line('Outras Despesas', 'despesa', -($buckets['Outras Despesas'] ?? 0), 1, 'Outras Despesas'),
        $line('Lucro Operacional', 'total', $lucroOperacional),
        $line('Despesas Financeiras', 'despesa', -$financeiras, 1, 'Despesas Financeiras'),
        $line('Resultado do Exercício', 'result', $resultadoFinal),
    ];
}

$competenciaParam = fa_request_param('competencia') ?? date('Y-m');
$month = fa_parse_month($competenciaParam);
$dateBase = fa_request_param('data_base') ?? 'pagamento';
$dateBase = in_array($dateBase, ['pagamento', 'vencimento'], true) ? $dateBase : 'pagamento';
$status = fa_request_param('status') ?? 'todos';

$start = $month->format('Y-m-01');
$end = $month->modify('+1 month')->format('Y-m-01');
$prev = $month->modify('-1 month');
$prevStart = $prev->format('Y-m-01');
$prevEnd = $month->format('Y-m-01');
$next = $month->modify('+1 month');

$summary = fa_summary($pdo, $start, $end, $dateBase, $status);
$previous = fa_summary($pdo, $prevStart, $prevEnd, $dateBase, $status);
$topReceitas = fa_top_receitas($pdo, $start, $end, $dateBase, $status);
$topDespesas = fa_top_despesas($pdo, $start, $end, $status);
$despesasCategorias = fa_despesas_por_categoria($pdo, $start, $end, $status);
$dre = fa_build_dre($summary, $despesasCategorias);

$deltaReceitas = fa_delta($summary['receitas'], $previous['receitas']);
$deltaDespesas = fa_delta($summary['despesas'], $previous['despesas']);
$deltaResultado = fa_delta($summary['resultado'], $previous['resultado']);
$deltaMargem = $summary['margem'] - $previous['margem'];
$maxChart = max(1, $summary['receitas'], $summary['despesas'], abs($summary['resultado']), $previous['receitas'], $previous['despesas'], abs($previous['resultado']));

$insights = [];
if ($summary['resultado'] < 0) {
    $insights[] = 'Resultado negativo no mês: as despesas superaram as receitas em ' . format_currency(abs($summary['resultado'])) . '.';
} else {
    $insights[] = 'Resultado positivo no mês: sobra operacional de ' . format_currency($summary['resultado']) . '.';
}
if ($summary['despesas'] > $previous['despesas']) {
    $insights[] = 'As despesas subiram ' . $deltaDespesas['label'] . ' contra o mês anterior.';
} else {
    $insights[] = 'As despesas reduziram ' . str_replace('-', '', $deltaDespesas['label']) . ' contra o mês anterior.';
}
if (!empty($topDespesas[0]) && $summary['despesas'] > 0) {
    $share = ((float)$topDespesas[0]['valor'] / $summary['despesas']) * 100;
    $insights[] = $topDespesas[0]['categoria'] . ' concentra ' . number_format($share, 1, ',', '.') . '% das despesas do mês.';
}
if ($summary['a_pagar'] > 0) {
    $insights[] = 'Existem ' . format_currency($summary['a_pagar']) . ' em despesas ainda pendentes no mês.';
}

includeSidebar('Análise Financeira');
?>

<style>
.fa-page{max-width:1440px;margin:0 auto;padding:1.5rem;color:#334155}.fa-top{display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap}.fa-title{margin:0;color:#1e3a8a;font-size:1.85rem;font-weight:900}.fa-sub{margin:.3rem 0 0;color:#64748b}.fa-actions{display:flex;gap:.65rem;flex-wrap:wrap}.fa-btn{border:0;border-radius:8px;padding:.72rem 1rem;font-weight:900;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;background:#e2e8f0;color:#334155}.fa-btn.primary{background:#1e3a8a;color:#fff}.fa-btn.green{background:#20c985;color:#fff}.fa-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 14px 34px rgba(15,23,42,.07)}.fa-filters{margin:1rem 0;padding:1rem;display:grid;grid-template-columns:repeat(4,minmax(0,1fr)) auto;gap:.8rem;align-items:end}.fa-field{display:grid;gap:.35rem}.fa-field label{font-weight:900;color:#475569;font-size:.82rem}.fa-field select,.fa-field input{border:1px solid #cbd5e1;border-radius:8px;padding:.65rem .75rem;font:inherit;background:#fff}.fa-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:1rem}.fa-kpi{padding:1.1rem;border-left:4px solid #38bdf8}.fa-kpi.receita{border-color:#22c55e}.fa-kpi.despesa{border-color:#ef4444}.fa-kpi.resultado{border-color:#3b82f6}.fa-kpi.margem{border-color:#a855f7}.fa-kpi h3{margin:0 0 .8rem;text-transform:uppercase;color:#64748b;font-size:.82rem}.fa-kpi .value{font-size:1.55rem;font-weight:900;color:#1f2937}.fa-kpi .meta{margin-top:.35rem;color:#64748b;font-size:.84rem}.fa-delta{font-weight:900}.fa-delta.good{color:#16a34a}.fa-delta.bad{color:#dc2626}.fa-month{display:flex;align-items:center;justify-content:center;gap:.7rem;margin:1.2rem 0}.fa-month a{width:36px;height:36px;border:1px solid #cbd5e1;border-radius:7px;background:#fff;color:#334155;text-decoration:none;display:grid;place-items:center;font-weight:900}.fa-month-title{font-weight:900;font-size:1.35rem;color:#475569}.fa-grid{display:grid;grid-template-columns:1.25fr .9fr;gap:1rem;margin-top:1rem}.fa-section{padding:1rem}.fa-section h2{margin:0 0 1rem;color:#1e3a8a;font-size:1.08rem}.fa-chart{display:grid;gap:.8rem}.fa-chart-row{display:grid;grid-template-columns:95px 1fr 110px;gap:.8rem;align-items:center}.fa-bar-track{height:28px;background:#eef2f7;border-radius:6px;overflow:hidden}.fa-bar{height:100%;min-width:2px}.fa-bar.receita{background:#22c55e}.fa-bar.despesa{background:#ef4444}.fa-bar.resultado{background:#3b82f6}.fa-table-wrap{overflow:auto}.fa-table{width:100%;border-collapse:collapse;min-width:620px}.fa-table th,.fa-table td{padding:.75rem;border-bottom:1px solid #e2e8f0;text-align:left;vertical-align:middle}.fa-table th{background:#f8fafc;color:#475569;text-transform:uppercase;font-size:.75rem}.fa-money{font-weight:900}.fa-money.out{color:#b42318}.fa-money.in{color:#15803d}.fa-dre-list{display:grid;gap:.55rem}.fa-dre-item{border:1px solid #e2e8f0;border-radius:8px;background:#fff;overflow:hidden}.fa-dre-item summary,.fa-dre-static{list-style:none;display:grid;grid-template-columns:1fr 160px 110px;gap:1rem;align-items:center;padding:.85rem 1rem}.fa-dre-item summary{cursor:pointer}.fa-dre-item summary::-webkit-details-marker{display:none}.fa-dre-item.expandable summary:before{content:'▸';font-weight:900;color:#64748b;margin-right:.5rem}.fa-dre-item.expandable[open] summary:before{content:'▾'}.fa-dre-item.total summary,.fa-dre-item.total .fa-dre-static,.fa-dre-item.result summary,.fa-dre-item.result .fa-dre-static{background:#eef6ff;font-weight:900}.fa-dre-item.title summary,.fa-dre-item.title .fa-dre-static{background:#f8fafc;font-weight:900;color:#475569}.fa-dre-label{display:flex;align-items:center;gap:.35rem;font-weight:900}.fa-dre-label.indent{padding-left:1.1rem;font-weight:800}.fa-dre-details{padding:.25rem 1rem 1rem 2.3rem;background:#fff}.fa-dre-detail-table{width:100%;border-collapse:collapse}.fa-dre-detail-table th,.fa-dre-detail-table td{padding:.55rem;border-bottom:1px solid #e2e8f0;text-align:left}.fa-dre-detail-table th{font-size:.72rem;color:#64748b;text-transform:uppercase}.fa-insights{display:grid;gap:.65rem}.fa-insight{border-left:4px solid #38bdf8;background:#f8fbff;border-radius:8px;padding:.75rem .9rem;font-weight:800;color:#334155}.fa-two{display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-top:1rem}.fa-muted{color:#64748b;font-size:.86rem}@media(max-width:1050px){.fa-summary,.fa-grid,.fa-two{grid-template-columns:1fr}.fa-filters{grid-template-columns:1fr 1fr}}@media(max-width:650px){.fa-page{padding:1rem}.fa-filters{grid-template-columns:1fr}.fa-chart-row{grid-template-columns:1fr}.fa-summary{grid-template-columns:1fr}.fa-dre-item summary,.fa-dre-static{grid-template-columns:1fr}.fa-dre-details{padding:.25rem .8rem .9rem}}
.fa-dre-item summary,.fa-dre-static{grid-template-columns:24px minmax(0,1fr) 160px 110px}.fa-dre-item.expandable summary:before,.fa-dre-item.expandable[open] summary:before{content:none}.fa-dre-arrow{width:18px;height:18px;display:inline-grid;place-items:center;color:#64748b;font-weight:900;transition:transform .15s ease}.fa-dre-item[open] .fa-dre-arrow{transform:rotate(90deg)}.fa-dre-static .fa-dre-arrow{visibility:hidden}.fa-dre-item summary .fa-money,.fa-dre-static .fa-money{text-align:right}.fa-dre-item summary span:last-child,.fa-dre-static span:last-child{text-align:right}@media(max-width:650px){.fa-dre-item summary,.fa-dre-static{grid-template-columns:24px 1fr;gap:.45rem .75rem}.fa-dre-item summary .fa-money,.fa-dre-static .fa-money,.fa-dre-item summary span:last-child,.fa-dre-static span:last-child{text-align:left;grid-column:2}}
</style>

<div class="fa-page">
    <div class="fa-top">
        <div>
            <h1 class="fa-title">Análise Financeira</h1>
            <p class="fa-sub">Performance mensal, comparativo e DRE calculado pelas categorias financeiras.</p>
        </div>
        <div class="fa-actions">
            <a class="fa-btn" href="index.php?page=financeiro&competencia=<?= h($month->format('Y-m')) ?>">Financeiro</a>
            <a class="fa-btn" href="index.php?page=financeiro_cartoes">Cartão de crédito</a>
        </div>
    </div>

    <form class="fa-card fa-filters" method="get">
        <input type="hidden" name="page" value="financeiro_analise">
        <div class="fa-field">
            <label>Mês</label>
            <input type="month" name="competencia" value="<?= h($month->format('Y-m')) ?>">
        </div>
        <div class="fa-field">
            <label>Mostrar receitas por data de</label>
            <select name="data_base">
                <option value="pagamento" <?= $dateBase === 'pagamento' ? 'selected' : '' ?>>Pagamento</option>
                <option value="vencimento" <?= $dateBase === 'vencimento' ? 'selected' : '' ?>>Vencimento</option>
            </select>
        </div>
        <div class="fa-field">
            <label>Status</label>
            <select name="status">
                <option value="todos" <?= $status === 'todos' ? 'selected' : '' ?>>Todos</option>
                <option value="pago" <?= $status === 'pago' ? 'selected' : '' ?>>Pago</option>
            </select>
        </div>
        <div class="fa-field">
            <label>Competência</label>
            <input type="text" value="<?= h(fa_month_label($month)) ?>" disabled>
        </div>
        <button class="fa-btn primary" type="submit">Filtrar</button>
    </form>

    <div class="fa-summary">
        <div class="fa-card fa-kpi receita">
            <h3>Receitas <?= h($month->format('m/Y')) ?></h3>
            <div class="value"><?= h(format_currency($summary['receitas'])) ?></div>
            <div class="meta"><?= (int)$summary['receitas_qtd'] ?> lançamentos · <span class="fa-delta <?= $deltaReceitas['value'] >= 0 ? 'good' : 'bad' ?>"><?= h($deltaReceitas['label']) ?></span> vs mês anterior</div>
        </div>
        <div class="fa-card fa-kpi despesa">
            <h3>Despesas <?= h($month->format('m/Y')) ?></h3>
            <div class="value"><?= h(format_currency($summary['despesas'])) ?></div>
            <div class="meta"><?= (int)$summary['despesas_qtd'] ?> lançamentos · <span class="fa-delta <?= $deltaDespesas['value'] <= 0 ? 'good' : 'bad' ?>"><?= h($deltaDespesas['label']) ?></span> vs mês anterior</div>
        </div>
        <div class="fa-card fa-kpi resultado">
            <h3>Resultado</h3>
            <div class="value"><?= h(format_currency($summary['resultado'])) ?></div>
            <div class="meta"><?= $summary['resultado'] >= 0 ? 'Lucro' : 'Prejuízo' ?> · <span class="fa-delta <?= $deltaResultado['value'] >= 0 ? 'good' : 'bad' ?>"><?= h($deltaResultado['label']) ?></span></div>
        </div>
        <div class="fa-card fa-kpi margem">
            <h3>Margem</h3>
            <div class="value"><?= h(number_format($summary['margem'], 1, ',', '.')) ?>%</div>
            <div class="meta">Resultado ÷ receita · <span class="fa-delta <?= $deltaMargem >= 0 ? 'good' : 'bad' ?>"><?= h(($deltaMargem >= 0 ? '+' : '') . number_format($deltaMargem, 1, ',', '.')) ?>pp</span></div>
        </div>
    </div>

    <div class="fa-month">
        <a href="index.php?page=financeiro_analise&competencia=<?= h($prev->format('Y-m')) ?>&data_base=<?= h($dateBase) ?>&status=<?= h($status) ?>">‹</a>
        <span class="fa-month-title"><?= h(fa_month_label($month)) ?></span>
        <a href="index.php?page=financeiro_analise&competencia=<?= h($next->format('Y-m')) ?>&data_base=<?= h($dateBase) ?>&status=<?= h($status) ?>">›</a>
    </div>

    <div class="fa-grid">
        <section class="fa-card fa-section">
            <h2>Comparativo com <?= h(fa_month_label($prev)) ?></h2>
            <div class="fa-chart">
                <?php foreach ([['Receitas', $summary['receitas'], $previous['receitas'], 'receita'], ['Despesas', $summary['despesas'], $previous['despesas'], 'despesa'], ['Resultado', abs($summary['resultado']), abs($previous['resultado']), 'resultado']] as $row): ?>
                    <div class="fa-chart-row">
                        <strong><?= h($row[0]) ?></strong>
                        <div>
                            <div class="fa-bar-track"><div class="fa-bar <?= h($row[3]) ?>" style="width:<?= h((string)max(2, min(100, ($row[1] / $maxChart) * 100))) ?>%"></div></div>
                            <div class="fa-muted">Atual: <?= h(format_currency($row[1])) ?> · Anterior: <?= h(format_currency($row[2])) ?></div>
                        </div>
                        <span class="fa-money"><?= h(format_currency($row[1])) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="fa-card fa-section">
            <h2>Leitura inteligente</h2>
            <div class="fa-insights">
                <?php foreach ($insights as $insight): ?><div class="fa-insight"><?= h($insight) ?></div><?php endforeach; ?>
            </div>
        </section>
    </div>

    <div class="fa-two">
        <section class="fa-card fa-section">
            <h2>Maiores Receitas</h2>
            <div class="fa-table-wrap"><table class="fa-table"><thead><tr><th>#</th><th>Unidade</th><th>Lançamentos</th><th>Valor</th></tr></thead><tbody>
                <?php foreach ($topReceitas as $idx => $row): ?><tr><td><?= $idx + 1 ?></td><td><?= h((string)$row['categoria']) ?></td><td><?= (int)$row['qtd'] ?></td><td><span class="fa-money in"><?= h(format_currency($row['valor'])) ?></span></td></tr><?php endforeach; ?>
                <?php if (!$topReceitas): ?><tr><td colspan="4" class="fa-muted">Nenhuma receita no período.</td></tr><?php endif; ?>
            </tbody></table></div>
        </section>
        <section class="fa-card fa-section">
            <h2>Maiores Despesas</h2>
            <div class="fa-table-wrap"><table class="fa-table"><thead><tr><th>#</th><th>Categoria</th><th>Lançamentos</th><th>Valor</th></tr></thead><tbody>
                <?php foreach ($topDespesas as $idx => $row): ?><tr><td><?= $idx + 1 ?></td><td><?= h((string)$row['categoria']) ?></td><td><?= (int)$row['qtd'] ?></td><td><span class="fa-money out">-<?= h(format_currency($row['valor'])) ?></span></td></tr><?php endforeach; ?>
                <?php if (!$topDespesas): ?><tr><td colspan="4" class="fa-muted">Nenhuma despesa no período.</td></tr><?php endif; ?>
            </tbody></table></div>
        </section>
    </div>

    <section class="fa-card fa-section" style="margin-top:1rem">
        <h2>DRE por Categorias</h2>
        <div class="fa-dre-list">
            <?php foreach ($dre as $line): ?>
                <?php
                    $pct = $summary['receitas'] > 0 ? (((float)$line['value'] / $summary['receitas']) * 100) : 0;
                    $details = is_array($line['details'] ?? null) ? $line['details'] : [];
                    $hasDetails = !empty($details);
                    $tag = $hasDetails ? 'details' : 'div';
                    $class = 'fa-dre-item ' . (string)$line['nature'] . ($hasDetails ? ' expandable' : '');
                ?>
                <<?= $tag ?> class="<?= h($class) ?>">
                    <?php if ($hasDetails): ?><summary><?php else: ?><div class="fa-dre-static"><?php endif; ?>
                        <span class="fa-dre-arrow"><?= $hasDetails ? '▸' : '' ?></span>
                        <span class="fa-dre-label <?= (int)$line['level'] > 0 ? 'indent' : '' ?>"><?= h((string)$line['label']) ?></span>
                        <span class="fa-money <?= (float)$line['value'] < 0 ? 'out' : 'in' ?>"><?= h(format_currency($line['value'])) ?></span>
                        <span><?= h(number_format($pct, 1, ',', '.')) ?>%</span>
                    <?php if ($hasDetails): ?></summary><?php else: ?></div><?php endif; ?>
                    <?php if ($hasDetails): ?>
                        <div class="fa-dre-details">
                            <table class="fa-dre-detail-table">
                                <thead><tr><th>Categoria envolvida</th><th>Lançamentos</th><th>Valor</th></tr></thead>
                                <tbody>
                                    <?php foreach ($details as $detail): ?>
                                        <tr>
                                            <td><?= h((string)$detail['categoria']) ?></td>
                                            <td><?= (int)$detail['qtd'] ?></td>
                                            <td><span class="fa-money out">-<?= h(format_currency($detail['valor'])) ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </<?= $tag ?>>
            <?php endforeach; ?>
        </div>
    </section>
</div>

<script>
(() => {
    const params = new URLSearchParams(window.location.search);
    const competencia = params.get('competencia');
    if (!/^\d{4}-\d{2}$/.test(competencia || '')) {
        return;
    }

    const monthInput = document.querySelector('input[name="competencia"]');
    if (!monthInput || monthInput.value === competencia) {
        return;
    }

    monthInput.value = competencia;
    if (params.has('_fa_reload')) {
        return;
    }

    const url = new URL(window.location.href);
    url.searchParams.set('_fa_reload', String(Date.now()));
    window.location.replace(url.toString());
})();
</script>

<?php endSidebar(); ?>
