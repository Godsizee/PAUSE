<?php
// app/Http/Traits/ApiHandlerTrait.php

namespace App\Http\Traits;

use App\Core\Security;
use App\Services\AuditLogger;
use Exception;
use PDOException;

/**
 * Trait ApiHandlerTrait
 *
 * Stellt eine standardisierte Methode zur Behandlung von API-Anfragen (JSON, FormData, GET) bereit.
 * Dieses Trait kümmert sich um:
 * - Setzen des JSON-Headers
 * - CSRF-Token-Überprüfung (für POST/JSON)
 * - Parsen von Eingabedaten (JSON-Body, FormData/POST, GET)
 * - Zentrales try/catch-Fehlerhandling
 * - Standardisierte JSON-Antworten (Erfolg/Fehler)
 * - Automatisches Audit-Logging bei Erfolg
 */
trait ApiHandlerTrait
{
    /**
     * Verarbeitet eine API-Anfrage standardisiert.
     *
     * @param callable $callback Die auszuführende Geschäftslogik. Erhält $data (geparste Eingabe) als Parameter.
     * MUSS ein Array zurückgeben, z.B.:
     * [
     * 'json_response' => ['success' => true, 'data' => ...],
     * 'log_action' => 'create_user',
     * 'log_target_type' => 'user',
     * 'log_target_id' => $newId,
     * 'log_details' => [...]
     * ]
     * oder für GET-Anfragen (die selbst 'echo' verwenden):
     * [ 'is_get_request' => true ]
     *
     * @param array $options Konfigurationsoptionen:
     * - 'inputType' (string): 'json' (default), 'form' (für $_POST/FormData), 'get' (für $_GET).
     * - 'checkRole' (string|array|null): Rolle(n), die via Security::requireRole geprüft werden sollen.
     */
    protected function handleApiRequest(callable $callback, array $options = []): void
    {
        // 1. Optionen und Standardwerte festlegen
        $inputType = $options['inputType'] ?? 'json'; // 'json', 'form', 'get'
        $roleToCheck = $options['checkRole'] ?? null;

        // 2. HTTP-Header setzen
        // (Wir setzen ihn immer, da auch Fehler als JSON zurückgegeben werden)
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }

        $data = [];

        try {
            // 3. Sicherheitsüberprüfungen (Rolle und CSRF)
            if ($roleToCheck) {
                Security::requireRole($roleToCheck);
            }

            // CSRF-Prüfung (überspringen bei GET)
            if ($inputType !== 'get') {
                Security::verifyCsrfToken(); // KORREKTUR: Name der Security-Methode
            }

            // 4. Eingabedaten parsen
            switch ($inputType) {
                case 'json':
                    $input = file_get_contents('php://input');
                    $data = json_decode($input, true);
                    if (json_last_error() !== JSON_ERROR_NONE && !empty($input)) {
                        throw new Exception("Ungültige JSON-Daten empfangen.", 400);
                    }
                    break;
                case 'form':
                    $data = $_POST; // Behandelt FormData und Standard-Formular-POST
                    // Hinweis: $_FILES muss im Callback separat behandelt werden
                    break;
                case 'get':
                    $data = $_GET;
                    break;
            }

            // 5. Geschäftslogik (Callback) ausführen
            $result = $callback($data);

            // 6. Antwort verarbeiten
            if (isset($result['is_get_request']) && $result['is_get_request'] === true) {
                // Der Callback hat die Antwort bereits gesendet (z.B. PlanController::getTimetableData)
                // Nichts weiter tun.
            } elseif (isset($result['json_response'])) {
                // Standard-Erfolgsfall:

                // 7. Audit-Log bei Erfolg (falls Daten vorhanden)
                if (isset($result['log_action'])) {
                    AuditLogger::log(
                        $result['log_action'],
                        $result['log_target_type'] ?? null,
                        $result['log_target_id'] ?? null,
                        $result['log_details'] ?? null
                    );
                }

                // 8. Erfolgs-JSON senden
                echo json_encode($result['json_response'], JSON_THROW_ON_ERROR);

            } else {
                // Fallback, falls der Callback kein 'json_response' zurückgibt
                throw new Exception("Interner Serverfehler: API-Callback lieferte keine gültige Antwort.", 500);
            }

        } catch (Exception $e) {
            // 9. Zentrales Fehlerhandling
            $statusCode = 500; // Standard-Serverfehler

            if ($e instanceof PDOException) {
                // Spezifische DB-Fehler
                if ($e->errorInfo[1] == 1062) { // Duplicate entry
                    $statusCode = 409; // Conflict
                } else {
                    $statusCode = 500; // Anderer DB-Fehler
                }
            } elseif (is_int($e->getCode()) && $e->getCode() >= 400 && $e->getCode() < 600) {
                // Verwende den HTTP-Code aus der Exception (z.B. 400, 403, 404, 409)
                $statusCode = $e->getCode();
            } elseif (str_contains($e->getMessage(), 'CSRF')) {
                $statusCode = 403;
            } elseif (str_contains($e->getMessage(), 'Berechtigung') || str_contains($e->getMessage(), 'Rolle')) {
                $statusCode = 403;
            }

            // Setze den HTTP-Statuscode
            http_response_code($statusCode);

            // Logge den serverseitigen Fehler (außer bei reinen Client-Fehlern wie 400, 404)
            if ($statusCode >= 500) {
                error_log("API Fehler (Trait): " . $e->getMessage() . "\n" . $e->getTraceAsString());
            }

            // Sende die Fehler-JSON-Antwort
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ], JSON_THROW_ON_ERROR);
        }
        
        // 10. Skriptausführung beenden
        exit();
    }
}