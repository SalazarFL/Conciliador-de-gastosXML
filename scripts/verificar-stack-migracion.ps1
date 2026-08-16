[CmdletBinding()]
param(
    [string]$ApacheExecutable = 'C:\WebServer\Apache24\bin\httpd.exe',
    [string]$ApacheConfig = 'C:\WebServer\Apache24\conf\httpd-xmlconcilia.conf',
    [string]$PhpExecutable = 'C:\WebServer\PHP84\php.exe',
    [string]$BaseUrl = 'http://127.0.0.1:8484',
    [switch]$UseRunningInstance
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$userHelper = Join-Path $PSScriptRoot 'usuario-smoke-migracion.php'
$apacheErrorLog = 'C:\WebServer\logs\apache-error.log'
$phpErrorLog = 'C:\WebServer\logs\php84-error.log'

foreach ($requiredFile in @($ApacheExecutable, $ApacheConfig, $PhpExecutable, $userHelper)) {
    if (-not (Test-Path -LiteralPath $requiredFile -PathType Leaf)) {
        throw "No existe el archivo requerido: $requiredFile"
    }
}

$previousErrorActionPreference = $ErrorActionPreference
$ErrorActionPreference = 'Continue'
try {
    $syntax = & $ApacheExecutable -t -f $ApacheConfig 2>&1
    $syntaxExit = $LASTEXITCODE
} finally {
    $ErrorActionPreference = $previousErrorActionPreference
}
if ($syntaxExit -ne 0) {
    throw "La configuracion Apache no es valida:`n$($syntax -join "`n")"
}

$version = & $PhpExecutable -v
$versionExit = $LASTEXITCODE
$phpInfo = & $PhpExecutable -i
$phpInfoExit = $LASTEXITCODE
if ($versionExit -ne 0 -or $phpInfoExit -ne 0 -or
    ($version -join "`n") -notmatch 'PHP 8\.4\.24' -or
    ($phpInfo -join "`n") -notmatch 'Thread Safety\s*=>\s*disabled') {
    throw "Se esperaba PHP 8.4.24 NTS y se recibio: $($version -join ' ')"
}

$existing = @(
    Get-CimInstance Win32_Process -Filter "Name='httpd.exe'" -ErrorAction SilentlyContinue |
        Where-Object {
            $null -ne $_.CommandLine -and
            $_.CommandLine.Replace('/', '\') -like ('*' + $ApacheConfig.Replace('/', '\') + '*')
        }
)
if (-not $UseRunningInstance -and $existing.Count -gt 0) {
    throw 'La instancia Apache de migracion ya esta en ejecucion.'
}
$portOwner = @(Get-NetTCPConnection -State Listen -LocalPort 8484 -ErrorAction SilentlyContinue)
if (-not $UseRunningInstance -and $portOwner.Count -gt 0) {
    throw 'El puerto de prueba 8484 ya esta ocupado.'
}
if ($UseRunningInstance -and $portOwner.Count -eq 0) {
    throw 'No hay una instancia de migracion escuchando en el puerto 8484.'
}

$initialApacheLog = if (Test-Path -LiteralPath $apacheErrorLog) {
    (Get-Item -LiteralPath $apacheErrorLog).Length
} else { 0 }
$initialPhpLog = if (Test-Path -LiteralPath $phpErrorLog) {
    (Get-Item -LiteralPath $phpErrorLog).Length
} else { 0 }

$credentialJson = & $PhpExecutable $userHelper create
if ($LASTEXITCODE -ne 0) {
    throw 'No se pudo crear el usuario web temporal.'
}
$credentials = (($credentialJson -join '') | ConvertFrom-Json)

$apacheProcess = $null
$startedByScript = $false
try {
    if (-not $UseRunningInstance) {
        $apacheProcess = Start-Process -FilePath $ApacheExecutable `
            -ArgumentList @('-f', $ApacheConfig) -WindowStyle Hidden -PassThru
        $startedByScript = $true
    }

    $loginPage = $null
    $startupError = ''
    for ($attempt = 0; $attempt -lt 40; $attempt++) {
        try {
            $loginPage = Invoke-WebRequest -UseBasicParsing -Uri ($BaseUrl + '/login') `
                -SessionVariable migrationSession -TimeoutSec 3
            break
        } catch {
            $startupError = $_.Exception.Message
            Start-Sleep -Milliseconds 250
        }
    }

    if ($null -eq $loginPage -or $loginPage.StatusCode -ne 200) {
        throw "El nuevo Apache no respondio: $startupError"
    }
    if ($loginPage.Content -notmatch 'name="csrf_token"\s+value="([^"]+)"') {
        throw 'La pantalla de acceso no entrego token CSRF.'
    }
    $csrf = $Matches[1]
    Write-Host '[OK] GET /login: HTTP 200 y CSRF' -ForegroundColor Green

    $formBody = 'csrf_token=' + [Uri]::EscapeDataString($csrf) +
        '&username=' + [Uri]::EscapeDataString([string] $credentials.username) +
        '&password=' + [Uri]::EscapeDataString([string] $credentials.password)
    $formBytes = [Text.Encoding]::UTF8.GetBytes($formBody)
    $loginRequest = [Net.HttpWebRequest]::Create($BaseUrl + '/login')
    $loginRequest.Method = 'POST'
    $loginRequest.AllowAutoRedirect = $false
    $loginRequest.CookieContainer = $migrationSession.Cookies
    $loginRequest.ContentType = 'application/x-www-form-urlencoded'
    $loginRequest.ContentLength = $formBytes.Length
    $loginRequest.Timeout = 15000
    $requestStream = $loginRequest.GetRequestStream()
    try {
        $requestStream.Write($formBytes, 0, $formBytes.Length)
    } finally {
        $requestStream.Dispose()
    }
    $loginResponse = $loginRequest.GetResponse()
    try {
        $loginStatus = [int] $loginResponse.StatusCode
        $loginLocation = [string] $loginResponse.Headers['Location']
    } finally {
        $loginResponse.Dispose()
    }
    if ($loginStatus -notin 301, 302, 303) {
        throw "El usuario temporal no pudo iniciar sesion; HTTP $loginStatus."
    }
    if ($loginLocation -ne '/') {
        throw "La redireccion de acceso no apunta a la raiz: $loginLocation"
    }
    Write-Host "[OK] POST /login: HTTP $loginStatus, Location=$loginLocation" -ForegroundColor Green

    foreach ($route in @('/', '/diagnostico', '/notas-credito', '/por-pagar', '/correo')) {
        $response = Invoke-WebRequest -UseBasicParsing -Uri ($BaseUrl + $route) `
            -WebSession $migrationSession -TimeoutSec 30
        if ($response.StatusCode -ne 200) {
            throw "La ruta $route respondio HTTP $($response.StatusCode)."
        }
        if ($response.Content -match '(?i)PHP (Deprecated|Warning|Fatal error)|Parse error') {
            throw "La ruta $route mostro un error de PHP."
        }
        if ($route -eq '/diagnostico' -and $response.Content -notmatch '8\.4\.24') {
            throw 'Diagnostico no confirmo PHP 8.4.24.'
        }
        if ($route -eq '/diagnostico' -and $response.Content -notmatch '11\.4\.12') {
            throw 'Diagnostico no confirmo MariaDB 11.4.12.'
        }
        Write-Host "[OK] GET ${route}: HTTP 200" -ForegroundColor Green
    }
} finally {
    if ($startedByScript) {
        $processes = @(
            Get-CimInstance Win32_Process -Filter "Name='httpd.exe'" -ErrorAction SilentlyContinue |
                Where-Object {
                    $null -ne $_.CommandLine -and
                    $_.CommandLine.Replace('/', '\') -like ('*' + $ApacheConfig.Replace('/', '\') + '*')
                }
        )
        if ($processes.Count -gt 0) {
            Stop-Process -Id @($processes | Select-Object -ExpandProperty ProcessId) -Force `
                -ErrorAction SilentlyContinue
        }
    }
    & $PhpExecutable $userHelper delete | Out-Null
}

function Get-NewLogContent([string]$Path, [long]$Offset) {
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { return '' }
    $stream = [System.IO.File]::Open($Path, 'Open', 'Read', 'ReadWrite')
    try {
        [void] $stream.Seek($Offset, [System.IO.SeekOrigin]::Begin)
        $reader = New-Object System.IO.StreamReader($stream)
        try { return $reader.ReadToEnd() } finally { $reader.Dispose() }
    } finally { $stream.Dispose() }
}

$newApacheLog = Get-NewLogContent $apacheErrorLog $initialApacheLog
$newPhpLog = Get-NewLogContent $phpErrorLog $initialPhpLog
if (($newApacheLog + "`n" + $newPhpLog) -match '(?i)PHP (Warning|Deprecated|Fatal|Parse)|\[(fcgid|core):error\]') {
    throw "El smoke test produjo errores nuevos:`n$newApacheLog`n$newPhpLog"
}

if (-not $UseRunningInstance -and
    @(Get-NetTCPConnection -State Listen -LocalPort 8484 -ErrorAction SilentlyContinue).Count -ne 0) {
    throw 'Apache de prueba no libero el puerto 8484.'
}

Write-Host '[VALIDADO] Apache 2.4.68 + PHP 8.4.24 FastCGI + MariaDB 11.4.12.' -ForegroundColor Green
