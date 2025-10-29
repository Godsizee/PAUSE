<?php
// app/Http/Controllers/Admin/CsvTemplateController.php

namespace App\Http\Controllers\Admin;

use App\Core\Security;
use App\Core\Database;
use Exception;

class CsvTemplateController
{
    /**
     * Zeigt die Seite mit der CSV-Importvorlage an.
     */
    public function index()
    {
        Security::requireRole('admin');
        global $config;
        $config = Database::getConfig();

        $page_title = 'CSV Importvorlage';
        $body_class = 'admin-dashboard-body';
        
        $templateData = $this->loadCsvTemplate();

        // Übergibt $templateData (Header und Zeilen) an die View
        include_once dirname(__DIR__, 4) . '/pages/admin/csv_template.php';
    }

    /**
     * Lädt die CSV-Vorlagendatei und parst sie.
     * @return array Ein Array mit 'headers' (array) und 'rows' (array of arrays).
     */
    private function loadCsvTemplate(): array
    {
        $templatePath = dirname(__DIR__, 4) . '/public/assets/templates/user_import_template.csv';
        $csvData = [
            'headers' => [],
            'rows' => []
        ];

        if (!file_exists($templatePath)) {
            // Zeigt einen Fehler an, wenn die Vorlagendatei fehlt
            $csvData['error'] = "Vorlagendatei nicht gefunden unter: " . htmlspecialchars($templatePath);
            return $csvData;
        }

        if (($handle = fopen($templatePath, "r")) !== FALSE) {
            // Lese Header
            if (($headers = fgetcsv($handle, 1000, ",")) !== FALSE) {
                $csvData['headers'] = $headers;
            }
            // Lese Beispielzeilen
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                $csvData['rows'][] = $data;
            }
            fclose($handle);
        } else {
             $csvData['error'] = "Vorlagendatei konnte nicht gelesen werden.";
        }

        return $csvData;
    }
}
