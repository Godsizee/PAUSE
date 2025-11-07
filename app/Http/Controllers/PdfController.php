<?php
// app/Http/Controllers/PdfController.php

// MODIFIZIERT:
// 1. Erbt nicht mehr von tFPDF.
// 2. Importiert den neuen PdfService.
// 3. Instanziiert den PdfService im Konstruktor.
// 4. Die Methode generateTimetablePdf() wurde komplett refaktorisiert:
//    - Sie ruft nur noch $this->pdfService->generateTimetablePdf() auf.
//    - Sie fängt Exceptions vom Service ab (z.B. "nicht veröffentlicht").
//    - Sie kümmert sich um das Senden der HTTP-Header und des PDF-Inhalts.
// 5. Alle privaten PDF-Zeichnungsmethoden (drawPdfHeader, drawTimetableCell etc.)
//    wurden entfernt, da sie sich nun im Service befinden.

namespace App\Http\Controllers;

use App\Core\Database;
use App\Core\Security;
use App\Core\Utils;
use App\Repositories\PlanRepository;
use App\Repositories\UserRepository;
use App\Services\PdfService; // NEU: Service importieren
use Exception;

// VERALTET: tFPDF wird nur noch im Service benötigt
// if (!defined('FPDF_FONTPATH')) { ... }
// require_once dirname(__DIR__, 3) . '/libs/tfpdf/tfpdf.php';
// class PdfController extends \tFPDF // VERALTET

class PdfController
{
    // VERALTET: Alle privaten Eigenschaften wurden in den Service verschoben
    // private PlanRepository $planRepository;
    // ...

    private PdfService $pdfService; // NEU

    /**
     * Konstruktor: Instanziiert den PdfService und übergibt Abhängigkeiten.
     */
    public function __construct()
    {
        // Hole die Abhängigkeiten
        $pdo = Database::getInstance();
        $planRepository = new PlanRepository($pdo);
        $userRepository = new UserRepository($pdo);
        $settings = Utils::getSettings();

        // NEU: Injiziere die Abhängigkeiten in den Service
        $this->pdfService = new PdfService(
            $planRepository,
            $userRepository,
            $settings
        );
    }

    /**
     * Einstiegspunkt zur Generierung des PDF-Stundenplans.
     * Delegiert die Logik an den PdfService und sendet die Antwort.
     *
     * @param int $year Das Ziel-Jahr aus der URL.
     * @param int $week Die Ziel-Woche aus der URL.
     */
    public function generateTimetablePdf(int $year, int $week)
    {
        try {
            // 1. Sicherheitscheck und Benutzerdaten holen
            Security::requireLogin();
            $userId = $_SESSION['user_id'] ?? null;
            $userRole = $_SESSION['user_role'] ?? null;

            if (!$userId || !$userRole) {
                throw new Exception("PDF Export nur für angemeldete Benutzer verfügbar.", 403);
            }

            // 2. PDF-Generierung an den Service delegieren
            // Der Service prüft Berechtigungen (Schüler/Lehrer) und Veröffentlichungsstatus
            $pdfOutput = $this->pdfService->generateTimetablePdf($userId, $userRole, $year, $week);

            // 3. Dateinamen generieren (im Controller, da er den User kennt)
            $username = $_SESSION['username'] ?? 'User';
            $filename = sprintf(
                'Stundenplan_%s_KW%02d_%d.pdf',
                preg_replace('/[^A-Za-z0-9_-]/', '_', $username), // Bereinigter Benutzername
                $week,
                $year
            );

            // 4. Header senden (falls noch nicht gesendet)
            if (!headers_sent()) {
                header('Content-Type: application/pdf');
                header('Content-Disposition: inline; filename="' . $filename . '"');
                header('Cache-Control: no-cache, no-store, must-revalidate');
                header('Pragma: no-cache');
                header('Expires: 0');
            }

            // 5. PDF-Inhalt ausgeben
            echo $pdfOutput;
            exit;

        } catch (Exception $e) {
            // 6. Fehlerbehandlung (z.B. wenn Plan nicht veröffentlicht ist)
            $statusCode = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
            
            if ($statusCode >= 500) {
                 error_log("Kritischer PDF-Generierungsfehler: " . $e->getMessage());
            }

            // Zeige eine saubere HTML-Fehlerseite statt eines kaputten PDFs
            http_response_code($statusCode);
            
            // Lade Header/Footer für eine konsistente Fehlerseite
            global $config;
            $config = Database::getConfig(); // Stelle sicher, dass $config für Header/Footer verfügbar ist
            $page_title = "PDF Fehler";
            
            include_once dirname(__DIR__, 3) . '/pages/partials/header.php';
            echo '<div class="page-wrapper" style="padding-top: 50px;">';
            echo '<div class="container message error" style="max-width: 600px;">';
            echo '<h1>PDF Generierungsfehler</h1>';
            echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
            echo '<p><a href="' . Utils::url('dashboard') . '" class="btn btn-primary" style="width: auto;">Zurück zum Dashboard</a></p>';
            echo '</div>';
            echo '</div>';
            include_once dirname(__DIR__, 3) . '/pages/partials/footer.php';
            exit;
        }
    }

    /**
     * VERALTET: Alle privaten PDF-Zeichnungsmethoden wurden in den PdfService verschoben.
     */
    // private function drawPdfHeader(): float { ... }
    // private function drawTimetableGrid() { ... }
    // private function prepareTimetableData(): array { ... }
    // private function drawTimetableCell(...) { ... }
    // private function getDateOfISOWeek(...) { ... }
    // public function Header() { ... }
    // public function Footer() { ... }
}