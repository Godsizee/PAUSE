/**
 * Hilfsfunktion zur Ermittlung der Kalenderwoche und des Jahres nach ISO 8601.
 */
export function getWeekAndYear(date) {
    const d = new Date(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()));
    d.setUTCDate(d.getUTCDate() + 4 - (d.getUTCDay() || 7));
    const yearStart = new Date(Date.UTC(d.getUTCFullYear(), 0, 1));
    const weekNo = Math.ceil((((d - yearStart) / 86400000) + 1) / 7);
    return { week: weekNo, year: d.getUTCFullYear() };
}

/**
 * Ermittelt das Datum des Montags einer gegebenen Kalenderwoche und eines Jahres.
 * @param {number} week - Die Kalenderwoche.
 * @param {number} year - Das Jahr.
 * @returns {Date} Das Datumsobjekt für den Montag.
 */
export function getDateOfISOWeek(week, year) {
    const simple = new Date(Date.UTC(year, 0, 1 + (week - 1) * 7));
    const dow = simple.getUTCDay(); // Sonntag = 0, Montag = 1 ...
    const ISOweekStart = simple;
    // Gehe zum Montag der Woche
    ISOweekStart.setUTCDate(simple.getUTCDate() - (dow || 7) + 1);
    // Konvertiere zurück in lokale Zeit für die Anzeige
    return new Date(ISOweekStart.getFullYear(), ISOweekStart.getMonth(), ISOweekStart.getDate());
}

/**
 * Berechnet den YYYY-MM-DD Datumsstring für einen Wochentag (1-5) in der aktuell gewählten Woche.
 * @param {number} dayNum - Der Wochentag (1=Mo, 5=Fr).
 * @param {string} year - Das ausgewählte Jahr.
 * @param {string} week - Die ausgewählte Woche.
 * @returns {string} Das Datum im Format YYYY-MM-DD.
A*/
export function getDateForDayInWeek(dayNum, year, week) {
    const monday = getDateOfISOWeek(week, year);
    const targetDate = new Date(monday.getTime() + (dayNum - 1) * 24 * 60 * 60 * 1000);

    const yyyy = targetDate.getFullYear();
    const mm = String(targetDate.getMonth() + 1).padStart(2, '0');
    const dd = String(targetDate.getDate()).padStart(2, '0');

    return `${yyyy}-${mm}-${dd}`;
}

/**
 * Bereinigt einen String für die sichere Anzeige in HTML.
 * @param {*} unsafe - Der Eingabewert.
Source: 
 * @returns {string} Der bereinigte String.
 */
export function escapeHtml(unsafe) {
    if (!unsafe) return '';
    return String(unsafe)
         .replace(/&/g, "&amp;")
         .replace(/</g, "&lt;")
         .replace(/>/g, "&gt;")
         .replace(/"/g, "&quot;")
         .replace(/'/g, "&#039;");
}