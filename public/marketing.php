<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/upload_magalu.php';

error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
@ini_set('display_errors', '0');

if (!$pdo instanceof PDO) {
    http_response_code(500);
    echo 'Conexao com o banco indisponivel.';
    exit;
}

$usuarioId = (int)($_SESSION['id'] ?? $_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? 0);
$podeAcessarMarketing = !empty($_SESSION['perm_marketing']) || !empty($_SESSION['perm_superadmin']);

if ($usuarioId <= 0) {
    header('Location: login.php');
    exit;
}

if (!$podeAcessarMarketing) {
    http_response_code(403);
    echo 'Acesso negado ao modulo Marketing.';
    exit;
}

$marketingPublicUrl = 'https://painelpro.smileeventos.com.br/?page=marketing_public';
$uploadPrefix = 'marketing/arquivos';
$feedbackOk = '';
$feedbackErro = '';

if (!function_exists('marketingUploadErrorMessage')) {
    function marketingUploadErrorMessage(int $code): string
    {
        switch ($code) {
            case UPLOAD_ERR_OK:
                return '';
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return 'Arquivo excede o limite permitido pelo servidor.';
            case UPLOAD_ERR_PARTIAL:
                return 'Upload incompleto. Tente novamente.';
            case UPLOAD_ERR_NO_FILE:
                return 'Selecione um arquivo para enviar.';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'Pasta temporaria de upload indisponivel.';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Falha ao gravar o arquivo temporario.';
            case UPLOAD_ERR_EXTENSION:
                return 'Upload bloqueado por extensao do servidor.';
            default:
                return 'Erro desconhecido no upload.';
        }
    }
}

if (!function_exists('marketingEnsureArquivosTable')) {
    function marketingEnsureArquivosTable(PDO $pdo): void
    {
        static $ready = false;
        if ($ready) {
            return;
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS marketing_arquivos (
                id BIGSERIAL PRIMARY KEY,
                original_name VARCHAR(255) NOT NULL,
                mime_type VARCHAR(120) NOT NULL,
                size_bytes BIGINT NOT NULL DEFAULT 0,
                storage_key VARCHAR(500) NOT NULL,
                public_url TEXT NOT NULL,
                descricao TEXT NULL,
                media_kind VARCHAR(20) NOT NULL CHECK (media_kind IN ('imagem', 'video')),
                uploaded_by_user_id INTEGER NULL,
                uploaded_at TIMESTAMP NOT NULL DEFAULT NOW(),
                deleted_at TIMESTAMP NULL,
                deleted_by_user_id INTEGER NULL
            )
        ");

        $pdo->exec("
            CREATE INDEX IF NOT EXISTS idx_marketing_arquivos_ativos
            ON marketing_arquivos (deleted_at, uploaded_at DESC)
        ");

        $pdo->exec("
            CREATE INDEX IF NOT EXISTS idx_marketing_arquivos_tipo
            ON marketing_arquivos (media_kind, deleted_at, uploaded_at DESC)
        ");

        $ready = true;
    }
}

if (!function_exists('marketingDetectMediaKind')) {
    function marketingDetectMediaKind(string $mimeType): ?string
    {
        $mimeType = strtolower(trim($mimeType));
        if (strpos($mimeType, 'image/') === 0) {
            return 'imagem';
        }
        if (strpos($mimeType, 'video/') === 0) {
            return 'video';
        }

        return null;
    }
}

if (!function_exists('marketingFormatBytes')) {
    function marketingFormatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = $bytes / 1024;
        $unitIndex = 0;

        while ($value >= 1024 && $unitIndex < count($units) - 1) {
            $value /= 1024;
            $unitIndex++;
        }

        return number_format($value, $value >= 10 ? 1 : 2, ',', '.') . ' ' . $units[$unitIndex];
    }
}

if (!function_exists('marketingParseIniSizeBytes')) {
    function marketingParseIniSizeBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }

        $unit = strtolower(substr($value, -1));
        $number = (float)$value;

        switch ($unit) {
            case 'g':
                return (int)round($number * 1024 * 1024 * 1024);
            case 'm':
                return (int)round($number * 1024 * 1024);
            case 'k':
                return (int)round($number * 1024);
            default:
                return (int)round((float)$value);
        }
    }
}

if (!function_exists('marketingListArquivos')) {
    function marketingListArquivos(PDO $pdo): array
    {
        $stmt = $pdo->query("
            SELECT ma.*, COALESCE(u.nome, 'Usuario removido') AS uploaded_by_name
            FROM marketing_arquivos ma
            LEFT JOIN usuarios u ON u.id = ma.uploaded_by_user_id
            WHERE ma.deleted_at IS NULL
            ORDER BY ma.uploaded_at DESC, ma.id DESC
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('marketingNormalizeUploadedFiles')) {
    function marketingNormalizeUploadedFiles(?array $fileBag): array
    {
        if (!$fileBag || !isset($fileBag['name'])) {
            return [];
        }

        if (!is_array($fileBag['name'])) {
            return [$fileBag];
        }

        $files = [];
        $total = count($fileBag['name']);
        for ($i = 0; $i < $total; $i++) {
            $files[] = [
                'name' => $fileBag['name'][$i] ?? '',
                'type' => $fileBag['type'][$i] ?? '',
                'tmp_name' => $fileBag['tmp_name'][$i] ?? '',
                'error' => $fileBag['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => $fileBag['size'][$i] ?? 0,
            ];
        }

        return $files;
    }
}

marketingEnsureArquivosTable($pdo);

$requestContentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
$postMaxSizeBytes = marketingParseIniSizeBytes((string)ini_get('post_max_size'));
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $requestContentLength > 0 && empty($_POST) && empty($_FILES) && $postMaxSizeBytes > 0 && $requestContentLength > $postMaxSizeBytes) {
    $feedbackErro = 'O lote selecionado passou do limite total de upload do servidor (' . marketingFormatBytes($postMaxSizeBytes) . '). Envie menos arquivos por vez ou reduza o tamanho total.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'upload_arquivo') {
        $arquivosUpload = marketingNormalizeUploadedFiles($_FILES['arquivo'] ?? null);
        $descricao = trim((string)($_POST['descricao'] ?? ''));

        if (empty($arquivosUpload)) {
            $feedbackErro = 'Selecione pelo menos um arquivo para enviar.';
        } else {
            $enviadosComSucesso = 0;
            $falhas = [];

            try {
                $uploader = new MagaluUpload(500);
                $stmt = $pdo->prepare("
                    INSERT INTO marketing_arquivos (
                        original_name,
                        mime_type,
                        size_bytes,
                        storage_key,
                        public_url,
                        descricao,
                        media_kind,
                        uploaded_by_user_id,
                        uploaded_at
                    ) VALUES (
                        :original_name,
                        :mime_type,
                        :size_bytes,
                        :storage_key,
                        :public_url,
                        :descricao,
                        :media_kind,
                        :uploaded_by_user_id,
                        NOW()
                    )
                ");

                foreach ($arquivosUpload as $arquivo) {
                    $nomeArquivo = trim((string)($arquivo['name'] ?? 'arquivo'));
                    $uploadError = (int)($arquivo['error'] ?? UPLOAD_ERR_NO_FILE);

                    if ($uploadError !== UPLOAD_ERR_OK) {
                        $falhas[] = $nomeArquivo . ': ' . marketingUploadErrorMessage($uploadError);
                        continue;
                    }

                    if ((int)($arquivo['size'] ?? 0) > 500 * 1024 * 1024) {
                        $falhas[] = $nomeArquivo . ': arquivo muito grande. Limite maximo: 500MB.';
                        continue;
                    }

                    $mimeTypeDetectado = (string)(mime_content_type((string)($arquivo['tmp_name'] ?? '')) ?: '');
                    $mediaKind = marketingDetectMediaKind($mimeTypeDetectado);
                    if ($mediaKind === null) {
                        $falhas[] = $nomeArquivo . ': somente imagens e videos sao aceitos.';
                        continue;
                    }

                    try {
                        $uploadResult = $uploader->upload($arquivo, $uploadPrefix);
                        $stmt->execute([
                            ':original_name' => (string)($uploadResult['nome_original'] ?? $nomeArquivo),
                            ':mime_type' => (string)($uploadResult['mime_type'] ?? ''),
                            ':size_bytes' => (int)($uploadResult['tamanho_bytes'] ?? ($arquivo['size'] ?? 0)),
                            ':storage_key' => (string)($uploadResult['chave_storage'] ?? ''),
                            ':public_url' => (string)($uploadResult['url'] ?? ''),
                            ':descricao' => $descricao !== '' ? $descricao : null,
                            ':media_kind' => $mediaKind,
                            ':uploaded_by_user_id' => $usuarioId > 0 ? $usuarioId : null,
                        ]);
                        $enviadosComSucesso++;
                    } catch (Throwable $e) {
                        error_log('marketing upload arquivo: ' . $e->getMessage());
                        $falhas[] = $nomeArquivo . ': falha ao enviar.';
                    }
                }

                if ($enviadosComSucesso > 0) {
                    $feedbackOk = $enviadosComSucesso === 1
                        ? '1 arquivo enviado com sucesso.'
                        : $enviadosComSucesso . ' arquivos enviados com sucesso.';
                }

                if (!empty($falhas)) {
                    $feedbackErro = implode(' ', $falhas);
                }

                if ($enviadosComSucesso === 0 && $feedbackErro === '') {
                    $feedbackErro = 'Nenhum arquivo foi enviado.';
                }
            } catch (Throwable $e) {
                error_log('marketing upload lote: ' . $e->getMessage());
                $feedbackErro = 'Falha ao preparar o envio dos arquivos.';
            }
        }
    } elseif ($acao === 'excluir_arquivo') {
        $arquivoId = (int)($_POST['arquivo_id'] ?? 0);

        if ($arquivoId <= 0) {
            $feedbackErro = 'Arquivo invalido para exclusao.';
        } else {
            $stmt = $pdo->prepare("
                UPDATE marketing_arquivos
                SET deleted_at = NOW(),
                    deleted_by_user_id = :deleted_by_user_id
                WHERE id = :id
                  AND deleted_at IS NULL
            ");
            $stmt->execute([
                ':deleted_by_user_id' => $usuarioId,
                ':id' => $arquivoId,
            ]);

            if ($stmt->rowCount() > 0) {
                $feedbackOk = 'Arquivo removido com sucesso.';
            } else {
                $feedbackErro = 'Arquivo nao encontrado ou ja removido.';
            }
        }
    }
}

$arquivos = marketingListArquivos($pdo);
$totalArquivos = count($arquivos);
$totalImagens = 0;
$totalVideos = 0;
$totalBytes = 0;

foreach ($arquivos as $arquivoItem) {
    $tipo = (string)($arquivoItem['media_kind'] ?? '');
    if ($tipo === 'imagem') {
        $totalImagens++;
    } elseif ($tipo === 'video') {
        $totalVideos++;
    }
    $totalBytes += (int)($arquivoItem['size_bytes'] ?? 0);
}

ob_start();
?>

<style>
.marketing-page {
    width: 100%;
    max-width: 1380px;
    margin: 0 auto;
    padding: 1.5rem;
}

.marketing-hero {
    display: grid;
    grid-template-columns: minmax(0, 1.35fr) minmax(320px, 0.9fr);
    gap: 1.25rem;
    margin-bottom: 1.5rem;
}

.marketing-panel {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 22px;
    box-shadow: 0 18px 45px rgba(15, 23, 42, 0.07);
}

.marketing-panel.primary {
    padding: 1.8rem;
    background:
        radial-gradient(circle at top right, rgba(45, 212, 191, 0.12), transparent 28%),
        linear-gradient(135deg, #ffffff 0%, #f8fbff 100%);
}

.marketing-badge {
    display: inline-flex;
    align-items: center;
    gap: .45rem;
    padding: .45rem .8rem;
    border-radius: 999px;
    background: rgba(30, 58, 138, 0.1);
    color: #1e3a8a;
    font-size: .9rem;
    font-weight: 700;
    letter-spacing: .02em;
}

.marketing-title {
    margin: 1rem 0 .65rem;
    font-size: 2rem;
    line-height: 1.1;
    color: #0f172a;
}

.marketing-subtitle {
    margin: 0;
    color: #475569;
    font-size: 1rem;
    line-height: 1.7;
    max-width: 62ch;
}

.marketing-link-box {
    margin-top: 1.4rem;
    display: flex;
    flex-wrap: wrap;
    gap: .75rem;
    align-items: center;
}

.marketing-link-pill {
    display: inline-flex;
    align-items: center;
    gap: .65rem;
    padding: .9rem 1rem;
    border-radius: 14px;
    background: #0f172a;
    color: #fff;
    text-decoration: none;
    font-weight: 700;
    word-break: break-all;
}

.marketing-link-pill:hover {
    background: #1e293b;
}

.marketing-link-copy {
    border: 1px solid #cbd5e1;
    border-radius: 12px;
    padding: .9rem 1rem;
    background: #fff;
    color: #0f172a;
    font-weight: 600;
}

.marketing-stats {
    padding: 1.5rem;
    display: grid;
    gap: .9rem;
    background:
        linear-gradient(180deg, #172554 0%, #0f172a 100%);
    color: #fff;
}

.marketing-stats h2 {
    margin: 0;
    font-size: 1.1rem;
}

.marketing-stat-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: .85rem;
}

.marketing-stat-card {
    padding: 1rem;
    border-radius: 16px;
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid rgba(255, 255, 255, 0.08);
}

.marketing-stat-card strong {
    display: block;
    font-size: 1.5rem;
    margin-bottom: .25rem;
}

.marketing-stat-card span {
    color: rgba(255, 255, 255, 0.78);
    font-size: .92rem;
}

.marketing-grid {
    display: grid;
    grid-template-columns: minmax(320px, 420px) minmax(0, 1fr);
    gap: 1.25rem;
}

.marketing-upload-card {
    padding: 1.4rem;
}

.marketing-upload-card h2,
.marketing-library-card h2 {
    margin: 0 0 1rem;
    color: #0f172a;
    font-size: 1.2rem;
}

.marketing-note {
    margin: -.35rem 0 1rem;
    color: #64748b;
    font-size: .95rem;
    line-height: 1.6;
}

.marketing-alert {
    border-radius: 14px;
    padding: .95rem 1rem;
    margin-bottom: 1rem;
    font-weight: 600;
}

.marketing-alert.ok {
    background: #ecfdf5;
    color: #166534;
    border: 1px solid #bbf7d0;
}

.marketing-alert.error {
    background: #fef2f2;
    color: #991b1b;
    border: 1px solid #fecaca;
}

.marketing-form {
    display: grid;
    gap: 1rem;
}

.marketing-field {
    display: grid;
    gap: .45rem;
}

.marketing-field label {
    color: #334155;
    font-weight: 700;
    font-size: .92rem;
}

.marketing-field input[type="file"],
.marketing-field textarea {
    width: 100%;
    border: 1px solid #cbd5e1;
    border-radius: 12px;
    padding: .85rem 1rem;
    background: #fff;
    font: inherit;
}

.marketing-field textarea {
    min-height: 110px;
    resize: vertical;
}

.marketing-file-summary {
    color: #64748b;
    font-size: .9rem;
    line-height: 1.5;
}

.marketing-actions {
    display: flex;
    gap: .75rem;
    flex-wrap: wrap;
}

.marketing-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .5rem;
    border: none;
    border-radius: 12px;
    padding: .9rem 1.15rem;
    text-decoration: none;
    cursor: pointer;
    font: inherit;
    font-weight: 700;
}

.marketing-btn.primary {
    background: linear-gradient(135deg, #1e3a8a, #224ec7);
    color: #fff;
}

.marketing-btn.secondary {
    background: #fff;
    color: #0f172a;
    border: 1px solid #cbd5e1;
}

.marketing-library-card {
    padding: 1.4rem;
}

.marketing-library-header {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    align-items: flex-start;
    margin-bottom: 1rem;
}

.marketing-library-list {
    display: grid;
    gap: 1rem;
}

.marketing-empty {
    padding: 2.4rem 1rem;
    text-align: center;
    border: 1px dashed #cbd5e1;
    border-radius: 18px;
    color: #64748b;
    background: linear-gradient(180deg, #f8fafc 0%, #ffffff 100%);
}

.marketing-media-item {
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    overflow: hidden;
    background: #fff;
}

.marketing-media-preview {
    background:
        linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
    min-height: 220px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.marketing-media-preview img,
.marketing-media-preview video {
    display: block;
    width: 100%;
    max-height: 360px;
    object-fit: cover;
    background: #000;
}

.marketing-media-body {
    padding: 1rem;
    display: grid;
    gap: .8rem;
}

.marketing-media-top {
    display: flex;
    justify-content: space-between;
    gap: .75rem;
    align-items: flex-start;
}

.marketing-media-name {
    margin: 0;
    font-size: 1rem;
    color: #0f172a;
    word-break: break-word;
}

.marketing-media-meta {
    display: flex;
    flex-wrap: wrap;
    gap: .5rem;
    color: #64748b;
    font-size: .9rem;
}

.marketing-tag {
    display: inline-flex;
    align-items: center;
    padding: .38rem .7rem;
    border-radius: 999px;
    font-size: .8rem;
    font-weight: 700;
    background: #dbeafe;
    color: #1e3a8a;
}

.marketing-description {
    margin: 0;
    color: #475569;
    line-height: 1.6;
    white-space: pre-wrap;
}

.marketing-media-actions {
    display: flex;
    flex-wrap: wrap;
    gap: .65rem;
}

.marketing-delete-form {
    margin: 0;
}

@media (max-width: 1080px) {
    .marketing-hero,
    .marketing-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 640px) {
    .marketing-page {
        padding: 1rem;
    }

    .marketing-title {
        font-size: 1.6rem;
    }

    .marketing-stat-grid {
        grid-template-columns: 1fr 1fr;
    }
}
</style>

<div class="marketing-page">
    <section class="marketing-hero">
        <div class="marketing-panel primary">
            <span class="marketing-badge">Marketing</span>
            <h1 class="marketing-title">Link publico e biblioteca de midias</h1>
            <p class="marketing-subtitle">
                Esta area logada serve para subir imagens e videos que o time de marketing vai usar.
                O site publico fica disponivel no link abaixo.
            </p>

            <div class="marketing-link-box">
                <a class="marketing-link-pill" href="<?= h($marketingPublicUrl) ?>" target="_blank" rel="noopener noreferrer">
                    Abrir pagina publica
                </a>
                <div class="marketing-link-copy"><?= h($marketingPublicUrl) ?></div>
            </div>
        </div>

        <aside class="marketing-panel marketing-stats">
            <h2>Resumo da biblioteca</h2>
            <div class="marketing-stat-grid">
                <div class="marketing-stat-card">
                    <strong><?= (int)$totalArquivos ?></strong>
                    <span>Arquivos ativos</span>
                </div>
                <div class="marketing-stat-card">
                    <strong><?= (int)$totalImagens ?></strong>
                    <span>Imagens</span>
                </div>
                <div class="marketing-stat-card">
                    <strong><?= (int)$totalVideos ?></strong>
                    <span>Videos</span>
                </div>
                <div class="marketing-stat-card">
                    <strong><?= h(marketingFormatBytes($totalBytes)) ?></strong>
                    <span>Armazenado</span>
                </div>
            </div>
        </aside>
    </section>

    <section class="marketing-grid">
        <div class="marketing-panel marketing-upload-card">
            <h2>Anexar imagem ou video</h2>
            <p class="marketing-note">
                Envie somente arquivos de imagem ou video. Limite por arquivo: 500MB.
            </p>

            <?php if ($feedbackOk !== ''): ?>
            <div class="marketing-alert ok"><?= h($feedbackOk) ?></div>
            <?php endif; ?>

            <?php if ($feedbackErro !== ''): ?>
            <div class="marketing-alert error"><?= h($feedbackErro) ?></div>
            <?php endif; ?>

            <form class="marketing-form" method="post" enctype="multipart/form-data">
                <input type="hidden" name="acao" value="upload_arquivo">

                <div class="marketing-field">
                    <label for="arquivo">Arquivo</label>
                    <input id="arquivo" type="file" name="arquivo[]" accept="image/*,video/*" multiple required>
                    <div id="marketingFileSummary" class="marketing-file-summary">
                        Ao escolher arquivo, voce pode selecionar varios de uma vez.
                    </div>
                </div>

                <div class="marketing-field">
                    <label for="descricao">Descricao</label>
                    <textarea id="descricao" name="descricao" placeholder="Ex.: video institucional maio 2026, fotos da campanha de buffet, reels para instagram..."></textarea>
                </div>

                <div class="marketing-actions">
                    <button class="marketing-btn primary" type="submit">Enviar arquivo</button>
                    <a class="marketing-btn secondary" href="<?= h($marketingPublicUrl) ?>" target="_blank" rel="noopener noreferrer">Abrir pagina publica</a>
                </div>
            </form>
        </div>

        <div class="marketing-panel marketing-library-card">
            <div class="marketing-library-header">
                <div>
                    <h2>Arquivos enviados</h2>
                    <p class="marketing-note">Tudo que estiver aqui fica centralizado para o marketing acessar.</p>
                </div>
            </div>

            <?php if (empty($arquivos)): ?>
            <div class="marketing-empty">
                Nenhum arquivo enviado ainda. Use o formulario ao lado para montar a biblioteca.
            </div>
            <?php else: ?>
            <div class="marketing-library-list">
                <?php foreach ($arquivos as $arquivo): ?>
                    <?php
                    $arquivoUrl = trim((string)($arquivo['public_url'] ?? ''));
                    $mimeType = trim((string)($arquivo['mime_type'] ?? ''));
                    $mediaKind = trim((string)($arquivo['media_kind'] ?? ''));
                    ?>
                    <article class="marketing-media-item">
                        <div class="marketing-media-preview">
                            <?php if ($mediaKind === 'imagem' && $arquivoUrl !== ''): ?>
                            <img src="<?= h($arquivoUrl) ?>" alt="<?= h($arquivo['original_name'] ?? 'Imagem marketing') ?>" loading="lazy">
                            <?php elseif ($mediaKind === 'video' && $arquivoUrl !== ''): ?>
                            <video controls preload="metadata">
                                <source src="<?= h($arquivoUrl) ?>" type="<?= h($mimeType) ?>">
                                Seu navegador nao suporta video incorporado.
                            </video>
                            <?php else: ?>
                            <div class="marketing-empty">Preview indisponivel</div>
                            <?php endif; ?>
                        </div>

                        <div class="marketing-media-body">
                            <div class="marketing-media-top">
                                <div>
                                    <h3 class="marketing-media-name"><?= h($arquivo['original_name'] ?? 'Arquivo') ?></h3>
                                </div>
                                <span class="marketing-tag"><?= h(ucfirst($mediaKind)) ?></span>
                            </div>

                            <div class="marketing-media-meta">
                                <span><?= h(marketingFormatBytes((int)($arquivo['size_bytes'] ?? 0))) ?></span>
                                <span><?= h(brDate((string)($arquivo['uploaded_at'] ?? ''))) ?></span>
                                <span>Por <?= h($arquivo['uploaded_by_name'] ?? 'Usuario') ?></span>
                            </div>

                            <?php if (trim((string)($arquivo['descricao'] ?? '')) !== ''): ?>
                            <p class="marketing-description"><?= h((string)$arquivo['descricao']) ?></p>
                            <?php endif; ?>

                            <div class="marketing-media-actions">
                                <?php if ($arquivoUrl !== ''): ?>
                                <a class="marketing-btn secondary" href="<?= h($arquivoUrl) ?>" target="_blank" rel="noopener noreferrer">Abrir arquivo</a>
                                <a class="marketing-btn secondary" href="<?= h($arquivoUrl) ?>" target="_blank" rel="noopener noreferrer" download>Download</a>
                                <?php endif; ?>

                                <form class="marketing-delete-form" method="post" onsubmit="return confirm('Excluir este arquivo da biblioteca de marketing?');">
                                    <input type="hidden" name="acao" value="excluir_arquivo">
                                    <input type="hidden" name="arquivo_id" value="<?= (int)($arquivo['id'] ?? 0) ?>">
                                    <button class="marketing-btn primary" type="submit">Excluir</button>
                                </form>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<script>
(function() {
    const input = document.getElementById('arquivo');
    const summary = document.getElementById('marketingFileSummary');
    if (!input || !summary) {
        return;
    }

    input.addEventListener('change', function() {
        const files = Array.from(input.files || []);
        if (files.length === 0) {
            summary.textContent = 'Ao escolher arquivo, voce pode selecionar varios de uma vez.';
            return;
        }

        if (files.length === 1) {
            summary.textContent = '1 arquivo selecionado: ' + files[0].name;
            return;
        }

        summary.textContent = files.length + ' arquivos selecionados.';
    });
})();
</script>

<?php
error_reporting(E_ALL);
@ini_set('display_errors', '0');

$conteudo = ob_get_clean();

if (ob_get_level() > 0) {
    ob_end_clean();
}

includeSidebar('Marketing');
echo $conteudo;
endSidebar();
?>
