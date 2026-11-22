<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';

// Staff are never locked out; nobody needs this page when the site is open.
if (maint_is_staff()) {
    redirect('/admin/dashboard.php');
}
$m = maintenance_current();
if ($m === null) {
    redirect(is_logged_in() ? '/dashboard.php' : '/auth/login.php');
}

http_response_code(503);
header('Retry-After: 60');
$base    = rtrim(BASE_URL, '/');
$endsFmt = $m['ends_at'] ? date('D, M j, Y', strtotime((string) $m['ends_at'])) : null;      // date
$endsTime = $m['ends_at'] ? date('H:i', strtotime((string) $m['ends_at'])) : null;            // 24-hour clock
$logo    = $base . '/img/logo1.png';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Server maintenance · SafeTrade</title>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;}
body{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;font-family:'Inter',sans-serif;color:#fff;
  background:radial-gradient(640px 640px at 88% -10%,rgba(217,148,42,.34),transparent 65%),linear-gradient(135deg,#092C21 0%,#155943 55%,#1D7254 100%);}
.card{max-width:500px;width:100%;text-align:center;padding:46px 36px;border-radius:28px;border:1px solid rgba(255,255,255,.3);
  background:linear-gradient(160deg,rgba(6,36,26,.58),rgba(6,36,26,.38));backdrop-filter:blur(24px);-webkit-backdrop-filter:blur(24px);box-shadow:0 30px 80px rgba(3,20,14,.45);}
.logo{text-decoration:none;transition:transform .2s;width:92px;height:92px;margin:0 auto;border-radius:24px;display:flex;align-items:center;justify-content:center;
  background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.28);box-shadow:0 12px 34px rgba(3,20,14,.4),inset 0 1px 0 rgba(255,255,255,.25);position:relative;}
.logo:hover{transform:translateY(-2px) scale(1.04);}
.logo img{width:62px;height:62px;object-fit:contain;}
.logo .gear{position:absolute;right:-10px;bottom:-10px;width:36px;height:36px;border-radius:50%;background:#F3C877;color:#5B3D06;
  display:flex;align-items:center;justify-content:center;font-size:19px;box-shadow:0 6px 16px rgba(0,0,0,.35);animation:wob 2.4s ease-in-out infinite;transform-origin:70% 70%;}
@keyframes wob{0%,100%{transform:rotate(-14deg);}50%{transform:rotate(14deg);}}
.badge{display:inline-flex;align-items:center;gap:7px;margin-top:20px;padding:6px 14px;border-radius:999px;background:rgba(243,200,119,.14);border:1px solid rgba(243,200,119,.4);color:#F8DFA6;font-size:11.5px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;}
.badge i{width:7px;height:7px;border-radius:50%;background:#F3C877;animation:pulse 1.6s infinite;}
@keyframes pulse{0%,100%{opacity:1;}50%{opacity:.25;}}
.cdgrid{display:flex;justify-content:center;gap:10px;margin-top:22px;}
.cdbox{min-width:70px;padding:12px 6px 10px;border-radius:16px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.18);}
.cdbox b{display:block;font-family:'Fraunces',serif;font-size:30px;line-height:1;color:#F3C877;font-variant-numeric:tabular-nums;}
.cdbox span{display:block;margin-top:6px;font-size:10px;letter-spacing:.14em;text-transform:uppercase;color:rgba(255,255,255,.65);}
.back{margin-top:18px;padding:12px 16px;border-radius:14px;background:rgba(0,0,0,.2);font-size:13px;color:rgba(255,255,255,.85);}
.back strong{color:#fff;}
.back .t{display:inline-block;margin-left:6px;padding:2px 9px;border-radius:8px;background:#F3C877;color:#4A3205;font-weight:700;font-variant-numeric:tabular-nums;}
.bar{margin-top:20px;height:4px;border-radius:4px;background:rgba(255,255,255,.14);overflow:hidden;}
.bar::after{content:'';display:block;height:100%;width:38%;border-radius:4px;background:linear-gradient(90deg,transparent,#F3C877,transparent);animation:slide 1.8s ease-in-out infinite;}
@keyframes slide{0%{margin-left:-40%;}100%{margin-left:100%;}}
h1{font-family:'Fraunces',serif;font-size:29px;margin-top:26px;}
p{margin-top:12px;color:rgba(255,255,255,.88);line-height:1.65;font-size:14.5px;}
.name{margin-top:18px;display:inline-block;padding:7px 16px;border-radius:999px;background:rgba(243,200,119,.18);border:1px solid rgba(243,200,119,.4);color:#F8DFA6;font-weight:600;font-size:13px;}
.sm{font-size:12.5px;color:rgba(255,255,255,.7);}
a.homebtn{display:inline-block;margin-top:22px;padding:11px 26px;border-radius:999px;background:#F3C877;color:#4A3205;font-size:13.5px;font-weight:700;box-shadow:0 8px 22px rgba(0,0,0,.3);transition:transform .15s,background .15s;}
a.homebtn:hover{background:#FFD98A;transform:translateY(-1px);}
a{color:#FFD98A;font-weight:600;text-decoration:none;}
</style>
</head>
<body>
<div class="card">
  <a class="logo" href="<?= e($base) ?>/Index.html" title="Back to SafeTrade home"><img src="<?= e($logo) ?>" alt="SafeTrade" onerror="this.style.display='none'"><span class="gear">🔧</span></a>
  <div class="badge"><i></i>Maintenance in progress</div>
  <h1>Server is under maintenance</h1>
  <p>We're making SafeTrade better. Please wait — this page will take you back in automatically as soon as maintenance is finished.</p>
  <div class="name"><?= e((string) $m['title']) ?></div>
  <?php if ($endsFmt): ?>
    <div class="cdgrid" id="cd" data-secs="<?= max(0, (int) $m['secs_left']) ?>">
      <div class="cdbox"><b id="cd-d">0</b><span>Days</span></div>
      <div class="cdbox"><b id="cd-h">00</b><span>Hours</span></div>
      <div class="cdbox"><b id="cd-m">00</b><span>Minutes</span></div>
      <div class="cdbox"><b id="cd-s">00</b><span>Seconds</span></div>
    </div>
    <div class="back">Expected back on <strong><?= e($endsFmt) ?></strong> at <span class="t"><?= e($endsTime) ?></span> <span class="sm">(24-hour)</span></div>
  <?php else: ?>
    <p class="sm">We'll be back shortly.</p>
  <?php endif; ?>
  <div class="bar"></div>
  <a class="homebtn" href="<?= e($base) ?>/auth/login.php">Log in →</a>
  <p class="sm" style="margin-top:14px;">Admins &amp; managers can still log in during maintenance.</p>
</div>
<script>
(function () {
  var cd = document.getElementById('cd');
  var secs = cd ? parseInt(cd.getAttribute('data-secs'), 10) : 0;
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function render() {
    if (!cd) return;
    var s = Math.max(0, secs);
    document.getElementById('cd-d').textContent = Math.floor(s / 86400);
    document.getElementById('cd-h').textContent = pad(Math.floor(s % 86400 / 3600));
    document.getElementById('cd-m').textContent = pad(Math.floor(s % 3600 / 60));
    document.getElementById('cd-s').textContent = pad(s % 60);
  }
  render();
  setInterval(function () { secs--; render(); }, 1000);

  // Check the server every 5s; the moment maintenance is over, send them back in.
  setInterval(function () {
    fetch('<?= e($base) ?>/maintenance-status.php', { cache: 'no-store', credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) { if (d && d.active === false) window.location.href = '<?= e($base) ?>/dashboard.php'; })
      .catch(function () {});
  }, 5000);
})();
</script>
</body>
</html>
