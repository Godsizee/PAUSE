<?php
namespace App\Http\Controllers\Admin;
use App\Core\Database;
use App\Core\Security;
use App\Repositories\CommunityPostRepository;
use Exception;
use PDO;
use \Parsedown; 
class CommunityController
{
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
    public function index()
    {
        Security::requireRole(['admin', 'planer']); 
        global $config;
        $config = Database::getConfig();
        $page_title = 'Moderation Schwarzes Brett';
        $body_class = 'admin-dashboard-body';
        try {
            $pendingPosts = $this->postRepo->getPostsByStatus('pending');
            foreach ($pendingPosts as &$post) {
                $post['content_html'] = $this->parsedown->text($post['content'] ?? '');
            }
            unset($post); 
            $approvedPosts = $this->postRepo->getPostsByStatus('approved');
            foreach ($approvedPosts as &$post) {
                $post['content_html'] = $this->parsedown->text($post['content'] ?? '');
            }
            unset($post); 
            Security::getCsrfToken();
            include_once dirname(__DIR__, 4) .'/pages/admin/community_moderation.php';
        } catch (Exception $e) {
            error_log("Fehler beim Laden der Moderationsseite: " . $e->getMessage());
            http_response_code(500);
            die("Ein Fehler ist beim Laden der Seite aufgetreten: " . $e->getMessage());
        }
    }
}