<?php
// public/login.php — layout original + lógica compatível (sem mexer no fluxo)
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) { session_start(); }

$debug = getenv('APP_DEBUG') === '1';
ini_set('display_errors', $debug ? '1' : '0');
ini_set('display_startup_errors', $debug ? '1' : '0');
ini_set('log_errors','1'); ini_set('error_log','php://stderr');
error_reporting($debug ? E_ALL : (E_ALL & ~E_NOTICE));

require_once __DIR__ . '/conexao.php'; // define $pdo / $db_error
$erro = '';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Se já logado, vai para o painel
if (!empty($_SESSION['logado']) && $_SESSION['logado'] == 1) {
    header('Location: index.php?page=dashboard');
    exit;
}

// Se houve erro de conexão, mostra o motivo
if (!empty($db_error ?? '')) {
    $erro = $db_error;
}

// ==== LÓGICA DE LOGIN (compatível com seu banco) ==== //
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($erro)) {
    // aceita "loguin" (legado), "login" ou "email"
    $loginInput = trim($_POST['loguin'] ?? ($_POST['login'] ?? ($_POST['email'] ?? '')));
    $senhaInput = (string)($_POST['senha'] ?? '');

    if ($loginInput === '' || $senhaInput === '') {
        $erro = 'Informe login e senha.';
    } else {
        try {
            if (!isset($pdo) || !$pdo) { throw new RuntimeException('Conexão não disponível.'); }

            // colunas existentes na tabela usuarios (no schema atual)
            $cols = $pdo->query("
                select column_name from information_schema.columns
                where table_schema = current_schema() and table_name = 'usuarios'
            ")->fetchAll(PDO::FETCH_COLUMN);

            $has = function(string $c) use ($cols){ return in_array($c, $cols, true); };

            // candidato de colunas para login e senha
            $loginWhere = [];
            foreach (['loguin','login','usuario','username','user','email'] as $c) {
                if ($has($c)) { $loginWhere[] = $c.' = :l'; }
            }
            if (!$loginWhere) { $loginWhere[] = 'login = :l'; } // fallback

            $senhaCol = null;
            foreach (['senha','senha_hash','password','pass'] as $c) {
                if ($has($c)) { $senhaCol = $c; break; }
            }
            if ($senhaCol === null) { throw new RuntimeException('Não encontrei a coluna de senha na tabela `usuarios`.'); }

            // busca usuário
            $sql = "select * from usuarios where (".implode(' OR ', $loginWhere).") limit 1";
            $st  = $pdo->prepare($sql);
            $st->execute([':l'=>$loginInput]);
            $u = $st->fetch(PDO::FETCH_ASSOC);

            if (!$u) {
                $erro = 'Usuário não encontrado.';
            } else {
                $stored = (string)$u[$senhaCol];
                $ok = false;

                // 1) password_hash (bcrypt/argon)
                if (!$ok && (preg_match('/^\$2[ayb]\$|\$argon2/i', $stored) === 1)) {
                    $ok = password_verify($senhaInput, $stored);
                }
                // 2) md5 legado
                if (!$ok && preg_match('/^[a-f0-9]{32}$/i', $stored) === 1) {
                    $ok = (strtolower($stored) === md5($senhaInput));
                }
                // 3) texto puro
