<?php
// app/Http/Controllers/CommunityController.php

// MODIFIZIERT:
// 1. ApiHandlerTrait importiert und verwendet.
// 2. Alle API-Methoden (getPostsApi, getMyPostsApi, createPostApi, updatePostApi,
//    approvePostApi, rejectPostApi, deletePostApi) nutzen jetzt handleApiRequest().
// 3. inputType 'get' für Lesezugriffe, 'json' für Schreibzugriffe (da JS JSON sendet).
// 4. Manuelle Security-Checks (requireLogin, verifyCsrfToken) wurden in die Trait-Optionen verlagert.
// 5. Manuelle json_encode/decode, header() und try/catch-Blöcke entfernt.
// 6. AuditLogger-Aufrufe in die 'log_action'-Rückgaben für den Trait umgewandelt.

namespace App\Http\Controllers;

use App\Core\Database;
use App\Core\Security; // Wird vom Trait intern genutzt
use App\Repositories\CommunityPostRepository;
use App\Services\AuditLogger; // Import bleibt (wird vom Trait genutzt)
use App\Http\Traits\ApiHandlerTrait; // NEU: Trait importieren
use Exception;
use PDO;
use Parsedown; // KORRIGIERT: Backslash entfernt

class CommunityController
{
    // NEU: Trait für API-Behandlung einbinden
    use ApiHandlerTrait;

    private PDO $pdo;
    private CommunityPostRepository $postRepo;
    private Parsedown $parsedown;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
        $this->postRepo = new CommunityPostRepository($this->pdo);
        $this->parsedown = new Parsedown();
        $this->parsedown->setSafeMode(true);
    }

    /**
     * API: Holt die letzten 50 freigegebenen Beiträge für das Dashboard.
     * MODIFIZIERT: Nutzt ApiHandlerTrait. $data ist $_GET.
     */
    public function getPostsApi()
    {
        $this->handleApiRequest(function($data) { // $data ist $_GET
            
            $posts = $this->postRepo->getApprovedPostsWithAuthorEmail(50);

            foreach ($posts as &$post) {
                $post['content_html'] = $this->parsedown->text($post['content'] ?? '');
            }
            unset($post);

            // Rückgabe für Trait
            return [
                'json_response' => ['success' => true, 'data' => $posts]
            ];

        }, [
            'inputType' => 'get',
            'checkRole' => ['schueler', 'lehrer', 'planer', 'admin'] // Jede eingeloggte Rolle
        ]);
    }

    /**
     * API: Holt alle Beiträge, die vom aktuell eingeloggten Benutzer erstellt wurden.
     * MODIFIZIERT: Nutzt ApiHandlerTrait. $data ist $_GET.
     */
    public function getMyPostsApi()
    {
        $this->handleApiRequest(function($data) { // $data ist $_GET
            
            $userId = $_SESSION['user_id'];
            $posts = $this->postRepo->getPostsByUserId($userId);

            foreach ($posts as &$post) {
                $post['content_html'] = $this->parsedown->text($post['content'] ?? '');
            }
            unset($post);

            // Rückgabe für Trait
            return [
                'json_response' => ['success' => true, 'data' => $posts]
            ];

        }, [
            'inputType' => 'get',
            'checkRole' => ['schueler', 'lehrer', 'planer', 'admin'] // Jede eingeloggte Rolle
        ]);
    }

    /**
     * API: Erstellt einen neuen Beitrag.
     * MODIFIZIERT: Nutzt ApiHandlerTrait. $data ist geparstes JSON.
     */
    public function createPostApi()
    {
        $this->handleApiRequest(function($data) { // $data ist JSON-Body
            
            $userId = $_SESSION['user_id'];
            $userRole = $_SESSION['user_role'];

            $title = trim($data['title'] ?? '');
            $content = trim($data['content'] ?? '');

            if (empty($title) || empty($content)) {
                throw new Exception("Titel und Inhalt dürfen nicht leer sein.", 400);
            }

            $allowedToAutoApprove = ['admin', 'planer', 'lehrer'];
            $initialStatus = in_array($userRole, $allowedToAutoApprove) ? 'approved' : 'pending';

            $newPostId = $this->postRepo->createPost($userId, $title, $content, $initialStatus);
            
            $message = ($initialStatus === 'approved')
                ? 'Beitrag erfolgreich veröffentlicht.'
                : 'Beitrag wurde zur Moderation eingereicht.';

            // Log-Details
            $logDetails = [
                'title' => $title,
                'status' => $initialStatus
            ];

            // Rückgabe für Trait
            return [
                'json_response' => ['success' => true, 'message' => $message, 'status' => $initialStatus],
                'log_action' => 'create_community_post',
                'log_target_type' => 'community_post',
                'log_target_id' => $newPostId,
                'log_details' => $logDetails
            ];

        }, [
            'inputType' => 'json',
            'checkRole' => ['schueler', 'lehrer', 'planer', 'admin'] // Jede eingeloggte Rolle
        ]);
    }

    /**
     * API: Aktualisiert einen bestehenden Beitrag.
     * MODIFIZIERT: Nutzt ApiHandlerTrait. $data ist geparstes JSON.
     */
    public function updatePostApi()
    {
        $this->handleApiRequest(function($data) { // $data ist JSON-Body
            
            $userId = $_SESSION['user_id'];
            $userRole = $_SESSION['user_role'];

            $postId = filter_var($data['post_id'] ?? null, FILTER_VALIDATE_INT);
            $title = trim($data['title'] ?? '');
            $content = trim($data['content'] ?? '');

            if (!$postId || empty($title) || empty($content)) {
                throw new Exception("ID, Titel und Inhalt dürfen nicht leer sein.", 400);
            }

            $post = $this->postRepo->getPostById($postId);
            if (!$post) {
                throw new Exception("Beitrag nicht gefunden.", 404);
            }

            $isOwner = ($post['user_id'] == $userId);
            $isModerator = in_array($userRole, ['admin', 'planer']);

            if (!$isOwner && !$isModerator) {
                throw new Exception("Sie sind nicht berechtigt, diesen Beitrag zu bearbeiten.", 403);
            }

            $newStatus = $post['status'];
            if ($isOwner && !$isModerator) {
                $newStatus = 'pending';
            }
            
            $moderatorId = $isModerator ? $userId : null;

            // ANNAHME: Die 'updatePost'-Methode im Repository wurde (in V10) so angepasst,
            // dass sie (int $postId, string $title, string $content, string $newStatus, ?int $moderatorId) akzeptiert.
            $success = $this->postRepo->updatePost($postId, $title, $content, $newStatus, $moderatorId);

            if (!$success) {
                throw new Exception("Beitrag konnte nicht aktualisiert werden (möglicherweise keine Berechtigung oder Daten waren identisch).", 500);
            }
            
            $message = ($newStatus === 'pending')
                ? 'Beitrag aktualisiert und zur erneuten Moderation eingereicht.'
                : 'Beitrag erfolgreich aktualisiert.';

            // Log-Details
            $logDetails = [
                'title' => $title,
                'new_status' => $newStatus
            ];

            // Rückgabe für Trait
            return [
                'json_response' => ['success' => true, 'message' => $message, 'new_status' => $newStatus],
                'log_action' => 'update_community_post',
                'log_target_type' => 'community_post',
                'log_target_id' => $postId,
                'log_details' => $logDetails
            ];

        }, [
            'inputType' => 'json',
            'checkRole' => ['schueler', 'lehrer', 'planer', 'admin'] // Jede eingeloggte Rolle
        ]);
    }

    /**
     * API: Genehmigt einen Beitrag (Admin/Planer).
     * MODIFIZIERT: Nutzt ApiHandlerTrait. $data ist geparstes JSON.
     */
    public function approvePostApi()
    {
        $this->handleApiRequest(function($data) { // $data ist JSON-Body
            
            $moderatorId = $_SESSION['user_id'];
            $postId = filter_var($data['post_id'] ?? null, FILTER_VALIDATE_INT);

            if (!$postId) {
                throw new Exception("Ungültige Beitrags-ID.", 400);
            }

            $success = $this->postRepo->updatePostStatus($postId, 'approved', $moderatorId);

            if (!$success) {
                throw new Exception("Beitrag konnte nicht freigegeben werden (vielleicht schon moderiert?).", 404);
            }
            
            // Rückgabe für Trait
            return [
                'json_response' => ['success' => true, 'message' => 'Beitrag freigegeben.'],
                'log_action' => 'approve_community_post',
                'log_target_type' => 'community_post',
                'log_target_id' => $postId
            ];

        }, [
            'inputType' => 'json',
            'checkRole' => ['admin', 'planer']
        ]);
    }
    
    /**
     * API: Lehnt einen Beitrag ab (Admin/Planer).
     * MODIFIZIERT: Nutzt ApiHandlerTrait. $data ist geparstes JSON.
     */
    public function rejectPostApi()
    {
        $this->handleApiRequest(function($data) { // $data ist JSON-Body
            
            $moderatorId = $_SESSION['user_id'];
            $postId = filter_var($data['post_id'] ?? null, FILTER_VALIDATE_INT);

            if (!$postId) {
                throw new Exception("Ungültige Beitrags-ID.", 400);
            }
            
            $post = $this->postRepo->getPostById($postId);
            $success = $this->postRepo->updatePostStatus($postId, 'rejected', $moderatorId);

            if (!$success) {
                throw new Exception("Beitrag konnte nicht abgelehnt werden (vielleicht schon moderiert?).", 404);
            }

            // Log-Details
            $logDetails = ['title' => $post['title'] ?? 'N/A'];

            // Rückgabe für Trait
            return [
                'json_response' => ['success' => true, 'message' => 'Beitrag abgelehnt.'],
                'log_action' => 'reject_community_post',
                'log_target_type' => 'community_post',
                'log_target_id' => $postId,
                'log_details' => $logDetails
            ];

        }, [
            'inputType' => 'json',
            'checkRole' => ['admin', 'planer']
        ]);
    }

    /**
     * API: Löscht einen Beitrag (Admin, Planer oder Ersteller).
     * MODIFIZIERT: Nutzt ApiHandlerTrait. $data ist geparstes JSON.
     */
    public function deletePostApi()
    {
        $this->handleApiRequest(function($data) { // $data ist JSON-Body
            
            $userId = $_SESSION['user_id'];
            $userRole = $_SESSION['user_role'];

            $postId = filter_var($data['post_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$postId) {
                throw new Exception("Ungültige Beitrags-ID.", 400);
            }

            $post = $this->postRepo->getPostById($postId);
            if (!$post) {
                throw new Exception("Beitrag nicht gefunden.", 404);
            }

            $isOwner = ($post['user_id'] == $userId);
            $isModerator = in_array($userRole, ['admin', 'planer']);

            if (!$isOwner && !$isModerator) {
                throw new Exception("Sie sind nicht berechtigt, diesen Beitrag zu löschen.", 403);
            }

            $success = $this->postRepo->deletePost($postId);
            if (!$success) {
                throw new Exception("Beitrag konnte nicht gelöscht werden.", 500);
            }

            // Log-Details
            $logDetails = [
                'title' => $post['title'] ?? 'N/A',
                'deleted_by' => $userRole
            ];

            // Rückgabe für Trait
            return [
                'json_response' => ['success' => true, 'message' => 'Beitrag erfolgreich gelöscht.'],
                'log_action' => 'delete_community_post',
                'log_target_type' => 'community_post',
                'log_target_id' => $postId,
                'log_details' => $logDetails
            ];

        }, [
            'inputType' => 'json',
            'checkRole' => ['schueler', 'lehrer', 'planer', 'admin'] // Jede eingeloggte Rolle
        ]);
    }
}