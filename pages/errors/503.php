<?php
// pages/errors/503.php
// Zeigt die Wartungsseite an.
// $maintenance_message wird von index.php übergeben.
global $config;
?>
<div class="page-wrapper" style="padding-top: 100px;">
    <div class="auth-container" style="max-width: 600px; text-align: center;">
        <h1 class="main-title" style="font-size: 2.5rem; color: var(--color-warning);">🔧 Wartungsarbeiten</h1>
        <p style="font-size: 1.1rem; line-height: 1.6; color: var(--color-text-muted);">
            <?php echo nl2br(htmlspecialchars($maintenance_message ?? 'Die Anwendung wird gerade gewartet. Bitte versuchen Sie es später erneut.')); ?>
        </p>
        
        <?php // Admins einen einfachen Weg zum Login geben ?>
         <a href="<?php echo htmlspecialchars(\App\Core\Utils::url('login')); ?>" class="btn btn-secondary" style="width: auto; margin-top: 25px;">
             Zum Admin-Login
         </a>
    </div>
</div>