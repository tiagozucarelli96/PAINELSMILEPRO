<?php
/**
 * eventos_galeria_public.php
 * Galeria pública (somente visualização) para clientes.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';

$categorias = [
    'infantil' => ['symbol' => 'IN', 'label' => 'Infantil'],
    'casamento' => ['symbol' => 'CS', 'label' => 'Casamento'],
    '15_anos' => ['symbol' => '15', 'label' => '15 anos'],
    'geral' => ['symbol' => 'GE', 'label' => 'Geral']
];
$categorias_filtro = [
    'infantil' => $categorias['infantil'],
    'casamento' => $categorias['casamento'],
    '15_anos' => $categorias['15_anos']
];

$categoria_filter = $_GET['categoria'] ?? '';
$search = trim((string)($_GET['search'] ?? ''));

$where = ["deleted_at IS NULL"];
$params = [];

if ($categoria_filter !== '' && isset($categorias[$categoria_filter])) {
    $where[] = "categoria = :categoria";
    $params[':categoria'] = $categoria_filter;
}

if ($search !== '') {
    $where[] = "(nome ILIKE :search OR COALESCE(tags, '') ILIKE :search OR COALESCE(descricao, '') ILIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

$where_sql = implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT id, categoria, nome, descricao, tags, transform_css, public_url
    FROM eventos_galeria
    WHERE {$where_sql}
    ORDER BY uploaded_at DESC
    LIMIT 300
");
$stmt->execute($params);
$imagens = $stmt->fetchAll(PDO::FETCH_ASSOC);

$contadores = [];
$stmt = $pdo->query("
    SELECT categoria, COUNT(*) AS total
    FROM eventos_galeria
    WHERE deleted_at IS NULL
    GROUP BY categoria
");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $contadores[$row['categoria']] = (int)$row['total'];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Galeria Smile</title>
    <style>
        :root {
            --brand-blue: #0f2d75;
            --brand-blue-2: #1e3a8a;
            --brand-accent: #2dd4bf;
            --ink: #0f172a;
            --muted: #64748b;
            --card: #ffffff;
            --line: #dbe3ef;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: "Manrope", "Segoe UI", sans-serif;
            color: var(--ink);
            background:
                radial-gradient(1200px 600px at 8% -10%, rgba(45, 212, 191, 0.12), transparent 50%),
                radial-gradient(1200px 600px at 95% 0%, rgba(59, 130, 246, 0.14), transparent 55%),
                linear-gradient(180deg, #f8fbff 0%, #eef4ff 100%);
            min-height: 100vh;
        }

        .hero {
            max-width: 1320px;
            margin: 0 auto;
            padding: 28px 16px 10px;
        }

        .hero-card {
            background: linear-gradient(135deg, var(--brand-blue) 0%, var(--brand-blue-2) 70%, #224ec7 100%);
            border-radius: 20px;
            padding: 20px;
            color: #fff;
            position: relative;
            overflow: hidden;
            box-shadow: 0 20px 40px rgba(15, 45, 117, 0.25);
        }

        .hero-card::after {
            content: "";
            position: absolute;
            width: 320px;
            height: 320px;
            border-radius: 50%;
            background: rgba(45, 212, 191, 0.2);
            top: -180px;
            right: -120px;
        }

        .brand-line {
            display: flex;
            align-items: flex-start;
            gap: 16px;
        }

        .brand-line img {
            width: 92px;
            height: 92px;
            object-fit: contain;
            flex-shrink: 0;
        }

        .brand-text {
            display: flex;
            flex-direction: column;
            gap: 4px;
            padding-top: 6px;
        }

        .brand-text h1 {
            margin: 0;
            font-size: 2rem;
            font-weight: 800;
            letter-spacing: 0.2px;
        }

        .brand-text p {
            margin: 0;
            max-width: 760px;
            color: rgba(255, 255, 255, 0.9);
            font-size: 0.94rem;
            line-height: 1.5;
        }

        .shell {
            max-width: 1320px;
            margin: 0 auto;
            padding: 10px 16px 26px;
        }

        .toolbar {
            background: rgba(255, 255, 255, 0.78);
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 12px;
            backdrop-filter: blur(7px);
            margin-bottom: 14px;
        }

        .cats {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 10px;
        }

        .cat {
            border: 1px solid #c6d6ec;
            background: #fff;
            border-radius: 999px;
            padding: 7px 12px;
            text-decoration: none;
            color: var(--ink);
            font-size: 0.84rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            transition: all 0.2s ease;
        }

        .cat.active {
            background: var(--brand-blue-2);
            color: #fff;
            border-color: var(--brand-blue-2);
        }

        .cat:hover {
            border-color: #7ca7e8;
        }

        .cat-icon {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: #dbeafe;
            color: #1e3a8a;
            font-size: 0.63rem;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            letter-spacing: 0.02em;
        }

        .cat.active .cat-icon {
            background: rgba(255, 255, 255, 0.22);
            color: #fff;
        }

        .search-row {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .search-row input {
            flex: 1;
            min-width: 220px;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 0.9rem;
        }

        .search-row button {
            border: 1px solid var(--brand-blue-2);
            background: var(--brand-blue-2);
            color: #fff;
            border-radius: 10px;
            padding: 10px 14px;
            font-weight: 700;
            cursor: pointer;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 14px;
        }

        .card {
            border: 1px solid var(--line);
            border-radius: 14px;
            background: var(--card);
            overflow: hidden;
            cursor: pointer;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 16px 34px rgba(15, 23, 42, 0.1);
        }

        .thumb {
            aspect-ratio: 1/1;
            overflow: hidden;
            background: #e2e8f0;
        }

        .thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .card-body {
            padding: 10px 12px 12px;
        }

        .card-title {
            margin: 0 0 5px;
            font-size: 0.9rem;
            font-weight: 800;
            color: var(--ink);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .card-meta {
            margin: 0;
            color: var(--muted);
            font-size: 0.78rem;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .meta-icon {
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background: #e2e8f0;
            color: #1e3a8a;
            font-size: 0.56rem;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            letter-spacing: 0.02em;
        }

        .empty {
            border: 1px dashed #cbd5e1;
            border-radius: 14px;
            background: rgba(255, 255, 255, 0.7);
            text-align: center;
            padding: 40px 20px;
            color: var(--muted);
        }

        .empty-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 12px;
            border-radius: 14px;
            border: 2px solid #cbd5e1;
            background: linear-gradient(160deg, #f8fafc 0%, #e2e8f0 100%);
            color: #334155;
            font-size: 0.74rem;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal {
            position: fixed;
            inset: 0;
            background: rgba(2, 6, 23, 0.8);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            padding: 16px;
        }

        .modal.show {
            display: flex;
        }

        .modal-inner {
            width: min(980px, 100%);
            max-height: calc(100vh - 32px);
            overflow: auto;
            border-radius: 16px;
            background: #fff;
        }

        .modal-header {
            padding: 12px 14px;
            border-bottom: 1px solid var(--line);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 1rem;
            color: #1e293b;
        }

        .modal-close {
            border: none;
            background: transparent;
            font-size: 24px;
            color: #64748b;
            cursor: pointer;
        }

        .modal-body {
            padding: 14px;
        }

        .modal-img-wrap {
            border-radius: 12px;
            overflow: hidden;
            background: #020617;
            text-align: center;
        }

        .modal-img-wrap img {
            max-width: 100%;
            max-height: 72vh;
            object-fit: contain;
        }

        .modal-desc {
            margin-top: 10px;
            color: #334155;
            font-size: 0.92rem;
        }

        @media (max-width: 768px) {
            .hero {
                padding-top: 14px;
            }
            .hero-card {
                border-radius: 16px;
                padding: 16px;
            }
            .brand-line img {
                width: 76px;
                height: 76px;
            }
            .brand-text {
                padding-top: 2px;
            }
            .brand-text h1 {
                font-size: 1.55rem;
            }
        }
    </style>
</head>
<body>
    <section class="hero">
        <div class="hero-card">
            <div class="brand-line">
                <img src="logo.png" alt="Grupo Smile">
                <div class="brand-text">
                    <h1>Galeria Smile</h1>
                    <p>Inspire-se com referências de decoração e ambientação dos eventos.</p>
                </div>
            </div>
        </div>
    </section>

    <main class="shell">
        <div class="toolbar">
            <div class="cats">
                <a href="?page=eventos_galeria_public" class="cat <?= $categoria_filter === '' ? 'active' : '' ?>">
                    <span class="cat-icon">TD</span>
                    Todas (<?= array_sum($contadores) ?>)
                </a>
                <?php foreach ($categorias_filtro as $key => $cat): ?>
                    <a href="?page=eventos_galeria_public&categoria=<?= urlencode($key) ?>" class="cat <?= $categoria_filter === $key ? 'active' : '' ?>">
                        <span class="cat-icon"><?= htmlspecialchars($cat['symbol']) ?></span>
                        <?= htmlspecialchars($cat['label']) ?> (<?= (int)($contadores[$key] ?? 0) ?>)
                    </a>
                <?php endforeach; ?>
            </div>

            <form class="search-row" method="GET">
                <input type="hidden" name="page" value="eventos_galeria_public">
                <?php if ($categoria_filter !== ''): ?>
                    <input type="hidden" name="categoria" value="<?= htmlspecialchars($categoria_filter) ?>">
                <?php endif; ?>
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Buscar por tema, nome ou descrição...">
                <button type="submit">Buscar</button>
            </form>
        </div>

        <?php if (empty($imagens)): ?>
            <div class="empty">
                <div class="empty-icon" aria-hidden="true">IMG</div>
                <p style="margin:0 0 4px;"><strong>Nenhuma imagem disponível</strong></p>
                <p style="margin:0;">Tente outro filtro ou volte mais tarde.</p>
            </div>
        <?php else: ?>
            <div class="grid">
                <?php foreach ($imagens as $img): ?>
                    <?php
                    $img_id = (int)$img['id'];
                    $img_categoria = (string)($img['categoria'] ?? '');
                    $img_name = trim((string)($img['nome'] ?? ''));
                    if ($img_categoria === 'infantil') {
                        $img_name = 'Infantil';
                    } elseif ($img_categoria === '15_anos') {
                        $img_name = '15 anos';
                    } elseif ($img_name === '') {
                        $img_name = (string)($categorias[$img_categoria]['label'] ?? 'Imagem');
                    }
                    $img_desc = (string)($img['descricao'] ?? '');
                    $img_fallback_src = 'eventos_galeria_public_imagem.php?id=' . $img_id;
                    $img_public_url = trim((string)($img['public_url'] ?? ''));
                    $img_src = $img_public_url !== '' ? $img_public_url : $img_fallback_src;
                    $img_transform = (string)($img['transform_css'] ?? '');
                    ?>
                    <article class="card"
                             data-src="<?= htmlspecialchars($img_src) ?>"
                             data-name="<?= htmlspecialchars($img_name) ?>"
                             data-desc="<?= htmlspecialchars($img_desc) ?>">
                        <div class="thumb">
                            <img src="<?= htmlspecialchars($img_src) ?>"
                                 alt="<?= htmlspecialchars($img_name) ?>"
                                 loading="lazy"
                                 decoding="async"
                                 data-fallback-src="<?= htmlspecialchars($img_fallback_src) ?>"
                                 onerror="if (this.dataset.fallbackSrc && this.src !== this.dataset.fallbackSrc) { this.src = this.dataset.fallbackSrc; this.onerror = null; }"
                                 style="<?= $img_transform !== '' ? 'transform:' . htmlspecialchars($img_transform) . ';' : '' ?>">
                        </div>
                        <div class="card-body">
                            <h3 class="card-title"><?= htmlspecialchars($img_name) ?></h3>
                            <p class="card-meta">
                                <span class="meta-icon"><?= htmlspecialchars($categorias[$img_categoria]['symbol'] ?? 'TD') ?></span>
                                <?= htmlspecialchars($categorias[$img_categoria]['label'] ?? $img_categoria) ?>
                            </p>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>

    <div class="modal" id="previewModal">
        <div class="modal-inner">
            <div class="modal-header">
                <h3 id="previewTitle">Imagem</h3>
                <button class="modal-close" type="button" onclick="closePreview()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="modal-img-wrap">
                    <img id="previewImg" src="" alt="Preview">
                </div>
                <div class="modal-desc" id="previewDesc"></div>
            </div>
        </div>
    </div>

    <script>
        const modal = document.getElementById('previewModal');
        const titleEl = document.getElementById('previewTitle');
        const imgEl = document.getElementById('previewImg');
        const descEl = document.getElementById('previewDesc');

        function openPreview(src, name, desc) {
            imgEl.src = src || '';
            imgEl.alt = name || 'Imagem';
            titleEl.textContent = name || 'Imagem';
            descEl.textContent = desc || '';
            modal.classList.add('show');
        }

        function closePreview() {
            modal.classList.remove('show');
        }

        document.querySelectorAll('.card').forEach((card) => {
            card.addEventListener('click', () => {
                openPreview(
                    card.getAttribute('data-src') || '',
                    card.getAttribute('data-name') || '',
                    card.getAttribute('data-desc') || ''
                );
            });
        });

        modal.addEventListener('click', (event) => {
            if (event.target === modal) {
                closePreview();
            }
        });
    </script>
</body>
</html>
