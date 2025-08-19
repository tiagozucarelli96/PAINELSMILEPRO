<?php
// public/dashboard2.php — usa seu layout + sidebar
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// garante conexão e preenche permissões sem mexer no login
require_once __DIR__ . '/conexao.php';
if (is_file(__DIR__ . '/permissoes_boot.php')) {
  require_once __DIR__ . '/permissoes_boot.php';
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Dashboard</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="estilo.css">
<style>
/* seus ajustes específicos do dashboard (mantive exatamente) */
.dashboard-title{ margin:12px 0 18px; font-weight:800; color:#0c3a91; letter-spacing:.2px; }
.card-grid{ display:grid; grid-template-columns: repeat(auto-fill, minmax(420px, 1fr)); gap:20px; }
.card-link{ text-decoration:none; display:block; border-radius:16px; transition: transform .12s, box-shadow .12s; }
.card{ background:#fff; border:1px solid #dfe7f4; border-radius:16px; padding:18px 22px; box-shadow:0 10px 24px rgba(13,51,125,.08); display:flex; align-items:center; gap:16px; min-height:88px; }
.card h3{ margin:0; font-weight:800; letter-spacing:.2px; color:#0c3a91; font-size:20px; }
.card p{ margin:0; color:#4a566a; font-size:15px; opacity:.9; }
.card .text{ display:flex; flex-direction:column; gap:6px; min-width:0; }
.card .icon{ font-size:28px; line-height:1; filter: drop-shadow(0 2px 8px rgba(0,0,0,.08)); }
.card-link:hover{ transform: translateY(-2px); }
.card-link:hover .card{ box-shadow:0 14px 28px rgba(13,51,125,.12), 0 2px 0 rgba(255,255,255,.6) inset; border-color:#cfe0ff; }
</style>
</head>
<body>

<?php
// sua sidebar (o arquivo já tem <div class="sidebar"> ... )
if (is_file(__DIR__ . '/sidebar.php')) { include __DIR__ . '/sidebar.php'; }
?>

<div class="main-content">
  <h1 class="dashboard-title">Bem-vindo, <?= htmlspecialchars($_SESSION['nome']()_
