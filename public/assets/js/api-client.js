import { showToast } from './notifications.js'; 
export async function apiFetch(url, options = {}) {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    const defaultHeaders = {
        'X-Requested-With': 'XMLHttpRequest',
    };
    if (csrfToken && options.method === 'POST' && !(options.body instanceof FormData)) {
        defaultHeaders['X-CSRF-TOKEN'] = csrfToken;
    }
    if (!(options.body instanceof FormData) && options.headers && options.headers['Content-Type']) {
         defaultHeaders['Content-Type'] = options.headers['Content-Type'];
    } else if (!(options.body instanceof FormData) && options.body && typeof options.body === 'string') {
         defaultHeaders['Content-Type'] = 'application/json';
    }
    const config = {
        ...options,
        headers: {
            ...defaultHeaders,
            ...(options.headers && !(options.body instanceof FormData) ? options.headers : {}),
        },
    };
    if (config.body instanceof FormData && csrfToken && options.method === 'POST') {
        if (!config.body.has('_csrf_token')) {
            config.body.append('_csrf_token', csrfToken);
        }
    }
    try {
        const response = await fetch(url, config);
        const responseText = await response.text();
        if (!response.ok) {
            let errorMessage = `Serverfehler: ${response.status}`;
            try {
                const errorData = JSON.parse(responseText);
                errorMessage = errorData.message || errorMessage;
            } catch (e) {
                errorMessage = `Serverfehler: ${response.status} (${response.statusText})`;
                if (response.status === 404) {
                    errorMessage = `API-Endpunkt nicht gefunden (404): ${url}`;
                }
            }
            if (response.status === 403 && (errorMessage.includes('CSRF') || errorMessage.includes('Sicherheit') || responseText.includes('CSRF'))) {
                throw new Error("Sicherheitsüberprüfung fehlgeschlagen. Bitte laden Sie die Seite neu und versuchen Sie es erneut.");
            }
            throw new Error(errorMessage);
        }
        let data;
        try {
            data = JSON.parse(responseText);
        } catch (e) {
            console.error("API-Antwort war kein gültiges JSON:", responseText);
            throw new Error(`Ungültige JSON-Antwort vom Server erhalten. (Möglicherweise ein PHP-Fehler). Antwort-Anfang: ${responseText.substring(0, 100)}...`);
        }
        if (data.success === false) {
            throw new Error(data.message || 'Ein unbekannter Anwendungsfehler ist aufgetreten.');
        }
        return data;
    } catch (error) {
        console.error('API Fehler:', error);
        showToast(error.message, 'error');
        throw error;
    }
}