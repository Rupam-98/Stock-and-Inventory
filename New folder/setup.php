<?php
/**
 * Database Setup Script
 * This file initializes the database with tables and demo data
 * Run this file once to set up your system
 */

$host = 'localhost';
$dbname = 'six_sems';
$dbuser = 'postgres';
$dbpass = '1035';

try {
    $pdo = new PDO("pgsql:host=$host;dbname=$dbname", $dbuser, $dbpass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    echo "✓ Connected to database\n";
} catch (Exception $e) {
    die("✗ Database connection failed: " . $e->getMessage() . "\n");
}

// ── CREATE TABLES ──────────────────────────────────────────────────────────
$tables_sql = "
    DROP TABLE IF EXISTS repair_requests CASCADE;
    DROP TABLE IF EXISTS department_requests CASCADE;
    DROP TABLE IF EXISTS items CASCADE;
    DROP TABLE IF EXISTS students CASCADE;
    DROP TABLE IF EXISTS departments CASCADE;
    DROP TABLE IF EXISTS admins CASCADE;

    CREATE TABLE students (
        id SERIAL PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        student_id VARCHAR(20) UNIQUE NOT NULL,
        email VARCHAR(100) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        department VARCHAR(80),
        created_at TIMESTAMP DEFAULT NOW()
    );

    CREATE TABLE items (
        id SERIAL PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        category VARCHAR(60),
        quantity INTEGER DEFAULT 0,
        description TEXT,
        created_at TIMESTAMP DEFAULT NOW()
    );

    CREATE TABLE department_requests (
        id SERIAL PRIMARY KEY,
        student_id INTEGER REFERENCES students(id) ON DELETE CASCADE,
        department VARCHAR(80) NOT NULL,
        request_type VARCHAR(20) NOT NULL CHECK (request_type IN ('missing','lost','consumable_restock','new_requirement')),
        item_name VARCHAR(150) NOT NULL,
        item_category VARCHAR(60),
        quantity_needed INTEGER DEFAULT 1,
        description TEXT NOT NULL,
        urgency VARCHAR(20) DEFAULT 'normal' CHECK (urgency IN ('low','normal','high','urgent')),
        status VARCHAR(20) DEFAULT 'pending' CHECK (status IN ('pending','dept_approved','dept_rejected','purchase_ordered','fulfilled')),
        dept_admin_note TEXT,
        requested_at TIMESTAMP DEFAULT NOW(),
        updated_at TIMESTAMP DEFAULT NOW()
    );

    CREATE TABLE repair_requests (
        id SERIAL PRIMARY KEY,
        student_id INTEGER REFERENCES students(id) ON DELETE CASCADE,
        item_name VARCHAR(100) NOT NULL,
        damage_desc TEXT NOT NULL,
        priority VARCHAR(20) DEFAULT 'normal' CHECK (priority IN ('low','normal','high','urgent')),
        status VARCHAR(20) DEFAULT 'pending' CHECK (status IN ('pending','in_progress','completed','rejected')),
        submitted_at TIMESTAMP DEFAULT NOW(),
        updated_at TIMESTAMP DEFAULT NOW()
    );

    CREATE TABLE departments (
        id SERIAL PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        email VARCHAR(100) UNIQUE NOT NULL,
        mobile_no VARCHAR(20),
        username VARCHAR(60) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT NOW()
    );

    CREATE TABLE admins (
        id SERIAL PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        username VARCHAR(60) UNIQUE NOT NULL,
        email VARCHAR(100) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        role VARCHAR(20) DEFAULT 'admin' CHECK (role IN ('admin','super_admin')),
        created_at TIMESTAMP DEFAULT NOW()
    );
";

try {
    $pdo->exec($tables_sql);
    echo "✓ Tables created successfully\n";
} catch (Exception $e) {
    die("✗ Error creating tables: " . $e->getMessage() . "\n");
}

// ── SEED DEMO STUDENTS ─────────────────────────────────────────────────────
echo "\n--- SEEDING DEMO DATA ---\n";

$student_hash = password_hash('student123', PASSWORD_DEFAULT);
$students_data = [
    ['Alice Johnson', 'STU-001', 'alice@university.edu', 'Computer Science'],
    ['Bob Martinez', 'STU-002', 'bob@university.edu', 'Electrical Engineering'],
    ['Clara Nguyen', 'STU-003', 'clara@university.edu', 'Mechanical Engineering'],
    ['David Chen', 'STU-004', 'david@university.edu', 'Computer Science'],
    ['Emma Wilson', 'STU-005', 'emma@university.edu', 'Civil Engineering'],
];

$stmt = $pdo->prepare("INSERT INTO students (name, student_id, email, password, department) VALUES (?, ?, ?, ?, ?)");
foreach ($students_data as $student) {
    try {
        $stmt->execute([$student[0], $student[1], $student[2], $student_hash, $student[3]]);
        echo "✓ Added student: {$student[1]} ({$student[0]})\n";
    } catch (Exception $e) {
        echo "⚠ Student {$student[1]} already exists or error: {$e->getMessage()}\n";
    }
}

// ── SEED DEMO DEPARTMENT ADMINS ────────────────────────────────────────────
echo "\n";
$admin_hash = password_hash('admin123', PASSWORD_DEFAULT);
$depts_data = [
    ['Computer Science Department', 'cs@university.edu', '+1-234-567-8901', 'admin_cs'],
    ['Electrical Engineering', 'ee@university.edu', '+1-234-567-8902', 'admin_ee'],
    ['Mechanical Engineering', 'me@university.edu', '+1-234-567-8903', 'admin_me'],
    ['Civil Engineering', 'ce@university.edu', '+1-234-567-8904', 'admin_ce'],
];

$stmt = $pdo->prepare("INSERT INTO departments (name, email, mobile_no, username, password) VALUES (?, ?, ?, ?, ?)");
foreach ($depts_data as $dept) {
    try {
        $stmt->execute([$dept[0], $dept[1], $dept[2], $dept[3], $admin_hash]);
        echo "✓ Added department admin: {$dept[3]} ({$dept[0]})\n";
    } catch (Exception $e) {
        echo "⚠ Department admin {$dept[3]} already exists or error: {$e->getMessage()}\n";
    }
}

// ── SEED DEMO SYSTEM ADMINS ────────────────────────────────────────────────
echo "\n";
$sys_admin_hash = password_hash('admin123', PASSWORD_DEFAULT);
$admins_data = [
    ['Super Administrator', 'superadmin', 'superadmin@university.edu', 'super_admin'],
    ['System Administrator', 'sysadmin', 'admin@university.edu', 'admin'],
];

$stmt = $pdo->prepare("INSERT INTO admins (name, username, email, password, role) VALUES (?, ?, ?, ?, ?)");
foreach ($admins_data as $admin) {
    try {
        $stmt->execute([$admin[0], $admin[1], $admin[2], $sys_admin_hash, $admin[3]]);
        echo "✓ Added system admin: {$admin[1]} ({$admin[0]}) - Role: {$admin[3]}\n";
    } catch (Exception $e) {
        echo "⚠ System admin {$admin[1]} already exists or error: {$e->getMessage()}\n";
    }
}

// ── SEED DEMO ITEMS ────────────────────────────────────────────────────────
echo "\n";
$items_data = [
    ['Laptop', 'Electronics', 10, 'Dell XPS 15 – general-use laptops'],
    ['Scientific Calculator', 'Stationery', 25, 'Casio FX-991 scientific calculator'],
    ['Oscilloscope', 'Lab Equipment', 5, 'Digital oscilloscope for EE labs'],
    ['Drawing Kit', 'Art Supplies', 15, 'Complete architectural drawing kit'],
    ['USB Flash Drive', 'Electronics', 30, '64 GB USB 3.0 flash drives'],
    ['Multimeter', 'Lab Equipment', 8, 'Digital multimeter for circuit testing'],
    ['Soldering Kit', 'Lab Equipment', 6, 'Complete soldering kit with iron & solder'],
    ['A4 Paper Ream', 'Stationery', 50, 'Standard 80gsm white paper'],
    ['Whiteboard Markers', 'Stationery', 40, 'Set of 12 assorted colors'],
    ['Safety Goggles', 'Safety Equipment', 20, 'Lab-grade protective eyewear'],
];

$stmt = $pdo->prepare("INSERT INTO items (name, category, quantity, description) VALUES (?, ?, ?, ?)");
foreach ($items_data as $item) {
    try {
        $stmt->execute($item);
        echo "✓ Added item: {$item[0]}\n";
    } catch (Exception $e) {
        echo "⚠ Item {$item[0]} already exists or error: {$e->getMessage()}\n";
    }
}

echo "\n✅ Database setup completed!\n";
echo "\n--- TEST CREDENTIALS ---\n";
echo "Student Login:\n";
echo "  ID: STU-001\n";
echo "  Password: student123\n";
echo "\nDepartment Admin Login:\n";
echo "  Username: admin_cs\n";
echo "  Password: admin123\n";
echo "\nSystem Admin Login:\n";
echo "  Username: superadmin (Super Admin)\n";
echo "  Username: sysadmin (Regular Admin)\n";
echo "  Password: admin123\n";
echo "\nAccess login page at: http://localhost/HPB/login.php\n";
?>
