# PowerShell Skript zum Erstellen der "PAUSE" Projektstruktur
#
# ANLEITUNG:
# 1. Öffnen Sie eine PowerShell-Konsole (als Administrator, um Berechtigungsprobleme zu vermeiden).
# 2. Navigieren Sie zu dem Ordner, in dem Sie dieses Skript gespeichert haben.
# 3. Führen Sie das Skript mit dem Befehl aus: .\create_project_structure.ps1

# --- EINSTELLUNGEN ---
$basePath = "C:\xampp\htdocs\files\PAUSE"

# --- Skript-Start ---
Write-Host "Erstelle Projektstruktur in: $basePath" -ForegroundColor Green

# Überprüfen, ob der Basisordner bereits existiert
if (Test-Path $basePath) {
    Write-Host "Warnung: Der Basisordner '$basePath' existiert bereits. Bestehende Dateien werden nicht überschrieben." -ForegroundColor Yellow
} else {
    New-Item -ItemType Directory -Path $basePath | Out-Null
}

# --- Ordnerstruktur erstellen ---
$folders = @(
    "app/Core",
    "app/Http/Controllers/Admin",
    "app/Http/Controllers/Auth",
    "app/Http/Controllers/Planer",
    "app/Repositories",
    "app/Services",
    "config",
    "pages/admin/partials",
    "pages/auth",
    "pages/errors",
    "pages/planer",
    "pages/partials",
    "public/assets/css",
    "public/assets/js",
    "public/assets/images"
)

foreach ($folder in $folders) {
    $fullPath = Join-Path -Path $basePath -ChildPath $folder
    if (!(Test-Path $fullPath)) {
        New-Item -ItemType Directory -Path $fullPath | Out-Null
        Write-Host "  -> Ordner erstellt: $fullPath"
    }
}

# --- Leere Dateien erstellen ---
$files = @(
    "app/Core/Cache.php",
    "app/Core/Database.php",
    "app/Core/Router.php",
    "app/Core/Security.php",
    "app/Core/Utils.php",
    "app/Http/Controllers/Admin/StammdatenController.php",
    "app/Http/Controllers/Admin/UserController.php",
    "app/Http/Controllers/Auth/AuthController.php",
    "app/Http/Controllers/Planer/PlanController.php",
    "app/Http/Controllers/DashboardController.php",
    "app/Repositories/AuditLogRepository.php",
    "app/Repositories/LoginAttemptRepository.php",
    "app/Repositories/UserRepository.php",
    "app/Repositories/StammdatenRepository.php",
    "app/Repositories/PlanRepository.php",
    "app/Services/AuthenticationService.php",
    "config/database_access.php",
    "config/routes.php",
    "config/settings.json",
    "pages/admin/partials/_sidebar.php",
    "pages/admin/dashboard.php",
    "pages/admin/users.php",
    "pages/admin/stammdaten.php",
    "pages/auth/login.php",
    "pages/errors/404.php",
    "pages/planer/dashboard.php",
    "pages/partials/header.php",
    "pages/partials/footer.php",
    "pages/partials/_timetable_grid.php",
    "pages/dashboard.php",
    "public/index.php",
    ".htaccess",
    "composer.json",
    "init.php",
    "README.md"
)

foreach ($file in $files) {
    $fullPath = Join-Path -Path $basePath -ChildPath $file
    if (!(Test-Path $fullPath)) {
        New-Item -ItemType File -Path $fullPath | Out-Null
        Write-Host "  -> Datei erstellt: $fullPath"
    }
}

Write-Host "Projektstruktur wurde erfolgreich erstellt." -ForegroundColor Green
