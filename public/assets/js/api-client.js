import { showToast } from './notifications.js'; // Import showToast

/**
 * Zentraler API-Client zum Senden von Anfragen an das Backend.
 * @param {string} url - Der API-Endpunkt (z.B. '/api/admin/users').
 * @param {object} options - Die Konfigurationsoptionen für den Fetch-Aufruf (z.B. method, body).
 * @returns {Promise<any>} - Ein Promise, das die JSON-Antwort des Servers zurückgibt.
 * @throws {Error} - Wirft einen Fehler bei Netzwerkproblemen oder wenn die API 'success: false' zurückgibt.
 */
export async function apiFetch(url, options = {}) {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

    const defaultHeaders = {
        'X-Requested-With': 'XMLHttpRequest',
    };

    // Füge das CSRF-Token zu allen POST-Anfragen hinzu, wenn es nicht FormData ist.
    // Bei FormData wird es im Body hinzugefügt.
    if (csrfToken && options.method === 'POST' && !(options.body instanceof FormData)) {
        defaultHeaders['X-CSRF-TOKEN'] = csrfToken;
    }
    // Für GET oder andere Methoden (falls CSRF im Header benötigt wird)
    // else if (csrfToken && options.method !== 'POST') { // Beispiel, anpassen falls nötig
    //     defaultHeaders['X-CSRF-TOKEN'] = csrfToken;
    // }


    // Content-Type nur setzen, wenn es kein FormData ist und einer vorhanden ist
    if (!(options.body instanceof FormData) && options.headers && options.headers['Content-Type']) {
         defaultHeaders['Content-Type'] = options.headers['Content-Type'];
    } else if (!(options.body instanceof FormData) && options.body && typeof options.body === 'string') {
        // Default auf application/json wenn body ein String ist (wahrscheinlich JSON.stringify)
         defaultHeaders['Content-Type'] = 'application/json';
    }


    const config = {
        ...options,
        headers: {
            ...defaultHeaders,
            // Überschreibe mit spezifischen Headern, außer Content-Type bei FormData
            ...(options.headers && !(options.body instanceof FormData) ? options.headers : {}),
        },
    };

    // Füge CSRF-Token zum FormData Body hinzu, falls nötig.
    if (config.body instanceof FormData && csrfToken && options.method === 'POST') {
        if (!config.body.has('_csrf_token')) {
            config.body.append('_csrf_token', csrfToken);
        }
    }


    try {
        const response = await fetch(url, config);

        // Zuerst den Text der Antwort abrufen
        const responseText = await response.text();

        if (!response.ok) {
            // Server hat einen HTTP-Fehlerstatus zurückgegeben (z.B. 400, 403, 500)
            let errorMessage = `Serverfehler: ${response.status}`;
            // Versuchen, eine JSON-Fehlermeldung aus dem Text zu parsen
            try {
                const errorData = JSON.parse(responseText);
                errorMessage = errorData.message || errorMessage;
            } catch (e) {
                // Der Text war kein JSON (z.B. eine HTML-Fehlerseite), verwende den Status-Text
                errorMessage = `Serverfehler: ${response.status} (${response.statusText})`;
                // Bei 404 eine spezifischere Meldung
                if (response.status === 404) {
                    errorMessage = `API-Endpunkt nicht gefunden (404): ${url}`;
                }
            }
            
            // Spezifische Behandlung für 403 CSRF Fehler
            if (response.status === 403 && (errorMessage.includes('CSRF') || errorMessage.includes('Sicherheit') || responseText.includes('CSRF'))) {
                throw new Error("Sicherheitsüberprüfung fehlgeschlagen. Bitte laden Sie die Seite neu und versuchen Sie es erneut.");
            }
            throw new Error(errorMessage);
        }

        // Response war OK (2xx), jetzt versuchen wir zu parsen
        let data;
        try {
            data = JSON.parse(responseText);
        } catch (e) {
            // Response war 200 OK, aber der Body war KEIN JSON
            // (Sehr wahrscheinlich ein PHP-Notice/Warning)
            console.error("API-Antwort war kein gültiges JSON:", responseText);
            throw new Error(`Ungültige JSON-Antwort vom Server erhalten. (Möglicherweise ein PHP-Fehler). Antwort-Anfang: ${responseText.substring(0, 100)}...`);
        }


        if (data.success === false) {
            // Die API meldet einen Anwendungsfehler (z.B. ungültige Eingabe)
            throw new Error(data.message || 'Ein unbekannter Anwendungsfehler ist aufgetreten.');
        }

        return data;

    } catch (error) {
        // Zeigt dem Benutzer eine Fehlermeldung an und leitet den Fehler weiter
        console.error('API Fehler:', error);
        // Verwende die importierte Funktion direkt
        showToast(error.message, 'error');

        // Wirft den Fehler erneut, damit aufrufende try/catch-Blöcke darauf reagieren können
        throw error;
    }
}

