<?php
// ============================================================
//  DATABASE CONFIG — update these values for your environment
// ============================================================
define('DB_HOST', 'localhost');
define('DB_PORT', '5432');
define('DB_NAME', 'school_db');
define('DB_USER', 'postgres');
define('DB_PASS', 'your_password');

// ============================================================
//  DATABASE HELPERS
// ============================================================
function getConnection() {
    $dsn = sprintf(
        'pgsql:host=%s;port=%s;dbname=%s',
        DB_HOST, DB_PORT, DB_NAME
    );
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        return null;
    }
}

function createTableIfNotExists($pdo) {
    $sql = "
        CREATE TABLE IF NOT EXISTS students (
            id             SERIAL PRIMARY KEY,
            first_name     VARCHAR(100)  NOT NULL,
            last_name      VARCHAR(100)  NOT NULL,
            email          VARCHAR(255)  NOT NULL UNIQUE,
            phone          VARCHAR(20),
            date_of_birth  DATE,
            gender         VARCHAR(10),
            course         VARCHAR(150),
            department     VARCHAR(150),
            year_of_study  SMALLINT,
            address        TEXT,
            city           VARCHAR(100),
            state          VARCHAR(100),
            pincode        VARCHAR(10),
            guardian_name  VARCHAR(150),
            guardian_phone VARCHAR(20),
            registered_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );
    ";
    $pdo->exec($sql);
}

// ============================================================
//  HANDLE AJAX / FORM ACTIONS
// ============================================================
header('Content-Type: text/html; charset=UTF-8');

$action  = $_POST['action'] ?? $_GET['action'] ?? '';
$message = '';
$msgType = '';

// ---- Register student ----
if ($action === 'register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $fields = [
        'first_name', 'last_name', 'email', 'phone',
        'date_of_birth', 'gender', 'course', 'department',
        'year_of_study', 'address', 'city', 'state', 'pincode',
        'guardian_name', 'guardian_phone'
    ];
    $data = [];
    foreach ($fields as $f) {
        $data[$f] = isset($_POST[$f]) ? trim($_POST[$f]) : null;
    }

    // Basic validation
    if (empty($data['first_name']) || empty($data['last_name']) || empty($data['email'])) {
        echo json_encode(['success' => false, 'message' => 'First name, last name and email are required.']);
        exit;
    }
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Invalid email address.']);
        exit;
    }

    $pdo = getConnection();
    if (!$pdo) {
        echo json_encode(['success' => false, 'message' => 'Database connection failed. Check your DB config.']);
        exit;
    }
    createTableIfNotExists($pdo);

    try {
        $stmt = $pdo->prepare("
            INSERT INTO students
                (first_name, last_name, email, phone, date_of_birth, gender,
                 course, department, year_of_study, address, city, state,
                 pincode, guardian_name, guardian_phone)
            VALUES
                (:first_name,:last_name,:email,:phone,:date_of_birth,:gender,
                 :course,:department,:year_of_study,:address,:city,:state,
                 :pincode,:guardian_name,:guardian_phone)
        ");
        $stmt->execute($data);
        $id = $pdo->lastInsertId();
        echo json_encode(['success' => true, 'message' => "Student registered successfully! ID: #$id"]);
    } catch (PDOException $e) {
        $msg = (strpos($e->getMessage(), 'unique') !== false)
             ? 'A student with this email already exists.'
             : 'Database error: ' . $e->getMessage();
        echo json_encode(['success' => false, 'message' => $msg]);
    }
    exit;
}

// ---- Export CSV ----
if ($action === 'export_csv') {
    $pdo = getConnection();
    if (!$pdo) {
        die('Database connection failed.');
    }
    createTableIfNotExists($pdo);

    $rows = $pdo->query("SELECT * FROM students ORDER BY registered_at DESC")->fetchAll();

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="students_' . date('Ymd_His') . '.csv"');
    header('Pragma: no-cache');

    $out = fopen('php://output', 'w');
    // UTF-8 BOM for Excel
    fputs($out, "\xEF\xBB\xBF");

    if (!empty($rows)) {
        fputcsv($out, array_keys($rows[0]));
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
    } else {
        fputcsv($out, ['No records found']);
    }
    fclose($out);
    exit;
}

// ---- Fetch students (JSON) ----
if ($action === 'fetch_students') {
    header('Content-Type: application/json');
    $pdo = getConnection();
    if (!$pdo) {
        echo json_encode(['success' => false, 'data' => []]);
        exit;
    }
    createTableIfNotExists($pdo);
    $rows = $pdo->query("SELECT id,first_name,last_name,email,course,department,year_of_study,registered_at FROM students ORDER BY registered_at DESC LIMIT 50")->fetchAll();
    echo json_encode(['success' => true, 'data' => $rows]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Student Registration Portal</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700;900&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
/* ============================================================
   DESIGN SYSTEM — Deep academic navy + warm gold
   ============================================================ */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --navy:      #0d1b2a;
    --navy-mid:  #1a2e42;
    --navy-lite: #243b55;
    --gold:      #c9972c;
    --gold-lite: #f0c254;
    --cream:     #fdf6e3;
    --white:     #ffffff;
    --muted:     #8a9bb0;
    --success:   #2ecc71;
    --error:     #e74c3c;
    --radius:    12px;
    --shadow:    0 8px 32px rgba(13,27,42,.18);
}

html { scroll-behavior: smooth; }

body {
    font-family: 'DM Sans', sans-serif;
    background: var(--navy);
    color: var(--white);
    min-height: 100vh;
    background-image:
        radial-gradient(ellipse 80% 60% at 50% -10%, rgba(201,151,44,.15) 0%, transparent 65%),
        repeating-linear-gradient(0deg, transparent, transparent 60px, rgba(255,255,255,.012) 60px, rgba(255,255,255,.012) 61px),
        repeating-linear-gradient(90deg, transparent, transparent 60px, rgba(255,255,255,.012) 60px, rgba(255,255,255,.012) 61px);
}

/* ---- Header ---- */
header {
    text-align: center;
    padding: 52px 24px 36px;
    position: relative;
}
header::after {
    content: '';
    display: block;
    width: 80px; height: 3px;
    background: linear-gradient(90deg, var(--gold), var(--gold-lite));
    margin: 20px auto 0;
    border-radius: 2px;
}
.logo-badge {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    background: rgba(201,151,44,.12);
    border: 1px solid rgba(201,151,44,.3);
    border-radius: 40px;
    padding: 6px 18px;
    font-size: .75rem;
    letter-spacing: .12em;
    text-transform: uppercase;
    color: var(--gold-lite);
    margin-bottom: 18px;
}
.logo-badge svg { width:14px; height:14px; fill:var(--gold-lite); }
h1 {
    font-family: 'Playfair Display', serif;
    font-size: clamp(2rem, 5vw, 3.2rem);
    font-weight: 900;
    line-height: 1.1;
    letter-spacing: -.02em;
}
h1 span { color: var(--gold); }
header p {
    color: var(--muted);
    margin-top: 10px;
    font-size: .95rem;
    font-weight: 300;
}

/* ---- Tabs ---- */
.tabs {
    display: flex;
    justify-content: center;
    gap: 4px;
    padding: 0 24px 24px;
}
.tab-btn {
    background: rgba(255,255,255,.06);
    border: 1px solid rgba(255,255,255,.1);
    color: var(--muted);
    padding: 10px 28px;
    border-radius: 40px;
    cursor: pointer;
    font-family: 'DM Sans', sans-serif;
    font-size: .88rem;
    font-weight: 500;
    transition: all .25s;
    letter-spacing: .03em;
}
.tab-btn:hover { color: var(--white); border-color: rgba(255,255,255,.25); }
.tab-btn.active {
    background: linear-gradient(135deg, var(--gold), var(--gold-lite));
    border-color: transparent;
    color: var(--navy);
    font-weight: 600;
    box-shadow: 0 4px 16px rgba(201,151,44,.35);
}

/* ---- Main Card ---- */
.container { max-width: 900px; margin: 0 auto; padding: 0 20px 60px; }
.card {
    background: rgba(255,255,255,.04);
    border: 1px solid rgba(255,255,255,.08);
    border-radius: 20px;
    padding: 36px 40px;
    backdrop-filter: blur(12px);
    box-shadow: var(--shadow);
}
.tab-panel { display: none; }
.tab-panel.active { display: block; }

/* ---- Section headers ---- */
.section-label {
    font-family: 'Playfair Display', serif;
    font-size: 1rem;
    color: var(--gold);
    margin: 28px 0 16px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.section-label::after {
    content: '';
    flex: 1;
    height: 1px;
    background: rgba(201,151,44,.2);
}
.section-label:first-child { margin-top: 0; }

/* ---- Grid ---- */
.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
.full    { grid-column: 1 / -1; }

@media(max-width:640px) {
    .grid-2, .grid-3 { grid-template-columns: 1fr; }
    .card { padding: 24px 18px; }
}

/* ---- Form fields ---- */
.field { display: flex; flex-direction: column; gap: 6px; }
label {
    font-size: .78rem;
    font-weight: 500;
    letter-spacing: .06em;
    text-transform: uppercase;
    color: var(--muted);
}
label .req { color: var(--gold); margin-left: 2px; }

input, select, textarea {
    background: rgba(255,255,255,.06);
    border: 1px solid rgba(255,255,255,.12);
    border-radius: var(--radius);
    padding: 11px 14px;
    color: var(--white);
    font-family: 'DM Sans', sans-serif;
    font-size: .9rem;
    transition: border-color .2s, background .2s, box-shadow .2s;
    outline: none;
    width: 100%;
}
input::placeholder, textarea::placeholder { color: rgba(255,255,255,.25); }
input:focus, select:focus, textarea:focus {
    border-color: var(--gold);
    background: rgba(201,151,44,.07);
    box-shadow: 0 0 0 3px rgba(201,151,44,.12);
}
select option { background: var(--navy-mid); }
textarea { resize: vertical; min-height: 80px; }

/* ---- Buttons ---- */
.btn-row {
    display: flex;
    gap: 12px;
    margin-top: 28px;
    flex-wrap: wrap;
}
.btn {
    padding: 13px 32px;
    border-radius: 40px;
    border: none;
    cursor: pointer;
    font-family: 'DM Sans', sans-serif;
    font-size: .9rem;
    font-weight: 600;
    letter-spacing: .04em;
    transition: all .22s;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}
.btn-primary {
    background: linear-gradient(135deg, var(--gold), var(--gold-lite));
    color: var(--navy);
    box-shadow: 0 4px 18px rgba(201,151,44,.3);
}
.btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(201,151,44,.45); }
.btn-primary:active { transform: translateY(0); }
.btn-secondary {
    background: rgba(255,255,255,.07);
    border: 1px solid rgba(255,255,255,.15);
    color: var(--white);
}
.btn-secondary:hover { background: rgba(255,255,255,.13); }

/* ---- Toast / Alert ---- */
#toast {
    position: fixed;
    top: 24px; right: 24px;
    padding: 14px 22px;
    border-radius: var(--radius);
    font-size: .9rem;
    font-weight: 500;
    z-index: 9999;
    transform: translateY(-20px);
    opacity: 0;
    transition: all .35s cubic-bezier(.4,0,.2,1);
    pointer-events: none;
    max-width: 340px;
    backdrop-filter: blur(10px);
}
#toast.show { transform: translateY(0); opacity: 1; pointer-events: all; }
#toast.success { background: rgba(46,204,113,.18); border:1px solid rgba(46,204,113,.4); color: #a8f0c6; }
#toast.error   { background: rgba(231,76,60,.18);  border:1px solid rgba(231,76,60,.4);  color: #f5b7b1; }

/* ---- Spinner ---- */
.spinner {
    width: 18px; height: 18px;
    border: 2px solid rgba(13,27,42,.3);
    border-top-color: var(--navy);
    border-radius: 50%;
    animation: spin .7s linear infinite;
    display: none;
}
.btn-primary.loading .spinner { display: block; }
.btn-primary.loading .btn-text { opacity: .5; }
@keyframes spin { to { transform: rotate(360deg); } }

/* ---- Students Table ---- */
.table-wrap { overflow-x: auto; }
table {
    width: 100%;
    border-collapse: collapse;
    font-size: .85rem;
}
th {
    text-align: left;
    padding: 10px 14px;
    font-size: .72rem;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: var(--gold);
    border-bottom: 1px solid rgba(201,151,44,.2);
}
td {
    padding: 12px 14px;
    border-bottom: 1px solid rgba(255,255,255,.05);
    color: rgba(255,255,255,.8);
}
tr:hover td { background: rgba(255,255,255,.03); }
.badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: .72rem;
    font-weight: 600;
    background: rgba(201,151,44,.15);
    color: var(--gold-lite);
    border: 1px solid rgba(201,151,44,.25);
}
.empty-state {
    text-align: center;
    padding: 48px 20px;
    color: var(--muted);
}
.empty-state svg { opacity:.3; margin-bottom: 12px; }

/* ---- Top bar for records panel ---- */
.panel-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 20px;
}
.panel-top h2 {
    font-family: 'Playfair Display', serif;
    font-size: 1.3rem;
}
</style>
</head>
<body>

<div id="toast"></div>

<header>
    <div class="logo-badge">
        <svg viewBox="0 0 24 24"><path d="M12 3L1 9l11 6 9-4.91V17h2V9L12 3zM5 13.18v4L12 21l7-3.82v-4L12 17l-7-3.82z"/></svg>
        Academic Portal
    </div>
    <h1>Student <span>Registration</span></h1>
    <p>Complete the form below to enrol in your academic programme</p>
</header>

<div class="container">
    <!-- Tabs -->
    <div class="tabs">
        <button class="tab-btn active" data-tab="register">📝 Register</button>
        <button class="tab-btn" data-tab="records" onclick="loadStudents()">📋 Records</button>
    </div>

    <div class="card">
        <!-- ==============================
             TAB: REGISTRATION FORM
             ============================== -->
        <div id="tab-register" class="tab-panel active">
            <form id="regForm" novalidate>

                <!-- Personal Info -->
                <div class="section-label">Personal Information</div>
                <div class="grid-2">
                    <div class="field">
                        <label>First Name <span class="req">*</span></label>
                        <input type="text" name="first_name" placeholder="e.g. Arjun" required>
                    </div>
                    <div class="field">
                        <label>Last Name <span class="req">*</span></label>
                        <input type="text" name="last_name" placeholder="e.g. Sharma" required>
                    </div>
                    <div class="field">
                        <label>Email Address <span class="req">*</span></label>
                        <input type="email" name="email" placeholder="student@university.edu" required>
                    </div>
                    <div class="field">
                        <label>Phone Number</label>
                        <input type="tel" name="phone" placeholder="+91 98765 43210">
                    </div>
                    <div class="field">
                        <label>Date of Birth</label>
                        <input type="date" name="date_of_birth">
                    </div>
                    <div class="field">
                        <label>Gender</label>
                        <select name="gender">
                            <option value="">-- Select --</option>
                            <option>Male</option>
                            <option>Female</option>
                            <option>Non-binary</option>
                            <option>Prefer not to say</option>
                        </select>
                    </div>
                </div>

                <!-- Academic Info -->
                <div class="section-label">Academic Details</div>
                <div class="grid-2">
                    <div class="field">
                        <label>Course / Programme</label>
                        <select name="course">
                            <option value="">-- Select Course --</option>
                            <option>B.Tech Computer Science</option>
                            <option>B.Tech Electronics</option>
                            <option>B.Tech Mechanical</option>
                            <option>B.Tech Civil</option>
                            <option>BCA</option>
                            <option>MCA</option>
                            <option>M.Tech Computer Science</option>
                            <option>MBA</option>
                            <option>B.Sc Physics</option>
                            <option>B.Sc Mathematics</option>
                            <option>B.Com</option>
                            <option>BA English</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>Department</label>
                        <select name="department">
                            <option value="">-- Select Department --</option>
                            <option>Computer Science & Engineering</option>
                            <option>Electronics & Communication</option>
                            <option>Mechanical Engineering</option>
                            <option>Civil Engineering</option>
                            <option>Management Studies</option>
                            <option>Science & Humanities</option>
                            <option>Commerce</option>
                            <option>Arts & Social Sciences</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>Year of Study</label>
                        <select name="year_of_study">
                            <option value="">-- Select Year --</option>
                            <option value="1">1st Year</option>
                            <option value="2">2nd Year</option>
                            <option value="3">3rd Year</option>
                            <option value="4">4th Year</option>
                        </select>
                    </div>
                </div>

                <!-- Address -->
                <div class="section-label">Address</div>
                <div class="grid-2">
                    <div class="field full">
                        <label>Street Address</label>
                        <textarea name="address" placeholder="House no, Street, Colony…"></textarea>
                    </div>
                    <div class="field">
                        <label>City</label>
                        <input type="text" name="city" placeholder="Guwahati">
                    </div>
                    <div class="field">
                        <label>State</label>
                        <input type="text" name="state" placeholder="Assam">
                    </div>
                    <div class="field">
                        <label>PIN Code</label>
                        <input type="text" name="pincode" placeholder="781001" maxlength="10">
                    </div>
                </div>

                <!-- Guardian -->
                <div class="section-label">Guardian / Emergency Contact</div>
                <div class="grid-2">
                    <div class="field">
                        <label>Guardian Name</label>
                        <input type="text" name="guardian_name" placeholder="Full name">
                    </div>
                    <div class="field">
                        <label>Guardian Phone</label>
                        <input type="tel" name="guardian_phone" placeholder="+91 98765 43210">
                    </div>
                </div>

                <div class="btn-row">
                    <button type="submit" class="btn btn-primary" id="submitBtn">
                        <div class="spinner"></div>
                        <span class="btn-text">Register Student</span>
                    </button>
                    <button type="reset" class="btn btn-secondary">Clear Form</button>
                </div>
            </form>
        </div>

        <!-- ==============================
             TAB: RECORDS
             ============================== -->
        <div id="tab-records" class="tab-panel">
            <div class="panel-top">
                <h2>Registered Students</h2>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <button class="btn btn-secondary" onclick="loadStudents()">🔄 Refresh</button>
                    <a href="?action=export_csv" class="btn btn-primary">
                        ⬇ Export CSV
                    </a>
                </div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Course</th>
                            <th>Department</th>
                            <th>Year</th>
                            <th>Registered</th>
                        </tr>
                    </thead>
                    <tbody id="studentsTbody">
                        <tr><td colspan="7">
                            <div class="empty-state">Loading…</div>
                        </td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
// ============================================================
//  TAB SWITCHING
// ============================================================
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('tab-' + btn.dataset.tab).classList.add('active');
    });
});

// ============================================================
//  TOAST NOTIFICATION
// ============================================================
function showToast(msg, type = 'success') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'show ' + type;
    clearTimeout(t._timer);
    t._timer = setTimeout(() => { t.className = ''; }, 3800);
}

// ============================================================
//  FORM SUBMISSION
// ============================================================
document.getElementById('regForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    // Client-side validation
    const firstName = this.first_name.value.trim();
    const lastName  = this.last_name.value.trim();
    const email     = this.email.value.trim();
    if (!firstName || !lastName) { showToast('Please enter first and last name.', 'error'); return; }
    if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        showToast('Please enter a valid email address.', 'error'); return;
    }

    const btn = document.getElementById('submitBtn');
    btn.classList.add('loading');
    btn.disabled = true;

    const fd = new FormData(this);
    fd.append('action', 'register');

    try {
        const res  = await fetch(location.href, { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            showToast('✓ ' + data.message, 'success');
            this.reset();
        } else {
            showToast('✗ ' + data.message, 'error');
        }
    } catch (err) {
        showToast('Network error. Please try again.', 'error');
    } finally {
        btn.classList.remove('loading');
        btn.disabled = false;
    }
});

// ============================================================
//  LOAD STUDENTS TABLE
// ============================================================
async function loadStudents() {
    const tbody = document.getElementById('studentsTbody');
    tbody.innerHTML = '<tr><td colspan="7"><div class="empty-state">Loading…</div></td></tr>';

    try {
        const res  = await fetch('?action=fetch_students');
        const data = await res.json();

        if (!data.success || !data.data.length) {
            tbody.innerHTML = `<tr><td colspan="7">
                <div class="empty-state">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="currentColor"><path d="M20 6H4V4h16v2zm2 2H2v12h20V8zM4 18V10h16v8H4z"/></svg>
                    <p>No students registered yet.</p>
                </div>
            </td></tr>`;
            return;
        }

        tbody.innerHTML = data.data.map(s => `
            <tr>
                <td><span class="badge">#${s.id}</span></td>
                <td><strong>${esc(s.first_name)} ${esc(s.last_name)}</strong></td>
                <td>${esc(s.email)}</td>
                <td>${esc(s.course || '—')}</td>
                <td>${esc(s.department || '—')}</td>
                <td>${s.year_of_study ? 'Year ' + s.year_of_study : '—'}</td>
                <td>${new Date(s.registered_at).toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'})}</td>
            </tr>
        `).join('');
    } catch (err) {
        tbody.innerHTML = '<tr><td colspan="7"><div class="empty-state">Failed to load records.</div></td></tr>';
    }
}

function esc(str) {
    const d = document.createElement('div');
    d.textContent = str || '';
    return d.innerHTML;
}
</script>
</body>
</html>