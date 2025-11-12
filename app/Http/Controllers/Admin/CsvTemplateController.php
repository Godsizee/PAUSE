<?php
namespace App\Http\Controllers\Admin;
use App\Core\Security;
use App\Core\Database;
use Exception;
class CsvTemplateController
{
    public function index()
    {
        Security::requireRole('admin');
        global $config;
        $config = Database::getConfig();
        $page_title = 'CSV Importvorlage';
        $body_class = 'admin-dashboard-body';
        $templateData = $this->loadCsvTemplate();
        include_once dirname(__DIR__, 4) . '/pages/admin/csv_template.php';
    }
    private function loadCsvTemplate(): array
    {
        $templatePath = dirname(__DIR__, 4) . '/public/assets/templates/user_import_template.csv';
        $csvData = [
            'headers' => [],
            'rows' => []
        ];
        if (!file_exists($templatePath)) {
            $csvData['error'] = "Vorlagendatei nicht gefunden unter: " . htmlspecialchars($templatePath);
            return $csvData;
        }
        if (($handle = fopen($templatePath, "r")) !== FALSE) {
            if (($headers = fgetcsv($handle, 1000, ",")) !== FALSE) {
                $csvData['headers'] = $headers;
            }
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