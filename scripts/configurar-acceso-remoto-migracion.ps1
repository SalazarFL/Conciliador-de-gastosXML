#Requires -RunAsAdministrator

[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$tailscale = 'C:\Program Files\Tailscale\tailscale.exe'
$apacheService = 'XmlConciliaApache84'
$smokeTest = Join-Path $PSScriptRoot 'verificar-stack-migracion.ps1'
$backend = 'http://127.0.0.1:8484'
$httpsPort = 8443

foreach ($file in @($tailscale, $smokeTest)) {
    if (-not (Test-Path -LiteralPath $file -PathType Leaf)) {
        throw "Falta un archivo requerido: $file"
    }
}
if ((Get-Service -Name 'Tailscale').Status -ne 'Running') {
    throw 'El servicio Tailscale no esta en ejecucion.'
}
if ((Get-Service -Name $apacheService).Status -ne 'Running') {
    throw "El servicio $apacheService no esta en ejecucion."
}
if (@(Get-NetTCPConnection -State Listen -LocalPort 8484 -ErrorAction SilentlyContinue).Count -eq 0) {
    throw 'Apache nuevo no esta escuchando en el puerto local 8484.'
}

$tailStatus = (& $tailscale status --json | ConvertFrom-Json)
if (-not $tailStatus.Self.Online) {
    throw 'Este equipo no aparece conectado a Tailscale.'
}
$dnsName = ([string] $tailStatus.Self.DNSName).TrimEnd('.')
if ([string]::IsNullOrWhiteSpace($dnsName)) {
    throw 'Tailscale no entrego un nombre MagicDNS para este equipo.'
}
$baseUrl = "https://${dnsName}:$httpsPort"

$currentStatus = ((& $tailscale serve status 2>&1) -join "`n")
if ($LASTEXITCODE -ne 0) {
    throw "No se pudo consultar Tailscale Serve:`n$currentStatus"
}

if ($currentStatus -notmatch '(?i)No serve config') {
    if ($currentStatus -notmatch [regex]::Escape($backend) -or
        $currentStatus -notmatch ":$httpsPort") {
        throw "Ya existe otra configuracion de Tailscale Serve. No se modifico:`n$currentStatus"
    }
    Write-Host '[OK] La publicacion HTTPS de prueba ya estaba configurada.' -ForegroundColor Green
} else {
    Write-Host "[1/2] Publicando $backend solo dentro de Tailscale..." -ForegroundColor Cyan
    $serveOutput = @()
    & $tailscale serve --bg --yes --https=$httpsPort $backend 2>&1 |
        Tee-Object -Variable serveOutput
    $serveExit = $LASTEXITCODE
    if ($serveExit -ne 0) {
        throw "No se pudo configurar Tailscale Serve:`n$($serveOutput -join "`n")"
    }
}

$finalStatus = ((& $tailscale serve status 2>&1) -join "`n")
if ($LASTEXITCODE -ne 0 -or
    $finalStatus -notmatch [regex]::Escape($backend) -or
    $finalStatus -notmatch ":$httpsPort") {
    throw "Tailscale no confirmo la publicacion esperada:`n$finalStatus"
}

Write-Host '[2/2] Validando HTTPS, login y rutas principales...' -ForegroundColor Cyan
& $smokeTest -UseRunningInstance -BaseUrl $baseUrl
if ($LASTEXITCODE -ne 0) {
    throw 'La aplicacion no supero la validacion a traves de Tailscale HTTPS.'
}

Write-Host "[VALIDADO] Acceso remoto privado: $baseUrl" -ForegroundColor Green
Write-Host 'Solo los dispositivos autorizados de este Tailscale pueden acceder.' -ForegroundColor Green
