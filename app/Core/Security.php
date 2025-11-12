<?php
namespace App\Core;
use Exception; 
class Security
{
    public static function requireLogin(): void
    {
        if (!isset($_SESSION['user_id'])) {
            header("Location: " . Utils::url('login'));
            exit();
        }
    }
    public static function requireRole($requiredRoles): void
    {
        self::requireLogin();
        if (!is_array($requiredRoles)) {
            $requiredRoles = [$requiredRoles];
        }
        $userRole = $_SESSION['user_role'] ?? '';
        if (!in_array($userRole, $requiredRoles)) {
            http_response_code(403);
            die("Zugriff verweigert. Sie haben nicht die erforderliche Rolle (" . htmlspecialchars($userRole) . "). Benötigt: " . implode(', ', $requiredRoles));
        }
    }
    public static function getCsrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
    public static function verifyCsrfToken(): void
    {
        $submittedToken = $_POST['_csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        $sessionToken = $_SESSION['csrf_token'] ?? null;
        if (!$submittedToken || !$sessionToken || !hash_equals($sessionToken, $submittedToken)) {
             http_response_code(403); 
             error_log("CSRF token validation failed. Submitted: " . ($submittedToken ?? 'NULL') . ", Session: " . ($sessionToken ?? 'NULL') . ", IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'));
             if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                 header('Content-Type: application/json');
                 throw new Exception("Sicherheitsüberprüfung fehlgeschlagen (CSRF-Token ungültig oder fehlt).");
             } else {
                 throw new Exception("Sicherheitsüberprüfung fehlgeschlagen (CSRF). Bitte gehen Sie zurück und versuchen Sie es erneut.");
             }
        }
    }
    public static function csrfInput(): void
    {
        echo '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars(self::getCsrfToken()) . '">';
    }
}