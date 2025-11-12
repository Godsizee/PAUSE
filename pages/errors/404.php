<?php
global $config;
?>
<div class="container text-center">
    <h1 class="main-title" style="font-size: 5rem; margin-bottom: 0;">404</h1>
    <p style="font-size: 1.5rem; color: var(--color-text-muted); margin-top: 0;">Stunde entfallen!</p>
    <p>Ups! Diese Seite scheint heute auszufallen. Bitte beachten Sie den digitalen Vertretungsplan und kehren Sie zur Startseite zurück.</p>
    <a href="<?php echo htmlspecialchars($config['base_url']); ?>/" class="btn btn-primary" style="width: auto; margin-top: 20px;">Zurück zum Stundenplan</a>
</div>