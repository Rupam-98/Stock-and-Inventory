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
        CREATE TABLE IF NOT EXISTS item_requests (
            id           SERIAL PRIMARY KEY,
            student_id   INTEGER REFERENCES students(id) ON DELETE CASCADE,
            item_id      INTEGER REFERENCES items(id)    ON DELETE CASCADE,
            quantity     INTEGER DEFAULT 1,
            purpose      TEXT,
            status       VARCHAR(20) DEFAULT 'pending' CHECK (status IN ('pending','approved','rejected','returned')),
            requested_at TIMESTAMP DEFAULT NOW(),
            updated_at   TIMESTAMP DEFAULT NOW()
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
// AUTH ACTIONS
// ============================================================
$loginError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    setupDatabase();

    if ($_POST['action'] === 'login') {
        $sid = trim($_POST['student_id'] ?? '');
        $pw  = trim($_POST['password']   ?? '');
        $stmt = getDB()->prepare("SELECT * FROM students WHERE student_id = ?");
        $stmt->execute([$sid]);
        $student = $stmt->fetch();
        if ($student && password_verify($pw, $student['password'])) {
            $_SESSION['student'] = $student;
            header('Location: '.$_SERVER['PHP_SELF']); exit;
        }
        $loginError = 'Invalid Student ID or password.';
    }

    if ($_POST['action'] === 'logout') {
        session_destroy();
        header('Location: '.$_SERVER['PHP_SELF']); exit;
    }
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
                echo json_encode($db->query("SELECT * FROM items ORDER BY name")->fetchAll());
                break;

            case 'get_item_requests':
                $s = $db->prepare("SELECT ir.*,i.name AS item_name,i.category
                    FROM item_requests ir JOIN items i ON i.id=ir.item_id
                    WHERE ir.student_id=? ORDER BY ir.requested_at DESC");
                $s->execute([$me['id']]); echo json_encode($s->fetchAll());
                break;

            case 'get_repair_requests':
                $s = $db->prepare("SELECT * FROM repair_requests WHERE student_id=? ORDER BY submitted_at DESC");
                $s->execute([$me['id']]); echo json_encode($s->fetchAll());
                break;

            case 'get_stats':
                $stats = [];
                foreach ([
                    'pending_item_requests'  => "SELECT COUNT(*) FROM item_requests   WHERE student_id=? AND status='pending'",
                    'approved_item_requests' => "SELECT COUNT(*) FROM item_requests   WHERE student_id=? AND status='approved'",
                    'pending_repairs'        => "SELECT COUNT(*) FROM repair_requests WHERE student_id=? AND status='pending'",
                    'active_repairs'         => "SELECT COUNT(*) FROM repair_requests WHERE student_id=? AND status='in_progress'",
                ] as $k=>$q) {
                    $s=$db->prepare($q); $s->execute([$me['id']]); $stats[$k]=$s->fetchColumn();
                }
                echo json_encode($stats);
                break;

            case 'submit_item_request':
                $d = json_decode(file_get_contents('php://input'), true);
                $db->prepare("INSERT INTO item_requests (student_id,item_id,quantity,purpose) VALUES (?,?,?,?)")
                   ->execute([$me['id'], intval($d['item_id']), intval($d['quantity']??1), trim($d['purpose']??'')]);
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
$setupError = null;
try { setupDatabase(); } catch (Exception $e) { $setupError = $e->getMessage(); }
$loggedIn = !empty($_SESSION['student']);
$student  = $_SESSION['student'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= $loggedIn ? 'Dashboard · '.htmlspecialchars($student['name']) : 'Sign In · UniPortal' ?></title>
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
   LOGIN
════════════════════════════════════════════════ */
.login-wrap{position:relative;z-index:1;min-height:100vh;display:grid;place-items:center;padding:24px;
  background:radial-gradient(ellipse 80% 60% at 50% 0%,rgba(79,124,255,.08) 0%,transparent 70%)}
.login-card{width:100%;max-width:430px;background:var(--surface);border:1px solid var(--border2);border-radius:24px;overflow:hidden;box-shadow:var(--sh)}
.login-top{
  background:linear-gradient(160deg,#0c1328 0%,#152055 55%,#0c1328 100%);
  padding:44px 40px 36px;text-align:center;position:relative;overflow:hidden
}
.login-top::before{content:'';position:absolute;inset:0;
  background:radial-gradient(circle at 50% -20%,rgba(79,124,255,.22) 0%,transparent 65%);pointer-events:none}
.login-orb{
  width:76px;height:76px;border-radius:22px;margin:0 auto 20px;position:relative;z-index:1;
  background:linear-gradient(135deg,var(--accent),var(--accent2));
  display:grid;place-items:center;font-size:2.2rem;box-shadow:0 10px 36px rgba(79,124,255,.45)
}
.login-top h1{font-family:var(--fh);font-size:1.8rem;font-weight:800;letter-spacing:-.025em;position:relative;z-index:1}
.login-top p{color:var(--muted);font-size:.85rem;margin-top:6px;position:relative;z-index:1}
.login-body{padding:34px 40px 40px}
.login-err{background:rgba(255,95,109,.07);border:1px solid rgba(255,95,109,.3);color:var(--danger);
  border-radius:10px;padding:12px 15px;font-size:.84rem;margin-bottom:20px;display:flex;align-items:center;gap:8px}
.fl{margin-bottom:18px}
.fl label{display:block;font-size:.72rem;text-transform:uppercase;letter-spacing:.1em;color:var(--muted);font-weight:600;margin-bottom:7px}
.fc{width:100%;background:var(--surface2);border:1px solid var(--border2);color:var(--text);
  border-radius:11px;padding:12px 15px;font-family:var(--fb);font-size:.92rem;outline:none;transition:border-color .2s,box-shadow .2s}
.fc:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(79,124,255,.13)}
.fc::placeholder{color:var(--muted)}
.btn-login{width:100%;padding:14px;border-radius:12px;border:none;cursor:pointer;
  background:linear-gradient(135deg,#5080ff,#3660f0);color:#fff;
  font-family:var(--fh);font-size:1rem;font-weight:700;letter-spacing:.01em;
  transition:all .2s;margin-top:4px;box-shadow:0 4px 20px rgba(79,124,255,.3)}
.btn-login:hover{transform:translateY(-2px);box-shadow:0 8px 30px rgba(79,124,255,.5)}
.login-hint{text-align:center;margin-top:18px;font-size:.78rem;color:var(--muted);line-height:1.7}
.login-hint b{color:var(--text);font-weight:600}

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
@media(max-width:650px){.app{flex-direction:column}.sidebar{width:100%;height:auto;position:relative}.sg{grid-template-columns:1fr 1fr}.fgrid{grid-template-columns:1fr}.qa{grid-template-columns:1fr}.ig{grid-template-columns:1fr 1fr}}
</style>
</head>
<body>

<?php if (!$loggedIn): ?>
<!-- ════════════════════ LOGIN ════════════════════ -->
<div class="login-wrap">
  <div class="login-card">
    <div class="login-top">
      <div class="login-orb">🎓</div>
      <h1>UniPortal</h1>
      <p>Student Resource Management System</p>
    </div>
    <div class="login-body">
      <?php if ($loginError): ?>
        <div class="login-err">⚠️ <?= htmlspecialchars($loginError) ?></div>
      <?php endif; ?>
      <?php if ($setupError): ?>
        <div class="login-err">🔌 Database Error: <?= htmlspecialchars($setupError) ?></div>
      <?php endif; ?>
      <form method="POST" autocomplete="on">
        <input type="hidden" name="action" value="login">
        <div class="fl">
          <label>Student ID</label>
          <input class="fc" type="text" name="student_id" placeholder="e.g. STU-001" autocomplete="username" required>
        </div>
        <div class="fl">
          <label>Password</label>
          <input class="fc" type="password" name="password" placeholder="••••••••" autocomplete="current-password" required>
        </div>
        <button class="btn-login" type="submit">Sign In →</button>
      </form>
      <div class="login-hint">
        Demo credentials<br>
        ID: <b>STU-001</b> &nbsp;/&nbsp; Password: <b>student123</b>
      </div>
    </div>
  </div>
</div>

<?php else: ?>
<!-- ══════════════════ DASHBOARD ══════════════════ -->
<div class="app">

  <!-- SIDEBAR -->
  <aside class="sidebar">
    <div class="logo">
      <div class="logo-row">
        <div class="logo-box">🎓</div> UniPortal
      </div>
      <div class="logo-sub">Resource System</div>
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
      <button class="ni" onclick="nav('request-item',this)">
        <span class="ni-ico">📦</span> Request Item
      </button>
      <button class="ni" onclick="nav('repair-request',this)">
        <span class="ni-ico">🔧</span> Report Damage
      </button>
      <button class="ni" onclick="nav('my-requests',this)">
        <span class="ni-ico">📋</span> My Requests
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
          <p>Here's a snapshot of your resource activity.</p>
        </div>

        <div class="sg">
          <div class="sc" style="--cc:var(--warning)">
            <div class="sc-ico">⏳</div>
            <div class="sc-val" id="s1">—</div>
            <div class="sc-lbl">Pending Requests</div>
          </div>
          <div class="sc" style="--cc:var(--success)">
            <div class="sc-ico">✅</div>
            <div class="sc-val" id="s2">—</div>
            <div class="sc-lbl">Approved</div>
          </div>
          <div class="sc" style="--cc:var(--danger)">
            <div class="sc-ico">🔧</div>
            <div class="sc-val" id="s3">—</div>
            <div class="sc-lbl">Pending Repairs</div>
          </div>
          <div class="sc" style="--cc:var(--accent2)">
            <div class="sc-ico">🛠️</div>
            <div class="sc-val" id="s4">—</div>
            <div class="sc-lbl">Active Repairs</div>
          </div>
        </div>

        <div class="qa">
          <div class="qac" onclick="nav('request-item',null)">
            <div class="qac-ico">📦</div>
            <div class="qac-t">Request an Item</div>
            <div class="qac-d">Borrow lab equipment, stationery, electronics & more.</div>
          </div>
          <div class="qac" onclick="nav('repair-request',null)">
            <div class="qac-ico">🔨</div>
            <div class="qac-t">Report Damage</div>
            <div class="qac-d">Submit a repair request for damaged university property.</div>
          </div>
        </div>

        <div class="card">
          <div class="ch"><span class="ct">📜 Recent Activity</span></div>
          <div class="cb" id="actFeed">
            <div class="empty"><div class="empty-ico">📭</div><h3>No activity yet</h3><p>Your requests will appear here.</p></div>
          </div>
        </div>
      </div>

      <!-- ─── REQUEST ITEM ───────────────────────── -->
      <div class="page" id="page-request-item">
        <div class="ph">
          <h1>📦 Request an Item</h1>
          <p>Click any item to submit a borrowing request.</p>
        </div>
        <div class="card">
          <div class="ch">
            <span class="ct">🗃️ Available Inventory</span>
            <button class="btn btn-sm btn-g" onclick="loadItems()">↻ Refresh</button>
          </div>
          <div class="cb">
            <div class="ig" id="igrid">
              <div class="empty"><div class="empty-ico">⏳</div><h3>Loading…</h3></div>
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

      <!-- ─── MY REQUESTS ────────────────────────── -->
      <div class="page" id="page-my-requests">
        <div class="ph">
          <h1>📋 My Item Requests</h1>
          <p>Track the status of your borrowing requests.</p>
        </div>
        <div class="card">
          <div class="ch">
            <span class="ct">Request History</span>
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

<!-- ─── ITEM REQUEST MODAL ─────────────────────── -->
<div class="mo" id="reqModal">
  <div class="modal">
    <div class="mh">
      <span class="mt">📦 Request Item</span>
      <button class="mc" onclick="closeModal()">✕</button>
    </div>
    <div class="mb">
      <div id="mAlert"></div>
      <div style="background:var(--surface2);border:1px solid var(--border2);border-radius:10px;padding:14px 16px;margin-bottom:18px">
        <div style="font-size:.7rem;color:var(--muted);text-transform:uppercase;letter-spacing:.09em;margin-bottom:4px">Selected Item</div>
        <div style="font-family:var(--fh);font-size:1rem;font-weight:700" id="mItemName">—</div>
        <div style="font-size:.78rem;color:var(--muted);margin-top:2px" id="mItemMeta">—</div>
      </div>
      <div class="fgrid">
        <div class="fg">
          <label class="flabel">Quantity</label>
          <input class="form-control" type="number" id="mQty" min="1" max="10" value="1">
        </div>
        <div class="fg full">
          <label class="flabel">Purpose / Reason</label>
          <textarea class="form-control" id="mPurpose" placeholder="Why do you need this item?"></textarea>
        </div>
      </div>
    </div>
    <div class="mf">
      <button class="btn btn-g" onclick="closeModal()">Cancel</button>
      <button class="btn btn-p" onclick="submitItemReq()">🚀 Submit Request</button>
    </div>
  </div>
</div>

<?php endif; ?>

<div id="tc"></div>

<?php if ($loggedIn): ?>
<script>
let items=[], selItem=null;

document.addEventListener('DOMContentLoaded',async()=>{
  document.getElementById('tbdate').textContent=new Date().toLocaleDateString('en-US',{weekday:'long',year:'numeric',month:'long',day:'numeric'});
  await Promise.all([loadStats(),loadItems(),loadActivityFeed()]);
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
  const T={'dashboard':'Dashboard','request-item':'Request Item','repair-request':'Report Damage','my-requests':'My Requests','my-repairs':'My Repairs'};
  document.getElementById('tbtitle').textContent=T[page]||page;
  if(page==='my-requests')loadMyRequests();
  if(page==='my-repairs') loadMyRepairs();
}

async function loadStats(){
  const s=await api('get_stats');
  document.getElementById('s1').textContent=s.pending_item_requests??0;
  document.getElementById('s2').textContent=s.approved_item_requests??0;
  document.getElementById('s3').textContent=s.pending_repairs??0;
  document.getElementById('s4').textContent=s.active_repairs??0;
  const pi=+s.pending_item_requests+(+s.approved_item_requests);
  const ri=+s.pending_repairs+(+s.active_repairs);
  const pb=document.getElementById('pbadge'),rb=document.getElementById('rbadge');
  pb.style.display=pi>0?'':'none'; pb.textContent=pi;
  rb.style.display=ri>0?'':'none'; rb.textContent=ri;
}

async function loadItems(){
  items=await api('get_items');
  const g=document.getElementById('igrid');
  if(!items.length){g.innerHTML=`<div class="empty"><div class="empty-ico">📭</div><h3>No items</h3></div>`;return;}
  g.innerHTML=items.map(i=>`
    <div class="ic">
      <div class="ic-cat">${esc(i.category||'General')}</div>
      <div class="ic-name">${esc(i.name)}</div>
      <div class="ic-desc">${esc(i.description||'No description.')}</div>
      <div class="ic-qty ${i.quantity>0?'avail':'none'}">${i.quantity>0?'✓ '+i.quantity+' Available':'✗ Out of Stock'}</div>
      <button class="ic-btn" onclick="openModal(${i.id})" ${i.quantity<=0?'disabled':''}>
        ${i.quantity>0?'📥 Request This Item':'Out of Stock'}
      </button>
    </div>`).join('');
}

function openModal(id){
  selItem=id;
  const it=items.find(x=>x.id==id);
  document.getElementById('mItemName').textContent=it.name;
  document.getElementById('mItemMeta').textContent=(it.category||'')+(it.category?' · ':'')+it.quantity+' available';
  document.getElementById('mQty').value=1;
  document.getElementById('mPurpose').value='';
  document.getElementById('mAlert').innerHTML='';
  document.getElementById('reqModal').classList.add('open');
}
function closeModal(){document.getElementById('reqModal').classList.remove('open');selItem=null;}
document.getElementById('reqModal').addEventListener('click',e=>{if(e.target===e.currentTarget)closeModal();});

async function submitItemReq(){
  const qty=parseInt(document.getElementById('mQty').value);
  const pur=document.getElementById('mPurpose').value.trim();
  const al=document.getElementById('mAlert');
  if(!qty||qty<1)return showAlert(al,'a-err','⚠️ Quantity must be at least 1.');
  if(!pur)return showAlert(al,'a-err','⚠️ Please describe the purpose.');
  const r=await api('submit_item_request',{},{item_id:selItem,quantity:qty,purpose:pur});
  if(r.success){closeModal();toast('ok','Item request submitted!');loadStats();loadActivityFeed();}
  else showAlert(al,'a-err','❌ '+(r.error||'Something went wrong.'));
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
  const[req,rep]=await Promise.all([api('get_item_requests'),api('get_repair_requests')]);
  const el=document.getElementById('actFeed');
  const ev=[
    ...req.map(r=>({t:r.requested_at,txt:`Item request for <b>${esc(r.item_name)}</b> — ${r.quantity} unit(s)`,s:r.status})),
    ...rep.map(r=>({t:r.submitted_at,txt:`Repair request for <b>${esc(r.item_name)}</b>`,s:r.status})),
  ].sort((a,b)=>new Date(b.t)-new Date(a.t)).slice(0,8);
  if(!ev.length){el.innerHTML=`<div class="empty"><div class="empty-ico">📭</div><h3>No activity yet</h3><p>Submit a request to see activity.</p></div>`;return;}
  el.innerHTML=ev.map(e=>`
    <div class="ai">
      <div class="ai-dot" style="background:${sc(e.s)}"></div>
      <div>
        <div class="ai-txt">${e.txt} <span class="badge b-${e.s}">${e.s.replace('_',' ')}</span></div>
        <div class="ai-time">${fmt(e.t)}</div>
      </div>
    </div>`).join('');
}

async function loadMyRequests(){
  const el=document.getElementById('myReqList');
  el.innerHTML=`<div class="empty"><div class="empty-ico">⏳</div><h3>Loading…</h3></div>`;
  const d=await api('get_item_requests');
  if(!d.length){el.innerHTML=`<div class="empty"><div class="empty-ico">📂</div><h3>No requests yet</h3><p>Submit your first item request!</p></div>`;return;}
  el.innerHTML=`<div class="rl">`+d.map(r=>`
    <div class="rrow">
      <div class="rrow-ico">📦</div>
      <div class="rrow-info">
        <div class="rrow-name">${esc(r.item_name)}</div>
        <div class="rrow-meta">Qty: ${r.quantity} · ${esc(r.category||'—')} · ${esc((r.purpose||'No reason given').substring(0,60))}</div>
      </div>
      <div class="rrow-right">
        <span class="badge b-${r.status}">${r.status}</span>
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

function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;')}
function fmt(d){return new Date(d).toLocaleString('en-US',{month:'short',day:'numeric',year:'numeric',hour:'2-digit',minute:'2-digit'})}
function sc(s){return{pending:'#f0a500',approved:'#2ecc71',rejected:'#ff5f6d',returned:'#3498db',in_progress:'#7b5ea7',completed:'#2ecc71'}[s]||'#4f7cff'}
function showAlert(el,cls,msg){el.innerHTML=`<div class="alert ${cls}">${msg}</div>`;setTimeout(()=>el.innerHTML='',5000)}
function toast(type,msg){const c=document.getElementById('tc');const t=document.createElement('div');t.className=`toast ${type}`;t.innerHTML=`<span>${type==='ok'?'✅':'❌'}</span><span class="toast-msg">${msg}</span>`;c.appendChild(t);setTimeout(()=>t.remove(),3500)}
</script>
<?php endif; ?>
</body>
</html>
