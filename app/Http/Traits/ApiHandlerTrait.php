<?php
namespace App\Http\Traits;
use App\Core\Security;
use App\Services\AuditLogger;
use Exception;
use PDOException; // Sicherstellen, dass PDOException importiert ist

trait ApiHandlerTrait
{
    protected function handleApiRequest(callable $callback, array $options = []): void
    {
        $inputType = $options['inputType'] ?? 'json';
        $roleToCheck = $options['checkRole'] ?? null;
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        $data = [];
        try {
            if ($roleToCheck) {
                Security::requireRole($roleToCheck);
            }
            if ($inputType !== 'get') {
                Security::verifyCsrfToken();
            }
            switch ($inputType) {
                case 'json':
                    $input = file_get_contents('php://input');
                    $data = json_decode($input, true);
                    if (json_last_error() !== JSON_ERROR_NONE && !empty($input)) {
                        throw new Exception("Ungültige JSON-Daten empfangen.", 400);
                    }
                    break;
                case 'form':
                    $data = $_POST;
                    break;
                case 'get':
                    $data = $_GET;
                    break;
            }
           
            // Für 'form' Input (wie bei SettingsController), übergeben wir auch $_FILES
            $files = ($inputType === 'form') ? $_FILES : [];
            $result = $callback($data, $files); // $data ist $_POST, $files ist $_FILES

            if (isset($result['is_get_request']) && $result['is_get_request'] === true) {
                // GET-Anfrage hat bereits per echo ausgegeben
            } elseif (isset($result['json_response'])) {
                // Standard-Antwortstruktur
                if (isset($result['log_action'])) {
                    AuditLogger::log(
                        $result['log_action'],
                        $result['log_target_type'] ?? null,
                        $result['log_target_id'] ?? null,
                        $result['log_details'] ?? null
                    );
                }
                echo json_encode($result['json_response'], JSON_THROW_ON_ERROR);
            } else {
                // Manueller echo-Fall (wie in SettingsController)
                if (isset($result['log_action'])) {
                    AuditLogger::log(
                        $result['log_action'],
                        $result['log_target_type'] ?? null,
                        $result['log_target_id'] ?? null,
                        $result['log_details'] ?? null
                    );
                }
            }
        } catch (\Throwable $e) { // GEÄNDERT: Von Exception zu Throwable
            $statusCode = 500;
            if ($e instanceof PDOException) {
                if ($e->errorInfo[1] == 1062) {
                    $statusCode = 409;
                } else {
                    $statusCode = 500;
                }
            } elseif (is_int($e->getCode()) && $e->getCode() >= 400 && $e->getCode() < 600) {
                $statusCode = $e->getCode();
            } elseif (str_contains($e->getMessage(), 'CSRF')) {
                $statusCode = 403;
            } elseif (str_contains($e->getMessage(), 'Berechtigung') || str_contains($e->getMessage(), 'Rolle')) {
                $statusCode = 403;
            }
            http_response_code($statusCode);
            if ($statusCode >= 500) {
                error_log("API Fehler (Trait): " . $e->getMessage() . "\n" . $e->getTraceAsString());
            }
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ], JSON_THROW_ON_ERROR);
        }
        exit();
    }
}