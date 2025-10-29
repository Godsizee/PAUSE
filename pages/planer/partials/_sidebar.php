<?php
// pages/planer/partials/_sidebar.php
// NEU: Eigene Sidebar für den Planer-Bereich
?>
<aside class="dashboard-sidebar">
    <h2>Planer-Werkzeuge</h2>
    <nav class="dashboard-nav">
        <?php
        $currentUrl = $_SERVER['REQUEST_URI'];
        $userRole = $_SESSION['user_role'] ?? '';
        ?>

        <?php if (in_array($userRole, ['admin', 'planer'])): ?>
            <a href="<?php echo htmlspecialchars(\App\Core\Utils::url('planer/dashboard')); ?>"
               class="dashboard-nav-item <?php echo (str_contains($currentUrl, 'planer/dashboard') ? 'active' : ''); ?>">
                Stundenplan-Editor
            </a>
            <a href="<?php echo htmlspecialchars(\App\Core\Utils::url('planer/absences')); ?>"
               class="dashboard-nav-item <?php echo (str_contains($currentUrl, 'planer/absences') ? 'active' : ''); ?>">
                Lehrer-Abwesenheiten
            </a>
            <!-- Zukünftige Planer-Links können hier hinzugefügt werden -->
        <?php endif; ?>
         
        <?php if (in_array($userRole, ['admin'])): ?>
             <div class="dropdown-divider" style="margin: 15px 0; border-color: var(--color-border);"></div>
            <a href="<?php echo htmlspecialchars(\App\Core\Utils::url('admin/dashboard')); ?>"
               class="dashboard-nav-item">
                &laquo; Zurück zum Admin-Dashboard
            </a>
        <?php endif; ?>
    </nav>
</aside>
