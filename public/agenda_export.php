<?php
// agenda_export.php — Exportação CSV dos relatórios
session_start();
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/agenda_helper.php';

$agenda = new AgendaHelper();
$usuario_id = $_SESSION['user_id'] ?? 1;

// Verificar permissões
if (!$agenda->canViewReports($usuario_id)) {
    header('Location: index.php?page=dashboard');
    exit;
}

// Obter parâmetros
$data_inicio = $_GET['data_inicio'] ?? date('Y-m-01');
$data_fim = $_GET['data_fim'] ?? date('Y-m-t');
$espaco_id = $_GET['espaco_id'] ?? null;
$responsavel_id = $_GET['responsavel_id'] ?? null;

// Obter dados
$stmt = $GLOBALS['pdo']->prepare("
    SELECT 
        ae.id,
        ae.titulo,
        ae.inicio,
        ae.fim,
        ae.compareceu,
        ae.fechou_contrato,
        ae.fechou_ref,
        u.nome as responsavel_nome,
        esp.nome as espaco_nome
    FROM agenda_eventos ae
    JOIN usuarios u ON ae.responsavel_usuario_id = u.id
    LEFT JOIN agenda_espacos esp ON ae.espaco_id = esp.id
    WHERE ae.tipo = 'visita'
    AND DATE(ae.inicio) BETWEEN ? AND ?
    " . ($espaco_id ? "AND ae.espaco_id = ?" : "") . "
    " . ($responsavel_id ? "AND ae.responsavel_usuario_id = ?" : "") . "
    ORDER BY ae.inicio DESC
");

$params = [$data_inicio, $data_fim];
if ($espaco_id) $params[] = $espaco_id;
if ($responsavel_id) $params[] = $responsavel_id;

$stmt->execute($params);
$visitas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Configurar headers para download
$filename = "relatorio_conversao_" . date('Y-m-d') . ".csv";
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, must-revalidate');
header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');

// Abrir output stream
$output = fopen('php://output', 'w');

// BOM para UTF-8
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Cabeçalho
fputcsv($output, [
    'ID',
    'Data/Hora Início',
    'Data/Hora Fim',
    'Cliente',
    'Responsável',
    'Espaço',
    'Compareceu',
    'Fechou Contrato',
    'Referência do Contrato'
], ';');

// Dados
foreach ($visitas as $visita) {
    fputcsv($output, [
        $visita['id'],
        date('d/m/Y H:i', strtotime($visita['inicio'])),
        date('d/m/Y H:i', strtotime($visita['fim'])),
        $visita['titulo'],
        $visita['responsavel_nome'],
        $visita['espaco_nome'] ?: 'N/A',
        $visita['compareceu'] ? 'Sim' : 'Não',
        $visita['fechou_contrato'] ? 'Sim' : 'Não',
        $visita['fechou_ref'] ?: ''
    ], ';');
}

fclose($output);
exit;
?>
