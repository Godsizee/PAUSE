<?php
// app/Http/Controllers/Auth/AuthController.php

// MODIFIZIERT:
// 1. ImpersonationService importiert und im Konstruktor injiziert.
// 2. revertImpersonation() wurde refaktorisiert:
//    - Die gesamte Session-Logik wurde entfernt.
//    - Ruft jetzt $this->impersonationService->revert() auf.
//    - Nutzt die Rückgabe des Service für das Audit-Log.
//    - Leitet bei Erfolg zum Admin-Dashboard (Benutzerliste) weiter.
// 3. KORREKTUR: Syntaxfehler (eingefügtes "Dienstag") in handleLogin() entfernt.

namespace App\Http\Controllers\Auth;

use App\Core\Database;
use App\Core\Utils;
use App\Core\Security;
use App\Repositories\UserRepository;
use App\Services\AuthenticationService;
use App\Services\AuditLogger;
use App\Services\ImpersonationService; // NEU: Service importieren
use Exception;
use PDO;

class AuthController
{
    private PDO $pdo;
    private UserRepository $userRepository;
    private ImpersonationService $impersonationService; // NEU

    public function __construct()
    {
        $this->pdo = Database::getInstance();
        $this->userRepository = new UserRepository($this->pdo);
        // NEU: Service instanziieren (benötigt UserRepository)
        $this->impersonationService = new ImpersonationService($this->userRepository);
    }

    /**
     * Verarbeitet die POST-Anfrage vom Login-Formular.
     * KORRIGIERT: Syntaxfehler entfernt.
     */
    public function handleLogin()
    {
        try {
            Security::verifyCsrfToken();
        } catch (Exception $e) {
            $message = $e->getMessage();
            $page_title = 'Login';
            Security::getCsrfToken();
            include_once dirname(__DIR__, 4) . '/pages/auth/login.php';
            return;
        }

        $identifier = $_POST['identifier'] ?? '';
        $password = $_POST['password'] ?? '';

        try {
            // Erstelle die notwendigen Objekte.
            $loginAttemptRepository = new \App\Repositories\LoginAttemptRepository($this->pdo);
            $authService = new AuthenticationService($this->userRepository, $loginAttemptRepository);

            // Führe den Login-Versuch durch.
            $userData = $authService->login($identifier, $password);

            // Wenn der Login erfolgreich war (kein Fehler geworfen wurde):
            session_regenerate_id(true); // Wichtig für die Sicherheit
            $_SESSION['user_id'] = $userData['user_id'];
            $_SESSION['username'] = $userData['username'];
            $_SESSION['user_role'] = $userData['role'];
            // NEU: Sperrstatus in Session geladen (passiert in AuthenticationService)
            
            Security::getCsrfToken();

            // Leite zum Dashboard weiter.
            // KORREKTUR: "Dienstag" entfernt
            header("Location: " . Utils::url('dashboard'));
            exit();

        } catch (Exception $e) {
            $message = $e->getMessage();
            $page_title = 'Login';
            Security::getCsrfToken();
            include_once dirname(__DIR__, 4) . '/pages/auth/login.php';
        }
    }

    /**
     * Zeigt die Login-Seite an.
     * (Unverändert)
     */
    public function showLogin()
    {
        global $config;
        $config = Database::getConfig(); // KORREKTUR: getConfig() statt getInstance()
        $page_title = 'Login';
        $message = $_SESSION['flash_message'] ?? '';
        unset($_SESSION['flash_message']);
        Security::getCsrfToken();
        include_once dirname(__DIR__, 4) . '/pages/auth/login.php';
    }

    /**
     * Loggt den Benutzer aus.
     * (Unverändert)
     */
    public function logout()
    {
        $_SESSION = [];
        session_destroy();
        session_start();
        $_SESSION['flash_message'] = "Sie wurden erfolgreich abgemeldet.";
        header("Location: " . Utils::url('login'));
        exit();
    }
    
    /**
     * Beendet die Impersonation und stellt die Admin-Sitzung wieder her.
     * MODIFIZIERT: Nutzt jetzt ImpersonationService.
     */
    public function revertImpersonation()
    {
        try {
            // 1. Logik an den Service delegieren
            // Der Service kümmert sich um Session-Zerstörung, Neuerstellung und DB-Abfragen
            $result = $this->impersonationService->revert();
            
            $adminUser = $result['adminUser'];
            $impersonatedUserId = $result['impersonatedUserId'];

            // 2. Aktion protokollieren (Session wurde bereits vom Service wiederhergestellt)
            AuditLogger::log(
                'impersonate_revert',
                'user',
                $adminUser['user_id'], // Der Admin, der die Aktion ausführt
                ['reverted_from_user_id' => $impersonatedUserId]
            );

            // 3. Zurück zur Benutzerliste im Admin-Panel
            header("Location: " . Utils::url('admin/users'));
            exit();

        } catch (Exception $e) {
            // Im schlimmsten Fall (Service wirft Fehler), sicher ausloggen
            error_log("Fehler bei revertImpersonation: " . $e->getMessage());
            // Rufe die lokale logout()-Methode auf, um eine saubere Weiterleitung zu gewährleisten
            $this->logout();
        }
    }
}