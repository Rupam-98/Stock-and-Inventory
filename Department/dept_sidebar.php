<!DOCTYPE html>
<html>
<style>
    /* Sidebar */
    .sidebar {
        position: fixed;
        top: 0;
        left: 0;
        width: 220px;
        height: 100vh;
        background: #1f2937;
        color: white;
        transition: width 0.3s;
        overflow: hidden;
    }

    /* Collapsed Sidebar */
    .sidebar.collapsed {
        width: 70px;
    }

    /* Header */
    .sidebar-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 15px;
    }

    .logo {
        font-size: 18px;
    }

    /* Toggle Button */
    #toggleBtn {
        background: none;
        border: none;
        color: white;
        font-size: 18px;
        cursor: pointer;
    }

    /* Menu */
    .menu {
        list-style: none;
        padding: 0;
    }

    .menu li {
        padding: 10px;
    }

    .menu a {
        color: white;
        text-decoration: none;
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px;
        border-radius: 5px;
    }

    .menu a:hover {
        background: #374151;
    }

    /* Hide text when collapsed */
    .sidebar.collapsed span {
        display: none;
    }

    /* Main Content */
    .main-content {
        margin-left: 220px;
        transition: margin-left 0.3s;
        padding: 20px;
        background: #f4f6f9;
        min-height: 100vh;
    }

    .main-content.collapsed {
        margin-left: 70px;
    }
</style>


<div class="sidebar" id="sidebar">

    <div class="sidebar-header">
        <h4 class="logo">Dept Panel</h4>
        <button id="toggleBtn">☰</button>
    </div>

    <ul class="menu">
        <li><a href="../Department/dept_dashboard.php"><i class="bi bi-speedometer2"></i> <span>Dashboard</span></a></li>
        <li><a href="../Department/dept_inventory.php"><i class="bi bi-box"></i> <span>Inventory</span></a></li>
        <li><a href="../Department/requests.php"><i class="bi bi-file-text"></i> <span>Requests</span></a></li>
        <li><a href="../student/add_student.php"><i class="bi bi-people"></i> <span>Students</span></a></li>
        <li><a href="../logout.php"><i class="bi bi-box-arrow-right"></i> <span>Logout</span></a></li>
    </ul>

</div>

</html>