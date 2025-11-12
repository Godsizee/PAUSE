export function initializeFooterUi() {
    const imprintToggle = document.getElementById('imprint-toggle');
    const imprintDetails = document.getElementById('imprint-details');
    const imprintClose = document.getElementById('imprint-close');
    if (imprintToggle && imprintDetails && imprintClose) {
        const toggleImprint = (show) => {
            if (show) {
                imprintDetails.classList.add('visible');
                imprintToggle.setAttribute('aria-expanded', 'true');
            } else {
                imprintDetails.classList.remove('visible');
                imprintToggle.setAttribute('aria-expanded', 'false');
            }
        };
        imprintToggle.addEventListener('click', () => {
            const isVisible = imprintDetails.classList.contains('visible');
            toggleImprint(!isVisible);
        });
        imprintClose.addEventListener('click', () => {
            toggleImprint(false);
        });
        document.addEventListener('click', (event) => {
            if (imprintDetails.classList.contains('visible') &&
                !imprintDetails.contains(event.target) &&
                event.target !== imprintToggle) {
                toggleImprint(false);
            }
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && imprintDetails.classList.contains('visible')) {
                toggleImprint(false);
            }
        });
    }
}