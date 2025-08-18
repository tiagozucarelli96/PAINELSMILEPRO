<?php
session_start();
include 'conexao.php';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $login = trim($_POST["usuario"] ?? '');
    $senha = $_POST["senha"] ?? '';

    $stmt = $pdo->prepare("SELECT id, nome, login, senha, funcao FROM usuarios WHERE login = ?");
    $stmt->execute([$login]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    echo '<pre>';
    var_dump($usuario);
    echo '</pre>';

    // Debug: mostrar exatamente a senha que chegou do formulário
    echo 'Senha digitada (input): ';
    var_dump($senha);
    echo '<br>';

    // Mostrar hash da senha no banco
    echo 'Senha hash no banco: ';
    var_dump($usuario['senha']);
    echo '<br>';

    if (!$usuario) {
        echo "Usuário não encontrado.";
    } else {
        if (password_verify($senha, $usuario['senha'])) {
            echo "Senha correta.";
        } else {
            echo "Senha incorreta.";
        }
    }
    exit;
}
?>
<form method="post">
    <input type="text" name="usuario" placeholder="Usuário" />
    <input type="password" name="senha" placeholder="Senha" />
    <button type="submit">Testar Login</button>
</form>
