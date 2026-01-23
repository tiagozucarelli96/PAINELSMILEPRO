<?php
// cartao_ofx_me_historico.php — Historico de OFX gerados
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
    if ($action === 'excluir') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('
                UPDATE cartao_ofx_geracoes
                SET status = ?, excluido_em = NOW(), excluido_por = ?
                WHERE id = ?
            ');
            $stmt->execute(['excluido', $_SESSION['id'] ?? null, $id]);
            $mensagens[] = 'Registro marcado como excluido.';
        }
    }
}

$viewId = (int)($_GET['view'] ?? 0);
$viewTransacoes = [];
$downloadId = (int)($_GET['download'] ?? 0);
$downloadError = null;

if ($downloadId > 0) {
    try {
        if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
            require_once __DIR__ . '/../vendor/autoload.php';
        }
        $stmt = $pdo->prepare('SELECT arquivo_key, arquivo_url FROM cartao_ofx_geracoes WHERE id = ?');
        $stmt->execute([$downloadId]);
        $arq = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$arq) {
            throw new Exception('Registro não encontrado');
        }
        $key = $arq['arquivo_key'] ?? null;
        $url = $arq['arquivo_url'] ?? null;
        if ($key && class_exists('Aws\\S3\\S3Client')) {
            $bucket = $_ENV['MAGALU_BUCKET'] ?? getenv('MAGALU_BUCKET') ?: 'smilepainel';
            $region = $_ENV['MAGALU_REGION'] ?? getenv('MAGALU_REGION') ?: 'br-se1';
            $endpoint = $_ENV['MAGALU_ENDPOINT'] ?? getenv('MAGALU_ENDPOINT') ?: 'https://br-se1.magaluobjects.com';
            $accessKey = $_ENV['MAGALU_ACCESS_KEY'] ?? getenv('MAGALU_ACCESS_KEY');
            $secretKey = $_ENV['MAGALU_SECRET_KEY'] ?? getenv('MAGALU_SECRET_KEY');
            $s3 = new Aws\S3\S3Client([
                'region' => $region,
                'version' => 'latest',
                'credentials' => [
                    'key' => $accessKey,
                    'secret' => $secretKey,
                ],
                'endpoint' => $endpoint,
                'use_path_style_endpoint' => true,
                'signature_version' => 'v4',
            ]);
            $cmd = $s3->getCommand('GetObject', ['Bucket' => strtolower($bucket), 'Key' => $key]);
            $presigned = $s3->createPresignedRequest($cmd, '+15 minutes')->getUri();
            header('Location: ' . (string)$presigned);
            exit;
        } elseif ($url) {
            header('Location: ' . $url);
            exit;
        } else {
            throw new Exception('Arquivo não disponível para download');
        }
    } catch (Exception $e) {
        $downloadError = $e->getMessage();
    }
}
if ($viewId > 0) {
    $stmt = $pdo->prepare('SELECT transacoes_json FROM cartao_ofx_geracoes WHERE id = ?');
    $stmt->execute([$viewId]);
    $json = $stmt->fetchColumn();
    if ($json) {
        $decoded = json_decode($json, true);
        if (is_array($decoded)) {
            $viewTransacoes = $decoded;
        }
    }
}

function cartao_ofx_hist_format_date(string $ymd): string {
    if (preg_match('/^\\d{8}$/', $ymd) === 1) {
        return substr($ymd, 6, 2) . '/' . substr($ymd, 4, 2) . '/' . substr($ymd, 0, 4);
    }
    return $ymd;
}

$stmt = $pdo->query('
    SELECT g.*, c.nome_cartao, u.nome as usuario_nome
    FROM cartao_ofx_geracoes g
    LEFT JOIN cartao_ofx_cartoes c ON c.id = g.cartao_id
    LEFT JOIN usuarios u ON u.id = g.usuario_id
    ORDER BY g.gerado_em DESC
');
$geracoes = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

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

.ofx-table {
    width: 100%;
    border-collapse: collapse;
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

.ofx-tag.gerado {
    background: #dcfce7;
    color: #166534;
}

.ofx-tag.excluido {
    background: #fee2e2;
    color: #991b1b;
}

.ofx-button {
    background: #2563eb;
    color: #fff;
    border: none;
    padding: 0.35rem 0.6rem;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
}

.ofx-alert {
    padding: 0.75rem 1rem;
    border-radius: 8px;
    margin-bottom: 1rem;
}

.ofx-alert.success {
    background: #dcfce7;
    color: #166534;
    border: 1px solid #bbf7d0;
}
</style>

<div class="ofx-container">
    <div class="ofx-header">
        <h1>Historico de OFX</h1>
        <p>Auditoria de geracoes de OFX por cartao e competencia.</p>
    </div>

    <div class="ofx-nav">
        <a href="index.php?page=cartao_ofx_me">Importar Fatura</a>
        <a href="index.php?page=cartao_ofx_me_cartoes">Cartoes</a>
        <a href="index.php?page=cartao_ofx_me_historico">Historico</a>
    </div>

    <?php foreach ($mensagens as $msg): ?>
        <div class="ofx-alert success"><?php echo htmlspecialchars($msg); ?></div>
    <?php endforeach; ?>
    <?php if ($downloadError): ?>
        <div class="ofx-alert error"><?php echo htmlspecialchars($downloadError); ?></div>
    <?php endif; ?>

    <?php if (!empty($viewTransacoes)): ?>
        <div class="ofx-card">
            <h3>Previa</h3>
            <table class="ofx-table">
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Descricao</th>
                        <th>Valor</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($viewTransacoes as $tx): ?>
                        <tr>
                            <td><?php echo htmlspecialchars(cartao_ofx_hist_format_date($tx['data'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars($tx['descricao'] ?? ''); ?></td>
                            <td>R$ <?php echo number_format((float)($tx['valor'] ?? 0), 2, ',', '.'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <div class="ofx-card">
        <h3>Geracoes</h3>
        <?php if (empty($geracoes)): ?>
            <p class="ofx-muted">Nenhuma geração registrada ainda.</p>
        <?php else: ?>
            <table class="ofx-table">
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Competencia</th>
                        <th>Cartao</th>
                        <th>Transacoes</th>
                        <th>Usuario</th>
                        <th>Status</th>
                        <th>Acoes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($geracoes as $geracao): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($geracao['gerado_em']); ?></td>
                            <td><?php echo htmlspecialchars($geracao['competencia']); ?></td>
                            <td><?php echo htmlspecialchars($geracao['nome_cartao'] ?? ''); ?></td>
                            <td><?php echo (int)$geracao['quantidade_transacoes']; ?></td>
                            <td><?php echo htmlspecialchars($geracao['usuario_nome'] ?? ''); ?></td>
                            <td>
                                <span class="ofx-tag <?php echo $geracao['status'] === 'excluido' ? 'excluido' : 'gerado'; ?>">
                                    <?php echo htmlspecialchars($geracao['status']); ?>
                                </span>
                            </td>
                            <td>
                            <?php if (!empty($geracao['arquivo_key']) || !empty($geracao['arquivo_url'])): ?>
                                <a href="index.php?page=cartao_ofx_me_historico&download=<?php echo (int)$geracao['id']; ?>">Baixar</a>
                            <?php endif; ?>
                                <a href="index.php?page=cartao_ofx_me_historico&view=<?php echo (int)$geracao['id']; ?>">Previa</a>
                                <form method="post" style="display:inline-block;margin-left:0.5rem;">
                                    <input type="hidden" name="action" value="excluir">
                                    <input type="hidden" name="id" value="<?php echo (int)$geracao['id']; ?>">
                                    <button class="ofx-button" type="submit" style="background:#ef4444;">Excluir</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php
$conteudo = ob_get_clean();
includeSidebar('Administrativo');
echo $conteudo;
endSidebar();
?>
