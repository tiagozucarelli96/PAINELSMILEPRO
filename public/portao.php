<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/conexao.php';

exigeLogin();
if (empty($_SESSION['perm_portao'])) {
    echo '<div class="alert-error">Acesso negado ao módulo Portão.</div>';
    exit;
}

$msg = '';
$ok  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'abrir') {
    // Aqui seria a chamada real à API Tuya (foco atual: não quebrar nada)
    $ok  = true; // simulação de sucesso
    $msg = 'Acionamento enviado.';

    // Log (se a tabela existir)
    try {
        $tableExists = $pdo->prepare("
            SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portao_logs'
        ");
        $tableExists->execute();
        if ((int)$tableExists->fetchColumn() > 0) {
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
            $ins = $pdo->prepare("
                INSERT INTO portao_logs (usuario_id, acao, ip, user_agent, criado_em)
                VALUES (:uid, :acao, :ip, :ua, NOW())
            ");
            $ins->execute([
                ':uid'  => (int)($_SESSION['id_usuario'] ?? 0),
                ':acao' => $ok ? 'abrir' : 'erro',
                ':ip'   => $ip,
                ':ua'   => $ua,
            ]);
        }
    } catch (Throwable $e) {
        // não quebra a página se a tabela não existir
    }
}
?>
<h1>Portão</h1>

<?php if ($msg): ?>
  <div class="<?= $ok ? 'alert-success' : 'alert-error' ?>">
    <?= htmlspecialchars($msg) ?>
  </div>
<?php endif; ?>

<form method="post" onsubmit="this.querySelector('button').disabled=true;">
  <input type="hidden" name="acao" value="abrir">
  <button type="submit" class="btn-primary">🚪 Abrir Portão</button>
</form>

<p style="margin-top:12px;opacity:.8;font-size:14px">
  * Integração real com Tuya será plugada depois. Agora o sistema respeita a permissão e registra histórico quando disponível.
</p>
