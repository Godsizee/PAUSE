<?php
namespace App\Repositories;
use PDO;
use Exception;
class CommunityPostRepository
{
    private PDO $pdo;
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }
    public function createPost(int $userId, string $title, string $content, string $initialStatus = 'pending'): int
    {
        $sql = "INSERT INTO community_posts (user_id, title, content, status, created_at)
                VALUES (:user_id, :title, :content, :status, NOW())";
        $stmt = $this->pdo->prepare($sql);
        $success = $stmt->execute([
            ':user_id' => $userId,
            ':title' => $title,
            ':content' => $content,
            ':status' => $initialStatus
        ]);
        if (!$success) {
            throw new Exception("Beitrag konnte nicht erstellt werden.");
        }
        return (int)$this->pdo->lastInsertId();
    }
    public function updatePost(int $postId, int $userId, string $title, string $content, string $newStatus): bool
    {
        $sql = "UPDATE community_posts SET
                    title = :title,
                    content = :content,
                    status = :status,
                    moderated_at = NULL,
                    moderator_id = NULL
                WHERE post_id = :post_id AND user_id = :user_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':title' => $title,
            ':content' => $content,
            ':status' => $newStatus,
            ':post_id' => $postId,
            ':user_id' => $userId
        ]);
        return $stmt->rowCount() > 0;
    }
    public function getPostsByStatus(string $status, int $limit = 50): array
    {
        $sql = "SELECT p.*, u.username, u.first_name, u.last_name
                FROM community_posts p
                JOIN users u ON p.user_id = u.user_id
                WHERE p.status = :status
                ORDER BY p.created_at DESC
                LIMIT :limit";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindParam(':status', $status, PDO::PARAM_STR);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    public function getPostsByUserId(int $userId): array
    {
        $sql = "SELECT *
                FROM community_posts
                WHERE user_id = :user_id
                ORDER BY
                    CASE status
                        WHEN 'pending' THEN 1
                        WHEN 'approved' THEN 2
                        WHEN 'rejected' THEN 3
                        ELSE 4
                    END,
                    created_at DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    public function getApprovedPostsWithAuthorEmail(int $limit = 50): array
    {
        $sql = "SELECT p.*, u.username, u.first_name, u.last_name, u.email
                FROM community_posts p
                JOIN users u ON p.user_id = u.user_id
                WHERE p.status = 'approved'
                ORDER BY p.created_at DESC
                LIMIT :limit";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    public function getPostById(int $postId)
    {
        $sql = "SELECT * FROM community_posts WHERE post_id = :post_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':post_id' => $postId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    public function updatePostStatus(int $postId, string $newStatus, int $moderatorUserId): bool
    {
        if (!in_array($newStatus, ['approved', 'rejected'])) {
            return false; 
        }
        $sql = "UPDATE community_posts SET
                    status = :status,
                    moderator_id = :moderator_id,
                    moderated_at = NOW()
                WHERE post_id = :post_id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            ':status' => $newStatus,
            ':moderator_id' => $moderatorUserId,
            ':post_id' => $postId
        ]);
    }
    public function deletePost(int $postId): bool
    {
        $sql = "DELETE FROM community_posts WHERE post_id = :post_id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':post_id' => $postId]);
    }
}