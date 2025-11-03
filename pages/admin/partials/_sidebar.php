<aside class="dashboard-sidebar">
    <h2>Verwaltung</h2>
    <nav class="dashboard-nav">
        <?php
        $currentUrl = $_SERVER['REQUEST_URI'];
        $userRole = $_SESSION['user_role'] ?? '';
        // NEU: Lade Einstellungen für die Sidebar
        $settings = \App\Core\Utils::getSettings();
        ?>

        <?php if (in_array($userRole, ['admin'])): ?>
            <a href="<?php echo htmlspecialchars(\App\Core\Utils::url('admin/dashboard')); ?>"
               class="dashboard-nav-item <?php echo (str_contains($currentUrl, 'admin/dashboard') ? 'active' : ''); ?>">
                Dashboard Übersicht
            </a>
            <a href="<?php echo htmlspecialchars(\App\Core\Utils::url('admin/users')); ?>"
               class="dashboard-nav-item <?php echo (str_contains($currentUrl, 'admin/users') ? 'active' : ''); ?>">
                Benutzer verwalten
            </a>
            <a href="<?php echo htmlspecialchars(\App\Core\Utils::url('admin/csv-template')); ?>"
               class="dashboard-nav-item <?php echo (str_contains($currentUrl, 'admin/csv-template') ? 'active' : ''); ?>">
                CSV Importvorlage
            </a>
            <a href="<?php echo htmlspecialchars(\App\Core\Utils::url('admin/stammdaten')); ?>"
               class="dashboard-nav-item <?php echo (str_contains($currentUrl, 'admin/stammdaten') ? 'active' : ''); ?>">
                Stammdaten
            </a>
        <?php endif; ?>

        <?php if (in_array($userRole, ['admin', 'planer', 'lehrer'])): ?>
            <a href="<?php echo htmlspecialchars(\App\Core\Utils::url('admin/announcements')); ?>"
               class="dashboard-nav-item <?php echo (str_contains($currentUrl, 'admin/announcements') ? 'active' : ''); ?>">
                Ankündigungen
            </a>
        <?php endif; ?>
        
        <?php // NEU: Link nur anzeigen, wenn das Board in den Settings aktiviert ist ?>
        <?php if (in_array($userRole, ['admin', 'planer']) && $settings['community_board_enabled']): ?>
            <a href="<?php echo htmlspecialchars(\App\Core\Utils::url('admin/community-moderation')); ?>"
               class="dashboard-nav-item <?php echo (str_contains($currentUrl, 'admin/community-moderation') ? 'active' : ''); ?>">
                Schwarzes Brett (Mod.)
            </a>
        <?php endif; ?>
        
        <?php if (in_array($userRole, ['admin'])): ?>
            <a href="<?php echo htmlspecialchars(\App\Core\Utils::url('admin/audit-logs')); ?>"
               class="dashboard-nav-item <?php echo (str_contains($currentUrl, 'admin/audit-logs') ? 'active' : ''); ?>">
                Audit Log (Protokoll)
            </a>
            <a href="<?php echo htmlspecialchars(\App\Core\Utils::url('admin/system-health')); ?>"
               class="dashboard-nav-item <?php echo (str_contains($currentUrl, 'admin/system-health') ? 'active' : ''); ?>">
                System-Status
            </a>
            <a href="<?php echo htmlspecialchars(\App\Core\Utils::url('admin/settings')); ?>"
               class="dashboard-nav-item <?php echo (str_contains($currentUrl, 'admin/settings') ? 'active' : ''); ?>">
                Einstellungen
            </a>
        <?php endif; ?>


         <?php if (in_array($userRole, ['planer'])): ?>
               <?php endif; ?>
    </nav>
</aside>