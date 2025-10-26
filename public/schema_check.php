<?php
declare(strict_types=1);
// public/ver.php — Detalhe de um grupo de listas (Compras / Encomendas)

// ========= Sessão / Auth =========
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == '443');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$https,'httponly'=>true,'samesite'=>'Lax']);
    session_start();
}
$uid = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
$logadoFlag = $_SESSION['logado'] ?? $_SESSION['logged_in'] ?? $_SESSION['auth'] ?? null;
$estaLogado = filter_var($logadoFlag, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
if ($estaLogado === null) { $estaLogado = in_array((string)$logadoFlag, ['1','true','on','yes'], true); }
if (!$uid || !is_numeric($uid) || !$estaLogado) { http_response_code(403); echo "Acesso negado. Faça login para continuar."; exit; }

// ========= Conexão =========
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
if (!isset($pdo) || !$pdo instanceof PDO) { echo "Falha na conexão com o banco de dados."; exit; }

// ========= Helpers =========

function qs(array $extra=[]): string { $base = $_GET; foreach ($extra as $k=>$v){ if($v===null) unset($base[$k]); else $base[$k]=$v; } return http_build_query($base); }
function dec($n){ $n=(float)$n; return rtrim(rtrim(number_format($n,3,'.',''), '0'), '.'); }

$grupoId = max(0, (int)($_GET['g'] ?? 0));
$tab = (string)($_GET['tab'] ?? 'compras');
if (!in_array($tab, ['compras','encomendas'], true)) $tab='compras';
$q = trim((string)($_GET['q'] ?? ''));

// ========= Cabeçalho do grupo =========
if ($grupoId<=0) { http_response_code(400); echo "Grupo inválido."; exit; }

$hdr = $pdo->prepare("
  SELECT
    :g AS grupo_id,
    max(data_gerada) AS data_gerada,
    max(espaco_consolidado) AS espaco_consolidado,
    max(eventos_resumo) AS eventos_resumo,
    max(criado_por_nome) AS criado_por_nome
  FROM lc_listas
  WHERE grupo_id = :g
");
$hdr->execute([':g'=>$grupoId]);
$grupo = $hdr->fetch(PDO::FETCH_ASSOC);
if (!$grupo) { http_response_code(404); echo "Grupo não encontrado."; exit; }

// Eventos do grupo (para exibir contexto)
$evs = $pdo->prepare("SELECT id, espaco, convidados, horario, data, dia_semana FROM lc_listas_eventos WHERE grupo_id=:g ORDER BY data, id");
$evs->execute([':g'=>$grupoId]);
$eventos = $evs->fetchAll(PDO::FETCH_ASSOC);

// ========= CSV export (stream) =========
$asCsv = (string)($_GET['export'] ?? '') === 'csv';
if ($asCsv) {
    header('Content-Type: text/csv; charset=UTF-8');
    $fname = "grupo_{$grupoId}_{$tab}.csv";
    header('Content-Disposition: attachment; filename="'.$fname.'"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ["Grupo","Tipo",$tab==="compras"?"Insumo":"Fornecedor",$tab==="compras"?"Unidade":"Evento",$tab==="compras"?"Quantidade":"Item",$tab==="compras"?"":"Unidade",$tab==="compras"?"":"Quantidade"], ';');

    if ($tab==='compras') {
        $sql = "SELECT nome_insumo, unidade, quantidade FROM lc_compras_consolidadas WHERE grupo_id=:g ORDER BY nome_insumo";
        $st = $pdo->prepare($sql); $st->execute([':g'=>$grupoId]);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($out, [$grupoId,'compras',$r['nome_insumo'],$r['unidade'],dec($r['quantidade'])], ';');
        }
    } else {
        $sql = "SELECT fornecedor_nome, evento_label, nome_item, COALESCE(unidade,'') AS unidade, quantidade
                FROM lc_encomendas_itens WHERE grupo_id=:g
                ORDER BY fornecedor_nome, COALESCE(evento_label,''), nome_item";
        $st = $pdo->prepare($sql); $st->execute([':g'=>$grupoId]);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($out, [$grupoId,'encomendas',$r['fornecedor_nome'],$r['evento_label'] ?: 'Consolidado',
                           $r['nome_item'],$r['unidade'],dec($r['quantidade'])], ';');
        }
    }
    fclose($out);
    exit;
}

// ========= Dados principais (com busca) =========
$compras = [];
$encomendas = [];

if ($tab==='compras') {
    $bind = [':g'=>$grupoId];
    $where = "WHERE grupo_id=:g";
    if ($q!=='') {
        $where .= " AND (nome_insumo ILIKE :pat OR unidade ILIKE :pat)";
        $bind[':pat'] = '%'.$q.'%';
    }
    $sql = "SELECT id, nome_insumo, unidade, quantidade
            FROM lc_compras_consolidadas
            $where
            ORDER BY nome_insumo";
    $st = $pdo->prepare($sql);
    foreach ($bind as $k=>$v) $st->bindValue($k,$v, is_string($v)?PDO::PARAM_STR:PDO::PARAM_INT);
    $st->execute();
    $compras = $st->fetchAll(PDO::FETCH_ASSOC);
} else {
    // Encomendas agrupadas por fornecedor e por evento (null = consolidado)
    $bind = [':g'=>$grupoId];
    $where = "WHERE grupo_id=:g";
    if ($q!=='') {
        $where .= " AND (fornecedor_nome ILIKE :pat OR COALESCE(evento_label,'Consolidado') ILIKE :pat OR nome_item ILIKE :pat OR COALESCE(unidade,'') ILIKE :pat)";
        $bind[':pat'] = '%'.$q.'%';
    }
    $sql = "SELECT fornecedor_id, fornecedor_nome, evento_id, evento_label, item_id, nome_item, COALESCE(unidade,'') AS unidade, quantidade
            FROM lc_encomendas_itens
            $where
            ORDER BY fornecedor_nome, COALESCE(evento_label,''), nome_item";
    $st = $pdo->prepare($sql);
    foreach ($bind as $k=>$v) $st->bindValue($k,$v, is_string($v)?PDO::PARAM_STR:PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    // estrutura: $encomendas[forn_nome]['consolidado'][] ou ['eventos'][evento_label][]
    foreach ($rows as $r) {
        $fn = $r['fornecedor_nome'] ?: 'Fornecedor';
        if (!isset($encomendas[$fn])) {
            $encomendas[$fn] = ['consolidado'=>[], 'eventos'=>[]];
        }
        if ($r['evento_id'] === null) {
            $encomendas[$fn]['consolidado'][] = $r;
        } else {
            $lbl = $r['evento_label'] ?: 'Evento';
            $encomendas[$fn]['eventos'][$lbl][] = $r;
        }
    }
}

// ========= HTML =========
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Grupo #<?= (int)$grupoId ?> — <?= $tab==='compras'?'Compras':'Encomendas' ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="estilo.css">
<style>
.wrap{padding:16px}
.header{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
h1{margin:0}
.kv{display:flex;gap:8px;flex-wrap:wrap}
.kv span{background:#eef5ff;border:1px solid #dbe6ff;border-radius:999px;padding:6px 10px;font-size:12px}
.tabs{display:flex;gap:8px;margin:8px 0 12px 0;flex-wrap:wrap}
.tab{padding:8px 12px;border:1px solid #e1ebff;border-radius:8px;text-decoration:none}
.tab.active{background:#004aad;color:#fff;border-color:#004aad}
.card{background:#fff;border:1px solid #dfe7f4;border-radius:12px;padding:16px;margin-bottom:12px}
.toolbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:8px}
.input{padding:10px;border:1px solid #cfe0ff;border-radius:8px}
.btn{background:#004aad;color:#fff;border:none;border-radius:8px;padding:10px 14px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-block}
.btn.gray{background:#e9efff;color:#004aad}
.btn.green{background:#1a9a3f}
table{width:100%;border-collapse:separate;border-spacing:0}
th,td{padding:10px;border-bottom:1px solid #eef3ff;vertical-align:top}
th{text-align:left;font-size:13px;color:#37517e}
.muted{color:#667b9f;font-size:12px}
.section-title{margin:0 0 6px 0}
.subtle{background:#f9fbff;padding:8px 12px;border-radius:8px;border:1px dashed #e6eeff}
@media print{
  .no-print{display:none !important}
  body{background:#fff}
}
</style>
</head>
<body>
<?php if (is_file(__DIR__.'/sidebar.php')) include __DIR__.'/sidebar.php'; ?>
<div class="main-content">
<div class="wrap">

    <div class="header">
        <div>
            <h1>Grupo #<?= (int)$grupoId ?> — <?= $tab==='compras'?'Compras':'Encomendas' ?></h1>
            <div class="kv">
                <span><strong>Gerado:</strong> <?= h((string)$grupo['data_gerada']) ?></span>
                <?php if (!empty($grupo['espaco_consolidado'])): ?>
                    <span><strong>Espaço:</strong> <?= h((string)$grupo['espaco_consolidado']) ?></span>
                <?php endif; ?>
                <?php if (!empty($grupo['criado_por_nome'])): ?>
                    <span><strong>Por:</strong> <?= h((string)$grupo['criado_por_nome']) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="no-print" style="display:flex;gap:8px;align-items:center">
            <a class="btn gray" href="lc_index.php">← Painel</a>
            <a class="btn gray" href="historico.php">Histórico</a>
            <button class="btn" onclick="window.print()">Imprimir</button>
            <a class="btn green" href="ver.php?<?= h(qs(['export'=>'csv'])) ?>">Exportar CSV</a>
        </div>
    </div>

    <?php if (!empty($grupo['eventos_resumo'])): ?>
        <div class="subtle" style="margin-bottom:12px">
            <strong>Eventos:</strong> <?= h((string)$grupo['eventos_resumo']) ?>
        </div>
    <?php endif; ?>

    <div class="tabs no-print">
        <a class="tab <?= $tab==='compras'?'active':'' ?>" href="ver.php?<?= h(qs(['tab'=>'compras','q'=>null])) ?>">Compras</a>
        <a class="tab <?= $tab==='encomendas'?'active':'' ?>" href="ver.php?<?= h(qs(['tab'=>'encomendas','q'=>null])) ?>">Encomendas</a>
    </div>

    <?php if ($tab==='compras'): ?>
        <div class="card">
            <form method="get" class="toolbar no-print">
                <input type="hidden" name="g" value="<?= (int)$grupoId ?>">
                <input type="hidden" name="tab" value="compras">
                <input class="input" type="text" name="q" value="<?= h($q) ?>" placeholder="Buscar por insumo/unidade...">
                <button class="btn" type="submit">Buscar</button>
                <?php if ($q!==''): ?><a class="btn gray" href="ver.php?<?= h(qs(['q'=>null])) ?>">Limpar</a><?php endif; ?>
                <span class="muted" style="margin-left:auto"><?= count($compras) ?> item(ns)</span>
            </form>

            <?php if (!$compras): ?>
                <div class="muted">Nenhum resultado.</div>
            <?php else: ?>
                <div style="overflow:auto">
                <table>
                    <thead>
                        <tr>
                            <th>Insumo</th>
                            <th style="width:160px">Unidade</th>
                            <th style="width:160px">Quantidade</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $totalLinhas = 0;
                        foreach ($compras as $r):
                            $totalLinhas++;
                        ?>
                        <tr>
                            <td><?= h((string)$r['nome_insumo']) ?></td>
                            <td><?= h((string)$r['unidade']) ?></td>
                            <td><?= h(dec($r['quantidade'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <div class="muted" style="margin-top:8px">Total de linhas: <strong><?= (int)$totalLinhas ?></strong></div>
            <?php endif; ?>
        </div>

    <?php else: // ENCOMENDAS ?>
        <div class="card">
            <form method="get" class="toolbar no-print">
                <input type="hidden" name="g" value="<?= (int)$grupoId ?>">
                <input type="hidden" name="tab" value="encomendas">
                <input class="input" type="text" name="q" value="<?= h($q) ?>" placeholder="Buscar por fornecedor/evento/item...">
                <button class="btn" type="submit">Buscar</button>
                <?php if ($q!==''): ?><a class="btn gray" href="ver.php?<?= h(qs(['q'=>null])) ?>">Limpar</a><?php endif; ?>
                <span class="muted" style="margin-left:auto">
                    Fornecedores: <strong><?= count($encomendas) ?></strong>
                </span>
            </form>

            <?php if (!$encomendas): ?>
                <div class="muted">Nenhum resultado.</div>
            <?php else: ?>
                <?php foreach ($encomendas as $fornecedor => $payload): ?>
                    <div class="card" style="margin:10px 0">
                        <h3 class="section-title"><?= h((string)$fornecedor) ?></h3>

                        <?php if (!empty($payload['consolidado'])): ?>
                            <div class="subtle" style="margin:8px 0 6px 0"><strong>Consolidado</strong></div>
                            <div style="overflow:auto;margin-bottom:8px">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Item</th>
                                        <th style="width:160px">Unidade</th>
                                        <th style="width:160px">Quantidade</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $lin=0; foreach ($payload['consolidado'] as $r): $lin++; ?>
                                    <tr>
                                        <td><?= h((string)$r['nome_item']) ?></td>
                                        <td><?= h((string)$r['unidade']) ?></td>
                                        <td><?= h(dec($r['quantidade'])) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            </div>
                            <div class="muted">Linhas (consolidado): <strong><?= (int)$lin ?></strong></div>
                        <?php endif; ?>

                        <?php if (!empty($payload['eventos'])): ?>
                            <div class="subtle" style="margin:12px 0 6px 0"><strong>Separado por evento</strong></div>
                            <?php foreach ($payload['eventos'] as $label => $rows): ?>
                                <div class="card" style="margin:8px 0">
                                    <div class="muted" style="margin-bottom:6px"><strong>Evento:</strong> <?= h((string)$label) ?></div>
                                    <div style="overflow:auto">
                                    <table>
                                        <thead>
                                            <tr>
                                                <th>Item</th>
                                                <th style="width:160px">Unidade</th>
                                                <th style="width:160px">Quantidade</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php $lin=0; foreach ($rows as $r): $lin++; ?>
                                            <tr>
                                                <td><?= h((string)$r['nome_item']) ?></td>
                                                <td><?= h((string)$r['unidade']) ?></td>
                                                <td><?= h(dec($r['quantidade'])) ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                    </div>
                                    <div class="muted">Linhas: <strong><?= (int)$lin ?></strong></div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

</div>
</div>
</body>
</html>
