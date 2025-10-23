<?php
// public/usuarios.php — Redireciona para versão moderna
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Redirecionar para a versão moderna
header('Location: usuarios_moderno.php');
exit;