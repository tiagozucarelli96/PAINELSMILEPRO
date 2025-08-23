<?php
// pdf_encomendas.php — Visualização/geração de PDF da LISTA DE ENCOMENDAS (por grupo)
// Tenta usar Dompdf se disponível. Se não, exibe HTML imprimível.

session_start();
ini_set('display_errors', 1); error_reporting(E_ALL);

if (!isset($_SESSION['logado']) || $_SESSION['logado'] != 1) {
    http_response_code(403);
    echo "Acesso negado.";
    exit;
}

@include_once __DIR__ . '/conexao.php';
if (!isset($pdo)) { echo "Falha na conexão."; exit; }

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function dt($s, $fmt='d/m/Y H:i'){ return $s ? date($fmt, strtotime($s)) : ''; }

$grupo_id = isset($_GET['grupo_id']) ? (int)$_GET['grupo_id'] : 0;
if ($grupo_id <= 0) { echo "grupo_id inválido."; exit; }

// ===== Cabeçalho (mesmo padrão do pdf_compras.php) =====
$stG = $pdo->prepare("SELECT g.id, g.grupo_token, g.criado_por, g.criado_por_nome, g.created_at
                      FROM lc_geracoes g WHERE g.id=?");
$stG->execute([$grupo_id]);
$G = $stG->fetch(PDO::FETCH_ASSOC);
if (!$G) { echo "Geração não encontrada."; exit; }

// Eventos do grupo (para compor cabeçalho)
$stEv = $pdo->prepare("SELECT id, espaco, convidados, horario, evento_texto, data_evento, dia_semana
                       FROM lc_lista_eventos
                       WHERE grupo_id=?
                       ORDER BY data_evento ASC, horario ASC, id ASC");
$stEv->execute([$grupo_id]);
$EVS = $stEv->fetchAll(PDO::FETCH_ASSOC);

// Monta resumos “Espaço(s)” e “Eventos”
$espacos = []; $datas = [];
foreach ($EVS as $e) {
    $espacos[$e['espaco']] = true;
    $datas[] = date('d/m', strtotime($e['data_evento']));
}
$espaco_consolidado = (count($espacos) === 1) ? array_key_first($espacos) : 'Múltiplos espaços';
$eventos_resumo = count($EVS).' evento'.(count($EVS)>1?'s':'').' ('.implode(', ', array_slice($datas,0,4)).(count($datas)>4?', …':'').')';

// ===== Encomendas (Fornecedor → Evento → Itens) =====
// Tenta enriquecer o rótulo do evento com data/hora/espaco quando possível.
$sql = "
  SELECT
    COALESCE(f.nome, 'Fornecedor #'||ei.fornecedor_id) AS fornecedor_nome,
    ei.evento_id,
    COALESCE(
      to_char(e.data_evento,'DD/MM')||' • '||substr(e.horario,1,5)||' • '||e.espaco,
      'Evento #'||ei.evento_id
    ) AS evento_label,
    COALESCE(i.nome, 'Item #'||ei.insumo_id) AS item_nome,
    COALESCE(ei.unidade,'') AS unidade,
    SUM(ei.quantidade)::numeric(12,3) AS quantidade
  FROM lc_encomendas_itens ei
  LEFT JOIN fornecedores f ON f.id = ei.fornecedor_id
  LEFT JOIN lc_insumos i   ON i.id = ei.insumo_id
  LEFT JOIN lc_lista_eventos e ON e.id = ei.evento_id
  WHERE ei.grupo_id = :g
  GROUP BY fornecedor_nome, ei.evento_id, evento_label, item_nome, unidade
  ORDER BY fornecedor_nome, evento_label, item_nome
";
$st = $pdo->prepare($sql);
$st->execute([':g'=>$grupo_id]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// Agrupa em PHP para render
$bySupplier = [];
foreach ($rows as $r) {
    $forn = (string)$r['fornecedor_nome'];
    $ev   = (string)$r['evento_label'];
    $bySupplier[$forn][$ev][] = $r;
}

// ===== Render HTML =====
ob_start();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Lista de Encomendas • Grupo #<?= (int)$grupo_id ?></title>
<style>
*{box-sizing:border-box}
body{font-family:Arial,Helvetica,sans-serif;margin:0;padding:24px;color:#111}
.header{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #000;padding-bottom:12px;margin-bottom:16px}
h1{font-size:22px;margin:0 0 6px 0}
.meta{font-size:12px;color:#444}
.badge{display:inline-block;font-size:11px;padding:2px 8px;border:1px solid #888;border-radius:999px;margin-left:6px}
.section{margin-top:14px;border:1px solid #ddd;border-radius:10px}
.section h3{margin:0;padding:10px 12px;background:#f7f9ff;border-bottom:1px solid #e5ecff}
.inner{padding:8px 12px}
table{width:100%;border-collapse:collapse;margin-top:6px}
th,td{border-bottom:1px solid #eee;padding:8px;text-align:left;vertical-align:top;font-size:13px}
th{background:#f4f6fb}
.small{font-size:11px;color:#555}
.right{text-align:right}
.nowrap{white-space:nowrap}
.print-hint{background:#fff6d6;border:1px solid #e6cf84;border-radius:6px;padding:8px;margin:8px 0}
@media print{
  .no-print{display:none}
  body{padding:0}
  .header{margin:0 0 8px 0;padding:0 0 8px 0}
}
</style>
</head>
<body>

<div class="header">
  <div>
    <h1>Lista de Encomendas</h1>
    <div class="meta">
      Grupo #<?= (int)$G['id'] ?> · <?= h($espaco_consolidado) ?> · <?= h($eventos_resumo) ?><br>
      Gerado em <?= h(dt($G['created_at'])) ?> · Por <?= h($G['criado_por_nome'] ?: ('ID '.$G['criado_por'])) ?>
    </div>
  </div>
  <div class="no-print">
    <button onclick="window.print()">Imprimir</button>
  </div>
</div>

<?php if (!$bySupplier): ?>
  <div class="small">Sem itens de encomendas para este grupo.</div>
<?php else: ?>
  <?php foreach ($bySupplier as $fornecedor => $byEvent): ?>
    <div class="section">
      <h3>Fornecedor: <?= h($fornecedor) ?></h3>
      <div class="inner">
        <?php foreach ($byEvent as $evLabel => $items): ?>
          <?php if ($evLabel !== '' && stripos($evLabel, 'Evento #') === false): ?>
            <div class="small" style="margin:4px 0 6px 0;">
              <strong>Evento:</strong> <?= h($evLabel) ?>
            </div>
          <?php endif; ?>
          <table>
            <thead>
              <tr>
                <th>Item</th>
                <th style="width:140px">Unidade</th>
                <th style="width:160px" class="right">Quantidade</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($items as $r): ?>
                <tr>
                  <td><?= h($r['item_nome']) ?></td>
                  <td><?= h($r['unidade']) ?></td>
                  <td class="right"><?= number_format((float)$r['quantidade'], 3, ',', '.') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <hr style="border:none;border-top:1px dashed #e6eeff;margin:10px 0">
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<div class="no-print">
  <div class="print-hint">
    Caso o PDF não baixe automaticamente, use “Imprimir” e salve como PDF. Se desejar PDF automático, instale o Dompdf (composer).
  </div>
</div>

</body>
</html>
<?php
$html = ob_get_clean();

// ===== Tenta gerar PDF com Dompdf (igual ao pdf_compras.php) =====
$dompdf_ok = false;
$autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
    try {
        if (class_exists('\\Dompdf\\Dompdf')) {
            $dompdf_ok = true;
            $dompdf = new \Dompdf\Dompdf([
                'isRemoteEnabled' => true,
                'defaultPaperSize' => 'a4',
                'isHtml5ParserEnabled' => true
            ]);
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();
            $fname = 'Lista_Encomendas_Grupo_'.$grupo_id.'.pdf';
            $dompdf->stream($fname, ['Attachment' => false]); // abre no navegador
            exit;
        }
    } catch (Throwable $e) {
        $dompdf_ok = false; // Fallback para HTML
    }
}

// Fallback: HTML imprimível
if (!$dompdf_ok) {
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
}
