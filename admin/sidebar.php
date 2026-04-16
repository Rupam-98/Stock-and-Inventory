<!DOCTYPE html>
<!-- Sidebar -->

<style>
    .submenu {
        max-height: 0;
        overflow: hidden;
        transition: max-height 0.4s ease;
        padding-left: 10px;
    }

    .submenu a {
        display: block;
        padding: 8px;
        font-size: 14px;
        background: #2d3748;
        margin: 2px 0;
        border-radius: 5px;
    }

    .submenu a:hover {
        background: #4b5563;
    }

    /* Active link */
    .sidebar a.active {
        background: #4b5563;
    }

    /* Arrow */
    .arrow {
        float: right;
        transition: transform 0.3s ease;
    }

    /* Rotate arrow */
    .arrow.rotate {
        transform: rotate(180deg);
    }
</style>
<div class="col-md-2 sidebar">
    <h4 class="mb-4">Admin Panel</h4>
    <a href="dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>


    <a href="#" class="menu-toggle" onclick="toggleMenu(event, 'deptMenu')">
        <i class="bi bi-building"></i> Departments
        <span class="arrow">▼</span>
    </a>

    <div id="deptMenu" class="submenu">
        <a href="departments.php">➤ View Departments</a>
        <a href="#">➤ Add Department</a>
    </div>

    <a href="#"><i class="bi bi-people"></i> Students</a>
    <a href="#"><i class="bi bi-box"></i> Inventory</a>
    <a href="#"><i class="bi bi-file-text"></i> Requests</a>
    <a href="../logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a>

</div>
<script>
    function toggleMenu(event, menuId) {
        event.preventDefault();

        const menu = document.getElementById(menuId);
        const arrow = event.currentTarget.querySelector(".arrow");

        document.querySelectorAll(".submenu").forEach(sub => {
            if (sub.id !== menuId) {
                sub.style.maxHeight = null;
            }
        });

        document.querySelectorAll(".arrow").forEach(arr => {
            if (arr !== arrow) {
                arr.classList.remove("rotate");
            }
        });

        if (menu.style.maxHeight) {
            menu.style.maxHeight = null;
            arrow.classList.remove("rotate");
        } else {
            menu.style.maxHeight = menu.scrollHeight + "px";
            arrow.classList.add("rotate");
        }
    }
</script>

</html>