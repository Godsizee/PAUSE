# --- Konfiguration ---
# Der Quellordner ist das Verzeichnis, in dem dieses Skript liegt.
$SourceDirectory = Split-Path -Parent $MyInvocation.MyCommand.Definition
$OutputDirectory = Join-Path $SourceDirectory "code_parts" # Erstellt einen Ordner 'code_parts' im Quellverzeichnis
# ANGEPASST: Füge 'tfpdf' zum Ausschlussmuster hinzu. Verwende ein reguläres Ausdrucksmuster, das beide, 'lib' und 'tfpdf', abdeckt.
$ExcludeDirectoryPattern = "lib|tfpdf" # Verzeichnisnamen, die ausgeschlossen werden sollen (z.B. "lib" oder "tfpdf"). Groß-/Kleinschreibung wird ignoriert.
$FileTypesToProcess = "*.php", "*.html", "*.css", "*.js" # Dateitypen, die verarbeitet werden sollen
$BytesPerPart = 400000 # Anzahl der Bytes (Zeichen) pro Ausgabedatei

# --- Globale Fehlerbehandlung (Trap) ---
# Fängt alle nicht abgefangenen Fehler ab und zeigt sie an, bevor das Skript beendet wird
Trap {
    Write-Error "Ein UNERWARTETER Fehler ist aufgetreten: $($_.Exception.Message)" -ErrorAction Continue
    Write-Host "Das Skript wird aufgrund eines Fehlers beendet. Bitte drücke eine Taste, um das Fenster zu schließen." -ForegroundColor Red
    Read-Host
    Exit 1 # Beendet das Skript mit einem Fehlercode
}

# --- Vorbereitung ---
Write-Host "Starte Code-Sammlung und Aufteilung nach Zeichenanzahl ($BytesPerPart Bytes pro Teil)..." -ForegroundColor Green

# Erstelle das Ausgabeverzeichnis, falls es nicht existiert
If (-not (Test-Path $OutputDirectory)) {
    Write-Host "Erstelle Ausgabeordner: '$OutputDirectory'" -ForegroundColor DarkGray
    New-Item -ItemType Directory -Path $OutputDirectory | Out-Null
} else {
    # Lösche alte Dateien im Ausgabeverzeichnis für einen sauberen Start
    Write-Host "Lösche alte Teildateien im '$OutputDirectory'..." -ForegroundColor Yellow
    Get-ChildItem -Path $OutputDirectory -Filter "combined_code_part*.txt" | Remove-Item -Force -ErrorAction SilentlyContinue
}

# Korrekter Aufruf von [System.IO.Path]::GetTempPath()
$tempCombinedFile = Join-Path ([System.IO.Path]::GetTempPath()) "temp_combined_code_$(Get-Random).txt"
Write-Host "Temporäre Sammeldatei: $($tempCombinedFile)" -ForegroundColor DarkGray

# --- Phase 1: Code sammeln und in temporäre Datei schreiben ---
Write-Host "Sammle Code in temporäre Datei '$tempCombinedFile'..." -ForegroundColor Cyan

$collectedContentLines = @()

Get-ChildItem -Path $SourceDirectory -Recurse -Include $FileTypesToProcess -File | ForEach-Object {
    $file = $_
    $filePath = $file.FullName

    # ANGEPASST: Der Match-Operator prüft nun auf '\lib\' ODER '\tfpdf\'
    If ($filePath -match "(?i)\\($($ExcludeDirectoryPattern))\\") {
        # Klarstellung, welches Muster ignoriert wurde
        $ignoredFolder = ($filePath | Select-String -Pattern "(?i)\\($($ExcludeDirectoryPattern))\\" | Select-Object -ExpandProperty Matches | Select-Object -First 1).Groups[1].Value
        Write-Host "Überspringe (Ordner '$ignoredFolder'): $($filePath)" -ForegroundColor DarkYellow
        Return
    }

    Write-Host "Füge hinzu: $($filePath)" -ForegroundColor Gray

    $collectedContentLines += "--- START FILE: $($filePath) ---"
    Try {
        # -Raw liest den gesamten Dateiinhalt als einen String
        # Encoding UTF8 ist wichtig für korrekte Byte-Zählung später
        $collectedContentLines += (Get-Content -Path $filePath -Raw -Encoding UTF8)
    } Catch {
        Write-Warning "Fehler beim Lesen der Datei '$filePath': $($_.Exception.Message). Datei wird übersprungen."
    }
    $collectedContentLines += "--- END FILE: $($filePath) ---"
}

# Schreibe den gesamten gesammelten Inhalt in die temporäre Datei.
# Sicherstellen, dass Zeilenumbrüche korrekt sind (Out-String)
If ($collectedContentLines.Count -gt 0) {
    Write-Host "Schreibe gesammelten Inhalt in '$tempCombinedFile'..." -ForegroundColor DarkGray
    $collectedContentLines | Out-String | Set-Content -Path $tempCombinedFile -Encoding UTF8 -Force
}

# Prüfen, ob die temporäre Datei Inhalt hat
If (-not (Test-Path $tempCombinedFile) -or (Get-Item $tempCombinedFile).Length -eq 0) {
    Write-Warning "Die temporäre Sammeldatei wurde nicht erstellt oder ist leer. Es gibt keinen Code zum Aufteilen."
    # Temporäre Datei löschen, auch wenn leer
    If (Test-Path $tempCombinedFile) { Remove-Item -Path $tempCombinedFile -Force -ErrorAction SilentlyContinue }
    Read-Host "Drücke eine Taste zum Beenden..." # Pause hier, falls nichts gesammelt wurde
    Exit
}

# --- Phase 2: Temporäre Datei in Teile aufteilen nach Bytes ---
Write-Host "`nTeile die gesammelte Datei in Teile à $BytesPerPart Bytes auf..." -ForegroundColor Cyan

$fileStream = $null # Initialisiere als $null für Finally-Block
Try {
    $fileStream = New-Object -TypeName System.IO.FileStream -ArgumentList $tempCombinedFile, ([System.IO.FileMode]::Open), ([System.IO.FileAccess]::Read)
    $buffer = New-Object -TypeName byte[] -ArgumentList $BytesPerPart
    $partNumber = 1

    While ($true) {
        $bytesRead = $fileStream.Read($buffer, 0, $BytesPerPart)

        If ($bytesRead -eq 0) {
            Break # Ende der Datei erreicht
        }

        $partFileName = Join-Path $OutputDirectory "combined_code_part_$($partNumber).txt"
        Write-Host "Erstelle Teil $partNumber ($bytesRead Bytes) -> $($partFileName)" -ForegroundColor DarkCyan

        # KORREKTUR HIER: Zusätzliche Klammern um den Select-Object Ausdruck
        [System.IO.File]::WriteAllBytes($partFileName, ($buffer | Select-Object -First $bytesRead))

        $partNumber++
    }
}
Catch {
    Write-Error "Ein Fehler ist beim Aufteilen der Datei aufgetreten: $($_.Exception.Message)" -ErrorAction Continue
}
Finally {
    # Sicherstellen, dass der FileStream geschlossen wird
    If ($fileStream) {
        Write-Host "Schließe FileStream." -ForegroundColor DarkGray
        $fileStream.Dispose()
    }
}

# --- Aufräumen ---
Write-Host "`nLösche temporäre Datei '$tempCombinedFile'..." -ForegroundColor DarkGray
Remove-Item -Path $tempCombinedFile -Force -ErrorAction SilentlyContinue

Write-Host "`nFertig! Alle Teildateien wurden im Ordner '$OutputDirectory' erstellt." -ForegroundColor Green
Write-Host "Die ursprünglichen Dateien wurden NICHT verändert." -ForegroundColor Green
Read-Host "Drücke eine beliebige Taste zum Beenden..." # Hält das Fenster offen