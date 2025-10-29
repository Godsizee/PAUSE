// public/assets/js/main.js

// Importiere globale UI-Handler
import { showToast, showConfirm } from './notifications.js'; // Stellt sicher, dass showToast/showConfirm global registriert werden
import { initializeHeaderUi } from './header-ui.js';
import { initializeFooterUi } from './footer-ui.js'; // Import footer UI

// Importiere seiten-spezifische Initialisierer
import { initializeAdminStammdaten } from './admin-stammdaten.js';
import { initializeAdminUsers } from './admin-users.js';
import { initializeAdminAnnouncements } from './admin-announcements.js';
import { initializePlanerDashboard } from './planer-dashboard.js';
import { initializeDashboard } from './dashboard.js';
import { initializeAdminAuditLogs } from './admin-audit-log.js'; 
import { initializeAdminSettings } from './admin-settings.js'; 
import { initializeTeacherCockpit } from './teacher-cockpit.js';
import { initializeAdminCommunity } from './admin-community.js';
import { initializeDashboardCommunity } from './dashboard-community.js';
// NEU: Import für "Meine Beiträge"
import { initializeMyCommunityPosts } from './dashboard-my-posts.js';
// NEU: Import für "Abwesenheiten"
import { initializePlanerAbsences } from './planer-absences.js';


console.log("main.js: Skript gestartet.");

/**
 * Führt alle seiten-spezifischen JavaScript-Module aus.
 * Prüft anhand von IDs im DOM, welche Module geladen werden sollen.
 */
function runContentInitializers() {
    console.log("main.js: Führe Inhalts-Initialisierer aus...");

    // Admin: Stammdaten
    if(document.getElementById('stammdaten-management')) {
        console.log("main.js: Initialisiere Admin Stammdaten...");
        initializeAdminStammdaten();
    }
    // Admin: Benutzerverwaltung
    if(document.getElementById('user-management')) {
        console.log("main.js: Initialisiere Admin Benutzer...");
        initializeAdminUsers();
    }
    // Admin: Ankündigungen
    if(document.getElementById('announcements-management')) {
        console.log("main.js: Initialisiere Admin Ankündigungen...");
        initializeAdminAnnouncements();
    }
    // Planer: Dashboard
    if(document.querySelector('.planer-dashboard-wrapper') && document.getElementById('timetable-container')) { // Spezifischer auf Dashboard
        console.log("main.js: Initialisiere Planer Dashboard...");
        initializePlanerDashboard();
    }
    // NEU: Planer: Abwesenheiten
    if(document.getElementById('absence-management')) {
        console.log("main.js: Initialisiere Planer Abwesenheiten...");
        initializePlanerAbsences();
    }
    // Schüler/Lehrer: Dashboard
    if(document.querySelector('.dashboard-wrapper')) {
        console.log("main.js: Initialisiere (Schüler/Lehrer) Dashboard...");
        initializeDashboard();
    }
    // Admin: Audit Logs
    if(document.getElementById('audit-log-management')) {
        console.log("main.js: Initialisiere Admin Audit Logs...");
        initializeAdminAuditLogs();
    }
    // Admin: Settings
    if(document.getElementById('settings-management')) {
        console.log("main.js: Initialisiere Admin Einstellungen...");
        initializeAdminSettings();
    }
    
    // Lehrer-Cockpit (wird auf der Dashboard-Seite geladen)
    // Die Logik in dashboard.js (initializeTabbedInterface) ruft dies verzögert auf
    // if(document.getElementById('teacher-cockpit')) {
    //     console.log("main.js: Initialisiere Lehrer-Cockpit...");
    //     initializeTeacherCockpit();
    // }

    // Admin Community Moderation
    if(document.getElementById('community-moderation')) {
        console.log("main.js: Initialisiere Admin Community Moderation...");
        initializeAdminCommunity();
    }
    
    // Dashboard Community Board (Schüler)
    // Wird verzögert von dashboard.js -> initializeTabbedInterface aufgerufen
    // if(document.getElementById('section-community-board')) {
    //     console.log("main.js: Initialisiere Dashboard Community Board...");
    //     initializeDashboardCommunity();
    // }

    // NEU: Dashboard "Meine Beiträge" (Schüler)
    // Wird verzögert von dashboard.js -> initializeTabbedInterface aufgerufen
    // if(document.getElementById('section-my-posts')) {
    //     console.log("main.js: Initialisiere Dashboard Meine Beiträge...");
    //     initializeMyCommunityPosts();
    // }


    console.log("main.js: Inhalts-Initialisierer abgeschlossen.");
}

/**
 * Führt globale UI-Initialisierer aus (Header, Footer, etc.)
 */
function runGlobalInitializers() {
    console.log("main.js: Führe globale Initialisierer aus...");
    initializeHeaderUi(); // Initialize the header interactions
    initializeFooterUi(); // Initialize the footer interactions
    // Future global initializers will go here
    console.log("main.js: Globale Initialisierer abgeschlossen.");
}


// Startet die Anwendung, sobald das DOM geladen ist.
document.addEventListener('DOMContentLoaded', () => {
    console.log("main.js: DOMContentLoaded Event ausgelöst.");
    runGlobalInitializers();
    runContentInitializers();
    console.log("main.js: Alle Initialisierungen nach DOMContentLoaded abgeschlossen.");
});

// NEU: Mache die Lazy-Loading-Funktionen global verfügbar,
// damit sie von dashboard.js aufgerufen werden können.
// (Bessere Methode wäre, dashboard.js auch modular zu importieren, aber das würde dashboard.js ändern)
window.initializeDashboardCommunity = initializeDashboardCommunity;
window.initializeMyCommunityPosts = initializeMyCommunityPosts;
window.initializeTeacherCockpit = initializeTeacherCockpit;


console.log("main.js: Skript-Ende erreicht.");

