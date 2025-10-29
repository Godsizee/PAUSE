/**
 * Erstellt und zeigt eine Toast-Benachrichtigung an.
 * @param {string} message - Die anzuzeigende Nachricht.
 * @param {'success'|'error'|'info'} type - Der Typ der Benachrichtigung.
 * @param {number} duration - Wie lange der Toast sichtbar bleibt (in ms).
 */
export function showToast(message, type = 'info', duration = 3000) {
    // Alten Toast entfernen, falls vorhanden
    const oldToast = document.querySelector('.toast-notification');
    if (oldToast) {
        oldToast.remove();
    }

    const toast = document.createElement('div');
    toast.className = `toast-notification toast-${type}`;
    toast.textContent = message;

    document.body.appendChild(toast);

    // Animation starten
    setTimeout(() => {
        toast.classList.add('visible');
    }, 10); // Kleiner Delay, damit CSS Transition greift

    // Toast nach der angegebenen Dauer ausblenden und entfernen
    setTimeout(() => {
        toast.classList.remove('visible');
        // Entferne das Element erst, nachdem die Ausblend-Animation abgeschlossen ist
        toast.addEventListener('transitionend', () => toast.remove());
    }, duration);
}

/**
 * Zeigt einen Bestätigungsdialog (Modal) an.
 * @param {string} title - Der Titel des Dialogs.
 * @param {string} message - Die Frage oder Nachricht im Dialog.
 * @returns {Promise<boolean>} - Ein Promise, das `true` bei Bestätigung und `false` bei Abbruch zurückgibt.
 */
export function showConfirm(title, message) {
    return new Promise((resolve) => {
        // Alten Dialog entfernen, falls einer existiert
        const oldOverlay = document.querySelector('.confirm-overlay');
        if (oldOverlay) {
            oldOverlay.remove();
        }

        const confirmOverlay = document.createElement('div');
        confirmOverlay.className = 'confirm-overlay';

        const confirmBox = document.createElement('div');
        confirmBox.className = 'confirm-box';

        // Verwende textContent für Sicherheit gegen XSS in title/message
        confirmBox.innerHTML = `
            <h2></h2>
            <p></p>
            <div class="confirm-actions">
                <button class="btn btn-secondary" id="confirm-cancel">Abbrechen</button>
                <button class="btn btn-danger" id="confirm-ok">Bestätigen</button>
            </div>
        `;
        confirmBox.querySelector('h2').textContent = title;
        confirmBox.querySelector('p').textContent = message;


        confirmOverlay.appendChild(confirmBox);
        document.body.appendChild(confirmOverlay);

        // Animation starten
        setTimeout(() => confirmOverlay.classList.add('visible'), 10);

        const close = (value) => {
            confirmOverlay.classList.remove('visible');
            confirmOverlay.addEventListener('transitionend', () => {
                confirmOverlay.remove();
                resolve(value);
            }, { once: true }); // Ensure listener is removed after execution
        };

        document.getElementById('confirm-ok').onclick = () => close(true);
        document.getElementById('confirm-cancel').onclick = () => close(false);

        // Schließen bei Klick außerhalb (optional)
        confirmOverlay.addEventListener('click', (e) => {
            if (e.target === confirmOverlay) {
                close(false);
            }
        });
         // Schließen bei Escape-Taste (optional)
        const escapeListener = (e) => {
             if (e.key === 'Escape') {
                 close(false);
                 document.removeEventListener('keydown', escapeListener); // Listener entfernen
             }
         };
         document.addEventListener('keydown', escapeListener);
         // Sicherstellen, dass der Listener entfernt wird, wenn das Modal anders geschlossen wird
         confirmOverlay.addEventListener('transitionend', () => {
             if (!confirmOverlay.classList.contains('visible')) {
                 document.removeEventListener('keydown', escapeListener);
             }
         }, { once: true });
    });
}

// Global assignments removed
// window.showToast = showToast;
// window.showConfirm = showConfirm;
