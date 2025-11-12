import { apiFetch } from './api-client.js';
import { showToast } from './notifications.js';
export function initializeAdminSettings() {
    const settingsForm = document.getElementById('settings-form');
    if (!settingsForm) return;
    const saveButton = document.getElementById('save-settings-btn');
    const statusSpinner = document.getElementById('save-settings-spinner');
    const maintenanceToggle = document.getElementById('maintenance_mode');
    const maintenanceStatus = document.getElementById('maintenance-status');
    const icalToggle = document.getElementById('ical_enabled');
    const icalStatus = document.getElementById('ical-status');
    const communityToggle = document.getElementById('community_board_enabled');
    const communityStatus = document.getElementById('community-board-status');
    const logoInput = document.getElementById('site_logo');
    const logoPreviewContainer = document.getElementById('logo-preview-container');
    const logoRemoveCheckbox = document.querySelector('input[name="remove_site_logo"]');
    const faviconInput = document.getElementById('site_favicon');
    const faviconPreviewContainer = document.getElementById('favicon-preview-container');
    const faviconRemoveCheckbox = document.querySelector('input[name="remove_site_favicon"]');
    const clearCacheBtn = document.getElementById('clear-cache-btn');
    const cacheStatusText = document.getElementById('cache-clear-status');
    const cacheCsrfTokenInput = document.getElementById('cache_csrf_token');
    const setupToggle = (toggleElement, statusElement, activeText = 'Aktiviert', inactiveText = 'Deaktiviert') => {
        if (!toggleElement || !statusElement) return;
        const updateStatus = () => {
             if (toggleElement.checked) {
                statusElement.textContent = activeText;
                statusElement.style.color = 'var(--color-success)';
            } else {
                statusElement.textContent = inactiveText;
                statusElement.style.color = 'var(--color-text-muted)';
            }
        };
        toggleElement.addEventListener('change', updateStatus);
        updateStatus(); 
    };
    setupToggle(maintenanceToggle, maintenanceStatus, 'Aktiviert', 'Deaktiviert');
    setupToggle(icalToggle, icalStatus, 'Aktiviert', 'Deaktiviert');
    setupToggle(communityToggle, communityStatus, 'Aktiviert', 'Deaktiviert'); 
    const updatePreview = (input, container, removeCheckbox, isLogo = true) => {
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = (e) => {
                container.innerHTML = `
                    <img src="${e.target.result}" alt="Vorschau" class="${isLogo ? 'logo-preview' : 'favicon-preview'}">
                    <label class="remove-file-label" style="display: block;">
                        <input type="checkbox" name="${removeCheckbox.name}" value="1"> Entfernen
                    </label>
                `;
                const newRemoveCheckbox = container.querySelector(`input[name="${removeCheckbox.name}"]`);
                if (newRemoveCheckbox) {
                     newRemoveCheckbox.addEventListener('change', () => handleRemoveCheck(newRemoveCheckbox, input, container, isLogo ? 'Logo' : 'Favicon'));
                }
            };
            reader.readAsDataURL(input.files[0]);
            if (removeCheckbox) removeCheckbox.checked = false; 
        }
    };
    const handleRemoveCheck = (checkbox, input, container, altText) => {
         if (checkbox.checked) {
            if (input) input.value = ''; 
            container.querySelector('img')?.remove(); 
            container.querySelector('.no-file')?.remove();
            const noFileSpan = document.createElement('span');
            noFileSpan.className = 'no-file';
            noFileSpan.textContent = `${altText} wird entfernt.`;
            container.prepend(noFileSpan);
            if (checkbox.parentElement) checkbox.parentElement.style.display = 'none';
        }
    };
    if (logoInput) logoInput.addEventListener('change', () => updatePreview(logoInput, logoPreviewContainer, logoRemoveCheckbox, true));
    if (faviconInput) faviconInput.addEventListener('change', () => updatePreview(faviconInput, faviconPreviewContainer, faviconRemoveCheckbox, false));
    if (logoRemoveCheckbox) {
        logoRemoveCheckbox.addEventListener('change', () => handleRemoveCheck(logoRemoveCheckbox, logoInput, logoPreviewContainer, 'Logo'));
    }
     if (faviconRemoveCheckbox) {
        faviconRemoveCheckbox.addEventListener('change', () => handleRemoveCheck(faviconRemoveCheckbox, faviconInput, faviconPreviewContainer, 'Favicon'));
    }
    if (clearCacheBtn && cacheStatusText && cacheCsrfTokenInput) {
        clearCacheBtn.addEventListener('click', async () => {
            if (confirm('Sind Sie sicher, dass Sie den gesamten Anwendungs-Cache leeren möchten?')) {
                clearCacheBtn.disabled = true;
                clearCacheBtn.textContent = 'Leere...';
                cacheStatusText.textContent = '';
                cacheStatusText.classList.remove('text-success', 'text-danger');
                const formData = new FormData();
                formData.append('csrf_token', cacheCsrfTokenInput.value);
                try {
                    const response = await apiFetch(`${window.APP_CONFIG.baseUrl}/api/admin/cache/clear`, {
                        method: 'POST',
                        body: formData
                    });
                    if (response.success) {
                        showToast(response.message || 'Cache erfolgreich geleert.', 'success');
                        cacheStatusText.textContent = response.message;
                        cacheStatusText.classList.add('text-success');
                    } else {
                        throw new Error(response.message || 'Ein unbekannter Fehler ist aufgetreten.');
                    }
                } catch (error) {
                    console.error('Fehler beim Leeren des Caches:', error);
                    const errorMessage = error.message || 'Fehler beim Leeren des Caches.';
                    showToast(errorMessage, 'error');
                    cacheStatusText.textContent = `Fehler: ${errorMessage}`;
                    cacheStatusText.classList.add('text-danger');
                } finally {
                    clearCacheBtn.disabled = false;
                    clearCacheBtn.textContent = 'Cache jetzt leeren';
                }
            }
        });
    }
    settingsForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (saveButton) saveButton.disabled = true;
        if (statusSpinner) statusSpinner.style.display = 'inline-block';
        const formData = new FormData(settingsForm);
        try {
            const response = await apiFetch(`${window.APP_CONFIG.baseUrl}/api/admin/settings/save`, {
                method: 'POST',
                body: formData 
            });
            if (response.success) {
                showToast('Einstellungen erfolgreich gespeichert.', 'success');
                const cacheBuster = `?v=${new Date().getTime()}`;
                const newTitle = formData.get('site_title');
                const logoContainer = document.querySelector('.site-logo');
                if (logoContainer) {
                    if (response.data.site_logo_path) {
                        logoContainer.innerHTML = `<img src="${window.APP_CONFIG.baseUrl}/${response.data.site_logo_path}${cacheBuster}" alt="${escapeHtml(newTitle)} Logo" class="site-logo-image" id="header-logo-img">`;
                    } else {
                        logoContainer.innerHTML = `<span id="header-logo-text">${escapeHtml(newTitle)}</span>`;
                    }
                }
                document.title = escapeHtml(newTitle);
                let faviconElement = document.querySelector('link[rel="icon"]');
                if (response.data.site_favicon_path) {
                    const newFaviconUrl = `${window.APP_CONFIG.baseUrl}/${response.data.site_favicon_path}${cacheBuster}`;
                    if (faviconElement) {
                        faviconElement.href = newFaviconUrl;
                    } else {
                        const newLink = document.createElement('link');
                        newLink.rel = 'icon';
                        newLink.href = newFaviconUrl;
                        document.head.appendChild(newLink);
                    }
                } else if (!response.data.site_favicon_path && faviconElement) {
                    faviconElement.remove();
                }
                updatePreviewContainer(logoPreviewContainer, response.data.site_logo_path, 'remove_site_logo', 'Logo', true);
                updatePreviewContainer(faviconPreviewContainer, response.data.site_favicon_path, 'remove_site_favicon', 'Favicon', false);
                if (logoInput) logoInput.value = '';
                if (faviconInput) faviconInput.value = '';
                 window.APP_CONFIG.settings.site_title = newTitle;
                 window.APP_CONFIG.settings.site_logo_path = response.data.site_logo_path;
                 window.APP_CONFIG.settings.site_favicon_path = response.data.site_favicon_path;
                 window.APP_CONFIG.settings.default_theme = response.data.default_theme;
                 window.APP_CONFIG.settings.ical_enabled = formData.get('ical_enabled') === '1';
                 window.APP_CONFIG.settings.ical_weeks_future = parseInt(formData.get('ical_weeks_future'), 10);
                 window.APP_CONFIG.settings.maintenance_whitelist_ips = formData.get('maintenance_whitelist_ips'); 
                 window.APP_CONFIG.settings.community_board_enabled = formData.get('community_board_enabled') === '1'; 
            }
        } catch (error) {
            console.error('Fehler beim Speichern der Einstellungen:', error);
        } finally {
            if (saveButton) saveButton.disabled = false;
            if (statusSpinner) statusSpinner.style.display = 'none';
        }
    });
    function updatePreviewContainer(container, newPath, checkboxName, altText, isLogo) {
        if (!container) return;
        const cacheBuster = `?v=${new Date().getTime()}`;
        if (newPath) {
             container.innerHTML = `
                <img src="${window.APP_CONFIG.baseUrl}/${newPath}${cacheBuster}" alt="${altText} Vorschau" class="${isLogo ? 'logo-preview' : 'favicon-preview'}">
                <label class="remove-file-label">
                    <input type="checkbox" name="${checkboxName}" value="1"> ${altText} entfernen
                </label>
            `;
             const newCheckbox = container.querySelector(`input[name="${checkboxName}"]`);
             if (newCheckbox) {
                 const inputEl = document.getElementById(isLogo ? 'site_logo' : 'site_favicon');
                 newCheckbox.addEventListener('change', () => handleRemoveCheck(newCheckbox, inputEl, container, altText));
             }
        } else {
             container.innerHTML = `<span class="no-file">Kein ${altText} festgelegt.</span>`;
        }
    }
    function escapeHtml(unsafe) {
         if (!unsafe) return '';
         return String(unsafe)
              .replace(/&/g, "&amp;")
              .replace(/</g, "&lt;")
              .replace(/>/g, "&gt;")
              .replace(/"/g, "&quot;")
              .replace(/'/g, "&#039;");
    }
}