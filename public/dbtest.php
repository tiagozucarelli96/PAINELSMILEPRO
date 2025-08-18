<?php
require 'db.php';

$stmt = $pdo->query("SELECT NOW() as agora");
$row = $stmt->fetch();
echo "Conexão bem-sucedida! Data/hora no PostgreSQL: " . $row['agora'];
?>
