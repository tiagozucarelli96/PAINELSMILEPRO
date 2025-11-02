<?php
/**
 * comercial_realizar_degustacao.php — Relatório para realização de degustação
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/lc_permissions_enhanced.php';
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
$degustacoes = [];
$error_message = '';

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
            
            // Buscar inscritos confirmados
            $stmt = $pdo->prepare("
                SELECT id, nome, qtd_pessoas, tipo_festa 
                FROM comercial_inscricoes 
                WHERE {$coluna_id} = :deg_id AND status = 'confirmado' 
                ORDER BY nome ASC
            ");
            $stmt->execute([':deg_id' => $degustacao_id]);
            $inscritos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        $error_message = "Erro ao buscar dados: " . $e->getMessage();
    }
}

// Verificar se é requisição de PDF
$is_pdf_request = isset($_GET['pdf']) && $_GET['pdf'] === '1';

// Detectar se está via router
$is_via_router = (isset($_GET['page']) || strpos($_SERVER['REQUEST_URI'] ?? '', 'index.php') !== false);
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Realizar Degustação - <?= $degustacao ? h($degustacao['nome']) : 'Relatório' ?></title>
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
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .mesa-card {
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            padding: 1.25rem;
            transition: all 0.2s;
        }

        .mesa-card:hover {
            border-color: #3b82f6;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.15);
            transform: translateY(-2px);
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

        .inscrito-nome {
            font-size: 1rem;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 0.25rem;
        }

        .inscrito-tipo {
            font-size: 0.875rem;
            color: #6b7280;
            background: #f1f5f9;
            padding: 0.25rem 0.5rem;
            border-radius: 6px;
            display: inline-block;
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
            .btn {
                display: none !important;
            }

            .relatorio-card {
                box-shadow: none;
                border: none;
                padding: 0;
            }

            .mesas-grid {
                grid-template-columns: repeat(3, 1fr);
                gap: 0.75rem;
            }

            .mesa-card {
                break-inside: avoid;
                page-break-inside: avoid;
                border: 1px solid #e2e8f0;
                box-shadow: none;
            }

            .mesa-card:hover {
                transform: none;
                box-shadow: none;
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
</head>
<body>
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
                    <?php if (isset($degustacao)): ?>
                        <p style="margin: 0.5rem 0 0 0;"><?= h($degustacao['nome']) ?></p>
                    <?php else: ?>
                        <p style="margin: 0.5rem 0 0 0; color: #dc2626;">⚠️ Degustação não encontrada no banco de dados</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($degustacao_id > 0 && isset($degustacao)): ?>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value"><?= count($inscritos) ?></div>
                    <div class="stat-label">Inscrições Confirmadas</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= count($inscritos) ?></div>
                    <div class="stat-label">Total de Mesas</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= array_sum(array_column($inscritos, 'qtd_pessoas')) ?></div>
                    <div class="stat-label">Total de Pessoas</div>
                </div>
            </div>

            <div class="relatorio-card">
                <div class="relatorio-header">
                    <h2 class="relatorio-title"><?= h($degustacao['nome']) ?></h2>
                    <div class="relatorio-meta">
                        <div class="relatorio-meta-item">
                            <span>📅</span>
                            <span><?= date('d/m/Y', strtotime($degustacao['data'])) ?></span>
                        </div>
                        <div class="relatorio-meta-item">
                            <span>🕐</span>
                            <span><?= date('H:i', strtotime($degustacao['hora_inicio'])) ?></span>
                        </div>
                        <?php if (!empty($degustacao['local'])): ?>
                            <div class="relatorio-meta-item">
                                <span>📍</span>
                                <span><?= h($degustacao['local']) ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="mesas-grid">
                    <?php if (empty($inscritos)): ?>
                        <div class="empty-state" style="grid-column: 1 / -1;">
                            <div class="empty-state-icon">📋</div>
                            <p style="font-size: 1.125rem;">Nenhum inscrito confirmado encontrado para esta degustação.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($inscritos as $index => $inscrito): ?>
                            <?php $qtdPessoas = (int)($inscrito['qtd_pessoas'] ?? 1); ?>
                            <div class="mesa-card">
                                <div class="mesa-header">
                                    <span class="mesa-numero">Mesa <?= $index + 1 ?></span>
                                    <span class="mesa-pessoas"><?= $qtdPessoas ?> <?= $qtdPessoas === 1 ? 'pessoa' : 'pessoas' ?></span>
                                </div>
                                <div class="inscrito-info">
                                    <div class="inscrito-nome"><?= h($inscrito['nome']) ?></div>
                                    <?php if (!empty($inscrito['tipo_festa'])): ?>
                                        <span class="inscrito-tipo"><?= h(ucfirst($inscrito['tipo_festa'])) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="actions-bar no-print">
                    <button type="button" class="btn btn-secondary" onclick="window.print()">
                        🖨️ Imprimir
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="gerarPDF()">
                        📄 Gerar PDF
                    </button>
                </div>
            </div>
        <?php elseif ($degustacao_id > 0 && !isset($degustacao)): ?>
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
        function gerarPDF() {
            const url = window.location.href + (window.location.href.includes('?') ? '&' : '?') + 'pdf=1';
            window.open(url, '_blank');
        }

        // Adicionar classe para ocultar elementos na impressão
        const style = document.createElement('style');
        style.textContent = `
            @media print {
                .no-print { display: none !important; }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>

<?php
// Geração de PDF usando Dompdf (se disponível)
if ($is_pdf_request && $degustacao_id > 0 && isset($degustacao)) {
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
        try {
            if (class_exists('\\Dompdf\\Dompdf')) {
                ob_start();
                ?>
                <!DOCTYPE html>
                <html lang="pt-BR">
                <head>
                    <meta charset="UTF-8">
                    <style>
                        * { box-sizing: border-box; margin: 0; padding: 0; }
                        body { font-family: Arial, sans-serif; padding: 2cm; color: #1e293b; }
                        .header { border-bottom: 3px solid #1e293b; padding-bottom: 1rem; margin-bottom: 2rem; }
                        .title { font-size: 1.5rem; font-weight: 700; margin-bottom: 0.5rem; }
                        .meta { font-size: 0.9rem; color: #6b7280; display: flex; gap: 2rem; }
                        .stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 2rem; }
                        .stat { text-align: center; padding: 1rem; background: #f8fafc; border-radius: 8px; }
                        .stat-value { font-size: 1.5rem; font-weight: 700; color: #3b82f6; }
                        .stat-label { font-size: 0.85rem; color: #6b7280; }
                        .mesas { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; }
                        .mesa { background: #f8fafc; border: 1px solid #e2e8f0; padding: 1rem; border-radius: 8px; break-inside: avoid; }
                        .mesa-header { display: flex; justify-content: space-between; margin-bottom: 0.5rem; padding-bottom: 0.5rem; border-bottom: 1px solid #e2e8f0; }
                        .mesa-numero { font-weight: 700; color: #3b82f6; }
                        .mesa-pessoas { font-size: 0.85rem; color: #6b7280; }
                        .inscrito-nome { font-weight: 600; margin-bottom: 0.25rem; }
                        @page { margin: 1.5cm; size: A4; }
                    </style>
                </head>
                <body>
                    <div class="header">
                        <div class="title"><?= h($degustacao['nome']) ?></div>
                        <div class="meta">
                            <span>📅 <?= date('d/m/Y', strtotime($degustacao['data'])) ?></span>
                            <span>🕐 <?= date('H:i', strtotime($degustacao['hora_inicio'])) ?></span>
                            <?php if (!empty($degustacao['local'])): ?>
                                <span>📍 <?= h($degustacao['local']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="stats">
                        <div class="stat">
                            <div class="stat-value"><?= count($inscritos) ?></div>
                            <div class="stat-label">Inscrições</div>
                        </div>
                        <div class="stat">
                            <div class="stat-value"><?= count($inscritos) ?></div>
                            <div class="stat-label">Mesas</div>
                        </div>
                        <div class="stat">
                            <div class="stat-value"><?= array_sum(array_column($inscritos, 'qtd_pessoas')) ?></div>
                            <div class="stat-label">Pessoas</div>
                        </div>
                    </div>
                    <div class="mesas">
                        <?php foreach ($inscritos as $index => $inscrito): ?>
                            <?php $qtdPessoas = (int)($inscrito['qtd_pessoas'] ?? 1); ?>
                            <div class="mesa">
                                <div class="mesa-header">
                                    <span class="mesa-numero">Mesa <?= $index + 1 ?></span>
                                    <span class="mesa-pessoas"><?= $qtdPessoas ?> <?= $qtdPessoas === 1 ? 'pessoa' : 'pessoas' ?></span>
                                </div>
                                <div class="inscrito-nome"><?= h($inscrito['nome']) ?></div>
                                <?php if (!empty($inscrito['tipo_festa'])): ?>
                                    <div style="font-size: 0.85rem; color: #6b7280;"><?= h(ucfirst($inscrito['tipo_festa'])) ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
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
                $dompdf->stream($fname, ['Attachment' => true]);
                exit;
            }
        } catch (Throwable $e) {
            error_log("Erro ao gerar PDF: " . $e->getMessage());
        }
    }
    
    // Fallback: redirecionar para página de impressão
    header('Location: ' . str_replace('&pdf=1', '', str_replace('?pdf=1', '', $_SERVER['REQUEST_URI'])));
    exit;
}
?>
