<?php
// pagamentos.php — Tabela única + Rascunho + Tipo de Chave Pix + Bancários completos
// (Deixe o session_start() no index.php. Se abrir este arquivo isolado, descomente abaixo.)
// session_start();

if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION) || ($_SESSION['logado'] ?? 0) != 1) { header('Location: login.php'); exit; }
@include_once __DIR__ . '/conexao.php';
if (!isset($pdo)) { die('<p>Erro de conexão com o banco.</p>'); }
require_once __DIR__ . '/sidebar_unified.php';
require_once __DIR__ . '/core/helpers.php';

/*
  Se ainda não tiver esses campos/valores, rode no MySQL:

  ALTER TABLE solicitacoes_pagfor
    ADD COLUMN IF NOT EXISTS tipo_chave_pix ENUM('CPF/CNPJ','E-mail','Telefone','Chave aleatória','QR Code') NULL AFTER chave_pix,
    ADD COLUMN IF NOT EXISTS ispb VARCHAR(20) NULL AFTER chave_pix,
    ADD COLUMN IF NOT EXISTS banco VARCHAR(80) NULL AFTER ispb,
    ADD COLUMN IF NOT EXISTS agencia VARCHAR(20) NULL AFTER banco,
    ADD COLUMN IF NOT EXISTS conta VARCHAR(30) NULL AFTER agencia,
    ADD COLUMN IF NOT EXISTS tipo_conta VARCHAR(5) NULL AFTER conta,
    MODIFY COLUMN status ENUM('Rascunho','Pendente','Aguardando pagamento','Pago') DEFAULT 'Rascunho';
*/

// ----------------- Helpers -----------------
function validarCPF(string $cpf): bool {
  $cpf = preg_replace('/\D/','',$cpf);
  if (strlen($cpf)!=11 || preg_match('/^(\d)\1{10}$/',$cpf)) return false;
  $s=0; for($i=0,$p=10;$i<9;$i++,$p--) $s+=(int)$cpf[$i]*$p;
  $r=$s%11; $d1=($r<2)?0:11-$r;
  $s=0; for($i=0,$p=11;$i<10;$i++,$p--) $s+=(int)$cpf[$i]*$p;
  $r=$s%11; $d2=($r<2)?0:11-$r;
  return ($cpf[9]==$d1)&&($cpf[10]==$d2);
}
function validarCNPJ(string $cnpj): bool {
  $cnpj=preg_replace('/\D/','',$cnpj);
  if (strlen($cnpj)!=14 || preg_match('/^(\d)\1{13}$/',$cnpj)) return false;
  $p1=[5,4,3,2,9,8,7,6,5,4,3,2]; $p2=[6,5,4,3,2,9,8,7,6,5,4,3,2];
  $s=0; for($i=0;$i<12;$i++) $s+=(int)$cnpj[$i]*$p1[$i];
  $r=$s%11; $d1=($r<2)?0:11-$r;
  $s=0; for($i=0;$i<13;$i++) $s+=(int)$cnpj[$i]*$p2[$i];
  $r=$s%11; $d2=($r<2)?0:11-$r;
  return ($cnpj[12]==$d1)&&($cnpj[13]==$d2);
}
function formaIniciacaoPorTipoChave(?string $tipo): string {
  return match($tipo){
    'CPF/CNPJ'        => 'CPF/CNPJ',
    'E-mail'          => 'Email',
    'Telefone'        => 'Telefone',
    'Chave aleatória' => 'Chave Aleatória',
    'QR Code'         => 'QR Code',
    default           => ''
  };
}

// -------------- Carregar rascunho p/ edição --------------
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$edit = null;
if ($editId) {
  $st = $pdo->prepare("SELECT * FROM solicitacoes_pagfor WHERE id=:id AND criado_por=:u LIMIT 1");
  $st->execute([':id'=>$editId, ':u'=>$_SESSION['id_usuario'] ?? 0]);
  $edit = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

// -------------- Ações --------------
$msg=''; $erro='';

if ($_SERVER['REQUEST_METHOD']==='POST') {
  // Apagar rascunho
  if (isset($_POST['del_id'])) {
    $id = (int)$_POST['del_id'];
    $pdo->prepare("DELETE FROM solicitacoes_pagfor WHERE id=:id AND criado_por=:u AND status='Rascunho'")
        ->execute([':id'=>$id, ':u'=>$_SESSION['id_usuario'] ?? 0]);
    $msg='Rascunho apagado.';
  }
  // Enviar selecionados
  elseif (isset($_POST['enviar']) && !empty($_POST['ids'])) {
    $ids = array_map('intval', $_POST['ids']);
    if ($ids){
      $in = implode(',', array_fill(0,count($ids),'?'));
      $sel = $pdo->prepare("SELECT id,tipo_chave_pix FROM solicitacoes_pagfor WHERE id IN ($in) AND criado_por=?");
      $sel->execute(array_merge($ids, [$_SESSION['id_usuario'] ?? 0]));
      $rows=$sel->fetchAll(PDO::FETCH_ASSOC);
      $pdo->beginTransaction();
      try{
        foreach($rows as $r){
          $forma = formaIniciacaoPorTipoChave($r['tipo_chave_pix'] ?? '');
          $pdo->prepare("UPDATE solicitacoes_pagfor SET status='Pendente', forma_iniciacao=:f WHERE id=:id")
              ->execute([':f'=>$forma, ':id'=>$r['id']]);
        }
        $pdo->commit(); $msg='Solicitações enviadas.';
      }catch(Throwable $e){ $pdo->rollBack(); $erro='Erro ao enviar: '.$e->getMessage(); }
    }
  }
  // Salvar / Atualizar rascunho
  else {
    $id = (int)($_POST['id'] ?? 0);
    $numero_pagamento = trim($_POST['numero_pagamento'] ?? '');
    $tipo_chave_pix   = $_POST['tipo_chave_pix'] ?? 'CPF/CNPJ';
    $chave_pix        = trim($_POST['chave_pix'] ?? '');
    $qrcode           = trim($_POST['qrcode'] ?? '');
    $ispb             = trim($_POST['ispb'] ?? '');
    $banco            = trim($_POST['banco'] ?? '');
    $agencia          = trim($_POST['agencia'] ?? '');
    $conta            = trim($_POST['conta'] ?? '');
    $tipo_conta       = trim($_POST['tipo_conta'] ?? '');
    $nome_fornecedor  = trim($_POST['nome_fornecedor'] ?? '');
    $tipo_documento   = (($_POST['tipo_documento'] ?? 'PF')==='PJ')?'PJ':'PF';
    $documento        = preg_replace('/\D+/','', $_POST['documento'] ?? '');
    $valor_str        = str_replace(',', '.', trim($_POST['valor'] ?? ''));
    $data_pagamento   = trim($_POST['data_pagamento'] ?? '');

    // Regras
    if ($numero_pagamento==='' || $nome_fornecedor==='' || $documento==='' || $data_pagamento==='') {
      $erro='Preencha os campos obrigatórios (*).';
    }
    if (!$erro && !is_numeric($valor_str)) { $erro='Valor inválido. Use 1234.56'; }
    if (!$erro) {
      if ($tipo_documento==='PF' && !validarCPF($documento)) $erro='CPF inválido.';
      if ($tipo_documento==='PJ' && !validarCNPJ($documento)) $erro='CNPJ inválido.';
    }
    // Chave: ou texto (CPF/E-mail/Telefone/Aleatória) OU QR Code
    if (!$erro){
      if ($tipo_chave_pix==='QR Code'){
        if ($qrcode==='') $erro='Informe o QR Code (copia e cola).';
        $chave_pix=''; // para não confundir
      } else {
        if ($chave_pix==='') $erro='Informe a chave Pix.';
        $qrcode=''; // idem
      }
    }

    if (!$erro){
      try{
        if ($id>0){
          $sql="UPDATE solicitacoes_pagfor SET
               numero_pagamento=:numero_pagamento, tipo_chave_pix=:tipo_chave_pix, chave_pix=:chave_pix, qrcode=:qrcode,
               ispb=:ispb, banco=:banco, agencia=:agencia, conta=:conta, tipo_conta=:tipo_conta,
               nome_fornecedor=:nome_fornecedor, tipo_documento=:tipo_documento, documento=:documento,
               valor=:valor, data_pagamento=:data_pagamento
               WHERE id=:id AND criado_por=:u AND status='Rascunho'";
          $pdo->prepare($sql)->execute([
            ':numero_pagamento'=>$numero_pagamento, ':tipo_chave_pix'=>$tipo_chave_pix, ':chave_pix'=>$chave_pix, ':qrcode'=>$qrcode,
            ':ispb'=>$ispb?:null, ':banco'=>$banco?:null, ':agencia'=>$agencia?:null, ':conta'=>$conta?:null, ':tipo_conta'=>$tipo_conta?:null,
            ':nome_fornecedor'=>$nome_fornecedor, ':tipo_documento'=>$tipo_documento, ':documento'=>$documento,
            ':valor'=>(float)$valor_str, ':data_pagamento'=>$data_pagamento,
            ':id'=>$id, ':u'=>$_SESSION['id_usuario'] ?? 0
          ]);
          $msg='Rascunho atualizado.';
        } else {
          $sql="INSERT INTO solicitacoes_pagfor
               (numero_pagamento,tipo_chave_pix,chave_pix,qrcode,ispb,banco,agencia,conta,tipo_conta,
                nome_fornecedor,tipo_documento,documento,valor,data_pagamento,status,criado_por)
               VALUES (:numero_pagamento,:tipo_chave_pix,:chave_pix,:qrcode,:ispb,:banco,:agencia,:conta,:tipo_conta,
                       :nome_fornecedor,:tipo_documento,:documento,:valor,:data_pagamento,'Rascunho',:u)";
          $pdo->prepare($sql)->execute([
            ':numero_pagamento'=>$numero_pagamento, ':tipo_chave_pix'=>$tipo_chave_pix, ':chave_pix'=>$chave_pix, ':qrcode'=>$qrcode,
            ':ispb'=>$ispb?:null, ':banco'=>$banco?:null, ':agencia'=>$agencia?:null, ':conta'=>$conta?:null, ':tipo_conta'=>$tipo_conta?:null,
            ':nome_fornecedor'=>$nome_fornecedor, ':tipo_documento'=>$tipo_documento, ':documento'=>$documento,
            ':valor'=>(float)$valor_str, ':data_pagamento'=>$data_pagamento,
            ':u'=>$_SESSION['id_usuario'] ?? null
          ]);
          $msg='Rascunho salvo.';
        }
        $edit=null; $editId=0;
      }catch(Throwable $e){ $erro='Erro ao salvar: '.$e->getMessage(); }
    }
  }
}

// -------------- Carregar rascunhos --------------
$st = $pdo->prepare("SELECT * FROM solicitacoes_pagfor WHERE criado_por=:u AND status='Rascunho' ORDER BY id DESC");
$st->execute([':u'=>$_SESSION['id_usuario'] ?? 0]);
$rascunhos = $st->fetchAll(PDO::FETCH_ASSOC);
?>
<h1>Solicitar Pagamento</h1>
<p style="margin:8px 0 16px">
  Preencha como <strong>Rascunho</strong> e, ao finalizar, selecione e envie. <strong>Tipo de conta</strong> é obrigatório para a planilha PagFor.
</p>

<?php if ($msg): ?><div class="card" style="max-width:1100px;background:#e8fff0;border-color:#bde5c8;text-align:left"><strong><?php echo htmlspecialchars($msg); ?></strong></div><?php endif; ?>
<?php if ($erro): ?><div class="card" style="max-width:1100px;background:#fff3f3;border-color:#f5c2c7;color:#a20000;text-align:left"><strong><?php echo htmlspecialchars($erro); ?></strong></div><?php endif; ?>

<!-- TABELA DE EDIÇÃO (uma faixa só) -->
<div class="card" style="max-width:1100px; text-align:left; padding:16px">
  <form id="formPag" method="post">
    <input type="hidden" name="id" value="<?php echo (int)($edit['id'] ?? 0); ?>">
    <table style="width:100%; border-collapse:collapse; table-layout:fixed">
      <colgroup>
        <col style="width:115px">
        <col style="width:120px">
        <col style="width:180px">
        <col style="width:75px">
        <col style="width:120px">
        <col style="width:120px">
        <col style="width:120px">
        <col style="width:95px">
        <col style="width:auto">
        <col style="width:95px">
        <col style="width:110px">
      </colgroup>
      <thead>
        <tr>
          <th style="text-align:left;padding:6px">Nº pagamento*</th>
          <th style="text-align:left;padding:6px">Tipo chave*</th>
          <th style="text-align:left;padding:6px">Chave / QR Code*</th>
          <th style="text-align:left;padding:6px">ISPB</th>
          <th style="text-align:left;padding:6px">Banco</th>
          <th style="text-align:left;padding:6px">Agência</th>
          <th style="text-align:left;padding:6px">Conta</th>
          <th style="text-align:left;padding:6px">Tipo conta</th>
          <th style="text-align:left;padding:6px">Fornecedor* / Doc*</th>
          <th style="text-align:left;padding:6px">Valor*</th>
          <th style="text-align:left;padding:6px">Data*</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <!-- Nº pagamento -->
          <td style="padding:6px"><input name="numero_pagamento" required value="<?php echo htmlspecialchars($edit['numero_pagamento'] ?? ''); ?>"></td>

          <!-- Tipo chave -->
          <td style="padding:6px">
            <?php $tcp=$edit['tipo_chave_pix'] ?? 'CPF/CNPJ'; ?>
            <select name="tipo_chave_pix" id="tipo_chave_pix">
              <option <?php echo $tcp==='CPF/CNPJ'?'selected':''; ?>>CPF/CNPJ</option>
              <option <?php echo $tcp==='E-mail'?'selected':''; ?>>E-mail</option>
              <option <?php echo $tcp==='Telefone'?'selected':''; ?>>Telefone</option>
              <option <?php echo $tcp==='Chave aleatória'?'selected':''; ?>>Chave aleatória</option>
              <option <?php echo $tcp==='QR Code'?'selected':''; ?>>QR Code</option>
            </select>
          </td>

          <!-- Chave ou QR -->
          <td style="padding:6px">
            <div id="bx-chave"><input name="chave_pix" id="chave_pix" placeholder="Digite a chave" value="<?php echo htmlspecialchars($edit['chave_pix'] ?? ''); ?>"></div>
            <div id="bx-qrcode" style="display:none"><textarea name="qrcode" id="qrcode" rows="2" placeholder="Cole o QR Code"><?php echo htmlspecialchars($edit['qrcode'] ?? ''); ?></textarea></div>
          </td>

          <!-- Bancários -->
          <td style="padding:6px"><input name="ispb" placeholder="60701190" value="<?php echo htmlspecialchars($edit['ispb'] ?? ''); ?>"></td>
          <td style="padding:6px"><input name="banco" value="<?php echo htmlspecialchars($edit['banco'] ?? ''); ?>"></td>
          <td style="padding:6px"><input name="agencia" value="<?php echo htmlspecialchars($edit['agencia'] ?? ''); ?>"></td>
          <td style="padding:6px"><input name="conta" value="<?php echo htmlspecialchars($edit['conta'] ?? ''); ?>"></td>
          <td style="padding:6px">
            <?php $tc=$edit['tipo_conta'] ?? ''; ?>
            <select name="tipo_conta">
              <option value=""  <?php echo $tc==''?'selected':''; ?>>—</option>
              <option value="CC" <?php echo $tc=='CC'?'selected':''; ?>>CC</option>
              <option value="CP" <?php echo $tc=='CP'?'selected':''; ?>>CP</option>
              <option value="Outros" <?php echo $tc=='Outros'?'selected':''; ?>>Outros</option>
            </select>
          </td>

          <!-- Fornecedor + Doc -->
          <td style="padding:6px">
            <div style="display:flex;gap:6px">
              <input style="flex:1" name="nome_fornecedor" placeholder="Nome fornecedor*" required value="<?php echo htmlspecialchars($edit['nome_fornecedor'] ?? ''); ?>">
              <?php $tdoc=$edit['tipo_documento'] ?? 'PF'; ?>
              <select name="tipo_documento" id="tipo_documento">
                <option value="PF" <?php echo $tdoc==='PF'?'selected':''; ?>>PF</option>
                <option value="PJ" <?php echo $tdoc==='PJ'?'selected':''; ?>>PJ</option>
              </select>
              <input style="width:140px" name="documento" id="documento" placeholder="CPF/CNPJ" required value="<?php echo htmlspecialchars($edit['documento'] ?? ''); ?>">
            </div>
          </td>

          <!-- Valor -->
          <td style="padding:6px"><input name="valor" id="valor" placeholder="1.234,56" required value="<?php echo isset($edit['valor']) ? number_format((float)$edit['valor'],2,',','') : ''; ?>"></td>

          <!-- Data -->
          <td style="padding:6px"><input type="date" name="data_pagamento" required value="<?php echo htmlspecialchars($edit['data_pagamento'] ?? date('Y-m-d')); ?>"></td>
        </tr>
      </tbody>
    </table>

    <div style="margin-top:10px;display:flex;gap:10px;flex-wrap:wrap">
      <button type="submit"><?php echo $edit ? 'Atualizar rascunho' : 'Salvar rascunho'; ?></button>
      <?php if ($edit): ?>
        <a class="card" href="index.php?page=pagamentos" style="text-decoration:none;padding:10px">Cancelar</a>
      <?php endif; ?>
    </div>
  </form>
</div>

<!-- LISTA DE RASCUNHOS -->
<h2 style="margin-top:22px">Meus rascunhos</h2>
<form method="post">
  <div class="card" style="max-width:1100px; padding:0; overflow:auto; text-align:left">
    <table style="width:100%; border-collapse:collapse">
      <thead>
        <tr>
          <th style="padding:8px;border-bottom:1px solid #eee"><input type="checkbox" onclick="document.querySelectorAll('.ck').forEach(c=>c.checked=this.checked)"></th>
          <th style="padding:8px;border-bottom:1px solid #eee">#</th>
          <th style="padding:8px;border-bottom:1px solid #eee">Nº</th>
          <th style="padding:8px;border-bottom:1px solid #eee">Tipo chave</th>
          <th style="padding:8px;border-bottom:1px solid #eee">Chave/QR</th>
          <th style="padding:8px;border-bottom:1px solid #eee">Banco</th>
          <th style="padding:8px;border-bottom:1px solid #eee">Ag/Conta</th>
          <th style="padding:8px;border-bottom:1px solid #eee">Tipo c.</th>
          <th style="padding:8px;border-bottom:1px solid #eee">Fornecedor</th>
          <th style="padding:8px;border-bottom:1px solid #eee">Doc</th>
          <th style="padding:8px;border-bottom:1px solid #eee">Valor</th>
          <th style="padding:8px;border-bottom:1px solid #eee">Data</th>
          <th style="padding:8px;border-bottom:1px solid #eee">Ações</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($rascunhos as $r): ?>
          <tr>
            <td style="padding:6px;border-bottom:1px solid #f2f2f2"><input class="ck" type="checkbox" name="ids[]" value="<?php echo (int)$r['id']; ?>"></td>
            <td style="padding:6px;border-bottom:1px solid #f2f2f2"><?php echo (int)$r['id']; ?></td>
            <td style="padding:6px;border-bottom:1px solid #f2f2f2"><?php echo htmlspecialchars($r['numero_pagamento']); ?></td>
            <td style="padding:6px;border-bottom:1px solid #f2f2f2"><?php echo htmlspecialchars($r['tipo_chave_pix']); ?></td>
            <td style="padding:6px;border-bottom:1px solid #f2f2f2;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
              <?php echo htmlspecialchars($r['tipo_chave_pix']==='QR Code' ? '[QR Code]' : ($r['chave_pix'] ?? '')); ?>
            </td>
            <td style="padding:6px;border-bottom:1px solid #f2f2f2"><?php echo htmlspecialchars($r['banco']); ?></td>
            <td style="padding:6px;border-bottom:1px solid #f2f2f2"><?php echo htmlspecialchars(($r['agencia']?:'').'/'.($r['conta']?:'')); ?></td>
            <td style="padding:6px;border-bottom:1px solid #f2f2f2"><?php echo htmlspecialchars($r['tipo_conta']); ?></td>
            <td style="padding:6px;border-bottom:1px solid #f2f2f2"><?php echo htmlspecialchars($r['nome_fornecedor']); ?></td>
            <td style="padding:6px;border-bottom:1px solid #f2f2f2"><?php echo htmlspecialchars($r['tipo_documento'].' '.$r['documento']); ?></td>
            <td style="padding:6px;border-bottom:1px solid #f2f2f2">R$ <?php echo number_format((float)$r['valor'],2,',','.'); ?></td>
            <td style="padding:6px;border-bottom:1px solid #f2f2f2"><?php echo htmlspecialchars($r['data_pagamento']); ?></td>
            <td style="padding:6px;border-bottom:1px solid #f2f2f2;white-space:nowrap">
              <a class="card" href="index.php?page=pagamentos&edit=<?php echo (int)$r['id']; ?>" title="Editar" style="padding:6px 8px; text-decoration:none">✏️</a>
              <form method="post" style="display:inline" onsubmit="return confirm('Apagar este rascunho?')">
                <input type="hidden" name="del_id" value="<?php echo (int)$r['id']; ?>">
                <button type="submit" title="Apagar" style="width:auto">🗑️</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rascunhos): ?>
          <tr><td colspan="13" style="padding:10px;color:#666">Sem rascunhos.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div style="margin-top:10px; display:flex; gap:10px; flex-wrap:wrap">
    <button type="submit" name="enviar" value="1">Enviar selecionados</button>
    <a class="card" href="index.php?page=dashboard" style="text-decoration:none;padding:12px">Voltar</a>
  </div>
</form>

<!-- UX: alternar chave/QR, máscaras e normalização -->
<script>
function toggleChave(){
  const tipo = document.getElementById('tipo_chave_pix').value;
  const bxC = document.getElementById('bx-chave');
  const bxQ = document.getElementById('bx-qrcode');
  if (tipo==='QR Code'){ bxC.style.display='none'; bxQ.style.display='block'; }
  else { bxC.style.display='block'; bxQ.style.display='none'; }
}
document.getElementById('tipo_chave_pix').addEventListener('change', toggleChave);
toggleChave();

// máscara doc (CPF/CNPJ) e valor
function maskDoc(v){
  v=v.replace(/\D/g,'');
  if (v.length<=11){
    v=v.replace(/(\d{3})(\d)/,'$1.$2');
    v=v.replace(/(\d{3})(\d)/,'$1.$2');
    v=v.replace(/(\d{3})(\d{1,2})$/,'$1-$2');
  }else{
    v=v.replace(/^(\d{2})(\d)/,'$1.$2');
    v=v.replace(/^(\d{2})\.(\d{3})(\d)/,'$1.$2.$3');
    v=v.replace(/\.(\d{3})(\d)/,'.$1/$2');
    v=v.replace(/(\d{4})(\d)/,'$1-$2');
  }
  return v;
}
const $doc = document.getElementById('documento');
const $val = document.getElementById('valor');
if ($doc) $doc.addEventListener('input', e=> e.target.value = maskDoc(e.target.value));
if ($val) $val.addEventListener('input', e=> e.target.value = e.target.value.replace(/[^0-9,\.]/g,''));

document.getElementById('formPag').addEventListener('submit', ()=>{
  if ($val) $val.value = $val.value.replace(/\./g,'').replace(',', '.');
  if ($doc) $doc.value = $doc.value.replace(/\D/g,'');
});
</script>
<?php endSidebar(); ?>
