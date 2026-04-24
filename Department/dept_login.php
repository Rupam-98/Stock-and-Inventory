<?php
session_start();

// DB Connection
$host = 'localhost';
$dbname = 'postgres';
$user = 'postgres';
$password = '1035';

$error = '';

// Department credentials table setup + seed
try {
    $pdo = new PDO("pgsql:host=$host;dbname=$dbname", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    // Create departments table if not exists
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS departments (
            id         SERIAL PRIMARY KEY,
            name       VARCHAR(100) NOT NULL,
            email      VARCHAR(100) UNIQUE NOT NULL,
            mobile_no  VARCHAR(20),
            username   VARCHAR(60) UNIQUE NOT NULL,
            password   VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT NOW()
        );
    ");

    // Seed a default department account (username: dept_admin, password: admin123)
    $check = $pdo->query("SELECT COUNT(*) FROM departments")->fetchColumn();
    if ($check == 0) {
        $hash = password_hash('admin123', PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO departments (name, email, mobile_no, username, password) VALUES (?, ?, ?, ?, ?)")
            ->execute(['Computer Science Dept', 'cs@college.edu', '9000000001', 'dept_admin', $hash]);
    }

} catch (Exception $e) {
    $error = 'DB Error: ' . $e->getMessage();
}

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error)) {
    $username = trim($_POST['username'] ?? '');
    $pass     = trim($_POST['password'] ?? '');

    if ($username && $pass) {
        $stmt = $pdo->prepare("SELECT * FROM departments WHERE username = ? OR email = ?");
        $stmt->execute([$username, $username]);
        $dept = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($dept && password_verify($pass, $dept['password'])) {
            $_SESSION['dept_id']   = $dept['id'];
            $_SESSION['dept_name'] = $dept['name'];
            $_SESSION['dept_user'] = $dept['username'];
            header('Location: deptt_dashboard.php');
            exit;
        } else {
            $error = 'Invalid username or password.';
        }
    } else {
        $error = 'Please fill in all fields.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Department Portal — Login</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --ink:     #0d0d12;
  --paper:   #f5f3ef;
  --accent:  #e84c1e;
  --mid:     #6b6b78;
  --border:  #dddad4;
  --card:    #ffffff;
  --shadow:  rgba(13,13,18,.12);
}

html, body {
  height: 100%;
  font-family: 'DM Sans', sans-serif;
  background: var(--paper);
  color: var(--ink);
}

/* Grid layout */
.page {
  min-height: 100vh;
  display: grid;
  grid-template-columns: 1fr 480px;
}

/* LEFT PANEL */
.panel-left {
  background: var(--ink);
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  padding: 52px 56px;
  position: relative;
  overflow: hidden;
}

.panel-left::before {
  content: '';
  position: absolute;
  top: -80px; right: -80px;
  width: 420px; height: 420px;
  border-radius: 50%;
  background: radial-gradient(circle, #e84c1e22 0%, transparent 70%);
}

.panel-left::after {
  content: '';
  position: absolute;
  bottom: 40px; left: 40px;
  width: 260px; height: 260px;
  border-radius: 50%;
  background: radial-gradient(circle, #e84c1e15 0%, transparent 70%);
}

.logo-mark {
  display: flex;
  align-items: center;
  gap: 14px;
}

.logo-icon {
  width: 44px; height: 44px;
  background: var(--accent);
  border-radius: 10px;
  display: grid;
  place-items: center;
}

.logo-icon svg { width: 24px; height: 24px; fill: #fff; }

.logo-text {
  font-family: 'Syne', sans-serif;
  font-size: 1.1rem;
  font-weight: 700;
  color: #fff;
  letter-spacing: .02em;
}

.hero-copy {
  position: relative;
  z-index: 2;
}

.hero-copy h1 {
  font-family: 'Syne', sans-serif;
  font-size: clamp(2.4rem, 4vw, 3.6rem);
  font-weight: 800;
  color: #fff;
  line-height: 1.1;
  margin-bottom: 20px;
}

.hero-copy h1 span { color: var(--accent); }

.hero-copy p {
  color: #9898a8;
  font-size: .97rem;
  line-height: 1.7;
  max-width: 360px;
}

.features {
  display: flex;
  flex-direction: column;
  gap: 18px;
  position: relative;
  z-index: 2;
}

.feat {
  display: flex;
  align-items: center;
  gap: 14px;
}

.feat-dot {
  width: 8px; height: 8px;
  border-radius: 50%;
  background: var(--accent);
  flex-shrink: 0;
}

.feat span {
  font-size: .88rem;
  color: #7d7d8c;
  font-weight: 400;
}

/* RIGHT PANEL */
.panel-right {
  background: var(--card);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 48px 52px;
  border-left: 1px solid var(--border);
}

.login-box {
  width: 100%;
  max-width: 360px;
}

.login-box h2 {
  font-family: 'Syne', sans-serif;
  font-size: 1.85rem;
  font-weight: 700;
  margin-bottom: 6px;
}

.login-box .sub {
  color: var(--mid);
  font-size: .9rem;
  margin-bottom: 38px;
}

.field {
  margin-bottom: 22px;
}

.field label {
  display: block;
  font-size: .8rem;
  font-weight: 500;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--mid);
  margin-bottom: 8px;
}

.field input {
  width: 100%;
  padding: 13px 16px;
  border: 1.5px solid var(--border);
  border-radius: 10px;
  font-family: 'DM Sans', sans-serif;
  font-size: .95rem;
  background: var(--paper);
  color: var(--ink);
  transition: border-color .2s, box-shadow .2s;
  outline: none;
}

.field input:focus {
  border-color: var(--accent);
  box-shadow: 0 0 0 3px rgba(232,76,30,.12);
  background: #fff;
}

.btn-login {
  width: 100%;
  padding: 14px;
  background: var(--ink);
  color: #fff;
  font-family: 'Syne', sans-serif;
  font-size: 1rem;
  font-weight: 600;
  border: none;
  border-radius: 10px;
  cursor: pointer;
  letter-spacing: .03em;
  transition: background .2s, transform .15s;
  margin-top: 4px;
}

.btn-login:hover {
  background: var(--accent);
  transform: translateY(-1px);
}

.alert {
  background: #fff1ee;
  border: 1.5px solid #f5b8a8;
  color: #c0351a;
  padding: 12px 16px;
  border-radius: 10px;
  font-size: .88rem;
  margin-bottom: 24px;
}

.hint {
  margin-top: 28px;
  padding-top: 24px;
  border-top: 1px solid var(--border);
  font-size: .82rem;
  color: var(--mid);
  text-align: center;
}

.hint strong { color: var(--ink); }

@media (max-width: 820px) {
  .page { grid-template-columns: 1fr; }
  .panel-left { display: none; }
  .panel-right { padding: 48px 28px; }
}
</style>
</head>
<body>
<div class="page">

  <!-- LEFT -->
  <div class="panel-left">
    <div class="logo-mark">
      <div class="logo-icon">
        <svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22" fill="none" stroke="#fff" stroke-width="1.5"/></svg>
      </div>
      <span class="logo-text">CampusSync · Dept</span>
    </div>

    <div class="hero-copy">
      <h1>Department<br><span>Control</span><br>Portal</h1>
      <p>Manage inventory, handle student requests, and oversee your department from one unified dashboard.</p>
    </div>

    <div class="features">
      <div class="feat"><div class="feat-dot"></div><span>Item & Stock Management</span></div>
      <div class="feat"><div class="feat-dot"></div><span>Student Request Approvals</span></div>
      <div class="feat"><div class="feat-dot"></div><span>Student Roster Management</span></div>
      <div class="feat"><div class="feat-dot"></div><span>Real-time Status Tracking</span></div>
    </div>
  </div>

  <!-- RIGHT -->
  <div class="panel-right">
    <div class="login-box">
      <h2>Welcome back</h2>
      <p class="sub">Sign in to your department account</p>

      <?php if ($error): ?>
        <div class="alert"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="POST">
        <div class="field">
          <label>Username or Email</label>
          <input type="text" name="username" placeholder="dept_admin" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required autofocus>
        </div>
        <div class="field">
          <label>Password</label>
          <input type="password" name="password" placeholder="••••••••" required>
        </div>
        <button type="submit" class="btn-login">Sign In →</button>
      </form>

      <div class="hint">
        Default credentials — <strong>dept_admin</strong> / <strong>admin123</strong>
      </div>
    </div>
  </div>

</div>
</body>
</html>