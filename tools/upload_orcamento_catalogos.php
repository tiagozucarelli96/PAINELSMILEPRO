<?php
declare(strict_types=1);

require_once __DIR__.'/../public/conexao.php';
require_once __DIR__.'/../public/magalu_storage_helper.php';

if (!$pdo instanceof PDO) throw new RuntimeException('Banco indisponível.');
$required=['MAGALU_ACCESS_KEY','MAGALU_SECRET_KEY','MAGALU_BUCKET'];
foreach($required as $key) if(!getenv($key)) throw new RuntimeException("Variável $key não configurada.");

$base='/Users/tiagozucarelli/Library/Mobile Documents/com~apple~CloudDocs/GRUPO SMILE/ORÇAMENTOS OFICIAIS ';
$files=[
 ['path'=>"$base/NOVO/OURO CHURRASCO.pdf",'key'=>'ouro-churrasco.pdf','name'=>'Ouro Churrasco','profile'=>'completo','format'=>'churrasco','priority'=>10],
 ['path'=>"$base/NOVO/OURO CRISTALGARDEN.pdf",'key'=>'ouro-mini-refeicoes.pdf','name'=>'Ouro Mini refeições','profile'=>'completo','format'=>'mini refeições','priority'=>12],
 ['path'=>"$base/NOVO/OURO JANTAR.pdf",'key'=>'ouro-jantar.pdf','name'=>'Ouro Jantar','profile'=>'completo','format'=>'jantar','priority'=>11],
 ['path'=>"$base/NOVO/PACOTE PRATA GARDEN .pdf",'key'=>'prata-mini-refeicoes.pdf','name'=>'Prata Mini refeições','profile'=>'equilibrado','format'=>'mini refeições','priority'=>22],
 ['path'=>"$base/NOVO/PRATA CHURRASCO.pdf",'key'=>'prata-churrasco.pdf','name'=>'Prata Churrasco','profile'=>'equilibrado','format'=>'churrasco','priority'=>20],
 ['path'=>"$base/NOVO/PRATA JANTAR.pdf",'key'=>'prata-jantar.pdf','name'=>'Prata Jantar','profile'=>'equilibrado','format'=>'jantar','priority'=>21],
];
$kids=[
 ['path'=>"$base/ATUAL ORÇAMENTO DIVERKIDS NOV.pdf",'key'=>'catalogo-diverkids.pdf','name'=>'Catálogo DiverKids','unit'=>'DiverKids','min'=>30,'max'=>70,'priority'=>5],
 ['path'=>"$base/LISBON KIDS ATUAL.pdf",'key'=>'catalogo-lisbon-kids.pdf','name'=>'Catálogo Lisbon Kids','unit'=>'Lisbon Kids','min'=>50,'max'=>100,'priority'=>5],
];

$storage=new MagaluStorageHelper();
$urls=[];
foreach(array_merge($files,$kids) as $item){
 if(!is_file($item['path'])) throw new RuntimeException('Arquivo não encontrado: '.$item['path']);
 $key='orcamentos/catalogos/2026/'.$item['key'];
 if(!in_array('--skip-upload',$argv,true)){
  fwrite(STDOUT,"Enviando {$item['key']}...\n");
  $result=$storage->uploadFileFromPath($item['path'],'orcamentos/catalogos','application/pdf',$key);
  if(empty($result['success'])||empty($result['url'])) throw new RuntimeException($result['error']??'Upload falhou.');
  $urls[$item['key']]=$result['url'];
 }else{
  $urls[$item['key']]=rtrim((string)getenv('MAGALU_ENDPOINT'),'/').'/'.getenv('MAGALU_BUCKET').'/'.$key;
 }
}

$unitStmt=$pdo->prepare('SELECT id,capacidade_min,capacidade_max FROM orcamento_unidades WHERE nome=:n AND ativo=true');
$find=$pdo->prepare('SELECT id FROM orcamento_pacotes WHERE unidade_id=:u AND tipo_evento=:t AND nome=:n LIMIT 1');
$insert=$pdo->prepare("INSERT INTO orcamento_pacotes(unidade_id,nome,tipo_evento,perfil,formato_gastronomico,convidados_min,convidados_max,dias_semana,pdf_url,descricao,diferenciais,prioridade,ativo) VALUES(:u,:n,:t,:p,:f,:a,:b,CAST(:days AS jsonb),:pdf,:d,CAST(:diff AS jsonb),:o,true)");
$update=$pdo->prepare("UPDATE orcamento_pacotes SET perfil=:p,formato_gastronomico=:f,convidados_min=:a,convidados_max=:b,dias_semana=CAST(:days AS jsonb),pdf_url=:pdf,descricao=:d,diferenciais=CAST(:diff AS jsonb),prioridade=:o,ativo=true,atualizado_em=NOW() WHERE id=:id");
function savePackage(PDOStatement $unitStmt,PDOStatement $find,PDOStatement $insert,PDOStatement $update,array $x): void {
 $unitStmt->execute([':n'=>$x['unit']]); $u=$unitStmt->fetch(); if(!$u)throw new RuntimeException('Unidade não encontrada: '.$x['unit']);
 $data=[':p'=>$x['profile']??null,':f'=>$x['format']??null,':a'=>$x['min'],':b'=>$x['max'],':days'=>json_encode($x['days']??[0,1,2,3,4,5,6]),':pdf'=>$x['url'],':d'=>$x['description'],':diff'=>json_encode($x['diff'],JSON_UNESCAPED_UNICODE),':o'=>$x['priority']];
 $find->execute([':u'=>$u['id'],':t'=>$x['type'],':n'=>$x['name']]); $id=$find->fetchColumn();
 if($id){$update->execute($data+[':id'=>$id]);}else{$insert->execute($data+[':u'=>$u['id'],':t'=>$x['type'],':n'=>$x['name']]);}
}

$pdo->beginTransaction();
try{
 foreach($files as $f)foreach(['Cristal'=>[70,150],'Garden'=>[90,250]] as $unit=>$range)foreach(['casamento','bodas'] as $type){
  savePackage($unitStmt,$find,$insert,$update,['unit'=>$unit,'type'=>$type,'name'=>$f['name'],'profile'=>$f['profile'],'format'=>$f['format'],'min'=>$range[0],'max'=>$range[1],'url'=>$urls[$f['key']],'priority'=>$f['priority'],'description'=>'Pacote '.$f['name'].' para celebrações nas unidades Cristal e Garden.','diff'=>['Buffet completo','Equipe de salão e cozinha','Estrutura para a celebração']]);
 }
 foreach($kids as $f){
  savePackage($unitStmt,$find,$insert,$update,['unit'=>$f['unit'],'type'=>'infantil','name'=>$f['name'],'profile'=>null,'format'=>null,'min'=>$f['min'],'max'=>$f['max'],'url'=>$urls[$f['key']],'priority'=>$f['priority'],'description'=>'Catálogo completo da unidade '.$f['unit'].'.','diff'=>['Estrutura infantil','Buffet e opções descritos no catálogo','Equipe para a festa']]);
 }
 $pdo->commit();
}catch(Throwable $e){$pdo->rollBack();throw $e;}

fwrite(STDOUT,'Concluído: '.count($urls)." arquivos e pacotes atualizados.\n");
