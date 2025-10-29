import { apiFetch } from './api-client.js';
import { showToast } from './notifications.js';

/**
 * Escapes HTML special characters to prevent XSS.
 * @param {string|null|undefined} unsafe - The string to escape.
 * @returns {string} The escaped string.
 */
function escapeHtml(unsafe) {
    if (unsafe === null || typeof unsafe === 'undefined') return '';
    return String(unsafe)
         .replace(/&/g, "&amp;")
         .replace(/</g, "&lt;")
         .replace(/>/g, "&gt;")
         .replace(/"/g, "&quot;")
         .replace(/'/g, "&#039;");
}

/**
 * Formatiert ein Datumsobjekt oder einen String in das deutsche Format.
 * KORRIGIERT: Ersetzt Leerzeichen durch 'T' für bessere new Date() Kompatibilität.
 * @param {string|Date} dateInput - Der Datumsstring (erwartet YYYY-MM-DD HH:MM:SS).
 * @returns {string} Formatiertes Datum (TT.MM.YYYY HH:MM:SS) oder 'Ungültig'.
 */
function formatDateTime(dateInput) {
    if (!dateInput) return 'N/A'; // Handle empty input

    try {
        let dateString = String(dateInput);
        // Ersetze das erste Leerzeichen durch 'T', um ISO-Nähe herzustellen
        dateString = dateString.replace(' ', 'T');

        const date = new Date(dateString);

        if (isNaN(date.getTime())) {
            console.warn("formatDateTime: Ungültiges Datum nach Konvertierung:", dateString, "Original:", dateInput);
            return 'Ungültig';
        }

        return date.toLocaleString('de-DE', {
            year: 'numeric', month: '2-digit', day: '2-digit',
            hour: '2-digit', minute: '2-digit', second: '2-digit'
        });
    } catch (e) {
        console.error("formatDateTime Fehler:", e, "Input:", dateInput);
        return 'Fehler'; // Gibt einen anderen Fehlertext zurück
    }
}


/**
 * Initialisiert die Logik für die Audit-Log-Seite.
 */
export function initializeAdminAuditLogs() {
    const managementContainer = document.getElementById('audit-log-management');
    if (!managementContainer) return;

    const filterForm = document.getElementById('audit-filter-form');
    const tableBody = document.getElementById('audit-logs-tbody');
    const paginationContainer = document.getElementById('pagination-controls');
    const paginationSummary = document.getElementById('pagination-summary');

    let currentFilters = {};
    let currentPage = 1;

    /**
     * Erstellt das HTML für eine einzelne Log-Zeile.
     * @param {object} log - Das Log-Objekt aus der API.
     * @returns {string} HTML-String für die <tr>.
     */
    const createLogRowHtml = (log) => {
        const userName = log.user_id
            ? `${escapeHtml(log.last_name)}, ${escapeHtml(log.first_name)} (${escapeHtml(log.username)})`
            : '<em style="color: var(--color-text-muted);">System/Gast</em>';

        let detailsHtml = '';
        if (log.details) {
            try {
                const detailsObj = JSON.parse(log.details);
                // Zeigt JSON als aufklappbares Detail an
                detailsHtml = `
                    <details class="log-details">
                        <summary>Details anzeigen</summary>
                        <pre>${escapeHtml(JSON.stringify(detailsObj, null, 2))}</pre>
                    </details>
                `;
            } catch (e) {
                detailsHtml = escapeHtml(log.details); // Fallback für kein JSON
            }
        }

        return `
            <tr data-log-id="${log.log_id}">
                <td data-label="Zeitstempel">${formatDateTime(log.timestamp)}</td> <td data-label="Benutzer">${userName}</td>
                <td data-label="Aktion">${escapeHtml(log.action)}</td>
                <td data-label="Ziel-Typ">${escapeHtml(log.target_type)}</td>
                <td data-label="Ziel-ID">${escapeHtml(log.target_id)}</td>
                <td data-label="Details" class="log-details-cell">${detailsHtml}</td>
                <td data-label="IP-Adresse">${escapeHtml(log.ip_address)}</td>
            </tr>
        `;
    };

    /**
     * Erstellt die Paginierungs-Buttons.
     * @param {number} currentPage
     * @param {number} totalPages
     */
    const renderPagination = (currentPage, totalPages) => {
        paginationContainer.innerHTML = '';
        if (totalPages <= 1) return;

        // "Zurück"-Button
        const prevBtn = document.createElement('button');
        prevBtn.className = 'btn btn-secondary btn-small pagination-btn';
        prevBtn.textContent = '« Zurück';
        prevBtn.disabled = currentPage <= 1;
        prevBtn.dataset.page = currentPage - 1;
        paginationContainer.appendChild(prevBtn);

        // Seiteninfo
        const pageInfo = document.createElement('span');
        pageInfo.className = 'pagination-info';
        pageInfo.textContent = `Seite ${currentPage} / ${totalPages}`;
        paginationContainer.appendChild(pageInfo);

        // "Weiter"-Button
        const nextBtn = document.createElement('button');
        nextBtn.className = 'btn btn-secondary btn-small pagination-btn';
        nextBtn.textContent = 'Weiter »';
        nextBtn.disabled = currentPage >= totalPages;
        nextBtn.dataset.page = currentPage + 1;
        paginationContainer.appendChild(nextBtn);
    };

    /**
     * Hauptfunktion zum Laden und Rendern der Logs.
     * @param {number} page - Die zu ladende Seite.
     * @param {object} filters - Die anzuwendenden Filter.
     */
    const loadLogs = async (page = 1, filters = {}) => {
        currentPage = page;
        currentFilters = filters;

        tableBody.innerHTML = `<tr><td colspan="7" style="text-align: center; padding: 40px;"><div class="loading-spinner"></div></td></tr>`;
        paginationContainer.innerHTML = '';
        paginationSummary.textContent = 'Lade Einträge...';

        const params = new URLSearchParams();
        params.append('page', page);
        for (const [key, value] of Object.entries(filters)) {
            if (value) { // Nur hinzufügen, wenn Wert vorhanden
                params.append(key, value);
            }
        }

        try {
            const url = `${window.APP_CONFIG.baseUrl}/api/admin/audit-logs?${params.toString()}`;
            const response = await apiFetch(url); // GET-Anfrage

            if (response.success) {
                // Logs rendern
                if (response.logs && response.logs.length > 0) {
                    tableBody.innerHTML = response.logs.map(createLogRowHtml).join('');
                } else {
                    tableBody.innerHTML = `<tr><td colspan="7" style="text-align: center; padding: 20px;">Keine Protokolleinträge für diese Filter gefunden.</td></tr>`;
                }

                // Paginierung rendern
                const { currentPage, totalPages, totalCount, limit } = response.pagination;
                renderPagination(currentPage, totalPages);

                // Zusammenfassung aktualisieren
                const startEntry = (currentPage - 1) * limit + 1;
                const endEntry = Math.min(currentPage * limit, totalCount);
                paginationSummary.textContent = totalCount > 0
                    ? `Zeige Einträge ${startEntry} - ${endEntry} von ${totalCount}`
                    : 'Keine Einträge gefunden';

            } else {
                throw new Error(response.message || 'Unbekannter Fehler');
            }

        } catch (error) {
            console.error("Fehler beim Laden der Audit-Logs:", error);
            showToast(error.message, 'error');
            tableBody.innerHTML = `<tr><td colspan="7" class="message error">Fehler: ${error.message}</td></tr>`;
            paginationSummary.textContent = 'Fehler beim Laden';
        }
    };

    // --- Event Listeners ---

    // Filter-Formular absenden
    filterForm.addEventListener('submit', (e) => {
        e.preventDefault();
        const formData = new FormData(filterForm);
        const filters = Object.fromEntries(formData.entries());
        loadLogs(1, filters); // Starte immer auf Seite 1, wenn gefiltert wird
    });

    // Filter zurücksetzen
    filterForm.addEventListener('reset', (e) => {
        // Verzögere das Neuladen leicht, damit das Formular zuerst zurückgesetzt wird
        setTimeout(() => {
            loadLogs(1, {}); // Lade Seite 1 ohne Filter
        }, 0);
    });

    // Klicks auf Paginierungs-Buttons
    paginationContainer.addEventListener('click', (e) => {
        const target = e.target.closest('.pagination-btn');
        if (target && !target.disabled) {
            const page = parseInt(target.dataset.page, 10);
            if (page) {
                loadLogs(page, currentFilters);
            }
        }
    });

    // Initiales Laden
    loadLogs(1, {});
}