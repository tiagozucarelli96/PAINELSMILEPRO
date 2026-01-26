<?php
/**
 * vendas_links_publicos.php
 * Área interna para o time copiar links públicos de Vendas.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';

if (empty($_SESSION['logado']) || empty($_SESSION['perm_comercial'])) {
    header('Location: index.php?page=login');
    exit;
}

// Montar base URL com suporte a proxy (Railway)
$proto = 'http';
if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
    $proto = (string)$_SERVER['HTTP_X_FORWARDED_PROTO'];
} elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    $proto = 'https';
}

$host = (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost');
$scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php');
$prefix = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
if ($prefix === '/') $prefix = '';

$baseUrl = $proto . '://' . $host . $prefix;

$links = [
    [
        'label' => 'Casamento',
        'desc' => 'Formulário público (cliente preenche).',
        'url' => $baseUrl . '/index.php?page=vendas_form_casamento',
    ],
    [
        'label' => 'Infantil',
        'desc' => 'Formulário público (cliente preenche).',
        'url' => $baseUrl . '/index.php?page=vendas_form_infantil',
    ],
    [
        'label' => 'Pessoa Jurídica (PJ)',
        'desc' => 'Formulário público (cliente preenche).',
        'url' => $baseUrl . '/index.php?page=vendas_form_pj',
    ],
];

ob_start();
?>

<style>
.vendas-links-container{
    max-width: 1100px;
    margin: 0 auto;
    padding: 1.5rem;
}
.vendas-links-header{
    margin-bottom: 1.25rem;
}
.vendas-links-header h1{
    font-size: 1.75rem;
    color: #1e3a8a;
    margin-bottom: .35rem;
}
.vendas-links-header p{
    color: #64748b;
}
.vendas-links-card{
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.06);
    padding: 1rem;
    margin-bottom: 1rem;
}
.vendas-links-row{
    display:flex;
    gap:.75rem;
    flex-wrap: wrap;
    align-items: center;
}
.vendas-links-title{
    font-weight: 700;
    color: #1e293b;
    font-size: 1.05rem;
}
.vendas-links-desc{
    color:#64748b;
    font-size:.9rem;
    margin-top:.25rem;
}
.vendas-links-input{
    flex: 1;
    min-width: 320px;
    padding: .7rem .8rem;
    border: 1px solid #d1d5db;
    border-radius: 10px;
    background: #f8fafc;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
    font-size: .9rem;
}
.btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:.4rem;
    padding:.6rem .95rem;
    border-radius:10px;
    border:1px solid transparent;
    cursor:pointer;
    font-weight:700;
    font-size:.9rem;
    text-decoration:none;
    user-select:none;
    transition: all .15s ease;
    white-space: nowrap;
}
.btn-primary{ background:#2563eb; color:#fff; border-color:#2563eb; }
.btn-primary:hover{ background:#1d4ed8; border-color:#1d4ed8; }
.btn-outline{ background:#ffffff; color:#1e3a8a; border-color:#93c5fd; }
.btn-outline:hover{ background:#eff6ff; }
.toast{
    position: fixed;
    right: 20px;
    bottom: 20px;
    background: #0b1220;
    color: #e5e7eb;
    padding: .8rem 1rem;
    border-radius: 12px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.2);
    font-weight: 700;
    display: none;
    z-index: 5000;
}
</style>

<div class="vendas-links-container">
    <div class="vendas-links-header">
        <h1>Links públicos — Vendas</h1>
        <p>Copie e envie para o cliente. Ao enviar, o cliente cria um Pré-contrato no Painel.</p>
    </div>

    <?php foreach ($links as $l): ?>
        <div class="vendas-links-card">
            <div class="vendas-links-title"><?php echo htmlspecialchars((string)$l['label']); ?></div>
            <div class="vendas-links-desc"><?php echo htmlspecialchars((string)$l['desc']); ?></div>
            <div class="vendas-links-row" style="margin-top:.75rem;">
                <input class="vendas-links-input" type="text" readonly value="<?php echo htmlspecialchars((string)$l['url']); ?>">
                <button type="button" class="btn btn-primary" data-copy-btn>Copiar</button>
                <a class="btn btn-outline" href="<?php echo htmlspecialchars((string)$l['url']); ?>" target="_blank" rel="noopener">Abrir</a>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="toast" id="toastCopy">Link copiado!</div>

<script>
(function(){
    const toast = document.getElementById('toastCopy');
    function showToast(msg){
        if (!toast) return;
        toast.textContent = msg || 'Link copiado!';
        toast.style.display = 'block';
        clearTimeout(window.__toastTimer);
        window.__toastTimer = setTimeout(() => { toast.style.display = 'none'; }, 1800);
    }

    document.querySelectorAll('[data-copy-btn]').forEach(btn => {
        btn.addEventListener('click', async function(){
            const card = this.closest('.vendas-links-row');
            const input = card ? card.querySelector('input') : null;
            const text = input ? input.value : '';
            if (!text) return;
            try {
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    await navigator.clipboard.writeText(text);
                } else {
                    input.focus();
                    input.select();
                    document.execCommand('copy');
                }
                showToast('Link copiado!');
            } catch (e) {
                showToast('Não foi possível copiar automaticamente');
            }
        });
    });
})();
</script>

<?php
$conteudo = ob_get_clean();
includeSidebar('Comercial');
echo $conteudo;
endSidebar();
?>

