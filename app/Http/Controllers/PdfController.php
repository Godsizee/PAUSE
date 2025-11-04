<?php
// app/Http/Controllers/PdfController.php

namespace App\Http\Controllers;

use App\Core\Database;
use App\Core\Security;
use App\Core\Utils;
use App\Repositories\PlanRepository;
use App\Repositories\UserRepository;
use Exception;
use DateTime;
use DateTimeZone;

// Define the font path constant BEFORE including tFPDF
// ** KORRIGIERT: Pfad zeigt jetzt auf das 'font' Hauptverzeichnis **
// tFPDF fügt 'unifont/' für TTF-Dateien selbst hinzu.
if (!defined('FPDF_FONTPATH')) {
    define('FPDF_FONTPATH', dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'libs' . DIRECTORY_SEPARATOR . 'tfpdf' . DIRECTORY_SEPARATOR . 'font' . DIRECTORY_SEPARATOR);
}

// Include tFPDF library
require_once dirname(__DIR__, 3) . '/libs/tfpdf/tfpdf.php';


class PdfController extends \tFPDF
{
    private PlanRepository $planRepository;
    private UserRepository $userRepository;
    private array $config;
    private array $settings; // NEU: Einstellungen
    private array $userData;
    private int $targetYear;
    private int $targetWeek;
    private array $timetableData = [];
    private array $substitutionData = [];
    private array $cellWidths = [];
    // private float $rowHeight = 8; // VERALTET: Wird jetzt berechnet
    private float $headerHeight = 7; // Header row height in mm
    private float $totalWidth = 277; // A4 Landscape (297) - 10mm margins = 277

    // --- Font properties ---
    private string $fontBody = 'Arial';
    private string $fontDisplay = 'Arial';

    // --- Color definitions (RGB) ---
    private array $colors = [
        'border' => [200, 200, 200],
        'headerBg' => [240, 240, 240],
        'headerText' => [50, 50, 50],
        'cellBg' => [255, 255, 255],
        'cellText' => [0, 0, 0],
        'substBorderVertretung' => [220, 53, 69], // danger
        'substBorderRaum' => [255, 193, 7], // warning
        'substBorderEvent' => [13, 110, 253], // primary-ish
        'substBorderEntfall' => [108, 117, 125], // secondary-ish
        'substTextEntfall' => [108, 117, 125],
        'commentText' => [108, 117, 125], // secondary-ish
    ];


    public function __construct()
    {
        // Call tFPDF constructor (implicitly done via parent::__construct inside generateTimetablePdf)
        $pdo = Database::getInstance();
        $this->planRepository = new PlanRepository($pdo);
        $this->userRepository = new UserRepository($pdo);
        $this->config = Database::getConfig();
        $this->settings = Utils::getSettings(); // NEU: Einstellungen laden
    }

    /**
     * Entry point for generating the PDF.
     * Fetches data based on session and URL params, then builds the PDF.
     */
    public function generateTimetablePdf(int $year, int $week)
    {
        try {
            Security::requireLogin(); // Ensure user is logged in
            $userId = $_SESSION['user_id'] ?? null;
            $userRole = $_SESSION['user_role'] ?? null;
            $this->userData = $userId ? $this->userRepository->findById($userId) : null;

            if (!$this->userData || !in_array($userRole, ['schueler', 'lehrer'])) {
                throw new Exception("PDF Export nur für Schüler und Lehrer verfügbar.", 403);
            }

            $this->targetYear = $year;
            $this->targetWeek = $week;

            // Fetch data (only if published)
            $targetGroup = ($userRole === 'schueler') ? 'student' : 'teacher';
            if (!$this->planRepository->isWeekPublishedFor($targetGroup, $year, $week)) {
                throw new Exception("Der Stundenplan für diese Woche ist noch nicht veröffentlicht.", 403);
            }

            if ($userRole === 'schueler' && !empty($this->userData['class_id'])) {
                $classId = $this->userData['class_id'];
                $this->timetableData = $this->planRepository->getPublishedTimetableForClass($classId, $year, $week);
                $this->substitutionData = $this->planRepository->getPublishedSubstitutionsForClassWeek($classId, $year, $week);
            } elseif ($userRole === 'lehrer' && !empty($this->userData['teacher_id'])) {
                $teacherId = $this->userData['teacher_id'];
                $this->timetableData = $this->planRepository->getPublishedTimetableForTeacher($teacherId, $year, $week);
                $this->substitutionData = $this->planRepository->getPublishedSubstitutionsForTeacherWeek($teacherId, $year, $week);
            } else {
                throw new Exception("Benutzerdaten unvollständig (Klasse/Lehrer fehlt).", 400);
            }

            // --- Start PDF Generation ---
            parent::__construct('L', 'mm', 'A4'); // Call tFPDF constructor

            // --- FONT LOADING ---
            try {
                // ** KORRIGIERT: Verwende .ttf Dateinamen und 'true' für Unicode **
                // tFPDF sucht diese (mit $uni=true) automatisch im FPDF_FONTPATH . "unifont/" Verzeichnis
                if (!file_exists(FPDF_FONTPATH . 'unifont' . DIRECTORY_SEPARATOR . 'Oswald-Regular.ttf')) throw new Exception("Font file not found: Oswald-Regular.ttf in " . FPDF_FONTPATH . 'unifont' . DIRECTORY_SEPARATOR);
                if (!file_exists(FPDF_FONTPATH . 'unifont' . DIRECTORY_SEPARATOR . 'Oswald-Bold.ttf')) throw new Exception("Font file not found: Oswald-Bold.ttf in " . FPDF_FONTPATH . 'unifont' . DIRECTORY_SEPARATOR);
                
                $this->AddFont('Oswald', '', 'Oswald-Regular.ttf', true);
                $this->AddFont('Oswald', 'B', 'Oswald-Bold.ttf', true);
                
                $this->fontBody = 'Arial'; // Set class property (Arial ist Core-Font)
                $this->fontDisplay = 'Oswald'; // Set class property

            } catch (Exception $fontEx) {
                // Fallback auf Arial, falls Oswald auch fehlschlägt
                error_log("Fehler beim Laden der PDF-Schriftarten: " . $fontEx->getMessage() . " - Fallback auf Arial.");
                $this->fontBody = 'Arial'; // Set class property
                $this->fontDisplay = 'Arial'; // Set class property
            }
            $this->AliasNbPages();
            $this->AddPage();
            // --- END FONT LOADING ---


            // Set Margins
            $this->SetMargins(10, 10, 10);
            // ** KORREKTUR: AutoPageBreak deaktivieren, da wir die Höhe manuell berechnen **
            $this->SetAutoPageBreak(false); 

            // Draw Header (Title)
            $this->drawPdfHeader();

            // Draw Timetable Grid
            $this->drawTimetableGrid();

            // Output PDF
            $filename = sprintf(
                'Stundenplan_%s_KW%02d_%d.pdf',
                str_replace([' ', '.'], '_', $this->userData['username'] ?? 'User'), // Replace spaces and dots in username
                $week,
                $year
            );
            // 'I' for inline display in browser
            $this->Output('I', $filename);
            exit; // Stop script after PDF output

        } catch (Exception $e) {
            http_response_code($e->getCode() ?: 500);
            // Simple error page (replace with a proper error view if needed)
            // CORRECTED PATHS: Go up 3 levels to project root, use DIRECTORY_SEPARATOR
            $projectRoot = dirname(__DIR__, 3);
            $headerPath = $projectRoot . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'header.php';
            $footerPath = $projectRoot . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'footer.php';

            // Prepare config for header/footer partials if they exist
            global $config; // Make $config available
            $config = $this->config; // Use the loaded config
            $page_title = "PDF Fehler"; // Basic title for error page

            // Check if header/footer exist before including to prevent further errors
            if (file_exists($headerPath)) {
                include_once $headerPath;
            } else {
                echo "<!DOCTYPE html><html><head><title>Fehler</title></head><body>"; // Basic fallback HTML
                error_log("Error page header not found: " . $headerPath);
            }

            echo '<div class="container message error" style="margin-top: 50px;">';
            echo '<h1>PDF Generierungsfehler</h1>';
            // Avoid showing potentially sensitive details from font loading errors directly
            $errorMessage = htmlspecialchars($e->getMessage());
            if (str_contains($e->getMessage(), 'Could not include font definition file') || str_contains($e->getMessage(), 'Font file not found')) {
                $errorMessage = 'Ein Fehler ist beim Laden der Schriftarten für das PDF aufgetreten (' . $errorMessage . '). Stellen Sie sicher, dass die .ttf-Dateien (und ggf. generierte .php/.z-Dateien) im korrekten Verzeichnis (`libs/tfpdf/font/unifont/`) liegen und lesbar sind. Kontaktieren Sie ggf. den Administrator.';
                error_log("PDF Generation Font Error: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString()); // Log original error
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

    /**
     * Draws the main title and subtitle of the PDF.
     * @return float The calculated height of the header area.
     */
    private function drawPdfHeader(): float
    {
        $monday = $this->getDateOfISOWeek($this->targetWeek, $this->targetYear);
        $friday = new DateTime();
        $friday->setTimestamp($monday->getTimestamp() + 4 * 24 * 60 * 60);

        $className = '';
        if($_SESSION['user_role'] === 'schueler') {
            $classData = $this->userRepository->findClassByUserId($this->userData['user_id']);
            if ($classData) {
                $className = 'Klasse ' . ($classData['class_name'] ?? $classData['class_id']);
            }
        }

        $title = sprintf(
            'Stundenplan %s %s', // Removed () if className is empty
            $this->userData['username'] ?? 'Benutzer',
            $className ? '(' . $className . ')' : ''
        );
        $subTitle = sprintf(
            'Kalenderwoche %d / %d (%s - %s)',
            $this->targetWeek,
            $this->targetYear,
            $monday->format('d.m.Y'),
            $friday->format('d.m.Y')
        );

        $this->SetFont($this->fontDisplay, 'B', 16); // Use class property
        $this->SetTextColor(0, 0, 0);
        $this->Cell(0, 8, $title, 0, 1, 'C'); // Centered title
        $this->SetFont($this->fontBody, '', 10); // Use class property
        $this->Cell(0, 6, $subTitle, 0, 1, 'C'); // Centered subtitle
        $this->Ln(5); // Space after header

        return 8 + 6 + 5; // Return calculated height (8 + 6 + 5 = 19)
    }

    /**
     * Draws the complete timetable grid with headers and data.
     */
    private function drawTimetableGrid()
    {
        // --- Calculate Column Widths ---
        $timeColWidth = 18; // Width for the time column
        $dayColWidth = ($this->totalWidth - $timeColWidth) / 5; // Width for each day column
        $this->cellWidths = [$timeColWidth, $dayColWidth, $dayColWidth, $dayColWidth, $dayColWidth, $dayColWidth];

        // --- Draw Headers ---
        $this->SetFont($this->fontBody, 'B', 8); // Use class property
        $this->SetFillColor(...$this->colors['headerBg']);
        $this->SetTextColor(...$this->colors['headerText']);
        $this->SetDrawColor(...$this->colors['border']);
        $this->SetLineWidth(0.2);

        // Header Row 1: Time + Days
        $this->Cell($this->cellWidths[0], $this->headerHeight, 'Zeit', 1, 0, 'C', true);
        $daysOfWeek = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag'];
        foreach ($daysOfWeek as $i => $day) {
            $this->Cell($this->cellWidths[$i + 1], $this->headerHeight, $day, 1, 0, 'C', true);
        }
        $this->Ln($this->headerHeight);

        // --- Calculate Dynamic Row Height ---
        $headerAreaHeight = 19; // Höhe von drawPdfHeader (8 + 6 + 5)
        // ** KORREKTUR: $this->bMargin (unterer Rand für PageBreak) wird durch 10 (den expliziten unteren Rand) ersetzt
        $drawableHeight = $this->h - $this->tMargin - 10; // 210 - 10 - 10 = 190 (bei A4 landscape)
        $gridBodyHeight = $drawableHeight - $headerAreaHeight - $this->headerHeight; // 190 - 19 - 7 = 164
        
        $timeSlotsDisplay = [
            "08:00\n08:45", "08:55\n09:40", "09:40\n10:25", "10:35\n11:20",
            "11:20\n12:05", "13:05\n13:50", "13:50\n14:35", "14:45\n15:30",
            "15:30\n16:15", "16:25\n17:10"
        ];
        $numRows = count($timeSlotsDisplay);
        $calculatedRowHeight = $gridBodyHeight / $numRows; // z.B. 164 / 10 = 16.4mm
        $timeCellLineHeight = 3.5; // Feste Zeilenhöhe für 3 Zeilen Text (Stunde, Start, Ende)

        $timetableByCell = $this->prepareTimetableData();

        foreach ($timeSlotsDisplay as $period => $timeLabel) {
            $currentPeriod = $period + 1; // 1., 2., 3. Stunde...

            // Store Y position before drawing time cell
            $rowStartY = $this->GetY();

            // Draw Time Cell
            $this->SetFillColor(...$this->colors['headerBg']);
            $this->SetTextColor(...$this->colors['headerText']);
            
            // *** Stundennummer (z.B. 1.) ***
            $this->SetFont($this->fontBody, 'B', 8); // Größere Schrift für Stundennummer
            $periodLabel = $currentPeriod . ".";
            
            // *** Zeit (z.B. 08:00 - 08:45) ***
            $timeLabelParts = explode("\n", $timeLabel); // "08:00", "08:45"
            $timeLabelFormatted = $timeLabelParts[0] . ' - ' . $timeLabelParts[1];
            
            // Berechne Y-Start für Zentrierung von 2 Zeilen (Stunde + Zeit)
            $totalTextHeight = $timeCellLineHeight * 2;
            $cellCenterY = $rowStartY + ($calculatedRowHeight / 2);
            $textStartY = $cellCenterY - ($totalTextHeight / 2);

            // *** KORREKTUR: Erst Hintergrundzelle, dann Text ***

            // 1. Rahmen und BG für die Zelle zeichnen
            $this->SetXY(10, $rowStartY); 
            $this->Cell($this->cellWidths[0], $calculatedRowHeight, '', 1, 0, 'C', true); // Rahmen und BG zeichnen

            // 2. Stundennummer (darüber schreiben)
            $this->SetXY(10, $textStartY); // 10 = left margin
            $this->SetFont($this->fontBody, 'B', 8);
            $this->Cell($this->cellWidths[0], $timeCellLineHeight, $periodLabel, 0, 1, 'C', false); // 0 border, false fill
            
            // 3. Zeit (darüber schreiben)
            $this->SetFont($this->fontBody, '', 7); // Kleinere Schrift für Zeit
            $this->SetX(10);
            $this->Cell($this->cellWidths[0], $timeCellLineHeight, $timeLabelFormatted, 0, 1, 'C', false);


            // Reset Y position for drawing day cells in the same row
            $this->SetY($rowStartY);

            // Draw Day Cells for this period
            for ($dayNum = 1; $dayNum <= 5; $dayNum++) {
                // Move X position for each day cell
                $currentX = 10 + $this->cellWidths[0] + (($dayNum - 1) * $this->cellWidths[$dayNum]); // Margin + Time Col + Prev Day Cols
                $this->SetX($currentX);

                $cellKey = $dayNum . '-' . $currentPeriod;
                $entry = $timetableByCell[$cellKey] ?? null;

                // Draw the cell using calculated max height
                $this->drawTimetableCell($entry, $this->cellWidths[$dayNum], $calculatedRowHeight, $this->fontBody, $currentPeriod); // Pass currentPeriod
            }
            // Move to the next line using the calculated max height
            $this->Ln($calculatedRowHeight);
        }
    }

    /**
     * Prepares timetable and substitution data into a lookup map by 'day-period'.
     * @return array Map where key is 'day-period' and value is the entry/substitution data.
     */
    private function prepareTimetableData(): array
    {
        $map = [];
        $userRole = $_SESSION['user_role'];

        // 1. Add regular entries
        foreach ($this->timetableData as $entry) {
            $key = $entry['day_of_week'] . '-' . $entry['period_number'];
            $map[$key] = [
                'type' => 'regular',
                'subject' => $entry['subject_shortcut'] ?? '---',
                'mainText' => $userRole === 'schueler' ? ($entry['teacher_shortcut'] ?? '---') : ($entry['class_name'] ?? '---'),
                'room' => $entry['room_name'] ?? '',
                'comment' => $entry['comment'] ?? '',
                'original' => $entry // Store original for context
            ];
        }

        // 2. Add substitutions, potentially overwriting regular entries
        foreach ($this->substitutionData as $sub) {
            // Calculate day_of_week (1=Mon, 5=Fri) using PHP DateTime
            try {
                $subDate = new DateTime($sub['date']);
                $dayNum = $subDate->format('N'); // ISO-8601 day of week (1 = Monday, 7 = Sunday)
            } catch (Exception $e) { continue; } // Skip if date is invalid

            if ($dayNum < 1 || $dayNum > 5) continue; // Skip weekends

            $key = $dayNum . '-' . $sub['period_number'];
            $regularEntryForKey = $map[$key]['original'] ?? null; // Get original entry if it exists

            $map[$key] = [
                'type' => $sub['substitution_type'],
                'subject' => $sub['new_subject_shortcut'] ?? $regularEntryForKey['subject_shortcut'] ?? ($sub['substitution_type'] === 'Sonderevent' ? 'EVENT' : ($sub['substitution_type'] === 'Entfall' ? ($regularEntryForKey['subject_shortcut'] ?? '---') : '---')),
                'mainText' => $sub['substitution_type'] === 'Vertretung'
                    ? ($userRole === 'teacher' ? ($sub['class_name'] ?? $regularEntryForKey['class_name'] ?? '---') : ($sub['new_teacher_shortcut'] ?? '---'))
                    : ($sub['substitution_type'] === 'Entfall' ? 'Entfällt' : ($regularEntryForKey ? ($userRole === 'schueler' ? $regularEntryForKey['teacher_shortcut'] : $regularEntryForKey['class_name']) : '')),
                'room' => $sub['new_room_name'] ?? $regularEntryForKey['room_name'] ?? '',
                'comment' => $sub['comment'] ?? '',
                'original' => $regularEntryForKey // Keep original entry context if available
            ];
        }
        return $map;
    }

    /**
     * Draws a single cell in the timetable grid.
     * @param array|null $entry Data for the cell, or null if empty.
     * @param float $width Cell width.
     * @param float $height Cell height.
     * @param string $fontBody Font name for cell content.
     * @param int $currentPeriod The current period number (1-10)
     */
    private function drawTimetableCell(?array $entry, float $width, float $height, string $fontBody, int $currentPeriod)
    {
        $this->SetFillColor(...$this->colors['cellBg']);
        $this->SetTextColor(...$this->colors['cellText']);
        $this->SetDrawColor(...$this->colors['border']);
        $this->SetLineWidth(0.2);
        $border = 1; // LRTB borders

        // Initial position
        $startX = $this->GetX();
        $startY = $this->GetY();

        $borderColor = null;

        if ($entry) {
            $subjectText = $entry['subject'] ?? '';
            $mainText = $entry['mainText'] ?? '';
            $roomText = $entry['room'] ?? '';
            $commentText = $entry['comment'] ?? '';

            // Determine border color for substitutions
            switch ($entry['type']) {
                case 'Vertretung': $borderColor = $this->colors['substBorderVertretung']; break;
                case 'Raumänderung': $borderColor = $this->colors['substBorderRaum']; break;
                case 'Sonderevent': $borderColor = $this->colors['substBorderEvent']; break;
                case 'Entfall': $borderColor = $this->colors['substBorderEntfall']; break;
                default: $borderColor = $this->colors['border']; // Regular border color
            }

            // Draw border first (if substitution)
            if ($borderColor && $borderColor !== $this->colors['border']) {
                $this->SetDrawColor(...$borderColor);
                $this->SetLineWidth(0.5); // Thicker border
                $this->Line($startX, $startY, $startX, $startY + $height); // Left border only
                $this->SetDrawColor(...$this->colors['border']); // Reset draw color
                $this->SetLineWidth(0.2); // Reset line width
            }

            // Draw the cell background and standard border
            $this->SetXY($startX, $startY);
            $this->Cell($width, $height, '', $border, 0, 'C', true);

            // --- Cell Content (Vertically Centered) ---
            $padding = 1.5; // Padding from top/sides
            $availableWidth = $width - (2 * $padding);
            $lineHeight = 3.5; // Angepasste Zeilenhöhe

            // Berechne die benötigte Gesamthöhe des Textblocks
            $textBlockHeight = 0;
            if ($entry['type'] === 'Entfall') {
                $textBlockHeight += $lineHeight; // Für "Entfällt: FACH"
            } else {
                $textBlockHeight += $lineHeight; // Für Fach
                $detailsText = trim(($mainText ? $mainText : '') . ($roomText ? ' (' . $roomText . ')' : ''));
                if ($detailsText) $textBlockHeight += $lineHeight;
            }
            if ($commentText && $entry['type'] !== 'Sonderevent') {
                $textBlockHeight += $lineHeight; // Annahme: Kommentar passt auf eine Zeile (oder MultiCell Höhe)
            }
            // Für Sonderevent (Fach + Kommentar als MainText)
            if ($entry['type'] === 'Sonderevent') {
                $textBlockHeight = $lineHeight * 2; // Annahme: 2 Zeilen
            }


            // Berechne Y-Startposition für vertikale Zentrierung
            $contentStartY = $startY + ($height - $textBlockHeight) / 2;
            if ($contentStartY < $startY + $padding) $contentStartY = $startY + $padding; // Verhindere Überlappung oben

            $this->SetXY($startX + $padding, $contentStartY);

            // Subject
            $this->SetFont($fontBody, 'B', 9); // Größere Schrift
            if ($entry['type'] === 'Entfall') {
                $this->SetTextColor(...$this->colors['substTextEntfall']);
                $this->Cell($availableWidth, $lineHeight, 'Entfällt: ' . $subjectText, 0, 1, 'C');
            } else {
                $this->SetTextColor(...$this->colors['cellText']);
                $this->Cell($availableWidth, $lineHeight, $subjectText, 0, 1, 'C');
            }

            // Main Text & Room (if not Entfall)
            if ($entry['type'] !== 'Entfall') {
                $this->SetFont($fontBody, '', 8); // Größere Schrift
                $this->SetTextColor(...$this->colors['cellText']);
                $detailsText = trim(($mainText ? $mainText : '') . ($roomText ? ' (' . $roomText . ')' : ''));
                if ($detailsText) {
                    $this->SetX($startX + $padding); // Reset X
                    $this->Cell($availableWidth, $lineHeight, $detailsText, 0, 1, 'C');
                }
            }

            // Comment
            if ($commentText && $entry['type'] !== 'Sonderevent') {
                $this->SetFont($fontBody, '', 7); // Größere Schrift
                $this->SetTextColor(...$this->colors['commentText']);
                $this->SetX($startX + $padding); // Reset X
                // Verwende Cell statt MultiCell, um Zeilenumbruch zu verhindern (besseres Layout bei fester Höhe)
                $this->Cell($availableWidth, $lineHeight, $commentText, 0, 1, 'C');
            }

        } else {
            // Empty cell or FU
            // ** KORRIGIERT: Verwende die übergebene $currentPeriod **
            $isFU = ($currentPeriod == ($this->settings['default_start_hour'] ?? 1) || $currentPeriod == ($this->settings['default_end_hour'] ?? 10)); // Verwende Einstellungen mit Fallback

            if ($isFU) {
                $this->SetFillColor(...$this->colors['headerBg']); // Lighter background for FU
                $this->SetTextColor(...$this->colors['headerText']);
                $this->SetFont($fontBody, 'B', 8); // Größer
                $this->Cell($width, $height, 'FU', $border, 0, 'C', true);
            } else {
                // Completely empty cell
                $this->Cell($width, $height, '', $border, 0, 'C', true);
            }

        }
        // Ensure we are positioned correctly for the next cell in the row
        $this->SetXY($startX + $width, $startY);
    }

    /**
     * Gets the date of the Monday of a given calendar week and year.
     * @param int $week - The calendar week.
     * @param int $year - The year.
     * @return DateTime The Date object for Monday (local time).
     */
    private function getDateOfISOWeek(int $week, int $year): DateTime {
        $dto = new DateTime();
        $dto->setISODate($year, $week, 1); // Set to Monday of the week
        $dto->setTime(0,0,0); // Set time to midnight
        return $dto;
    }

    // --- Override Header/Footer if needed ---
    // public function Header() { /* ... */ }
    public function Footer()
    {
        // Position at 1 cm from bottom
        $this->SetY(-10);
        // KORRIGIERT: Verwende die Klassen-Eigenschaft $this->fontBody
        $this->SetFont($this->fontBody,'',8);
        $this->SetTextColor(128); // Graue Schrift

        // NEU: Benutzerdefinierter Footer-Text aus Einstellungen
        $footerText = $this->settings['pdf_footer_text'] ?? 'PAUSE Portal';
        // Füge Generierungsdatum hinzu
        $footerText .= ' | Generiert am: ' . date('d.m.Y H:i');
        
        $this->Cell(0, 10, $footerText, 0, 0, 'L'); // Linksbündig

        // Page number
        // $this->Cell(0,10,'Seite '.$this->PageNo().'/{nb}',0,0,'C'); // Zentriert
        $this->Cell(0, 10, 'Seite '.$this->PageNo().'/{nb}', 0, 0, 'R'); // Rechtsbündig
    }
}