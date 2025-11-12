<?php
namespace App\Services;
use App\Repositories\UserRepository;
use App\Repositories\LoginAttemptRepository;
use Exception;
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
    public function login(string $identifier, string $password): array
    {
        if (!$this->loginAttemptRepository->isAllowed($identifier)) {
            AuditLogger::log(
                'login_lockout', 
                'user', 
                $identifier, 
                ['message' => 'Zu viele Login-Versuche.']
            );
            throw new Exception("Zu viele fehlgeschlagene Login-Versuche. Ihr Account ist vorübergehend gesperrt.");
        }
        $user = $this->userRepository->findByUsernameOrEmail($identifier);
        if ($user && password_verify($password, $user['password_hash'])) {
            $this->loginAttemptRepository->clearAttempts($identifier);
            $_SESSION['user_id'] = $user['user_id']; 
            AuditLogger::log(
                'login_success', 
                'user', 
                $user['user_id']
            );
            $_SESSION['is_community_banned'] = (int)($user['is_community_banned'] ?? 0);
            return $user;
        }
        $this->loginAttemptRepository->recordFailure($identifier);
        AuditLogger::log(
            'login_failure', 
            'user', 
            $identifier, 
            ['message' => 'Falscher Benutzername oder Passwort.']
        );
        throw new Exception("Benutzername oder Passwort ist falsch.");
    }
}