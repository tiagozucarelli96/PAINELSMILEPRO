<?php
// cartao_ofx_me_cartoes.php — Cadastro de cartoes
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['logado']) || empty($_SESSION['perm_administrativo'])) {
    header('Location: index.php?page=login');
    exit;
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';

$pdo = $GLOBALS['pdo'];
$mensagens = [];
$erros = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'salvar') {
        $id = (int)($_POST['id'] ?? 0);
        $nome = trim($_POST['nome_cartao'] ?? '');
        $diaVencimento = (int)($_POST['dia_vencimento'] ?? 0);
        $status = !empty($_POST['status']) ? 1 : 0;
        $apelido = trim($_POST['apelido'] ?? '');
        $cor = trim($_POST['cor'] ?? '');
        $final = trim($_POST['final'] ?? '');

        if ($nome === '') {
            $erros[] = 'Informe o nome do cartao.';
        }
        if ($diaVencimento < 1 || $diaVencimento > 31) {
            $erros[] = 'Dia de vencimento invalido.';
        }

        if (empty($erros)) {
            if ($id > 0) {
                $stmt = $pdo->prepare('
                    UPDATE cartao_ofx_cartoes
                    SET nome_cartao = ?, dia_vencimento = ?, status = ?, apelido = ?, cor = ?, final = ?, atualizado_em = NOW()
                    WHERE id = ?
                ');
                $stmt->execute([$nome, $diaVencimento, $status, $apelido ?: null, $cor ?: null, $final ?: null, $id]);
                $mensagens[] = 'Cartao atualizado.';
            } else {
                $stmt = $pdo->prepare('
                    INSERT INTO cartao_ofx_cartoes
                    (nome_cartao, dia_vencimento, status, apelido, cor, final, criado_em, atualizado_em)
                    VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
                ');
                $stmt->execute([$nome, $diaVencimento, $status, $apelido ?: null, $cor ?: null, $final ?: null]);
                $mensagens[] = 'Cartao cadastrado.';
            }
        }
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('UPDATE cartao_ofx_cartoes SET status = NOT status, atualizado_em = NOW() WHERE id = ?');
            $stmt->execute([$id]);
            $mensagens[] = 'Status atualizado.';
        }
    }
}

$cartoesStmt = $pdo->query('SELECT * FROM cartao_ofx_cartoes ORDER BY nome_cartao');
$cartoes = $cartoesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$editId = (int)($_GET['edit'] ?? 0);
$editCard = null;
if ($editId > 0) {
    foreach ($cartoes as $cartao) {
        if ((int)$cartao['id'] === $editId) {
            $editCard = $cartao;
            break;
        }
    }
}

ob_start();
?>

<style>
.ofx-container {
    width: 100%;
    max-width: 1200px;
    margin: 0 auto;
    padding: 1.5rem;
}

.ofx-header {
    margin-bottom: 1.5rem;
}

.ofx-header h1 {
    font-size: 1.75rem;
    color: #1e3a8a;
    margin-bottom: 0.25rem;
}

.ofx-nav {
    display: flex;
    gap: 1rem;
    margin-bottom: 1.5rem;
    flex-wrap: wrap;
}

.ofx-nav a {
    text-decoration: none;
    padding: 0.5rem 1rem;
    border-radius: 8px;
    background: #f1f5f9;
    color: #1e3a8a;
    font-weight: 600;
}

.ofx-card {
    background: #fff;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 10px rgba(15, 23, 42, 0.08);
    margin-bottom: 1.5rem;
}

.ofx-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
}

.ofx-field label {
    font-weight: 600;
    display: block;
    margin-bottom: 0.4rem;
}

.ofx-field input,
.ofx-field select {
    width: 100%;
    padding: 0.55rem 0.75rem;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    font-size: 0.95rem;
}

.ofx-button {
    background: #2563eb;
    color: #fff;
    border: none;
    padding: 0.6rem 1.2rem;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
}

.ofx-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 1rem;
}

.ofx-table th,
.ofx-table td {
    padding: 0.65rem;
    border-bottom: 1px solid #e2e8f0;
    text-align: left;
}

.ofx-tag {
    padding: 0.2rem 0.5rem;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 600;
    display: inline-block;
}

.ofx-tag.ativo {
    background: #dcfce7;
    color: #166534;
}

.ofx-tag.inativo {
    background: #fee2e2;
    color: #991b1b;
}

.ofx-alert {
    padding: 0.75rem 1rem;
    border-radius: 8px;
    margin-bottom: 1rem;
}

.ofx-alert.error {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fecaca;
}

.ofx-alert.success {
    background: #dcfce7;
    color: #166534;
    border: 1px solid #bbf7d0;
}
</style>

<div class="ofx-container">
    <div class="ofx-header">
        <h1>Cartoes</h1>
        <p>Cadastre cartoes para gerar vencimento automaticamente.</p>
    </div>

    <div class="ofx-nav">
        <a href="index.php?page=cartao_ofx_me">Importar Fatura</a>
        <a href="index.php?page=cartao_ofx_me_cartoes">Cartoes</a>
        <a href="index.php?page=cartao_ofx_me_historico">Historico</a>
    </div>

    <?php foreach ($erros as $erro): ?>
        <div class="ofx-alert error"><?php echo htmlspecialchars($erro); ?></div>
    <?php endforeach; ?>

    <?php foreach ($mensagens as $msg): ?>
        <div class="ofx-alert success"><?php echo htmlspecialchars($msg); ?></div>
    <?php endforeach; ?>

    <div class="ofx-card">
        <h3><?php echo $editCard ? 'Editar cartao' : 'Novo cartao'; ?></h3>
        <form method="post">
            <input type="hidden" name="action" value="salvar">
            <input type="hidden" name="id" value="<?php echo $editCard ? (int)$editCard['id'] : 0; ?>">
            <div class="ofx-grid">
                <div class="ofx-field">
                    <label>Nome do cartao</label>
                    <input type="text" name="nome_cartao" value="<?php echo htmlspecialchars($editCard['nome_cartao'] ?? ''); ?>" required>
                </div>
                <div class="ofx-field">
                    <label>Dia do vencimento</label>
                    <input type="number" name="dia_vencimento" min="1" max="31" value="<?php echo htmlspecialchars($editCard['dia_vencimento'] ?? ''); ?>" required>
                </div>
                <div class="ofx-field">
                    <label>Status</label>
                    <select name="status">
                        <option value="1" <?php echo (!isset($editCard['status']) || $editCard['status']) ? 'selected' : ''; ?>>Ativo</option>
                        <option value="0" <?php echo (isset($editCard['status']) && !$editCard['status']) ? 'selected' : ''; ?>>Inativo</option>
                    </select>
                </div>
                <div class="ofx-field">
                    <label>Apelido</label>
                    <input type="text" name="apelido" value="<?php echo htmlspecialchars($editCard['apelido'] ?? ''); ?>">
                </div>
                <div class="ofx-field">
                    <label>Cor</label>
                    <input type="text" name="cor" value="<?php echo htmlspecialchars($editCard['cor'] ?? ''); ?>">
                </div>
                <div class="ofx-field">
                    <label>Final</label>
                    <input type="text" name="final" value="<?php echo htmlspecialchars($editCard['final'] ?? ''); ?>">
                </div>
            </div>
            <div style="margin-top: 1rem;">
                <button class="ofx-button" type="submit">Salvar</button>
            </div>
        </form>
    </div>

    <div class="ofx-card">
        <h3>Cartoes cadastrados</h3>
        <table class="ofx-table">
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>Vencimento</th>
                    <th>Status</th>
                    <th>Acoes</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($cartoes as $cartao): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($cartao['nome_cartao']); ?></td>
                        <td>Dia <?php echo (int)$cartao['dia_vencimento']; ?></td>
                        <td>
                            <span class="ofx-tag <?php echo $cartao['status'] ? 'ativo' : 'inativo'; ?>">
                                <?php echo $cartao['status'] ? 'Ativo' : 'Inativo'; ?>
                            </span>
                        </td>
                        <td>
                            <a href="index.php?page=cartao_ofx_me_cartoes&edit=<?php echo (int)$cartao['id']; ?>">Editar</a>
                            <form method="post" style="display:inline-block;margin-left:0.5rem;">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?php echo (int)$cartao['id']; ?>">
                                <button type="submit" class="ofx-button" style="background:#475569;padding:0.35rem 0.6rem;">Ativar/Inativar</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
$conteudo = ob_get_clean();
includeSidebar('Administrativo');
echo $conteudo;
endSidebar();
?>
