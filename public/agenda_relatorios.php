<?php
// agenda_relatorios.php — Relatórios de conversão da agenda
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/agenda_helper.php';

$agenda = new AgendaHelper();
$usuario_id = $_SESSION['user_id'] ?? 1;

// Verificar permissões
if (!$agenda->canViewReports($usuario_id)) {
    header('Location: index.php?page=dashboard');
    exit;
}

// Obter dados para filtros
$espacos = $agenda->obterEspacos();
$usuarios = $agenda->obterUsuariosComCores();

// Processar filtros
$data_inicio = $_GET['data_inicio'] ?? date('Y-m-01');
$data_fim = $_GET['data_fim'] ?? date('Y-m-t');
$espaco_id = $_GET['espaco_id'] ?? null;
$responsavel_id = $_GET['responsavel_id'] ?? null;

// Obter relatório
$relatorio = $agenda->obterRelatorioConversao($data_inicio, $data_fim, $espaco_id, $responsavel_id);

// Obter detalhes das visitas (Agenda + Google Calendar)
$sql_visitas = "
    SELECT 
        'agenda_' || ae.id as id,
        ae.titulo,
        ae.inicio,
        ae.fim,
        ae.compareceu,
        ae.fechou_contrato,
        ae.fechou_ref,
        u.nome as responsavel_nome,
        esp.nome as espaco_nome,
        'agenda' as origem
    FROM agenda_eventos ae
    JOIN usuarios u ON ae.responsavel_usuario_id = u.id
    LEFT JOIN agenda_espacos esp ON ae.espaco_id = esp.id
    WHERE ae.tipo = 'visita'
    AND DATE(ae.inicio) BETWEEN ? AND ?
    " . ($espaco_id ? "AND ae.espaco_id = ?" : "") . "
    " . ($responsavel_id ? "AND ae.responsavel_usuario_id = ?" : "") . "
    
    UNION ALL
    
    SELECT 
        'google_' || gce.id as id,
        gce.titulo,
        gce.inicio,
        gce.fim,
        false as compareceu,
        gce.contrato_fechado as fechou_contrato,
        null as fechou_ref,
        COALESCE(gce.organizador_email, 'Google Calendar') as responsavel_nome,
        gce.localizacao as espaco_nome,
        'google' as origem
    FROM google_calendar_eventos gce
    WHERE gce.eh_visita_agendada = true
    AND DATE(gce.inicio) BETWEEN ? AND ?
    ORDER BY inicio DESC
";

$params = [$data_inicio, $data_fim];
if ($espaco_id) $params[] = $espaco_id;
if ($responsavel_id) $params[] = $responsavel_id;
// Parâmetros duplicados para a parte do Google Calendar
$params[] = $data_inicio;
$params[] = $data_fim;

$stmt = $GLOBALS['pdo']->prepare($sql_visitas);
$stmt->execute($params);
$visitas = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatórios de Conversão - GRUPO Smile EVENTOS</title>
    <link rel="stylesheet" href="estilo.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f4f7f6;
            color: #333;
            margin: 0;
        }

        .main-content {
            margin-left: 250px;
            padding: 20px;
            transition: margin-left 0.3s ease;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            background-color: #fff;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
        }

        h1 {
            color: #1e3a8a;
            font-size: 2.2rem;
            margin-bottom: 25px;
            border-bottom: 2px solid #e0e7ff;
            padding-bottom: 15px;
        }

        .filters {
            background: #f9fafb;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
        }

        .filters h3 {
            margin-top: 0;
            color: #1e3a8a;
        }

        .filter-row {
            display: flex;
            gap: 20px;
            align-items: end;
            flex-wrap: wrap;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .filter-group label {
            font-size: 0.9rem;
            font-weight: 600;
            color: #374151;
        }

        .filter-group input,
        .filter-group select {
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 0.9rem;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: none;
        }

        .btn-primary {
            background-color: #1e3a8a;
            color: #fff;
            border: 1px solid #1e3a8a;
        }

        .btn-primary:hover {
            background-color: #1c327a;
            border-color: #1c327a;
            transform: translateY(-1px);
        }

        .btn-success {
            background-color: #10b981;
            color: #fff;
            border: 1px solid #10b981;
        }

        .btn-outline {
            background-color: transparent;
            color: #1e3a8a;
            border: 1px solid #1e3a8a;
        }

        .btn-outline:hover {
            background-color: #e0e7ff;
            transform: translateY(-1px);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px;
            border-radius: 12px;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .stat-card h3 {
            margin: 0 0 10px 0;
            font-size: 2.5rem;
            font-weight: 700;
        }

        .stat-card p {
            margin: 0;
            font-size: 1.1rem;
            opacity: 0.9;
        }

        .stat-card.success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }

        .stat-card.warning {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        }

        .stat-card.danger {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        }

        .table-container {
            overflow-x: auto;
            margin-top: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }

        th {
            background: #f9fafb;
            font-weight: 600;
            color: #374151;
        }

        tr:hover {
            background: #f9fafb;
        }

        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .status-compareceu {
            background: #dcfce7;
            color: #166534;
        }

        .status-no-show {
            background: #fef2f2;
            color: #dc2626;
        }

        .status-contrato {
            background: #dbeafe;
            color: #1e40af;
        }

        .export-actions {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            justify-content: flex-end;
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
            .container {
                padding: 15px;
            }
            h1 {
                font-size: 1.8rem;
            }
            .filter-row {
                flex-direction: column;
                align-items: stretch;
            }
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="main-content">
        <div class="container">
            <h1>📊 Relatórios de Conversão</h1>
            
            <!-- Filtros -->
            <div class="filters">
                <h3>🔍 Filtros</h3>
                <form method="GET">
                    <div class="filter-row">
                        <div class="filter-group">
                            <label>Data Início</label>
                            <input type="date" name="data_inicio" value="<?= htmlspecialchars($data_inicio) ?>">
                        </div>
                        <div class="filter-group">
                            <label>Data Fim</label>
                            <input type="date" name="data_fim" value="<?= htmlspecialchars($data_fim) ?>">
                        </div>
                        <div class="filter-group">
                            <label>Espaço</label>
                            <select name="espaco_id">
                                <option value="">Todos os espaços</option>
                                <?php foreach ($espacos as $espaco): ?>
                                    <option value="<?= $espaco['id'] ?>" 
                                            <?= $espaco_id == $espaco['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($espaco['nome']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Responsável</label>
                            <select name="responsavel_id">
                                <option value="">Todos os responsáveis</option>
                                <?php foreach ($usuarios as $user): ?>
                                    <option value="<?= $user['id'] ?>" 
                                            <?= $responsavel_id == $user['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($user['nome']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <button type="submit" class="btn btn-primary">
                                🔍 Filtrar
                            </button>
                        </div>
                    </div>
                </form>
            </div>
            
            <!-- Estatísticas -->
            <div class="stats-grid">
                <div class="stat-card">
                    <h3><?= $relatorio['total_visitas'] ?></h3>
                    <p>Total de Visitas</p>
                </div>
                <div class="stat-card success">
                    <h3><?= $relatorio['comparecimentos'] ?></h3>
                    <p>Comparecimentos</p>
                </div>
                <div class="stat-card warning">
                    <h3><?= $relatorio['contratos_fechados'] ?></h3>
                    <p>Contratos Fechados</p>
                </div>
                <div class="stat-card danger">
                    <h3><?= $relatorio['taxa_conversao'] ?>%</h3>
                    <p>Taxa de Conversão</p>
                </div>
            </div>
            
            <!-- Ações de Exportação -->
            <div class="export-actions">
                <button class="btn btn-success" onclick="exportCSV()">
                    📊 Exportar CSV
                </button>
                <a href="agenda.php" class="btn btn-outline">
                    ← Voltar para Agenda
                </a>
            </div>
            
            <!-- Tabela de Visitas -->
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Data/Hora</th>
                            <th>Cliente</th>
                            <th>Responsável</th>
                            <th>Espaço</th>
                            <th>Compareceu</th>
                            <th>Fechou Contrato</th>
                            <th>Referência</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($visitas as $visita): ?>
                            <tr>
                                <td>
                                    <?= date('d/m/Y H:i', strtotime($visita['inicio'])) ?><br>
                                    <small style="color: #666;">
                                        <?= date('H:i', strtotime($visita['fim'])) ?>
                                    </small>
                                </td>
                                <td><?= htmlspecialchars($visita['titulo']) ?></td>
                                <td><?= htmlspecialchars($visita['responsavel_nome']) ?></td>
                                <td><?= htmlspecialchars($visita['espaco_nome'] ?: 'N/A') ?></td>
                                <td>
                                    <span class="status-badge <?= $visita['compareceu'] ? 'status-compareceu' : 'status-no-show' ?>">
                                        <?= $visita['compareceu'] ? '✅ Sim' : '❌ No Show' ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($visita['fechou_contrato']): ?>
                                        <span class="status-badge status-contrato">
                                            ✅ <?= htmlspecialchars($visita['fechou_ref'] ?: 'Sim') ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="status-badge status-no-show">❌ Não</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($visita['fechou_ref'] ?: '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <?php if (empty($visitas)): ?>
                <div style="text-align: center; padding: 40px; color: #666;">
                    <h3>📅 Nenhuma visita encontrada</h3>
                    <p>Não há visitas no período selecionado com os filtros aplicados.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function exportCSV() {
            const params = new URLSearchParams(window.location.search);
            params.set('export', 'csv');
            window.location.href = 'agenda_export.php?' + params.toString();
        }
    </script>
    
    <!-- Custom Modals CSS -->
    <link rel="stylesheet" href="assets/css/custom_modals.css">
    <!-- Custom Modals JS -->
    <script src="assets/js/custom_modals.js"></script>
</body>
</html>
