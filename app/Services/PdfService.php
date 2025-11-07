<?php
// app/Services/PdfService.php

namespace App\Services;

use App\Repositories\PlanRepository;
use App\Repositories\UserRepository;
use App\Core\Utils; // Importiert für die getSettings-Typisierung
use Exception;
use DateTime;
use DateTimeZone;

// Definiere den Font-Pfad, bevor tFPDF eingebunden wird
if (!defined('FPDF_FONTPATH')) {
    define('FPDF_FONTPATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'libs' . DIRECTORY_SEPARATOR . 'tfpdf' . DIRECTORY_SEPARATOR . 'font' . DIRECTORY_SEPARATOR);
}
// Binde die tFPDF-Bibliothek ein
require_once dirname(__DIR__, 2) . '/libs/tfpdf/tfpdf.php';

/**
 * Service-Klasse zur Kapselung der PDF-Generierungslogik.
 * (Ausgelagert aus PdfController).
 * Erbt von tFPDF, um die PDF-Funktionalität bereitzustellen.
 */
class PdfService extends \tFPDF
{
    private PlanRepository $planRepository;
    private UserRepository $userRepository;
    private array $settings;
    private ?array $userData = null; // Speichert den Benutzer, für den das PDF generiert wird
    private int $targetYear;
    private int $targetWeek;
    private array $timetableData = [];
    private array $substitutionData = [];
    private array $cellWidths = [];
    private float $headerHeight = 7;
    private float $totalWidth = 277; // A4 Landscape (297) - 10mm margins = 277

    private string $fontBody = 'Arial';
    private string $fontDisplay = 'Arial';

    private array $colors = [
        'border' => [200, 200, 200],
        'headerBg' => [240, 240, 240],
        'headerText' => [50, 50, 50],
        'cellBg' => [255, 255, 255],
        'cellText' => [0, 0, 0],
        'substBorderVertretung' => [220, 53, 69],
        'substBorderRaum' => [255, 193, 7],
        'substBorderEvent' => [13, 110, 253],
        'substBorderEntfall' => [108, 117, 125],
        'substTextEntfall' => [108, 117, 125],
        'commentText' => [108, 117, 125],
    ];

    /**
     * Konstruktor: Nimmt Abhängigkeiten entgegen.
     */
    public function __construct(
        PlanRepository $planRepository,
        UserRepository $userRepository,
        array $settings
    ) {
        $this->planRepository = $planRepository;
        $this->userRepository = $userRepository;
        $this->settings = $settings;
        
        // WICHTIG: Der tFPDF-Konstruktor muss hier nicht aufgerufen werden,
        // da er von der generateTimetablePdf-Methode aufgerufen wird,
        // die die Seitenorientierung festlegt.
    }

    /**
     * Hauptmethode zur Generierung des PDF-Stundenplans.
     *
     * @param int $userId ID des anfragenden Benutzers.
     * @param string $userRole Rolle des anfragenden Benutzers.
     * @param int $year Ziel-Jahr.
     * @param int $week Ziel-Woche.
     * @return string Der rohe PDF-Daten-String.
     * @throws Exception Wenn Validierung fehlschlägt oder PDF-Fehler auftreten.
     */
    public function generateTimetablePdf(int $userId, string $userRole, int $year, int $week): string
    {
        // 1. Benutzerdaten validieren und holen
        $this->userData = $this->userRepository->findById($userId);
        if (!$this->userData || !in_array($userRole, ['schueler', 'lehrer'])) {
            throw new Exception("PDF Export nur für Schüler und Lehrer verfügbar.", 403);
        }

        $this->targetYear = $year;
        $this->targetWeek = $week;

        // 2. Daten abrufen (nur wenn veröffentlicht)
        $targetGroup = ($userRole === 'schueler') ? 'student' : 'teacher';
        if (!$this->planRepository->isWeekPublishedFor($targetGroup, $year, $week)) {
            throw new Exception("Der Stundenplan für diese Woche ist noch nicht veröffentlicht.", 403);
        }

        // 3. Daten basierend auf der Rolle laden
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

        // --- PDF-Generierung starten ---

        // 4. tFPDF-Konstruktor aufrufen
        parent::__construct('L', 'mm', 'A4'); // A4 Landscape

        // 5. Schriftarten laden
        try {
            if (!file_exists(FPDF_FONTPATH . 'unifont' . DIRECTORY_SEPARATOR . 'Oswald-Regular.ttf')) throw new Exception("Font file not found: Oswald-Regular.ttf");
            if (!file_exists(FPDF_FONTPATH . 'unifont' . DIRECTORY_SEPARATOR . 'Oswald-Bold.ttf')) throw new Exception("Font file not found: Oswald-Bold.ttf");
            
            $this->AddFont('Oswald', '', 'Oswald-Regular.ttf', true);
            $this->AddFont('Oswald', 'B', 'Oswald-Bold.ttf', true);
            
            $this->fontBody = 'Arial';
            $this->fontDisplay = 'Oswald';
        } catch (Exception $fontEx) {
            error_log("Fehler beim Laden der PDF-Schriftarten: " . $fontEx->getMessage() . " - Fallback auf Arial.");
            $this->fontBody = 'Arial';
            $this->fontDisplay = 'Arial';
        }

        // 6. PDF-Dokument aufbauen
        $this->AliasNbPages();
        $this->AddPage();
        $this->SetMargins(10, 10, 10);
        $this->SetAutoPageBreak(false);

        $this->drawPdfHeader();
        $this->drawTimetableGrid();

        // 7. PDF-Daten als String zurückgeben
        return $this->Output('S');
    }

    /**
     * Zeichnet den Titel und Untertitel des PDFs.
     */
    private function drawPdfHeader(): float
    {
        $monday = $this->getDateOfISOWeek($this->targetWeek, $this->targetYear);
        $friday = (new DateTime())->setTimestamp($monday->getTimestamp() + 4 * 24 * 60 * 60);

        $className = '';
        if($this->userData['role'] === 'schueler') {
            $classData = $this->userRepository->findClassByUserId($this->userData['user_id']);
            if ($classData) {
                $className = 'Klasse ' . ($classData['class_name'] ?? $classData['class_id']);
            }
        }

        $title = sprintf(
            'Stundenplan %s %s',
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

        $this->SetFont($this->fontDisplay, 'B', 16);
        $this->SetTextColor(0, 0, 0);
        $this->Cell(0, 8, $title, 0, 1, 'C');
        $this->SetFont($this->fontBody, '', 10);
        $this->Cell(0, 6, $subTitle, 0, 1, 'C');
        $this->Ln(5);

        return 19; // Höhe des Headers
    }

    /**
     * Zeichnet das komplette Stundenplan-Raster.
     */
    private function drawTimetableGrid()
    {
        // Spaltenbreiten berechnen
        $timeColWidth = 18;
        $dayColWidth = ($this->totalWidth - $timeColWidth) / 5;
        $this->cellWidths = [$timeColWidth, $dayColWidth, $dayColWidth, $dayColWidth, $dayColWidth, $dayColWidth];

        // Header zeichnen
        $this->SetFont($this->fontBody, 'B', 8);
        $this->SetFillColor(...$this->colors['headerBg']);
        $this->SetTextColor(...$this->colors['headerText']);
        $this->SetDrawColor(...$this->colors['border']);
        $this->SetLineWidth(0.2);

        $this->Cell($this->cellWidths[0], $this->headerHeight, 'Zeit', 1, 0, 'C', true);
        $daysOfWeek = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag'];
        foreach ($daysOfWeek as $i => $day) {
            $this->Cell($this->cellWidths[$i + 1], $this->headerHeight, $day, 1, 0, 'C', true);
        }
        $this->Ln($this->headerHeight);

        // Dynamische Zeilenhöhe berechnen
        $headerAreaHeight = 19; // Höhe von drawPdfHeader
        $drawableHeight = $this->h - $this->tMargin - 10; // 190mm
        $gridBodyHeight = $drawableHeight - $headerAreaHeight - $this->headerHeight; // 164mm
        
        $timeSlotsDisplay = [
            "08:00\n08:45", "08:55\n09:40", "09:40\n10:25", "10:35\n11:20",
            "11:20\n12:05", "13:05\n13:50", "13:50\n14:35", "14:45\n15:30",
            "15:30\n16:15", "16:25\n17:10"
        ];
        $numRows = count($timeSlotsDisplay);
        $calculatedRowHeight = $gridBodyHeight / $numRows; // 16.4mm
        $timeCellLineHeight = 3.5;

        $timetableByCell = $this->prepareTimetableData();

        foreach ($timeSlotsDisplay as $period => $timeLabel) {
            $currentPeriod = $period + 1;
            $rowStartY = $this->GetY();

            // Zeitzelle zeichnen
            $this->SetFillColor(...$this->colors['headerBg']);
            $this->SetTextColor(...$this->colors['headerText']);
            
            $periodLabel = $currentPeriod . ".";
            $timeLabelParts = explode("\n", $timeLabel);
            $timeLabelFormatted = $timeLabelParts[0] . ' - ' . $timeLabelParts[1];
            
            $totalTextHeight = $timeCellLineHeight * 2;
            $cellCenterY = $rowStartY + ($calculatedRowHeight / 2);
            $textStartY = $cellCenterY - ($totalTextHeight / 2);

            $this->SetXY(10, $rowStartY); 
            $this->Cell($this->cellWidths[0], $calculatedRowHeight, '', 1, 0, 'C', true);
            $this->SetXY(10, $textStartY);
            $this->SetFont($this->fontBody, 'B', 8);
            $this->Cell($this->cellWidths[0], $timeCellLineHeight, $periodLabel, 0, 1, 'C', false);
            $this->SetFont($this->fontBody, '', 7);
            $this->SetX(10);
            $this->Cell($this->cellWidths[0], $timeCellLineHeight, $timeLabelFormatted, 0, 1, 'C', false);
            $this->SetY($rowStartY);

            // Tageszellen zeichnen
            for ($dayNum = 1; $dayNum <= 5; $dayNum++) {
                $currentX = 10 + $this->cellWidths[0] + (($dayNum - 1) * $this->cellWidths[$dayNum]);
                $this->SetX($currentX);

                $cellKey = $dayNum . '-' . $currentPeriod;
                $entry = $timetableByCell[$cellKey] ?? null;

                $this->drawTimetableCell($entry, $this->cellWidths[$dayNum], $calculatedRowHeight, $this->fontBody, $currentPeriod);
            }
            $this->Ln($calculatedRowHeight);
        }
    }

    /**
     * Bereitet die Rohdaten in eine 'Tag-Periode'-Map um.
     */
    private function prepareTimetableData(): array
    {
        $map = [];
        $userRole = $this->userData['role']; // Verwende die gespeicherten Benutzerdaten

        // 1. Reguläre Einträge
        foreach ($this->timetableData as $entry) {
            $key = $entry['day_of_week'] . '-' . $entry['period_number'];
            $map[$key] = [
                'type' => 'regular',
                'subject' => $entry['subject_shortcut'] ?? '---',
                'mainText' => $userRole === 'schueler' ? ($entry['teacher_shortcut'] ?? '---') : ($entry['class_name'] ?? '---'),
                'room' => $entry['room_name'] ?? '',
                'comment' => $entry['comment'] ?? '',
                'original' => $entry
            ];
        }

        // 2. Vertretungen (überschreiben)
        foreach ($this->substitutionData as $sub) {
            try {
                $subDate = new DateTime($sub['date']);
                $dayNum = $subDate->format('N');
            } catch (Exception $e) { continue; }

            if ($dayNum < 1 || $dayNum > 5) continue;

            $key = $dayNum . '-' . $sub['period_number'];
            $regularEntryForKey = $map[$key]['original'] ?? null;

            $map[$key] = [
                'type' => $sub['substitution_type'],
                'subject' => $sub['new_subject_shortcut'] ?? $regularEntryForKey['subject_shortcut'] ?? ($sub['substitution_type'] === 'Sonderevent' ? 'EVENT' : ($sub['substitution_type'] === 'Entfall' ? ($regularEntryForKey['subject_shortcut'] ?? '---') : '---')),
                'mainText' => $sub['substitution_type'] === 'Vertretung'
                    ? ($userRole === 'teacher' ? ($sub['class_name'] ?? $regularEntryForKey['class_name'] ?? '---') : ($sub['new_teacher_shortcut'] ?? '---'))
                    : ($sub['substitution_type'] === 'Entfall' ? 'Entfällt' : ($regularEntryForKey ? ($userRole === 'schueler' ? $regularEntryForKey['teacher_shortcut'] : $regularEntryForKey['class_name']) : '')),
                'room' => $sub['new_room_name'] ?? $regularEntryForKey['room_name'] ?? '',
                'comment' => $sub['comment'] ?? '',
                'original' => $regularEntryForKey
            ];
        }
        return $map;
    }

    /**
     * Zeichnet eine einzelne Zelle im Raster.
     */
    private function drawTimetableCell(?array $entry, float $width, float $height, string $fontBody, int $currentPeriod)
    {
        $this->SetFillColor(...$this->colors['cellBg']);
        $this->SetTextColor(...$this->colors['cellText']);
        $this->SetDrawColor(...$this->colors['border']);
        $this->SetLineWidth(0.2);
        $border = 1;

        $startX = $this->GetX();
        $startY = $this->GetY();
        $borderColor = null;

        if ($entry) {
            $subjectText = $entry['subject'] ?? '';
            $mainText = $entry['mainText'] ?? '';
            $roomText = $entry['room'] ?? '';
            $commentText = $entry['comment'] ?? '';

            switch ($entry['type']) {
                case 'Vertretung': $borderColor = $this->colors['substBorderVertretung']; break;
                case 'Raumänderung': $borderColor = $this->colors['substBorderRaum']; break;
                case 'Sonderevent': $borderColor = $this->colors['substBorderEvent']; break;
                case 'Entfall': $borderColor = $this->colors['substBorderEntfall']; break;
                default: $borderColor = $this->colors['border'];
            }

            if ($borderColor && $borderColor !== $this->colors['border']) {
                $this->SetDrawColor(...$borderColor);
                $this->SetLineWidth(0.5);
                $this->Line($startX, $startY, $startX, $startY + $height);
                $this->SetDrawColor(...$this->colors['border']);
                $this->SetLineWidth(0.2);
            }

            $this->SetXY($startX, $startY);
            $this->Cell($width, $height, '', $border, 0, 'C', true);

            // Inhalt (vertikal zentriert)
            $padding = 1.5;
            $availableWidth = $width - (2 * $padding);
            $lineHeight = 3.5;
            $textBlockHeight = 0;

            if ($entry['type'] === 'Entfall') {
                $textBlockHeight += $lineHeight;
            } else {
                $textBlockHeight += $lineHeight;
                $detailsText = trim(($mainText ? $mainText : '') . ($roomText ? ' (' . $roomText . ')' : ''));
                if ($detailsText) $textBlockHeight += $lineHeight;
            }
            if ($commentText && $entry['type'] !== 'Sonderevent') {
                $textBlockHeight += $lineHeight;
            }
            if ($entry['type'] === 'Sonderevent') {
                $textBlockHeight = $lineHeight * 2;
            }

            $contentStartY = $startY + ($height - $textBlockHeight) / 2;
            if ($contentStartY < $startY + $padding) $contentStartY = $startY + $padding;

            $this->SetXY($startX + $padding, $contentStartY);

            // Fach
            $this->SetFont($fontBody, 'B', 9);
            if ($entry['type'] === 'Entfall') {
                $this->SetTextColor(...$this->colors['substTextEntfall']);
                $this->Cell($availableWidth, $lineHeight, 'Entfällt: ' . $subjectText, 0, 1, 'C');
            } else {
                $this->SetTextColor(...$this->colors['cellText']);
                $this->Cell($availableWidth, $lineHeight, $subjectText, 0, 1, 'C');
            }

            // Haupttext & Raum
            if ($entry['type'] !== 'Entfall') {
                $this->SetFont($fontBody, '', 8);
                $this->SetTextColor(...$this->colors['cellText']);
                $detailsText = trim(($mainText ? $mainText : '') . ($roomText ? ' (' . $roomText . ')' : ''));
                if ($detailsText) {
                    $this->SetX($startX + $padding);
                    $this->Cell($availableWidth, $lineHeight, $detailsText, 0, 1, 'C');
                }
            }

            // Kommentar
            if ($commentText && $entry['type'] !== 'Sonderevent') {
                $this->SetFont($fontBody, '', 7);
                $this->SetTextColor(...$this->colors['commentText']);
                $this->SetX($startX + $padding);
                $this->Cell($availableWidth, $lineHeight, $commentText, 0, 1, 'C');
            }

        } else {
            // Leere Zelle oder FU
            $isFU = ($currentPeriod == ($this->settings['default_start_hour'] ?? 1) || $currentPeriod == ($this->settings['default_end_hour'] ?? 10));
            if ($isFU) {
                $this->SetFillColor(...$this->colors['headerBg']);
                $this->SetTextColor(...$this->colors['headerText']);
                $this->SetFont($fontBody, 'B', 8);
                $this->Cell($width, $height, 'FU', $border, 0, 'C', true);
            } else {
                $this->Cell($width, $height, '', $border, 0, 'C', true);
            }
        }
        $this->SetXY($startX + $width, $startY);
    }

    /**
     * Hilfsfunktion zum Holen des Montagsdatums einer ISO-Woche.
     */
    private function getDateOfISOWeek(int $week, int $year): DateTime
    {
        $dto = new DateTime();
        $dto->setISODate($year, $week, 1);
        $dto->setTime(0,0,0);
        return $dto;
    }

    /**
     * Überschreibt die tFPDF Header-Methode (hier leer).
     */
    public function Header()
    {
        // Wird von drawPdfHeader() manuell gehandhabt
    }

    /**
     * Überschreibt die tFPDF Footer-Methode.
     */
    public function Footer()
    {
        $this->SetY(-10);
        $this->SetFont($this->fontBody,'',8);
        $this->SetTextColor(128);

        // Verwende den geladenen Einstellungs-Cache
        $footerText = $this->settings['pdf_footer_text'] ?? 'PAUSE Portal';
        $footerText .= ' | Generiert am: ' . date('d.m.Y H:i');
        
        $this->Cell(0, 10, $footerText, 0, 0, 'L');
        $this->Cell(0, 10, 'Seite '.$this->PageNo().'/{nb}', 0, 0, 'R');
    }
}