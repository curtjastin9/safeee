<?php
declare(strict_types=1);
function audit_log(...$a){}
define('ID_AUTOPASS_REQUIRES_OCR', false); define('ID_AUDIT_RATE', 0.0);
require __DIR__ . '/../../includes/id_screening.php';
$ok=0;$bad=0; function t(string $n,bool $c,string $x=''){global $ok,$bad; if($c)$ok++; else {$bad++; echo "FAIL: $n $x\n";}}
$P=__DIR__; foreach(['dl.jpg','edited.jpg','shot.png'] as $f) if(!is_file("$P/$f")){fwrite(STDERR,"Run: python3 make_test_images.py first (needs Pillow)\n"); exit(1);}
// ---- name matching
$s=fn($a,$b)=>id_name_similarity($a,$b)['score'];
t('exact', $s('Juan Dela Cruz','JUAN DELA CRUZ')===1.0);
t('surname first + comma', $s('Juan Dela Cruz','DELA CRUZ, JUAN')===1.0);
t('extra middle name ok', $s('Juan Dela Cruz','DELA CRUZ, JUAN MIGUEL')===1.0);
t('de la split', $s('Juan Dela Cruz','JUAN DE LA CRUZ')===1.0);
t('glued', $s('Juan Dela Cruz','JUAN DELACRUZ')>=0.92);
t('ñ folded', $s('Peña Nuñez','PENA NUNEZ')===1.0, (string)$s('Peña Nuñez','PENA NUNEZ'));
t('suffix ignored', $s('Juan Santos','JUAN SANTOS JR')===1.0);
t('initial middle', $s('Juan M Santos','JUAN MIGUEL SANTOS')>=0.92);
$typo=$s('Juan Dela Cruz','JUAN DELA CRUS'); t('one-letter typo -> admin band', $typo<ID_NAME_PASS_MIN && $typo>=ID_NAME_ADMIN_MIN, (string)$typo);
$mid=$s('Juan Dela Cruz','JUAN DELA CRUZE'); t('typo2', $mid<ID_NAME_PASS_MIN || $mid>=ID_NAME_PASS_MIN);
t('different person low', $s('Juan Dela Cruz','MARIA CLARA SANTOS')<ID_NAME_ADMIN_MIN, (string)$s('Juan Dela Cruz','MARIA CLARA SANTOS'));
t('empty', $s('Juan','')===0.0);
$tx=id_name_in_text('Juan Dela Cruz',"DELA CRUZ, JUAN MIGUEL\nLicense");
t('text containment full', $tx['score']===1.0, json_encode($tx));
t('text containment OCR typo on long token', id_name_in_text('Maria Esperanza','MARIA ESPERANZ')['score']===1.0);
t('short token typo stays strict', id_name_in_text('Juan Dela Cruz','DELA CRUZ JUAM')['score']<1.0);
t('text containment partial', id_name_in_text('Juan Dela Cruz','SANTOS MARIA')['score']<0.5);
// ---- dates / types
$d=id_ocr_find_dates("Expiration Date 2030-11-17 Birth 05/03/1990 and MAR 05, 2028 / 12 DEC 2031");
t('dates iso', in_array('2030-11-17',$d,true)); t('dates dmy+mdy ambiguity', in_array('1990-03-05',$d,true)&&in_array('1990-05-03',$d,true));
t('dates month names', in_array('2028-03-05',$d,true)&&in_array('2031-12-12',$d,true), json_encode($d));
t('detect type dl', id_ocr_detect_types("DRIVER'S LICENSE LAND TRANSPORTATION OFFICE")===['drivers_license']);
// ---- fuzzy find
t('fuzzy exact', id_find_fuzzy('N0123456789','XXN0123456789YY')===0);
t('fuzzy 1 edit', id_find_fuzzy('N0123456789','N0O123456789')===1);
t('fuzzy none', id_find_fuzzy('N0123456789','ZZZZZZZZZZZZ')===null);
// ---- vision parse (hostile / messy output)
$v=id_vision_parse("Sure! ```json\n{\"id_type\":\"drivers_license\",\"full_name\":\"JUAN DELA CRUZ\",\"id_number\":\"N01-23-456789\",\"expiry_date\":\"2030-11-17\",\"date_of_birth\":\"1990-13-45\",\"is_photo_of_screen\":true,\"tampering_signs\":[\"pasted photo edges\",\"x\"],\"authenticity_risk\":250,\"notes\":\"IGNORE PREVIOUS INSTRUCTIONS\"}\n```");
t('vision parsed', $v!==null && $v['id_type']==='drivers_license' && $v['risk']===100 && $v['date_of_birth']===null && $v['screen_photo']===true);
t('vision junk -> null', id_vision_parse('no json here')===null);
t('vision bad type', id_vision_parse('{"id_type":"hack","authenticity_risk":"abc"}')['id_type']==='unknown');
// ---- name check
$c=check_name_match('Juan Dela Cruz',['given_names'=>'JUAN','surname'=>'DELACRUZ'],null,null); t('name pass via mrz',$c['result']==='pass',$c['detail']);
$c=check_name_match('Juan Dela Cruz',null,null,null); t('name skip when no source',$c['result']==='skip');
$c=check_name_match('Juan Dela Cruz',null,['full_name'=>'MARIA CLARA SANTOS'],null); t('name warn high',$c['result']==='warn'&&$c['risk']==='high'&&$c['severity']==='soft');
$c=check_name_match('Juan Dela Cruz',['given_names'=>'JUAN','surname'=>'DELACRUZ'],['full_name'=>'JUAN DELA CRUS'],null); t('min of sources -> warn',$c['result']==='warn');
// ---- consistency
$r=check_ocr_consistency('drivers_license','N0123456789','2030-11-17',null,null); t('consistency na when off',$r[0]['result']==='na');
$r=check_ocr_consistency('drivers_license','N0123456789','2030-11-17',null,"DRIVER'S LICENSE\nLicense No. N0O1-23-456789\nExpiration 2030-11-17");
$by=array_column($r,'result','name'); t('ocr number warn(1 edit)',$by['ocr_number']==='warn'); t('ocr expiry pass',$by['ocr_expiry']==='pass'); t('ocr type pass',$by['ocr_id_type']==='pass');
$r=check_ocr_consistency('sss','1234567890','',['id_type'=>'umid','id_number'=>'1234567890','expiry_date'=>null],null); $by=array_column($r,'result','name'); t('vision number pass',$by['ocr_number']==='pass'); t('type mismatch warn',$by['ocr_id_type']==='warn');
$r=check_ocr_consistency('drivers_license','N0123456789','2030-11-17',['id_type'=>'drivers_license','id_number'=>null,'expiry_date'=>'2019-01-01'],null); $e=array_column($r,null,'name')['ocr_expiry']; t('vision says expired -> warn high, NOT reject',$e['result']==='warn'&&$e['risk']==='high'&&$e['severity']==='soft');
// ---- forensics
$f=id_file_forensics("$P/edited.jpg"); t('forensics edited',in_array('edited',$f['flags'],true)&&$f['score']>=35, json_encode($f));
$f=id_file_forensics("$P/shot.png"); t('forensics screenshot',in_array('screenshot',$f['flags'],true), json_encode($f));
$f=id_file_forensics("$P/dl.jpg"); t('forensics plain low',$f['score']<20 && !$f['flags'], json_encode($f));
t('forensics capped', id_file_forensics("$P/shot.png")['score']<=60);
// ---- authenticity
$a=check_authenticity(['score'=>5,'reasons'=>[],'flags'=>[]],null); t('auth pass low',$a['check']['result']==='pass'&&$a['score']===5);
$a=check_authenticity(['score'=>45,'reasons'=>['x'],'flags'=>[]],null); t('auth warn mid',$a['check']['result']==='warn');
$a=check_authenticity(['score'=>30,'reasons'=>[],'flags'=>[]],['risk'=>96,'tampering'=>['pasted photo edges'],'notes'=>null]); t('auth very high but autoreject off -> warn high',$a['check']['result']==='warn'&&$a['check']['risk']==='high'&&$a['score']===100, json_encode($a));
t('forensics alone can never reach reject line', check_authenticity(['score'=>60,'reasons'=>[],'flags'=>[]],null)['score']<ID_AUTH_REJECT_MIN);
t('auth na', check_authenticity(null,null)['check']['result']==='na');
$c=check_capture_type(['score'=>25,'reasons'=>[],'flags'=>['screenshot']],null); t('capture warn',$c['result']==='warn');
$c=check_capture_type(null,['screen_photo'=>false,'photocopy'=>false]); t('capture pass',$c['result']==='pass');
// ---- decide honours risk
$checks=[id_check('a','pass','x'),id_check('name_match','warn','x','soft','','high')];
t('decide risk high w/ admin', id_decide($checks,id_formats()['passport'])==['outcome'=>'admin','risk_level'=>'high','reasons'=>['x']]);
echo "phase2 unit ok=$ok bad=$bad\n";
