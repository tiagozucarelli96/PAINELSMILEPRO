<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__.'/conexao.php';
require_once __DIR__.'/config.php';
exigeLogin(); refreshPermissoes($pdo);
if (empty($_SESSION['perm_estoque_logistico'])) { echo '<div class="alert-error">Acesso negado.</div>'; exit; }
?>
<h1>📦 Estoque Logístico</h1>
<p>Em breve.</p>
