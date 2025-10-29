import * as DOM from './planer-dom.js';
import { getState } from './planer-state.js';
import { escapeHtml } from './planer-utils.js';

/** Füllt einen Jahres-Selektor (allgemein) */
export const populateYearSelector = (selectorElement, defaultYear) => {
    if (!selectorElement) return; // Add check
    const currentYear = new Date().getFullYear();
    let options = '';
    for (let i = currentYear - 2; i <= currentYear + 2; i++) {
        options += `<option value="${i}">${i}</option>`;
    }
    selectorElement.innerHTML = options;
    selectorElement.value = defaultYear;
};

/** Füllt einen Wochen-Selektor (allgemein) */
export const populateWeekSelector = (selectorElement, defaultWeek) => {
     if (!selectorElement) return; // Add check
    let options = '';
    for (let i = 1; i <= 53; i++) {
        options += `<option value="${i}">KW ${i}</option>`;
    }
    selectorElement.innerHTML = options;
    selectorElement.value = defaultWeek;
};

/** Füllt den Klassen-Hauptselektor */
export const populateClassSelector = (classes = []) => { // Default to empty array
    if (!DOM.classSelector) return; // Add check
    console.log(`planer-ui: Populating class selector with ${classes.length} classes...`); // Logging hinzugefügt
    DOM.classSelector.innerHTML = '<option value="">Bitte wählen...</option>' +
        classes.map(c => `<option value="${c.class_id}">${c.class_id} - ${escapeHtml(c.class_name)}</option>`).join(''); // Escape class name
};

/** Füllt den Lehrer-Hauptselektor */
export const populateTeacherSelector = (teachers = []) => { // Default to empty array
     if (!DOM.teacherSelector) return; // Add check
    const filteredTeachers = teachers.filter(t => t.teacher_shortcut !== 'SGL'); // Filter out 'SGL'
    console.log(`planer-ui: Populating teacher selector with ${filteredTeachers.length} teachers...`); // Logging hinzugefügt
    DOM.teacherSelector.innerHTML = '<option value="">Bitte wählen...</option>' +
        filteredTeachers.map(t => `<option value="${t.teacher_id}">${escapeHtml(t.last_name)}, ${escapeHtml(t.first_name)} (${escapeHtml(t.teacher_shortcut)})</option>`).join(''); // Escape names/shortcut
};

/** Füllt alle Selects in den Modals */
export const populateAllModalSelects = (stammdaten) => {
     if (!DOM.modal) return; // Exit if modal doesn't exist on the page
    console.log("planer-ui: Populating modal selects..."); // Logging hinzugefügt
    const { subjects = [], teachers = [], rooms = [], classes = [] } = stammdaten || {}; // Default to empty objects/arrays

    // Safely query selectors within the modal
    const subjectSelect = DOM.modal.querySelector('#subject_id');
    const newSubjectSelect = DOM.modal.querySelector('#new_subject_id');
    const teacherSelect = DOM.modal.querySelector('#teacher_id');
    const newTeacherSelect = DOM.modal.querySelector('#new_teacher_id');
    const roomSelect = DOM.modal.querySelector('#room_id');
    const newRoomSelect = DOM.modal.querySelector('#new_room_id');
    const templateClassSelect = DOM.modal.querySelector('#template_class_id');

    // Populate Subject Selects
    if (subjectSelect) {
        subjectSelect.innerHTML = subjects.map(s => `<option value="${s.subject_id}">${escapeHtml(s.subject_name)} (${escapeHtml(s.subject_shortcut)})</option>`).join('');
    }
    if (newSubjectSelect) {
        newSubjectSelect.innerHTML = '<option value="">(wie Original)</option>' + subjects.map(s => `<option value="${s.subject_id}">${escapeHtml(s.subject_name)} (${escapeHtml(s.subject_shortcut)})</option>`).join('');
    }

    // Populate Teacher Selects (filter out SGL)
    const filteredTeachers = teachers.filter(t => t.teacher_shortcut !== 'SGL');
    const teacherOptions = filteredTeachers.map(t => `<option value="${t.teacher_id}">${escapeHtml(t.first_name)} ${escapeHtml(t.last_name)}</option>`).join('');
    if (teacherSelect) {
        teacherSelect.innerHTML = teacherOptions;
    }
    if (newTeacherSelect) {
        newTeacherSelect.innerHTML = '<option value="">(kein Lehrer)</option>' + teacherOptions;
    }

    // Populate Room Selects
    const roomOptions = rooms.map(r => `<option value="${r.room_id}">${escapeHtml(r.room_name)}</option>`).join('');
    if (roomSelect) {
        roomSelect.innerHTML = roomOptions;
    }
    if (newRoomSelect) {
        newRoomSelect.innerHTML = '<option value="">(kein Raum)</option>' + roomOptions;
    }

    // Populate Class Select (for Template Editor within the modal)
    if (templateClassSelect) {
        templateClassSelect.innerHTML = '<option value="0">(Keine Klasse)</option>' + classes.map(c => `<option value="${c.class_id}">${escapeHtml(c.class_name)}</option>`).join('');
    }
};


/** Füllt die Vorlagen-Selects (Anwenden-Modal) */
export const populateTemplateSelects = (templates = []) => { // Default to empty array
     if (!DOM.applyTemplateSelect) return; // Add check
     console.log(`planer-ui: Populating template select with ${templates.length} templates...`); // Logging hinzugefügt
    DOM.applyTemplateSelect.innerHTML = templates.length > 0
        ? '<option value="">-- Vorlage wählen --</option>' + templates.map(t => `<option value="${t.template_id}">${escapeHtml(t.name)}</option>`).join('')
        : '<option value="">Keine Vorlagen verfügbar</option>';
};

/** Aktualisiert die UI für den Veröffentlichungsstatus */
export const updatePublishControls = (status = { student: false, teacher: false }) => { // Default status
     // Check if elements exist before updating
    if (DOM.publishWeekLabel) DOM.publishWeekLabel.textContent = DOM.weekSelector?.value || '--'; // Use optional chaining

    if (DOM.publishStatusStudent) {
        DOM.publishStatusStudent.textContent = status.student ? 'Schüler: Veröffentlicht' : 'Schüler: Nicht veröffentlicht';
        DOM.publishStatusStudent.classList.toggle('published', !!status.student);
        DOM.publishStatusStudent.classList.toggle('not-published', !status.student);
    }
     if (DOM.publishStudentBtn) DOM.publishStudentBtn.classList.toggle('hidden', !!status.student);
     if (DOM.unpublishStudentBtn) DOM.unpublishStudentBtn.classList.toggle('hidden', !status.student);

    if (DOM.publishStatusTeacher) {
        DOM.publishStatusTeacher.textContent = status.teacher ? 'Lehrer: Veröffentlicht' : 'Lehrer: Nicht veröffentlicht';
        DOM.publishStatusTeacher.classList.toggle('published', !!status.teacher);
        DOM.publishStatusTeacher.classList.toggle('not-published', !status.teacher);
    }
     if (DOM.publishTeacherBtn) DOM.publishTeacherBtn.classList.toggle('hidden', !!status.teacher);
     if (DOM.unpublishTeacherBtn) DOM.unpublishTeacherBtn.classList.toggle('hidden', !status.teacher);

    // Disable buttons if year or week is not selected
    const isValidWeek = DOM.yearSelector?.value && DOM.weekSelector?.value;
     if (DOM.publishStudentBtn) DOM.publishStudentBtn.disabled = !isValidWeek;
     if (DOM.publishTeacherBtn) DOM.publishTeacherBtn.disabled = !isValidWeek;
     if (DOM.unpublishStudentBtn) DOM.unpublishStudentBtn.disabled = !isValidWeek;
     if (DOM.unpublishTeacherBtn) DOM.unpublishTeacherBtn.disabled = !isValidWeek;
};

/** Zeigt Konfliktwarnungen im Modal an */
export const showConflicts = (conflictMessages = []) => { // Default to empty array
    if (DOM.conflictWarningBox) {
        DOM.conflictWarningBox.innerHTML = conflictMessages.map(msg => `<div>${escapeHtml(msg)}</div>`).join('');
        DOM.conflictWarningBox.style.display = 'block';
    }
     // Disable save button when conflicts are shown
    if (DOM.saveButton) {
        DOM.saveButton.disabled = true;
        DOM.saveButton.style.opacity = '0.5';
        DOM.saveButton.style.cursor = 'not-allowed';
    }
};

/** Versteckt Konfliktwarnungen im Modal */
export const hideConflicts = () => {
    if (DOM.conflictWarningBox) {
        DOM.conflictWarningBox.innerHTML = '';
        DOM.conflictWarningBox.style.display = 'none';
    }
     // Re-enable save button when conflicts are hidden
    if (DOM.saveButton) {
        DOM.saveButton.disabled = false;
        DOM.saveButton.style.opacity = '1';
        DOM.saveButton.style.cursor = 'pointer';
    }
};

/** Schaltet die Ansicht im "Vorlagen verwalten"-Modal um */
export const showTemplateView = (viewType) => {
     // Check if elements exist before manipulating them
     if (!DOM.templateListView || !DOM.templateEditorView || !DOM.manageTemplatesModal) return;

    if (viewType === 'editor') {
        DOM.templateListView.style.display = 'none';
        DOM.templateEditorView.style.display = 'block';
        if (DOM.templateEditorTitle) DOM.templateEditorTitle.textContent = "Neue leere Vorlage erstellen"; // Default title
        const nameInput = DOM.manageTemplatesModal.querySelector('#template-editor-name');
        if (nameInput) nameInput.focus(); // Focus on name input when editor opens
    } else { // 'list' or default
        DOM.templateListView.style.display = 'block';
        DOM.templateEditorView.style.display = 'none';
    }
};
