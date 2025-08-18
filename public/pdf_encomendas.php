<?php
// pdf_encomendas.php — Visualização/geração de PDF das ENCOMENDAS por fornecedor
// Usa Dompdf se disponível; senão, exibe HTML imprimível.

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
function num($v){ return number_format((float)$v, 4, ',', '.'); }

$grupo_id = isset($_GET['grupo_id']) ? (int)$_GET['grupo_id'] : 0;
if ($grupo_id <= 0) { echo "grupo_id inválido."; exit; }

// Cabeçalho da geração
$ger = $pdo->prepare("SELECT g.id, g.grupo_token, g.criado_por, g.criado_por_nome, g.created_at
                      FROM lc_geracoes g WHERE g.id=?");
$ger->execute([$grupo_id]);
$G = $ger->fetch(PDO::FETCH_ASSOC);
if (!$G) { echo "Geração não encontrada."; exit; }

// Eventos do grupo (para mapear detalhes quando modo = separado por evento)
$stEv = $pdo->prepare("SELECT id, espaco, convidados, horario, evento_texto, data_evento, dia_semana
                       FROM lc_lista_eventos WHERE grupo_id=?");
$stEv->execute([$grupo_id]);
$EVS = $stEv->fetchAll(PDO::FETCH_ASSOC);
$mapEv = [];
$datasParaResumo = [];
$espacosResumo = [];
foreach ($EVS as $e){
    $mapEv[(int)$e['id']] = $e;
    $datasParaResumo[] = date('d/m', strtotime($e['data_evento']));
    $espacosResumo[$e['espaco']] = true;
}
$espaco_consolidado = (count($espacosResumo) === 1) ? array_key_first($espacosResumo) : 'Múltiplos espaços';
$eventos_resumo = count($EVS).' evento'.(count($EVS)>1?'s':'').' ('.implode(', ', array_slice($datasParaResumo,0,4)).(count($datasParaResumo)>4?', …':'').')';

// Itens de encomenda
$sql = "SELECT ei.id, ei.fornecedor_id, f.nome AS fornecedor_nome, f.whatsapp, f.email, f.observacoes,
               ei.evento_id, ei.item_id, it.nome AS item_nome, ei.quantidade, ei.unidade, ei.modo_fornecedor,
               le.espaco, le.data_evento, le.horario, le.evento_texto, le.dia_semana
        FROM lc_encomendas_itens ei
        JOIN lc_itens it ON it.id = ei.item_id
        JOIN lc_fornecedores f ON f.id = ei.fornecedor_id
        LEFT JOIN lc_lista_eventos le ON le.id = ei.evento_id
        WHERE ei.grupo_id=?
        ORDER BY f.nome ASC,
                 CASE WHEN ei.evento_id IS NULL THEN 1 ELSE 0 END ASC,
                 le.data_evento ASC, le.horario ASC,
                 it.nome ASC, ei.id ASC";
$st = $pdo->prepare($sql);
$st->execute([$grupo_id]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// Agrupa por fornecedor
$byForn = []; // forn_id => ['info'=>..., 'consolidado'=>[key=>['item_nome','un','q']], 'por_evento'=>[eid=>[...]]]
foreach ($rows as $r){
    $fid = (int)$r['fornecedor_id'];
    if (!isset($byForn[$fid])){
        $byForn[$fid] = [
            'info' => [
                'nome'=>$r['fornecedor_nome'],
                'whatsapp'=>$r['whatsapp'],
                'email'=>$r['email'],
                'observacoes'=>$r['observacoes'],
            ],
            'consolidado' => [],   // key=item_id|un -> ['item_nome'=>, 'un'=>, 'q'=>sum]
            'por_evento'  => [],   // eid => key=item_id|un -> ['item_nome'=>, 'un'=>, 'q'=>sum]
        ];
    }
    $key = $r['item_id'].'|'.$r['unidade'];
    if ($r['evento_id'] === null) {
        if (!isset($byForn[$fid]['consolidado'][$key])){
            $byForn[$fid]['consolidado'][$key] = ['item_nome'=>$r['item_nome'], 'un'=>$r['unidade'], 'q'=>0];
        }
        $byForn[$fid]['consolidado'][$key]['q'] += (float)$r['quantidade'];
    } else {
        $eid = (int)$r['evento_id'];
        if (!isset($byForn[$fid]['por_evento'][$eid])) $byForn[$fid]['por_evento'][$eid] = [];
        if (!isset($byForn[$fid]['por_evento'][$eid][$key])){
            $byForn[$fid]['por_evento'][$eid][$key] = ['item_nome'=>$r['item_nome'], 'un'=>$r['unidade'], 'q'=>0];
        }
        $byForn[$fid]['por_evento'][$eid][$key]['q'] += (float)$r['quantidade'];
    }
}

// HTML
ob_start();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Encomendas • Grupo #<?= (int)$G['id'] ?></title>
<style>
*{box-sizing:border-box}
body{font-family:Arial,Helvetica,sans-serif;margin:0;padding:24px;color:#111}
.header{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #000;padding-bottom:12px;margin-bottom:16px}
h1{font-size:22px;margin:0 0 6px 0}
.meta{font-size:12px;color:#444}
.section{margin-top:14px}
.subhead{font-size:14px;margin:10px 0 4px 0}
.badge{display:inline-block;font-size:11px;padding:2px 8px;border:1px solid #888;border-radius:999px;margin-left:6px}
.card{border:1px solid #ddd;border-radius:8px;padding:10px;margin-top:6px}
table{width:100%;border-collapse:collapse;margin-top:6px}
th,td{border-bottom:1px solid #ddd;padding:8px;text-align:left;vertical-align:top;font-size:13px}
th{background:#f4f6fb}
.right{text-align:right}
.small{font-size:11px;color:#555}
.no-print{margin-top:8px}
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
    <h1>Encomendas por fornecedor</h1>
    <div class="meta">
      Grupo #<?= (int)$G['id'] ?> · <?= h($espaco_consolidado) ?> · <?= h($eventos_resumo) ?><br>
      Gerado em <?= h(dt($G['created_at'])) ?> · Por <?= h($G['criado_por_nome'] ?: ('ID '.$G['criado_por'])) ?>
    </div>
  </div>
  <div class="no-print">
    <button onclick="window.print()">Imprimir</button>
  </div>
</div>

<?php if (!$rows): ?>
  <p>Nenhuma encomenda gerada para este grupo.</p>
<?php else: ?>
  <?php foreach ($byForn as $fid=>$data): ?>
    <div class="section">
      <div class="card">
        <div class="subhead"><b><?= h($data['info']['nome']) ?></b></div>
        <div class="small">
            <?php if ($data['info']['whatsapp']): ?>WhatsApp: <?= h($data['info']['whatsapp']) ?> · <?php endif; ?>
            <?php if ($data['info']['email']): ?>E-mail: <?= h($data['info']['email']) ?><?php endif; ?>
            <?php if ($data['info']['observacoes']): ?><br>Obs. fornecedor: <?= h($data['info']['observacoes']) ?><?php endif; ?>
        </div>

        <?php if (!empty($data['consolidado'])): ?>
          <div class="subhead">Itens consolidados</div>
          <table>
            <thead>
              <tr>
                <th>Item</th>
                <th style="width:18%">Unidade</th>
                <th style="width:20%" class="right">Quantidade</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($data['consolidado'] as $row): ?>
                <tr>
                  <td><?= h($row['item_nome']) ?></td>
                  <td><?= h($row['un']) ?></td>
                  <td class="right"><b><?= num($row['q']) ?></b></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>

        <?php if (!empty($data['por_evento'])): ?>
          <div class="subhead">Itens separados por evento</div>
          <?php
            // Ordena por data/hora do evento
            uksort($data['por_evento'], function($a,$b) use($mapEv){
                $ea = $mapEv[$a] ?? null; $eb = $mapEv[$b] ?? null;
                $ka = ($ea['data_evento'] ?? '').' '.($ea['horario'] ?? '');
                $kb = ($eb['data_evento'] ?? '').' '.($eb['horario'] ?? '');
                return strcmp($ka, $kb);
            });
          ?>
          <?php foreach ($data['por_evento'] as $eid=>$items): $ev=$mapEv[$eid]??null; ?>
            <div class="card">
              <div class="small">
                <?php if ($ev): ?>
                  <b>Evento:</b> <?= h($ev['evento_texto']) ?> · <b>Espaço:</b> <?= h($ev['espaco']) ?>
                  · <b>Data:</b> <?= h(dt($ev['data_evento'],'d/m/Y')) ?> (<?= h($ev['dia_semana']) ?>)
                  · <b>Hora:</b> <?= h(substr($ev['horario'],0,5)) ?>
                  · <b>Convidados:</b> <?= (int)$ev['convidados'] ?>
                <?php else: ?>
                  <b>Evento:</b> #<?= (int)$eid ?>
                <?php endif; ?>
              </div>
              <table>
                <thead>
                  <tr>
                    <th>Item</th>
                    <th style="width:18%">Unidade</th>
                    <th style="width:20%" class="right">Quantidade</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($items as $row): ?>
                    <tr>
                      <td><?= h($row['item_nome']) ?></td>
                      <td><?= h($row['un']) ?></td>
                      <td class="right"><b><?= num($row['q']) ?></b></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>

      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<div class="section no-print">
  <div class="small">
    Caso o PDF não baixe automaticamente, use “Imprimir” e salve como PDF. Para baixar automático, instale Dompdf (composer).
  </div>
</div>

</body>
</html>
<?php
$html = ob_get_clean();

// Tenta PDF via Dompdf
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
            $fname = 'Encomendas_Grupo_'.$grupo_id.'.pdf';
            $dompdf->stream($fname, ['Attachment' => false]); // abre no navegador
            exit;
        }
    } catch (Throwable $e) { $dompdf_ok = false; }
}

// Fallback HTML
if (!$dompdf_ok) {
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
}
