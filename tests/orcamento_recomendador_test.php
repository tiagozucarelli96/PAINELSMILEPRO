<?php
require __DIR__.'/../public/core/orcamento_recomendador.php';
function ok($v,$m){if(!$v){fwrite(STDERR,"FALHOU: $m\n");exit(1);}}
$cases = [
 [['tipo_evento'=>'infantil','convidados'=>30],['DiverKids']],
 [['tipo_evento'=>'infantil','convidados'=>50],['DiverKids','Lisbon Kids']],
 [['tipo_evento'=>'infantil','convidados'=>71],['Lisbon Kids']],
 [['tipo_evento'=>'casamento','convidados'=>70,'cerimonia'=>true],['Cristal']],
 [['tipo_evento'=>'bodas','convidados'=>151,'cerimonia'=>true],['Garden']],
 [['tipo_evento'=>'15anos','convidados'=>90],['Lisbon','Cristal']],
];
foreach($cases as [$in,$want]) ok(OrcamentoRecomendador::recomendar($in)['unidades']===$want,json_encode($in));
ok(OrcamentoRecomendador::recomendar(['tipo_evento'=>'infantil','convidados'=>101])['analise'],'infantil > 100');
ok(OrcamentoRecomendador::recomendar(['tipo_evento'=>'casamento','convidados'=>251])['analise'],'adulto > 250');
echo "OK - ".(count($cases)+2)." bifurcações\n";
