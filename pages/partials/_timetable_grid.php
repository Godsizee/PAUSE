<?php
$settings = \App\Core\Utils::getSettings();
global $config; // Ensure $config is accessible
$faviconPath = !empty($settings['site_favicon_path'])
    ? htmlspecialchars(\App\Core\Utils::url($settings['site_favicon_path']))
    : null; // Oder ein Pfad zu einem Standard-Favicon
$logoPath = !empty($settings['site_logo_path'])
    ? htmlspecialchars(\App\Core\Utils::url($settings['site_logo_path']))
    : null;
$cacheBuster = "?v=" . time();
if ($faviconPath) $faviconPath .= $cacheBuster;
if ($logoPath) $logoPath .= $cacheBuster;
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars(\App\Core\Security::getCsrfToken()); ?>">
    <title><?php echo htmlspecialchars($page_title ?? $settings['site_title']); ?></title>
    <?php // NEU: Dynamisches Favicon ?>
    <?php if ($faviconPath): ?>
        <link rel="icon" href="<?php echo $faviconPath; ?>">
    <?php endif; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Oswald:wght@700&display=swap" rel="stylesheet">
    <script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.14/index.global.min.js'></script>
    <script>
        (function() {
            const defaultTheme = <?php echo json_encode($settings['default_theme'] ?? 'light'); ?>;
            const theme = localStorage.getItem('theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : defaultTheme);
            if (theme === 'dark') {
                document.documentElement.classList.add('dark-mode');
            }
        })();
        window.APP_CONFIG = {
            baseUrl: '<?php echo rtrim($config['base_url'], '/'); ?>',
            userRole: '<?php echo $_SESSION['user_role'] ?? ''; ?>',
            userId: <?php echo $_SESSION['user_id'] ?? 'null'; ?>, // Added userId for potential JS checks
            settings: <?php echo json_encode($settings); ?>
        };
    </script>
    <!-- === MODULAR CSS START (ITCSS/CUBE) === -->
    <?php
    $css_version_path = $_SERVER['DOCUMENT_ROOT'] . rtrim($config['base_url'], '/') . '/assets/css/1-settings/variables.css';
    $css_version = file_exists($css_version_path) ? filemtime($css_version_path) : time();
    $base_url = rtrim($config['base_url'], '/');
    $css_files = [
        '1-settings/variables.css',
        '3-generic/reset.css',
        '4-base/globals.css',
        '4-base/typography.css',
        '5-layout/page.css',
        '5-layout/header.css',
        '5-layout/footer.css',
        '5-layout/grid.css',
        '5-layout/sidebar.css',
        '6-components/button.css',
        '6-components/form.css',
        '6-components/table.css',
        '6-components/tabs.css',
        '6-components/messages.css',
        '6-components/modal.css',
        '6-components/widget.css',
        '6-components/post.css',
        '6-components/timetable.css',
        '6-components/user-menu.css',
        '6-components/status.css',
        '7-utilities/spacing.css',
        '7-utilities/flex.css',
        '7-utilities/helpers.css', // HINZUGEFÜGT
        '8-pages/auth.css',
        '8-pages/admin-dashboard.css',
        '8-pages/admin-users.css',
        '8-pages/admin-announcements.css',
        '8-pages/admin-community.css',
        '8-pages/admin-settings.css',
        '8-pages/admin-audit-log.css',
        '8-pages/admin-system-health.css',
        '8-pages/admin-csv-template.css',
        '8-pages/planer-layout.css',
        '8-pages/dashboard-cockpit.css'
    ];
    ?>
    <!-- Lade alle CSS-Dateien -->
    <?php foreach ($css_files as $file): ?>
<link rel="stylesheet" href="<?php echo $base_url; ?>/assets/css/<?php echo $file; ?>?v=<?php echo $css_version; ?>">
    <?php endforeach; ?>
    <!-- === MODULAR CSS END === -->
    <!-- <link rel="stylesheet" href="<?php echo htmlspecialchars(rtrim($config['base_url'], '/')); ?>/assets/css/main.css"> --> <!-- ALT, ERSETZT -->
</head>
<body class="<?php echo htmlspecialchars($body_class ?? ''); ?> role-<?php echo htmlspecialchars($_SESSION['user_role'] ?? 'guest'); ?>"> <?php  ?>
    <?php // Impersonation Banner ?>
    <?php if (isset($_SESSION['impersonator_id'])): ?>
        <div class="impersonation-banner">
            <div class="page-wrapper" style="display: flex; justify-content: space-between; align-items: center; padding: 12px 20px;">
                <span>
                    <strong>Achtung:</strong> Sie sind angemeldet als 
                    <strong><?php echo htmlspecialchars($_SESSION['username'] ?? 'Benutzer'); ?></strong> 
                    (Rolle: <?php echo htmlspecialchars($_SESSION['user_role'] ?? 'N/A'); ?>).
                </span>
                <a href="<?php echo htmlspecialchars(\App\Core\Utils::url('logout/revert')); ?>" class="btn btn-secondary btn-small" style="width: auto; margin-bottom: 0;">
                    Zurück zum Admin-Konto
                </a>
            </div>
        </div>
    <?php endif; ?>
    <header class="page-header">
        <a href="<?php echo htmlspecialchars(\App\Core\Utils::url('/')); ?>" class="site-logo">
            <?php // NEU: Logo-Logik ?>
            <?php if ($logoPath): ?>
                <img src="<?php echo $logoPath; ?>" alt="<?php echo htmlspecialchars($settings['site_title']); ?> Logo" class="site-logo-image" id="header-logo-img">
            <?php else: ?>
                <span id="header-logo-text"><?php echo htmlspecialchars($settings['site_title']); ?></span>
            <?php endif; ?>
        </a>
        <nav class="header-nav" id="header-nav">
            <div class="nav-right">
                <button id="theme-toggle" class="theme-toggle" title="Theme umschalten">
                    <img class="sun-icon" src="<?php echo htmlspecialchars(rtrim($config['base_url'], '/')); ?>/assets/images/sun.png" alt="Light Mode">
                    <img class="moon-icon" src="<?php echo htmlspecialchars(rtrim($config['base_url'], '/')); ?>/assets/images/moon.png" alt="Dark Mode">
                </button>
                <span class="nav-separator"></span> <?php if (isset($_SESSION['user_id'])):
                    $userRole = $_SESSION['user_role'] ?? '';
                ?>
                    <div class="user-menu">
                        <?php // KORREKTUR: id="user-menu-btn" hinzugefügt ?>
                        <button id="user-menu-btn" class="user-menu-toggle" aria-haspopup="true" aria-expanded="false">
                            <span>Hallo, <?php echo htmlspecialchars($_SESSION['username']); ?></span>
                            <svg class="chevron-down" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                        </button>
                        <?php // KORREKTUR: id="user-menu-dropdown" hinzugefügt ?>
                        <div id="user-menu-dropdown" class="user-menu-dropdown">
                            <a href="<?php echo htmlspecialchars(\App\Core\Utils::url('dashboard')); ?>">Mein Dashboard</a>
                            <?php if ($userRole === 'admin'): ?>
                                 <a href="<?php echo htmlspecialchars(\App\Core\Utils::url('admin/dashboard')); ?>">Admin Bereich</a>
                            <?php elseif ($userRole === 'planer'): ?>
                                 <a href="<?php echo htmlspecialchars(\App\Core\Utils::url('planer/dashboard')); ?>">Planer Bereich</a>
                            <?php endif; ?>
                            <?php if (in_array($userRole, ['admin', 'planer', 'lehrer'])): ?>
                                 <a href="<?php echo htmlspecialchars(\App\Core\Utils::url('admin/announcements')); ?>">Ankündigungen</a>
                            <?php endif; ?>
                            <div class="dropdown-divider"></div>
                            <a href="<?php echo htmlspecialchars(\App\Core\Utils::url('logout')); ?>">Abmelden</a>
                        </div>
                    </div>
                <?php else: ?>
                    <a href="<?php echo htmlspecialchars(\App\Core\Utils::url('login')); ?>" class="header-link">Anmelden</a>
                    <?php endif; ?>
            </div>
        </nav>
        <button class="mobile-menu-toggle" id="mobile-menu-toggle" aria-controls="header-nav" aria-expanded="false">
            <span class="visually-hidden">Menü</span>
            <span class="hamburger-box"><span class="hamburger-inner"></span></span>
        </button>
    </header>
    <main class="page-wrapper">