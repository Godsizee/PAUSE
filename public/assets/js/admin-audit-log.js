import { apiFetch } from './api-client.js';
import { showToast } from './notifications.js';
function escapeHtml(unsafe) {
    if (unsafe === null || typeof unsafe === 'undefined') return '';
    return String(unsafe)
         .replace(/&/g, "&amp;")
         .replace(/</g, "&lt;")
         .replace(/>/g, "&gt;")
         .replace(/"/g, "&quot;")
         .replace(/'/g, "&#039;");
}
function formatDateTime(dateInput) {
    if (!dateInput) return 'N/A'; 
    try {
        let dateString = String(dateInput);
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
        return 'Fehler'; 
    }
}
export function initializeAdminAuditLogs() {
    const managementContainer = document.getElementById('audit-log-management');
    if (!managementContainer) return;
    const filterForm = document.getElementById('audit-filter-form');
    const tableBody = document.getElementById('audit-logs-tbody');
    const paginationContainer = document.getElementById('pagination-controls');
    const paginationSummary = document.getElementById('pagination-summary');
    let currentFilters = {};
    let currentPage = 1;
    const createLogRowHtml = (log) => {
        const userName = log.user_id
            ? `${escapeHtml(log.last_name)}, ${escapeHtml(log.first_name)} (${escapeHtml(log.username)})`
            : '<em style="color: var(--color-text-muted);">System/Gast</em>';
        let detailsHtml = '';
        if (log.details) {
            try {
                const detailsObj = JSON.parse(log.details);
                detailsHtml = `
                    <details class="log-details">
                        <summary>Details anzeigen</summary>
                        <pre>${escapeHtml(JSON.stringify(detailsObj, null, 2))}</pre>
                    </details>
                `;
            } catch (e) {
                detailsHtml = escapeHtml(log.details); 
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
    const renderPagination = (currentPage, totalPages) => {
        paginationContainer.innerHTML = '';
        if (totalPages <= 1) return;
        const prevBtn = document.createElement('button');
        prevBtn.className = 'btn btn-secondary btn-small pagination-btn';
        prevBtn.textContent = '« Zurück';
        prevBtn.disabled = currentPage <= 1;
        prevBtn.dataset.page = currentPage - 1;
        paginationContainer.appendChild(prevBtn);
        const pageInfo = document.createElement('span');
        pageInfo.className = 'pagination-info';
        pageInfo.textContent = `Seite ${currentPage} / ${totalPages}`;
        paginationContainer.appendChild(pageInfo);
        const nextBtn = document.createElement('button');
        nextBtn.className = 'btn btn-secondary btn-small pagination-btn';
        nextBtn.textContent = 'Weiter »';
        nextBtn.disabled = currentPage >= totalPages;
        nextBtn.dataset.page = currentPage + 1;
        paginationContainer.appendChild(nextBtn);
    };
    const loadLogs = async (page = 1, filters = {}) => {
        currentPage = page;
        currentFilters = filters;
        tableBody.innerHTML = `<tr><td colspan="7" style="text-align: center; padding: 40px;"><div class="loading-spinner"></div></td></tr>`;
        paginationContainer.innerHTML = '';
        paginationSummary.textContent = 'Lade Einträge...';
        const params = new URLSearchParams();
        params.append('page', page);
        for (const [key, value] of Object.entries(filters)) {
            if (value) { 
                params.append(key, value);
            }
        }
        try {
            const url = `${window.APP_CONFIG.baseUrl}/api/admin/audit-logs?${params.toString()}`;
            const response = await apiFetch(url); 
            if (response.success) {
                if (response.logs && response.logs.length > 0) {
                    tableBody.innerHTML = response.logs.map(createLogRowHtml).join('');
                } else {
                    tableBody.innerHTML = `<tr><td colspan="7" style="text-align: center; padding: 20px;">Keine Protokolleinträge für diese Filter gefunden.</td></tr>`;
                }
                const { currentPage, totalPages, totalCount, limit } = response.pagination;
                renderPagination(currentPage, totalPages);
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
    filterForm.addEventListener('submit', (e) => {
        e.preventDefault();
        const formData = new FormData(filterForm);
        const filters = Object.fromEntries(formData.entries());
        loadLogs(1, filters); 
    });
    filterForm.addEventListener('reset', (e) => {
        setTimeout(() => {
            loadLogs(1, {}); 
        }, 0);
    });
    paginationContainer.addEventListener('click', (e) => {
        const target = e.target.closest('.pagination-btn');
        if (target && !target.disabled) {
            const page = parseInt(target.dataset.page, 10);
            if (page) {
                loadLogs(page, currentFilters);
            }
        }
    });
    loadLogs(1, {});
}