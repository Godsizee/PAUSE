<?php
// app/Services/AuthenticationService.php
namespace App\Services;

use App\Repositories\UserRepository;
use App\Repositories\LoginAttemptRepository;
use Exception;
// NEU: AuditLogger importieren
use App\Services\AuditLogger;

class AuthenticationService
{
    private UserRepository $userRepository;
    private LoginAttemptRepository $loginAttemptRepository;

    public function __construct(UserRepository $userRepository, LoginAttemptRepository $loginAttemptRepository)
    {
        $this->userRepository = $userRepository;
        $this->loginAttemptRepository = $loginAttemptRepository;
    }

    /**
     * Führt den kompletten Login-Prozess aus, inklusive Brute-Force-Schutz.
     * @param string $identifier Der eingegebene Benutzername oder die E-Mail.
     * @param string $password Das eingegebene Passwort.
     * @return array Die Benutzerdaten bei einem erfolgreichen Login.
     * @throws Exception Wenn die Anmeldedaten ungültig sind oder der Account gesperrt ist.
     */
    public function login(string $identifier, string $password): array
    {
        // Schritt 1: Prüfen, ob der Login-Versuch überhaupt erlaubt ist (Brute-Force-Schutz)
        if (!$this->loginAttemptRepository->isAllowed($identifier)) {
            // NEU: Audit-Log für gesperrten Account
            AuditLogger::log(
                'login_lockout', 
                'user', 
                $identifier, 
                ['message' => 'Zu viele Login-Versuche.']
            );
            throw new Exception("Zu viele fehlgeschlagene Login-Versuche. Ihr Account ist vorübergehend gesperrt.");
        }

        // Schritt 2: Versuche, den Benutzer in der Datenbank zu finden.
        $user = $this->userRepository->findByUsernameOrEmail($identifier);

        // Schritt 3: Prüfen, ob ein Benutzer gefunden wurde UND ob das Passwort korrekt ist.
        if ($user && password_verify($password, $user['password_hash'])) {
            // Erfolg! Login-Versuche zurücksetzen und Benutzerdaten zurückgeben.
            $this->loginAttemptRepository->clearAttempts($identifier);
            
            // NEU: Erfolgreichen Login protokollieren
            // Temporäre Session-Daten setzen, damit der Logger die User-ID findet
            $_SESSION['user_id'] = $user['user_id']; 
            AuditLogger::log(
                'login_success', 
                'user', 
                $user['user_id']
            );
            // Session-ID wird im AuthController regeneriert
            
            // NEU: Sperrstatus für Community Board in die Session laden
            $_SESSION['is_community_banned'] = (int)($user['is_community_banned'] ?? 0);


            return $user;
        }

        // Schritt 4: Wenn die Prüfung fehlschlägt, einen fehlgeschlagenen Versuch protokollieren und einen Fehler werfen.
        $this->loginAttemptRepository->recordFailure($identifier);
        
        // NEU: Fehlgeschlagenen Login protokollieren
        AuditLogger::log(
            'login_failure', 
            'user', 
            $identifier, 
            ['message' => 'Falscher Benutzername oder Passwort.']
        );
        
        throw new Exception("Benutzername oder Passwort ist falsch.");
    }
}