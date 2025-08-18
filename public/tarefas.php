<?php
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);
session_start();

if (!isset($_SESSION["logado"]) || $_SESSION["logado"] !== true) {
    header("Location: login.php");
    exit;
}

// Permissão (se quiser travar por perfil)
if (empty($_SESSION['perm_tarefas'])) {
    header("Location: dashboard.php");
    exit;
}

include "config.php"; // precisa expor $pdo

// Busca tarefas (ajuste o nome da tabela se diferente)
$stmt = $pdo->query("SELECT usuario, descricao, status, prazo, criado_em FROM tarefas ORDER BY prazo ASC, criado_em DESC");
$tarefas = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
$current = 'tarefas';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <title>Tarefas | Painel Smile</title>
  <link rel="stylesheet" href="estilo.css" />
</head>
<body>

<!-- SIDEBAR -->
<div class="sidebar">
  <img src="arquivos/logo-smile.png" alt="Logo Smile Eventos" />
  <nav>
    <a href="dashboard.php" class="<?= $current==='dashboard'?'active':'' ?>"><span class="icon">🏠</span>Dashboard</a>
    <?php if (!empty($_SESSION['perm_usuarios'])): ?>
      <a href="usuarios.php" class="<?= $current==='usuarios'?'active':'' ?>"><span class="icon">👥</span>Usuários</a>
    <?php endif; ?>
    <?php if (!empty($_SESSION['perm_solicitar_pagamento'])): ?>
      <a href="pagamentos.php" class="<?= $current==='pagamentos'?'active':'' ?>"><span class="icon">💸</span>Pagamentos</a>
    <?php endif; ?>
    <?php if (!empty($_SESSION['perm_lista_compras'])): ?>
      <a href="lista_compras.php" class="<?= $current==='lista_compras'?'active':'' ?>"><span class="icon">🛒</span>Lista de Compras</a>
    <?php endif; ?>
    <?php if (!empty($_SESSION['perm_tarefas'])): ?>
      <a href="tarefas.php" class="<?= $current==='tarefas'?'active':'' ?>"><span class="icon">📋</span>Tarefas</a>
    <?php endif; ?>
    <?php if (!empty($_SESSION['perm_demandas'])): ?>
      <a href="demandas.php" class="<?= $current==='demandas'?'active':'' ?>"><span class="icon">📁</span>Demandas</a>
    <?php endif; ?>
    <a href="logout.php"><span class="icon">🚪</span>Sair</a>
  </nav>
</div>

<!-- CONTEÚDO -->
<div class="main-content">
  <h1>Tarefas</h1>

  <?php if (count($tarefas)): ?>
    <div class="card" style="width:100%; overflow:auto;">
      <table style="width:100%; border-collapse:collapse;">
        <thead>
          <tr style="text-align:left; border-bottom:1px solid #ddd;">
            <th style="padding:10px;">Usuário</th>
            <th style="padding:10px;">Descrição</th>
            <th style="padding:10px;">Status</th>
            <th style="padding:10px;">Prazo</th>
            <th style="padding:10px;">Criado em</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($tarefas as $t): ?>
            <tr style="border-bottom:1px solid #f0f0f0;">
              <td style="padding:10px;"><?= htmlspecialchars($t['usuario'] ?? '') ?></td>
              <td style="padding:10px;"><?= htmlspecialchars($t['descricao'] ?? '') ?></td>
              <td style="padding:10px;"><?= htmlspecialchars($t['status'] ?? '') ?></td>
              <td style="padding:10px;"><?= htmlspecialchars($t['prazo'] ?? '-') ?></td>
              <td style="padding:10px;"><?= htmlspecialchars($t['criado_em'] ?? '') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <p>Não há tarefas cadastradas.</p>
  <?php endif; ?>
</div>

</body>
</html>
