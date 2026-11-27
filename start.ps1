<#
.SYNOPSIS
    Démarre le serveur de développement de Hair_style by Garance en arrière-plan.

.DESCRIPTION
    Lance le serveur intégré de PHP (php -S) sur public/, enregistre son PID dans
    storage/server.pid et attend que l'application réponde sur /sante.
    Avec l'adresse par défaut (127.0.0.1), un second serveur écoute sur [::1] (IPv6 local),
    car les navigateurs résolvent souvent « localhost » en ::1 : http://localhost:PORT fonctionne ainsi aussi.
    Le journal du serveur est écrit dans storage/logs/server.log.

.EXAMPLE
    .\start.ps1
    .\start.ps1 -Port 8080 -Open
#>
[CmdletBinding()]
param(
    [int]$Port = 8000,
    [string]$Address = '127.0.0.1',
    # Ouvre le site dans le navigateur une fois démarré.
    [switch]$Open
)

$ErrorActionPreference = 'Stop'
$root = $PSScriptRoot
$pidFile = Join-Path $root 'storage\server.pid'
$logFile = Join-Path $root 'storage\logs\server.log'
$errFile = Join-Path $root 'storage\logs\server-error.log'
$url = "http://${Address}:${Port}"

function Write-Step([string]$text, [string]$color = 'Gray') { Write-Host $text -ForegroundColor $color }

# --- 1. Serveur déjà lancé ? ---
if (Test-Path $pidFile) {
    $existingPid = [int](Get-Content $pidFile | Select-Object -First 1)
    $existing = Get-Process -Id $existingPid -ErrorAction SilentlyContinue
    if ($existing -and $existing.ProcessName -eq 'php') {
        Write-Step "Le serveur tourne déjà (PID $existingPid). Arrêtez-le avec .\stop.ps1" 'Yellow'
        exit 0
    }
    Remove-Item $pidFile -Force   # PID obsolète (serveur arrêté sans stop.ps1)
}

# --- 2. PHP ---
$php = (Get-Command php -ErrorAction SilentlyContinue).Source
if (-not $php -and (Test-Path 'C:\php\php.exe')) { $php = 'C:\php\php.exe' }
if (-not $php) {
    Write-Step 'PHP introuvable : ajoutez son dossier au PATH (ex. C:\php).' 'Red'
    exit 1
}

if (-not (Test-Path (Join-Path $root 'vendor\autoload.php'))) {
    Write-Step 'Dépendances absentes : lancez « composer install » avant de démarrer.' 'Red'
    exit 1
}

# --- 3. PostgreSQL ---
$pgService = Get-Service -Name 'postgresql*' -ErrorAction SilentlyContinue | Select-Object -First 1
if ($pgService -and $pgService.Status -ne 'Running') {
    Write-Step "Le service $($pgService.Name) est arrêté, tentative de démarrage…" 'Yellow'
    try {
        Start-Service $pgService.Name
    } catch {
        Write-Step "Impossible de démarrer PostgreSQL sans droits administrateur. Démarrez le service « $($pgService.Name) » puis relancez." 'Red'
        exit 1
    }
}

# --- 4. Port libre ? ---
$busy = Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue
if ($busy) {
    $owner = Get-Process -Id ($busy | Select-Object -First 1).OwningProcess -ErrorAction SilentlyContinue
    Write-Step "Le port $Port est déjà utilisé par « $($owner.ProcessName) » (PID $($owner.Id)). Choisissez un autre port : .\start.ps1 -Port 8080" 'Red'
    exit 1
}

# --- 5. Démarrage ---
New-Item -ItemType Directory -Force -Path (Split-Path $logFile) | Out-Null

$process = Start-Process -FilePath $php `
    -ArgumentList @('-S', "${Address}:${Port}", '-t', 'public', 'public/router.php') `
    -WorkingDirectory $root `
    -WindowStyle Hidden `
    -RedirectStandardOutput $logFile `
    -RedirectStandardError $errFile `
    -PassThru

Set-Content -Path $pidFile -Value $process.Id -Encoding ascii

# --- 6. Attente de la réponse de l'application ---
$ready = $false
for ($i = 0; $i -lt 20; $i++) {
    Start-Sleep -Milliseconds 500
    if ($process.HasExited) { break }
    try {
        $health = Invoke-WebRequest -UseBasicParsing -Uri "$url/sante" -TimeoutSec 2
        $ready = $true
        break
    } catch {
        # 503 = application démarrée mais base indisponible
        if ($_.Exception.Response -and [int]$_.Exception.Response.StatusCode -eq 503) {
            $ready = $true
            Write-Step 'Attention : le site répond mais la base de données est indisponible (voir php bin/console app:check).' 'Yellow'
            break
        }
    }
}

if (-not $ready) {
    Write-Step 'Le serveur n''a pas démarré correctement. Dernières lignes du journal :' 'Red'
    if (Test-Path $errFile) { Get-Content $errFile -Tail 10 }
    if (-not $process.HasExited) { Stop-Process -Id $process.Id -Force }
    Remove-Item $pidFile -Force -ErrorAction SilentlyContinue
    exit 1
}

# --- 7. « localhost » en IPv6 (::1), boucle locale uniquement ---
$pids = @($process.Id)
if ($Address -eq '127.0.0.1') {
    $v6 = Start-Process -FilePath $php `
        -ArgumentList @('-S', "[::1]:${Port}", '-t', 'public', 'public/router.php') `
        -WorkingDirectory $root `
        -WindowStyle Hidden `
        -RedirectStandardOutput (Join-Path $root 'storage\logs\server-v6.log') `
        -RedirectStandardError (Join-Path $root 'storage\logs\server-v6-error.log') `
        -PassThru
    Start-Sleep -Milliseconds 500
    if (-not $v6.HasExited) { $pids += $v6.Id }
}
Set-Content -Path $pidFile -Value $pids -Encoding ascii

Write-Step "Serveur démarré (PID $($pids -join ', '))" 'Green'
Write-Step "  Site      : $url"
if ($pids.Count -gt 1) { Write-Step "              http://localhost:${Port}" }
Write-Step "  Journal   : storage\logs\server-error.log"
Write-Step "  Arrêter   : .\stop.ps1"

if ($Open) { Start-Process $url }
