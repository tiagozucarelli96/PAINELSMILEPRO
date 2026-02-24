<?php
/**
 * comercial_realizar_degustacao.php — Relatório para realização de degustação
 */

// CRÍTICO: Verificar se é PDF ANTES de qualquer output
$is_pdf_request = (string)($_GET['pdf'] ?? $_POST['pdf'] ?? '') === '1';

// Se for PDF, garantir que não há output anterior
if ($is_pdf_request) {
    while (ob_get_level()) {
        ob_end_clean();
    }
}

if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/lc_permissions_enhanced.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/helpers.php';

// Parsear QUERY_STRING manualmente para garantir parâmetros
if (isset($_SERVER['QUERY_STRING']) && !empty($_SERVER['QUERY_STRING'])) {
    parse_str($_SERVER['QUERY_STRING'], $parsed_all);
    $_GET = array_merge($parsed_all, $_GET);
    $_REQUEST = array_merge($parsed_all, $_REQUEST);
}

// Tentar recuperar degustacao_id do REQUEST_URI se não estiver em $_GET
if (!isset($_GET['degustacao_id']) && isset($_SERVER['REQUEST_URI'])) {
    $request_uri = $_SERVER['REQUEST_URI'];
    if (preg_match('/degustacao_id[=:](\d+)/', $request_uri, $matches)) {
        $_GET['degustacao_id'] = $matches[1];
    } elseif (strpos($request_uri, 'degustacao_id=') !== false) {
        $parts = parse_url($request_uri);
        if (isset($parts['query'])) {
            parse_str($parts['query'], $uri_params);
            if (isset($uri_params['degustacao_id'])) {
                $_GET['degustacao_id'] = $uri_params['degustacao_id'];
            }
        }
    }
}

// Obter degustacao_id
$degustacao_id = 0;
if (isset($_GET['degustacao_id']) && $_GET['degustacao_id'] !== '') {
    $degustacao_id = (int)$_GET['degustacao_id'];
} elseif (isset($_REQUEST['degustacao_id']) && $_REQUEST['degustacao_id'] !== '') {
    $degustacao_id = (int)$_REQUEST['degustacao_id'];
} elseif (isset($_SERVER['QUERY_STRING'])) {
    parse_str($_SERVER['QUERY_STRING'], $q);
    if (isset($q['degustacao_id']) && $q['degustacao_id'] !== '') {
        $degustacao_id = (int)$q['degustacao_id'];
    }
}

$pdo = $GLOBALS['pdo'];
$degustacao = null;
$inscritos = [];
$mesas = [];
$resumo_mesas = [
    'total_inscricoes' => 0,
    'mesas_com_inscritos' => 0,
    'total_pessoas' => 0,
];
$degustacoes = [];
$error_message = '';
$layout_json = trim((string)($_POST['layout_json'] ?? $_GET['layout_json'] ?? ''));

function dr_normalizar_inscritos(array $inscritos): array
{
    $normalizados = [];
    foreach ($inscritos as $inscrito) {
        $id = (int)($inscrito['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }

        $qtd_pessoas = (int)($inscrito['qtd_pessoas'] ?? 1);
        if ($qtd_pessoas < 1) {
            $qtd_pessoas = 1;
        }

        $fechou_contrato = strtolower(trim((string)($inscrito['fechou_contrato'] ?? 'nao')));
        $inscrito['id'] = $id;
        $inscrito['qtd_pessoas'] = $qtd_pessoas;
        $inscrito['fechou_contrato'] = $fechou_contrato === 'sim' ? 'sim' : 'nao';
        $normalizados[] = $inscrito;
    }
    return $normalizados;
}

function dr_mesas_padrao(array $inscritos): array
{
    $mesas = [];
    foreach (dr_normalizar_inscritos($inscritos) as $inscrito) {
        $mesas[] = ['inscritos' => [$inscrito]];
    }
    return $mesas;
}

function dr_is_list(array $value): bool
{
    $expected = 0;
    foreach ($value as $key => $_) {
        if ($key !== $expected) {
            return false;
        }
        $expected++;
    }
    return true;
}

function dr_finalizar_mesas(array $mesas): array
{
    $final = [];
    $numero = 1;
    foreach ($mesas as $mesa) {
        $inscritos_mesa = [];
        if (isset($mesa['inscritos']) && is_array($mesa['inscritos'])) {
            $inscritos_mesa = dr_normalizar_inscritos($mesa['inscritos']);
        }
        $total_pessoas = array_sum(array_map(
            fn($i) => (int)($i['qtd_pessoas'] ?? 1),
            $inscritos_mesa
        ));
        $final[] = [
            'numero' => $numero++,
            'inscritos' => $inscritos_mesa,
            'total_inscricoes' => count($inscritos_mesa),
            'total_pessoas' => $total_pessoas,
        ];
    }
    return $final;
}

function dr_construir_mesas(array $inscritos, string $layout_json = ''): array
{
    $inscritos_norm = dr_normalizar_inscritos($inscritos);
    if (empty($inscritos_norm)) {
        return [];
    }

    if ($layout_json === '') {
        return dr_finalizar_mesas(dr_mesas_padrao($inscritos_norm));
    }

    $layout = json_decode($layout_json, true);
    if (!is_array($layout)) {
        return dr_finalizar_mesas(dr_mesas_padrao($inscritos_norm));
    }

    if (isset($layout['mesas']) && is_array($layout['mesas'])) {
        $layout = $layout['mesas'];
    }

    if (!dr_is_list($layout)) {
        return dr_finalizar_mesas(dr_mesas_padrao($inscritos_norm));
    }

    $inscritos_por_id = [];
    foreach ($inscritos_norm as $inscrito) {
        $inscritos_por_id[(int)$inscrito['id']] = $inscrito;
    }

    $utilizados = [];
    $mesas = [];

    foreach ($layout as $mesa_layout) {
        if (!is_array($mesa_layout)) {
            continue;
        }

        $ids = $mesa_layout['inscrito_ids'] ?? $mesa_layout['inscritos'] ?? [];
        if (!is_array($ids)) {
            continue;
        }

        $inscritos_mesa = [];
        foreach ($ids as $id_raw) {
            $id = (int)$id_raw;
            if ($id <= 0 || isset($utilizados[$id]) || !isset($inscritos_por_id[$id])) {
                continue;
            }
            $inscritos_mesa[] = $inscritos_por_id[$id];
            $utilizados[$id] = true;
        }
        $mesas[] = ['inscritos' => $inscritos_mesa];
    }

    // Garantir que nenhum inscrito seja perdido por layout inválido/incompleto
    foreach ($inscritos_norm as $inscrito) {
        $id = (int)$inscrito['id'];
        if (!isset($utilizados[$id])) {
            $mesas[] = ['inscritos' => [$inscrito]];
        }
    }

    if (empty($mesas)) {
        $mesas = dr_mesas_padrao($inscritos_norm);
    }

    return dr_finalizar_mesas($mesas);
}

function dr_resumo_mesas(array $mesas): array
{
    $total_inscricoes = 0;
    $total_pessoas = 0;
    $mesas_com_inscritos = 0;

    foreach ($mesas as $mesa) {
        $qtd_inscricoes = (int)($mesa['total_inscricoes'] ?? 0);
        $qtd_pessoas = (int)($mesa['total_pessoas'] ?? 0);
        if ($qtd_inscricoes > 0) {
            $mesas_com_inscritos++;
        }
        $total_inscricoes += $qtd_inscricoes;
        $total_pessoas += $qtd_pessoas;
    }

    return [
        'total_inscricoes' => $total_inscricoes,
        'mesas_com_inscritos' => $mesas_com_inscritos,
        'total_pessoas' => $total_pessoas,
    ];
}

function dr_json_response(array $payload, int $status = 200): void
{
    while (ob_get_level()) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function dr_layout_table_ensure(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS comercial_degustacao_layout_mesas (
            id BIGSERIAL PRIMARY KEY,
            degustacao_id INTEGER NOT NULL UNIQUE,
            layout_json TEXT NOT NULL,
            criado_em TIMESTAMP NOT NULL DEFAULT NOW(),
            atualizado_em TIMESTAMP NOT NULL DEFAULT NOW()
        )
    ");
    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_comercial_degustacao_layout_mesas_degustacao
        ON comercial_degustacao_layout_mesas(degustacao_id)
    ");

    $ready = true;
}

function dr_validar_layout_json(string $layout_json): string
{
    $decoded = json_decode($layout_json, true);
    if (!is_array($decoded)) {
        throw new InvalidArgumentException('Layout inválido.');
    }

    $mesas = $decoded['mesas'] ?? $decoded;
    if (!is_array($mesas) || !dr_is_list($mesas)) {
        throw new InvalidArgumentException('Layout inválido.');
    }

    $layout_normalizado = ['mesas' => []];
    foreach ($mesas as $mesa) {
        if (!is_array($mesa)) {
            continue;
        }
        $ids = $mesa['inscrito_ids'] ?? $mesa['inscritos'] ?? [];
        if (!is_array($ids)) {
            $ids = [];
        }
        $ids_normalizados = [];
        foreach ($ids as $id_raw) {
            $id = (int)$id_raw;
            if ($id > 0) {
                $ids_normalizados[] = $id;
            }
        }
        $layout_normalizado['mesas'][] = ['inscrito_ids' => $ids_normalizados];
    }

    $json = json_encode($layout_normalizado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new InvalidArgumentException('Layout inválido.');
    }
    return $json;
}

function dr_buscar_layout_salvo(PDO $pdo, int $degustacao_id): string
{
    if ($degustacao_id <= 0) {
        return '';
    }

    dr_layout_table_ensure($pdo);
    $stmt = $pdo->prepare("
        SELECT layout_json
        FROM comercial_degustacao_layout_mesas
        WHERE degustacao_id = :degustacao_id
        LIMIT 1
    ");
    $stmt->execute([':degustacao_id' => $degustacao_id]);
    $layout = (string)($stmt->fetchColumn() ?: '');
    return trim($layout);
}

function dr_salvar_layout(PDO $pdo, int $degustacao_id, string $layout_json): string
{
    if ($degustacao_id <= 0) {
        throw new InvalidArgumentException('Degustação inválida.');
    }

    $layout_validado = dr_validar_layout_json($layout_json);
    dr_layout_table_ensure($pdo);

    $stmt = $pdo->prepare("
        INSERT INTO comercial_degustacao_layout_mesas (degustacao_id, layout_json, criado_em, atualizado_em)
        VALUES (:degustacao_id, :layout_json, NOW(), NOW())
        ON CONFLICT (degustacao_id)
        DO UPDATE SET layout_json = EXCLUDED.layout_json, atualizado_em = NOW()
    ");
    $stmt->execute([
        ':degustacao_id' => $degustacao_id,
        ':layout_json' => $layout_validado,
    ]);

    return $layout_validado;
}

$action = trim((string)($_POST['action'] ?? $_GET['action'] ?? ''));
if ($action === 'salvar_layout_mesas') {
    if ($degustacao_id <= 0) {
        dr_json_response([
            'success' => false,
            'message' => 'Degustação inválida.',
        ], 400);
    }

    try {
        $layout_recebido = trim((string)($_POST['layout_json'] ?? ''));
        if ($layout_recebido === '') {
            throw new InvalidArgumentException('Layout não enviado.');
        }

        dr_salvar_layout($pdo, $degustacao_id, $layout_recebido);

        dr_json_response([
            'success' => true,
            'message' => 'Layout salvo com sucesso.',
            'updated_at' => date('c'),
        ]);
    } catch (InvalidArgumentException $e) {
        dr_json_response([
            'success' => false,
            'message' => $e->getMessage(),
        ], 400);
    } catch (Throwable $e) {
        error_log('Erro ao salvar layout de mesas: ' . $e->getMessage());
        dr_json_response([
            'success' => false,
            'message' => 'Erro ao salvar layout.',
        ], 500);
    }
}

// Buscar lista de degustações
try {
    $degustacoes = $pdo->query("
        SELECT id, nome, data, hora_inicio, local, capacidade
        FROM comercial_degustacoes
        ORDER BY data DESC, hora_inicio DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error_message = "Erro ao buscar degustações: " . $e->getMessage();
}

// Buscar dados da degustação selecionada
if ($degustacao_id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM comercial_degustacoes WHERE id = :id");
        $stmt->execute([':id' => $degustacao_id]);
        $degustacao = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($degustacao) {
            // Verificar qual coluna usar para inscrições
            $check_col = $pdo->query("
                SELECT column_name 
                FROM information_schema.columns 
                WHERE table_name = 'comercial_inscricoes' 
                AND column_name IN ('degustacao_id', 'event_id')
                LIMIT 1
            ")->fetch(PDO::FETCH_ASSOC);
            
            $coluna_id = $check_col ? $check_col['column_name'] : 'degustacao_id';
            
            // Buscar inscritos confirmados (incluindo fechou_contrato para PDF)
            $stmt = $pdo->prepare("
                SELECT id, nome, qtd_pessoas, tipo_festa, 
                       COALESCE(fechou_contrato, 'nao') as fechou_contrato
                FROM comercial_inscricoes 
                WHERE {$coluna_id} = :deg_id AND status = 'confirmado' 
                ORDER BY nome ASC
            ");
            $stmt->execute([':deg_id' => $degustacao_id]);
            $inscritos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($layout_json === '') {
                $layout_json = dr_buscar_layout_salvo($pdo, $degustacao_id);
            }
            $mesas = dr_construir_mesas($inscritos, $layout_json);
            $resumo_mesas = dr_resumo_mesas($mesas);
        }
    } catch (Exception $e) {
        $error_message = "Erro ao buscar dados: " . $e->getMessage();
    }
}

// Detectar se está via router
$is_via_router = (isset($_GET['page']) || strpos($_SERVER['REQUEST_URI'] ?? '', 'index.php') !== false);

// CRÍTICO: Processar PDF ANTES de qualquer output HTML
if ($is_pdf_request && $degustacao_id > 0) {
    // SEMPRE buscar dados quando for PDF request
    try {
        error_log("📄 Iniciando geração de PDF para degustacao_id: $degustacao_id");
        
        $stmt = $pdo->prepare("SELECT * FROM comercial_degustacoes WHERE id = :id");
        $stmt->execute([':id' => $degustacao_id]);
        $degustacao = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($degustacao) {
            error_log("✅ Degustação encontrada: " . ($degustacao['nome'] ?? 'sem nome'));
            
            // Buscar inscritos
            $check_col = $pdo->query("
                SELECT column_name 
                FROM information_schema.columns 
                WHERE table_name = 'comercial_inscricoes' 
                AND column_name IN ('degustacao_id', 'event_id')
                LIMIT 1
            ")->fetch(PDO::FETCH_ASSOC);
            $coluna_id = $check_col ? $check_col['column_name'] : 'degustacao_id';
            
            $stmt = $pdo->prepare("
                SELECT id, nome, qtd_pessoas, tipo_festa, 
                       COALESCE(fechou_contrato, 'nao') as fechou_contrato
                FROM comercial_inscricoes 
                WHERE {$coluna_id} = :deg_id AND status = 'confirmado' 
                ORDER BY nome ASC
            ");
            $stmt->execute([':deg_id' => $degustacao_id]);
            $inscritos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($layout_json === '') {
                $layout_json = dr_buscar_layout_salvo($pdo, $degustacao_id);
            }
            $mesas = dr_construir_mesas($inscritos, $layout_json);
            $resumo_mesas = dr_resumo_mesas($mesas);
            error_log("✅ Inscritos encontrados: " . count($inscritos));
        } else {
            error_log("⚠️ Degustação não encontrada para ID: $degustacao_id");
        }
    } catch (Exception $e) {
        error_log("❌ Erro ao buscar dados para PDF: " . $e->getMessage());
    }
    
    if ($degustacao && !empty($inscritos)) {
        // Tentar vários caminhos possíveis para o autoload
        $autoload_paths = [
            __DIR__ . '/vendor/autoload.php',
            __DIR__ . '/../vendor/autoload.php',
            __DIR__ . '/../../vendor/autoload.php',
        ];
        
        $autoload = null;
        foreach ($autoload_paths as $path) {
            if (file_exists($path)) {
                $autoload = $path;
                error_log("✅ Autoload encontrado em: $path");
                break;
            }
        }
        
        if ($autoload) {
            require_once $autoload;
            try {
                // Verificar se Dompdf está disponível após carregar autoload
                $dompdf_available = false;
                if (class_exists('\\Dompdf\\Dompdf')) {
                    $dompdf_available = true;
                    error_log("✅ Dompdf disponível via namespace, iniciando geração...");
                } elseif (class_exists('Dompdf')) {
                    $dompdf_available = true;
                    error_log("✅ Dompdf disponível sem namespace, iniciando geração...");
                } elseif (file_exists(__DIR__ . '/../vendor/dompdf/dompdf/src/Dompdf.php')) {
                    require_once __DIR__ . '/../vendor/dompdf/dompdf/src/Dompdf.php';
                    if (class_exists('\\Dompdf\\Dompdf')) {
                        $dompdf_available = true;
                        error_log("✅ Dompdf carregado diretamente, iniciando geração...");
                    }
                }
                
                if ($dompdf_available) {
                    // Limpar qualquer output anterior
                    while (ob_get_level()) {
                        ob_end_clean();
                    }
                    
                    ob_start();
                    
                    // Verificar qual logo usar
                    $logo_path = __DIR__ . '/logo-smile.png';
                    if (!file_exists($logo_path)) {
                        $logo_path = __DIR__ . '/logo.png';
                    }
                    
                    // Converter logo para base64 para incluir no PDF
                    $logo_base64 = '';
                    if (file_exists($logo_path)) {
                        try {
                            $logo_data = file_get_contents($logo_path);
                            $logo_info = getimagesizefromstring($logo_data);
                            
                            // Verificar se é uma imagem válida
                            if ($logo_info && isset($logo_info['mime'])) {
                                $mime_type = $logo_info['mime'];
                                // Apenas PNG e JPEG são suportados pelo Dompdf
                                if (in_array($mime_type, ['image/png', 'image/jpeg', 'image/jpg'])) {
                                    $logo_base64 = 'data:' . $mime_type . ';base64,' . base64_encode($logo_data);
                                    error_log("✅ Logo carregado: $logo_path ($mime_type, " . strlen($logo_data) . " bytes)");
                                } else {
                                    error_log("⚠️ Logo em formato não suportado: $mime_type");
                                }
                            } else {
                                error_log("⚠️ Logo inválido ou corrompido: $logo_path");
                            }
                        } catch (Exception $e) {
                            error_log("❌ Erro ao carregar logo: " . $e->getMessage());
                        }
                    } else {
                        error_log("⚠️ Logo não encontrado em: $logo_path");
                    }
                    
                    $mesas_pdf = array_values(array_filter($mesas, fn($mesa) => ((int)($mesa['total_inscricoes'] ?? 0)) > 0));
                    if (empty($mesas_pdf) && !empty($inscritos)) {
                        $mesas_pdf = dr_finalizar_mesas(dr_mesas_padrao($inscritos));
                    }
                    $total_mesas = count($mesas_pdf);
                    $total_inscricoes_pdf = array_sum(array_map(fn($mesa) => (int)($mesa['total_inscricoes'] ?? 0), $mesas_pdf));
                    $total_pessoas = array_sum(array_map(fn($mesa) => (int)($mesa['total_pessoas'] ?? 0), $mesas_pdf));
                    ?>
                <!DOCTYPE html>
                <html lang="pt-BR">
                <head>
                    <meta charset="UTF-8">
                    <style>
                        * { box-sizing: border-box; margin: 0; padding: 0; }
                        body { 
                            font-family: Arial, sans-serif; 
                            padding: 1.5cm; 
                            color: #1e293b; 
                            font-size: 11pt;
                        }
                        .header {
                            margin-bottom: 1.5cm;
                            padding-bottom: 1rem;
                            border-bottom: 2px solid #3b82f6;
                            overflow: hidden;
                        }
                        .logo {
                            max-width: 120px;
                            max-height: 80px;
                            height: auto;
                            width: auto;
                            float: left;
                            margin-right: 1.5rem;
                            margin-bottom: 0.5rem;
                            object-fit: contain;
                        }
                        .header-info {
                            overflow: hidden;
                        }
                        .degustacao-nome {
                            font-size: 1.4rem;
                            font-weight: 700;
                            color: #1e293b;
                            margin-bottom: 0.5rem;
                        }
                        .degustacao-meta {
                            font-size: 0.95rem;
                            color: #6b7280;
                        }
                        .degustacao-meta span {
                            margin-right: 1.5rem;
                        }
                        table {
                            width: 100%;
                            border-collapse: collapse;
                            margin-top: 1cm;
                            margin-bottom: 1.5cm;
                        }
                        thead {
                            background: #3b82f6;
                            color: white;
                        }
                        th {
                            padding: 0.75rem;
                            text-align: left;
                            font-weight: 600;
                            font-size: 0.9rem;
                            border: 1px solid #2563eb;
                        }
                        th:nth-child(2),
                        th:nth-child(4) {
                            text-align: center;
                            width: 90px;
                        }
                        tbody tr {
                            border-bottom: 1px solid #e5e7eb;
                        }
                        td {
                            padding: 0.75rem;
                            font-size: 0.9rem;
                        }
                        td:nth-child(2),
                        td:nth-child(4) {
                            text-align: center;
                        }
                        .mesa-group {
                            background: #eff6ff;
                            color: #1e3a8a;
                            font-weight: 700;
                        }
                        .mesa-group td {
                            border-top: 1px solid #bfdbfe;
                            border-bottom: 1px solid #bfdbfe;
                            padding: 0.6rem 0.75rem;
                        }
                        .fechou-sim {
                            color: #10b981;
                            font-weight: 600;
                        }
                        .fechou-nao {
                            color: #ef4444;
                        }
                        .tfooter {
                            background: #f1f5f9;
                            font-weight: 700;
                            border-top: 2px solid #3b82f6;
                        }
                        .tfooter td {
                            padding: 1rem 0.75rem;
                            font-size: 1rem;
                        }
                        .tfooter td:first-child {
                            text-align: right;
                            padding-right: 1rem;
                        }
                        @page { 
                            margin: 1.5cm; 
                            size: A4;
                        }
                    </style>
                </head>
                <body>
                    <div class="header">
                        <?php if ($logo_base64): ?>
                            <img src="<?= $logo_base64 ?>" alt="Logo" class="logo">
                        <?php endif; ?>
                        <div class="header-info">
                            <div class="degustacao-nome"><?= h($degustacao['nome']) ?></div>
                            <div class="degustacao-meta">
                                <span>Data: <?= date('d/m/Y', strtotime($degustacao['data'])) ?></span>
                                <span>Horário: <?= date('H:i', strtotime($degustacao['hora_inicio'])) ?></span>
                                <?php if (!empty($degustacao['local'])): ?>
                                    <span><?= h($degustacao['local']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <table>
                        <thead>
                            <tr>
                                <th>Nome do Inscrito</th>
                                <th>Pessoas</th>
                                <th>Tipo de Evento</th>
                                <th>Fechou Contrato</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($mesas_pdf as $mesa): ?>
                                <tr class="mesa-group">
                                    <td colspan="4">
                                        Mesa <?= (int)$mesa['numero'] ?> • <?= (int)$mesa['total_inscricoes'] ?> inscriç<?= (int)$mesa['total_inscricoes'] === 1 ? 'ão' : 'ões' ?> • <?= (int)$mesa['total_pessoas'] ?> pessoa<?= (int)$mesa['total_pessoas'] === 1 ? '' : 's' ?>
                                    </td>
                                </tr>
                                <?php foreach (($mesa['inscritos'] ?? []) as $inscrito): ?>
                                    <?php
                                    $qtdPessoas = (int)($inscrito['qtd_pessoas'] ?? 1);
                                    $fechou = strtolower((string)($inscrito['fechou_contrato'] ?? 'nao')) === 'sim' ? 'sim' : 'nao';
                                    ?>
                                    <tr>
                                        <td><?= h($inscrito['nome']) ?></td>
                                        <td><?= $qtdPessoas ?></td>
                                        <td><?= !empty($inscrito['tipo_festa']) ? h(ucfirst((string)$inscrito['tipo_festa'])) : '-' ?></td>
                                        <td>
                                            <span class="<?= $fechou === 'sim' ? 'fechou-sim' : 'fechou-nao' ?>">
                                                <?= $fechou === 'sim' ? 'Sim' : 'Não' ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="tfooter">
                                <td colspan="2"><strong>Total de Mesas:</strong></td>
                                <td colspan="2"><strong><?= $total_mesas ?></strong></td>
                            </tr>
                            <tr class="tfooter">
                                <td colspan="2"><strong>Total de Inscrições:</strong></td>
                                <td colspan="2"><strong><?= $total_inscricoes_pdf ?></strong></td>
                            </tr>
                            <tr class="tfooter">
                                <td colspan="2"><strong>Total de Pessoas:</strong></td>
                                <td colspan="2"><strong><?= $total_pessoas ?></strong></td>
                            </tr>
                        </tfoot>
                    </table>
                </body>
                    </html>
                    <?php
                    $html = ob_get_clean();
                    
                    $dompdf = new \Dompdf\Dompdf([
                        'isRemoteEnabled' => true,
                        'defaultPaperSize' => 'a4',
                        'isHtml5ParserEnabled' => true
                    ]);
                    $dompdf->loadHtml($html, 'UTF-8');
                    $dompdf->setPaper('A4', 'portrait');
                    $dompdf->render();
                    $fname = 'Relatorio_Degustacao_' . $degustacao_id . '_' . date('Y-m-d') . '.pdf';
                    
                    // Garantir headers corretos antes de enviar PDF
                    header('Content-Type: application/pdf');
                    header('Content-Disposition: attachment; filename="' . $fname . '"');
                    
                    $dompdf->stream($fname, ['Attachment' => true]);
                    exit;
                } else {
                    error_log("⚠️ Dompdf não disponível - classe não encontrada");
                    error_log("   Verificando instalação...");
                    
                    // Verificar se o pacote está instalado mas não carregado
                    $dompdf_check_paths = [
                        __DIR__ . '/../vendor/dompdf/dompdf',
                        dirname(__DIR__) . '/vendor/dompdf/dompdf'
                    ];
                    
                    $dompdf_package_exists = false;
                    foreach ($dompdf_check_paths as $dp_check) {
                        if (is_dir($dp_check)) {
                            $dompdf_package_exists = true;
                            error_log("   ✅ Pacote dompdf encontrado em: $dp_check");
                            error_log("   ⚠️ Mas classe não pode ser carregada. Verifique dependências.");
                            break;
                        }
                    }
                    
                    if (!$dompdf_package_exists) {
                        error_log("   ❌ Pacote dompdf não encontrado. Execute: composer require dompdf/dompdf");
                    }
                    
                    while (ob_get_level()) {
                        ob_end_clean();
                    }
                    header('Content-Type: text/html; charset=utf-8');
                    echo "<html><body>";
                    echo "<h1>Erro: Dompdf não instalado</h1>";
                    echo "<p>A biblioteca Dompdf não está disponível.</p>";
                    if ($dompdf_package_exists) {
                        echo "<p><strong>Pacote encontrado mas não pode ser carregado.</strong></p>";
                        echo "<p>Verifique se todas as dependências estão instaladas:</p>";
                        echo "<p><code>composer install</code></p>";
                    } else {
                        echo "<p>Execute: <code>composer require dompdf/dompdf</code></p>";
                    }
                    echo "<p><a href='javascript:history.back()'>Voltar</a></p>";
                    echo "</body></html>";
                    exit;
                }
            } catch (Throwable $e) {
                error_log("❌ Erro ao gerar PDF: " . $e->getMessage());
                error_log("Stack trace: " . $e->getTraceAsString());
                while (ob_get_level()) {
                    ob_end_clean();
                }
                header('Content-Type: text/html; charset=utf-8');
                echo "<html><body>";
                echo "<h1>Erro ao gerar PDF</h1>";
                echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
                echo "<p><a href='javascript:history.back()'>Voltar</a></p>";
                echo "</body></html>";
                exit;
            }
        } else {
            error_log("❌ Autoload não encontrado em nenhum caminho");
            while (ob_get_level()) {
                ob_end_clean();
            }
            header('Content-Type: text/html; charset=utf-8');
            echo "<html><body>";
            echo "<h1>Erro: Composer não instalado</h1>";
            echo "<p>O arquivo vendor/autoload.php não foi encontrado.</p>";
            echo "<p>Execute: <code>composer install</code> na raiz do projeto</p>";
            echo "<p><a href='javascript:history.back()'>Voltar</a></p>";
            echo "</body></html>";
            exit;
        }
    } else {
        error_log("⚠️ PDF solicitado mas dados não encontrados - degustacao_id: $degustacao_id, degustacao: " . (isset($degustacao) ? 'existe' : 'não existe') . ", inscritos: " . count($inscritos ?? []));
    }
    
    // Fallback: redirecionar para página normal sem pdf=1
    $redirect_url = preg_replace('/[&?]pdf=1(&|$)/', '', $_SERVER['REQUEST_URI']);
    if (strpos($redirect_url, '?') === false && strpos($redirect_url, '&') !== false) {
        $redirect_url = str_replace('&', '?', $redirect_url, 1);
    }
    header('Location: ' . $redirect_url);
    exit;
}

if ($degustacao_id > 0 && !empty($degustacao) && empty($mesas) && !empty($inscritos)) {
    $mesas = dr_construir_mesas($inscritos, $layout_json);
}
$resumo_mesas = dr_resumo_mesas($mesas);
$mesas_json = json_encode(
    $mesas,
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
if ($mesas_json === false) {
    $mesas_json = '[]';
}
?>

<?php includeSidebar('Realizar Degustação'); ?>

<style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f5f7fa;
            color: #1e293b;
            line-height: 1.6;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }

        .page-header {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
            padding: 2rem;
            border-radius: 12px;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .page-title {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .page-subtitle {
            font-size: 1rem;
            opacity: 0.9;
        }

        .selection-card {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            margin-bottom: 2rem;
        }

        .form-group {
            display: flex;
            gap: 1rem;
            align-items: flex-end;
        }

        .form-field {
            flex: 1;
        }

        label {
            display: block;
            font-weight: 600;
            color: #374151;
            margin-bottom: 0.5rem;
            font-size: 0.95rem;
        }

        select {
            width: 100%;
            padding: 0.75rem 1rem;
            font-size: 1rem;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            background: white;
            color: #1e293b;
            transition: all 0.2s;
        }

        select:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .btn {
            padding: 0.75rem 2rem;
            font-size: 1rem;
            font-weight: 600;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
            white-space: nowrap;
        }

        .btn-primary {
            background: #3b82f6;
            color: white;
        }

        .btn-primary:hover {
            background: #2563eb;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
        }

        .info-badge {
            margin-top: 1rem;
            padding: 1rem;
            background: #e0f2fe;
            border: 1px solid #bae6fd;
            border-radius: 8px;
            color: #0369a1;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            text-align: center;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: #3b82f6;
            margin-bottom: 0.25rem;
        }

        .stat-label {
            font-size: 0.875rem;
            color: #6b7280;
            font-weight: 500;
        }

        .relatorio-card {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            margin-bottom: 2rem;
        }

        .relatorio-header {
            border-bottom: 3px solid #3b82f6;
            padding-bottom: 1.5rem;
            margin-bottom: 2rem;
        }

        .relatorio-title {
            font-size: 1.75rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 1rem;
        }

        .relatorio-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1.5rem;
            color: #6b7280;
            font-size: 0.95rem;
        }

        .relatorio-meta-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .mesas-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .mesas-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }

        .toolbar-hint {
            color: #64748b;
            font-size: 0.9rem;
            font-weight: 500;
        }

        .layout-save-status {
            margin-top: 0.35rem;
            font-size: 0.82rem;
            font-weight: 600;
            color: #64748b;
        }

        .layout-save-status.is-saving {
            color: #1d4ed8;
        }

        .layout-save-status.is-saved {
            color: #166534;
        }

        .layout-save-status.is-error {
            color: #b91c1c;
        }

        .mesa-card {
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            padding: 1.25rem;
            transition: all 0.2s;
            min-height: 220px;
        }

        .mesa-card:hover {
            border-color: #3b82f6;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.15);
        }

        .mesa-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.75rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid #e2e8f0;
        }

        .mesa-numero {
            font-size: 1.125rem;
            font-weight: 700;
            color: #3b82f6;
        }

        .mesa-pessoas {
            font-size: 0.875rem;
            color: #6b7280;
            background: #e0f2fe;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-weight: 500;
        }

        .mesa-actions {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 0.6rem;
        }

        .btn-link-danger {
            border: none;
            background: transparent;
            color: #ef4444;
            font-size: 0.82rem;
            font-weight: 600;
            cursor: pointer;
            padding: 0.1rem 0.2rem;
        }

        .mesa-inscritos {
            min-height: 110px;
            border: 1px dashed #cbd5e1;
            border-radius: 8px;
            padding: 0.6rem;
            background: #fff;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            transition: border-color 0.2s, background 0.2s;
        }

        .mesa-inscritos.drag-over {
            border-color: #2563eb;
            background: #eff6ff;
        }

        .mesa-vazia {
            text-align: center;
            color: #94a3b8;
            font-size: 0.85rem;
            padding: 0.6rem;
        }

        .inscrito-card {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            background: #f8fafc;
            padding: 0.55rem 0.65rem;
            cursor: grab;
            user-select: none;
        }

        .inscrito-card:active {
            cursor: grabbing;
        }

        .inscrito-card.dragging {
            opacity: 0.45;
        }

        .inscrito-nome {
            font-size: 1rem;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }

        .inscrito-tipo {
            font-size: 0.875rem;
            color: #6b7280;
            background: #f1f5f9;
            padding: 0.25rem 0.5rem;
            border-radius: 6px;
            display: inline-block;
        }

        .status-contrato {
            font-size: 0.75rem;
            font-weight: 700;
            padding: 0.15rem 0.45rem;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            gap: 0.2rem;
            flex-shrink: 0;
        }

        .status-sim {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #86efac;
        }

        .status-nao {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }

        .actions-bar {
            display: flex;
            gap: 1rem;
            justify-content: center;
            padding: 1.5rem;
            background: #f8fafc;
            border-radius: 10px;
            margin-top: 2rem;
        }

        .btn-secondary {
            background: white;
            color: #3b82f6;
            border: 2px solid #3b82f6;
        }

        .btn-secondary:hover {
            background: #3b82f6;
            color: white;
        }

        .error-message {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 2rem;
        }

        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #6b7280;
        }

        .empty-state-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }

        /* Impressão */
        @media print {
            body {
                background: white;
                padding: 0;
            }

            .container {
                max-width: 100%;
                padding: 0;
            }

            .page-header {
                background: white !important;
                color: #1e293b !important;
                border-bottom: 3px solid #1e293b;
                padding: 1rem 0;
                margin-bottom: 1.5rem;
            }

            .selection-card,
            .actions-bar,
            .mesas-toolbar,
            .mesa-actions,
            .btn {
                display: none !important;
            }

            .relatorio-card {
                box-shadow: none;
                border: none;
                padding: 0;
            }

            .mesas-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 0.75rem;
            }

            .mesa-card {
                break-inside: avoid;
                page-break-inside: avoid;
                border: 1px solid #e2e8f0;
                box-shadow: none;
                min-height: auto;
            }

            .mesa-card:hover {
                transform: none;
                box-shadow: none;
            }

            .mesa-inscritos {
                border: none;
                background: transparent;
                padding: 0;
                min-height: auto;
            }

            .inscrito-card {
                border: none;
                background: transparent;
                padding: 0.15rem 0;
            }

            .mesa-vazia {
                display: none;
            }

            .stats-grid {
                grid-template-columns: repeat(4, 1fr);
                gap: 0.75rem;
                margin-bottom: 1rem;
            }

            @page {
                margin: 1.5cm;
                size: A4;
            }
        }
</style>

    <div class="container">
        <div class="page-header">
            <h1 class="page-title">🍽️ Realizar Degustação</h1>
            <p class="page-subtitle">Selecione uma degustação para gerar o relatório de mesas e inscritos</p>
        </div>

        <?php if ($error_message): ?>
            <div class="error-message">
                ❌ <strong>Erro:</strong> <?= h($error_message) ?>
            </div>
        <?php endif; ?>

        <div class="selection-card">
            <form method="GET" action="<?= $is_via_router ? 'index.php' : 'comercial_realizar_degustacao.php' ?>" id="formSelecaoDegustacao">
                <?php if ($is_via_router): ?>
                    <input type="hidden" name="page" value="comercial_realizar_degustacao">
                <?php endif; ?>
                <div class="form-group">
                    <div class="form-field">
                        <label for="selectDegustacao">Selecione a Degustação</label>
                        <select name="degustacao_id" id="selectDegustacao" required>
                            <option value="">-- Selecione uma degustação --</option>
                            <?php foreach ($degustacoes as $deg): ?>
                                <option value="<?= $deg['id'] ?>" <?= $degustacao_id == $deg['id'] ? 'selected' : '' ?>>
                                    <?= h($deg['nome']) ?> - <?= date('d/m/Y', strtotime($deg['data'])) ?> - <?= date('H:i', strtotime($deg['hora_inicio'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">📊 Gerar Relatório</button>
                </div>
            </form>

            <?php if ($degustacao_id > 0): ?>
                <div class="info-badge">
                    <strong>✅ Degustação selecionada (ID: <?= $degustacao_id ?>)</strong>
                    <?php if (!empty($degustacao)): ?>
                        <p style="margin: 0.5rem 0 0 0;"><?= h($degustacao['nome']) ?></p>
                    <?php else: ?>
                        <p style="margin: 0.5rem 0 0 0; color: #dc2626;">⚠️ Degustação não encontrada no banco de dados</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($degustacao_id > 0 && !empty($degustacao)): ?>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value" id="statTotalInscricoes"><?= (int)$resumo_mesas['total_inscricoes'] ?></div>
                    <div class="stat-label">Inscrições Confirmadas</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value" id="statTotalMesas"><?= (int)$resumo_mesas['mesas_com_inscritos'] ?></div>
                    <div class="stat-label">Total de Mesas</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value" id="statTotalPessoas"><?= (int)$resumo_mesas['total_pessoas'] ?></div>
                    <div class="stat-label">Total de Pessoas</div>
                </div>
            </div>

            <div class="relatorio-card">
                <div class="relatorio-header">
                    <h2 class="relatorio-title"><?= h($degustacao['nome']) ?></h2>
                    <div class="relatorio-meta">
                        <div class="relatorio-meta-item">
                            <span>Data:</span>
                            <span><?= date('d/m/Y', strtotime($degustacao['data'])) ?></span>
                        </div>
                        <div class="relatorio-meta-item">
                            <span>Horário:</span>
                            <span><?= date('H:i', strtotime($degustacao['hora_inicio'])) ?></span>
                        </div>
                        <?php if (!empty($degustacao['local'])): ?>
                            <div class="relatorio-meta-item">
                                <span>Local:</span>
                                <span><?= h($degustacao['local']) ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="mesas-toolbar no-print">
                    <div>
                        <div class="toolbar-hint">Arraste os inscritos entre as mesas para organizar o relatório.</div>
                        <div id="layoutSaveStatus" class="layout-save-status">Layout carregado.</div>
                    </div>
                    <button type="button" class="btn btn-primary" onclick="adicionarMesa()">➕ Nova Mesa</button>
                </div>

                <div class="mesas-grid" id="mesasGrid">
                    <?php if (empty($mesas)): ?>
                        <div class="empty-state" style="grid-column: 1 / -1;">
                            <div class="empty-state-icon">📋</div>
                            <p style="font-size: 1.125rem;">Nenhum inscrito confirmado encontrado para esta degustação.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($mesas as $mesa): ?>
                            <div class="mesa-card" data-mesa-card>
                                <div class="mesa-header">
                                    <span class="mesa-numero">Mesa <?= (int)$mesa['numero'] ?></span>
                                    <span class="mesa-pessoas"><?= (int)$mesa['total_pessoas'] ?> <?= (int)$mesa['total_pessoas'] === 1 ? 'pessoa' : 'pessoas' ?></span>
                                </div>
                                <div class="mesa-actions no-print">
                                    <button type="button" class="btn-link-danger" onclick="removerMesaVazia(this)">Remover mesa</button>
                                </div>
                                <div class="mesa-inscritos" data-dropzone>
                                    <?php if (empty($mesa['inscritos'])): ?>
                                        <div class="mesa-vazia">Arraste inscritos para esta mesa</div>
                                    <?php else: ?>
                                        <?php foreach (($mesa['inscritos'] ?? []) as $inscrito): ?>
                                            <?php
                                            $qtdPessoas = (int)($inscrito['qtd_pessoas'] ?? 1);
                                            $fechou = strtolower((string)($inscrito['fechou_contrato'] ?? 'nao')) === 'sim' ? 'sim' : 'nao';
                                            ?>
                                            <div class="inscrito-card"
                                                draggable="true"
                                                data-inscrito-id="<?= (int)$inscrito['id'] ?>"
                                                data-qtd-pessoas="<?= $qtdPessoas ?>">
                                                <div class="inscrito-nome">
                                                    <span class="status-contrato <?= $fechou === 'sim' ? 'status-sim' : 'status-nao' ?>">
                                                        <?= $fechou === 'sim' ? '✅ Fechou' : '❌ Não fechou' ?>
                                                    </span>
                                                    <span><?= h($inscrito['nome']) ?></span>
                                                </div>
                                                <?php if (!empty($inscrito['tipo_festa'])): ?>
                                                    <span class="inscrito-tipo"><?= h(ucfirst((string)$inscrito['tipo_festa'])) ?></span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="actions-bar no-print">
                    <button type="button" class="btn btn-secondary" onclick="gerarPDF()">
                        📄 Gerar PDF Agrupado por Mesa
                    </button>
                </div>

                <form method="POST"
                    action="<?= $is_via_router ? 'index.php?page=comercial_realizar_degustacao' : 'comercial_realizar_degustacao.php' ?>"
                    id="pdfLayoutForm"
                    target="_blank"
                    style="display: none;">
                    <input type="hidden" name="degustacao_id" value="<?= (int)$degustacao_id ?>">
                    <input type="hidden" name="pdf" value="1">
                    <input type="hidden" name="layout_json" id="layoutJsonInput" value="">
                </form>
            </div>
        <?php elseif ($degustacao_id > 0 && empty($degustacao)): ?>
            <div class="error-message">
                ⚠️ <strong>Atenção:</strong> Degustação selecionada (ID: <?= $degustacao_id ?>) mas dados não encontrados no banco de dados.
            </div>
        <?php else: ?>
            <div class="empty-state">
                <div class="empty-state-icon">📋</div>
                <p style="font-size: 1.125rem; margin-bottom: 0.5rem; font-weight: 600;">Instruções</p>
                <p>Selecione uma degustação no dropdown acima e clique em <strong>"📊 Gerar Relatório"</strong> para visualizar os dados.</p>
            </div>
        <?php endif; ?>
    </div>

    <script>
        const initialMesas = <?= $mesas_json ?>;
        const isViaRouter = <?= $is_via_router ? 'true' : 'false' ?>;
        const degustacaoId = <?= (int)$degustacao_id ?>;
        let draggedInscritoCard = null;
        let saveLayoutTimer = null;
        let ultimoLayoutSalvo = '';

        function endpointAtual() {
            return isViaRouter
                ? 'index.php?page=comercial_realizar_degustacao'
                : 'comercial_realizar_degustacao.php';
        }

        function atualizarStatusPersistencia(texto, classe = '') {
            const statusEl = document.getElementById('layoutSaveStatus');
            if (!statusEl) return;

            statusEl.textContent = texto;
            statusEl.classList.remove('is-saving', 'is-saved', 'is-error');
            if (classe) {
                statusEl.classList.add(classe);
            }
        }

        async function persistirLayoutNoBanco() {
            if (!degustacaoId) return;

            const layoutInput = document.getElementById('layoutJsonInput');
            const layoutAtual = (layoutInput?.value || '').trim();
            if (!layoutAtual) return;
            if (layoutAtual === ultimoLayoutSalvo) {
                atualizarStatusPersistencia('Layout salvo.', 'is-saved');
                return;
            }

            atualizarStatusPersistencia('Salvando layout...', 'is-saving');

            try {
                const body = new URLSearchParams();
                body.set('action', 'salvar_layout_mesas');
                body.set('degustacao_id', String(degustacaoId));
                body.set('layout_json', layoutAtual);

                const response = await fetch(endpointAtual(), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    body: body.toString(),
                });

                const data = await response.json();
                if (!response.ok || !data?.success) {
                    throw new Error(data?.message || 'Não foi possível salvar o layout.');
                }

                ultimoLayoutSalvo = layoutAtual;
                atualizarStatusPersistencia('Layout salvo.', 'is-saved');
            } catch (error) {
                atualizarStatusPersistencia('Erro ao salvar. Tentaremos novamente.', 'is-error');
            }
        }

        function agendarPersistenciaLayout() {
            if (!degustacaoId) return;
            if (saveLayoutTimer) {
                clearTimeout(saveLayoutTimer);
            }
            saveLayoutTimer = setTimeout(() => {
                persistirLayoutNoBanco();
            }, 500);
        }

        function attachInscritoDragEvents(card) {
            if (!card) return;
            card.addEventListener('dragstart', function () {
                draggedInscritoCard = card;
                card.classList.add('dragging');
            });
            card.addEventListener('dragend', function () {
                card.classList.remove('dragging');
                draggedInscritoCard = null;
                document.querySelectorAll('.mesa-inscritos').forEach((zone) => zone.classList.remove('drag-over'));
                atualizarLayoutMesas();
            });
        }

        function attachDropzoneEvents(zone) {
            if (!zone) return;
            zone.addEventListener('dragover', function (event) {
                event.preventDefault();
                zone.classList.add('drag-over');
            });
            zone.addEventListener('dragleave', function (event) {
                const related = event.relatedTarget;
                if (!related || !zone.contains(related)) {
                    zone.classList.remove('drag-over');
                }
            });
            zone.addEventListener('drop', function (event) {
                event.preventDefault();
                zone.classList.remove('drag-over');
                if (!draggedInscritoCard) return;
                zone.appendChild(draggedInscritoCard);
                atualizarLayoutMesas();
            });
        }

        function aplicarEventosDnD() {
            document.querySelectorAll('.inscrito-card').forEach(attachInscritoDragEvents);
            document.querySelectorAll('.mesa-inscritos').forEach(attachDropzoneEvents);
        }

        function garantirPlaceholderVazio(zone) {
            if (!zone) return;
            const hasInscrito = zone.querySelector('.inscrito-card');
            let placeholder = zone.querySelector('.mesa-vazia');
            if (!hasInscrito) {
                if (!placeholder) {
                    placeholder = document.createElement('div');
                    placeholder.className = 'mesa-vazia';
                    placeholder.textContent = 'Arraste inscritos para esta mesa';
                    zone.appendChild(placeholder);
                }
            } else if (placeholder) {
                placeholder.remove();
            }
        }

        function serializarLayout() {
            const mesas = [];
            document.querySelectorAll('#mesasGrid .mesa-card').forEach((mesaCard) => {
                const ids = Array.from(mesaCard.querySelectorAll('.inscrito-card')).map((card) => Number(card.dataset.inscritoId || 0)).filter((id) => id > 0);
                mesas.push({ inscrito_ids: ids });
            });
            return { mesas };
        }

        function atualizarLayoutMesas(persistir = true) {
            const mesaCards = document.querySelectorAll('#mesasGrid .mesa-card');
            let totalMesasComInscritos = 0;
            let totalInscricoes = 0;
            let totalPessoas = 0;

            mesaCards.forEach((mesaCard, index) => {
                const numeroEl = mesaCard.querySelector('.mesa-numero');
                const pessoasEl = mesaCard.querySelector('.mesa-pessoas');
                const zone = mesaCard.querySelector('.mesa-inscritos');
                if (!zone) return;

                const cards = Array.from(zone.querySelectorAll('.inscrito-card'));
                const qtdInscricoesMesa = cards.length;
                const qtdPessoasMesa = cards.reduce((acc, card) => acc + Number(card.dataset.qtdPessoas || 1), 0);

                if (numeroEl) {
                    numeroEl.textContent = `Mesa ${index + 1}`;
                }
                if (pessoasEl) {
                    pessoasEl.textContent = `${qtdPessoasMesa} ${qtdPessoasMesa === 1 ? 'pessoa' : 'pessoas'}`;
                }

                if (qtdInscricoesMesa > 0) {
                    totalMesasComInscritos += 1;
                }
                totalInscricoes += qtdInscricoesMesa;
                totalPessoas += qtdPessoasMesa;

                garantirPlaceholderVazio(zone);
            });

            const statMesas = document.getElementById('statTotalMesas');
            const statInscricoes = document.getElementById('statTotalInscricoes');
            const statPessoas = document.getElementById('statTotalPessoas');
            if (statMesas) statMesas.textContent = String(totalMesasComInscritos);
            if (statInscricoes) statInscricoes.textContent = String(totalInscricoes);
            if (statPessoas) statPessoas.textContent = String(totalPessoas);

            const layoutInput = document.getElementById('layoutJsonInput');
            if (layoutInput) {
                layoutInput.value = JSON.stringify(serializarLayout());
            }

            if (persistir) {
                agendarPersistenciaLayout();
            }
        }

        function adicionarMesa() {
            const mesasGrid = document.getElementById('mesasGrid');
            if (!mesasGrid) return;
            const emptyState = mesasGrid.querySelector('.empty-state');
            if (emptyState) {
                emptyState.remove();
            }

            const mesaCard = document.createElement('div');
            mesaCard.className = 'mesa-card';
            mesaCard.setAttribute('data-mesa-card', '');
            mesaCard.innerHTML = `
                <div class="mesa-header">
                    <span class="mesa-numero">Mesa</span>
                    <span class="mesa-pessoas">0 pessoas</span>
                </div>
                <div class="mesa-actions no-print">
                    <button type="button" class="btn-link-danger" onclick="removerMesaVazia(this)">Remover mesa</button>
                </div>
                <div class="mesa-inscritos" data-dropzone>
                    <div class="mesa-vazia">Arraste inscritos para esta mesa</div>
                </div>
            `;
            mesasGrid.appendChild(mesaCard);

            const zone = mesaCard.querySelector('.mesa-inscritos');
            attachDropzoneEvents(zone);
            atualizarLayoutMesas();
        }

        function removerMesaVazia(buttonEl) {
            const mesaCard = buttonEl?.closest('.mesa-card');
            if (!mesaCard) return;

            const cards = mesaCard.querySelectorAll('.inscrito-card');
            if (cards.length > 0) {
                alert('Esta mesa possui inscritos. Mova-os para outra mesa antes de remover.');
                return;
            }

            mesaCard.remove();
            atualizarLayoutMesas();
        }

        async function gerarPDF() {
            const layoutInput = document.getElementById('layoutJsonInput');
            const pdfForm = document.getElementById('pdfLayoutForm');
            if (!layoutInput || !pdfForm) {
                return;
            }

            atualizarLayoutMesas(false);
            await persistirLayoutNoBanco();
            const layout = JSON.parse(layoutInput.value || '{"mesas": []}');
            const totalInscritos = Array.isArray(layout.mesas)
                ? layout.mesas.reduce((acc, mesa) => acc + ((mesa.inscrito_ids || []).length), 0)
                : 0;

            if (totalInscritos === 0) {
                alert('Não há inscritos para gerar o PDF.');
                return;
            }

            pdfForm.submit();
        }

        document.addEventListener('DOMContentLoaded', function () {
            if (Array.isArray(initialMesas) && initialMesas.length > 0) {
                aplicarEventosDnD();
                atualizarLayoutMesas(false);
                const layoutInput = document.getElementById('layoutJsonInput');
                ultimoLayoutSalvo = (layoutInput?.value || '').trim();
                atualizarStatusPersistencia('Layout salvo.', 'is-saved');
            }
        });
    </script>

<?php endSidebar(); ?>
