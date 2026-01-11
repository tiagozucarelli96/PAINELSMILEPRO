<?php
// test_agenda_google.php — Teste direto dos eventos Google na agenda
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/google_calendar_helper.php';

header('Content-Type: application/json');

$start = $_GET['start'] ?? '2026-01-01';
$end = $_GET['end'] ?? '2026-01-31';

$pdo = $GLOBALS['pdo'];

// Teste direto da query
$stmt = $pdo->prepare("
    SELECT 
        id,
        'google_' || id::text as id_formatado,
        titulo,
        descricao,
        inicio,
        fim,
        localizacao,
        organizador_email,
        html_link,
        COALESCE(eh_visita_agendada, false) as eh_visita_agendada,
        COALESCE(contrato_fechado, false) as contrato_fechado
    FROM google_calendar_eventos
    WHERE status = 'confirmed'
      AND (
          (inicio >= :start AND inicio <= :end)
          OR (fim >= :start AND fim <= :end)
          OR (inicio <= :start AND fim >= :end)
      )
    ORDER BY inicio ASC
    LIMIT 20
");

$start_date = date('Y-m-d 00:00:00', strtotime($start));
$end_date = date('Y-m-d 23:59:59', strtotime($end));

$stmt->execute([
    ':start' => $start_date,
    ':end' => $end_date
]);

$eventos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$resultado = [
    'total_encontrado' => count($eventos),
    'periodo' => "$start_date até $end_date",
    'eventos' => $eventos
];

echo json_encode($resultado, JSON_PRETTY_PRINT);
