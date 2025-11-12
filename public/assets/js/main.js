import { showToast, showConfirm } from './notifications.js'; 
import { initializeHeaderUi } from './header-ui.js';
import { initializeFooterUi } from './footer-ui.js'; 
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
import { initializeMyCommunityPosts } from './dashboard-my-posts.js';
import { initializePlanerAbsences } from './planer-absences.js';
console.log("main.js: Skript gestartet.");
function runContentInitializers() {
    console.log("main.js: Führe Inhalts-Initialisierer aus...");
    if(document.getElementById('stammdaten-management')) {
        console.log("main.js: Initialisiere Admin Stammdaten...");
        initializeAdminStammdaten();
    }
    if(document.getElementById('user-management')) {
        console.log("main.js: Initialisiere Admin Benutzer...");
        initializeAdminUsers();
    }
    if(document.getElementById('announcements-management')) {
        console.log("main.js: Initialisiere Admin Ankündigungen...");
        initializeAdminAnnouncements();
    }
    if(document.getElementById('audit-log-management')) {
        console.log("main.js: Initialisiere Admin Audit Logs...");
        initializeAdminAuditLogs();
    }
    if(document.getElementById('settings-management')) {
        console.log("main.js: Initialisiere Admin Einstellungen...");
        initializeAdminSettings();
    }
    if(document.getElementById('community-moderation')) {
        console.log("main.js: Initialisiere Admin Community Moderation...");
        initializeAdminCommunity();
    }
    if(document.querySelector('.planer-dashboard-wrapper') && document.getElementById('planer-main-content')) { 
        console.log("main.js: Initialisiere Planer Dashboard...");
        initializePlanerDashboard(); // Diese Funktion ruft jetzt alle 5 'interactions'-Module auf
    }
    if(document.getElementById('absence-management')) {
        console.log("main.js: Initialisiere Planer Abwesenheiten...");
        initializePlanerAbsences();
    }
    if(document.querySelector('.dashboard-wrapper') && !document.querySelector('.admin-dashboard-wrapper') && !document.querySelector('.planer-dashboard-wrapper')) {
        console.log("main.js: Initialisiere (Schüler/Lehrer) Dashboard...");
        initializeDashboard();
    }
    console.log("main.js: Inhalts-Initialisierer abgeschlossen.");
}
function runGlobalInitializers() {
    console.log("main.js: Führe globale Initialisierer aus...");
    initializeHeaderUi(); 
    initializeFooterUi(); 
    console.log("main.js: Globale Initialisierer abgeschlossen.");
}
document.addEventListener('DOMContentLoaded', () => {
    console.log("main.js: DOMContentLoaded Event ausgelöst.");
    runGlobalInitializers();
    runContentInitializers();
    console.log("main.js: Alle Initialisierungen nach DOMContentLoaded abgeschlossen.");
});
window.initializeDashboardCommunity = initializeDashboardCommunity;
window.initializeMyCommunityPosts = initializeMyCommunityPosts;
window.initializeTeacherCockpit = initializeTeacherCockpit;
console.log("main.js: Skript-Ende erreicht.");