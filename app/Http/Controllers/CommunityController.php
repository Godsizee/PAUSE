<?php
// app/Http/Controllers/CommunityController.php

namespace App\Http\Controllers;

use App\Core\Database;
use App\Core\Security;
use App\Repositories\CommunityPostRepository;
use App\Services\AuditLogger;
use Exception;
use PDO;
use \Parsedown; // KORREKTUR: Parsedown aus dem globalen Namespace importieren

class CommunityController
{
    private PDO $pdo;
    private CommunityPostRepository $postRepo;
    private Parsedown $parsedown; // KORREKTUR: Typehint kann jetzt ohne Backslash sein

    public function __construct()
    {
        $this->pdo = Database::getInstance();
        $this->postRepo = new CommunityPostRepository($this->pdo);
        $this->parsedown = new Parsedown(); // KORREKTUR: Kann jetzt direkt verwendet werden
        $this->parsedown->setSafeMode(true);
    }

    /**
     * API: Holt die letzten 50 freigegebenen Beiträge für das Dashboard.
     */
    public function getPostsApi()
    {
        Security::requireLogin();
        header('Content-Type: application/json');

        try {
            // KORREKTUR: Rufe die Methode im Repository auf, die E-Mails mitlädt
            $posts = $this->postRepo->getApprovedPostsWithAuthorEmail(50);

            // Konvertiere Markdown zu HTML
            foreach ($posts as &$post) {
                $post['content_html'] = $this->parsedown->text($post['content'] ?? '');
            }
            unset($post);

            echo json_encode(['success' => true, 'data' => $posts]);

        } catch (Exception $e) {
            http_response_code(500);
            error_log("API Error (getPostsApi): " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Fehler beim Laden der Beiträge.']);
        }
        exit();
    }

    /**
     * NEU: API: Holt alle Beiträge, die vom aktuell eingeloggten Benutzer erstellt wurden.
     */
    public function getMyPostsApi()
    {
        Security::requireLogin();
        header('Content-Type: application/json');

        try {
            $userId = $_SESSION['user_id'];
            $posts = $this->postRepo->getPostsByUserId($userId);

            // Konvertiere Markdown zu HTML
            foreach ($posts as &$post) {
                $post['content_html'] = $this->parsedown->text($post['content'] ?? '');
            }
            unset($post);

            echo json_encode(['success' => true, 'data' => $posts]);

        } catch (Exception $e) {
            http_response_code(500);
            error_log("API Error (getMyPostsApi): " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Fehler beim Laden Ihrer Beiträge.']);
        }
        exit();
    }


    /**
     * API: Erstellt einen neuen Beitrag.
     * Admins/Planer/Lehrer werden sofort freigeschaltet, Schüler müssen moderiert werden.
     */
    public function createPostApi()
    {
        Security::requireLogin();
        Security::verifyCsrfToken();
        header('Content-Type: application/json');

        try {
            $userId = $_SESSION['user_id'];
            $userRole = $_SESSION['user_role'];

            $data = json_decode(file_get_contents('php://input'), true);
            $title = trim($data['title'] ?? '');
            $content = trim($data['content'] ?? '');

            if (empty($title) || empty($content)) {
                throw new Exception("Titel und Inhalt dürfen nicht leer sein.", 400);
            }

            // Admins, Planer und Lehrer dürfen ohne Moderation posten
            $allowedToAutoApprove = ['admin', 'planer', 'lehrer'];
            $initialStatus = in_array($userRole, $allowedToAutoApprove) ? 'approved' : 'pending';

            $newPostId = $this->postRepo->createPost($userId, $title, $content, $initialStatus);
            
            AuditLogger::log('create_community_post', 'community_post', $newPostId, [
                'title' => $title,
                'status' => $initialStatus
            ]);

            $message = ($initialStatus === 'approved')
                ? 'Beitrag erfolgreich veröffentlicht.'
                : 'Beitrag wurde zur Moderation eingereicht.';

            echo json_encode(['success' => true, 'message' => $message, 'status' => $initialStatus]);

        } catch (Exception $e) {
            $code = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
            http_response_code($code);
            error_log("API Error (createPostApi): " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Fehler beim Erstellen des Beitrags: ' . $e->getMessage()]);
        }
        exit();
    }

    /**
     * NEU: API: Aktualisiert einen bestehenden Beitrag.
     * Nur der Ersteller (Schüler) oder Admins/Planer dürfen dies.
     * Setzt den Status bei Schüler-Bearbeitung auf 'pending' zurück.
     */
    public function updatePostApi()
    {
        Security::requireLogin();
        Security::verifyCsrfToken();
        header('Content-Type: application/json');

        try {
            $userId = $_SESSION['user_id'];
            $userRole = $_SESSION['user_role'];

            $data = json_decode(file_get_contents('php://input'), true);
            $postId = filter_var($data['post_id'] ?? null, FILTER_VALIDATE_INT);
            $title = trim($data['title'] ?? '');
            $content = trim($data['content'] ?? '');

            if (!$postId || empty($title) || empty($content)) {
                throw new Exception("ID, Titel und Inhalt dürfen nicht leer sein.", 400);
            }

            // Prüfe Berechtigung
            $post = $this->postRepo->getPostById($postId);
            if (!$post) {
                throw new Exception("Beitrag nicht gefunden.", 404);
            }

            $isOwner = ($post['user_id'] == $userId);
            $isModerator = in_array($userRole, ['admin', 'planer']);

            if (!$isOwner && !$isModerator) {
                throw new Exception("Sie sind nicht berechtigt, diesen Beitrag zu bearbeiten.", 403);
            }

            // Wenn ein Schüler (der Besitzer ist) bearbeitet, auf 'pending' zurücksetzen.
            // Admins/Planer dürfen bearbeiten, ohne den Status zu ändern.
            $newStatus = $post['status'];
            if ($isOwner && !$isModerator) {
                $newStatus = 'pending';
            }
            
            // Moderator-ID setzen, wenn ein Moderator bearbeitet, sonst NULL
            $moderatorId = $isModerator ? $userId : null;

            $success = $this->postRepo->updatePost($postId, $title, $content, $newStatus, $moderatorId);

            if ($success) {
                AuditLogger::log('update_community_post', 'community_post', $postId, [
                    'title' => $title,
                    'new_status' => $newStatus
                ]);
                $message = ($newStatus === 'pending')
                    ? 'Beitrag aktualisiert und zur erneuten Moderation eingereicht.'
                    : 'Beitrag erfolgreich aktualisiert.';
                echo json_encode(['success' => true, 'message' => $message, 'new_status' => $newStatus]);
            } else {
                throw new Exception("Beitrag konnte nicht aktualisiert werden.", 500);
            }

        } catch (Exception $e) {
            $code = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
            http_response_code($code);
            error_log("API Error (updatePostApi): " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Fehler beim Aktualisieren: ' . $e->getMessage()]);
        }
        exit();
    }


    /**
     * API: Genehmigt einen Beitrag (Admin/Planer).
     */
    public function approvePostApi()
    {
        Security::requireRole(['admin', 'planer']);
        Security::verifyCsrfToken();
        header('Content-Type: application/json');

        try {
            $moderatorId = $_SESSION['user_id'];
            $data = json_decode(file_get_contents('php://input'), true);
            $postId = filter_var($data['post_id'] ?? null, FILTER_VALIDATE_INT);

            if (!$postId) {
                throw new Exception("Ungültige Beitrags-ID.", 400);
            }

            $success = $this->postRepo->updatePostStatus($postId, 'approved', $moderatorId);

            if ($success) {
                AuditLogger::log('approve_community_post', 'community_post', $postId);
                echo json_encode(['success' => true, 'message' => 'Beitrag freigegeben.']);
            } else {
                throw new Exception("Beitrag konnte nicht freigegeben werden (vielleicht schon moderiert?).", 404);
            }

        } catch (Exception $e) {
            $code = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
            http_response_code($code);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit();
    }
    
    /**
     * API: Lehnt einen Beitrag ab (Admin/Planer).
     * KORRIGIERT: Setzt Status auf 'rejected' statt zu löschen.
     */
    public function rejectPostApi()
    {
        Security::requireRole(['admin', 'planer']);
        Security::verifyCsrfToken();
        header('Content-Type: application/json');

        try {
            $moderatorId = $_SESSION['user_id']; // Für Logging
            $data = json_decode(file_get_contents('php://input'), true);
            $postId = filter_var($data['post_id'] ?? null, FILTER_VALIDATE_INT);

            if (!$postId) {
                throw new Exception("Ungültige Beitrags-ID.", 400);
            }
            
            // Optional: Daten für Log holen
            $post = $this->postRepo->getPostById($postId);

            // Status auf 'rejected' setzen
            $success = $this->postRepo->updatePostStatus($postId, 'rejected', $moderatorId);

            if ($success) {
                AuditLogger::log('reject_community_post', 'community_post', $postId, ['title' => $post['title'] ?? 'N/A']);
                echo json_encode(['success' => true, 'message' => 'Beitrag abgelehnt.']);
            } else {
                throw new Exception("Beitrag konnte nicht abgelehnt werden (vielleicht schon moderiert?).", 404);
            }

        } catch (Exception $e) {
            $code = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
            http_response_code($code);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit();
    }

    /**
     * NEU: API: Löscht einen Beitrag (Admin, Planer oder Ersteller).
     */
    public function deletePostApi()
    {
        Security::requireLogin();
        Security::verifyCsrfToken();
        header('Content-Type: application/json');

        try {
            $userId = $_SESSION['user_id'];
            $userRole = $_SESSION['user_role'];

            $data = json_decode(file_get_contents('php://input'), true);
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

            // Admins/Planer dürfen immer löschen, Besitzer nur, wenn er nicht 'rejected' ist?
            // Aktuelle Logik: Besitzer darf immer löschen.
            
            $success = $this->postRepo->deletePost($postId);

            if ($success) {
                AuditLogger::log('delete_community_post', 'community_post', $postId, [
                    'title' => $post['title'] ?? 'N/A',
                    'deleted_by' => $userRole
                ]);
                echo json_encode(['success' => true, 'message' => 'Beitrag erfolgreich gelöscht.']);
            } else {
                throw new Exception("Beitrag konnte nicht gelöscht werden.", 500);
            }

        } catch (Exception $e) {
            $code = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
            http_response_code($code);
            error_log("API Error (deletePostApi): " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Fehler beim Löschen: ' . $e->getMessage()]);
        }
        exit();
    }
}

