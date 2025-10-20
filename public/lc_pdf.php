<?php
// public/lc_pdf.php
// Gera PDF de uma lista de compras/encomendas com logotipo oficial

session_start();
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/lc_config_helper.php';
require_once __DIR__ . '/fpdf/fpdf.php'; // use o FPDF que já está na pasta public/

// Configurações
$precQ = (int)lc_get_config($pdo, 'precisao_quantidade', 3);
$precV = (int)lc_get_config($pdo, 'precisao_valor', 2);
$showCPPdf = lc_get_config($pdo, 'mostrar_custo_pdf', '1') === '1';

$id   = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$tipo = isset($_GET['tipo']) ? $_GET['tipo'] : 'compras'; // compras|encomendas

if ($id <= 0) {
  die('Lista inválida.');
}

// Cabeçalho
$stmt = $pdo->prepare("
  SELECT l.*, u.nome AS criado_por_nome
  FROM smilee12_painel_smile.lc_listas l
  LEFT JOIN smilee12_painel_smile.usuarios u ON u.id = l.criado_por
  WHERE l.id = :id
");
$stmt->execute([':id' => $id]);
$lista = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$lista) {
  die('Lista não encontrada.');
}

// Eventos vinculados
$stmtEv = $pdo->prepare("SELECT * FROM smilee12_painel_smile.lc_listas_eventos WHERE lista_id = :id ORDER BY id");
$stmtEv->execute([':id' => $id]);
$eventos = $stmtEv->fetchAll(PDO::FETCH_ASSOC);

// Itens
if ($tipo === 'encomendas') {
  $stmtItens = $pdo->prepare("
    SELECT e.*, f.nome AS fornecedor_nome
    FROM smilee12_painel_smile.lc_encomendas_itens e
    LEFT JOIN smilee12_painel_smile.fornecedores f ON f.id = e.fornecedor_id
    WHERE e.lista_id = :id
    ORDER BY f.nome NULLS LAST, e.evento_id, e.item_nome
  ");
} else {
  $stmtItens = $pdo->prepare("
    SELECT * FROM smilee12_painel_smile.lc_compras_consolidadas
    WHERE lista_id = :id
    ORDER BY insumo_nome
  ");
}
$stmtItens->execute([':id' => $id]);
$itens = $stmtItens->fetchAll(PDO::FETCH_ASSOC);

// Funções auxiliares
function utf($s){ return utf8_decode((string)$s); }
function br($pdf, $h=5){ $pdf->Ln($h); }

// Iniciar PDF
$pdf = new FPDF('P','mm','A4');
$pdf->AddPage();

// --- LOGO ---
$logoPath = __DIR__ . '/logo.png';
if (file_exists($logoPath)) {
  $pdf->Image($logoPath, 10, 8, 35); // x, y, largura
}
$pdf->SetFont('Arial','B',14);
$pdf->Cell(0,8,utf('Grupo Smile Eventos'),0,1,'C');
$pdf->SetFont('Arial','',12);
$pdf->Cell(0,6,utf('Lista de '.ucfirst($tipo)),0,1,'C');
br($pdf, 10);

// --- Cabeçalho info ---
$pdf->SetFont('Arial','',9);
$pdf->MultiCell(0,5,
  utf(
    "Nº da Lista: {$lista['id']}\n".
    "Data gerada: ".date('d/m/Y H:i', strtotime($lista['criado_em']))."\n".
    "Espaço: ".($lista['espaco_resumo'] ?: 'Múltiplos')."\n".
    "Eventos: ".$lista['resumo_eventos']."\n".
    "Criado por: ".($lista['criado_por_nome'] ?: '#'.$lista['criado_por'])
  ),
  0,'L'
);
br($pdf, 5);

// --- Eventos vinculados ---
if ($eventos) {
  $pdf->SetFont('Arial','B',10);
  $pdf->Cell(0,6,utf('Eventos vinculados:'),0,1);
  $pdf->SetFont('Arial','',9);
  foreach ($eventos as $e) {
    $linha = "Evento #{$e['evento_id']} | Convidados: {$e['convidados']} | Data: {$e['data_evento']} | {$e['resumo']}";
    $pdf->Cell(0,5,utf($linha),0,1);
  }
  br($pdf, 4);
}

// --- Itens ---
$totalGeral = 0;

if ($tipo === 'compras') {
  $pdf->SetFont('Arial','B',10);
  $pdf->Cell(0,6,utf('Compras (insumos internos e fixos)'),0,1);
  $pdf->SetFont('Arial','B',9);
  $pdf->Cell(100,6,utf('Insumo'),1,0);
  $pdf->Cell(25,6,utf('Qtd'),1,0,'C');
  $pdf->Cell(25,6,utf('Unid.'),1,0,'C');
  $pdf->Cell(35,6,utf('Custo'),1,1,'C');
  $pdf->SetFont('Arial','',9);

  foreach ($itens as $i) {
    $totalGeral += (float)$i['custo'];
    $pdf->Cell(100,6,utf($i['insumo_nome']),1,0);
    $pdf->Cell(25,6,number_format($i['qtd'],$precQ,',','.'),1,0,'R');
    $pdf->Cell(25,6,utf($i['unidade_simbolo']),1,0,'C');
    $pdf->Cell(35,6,'R$ '.number_format($i['custo'],$precV,',','.'),1,1,'R');
  }
} else {
  // ENCOMENDAS — agrupadas por fornecedor → evento
  $pdf->SetFont('Arial','B',10);
  $pdf->Cell(0,6,utf('Encomendas (Fornecedor → Evento)'),0,1);
  $pdf->SetFont('Arial','',9);

  $grp = [];
  foreach ($itens as $r) {
    $forn = $r['fornecedor_nome'] ?: 'Sem fornecedor';
    $grp[$forn][$r['evento_id']][] = $r;
  }

  foreach ($grp as $forn => $eventos) {
    br($pdf, 3);
    $pdf->SetFont('Arial','B',9);
    $pdf->Cell(0,6,utf('Fornecedor: '.$forn),0,1);
    foreach ($eventos as $evId => $rows) {
      $pdf->SetFont('Arial','I',9);
      $pdf->Cell(0,5,utf("Evento #$evId"),0,1);
      $pdf->SetFont('Arial','',9);
      $pdf->Cell(90,6,utf('Item'),1,0);
      $pdf->Cell(25,6,utf('Qtd'),1,0,'C');
      $pdf->Cell(25,6,utf('Unid.'),1,0,'C');
      $pdf->Cell(35,6,utf('Custo'),1,1,'C');
      foreach ($rows as $r) {
        $totalGeral += (float)$r['custo'];
        $pdf->Cell(90,6,utf($r['item_nome']),1,0);
        $pdf->Cell(25,6,number_format($r['qtd'],$precQ,',','.'),1,0,'R');
        $pdf->Cell(25,6,utf($r['unidade_simbolo']),1,0,'C');
        $pdf->Cell(35,6,'R$ '.number_format($r['custo'],$precV,',','.'),1,1,'R');
      }
    }
  }
}

// --- Custo por Convidado (se habilitado) ---
if ($showCPPdf) {
  // Calcular total de convidados
  $stTmp = $pdo->prepare("SELECT COALESCE(SUM(convidados),0) FROM smilee12_painel_smile.lc_listas_eventos WHERE lista_id = :id");
  $stTmp->execute([':id'=>$id]);
  $totConvidados = (int)$stTmp->fetchColumn();
  
  if ($totConvidados > 0) {
    $custoPorConvidado = $totalGeral / $totConvidados;
    br($pdf, 4);
    $pdf->SetFont('Arial','',9);
    $pdf->Cell(0,6,utf("Total de convidados: {$totConvidados}"),0,1,'R');
    $pdf->Cell(0,6,utf('Custo por convidado: R$ '.number_format($custoPorConvidado,$precV,',','.')),0,1,'R');
  }
}

// --- Total Geral ---
br($pdf, 6);
$pdf->SetFont('Arial','B',10);
$pdf->Cell(0,8,utf('Total geral: R$ '.number_format($totalGeral,$precV,',','.')),0,1,'R');

$pdf->Output('I', "Lista_{$tipo}_{$id}.pdf");
