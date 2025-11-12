export function showToast(message, type = 'info', duration = 3000) {
    const oldToast = document.querySelector('.toast-notification');
    if (oldToast) {
        oldToast.remove();
    }
    const toast = document.createElement('div');
    toast.className = `toast-notification toast-${type}`;
    toast.textContent = message;
    document.body.appendChild(toast);
    setTimeout(() => {
        toast.classList.add('visible');
    }, 10); 
    setTimeout(() => {
        toast.classList.remove('visible');
        toast.addEventListener('transitionend', () => toast.remove());
    }, duration);
}
export function showConfirm(title, message) {
    return new Promise((resolve) => {
        const oldOverlay = document.querySelector('.confirm-overlay');
        if (oldOverlay) {
            oldOverlay.remove();
        }
        const confirmOverlay = document.createElement('div');
        confirmOverlay.className = 'confirm-overlay';
        const confirmBox = document.createElement('div');
        confirmBox.className = 'confirm-box';
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
        setTimeout(() => confirmOverlay.classList.add('visible'), 10);
        const close = (value) => {
            confirmOverlay.classList.remove('visible');
            confirmOverlay.addEventListener('transitionend', () => {
                confirmOverlay.remove();
                resolve(value);
            }, { once: true }); 
        };
        document.getElementById('confirm-ok').onclick = () => close(true);
        document.getElementById('confirm-cancel').onclick = () => close(false);
        confirmOverlay.addEventListener('click', (e) => {
            if (e.target === confirmOverlay) {
                close(false);
            }
        });
        const escapeListener = (e) => {
             if (e.key === 'Escape') {
                 close(false);
                 document.removeEventListener('keydown', escapeListener); 
             }
         };
         document.addEventListener('keydown', escapeListener);
         confirmOverlay.addEventListener('transitionend', () => {
             if (!confirmOverlay.classList.contains('visible')) {
                 document.removeEventListener('keydown', escapeListener);
             }
         }, { once: true });
    });
}