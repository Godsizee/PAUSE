// public/assets/js/admin-community.js
import { apiFetch } from './api-client.js';
import { showToast, showConfirm } from './notifications.js';

/**
 * Initialisiert die Moderationsseite für das Schwarze Brett.
 * NEU: Fügt Tab-Switching-Logik hinzu.
 */
export function initializeAdminCommunity() {
    const container = document.getElementById('community-moderation');
    if (!container) return;

    // --- NEU: Tab-Logik ---
    const tabContainer = container.querySelector('.tab-navigation');
    const contentContainer = container.querySelector('.tab-content');

    if (tabContainer && contentContainer) {
        const tabButtons = tabContainer.querySelectorAll('.tab-button');
        const tabContents = contentContainer.querySelectorAll('.dashboard-section');

        const handleTabClick = (button) => {
            const targetId = button.dataset.target;
            if (!targetId) return;

            tabButtons.forEach(btn => btn.classList.remove('active'));
            tabContents.forEach(content => content.classList.remove('active'));

            button.classList.add('active');
            const targetContent = document.getElementById(targetId);
            if (targetContent) {
                targetContent.classList.add('active');
            }
        };

        tabButtons.forEach(button => {
            button.addEventListener('click', () => handleTabClick(button));
        });

        // KORREKTUR: Die Initialisierungs-Logik wird entfernt.
        // Das HTML (PHP) rendert bereits den korrekten Start-Zustand
        // (erster Tab aktiv, erstes Panel aktiv).
        // Wir müssen nur die Klick-Listener hinzufügen.
        /*
        const initiallyActiveButton = tabContainer.querySelector('.tab-button.active');
        if (initiallyActiveButton) {
             handleTabClick(initiallyActiveButton);
        } else if (tabButtons.length > 0) {
             handleTabClick(tabButtons[0]); // Fallback: Ersten Tab aktivieren
        }
        */
    }
    // --- ENDE Tab-Logik ---


    // Event-Listener auf den Hauptcontainer legen (Event Delegation)
    container.addEventListener('click', handleModerationClick);
}

/**
 * Behandelt Klicks auf alle Moderations-Buttons (Freigeben, Ablehnen, Löschen)
 * @param {Event} e
 */
async function handleModerationClick(e) {
    const approveBtn = e.target.closest('.approve-post-btn');
    const rejectBtn = e.target.closest('.reject-post-btn');
    const deleteBtn = e.target.closest('.delete-approved-btn'); // NEU

    const button = approveBtn || rejectBtn || deleteBtn;
    if (!button) return;

    e.preventDefault();
    
    const postItem = button.closest('.moderation-item');
    const postId = postItem?.dataset.id;
    const postTitle = postItem?.querySelector('.post-title')?.textContent || 'dieser Beitrag';

    if (!postId) {
        showToast('Konnte Beitrags-ID nicht finden.', 'error');
        return;
    }

    let actionText, url, confirmTitle, confirmMessage, logAction;

    if (approveBtn) {
        actionText = 'Freigeben';
        url = `${window.APP_CONFIG.baseUrl}/api/admin/community/approve`;
        logAction = 'approve';
        confirmTitle = 'Beitrag freigeben';
        confirmMessage = `Möchten Sie "${postTitle}" wirklich freigeben?`;
    } else if (rejectBtn) {
        actionText = 'Ablehnen & Löschen';
        url = `${window.APP_CONFIG.baseUrl}/api/admin/community/reject`;
        logAction = 'reject';
        confirmTitle = 'Beitrag ablehnen';
        confirmMessage = `Möchten Sie "${postTitle}" wirklich ablehnen und löschen?`;
    } else if (deleteBtn) { // NEU
        actionText = 'Endgültig Löschen';
        url = `${window.APP_CONFIG.baseUrl}/api/admin/community/delete-approved`;
        logAction = 'delete';
        confirmTitle = 'Beitrag löschen';
        confirmMessage = `Möchten Sie den freigegebenen Beitrag "${postTitle}" wirklich endgültig löschen?`;
    }

    // Bestätigung einholen
    if (await showConfirm(confirmTitle, confirmMessage)) {
        button.disabled = true;
        const spinner = document.createElement('span');
        spinner.className = 'loading-spinner small';
        button.insertAdjacentElement('afterend', spinner);

        try {
            const response = await apiFetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ post_id: postId })
            });

            if (response.success) {
                showToast(response.message, 'success');
                // Beitrag aus der Liste entfernen
                postItem.style.transition = 'opacity 0.3s ease, height 0.3s ease, margin 0.3s ease, padding 0.3s ease';
                postItem.style.opacity = '0';
                postItem.style.height = '0px';
                postItem.style.paddingTop = '0';
                postItem.style.paddingBottom = '0';
                postItem.style.margin = '0';
                
                setTimeout(() => {
                    const listContainer = postItem.parentElement;
                    postItem.remove();
                    // Prüfen, ob die Liste jetzt leer ist
                    if (listContainer && listContainer.childElementCount === 0) {
                        const messageType = (logAction === 'approve' || logAction === 'reject') ? 'ausstehende' : 'freigegebene';
                        listContainer.innerHTML = `<p class="message info">Aktuell gibt es keine ${messageType} Beiträge.</p>`;
                    }
                }, 300);
            }
            // Fehler wird von apiFetch als Toast angezeigt
        } catch (error) {
            console.error(`Fehler bei Aktion '${logAction}':`, error);
            // Button wieder aktivieren, falls der Request fehlschlägt
            button.disabled = false;
        } finally {
            spinner.remove();
        }
    }
}


