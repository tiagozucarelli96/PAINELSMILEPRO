<?php
// logistica_lista_pdf.php — PDF/HTML da lista de compras
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['logado']) || $_SESSION['logado'] != 1) {
    http_response_code(403);
    echo "Acesso negado.";
    exit;
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';

$lista_id = isset($_GET['lista_id']) ? (int)$_GET['lista_id'] : 0;
if ($lista_id <= 0) { echo "lista_id inválido."; exit; }
$show_values = !empty($_GET['show_values']) && (!empty($_SESSION['perm_superadmin']) || !empty($_SESSION['perm_logistico_financeiro']));

$stmt = $pdo->prepare("
    SELECT l.*, u.nome AS unidade_nome
    FROM logistica_listas l
    LEFT JOIN logistica_unidades u ON u.id = l.unidade_interna_id
    WHERE l.id = :id
");
$stmt->execute([':id' => $lista_id]);
$lista = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$lista) { echo "Lista não encontrada."; exit; }

$stmt = $pdo->prepare("SELECT * FROM logistica_lista_eventos WHERE lista_id = :id ORDER BY data_evento ASC, hora_inicio ASC NULLS LAST");
$stmt->execute([':id' => $lista_id]);
$eventos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT li.*, i.nome_oficial, i.custo_padrao, u.nome AS unidade_nome, t.nome AS tipologia_nome
    FROM logistica_lista_itens li
    LEFT JOIN logistica_insumos i ON i.id = li.insumo_id
    LEFT JOIN logistica_unidades_medida u ON u.id = li.unidade_medida_id
    LEFT JOIN logistica_tipologias_insumo t ON t.id = li.tipologia_insumo_id
    WHERE li.lista_id = :id
    ORDER BY t.nome, i.nome_oficial
");
$stmt->execute([':id' => $lista_id]);
$itens = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_valor = 0.0;
if ($show_values) {
    foreach ($itens as &$it) {
        if ($it['custo_padrao'] === null || $it['custo_padrao'] === '') {
            $it['custo_total'] = null;
        } else {
            $it['custo_total'] = (float)$it['quantidade_total_bruto'] * (float)$it['custo_padrao'];
            $total_valor += (float)$it['custo_total'];
        }
    }
    unset($it);
}

ob_start();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Lista de Compras #<?= (int)$lista_id ?></title>
<style>
*{box-sizing:border-box}
body{font-family:Arial,Helvetica,sans-serif;margin:0;padding:24px;color:#111}
.header{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #000;padding-bottom:12px;margin-bottom:16px}
h1{font-size:22px;margin:0 0 6px 0}
.meta{font-size:12px;color:#444}
table{width:100%;border-collapse:collapse;margin-top:6px}
th,td{border-bottom:1px solid #ddd;padding:8px;text-align:left;vertical-align:top;font-size:13px}
th{background:#f4f6fb}
.right{text-align:right}
.section{margin-top:12px}
@media print{ .no-print{display:none} body{padding:0} }
</style>
</head>
<body>

<div class="header">
  <div>
    <h1>Lista de Compras</h1>
    <div class="meta">
      Lista #<?= (int)$lista_id ?> · <?= h($lista['unidade_nome'] ?? '') ?>
      <?= $lista['space_visivel'] ? ' · ' . h($lista['space_visivel']) : '' ?><br>
      Gerado em <?= h(date('d/m/Y H:i', strtotime($lista['criado_em']))) ?>
    </div>
  </div>
  <div class="no-print">
    <button onclick="window.print()">Imprimir</button>
  </div>
</div>

<div class="section">
  <h3 style="margin:0">Eventos</h3>
  <table>
    <thead>
      <tr>
        <th>Data</th>
        <th>Hora</th>
        <th>Evento</th>
        <th>Local</th>
        <th class="right">Convidados</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($eventos as $e): ?>
        <tr>
          <td><?= h(date('d/m/Y', strtotime($e['data_evento']))) ?></td>
          <td><?= h($e['hora_inicio'] ?? '') ?></td>
          <td><?= h($e['nome_evento'] ?? 'Evento') ?></td>
          <td><?= h($e['localevento'] ?? '') ?></td>
          <td class="right"><?= (int)($e['convidados'] ?? 0) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="section">
  <h3 style="margin:0">Itens consolidados</h3>
  <table>
    <thead>
      <tr>
        <th>Tipologia</th>
        <th>Item</th>
        <th>Unidade</th>
        <th class="right">Quantidade total (bruto)</th>
        <?php if ($show_values): ?>
        <th class="right">Custo padrão</th>
        <th class="right">Custo total</th>
        <?php endif; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($itens as $it): ?>
        <tr>
          <td><?= h($it['tipologia_nome'] ?? 'Sem tipologia') ?></td>
          <td><?= h($it['nome_oficial'] ?? '') ?></td>
          <td><?= h($it['unidade_nome'] ?? '') ?></td>
          <td class="right"><?= number_format((float)$it['quantidade_total_bruto'], 4, ',', '.') ?></td>
          <?php if ($show_values): ?>
            <td class="right"><?= $it['custo_padrao'] === null || $it['custo_padrao'] === '' ? '-' : format_currency($it['custo_padrao']) ?></td>
            <td class="right"><?= $it['custo_total'] === null ? '-' : format_currency($it['custo_total']) ?></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($show_values): ?>
  <div style="margin-top:12px;text-align:right;">
    <strong>Total:</strong> <?= format_currency($total_valor) ?>
  </div>
<?php endif; ?>

</body>
</html>
<?php
$html = ob_get_clean();

$dompdf_ok = false;
$autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
    try {
        if (class_exists('\\Dompdf\\Dompdf')) {
            $dompdf_ok = true;
            $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => true, 'defaultPaperSize' => 'a4', 'isHtml5ParserEnabled' => true]);
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();
            $fname = 'Lista_Compras_' . $lista_id . '.pdf';
            $dompdf->stream($fname, ['Attachment' => false]);
            exit;
        }
    } catch (Throwable $e) {
        $dompdf_ok = false;
    }
}

if (!$dompdf_ok) {
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
}
