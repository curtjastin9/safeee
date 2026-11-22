<?php
declare(strict_types=1);
function audit_log(...$a){}
putenv('SAFETRADE_ID_AUTOPASS_NEEDS_OCR=1');
require __DIR__ . '/../../includes/id_screening.php';
$ok=0;$bad=0; function t(string $n,bool $c,string $x=''){global $ok,$bad; if($c)$ok++; else {$bad++; echo "FAIL: $n $x\n";}}
mt_srand(7);
function vec(): array { $v=[]; for($i=0;$i<128;$i++) $v[]=(mt_rand()/mt_getrandmax()-0.5)*0.2; return $v; }
function at_distance(array $base,float $d): array { $u=vec(); $n=sqrt(array_sum(array_map(fn($x)=>$x*$x,$u))); foreach($u as $i=>$x) $base[$i]+=$x/$n*$d; return $base; }
$reg=vec();
$face=fn(array $desc,float $score=0.9,int $box=150)=>['status'=>'ok','descriptor'=>$desc,'score'=>$score,'box_w'=>$box,'faces'=>1];
// ---- parse
$p=id_face_parse_post(['id_face_status'=>'ok','id_face_descriptor'=>json_encode($reg),'id_face_score'=>'0.93','id_face_box'=>'140','id_face_count'=>'2']);
t('parse ok', $p['status']==='ok' && count($p['descriptor'])===128 && $p['faces']===2 && $p['box_w']===140);
t('parse missing', id_face_parse_post([])['status']==='missing');
t('parse bad descriptor -> error', id_face_parse_post(['id_face_status'=>'ok','id_face_descriptor'=>'[1,2,3]'])['status']==='error');
t('parse garbage status', id_face_parse_post(['id_face_status'=>'<script>'])['status']==='missing');
t('parse all zeros rejected', id_face_parse_post(['id_face_status'=>'ok','id_face_descriptor'=>json_encode(array_fill(0,128,0))])['status']==='error');
t('parse huge numbers rejected', id_face_parse_post(['id_face_status'=>'ok','id_face_descriptor'=>json_encode(array_fill(0,128,99))])['status']==='error');
t('parse clamps score', id_face_parse_post(['id_face_status'=>'no_face','id_face_score'=>'7'])['score']===1.0);
// ---- bands
$r=check_face_match($reg,$face(at_distance($reg,0.30))); t('strong pass',$r['check']['result']==='pass' && abs($r['distance']-0.30)<0.01, json_encode($r));
$r=check_face_match($reg,$face(at_distance($reg,0.55))); t('borderline warn medium',$r['check']['result']==='warn' && $r['check']['risk']==='medium');
$r=check_face_match($reg,$face(at_distance($reg,0.72))); t('probably different warn high, no reject',$r['check']['result']==='warn' && $r['check']['risk']==='high' && $r['check']['severity']==='soft');
$r=check_face_match($reg,$face(at_distance($reg,0.95))); t('clearly different -> hard fail',$r['check']['result']==='fail' && $r['check']['severity']==='hard' && $r['check']['user_msg']!=='');
$r=check_face_match($reg,$face(at_distance($reg,0.95),0.4,150)); t('different but low detector score -> warn not reject',$r['check']['result']==='warn');
$r=check_face_match($reg,$face(at_distance($reg,0.95),0.9,40)); t('different but tiny face -> warn not reject',$r['check']['result']==='warn');
$r=check_face_match($reg,$face(at_distance($reg,0.30),0.9,40)); t('strong but tiny face -> warn (not auto-pass eligible)',$r['check']['result']==='warn');
$r=check_face_match($reg,$face(at_distance($reg,0.02))); t('too perfect -> warn high',$r['check']['result']==='warn' && $r['check']['risk']==='high');
t('no_face -> warn', check_face_match($reg,['status'=>'no_face','descriptor'=>null,'score'=>null,'box_w'=>null,'faces'=>0])['check']['result']==='warn');
t('missing -> skip', check_face_match($reg,id_face_parse_post([]))['check']['result']==='skip');
t('no registered -> skip', check_face_match(null,$face($reg))['check']['result']==='skip');
// ---- decide gating on OCR confirmation
$fmtP=id_formats()['passport'];
$clean=[id_check('a','pass','x'),id_check('face_match','pass','x'),id_check('ocr_number','pass','x'),id_check('ocr_expiry','pass','x')];
t('autopass when everything incl OCR passes', id_decide($clean,$fmtP)['outcome']==='pass');
$noOcr=[id_check('a','pass','x'),id_check('face_match','pass','x'),id_check('ocr_number','na','x'),id_check('ocr_expiry','na','x')];
$d=id_decide($noOcr,$fmtP); t('no OCR confirmation -> admin with explanation', $d['outcome']==='admin' && str_contains($d['reasons'][0],'OCR'), json_encode($d));
t('suspicious flag blocks', id_decide(array_merge($clean,[id_check('authenticity','warn','x')]),$fmtP)['outcome']==='admin');
t('face borderline blocks', id_decide(array_merge($clean,[id_check('face_match2','warn','x')]),$fmtP)['outcome']==='admin');
t('hard face fail rejects', id_decide(array_merge($clean,[id_check('face_match','fail','x','hard','msg')]),$fmtP)['outcome']==='reject');
echo "phase3 unit ok=$ok bad=$bad\n";
