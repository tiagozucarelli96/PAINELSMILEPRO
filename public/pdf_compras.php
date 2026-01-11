<?php
// pdf_compras.php — Visualização/geração de PDF da LISTA DE COMPRAS
// Tenta usar Dompdf se disponível. Se não, exibe HTML imprimível.

if (session_status() === PHP_SESSION_NONE) { session_start(); }
ini_set('display_errors', 1); error_reporting(E_ALL);

if (!isset($_SESSION['logado']) || $_SESSION['logado'] != 1) {
    http_response_code(403);
    echo "Acesso negado.";
    exit;
}

@include_once __DIR__ . '/conexao.php';
if (!isset($pdo)) { echo "Falha na conexão."; exit; }


function dt($s, $fmt='d/m/Y H:i'){ return $s ? date($fmt, strtotime($s)) : ''; }

$grupo_id = isset($_GET['grupo_id']) ? (int)$_GET['grupo_id'] : 0;
if ($grupo_id <= 0) { echo "grupo_id inválido."; exit; }

// Cabeçalhos
$ger = $pdo->prepare("SELECT g.id, g.grupo_token, g.criado_por, g.criado_por_nome, g.created_at
                      FROM lc_geracoes g WHERE g.id=?");
$ger->execute([$grupo_id]);
$G = $ger->fetch(PDO::FETCH_ASSOC);
if (!$G) { echo "Geração não encontrada."; exit; }

// Eventos do grupo
$stEv = $pdo->prepare("SELECT id, espaco, convidados, horario, evento_texto, data_evento, dia_semana
                       FROM lc_lista_eventos WHERE grupo_id=? ORDER BY data_evento ASC, horario ASC, id ASC");
$stEv->execute([$grupo_id]);
$EVS = $stEv->fetchAll(PDO::FETCH_ASSOC);

// Compras consolidadas
$stC = $pdo->prepare("SELECT c.id, c.insumo_id, i.nome AS insumo_nome, c.unidade, c.qtd_bruta, c.qtd_final, c.foi_arredondado, c.origem_json
                      FROM lc_compras_consolidadas c
                      JOIN lc_insumos i ON i.id=c.insumo_id
                      WHERE c.grupo_id=?
                      ORDER BY i.nome ASC, c.unidade ASC, c.id ASC");
$stC->execute([$grupo_id]);
$COMPRAS = $stC->fetchAll(PDO::FETCH_ASSOC);

// Resumo para título
$espacos = [];
$datas = [];
foreach ($EVS as $e) {
    $espacos[$e['espaco']] = true;
    $datas[] = date('d/m', strtotime($e['data_evento']));
}
$espaco_consolidado = (count($espacos) === 1) ? array_key_first($espacos) : 'Múltiplos espaços';
$eventos_resumo = count($EVS).' evento'.(count($EVS)>1?'s':'').' ('.implode(', ', array_slice($datas,0,4)).(count($datas)>4?', …':'').')';

//
// Render HTML
//
ob_start();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Lista de Compras • Grupo #<?= (int)$grupo_id ?></title>
<style>
*{box-sizing:border-box}
body{font-family:Arial,Helvetica,sans-serif;margin:0;padding:24px;color:#111}
.header{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #000;padding-bottom:12px;margin-bottom:16px}
h1{font-size:22px;margin:0 0 6px 0}
.meta{font-size:12px;color:#444}
.badge{display:inline-block;font-size:11px;padding:2px 8px;border:1px solid #888;border-radius:999px;margin-left:6px}
.section{margin-top:12px}
table{width:100%;border-collapse:collapse;margin-top:6px}
th,td{border-bottom:1px solid #ddd;padding:8px;text-align:left;vertical-align:top;font-size:13px}
th{background:#f4f6fb}
.small{font-size:11px;color:#555}
.right{text-align:right}
.nowrap{white-space:nowrap}
.row{display:flex;gap:10px;flex-wrap:wrap}
.card{border:1px solid #ddd;border-radius:8px;padding:10px;margin-top:6px}
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
    <h1>Lista de Compras</h1>
    <div class="meta">
      Grupo #<?= (int)$G['id'] ?> · <?= h($espaco_consolidado) ?> · <?= h($eventos_resumo) ?><br>
      Gerado em <?= h(dt($G['created_at'])) ?> · Por <?= h($G['criado_por_nome'] ?: ('ID '.$G['criado_por'])) ?>
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
        <th style="width:22%">Espaço</th>
        <th style="width:10%">Data</th>
        <th style="width:10%">Dia</th>
        <th style="width:10%">Hora</th>
        <th>Evento</th>
        <th style="width:10%" class="right">Convid.</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$EVS): ?>
        <tr><td colspan="6">Sem eventos.</td></tr>
      <?php else: foreach ($EVS as $e): ?>
        <tr>
          <td><?= h($e['espaco']) ?></td>
          <td class="nowrap"><?= h(dt($e['data_evento'], 'd/m/Y')) ?></td>
          <td><?= h($e['dia_semana']) ?></td>
          <td class="nowrap"><?= h(substr($e['horario'],0,5)) ?></td>
          <td><?= h($e['evento_texto']) ?></td>
          <td class="right"><?= (int)$e['convidados'] ?></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<div class="section">
  <h3 style="margin:0">Insumos consolidados</h3>
  <table>
    <thead>
      <tr>
        <th>Insumo</th>
        <th style="width:12%">Unidade</th>
        <th style="width:16%" class="right">Qtd. bruta</th>
        <th style="width:16%" class="right">Qtd. final</th>
        <th style="width:14%">Arred.</th>
        <th class="small">Origem por evento</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$COMPRAS): ?>
        <tr><td colspan="6">Nenhum insumo calculado.</td></tr>
      <?php else: foreach ($COMPRAS as $c): ?>
        <tr>
          <td><?= h($c['insumo_nome']) ?></td>
          <td><?= h($c['unidade']) ?></td>
          <td class="right"><?= number_format((float)$c['qtd_bruta'], 4, ',', '.') ?></td>
          <td class="right"><b><?= number_format((float)$c['qtd_final'], 4, ',', '.') ?></b></td>
          <td><?= $c['foi_arredondado'] ? '<span class="badge">aplicado</span>' : '' ?></td>
          <td class="small">
            <?php
              // Mostra resumo curto da origem por evento: EV#id:qtd
              $orig = $c['origem_json'] ? json_decode($c['origem_json'], true) : [];
              if ($orig && is_array($orig)) {
                  $parts = [];
                  foreach ($orig as $eid=>$q) { $parts[] = 'EV#'.$eid.': '.number_format((float)$q, 4, ',', '.'); }
                  echo h(implode(' • ', $parts));
              }
            ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<div class="section no-print">
  <div class="print-hint">
    Caso o PDF não baixe automaticamente, use “Imprimir” e salve como PDF. Se desejar PDF automático, instale o Dompdf (composer) no servidor.
  </div>
</div>

</body>
</html>
<?php
$html = ob_get_clean();

//
// Tenta gerar PDF com Dompdf se disponível
//
$dompdf_ok = false;
$autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
    try {
        // Dompdf 2.x
        if (class_exists('\\Dompdf\\Dompdf')) {
            $dompdf_ok = true;
            $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => true, 'defaultPaperSize' => 'a4', 'isHtml5ParserEnabled' => true]);
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();
            // Nome do arquivo
            $fname = 'Lista_Compras_Grupo_'.$grupo_id.'.pdf';
            $dompdf->stream($fname, ['Attachment' => false]); // abre no navegador
            exit;
        }
    } catch (Throwable $e) {
        // Silencia e cai para HTML
        $dompdf_ok = false;
    }
}

// Fallback: HTML imprimível
if (!$dompdf_ok) {
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
}
