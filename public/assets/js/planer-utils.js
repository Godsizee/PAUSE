export function getWeekAndYear(date) {
    const d = new Date(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()));
    d.setUTCDate(d.getUTCDate() + 4 - (d.getUTCDay() || 7));
    const yearStart = new Date(Date.UTC(d.getUTCFullYear(), 0, 1));
    const weekNo = Math.ceil((((d - yearStart) / 86400000) + 1) / 7);
    return { week: weekNo, year: d.getUTCFullYear() };
}
export function getDateOfISOWeek(week, year) {
    const simple = new Date(Date.UTC(year, 0, 1 + (week - 1) * 7));
    const dow = simple.getUTCDay(); 
    const ISOweekStart = simple;
    ISOweekStart.setUTCDate(simple.getUTCDate() - (dow || 7) + 1);
    return new Date(ISOweekStart.getFullYear(), ISOweekStart.getMonth(), ISOweekStart.getDate());
}
export function getDateForDayInWeek(dayNum, year, week) {
    const monday = getDateOfISOWeek(week, year);
    const targetDate = new Date(monday.getTime() + (dayNum - 1) * 24 * 60 * 60 * 1000);
    const yyyy = targetDate.getFullYear();
    const mm = String(targetDate.getMonth() + 1).padStart(2, '0');
    const dd = String(targetDate.getDate()).padStart(2, '0');
    return `${yyyy}-${mm}-${dd}`;
}
export function escapeHtml(unsafe) {
    if (!unsafe) return '';
    return String(unsafe)
         .replace(/&/g, "&amp;")
         .replace(/</g, "&lt;")
         .replace(/>/g, "&gt;")
         .replace(/"/g, "&quot;")
         .replace(/'/g, "&#039;");
}