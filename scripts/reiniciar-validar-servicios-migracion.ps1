#Requires -RunAsAdministrator

[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$apacheService = 'XmlConciliaApache84'
$mariaService = 'XmlConciliaMariaDB114'
$xamppServices = @('Apache2.4', 'mysql')
$smokeTest = Join-Path $PSScriptRoot 'verificar-stack-migracion.ps1'

foreach ($serviceName in @($mariaService, $apacheService) + $xamppServices) {
    if ($null -eq (Get-Service -Name $serviceName -ErrorAction SilentlyContinue)) {
        throw "No existe el servicio requerido: $serviceName"
    }
}
if (-not (Test-Path -LiteralPath $smokeTest -PathType Leaf)) {
    throw "No existe el verificador web: $smokeTest"
}

$xamppBefore = @{}
foreach ($serviceName in $xamppServices) {
    $xamppBefore[$serviceName] = (Get-Service -Name $serviceName).Status
}

Write-Host '[1/4] Deteniendo solo el Apache nuevo...' -ForegroundColor Cyan
Stop-Service -Name $apacheService -Force
(Get-Service -Name $apacheService).WaitForStatus('Stopped', [TimeSpan]::FromSeconds(30))

Write-Host '[2/4] Reiniciando solo MariaDB 11.4...' -ForegroundColor Cyan
Restart-Service -Name $mariaService -Force
(Get-Service -Name $mariaService).WaitForStatus('Running', [TimeSpan]::FromSeconds(30))

for ($attempt = 0; $attempt -lt 60; $attempt++) {
    if (@(Get-NetTCPConnection -State Listen -LocalPort 3307 -ErrorAction SilentlyContinue).Count -gt 0) {
        break
    }
    Start-Sleep -Milliseconds 500
}
if (@(Get-NetTCPConnection -State Listen -LocalPort 3307 -ErrorAction SilentlyContinue).Count -eq 0) {
    throw 'MariaDB 11.4 inicio, pero no abrio el puerto local 3307.'
}

Write-Host '[3/4] Iniciando Apache 2.4.68 con PHP 8.4...' -ForegroundColor Cyan
Start-Service -Name $apacheService
(Get-Service -Name $apacheService).WaitForStatus('Running', [TimeSpan]::FromSeconds(30))

for ($attempt = 0; $attempt -lt 60; $attempt++) {
    if (@(Get-NetTCPConnection -State Listen -LocalPort 8484 -ErrorAction SilentlyContinue).Count -gt 0) {
        break
    }
    Start-Sleep -Milliseconds 500
}
if (@(Get-NetTCPConnection -State Listen -LocalPort 8484 -ErrorAction SilentlyContinue).Count -eq 0) {
    throw 'Apache nuevo inicio, pero no abrio el puerto local 8484.'
}

Write-Host '[4/4] Ejecutando login y navegacion automatizada...' -ForegroundColor Cyan
& $smokeTest -UseRunningInstance
if ($LASTEXITCODE -ne 0) {
    throw 'La validacion web posterior al reinicio fallo.'
}

foreach ($serviceName in $xamppServices) {
    $currentStatus = (Get-Service -Name $serviceName).Status
    if ($currentStatus -ne $xamppBefore[$serviceName]) {
        throw "El servicio XAMPP $serviceName cambio de estado inesperadamente: $($xamppBefore[$serviceName]) -> $currentStatus"
    }
}

$listeners = @(Get-NetTCPConnection -State Listen -ErrorAction SilentlyContinue |
    Where-Object { $_.LocalPort -in 80, 443, 3306, 3307, 8484 } |
    Sort-Object LocalPort |
    Select-Object LocalAddress, LocalPort, OwningProcess)

$allServices = @($apacheService, $mariaService) + $xamppServices
Get-Service -Name $allServices |
    Sort-Object Name |
    Format-Table Name, Status, StartType -AutoSize
$listeners | Format-Table -AutoSize

Write-Host '[VALIDADO] El stack nuevo sobrevivio un reinicio controlado.' -ForegroundColor Green
Write-Host '[INTACTO] Los servicios de XAMPP conservaron su estado original.' -ForegroundColor Green
