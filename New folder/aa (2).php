<?php
session_start();

// ============================================================
// DATABASE CONFIGURATION
// ============================================================
define('DB_HOST', 'localhost');
define('DB_PORT', '5432');
define('DB_NAME', 'six_sems');
define('DB_USER', 'postgres');
define('DB_PASS', '1035');

// ============================================================
// DATABASE CONNECTION
// ============================================================
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "pgsql:host=".DB_HOST.";port=".DB_PORT.";dbname=".DB_NAME;
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            die(json_encode(['error'=>'DB connection failed: '.$e->getMessage()]));
        }
    }
    return $pdo;
}

// ============================================================
// DATABASE SETUP
// ============================================================
function setupDatabase() {
    $db = getDB();
    $db->exec("
        CREATE TABLE IF NOT EXISTS students (
            id         SERIAL PRIMARY KEY,
            name       VARCHAR(100) NOT NULL,
            student_id VARCHAR(20)  UNIQUE NOT NULL,
            email      VARCHAR(100) UNIQUE NOT NULL,
            password   VARCHAR(255) NOT NULL,
            department VARCHAR(80),
            created_at TIMESTAMP DEFAULT NOW()
        );
        CREATE TABLE IF NOT EXISTS items (
            id          SERIAL PRIMARY KEY,
            name        VARCHAR(100) NOT NULL,
            category    VARCHAR(60),
            quantity    INTEGER DEFAULT 0,
            description TEXT,
            created_at  TIMESTAMP DEFAULT NOW()
        );
        CREATE TABLE IF NOT EXISTS department_requests (
            id             SERIAL PRIMARY KEY,
            student_id     INTEGER REFERENCES students(id) ON DELETE CASCADE,
            department     VARCHAR(80) NOT NULL,
            request_type   VARCHAR(20) NOT NULL CHECK (request_type IN ('missing','lost','consumable_restock','new_requirement')),
            item_name      VARCHAR(150) NOT NULL,
            item_category  VARCHAR(60),
            quantity_needed INTEGER DEFAULT 1,
            description    TEXT NOT NULL,
            urgency        VARCHAR(20) DEFAULT 'normal' CHECK (urgency IN ('low','normal','high','urgent')),
            status         VARCHAR(20) DEFAULT 'pending' CHECK (status IN ('pending','dept_approved','dept_rejected','purchase_ordered','fulfilled')),
            dept_admin_note TEXT,
            requested_at   TIMESTAMP DEFAULT NOW(),
            updated_at     TIMESTAMP DEFAULT NOW()
        );
        CREATE TABLE IF NOT EXISTS repair_requests (
            id           SERIAL PRIMARY KEY,
            student_id   INTEGER REFERENCES students(id) ON DELETE CASCADE,
            item_name    VARCHAR(100) NOT NULL,
            damage_desc  TEXT NOT NULL,
            priority     VARCHAR(20) DEFAULT 'normal' CHECK (priority IN ('low','normal','high','urgent')),
            status       VARCHAR(20) DEFAULT 'pending' CHECK (status IN ('pending','in_progress','completed','rejected')),
            submitted_at TIMESTAMP DEFAULT NOW(),
            updated_at   TIMESTAMP DEFAULT NOW()
        );
    ");

    // Seed demo students — password: student123
    $hash = password_hash('student123', PASSWORD_DEFAULT);
    foreach ([
        ['Alice Johnson','STU-001','alice@university.edu','Computer Science'],
        ['Bob Martinez', 'STU-002','bob@university.edu',  'Electrical Engineering'],
        ['Clara Nguyen', 'STU-003','clara@university.edu','Mechanical Engineering'],
    ] as $s) {
        $db->prepare("INSERT INTO students (name,student_id,email,password,department)
            SELECT ?,?,?,?,? WHERE NOT EXISTS (SELECT 1 FROM students WHERE student_id=?)")
           ->execute([$s[0],$s[1],$s[2],$hash,$s[3],$s[1]]);
    }

    // Seed items
    foreach ([
        ['Laptop',             'Electronics',   10, 'Dell XPS 15 – general-use laptops'],
        ['Scientific Calculator','Stationery',  25, 'Casio FX-991 scientific calculator'],
        ['Oscilloscope',       'Lab Equipment',  5, 'Digital oscilloscope for EE labs'],
        ['Drawing Kit',        'Art Supplies',  15, 'Complete architectural drawing kit'],
        ['USB Flash Drive',    'Electronics',   30, '64 GB USB 3.0 flash drives'],
        ['Multimeter',         'Lab Equipment',  8, 'Digital multimeter for circuit testing'],
        ['Soldering Kit',      'Lab Equipment',  6, 'Complete soldering kit with iron & solder'],
    ] as $i) {
        $db->prepare("INSERT INTO items (name,category,quantity,description)
            SELECT ?,?,?,? WHERE NOT EXISTS (SELECT 1 FROM items WHERE name=?)")
           ->execute([$i[0],$i[1],$i[2],$i[3],$i[0]]);
    }
}

// ============================================================
// AUTH GUARD
// ============================================================
if (empty($_SESSION['student'])) {
    header('Location: login.php'); exit;
}

// Handle logout
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'logout') {
    session_destroy();
    header('Location: login.php'); exit;
}

// ============================================================
// JSON API (session-protected)
// ============================================================
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    if (empty($_SESSION['student'])) {
        http_response_code(401); echo json_encode(['error'=>'Unauthorized']); exit;
    }
    $me  = $_SESSION['student'];
    $db  = getDB();
    $act = $_GET['api'];

    try {
        switch ($act) {

            case 'get_items':
                // Only show items belonging to the student's own department
                $s = $db->prepare("SELECT * FROM items WHERE department=? OR department IS NULL ORDER BY name");
                $s->execute([$me['department'] ?? '']);
                echo json_encode($s->fetchAll());
                break;

            case 'get_dept_requests':
                $s = $db->prepare("SELECT * FROM department_requests WHERE student_id=? ORDER BY requested_at DESC");
                $s->execute([$me['id']]); echo json_encode($s->fetchAll());
                break;

            case 'get_repair_requests':
                $s = $db->prepare("SELECT * FROM repair_requests WHERE student_id=? ORDER BY submitted_at DESC");
                $s->execute([$me['id']]); echo json_encode($s->fetchAll());
                break;

            case 'get_stats':
                $stats = [];
                foreach ([
                    'pending_dept_requests'   => "SELECT COUNT(*) FROM department_requests WHERE student_id=? AND status='pending'",
                    'approved_dept_requests'  => "SELECT COUNT(*) FROM department_requests WHERE student_id=? AND status='dept_approved'",
                    'ordered_dept_requests'   => "SELECT COUNT(*) FROM department_requests WHERE student_id=? AND status='purchase_ordered'",
                    'pending_repairs'         => "SELECT COUNT(*) FROM repair_requests WHERE student_id=? AND status='pending'",
                    'active_repairs'          => "SELECT COUNT(*) FROM repair_requests WHERE student_id=? AND status='in_progress'",
                ] as $k=>$q) {
                    $s=$db->prepare($q); $s->execute([$me['id']]); $stats[$k]=$s->fetchColumn();
                }
                echo json_encode($stats);
                break;

            case 'submit_dept_request':
                $d = json_decode(file_get_contents('php://input'), true);
                $rtype = $d['request_type'] ?? 'missing';
                $allowed = ['missing','lost','consumable_restock','new_requirement'];
                if (!in_array($rtype, $allowed)) { http_response_code(400); echo json_encode(['error'=>'Invalid request type']); break; }
                $db->prepare("INSERT INTO department_requests
                    (student_id, department, request_type, item_name, item_category, quantity_needed, description, urgency)
                    VALUES (?,?,?,?,?,?,?,?)")
                   ->execute([
                       $me['id'],
                       $me['department'] ?? 'General',
                       $rtype,
                       trim($d['item_name'] ?? ''),
                       trim($d['item_category'] ?? ''),
                       max(1, intval($d['quantity_needed'] ?? 1)),
                       trim($d['description'] ?? ''),
                       $d['urgency'] ?? 'normal',
                   ]);
                echo json_encode(['success'=>true]);
                break;

            case 'submit_repair_request':
                $d = json_decode(file_get_contents('php://input'), true);
                $db->prepare("INSERT INTO repair_requests (student_id,item_name,damage_desc,priority) VALUES (?,?,?,?)")
                   ->execute([$me['id'], trim($d['item_name']), trim($d['damage_desc']), $d['priority']??'normal']);
                echo json_encode(['success'=>true]);
                break;

            default:
                http_response_code(404); echo json_encode(['error'=>'Unknown action']);
        }
    } catch (Exception $e) {
        http_response_code(500); echo json_encode(['error'=>$e->getMessage()]);
    }
    exit;
}

// ============================================================
// PAGE INIT
// ============================================================
try { setupDatabase(); } catch (Exception $e) { /* non-fatal */ }
$student = $_SESSION['student'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Dashboard · <?= htmlspecialchars($student['name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
<style>
/* ── RESET & VARS ───────────────────────────────── */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#090c13;--surface:#10141f;--surface2:#161c2d;--border:#1c2340;--border2:#27306a;
  --accent:#4f7cff;--accent2:#7b5ea7;
  --danger:#ff5f6d;--success:#2ecc71;--warning:#f0a500;--info:#3498db;
  --text:#e8ecf8;--muted:#68739e;
  --fh:'Syne',sans-serif;--fb:'DM Sans',sans-serif;
  --r:12px;--rl:18px;--sh:0 14px 44px rgba(0,0,0,.65);--glow:0 0 36px rgba(79,124,255,.13)
}
html{font-size:16px}
body{font-family:var(--fb);background:var(--bg);color:var(--text);min-height:100vh;overflow-x:hidden}
body::before{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;
  background:url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.03'/%3E%3C/svg%3E")}

/* ════════════════════════════════════════════════
   APP SHELL
════════════════════════════════════════════════ */
.app{position:relative;z-index:1;display:flex;min-height:100vh}

/* ── Sidebar ── */
.sidebar{width:256px;flex-shrink:0;background:var(--surface);border-right:1px solid var(--border);
  display:flex;flex-direction:column;padding:26px 0;position:sticky;top:0;height:100vh;overflow-y:auto}
.logo{padding:0 22px 24px;border-bottom:1px solid var(--border)}
.logo-row{display:flex;align-items:center;gap:10px;font-family:var(--fh);font-size:1.2rem;font-weight:800;letter-spacing:-.02em}
.logo-box{width:34px;height:34px;border-radius:9px;background:linear-gradient(135deg,var(--accent),var(--accent2));display:grid;place-items:center;font-size:.9rem}
.logo-sub{font-size:.67rem;color:var(--muted);margin-top:4px;text-transform:uppercase;letter-spacing:.1em}

/* Student info card */
.stu-card{margin:14px 12px;background:linear-gradient(135deg,rgba(79,124,255,.08),rgba(123,94,167,.06));
  border:1px solid var(--border2);border-radius:14px;padding:16px}
.stu-avatar{width:42px;height:42px;border-radius:50%;margin-bottom:10px;
  background:linear-gradient(135deg,var(--accent),var(--accent2));
  display:grid;place-items:center;font-family:var(--fh);font-weight:800;font-size:1.1rem}
.stu-name{font-family:var(--fh);font-size:.94rem;font-weight:700}
.stu-id{font-size:.72rem;color:var(--muted);margin-top:2px}
.stu-dept{font-size:.7rem;color:var(--accent);margin-top:6px;display:inline-block;
  padding:3px 9px;background:rgba(79,124,255,.1);border-radius:20px}

/* Nav */
.nav{padding:6px 10px;flex:1}
.ni{display:flex;align-items:center;gap:10px;padding:10px 13px;border-radius:10px;margin-bottom:3px;
  cursor:pointer;color:var(--muted);font-size:.87rem;font-weight:500;border:none;background:none;width:100%;text-align:left;transition:all .2s}
.ni:hover{background:var(--surface2);color:var(--text)}
.ni.active{background:rgba(79,124,255,.12);color:var(--accent)}
.ni-ico{width:18px;text-align:center;font-size:.92rem}
.nbadge{margin-left:auto;background:var(--danger);color:#fff;font-size:.63rem;font-weight:700;padding:2px 6px;border-radius:20px}

.sb-foot{padding:14px 22px;border-top:1px solid var(--border)}
.logout-btn{display:flex;align-items:center;gap:9px;width:100%;padding:10px 13px;border-radius:10px;
  border:1px solid rgba(255,95,109,.22);background:rgba(255,95,109,.05);
  color:var(--danger);font-size:.85rem;font-weight:600;cursor:pointer;transition:all .2s;font-family:var(--fb)}
.logout-btn:hover{background:rgba(255,95,109,.12);border-color:var(--danger)}

/* ── Main ── */
.main{flex:1;display:flex;flex-direction:column;min-width:0}
.topbar{display:flex;align-items:center;justify-content:space-between;padding:17px 30px;
  border-bottom:1px solid var(--border);background:var(--surface);position:sticky;top:0;z-index:10}
.tb-title{font-family:var(--fh);font-size:1.08rem;font-weight:700}
.tb-date{font-size:.78rem;color:var(--muted)}

.content{flex:1;padding:30px}
.page{display:none;animation:fi .3s ease}
.page.active{display:block}
@keyframes fi{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}

.ph{margin-bottom:24px}
.ph h1{font-family:var(--fh);font-size:1.7rem;font-weight:800}
.ph p{color:var(--muted);margin-top:5px;font-size:.86rem}

/* ── Stats ── */
.sg{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:26px}
.sc{background:var(--surface);border:1px solid var(--border);border-radius:var(--rl);padding:20px;
  position:relative;overflow:hidden;transition:transform .2s,box-shadow .2s}
.sc:hover{transform:translateY(-3px);box-shadow:var(--glow)}
.sc::after{content:'';position:absolute;top:-28px;right:-28px;width:96px;height:96px;border-radius:50%;background:var(--cc,var(--accent));opacity:.06}
.sc-ico{font-size:1.4rem;margin-bottom:10px}
.sc-val{font-family:var(--fh);font-size:1.85rem;font-weight:800;line-height:1}
.sc-lbl{color:var(--muted);font-size:.7rem;margin-top:5px;text-transform:uppercase;letter-spacing:.07em}

/* ── Card ── */
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--rl);overflow:hidden;margin-bottom:20px}
.ch{padding:17px 22px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.ct{font-family:var(--fh);font-size:.93rem;font-weight:700}
.cb{padding:22px}

/* ── Item grid ── */
.ig{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:14px}
.ic{background:var(--surface2);border:1px solid var(--border2);border-radius:14px;padding:18px;
  transition:all .2s;position:relative;overflow:hidden}
.ic:hover{border-color:var(--accent);transform:translateY(-2px);box-shadow:var(--glow)}
.ic::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;
  background:linear-gradient(90deg,var(--accent),var(--accent2));opacity:0;transition:opacity .2s}
.ic:hover::before{opacity:1}
.ic-cat{font-size:.66rem;text-transform:uppercase;letter-spacing:.1em;color:var(--muted);margin-bottom:7px}
.ic-name{font-family:var(--fh);font-size:.98rem;font-weight:700;margin-bottom:5px}
.ic-desc{font-size:.76rem;color:var(--muted);line-height:1.5;margin-bottom:12px;min-height:34px}
.ic-qty{display:inline-flex;align-items:center;gap:5px;font-size:.75rem;font-weight:600;
  padding:3px 10px;border-radius:20px;margin-bottom:12px}
.avail{background:rgba(46,204,113,.1);color:var(--success)}
.none{background:rgba(255,95,109,.1);color:var(--danger)}
.ic-btn{width:100%;padding:9px;border-radius:9px;border:none;cursor:pointer;
  background:rgba(79,124,255,.13);color:var(--accent);font-family:var(--fb);
  font-size:.8rem;font-weight:600;transition:all .2s}
.ic-btn:hover:not(:disabled){background:var(--accent);color:#fff}
.ic-btn:disabled{opacity:.3;cursor:not-allowed}

/* ── Form controls ── */
.fgrid{display:grid;grid-template-columns:1fr 1fr;gap:15px}
.fg{display:flex;flex-direction:column;gap:6px}
.fg.full{grid-column:1/-1}
.flabel{font-size:.71rem;text-transform:uppercase;letter-spacing:.09em;color:var(--muted);font-weight:600}
.form-control{width:100%;background:var(--surface2);border:1px solid var(--border2);color:var(--text);
  border-radius:10px;padding:11px 14px;font-family:var(--fb);font-size:.9rem;outline:none;
  transition:border-color .2s,box-shadow .2s}
.form-control:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(79,124,255,.12)}
.form-control::placeholder{color:var(--muted)}
textarea.form-control{min-height:94px;resize:vertical}
select.form-control{cursor:pointer}

/* ── Buttons ── */
.btn{display:inline-flex;align-items:center;gap:7px;padding:10px 20px;border-radius:10px;border:none;
  cursor:pointer;font-family:var(--fb);font-size:.87rem;font-weight:600;transition:all .2s;white-space:nowrap}
.btn-p{background:var(--accent);color:#fff}
.btn-p:hover{background:#3a66f5;box-shadow:0 4px 20px rgba(79,124,255,.4)}
.btn-g{background:var(--surface2);color:var(--text);border:1px solid var(--border2)}
.btn-g:hover{border-color:var(--accent);color:var(--accent)}
.btn-sm{padding:7px 13px;font-size:.78rem;border-radius:8px}

/* ── Request rows ── */
.rl{display:flex;flex-direction:column;gap:10px}
.rrow{background:var(--surface2);border:1px solid var(--border2);border-radius:12px;
  padding:14px 17px;display:flex;align-items:center;gap:13px;transition:border-color .2s}
.rrow:hover{border-color:var(--border2)}
.rrow-ico{width:38px;height:38px;border-radius:10px;background:rgba(79,124,255,.1);
  display:grid;place-items:center;font-size:1.1rem;flex-shrink:0}
.rrow-info{flex:1;min-width:0}
.rrow-name{font-weight:600;font-size:.88rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.rrow-meta{font-size:.74rem;color:var(--muted);margin-top:3px}
.rrow-right{display:flex;flex-direction:column;align-items:flex-end;gap:5px;flex-shrink:0}
.rrow-date{font-size:.7rem;color:var(--muted)}

/* ── Badges ── */
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:20px;font-size:.7rem;font-weight:600}
.badge::before{content:'';width:5px;height:5px;border-radius:50%;background:currentColor}
.b-pending{background:rgba(240,165,0,.12);color:var(--warning)}
.b-approved{background:rgba(46,204,113,.12);color:var(--success)}
.b-rejected{background:rgba(255,95,109,.12);color:var(--danger)}
.b-returned{background:rgba(52,152,219,.12);color:var(--info)}
.b-in_progress{background:rgba(123,94,167,.12);color:var(--accent2)}
.b-completed{background:rgba(46,204,113,.12);color:var(--success)}
.b-low{background:rgba(52,152,219,.1);color:var(--info)}
.b-normal{background:rgba(240,165,0,.1);color:var(--warning)}
.b-high{background:rgba(255,95,109,.1);color:var(--danger)}
.b-urgent{background:rgba(255,95,109,.17);color:var(--danger);border:1px solid rgba(255,95,109,.38)}

/* ── Empty ── */
.empty{text-align:center;padding:42px 20px;color:var(--muted)}
.empty-ico{font-size:2.6rem;margin-bottom:13px;opacity:.4}
.empty h3{font-family:var(--fh);font-size:.98rem;color:var(--text);margin-bottom:5px}
.empty p{font-size:.8rem}

/* ── Alert ── */
.alert{padding:11px 15px;border-radius:10px;margin-bottom:16px;font-size:.84rem;display:flex;align-items:center;gap:8px}
.a-err{background:rgba(255,95,109,.07);border:1px solid rgba(255,95,109,.27);color:var(--danger)}
.a-ok{background:rgba(46,204,113,.07);border:1px solid rgba(46,204,113,.27);color:var(--success)}

/* ── Toast ── */
#tc{position:fixed;bottom:22px;right:22px;z-index:9999;display:flex;flex-direction:column;gap:8px}
.toast{background:var(--surface2);border:1px solid var(--border2);border-radius:12px;
  padding:12px 18px;display:flex;align-items:center;gap:10px;min-width:230px;
  box-shadow:var(--sh);animation:si .3s ease}
.toast.ok{border-left:3px solid var(--success)}
.toast.er{border-left:3px solid var(--danger)}
.toast-msg{font-size:.84rem}
@keyframes si{from{opacity:0;transform:translateX(14px)}to{opacity:1;transform:none}}

/* ── Activity ── */
.ai{display:flex;gap:11px;align-items:flex-start;padding:12px 0;border-bottom:1px solid var(--border)}
.ai:last-child{border-bottom:none}
.ai-dot{width:9px;height:9px;border-radius:50%;margin-top:5px;flex-shrink:0}
.ai-txt{font-size:.84rem;line-height:1.5}
.ai-time{font-size:.71rem;color:var(--muted);margin-top:2px}

/* ── Quick actions ── */
.qa{display:grid;grid-template-columns:1fr 1fr;gap:13px;margin-bottom:24px}
.qac{background:var(--surface2);border:1px solid var(--border2);border-radius:var(--rl);
  padding:20px;cursor:pointer;transition:all .2s;text-align:left;border-left:3px solid transparent}
.qac:hover{border-left-color:var(--accent);background:rgba(79,124,255,.05);transform:translateY(-2px)}
.qac-ico{font-size:1.8rem;margin-bottom:9px}
.qac-t{font-family:var(--fh);font-weight:700;font-size:.88rem;margin-bottom:3px}
.qac-d{font-size:.76rem;color:var(--muted)}

/* ── Modal ── */
.mo{display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.74);
  backdrop-filter:blur(4px);place-items:center}
.mo.open{display:grid}
.modal{background:var(--surface);border:1px solid var(--border2);border-radius:var(--rl);
  width:90%;max-width:510px;box-shadow:var(--sh);animation:mIn .25s ease}
.mh{padding:19px 25px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.mt{font-family:var(--fh);font-size:1.03rem;font-weight:700}
.mc{background:none;border:none;color:var(--muted);font-size:1.3rem;cursor:pointer;line-height:1}
.mc:hover{color:var(--text)}
.mb{padding:24px}
.mf{padding:17px 25px;border-top:1px solid var(--border);display:flex;gap:10px;justify-content:flex-end}
@keyframes mIn{from{opacity:0;transform:scale(.95) translateY(-8px)}to{opacity:1;transform:none}}

/* ── Responsive ── */
@media(max-width:920px){.sg{grid-template-columns:1fr 1fr}.sidebar{width:222px}.content{padding:20px}}
@media(max-width:650px){.app{flex-direction:column}.sidebar{width:100%;height:auto;position:relative}.sg{grid-template-columns:1fr 1fr}.fgrid{grid-template-columns:1fr}.qa{grid-template-columns:1fr}.ig{grid-template-columns:1fr 1fr}.type-grid{grid-template-columns:1fr 1fr}}

/* ── Dept need type selector ── */
.type-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
@media(max-width:920px){.type-grid{grid-template-columns:1fr 1fr}}
.type-card{background:var(--surface);border:2px solid var(--border);border-radius:14px;
  padding:18px;cursor:pointer;transition:all .2s;text-align:left}
.type-card:hover{border-color:var(--accent);background:rgba(79,124,255,.04)}
.type-card.active{border-color:var(--accent);background:rgba(79,124,255,.08);box-shadow:0 0 0 3px rgba(79,124,255,.1)}
.type-ico{font-size:1.7rem;margin-bottom:9px}
.type-t{font-family:var(--fh);font-size:.88rem;font-weight:700;margin-bottom:5px}
.type-d{font-size:.74rem;color:var(--muted);line-height:1.5}
.context-note{font-size:.8rem;color:var(--muted);background:var(--surface2);border-left:3px solid var(--accent);
  padding:10px 14px;border-radius:0 8px 8px 0;margin-top:14px;display:none}
.context-note.show{display:block}

/* ── Extra badge statuses ── */
.b-dept_approved{background:rgba(46,204,113,.12);color:var(--success)}
.b-dept_rejected{background:rgba(255,95,109,.12);color:var(--danger)}
.b-purchase_ordered{background:rgba(52,152,219,.12);color:var(--info)}
.b-fulfilled{background:rgba(46,204,113,.18);color:var(--success)}
.b-missing{background:rgba(240,165,0,.1);color:var(--warning)}
.b-lost{background:rgba(255,95,109,.1);color:var(--danger)}
.b-consumable_restock{background:rgba(52,152,219,.1);color:var(--info)}
.b-new_requirement{background:rgba(123,94,167,.1);color:var(--accent2)}</style>
</head>
<body>

<!-- ══════════════════ DASHBOARD ══════════════════ -->
<div class="app">

  <!-- SIDEBAR -->
  <aside class="sidebar">
    <div class="logo">
      <div class="logo-row">
        <div class="logo-box">🎓</div> NLUPortal
      </div>
      <div class="logo-sub">Stock and Inventory Management System</div>
    </div>

    <!-- Student profile -->
    <div class="stu-card">
      <div class="stu-avatar"><?= strtoupper(substr($student['name'],0,1)) ?></div>
      <div class="stu-name"><?= htmlspecialchars($student['name']) ?></div>
      <div class="stu-id"><?= htmlspecialchars($student['student_id']) ?></div>
      <div class="stu-dept"><?= htmlspecialchars($student['department'] ?? 'Student') ?></div>
    </div>

    <nav class="nav">
      <button class="ni active" onclick="nav('dashboard',this)">
        <span class="ni-ico">🏠</span> Dashboard
      </button>
      <button class="ni" onclick="nav('report-need',this)">
        <span class="ni-ico">📋</span> Report Dept Need
      </button>
      <button class="ni" onclick="nav('repair-request',this)">
        <span class="ni-ico">🔧</span> Report Damage
      </button>
      <button class="ni" onclick="nav('my-requests',this)">
        <span class="ni-ico">📂</span> My Reports
        <span class="nbadge" id="pbadge" style="display:none">0</span>
      </button>
      <button class="ni" onclick="nav('my-repairs',this)">
        <span class="ni-ico">🛠️</span> My Repairs
        <span class="nbadge" id="rbadge" style="display:none">0</span>
      </button>
    </nav>

    <div class="sb-foot">
      <form method="POST">
        <input type="hidden" name="action" value="logout">
        <button class="logout-btn" type="submit">🚪 Sign Out</button>
      </form>
    </div>
  </aside>

  <!-- MAIN -->
  <main class="main">
    <header class="topbar">
      <div class="tb-title" id="tbtitle">Dashboard</div>
      <div class="tb-date" id="tbdate"></div>
    </header>

    <div class="content">

      <!-- ─── DASHBOARD ──────────────────────────── -->
      <div class="page active" id="page-dashboard">
        <div class="ph">
          <h1>Welcome back, <?= htmlspecialchars(explode(' ',$student['name'])[0]) ?> 👋</h1>
          <p>Department: <strong><?= htmlspecialchars($student['department'] ?? '—') ?></strong> &mdash; Report missing, lost, or low-stock items on behalf of your department.</p>
        </div>

        <div class="sg">
          <div class="sc" style="--cc:var(--warning)">
            <div class="sc-ico">⏳</div>
            <div class="sc-val" id="s1">—</div>
            <div class="sc-lbl">Pending Reports</div>
          </div>
          <div class="sc" style="--cc:var(--success)">
            <div class="sc-ico">✅</div>
            <div class="sc-val" id="s2">—</div>
            <div class="sc-lbl">Dept Approved</div>
          </div>
          <div class="sc" style="--cc:var(--info)">
            <div class="sc-ico">🛒</div>
            <div class="sc-val" id="s3">—</div>
            <div class="sc-lbl">Purchase Ordered</div>
          </div>
          <div class="sc" style="--cc:var(--accent2)">
            <div class="sc-ico">🔧</div>
            <div class="sc-val" id="s4">—</div>
            <div class="sc-lbl">Active Repairs</div>
          </div>
        </div>

        <div class="qa">
          <div class="qac" onclick="nav('report-need',null)">
            <div class="qac-ico">📋</div>
            <div class="qac-t">Report a Department Need</div>
            <div class="qac-d">Flag missing, lost, or low consumable stock for your department.</div>
          </div>
          <div class="qac" onclick="nav('repair-request',null)">
            <div class="qac-ico">🔨</div>
            <div class="qac-t">Report Damaged Equipment</div>
            <div class="qac-d">Submit a repair request for damaged department property.</div>
          </div>
        </div>

        <div class="card">
          <div class="ch"><span class="ct">📜 Recent Activity</span></div>
          <div class="cb" id="actFeed">
            <div class="empty"><div class="empty-ico">📭</div><h3>No activity yet</h3><p>Your requests will appear here.</p></div>
          </div>
        </div>
      </div>

      <!-- ─── REPORT DEPT NEED ───────────────────── -->
      <div class="page" id="page-report-need">
        <div class="ph">
          <h1>📋 Report a Department Need</h1>
          <p>Report missing, lost, or low-stock items in your department. Your report goes to the dept admin for review and approval before a purchase is made.</p>
        </div>

        <!-- Type selector cards -->
        <div class="type-grid" id="typeGrid">
          <div class="type-card active" data-type="missing" onclick="selectType(this,'missing')">
            <div class="type-ico">🔍</div>
            <div class="type-t">Missing Item</div>
            <div class="type-d">An item that should be in the department but cannot be found.</div>
          </div>
          <div class="type-card" data-type="lost" onclick="selectType(this,'lost')">
            <div class="type-ico">❌</div>
            <div class="type-t">Lost / Damaged Beyond Repair</div>
            <div class="type-d">Item confirmed lost or too damaged to use — needs replacement.</div>
          </div>
          <div class="type-card" data-type="consumable_restock" onclick="selectType(this,'consumable_restock')">
            <div class="type-ico">🔄</div>
            <div class="type-t">Consumable Restock</div>
            <div class="type-d">Consumables running low and need to be refilled (paper, chemicals, toner, etc.).</div>
          </div>
          <div class="type-card" data-type="new_requirement" onclick="selectType(this,'new_requirement')">
            <div class="type-ico">✨</div>
            <div class="type-t">New Requirement</div>
            <div class="type-d">Department needs a new item not previously in inventory.</div>
          </div>
        </div>

        <div class="card" style="margin-top:20px">
          <div class="ch">
            <span class="ct" id="formCardTitle">🔍 Report Missing Item</span>
          </div>
          <div class="cb">
            <div id="needAlert"></div>
            <div class="fgrid">
              <div class="fg full">
                <label class="flabel">Item / Equipment Name <span style="color:var(--danger)">*</span></label>
                <input class="form-control" type="text" id="nItemName" placeholder="e.g. Oscilloscope, A4 Paper Ream, Whiteboard Marker…">
              </div>
              <div class="fg">
                <label class="flabel">Category</label>
                <select class="form-control" id="nCategory">
                  <option value="">— Select category —</option>
                  <option>Electronics</option>
                  <option>Lab Equipment</option>
                  <option>Stationery</option>
                  <option>Furniture</option>
                  <option>Consumables</option>
                  <option>Safety Equipment</option>
                  <option>Software / License</option>
                  <option>Other</option>
                </select>
              </div>
              <div class="fg">
                <label class="flabel">Quantity Needed <span style="color:var(--danger)">*</span></label>
                <input class="form-control" type="number" id="nQty" min="1" value="1">
              </div>
              <div class="fg full">
                <label class="flabel">Description / Reason <span style="color:var(--danger)">*</span></label>
                <textarea class="form-control" id="nDesc" placeholder="Explain the situation — when it went missing, how it was used, why it's needed, etc."></textarea>
              </div>
              <div class="fg">
                <label class="flabel">Urgency Level</label>
                <select class="form-control" id="nUrgency">
                  <option value="low">🟢 Low — Can wait a few weeks</option>
                  <option value="normal" selected>🟡 Normal — Needed within a week</option>
                  <option value="high">🟠 High — Needed in 1–2 days</option>
                  <option value="urgent">🔴 Urgent — Blocking lab / classes now</option>
                </select>
              </div>
            </div>
            <div id="contextNote" class="context-note"></div>
            <div style="margin-top:20px;display:flex;gap:10px">
              <button class="btn btn-p" onclick="submitDeptRequest()">📤 Submit Report</button>
              <button class="btn btn-g" onclick="clearNeedForm()">✕ Clear</button>
            </div>
          </div>
        </div>
      </div>

      <!-- ─── REPAIR REQUEST ─────────────────────── -->
      <div class="page" id="page-repair-request">
        <div class="ph">
          <h1>🔧 Report Damaged Item</h1>
          <p>Fill in the form below to submit a repair request.</p>
        </div>
        <div class="card">
          <div class="ch"><span class="ct">New Repair Request</span></div>
          <div class="cb">
            <div id="repAlert"></div>
            <div class="fgrid">
              <div class="fg full">
                <label class="flabel">Item / Equipment Name</label>
                <input class="form-control" type="text" id="rItemName" placeholder="e.g. Oscilloscope #3, Lab Laptop B…">
              </div>
              <div class="fg full">
                <label class="flabel">Damage Description</label>
                <textarea class="form-control" id="rDmgDesc" placeholder="Describe what is broken and how it happened…"></textarea>
              </div>
              <div class="fg">
                <label class="flabel">Priority Level</label>
                <select class="form-control" id="rPriority">
                  <option value="low">🟢 Low — Minor cosmetic damage</option>
                  <option value="normal" selected>🟡 Normal — Partially functional</option>
                  <option value="high">🟠 High — Mostly non-functional</option>
                  <option value="urgent">🔴 Urgent — Safety hazard / completely broken</option>
                </select>
              </div>
            </div>
            <div style="margin-top:20px;display:flex;gap:10px">
              <button class="btn btn-p" onclick="submitRepair()">📤 Submit Request</button>
              <button class="btn btn-g" onclick="clearRepair()">✕ Clear</button>
            </div>
          </div>
        </div>
      </div>

      <!-- ─── MY REPORTS ────────────────────────── -->
      <div class="page" id="page-my-requests">
        <div class="ph">
          <h1>📂 My Department Reports</h1>
          <p>Track all the department needs you've reported and their approval status.</p>
        </div>
        <div class="card">
          <div class="ch">
            <span class="ct">Report History</span>
            <button class="btn btn-sm btn-g" onclick="loadMyRequests()">↻ Refresh</button>
          </div>
          <div class="cb" id="myReqList">
            <div class="empty"><div class="empty-ico">⏳</div><h3>Loading…</h3></div>
          </div>
        </div>
      </div>

      <!-- ─── MY REPAIRS ─────────────────────────── -->
      <div class="page" id="page-my-repairs">
        <div class="ph">
          <h1>🛠️ My Repair Requests</h1>
          <p>Track the progress of your submitted repairs.</p>
        </div>
        <div class="card">
          <div class="ch">
            <span class="ct">Repair History</span>
            <button class="btn btn-sm btn-g" onclick="loadMyRepairs()">↻ Refresh</button>
          </div>
          <div class="cb" id="myRepList">
            <div class="empty"><div class="empty-ico">⏳</div><h3>Loading…</h3></div>
          </div>
        </div>
      </div>

    </div><!-- /content -->
  </main>
</div><!-- /app -->

<div id="tc"></div>

<script>
let selType='missing';

const TYPE_META = {
  missing:           {title:'🔍 Report Missing Item',       note:'Tip: Note the last known location and when it was last seen.'},
  lost:              {title:'❌ Report Lost / Irreparable Item', note:'Tip: Include how the item was lost or damaged, and any supporting context.'},
  consumable_restock:{title:'🔄 Request Consumable Restock', note:'Tip: Specify current remaining stock level and expected weekly usage.'},
  new_requirement:   {title:'✨ Request New Item for Dept',  note:'Tip: Explain the academic/lab need and if any current item could substitute.'},
};

document.addEventListener('DOMContentLoaded',async()=>{
  document.getElementById('tbdate').textContent=new Date().toLocaleDateString('en-US',{weekday:'long',year:'numeric',month:'long',day:'numeric'});
  await Promise.all([loadStats(),loadActivityFeed()]);
});

async function api(a,p={},b=null){
  const q=new URLSearchParams({api:a,...p}).toString();
  const o=b?{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(b)}:{method:'GET'};
  return (await fetch('?'+q,o)).json();
}

function nav(page,btn){
  document.querySelectorAll('.page').forEach(p=>p.classList.remove('active'));
  document.querySelectorAll('.ni').forEach(n=>n.classList.remove('active'));
  document.getElementById('page-'+page).classList.add('active');
  if(btn)btn.classList.add('active');
  else document.querySelectorAll('.ni').forEach(n=>{if(n.getAttribute('onclick')?.includes(page))n.classList.add('active')});
  const T={'dashboard':'Dashboard','report-need':'Report Dept Need','repair-request':'Report Damage','my-requests':'My Reports','my-repairs':'My Repairs'};
  document.getElementById('tbtitle').textContent=T[page]||page;
  if(page==='my-requests')loadMyRequests();
  if(page==='my-repairs') loadMyRepairs();
}

async function loadStats(){
  const s=await api('get_stats');
  document.getElementById('s1').textContent=s.pending_dept_requests??0;
  document.getElementById('s2').textContent=s.approved_dept_requests??0;
  document.getElementById('s3').textContent=s.ordered_dept_requests??0;
  document.getElementById('s4').textContent=s.active_repairs??0;
  const pi=(+s.pending_dept_requests)+(+s.approved_dept_requests);
  const ri=(+s.pending_repairs)+(+s.active_repairs);
  const pb=document.getElementById('pbadge'),rb=document.getElementById('rbadge');
  pb.style.display=pi>0?'':'none'; pb.textContent=pi;
  rb.style.display=ri>0?'':'none'; rb.textContent=ri;
}

function selectType(el, type){
  selType=type;
  document.querySelectorAll('.type-card').forEach(c=>c.classList.remove('active'));
  el.classList.add('active');
  const m=TYPE_META[type];
  document.getElementById('formCardTitle').textContent=m.title;
  const cn=document.getElementById('contextNote');
  cn.textContent=m.note; cn.classList.add('show');
}

async function submitDeptRequest(){
  const nm=document.getElementById('nItemName').value.trim();
  const cat=document.getElementById('nCategory').value;
  const qty=parseInt(document.getElementById('nQty').value)||1;
  const desc=document.getElementById('nDesc').value.trim();
  const urgency=document.getElementById('nUrgency').value;
  const al=document.getElementById('needAlert');
  if(!nm)return showAlert(al,'a-err','⚠️ Please enter the item name.');
  if(!desc)return showAlert(al,'a-err','⚠️ Please provide a description/reason.');
  const r=await api('submit_dept_request',{},{
    request_type:selType, item_name:nm, item_category:cat,
    quantity_needed:qty, description:desc, urgency
  });
  if(r.success){
    showAlert(al,'a-ok','✅ Report submitted! Your department admin will review it shortly.');
    clearNeedForm();
    toast('ok','Department need reported!');
    loadStats(); loadActivityFeed();
  } else showAlert(al,'a-err','❌ '+(r.error||'Something went wrong.'));
}

function clearNeedForm(){
  document.getElementById('nItemName').value='';
  document.getElementById('nCategory').value='';
  document.getElementById('nQty').value=1;
  document.getElementById('nDesc').value='';
  document.getElementById('nUrgency').value='normal';
}

async function submitRepair(){
  const nm=document.getElementById('rItemName').value.trim();
  const dd=document.getElementById('rDmgDesc').value.trim();
  const pr=document.getElementById('rPriority').value;
  const al=document.getElementById('repAlert');
  if(!nm)return showAlert(al,'a-err','⚠️ Please enter the item name.');
  if(!dd)return showAlert(al,'a-err','⚠️ Please describe the damage.');
  const r=await api('submit_repair_request',{},{item_name:nm,damage_desc:dd,priority:pr});
  if(r.success){showAlert(al,'a-ok','✅ Repair request submitted!');clearRepair();toast('ok','Repair request submitted!');loadStats();loadActivityFeed();}
  else showAlert(al,'a-err','❌ '+(r.error||'Something went wrong.'));
}
function clearRepair(){document.getElementById('rItemName').value='';document.getElementById('rDmgDesc').value='';document.getElementById('rPriority').value='normal';}

async function loadActivityFeed(){
  const[req,rep]=await Promise.all([api('get_dept_requests'),api('get_repair_requests')]);
  const el=document.getElementById('actFeed');
  const typeLabel={missing:'Missing item',lost:'Lost item',consumable_restock:'Restock',new_requirement:'New requirement'};
  const ev=[
    ...req.map(r=>({t:r.requested_at,txt:`<b>${typeLabel[r.request_type]||r.request_type}</b>: ${esc(r.item_name)} (×${r.quantity_needed})`,s:r.status})),
    ...rep.map(r=>({t:r.submitted_at,txt:`Repair report for <b>${esc(r.item_name)}</b>`,s:r.status})),
  ].sort((a,b)=>new Date(b.t)-new Date(a.t)).slice(0,8);
  if(!ev.length){el.innerHTML=`<div class="empty"><div class="empty-ico">📭</div><h3>No activity yet</h3><p>Submit a report to see activity here.</p></div>`;return;}
  el.innerHTML=ev.map(e=>`
    <div class="ai">
      <div class="ai-dot" style="background:${sc(e.s)}"></div>
      <div>
        <div class="ai-txt">${e.txt} <span class="badge b-${e.s}">${fmtStatus(e.s)}</span></div>
        <div class="ai-time">${fmt(e.t)}</div>
      </div>
    </div>`).join('');
}

async function loadMyRequests(){
  const el=document.getElementById('myReqList');
  el.innerHTML=`<div class="empty"><div class="empty-ico">⏳</div><h3>Loading…</h3></div>`;
  const d=await api('get_dept_requests');
  if(!d.length){el.innerHTML=`<div class="empty"><div class="empty-ico">📂</div><h3>No reports yet</h3><p>Use "Report Dept Need" to flag a department shortage.</p></div>`;return;}
  const typeIco={missing:'🔍',lost:'❌',consumable_restock:'🔄',new_requirement:'✨'};
  const typeLabel={missing:'Missing',lost:'Lost',consumable_restock:'Restock',new_requirement:'New Req.'};
  el.innerHTML=`<div class="rl">`+d.map(r=>`
    <div class="rrow">
      <div class="rrow-ico">${typeIco[r.request_type]||'📋'}</div>
      <div class="rrow-info">
        <div class="rrow-name">${esc(r.item_name)}</div>
        <div class="rrow-meta">
          <span class="badge b-${r.request_type}" style="margin-right:5px">${typeLabel[r.request_type]||r.request_type}</span>
          Qty: ${r.quantity_needed}${r.item_category?' · '+esc(r.item_category):''}
          · ${esc((r.description||'').substring(0,55))}${r.description?.length>55?'…':''}
        </div>
        ${r.dept_admin_note?`<div class="rrow-meta" style="margin-top:4px;color:var(--accent)">📝 Admin note: ${esc(r.dept_admin_note)}</div>`:''}
      </div>
      <div class="rrow-right">
        <span class="badge b-${r.status}">${fmtStatus(r.status)}</span>
        <span class="badge b-${r.urgency}" style="font-size:.65rem">${r.urgency}</span>
        <span class="rrow-date">${fmt(r.requested_at)}</span>
      </div>
    </div>`).join('')+`</div>`;
}

async function loadMyRepairs(){
  const el=document.getElementById('myRepList');
  el.innerHTML=`<div class="empty"><div class="empty-ico">⏳</div><h3>Loading…</h3></div>`;
  const d=await api('get_repair_requests');
  if(!d.length){el.innerHTML=`<div class="empty"><div class="empty-ico">🔧</div><h3>No repair requests yet</h3><p>Report a damaged item to get started.</p></div>`;return;}
  el.innerHTML=`<div class="rl">`+d.map(r=>`
    <div class="rrow">
      <div class="rrow-ico">🔧</div>
      <div class="rrow-info">
        <div class="rrow-name">${esc(r.item_name)}</div>
        <div class="rrow-meta">${esc(r.damage_desc.substring(0,80))}${r.damage_desc.length>80?'…':''}</div>
      </div>
      <div class="rrow-right">
        <div style="display:flex;gap:5px;flex-wrap:wrap;justify-content:flex-end">
          <span class="badge b-${r.priority}">${r.priority}</span>
          <span class="badge b-${r.status}">${r.status.replace('_',' ')}</span>
        </div>
        <span class="rrow-date">${fmt(r.submitted_at)}</span>
      </div>
    </div>`).join('')+`</div>`;
}

function fmtStatus(s){return(s||'').replace(/_/g,' ')}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;')}
function fmt(d){return new Date(d).toLocaleString('en-US',{month:'short',day:'numeric',year:'numeric',hour:'2-digit',minute:'2-digit'})}
function sc(s){return{pending:'#f0a500',dept_approved:'#2ecc71',dept_rejected:'#ff5f6d',purchase_ordered:'#3498db',fulfilled:'#2ecc71',in_progress:'#7b5ea7',completed:'#2ecc71'}[s]||'#4f7cff'}
function showAlert(el,cls,msg){el.innerHTML=`<div class="alert ${cls}">${msg}</div>`;setTimeout(()=>el.innerHTML='',6000)}
function toast(type,msg){const c=document.getElementById('tc');const t=document.createElement('div');t.className=`toast ${type}`;t.innerHTML=`<span>${type==='ok'?'✅':'❌'}</span><span class="toast-msg">${msg}</span>`;c.appendChild(t);setTimeout(()=>t.remove(),3500)}
</script>
</body>
</html>
