<?php
// app/Http/Controllers/Auth/AuthController.php
// MODIFIZIERT:
// 1. UserRepository und AuditLogger importiert.
// 2. Konstruktor erweitert, um UserRepository zu initialisieren.
// 3. Neue Methode revertImpersonation() hinzugefügt.

namespace App\Http\Controllers\Auth;

use App\Core\Database;
use App\Core\Utils;
use App\Core\Security; // Use statement
use App\Repositories\UserRepository;
use App\Services\AuthenticationService;
use App\Services\AuditLogger; // NEU
use Exception;
use PDO;

class AuthController
{
    private PDO $pdo;
    private UserRepository $userRepository; // NEU
    // private AuthenticationService $authService; // Wird nur in handleLogin benötigt

    public function __construct()
    {
        $this->pdo = Database::getInstance();
        $this->userRepository = new UserRepository($this->pdo); // NEU
    }

    /**
     * Verarbeitet die POST-Anfrage vom Login-Formular.
     */
    public function handleLogin()
    {
        // CSRF Token Validation
        try {
            Security::verifyCsrfToken(); // Use the updated method name
        } catch (Exception $e) {
             // Handle CSRF validation failure - show login page with error
            $message = $e->getMessage();
            $page_title = 'Login';
             // Regenerate token on failure? Optional, but can help if token expired.
             Security::getCsrfToken(); // Ensure a new one is available for the form
            include_once dirname(__DIR__, 4) . '/pages/auth/login.php';
            return; // Stop execution
        }


        $identifier = $_POST['identifier'] ?? '';
        $password = $_POST['password'] ?? '';

        try {
            // Erstelle die notwendigen Objekte.
            // $userRepository = new UserRepository($this->pdo); // Bereits im Konstruktor
            // Assuming LoginAttemptRepository is needed by AuthenticationService
            $loginAttemptRepository = new \App\Repositories\LoginAttemptRepository($this->pdo);
            $authService = new AuthenticationService($this->userRepository, $loginAttemptRepository);

            // Führe den Login-Versuch durch.
            $userData = $authService->login($identifier, $password);

            // Wenn der Login erfolgreich war (kein Fehler geworfen wurde):
            session_regenerate_id(true); // Wichtig für die Sicherheit
            $_SESSION['user_id'] = $userData['user_id'];
            $_SESSION['username'] = $userData['username'];
            $_SESSION['user_role'] = $userData['role'];
            // Store/Ensure CSRF token after successful login and session regeneration
            Security::getCsrfToken();


            // Leite zum Dashboard weiter.
            header("Location: " . Utils::url('dashboard'));
            exit();

        } catch (Exception $e) {
            // Wenn der Login fehlschlägt, fängt der Catch-Block den Fehler ab.
            // Wir speichern die Fehlermeldung und laden die Login-Seite erneut.
            $message = $e->getMessage();
            $page_title = 'Login';
             // Ensure a CSRF token is available for the re-rendered form
             Security::getCsrfToken();
            include_once dirname(__DIR__, 4) . '/pages/auth/login.php';
        }
    }


    public function showLogin()
    {
        global $config; // Wird für die Basis-URL in den Views benötigt.
        $config = Database::getConfig();
        $page_title = 'Login';
        $message = $_SESSION['flash_message'] ?? '';
        unset($_SESSION['flash_message']);
        // Ensure a CSRF token is available for the form
        Security::getCsrfToken();
        include_once dirname(__DIR__, 4) . '/pages/auth/login.php';
    }

    public function logout()
    {
        $_SESSION = [];
        session_destroy();
        session_start(); // Start a new session for the flash message
        $_SESSION['flash_message'] = "Sie wurden erfolgreich abgemeldet.";
        header("Location: " . Utils::url('login'));
        exit();
    }
    
    /**
     * NEU: Beendet die Impersonation und stellt die Admin-Sitzung wieder her.
     */
    public function revertImpersonation()
    {
        // 1. Prüfen, ob wir überhaupt in einer Impersonation-Sitzung sind
        if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['impersonator_id'])) {
            // Falls nicht, einfach normal ausloggen
            $this->logout();
            return;
        }

        $impersonatedUserId = $_SESSION['user_id'] ?? 0;
        $adminUserId = $_SESSION['impersonator_id'];

        // 2. Aktuelle (impersonierte) Sitzung zerstören
        $_SESSION = [];
        session_destroy();
        
        // 3. Neue, saubere Session für den Admin starten
        session_start(); 
        session_regenerate_id(true);

        try {
            // 4. Admin-Benutzerdaten holen
            $adminUser = $this->userRepository->findById($adminUserId);
            if (!$adminUser || $adminUser['role'] !== 'admin') {
                throw new Exception("Ursprünglicher Benutzer ist kein Admin.");
            }

            // 5. Admin-Session manuell aufbauen (genau wie in handleLogin)
            $_SESSION['user_id'] = $adminUser['user_id'];
            $_SESSION['username'] = $adminUser['username'];
            $_SESSION['user_role'] = $adminUser['role'];
            // (Die 'impersonator_id' ist nicht mehr vorhanden, da die Session neu ist)
            
            // 6. CSRF-Token für die neue Admin-Session setzen
            Security::getCsrfToken();

            // 7. Aktion protokollieren
            AuditLogger::log(
                'impersonate_revert',
                'user',
                $adminUserId, // Der Admin, der die Aktion ausführt
                ['reverted_from_user_id' => $impersonatedUserId]
            );

            // 8. Zurück zur Benutzerliste im Admin-Panel
            header("Location: " . Utils::url('admin/users'));
            exit();

        } catch (Exception $e) {
            // Im schlimmsten Fall (Admin-Konto existiert nicht mehr?), sicher ausloggen
            error_log("Fehler bei revertImpersonation: " . $e->getMessage());
            $this->logout(); // Führt zu einer sauberen Logout-Weiterleitung
        }
    }
}