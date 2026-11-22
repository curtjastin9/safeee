<?php
declare(strict_types=1);
putenv('SAFETRADE_DB_NAME=safetrade'); putenv('SAFETRADE_ID_AUTOPASS_NEEDS_OCR=1'); putenv('SAFETRADE_ID_AUDIT_RATE=0.10');
require __DIR__ . '/../../config/config.php';
require __DIR__ . '/../../includes/id_screening.php';
$conn=db(); foreach(['id_screenings','government_ids','users','audit_logs','face_verifications','inbox_messages'] as $t) $conn->query("DELETE FROM $t");
$ok=0;$bad=0; function t(string $n,bool $c,string $x=''){global $ok,$bad; if($c)$ok++; else {$bad++; echo "FAIL: $n $x\n";}}
mt_srand(11);
function vec(): array { $v=[]; for($i=0;$i<128;$i++) $v[]=(mt_rand()/mt_getrandmax()-0.5)*0.2; return $v; }
function at_distance(array $b,float $d): array { $u=vec(); $n=sqrt(array_sum(array_map(fn($x)=>$x*$x,$u))); foreach($u as $i=>$x) $b[$i]+=$x/$n*$d; return $b; }
function mk_user(mysqli $c,string $email,string $role='user',string $status='pending_verification',string $first='Juan',string $last='Dela Cruz'): int { $u=uuid_v4(); $h='x';
  $s=$c->prepare("INSERT INTO users (uuid,first_name,last_name,email,phone,password_hash,role,status,verification_status) VALUES (?,?,?,?,'0917',?,?,?,'pending')"); $s->bind_param('sssssss',$u,$first,$last,$email,$h,$role,$status); $s->execute(); return (int)$c->insert_id; }
function mk_gov(mysqli $c,int $uid,string $sha,string $ph): int { $ph=substr(md5($sha),0,16); $z='';$t='Passport'; $s=$c->prepare("INSERT INTO government_ids (user_id,id_type,front_path,back_path,image_hash,image_phash) VALUES (?,?,?,?,?,?)"); $s->bind_param('isssss',$uid,$t,$z,$z,$sha,$ph); $s->execute(); return (int)$c->insert_id; }
function mrz(string $doc): array { $exp=date('ymd',strtotime('+4 years')); $dob='900101'; $d=str_pad($doc,9,'<'); $l1=str_pad('P<PHLDELACRUZ<<JUAN',44,'<');
  $l2=$d.id_mrz_check_digit($d).'PHL'.$dob.id_mrz_check_digit($dob).'M'.$exp.id_mrz_check_digit($exp).str_repeat('<',14).'<'; $l2.=id_mrz_check_digit(substr($l2,0,10).substr($l2,13,7).substr($l2,21,22)); return [$l1,$l2]; }
$exp=date('Y-m-d',strtotime('+4 years'));
$vision=fn(string $no)=>['provider'=>'vision','text'=>null,'errors'=>[],'vision'=>['id_type'=>'passport','surname'=>'DELA CRUZ','given_names'=>'JUAN','full_name'=>null,'id_number'=>$no,'date_of_birth'=>null,'expiry_date'=>$GLOBALS['exp'],'mrz_lines'=>[],'screen_photo'=>false,'photocopy'=>false,'tampering'=>[],'risk'=>5,'notes'=>null]];
$forensics=['score'=>5,'reasons'=>[],'flags'=>[]];
$faceCtx=fn(array $d)=>['status'=>'ok','descriptor'=>$d,'score'=>0.9,'box_w'=>160,'faces'=>1];
function run(mysqli $c,int $uid,int $gid,string $no,array $extra): array { global $exp,$forensics;
  return screen_government_id($c,$uid,$gid,array_merge(['declared'=>['id_number'=>$no,'expiry'=>$exp,'mrz'=>mrz($no)],'attempt_no'=>1,'registered_name'=>'Juan Dela Cruz','forensics'=>$forensics],$extra)); }
function inbox_titles(mysqli $c): array { $r=$c->query("SELECT title FROM inbox_messages ORDER BY id"); $o=[]; while($x=$r->fetch_row()) $o[]=$x[0]; return $o; }
$admin=mk_user($conn,'admin@t.test','admin','active','Ad','Min'); $mgr=mk_user($conn,'mgr@t.test','manager','active','Ma','Nager'); $regular=mk_user($conn,'reg@t.test','user','active','Re','Gular');
$base=vec();

// 1 full auto-pass: strong face + OCR(vision) confirms + audit roll high
$u1=mk_user($conn,'u1@t.test'); save_face_embedding($conn,$u1,$base); $g1=mk_gov($conn,$u1,'s1','0000000000000001');
$r=run($conn,$u1,$g1,'P1234567A',['id_face'=>$faceCtx(at_distance($base,0.30)),'phase2'=>$vision('P1234567A'),'audit_roll'=>0.95]);
t('auto-pass', $r['outcome']==='pass', json_encode($r['reasons']));
$st=$conn->query("SELECT status,verification_status FROM users WHERE id=$u1")->fetch_assoc(); t('user active', $st['status']==='active'&&$st['verification_status']==='approved');
$row=$conn->query("SELECT face_distance FROM id_screenings WHERE id={$r['screening_id']}")->fetch_row(); t('face_distance saved ~0.30', abs((float)$row[0]-0.30)<0.01, (string)$row[0]);
t('descriptor stored', $conn->query("SELECT id_face_descriptor FROM government_ids WHERE id=$g1")->fetch_row()[0]!==null);
$titles=inbox_titles($conn); t('staff told: auto-approved (admin+manager only)', count(array_filter($titles,fn($x)=>str_starts_with($x,'Auto-approved: Juan')))===2 && !$conn->query("SELECT 1 FROM inbox_messages WHERE user_id=$regular")->fetch_row(), json_encode($titles));
$ib=$conn->query("SELECT link,type,ref_id FROM inbox_messages WHERE type='staff_id_auto_pass' LIMIT 1")->fetch_assoc(); t('notification link+ref', $ib['link']==='/admin/dashboard.php?tab=verifications' && (int)$ib['ref_id']===$u1);

// 2 same but random audit selects it -> admin, low risk, NO "approved" notification
$conn->query("DELETE FROM inbox_messages"); $u2=mk_user($conn,'u2@t.test'); save_face_embedding($conn,$u2,vec()); $g2=mk_gov($conn,$u2,'s2','1000000000000002'); $f2=vec(); 
$u2b=$conn->query("SELECT embedding FROM face_verifications WHERE user_id=$u2")->fetch_row()[0]; $reg2=json_decode($u2b,true);
$r=run($conn,$u2,$g2,'P7777777B',['id_face'=>$faceCtx(at_distance($reg2,0.3)),'phase2'=>$vision('P7777777B'),'audit_roll'=>0.01]);
t('audit sample -> admin', $r['outcome']==='admin' && $r['risk_level']==='low' && str_contains(implode(' ',$r['reasons']),'audit'), json_encode($r));
t('audit -> no notification', count(inbox_titles($conn))===0);

// 3 passport clean + strong face but OCR off -> admin w/ reason
$u3=mk_user($conn,'u3@t.test'); save_face_embedding($conn,$u3,vec()); $reg3=json_decode($conn->query("SELECT embedding FROM face_verifications WHERE user_id=$u3")->fetch_row()[0],true); $g3=mk_gov($conn,$u3,'s3','2000000000000003');
$r=run($conn,$u3,$g3,'P3333333C',['id_face'=>$faceCtx(at_distance($reg3,0.3)),'audit_roll'=>0.99]);
t('no OCR -> admin', $r['outcome']==='admin' && str_contains(implode(' ',$r['reasons']),'OCR'), json_encode($r['reasons']));

// 4 clearly different face -> auto-reject + notification with reason
$conn->query("DELETE FROM inbox_messages"); $u4=mk_user($conn,'u4@t.test'); save_face_embedding($conn,$u4,vec()); $reg4=json_decode($conn->query("SELECT embedding FROM face_verifications WHERE user_id=$u4")->fetch_row()[0],true); $g4=mk_gov($conn,$u4,'s4','3000000000000004');
$r=run($conn,$u4,$g4,'P4444444D',['id_face'=>$faceCtx(at_distance($reg4,0.95)),'phase2'=>$vision('P4444444D')]);
t('different face reject', $r['outcome']==='reject' && str_contains(implode(' ',$r['reasons']),'does not match the face'), json_encode($r['reasons']));
$st=$conn->query("SELECT status,verification_status,id_attempts FROM users WHERE id=$u4")->fetch_assoc(); t('rejected account kept', $st['status']==='pending_verification'&&$st['verification_status']==='rejected'&&(int)$st['id_attempts']===1);
$titles=inbox_titles($conn); t('staff told: auto-declined', count(array_filter($titles,fn($x)=>str_starts_with($x,'Auto-declined: Juan Dela Cruz (attempt 1 of 3)')))===2, json_encode($titles));
$body=$conn->query("SELECT body FROM inbox_messages WHERE type='staff_id_auto_reject' LIMIT 1")->fetch_row()[0]; t('decline body has reason', str_contains($body,'does not match the face'), $body);

// 5 same face as ANOTHER account's registered face -> admin high + notification (high risk)
$conn->query("DELETE FROM inbox_messages"); $u5=mk_user($conn,'u5@t.test'); save_face_embedding($conn,$u5,at_distance($base,0.1)); $g5=mk_gov($conn,$u5,'s5','4000000000000005');
$r=run($conn,$u5,$g5,'P5555555E',['id_face'=>$faceCtx(at_distance($base,0.2)),'phase2'=>$vision('P5555555E'),'audit_roll'=>0.9]);   // ID face ≈ user1's registered face
$ck=array_column($r['checks'],null,'name');
t('face_duplicate warn high', $ck['face_duplicate']['result']==='warn' && $ck['face_duplicate']['risk']==='high', $ck['face_duplicate']['detail']);
t('-> admin high', $r['outcome']==='admin' && $r['risk_level']==='high', json_encode([$r['outcome'],$r['risk_level']]));
t('high-risk notification', count(array_filter(inbox_titles($conn),fn($x)=>str_starts_with($x,'High-risk ID needs review: Juan')))===2, json_encode(inbox_titles($conn)));

// 6 same face on ANOTHER account's ID (their registered face unrelated)
$conn->query("DELETE FROM inbox_messages"); $u6=mk_user($conn,'u6@t.test'); save_face_embedding($conn,$u6,vec()); $reg6=json_decode($conn->query("SELECT embedding FROM face_verifications WHERE user_id=$u6")->fetch_row()[0],true); $g6=mk_gov($conn,$u6,'s6','5000000000000006');
$idFace=at_distance($reg6,0.3);  // matches own registration…
$r=run($conn,$u6,$g6,'P6666666F',['id_face'=>$faceCtx($idFace),'phase2'=>$vision('P6666666F'),'audit_roll'=>0.9]);
$u7=mk_user($conn,'u7@t.test'); save_face_embedding($conn,$u7,vec()); $g7=mk_gov($conn,$u7,'s7','6000000000000007');
$r7=run($conn,$u7,$g7,'P7000000G',['id_face'=>$faceCtx(at_distance($idFace,0.1)),'phase2'=>$vision('P7000000G'),'audit_roll'=>0.9]);   // same ID face as u6's ID
$ck7=array_column($r7['checks'],null,'name'); t('same face on another ID flagged', $ck7['face_duplicate']['result']==='warn' && str_contains($ck7['face_duplicate']['detail'],'face on the ID of account #'.$u6), $ck7['face_duplicate']['detail']);

// 7 browser sent nothing (JS off) -> skip -> admin; extracted face status recorded
$u8=mk_user($conn,'u8@t.test'); save_face_embedding($conn,$u8,vec()); $g8=mk_gov($conn,$u8,'s8','7000000000000008');
$r=run($conn,$u8,$g8,'P8000000H',['id_face'=>id_face_parse_post([]),'phase2'=>$vision('P8000000H'),'audit_roll'=>0.9]);
t('no descriptor -> admin', $r['outcome']==='admin' && array_column($r['checks'],null,'name')['face_match']['result']==='skip');

// 8 notification failure never breaks screening (staff list empty)
$conn->query("UPDATE users SET status='suspended' WHERE role IN ('admin','manager')");
$conn->query("DELETE FROM inbox_messages"); $u9=mk_user($conn,'u9@t.test'); save_face_embedding($conn,$u9,vec()); $reg9=json_decode($conn->query("SELECT embedding FROM face_verifications WHERE user_id=$u9")->fetch_row()[0],true); $g9=mk_gov($conn,$u9,'s9','8000000000000009');
$r=run($conn,$u9,$g9,'P9000000J',['id_face'=>$faceCtx(at_distance($reg9,0.3)),'phase2'=>$vision('P9000000J'),'audit_roll'=>0.9]);
t('no staff available -> still approves', $r['outcome']==='pass');
echo "phase3 db ok=$ok bad=$bad\n";
