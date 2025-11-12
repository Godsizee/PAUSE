<?php
namespace App\Http\Controllers;
use App\Core\Database;
use App\Core\Utils;
use App\Repositories\UserRepository;
use App\Repositories\PlanRepository;
use App\Repositories\AcademicEventRepository;
use App\Repositories\AppointmentRepository; // NEU
use App\Services\IcalService; 
use Exception;
use PDO;

class IcalController
{
    private IcalService $icalService; 

    public function __construct()
    {
        
        $pdo = Database::getInstance();
        $userRepository = new UserRepository($pdo);
        $planRepository = new PlanRepository($pdo);
        $eventRepository = new AcademicEventRepository($pdo);
        $appointmentRepository = new AppointmentRepository($pdo); // NEU
        $settings = Utils::getSettings();
        
        // Service mit allen benötigten Repositories instanziieren
        $this->icalService = new IcalService(
            $userRepository, 
            $planRepository, 
            $eventRepository, 
            $appointmentRepository, // NEU
            $settings
        );
    }

    /**
     * Generiert den iCal-Feed für einen bestimmten Benutzer-Token.
     * Delegiert die gesamte Logik an den IcalService.
     */
    public function generateFeed(string $token)
    {
        try {
            // Logik an Service delegieren
            $icsContent = $this->icalService->generateFeed($token);

            // HTTP-Header für iCal-Feed setzen
            header('Content-Type: text/calendar; charset=utf-8');
            header('Content-Disposition: inline; filename="pause_stundenplan.ics"');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
            
            echo $icsContent;
            exit;

        } catch (Exception $e) {
            // Fehlerbehandlung für Exceptions aus dem Service
            $code = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
            http_response_code($code);
            header('Content-Type: text/plain');
            echo $e->getMessage();
            exit;
        }
    }
}