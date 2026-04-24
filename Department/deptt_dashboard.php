<?php
session_start();

// ── Auth guard ──────────────────────────────────────────────────────────────
if (!isset($_SESSION['dept_id'])) {
    header('Location: dept_login.php');
    exit;
}

// ── DB ───────────────────────────────────────────────────────────────────────
$host = 'localhost'; $dbname = 'six_sems'; $dbuser = 'postgres'; $dbpass = '1035';
try {
    $pdo = new PDO("pgsql:host=$host;dbname=$dbname", $dbuser, $dbpass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Exception $e) {
    die('DB Error: ' . $e->getMessage());
}

// ── Ensure tables exist ───────────────────────────────────────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS students (
        id SERIAL PRIMARY KEY, name VARCHAR(100) NOT NULL,
        student_id VARCHAR(20) UNIQUE NOT NULL, email VARCHAR(100) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL, department VARCHAR(80), created_at TIMESTAMP DEFAULT NOW()
    );
    CREATE TABLE IF NOT EXISTS items (
        id SERIAL PRIMARY KEY, name VARCHAR(100) NOT NULL, category VARCHAR(60),
        quantity INTEGER DEFAULT 0, description TEXT, created_at TIMESTAMP DEFAULT NOW()
    );
    CREATE TABLE IF NOT EXISTS item_requests (
        id SERIAL PRIMARY KEY, student_id INTEGER REFERENCES students(id) ON DELETE CASCADE,
        item_id INTEGER REFERENCES items(id) ON DELETE CASCADE, quantity INTEGER DEFAULT 1,
        purpose TEXT, status VARCHAR(20) DEFAULT 'pending'
            CHECK (status IN ('pending','approved','rejected','returned')),
        requested_at TIMESTAMP DEFAULT NOW(), updated_at TIMESTAMP DEFAULT NOW()
    );
    CREATE TABLE IF NOT EXISTS repair_requests (
        id SERIAL PRIMARY KEY, student_id INTEGER REFERENCES students(id) ON DELETE CASCADE,
        item_name VARCHAR(100) NOT NULL, damage_desc TEXT NOT NULL,
        priority VARCHAR(20) DEFAULT 'normal' CHECK (priority IN ('low','normal','high','urgent')),
        status VARCHAR(20) DEFAULT 'pending'
            CHECK (status IN ('pending','in_progress','completed','rejected')),
        submitted_at TIMESTAMP DEFAULT NOW(), updated_at TIMESTAMP DEFAULT NOW()
    );
    CREATE TABLE IF NOT EXISTS departments (
        id SERIAL PRIMARY KEY, name VARCHAR(100) NOT NULL, email VARCHAR(100) UNIQUE NOT NULL,
        mobile_no VARCHAR(20), username VARCHAR(60) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL, created_at TIMESTAMP DEFAULT NOW()
    );
");

$flash = '';
$flashType = 'success';

// ── Logout ────────────────────────────────────────────────────────────────────
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: dept_login.php');
    exit;
}

// ── AJAX / POST actions ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ADD ITEM
    if ($action === 'add_item') {
        $name  = trim($_POST['item_name']);
        $qty   = (int)$_POST['quantity'];
        $cat   = trim($_POST['category']);
        $desc  = trim($_POST['description']);
        $pdo->prepare("INSERT INTO items (name,category,quantity,description) VALUES (?,?,?,?)")
            ->execute([$name, $cat, $qty, $desc]);
        $flash = 'Item added successfully.';
    }

    // UPDATE STOCK
    if ($action === 'update_stock') {
        $id  = (int)$_POST['item_id'];
        $qty = (int)$_POST['quantity'];
        $pdo->prepare("UPDATE items SET quantity=? WHERE id=?")->execute([$qty, $id]);
        $flash = 'Stock updated.';
    }

    // DELETE ITEM
    if ($action === 'delete_item') {
        $id = (int)$_POST['item_id'];
        $pdo->prepare("DELETE FROM items WHERE id=?")->execute([$id]);
        $flash = 'Item deleted.';
    }

    // UPDATE REQUEST STATUS
    if ($action === 'update_request') {
        $id      = (int)$_POST['request_id'];
        $status  = $_POST['status'];
        $remarks = trim($_POST['remarks'] ?? '');
        $allowed = ['pending','approved','rejected','returned'];
        if (in_array($status, $allowed)) {
            $pdo->prepare("UPDATE item_requests SET status=?, updated_at=NOW() WHERE id=?")
                ->execute([$status, $id]);
            $flash = 'Request status updated.';
        }
    }

    // ADD STUDENT
    if ($action === 'add_student') {
        $sname = trim($_POST['student_name']);
        $sid   = trim($_POST['student_id']);
        $semail= trim($_POST['student_email']);
        $sdept = trim($_POST['student_dept']);
        $spass = password_hash(trim($_POST['student_password']), PASSWORD_DEFAULT);
        try {
            $pdo->prepare("INSERT INTO students (name,student_id,email,department,password) VALUES (?,?,?,?,?)")
                ->execute([$sname, $sid, $semail, $sdept, $spass]);
            $flash = 'Student added successfully.';
        } catch (Exception $e) {
            $flash = 'Error: ' . $e->getMessage();
            $flashType = 'error';
        }
    }
}

// ── DATA FETCH ────────────────────────────────────────────────────────────────
$items    = $pdo->query("SELECT * FROM items ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$requests = $pdo->query("
    SELECT ir.*, s.name AS student_name, s.student_id AS s_id, i.name AS item_name
    FROM item_requests ir
    JOIN students s ON s.id = ir.student_id
    JOIN items    i ON i.id = ir.item_id
    ORDER BY ir.requested_at DESC
")->fetchAll(PDO::FETCH_ASSOC);
$students = $pdo->query("SELECT * FROM students ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

// Stats
$totalItems    = count($items);
$totalStudents = count($students);
$pendingReqs   = count(array_filter($requests, fn($r) => $r['status'] === 'pending'));
$approvedReqs  = count(array_filter($requests, fn($r) => $r['status'] === 'approved'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Department Dashboard — CampusSync</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:ital,wght@0,300;0,400;0,500;1,400&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --bg:      #f0ede8;
  --surface: #ffffff;
  --ink:     #0d0d12;
  --mid:     #6b6b78;
  --light:   #9898a6;
  --border:  #e2dfd9;
  --accent:  #e84c1e;
  --green:   #1db97a;
  --amber:   #f59e0b;
  --red:     #e84c1e;
  --blue:    #3b82f6;
  --sidebar-w: 240px;
}

body {
  font-family: 'DM Sans', sans-serif;
  background: var(--bg);
  color: var(--ink);
  min-height: 100vh;
  display: flex;
}

/* ── SIDEBAR ── */
.sidebar {
  width: var(--sidebar-w);
  background: var(--ink);
  min-height: 100vh;
  position: fixed;
  top: 0; left: 0;
  display: flex;
  flex-direction: column;
  z-index: 100;
  padding: 0 0 24px 0;
}

.sb-brand {
  padding: 28px 24px 24px;
  border-bottom: 1px solid #ffffff14;
  margin-bottom: 12px;
}

.sb-brand .b-icon {
  width: 38px; height: 38px;
  background: var(--accent);
  border-radius: 9px;
  display: grid; place-items: center;
  margin-bottom: 12px;
}

.sb-brand .b-icon svg { width: 20px; height: 20px; fill: #fff; }

.sb-brand h2 {
  font-family: 'Syne', sans-serif;
  font-size: .95rem;
  font-weight: 700;
  color: #fff;
  letter-spacing: .02em;
}

.sb-brand p {
  font-size: .75rem;
  color: #6b6b78;
  margin-top: 2px;
}

.sb-nav {
  flex: 1;
  padding: 0 12px;
}

.nav-section {
  font-size: .68rem;
  font-weight: 600;
  letter-spacing: .1em;
  text-transform: uppercase;
  color: #4b4b58;
  padding: 16px 12px 8px;
}

.nav-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 14px;
  border-radius: 9px;
  cursor: pointer;
  color: #8888a0;
  font-size: .88rem;
  font-weight: 400;
  transition: background .15s, color .15s;
  border: none;
  background: none;
  width: 100%;
  text-align: left;
  text-decoration: none;
  margin-bottom: 2px;
}

.nav-item svg { width: 17px; height: 17px; stroke: currentColor; fill: none; flex-shrink: 0; }

.nav-item:hover { background: #ffffff0d; color: #ccc; }
.nav-item.active { background: var(--accent); color: #fff; }
.nav-item.active svg { stroke: #fff; }

.sb-footer {
  padding: 16px 24px 0;
  border-top: 1px solid #ffffff10;
}

.dept-pill {
  font-size: .78rem;
  color: #6b6b78;
  margin-bottom: 12px;
  line-height: 1.5;
}

.dept-pill strong { color: #ccc; display: block; }

.btn-logout {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 9px 14px;
  background: #1a1a22;
  border: 1px solid #2a2a34;
  border-radius: 8px;
  color: #8888a0;
  font-size: .82rem;
  cursor: pointer;
  text-decoration: none;
  transition: background .15s, color .15s;
}

.btn-logout:hover { background: #e84c1e22; color: var(--accent); border-color: var(--accent); }
.btn-logout svg { width: 14px; height: 14px; stroke: currentColor; fill: none; }

/* ── MAIN ── */
.main {
  margin-left: var(--sidebar-w);
  flex: 1;
  min-height: 100vh;
  display: flex;
  flex-direction: column;
}

.topbar {
  background: var(--surface);
  border-bottom: 1px solid var(--border);
  padding: 18px 32px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  position: sticky;
  top: 0;
  z-index: 50;
}

.topbar h1 {
  font-family: 'Syne', sans-serif;
  font-size: 1.2rem;
  font-weight: 700;
}

.topbar .tb-right {
  display: flex;
  align-items: center;
  gap: 14px;
}

.avatar {
  width: 36px; height: 36px;
  background: var(--accent);
  border-radius: 50%;
  display: grid; place-items: center;
  font-family: 'Syne', sans-serif;
  font-size: .82rem;
  font-weight: 700;
  color: #fff;
}

.content {
  padding: 32px;
  flex: 1;
}

/* ── TABS ── */
.tab-section {
  display: none;
}

.tab-section.active {
  display: block;
  animation: fadeIn .3s ease;
}

@keyframes fadeIn {
  from { opacity: 0; transform: translateY(8px); }
  to   { opacity: 1; transform: translateY(0); }
}

/* ── STATS ── */
.stats-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 18px;
  margin-bottom: 30px;
}

.stat-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 14px;
  padding: 22px 24px;
  position: relative;
  overflow: hidden;
}

.stat-card::before {
  content: '';
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 3px;
  background: var(--accent-color, var(--accent));
}

.stat-card .label {
  font-size: .78rem;
  font-weight: 500;
  color: var(--mid);
  letter-spacing: .04em;
  text-transform: uppercase;
  margin-bottom: 10px;
}

.stat-card .value {
  font-family: 'Syne', sans-serif;
  font-size: 2rem;
  font-weight: 800;
  line-height: 1;
}

.stat-card .sub {
  font-size: .78rem;
  color: var(--light);
  margin-top: 4px;
}

/* ── SECTION HEADER ── */
.sec-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 20px;
}

.sec-header h2 {
  font-family: 'Syne', sans-serif;
  font-size: 1.1rem;
  font-weight: 700;
}

/* ── BUTTONS ── */
.btn {
  padding: 9px 18px;
  border-radius: 9px;
  font-family: 'DM Sans', sans-serif;
  font-size: .85rem;
  font-weight: 500;
  cursor: pointer;
  border: none;
  transition: all .15s;
}

.btn-primary {
  background: var(--ink);
  color: #fff;
}

.btn-primary:hover { background: var(--accent); }

.btn-sm { padding: 6px 12px; font-size: .78rem; border-radius: 7px; }

.btn-danger { background: #fff0ee; color: var(--red); border: 1px solid #fbbcad; }
.btn-danger:hover { background: var(--red); color: #fff; }

.btn-success { background: #e8f8f1; color: var(--green); border: 1px solid #a8e6cb; }
.btn-success:hover { background: var(--green); color: #fff; }

.btn-warn { background: #fef3e2; color: #d97706; border: 1px solid #fcd49a; }
.btn-warn:hover { background: var(--amber); color: #fff; }

.btn-info { background: #eff6ff; color: var(--blue); border: 1px solid #bfdbfe; }

/* ── TABLE ── */
.table-wrap {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 14px;
  overflow: hidden;
}

table {
  width: 100%;
  border-collapse: collapse;
}

th {
  font-size: .75rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: .07em;
  color: var(--mid);
  padding: 13px 18px;
  text-align: left;
  background: #fafaf9;
  border-bottom: 1px solid var(--border);
}

td {
  padding: 13px 18px;
  font-size: .87rem;
  border-bottom: 1px solid #f5f3ef;
  vertical-align: middle;
}

tr:last-child td { border-bottom: none; }
tr:hover td { background: #fafaf9; }

/* ── BADGE ── */
.badge {
  display: inline-block;
  padding: 3px 10px;
  border-radius: 20px;
  font-size: .72rem;
  font-weight: 600;
  letter-spacing: .04em;
}

.badge-pending  { background: #fef3e2; color: #d97706; }
.badge-approved { background: #e8f8f1; color: #16a34a; }
.badge-rejected { background: #fff0ee; color: #dc2626; }
.badge-returned { background: #eff6ff; color: #2563eb; }

/* ── MODAL / FORM ── */
.modal-overlay {
  position: fixed; inset: 0;
  background: rgba(0,0,0,.5);
  z-index: 200;
  display: none;
  align-items: center;
  justify-content: center;
}

.modal-overlay.open { display: flex; }

.modal {
  background: var(--surface);
  border-radius: 16px;
  padding: 32px;
  width: 480px;
  max-width: 95vw;
  max-height: 90vh;
  overflow-y: auto;
  box-shadow: 0 20px 60px rgba(0,0,0,.25);
}

.modal h3 {
  font-family: 'Syne', sans-serif;
  font-size: 1.1rem;
  font-weight: 700;
  margin-bottom: 24px;
}

.form-group {
  margin-bottom: 18px;
}

.form-group label {
  display: block;
  font-size: .78rem;
  font-weight: 500;
  letter-spacing: .05em;
  text-transform: uppercase;
  color: var(--mid);
  margin-bottom: 7px;
}

.form-group input,
.form-group textarea,
.form-group select {
  width: 100%;
  padding: 11px 14px;
  border: 1.5px solid var(--border);
  border-radius: 9px;
  font-family: 'DM Sans', sans-serif;
  font-size: .9rem;
  background: var(--bg);
  color: var(--ink);
  outline: none;
  transition: border-color .2s, box-shadow .2s;
}

.form-group input:focus,
.form-group textarea:focus,
.form-group select:focus {
  border-color: var(--accent);
  box-shadow: 0 0 0 3px rgba(232,76,30,.12);
  background: #fff;
}

.form-group textarea { resize: vertical; min-height: 80px; }

.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }

.modal-actions {
  display: flex;
  gap: 10px;
  justify-content: flex-end;
  margin-top: 24px;
  padding-top: 20px;
  border-top: 1px solid var(--border);
}

/* ── FLASH ── */
.flash {
  padding: 13px 18px;
  border-radius: 10px;
  font-size: .88rem;
  margin-bottom: 22px;
  display: flex;
  align-items: center;
  gap: 10px;
}

.flash.success { background: #e8f8f1; color: #16a34a; border: 1px solid #a8e6cb; }
.flash.error   { background: #fff0ee; color: #dc2626; border: 1px solid #fbbcad; }

/* ── INLINE EDIT ── */
.inline-form { display: flex; gap: 8px; align-items: center; }
.inline-form input { width: 80px; padding: 5px 9px; border: 1.5px solid var(--border); border-radius: 7px; font-size: .85rem; background: var(--bg); color: var(--ink); outline: none; }
.inline-form input:focus { border-color: var(--accent); }

.empty-state {
  text-align: center;
  padding: 48px 20px;
  color: var(--mid);
  font-size: .9rem;
}

.empty-state svg { width: 40px; height: 40px; stroke: var(--border); fill: none; margin-bottom: 12px; }
</style>
</head>
<body>

<!-- ═══════════════════════════ SIDEBAR ═══════════════════════════ -->
<div class="sidebar">
  <div class="sb-brand">
    <div class="b-icon">
      <svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22" fill="none" stroke="#fff" stroke-width="1.5"/></svg>
    </div>
    <h2>CampusSync</h2>
    <p>Department Portal</p>
  </div>

  <nav class="sb-nav">
    <div class="nav-section">Main</div>
    <button class="nav-item active" onclick="showTab('dashboard', this)">
      <svg viewBox="0 0 24 24" stroke-width="1.8"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
      Dashboard
    </button>

    <div class="nav-section">Inventory</div>
    <button class="nav-item" onclick="showTab('items', this)">
      <svg viewBox="0 0 24 24" stroke-width="1.8"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
      Item Management
    </button>

    <div class="nav-section">Requests</div>
    <button class="nav-item" onclick="showTab('requests', this)">
      <svg viewBox="0 0 24 24" stroke-width="1.8"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2z"/></svg>
      Item Requests
      <?php if ($pendingReqs > 0): ?>
        <span style="margin-left:auto;background:var(--accent);color:#fff;font-size:.7rem;font-weight:700;padding:2px 7px;border-radius:20px;"><?= $pendingReqs ?></span>
      <?php endif; ?>
    </button>

    <div class="nav-section">People</div>
    <button class="nav-item" onclick="showTab('students', this)">
      <svg viewBox="0 0 24 24" stroke-width="1.8"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      Students
    </button>
  </nav>

  <div class="sb-footer">
    <div class="dept-pill">
      <strong><?= htmlspecialchars($_SESSION['dept_name']) ?></strong>
      @<?= htmlspecialchars($_SESSION['dept_user']) ?>
    </div>
    <a href="?logout=1" class="btn-logout">
      <svg viewBox="0 0 24 24" stroke-width="1.8"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      Sign out
    </a>
  </div>
</div>

<!-- ═══════════════════════════ MAIN ═══════════════════════════════ -->
<div class="main">

  <!-- Topbar -->
  <div class="topbar">
    <h1 id="page-title">Dashboard</h1>
    <div class="tb-right">
      <div style="font-size:.82rem;color:var(--mid);"><?= date('D, d M Y') ?></div>
      <div class="avatar"><?= strtoupper(substr($_SESSION['dept_name'], 0, 2)) ?></div>
    </div>
  </div>

  <div class="content">

    <?php if ($flash): ?>
      <div class="flash <?= $flashType === 'error' ? 'error' : 'success' ?>">
        <?= $flashType === 'error' ? '⚠' : '✓' ?> <?= htmlspecialchars($flash) ?>
      </div>
    <?php endif; ?>

    <!-- ══════════ DASHBOARD TAB ══════════ -->
    <section id="tab-dashboard" class="tab-section active">
      <div class="stats-grid">
        <div class="stat-card" style="--accent-color:#3b82f6">
          <div class="label">Total Items</div>
          <div class="value"><?= $totalItems ?></div>
          <div class="sub">in inventory</div>
        </div>
        <div class="stat-card" style="--accent-color:#1db97a">
          <div class="label">Students</div>
          <div class="value"><?= $totalStudents ?></div>
          <div class="sub">registered</div>
        </div>
        <div class="stat-card" style="--accent-color:#f59e0b">
          <div class="label">Pending</div>
          <div class="value"><?= $pendingReqs ?></div>
          <div class="sub">awaiting review</div>
        </div>
        <div class="stat-card" style="--accent-color:#e84c1e">
          <div class="label">Approved</div>
          <div class="value"><?= $approvedReqs ?></div>
          <div class="sub">this session</div>
        </div>
      </div>

      <div class="sec-header"><h2>Recent Item Requests</h2></div>
      <div class="table-wrap">
        <?php if (empty($requests)): ?>
          <div class="empty-state">
            <svg viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/></svg>
            <p>No requests yet</p>
          </div>
        <?php else: ?>
        <table>
          <thead>
            <tr><th>Student</th><th>Item</th><th>Qty</th><th>Requested</th><th>Status</th></tr>
          </thead>
          <tbody>
            <?php foreach (array_slice($requests, 0, 6) as $r): ?>
            <tr>
              <td><?= htmlspecialchars($r['student_name']) ?><br><span style="color:var(--light);font-size:.75rem"><?= htmlspecialchars($r['s_id']) ?></span></td>
              <td><?= htmlspecialchars($r['item_name']) ?></td>
              <td><?= $r['quantity'] ?></td>
              <td style="color:var(--mid);font-size:.82rem"><?= date('d M, H:i', strtotime($r['requested_at'])) ?></td>
              <td><span class="badge badge-<?= $r['status'] ?>"><?= ucfirst($r['status']) ?></span></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </section>

    <!-- ══════════ ITEMS TAB ══════════ -->
    <section id="tab-items" class="tab-section">
      <div class="sec-header">
        <h2>Item Management</h2>
        <button class="btn btn-primary" onclick="openModal('modal-add-item')">+ Add Item</button>
      </div>
      <div class="table-wrap">
        <?php if (empty($items)): ?>
          <div class="empty-state">
            <svg viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
            <p>No items yet. Add your first item.</p>
          </div>
        <?php else: ?>
        <table>
          <thead>
            <tr><th>#</th><th>Item Name</th><th>Category</th><th>Quantity</th><th>Description</th><th>Actions</th></tr>
          </thead>
          <tbody>
            <?php foreach ($items as $item): ?>
            <tr>
              <td style="color:var(--light)"><?= $item['id'] ?></td>
              <td style="font-weight:500"><?= htmlspecialchars($item['name']) ?></td>
              <td><span class="badge" style="background:#f0ede8;color:var(--mid)"><?= htmlspecialchars($item['category'] ?: '—') ?></span></td>
              <td>
                <form method="POST" class="inline-form">
                  <input type="hidden" name="action" value="update_stock">
                  <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                  <input type="number" name="quantity" value="<?= $item['quantity'] ?>" min="0">
                  <button class="btn btn-sm btn-info" type="submit">Save</button>
                </form>
              </td>
              <td style="color:var(--mid);font-size:.83rem"><?= htmlspecialchars(substr($item['description'] ?? '', 0, 50)) ?><?= strlen($item['description'] ?? '') > 50 ? '…' : '' ?></td>
              <td>
                <form method="POST" onsubmit="return confirm('Delete this item?')">
                  <input type="hidden" name="action" value="delete_item">
                  <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                  <button class="btn btn-sm btn-danger" type="submit">Delete</button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </section>

    <!-- ══════════ REQUESTS TAB ══════════ -->
    <section id="tab-requests" class="tab-section">
      <div class="sec-header">
        <h2>Item Requests</h2>
        <span style="font-size:.82rem;color:var(--mid)"><?= count($requests) ?> total request<?= count($requests) !== 1 ? 's' : '' ?></span>
      </div>
      <div class="table-wrap">
        <?php if (empty($requests)): ?>
          <div class="empty-state">
            <svg viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/></svg>
            <p>No requests found</p>
          </div>
        <?php else: ?>
        <table>
          <thead>
            <tr><th>Student</th><th>Item</th><th>Quantity</th><th>Status</th><th>Requested</th><th>Action</th><th>Remarks</th></tr>
          </thead>
          <tbody>
            <?php foreach ($requests as $r): ?>
            <tr>
              <td>
                <strong><?= htmlspecialchars($r['student_name']) ?></strong><br>
                <span style="color:var(--light);font-size:.75rem"><?= htmlspecialchars($r['s_id']) ?></span>
              </td>
              <td><?= htmlspecialchars($r['item_name']) ?></td>
              <td><?= $r['quantity'] ?></td>
              <td><span class="badge badge-<?= $r['status'] ?>"><?= ucfirst($r['status']) ?></span></td>
              <td style="color:var(--mid);font-size:.8rem"><?= date('d M Y', strtotime($r['requested_at'])) ?></td>
              <td>
                <form method="POST" style="display:flex;gap:6px;align-items:center">
                  <input type="hidden" name="action" value="update_request">
                  <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                  <select name="status" style="padding:5px 8px;border:1.5px solid var(--border);border-radius:7px;font-size:.8rem;background:var(--bg);color:var(--ink);outline:none;cursor:pointer">
                    <option value="pending"  <?= $r['status']==='pending'   ? 'selected' : '' ?>>Pending</option>
                    <option value="approved" <?= $r['status']==='approved'  ? 'selected' : '' ?>>Approved</option>
                    <option value="rejected" <?= $r['status']==='rejected'  ? 'selected' : '' ?>>Rejected</option>
                    <option value="returned" <?= $r['status']==='returned'  ? 'selected' : '' ?>>Returned</option>
                  </select>
                  <button class="btn btn-sm btn-success" type="submit">Update</button>
                </form>
              </td>
              <td style="color:var(--mid);font-size:.8rem"><?= htmlspecialchars($r['purpose'] ?? '—') ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </section>

    <!-- ══════════ STUDENTS TAB ══════════ -->
    <section id="tab-students" class="tab-section">
      <div class="sec-header">
        <h2>Student Management</h2>
        <button class="btn btn-primary" onclick="openModal('modal-add-student')">+ Add Student</button>
      </div>
      <div class="table-wrap">
        <?php if (empty($students)): ?>
          <div class="empty-state">
            <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
            <p>No students registered yet</p>
          </div>
        <?php else: ?>
        <table>
          <thead>
            <tr><th>#</th><th>Name</th><th>Student ID</th><th>Email</th><th>Department</th><th>Joined</th></tr>
          </thead>
          <tbody>
            <?php foreach ($students as $s): ?>
            <tr>
              <td style="color:var(--light)"><?= $s['id'] ?></td>
              <td style="font-weight:500"><?= htmlspecialchars($s['name']) ?></td>
              <td><code style="background:#f0ede8;padding:2px 7px;border-radius:5px;font-size:.8rem"><?= htmlspecialchars($s['student_id']) ?></code></td>
              <td style="color:var(--mid)"><?= htmlspecialchars($s['email']) ?></td>
              <td><?= htmlspecialchars($s['department'] ?? '—') ?></td>
              <td style="color:var(--mid);font-size:.82rem"><?= date('d M Y', strtotime($s['created_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </section>

  </div><!-- /content -->
</div><!-- /main -->

<!-- ═════════════════════ MODALS ════════════════════════ -->

<!-- Add Item Modal -->
<div class="modal-overlay" id="modal-add-item">
  <div class="modal">
    <h3>Add New Item</h3>
    <form method="POST">
      <input type="hidden" name="action" value="add_item">
      <div class="form-row">
        <div class="form-group">
          <label>Item Name *</label>
          <input type="text" name="item_name" placeholder="e.g. Projector" required>
        </div>
        <div class="form-group">
          <label>Category</label>
          <input type="text" name="category" placeholder="e.g. Electronics">
        </div>
      </div>
      <div class="form-group">
        <label>Initial Quantity *</label>
        <input type="number" name="quantity" min="0" value="0" required>
      </div>
      <div class="form-group">
        <label>Description</label>
        <textarea name="description" placeholder="Brief description of the item..."></textarea>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn" onclick="closeModal('modal-add-item')" style="background:var(--bg);border:1px solid var(--border)">Cancel</button>
        <button type="submit" class="btn btn-primary">Add Item</button>
      </div>
    </form>
  </div>
</div>

<!-- Add Student Modal -->
<div class="modal-overlay" id="modal-add-student">
  <div class="modal">
    <h3>Add New Student</h3>
    <form method="POST">
      <input type="hidden" name="action" value="add_student">
      <div class="form-row">
        <div class="form-group">
          <label>Full Name *</label>
          <input type="text" name="student_name" placeholder="John Doe" required>
        </div>
        <div class="form-group">
          <label>Student ID *</label>
          <input type="text" name="student_id" placeholder="CS2024001" required>
        </div>
      </div>
      <div class="form-group">
        <label>Email *</label>
        <input type="email" name="student_email" placeholder="student@college.edu" required>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Department</label>
          <input type="text" name="student_dept" placeholder="Computer Science">
        </div>
        <div class="form-group">
          <label>Password *</label>
          <input type="password" name="student_password" placeholder="Set initial password" required>
        </div>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn" onclick="closeModal('modal-add-student')" style="background:var(--bg);border:1px solid var(--border)">Cancel</button>
        <button type="submit" class="btn btn-primary">Add Student</button>
      </div>
    </form>
  </div>
</div>

<script>
const titles = {
  dashboard: 'Dashboard',
  items:     'Item Management',
  requests:  'Item Requests',
  students:  'Student Management'
};

function showTab(name, btn) {
  document.querySelectorAll('.tab-section').forEach(s => s.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
  document.getElementById('tab-' + name).classList.add('active');
  btn.classList.add('active');
  document.getElementById('page-title').textContent = titles[name];
}

function openModal(id) {
  document.getElementById(id).classList.add('open');
  document.body.style.overflow = 'hidden';
}

function closeModal(id) {
  document.getElementById(id).classList.remove('open');
  document.body.style.overflow = '';
}

// Close modal on overlay click
document.querySelectorAll('.modal-overlay').forEach(o => {
  o.addEventListener('click', e => {
    if (e.target === o) {
      o.classList.remove('open');
      document.body.style.overflow = '';
    }
  });
});

// Auto-hide flash after 4s
const fl = document.querySelector('.flash');
if (fl) setTimeout(() => fl.style.transition = 'opacity .5s', 3500) || setTimeout(() => fl.style.opacity = 0, 4000);
</script>
</body>
</html>