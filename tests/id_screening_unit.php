<?php
declare(strict_types=1);
function audit_log(...$a){}
define('ID_AUTOPASS_REQUIRES_OCR', false); define('ID_AUDIT_RATE', 0.0);
require __DIR__ . '/../includes/id_screening.php';
$ok = 0; $bad = 0;
function t(string $n, bool $c){ global $ok,$bad; if($c){$ok++;} else {$bad++; echo "FAIL: $n\n";} }

// ICAO 9303 specimen
$l1='P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<';
$l2='L898902C36UTO7408122F1204159ZE184226B<<<<<10';
$p = id_mrz_parse_td3([$l1,$l2]);
t('specimen no structure errors', !$p['structure_errors']);
t('specimen digits ok', !$p['digit_failures']);
t('specimen surname', $p['fields']['surname']==='ERIKSSON');
t('specimen given', $p['fields']['given_names']==='ANNA MARIA');
t('specimen dob', $p['fields']['dob']==='1974-08-12');
t('specimen expiry', $p['fields']['expiry']==='2012-04-15');
// tamper: change DOB digit
$bad2 = substr_replace($l2,'3',14,1);
$p2 = id_mrz_parse_td3([$l1,$bad2]);
t('tamper detected', in_array('date of birth',$p2['digit_failures'],true));
t('tamper composite too', in_array('overall (composite)',$p2['digit_failures'],true));
// short line
t('structure short', (bool)id_mrz_structure_errors([substr($l1,0,40),$l2]));
// check function
$c = check_passport_mrz([$l1,$l2],'L898902C3');
t('passport check warns (issuer UTO != PHL)', $c['check']['result']==='warn');
$c = check_passport_mrz([$l1,$bad2],'L898902C3');
t('passport check hard fail', $c['check']['result']==='fail' && $c['check']['severity']==='hard');
// build a valid PHL MRZ
function mk(string $doc,string $dob,string $exp):array{
  $l1=str_pad('P<PHLDELACRUZ<<JUAN<MIGUEL',44,'<');
  $d=str_pad($doc,9,'<'); $dc=id_mrz_check_digit($d);
  $dbc=id_mrz_check_digit($dob); $ec=id_mrz_check_digit($exp);
  $opt=str_repeat('<',14); $oc='<';
  $l2=$d.$dc.'PHL'.$dob.$dbc.'M'.$exp.$ec.$opt.$oc;
  $comp=substr($l2,0,10).substr($l2,13,7).substr($l2,21,22);
  $l2.=id_mrz_check_digit($comp);
  return [$l1,$l2];
}
$m = mk('P1234567A','900101','351231');
t('mk len', strlen($m[1])===44 && strlen($m[0])===44);
$c = check_passport_mrz($m,'P1234567A');
t('valid PHL pass', $c['check']['result']==='pass');
$c = check_passport_mrz($m,'P7654321A');
t('number mismatch warn', $c['check']['result']==='warn');
// optional field with 0 check
t('digit_ok < filler', id_mrz_digit_ok(str_repeat('<',14),'<'));
t('digit_ok < non-filler', !id_mrz_digit_ok('ABC','<'));
// expiry
$fmt=id_formats()['drivers_license'];
t('expired hard', check_expiry('2020-01-01',$fmt)['severity']==='hard' && check_expiry('2020-01-01',$fmt)['result']==='fail');
t('future pass', check_expiry('2099-01-01',$fmt)['result']==='warn'); // >25y
t('future ok', check_expiry(date('Y-m-d',strtotime('+3 years')),$fmt)['result']==='pass');
t('missing expiry skip', check_expiry('',$fmt)['result']==='skip');
t('missing expiry na', check_expiry('',id_formats()['sss'])['result']==='na');
t('bad date skip', check_expiry('2025-02-30',$fmt)['result']==='skip');
// formats
t('normalize', id_normalize_number('n01-23-456789')==='N0123456789');
t('dl format', check_number_format('drivers_license','N0123456789')['result']==='pass');
t('sss soft fail', ($r=check_number_format('sss','123'))['result']==='fail' && $r['severity']==='soft');
$GLOBALS['x']=1;
t('philsys 16', check_number_format('philsys','1234567890123456')['result']==='pass');
t('type mismatch hint', check_id_type_match('sss','123456789012')['result']==='warn');
t('type ok', check_id_type_match('sss','1234567890')['result']==='pass');
// decide
$fmtP=id_formats()['passport'];
$clean=[id_check('a','pass','x'),id_check('b','na','x')];
t('decide pass', id_decide($clean,$fmtP)['outcome']==='pass');
$withSkip=array_merge($clean,[id_check('face','skip','x')]);
t('decide skip->admin', id_decide($withSkip,$fmtP)['outcome']==='admin');
$hard=array_merge($clean,[id_check('d','fail','x','hard','msg')]);
$dd=id_decide($hard,$fmtP);
t('decide reject', $dd['outcome']==='reject' && $dd['reasons']===['msg']);
t('weak always admin', id_decide($clean,id_formats()['postal'])['outcome']==='admin');
t('medium admin', id_decide($clean,id_formats()['sss'])['outcome']==='admin');
// hash/mask
t('hash stable', id_number_hash('sss','1234567890')===id_number_hash('sss','1234567890'));
t('hash differs by type', id_number_hash('sss','1234567890')!==id_number_hash('umid','1234567890'));
t('mask', id_mask_number('1234567890')==='******7890');
t('hamming', id_hamming_hex('ffffffffffffffff','fffffffffffffffe')===1);
echo "ok=$ok bad=$bad\n";
