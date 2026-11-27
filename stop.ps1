<#
.SYNOPSIS
    Arrête le serveur de développement lancé par start.ps1.

.DESCRIPTION
    Arrête le processus dont le PID est enregistré dans storage/server.pid.
    Si ce fichier est absent, cherche un serveur PHP qui écoute sur le port indiqué.

.EXAMPLE
    .\stop.ps1
    .\stop.ps1 -Port 8080
#>
[CmdletBinding()]
param(
    [int]$Port = 8000
)

$ErrorActionPreference = 'Stop'
$pidFile = Join-Path $PSScriptRoot 'storage\server.pid'

function Write-Step([string]$text, [string]$color = 'Gray') { Write-Host $text -ForegroundColor $color }

$targetPids = @()

if (Test-Path $pidFile) {
    # Une ligne par serveur (IPv4 et, le cas échéant, IPv6 local).
    $targetPids = @(Get-Content $pidFile | Where-Object { $_ -match '^\d+$' } | ForEach-Object { [int]$_ })
} else {
    # Repli : serveur PHP lancé autrement (ou fichier PID supprimé).
    foreach ($listener in @(Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue)) {
        $candidate = Get-Process -Id $listener.OwningProcess -ErrorAction SilentlyContinue
        if ($candidate -and $candidate.ProcessName -eq 'php' -and $targetPids -notcontains $candidate.Id) { $targetPids += $candidate.Id }
    }
}

if ($targetPids.Count -eq 0) {
    Write-Step "Aucun serveur en cours d'exécution (port $Port)." 'Yellow'
    exit 0
}

foreach ($targetPid in $targetPids) {
    $process = Get-Process -Id $targetPid -ErrorAction SilentlyContinue
    if ($process -and $process.ProcessName -eq 'php') {
        Stop-Process -Id $targetPid -Force
        $process.WaitForExit(5000) | Out-Null
        Write-Step "Serveur arrêté (PID $targetPid)." 'Green'
    } else {
        # On ne tue jamais un processus qui n'est pas PHP (PID réattribué par Windows).
        Write-Step "Le processus $targetPid n'existe plus : le serveur était déjà arrêté." 'Yellow'
    }
}

Remove-Item $pidFile -Force -ErrorAction SilentlyContinue
