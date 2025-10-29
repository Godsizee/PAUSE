<?php
$settings = \App\Core\Utils::getSettings();
global $config; // Ensure $config is accessible

// NEU: Bereite Logo- und Favicon-Pfade vor
$faviconPath = !empty($settings['site_favicon_path'])
    ? htmlspecialchars(\App\Core\Utils::url($settings['site_favicon_path']))
    : null; // Oder ein Pfad zu einem Standard-Favicon

$logoPath = !empty($settings['site_logo_path'])
    ? htmlspecialchars(\App\Core\Utils::url($settings['site_logo_path']))
    : null;

// Füge einen Cache-Buster hinzu, um Änderungen sofort sichtbar zu machen
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

    <!-- NEU: FullCalendar Skripte (für Abwesenheits-Management) -->
    <script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.14/index.global.min.js'></script>
    <!-- Ende FullCalendar -->

    <script>
        // Immediately set theme before rendering to prevent flash
        (function() {
            // KORRIGIERT: Bevorzuge das Admin-Setting als Fallback
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

    <link rel="stylesheet" href="<?php echo htmlspecialchars(rtrim($config['base_url'], '/')); ?>/assets/css/main.css">
</head>
<body class="<?php echo htmlspecialchars($body_class ?? ''); ?> role-<?php echo htmlspecialchars($_SESSION['user_role'] ?? 'guest'); ?>"> <?php /* Added role class to body */ ?>
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
                        <button class="user-menu-toggle" aria-haspopup="true" aria-expanded="false">
                            <span>Hallo, <?php echo htmlspecialchars($_SESSION['username']); ?></span>
                            <svg class="chevron-down" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                        </button>
                        <div class="user-menu-dropdown">
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
