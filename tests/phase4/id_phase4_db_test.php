<?php
declare(strict_types=1);
putenv('SAFETRADE_DB_NAME=safetrade'); $R = dirname(__DIR__, 2);

require "$R/config/config.php";
db()->query("DELETE FROM id_settings");   // stale overrides would be loaded as constants at include time
require "$R/includes/id_screening.php";
$conn = db(); foreach (['id_screenings','users','audit_logs','id_tuning_labels','id_settings'] as $t) $conn->query("DELETE FROM $t");
$ok = 0; $bad = 0; function t(string $n, bool $c, string $x = '') { global $ok, $bad; if ($c) $ok++; else { $bad++; echo "FAIL: $n $x\n"; } }
function mk_user(mysqli $c, string $email): int { $u = uuid_v4(); $h='x'; $s=$c->prepare("INSERT INTO users (uuid,first_name,last_name,email,phone,password_hash,role,status,verification_status) VALUES (?,'Juan','Cruz',?,'0917',?,'user','pending_verification','pending')"); $s->bind_param('sss',$u,$email,$h); $s->execute(); return (int)$c->insert_id; }
function mk_scr(mysqli $c, int $uid, string $outcome, ?float $face, array $checks, string $reasons = '', ?int $auth = 10, string $source = 'auto'): int {
  $j = json_encode($checks); $s=$c->prepare("INSERT INTO id_screenings (user_id,government_id_id,attempt_no,source,outcome,risk_level,detected_id_type,extracted_json,checks_json,reasons,authenticity_score,face_distance) VALUES (?,0,1,?,?,'low','Passport','{}',?,?,?,?)");
  $s->bind_param('issssid', $uid, $source, $outcome, $j, $reasons, $auth, $face); $s->execute(); return (int)$c->insert_id; }
function ck(string $n, string $r, string $d = ''): array { return ['name'=>$n,'result'=>$r,'severity'=>'soft','detail'=>$d,'user_msg'=>'','risk'=>'']; }
function lab(mysqli $c, int $sid, string $sys, string $verdict, ?float $face, ?int $rel = 1, int $audit = 0): void {
  $s=$c->prepare("INSERT INTO id_tuning_labels (screening_id,system_outcome,was_audit,face_distance,face_reliable,verdict,verdict_via) VALUES (?,?,?,?,?,?,'test')"); $s->bind_param('isidis',$sid,$sys,$audit,$face,$rel,$verdict); $s->execute(); }

// ---- 1. label recording ----
$u = mk_user($conn, 'a@t.test');
t('no screening -> no label', (function() use($conn,$u){ id_label_record($conn,$u,'approved','verify',1); return $conn->query("SELECT COUNT(*) FROM id_tuning_labels")->fetch_row()[0]==0; })());
mk_scr($conn,$u,'reject',0.95,[ck('face_match','fail','Distance 0.950 ... Clearly a different face.'),ck('name_match','pass')],'face differs');
$s2 = mk_scr($conn,$u,'admin',0.52,[ck('face_match','warn','Distance 0.520 Borderline'),ck('ocr_number','pass')],'Random quality audit: x');
id_label_record($conn,$u,'approved','verify',7);
$L = $conn->query("SELECT * FROM id_tuning_labels")->fetch_assoc();
t('labels latest auto screening', (int)$L['screening_id']===$s2 && $L['system_outcome']==='admin' && $L['verdict']==='approved' && (int)$L['was_audit']===1 && abs((float)$L['face_distance']-0.52)<0.001 && (int)$L['face_reliable']===1, json_encode($L));
t('label holds no personal data', !array_intersect(['user_id','email','first_name'], array_keys($L)));
id_label_record($conn,$u,'rejected','revoke',7);
t('relabel updates same row', $conn->query("SELECT COUNT(*) FROM id_tuning_labels")->fetch_row()[0]==1 && $conn->query("SELECT verdict FROM id_tuning_labels")->fetch_row()[0]==='rejected');
// unreliable face flagged
$u3 = mk_user($conn,'c@t.test'); mk_scr($conn,$u3,'admin',0.70,[ck('face_match','warn','Distance 0.700 (the face on the ID is small or unclear, so this distance is unreliable).')]); id_label_record($conn,$u3,'approved','verify',7);
t('unreliable face recorded', (int)$conn->query("SELECT face_reliable FROM id_tuning_labels WHERE face_distance=0.70")->fetch_row()[0]===0);
// retry/admin-source rows are never labelled
$u4 = mk_user($conn,'d@t.test'); mk_scr($conn,$u4,'retry',null,[],'',null); mk_scr($conn,$u4,'pass',null,[],'',null,'admin'); id_label_record($conn,$u4,'approved','verify',7);
t('retry/admin rows ignored', $conn->query("SELECT COUNT(*) FROM id_tuning_labels")->fetch_row()[0]==2);

// ---- 2. report & suggestions ----
function reset_labels(mysqli $c): void { $c->query("DELETE FROM id_tuning_labels"); $c->query("DELETE FROM id_screenings"); $c->query("DELETE FROM id_settings"); }
function seed(mysqli $c, array $appr, array $rej): void { foreach ($appr as $d) lab($c, mk_scr($c, 1, 'admin', $d, []), 'admin', 'approved', $d); foreach ($rej as $d) lab($c, mk_scr($c, 1, 'admin', $d, []), 'admin', 'rejected', $d); }
$sugOf = fn(array $rep, string $k) => array_values(array_filter($rep['suggestions'], fn($s) => $s['key'] === $k))[0] ?? null;

reset_labels($conn); $rep = id_tuning_report($conn);
t('empty: ready, no suggestions, note shown', $rep['ready'] && !$rep['suggestions'] && count($rep['notes'])===1);

reset_labels($conn); seed($conn, array_map(fn($i)=>0.20+$i*0.0075, range(0,39)), [0.70,0.75,0.80,0.90,1.0,1.1]); // approved 0.20..0.49
$rep = id_tuning_report($conn); $s = $sugOf($rep,'ID_FACE_STRONG_MAX');
t('clean data -> raise strong limit one small step', $s && $s['to']==0.50 && $s['direction']==='more automation', json_encode($s));
t('what-if replay has no false pass', $s && $s['after']['false_pass']===0 && $s['after']['pass_eligible']>$s['before']['pass_eligible']);
t('no suggestion for reject line when nothing wrong', $sugOf($rep,'ID_FACE_REJECT_MIN')===null);

reset_labels($conn); seed($conn, [0.2,0.25,0.3], [0.40]); // admin declined someone at 0.40 (<= 0.45): dangerous
$rep = id_tuning_report($conn); $s = $sugOf($rep,'ID_FACE_STRONG_MAX');
t('declined person under strong limit -> tighten even with tiny sample', $s && $s['to']==0.38 && $s['direction']==='safer', json_encode($s));
t('what-if shows the false pass removed', $s['before']['false_pass']===1 && $s['after']['false_pass']===0);

reset_labels($conn); seed($conn, [0.3,0.85], [0.95]); // admin approved someone at 0.85 (>= 0.80)
$rep = id_tuning_report($conn); $s = $sugOf($rep,'ID_FACE_REJECT_MIN');
t('approved person over decline line -> raise decline line', $s && $s['to']==0.90 && $s['direction']==='safer', json_encode($s));
t('what-if: wrong declines drop', $s['before']['false_reject']===1 && $s['after']['false_reject']===0);

reset_labels($conn); // unreliable faces are ignored
for ($i=0;$i<3;$i++) lab($conn, mk_scr($conn,1,'admin',0.85,[]), 'admin','approved',0.85,0);
t('unreliable faces never drive suggestions', id_tuning_report($conn)['suggestions']===[]);

reset_labels($conn); // audit rate
for ($i=0;$i<2;$i++) lab($conn, mk_scr($conn,1,'admin',0.3,[],'Random quality audit: x'), 'admin','rejected',0.3,1,1);
$rep = id_tuning_report($conn); $s = $sugOf($rep,'ID_AUDIT_RATE');
t('audit found a wrong approval -> audit more', $s && $s['to']==0.25 && $rep['quality']['audit_bad']===2, json_encode($s));
reset_labels($conn); for ($i=0;$i<50;$i++) lab($conn, mk_scr($conn,1,'admin',null,[],'Random quality audit: x'), 'admin','approved',null,null,1);
$s = $sugOf(id_tuning_report($conn),'ID_AUDIT_RATE'); t('50 clean audits -> lower to 5%, never below 2%', $s && $s['to']==0.05);
mk_scr($conn,1,'admin',null,[],'Random quality audit: pending one');
t('pending audits counted', id_tuning_report($conn)['quality']['audit_pending']===1);

// quality counters
reset_labels($conn);
lab($conn, mk_scr($conn,1,'reject',0.9,[]),'reject','approved',0.9); lab($conn, mk_scr($conn,1,'pass',0.2,[]),'pass','rejected',0.2);
$q = id_tuning_report($conn)['quality']; t('wrong decline / wrong approval counted', $q['reject_reversed']===1 && $q['pass_revoked']===1);

// per-check table + histogram + auth bands
reset_labels($conn);
lab($conn, mk_scr($conn,1,'admin',0.30,[ck('name_match','warn'),ck('face_match','pass')],'',55), 'admin','approved',0.30);
$conn->query("UPDATE id_tuning_labels SET checks_json='[[\"name_match\",\"warn\"],[\"face_match\",\"pass\"]]', authenticity_score=55");
$rep = id_tuning_report($conn);
t('check usefulness table', ($rep['checks']['name_match']['warn']['approved'] ?? 0)===1);
t('histogram bucket 0.30', count($rep['face_hist'])===1 && abs($rep['face_hist'][0]['lo']-0.30)<0.001);
t('auth band 40-59', array_values(array_filter($rep['auth_bands'], fn($b)=>$b['range']==='40–59'))[0]['approved']===1);

// ---- 3. apply / reset / loader ----
reset_labels($conn);
t('apply valid', id_tuning_apply($conn, 5, 'ID_FACE_STRONG_MAX', '0.40') === null && id_settings_read($conn)['ID_FACE_STRONG_MAX']==0.40);
t('audit-logged', (int)$conn->query("SELECT COUNT(*) FROM audit_logs WHERE action='admin.id_tuning.apply' AND details LIKE 'ID_FACE_STRONG_MAX: 0.45 -> 0.4%'")->fetch_row()[0]===1);
t('out of bounds refused', id_tuning_apply($conn,5,'ID_FACE_STRONG_MAX','0.90')!==null && id_tuning_apply($conn,5,'ID_AUDIT_RATE','0')!==null);
t('audit rate floor 2%', id_tuning_apply($conn,5,'ID_AUDIT_RATE','0.01')!==null && id_tuning_apply($conn,5,'ID_AUDIT_RATE','0.02')===null);
t('ordering enforced', id_tuning_check_set(['ID_FACE_STRONG_MAX'=>0.55,'ID_FACE_BORDER_MAX'=>0.57])!==null && id_tuning_check_set(['ID_FACE_BORDER_MAX'=>0.72,'ID_FACE_REJECT_MIN'=>0.74])!==null && id_tuning_check_set(['ID_FACE_STRONG_MAX'=>0.45,'ID_FACE_BORDER_MAX'=>0.65])===null);
t('unknown / non-numeric refused', id_tuning_apply($conn,5,'ID_MAX_ATTEMPTS','5')!==null && id_tuning_apply($conn,5,'ID_FACE_MIN_BOX','abc')!==null);
t('int setting rounds', id_tuning_apply($conn,5,'ID_FACE_MIN_BOX','80.6')===null && id_settings_read($conn)['ID_FACE_MIN_BOX']==81.0);
// loader in a fresh process: DB overrides become the constants
$out = shell_exec('SAFETRADE_DB_NAME=safetrade php -r \'require "'.$R.'/config/config.php"; require "'.$R.'/includes/id_screening.php"; echo ID_FACE_STRONG_MAX,"|",ID_FACE_BORDER_MAX,"|",ID_FACE_MIN_BOX,"|",ID_AUDIT_RATE,"|",id_tuning_current(db())["ID_FACE_STRONG_MAX"];\' 2>&1');
t('fresh process picks up overrides', $out === '0.4|0.65|81|0.02|0.4', (string)$out);
// inconsistent hand-edited rows are ignored as a set
$conn->query("REPLACE INTO id_settings (k,v) VALUES ('ID_FACE_STRONG_MAX','0.60'),('ID_FACE_BORDER_MAX','0.50')");
$out = shell_exec('SAFETRADE_DB_NAME=safetrade php -r \'require "'.$R.'/config/config.php"; require "'.$R.'/includes/id_screening.php"; echo ID_FACE_STRONG_MAX,"|",ID_FACE_BORDER_MAX;\' 2>/dev/null');
t('inconsistent rows ignored -> defaults', $out === '0.45|0.65', (string)$out);
// code-level define() beats the DB
$out = shell_exec('SAFETRADE_DB_NAME=safetrade php -r \'define("ID_FACE_STRONG_MAX",0.33); require "'.$R.'/config/config.php"; require "'.$R.'/includes/id_screening.php"; echo ID_FACE_STRONG_MAX;\' 2>&1');
t('explicit define wins', $out === '0.33', (string)$out);
$conn->query("DELETE FROM id_settings"); id_tuning_apply($conn,5,'ID_FACE_MIN_SCORE','0.7');
t('reset', id_tuning_reset($conn,5,'ID_FACE_MIN_SCORE')===null && !isset(id_settings_read($conn)['ID_FACE_MIN_SCORE']) && id_tuning_reset($conn,5,'NOPE')!==null);

// ---- 4. migration not run ----
$conn->query("RENAME TABLE id_tuning_labels TO id_tuning_labels_x");
$rep = id_tuning_report($conn); t('report says not ready without table', $rep['ready']===false);
id_label_record($conn,$u,'approved','verify',1); t('label recording never throws without table', true);
$conn->query("RENAME TABLE id_tuning_labels_x TO id_tuning_labels");
echo "phase4 db ok=$ok bad=$bad\n";
