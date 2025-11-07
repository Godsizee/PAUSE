<?php
// app/Services/ImpersonationService.php

namespace App\Services;

use App\Repositories\UserRepository;
use App\Core\Security;
use App\Core\Utils;
use Exception;

/**
 * Service-Klasse zur Kapselung der Logik für User-Impersonation.
 * Stellt Methoden zum Starten und Beenden der Impersonation bereit.
 */
class ImpersonationService
{
    private UserRepository $userRepository;

    /**
     * @param UserRepository $userRepository Wird benötigt, um Benutzerdaten zu holen.
     */
    public function __construct(UserRepository $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    /**
     * Startet die Impersonation für einen Zielbenutzer.
     *
     * @param int $targetUserId Der Benutzer, als der man sich anmelden möchte.
     * @param int $adminUserId Der Admin, der die Aktion ausführt.
     * @return array Der Zielbenutzer (für Logging-Zwecke).
     * @throws Exception Wenn Validierungen fehlschlagen.
     */
    public function start(int $targetUserId, int $adminUserId): array
    {
        if ($targetUserId == $adminUserId) {
            throw new Exception("Sie können sich nicht selbst imitieren.", 400);
        }

        // 1. Zieldaten des Benutzers abrufen
        $targetUser = $this->userRepository->findById($targetUserId);
        if (!$targetUser) {
            throw new Exception("Zielbenutzer nicht gefunden.", 404);
        }

        // 2. Admin-ID in der aktuellen Session sichern
        $_SESSION['impersonator_id'] = $adminUserId;

        // 3. Aktuelle Sitzungsdaten mit Zieldaten überschreiben
        // Wichtig für die Sicherheit: Regeneriert die Session-ID
        session_regenerate_id(true);

        $_SESSION['user_id'] = $targetUser['user_id'];
        $_SESSION['username'] = $targetUser['username'];
        $_SESSION['user_role'] = $targetUser['role'];
        // NEU: Community-Sperrstatus ebenfalls setzen
        $_SESSION['is_community_banned'] = (int)($targetUser['is_community_banned'] ?? 0);

        // 4. Admin-ID erneut sichern (da die Session erneuert wurde)
        $_SESSION['impersonator_id'] = $adminUserId;

        // 5. CSRF-Token für die neue Session neu generieren
        // (Wichtig, da das alte Token ungültig ist)
        Security::getCsrfToken();

        // 6. Zielbenutzerdaten für das Logging im Controller zurückgeben
        return $targetUser;
    }

    /**
     * Beendet die Impersonation und stellt die Admin-Sitzung wieder her.
     *
     * @return array ['adminUser' => array, 'impersonatedUserId' => int]
     * @throws Exception Wenn die Wiederherstellung fehlschlägt.
     */
    public function revert(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['impersonator_id'])) {
            // Dies sollte nicht passieren, wenn die Route geschützt ist,
            // aber zur Sicherheit.
            throw new Exception("Keine aktive Impersonation-Sitzung gefunden.", 400);
        }

        $impersonatedUserId = $_SESSION['user_id'] ?? 0;
        $adminUserId = $_SESSION['impersonator_id'];

        // 1. Aktuelle (impersonierte) Sitzung zerstören
        $_SESSION = [];
        session_destroy();

        // 2. Neue, saubere Session für den Admin starten
        session_start();
        session_regenerate_id(true);

        // 3. Admin-Benutzerdaten holen
        $adminUser = $this->userRepository->findById($adminUserId);
        if (!$adminUser || !in_array($adminUser['role'], ['admin', 'planer'])) { // Admins/Planer dürfen
            error_log("Impersonation Revert Failed: Original user {$adminUserId} is no longer an admin/planer.");
            throw new Exception("Ursprünglicher Benutzer konnte nicht wiederhergestellt werden.", 403);
        }

        // 4. Admin-Session manuell aufbauen
        $_SESSION['user_id'] = $adminUser['user_id'];
        $_SESSION['username'] = $adminUser['username'];
        $_SESSION['user_role'] = $adminUser['role'];
        $_SESSION['is_community_banned'] = 0; // Admins/Planer sind nie gesperrt
        // (Die 'impersonator_id' ist jetzt weg)

        // 5. CSRF-Token für die neue Admin-Session setzen
        Security::getCsrfToken();

        // 6. Daten für Logging zurückgeben
        return [
            'adminUser' => $adminUser,
            'impersonatedUserId' => $impersonatedUserId
        ];
    }
}