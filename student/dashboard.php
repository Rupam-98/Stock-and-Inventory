<?php
// ============================================================
// DATABASE CONFIGURATION
// ============================================================
define('DB_HOST', 'localhost');
define('DB_PORT', '5432');
define('DB_NAME', 'student_db');
define('DB_USER', 'postgres');
define('DB_PASS', '1035');

// ============================================================
// DATABASE CONNECTION
// ============================================================
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME;
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            die(json_encode(['error' => 'DB connection failed: ' . $e->getMessage()]));
        }
    }
    return $pdo;
}

// ============================================================
// DATABASE SETUP — run once to create tables
// ============================================================
function setupDatabase() {
    $db = getDB();
    $db->exec("
        CREATE TABLE IF NOT EXISTS students (
            id          SERIAL PRIMARY KEY,
            name        VARCHAR(100) NOT NULL,
            student_id  VARCHAR(20)  UNIQUE NOT NULL,
            email       VARCHAR(100) UNIQUE NOT NULL,
            department  VARCHAR(80),
            created_at  TIMESTAMP DEFAULT NOW()
        );

        CREATE TABLE IF NOT EXISTS items (
            id           SERIAL PRIMARY KEY,
            name         VARCHAR(100) NOT NULL,
            category     VARCHAR(60),
            quantity     INTEGER DEFAULT 0,
            description  TEXT,
            created_at   TIMESTAMP DEFAULT NOW()
        );

        CREATE TABLE IF NOT EXISTS item_requests (
            id           SERIAL PRIMARY KEY,
            student_id   INTEGER REFERENCES students(id) ON DELETE CASCADE,
            item_id      INTEGER REFERENCES items(id)    ON DELETE CASCADE,
            quantity     INTEGER DEFAULT 1,
            purpose      TEXT,
            status       VARCHAR(20) DEFAULT 'pending'  CHECK (status IN ('pending','approved','rejected','returned')),
            requested_at TIMESTAMP DEFAULT NOW(),
            updated_at   TIMESTAMP DEFAULT NOW()
        );

        CREATE TABLE IF NOT EXISTS repair_requests (
            id           SERIAL PRIMARY KEY,
            student_id   INTEGER REFERENCES students(id) ON DELETE CASCADE,
            item_name    VARCHAR(100) NOT NULL,
            damage_desc  TEXT NOT NULL,
            priority     VARCHAR(20) DEFAULT 'normal'   CHECK (priority IN ('low','normal','high','urgent')),
            status       VARCHAR(20) DEFAULT 'pending'  CHECK (status IN ('pending','in_progress','completed','rejected')),
            submitted_at TIMESTAMP DEFAULT NOW(),
            updated_at   TIMESTAMP DEFAULT NOW()
        );

        INSERT INTO students (name, student_id, email, department)
        SELECT 'Alice Johnson','STU-001','alice@university.edu','Computer Science'
        WHERE NOT EXISTS (SELECT 1 FROM students WHERE student_id='STU-001');

        INSERT INTO students (name, student_id, email, department)
        SELECT 'Bob Martinez','STU-002','bob@university.edu','Electrical Engineering'
        WHERE NOT EXISTS (SELECT 1 FROM students WHERE student_id='STU-002');

        INSERT INTO items (name, category, quantity, description)
        SELECT 'Laptop','Electronics',10,'Dell XPS 15 – general-use laptops'
        WHERE NOT EXISTS (SELECT 1 FROM items WHERE name='Laptop');

        INSERT INTO items (name, category, quantity, description)
        SELECT 'Scientific Calculator','Stationery',25,'Casio FX-991 scientific calculator'
        WHERE NOT EXISTS (SELECT 1 FROM items WHERE name='Scientific Calculator');

        INSERT INTO items (name, category, quantity, description)
        SELECT 'Oscilloscope','Lab Equipment',5,'Digital oscilloscope for EE labs'
        WHERE NOT EXISTS (SELECT 1 FROM items WHERE name='Oscilloscope');

        INSERT INTO items (name, category, quantity, description)
        SELECT 'Drawing Kit','Art Supplies',15,'Complete architectural drawing kit'
        WHERE NOT EXISTS (SELECT 1 FROM items WHERE name='Drawing Kit');

        INSERT INTO items (name, category, quantity, description)
        SELECT 'USB Flash Drive','Electronics',30,'64 GB USB 3.0 flash drives'
        WHERE NOT EXISTS (SELECT 1 FROM items WHERE name='USB Flash Drive');
    ");
}

// ============================================================
// API HANDLER
// ============================================================
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    $action = $_GET['api'];
    $db = getDB();

    try {
        switch ($action) {

            case 'get_students':
                echo json_encode($db->query("SELECT * FROM students ORDER BY name")->fetchAll());
                break;

            case 'get_items':
                echo json_encode($db->query("SELECT * FROM items ORDER BY name")->fetchAll());
                break;

            case 'get_item_requests':
                $sid = intval($_GET['student_id'] ?? 0);
                $stmt = $db->prepare("
                    SELECT ir.*, i.name AS item_name, i.category,
                           s.name AS student_name
                    FROM item_requests ir
                    JOIN items    i ON i.id = ir.item_id
                    JOIN students s ON s.id = ir.student_id
                    WHERE ir.student_id = ?
                    ORDER BY ir.requested_at DESC
                ");
                $stmt->execute([$sid]);
                echo json_encode($stmt->fetchAll());
                break;

            case 'get_repair_requests':
                $sid = intval($_GET['student_id'] ?? 0);
                $stmt = $db->prepare("
                    SELECT rr.*, s.name AS student_name
                    FROM repair_requests rr
                    JOIN students s ON s.id = rr.student_id
                    WHERE rr.student_id = ?
                    ORDER BY rr.submitted_at DESC
                ");
                $stmt->execute([$sid]);
                echo json_encode($stmt->fetchAll());
                break;

            case 'get_stats':
                $sid = intval($_GET['student_id'] ?? 0);
                $stats = [];

                $stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM item_requests   WHERE student_id=? AND status='pending'");
                $stmt->execute([$sid]); $stats['pending_item_requests'] = $stmt->fetchColumn();

                $stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM item_requests   WHERE student_id=? AND status='approved'");
                $stmt->execute([$sid]); $stats['approved_item_requests'] = $stmt->fetchColumn();

                $stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM repair_requests WHERE student_id=? AND status='pending'");
                $stmt->execute([$sid]); $stats['pending_repairs'] = $stmt->fetchColumn();

                $stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM repair_requests WHERE student_id=? AND status='in_progress'");
                $stmt->execute([$sid]); $stats['active_repairs'] = $stmt->fetchColumn();

                echo json_encode($stats);
                break;

            case 'submit_item_request':
                $data = json_decode(file_get_contents('php://input'), true);
                $stmt = $db->prepare("
                    INSERT INTO item_requests (student_id, item_id, quantity, purpose)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([
                    intval($data['student_id']),
                    intval($data['item_id']),
                    intval($data['quantity'] ?? 1),
                    trim($data['purpose'] ?? '')
                ]);
                echo json_encode(['success' => true, 'id' => $db->lastInsertId()]);
                break;

            case 'submit_repair_request':
                $data = json_decode(file_get_contents('php://input'), true);
                $stmt = $db->prepare("
                    INSERT INTO repair_requests (student_id, item_name, damage_desc, priority)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([
                    intval($data['student_id']),
                    trim($data['item_name']),
                    trim($data['damage_desc']),
                    $data['priority'] ?? 'normal'
                ]);
                echo json_encode(['success' => true, 'id' => $db->lastInsertId()]);
                break;

            default:
                http_response_code(404);
                echo json_encode(['error' => 'Unknown action']);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ============================================================
// FIRST-RUN SETUP
// ============================================================
try {
    setupDatabase();
    $setupError = null;
} catch (Exception $e) {
    $setupError = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Student Resource Portal</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;1,9..40,300&display=swap" rel="stylesheet">

<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --bg:         #0a0d14;
  --surface:    #111520;
  --surface2:   #181d2e;
  --border:     #1e2540;
  --border2:    #2a3060;
  --accent:     #4f7cff;
  --accent2:    #7b5ea7;
  --gold:       #f0c040;
  --danger:     #ff5f6d;
  --success:    #2ecc71;
  --warning:    #f39c12;
  --info:       #3498db;
  --text:       #e8ecf5;
  --muted:      #7a85a3;
  --font-head:  'Syne', sans-serif;
  --font-body:  'DM Sans', sans-serif;
  --radius:     12px;
  --radius-lg:  20px;
  --shadow:     0 8px 32px rgba(0,0,0,.5);
  --glow:       0 0 30px rgba(79,124,255,.15);
}

html { font-size: 16px; }
body {
  font-family: var(--font-body);
  background: var(--bg);
  color: var(--text);
  min-height: 100vh;
  overflow-x: hidden;
}

body::before {
  content: '';
  position: fixed; inset: 0; z-index: 0;
  background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.04'/%3E%3C/svg%3E");
  pointer-events: none;
}

.app { position: relative; z-index: 1; display: flex; min-height: 100vh; }

.sidebar {
  width: 260px; flex-shrink: 0;
  background: var(--surface);
  border-right: 1px solid var(--border);
  display: flex; flex-direction: column;
  padding: 32px 0;
  position: sticky; top: 0; height: 100vh;
}

.logo { padding: 0 28px 32px; border-bottom: 1px solid var(--border); }
.logo-mark {
  display: flex; align-items: center; gap: 10px;
  font-family: var(--font-head); font-size: 1.25rem; font-weight: 800; letter-spacing: -.02em;
}
.logo-icon {
  width: 36px; height: 36px; border-radius: 10px;
  background: linear-gradient(135deg, var(--accent), var(--accent2));
  display: grid; place-items: center; font-size: 1rem;
}
.logo-sub { font-size: .72rem; color: var(--muted); margin-top: 4px; letter-spacing: .08em; text-transform: uppercase; }

.student-selector { padding: 20px 28px; border-bottom: 1px solid var(--border); }
.selector-label { font-size: .7rem; text-transform: uppercase; letter-spacing: .1em; color: var(--muted); margin-bottom: 8px; }
.selector-select {
  width: 100%; background: var(--surface2); border: 1px solid var(--border2);
  color: var(--text); border-radius: 8px; padding: 9px 12px;
  font-family: var(--font-body); font-size: .88rem; cursor: pointer; outline: none;
}
.selector-select:focus { border-color: var(--accent); }

.nav { padding: 20px 16px; flex: 1; }
.nav-item {
  display: flex; align-items: center; gap: 12px;
  padding: 11px 14px; border-radius: 10px; margin-bottom: 4px;
  cursor: pointer; transition: all .2s; color: var(--muted);
  font-size: .9rem; font-weight: 500; border: none; background: none; width: 100%; text-align: left;
}
.nav-item:hover { background: var(--surface2); color: var(--text); }
.nav-item.active { background: rgba(79,124,255,.12); color: var(--accent); }
.nav-item .nav-icon { width: 20px; text-align: center; font-size: 1rem; }
.nav-badge {
  margin-left: auto; background: var(--danger);
  color: #fff; font-size: .68rem; font-weight: 700;
  padding: 2px 7px; border-radius: 20px;
}

.sidebar-footer { padding: 20px 28px; border-top: 1px solid var(--border); }
.sf-version { font-size: .7rem; color: var(--muted); }

.main { flex: 1; display: flex; flex-direction: column; }

.topbar {
  display: flex; align-items: center; justify-content: space-between;
  padding: 20px 36px; border-bottom: 1px solid var(--border);
  background: var(--surface); position: sticky; top: 0; z-index: 10;
}
.topbar-title { font-family: var(--font-head); font-size: 1.15rem; font-weight: 700; }
.topbar-right { display: flex; align-items: center; gap: 14px; }
.topbar-avatar {
  width: 38px; height: 38px; border-radius: 50%;
  background: linear-gradient(135deg, var(--accent), var(--accent2));
  display: grid; place-items: center; font-weight: 700; font-size: .9rem;
}

.content { flex: 1; padding: 36px; }
.page { display: none; animation: fadeIn .3s ease; }
.page.active { display: block; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }

.page-header { margin-bottom: 28px; }
.page-header h1 { font-family: var(--font-head); font-size: 1.9rem; font-weight: 800; }
.page-header p  { color: var(--muted); margin-top: 6px; font-size: .9rem; }

.stats-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 18px; margin-bottom: 32px; }
.stat-card {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: var(--radius-lg); padding: 22px 24px;
  position: relative; overflow: hidden; transition: transform .2s, box-shadow .2s;
}
.stat-card:hover { transform: translateY(-3px); box-shadow: var(--glow); }
.stat-card::after {
  content: ''; position: absolute; top: -30px; right: -30px;
  width: 100px; height: 100px; border-radius: 50%;
  background: var(--card-accent, var(--accent)); opacity: .06;
}
.stat-icon { font-size: 1.6rem; margin-bottom: 12px; }
.stat-value { font-family: var(--font-head); font-size: 2rem; font-weight: 800; line-height: 1; }
.stat-label { color: var(--muted); font-size: .8rem; margin-top: 6px; text-transform: uppercase; letter-spacing: .06em; }

.card {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: var(--radius-lg); overflow: hidden; margin-bottom: 24px;
}
.card-header {
  padding: 20px 24px; border-bottom: 1px solid var(--border);
  display: flex; align-items: center; justify-content: space-between;
}
.card-title { font-family: var(--font-head); font-size: 1rem; font-weight: 700; }
.card-body { padding: 24px; }

.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
.form-group { display: flex; flex-direction: column; gap: 6px; }
.form-group.full { grid-column: 1/-1; }
.form-label { font-size: .8rem; text-transform: uppercase; letter-spacing: .07em; color: var(--muted); font-weight: 500; }
.form-control {
  background: var(--surface2); border: 1px solid var(--border2);
  color: var(--text); border-radius: 10px; padding: 11px 14px;
  font-family: var(--font-body); font-size: .92rem; outline: none;
  transition: border-color .2s, box-shadow .2s; width: 100%;
}
.form-control:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(79,124,255,.12); }
textarea.form-control { min-height: 100px; resize: vertical; }
select.form-control { cursor: pointer; }

.btn {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 11px 22px; border-radius: 10px; border: none; cursor: pointer;
  font-family: var(--font-body); font-size: .9rem; font-weight: 600;
  transition: all .2s; white-space: nowrap;
}
.btn-primary { background: var(--accent); color: #fff; }
.btn-primary:hover { background: #3a66f5; box-shadow: 0 4px 20px rgba(79,124,255,.4); }
.btn-secondary { background: var(--surface2); color: var(--text); border: 1px solid var(--border2); }
.btn-secondary:hover { border-color: var(--accent); color: var(--accent); }
.btn-sm { padding: 7px 14px; font-size: .82rem; border-radius: 8px; }

.table-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; }
th {
  text-align: left; padding: 12px 16px;
  font-size: .72rem; text-transform: uppercase; letter-spacing: .08em;
  color: var(--muted); border-bottom: 1px solid var(--border); font-weight: 600;
}
td { padding: 14px 16px; border-bottom: 1px solid var(--border); font-size: .9rem; vertical-align: middle; }
tr:last-child td { border-bottom: none; }
tr:hover td { background: rgba(255,255,255,.02); }

.badge {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 4px 10px; border-radius: 20px; font-size: .75rem; font-weight: 600;
}
.badge::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
.badge-pending     { background: rgba(243,156,18,.12); color: var(--warning); }
.badge-approved    { background: rgba(46,204,113,.12); color: var(--success); }
.badge-rejected    { background: rgba(255,95,109,.12); color: var(--danger); }
.badge-returned    { background: rgba(52,152,219,.12); color: var(--info); }
.badge-in_progress { background: rgba(123,94,167,.12); color: var(--accent2); }
.badge-completed   { background: rgba(46,204,113,.12); color: var(--success); }
.badge-low         { background: rgba(52,152,219,.12); color: var(--info); }
.badge-normal      { background: rgba(243,156,18,.12); color: var(--warning); }
.badge-high        { background: rgba(255,95,109,.12); color: var(--danger); }
.badge-urgent      { background: rgba(255,95,109,.2);  color: var(--danger); border: 1px solid var(--danger); }

.empty-state { text-align: center; padding: 48px 24px; color: var(--muted); }
.empty-state .es-icon { font-size: 3rem; margin-bottom: 16px; opacity: .5; }
.empty-state h3 { font-family: var(--font-head); font-size: 1.1rem; margin-bottom: 8px; color: var(--text); }

#toast-container { position: fixed; bottom: 28px; right: 28px; z-index: 9999; display: flex; flex-direction: column; gap: 10px; }
.toast {
  background: var(--surface2); border: 1px solid var(--border2);
  border-radius: 12px; padding: 14px 20px; display: flex; align-items: center; gap: 12px;
  min-width: 260px; box-shadow: var(--shadow); animation: slideIn .3s ease;
}
.toast.success { border-left: 3px solid var(--success); }
.toast.error   { border-left: 3px solid var(--danger); }
.toast-icon { font-size: 1.2rem; }
.toast-msg { font-size: .88rem; }
@keyframes slideIn { from { opacity: 0; transform: translateX(20px); } to { opacity: 1; transform: none; } }

.activity-item {
  display: flex; gap: 14px; align-items: flex-start;
  padding: 14px 0; border-bottom: 1px solid var(--border);
}
.activity-item:last-child { border-bottom: none; }
.activity-dot { width: 10px; height: 10px; border-radius: 50%; background: var(--accent); margin-top: 5px; flex-shrink: 0; }
.activity-text { font-size: .88rem; line-height: 1.5; }
.activity-time { font-size: .75rem; color: var(--muted); margin-top: 2px; }

.quick-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 28px; }
.qa-card {
  background: var(--surface2); border: 1px solid var(--border2);
  border-radius: var(--radius-lg); padding: 22px;
  cursor: pointer; transition: all .2s; text-align: left;
}
.qa-card:hover { border-color: var(--accent); background: rgba(79,124,255,.06); transform: translateY(-2px); }
.qa-icon { font-size: 2rem; margin-bottom: 12px; }
.qa-title { font-family: var(--font-head); font-weight: 700; font-size: .95rem; margin-bottom: 4px; }
.qa-desc { font-size: .82rem; color: var(--muted); }

.alert { padding: 14px 18px; border-radius: 10px; margin-bottom: 20px; font-size: .88rem; display: flex; align-items: center; gap: 10px; }
.alert-error   { background: rgba(255,95,109,.1); border: 1px solid rgba(255,95,109,.3); color: var(--danger); }
.alert-success { background: rgba(46,204,113,.1); border: 1px solid rgba(46,204,113,.3); color: var(--success); }

@media (max-width: 900px) {
  .sidebar { width: 220px; }
  .stats-grid { grid-template-columns: 1fr 1fr; }
  .content { padding: 24px; }
}
@media (max-width: 640px) {
  .app { flex-direction: column; }
  .sidebar { width: 100%; height: auto; position: relative; }
  .stats-grid { grid-template-columns: 1fr 1fr; }
  .form-grid { grid-template-columns: 1fr; }
  .quick-actions { grid-template-columns: 1fr; }
}
</style>
</head>
<body>

<?php if ($setupError): ?>
<div style="background:#1a0010;color:#ff5f6d;padding:20px;text-align:center;font-family:monospace;">
  ⚠️ Database Setup Error: <?= htmlspecialchars($setupError) ?><br>
  <small>Check your DB credentials in the DB_* constants at the top of this file.</small>
</div>
<?php endif; ?>

<div class="app">
  <aside class="sidebar">
    <div class="logo">
      <div class="logo-mark">
        <div class="logo-icon">🎓</div>
        UniPortal
      </div>
      <div class="logo-sub">Student Resource System</div>
    </div>

    <div class="student-selector">
      <div class="selector-label">Logged in as</div>
      <select class="selector-select" id="studentSelect" onchange="switchStudent()">
        <option value="">Loading…</option>
      </select>
    </div>

    <nav class="nav">
      <button class="nav-item active" onclick="navigate('dashboard',this)">
        <span class="nav-icon">🏠</span> Dashboard
      </button>
      <button class="nav-item" onclick="navigate('request-item',this)">
        <span class="nav-icon">📦</span> Request Item
      </button>
      <button class="nav-item" onclick="navigate('repair-request',this)">
        <span class="nav-icon">🔧</span> Report Damage
      </button>
      <button class="nav-item" onclick="navigate('my-requests',this)">
        <span class="nav-icon">📋</span> My Requests
        <span class="nav-badge" id="pendingBadge" style="display:none">0</span>
      </button>
      <button class="nav-item" onclick="navigate('my-repairs',this)">
        <span class="nav-icon">🛠️</span> My Repairs
        <span class="nav-badge" id="repairBadge" style="display:none">0</span>
      </button>
    </nav>

    <div class="sidebar-footer">
      <div class="sf-version">v1.0.0 · PHP + PostgreSQL · DB: six_sem</div>
    </div>
  </aside>

  <main class="main">
    <header class="topbar">
      <div class="topbar-title" id="topbarTitle">Dashboard</div>
      <div class="topbar-right">
        <span style="font-size:.85rem;color:var(--muted)" id="topbarDate"></span>
        <div class="topbar-avatar" id="topbarAvatar">?</div>
      </div>
    </header>

    <div class="content">

      <!-- DASHBOARD -->
      <div class="page active" id="page-dashboard">
        <div class="page-header">
          <h1>Good morning, <span id="studentFirstName">Student</span> 👋</h1>
          <p>Here's an overview of your resource activity.</p>
        </div>

        <div class="stats-grid">
          <div class="stat-card" style="--card-accent:var(--warning)">
            <div class="stat-icon">⏳</div>
            <div class="stat-value" id="statPendingItems">—</div>
            <div class="stat-label">Pending Requests</div>
          </div>
          <div class="stat-card" style="--card-accent:var(--success)">
            <div class="stat-icon">✅</div>
            <div class="stat-value" id="statApprovedItems">—</div>
            <div class="stat-label">Approved Requests</div>
          </div>
          <div class="stat-card" style="--card-accent:var(--danger)">
            <div class="stat-icon">🔧</div>
            <div class="stat-value" id="statPendingRepairs">—</div>
            <div class="stat-label">Pending Repairs</div>
          </div>
          <div class="stat-card" style="--card-accent:var(--accent2)">
            <div class="stat-icon">🛠️</div>
            <div class="stat-value" id="statActiveRepairs">—</div>
            <div class="stat-label">Active Repairs</div>
          </div>
        </div>

        <div class="quick-actions">
          <div class="qa-card" onclick="navigate('request-item',null)">
            <div class="qa-icon">📦</div>
            <div class="qa-title">Request an Item</div>
            <div class="qa-desc">Borrow lab equipment, stationery, electronics & more.</div>
          </div>
          <div class="qa-card" onclick="navigate('repair-request',null)">
            <div class="qa-icon">🔨</div>
            <div class="qa-title">Report Damage</div>
            <div class="qa-desc">Submit a repair request for damaged university items.</div>
          </div>
        </div>

        <div class="card">
          <div class="card-header"><span class="card-title">📜 Recent Activity</span></div>
          <div class="card-body" id="activityFeed">
            <div class="empty-state">
              <div class="es-icon">📭</div>
              <h3>No activity yet</h3>
              <p>Your requests and repairs will appear here.</p>
            </div>
          </div>
        </div>
      </div>

      <!-- REQUEST ITEM -->
      <div class="page" id="page-request-item">
        <div class="page-header">
          <h1>📦 Request an Item</h1>
          <p>Browse available inventory and submit a borrowing request.</p>
        </div>
        <div class="card">
          <div class="card-header"><span class="card-title">New Item Request</span></div>
          <div class="card-body">
            <div id="itemRequestAlert"></div>
            <div class="form-grid">
              <div class="form-group">
                <label class="form-label">Select Item</label>
                <select class="form-control" id="reqItemId">
                  <option value="">— choose an item —</option>
                </select>
              </div>
              <div class="form-group">
                <label class="form-label">Quantity</label>
                <input type="number" class="form-control" id="reqQty" min="1" max="10" value="1">
              </div>
              <div class="form-group full">
                <label class="form-label">Purpose / Reason</label>
                <textarea class="form-control" id="reqPurpose" placeholder="Briefly describe why you need this item…"></textarea>
              </div>
            </div>
            <div style="margin-top:20px;display:flex;gap:12px;">
              <button class="btn btn-primary" onclick="submitItemRequest()">🚀 Submit Request</button>
              <button class="btn btn-secondary" onclick="clearItemForm()">✕ Clear</button>
            </div>
          </div>
        </div>

        <div class="card">
          <div class="card-header"><span class="card-title">🗃️ Available Inventory</span></div>
          <div class="table-wrap">
            <table id="inventoryTable">
              <thead><tr><th>Item</th><th>Category</th><th>Available</th><th>Action</th></tr></thead>
              <tbody id="inventoryBody"><tr><td colspan="4" style="text-align:center;color:var(--muted);padding:30px">Loading…</td></tr></tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- REPAIR REQUEST -->
      <div class="page" id="page-repair-request">
        <div class="page-header">
          <h1>🔧 Report Damaged Item</h1>
          <p>Fill in the form below to request a repair for damaged university property.</p>
        </div>
        <div class="card">
          <div class="card-header"><span class="card-title">New Repair Request</span></div>
          <div class="card-body">
            <div id="repairRequestAlert"></div>
            <div class="form-grid">
              <div class="form-group full">
                <label class="form-label">Item / Equipment Name</label>
                <input type="text" class="form-control" id="repItemName" placeholder="e.g. Dell Laptop (Lab C), Microscope #3…">
              </div>
              <div class="form-group full">
                <label class="form-label">Damage Description</label>
                <textarea class="form-control" id="repDamageDesc" placeholder="Describe the damage in detail — what happened, what is broken, when it occurred…"></textarea>
              </div>
              <div class="form-group">
                <label class="form-label">Priority Level</label>
                <select class="form-control" id="repPriority">
                  <option value="low">🟢 Low — Minor cosmetic damage</option>
                  <option value="normal" selected>🟡 Normal — Partially functional</option>
                  <option value="high">🟠 High — Mostly non-functional</option>
                  <option value="urgent">🔴 Urgent — Safety hazard / completely broken</option>
                </select>
              </div>
            </div>
            <div style="margin-top:20px;display:flex;gap:12px;">
              <button class="btn btn-primary" onclick="submitRepairRequest()">📤 Submit Repair Request</button>
              <button class="btn btn-secondary" onclick="clearRepairForm()">✕ Clear</button>
            </div>
          </div>
        </div>
      </div>

      <!-- MY REQUESTS -->
      <div class="page" id="page-my-requests">
        <div class="page-header">
          <h1>📋 My Item Requests</h1>
          <p>Track the status of all your borrowing requests.</p>
        </div>
        <div class="card">
          <div class="card-header">
            <span class="card-title">Request History</span>
            <button class="btn btn-sm btn-secondary" onclick="loadMyRequests()">↻ Refresh</button>
          </div>
          <div class="table-wrap">
            <table>
              <thead><tr><th>#</th><th>Item</th><th>Category</th><th>Qty</th><th>Purpose</th><th>Status</th><th>Date</th></tr></thead>
              <tbody id="myRequestsBody"><tr><td colspan="7" style="text-align:center;color:var(--muted);padding:30px">Loading…</td></tr></tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- MY REPAIRS -->
      <div class="page" id="page-my-repairs">
        <div class="page-header">
          <h1>🛠️ My Repair Requests</h1>
          <p>Track the progress of your submitted repair requests.</p>
        </div>
        <div class="card">
          <div class="card-header">
            <span class="card-title">Repair History</span>
            <button class="btn btn-sm btn-secondary" onclick="loadMyRepairs()">↻ Refresh</button>
          </div>
          <div class="table-wrap">
            <table>
              <thead><tr><th>#</th><th>Item</th><th>Damage Description</th><th>Priority</th><th>Status</th><th>Date</th></tr></thead>
              <tbody id="myRepairsBody"><tr><td colspan="6" style="text-align:center;color:var(--muted);padding:30px">Loading…</td></tr></tbody>
            </table>
          </div>
        </div>
      </div>

    </div>
  </main>
</div>

<div id="toast-container"></div>

<script>
let currentStudentId = null;
let students = [];
let items    = [];

document.addEventListener('DOMContentLoaded', async () => {
  document.getElementById('topbarDate').textContent =
    new Date().toLocaleDateString('en-US', { weekday:'long', year:'numeric', month:'long', day:'numeric' });

  students = await api('get_students');
  buildStudentSelector(students);

  items = await api('get_items');
  buildInventoryTable(items);
  buildItemDropdown(items);

  if (students.length) {
    currentStudentId = students[0].id;
    onStudentChanged();
  }
});

async function api(action, params = {}, body = null) {
  const qs = new URLSearchParams({ api: action, ...params }).toString();
  const opts = body
    ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }
    : { method: 'GET' };
  const res = await fetch(`?${qs}`, opts);
  return await res.json();
}

function buildStudentSelector(students) {
  const sel = document.getElementById('studentSelect');
  sel.innerHTML = students.map(s =>
    `<option value="${s.id}">${s.name} (${s.student_id})</option>`
  ).join('');
}

function switchStudent() {
  currentStudentId = parseInt(document.getElementById('studentSelect').value);
  onStudentChanged();
}

function onStudentChanged() {
  const s = students.find(x => x.id == currentStudentId);
  if (!s) return;
  document.getElementById('studentFirstName').textContent = s.name.split(' ')[0];
  document.getElementById('topbarAvatar').textContent = s.name.charAt(0).toUpperCase();
  loadStats();
  loadActivityFeed();
  if (document.getElementById('page-my-requests').classList.contains('active')) loadMyRequests();
  if (document.getElementById('page-my-repairs').classList.contains('active')) loadMyRepairs();
}

function navigate(page, btn) {
  document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
  document.getElementById('page-' + page).classList.add('active');
  if (btn) btn.classList.add('active');
  else {
    document.querySelectorAll('.nav-item').forEach(n => {
      if (n.getAttribute('onclick')?.includes(page)) n.classList.add('active');
    });
  }
  const titles = { 'dashboard':'Dashboard','request-item':'Request Item','repair-request':'Report Damage','my-requests':'My Requests','my-repairs':'My Repairs' };
  document.getElementById('topbarTitle').textContent = titles[page] || page;
  if (page === 'my-requests') loadMyRequests();
  if (page === 'my-repairs')  loadMyRepairs();
}

async function loadStats() {
  const stats = await api('get_stats', { student_id: currentStudentId });
  document.getElementById('statPendingItems').textContent  = stats.pending_item_requests  ?? 0;
  document.getElementById('statApprovedItems').textContent = stats.approved_item_requests ?? 0;
  document.getElementById('statPendingRepairs').textContent= stats.pending_repairs        ?? 0;
  document.getElementById('statActiveRepairs').textContent = stats.active_repairs         ?? 0;
  const pb = document.getElementById('pendingBadge');
  const rb = document.getElementById('repairBadge');
  const pi = parseInt(stats.pending_item_requests) + parseInt(stats.approved_item_requests);
  const ri = parseInt(stats.pending_repairs)        + parseInt(stats.active_repairs);
  pb.style.display = pi > 0 ? '' : 'none'; pb.textContent = pi;
  rb.style.display = ri > 0 ? '' : 'none'; rb.textContent = ri;
}

async function loadActivityFeed() {
  const [reqs, reps] = await Promise.all([
    api('get_item_requests',   { student_id: currentStudentId }),
    api('get_repair_requests', { student_id: currentStudentId }),
  ]);
  const feed = document.getElementById('activityFeed');
  const events = [
    ...reqs.map(r => ({ time: r.requested_at, text: `Item request for <b>${r.item_name}</b> — ${r.quantity} unit(s)`, status: r.status })),
    ...reps.map(r => ({ time: r.submitted_at, text: `Repair request for <b>${r.item_name}</b>`, status: r.status })),
  ].sort((a, b) => new Date(b.time) - new Date(a.time)).slice(0, 8);
  if (!events.length) {
    feed.innerHTML = `<div class="empty-state"><div class="es-icon">📭</div><h3>No activity yet</h3><p>Your requests will appear here.</p></div>`;
    return;
  }
  feed.innerHTML = events.map(e => `
    <div class="activity-item">
      <div class="activity-dot" style="background:${statusColor(e.status)}"></div>
      <div>
        <div class="activity-text">${e.text} · <span class="badge badge-${e.status}">${e.status.replace('_',' ')}</span></div>
        <div class="activity-time">${formatDate(e.time)}</div>
      </div>
    </div>
  `).join('');
}

function buildInventoryTable(items) {
  const tbody = document.getElementById('inventoryBody');
  tbody.innerHTML = items.map(i => `
    <tr>
      <td><b>${esc(i.name)}</b><div style="font-size:.78rem;color:var(--muted);margin-top:2px">${esc(i.description||'')}</div></td>
      <td><span style="font-size:.8rem;background:var(--surface2);padding:3px 10px;border-radius:20px;border:1px solid var(--border2)">${esc(i.category||'—')}</span></td>
      <td><b style="color:${i.quantity>0?'var(--success)':'var(--danger)'}">${i.quantity}</b></td>
      <td>
        <button class="btn btn-sm btn-secondary" onclick="quickRequest(${i.id})"
          ${i.quantity<=0?'disabled style="opacity:.4;cursor:not-allowed"':''}>
          ${i.quantity>0?'📥 Request':'Out of Stock'}
        </button>
      </td>
    </tr>
  `).join('');
}

function buildItemDropdown(items) {
  const sel = document.getElementById('reqItemId');
  sel.innerHTML = '<option value="">— choose an item —</option>' +
    items.map(i => `<option value="${i.id}" ${i.quantity<=0?'disabled':''}>
      ${esc(i.name)} (${esc(i.category||'')}) — ${i.quantity} avail.
    </option>`).join('');
}

function quickRequest(itemId) {
  navigate('request-item', null);
  setTimeout(() => { document.getElementById('reqItemId').value = itemId; }, 200);
}

async function submitItemRequest() {
  const itemId  = parseInt(document.getElementById('reqItemId').value);
  const qty     = parseInt(document.getElementById('reqQty').value);
  const purpose = document.getElementById('reqPurpose').value.trim();
  const alertEl = document.getElementById('itemRequestAlert');
  if (!itemId)   return showAlert(alertEl,'error','⚠️ Please select an item.');
  if (!qty||qty<1) return showAlert(alertEl,'error','⚠️ Quantity must be at least 1.');
  if (!purpose)  return showAlert(alertEl,'error','⚠️ Please describe the purpose.');
  if (!currentStudentId) return showAlert(alertEl,'error','⚠️ No student selected.');
  const res = await api('submit_item_request', {}, { student_id: currentStudentId, item_id: itemId, quantity: qty, purpose });
  if (res.success) {
    showAlert(alertEl,'success','✅ Item request submitted successfully!');
    clearItemForm(); toast('success','Request submitted!');
    loadStats(); loadActivityFeed();
  } else {
    showAlert(alertEl,'error','❌ ' + (res.error||'Something went wrong.'));
  }
}

function clearItemForm() {
  document.getElementById('reqItemId').value  = '';
  document.getElementById('reqQty').value     = 1;
  document.getElementById('reqPurpose').value = '';
  document.getElementById('itemRequestAlert').innerHTML = '';
}

async function submitRepairRequest() {
  const itemName   = document.getElementById('repItemName').value.trim();
  const damageDesc = document.getElementById('repDamageDesc').value.trim();
  const priority   = document.getElementById('repPriority').value;
  const alertEl    = document.getElementById('repairRequestAlert');
  if (!itemName)   return showAlert(alertEl,'error','⚠️ Please enter the item name.');
  if (!damageDesc) return showAlert(alertEl,'error','⚠️ Please describe the damage.');
  if (!currentStudentId) return showAlert(alertEl,'error','⚠️ No student selected.');
  const res = await api('submit_repair_request', {}, { student_id: currentStudentId, item_name: itemName, damage_desc: damageDesc, priority });
  if (res.success) {
    showAlert(alertEl,'success','✅ Repair request submitted! Our team will review it shortly.');
    clearRepairForm(); toast('success','Repair request submitted!');
    loadStats(); loadActivityFeed();
  } else {
    showAlert(alertEl,'error','❌ ' + (res.error||'Something went wrong.'));
  }
}

function clearRepairForm() {
  document.getElementById('repItemName').value   = '';
  document.getElementById('repDamageDesc').value = '';
  document.getElementById('repPriority').value   = 'normal';
  document.getElementById('repairRequestAlert').innerHTML = '';
}

async function loadMyRequests() {
  const tbody = document.getElementById('myRequestsBody');
  tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:var(--muted);padding:30px">Loading…</td></tr>';
  const data = await api('get_item_requests', { student_id: currentStudentId });
  if (!data.length) {
    tbody.innerHTML = `<tr><td colspan="7"><div class="empty-state"><div class="es-icon">📂</div><h3>No requests yet</h3><p>Submit your first item request!</p></div></td></tr>`;
    return;
  }
  tbody.innerHTML = data.map((r,i) => `
    <tr>
      <td style="color:var(--muted)">#${i+1}</td>
      <td><b>${esc(r.item_name)}</b></td>
      <td><span style="font-size:.78rem;background:var(--surface2);padding:3px 9px;border-radius:20px;border:1px solid var(--border2)">${esc(r.category||'—')}</span></td>
      <td>${r.quantity}</td>
      <td style="max-width:200px;font-size:.84rem;color:var(--muted)">${esc(r.purpose||'—')}</td>
      <td><span class="badge badge-${r.status}">${r.status}</span></td>
      <td style="font-size:.8rem;color:var(--muted)">${formatDate(r.requested_at)}</td>
    </tr>
  `).join('');
}

async function loadMyRepairs() {
  const tbody = document.getElementById('myRepairsBody');
  tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--muted);padding:30px">Loading…</td></tr>';
  const data = await api('get_repair_requests', { student_id: currentStudentId });
  if (!data.length) {
    tbody.innerHTML = `<tr><td colspan="6"><div class="empty-state"><div class="es-icon">🔧</div><h3>No repair requests yet</h3><p>Report a damaged item to get started.</p></div></td></tr>`;
    return;
  }
  tbody.innerHTML = data.map((r,i) => `
    <tr>
      <td style="color:var(--muted)">#${i+1}</td>
      <td><b>${esc(r.item_name)}</b></td>
      <td style="max-width:220px;font-size:.84rem;color:var(--muted)">${esc(r.damage_desc)}</td>
      <td><span class="badge badge-${r.priority}">${r.priority}</span></td>
      <td><span class="badge badge-${r.status}">${r.status.replace('_',' ')}</span></td>
      <td style="font-size:.8rem;color:var(--muted)">${formatDate(r.submitted_at)}</td>
    </tr>
  `).join('');
}

function esc(str) {
  return String(str||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function formatDate(d) {
  return new Date(d).toLocaleString('en-US', { month:'short', day:'numeric', year:'numeric', hour:'2-digit', minute:'2-digit' });
}
function statusColor(s) {
  return { pending:'#f39c12', approved:'#2ecc71', rejected:'#ff5f6d', returned:'#3498db', in_progress:'#7b5ea7', completed:'#2ecc71' }[s] || '#4f7cff';
}
function showAlert(el, type, msg) {
  el.innerHTML = `<div class="alert alert-${type}">${msg}</div>`;
  setTimeout(() => { el.innerHTML = ''; }, 5000);
}
function toast(type, msg) {
  const c = document.getElementById('toast-container');
  const t = document.createElement('div');
  t.className = `toast ${type}`;
  t.innerHTML = `<span class="toast-icon">${type==='success'?'✅':'❌'}</span><span class="toast-msg">${msg}</span>`;
  c.appendChild(t);
  setTimeout(() => t.remove(), 3500);
}
</script>
</body>
</html>