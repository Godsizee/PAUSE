import * as DOM from './planer-dom.js';
import { getState } from './planer-state.js';
import { escapeHtml } from './planer-utils.js';
export const populateYearSelector = (selectorElement, defaultYear) => {
    if (!selectorElement) return; 
    const currentYear = new Date().getFullYear();
    let options = '';
    for (let i = currentYear - 2; i <= currentYear + 2; i++) {
        options += `<option value="${i}">${i}</option>`;
    }
    selectorElement.innerHTML = options;
    selectorElement.value = defaultYear;
};
export const populateWeekSelector = (selectorElement, defaultWeek) => {
     if (!selectorElement) return; 
    let options = '';
    for (let i = 1; i <= 53; i++) {
        options += `<option value="${i}">KW ${i}</option>`;
    }
    selectorElement.innerHTML = options;
    selectorElement.value = defaultWeek;
};
export const populateClassSelector = (classes = []) => { 
    if (!DOM.classSelector) return; 
    console.log(`planer-ui: Populating class selector with ${classes.length} classes...`); 
    DOM.classSelector.innerHTML = '<option value="">Bitte wählen...</option>' +
        classes.map(c => `<option value="${c.class_id}">${c.class_id} - ${escapeHtml(c.class_name)}</option>`).join(''); 
};
export const populateTeacherSelector = (teachers = []) => { 
     if (!DOM.teacherSelector) return; 
    const filteredTeachers = teachers.filter(t => t.teacher_shortcut !== 'SGL'); 
    console.log(`planer-ui: Populating teacher selector with ${filteredTeachers.length} teachers...`); 
    DOM.teacherSelector.innerHTML = '<option value="">Bitte wählen...</option>' +
        filteredTeachers.map(t => `<option value="${t.teacher_id}">${escapeHtml(t.last_name)}, ${escapeHtml(t.first_name)} (${escapeHtml(t.teacher_shortcut)})</option>`).join(''); 
};
export const populateAllModalSelects = (stammdaten) => {
     if (!DOM.modal) return; 
    console.log("planer-ui: Populating modal selects..."); 
    const { subjects = [], teachers = [], rooms = [], classes = [] } = stammdaten || {}; 
    const subjectSelect = DOM.modal.querySelector('#subject_id');
    const newSubjectSelect = DOM.modal.querySelector('#new_subject_id');
    const teacherSelect = DOM.modal.querySelector('#teacher_id');
    const newTeacherSelect = DOM.modal.querySelector('#new_teacher_id');
    const roomSelect = DOM.modal.querySelector('#room_id');
    const newRoomSelect = DOM.modal.querySelector('#new_room_id');
    const templateClassSelect = DOM.modal.querySelector('#template_class_id');
    if (subjectSelect) {
        subjectSelect.innerHTML = subjects.map(s => `<option value="${s.subject_id}">${escapeHtml(s.subject_name)} (${escapeHtml(s.subject_shortcut)})</option>`).join('');
    }
    if (newSubjectSelect) {
        newSubjectSelect.innerHTML = '<option value="">(wie Original)</option>' + subjects.map(s => `<option value="${s.subject_id}">${escapeHtml(s.subject_name)} (${escapeHtml(s.subject_shortcut)})</option>`).join('');
    }
    const filteredTeachers = teachers.filter(t => t.teacher_shortcut !== 'SGL');
    const teacherOptions = filteredTeachers.map(t => `<option value="${t.teacher_id}">${escapeHtml(t.first_name)} ${escapeHtml(t.last_name)}</option>`).join('');
    if (teacherSelect) {
        teacherSelect.innerHTML = teacherOptions;
    }
    if (newTeacherSelect) {
        newTeacherSelect.innerHTML = '<option value="">(kein Lehrer)</option>' + teacherOptions;
    }
    const roomOptions = rooms.map(r => `<option value="${r.room_id}">${escapeHtml(r.room_name)}</option>`).join('');
    if (roomSelect) {
        roomSelect.innerHTML = roomOptions;
    }
    if (newRoomSelect) {
        newRoomSelect.innerHTML = '<option value="">(kein Raum)</option>' + roomOptions;
    }
    if (templateClassSelect) {
        templateClassSelect.innerHTML = '<option value="0">(Keine Klasse)</option>' + classes.map(c => `<option value="${c.class_id}">${escapeHtml(c.class_name)}</option>`).join('');
    }
};
export const populateTemplateSelects = (templates = []) => { 
     if (!DOM.applyTemplateSelect) return; 
     console.log(`planer-ui: Populating template select with ${templates.length} templates...`); 
    DOM.applyTemplateSelect.innerHTML = templates.length > 0
        ? '<option value="">-- Vorlage wählen --</option>' + templates.map(t => `<option value="${t.template_id}">${escapeHtml(t.name)}</option>`).join('')
        : '<option value="">Keine Vorlagen verfügbar</option>';
};
export const updatePublishControls = (status = { student: false, teacher: false }) => { 
    if (DOM.publishWeekLabel) DOM.publishWeekLabel.textContent = DOM.weekSelector?.value || '--'; 
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
    const isValidWeek = DOM.yearSelector?.value && DOM.weekSelector?.value;
     if (DOM.publishStudentBtn) DOM.publishStudentBtn.disabled = !isValidWeek;
     if (DOM.publishTeacherBtn) DOM.publishTeacherBtn.disabled = !isValidWeek;
     if (DOM.unpublishStudentBtn) DOM.unpublishStudentBtn.disabled = !isValidWeek;
     if (DOM.unpublishTeacherBtn) DOM.unpublishTeacherBtn.disabled = !isValidWeek;
};
export const showConflicts = (conflictMessages = []) => { 
    if (DOM.conflictWarningBox) {
        DOM.conflictWarningBox.innerHTML = conflictMessages.map(msg => `<div>${escapeHtml(msg)}</div>`).join('');
        DOM.conflictWarningBox.style.display = 'block';
    }
    if (DOM.saveButton) {
        DOM.saveButton.disabled = true;
        DOM.saveButton.style.opacity = '0.5';
        DOM.saveButton.style.cursor = 'not-allowed';
    }
};
export const hideConflicts = () => {
    if (DOM.conflictWarningBox) {
        DOM.conflictWarningBox.innerHTML = '';
        DOM.conflictWarningBox.style.display = 'none';
    }
    if (DOM.saveButton) {
        DOM.saveButton.disabled = false;
        DOM.saveButton.style.opacity = '1';
        DOM.saveButton.style.cursor = 'pointer';
    }
};
export const showTemplateView = (viewType) => {
     if (!DOM.templateListView || !DOM.templateEditorView || !DOM.manageTemplatesModal) return;
    if (viewType === 'editor') {
        DOM.templateListView.style.display = 'none';
        DOM.templateEditorView.style.display = 'flex'; 
        if (DOM.templateEditorTitle) DOM.templateEditorTitle.textContent = "Neue leere Vorlage erstellen"; 
        const nameInput = DOM.manageTemplatesModal.querySelector('#template-editor-name');
        if (nameInput) nameInput.focus(); 
    } else { 
        DOM.templateListView.style.display = 'flex'; 
        DOM.templateEditorView.style.display = 'none';
    }
};