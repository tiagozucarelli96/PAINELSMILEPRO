<?php
declare(strict_types=1);
// public/ver.php — Detalhe de um Grupo de Lista de Compras (PostgreSQL/Railway)

// ========= Sessão / Auth =========
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == '443');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'secure' => $https,
        'httponly' => true, 'samesite' => 'Lax',
    ]);
    session_start();
}
$uid = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
$logadoFlag = $_SESSION['logado'] ?? $_SESSION['logged_in'] ?? $_SESSION['auth'] ?? null;
$estaLogado = filter_var($logadoFlag, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
if ($estaLogado === null) { $estaLogado = in_array((string)$logadoFlag, ['1','true','on','yes'], true); }
if (!$uid || !is_numeric($uid) || !$estaLogado) {
    http_response_code(403);
    echo "Acesso negado. Faça login para continuar.";
    exit;
}

// ========= Conexão =========
require_once __DIR__ . '/conexao.php';
if (!isset($pdo) || !$pdo instanceof PDO) { echo "Falha na conexão com o banco de dados."; exit; }

// ========= Helpers =========
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function brDate(string $isoTs): string {
    $t = strtotime($isoTs);
    return $t ? date('d/m/Y H:i', $t) : $isoTs;
}
function qs(array $extra=[]): string {
    $base = $_GET;
    foreach ($extra as $k=>$v) $base[$k]=$v;
    return http_build_query($base);
}

// ========= Inputs =========
$g   = (int)($_GET['g']   ?? 0);
$tab = (string)($_GET['tab'] ?? 'compras');
$q   = trim((string)($_GET['q'] ?? ''));
$exp = (string)($_GET['export'] ?? ''); // 'csv' para exportar

if ($g <= 0) { http_response_code(400); echo "Grupo inválido."; exit; }
if (!in_array($tab, ['compras','encomendas'], true)) $tab = 'compras';

// ========= Cabeçalho do grupo =========
$hdr = $pdo->prepare("
  SELECT 
    max(data_gerada) AS data_gerada,
    max(espaco_consolidado) AS espaco_consolidado,
    max(eventos_resumo) AS eventos_resumo,
    max(criado_por_nome) AS criado_por_nome,
    max(criado_por) AS criado_por
  FROM lc_listas
  WHERE grupo_id = :g
");
$hdr->execute([':g'=>$g]);
$hrow = $hdr->fetch(PDO::FETCH_ASSOC);
if (!$hrow || !$hrow['data_gerada']) { http_response_code(404); echo "Grupo não encontrado."; exit; }

// ========= Data sources =========
if ($tab === 'compras') {
    // COMPRAS consolidadas
    $sql = "
      SELECT nome_insumo, unidade, SUM(quantidade)::numeric(12,3) AS quantidade
      FROM lc_compras_consolidadas
      WHERE grupo_id = :g
      GROUP BY nome_insumo, unidade
      ORDER BY nome_insumo
    ";
    $st = $pdo->prepare($sql);
    $st->execute([':g'=>$g]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    // filtro em memória (simples) para q
    if ($q !== '') {
        $rows = array_values(array_filter($rows, function($r) use ($q){
            $hay = strtolower(($r['nome_insumo'] ?? '').' '.($r['unidade'] ?? ''));
            return str_contains($hay, strtolower($q));
        }));
    }

    // Export CSV?
    if ($exp === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="compras_grupo_'.$g.'.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Insumo','Unidade','Quantidade']);
        foreach ($rows as $r) {
            fputcsv($out, [$r['nome_insumo'], $r['unidade'], (string)$r['quantidade']]);
        }
        fclose($out);
        exit;
    }

} else {
    // ENCOMENDAS por fornecedor / opcional por evento
    $sql = "
      SELECT 
        fornecedor_nome,
        COALESCE(evento_label,'') AS evento_label,
        nome_item,
        COALESCE(unidade,'') AS unidade,
        SUM(quantidade)::numeric(12,3) AS quantidade
      FROM lc_encomendas_itens
      WHERE grupo_id = :g
      GROUP BY fornecedor_nome, evento_label, nome_item, unidade
      ORDER BY fornecedor_nome, evento_label, nome_item
    ";
    $st = $pdo->prepare($sql);
    $st->execute([':g'=>$g]);
    $all = $st->fetchAll(PDO::FETCH_ASSOC);

    // filtro q
    if ($q !== '') {
        $all = array_values(array_filter($all, function($r) use ($q){
            $hay = strtolower(
                ($r['fornecedor_nome'] ?? '').' '.($r['evento_label'] ?? '').' '.
                ($r['nome_item'] ?? '').' '.($r['unidade'] ?? '')
            );
            return str_contains($hay, strtolower($q));
        }));
    }

    // Agrupa por fornecedor e por evento_label
    $grouped = [];
    foreach ($all as $r) {
        $f = (string)$r['fornecedor_nome'];
        $e = (string)$r['evento_label'];
        $grouped[$f][$e][] = $r;
    }

    // Export CSV?
    if ($exp === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="encomendas_grupo_'.$g.'.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Fornecedor','Evento','Item','Unidade','Quantidade']);
        foreach ($grouped as $forn => $byEvent) {
            foreach ($byEvent as $ev => $items) {
                foreach ($items as $r) {
                    fputcsv($out, [$forn, $ev, $r['nome_item'], $r['unidade'], (string)$r['quantidade']]);
                }
            }
        }
        fclose($out);
        exit;
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Grupo #<?= (int)$g ?> — Lista de Compras</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="estilo.css">
    <style>
        .wrap{padding:16px}
        .card{background:#fff;border:1px solid #dfe7f4;border-radius:12px;padding:16px}
        .header{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}
        h1{margin:0}
        .muted{color:#667b9f;font-size:12px}
        .tabs{display:flex;gap:8px;margin:8px 0 12px 0}
        .tab{padding:8px 12px;border:1px solid #e1ebff;border-radius:8px;text-decoration:none}
        .tab.active{background:#004aad;color:#fff;border-color:#004aad}
        .toolbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:12px}
        .input{padding:10px;border:1px solid #cfe0ff;border-radius:8px}
        .btn{background:#004aad;color:#fff;border:none;border-radius:8px;padding:10px 14px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-block}
        .btn.gray{background:#e9efff;color:#004aad}
        table{width:100%;border-collapse:separate;border-spacing:0}
        th,td{padding:10px;border-bottom:1px solid #eef3ff;vertical-align:top}
        th{text-align:left;font-size:13px;color:#37517e}
        .section{border:1px dashed #dbe6ff;border-radius:10px;margin:10px 0}
        .section h3{margin:0;padding:10px 12px;border-bottom:1px dashed #e6eeff;background:#f9fbff;border-radius:10px 10px 0 0}
        .section .inner{padding:8px 12px}
    </style>
</head>
<body>
<?php if (is_file(__DIR__.'/sidebar.php')) include __DIR__.'/sidebar.php'; ?>
<div class="main-content">
<div class="wrap">

    <div class="header">
        <div>
            <h1>Grupo #<?= (int)$g ?></h1>
            <div class="muted">
                <strong>Gerado:</strong> <?= h(brDate((string)$hrow['data_gerada'])) ?> •
                <strong>Espaço(s):</strong> <?= h((string)$hrow['espaco_consolidado']) ?><br>
                <strong>Eventos:</strong> <?= h((string)$hrow['eventos_resumo']) ?><br>
                <strong>Criado por:</strong> <?= h((string)$hrow['criado_por_nome'] ?? '') ?> (ID <?= (int)($hrow['criado_por'] ?? 0) ?>)
            </div>
        </div>
        <div>
            <a class="btn gray" href="historico.php">← Histórico</a>
            <a class="btn" href="lista_compras.php">+ Gerar novo</a>
        </div>
    </div>

    <div class="tabs">
        <a class="tab <?= $tab==='compras'?'active':'' ?>" href="ver.php?<?= h(qs(['tab'=>'compras','export'=>null])) ?>">Compras</a>
        <a class="tab <?= $tab==='encomendas'?'active':'' ?>" href="ver.php?<?= h(qs(['tab'=>'encomendas','export'=>null])) ?>">Encomendas</a>
    </div>

    <div class="card">
        <form method="get" class="toolbar">
            <input type="hidden" name="g" value="<?= (int)$g ?>">
            <input type="hidden" name="tab" value="<?= h($tab) ?>">
            <input class="input" type="text" name="q" value="<?= h($q) ?>" placeholder="Buscar nesta aba...">
            <button class="btn" type="submit">Buscar</button>
            <?php if ($q!==''): ?><a class="btn gray" href="ver.php?<?= h(qs(['q'=>null])) ?>">Limpar</a><?php endif; ?>
            <a class="btn" href="ver.php?<?= h(qs(['export'=>'csv'])) ?>">Exportar CSV</a>
        </form>

        <?php if ($tab === 'compras'): ?>
            <div style="overflow:auto">
            <table>
                <thead>
                    <tr>
                        <th>Insumo</th>
                        <th style="width:140px">Unidade</th>
                        <th style="width:160px">Quantidade</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="3" class="muted">Nenhum insumo nesta lista.</td></tr>
                <?php else: foreach ($rows as $r): ?>
                    <tr>
                        <td><?= h($r['nome_insumo']) ?></td>
                        <td><?= h($r['unidade']) ?></td>
                        <td><?= h((string)$r['quantidade']) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
            </div>

        <?php else: /* ENCOMENDAS */ ?>
            <?php if (empty($grouped)): ?>
                <div class="muted">Nenhuma encomenda nesta lista.</div>
            <?php else: ?>
                <?php foreach ($grouped as $fornecedor => $byEvent): ?>
                    <div class="section">
                        <h3>Fornecedor: <?= h($fornecedor) ?></h3>
                        <div class="inner">
                            <?php foreach ($byEvent as $evLabel => $items): ?>
                                <?php if ($evLabel !== ''): ?>
                                    <div class="muted" style="margin:4px 0 6px 0;"><strong>Evento:</strong> <?= h($evLabel) ?></div>
                                <?php endif; ?>
                                <div style="overflow:auto">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Item</th>
                                            <th style="width:140px">Unidade</th>
                                            <th style="width:160px">Quantidade</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($items as $r): ?>
                                            <tr>
                                                <td><?= h($r['nome_item']) ?></td>
                                                <td><?= h($r['unidade']) ?></td>
                                                <td><?= h((string)$r['quantidade']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                </div>
                                <hr style="border:none;border-top:1px dashed #e6eeff;margin:10px 0">
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>

</div>
</div>
</body>
</html>
