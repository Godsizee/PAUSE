<?php
namespace App\Http\Controllers;
use App\Core\Database;
use App\Core\Security;
use App\Repositories\CommunityPostRepository;
use App\Services\AuditLogger;
use Exception;
use PDO;
use \Parsedown;
use App\Http\Traits\ApiHandlerTrait; // NEU

class CommunityController
{
    use ApiHandlerTrait; // NEU

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

    public function getPostsApi()
    {
        $this->handleApiRequest(function($data) {
            $posts = $this->postRepo->getApprovedPostsWithAuthorEmail(50);
            foreach ($posts as &$post) {
                $post['content_html'] = $this->parsedown->text($post['content'] ?? '');
            }
            unset($post);
            echo json_encode(['success' => true, 'data' => $posts]);
            
            return ['is_get_request' => true];
        }, [
            'inputType' => 'get',
            'checkRole' => ['schueler', 'lehrer', 'planer', 'admin'] // requireLogin
        ]);
    }

    public function getMyPostsApi()
    {
        $this->handleApiRequest(function($data) {
            $userId = $_SESSION['user_id'];
            $posts = $this->postRepo->getPostsByUserId($userId);
            foreach ($posts as &$post) {
                $post['content_html'] = $this->parsedown->text($post['content'] ?? '');
            }
            unset($post);
            echo json_encode(['success' => true, 'data' => $posts]);
            
            return ['is_get_request' => true];
        }, [
            'inputType' => 'get',
            'checkRole' => ['schueler', 'lehrer', 'planer', 'admin'] // requireLogin
        ]);
    }

    public function createPostApi()
    {
        $this->handleApiRequest(function($data) { // $data kommt von JSON
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
                
            echo json_encode(['success' => true, 'message' => $message, 'status' => $initialStatus]);

            return [
                'log_action' => 'create_community_post',
                'log_target_type' => 'community_post',
                'log_target_id' => $newPostId,
                'log_details' => [
                    'title' => $title,
                    'status' => $initialStatus
                ]
            ];
        }, [
            'inputType' => 'json',
            'checkRole' => ['schueler', 'lehrer', 'planer', 'admin'] // requireLogin
        ]);
    }

    public function updatePostApi()
    {
        $this->handleApiRequest(function($data) { // $data kommt von JSON
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
            $success = $this->postRepo->updatePost($postId, $title, $content, $newStatus, $moderatorId);
            if ($success) {
                $message = ($newStatus === 'pending')
                    ? 'Beitrag aktualisiert und zur erneuten Moderation eingereicht.'
                    : 'Beitrag erfolgreich aktualisiert.';
                
                echo json_encode(['success' => true, 'message' => $message, 'new_status' => $newStatus]);
                
                return [
                    'log_action' => 'update_community_post',
                    'log_target_type' => 'community_post',
                    'log_target_id' => $postId,
                    'log_details' => [
                        'title' => $title,
                        'new_status' => $newStatus
                    ]
                ];
            } else {
                throw new Exception("Beitrag konnte nicht aktualisiert werden.", 500);
            }
        }, [
            'inputType' => 'json',
            'checkRole' => ['schueler', 'lehrer', 'planer', 'admin'] // requireLogin
        ]);
    }

    public function approvePostApi()
    {
        $this->handleApiRequest(function($data) { // $data kommt von JSON
            $moderatorId = $_SESSION['user_id'];
            $postId = filter_var($data['post_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$postId) {
                throw new Exception("Ungültige Beitrags-ID.", 400);
            }
            $success = $this->postRepo->updatePostStatus($postId, 'approved', $moderatorId);
            if ($success) {
                echo json_encode(['success' => true, 'message' => 'Beitrag freigegeben.']);
                return [
                    'log_action' => 'approve_community_post',
                    'log_target_type' => 'community_post',
                    'log_target_id' => $postId
                ];
            } else {
                throw new Exception("Beitrag konnte nicht freigegeben werden (vielleicht schon moderiert?).", 404);
            }
        }, [
            'inputType' => 'json',
            'checkRole' => ['admin', 'planer']
        ]);
    }

    public function rejectPostApi()
    {
        $this->handleApiRequest(function($data) { // $data kommt von JSON
            $moderatorId = $_SESSION['user_id'];
            $postId = filter_var($data['post_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$postId) {
                throw new Exception("Ungültige Beitrags-ID.", 400);
            }
            $post = $this->postRepo->getPostById($postId);
            $success = $this->postRepo->updatePostStatus($postId, 'rejected', $moderatorId);
            if ($success) {
                echo json_encode(['success' => true, 'message' => 'Beitrag abgelehnt.']);
                return [
                    'log_action' => 'reject_community_post',
                    'log_target_type' => 'community_post',
                    'log_target_id' => $postId,
                    'log_details' => ['title' => $post['title'] ?? 'N/A']
                ];
            } else {
                throw new Exception("Beitrag konnte nicht abgelehnt werden (vielleicht schon moderiert?).", 404);
            }
        }, [
            'inputType' => 'json',
            'checkRole' => ['admin', 'planer']
        ]);
    }

    public function deletePostApi()
    {
        $this->handleApiRequest(function($data) { // $data kommt von JSON
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
            if ($success) {
                echo json_encode(['success' => true, 'message' => 'Beitrag erfolgreich gelöscht.']);
                return [
                    'log_action' => 'delete_community_post',
                    'log_target_type' => 'community_post',
                    'log_target_id' => $postId,
                    'log_details' => [
                        'title' => $post['title'] ?? 'N/A',
                        'deleted_by' => $userRole
                    ]
                ];
            } else {
                throw new Exception("Beitrag konnte nicht gelöscht werden.", 500);
            }
        }, [
            'inputType' => 'json',
            'checkRole' => ['schueler', 'lehrer', 'planer', 'admin'] // requireLogin
        ]);
    }
}