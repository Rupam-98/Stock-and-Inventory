<?php
session_start();

// ── Auth guard ───────────────────────────────────────────────────────────────
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php'); exit;
}

// ── DB ────────────────────────────────────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_PORT', '5432');
define('DB_NAME', 'six_sems');
define('DB_USER', 'postgres');
define('DB_PASS', '1035');

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "pgsql:host=".DB_HOST.";port=".DB_PORT.";dbname=".DB_NAME;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

$isSuperAdmin = ($_SESSION['admin_role'] ?? '') === 'super_admin';
$flash = ''; $flashType = 'success';

// ── Logout ────────────────────────────────────────────────────────────────────
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: login.php'); exit;
}

// ── POST Actions ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $db = getDB();

    // Mark a dept-approved request as purchase_ordered or fulfilled
    if ($action === 'process_purchase') {
        $id     = (int)$_POST['request_id'];
        $status = $_POST['status'];
        $note   = trim($_POST['admin_note'] ?? '');
        $allowed = ['dept_approved','purchase_ordered','fulfilled'];
        if (in_array($status, $allowed)) {
            $db->prepare("UPDATE department_requests SET status=?, dept_admin_note=COALESCE(NULLIF(?,'')||' | Admin: '||?, dept_admin_note), updated_at=NOW() WHERE id=?")
               ->execute([$status, $note, $note, $id]);
            $flash = 'Purchase status updated.';
        }
    }

    // Add a new department (super admin only)
    if ($action === 'add_department' && $isSuperAdmin) {
        try {
            $hash = password_hash(trim($_POST['dept_password']), PASSWORD_DEFAULT);
            $db->prepare("INSERT INTO departments (name,email,mobile_no,username,password) VALUES (?,?,?,?,?)")
               ->execute([
                   trim($_POST['dept_name']), trim($_POST['dept_email']),
                   trim($_POST['dept_mobile']), trim($_POST['dept_username']), $hash,
               ]);
            $flash = 'Department added successfully.';
        } catch (Exception $e) {
            $flash = 'Error: '.$e->getMessage(); $flashType = 'error';
        }
    }

    // Delete department (super admin only)
    if ($action === 'delete_department' && $isSuperAdmin) {
        $db->prepare("DELETE FROM departments WHERE id=?")->execute([(int)$_POST['dept_id']]);
        $flash = 'Department removed.';
    }

    // Add admin (super admin only)
    if ($action === 'add_admin' && $isSuperAdmin) {
        try {
            $hash = password_hash(trim($_POST['admin_password']), PASSWORD_DEFAULT);
            $db->prepare("INSERT INTO admins (name,username,email,password,role) VALUES (?,?,?,?,?)")
               ->execute([
                   trim($_POST['admin_name']), trim($_POST['admin_username']),
                   trim($_POST['admin_email']), $hash, $_POST['admin_role'],
               ]);
            $flash = 'Admin user added.';
        } catch (Exception $e) {
            $flash = 'Error: '.$e->getMessage(); $flashType = 'error';
        }
    }

    // Delete admin (super admin only, cannot delete self)
    if ($action === 'delete_admin' && $isSuperAdmin) {
        $id = (int)$_POST['admin_id'];
        if ($id !== (int)$_SESSION['admin_id']) {
            $db->prepare("DELETE FROM admins WHERE id=?")->execute([$id]);
            $flash = 'Admin removed.';
        } else {
            $flash = 'You cannot delete your own account.'; $flashType = 'error';
        }
    }
}

// ── Data fetch ────────────────────────────────────────────────────────────────
$db = getDB();

$dept_requests = $db->query("
    SELECT dr.*, s.name AS student_name, s.student_id AS s_id, s.department AS s_dept
    FROM department_requests dr
    JOIN students s ON s.id = dr.student_id
    ORDER BY dr.requested_at DESC
")->fetchAll();

$departments = $db->query("SELECT * FROM departments ORDER BY created_at DESC")->fetchAll();
$admins      = $db->query("SELECT * FROM admins ORDER BY created_at DESC")->fetchAll();
$students    = $db->query("SELECT * FROM students ORDER BY created_at DESC")->fetchAll();
$items       = $db->query("SELECT * FROM items ORDER BY name")->fetchAll();

// Stats
$totalReqs      = count($dept_requests);
$pendingReqs    = count(array_filter($dept_requests, fn($r)=>$r['status']==='pending'));
$approvedReqs   = count(array_filter($dept_requests, fn($r)=>$r['status']==='dept_approved'));
$orderedReqs    = count(array_filter($dept_requests, fn($r)=>$r['status']==='purchase_ordered'));
$fulfilledReqs  = count(array_filter($dept_requests, fn($r)=>$r['status']==='fulfilled'));

function typeLabel(string $t): string {
    return match($t) {
        'missing'            => '🔍 Missing',
        'lost'               => '❌ Lost',
        'consumable_restock' => '🔄 Restock',
        'new_requirement'    => '✨ New Req.',
        default              => ucfirst($t),
    };
}
function statusLabel(string $s): string {
    return match($s) {
        'pending'          => 'Pending',
        'dept_approved'    => 'Dept Approved',
        'dept_rejected'    => 'Rejected',
        'purchase_ordered' => 'Ordered',
        'fulfilled'        => 'Fulfilled',
        default            => ucfirst(str_replace('_',' ',$s)),
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= $isSuperAdmin ? 'Super Admin' : 'Admin' ?> Dashboard — NLUPortal</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#f0ede8; --surface:#fff; --ink:#0d0d12; --mid:#6b6b78;
  --light:#9898a6; --border:#e2dfd9; --border2:#d0ccc4;
  --accent:#e84c1e; --green:#1db97a; --amber:#f59e0b;
  --red:#e84c1e; --blue:#3b82f6; --purple:#7c3aed;
  --sidebar-w:252px;
}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--ink);min-height:100vh;display:flex}

/* ── SIDEBAR ── */
.sidebar{width:var(--sidebar-w);background:var(--ink);min-height:100vh;position:fixed;top:0;left:0;
  display:flex;flex-direction:column;z-index:100;padding:0 0 24px}
.sb-brand{padding:26px 22px 22px;border-bottom:1px solid #ffffff12;margin-bottom:10px}
.sb-brand .b-icon{width:40px;height:40px;background:linear-gradient(135deg,var(--accent),#c0390e);
  border-radius:10px;display:grid;place-items:center;margin-bottom:12px;font-size:1.2rem}
.sb-brand h2{font-family:'Syne',sans-serif;font-size:.95rem;font-weight:700;color:#fff;letter-spacing:.02em}
.sb-brand p{font-size:.72rem;color:#4b4b58;margin-top:2px}
.sb-role{display:inline-flex;align-items:center;gap:6px;margin-top:8px;
  padding:3px 10px;border-radius:20px;font-size:.68rem;font-weight:600;letter-spacing:.06em;text-transform:uppercase}
.role-super{background:rgba(232,76,30,.2);color:#ff7a55}
.role-admin{background:rgba(59,130,246,.15);color:#60a5fa}

.sb-nav{flex:1;padding:0 10px}
.nav-sec{font-size:.65rem;font-weight:600;letter-spacing:.1em;text-transform:uppercase;
  color:#3a3a48;padding:14px 12px 6px}
.ni{display:flex;align-items:center;gap:10px;padding:10px 13px;border-radius:9px;cursor:pointer;
  color:#7878a0;font-size:.86rem;font-weight:400;transition:all .15s;border:none;background:none;
  width:100%;text-align:left;margin-bottom:2px;text-decoration:none}
.ni svg{width:16px;height:16px;stroke:currentColor;fill:none;flex-shrink:0}
.ni:hover{background:#ffffff0d;color:#ccc}
.ni.active{background:var(--accent);color:#fff}
.nbadge{margin-left:auto;background:rgba(255,255,255,.2);color:#fff;font-size:.63rem;
  font-weight:700;padding:2px 6px;border-radius:20px}
.ni.active .nbadge{background:rgba(255,255,255,.25)}

.sb-foot{padding:14px 22px 0;border-top:1px solid #ffffff0f}
.admin-pill{font-size:.77rem;color:#5a5a68;margin-bottom:10px;line-height:1.6}
.admin-pill strong{color:#bbb;display:block}
.btn-logout{display:flex;align-items:center;gap:8px;padding:9px 14px;background:#1a1a22;
  border:1px solid #2a2a34;border-radius:8px;color:#7878a0;font-size:.82rem;cursor:pointer;
  text-decoration:none;transition:all .15s;font-family:'DM Sans',sans-serif}
.btn-logout:hover{background:rgba(232,76,30,.12);color:var(--accent);border-color:var(--accent)}
.btn-logout svg{width:14px;height:14px;stroke:currentColor;fill:none}

/* ── MAIN ── */
.main{margin-left:var(--sidebar-w);flex:1;min-height:100vh;display:flex;flex-direction:column}
.topbar{background:var(--surface);border-bottom:1px solid var(--border);padding:16px 30px;
  display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50}
.topbar h1{font-family:'Syne',sans-serif;font-size:1.15rem;font-weight:700}
.tb-right{display:flex;align-items:center;gap:14px}
.avatar{width:36px;height:36px;background:var(--accent);border-radius:50%;
  display:grid;place-items:center;font-family:'Syne',sans-serif;font-size:.8rem;font-weight:700;color:#fff}
.content{padding:30px;flex:1}

/* ── TABS ── */
.tab-section{display:none}
.tab-section.active{display:block;animation:fi .3s ease}
@keyframes fi{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}

/* ── STATS ── */
.sg{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:28px}
@media(max-width:1100px){.sg{grid-template-columns:repeat(3,1fr)}}
.sc{background:var(--surface);border:1px solid var(--border);border-radius:14px;
  padding:20px 22px;position:relative;overflow:hidden}
.sc::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:var(--cc,var(--accent))}
.sc .label{font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--mid);margin-bottom:8px}
.sc .value{font-family:'Syne',sans-serif;font-size:1.9rem;font-weight:800;line-height:1}
.sc .sub{font-size:.75rem;color:var(--light);margin-top:4px}

/* ── CARDS / TABLES ── */
.card{background:var(--surface);border:1px solid var(--border);border-radius:14px;overflow:hidden;margin-bottom:22px}
.ch{padding:16px 22px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.ct{font-family:'Syne',sans-serif;font-size:.93rem;font-weight:700}
.tw{overflow-x:auto}
table{width:100%;border-collapse:collapse}
th{font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:var(--mid);
  padding:12px 18px;text-align:left;background:#fafaf9;border-bottom:1px solid var(--border)}
td{padding:12px 18px;font-size:.86rem;border-bottom:1px solid #f5f3ef;vertical-align:middle}
tr:last-child td{border-bottom:none}
tr:hover td{background:#fafaf9}
.empty-state{text-align:center;padding:44px 20px;color:var(--mid);font-size:.9rem}
.empty-state svg{width:38px;height:38px;stroke:var(--border);fill:none;margin-bottom:10px;display:block;margin-inline:auto}

/* ── BUTTONS ── */
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 17px;border-radius:9px;
  font-family:'DM Sans',sans-serif;font-size:.84rem;font-weight:500;cursor:pointer;border:none;transition:all .15s}
.btn-primary{background:var(--ink);color:#fff}
.btn-primary:hover{background:var(--accent)}
.btn-sm{padding:5px 11px;font-size:.76rem;border-radius:7px}
.btn-danger{background:#fff0ee;color:var(--red);border:1px solid #fbbcad}
.btn-danger:hover{background:var(--red);color:#fff}
.btn-success{background:#e8f8f1;color:var(--green);border:1px solid #a8e6cb}
.btn-success:hover{background:var(--green);color:#fff}
.btn-info{background:#eff6ff;color:var(--blue);border:1px solid #bfdbfe}
.btn-info:hover{background:var(--blue);color:#fff}
.btn-purple{background:#f3e8ff;color:var(--purple);border:1px solid #d8b4fe}
.btn-purple:hover{background:var(--purple);color:#fff}

/* ── BADGES ── */
.badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:.7rem;font-weight:600;letter-spacing:.03em}
.badge-pending          {background:#fef3e2;color:#d97706}
.badge-dept_approved    {background:#e8f8f1;color:#16a34a}
.badge-dept_rejected    {background:#fff0ee;color:#dc2626}
.badge-purchase_ordered {background:#eff6ff;color:#2563eb}
.badge-fulfilled        {background:#d1fae5;color:#065f46}
.badge-type-missing            {background:#fef3e2;color:#d97706}
.badge-type-lost               {background:#fff0ee;color:#dc2626}
.badge-type-consumable_restock {background:#eff6ff;color:#2563eb}
.badge-type-new_requirement    {background:#f3e8ff;color:#7c3aed}
.badge-urgency-low    {background:#f0fdf4;color:#16a34a}
.badge-urgency-normal {background:#fef3e2;color:#d97706}
.badge-urgency-high   {background:#fff7ed;color:#ea580c}
.badge-urgency-urgent {background:#fff0ee;color:#dc2626;border:1px solid #fbbcad}
.badge-super_admin{background:rgba(232,76,30,.1);color:var(--red)}
.badge-admin      {background:#eff6ff;color:var(--blue)}

/* ── FLASH ── */
.flash{padding:12px 18px;border-radius:10px;font-size:.87rem;margin-bottom:20px;display:flex;align-items:center;gap:9px}
.flash.success{background:#e8f8f1;color:#16a34a;border:1px solid #a8e6cb}
.flash.error  {background:#fff0ee;color:#dc2626;border:1px solid #fbbcad}

/* ── MODAL ── */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:200;
  display:none;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal{background:var(--surface);border-radius:16px;padding:30px;width:460px;
  max-width:95vw;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.22)}
.modal h3{font-family:'Syne',sans-serif;font-size:1.05rem;font-weight:700;margin-bottom:22px}
.fg{margin-bottom:16px}
.fg label{display:block;font-size:.73rem;font-weight:600;text-transform:uppercase;
  letter-spacing:.07em;color:var(--mid);margin-bottom:6px}
.fg input,.fg select,.fg textarea{width:100%;padding:10px 13px;border:1.5px solid var(--border);
  border-radius:9px;font-family:'DM Sans',sans-serif;font-size:.9rem;background:var(--bg);
  color:var(--ink);outline:none;transition:border-color .2s}
.fg input:focus,.fg select:focus,.fg textarea:focus{border-color:var(--accent);background:#fff}
.fg textarea{resize:vertical;min-height:72px}
.frow{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.modal-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:20px;
  padding-top:18px;border-top:1px solid var(--border)}

/* ── PURCHASE FORM inline ── */
.pur-form{display:flex;flex-direction:column;gap:5px;min-width:190px}
.pur-form .row{display:flex;gap:6px;align-items:center}
.pur-form select,.pur-form input[type=text]{padding:5px 8px;border:1.5px solid var(--border);
  border-radius:7px;font-size:.78rem;background:var(--bg);color:var(--ink);outline:none}
.pur-form select{flex:1;cursor:pointer}
.pur-form input[type=text]{width:100%;font-size:.76rem}
</style>
</head>
<body>

<!-- ════════ SIDEBAR ════════ -->
<div class="sidebar">
  <div class="sb-brand">
    <div class="b-icon">🛡️</div>
    <h2>NLUPORTAL</h2>
    <p>Administrative Panel</p>
    <span class="sb-role <?= $isSuperAdmin ? 'role-super' : 'role-admin' ?>">
      <?= $isSuperAdmin ? '⭐ Super Admin' : '🔵 Admin' ?>
    </span>
  </div>

  <nav class="sb-nav">
    <div class="nav-sec">Overview</div>
    <button class="ni active" onclick="showTab('dashboard',this)">
      <svg viewBox="0 0 24 24" stroke-width="1.8"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
      Dashboard
    </button>

    <div class="nav-sec">Requests</div>
    <button class="ni" onclick="showTab('purchases',this)">
      <svg viewBox="0 0 24 24" stroke-width="1.8"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
      Purchase Queue
      <?php if ($approvedReqs > 0): ?>
        <span class="nbadge"><?= $approvedReqs ?></span>
      <?php endif; ?>
    </button>
    <button class="ni" onclick="showTab('all-requests',this)">
      <svg viewBox="0 0 24 24" stroke-width="1.8"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2z"/></svg>
      All Reports
      <?php if ($pendingReqs > 0): ?>
        <span class="nbadge"><?= $pendingReqs ?></span>
      <?php endif; ?>
    </button>

    <div class="nav-sec">Management</div>
    <button class="ni" onclick="showTab('departments',this)">
      <svg viewBox="0 0 24 24" stroke-width="1.8"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
      Departments
    </button>
    <button class="ni" onclick="showTab('students',this)">
      <svg viewBox="0 0 24 24" stroke-width="1.8"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      Students
    </button>
    <button class="ni" onclick="showTab('inventory',this)">
      <svg viewBox="0 0 24 24" stroke-width="1.8"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
      Inventory
    </button>
    <?php if ($isSuperAdmin): ?>
    <div class="nav-sec">Super Admin</div>
    <button class="ni" onclick="showTab('admins',this)">
      <svg viewBox="0 0 24 24" stroke-width="1.8"><circle cx="12" cy="8" r="4"/><path d="M12 14c-5 0-8 2-8 3v1h16v-1c0-1-3-3-8-3z"/><path d="M18 8h4m-2-2v4"/></svg>
      Admin Users
    </button>
    <?php endif; ?>
  </nav>

  <div class="sb-foot">
    <div class="admin-pill">
      <strong><?= htmlspecialchars($_SESSION['admin_name']) ?></strong>
      @<?= htmlspecialchars($_SESSION['admin_user']) ?>
    </div>
    <a href="?logout=1" class="btn-logout">
      <svg viewBox="0 0 24 24" stroke-width="1.8"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      Sign out
    </a>
  </div>
</div>

<!-- ════════ MAIN ════════ -->
<div class="main">
  <div class="topbar">
    <h1 id="page-title">Dashboard</h1>
    <div class="tb-right">
      <span style="font-size:.8rem;color:var(--mid)"><?= date('D, d M Y') ?></span>
      <div class="avatar"><?= strtoupper(substr($_SESSION['admin_name'],0,2)) ?></div>
    </div>
  </div>

  <div class="content">

    <?php if ($flash): ?>
      <div class="flash <?= $flashType==='error'?'error':'success' ?>">
        <?= $flashType==='error'?'⚠':'✓' ?> <?= htmlspecialchars($flash) ?>
      </div>
    <?php endif; ?>

    <!-- ══ DASHBOARD TAB ══ -->
    <section id="tab-dashboard" class="tab-section active">
      <div class="sg">
        <div class="sc" style="--cc:#3b82f6"><div class="label">Total Reports</div><div class="value"><?= $totalReqs ?></div><div class="sub">all time</div></div>
        <div class="sc" style="--cc:#f59e0b"><div class="label">Pending</div><div class="value"><?= $pendingReqs ?></div><div class="sub">awaiting dept review</div></div>
        <div class="sc" style="--cc:#1db97a"><div class="label">Dept Approved</div><div class="value"><?= $approvedReqs ?></div><div class="sub">ready to order</div></div>
        <div class="sc" style="--cc:#e84c1e"><div class="label">Ordered</div><div class="value"><?= $orderedReqs ?></div><div class="sub">purchase in progress</div></div>
        <div class="sc" style="--cc:#7c3aed"><div class="label">Fulfilled</div><div class="value"><?= $fulfilledReqs ?></div><div class="sub">completed</div></div>
      </div>

      <!-- Quick purchase queue highlight -->
      <?php $toOrder = array_filter($dept_requests, fn($r)=>$r['status']==='dept_approved'); ?>
      <?php if ($toOrder): ?>
      <div class="card">
        <div class="ch">
          <span class="ct">🛒 Purchase Queue — <?= count($toOrder) ?> item<?= count($toOrder)!==1?'s':'' ?> approved and awaiting purchase</span>
          <button class="btn btn-sm btn-primary" onclick="showTab('purchases',document.querySelector('.ni[onclick*=purchases]'))">View All →</button>
        </div>
        <div class="tw">
          <table>
            <thead><tr><th>Department</th><th>Type</th><th>Item</th><th>Qty</th><th>Urgency</th><th>Reported</th></tr></thead>
            <tbody>
              <?php foreach(array_slice($toOrder,0,5) as $r): ?>
              <tr>
                <td><?= htmlspecialchars($r['department']) ?></td>
                <td><span class="badge badge-type-<?=$r['request_type']?>"><?=typeLabel($r['request_type'])?></span></td>
                <td style="font-weight:500"><?= htmlspecialchars($r['item_name']) ?></td>
                <td><?= $r['quantity_needed'] ?></td>
                <td><span class="badge badge-urgency-<?=$r['urgency']?>"><?=ucfirst($r['urgency'])?></span></td>
                <td style="color:var(--mid);font-size:.8rem"><?= date('d M Y', strtotime($r['requested_at'])) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

      <!-- Summary by department -->
      <div class="card">
        <div class="ch"><span class="ct">📊 Requests by Department</span></div>
        <div class="tw">
          <?php
            $byDept = [];
            foreach ($dept_requests as $r) {
                $d = $r['department'];
                if (!isset($byDept[$d])) $byDept[$d] = ['total'=>0,'pending'=>0,'approved'=>0,'ordered'=>0,'fulfilled'=>0];
                $byDept[$d]['total']++;
                $byDept[$d][$r['status'] === 'dept_approved' ? 'approved' : ($r['status'] === 'purchase_ordered' ? 'ordered' : ($r['status'] === 'fulfilled' ? 'fulfilled' : 'pending'))]++;
            }
          ?>
          <?php if ($byDept): ?>
          <table>
            <thead><tr><th>Department</th><th>Total</th><th>Pending</th><th>Approved</th><th>Ordered</th><th>Fulfilled</th></tr></thead>
            <tbody>
              <?php foreach ($byDept as $dname => $counts): ?>
              <tr>
                <td style="font-weight:500"><?= htmlspecialchars($dname) ?></td>
                <td><?= $counts['total'] ?></td>
                <td><?= $counts['pending'] ? '<span class="badge badge-pending">'.$counts['pending'].'</span>' : '—' ?></td>
                <td><?= $counts['approved'] ? '<span class="badge badge-dept_approved">'.$counts['approved'].'</span>' : '—' ?></td>
                <td><?= $counts['ordered'] ? '<span class="badge badge-purchase_ordered">'.$counts['ordered'].'</span>' : '—' ?></td>
                <td><?= $counts['fulfilled'] ? '<span class="badge badge-fulfilled">'.$counts['fulfilled'].'</span>' : '—' ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php else: ?>
          <div class="empty-state"><p>No reports yet across any department.</p></div>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <!-- ══ PURCHASE QUEUE TAB ══ -->
    <section id="tab-purchases" class="tab-section">
      <div class="ch" style="background:var(--surface);border:1px solid var(--border);border-radius:14px 14px 0 0;padding:16px 22px;margin-bottom:0">
        <span style="font-family:'Syne',sans-serif;font-weight:700;font-size:.95rem">🛒 Purchase Queue</span>
        <span style="font-size:.8rem;color:var(--mid)">Dept-approved requests ready to be ordered</span>
      </div>
      <div class="card" style="border-radius:0 0 14px 14px;border-top:none">
        <div class="tw">
          <?php $purchasable = array_filter($dept_requests, fn($r)=>in_array($r['status'],['dept_approved','purchase_ordered'])); ?>
          <?php if (empty($purchasable)): ?>
            <div class="empty-state">
              <svg viewBox="0 0 24 24"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/></svg>
              <p>No approved requests pending purchase.</p>
            </div>
          <?php else: ?>
          <table>
            <thead>
              <tr><th>Dept</th><th>Type</th><th>Item</th><th>Qty</th><th>Urgency</th><th>Description</th><th>Reported</th><th>Process</th></tr>
            </thead>
            <tbody>
              <?php foreach ($purchasable as $r): ?>
              <tr>
                <td><?= htmlspecialchars($r['department']) ?><br><span style="font-size:.73rem;color:var(--light)"><?= htmlspecialchars($r['student_name']) ?></span></td>
                <td><span class="badge badge-type-<?=$r['request_type']?>"><?=typeLabel($r['request_type'])?></span></td>
                <td style="font-weight:500"><?= htmlspecialchars($r['item_name']) ?><?php if($r['item_category']): ?><br><span style="font-size:.72rem;color:var(--light)"><?=htmlspecialchars($r['item_category'])?></span><?php endif; ?></td>
                <td><?= $r['quantity_needed'] ?></td>
                <td><span class="badge badge-urgency-<?=$r['urgency']?>"><?=ucfirst($r['urgency'])?></span></td>
                <td style="font-size:.79rem;color:var(--mid);max-width:160px"><?=htmlspecialchars(mb_substr($r['description'],0,70))?><?=mb_strlen($r['description'])>70?'…':''?></td>
                <td style="font-size:.79rem;color:var(--mid);white-space:nowrap"><?= date('d M Y', strtotime($r['requested_at'])) ?></td>
                <td>
                  <form method="POST" class="pur-form">
                    <input type="hidden" name="action" value="process_purchase">
                    <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                    <div class="row">
                      <select name="status">
                        <option value="dept_approved"    <?=$r['status']==='dept_approved'    ?'selected':''?>>Approved</option>
                        <option value="purchase_ordered" <?=$r['status']==='purchase_ordered' ?'selected':''?>>Mark Ordered</option>
                        <option value="fulfilled"        <?=$r['status']==='fulfilled'        ?'selected':''?>>Mark Fulfilled</option>
                      </select>
                      <button class="btn btn-sm btn-success" type="submit">Save</button>
                    </div>
                    <input type="text" name="admin_note" placeholder="Admin note (optional)" value="<?=htmlspecialchars($r['dept_admin_note']??'')?>">
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <!-- ══ ALL REPORTS TAB ══ -->
    <section id="tab-all-requests" class="tab-section">
      <div class="card">
        <div class="ch">
          <span class="ct">📋 All Department Reports</span>
          <span style="font-size:.8rem;color:var(--mid)"><?= count($dept_requests) ?> total</span>
        </div>
        <div class="tw">
          <?php if (empty($dept_requests)): ?>
            <div class="empty-state"><p>No reports submitted yet.</p></div>
          <?php else: ?>
          <table>
            <thead><tr><th>Student</th><th>Dept</th><th>Type</th><th>Item</th><th>Qty</th><th>Urgency</th><th>Description</th><th>Reported</th><th>Status</th><th>Admin Note</th></tr></thead>
            <tbody>
              <?php foreach ($dept_requests as $r): ?>
              <tr>
                <td><?=htmlspecialchars($r['student_name'])?><br><span style="font-size:.72rem;color:var(--light)"><?=htmlspecialchars($r['s_id'])?></span></td>
                <td style="font-size:.83rem"><?=htmlspecialchars($r['department'])?></td>
                <td><span class="badge badge-type-<?=$r['request_type']?>"><?=typeLabel($r['request_type'])?></span></td>
                <td style="font-weight:500"><?=htmlspecialchars($r['item_name'])?></td>
                <td><?=$r['quantity_needed']?></td>
                <td><span class="badge badge-urgency-<?=$r['urgency']?>"><?=ucfirst($r['urgency'])?></span></td>
                <td style="font-size:.79rem;color:var(--mid);max-width:150px"><?=htmlspecialchars(mb_substr($r['description'],0,60))?><?=mb_strlen($r['description'])>60?'…':''?></td>
                <td style="font-size:.78rem;color:var(--mid);white-space:nowrap"><?=date('d M Y',strtotime($r['requested_at']))?></td>
                <td><span class="badge badge-<?=$r['status']?>"><?=statusLabel($r['status'])?></span></td>
                <td style="font-size:.78rem;color:var(--mid)"><?=htmlspecialchars($r['dept_admin_note']??'—')?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <!-- ══ DEPARTMENTS TAB ══ -->
    <section id="tab-departments" class="tab-section">
      <div class="card">
        <div class="ch">
          <span class="ct">🏢 Departments</span>
          <?php if ($isSuperAdmin): ?>
            <button class="btn btn-primary btn-sm" onclick="openModal('modal-add-dept')">+ Add Department</button>
          <?php endif; ?>
        </div>
        <div class="tw">
          <?php if (empty($departments)): ?>
            <div class="empty-state"><p>No departments registered yet.</p></div>
          <?php else: ?>
          <table>
            <thead><tr><th>#</th><th>Name</th><th>Username</th><th>Email</th><th>Mobile</th><th>Created</th><?php if($isSuperAdmin):?><th>Action</th><?php endif;?></tr></thead>
            <tbody>
              <?php foreach ($departments as $d): ?>
              <tr>
                <td style="color:var(--light)"><?=$d['id']?></td>
                <td style="font-weight:500"><?=htmlspecialchars($d['name'])?></td>
                <td><code style="background:#f0ede8;padding:2px 7px;border-radius:5px;font-size:.79rem"><?=htmlspecialchars($d['username'])?></code></td>
                <td style="color:var(--mid)"><?=htmlspecialchars($d['email'])?></td>
                <td style="color:var(--mid)"><?=htmlspecialchars($d['mobile_no']??'—')?></td>
                <td style="color:var(--mid);font-size:.8rem"><?=date('d M Y',strtotime($d['created_at']))?></td>
                <?php if ($isSuperAdmin): ?>
                <td>
                  <form method="POST" onsubmit="return confirm('Delete this department?')">
                    <input type="hidden" name="action" value="delete_department">
                    <input type="hidden" name="dept_id" value="<?=$d['id']?>">
                    <button class="btn btn-sm btn-danger" type="submit">Delete</button>
                  </form>
                </td>
                <?php endif; ?>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <!-- ══ STUDENTS TAB ══ -->
    <section id="tab-students" class="tab-section">
      <div class="card">
        <div class="ch"><span class="ct">🎓 All Students</span><span style="font-size:.8rem;color:var(--mid)"><?=count($students)?> registered</span></div>
        <div class="tw">
          <?php if (empty($students)): ?>
            <div class="empty-state"><p>No students registered yet.</p></div>
          <?php else: ?>
          <table>
            <thead><tr><th>#</th><th>Name</th><th>Student ID</th><th>Email</th><th>Department</th><th>Joined</th></tr></thead>
            <tbody>
              <?php foreach ($students as $s): ?>
              <tr>
                <td style="color:var(--light)"><?=$s['id']?></td>
                <td style="font-weight:500"><?=htmlspecialchars($s['name'])?></td>
                <td><code style="background:#f0ede8;padding:2px 7px;border-radius:5px;font-size:.79rem"><?=htmlspecialchars($s['student_id'])?></code></td>
                <td style="color:var(--mid)"><?=htmlspecialchars($s['email'])?></td>
                <td><?=htmlspecialchars($s['department']??'—')?></td>
                <td style="color:var(--mid);font-size:.8rem"><?=date('d M Y',strtotime($s['created_at']))?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <!-- ══ INVENTORY TAB ══ -->
    <section id="tab-inventory" class="tab-section">
      <div class="card">
        <div class="ch"><span class="ct">📦 Inventory Items</span><span style="font-size:.8rem;color:var(--mid)"><?=count($items)?> items</span></div>
        <div class="tw">
          <?php if (empty($items)): ?>
            <div class="empty-state"><p>No items in inventory.</p></div>
          <?php else: ?>
          <table>
            <thead><tr><th>#</th><th>Name</th><th>Category</th><th>Quantity</th><th>Description</th><th>Added</th></tr></thead>
            <tbody>
              <?php foreach ($items as $i): ?>
              <tr>
                <td style="color:var(--light)"><?=$i['id']?></td>
                <td style="font-weight:500"><?=htmlspecialchars($i['name'])?></td>
                <td><span class="badge" style="background:#f0ede8;color:var(--mid)"><?=htmlspecialchars($i['category']?:'—')?></span></td>
                <td><?= $i['quantity'] > 0 ? $i['quantity'] : '<span style="color:#dc2626">0</span>' ?></td>
                <td style="color:var(--mid);font-size:.81rem"><?=htmlspecialchars(mb_substr($i['description']??'',0,60))?></td>
                <td style="color:var(--mid);font-size:.8rem"><?=date('d M Y',strtotime($i['created_at']))?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <!-- ══ ADMIN USERS TAB (super admin only) ══ -->
    <?php if ($isSuperAdmin): ?>
    <section id="tab-admins" class="tab-section">
      <div class="card">
        <div class="ch">
          <span class="ct">🛡️ Admin Users</span>
          <button class="btn btn-primary btn-sm" onclick="openModal('modal-add-admin')">+ Add Admin</button>
        </div>
        <div class="tw">
          <table>
            <thead><tr><th>#</th><th>Name</th><th>Username</th><th>Email</th><th>Role</th><th>Created</th><th>Action</th></tr></thead>
            <tbody>
              <?php foreach ($admins as $a): ?>
              <tr>
                <td style="color:var(--light)"><?=$a['id']?></td>
                <td style="font-weight:500"><?=htmlspecialchars($a['name'])?></td>
                <td><code style="background:#f0ede8;padding:2px 7px;border-radius:5px;font-size:.79rem"><?=htmlspecialchars($a['username'])?></code></td>
                <td style="color:var(--mid)"><?=htmlspecialchars($a['email'])?></td>
                <td><span class="badge badge-<?=$a['role']?>"><?=$a['role']==='super_admin'?'⭐ Super Admin':'🔵 Admin'?></span></td>
                <td style="color:var(--mid);font-size:.8rem"><?=date('d M Y',strtotime($a['created_at']))?></td>
                <td>
                  <?php if ($a['id'] != $_SESSION['admin_id']): ?>
                  <form method="POST" onsubmit="return confirm('Remove this admin?')">
                    <input type="hidden" name="action" value="delete_admin">
                    <input type="hidden" name="admin_id" value="<?=$a['id']?>">
                    <button class="btn btn-sm btn-danger" type="submit">Remove</button>
                  </form>
                  <?php else: ?>
                    <span style="font-size:.76rem;color:var(--light)">You</span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </section>
    <?php endif; ?>

  </div><!-- /content -->
</div><!-- /main -->

<!-- ═════ MODALS ═════ -->

<!-- Add Department -->
<?php if ($isSuperAdmin): ?>
<div class="modal-overlay" id="modal-add-dept">
  <div class="modal">
    <h3>🏢 Add Department</h3>
    <form method="POST">
      <input type="hidden" name="action" value="add_department">
      <div class="frow">
        <div class="fg"><label>Dept Name *</label><input type="text" name="dept_name" placeholder="e.g. Computer Science" required></div>
        <div class="fg"><label>Username *</label><input type="text" name="dept_username" placeholder="e.g. cs_admin" required></div>
      </div>
      <div class="fg"><label>Email *</label><input type="email" name="dept_email" placeholder="dept@university.edu" required></div>
      <div class="frow">
        <div class="fg"><label>Mobile</label><input type="text" name="dept_mobile" placeholder="Optional"></div>
        <div class="fg"><label>Password *</label><input type="password" name="dept_password" placeholder="Set password" required></div>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn" onclick="closeModal('modal-add-dept')" style="background:var(--bg);border:1px solid var(--border)">Cancel</button>
        <button type="submit" class="btn btn-primary">Add Department</button>
      </div>
    </form>
  </div>
</div>

<!-- Add Admin -->
<div class="modal-overlay" id="modal-add-admin">
  <div class="modal">
    <h3>🛡️ Add Admin User</h3>
    <form method="POST">
      <input type="hidden" name="action" value="add_admin">
      <div class="frow">
        <div class="fg"><label>Full Name *</label><input type="text" name="admin_name" placeholder="Full name" required></div>
        <div class="fg"><label>Username *</label><input type="text" name="admin_username" placeholder="username" required></div>
      </div>
      <div class="fg"><label>Email *</label><input type="email" name="admin_email" placeholder="admin@university.edu" required></div>
      <div class="frow">
        <div class="fg">
          <label>Role *</label>
          <select name="admin_role">
            <option value="admin">🔵 Admin</option>
            <option value="super_admin">⭐ Super Admin</option>
          </select>
        </div>
        <div class="fg"><label>Password *</label><input type="password" name="admin_password" placeholder="Set password" required></div>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn" onclick="closeModal('modal-add-admin')" style="background:var(--bg);border:1px solid var(--border)">Cancel</button>
        <button type="submit" class="btn btn-primary">Add Admin</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
const titles = {
  dashboard: 'Dashboard', purchases: 'Purchase Queue',
  'all-requests': 'All Reports', departments: 'Departments',
  students: 'Students', inventory: 'Inventory', admins: 'Admin Users'
};

function showTab(name, btn) {
  document.querySelectorAll('.tab-section').forEach(s => s.classList.remove('active'));
  document.querySelectorAll('.ni').forEach(n => n.classList.remove('active'));
  document.getElementById('tab-' + name).classList.add('active');
  if (btn) btn.classList.add('active');
  document.getElementById('page-title').textContent = titles[name] || name;
}

function openModal(id)  { document.getElementById(id).classList.add('open');    document.body.style.overflow='hidden'; }
function closeModal(id) { document.getElementById(id).classList.remove('open'); document.body.style.overflow=''; }
document.querySelectorAll('.modal-overlay').forEach(o => {
  o.addEventListener('click', e => { if(e.target===o){ o.classList.remove('open'); document.body.style.overflow=''; } });
});

const fl = document.querySelector('.flash');
if (fl) setTimeout(()=>{ fl.style.transition='opacity .5s'; fl.style.opacity=0; }, 3500);
</script>
</body>
</html>
