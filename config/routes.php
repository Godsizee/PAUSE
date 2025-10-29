<?php
// config/routes.php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\StammdatenController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\AnnouncementController as AdminAnnouncementController;
use App\Http\Controllers\Admin\CsvTemplateController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\CommunityController as AdminCommunityController;
use App\Http\Controllers\Planer\PlanController;
use App\Http\Controllers\Planer\AbsenceController; // NEU HINZUGEFÜGT
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\CommunityController;
use App\Http\Controllers\IcalController;
use App\Http\Controllers\PdfController;
use App\Http\Controllers\TeacherController;
use App\Http\Controllers\AcademicEventController;
use App\Http\Controllers\SingleEventController;

return [
    // --- Authentifizierung ---
    '#^login$#' => [AuthController::class, 'showLogin'],
    '#^login/process$#' => [AuthController::class, 'handleLogin'],
    '#^logout$#' => [AuthController::class, 'logout'],

    // --- Dashboard (Startseite nach Login) ---
    '#^$#' => [DashboardController::class, 'index'], // Root maps to dashboard
    '#^dashboard$#' => [DashboardController::class, 'index'],

    // --- Admin Bereich ---
    '#^admin/dashboard$#' => [AdminDashboardController::class, 'index'],
    '#^admin/users$#' => [UserController::class, 'index'],
    '#^admin/csv-template$#' => [CsvTemplateController::class, 'index'],
    '#^admin/stammdaten$#' => [StammdatenController::class, 'index'],
    '#^admin/announcements$#' => [AdminAnnouncementController::class, 'index'],
    '#^admin/audit-logs$#' => [AuditLogController::class, 'index'], 
    '#^admin/settings$#' => [SettingsController::class, 'index'], 
    '#^admin/community-moderation$#' => [AdminCommunityController::class, 'index'],


    // --- Planer Bereich ---
    '#^planer/dashboard$#' => [PlanController::class, 'index'],
    '#^planer/absences$#' => [AbsenceController::class, 'index'], // NEU HINZUGEFÜGT

    // --- iCal Feed ---
    '#^ical/([a-f0-9]{64})$#' => [IcalController::class, 'generateFeed'],
    
    // --- Einzel-Event ICS Download ---
    '#^ics/event/(\w+)/(\d+)$#' => [SingleEventController::class, 'generateIcs'],

    // --- PDF Export ---
    '#^pdf/timetable/(\d{4})/(\d{1,2})$#' => [PdfController::class, 'generateTimetablePdf'],

    // --- API Routes for Stammdaten ---
    '#^api/admin/subjects$#' => [StammdatenController::class, 'getSubjects'],
    '#^api/admin/subjects/create$#' => [StammdatenController::class, 'createSubject'],
    '#^api/admin/subjects/update$#' => [StammdatenController::class, 'updateSubject'],
    '#^api/admin/subjects/delete$#' => [StammdatenController::class, 'deleteSubject'],
    '#^api/admin/rooms$#' => [StammdatenController::class, 'getRooms'],
    '#^api/admin/rooms/create$#' => [StammdatenController::class, 'createRoom'],
    '#^api/admin/rooms/update$#' => [StammdatenController::class, 'updateRoom'],
    '#^api/admin/rooms/delete$#' => [StammdatenController::class, 'deleteRoom'],
    '#^api/admin/teachers$#' => [StammdatenController::class, 'getTeachers'],
    '#^api/admin/teachers/create$#' => [StammdatenController::class, 'createTeacher'],
    '#^api/admin/teachers/update$#' => [StammdatenController::class, 'updateTeacher'],
    '#^api/admin/teachers/delete$#' => [StammdatenController::class, 'deleteTeacher'],
    '#^api/admin/classes$#' => [StammdatenController::class, 'getClasses'],
    '#^api/admin/classes/create$#' => [StammdatenController::class, 'createClass'],
    '#^api/admin/classes/update$#' => [StammdatenController::class, 'updateClass'],
    '#^api/admin/classes/delete$#' => [StammdatenController::class, 'deleteClass'],

    // --- API Routes for Users ---
    '#^api/admin/users$#' => [UserController::class, 'getUsers'],
    '#^api/admin/users/create$#' => [UserController::class, 'createUser'],
    '#^api/admin/users/update$#' => [UserController::class, 'updateUser'],
    '#^api/admin/users/delete$#' => [UserController::class, 'deleteUser'],
    '#^api/admin/users/import$#' => [UserController::class, 'importUsers'],

    // --- API Routes for Admin ---
    '#^api/admin/audit-logs$#' => [AuditLogController::class, 'getLogsApi'],
    '#^api/admin/settings/save$#' => [SettingsController::class, 'save'],
    '#^api/admin/cache/clear$#' => [SettingsController::class, 'clearCacheApi'], 
    '#^api/admin/community/approve$#' => [CommunityController::class, 'approvePostApi'],
    // 'rejectPostApi' (alt) wird durch 'deletePostApi' ersetzt, da Admins/Schüler jetzt löschen
    '#^api/admin/community/reject$#' => [CommunityController::class, 'rejectPostApi'], // Wird jetzt auf 'rejected' setzen
    '#^api/admin/community/delete$#' => [CommunityController::class, 'deletePostApi'], // NEU: Admin löscht freigegebenen Post


    // --- API Routes for Planer ---
    '#^api/planer/data$#' => [PlanController::class, 'getTimetableData'],
    '#^api/planer/entry/save$#' => [PlanController::class, 'saveEntry'],
    '#^api/planer/entry/delete$#' => [PlanController::class, 'deleteEntry'],
    '#^api/planer/substitution/save$#' => [PlanController::class, 'saveSubstitution'],
    '#^api/planer/substitution/delete$#' => [PlanController::class, 'deleteSubstitution'],
    '#^api/planer/publish$#' => [PlanController::class, 'publish'],
    '#^api/planer/unpublish$#' => [PlanController::class, 'unpublish'],
    '#^api/planer/status$#' => [PlanController::class, 'getStatus'],
    '#^api/planer/check-conflicts$#' => [PlanController::class, 'checkConflictsApi'],
    '#^api/planer/copy-week$#' => [PlanController::class, 'copyWeek'],
    '#^api/planer/templates$#' => [PlanController::class, 'getTemplates'],
    '#^api/planer/templates/create$#' => [PlanController::class, 'createTemplate'],
    '#^api/planer/templates/apply$#' => [PlanController::class, 'applyTemplate'],
    '#^api/planer/templates/delete$#' => [PlanController::class, 'deleteTemplate'],
    '#^api/planer/templates/(\d+)$#' => [PlanController::class, 'getTemplateDetails'],
    '#^api/planer/templates/save$#' => [PlanController::class, 'saveTemplateDetails'],

    // --- NEU: API Routes for Absences ---
    '#^api/planer/absences$#' => [AbsenceController::class, 'getAbsencesApi'],
    '#^api/planer/absences/save$#' => [AbsenceController::class, 'saveAbsenceApi'],
    '#^api/planer/absences/delete$#' => [AbsenceController::class, 'deleteAbsenceApi'],

    // --- API Routes for Announcements (General API) ---
    '#^api/announcements$#' => [AnnouncementController::class, 'getAnnouncements'],
    '#^api/announcements/create$#' => [AnnouncementController::class, 'createAnnouncement'],
    '#^api/announcements/delete$#' => [AnnouncementController::class, 'deleteAnnouncement'],

    // --- API Route for User Dashboards ---
    '#^api/dashboard/weekly-data$#' => [DashboardController::class, 'getWeeklyData'],
    // NEU: API Route für Notizen
    '#^api/student/note/save$#' => [DashboardController::class, 'saveNoteApi'],

    // --- API Routes for Teacher Cockpit ---
    '#^api/teacher/search-colleagues$#' => [TeacherController::class, 'searchColleaguesApi'], 
    '#^api/teacher/find-colleague$#' => [TeacherController::class, 'findColleagueApi'],
    '#^api/teacher/current-lesson$#' => [TeacherController::class, 'getCurrentLessonWithStudentsApi'],
    '#^api/teacher/attendance/save$#' => [TeacherController::class, 'saveAttendanceApi'],
    '#^api/teacher/prerequisites$#' => [TeacherController::class, 'getPrerequisitesApi'],
    '#^api/teacher/events$#' => [AcademicEventController::class, 'getForTeacher'],

    // --- API Routes für Sprechstunden ---
    '#^api/teacher/office-hours$#' => [TeacherController::class, 'getOfficeHoursApi'], 
    '#^api/teacher/office-hours/save$#' => [TeacherController::class, 'saveOfficeHoursApi'], 
    '#^api/teacher/office-hours/delete$#' => [TeacherController::class, 'deleteOfficeHoursApi'], 
    '#^api/student/available-slots$#' => [DashboardController::class, 'getAvailableSlotsApi'], 
    '#^api/student/book-appointment$#' => [DashboardController::class, 'bookAppointmentApi'], 
    '#^api/appointment/cancel$#' => [DashboardController::class, 'cancelAppointmentApi'],
    
    // --- API Routes für Schwarzes Brett (Community) ---
    '#^api/community/posts$#' => [CommunityController::class, 'getPostsApi'], // GET (Alle freigegebenen)
    '#^api/community/posts/create$#' => [CommunityController::class, 'createPostApi'], // POST (Schüler/Lehrer erstellen)
    '#^api/community/my-posts$#' => [CommunityController::class, 'getMyPostsApi'], // GET (NEU: Schüler holt seine Posts)
    '#^api/community/post/update$#' => [CommunityController::class, 'updatePostApi'], // POST (NEU: Schüler/Admin bearbeitet Post)
    '#^api/community/post/delete$#' => [CommunityController::class, 'deletePostApi'], // POST (NEU: Schüler/Admin löscht eigenen/beliebigen Post)

];

