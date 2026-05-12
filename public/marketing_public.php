<?php
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';

header('Cache-Control: public, max-age=300, stale-while-revalidate=600');

if (!$pdo instanceof PDO) {
    http_response_code(500);
    echo 'Conexao com o banco indisponivel.';
    exit;
}

$stmt = $pdo->query("
    SELECT ma.*, COALESCE(u.nome, 'Equipe Smile') AS uploaded_by_name
    FROM marketing_arquivos ma
    LEFT JOIN usuarios u ON u.id = ma.uploaded_by_user_id
    WHERE ma.deleted_at IS NULL
    ORDER BY ma.uploaded_at DESC, ma.id DESC
");
$arquivos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$totalArquivos = count($arquivos);
$totalImagens = 0;
$totalVideos = 0;

foreach ($arquivos as $arquivo) {
    $tipo = trim((string)($arquivo['media_kind'] ?? ''));
    if ($tipo === 'imagem') {
        $totalImagens++;
    } elseif ($tipo === 'video') {
        $totalVideos++;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Marketing Smile</title>
    <style>
        :root {
            --brand-1: #0f2d75;
            --brand-2: #1e3a8a;
            --brand-3: #3b82f6;
            --brand-accent: #2dd4bf;
            --ink: #0f172a;
            --muted: #64748b;
            --line: #dbe3ef;
            --card: #ffffff;
            --bg: #f8fbff;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: "Manrope", "Segoe UI", sans-serif;
            color: var(--ink);
            background:
                radial-gradient(1000px 500px at 10% -10%, rgba(45, 212, 191, 0.12), transparent 50%),
                radial-gradient(900px 480px at 100% 0%, rgba(59, 130, 246, 0.14), transparent 55%),
                linear-gradient(180deg, var(--bg) 0%, #fff 100%);
            min-height: 100vh;
        }

        .hero,
        .shell {
            max-width: 1320px;
            margin: 0 auto;
            padding-left: 16px;
            padding-right: 16px;
        }

        .hero {
            padding-top: 28px;
            padding-bottom: 16px;
        }

        .hero-card {
            border-radius: 24px;
            padding: 28px;
            color: #fff;
            background:
                radial-gradient(circle at top right, rgba(255,255,255,0.16), transparent 28%),
                linear-gradient(135deg, var(--brand-1) 0%, var(--brand-2) 72%, #224ec7 100%);
            box-shadow: 0 28px 60px rgba(15, 45, 117, 0.25);
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(255,255,255,0.12);
            font-size: .82rem;
            font-weight: 800;
            letter-spacing: .05em;
            text-transform: uppercase;
        }

        .hero h1 {
            margin: 14px 0 10px;
            font-size: clamp(2rem, 4vw, 3.4rem);
            line-height: 1;
        }

        .hero p {
            margin: 0;
            max-width: 720px;
            font-size: 1rem;
            line-height: 1.7;
            color: rgba(255,255,255,.9);
        }

        .hero-stats {
            margin-top: 18px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .hero-stats span {
            display: inline-flex;
            align-items: center;
            padding: 10px 14px;
            border-radius: 999px;
            background: rgba(255,255,255,0.12);
            font-weight: 700;
        }

        .shell {
            padding-top: 4px;
            padding-bottom: 28px;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 18px;
        }

        .empty {
            border: 1px dashed #c6d6ec;
            border-radius: 22px;
            padding: 42px 18px;
            text-align: center;
            color: var(--muted);
            background: rgba(255,255,255,0.85);
        }

        .card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 22px;
            overflow: hidden;
            box-shadow: 0 16px 35px rgba(15, 23, 42, 0.06);
        }

        .preview {
            min-height: 240px;
            background: linear-gradient(135deg, #eff6ff 0%, #f8fafc 100%);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .preview img,
        .preview video {
            width: 100%;
            max-height: 380px;
            object-fit: cover;
            display: block;
            background: #000;
        }

        .body {
            padding: 16px;
        }

        .topline {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            align-items: flex-start;
        }

        .name {
            margin: 0;
            font-size: 1rem;
            color: var(--ink);
            word-break: break-word;
        }

        .tag {
            display: inline-flex;
            padding: 7px 10px;
            border-radius: 999px;
            font-size: .78rem;
            font-weight: 800;
            background: #dbeafe;
            color: var(--brand-2);
            white-space: nowrap;
        }

        .meta {
            margin-top: 10px;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            color: var(--muted);
            font-size: .9rem;
        }

        .desc {
            margin: 12px 0 0;
            color: #475569;
            line-height: 1.65;
            white-space: pre-wrap;
        }

        .actions {
            margin-top: 14px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 14px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 800;
            border: 1px solid #c6d6ec;
            color: var(--brand-1);
            background: #fff;
        }

        .btn:hover {
            background: #f8fbff;
        }
    </style>
</head>
<body>
    <header class="hero">
        <div class="hero-card">
            <span class="eyebrow">Marketing Smile</span>
            <h1>Galeria publica de materiais</h1>
            <p>Imagens e videos disponibilizados para consulta e uso do time de marketing.</p>
            <div class="hero-stats">
                <span><?= (int)$totalArquivos ?> arquivos</span>
                <span><?= (int)$totalImagens ?> imagens</span>
                <span><?= (int)$totalVideos ?> videos</span>
            </div>
        </div>
    </header>

    <main class="shell">
        <?php if (empty($arquivos)): ?>
        <div class="empty">Nenhum material foi publicado ainda.</div>
        <?php else: ?>
        <section class="grid">
            <?php foreach ($arquivos as $arquivo): ?>
                <?php
                $arquivoUrl = trim((string)($arquivo['public_url'] ?? ''));
                $mimeType = trim((string)($arquivo['mime_type'] ?? ''));
                $mediaKind = trim((string)($arquivo['media_kind'] ?? ''));
                ?>
                <article class="card">
                    <div class="preview">
                        <?php if ($mediaKind === 'imagem' && $arquivoUrl !== ''): ?>
                        <img src="<?= h($arquivoUrl) ?>" alt="<?= h($arquivo['original_name'] ?? 'Imagem') ?>" loading="lazy">
                        <?php elseif ($mediaKind === 'video' && $arquivoUrl !== ''): ?>
                        <video controls preload="metadata">
                            <source src="<?= h($arquivoUrl) ?>" type="<?= h($mimeType) ?>">
                        </video>
                        <?php else: ?>
                        <div class="empty">Preview indisponivel</div>
                        <?php endif; ?>
                    </div>

                    <div class="body">
                        <div class="topline">
                            <h2 class="name"><?= h($arquivo['original_name'] ?? 'Arquivo') ?></h2>
                            <span class="tag"><?= h(ucfirst($mediaKind)) ?></span>
                        </div>

                        <div class="meta">
                            <span><?= h(brDate((string)($arquivo['uploaded_at'] ?? ''))) ?></span>
                            <span>por <?= h($arquivo['uploaded_by_name'] ?? 'Equipe Smile') ?></span>
                        </div>

                        <?php if (trim((string)($arquivo['descricao'] ?? '')) !== ''): ?>
                        <p class="desc"><?= h((string)$arquivo['descricao']) ?></p>
                        <?php endif; ?>

                        <?php if ($arquivoUrl !== ''): ?>
                        <div class="actions">
                            <a class="btn" href="<?= h($arquivoUrl) ?>" target="_blank" rel="noopener noreferrer">Abrir arquivo</a>
                        </div>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>
        <?php endif; ?>
    </main>
</body>
</html>
