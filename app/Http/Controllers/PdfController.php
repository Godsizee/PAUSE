<?php
namespace App\Http\Controllers;
use App\Core\Database;
use App\Core\Security;
use App\Core\Utils;
use App\Repositories\PlanRepository;
use App\Repositories\UserRepository;
use App\Services\PdfService; 
use Exception;
class PdfController
{
    private PdfService $pdfService; 
    private array $config; 
    public function __construct()
    {
      
        $pdo = Database::getInstance();
        $this->config = Database::getConfig();
        $settings = Utils::getSettings();
        $planRepository = new PlanRepository($pdo);
        $userRepository = new UserRepository($pdo);
        $this->pdfService = new PdfService($planRepository, $userRepository, $settings);
    }
    public function generateTimetablePdf(int $year, int $week)
    {
        try {
            Security::requireLogin();
            $userId = $_SESSION['user_id'] ?? null;
            $userRole = $_SESSION['user_role'] ?? null;
            // NEU: Logik an Service delegieren
            $pdfOutput = $this->pdfService->generateTimetablePdf($userId, $userRole, $year, $week);
            $filename = sprintf(
                'Stundenplan_KW%02d_%d.pdf',
                $week,
                $year
            );
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="' . $filename . '"');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
            echo $pdfOutput;
            exit;
        } catch (Exception $e) {
            // NEU: Fehlerbehandlung für Exceptions aus dem Service
            http_response_code($e->getCode() ?: 500);
            $projectRoot = dirname(__DIR__, 3);
            $headerPath = $projectRoot . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'header.php';
            $footerPath = $projectRoot . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'footer.php';
            global $config;
            $config = $this->config;
            $page_title = "PDF Fehler";
            if (file_exists($headerPath)) {
                include_once $headerPath;
            } else {
                echo "<!DOCTYPE html><html><head><title>Fehler</title></head><body>";
                error_log("Error page header not found: " . $headerPath);
            }
            echo '<div class="container message error" style="margin-top: 50px;">';
            echo '<h1>PDF Generierungsfehler</h1>';
            $errorMessage = htmlspecialchars($e->getMessage());
            if (str_contains($e->getMessage(), 'Could not include font definition file') || str_contains($e->getMessage(), 'Font file not found')) {
                $errorMessage = 'Ein Fehler ist beim Laden der Schriftarten für das PDF aufgetreten (' . $errorMessage . '). Stellen Sie sicher, dass die .ttf-Dateien (und ggf. generierte .php/.z-Dateien) im korrekten Verzeichnis (`libs/tfpdf/font/unifont/`) liegen und lesbar sind. Kontaktieren Sie ggf. den Administrator.';
                error_log("PDF Generation Font Error: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
            }
            echo '<p>' . $errorMessage . '</p>';
            echo '<p><a href="' . Utils::url('dashboard') . '" class="btn btn-primary">Zurück zum Dashboard</a></p>';
            echo '</div>';
            if (file_exists($footerPath)) {
                include_once $footerPath;
            } else {
                echo "</body></html>";
                error_log("Error page footer not found: " . $footerPath);
            }
            exit;
        }
    }
}