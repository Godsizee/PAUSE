<?php
// pages/admin/system_health.php
// NEU ERSTELLT
include_once dirname(__DIR__) . '/partials/header.php';
// $data (Array) wird vom SystemHealthController::index() übergeben
?>

<div class="page-wrapper admin-dashboard-wrapper">
    <h1 class="main-title">System-Status</h1>
    <div class="dashboard-grid">
        
        <?php include_once __DIR__ . '/partials/_sidebar.php'; ?>

        <main class="dashboard-content" id="system-health-management">
            <div class="dashboard-section active">
                
                <div class="health-grid">

                    <div class="health-widget widget-server">
                        <h3>Server & Datenbank</h3>
                        <ul class="health-list">
                            <li>
                                <span class="health-label">PHP Version</span>
                                <span class="health-value"><?php echo htmlspecialchars($data['phpVersion']); ?></span>
                            </li>
                            <li>
                                <span class="health-label">Server Software</span>
                                <span class="health-value"><?php echo htmlspecialchars($data['serverSoftware']); ?></span>
                            </li>
                            <li>
                                <span class="health-label">Datenbank-Status</span>
                                <?php if ($data['dbStatus']['status'] === 'ok'): ?>
                                    <span class="health-value status-ok">
                                        <i class="icon-check"></i> <?php echo htmlspecialchars($data['dbStatus']['message']); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="health-value status-error">
                                        <i class="icon-x"></i> <?php echo htmlspecialchars($data['dbStatus']['message']); ?>
                                    </span>
                                <?php endif; ?>
                            </li>
                             <li>
                                <span class="health-label">Wartungsmodus</span>
                                <?php if ($data['settings']['maintenance_mode']): ?>
                                    <span class="health-value status-warn">
                                        <i class="icon-tool"></i> Aktiviert
                                    </span>
                                <?php else: ?>
                                    <span class="health-value status-ok">
                                        <i class="icon-check"></i> Deaktiviert
                                    </span>
                                <?php endif; ?>
                            </li>
                        </ul>
                    </div>

                    <div class="health-widget widget-extensions">
                        <h3>PHP-Erweiterungen</h3>
                        <ul class="health-list">
                            <?php foreach ($data['extensions'] as $ext => $isLoaded): ?>
                            <li>
                                <span class="health-label"><?php echo htmlspecialchars($ext); ?></span>
                                <?php if ($isLoaded): ?>
                                    <span class="health-value status-ok">
                                        <i class="icon-check"></i> Geladen
                                    </span>
                                <?php else: ?>
                                    <span class="health-value status-error">
                                        <i class="icon-x"></i> Nicht geladen
                                    </span>
                                <?php endif; ?>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    
                    <div class="health-widget widget-permissions">
                        <h3>Verzeichnis-Berechtigungen</h3>
                        <ul class="health-list">
                             <?php foreach ($data['directoryStatus'] as $name => $dir): ?>
                            <li>
                                <span class="health-label"><?php echo htmlspecialchars($name); ?></span>
                                <?php if ($dir['status'] === 'ok'): ?>
                                    <span class="health-value status-ok">
                                        <i class="icon-check"></i> <?php echo htmlspecialchars($dir['message']); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="health-value status-error">
                                        <i class="icon-x"></i> <?php echo htmlspecialchars($dir['message']); ?>
                                    </span>
                                <?php endif; ?>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>

                </div>
            </div>
        </main>
    </div>
</div>

<?php
include_once dirname(__DIR__) . '/partials/footer.php';
?>