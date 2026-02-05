<?php
/**
 * Holerite individual — Selecionar funcionário, mês/ano e anexar holerite.
 * O holerite aparece na área "Minha conta" do funcionário.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['logado']) || empty($_SESSION['perm_administrativo'])) {
    header('Location: index.php?page=login');
    exit;
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/upload_magalu.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/setup_holerites_individual.php';

$mensagem = '';
$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'cadastrar') {
    try {
        $usuario_id = (int)($_POST['usuario_id'] ?? 0);
        $mes_competencia = trim($_POST['mes_competencia'] ?? '');

        if ($usuario_id <= 0) {
            throw new Exception('Selecione o funcionário.');
        }
        if (empty($mes_competencia) || !preg_match('/^\d{2}\/\d{4}$/', $mes_competencia)) {
            throw new Exception('Mês/ano inválido. Use MM/AAAA (ex: 01/2025).');
        }
        if (!isset($_FILES['arquivo']) || $_FILES['arquivo']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Selecione o arquivo do holerite.');
        }

        $uploader = new MagaluUpload();
        $resultado = $uploader->upload($_FILES['arquivo'], 'contabilidade/holerites_individual');

        $arquivo_url = $resultado['url'] ?? null;
        $chave_storage = $resultado['chave_storage'] ?? null;
        $arquivo_nome = $resultado['nome_original'] ?? $_FILES['arquivo']['name'];

        $stmt = $pdo->prepare("
            INSERT INTO contabilidade_holerites_individual (usuario_id, mes_competencia, arquivo_url, arquivo_nome, chave_storage)
            VALUES (:usuario_id, :mes_competencia, :arquivo_url, :arquivo_nome, :chave_storage)
        ");
        $stmt->execute([
            ':usuario_id' => $usuario_id,
            ':mes_competencia' => $mes_competencia,
            ':arquivo_url' => $arquivo_url,
            ':arquivo_nome' => $arquivo_nome,
            ':chave_storage' => $chave_storage,
        ]);
        $mensagem = 'Holerite individual cadastrado. O funcionário verá em Minha conta.';
    } catch (Exception $e) {
        $erro = $e->getMessage();
    }
}

// Listar usuários para o select
$usuarios = [];
try {
    $stmt = $pdo->query("SELECT id, nome, email FROM usuarios WHERE ativo IS DISTINCT FROM FALSE ORDER BY nome ASC");
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Holerite individual - listar usuarios: " . $e->getMessage());
}

// Listar holerites individuais recentes
$holerites = [];
try {
    $stmt = $pdo->query("
        SELECT h.id, h.usuario_id, h.mes_competencia, h.arquivo_nome, h.criado_em, u.nome as usuario_nome
        FROM contabilidade_holerites_individual h
        JOIN usuarios u ON u.id = h.usuario_id
        ORDER BY h.criado_em DESC
        LIMIT 50
    ");
    $holerites = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Holerite individual - listar: " . $e->getMessage());
}

ob_start();
?>
<style>
    .holerite-ind-container { padding: 2rem; max-width: 900px; margin: 0 auto; background: #f8fafc; }
    .page-title { font-size: 1.5rem; font-weight: 700; color: #1e3a8a; margin: 0 0 0.5rem 0; }
    .page-subtitle { color: #64748b; font-size: 0.9rem; margin-bottom: 1.5rem; }
    .card { background: white; border-radius: 12px; padding: 1.5rem; margin-bottom: 1.5rem; box-shadow: 0 2px 8px rgba(0,0,0,0.06); border: 1px solid #e5e7eb; }
    .card h2 { font-size: 1.1rem; color: #1e3a8a; margin: 0 0 1rem 0; }
    .form-group { margin-bottom: 1rem; }
    .form-label { display: block; font-weight: 500; color: #374151; margin-bottom: 0.35rem; font-size: 0.875rem; }
    .form-input, .form-select { width: 100%; padding: 0.6rem 0.75rem; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.9rem; }
    .btn-primary { background: #1e40af; color: white; padding: 0.6rem 1.25rem; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; }
    .btn-primary:hover { background: #1e3a8a; }
    .alert { padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1rem; }
    .alert-success { background: #d1fae5; color: #065f46; }
    .alert-error { background: #fee2e2; color: #991b1b; }
    .tbl { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
    .tbl th, .tbl td { padding: 0.6rem 0.75rem; text-align: left; border-bottom: 1px solid #e5e7eb; }
    .tbl th { font-weight: 600; color: #1e3a8a; }
    .link-download { color: #1e40af; text-decoration: none; font-weight: 500; }
    .link-download:hover { text-decoration: underline; }
</style>

<div class="holerite-ind-container">
    <h1 class="page-title">Holerite individual</h1>
    <p class="page-subtitle">Selecione o funcionário, mês/ano e anexe o holerite. Ele aparecerá em <strong>Minha conta</strong> do funcionário.</p>

    <?php if ($mensagem): ?>
    <div class="alert alert-success"><?= htmlspecialchars($mensagem) ?></div>
    <?php endif; ?>
    <?php if ($erro): ?>
    <div class="alert alert-error"><?= htmlspecialchars($erro) ?></div>
    <?php endif; ?>

    <div class="card">
        <h2>➕ Anexar holerite</h2>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="acao" value="cadastrar">
            <div class="form-group">
                <label class="form-label">Funcionário *</label>
                <select name="usuario_id" class="form-select" required>
                    <option value="">— Selecione —</option>
                    <?php foreach ($usuarios as $u): ?>
                    <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['nome']) ?> (<?= htmlspecialchars($u['email']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Mês/Ano (competência) *</label>
                <input type="text" name="mes_competencia" class="form-input" placeholder="MM/AAAA (ex: 01/2025)" maxlength="7" required>
            </div>
            <div class="form-group">
                <label class="form-label">Arquivo (PDF) *</label>
                <input type="file" name="arquivo" class="form-input" accept=".pdf,application/pdf" required>
            </div>
            <button type="submit" class="btn-primary">Enviar holerite</button>
        </form>
    </div>

    <div class="card">
        <h2>📄 Holerites individuais recentes</h2>
        <?php if (empty($holerites)): ?>
        <p style="color: #64748b;">Nenhum holerite individual cadastrado ainda.</p>
        <?php else: ?>
        <table class="tbl">
            <thead>
                <tr><th>Funcionário</th><th>Competência</th><th>Arquivo</th><th>Data</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($holerites as $h): ?>
                <tr>
                    <td><?= htmlspecialchars($h['usuario_nome']) ?></td>
                    <td><?= htmlspecialchars($h['mes_competencia']) ?></td>
                    <td><?= htmlspecialchars($h['arquivo_nome']) ?></td>
                    <td><?= date('d/m/Y H:i', strtotime($h['criado_em'])) ?></td>
                    <td><a href="contabilidade_download.php?tipo=holerite_individual&id=<?= (int)$h['id'] ?>" class="link-download" target="_blank">Ver / Baixar</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <p><a href="index.php?page=contabilidade" style="color: #1e40af;">← Voltar para Contabilidade</a></p>
</div>

<?php
$conteudo = ob_get_clean();
includeSidebar('Holerite individual');
echo $conteudo;
endSidebar();
