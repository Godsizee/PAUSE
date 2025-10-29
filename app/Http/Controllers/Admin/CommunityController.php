<?php
// app/Http/Controllers/Admin/CommunityController.php

namespace App\Http\Controllers\Admin;

use App\Core\Database;
use App\Core\Security;
use App\Repositories\CommunityPostRepository;
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
     * Zeigt die Hauptseite für die Moderation des Schwarzen Bretts an.
     * NEU: Lädt jetzt ausstehende UND freigegebene Beiträge.
     */
    public function index()
    {
        Security::requireRole(['admin', 'planer']); // Nur Admins/Planer dürfen moderieren
        global $config;
        $config = Database::getConfig();

        $page_title = 'Moderation Schwarzes Brett';
        $body_class = 'admin-dashboard-body';

        try {
            // Lade alle ausstehenden Beiträge
            $pendingPosts = $this->postRepo->getPostsByStatus('pending');
            
            // Konvertiere Markdown zu HTML für die Vorschau
            foreach ($pendingPosts as &$post) {
                $post['content_html'] = $this->parsedown->text($post['content'] ?? '');
            }
            unset($post); // Referenz aufheben

            // NEU: Lade alle freigegebenen Beiträge
            $approvedPosts = $this->postRepo->getPostsByStatus('approved');
            
            // Konvertiere Markdown zu HTML für die Vorschau
            foreach ($approvedPosts as &$post) {
                $post['content_html'] = $this->parsedown->text($post['content'] ?? '');
            }
            unset($post); // Referenz aufheben


            Security::getCsrfToken();
            include_once dirname(__DIR__, 4) .'/pages/admin/community_moderation.php';

        } catch (Exception $e) {
            error_log("Fehler beim Laden der Moderationsseite: " . $e->getMessage());
            http_response_code(500);
            die("Ein Fehler ist beim Laden der Seite aufgetreten: " . $e->getMessage());
        }
    }
}
