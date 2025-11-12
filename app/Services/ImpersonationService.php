<?php
namespace App\Services;
use App\Repositories\UserRepository;
use App\Core\Security;
use App\Core\Utils;
use Exception;
class ImpersonationService
{
    private UserRepository $userRepository;
    public function __construct(UserRepository $userRepository)
    {
        $this->userRepository = $userRepository;
    }
    public function start(int $targetUserId, int $adminUserId): array
    {
        if ($targetUserId == $adminUserId) {
            throw new Exception("Sie können sich nicht selbst imitieren.", 400);
        }
        $targetUser = $this->userRepository->findById($targetUserId);
        if (!$targetUser) {
            throw new Exception("Zielbenutzer nicht gefunden.", 404);
        }
        $_SESSION['impersonator_id'] = $adminUserId;
        session_regenerate_id(true);
        $_SESSION['user_id'] = $targetUser['user_id'];
        $_SESSION['username'] = $targetUser['username'];
        $_SESSION['user_role'] = $targetUser['role'];
        $_SESSION['is_community_banned'] = (int)($targetUser['is_community_banned'] ?? 0);
        $_SESSION['impersonator_id'] = $adminUserId;
        Security::getCsrfToken();
        return $targetUser;
    }
    public function revert(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['impersonator_id'])) {
            throw new Exception("Keine aktive Impersonation-Sitzung gefunden.", 400);
        }
        $impersonatedUserId = $_SESSION['user_id'] ?? 0;
        $adminUserId = $_SESSION['impersonator_id'];
        $_SESSION = [];
        session_destroy();
        session_start();
        session_regenerate_id(true);
        $adminUser = $this->userRepository->findById($adminUserId);
        if (!$adminUser || !in_array($adminUser['role'], ['admin', 'planer'])) { 
            error_log("Impersonation Revert Failed: Original user {$adminUserId} is no longer an admin/planer.");
            throw new Exception("Ursprünglicher Benutzer konnte nicht wiederhergestellt werden.", 403);
        }
        $_SESSION['user_id'] = $adminUser['user_id'];
        $_SESSION['username'] = $adminUser['username'];
        $_SESSION['user_role'] = $adminUser['role'];
        $_SESSION['is_community_banned'] = 0; 
        Security::getCsrfToken();
        return [
            'adminUser' => $adminUser,
            'impersonatedUserId' => $impersonatedUserId
        ];
    }
}