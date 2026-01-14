<?php
// logistica_financeiro_estoque_pdf.php — PDF de valor do estoque
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (empty($_SESSION['perm_superadmin']) && empty($_SESSION['perm_logistico_financeiro'])) {
    http_response_code(403);
    echo "Acesso negado.";
    exit;
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';

$unidade_id = $_GET['unidade_id'] ?? 'todas';
$q = trim((string)($_GET['q'] ?? ''));

$params = [];
$where = [];
if ($unidade_id !== 'todas') {
    $where[] = "s.unidade_id = :uid";
    $params[':uid'] = (int)$unidade_id;
}
if ($q !== '') {
    $where[] = "(LOWER(i.nome_oficial) LIKE :q OR LOWER(COALESCE(i.sinonimos,'')) LIKE :q)";
    $params[':q'] = '%' . strtolower($q) . '%';
}
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

if ($unidade_id === 'todas') {
    $sql = "
        SELECT i.id, i.nome_oficial, i.custo_padrao, u.nome AS unidade_nome,
               SUM(COALESCE(s.quantidade_atual, 0)) AS saldo_atual
        FROM logistica_insumos i
        LEFT JOIN logistica_unidades_medida u ON u.id = i.unidade_medida_padrao_id
        LEFT JOIN logistica_estoque_saldos s ON s.insumo_id = i.id
        $where_sql
        GROUP BY i.id, i.nome_oficial, i.custo_padrao, u.nome
        HAVING SUM(COALESCE(s.quantidade_atual, 0)) > 0
        ORDER BY i.nome_oficial
    ";
} else {
    $sql = "
        SELECT i.id, i.nome_oficial, i.custo_padrao, u.nome AS unidade_nome,
               COALESCE(s.quantidade_atual, 0) AS saldo_atual
        FROM logistica_insumos i
        LEFT JOIN logistica_unidades_medida u ON u.id = i.unidade_medida_padrao_id
        LEFT JOIN logistica_estoque_saldos s ON s.insumo_id = i.id
        $where_sql
        AND COALESCE(s.quantidade_atual, 0) > 0
        ORDER BY i.nome_oficial
    ";
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total = 0.0;
$missing = 0;
foreach ($rows as $r) {
    if ($r['custo_padrao'] === null || $r['custo_padrao'] === '') {
        $missing++;
        continue;
    }
    $total += (float)$r['saldo_atual'] * (float)$r['custo_padrao'];
}

ob_start();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Valor do Estoque</title>
<style>
*{box-sizing:border-box}
body{font-family:Arial,Helvetica,sans-serif;margin:0;padding:24px;color:#111}
h1{font-size:20px;margin:0 0 12px 0}
table{width:100%;border-collapse:collapse}
th,td{border-bottom:1px solid #ddd;padding:8px;text-align:left;font-size:12px}
th{background:#f4f6fb}
.right{text-align:right}
.meta{font-size:11px;color:#555;margin-bottom:12px}
</style>
</head>
<body>
  <h1>Valor do Estoque</h1>
  <div class="meta">
    Unidade: <?= htmlspecialchars($unidade_id === 'todas' ? 'Todas' : (string)$unidade_id) ?>
    · Gerado em <?= date('d/m/Y H:i') ?>
  </div>
  <table>
    <thead>
      <tr>
        <th>Insumo</th>
        <th>Unidade</th>
        <th class="right">Saldo</th>
        <th class="right">Custo padrão</th>
        <th class="right">Valor total</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <?php
          $custo = $r['custo_padrao'];
          $saldo = (float)$r['saldo_atual'];
          $valor_total = ($custo !== null && $custo !== '') ? $saldo * (float)$custo : null;
        ?>
        <tr>
          <td><?= htmlspecialchars($r['nome_oficial']) ?></td>
          <td><?= htmlspecialchars($r['unidade_nome'] ?? '') ?></td>
          <td class="right"><?= number_format($saldo, 4, ',', '.') ?></td>
          <td class="right"><?= $custo === null || $custo === '' ? '-' : format_currency($custo) ?></td>
          <td class="right"><?= $valor_total === null ? '-' : format_currency($valor_total) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <div style="margin-top:12px;">
    Itens sem custo: <?= (int)$missing ?> · Total: <?= format_currency($total) ?>
  </div>
</body>
</html>
<?php
$html = ob_get_clean();

if (class_exists('Dompdf\\Dompdf')) {
    $dompdf = new Dompdf\Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $dompdf->stream('valor_estoque.pdf', ['Attachment' => false]);
} else {
    echo $html;
}
