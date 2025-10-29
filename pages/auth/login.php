<?php
// pages/auth/login.php

// Der Pfad verweist jetzt auf den korrekten Ordner `pages/partials`.
include_once __DIR__ . '/../partials/header.php';
?>

<div class="page-wrapper" style="padding-top: 100px;">
    <div class="auth-container">

        <h1 class="main-title">Login</h1>

        <?php if (!empty($message)): ?>
            <p class="message error"><?php echo htmlspecialchars($message); ?></p>
        <?php endif; ?>

        <form action="<?php echo \App\Core\Utils::url('login/process'); ?>" method="post">
            <?php \App\Core\Security::csrfInput(); // Add CSRF input field ?>
            <p>
                <label for="identifier">Benutzername oder E-Mail</label>
                <input type="text" id="identifier" name="identifier" required>
            </p>
            <p>
                <label for="password">Passwort</label>
                <input type="password" id="password" name="password" required>
            </p>
            <p>
                <input type="submit" value="Anmelden" class="btn btn-primary" style="width: 100%;">
            </p>
        </form>
    </div>
</div>

<?php
// Der Pfad verweist jetzt auf den korrekten Ordner `pages/partials`.
include_once __DIR__ . '/../partials/footer.php';
?>

