<?php
// agenda_ics.php — Exportação ICS para sincronização de calendário
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/agenda_helper.php';

// Obter parâmetros
$usuario_id = $_GET['u'] ?? null;
$token = $_GET['t'] ?? null;

if (!$usuario_id) {
    http_response_code(400);
    echo "Parâmetro 'u' (usuário) é obrigatório";
    exit;
}

try {
    $agenda = new AgendaHelper();
    
    // Verificar token se fornecido
    if ($token) {
        $stmt = $GLOBALS['pdo']->prepare("
            SELECT ativo FROM agenda_tokens_ics 
            WHERE usuario_id = ? AND token = ?
        ");
        $stmt->execute([$usuario_id, $token]);
        if (!$stmt->fetch()) {
            http_response_code(403);
            echo "Token inválido";
            exit;
        }
    }
    
    // Obter eventos do usuário
    $eventos = $agenda->obterEventosICS($usuario_id);
    
    // Configurar headers para download
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="agenda_smile.ics"');
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');
    
    // Gerar conteúdo ICS
    echo "BEGIN:VCALENDAR\r\n";
    echo "VERSION:2.0\r\n";
    echo "PRODID:-//GRUPO Smile EVENTOS//Agenda Interna//PT\r\n";
    echo "CALSCALE:GREGORIAN\r\n";
    echo "METHOD:PUBLISH\r\n";
    
    foreach ($eventos as $evento) {
        $uid = "agenda-{$evento['id']}@smileeventos.com.br";
        $dtstart = date('Ymd\THis\Z', strtotime($evento['inicio']));
        $dtend = date('Ymd\THis\Z', strtotime($evento['fim']));
        $dtstamp = date('Ymd\THis\Z');
        $created = date('Ymd\THis\Z', strtotime($evento['inicio']));
        $summary = $evento['titulo'];
        $description = $evento['descricao'] ?: '';
        
        if ($evento['espaco_nome']) {
            $location = $evento['espaco_nome'];
        } else {
            $location = '';
        }
        
        // Determinar status
        $status = 'CONFIRMED';
        if ($evento['tipo'] === 'bloqueio') {
            $status = 'FREE';
        }
        
        echo "BEGIN:VEVENT\r\n";
        echo "UID:{$uid}\r\n";
        echo "DTSTART:{$dtstart}\r\n";
        echo "DTEND:{$dtend}\r\n";
        echo "DTSTAMP:{$dtstamp}\r\n";
        echo "CREATED:{$created}\r\n";
        echo "SUMMARY:{$summary}\r\n";
        
        if ($description) {
            echo "DESCRIPTION:" . str_replace(["\r\n", "\n", "\r"], "\\n", $description) . "\r\n";
        }
        
        if ($location) {
            echo "LOCATION:{$location}\r\n";
        }
        
        echo "STATUS:{$status}\r\n";
        echo "TRANSP:OPAQUE\r\n";
        echo "END:VEVENT\r\n";
    }
    
    echo "END:VCALENDAR\r\n";
    
} catch (Exception $e) {
    http_response_code(500);
    echo "Erro interno: " . $e->getMessage();
}
?>
