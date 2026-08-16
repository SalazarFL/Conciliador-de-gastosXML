#Requires -RunAsAdministrator

[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$mariaService = 'XmlConciliaMariaDB114'
$apacheService = 'XmlConciliaApache84'
$mariaExecutable = 'C:\WebServer\MariaDB114\bin\mariadbd.exe'
$mariaAdmin = 'C:\WebServer\MariaDB114\bin\mariadb-admin.exe'
$mariaConfig = 'C:\WebServer\MariaDB114\my-xmlconcilia.ini'
$rootClient = 'C:\WebServer\secrets\mariadb114-root-client.ini'
$apacheExecutable = 'C:\WebServer\Apache24\bin\httpd.exe'
$apacheConfig = 'C:\WebServer\Apache24\conf\httpd-xmlconcilia.conf'
$smokeTest = Join-Path $PSScriptRoot 'verificar-stack-migracion.ps1'

foreach ($file in @(
    $mariaExecutable, $mariaAdmin, $mariaConfig, $rootClient,
    $apacheExecutable, $apacheConfig, $smokeTest
)) {
    if (-not (Test-Path -LiteralPath $file -PathType Leaf)) {
        throw "Falta un archivo requerido: $file"
    }
}

$previousErrorActionPreference = $ErrorActionPreference
$ErrorActionPreference = 'Continue'
try {
    $syntax = & $apacheExecutable -t -f $apacheConfig 2>&1
    $syntaxExit = $LASTEXITCODE
} finally {
    $ErrorActionPreference = $previousErrorActionPreference
}
if ($syntaxExit -ne 0) {
    throw "La configuracion Apache no es valida:`n$($syntax -join "`n")"
}

function Assert-ServicePath([string]$Name, [string]$ExpectedExecutable) {
    $service = Get-CimInstance Win32_Service -Filter "Name='$Name'" -ErrorAction SilentlyContinue
    if ($null -ne $service -and
        $service.PathName -notlike ('*' + $ExpectedExecutable + '*')) {
        throw "Ya existe $Name, pero apunta a otro ejecutable: $($service.PathName)"
    }
    return $service
}

$existingMaria = Assert-ServicePath $mariaService $mariaExecutable
$existingApache = Assert-ServicePath $apacheService $apacheExecutable

$listener3307 = Get-NetTCPConnection -State Listen -LocalPort 3307 -ErrorAction SilentlyContinue |
    Select-Object -First 1
if ($null -ne $listener3307 -and $null -eq $existingMaria) {
    $process = Get-CimInstance Win32_Process -Filter "ProcessId=$($listener3307.OwningProcess)"
    if ($process.ExecutablePath -ne $mariaExecutable) {
        throw "El puerto 3307 pertenece a otro programa: $($process.ExecutablePath)"
    }
    & $mariaAdmin "--defaults-extra-file=$rootClient" shutdown
    if ($LASTEXITCODE -ne 0) {
        throw 'No se pudo detener limpiamente el MariaDB temporal.'
    }
    for ($attempt = 0; $attempt -lt 40; $attempt++) {
        if (@(Get-NetTCPConnection -State Listen -LocalPort 3307 -ErrorAction SilentlyContinue).Count -eq 0) {
            break
        }
        Start-Sleep -Milliseconds 250
    }
}

if ($null -eq $existingMaria) {
    $previousErrorActionPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $result = & $mariaExecutable --install $mariaService "--defaults-file=$mariaConfig" 2>&1
        $installExit = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }
    if ($installExit -ne 0) {
        throw "No se pudo registrar ${mariaService}:`n$($result -join "`n")"
    }
}

if ($null -eq $existingApache) {
    $previousErrorActionPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $result = & $apacheExecutable -k install -n $apacheService -f $apacheConfig 2>&1
        $installExit = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }
    if ($installExit -ne 0) {
        throw "No se pudo registrar ${apacheService}:`n$($result -join "`n")"
    }
}

foreach ($serviceName in @($mariaService, $apacheService)) {
    & sc.exe config $serviceName start= delayed-auto | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw "No se pudo configurar el inicio automatico de $serviceName."
    }
    & sc.exe failure $serviceName reset= 86400 actions= restart/5000/restart/10000/restart/30000 | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw "No se pudo configurar la recuperacion de $serviceName."
    }
}

& sc.exe description $mariaService 'MariaDB 11.4 LTS para Nexo Fiscal (puerto paralelo 3307)' | Out-Null
& sc.exe description $apacheService 'Apache 2.4.68 y PHP 8.4 FastCGI para Nexo Fiscal (puerto paralelo 8484)' | Out-Null
& sc.exe config $apacheService depend= $mariaService | Out-Null
if ($LASTEXITCODE -ne 0) {
    throw "No se pudo declarar la dependencia de $apacheService."
}

if ((Get-Service -Name $mariaService).Status -ne 'Running') {
    Start-Service -Name $mariaService
}
(Get-Service -Name $mariaService).WaitForStatus('Running', [TimeSpan]::FromSeconds(30))
for ($attempt = 0; $attempt -lt 40; $attempt++) {
    if (@(Get-NetTCPConnection -State Listen -LocalPort 3307 -ErrorAction SilentlyContinue).Count -gt 0) {
        break
    }
    Start-Sleep -Milliseconds 250
}
if (@(Get-NetTCPConnection -State Listen -LocalPort 3307 -ErrorAction SilentlyContinue).Count -eq 0) {
    throw 'MariaDB se registro, pero no abrio el puerto local 3307.'
}

if ((Get-Service -Name $apacheService).Status -ne 'Running') {
    Start-Service -Name $apacheService
}
(Get-Service -Name $apacheService).WaitForStatus('Running', [TimeSpan]::FromSeconds(30))
for ($attempt = 0; $attempt -lt 40; $attempt++) {
    if (@(Get-NetTCPConnection -State Listen -LocalPort 8484 -ErrorAction SilentlyContinue).Count -gt 0) {
        break
    }
    Start-Sleep -Milliseconds 250
}
if (@(Get-NetTCPConnection -State Listen -LocalPort 8484 -ErrorAction SilentlyContinue).Count -eq 0) {
    throw 'Apache se registro, pero no abrio el puerto local 8484.'
}

try {
    & $smokeTest -UseRunningInstance
} catch {
    Stop-Service -Name $apacheService -Force -ErrorAction SilentlyContinue
    throw
}

Get-CimInstance Win32_Service -Filter "Name='$mariaService' OR Name='$apacheService'" |
    Select-Object Name, State, StartMode, StartName, PathName |
    Format-List

Write-Host '[LISTO] Los servicios paralelos quedaron instalados y validados.' -ForegroundColor Green
Write-Host 'XAMPP continua atendiendo 80/443/3306; el nuevo stack usa 8484/3307.' -ForegroundColor Green
