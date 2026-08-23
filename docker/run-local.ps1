# ============================================================================
# RadioRing – lokaler Liquidsoap-Test (One-Click)
#
# Macht alles, damit lokal Ton aus einem Liquidsoap-Container kommt:
#   1. Konfiguriert Station (Output, Rundown, docker/.env) via Artisan
#   2. Startet die Laravel-App (php artisan serve) falls noch nicht laufend
#   3. Baut + startet Icecast und den Station-Container
#
# Aufruf (aus dem Projekt-Root):  ./docker/run-local.ps1
# Hören danach:                   http://localhost:8000/<slug>  (z.B. in VLC)
# ============================================================================

$ErrorActionPreference = 'Stop'

# Projekt-Root = übergeordnetes Verzeichnis dieses Scripts
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

Write-Host '== 1/3  Station konfigurieren ==' -ForegroundColor Cyan
php artisan radioring:prepare-local-stream
if (-not $?) { Write-Host 'Konfiguration fehlgeschlagen.' -ForegroundColor Red; exit 1 }

Write-Host ''
Write-Host '== 2/3  Laravel-App sicherstellen (Port 8000) ==' -ForegroundColor Cyan
$portInUse = Get-NetTCPConnection -LocalPort 8000 -State Listen -ErrorAction SilentlyContinue
if ($portInUse) {
    Write-Host 'App läuft bereits auf Port 8000.' -ForegroundColor Green
} else {
    Write-Host 'Starte php artisan serve in neuem Fenster...' -ForegroundColor Yellow
    Start-Process powershell -ArgumentList @(
        '-NoExit', '-Command',
        "Set-Location '$root'; php artisan serve --host=0.0.0.0 --port=8000"
    )
    Start-Sleep -Seconds 3
}

Write-Host ''
$slug = (Get-Content docker/.env | Select-String 'STATION_SLUG=(.*)').Matches.Groups[1].Value
Write-Host "== 3/3  Docker (Icecast + Station-Container) ==" -ForegroundColor Cyan
Write-Host "Hören sobald oben 'Connection setup was successful' steht:" -ForegroundColor Green
Write-Host "  http://localhost:8010/$slug   (z.B. in VLC)" -ForegroundColor Green
Write-Host ''
docker compose --env-file docker/.env -f docker/docker-compose.local.yml up --build
