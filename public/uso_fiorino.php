<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__.'/conexao.php';
require_once __DIR__.'/config.php';
exigeLogin(); refreshPermissoes($pdo);
if (empty($_SESSION['perm_uso_fiorino'])) { echo '<div class="alert-error">Acesso negado.</div>'; exit; }
?>
<h1>🚘 Uso de Fiorino</h1>
<p>Em breve.</p>
