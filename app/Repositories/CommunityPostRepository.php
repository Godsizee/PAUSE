<?php
// app/Repositories/CommunityPostRepository.php

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

    /**
     * Erstellt einen neuen Beitrag.
     * @param int $userId
     * @param string $title
     * @param string $content
     * @param string $initialStatus (z.B. 'pending' oder 'approved')
     * @return int
     * @throws Exception
     */
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

    /**
     * NEU: Aktualisiert einen bestehenden Beitrag.
     * @param int $postId
     * @param int $userId (Zur Verifizierung der Inhaberschaft)
     * @param string $title
     * @param string $content
     * @param string $newStatus (z.B. 'pending' nach Bearbeitung)
     * @return bool
     */
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


    /**
     * Holt Beiträge basierend auf dem Status, sortiert von neu nach alt.
     * @param string $status ('approved', 'pending', 'rejected')
     * @param int $limit
     * @return array
     */
    public function getPostsByStatus(string $status, int $limit = 50): array
    {
        // Hole Posts inklusive Ersteller-Infos
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
    
    /**
     * NEU: Holt alle Beiträge eines bestimmten Benutzers.
     * @param int $userId
     * @return array
     */
    public function getPostsByUserId(int $userId): array
    {
        // Holt Posts ohne Ersteller-Infos (da es der eigene Benutzer ist)
        // Sortiert, sodass 'pending' oben steht, dann nach Datum
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

    
    /**
     * NEU: Holt freigegebene Beiträge INKLUSIVE E-Mail des Autors.
     * @param int $limit
     * @return array
     */
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


    /**
     * Holt einen einzelnen Post anhand der ID.
     * @param int $postId
     * @return array|false
     */
    public function getPostById(int $postId)
    {
        $sql = "SELECT * FROM community_posts WHERE post_id = :post_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':post_id' => $postId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Aktualisiert den Status eines Beitrags.
     * @param int $postId
     * @param string $newStatus ('approved', 'rejected')
     * @param int $moderatorUserId
     * @return bool
     */
    public function updatePostStatus(int $postId, string $newStatus, int $moderatorUserId): bool
    {
        if (!in_array($newStatus, ['approved', 'rejected'])) {
            return false; // Ungültiger Status
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

    /**
     * Löscht einen Beitrag (alternativ zu 'rejected').
     * @param int $postId
     * @return bool
     */
    public function deletePost(int $postId): bool
    {
        $sql = "DELETE FROM community_posts WHERE post_id = :post_id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':post_id' => $postId]);
    }
}
