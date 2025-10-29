<?php
// app/Http/Controllers/Auth/AuthController.php
namespace App\Http\Controllers\Auth;

use App\Core\Database;
use App\Core\Utils;
use App\Core\Security; // Use statement
use App\Repositories\UserRepository;
use App\Services\AuthenticationService;
use Exception;
use PDO;

class AuthController
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
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
            $userRepository = new UserRepository($this->pdo);
            // Assuming LoginAttemptRepository is needed by AuthenticationService
            $loginAttemptRepository = new \App\Repositories\LoginAttemptRepository($this->pdo);
            $authService = new AuthenticationService($userRepository, $loginAttemptRepository);

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
}

