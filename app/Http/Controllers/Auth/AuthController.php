<?php
namespace App\Http\Controllers\Auth;
use App\Core\Database;
use App\Core\Utils;
use App\Core\Security; 
use App\Repositories\UserRepository;
use App\Services\AuthenticationService;
use App\Services\AuditLogger; 
use Exception;
use PDO;
class AuthController
{
    private PDO $pdo;
    private UserRepository $userRepository; 
    public function __construct()
    {
        $this->pdo = Database::getInstance();
        $this->userRepository = new UserRepository($this->pdo); 
    }
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
            $loginAttemptRepository = new \App\Repositories\LoginAttemptRepository($this->pdo);
            $authService = new AuthenticationService($this->userRepository, $loginAttemptRepository);
            $userData = $authService->login($identifier, $password);
            session_regenerate_id(true); 
            $_SESSION['user_id'] = $userData['user_id'];
            $_SESSION['username'] = $userData['username'];
            $_SESSION['user_role'] = $userData['role'];
            Security::getCsrfToken();
            header("Location: " . Utils::url('dashboard'));
            exit();
        } catch (Exception $e) {
            $message = $e->getMessage();
            $page_title = 'Login';
             Security::getCsrfToken();
            include_once dirname(__DIR__, 4) . '/pages/auth/login.php';
        }
    }
    public function showLogin()
    {
        global $config; 
        $config = Database::getConfig();
        $page_title = 'Login';
        $message = $_SESSION['flash_message'] ?? '';
        unset($_SESSION['flash_message']);
        Security::getCsrfToken();
        include_once dirname(__DIR__, 4) . '/pages/auth/login.php';
    }
    public function logout()
    {
        $_SESSION = [];
        session_destroy();
        session_start(); 
        $_SESSION['flash_message'] = "Sie wurden erfolgreich abgemeldet.";
        header("Location: " . Utils::url('login'));
        exit();
    }
    public function revertImpersonation()
    {
        if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['impersonator_id'])) {
            $this->logout();
            return;
        }
        $impersonatedUserId = $_SESSION['user_id'] ?? 0;
        $adminUserId = $_SESSION['impersonator_id'];
        $_SESSION = [];
        session_destroy();
        session_start(); 
        session_regenerate_id(true);
        try {
            $adminUser = $this->userRepository->findById($adminUserId);
            if (!$adminUser || $adminUser['role'] !== 'admin') {
                throw new Exception("Ursprünglicher Benutzer ist kein Admin.");
            }
            $_SESSION['user_id'] = $adminUser['user_id'];
            $_SESSION['username'] = $adminUser['username'];
            $_SESSION['user_role'] = $adminUser['role'];
            Security::getCsrfToken();
            AuditLogger::log(
                'impersonate_revert',
                'user',
                $adminUserId, 
                ['reverted_from_user_id' => $impersonatedUserId]
            );
            header("Location: " . Utils::url('admin/users'));
            exit();
        } catch (Exception $e) {
            error_log("Fehler bei revertImpersonation: " . $e->getMessage());
            $this->logout(); 
        }
    }
}