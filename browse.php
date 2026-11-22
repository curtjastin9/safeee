<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';

/* browse.php — public, no-login-required marketplace browsing (guest only).
   Logged-in users are bounced to dashboard.php. No DB writes happen here;
   "Chat" / "Message seller" send guests to auth/login.php?redirect_product=ID.

   DESIGN: this page now uses the exact same shell + product cards as the
   logged-in user dashboard (green sidebar, gold CTA, dark-bordered cards,
   market search, category/posted filters) so guests and members see one
   consistent, modern marketplace. */

if (is_logged_in()) {
    $qs = $_GET;
    $qs['view'] = in_array($qs['view'] ?? '', ['item'], true) ? 'item' : 'market';
    redirect('/dashboard.php?' . http_build_query($qs));
}

$conn = db();

$categoriesFlat = [];
try {
    $catRes = $conn->query('SELECT id, name, parent_id, icon FROM categories ORDER BY (parent_id IS NULL) DESC, parent_id ASC, name ASC');
    while ($row = $catRes->fetch_assoc()) {
        $categoriesFlat[] = [
            'id'        => (int) $row['id'],
            'name'      => $row['name'],
            'parent_id' => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
            'icon'      => $row['icon'] !== null && $row['icon'] !== '' ? $row['icon'] : '📦',
        ];
    }
} catch (Throwable $e) {
    $catRes = $conn->query('SELECT id, name FROM categories ORDER BY name');
    while ($row = $catRes->fetch_assoc()) {
        $categoriesFlat[] = ['id' => (int) $row['id'], 'name' => $row['name'], 'parent_id' => null, 'icon' => '📦'];
    }
}
$topCategories = array_values(array_filter($categoriesFlat, fn($c) => $c['parent_id'] === null));

function bp_children(array $flat, int $parentId): array
{
    return array_values(array_filter($flat, fn($c) => $c['parent_id'] === $parentId));
}

function bp_thumbs(?string $concat): array
{
    if (!$concat) return [];
    $ids = array_values(array_filter(explode('|', $concat), fn($p) => $p !== ''));
    $ids = array_slice($ids, 0, 6);
    return array_map(fn($id) => 'image.php?type=product&id=' . (int) $id, $ids);
}

function bp_qty(array $row): int
{
    return isset($row['quantity']) ? (int) $row['quantity'] : 1;
}

function bp_query_string(array $overrides = []): string
{
    $params = [
        'cat'    => (int) ($_GET['cat'] ?? 0),
        'q'      => trim((string) ($_GET['q'] ?? '')),
        'posted' => (string) ($_GET['posted'] ?? ''),
    ];
    $params = array_merge($params, $overrides);
    $qs = 'view=market';
    if (!empty($params['cat'])) $qs .= '&cat=' . (int) $params['cat'];
    if (($params['q'] ?? '') !== '') $qs .= '&q=' . urlencode($params['q']);
    if (($params['posted'] ?? '') !== '') $qs .= '&posted=' . urlencode($params['posted']);
    return '?' . $qs;
}

function bp_nav_link(string $href, string $label, bool $active, string $iconPath): string
{
    return '<a class="side-link' . ($active ? ' active' : '') . '" href="' . e($href) . '">'
         . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $iconPath . '</svg>'
         . '<span>' . e($label) . '</span></a>';
}

$imagesSubquery = "(SELECT GROUP_CONCAT(pi.id ORDER BY pi.id ASC SEPARATOR '|') FROM product_images pi WHERE pi.product_id = p.id) AS images_concat";

$view = ($_GET['view'] ?? 'market') === 'item' ? 'item' : 'market';

$item          = null;
$items         = null;
$catFilter     = 0;
$searchQuery   = '';
$postedFilter  = '';
$postedLabels  = ['today' => 'today', 'week' => 'this week', 'month' => 'this month', 'year' => 'this year'];

if ($view === 'item') {
    $id = (int) ($_GET['id'] ?? 0);
    $stmt = $conn->prepare(
        "SELECT p.*, u.first_name, u.last_name, u.id AS seller_id2, $imagesSubquery
         FROM products p JOIN users u ON u.id = p.seller_id WHERE p.id=? LIMIT 1"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $item = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($item && ((int) $item['is_deleted'] === 1 || (int) $item['is_active'] === 0)) {
        $item = null;
    }
} else {
    $catFilter   = (int) ($_GET['cat'] ?? 0);
    $searchQuery = trim(clean_input($_GET['q'] ?? ''));

    $postedFilter = (string) ($_GET['posted'] ?? '');
    if (!in_array($postedFilter, ['today', 'week', 'month', 'year'], true)) $postedFilter = '';

    $catIds = [];
    if ($catFilter) {
        $catIds[] = $catFilter;
        foreach ($categoriesFlat as $c) {
            if ($c['parent_id'] === $catFilter) $catIds[] = $c['id'];
        }
    }

    $where  = ['COALESCE(p.is_active,1)=1', 'COALESCE(p.is_deleted,0)=0', 'COALESCE(p.is_draft,0)=0'];
    $params = [];
    $types  = '';
    if ($catIds) {
        $where[] = 'p.category_id IN (' . implode(',', array_fill(0, count($catIds), '?')) . ')';
        foreach ($catIds as $cid) { $params[] = $cid; $types .= 'i'; }
    }
    if ($searchQuery !== '') {
        $where[]  = '(p.title LIKE ? OR p.description LIKE ?)';
        $like     = '%' . $searchQuery . '%';
        $params[] = $like; $params[] = $like; $types .= 'ss';
    }
    switch ($postedFilter) {
        case 'today': $where[] = 'DATE(p.created_at) = CURDATE()'; break;
        case 'week':  $where[] = 'YEARWEEK(p.created_at, 1) = YEARWEEK(CURDATE(), 1)'; break;
        case 'month': $where[] = 'YEAR(p.created_at) = YEAR(CURDATE()) AND MONTH(p.created_at) = MONTH(CURDATE())'; break;
        case 'year':  $where[] = 'YEAR(p.created_at) = YEAR(CURDATE())'; break;
    }
    $whereSql = implode(' AND ', $where);

    $sql = "SELECT p.*, u.first_name, u.last_name, $imagesSubquery
            FROM products p JOIN users u ON u.id=p.seller_id
            WHERE $whereSql ORDER BY p.created_at DESC LIMIT 60";
    $stmt = $conn->prepare($sql);
    if ($types) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $items = $stmt->get_result();
    $stmt->close();
}

$pageTitle = ($view === 'item' && $item) ? ($item['title'] . ' · SafeTrade') : 'Browse Market · SafeTrade';
$base = rtrim(BASE_URL, '/');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,400;0,9..144,500;0,9..144,600;1,9..144,500&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500&display=swap" rel="stylesheet">
<style>
/* ---- Same base styles as dashboard.php (marketplace-ui.css is layered on top below) ---- */
:root{--cream:#FBF7EF;--paper:#F4EEE1;--paper-2:#ECE2CC;--ink:#161F16;--ink-soft:#5A6252;--ink-faint:#8B927F;--brand:#155943;--brand-dark:#092C21;--brand-light:#E4EFE7;--gold:#C6871F;--gold-light:#F5E6C2;--clay:#C97B52;--line:rgba(22,31,22,.10);--line-strong:rgba(22,31,22,.17);--radius:18px;--radius-lg:22px;--danger:#B3452F;--danger-bg:#F8E8DF;--shadow-xs:0 1px 2px rgba(9,44,33,.05);--shadow-sm:0 3px 12px rgba(9,44,33,.08);--shadow-md:0 12px 30px rgba(9,44,33,.13);--ease:cubic-bezier(.22,.75,.28,1);}
*{box-sizing:border-box;}
body{margin:0;background:var(--cream);color:var(--ink);font-family:'Inter',sans-serif;-webkit-font-smoothing:antialiased;}
h1,h2,h3{font-family:'Fraunces',serif;font-weight:600;margin:0;letter-spacing:-.01em;}
a{color:inherit;text-decoration:none;}
*{scrollbar-width:thin;scrollbar-color:var(--line-strong) transparent;}
*::-webkit-scrollbar{width:8px;height:8px;}
*::-webkit-scrollbar-thumb{background:var(--line-strong);border-radius:99px;}

.app{display:grid;grid-template-columns:224px 1fr;min-height:100vh;}
.side-main{background:linear-gradient(190deg,var(--brand) 0%,var(--brand-dark) 100%);color:var(--cream);padding:24px 14px;position:sticky;top:0;height:100vh;display:flex;flex-direction:column;box-shadow:2px 0 24px rgba(9,44,33,.14);z-index:5;}
.side-logo{display:flex;align-items:center;gap:11px;font-family:'Fraunces',serif;font-weight:700;font-size:19.5px;padding:8px 10px 24px;letter-spacing:-.01em;}
.side-toggle{background:rgba(251,247,239,.10);border:none;color:var(--cream);width:32px;height:32px;border-radius:10px;display:flex;align-items:center;justify-content:center;cursor:pointer;flex-shrink:0;padding:0;transition:background .15s var(--ease);}
.side-toggle:hover{background:rgba(251,247,239,.20);}
.side-toggle svg{width:17px;height:17px;}
.side-logo-text{white-space:nowrap;}
.app.sidebar-collapsed{grid-template-columns:78px 1fr;}
.app.sidebar-collapsed .side-logo{justify-content:center;padding-left:0;padding-right:0;}
.app.sidebar-collapsed .side-logo-text,.app.sidebar-collapsed .side-cta span,.app.sidebar-collapsed .side-link span,.app.sidebar-collapsed .side-foot{display:none;}
.app.sidebar-collapsed .side-cta,.app.sidebar-collapsed .side-link{justify-content:center;padding:12px;}
.side-nav{display:flex;flex-direction:column;gap:3px;}
.side-link{display:flex;align-items:center;gap:12px;padding:11px 13px;border-radius:13px;font-size:14px;font-weight:500;color:rgba(251,247,239,.70);transition:background .15s var(--ease),color .15s var(--ease),transform .15s var(--ease);position:relative;}
.side-link svg{width:18px;height:18px;flex-shrink:0;}
.side-cta{display:flex;align-items:center;gap:10px;background:var(--gold);color:var(--brand-dark);font-weight:700;font-size:14px;padding:13px 16px;border-radius:14px;margin:4px 4px 18px;box-shadow:0 3px 10px rgba(0,0,0,.18);transition:background .15s var(--ease),transform .15s var(--ease),box-shadow .15s var(--ease);}
.side-cta svg{width:18px;height:18px;flex-shrink:0;}
.side-cta:hover{background:var(--gold-light);transform:translateY(-1px);box-shadow:0 6px 16px rgba(0,0,0,.22);}
.side-link:hover{background:rgba(251,247,239,.09);color:var(--cream);transform:translateX(1px);}
.side-link.active{background:rgba(251,247,239,.16);color:var(--cream);font-weight:600;}
.side-link.active::before{content:"";position:absolute;left:-14px;top:50%;transform:translateY(-50%);width:4px;height:20px;border-radius:0 4px 4px 0;background:var(--gold);box-shadow:0 0 10px rgba(198,135,31,.5);}
.side-foot{margin-top:auto;font-size:12px;color:rgba(251,247,239,.6);line-height:1.7;padding:8px;}
.main-col{padding:28px 32px 64px;overflow-y:auto;}

.row-head{display:flex;align-items:baseline;justify-content:space-between;margin:26px 0 14px;}
.row-head:first-child{margin-top:0;}
.row-head h2{font-size:19px;}
.crumb{display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:600;color:var(--ink-soft);margin-bottom:16px;transition:color .15s ease;}
.crumb:hover{color:var(--brand);}
.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;}
.price{font-family:'Fraunces',serif;font-weight:600;color:var(--brand);font-size:15.5px;}

.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:11px 18px;border-radius:999px;font-weight:600;font-size:13.5px;border:none;cursor:pointer;background:var(--brand);color:var(--cream);transition:background .16s var(--ease),transform .16s var(--ease),box-shadow .16s var(--ease);box-shadow:var(--shadow-xs);}
.btn:hover{background:var(--brand-dark);transform:translateY(-1px);box-shadow:var(--shadow-sm);}
.btn.ghost{background:transparent;border:1.5px solid var(--line-strong);color:var(--ink);box-shadow:none;}
.btn.ghost:hover{border-color:var(--brand);color:var(--brand);background:var(--brand-light);}
.btn.sm{padding:7px 14px;font-size:12px;}

.field{margin-top:15px;}
.field label{font-size:12px;font-weight:600;color:var(--ink-soft);letter-spacing:.01em;}
.field select{width:100%;margin-top:6px;padding:11.5px 13px;border-radius:12px;border:1.5px solid var(--line-strong);background:var(--cream);font-family:inherit;font-size:13.5px;color:var(--ink);}
.field-row{display:flex;gap:12px;} .field-row .field{flex:1;}

.pill{display:inline-block;font-size:11px;font-weight:700;padding:4.5px 11px;border-radius:999px;background:var(--gold-light);color:#7A5308;letter-spacing:.01em;}
.pill.ok{background:var(--brand-light);color:var(--brand-dark);}
.status-banner{background:var(--brand-light);color:var(--brand-dark);border-radius:13px;padding:13px 16px;font-size:13.5px;font-weight:600;margin:15px 0;}
.loc-banner{display:flex;align-items:center;gap:10px;background:var(--gold-light);color:#7A5308;border-radius:13px;padding:11px 15px;font-size:12.5px;font-weight:600;margin-bottom:18px;}
.loc-banner a{text-decoration:underline;}

.p-card{background:var(--paper);border:1px solid var(--line);border-radius:var(--radius);overflow:hidden;display:flex;flex-direction:column;transition:transform .2s var(--ease),box-shadow .2s var(--ease);}
.p-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-md);}
.p-media{position:relative;display:block;aspect-ratio:4/3;background:var(--cream);overflow:hidden;}
.photo-strip{display:flex;overflow-x:auto;scroll-snap-type:x mandatory;height:100%;scrollbar-width:none;-ms-overflow-style:none;}
.photo-strip::-webkit-scrollbar{display:none;}
.photo-strip img{flex:0 0 100%;width:100%;height:100%;object-fit:cover;scroll-snap-align:start;}
.p-media-fallback{display:flex;align-items:center;justify-content:center;height:100%;font-size:46px;}
.dots{position:absolute;bottom:9px;left:0;right:0;display:flex;justify-content:center;gap:5px;pointer-events:none;}
.dot{width:5px;height:5px;border-radius:50%;background:rgba(255,255,255,.55);transition:all .15s ease;}
.dot.active{background:#fff;width:6px;height:6px;}
.p-body{padding:14px 16px 16px;display:flex;flex-direction:column;gap:4px;}
.p-price{font-family:'Fraunces',serif;font-weight:600;color:var(--brand);font-size:17px;}
.p-title{font-size:13.5px;font-weight:600;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.p-meta{font-size:11.5px;color:var(--ink-soft);}
.p-seller{display:flex;align-items:center;justify-content:space-between;margin-top:9px;padding-top:11px;border-top:1px solid var(--line);gap:8px;}
.p-seller-info{display:flex;align-items:center;gap:8px;font-size:11.5px;color:var(--ink-soft);line-height:1.5;}
.p-avatar{width:25px;height:25px;border-radius:50%;background:linear-gradient(160deg,var(--brand),var(--brand-dark));color:var(--cream);display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;flex-shrink:0;}
.chat-pill{border:1.5px solid var(--line-strong);background:transparent;border-radius:999px;padding:6.5px 13px;font-size:11.5px;font-weight:600;cursor:pointer;color:var(--ink);white-space:nowrap;transition:all .15s var(--ease);display:inline-block;}
.chat-pill:hover{border-color:var(--brand);color:var(--brand);background:var(--brand-light);}

.detail-card{background:var(--paper);border:1px solid var(--line);border-radius:var(--radius-lg);padding:26px;max-width:760px;box-shadow:var(--shadow-xs);}
.strip-hero{aspect-ratio:16/10;border-radius:var(--radius-lg);overflow:hidden;position:relative;margin-bottom:18px;box-shadow:var(--shadow-sm);}
.strip-hero .p-media-fallback{font-size:64px;background:var(--cream);}
.thumbs-row{display:flex;gap:10px;margin:-6px 0 18px;overflow-x:auto;}
.thumbs-row img{width:70px;height:70px;object-fit:cover;border-radius:12px;border:2px solid transparent;cursor:pointer;opacity:.7;transition:.15s;flex:none;}
.thumbs-row img:hover,.thumbs-row img.on{opacity:1;border-color:var(--brand);}

.market-search{display:flex;align-items:center;gap:10px;background:var(--paper);border:1px solid var(--line);border-radius:999px;padding:6px 8px 6px 17px;margin-bottom:18px;max-width:520px;transition:border-color .15s ease,box-shadow .15s ease;}
.market-search:focus-within{border-color:var(--brand);box-shadow:0 0 0 3px rgba(21,89,67,.12);}
.market-search-icon{font-size:14px;color:var(--ink-soft);flex-shrink:0;}
.market-search input[type="text"]{flex:1;border:none;background:transparent;padding:10px 4px;font-family:inherit;font-size:14px;color:var(--ink);outline:none;}
.market-search-clear{flex-shrink:0;color:var(--ink-soft);font-size:13px;padding:4px;}
.market-search-clear:hover{color:var(--danger);}
.market-search .btn.sm{flex-shrink:0;}

.empty-note{grid-column:1/-1;text-align:center;background:var(--paper);border:1.5px dashed var(--line-strong);border-radius:22px;padding:60px 20px;}
.empty-note .ei{font-size:50px;}
.empty-note h3{font-size:20px;margin-top:10px;}
.empty-note p{color:var(--ink-soft);font-size:13.5px;margin:6px 0 16px;}

@media(max-width:1100px){.app{grid-template-columns:78px 1fr;}.side-logo{justify-content:center;padding-left:0;padding-right:0;}.side-logo-text{display:none;}.side-link span,.side-cta span{display:none;}.side-cta{justify-content:center;padding:12px;}.side-link{justify-content:center;padding:12px;}}
@media(max-width:640px){.grid{grid-template-columns:1fr;}}
</style>
<!-- Same stylesheets the logged-in dashboard gets (browse.php is on theme-inject's skip list, so link them here) -->
<link rel="stylesheet" href="<?= e($base) ?>/assets/css/theme.css?v=<?= is_file(__DIR__ . '/assets/css/theme.css') ? filemtime(__DIR__ . '/assets/css/theme.css') : 1 ?>">
<link rel="stylesheet" href="<?= e($base) ?>/assets/css/marketplace-ui.css?v=<?= is_file(__DIR__ . '/assets/css/marketplace-ui.css') ? filemtime(__DIR__ . '/assets/css/marketplace-ui.css') : 1 ?>">
</head>
<body>
<div class="app">

  <!-- ===================== Sidebar (guest version of the user sidebar) ===================== -->
  <aside class="side-main">
    <div class="side-logo">
      <button type="button" class="side-toggle" id="side-toggle" aria-label="Toggle sidebar" aria-expanded="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="4" y1="7" x2="20" y2="7"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="17" x2="20" y2="17"/></svg>
      </button>
      <span class="side-logo-text">SafeTrade</span>
    </div>
    <a class="side-cta" href="auth/register.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
      <span>Sell an item</span>
    </a>
    <nav class="side-nav">
      <?= bp_nav_link('Index.html', 'Home', false, '<path d="M3 11l9-7 9 7"/><path d="M5 10v10h14V10"/>') ?>
      <?= bp_nav_link('browse.php', 'Browse Market', true, '<path d="M4 4h16l1.4 5.2a1 1 0 01-1 1.3H3.6a1 1 0 01-1-1.3L4 4z"/><path d="M4.5 10.5V20h15v-9.5"/><path d="M9.5 20v-6.5h5V20"/>') ?>
      <?= bp_nav_link('Index.html#how', 'How it works', false, '<circle cx="12" cy="12" r="9"/><path d="M12 8v4l3 2"/>') ?>
      <?= bp_nav_link('Index.html#why', 'Why SafeTrade', false, '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/>') ?>
      <div class="side-sec">Account</div>
      <?= bp_nav_link('auth/login.php', 'Log in', false, '<path d="M15 3h4a2 2 0 012 2v14a2 2 0 01-2 2h-4"/><path d="M10 17l5-5-5-5M15 12H3"/>') ?>
      <?= bp_nav_link('auth/register.php', 'Get Verified', false, '<circle cx="12" cy="8" r="4"/><path d="M4 20c0-4.4 3.6-7 8-7s8 2.6 8 7"/>') ?>
    </nav>
    <div class="side-foot" id="side-foot">
      <div class="sf-top">
        <div class="sf-av">👋</div>
        <div class="sf-id">
          <div class="sf-name">Guest</div>
          <span class="sf-badge">Browsing only</span>
        </div>
      </div>
      <div class="sf-actions">
        <a class="sf-profile" href="auth/register.php">Register</a>
        <a class="sf-logout" href="auth/login.php">Log in</a>
      </div>
    </div>
  </aside>

  <script>
  (function () {
    var appEl = document.querySelector('.app');
    var toggleBtn = document.getElementById('side-toggle');
    if (!appEl || !toggleBtn) return;
    var KEY = 'safetrade_sidebar_collapsed';
    function apply(c) { appEl.classList.toggle('sidebar-collapsed', c); toggleBtn.setAttribute('aria-expanded', c ? 'false' : 'true'); }
    try { apply(localStorage.getItem(KEY) === '1'); } catch (e) {}
    toggleBtn.addEventListener('click', function () {
      var c = !appEl.classList.contains('sidebar-collapsed');
      apply(c);
      try { localStorage.setItem(KEY, c ? '1' : '0'); } catch (e) {}
    });
  })();
  </script>

  <!-- ===================== Main ===================== -->
  <main class="main-col">

<?php if ($view === 'market'): ?>

    <div class="loc-banner">👋 Browsing as a guest — look around freely. <a href="auth/login.php">Log in</a> when you're ready to message a seller.</div>

    <div class="row-head">
      <h2><?= $searchQuery !== '' ? 'Results for “' . e($searchQuery) . '”' : 'Browse market' ?></h2>
      <span style="font-size:13px;color:var(--ink-soft);font-weight:500;"><?= (int) $items->num_rows ?> listing<?= $items->num_rows === 1 ? '' : 's' ?></span>
    </div>

    <form method="get" class="market-search" role="search">
      <input type="hidden" name="view" value="market">
      <?php if ($catFilter): ?><input type="hidden" name="cat" value="<?= (int) $catFilter ?>"><?php endif; ?>
      <?php if ($postedFilter !== ''): ?><input type="hidden" name="posted" value="<?= e($postedFilter) ?>"><?php endif; ?>
      <span class="market-search-icon">🔍</span>
      <input type="text" name="q" value="<?= e($searchQuery) ?>" placeholder="Search Marketplace">
      <?php if ($searchQuery !== ''): ?>
        <a class="market-search-clear" href="<?= e(bp_query_string(['q' => ''])) ?>" title="Clear search">✕</a>
      <?php endif; ?>
      <button type="submit" class="btn sm">Search</button>
    </form>

    <div class="field-row" style="max-width:620px;margin-bottom:20px;gap:14px;">
      <div class="field" style="margin-top:0;">
        <label>Category</label>
        <select onchange="if(this.value) window.location.href=this.value;">
          <option value="<?= e(bp_query_string(['cat' => 0])) ?>" <?= empty($catFilter) ? 'selected' : '' ?>>All Categories</option>
          <?php foreach ($topCategories as $top):
            $subs = bp_children($categoriesFlat, $top['id']); ?>
            <?php if ($subs): ?>
              <optgroup label="<?= e($top['icon'] . ' ' . $top['name']) ?>">
                <option value="<?= e(bp_query_string(['cat' => $top['id']])) ?>" <?= $catFilter === $top['id'] ? 'selected' : '' ?>><?= e($top['name']) ?> (general)</option>
                <?php foreach ($subs as $sc): ?>
                  <option value="<?= e(bp_query_string(['cat' => $sc['id']])) ?>" <?= $catFilter === $sc['id'] ? 'selected' : '' ?>><?= e($sc['name']) ?></option>
                <?php endforeach; ?>
              </optgroup>
            <?php else: ?>
              <option value="<?= e(bp_query_string(['cat' => $top['id']])) ?>" <?= $catFilter === $top['id'] ? 'selected' : '' ?>><?= e($top['icon'] . ' ' . $top['name']) ?></option>
            <?php endif; ?>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field" style="margin-top:0;">
        <label>Posted</label>
        <select onchange="if(this.value) window.location.href=this.value;">
          <option value="<?= e(bp_query_string(['posted' => ''])) ?>" <?= $postedFilter === '' ? 'selected' : '' ?>>Any time</option>
          <option value="<?= e(bp_query_string(['posted' => 'today'])) ?>" <?= $postedFilter === 'today' ? 'selected' : '' ?>>Today</option>
          <option value="<?= e(bp_query_string(['posted' => 'week'])) ?>" <?= $postedFilter === 'week' ? 'selected' : '' ?>>This week</option>
          <option value="<?= e(bp_query_string(['posted' => 'month'])) ?>" <?= $postedFilter === 'month' ? 'selected' : '' ?>>This month</option>
          <option value="<?= e(bp_query_string(['posted' => 'year'])) ?>" <?= $postedFilter === 'year' ? 'selected' : '' ?>>This year</option>
        </select>
      </div>
    </div>

    <div class="grid">
      <?php if ($items->num_rows === 0): ?>
        <div class="empty-note">
          <div class="ei">🔍</div>
          <h3><?= $searchQuery !== '' ? 'No items match “' . e($searchQuery) . '”' : 'No items found' ?></h3>
          <p>
            <?php if ($postedFilter !== ''): ?>Nothing posted <?= e($postedLabels[$postedFilter]) ?> — try a wider time range.
            <?php elseif ($catFilter): ?>Try another category or clear your search.
            <?php else: ?>Check back soon — new listings are posted every day.<?php endif; ?>
          </p>
          <a class="btn sm" href="?view=market">View all listings</a>
        </div>
      <?php endif; ?>

      <?php while ($it = $items->fetch_assoc()):
        $thumbs = bp_thumbs($it['images_concat'] ?? null);
        $qty    = bp_qty($it); ?>
        <div class="p-card">
          <a href="?view=item&id=<?= (int) $it['id'] ?>" class="p-media">
            <?php if ($thumbs): ?>
              <div class="photo-strip">
                <?php foreach ($thumbs as $src): ?><img src="<?= e($src) ?>" alt="" loading="lazy"><?php endforeach; ?>
              </div>
              <?php if (count($thumbs) > 1): ?>
                <div class="dots">
                  <?php foreach ($thumbs as $idx => $_): ?><span class="dot<?= $idx === 0 ? ' active' : '' ?>"></span><?php endforeach; ?>
                </div>
              <?php endif; ?>
            <?php else: ?>
              <div class="p-media-fallback"><?= e($it['icon']) ?></div>
            <?php endif; ?>
          </a>
          <div class="p-body">
            <div class="p-price">₱<?= number_format((float) $it['price'], 2) ?></div>
            <div class="sub" style="font-size:11px;color:var(--ink-soft);margin-top:1px;"><?= $qty ?> left</div>
            <a class="p-title" href="?view=item&id=<?= (int) $it['id'] ?>"><?= e($it['title']) ?></a>
            <div class="p-meta"><?= e($it['location']) ?></div>
            <div class="p-seller">
              <div class="p-seller-info">
                <span class="p-avatar"><?= e(strtoupper(substr($it['first_name'], 0, 1) . substr($it['last_name'], 0, 1))) ?></span>
                <span><?= e($it['first_name']) ?><br><small><?= e(time_ago($it['created_at'])) ?></small></span>
              </div>
              <a class="chat-pill" href="auth/login.php?redirect_product=<?= (int) $it['id'] ?>">💬 Chat</a>
            </div>
          </div>
        </div>
      <?php endwhile; ?>
    </div>

<?php else: /* item */ ?>

    <a class="crumb" href="?view=market">← Back to market</a>
    <?php if (!$item): ?>
      <div class="grid"><div class="empty-note"><div class="ei">😕</div><h3>Item not found</h3><p>This listing may have been sold or removed.</p><a class="btn sm" href="?view=market">Browse market</a></div></div>
    <?php else:
      $heroThumbs = bp_thumbs($item['images_concat'] ?? null);
      $itQty      = bp_qty($item); ?>
      <div class="detail-card">
        <div class="strip-hero">
          <?php if ($heroThumbs): ?>
            <div class="photo-strip">
              <?php foreach ($heroThumbs as $src): ?><img src="<?= e($src) ?>" alt=""><?php endforeach; ?>
            </div>
            <?php if (count($heroThumbs) > 1): ?>
              <div class="dots"><?php foreach ($heroThumbs as $idx => $_): ?><span class="dot<?= $idx === 0 ? ' active' : '' ?>"></span><?php endforeach; ?></div>
            <?php endif; ?>
          <?php else: ?>
            <div class="p-media-fallback"><?= e($item['icon']) ?></div>
          <?php endif; ?>
        </div>
        <h2><?= e($item['title']) ?></h2>
        <div style="margin-top:6px;color:var(--ink-soft);font-size:13px;"><?= e($item['location']) ?> · posted <?= e(time_ago($item['created_at'])) ?></div>
        <div class="price" style="font-size:24px;margin-top:12px;">₱<?= number_format((float) $item['price'], 2) ?></div>
        <div style="margin-top:4px;color:var(--ink-soft);font-size:13px;">📦 <?= $itQty ?> available</div>
        <p style="margin-top:14px;color:var(--ink-soft);line-height:1.6;"><?= nl2br(e($item['description'])) ?></p>
        <div class="status-banner">Seller: <?= e($item['first_name'] . ' ' . $item['last_name']) ?> <span class="pill ok" style="margin-left:6px;">ID verified</span></div>
        <a class="btn" href="auth/login.php?redirect_product=<?= (int) $item['id'] ?>">💬 Message seller</a>
        <div style="margin-top:12px;font-size:12px;color:var(--ink-soft);">🛡️ Log in to chat. Meet in public places &amp; confirm in-app.</div>
      </div>
    <?php endif; ?>

<?php endif; ?>

  </main>
</div>

<script>
// Keep each card's dot indicators in sync with its photo strip (same as dashboard).
document.querySelectorAll('.photo-strip').forEach(function (strip) {
  var wrap = strip.closest('.p-media, .strip-hero');
  var dotsWrap = wrap ? wrap.querySelector('.dots') : null;
  if (!dotsWrap) return;
  var dots = dotsWrap.querySelectorAll('.dot');
  var ticking = false;
  strip.addEventListener('scroll', function () {
    if (ticking) return;
    ticking = true;
    requestAnimationFrame(function () {
      var idx = Math.round(strip.scrollLeft / strip.clientWidth);
      dots.forEach(function (d, i) { d.classList.toggle('active', i === idx); });
      ticking = false;
    });
  }, { passive: true });
});
</script>
</body>
</html>
