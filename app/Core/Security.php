<?php
// app/Core/Security.php
namespace App\Core;

use Exception; // Added

class Security
{
    /**
     * Stellt sicher, dass ein Benutzer angemeldet ist.
     * Leitet andernfalls zur Login-Seite weiter.
     */
    public static function requireLogin(): void
    {
        if (!isset($_SESSION['user_id'])) {
            header("Location: " . Utils::url('login'));
            exit();
        }
    }

    /**
     * Stellt sicher, dass ein Benutzer eine bestimmte Rolle hat.
     *
     * @param string|array $requiredRoles Die erforderliche Rolle oder ein Array von Rollen.
     */
    public static function requireRole($requiredRoles): void
    {
        self::requireLogin();

        if (!is_array($requiredRoles)) {
            $requiredRoles = [$requiredRoles];
        }

        $userRole = $_SESSION['user_role'] ?? '';

        if (!in_array($userRole, $requiredRoles)) {
            // Optional: Weiterleitung zu einer "Zugriff verweigert"-Seite
            http_response_code(403);
            // Include a more user-friendly error page/message in production
            die("Zugriff verweigert. Sie haben nicht die erforderliche Rolle (" . htmlspecialchars($userRole) . "). Benötigt: " . implode(', ', $requiredRoles));
        }
    }

    /**
     * Generiert ein CSRF-Token, speichert es in der Session und gibt es zurück.
     * Wenn bereits ein Token in der Session existiert, wird dieses zurückgegeben.
     * @return string Das CSRF-Token.
     */
    public static function getCsrfToken(): string
    {
        // Renamed from generateCsrfToken for consistency, kept the logic
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Verifiziert das übermittelte CSRF-Token gegen das in der Session gespeicherte.
     * Wird für POST-Requests und AJAX-Anfragen mit Header verwendet.
     * @throws Exception Wenn das Token ungültig oder nicht vorhanden ist.
     */
    public static function verifyCsrfToken(): void
    {
        // Renamed from validateCsrfToken
        // Check both POST data and potential AJAX header
        $submittedToken = $_POST['_csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        $sessionToken = $_SESSION['csrf_token'] ?? null;

        if (!$submittedToken || !$sessionToken || !hash_equals($sessionToken, $submittedToken)) {
             http_response_code(403); // Forbidden
             // Log this attempt for security monitoring
             error_log("CSRF token validation failed. Submitted: " . ($submittedToken ?? 'NULL') . ", Session: " . ($sessionToken ?? 'NULL') . ", IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'));

            // Provide appropriate response based on request type
             if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                 // AJAX request
                 header('Content-Type: application/json');
                 // Throwing exception might be better handled by a global error handler
                 // echo json_encode(['success' => false, 'message' => 'Ungültiges CSRF-Token. Bitte laden Sie die Seite neu.']);
                 // exit();
                 throw new Exception("Sicherheitsüberprüfung fehlgeschlagen (CSRF-Token ungültig oder fehlt).");

             } else {
                 // Standard form submission
                 // In production, show a user-friendly error page instead of die()
                 // die('Fehler: Ungültiges Sicherheitstoken. Bitte versuchen Sie es erneut.');
                 throw new Exception("Sicherheitsüberprüfung fehlgeschlagen (CSRF). Bitte gehen Sie zurück und versuchen Sie es erneut.");
             }
        }
         // Optional: Consider regenerating the token after successful validation for one-time use tokens,
         // but this can cause issues with multiple tabs or back button usage.
         // unset($_SESSION['csrf_token']); // Remove if implementing one-time tokens
    }


    /**
     * Gibt das HTML für das versteckte CSRF-Input-Feld aus.
     * Ruft intern getCsrfToken auf, um sicherzustellen, dass ein Token existiert.
     */
    public static function csrfInput(): void
    {
        echo '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars(self::getCsrfToken()) . '">';
    }
}

