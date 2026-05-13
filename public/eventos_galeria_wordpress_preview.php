<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/eventos_galeria_helper.php';

if (empty($_SESSION['perm_comercial']) && empty($_SESSION['perm_superadmin'])) {
    header('Location: index.php?page=dashboard');
    exit;
}

$thumbColumns = eventosGaleriaThumbColumns($pdo);
$thumbSelect = $thumbColumns['thumb_public_url']
    ? ', thumb_public_url'
    : ", NULL::text AS thumb_public_url";

$stmt = $pdo->prepare("
    SELECT id, categoria, nome, descricao, public_url{$thumbSelect}
    FROM eventos_galeria
    WHERE deleted_at IS NULL
      AND COALESCE(public_url, '') <> ''
    ORDER BY uploaded_at DESC, id DESC
    LIMIT 6
");
$stmt->execute();
$imagens = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$placeholderCards = max(0, 6 - count($imagens));
$galeriaPublicUrl = 'index.php?page=eventos_galeria_public';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preview WordPress - Galeria Smile</title>
    <style>
        :root {
            --bg: #f4f1ea;
            --ink: #16130f;
            --muted: #6b6258;
            --card: #fffdf9;
            --line: rgba(22, 19, 15, 0.1);
            --accent: #b58852;
            --accent-dark: #8f6738;
            --shadow: 0 24px 80px rgba(40, 28, 16, 0.12);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: Georgia, "Times New Roman", serif;
            color: var(--ink);
            background:
                radial-gradient(900px 500px at 10% -10%, rgba(181, 136, 82, 0.22), transparent 55%),
                radial-gradient(700px 420px at 100% 0%, rgba(241, 224, 199, 0.85), transparent 58%),
                linear-gradient(180deg, #fbf7f0 0%, var(--bg) 100%);
        }

        .shell {
            max-width: 1240px;
            margin: 0 auto;
            padding: 32px 18px 48px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 22px;
        }

        .topbar a {
            color: var(--ink);
            text-decoration: none;
            font-size: 0.95rem;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.72);
            border: 1px solid rgba(22, 19, 15, 0.08);
            font-size: 0.82rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .frame {
            background: rgba(255, 253, 249, 0.84);
            border: 1px solid rgba(22, 19, 15, 0.08);
            border-radius: 28px;
            box-shadow: var(--shadow);
            overflow: hidden;
            backdrop-filter: blur(10px);
        }

        .hero {
            padding: 42px 32px 24px;
            border-bottom: 1px solid var(--line);
            background:
                linear-gradient(135deg, rgba(255,255,255,0.64) 0%, rgba(255,249,240,0.92) 100%);
        }

        .eyebrow {
            display: inline-block;
            margin-bottom: 14px;
            font-size: 0.8rem;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            color: var(--accent-dark);
        }

        h1 {
            margin: 0;
            max-width: 820px;
            font-size: clamp(2.4rem, 5vw, 4.6rem);
            line-height: 0.96;
            font-weight: 700;
        }

        .hero p {
            margin: 18px 0 0;
            max-width: 720px;
            font-family: "Helvetica Neue", Arial, sans-serif;
            font-size: 1.02rem;
            line-height: 1.75;
            color: var(--muted);
        }

        .section {
            padding: 28px 32px 36px;
        }

        .section-head {
            display: flex;
            justify-content: space-between;
            align-items: end;
            gap: 18px;
            margin-bottom: 22px;
        }

        .section-head h2 {
            margin: 0;
            font-size: 1.6rem;
            font-weight: 700;
        }

        .section-head p {
            margin: 8px 0 0;
            font-family: "Helvetica Neue", Arial, sans-serif;
            color: var(--muted);
            line-height: 1.6;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
        }

        .card-trigger {
            appearance: none;
            border: 0;
            padding: 0;
            margin: 0;
            width: 100%;
            background: transparent;
            cursor: pointer;
            text-align: left;
        }

        .card {
            position: relative;
            overflow: hidden;
            border-radius: 22px;
            background: #ede7de;
            box-shadow: 0 18px 44px rgba(38, 29, 20, 0.08);
        }

        .card::after {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(180deg, rgba(0,0,0,0) 45%, rgba(14,10,7,0.5) 100%);
            opacity: 0;
            transition: opacity 0.28s ease;
        }

        .card-trigger:hover .card::after,
        .card-trigger:focus-visible .card::after {
            opacity: 1;
        }

        .card img,
        .placeholder {
            display: block;
            width: 100%;
            aspect-ratio: 4 / 5;
            object-fit: cover;
        }

        .placeholder {
            background:
                linear-gradient(135deg, rgba(181, 136, 82, 0.18), rgba(255,255,255,0.7)),
                repeating-linear-gradient(
                    -45deg,
                    rgba(181, 136, 82, 0.08) 0,
                    rgba(181, 136, 82, 0.08) 12px,
                    rgba(255,255,255,0.22) 12px,
                    rgba(255,255,255,0.22) 24px
                );
        }

        .card-copy {
            position: absolute;
            inset: auto 0 0 0;
            padding: 18px;
            z-index: 1;
            color: #fff;
        }

        .card-copy strong {
            display: block;
            font-size: 1rem;
            font-weight: 700;
            font-family: "Helvetica Neue", Arial, sans-serif;
        }

        .card-copy span {
            display: block;
            margin-top: 6px;
            font-size: 0.85rem;
            opacity: 0.88;
            font-family: "Helvetica Neue", Arial, sans-serif;
        }

        .cta-wrap {
            display: flex;
            justify-content: center;
            margin-top: 28px;
        }

        .cta-button {
            appearance: none;
            border: 0;
            border-radius: 999px;
            padding: 16px 28px;
            background: linear-gradient(135deg, var(--accent) 0%, var(--accent-dark) 100%);
            color: #fff;
            cursor: pointer;
            font-size: 0.98rem;
            font-weight: 700;
            letter-spacing: 0.02em;
            box-shadow: 0 16px 34px rgba(143, 103, 56, 0.28);
        }

        .note {
            margin-top: 18px;
            text-align: center;
            color: var(--muted);
            font-family: "Helvetica Neue", Arial, sans-serif;
            font-size: 0.92rem;
        }

        .empty {
            border: 1px dashed rgba(22, 19, 15, 0.18);
            border-radius: 22px;
            padding: 28px;
            text-align: center;
            color: var(--muted);
            font-family: "Helvetica Neue", Arial, sans-serif;
            background: rgba(255,255,255,0.45);
        }

        .modal {
            position: fixed;
            inset: 0;
            z-index: 99999;
            display: none;
        }

        .modal.is-open {
            display: block;
        }

        .modal-backdrop {
            position: absolute;
            inset: 0;
            background: rgba(18, 14, 10, 0.76);
        }

        .modal-dialog {
            position: relative;
            width: min(1360px, calc(100% - 28px));
            height: min(90vh, 940px);
            margin: 4vh auto;
            border-radius: 24px;
            overflow: hidden;
            background: #fff;
            box-shadow: 0 32px 90px rgba(0,0,0,0.35);
        }

        .modal-dialog iframe {
            width: 100%;
            height: 100%;
            border: 0;
            display: block;
            background: #fff;
        }

        .modal-close {
            position: absolute;
            top: 12px;
            right: 12px;
            z-index: 2;
            width: 42px;
            height: 42px;
            border: 0;
            border-radius: 999px;
            background: rgba(0, 0, 0, 0.68);
            color: #fff;
            cursor: pointer;
            font-size: 1.9rem;
            line-height: 1;
        }

        @media (max-width: 980px) {
            .grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 680px) {
            .shell {
                padding-left: 12px;
                padding-right: 12px;
            }

            .topbar,
            .section-head {
                flex-direction: column;
                align-items: flex-start;
            }

            .hero,
            .section {
                padding-left: 20px;
                padding-right: 20px;
            }

            .grid {
                grid-template-columns: 1fr;
            }

            .modal-dialog {
                width: calc(100% - 12px);
                height: 94vh;
                margin: 2vh auto;
                border-radius: 16px;
            }
        }
    </style>
</head>
<body>
    <div class="shell">
        <div class="topbar">
            <a href="index.php?page=eventos_galeria">← Voltar para gestão da galeria</a>
            <span class="badge">Simulação da seção no WordPress</span>
        </div>

        <div class="frame">
            <div class="hero">
                <span class="eyebrow">Smile Eventos</span>
                <h1>Galeria pública com prévia elegante e abertura da coleção completa.</h1>
                <p>Esta página simula o bloco que pode ser embedado no site. Ela mostra apenas uma seleção de imagens e abre a galeria pública completa em um modal, sem tirar o visitante da experiência principal.</p>
            </div>

            <div class="section">
                <div class="section-head">
                    <div>
                        <h2>Galeria de Fotos</h2>
                        <p>Prévia enxuta para a página do site, com acesso imediato à coleção completa.</p>
                    </div>
                </div>

                <?php if (!empty($imagens)): ?>
                    <div class="grid">
                        <?php foreach ($imagens as $imagem): ?>
                            <?php
                            $previewUrl = trim((string)($imagem['thumb_public_url'] ?? ''));
                            if ($previewUrl === '') {
                                $previewUrl = trim((string)($imagem['public_url'] ?? ''));
                            }

                            $nome = trim((string)($imagem['nome'] ?? ''));
                            $descricao = trim((string)($imagem['descricao'] ?? ''));
                            $categoria = trim((string)($imagem['categoria'] ?? ''));
                            ?>
                            <button class="card-trigger js-open-gallery" type="button">
                                <div class="card">
                                    <img src="<?= htmlspecialchars($previewUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($nome !== '' ? $nome : 'Foto do evento', ENT_QUOTES, 'UTF-8') ?>">
                                    <div class="card-copy">
                                        <strong><?= htmlspecialchars($nome !== '' ? $nome : 'Evento Smile', ENT_QUOTES, 'UTF-8') ?></strong>
                                        <span><?= htmlspecialchars($descricao !== '' ? $descricao : ucfirst(str_replace('_', ' ', $categoria)), ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                </div>
                            </button>
                        <?php endforeach; ?>

                        <?php for ($i = 0; $i < $placeholderCards; $i++): ?>
                            <button class="card-trigger js-open-gallery" type="button" aria-label="Abrir galeria completa">
                                <div class="card">
                                    <div class="placeholder"></div>
                                    <div class="card-copy">
                                        <strong>Mais momentos</strong>
                                        <span>Abra a galeria completa para continuar vendo</span>
                                    </div>
                                </div>
                            </button>
                        <?php endfor; ?>
                    </div>
                <?php else: ?>
                    <div class="empty">
                        Ainda não existem imagens públicas suficientes para montar a prévia. Publique fotos na galeria e atualize este teste.
                    </div>
                <?php endif; ?>

                <div class="cta-wrap">
                    <button class="cta-button js-open-gallery" type="button">Ver galeria completa</button>
                </div>
                <div class="note">A coleção completa aberta no modal usa a rota pública da galeria, para reproduzir o comportamento esperado no site.</div>
            </div>
        </div>
    </div>

    <div class="modal" id="galleryModal" aria-hidden="true">
        <div class="modal-backdrop"></div>
        <div class="modal-dialog" role="dialog" aria-modal="true" aria-label="Galeria pública completa">
            <button class="modal-close" id="galleryModalClose" type="button" aria-label="Fechar">×</button>
            <iframe
                src="<?= htmlspecialchars($galeriaPublicUrl, ENT_QUOTES, 'UTF-8') ?>"
                title="Galeria Smile pública"
                loading="lazy">
            </iframe>
        </div>
    </div>

    <script>
        (function () {
            const modal = document.getElementById('galleryModal');
            const closeButton = document.getElementById('galleryModalClose');
            const backdrop = modal ? modal.querySelector('.modal-backdrop') : null;
            const triggers = document.querySelectorAll('.js-open-gallery');

            if (!modal || !closeButton || !backdrop || !triggers.length) {
                return;
            }

            function openModal() {
                modal.classList.add('is-open');
                modal.setAttribute('aria-hidden', 'false');
                document.body.style.overflow = 'hidden';
            }

            function closeModal() {
                modal.classList.remove('is-open');
                modal.setAttribute('aria-hidden', 'true');
                document.body.style.overflow = '';
            }

            triggers.forEach(function (trigger) {
                trigger.addEventListener('click', openModal);
            });

            closeButton.addEventListener('click', closeModal);
            backdrop.addEventListener('click', closeModal);

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && modal.classList.contains('is-open')) {
                    closeModal();
                }
            });
        })();
    </script>
</body>
</html>
