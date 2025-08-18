<?php
// admin_pagamentos.php – Gestão/Exportação PagFor + contadores
session_start();
if (!isset($_SESSION['logado']) || $_SESSION['logado'] != 1) { header('Location: login.php'); exit; }
if (($_SESSION['perm_financeiro_admin'] ?? 0) != 1) { http_response_code(403); echo '<h1>Acesso negado</h1>'; exit; }

@include_once __DIR__ . '/conexao.php';
if (!isset($pdo)) { die('<p>Erro de conexão com o banco.</p>'); }

$STATUS = ['Pendente','Aguardando pagamento','Pago'];
$filtro = $_GET['status'] ?? '';

// Contadores
$counts = ['total'=>0,'Pendente'=>0,'Aguardando pagamento'=>0,'Pago'=>0];
$sqlc = "SELECT status, COUNT(*) q FROM solicitacoes_pagfor GROUP BY status";
foreach ($pdo->query($sqlc) as $row){ $counts[$row['status']] = (int)$row['q']; $counts['total'] += (int)$row['q']; }

// Atualiza status
if (isset($_POST['set_status'], $_POST['id'])) {
  $id = (int)$_POST['id'];
  $novo = in_array($_POST['set_status'], $STATUS) ? $_POST['set_status'] : 'Pendente';
  $pdo->prepare("UPDATE solicitacoes_pagfor SET status=:s WHERE id=:id")->execute([':s'=>$novo, ':id'=>$id]);
  header('Location: index.php?page=pagamentos_admin&status=' . urlencode($filtro)); exit;
}

// Exportar CSV
if (isset($_POST['exportar']) && !empty($_POST['ids'])) {
  $ids = array_map('intval', $_POST['ids']);
  $in  = implode(',', array_fill(0, count($ids), '?'));
  $st  = $pdo->prepare("SELECT * FROM solicitacoes_pagfor WHERE id IN ($in) ORDER BY id ASC");
  $st->execute($ids);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  $arquivos=[]; $chunk=150; $parts=array_chunk($rows,$chunk); $i=1;
  foreach ($parts as $parte){
    $fname = 'pagfor_' . date('Ymd_His') . sprintf('_%02d', $i) . '.csv';
    $fp = fopen('php://temp','w+');
    foreach ($parte as $r){
      $linha = [
        $r['forma_iniciacao'] ?? '',
        $r['numero_pagamento'],
        $r['chave_pix'],
        $r['ispb'],
        $r['banco'],
        $r['agencia'],
        $r['conta'],
        $r['tipo_conta'],
        $r['nome_fornecedor'],
        $r['tipo_documento'],
        $r['documento'],
        number_format((float)$r['valor'],2,'.',''),
        $r['data_pagamento'],
        $r['qrcode'],
        $r['info_recebedor'],
      ];
      fputcsv($fp,$linha,';');
    }
    rewind($fp); $arquivos[$fname]=stream_get_contents($fp); fclose($fp); $i++;
  }
  if (count($arquivos)===1){
    $nome = array_key_first($arquivos);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$nome.'"');
    echo $arquivos[$nome]; exit;
  } else {
    $zipname='pagfor_'.date('Ymd_His').'.zip';
    $zip=new ZipArchive(); $tmp=tempnam(sys_get_temp_dir(),'zip'); $zip->open($tmp,ZipArchive::OVERWRITE);
    foreach($arquivos as $nome=>$conteudo){ $zip->addFromString($nome,$conteudo); }
    $zip->close(); header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="'.$zipname.'"'); readfile($tmp); unlink($tmp); exit;
  }
}

// Lista
$st = $pdo->prepare("SELECT * FROM solicitacoes_pagfor WHERE (:f='' OR status=:f) ORDER BY criado_em DESC");
$st->execute([':f'=> in_array($filtro,$STATUS)?$filtro:'']);
$lista = $st->fetchAll(PDO::FETCH_ASSOC);

function Card($title,$value){ echo '<div class="card" style="width:220px"><h3 style="margin:0">'.$title.'</h3><p style="font-size:28px;margin:6px 0 0 0">'.$value.'</p></div>'; }
?>
<h1>Gestão de Pagamentos (Admin)</h1>

<div style="display:flex;gap:12px;flex-wrap:wrap;margin:10px 0 16px 0">
  <?php Card('Total', $counts['total']); ?>
  <?php Card('Pendente', $counts['Pendente']); ?>
  <?php Card('Aguardando', $counts['Aguardando pagamento']); ?>
  <?php Card('Pago', $counts['Pago']); ?>
</div>

<form method="get" style="margin-bottom:10px">
  <input type="hidden" name="page" value="pagamentos_admin">
  <label>Status:
    <select name="status" onchange="this.form.submit()">
      <option value="">Todos</option>
      <?php foreach ($STATUS as $s): ?>
        <option value="<?php echo $s; ?>" <?php echo ($filtro===$s)?'selected':''; ?>><?php echo $s; ?></option>
      <?php endforeach; ?>
    </select>
  </label>
</form>

<form method="post">
  <div class="card" style="padding:12px;overflow:auto">
    <table style="width:100%; border-collapse:collapse">
      <thead>
        <tr>
          <th style="text-align:left;padding:8px;border-bottom:1px solid #ddd"><input type="checkbox" onclick="document.querySelectorAll('.ck').forEach(c=>c.checked=this.checked)"></th>
          <th style="text-align:left;padding:8px;border-bottom:1px solid #ddd">#</th>
          <th style="text-align:left;padding:8px;border-bottom:1px solid #ddd">Número</th>
          <th style="text-align:left;padding:8px;border-bottom:1px solid #ddd">Fornecedor</th>
          <th style="text-align:left;padding:8px;border-bottom:1px solid #ddd">Doc</th>
          <th style="text-align:left;padding:8px;border-bottom:1px solid #ddd">Valor</th>
          <th style="text-align:left;padding:8px;border-bottom:1px solid #ddd">Data</th>
          <th style="text-align:left;padding:8px;border-bottom:1px solid #ddd">Status</th>
          <th style="text-align:left;padding:8px;border-bottom:1px solid #ddd">Ações</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($lista as $r): ?>
        <tr>
          <td style="padding:6px;border-bottom:1px solid #eee"><input class="ck" type="checkbox" name="ids[]" value="<?php echo (int)$r['id']; ?>"></td>
          <td style="padding:6px;border-bottom:1px solid #eee"><?php echo (int)$r['id']; ?></td>
          <td style="padding:6px;border-bottom:1px solid #eee"><?php echo htmlspecialchars($r['numero_pagamento']); ?></td>
          <td style="padding:6px;border-bottom:1px solid #eee"><?php echo htmlspecialchars($r['nome_fornecedor']); ?></td>
          <td style="padding:6px;border-bottom:1px solid #eee"><?php echo htmlspecialchars($r['tipo_documento'].' '.$r['documento']); ?></td>
          <td style="padding:6px;border-bottom:1px solid #eee">R$ <?php echo number_format($r['valor'],2,',','.'); ?></td>
          <td style="padding:6px;border-bottom:1px solid #eee"><?php echo htmlspecialchars($r['data_pagamento']); ?></td>
          <td style="padding:6px;border-bottom:1px solid #eee"><?php echo htmlspecialchars($r['status']); ?></td>
          <td style="padding:6px;border-bottom:1px solid #eee">
            <form method="post" style="display:inline">
              <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
              <select name="set_status" onchange="this.form.submit()">
                <?php foreach ($STATUS as $s): ?>
                  <option value="<?php echo $s; ?>" <?php echo ($r['status']===$s)?'selected':''; ?>><?php echo $s; ?></option>
                <?php endforeach; ?>
              </select>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$lista): ?>
        <tr><td colspan="9" style="padding:10px;color:#666">Nenhuma solicitação.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div style="margin-top:10px;display:flex;gap:10px">
    <button type="submit" name="exportar" value="1">Exportar selecionados (CSV PagFor)</button>
    <a class="card" href="index.php?page=pagamentos" style="text-decoration:none;padding:12px">+ Nova solicitação</a>
  </div>
</form>
