# --- Konfiguration ---
# Setzt den Quellordner auf das Verzeichnis, in dem dieses Skript liegt
$SourceDirectory = Split-Path -Parent $MyInvocation.MyCommand.Definition
$ExcludeDirectoryPattern = "tfpdf"
$FileTypesToProcess = "*.php", "*.html", "*.css", "*.js"

# --- Vorbereitung ---
Write-Host "Starte verbesserte Bereinigung von Kommentaren UND Whitespace..." -ForegroundColor Green
Write-Host "Dateien werden überschrieben."

# --- WICHTIGER HINWEIS ---
Write-Host "`n"
Write-Host "¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦" -ForegroundColor Red
Write-Host "¦¦¦ ACHTUNG: Das Überschreiben von Originaldateien ist ein irreversibler Vorgang! ¦¦¦" -ForegroundColor Red
Write-Host "¦¦¦ Es werden KEINE Sicherungskopien erstellt. Stellen Sie sicher, dass Sie ¦¦¦" -ForegroundColor Red
Write-Host "¦¦¦ vor der Fortsetzung eine Sicherung Ihrer Dateien erstellt haben.       ¦¦¦" -ForegroundColor Red
Write-Host "¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦¦" -ForegroundColor Red
Write-Host "`n"
Read-Host "Drücken Sie die Enter-Taste, um zu bestätigen, dass Sie diesen Hinweis verstanden haben und fortfahren möchten..." | Out-Null

# --- Hauptlogik ---
Get-ChildItem -Path $SourceDirectory -Recurse -Include $FileTypesToProcess -File | ForEach-Object {
    $file = $_
    $filePath = $file.FullName

    # Überspringe ausgeschlossene Verzeichnisse
    If ($filePath -like "*\$ExcludeDirectoryPattern\*") {
        Write-Host "Überspringe (ausgeschlossener Pfad): $($filePath)" -ForegroundColor DarkYellow
        Return
    }

    Write-Host "Verarbeite und überschreibe: $($filePath)"

    # Schritt 1: Gesamten Dateiinhalt einlesen, um Block-Kommentare (/* ... */) zu entfernen
    $content = Get-Content -Path $filePath -Raw -Encoding UTF8
    $cleanedContent = $content -replace '(?s)/\*.*?\*/', ''

    # Schritt 2: Zeilenweise aufteilen und bereinigen (Kommentare und leere Zeilen)
    # Verwendung von .Split() für eine robustere Zeilenaufteilung
    $lines = $cleanedContent.Split([System.Environment]::NewLine, [System.StringSplitOptions]::None)
    
    $finalLines = foreach ($line in $lines) {
        # Prüfung 1: Ist die Zeile ein reiner // Kommentar? (Verbesserte Regex)
        $isOnlyComment = $line -match "^\s*//.*"
        
        # Prüfung 2: Ist die Zeile leer oder besteht nur aus Whitespace?
        $isOnlyWhitespace = $line -match "^\s*$"

        # Nur Zeilen behalten, die WEDER ein Kommentar NOCH reiner Whitespace sind
        if (-not $isOnlyComment -and -not $isOnlyWhitespace) {
            $line
        }
    }

    # Die komplett bereinigten Zeilen wieder zusammenfügen und in die Datei schreiben
    # Wichtig: .Split() entfernt die Zeilenumbrüche, .Join() fügt sie wieder hinzu.
    $finalContent = $finalLines -join [System.Environment]::NewLine
    
    # Set-Content fügt oft am Ende eine Leerzeile hinzu, was wir hier vermeiden,
    # indem wir direkt .NET-Methoden nutzen (robuster).
    [System.IO.File]::WriteAllText($filePath, $finalContent, [System.Text.UTF8Encoding]::new($false))
}

Write-Host "`nFertig! Alle bereinigten Dateien wurden direkt überschrieben." -ForegroundColor Green
Write-Host "Es wurden KEINE Sicherungskopien erstellt." -ForegroundColor Red