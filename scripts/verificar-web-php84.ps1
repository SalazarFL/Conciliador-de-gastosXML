[CmdletBinding()]
param(
    [string]$ApacheExecutable = 'C:\xampp\apache\bin\httpd.exe',
    [string]$ApacheConfig = 'C:\xampp\php84\apache-cert\httpd.conf',
    [string]$PhpExecutable = 'C:\xampp\php84\php.exe',
    [string]$PhpCgiExecutable = 'C:\xampp\php84-nts\php-cgi.exe',
    [string]$FastCgiEndpoint = '127.0.0.1:9084',
    [string]$BaseUrl = 'http://127.0.0.1:8484/xmlconcilia/public'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

foreach ($requiredFile in @($ApacheExecutable, $ApacheConfig, $PhpExecutable, $PhpCgiExecutable)) {
    if (-not (Test-Path -LiteralPath $requiredFile -PathType Leaf)) {
        throw "No existe el archivo requerido: $requiredFile"
    }
}

$previousErrorActionPreference = $ErrorActionPreference
$ErrorActionPreference = 'Continue'
try {
    $apacheSyntax = & $ApacheExecutable -t -f $ApacheConfig 2>&1
    $apacheSyntaxExit = $LASTEXITCODE
    $phpCgiVersion = & $PhpCgiExecutable -v 2>&1
    $phpCgiVersionExit = $LASTEXITCODE
} finally {
    $ErrorActionPreference = $previousErrorActionPreference
}
if ($apacheSyntaxExit -ne 0) {
    throw "La configuración paralela de Apache no es válida:`n$($apacheSyntax -join "`n")"
}
if ($phpCgiVersionExit -ne 0 -or ($phpCgiVersion -join "`n") -notmatch 'PHP 8\.4\.24 \(cgi-fcgi\).*NTS') {
    throw "El proceso web debe ser PHP 8.4.24 NTS:`n$($phpCgiVersion -join "`n")"
}
Write-Host '[OK] Configuración Apache válida y PHP-CGI 8.4.24 NTS' -ForegroundColor Green

$testConfigFile = [Environment]::GetEnvironmentVariable('XMLCONCILIA_CONFIG_FILE')
if ([string]::IsNullOrWhiteSpace($testConfigFile) -or
    -not (Test-Path -LiteralPath $testConfigFile -PathType Leaf)) {
    throw 'Define XMLCONCILIA_CONFIG_FILE con la configuración aislada de certificación.'
}
$testConfigFile = (Resolve-Path -LiteralPath $testConfigFile).Path
$certLogDirectory = Join-Path (Split-Path -Parent $ApacheConfig) 'logs'
$certErrorLog = Join-Path $certLogDirectory 'error.log'
$certPidFile = Join-Path $certLogDirectory 'httpd.pid'
$configNeedle = (Resolve-Path -LiteralPath $ApacheConfig).Path
$alreadyRunning = @(
    Get-CimInstance Win32_Process -Filter "Name='httpd.exe'" -ErrorAction SilentlyContinue |
        Where-Object {
            $null -ne $_.CommandLine -and
            $_.CommandLine.Replace('/', '\') -like ('*' + $configNeedle.Replace('/', '\') + '*')
        }
)
if ($alreadyRunning.Count -gt 0) {
    throw 'Ya existe una instancia Apache con la configuración de certificación.'
}
$initialErrorLogLength = if (Test-Path -LiteralPath $certErrorLog) {
    (Get-Item -LiteralPath $certErrorLog).Length
} else {
    0
}
if (Test-Path -LiteralPath $certPidFile -PathType Leaf) {
    [System.IO.File]::Delete($certPidFile)
}

$credentialProbe = @'
$local = require $argv[1];
$cert = is_array($local) && isset($local['certification']) && is_array($local['certification']) ? $local['certification'] : [];
echo json_encode(['username' => $cert['username'] ?? '', 'password' => $cert['password'] ?? '']);
'@
$credentialJson = & $PhpExecutable -r $credentialProbe $testConfigFile
if ($LASTEXITCODE -ne 0) {
    throw 'No se pudo leer la cuenta web de certificación.'
}
$credentials = (($credentialJson -join '') | ConvertFrom-Json)
if ([string]::IsNullOrWhiteSpace([string] $credentials.username) -or
    [string]::IsNullOrWhiteSpace([string] $credentials.password)) {
    throw "La configuración aislada no contiene 'certification.username' y 'certification.password'."
}

$certProcess = $null
$phpCgiProcess = $null
$previousPhpIni = [Environment]::GetEnvironmentVariable('PHPRC')
$previousFastCgiRequests = [Environment]::GetEnvironmentVariable('PHP_FCGI_MAX_REQUESTS')
try {
    [Environment]::SetEnvironmentVariable('PHPRC', (Split-Path -Parent $PhpCgiExecutable))
    [Environment]::SetEnvironmentVariable('PHP_FCGI_MAX_REQUESTS', '100')
    $phpCgiProcess = Start-Process -FilePath $PhpCgiExecutable `
        -ArgumentList @('-b', $FastCgiEndpoint) -WindowStyle Hidden -PassThru
    $certProcess = Start-Process -FilePath $ApacheExecutable `
        -ArgumentList @('-f', $ApacheConfig) -WindowStyle Hidden -PassThru

    $loginPage = $null
    $lastStartupError = ''
    for ($attempt = 0; $attempt -lt 30; $attempt++) {
        try {
            $loginPage = Invoke-WebRequest -UseBasicParsing -Uri ($BaseUrl + '/login') `
                -SessionVariable certSession -TimeoutSec 3
            break
        } catch {
            $lastStartupError = $_.Exception.Message
            Start-Sleep -Milliseconds 200
        }
    }
    if ($null -eq $loginPage -or $loginPage.StatusCode -ne 200) {
        throw "Apache PHP 8.4 no respondió en el puerto de certificación: $lastStartupError"
    }
    if ($loginPage.Content -notmatch 'name="csrf_token"\s+value="([^"]+)"') {
        throw 'La pantalla de acceso no entregó el token CSRF.'
    }
    $csrfToken = $Matches[1]
    Write-Host '[OK] GET /login (HTTP 200 + CSRF)' -ForegroundColor Green

    $loginResponse = Invoke-WebRequest -UseBasicParsing -Uri ($BaseUrl + '/login') `
        -Method Post -WebSession $certSession -Body @{
            csrf_token = $csrfToken
            username = [string] $credentials.username
            password = [string] $credentials.password
        } -TimeoutSec 10
    if ($loginResponse.StatusCode -ne 200 -or
        $loginResponse.Content -match 'Usuario o contraseña incorrectos') {
        throw 'El inicio de sesión de certificación no fue aceptado.'
    }
    Write-Host '[OK] POST /login (sesión autenticada)' -ForegroundColor Green

    foreach ($route in @('/', '/diagnostico', '/notas-credito', '/por-pagar', '/correo')) {
        $response = Invoke-WebRequest -UseBasicParsing -Uri ($BaseUrl + $route) `
            -WebSession $certSession -TimeoutSec 15
        if ($response.StatusCode -ne 200) {
            throw "La ruta $route respondió HTTP $($response.StatusCode)."
        }
        if ($response.Content -match '(?i)PHP (Deprecated|Warning|Fatal error)|Parse error') {
            throw "La ruta $route mostró un error o una deprecación de PHP."
        }
        if ($route -eq '/diagnostico' -and $response.Content -notmatch '8\.4\.24') {
            throw 'El diagnóstico web no confirmó PHP 8.4.24.'
        }
        if ($route -eq '/diagnostico' -and $response.Content -notmatch 'Todas presentes') {
            throw 'El diagnóstico web no confirmó todas las extensiones requeridas.'
        }
        Write-Host "[OK] GET $route (HTTP 200)" -ForegroundColor Green
    }

} finally {
    $certProcesses = @(
        Get-CimInstance Win32_Process -Filter "Name='httpd.exe'" -ErrorAction SilentlyContinue |
            Where-Object {
                $null -ne $_.CommandLine -and
                $_.CommandLine.Replace('/', '\') -like ('*' + $configNeedle.Replace('/', '\') + '*')
            }
    )
    if ($certProcesses.Count -gt 0) {
        Stop-Process -Id @($certProcesses | Select-Object -ExpandProperty ProcessId) -Force `
            -ErrorAction SilentlyContinue
    }
    if ($null -ne $phpCgiProcess -and -not $phpCgiProcess.HasExited) {
        Stop-Process -Id $phpCgiProcess.Id -Force -ErrorAction SilentlyContinue
    }
    if (Test-Path -LiteralPath $certPidFile -PathType Leaf) {
        [System.IO.File]::Delete($certPidFile)
    }
    [Environment]::SetEnvironmentVariable('PHPRC', $previousPhpIni)
    [Environment]::SetEnvironmentVariable('PHP_FCGI_MAX_REQUESTS', $previousFastCgiRequests)
}

$newErrorLog = ''
if (Test-Path -LiteralPath $certErrorLog -PathType Leaf) {
    $stream = [System.IO.File]::Open($certErrorLog, 'Open', 'Read', 'ReadWrite')
    try {
        [void] $stream.Seek($initialErrorLogLength, [System.IO.SeekOrigin]::Begin)
        $reader = New-Object System.IO.StreamReader($stream)
        try {
            $newErrorLog = $reader.ReadToEnd()
        } finally {
            $reader.Dispose()
        }
    } finally {
        $stream.Dispose()
    }
}
if ($newErrorLog -match '(?i)PHP (Warning|Deprecated|Fatal|Parse)|\[(proxy|core):error\]') {
    throw "El smoke test dejó errores nuevos en Apache:`n$newErrorLog"
}
Write-Host '[OK] Log de Apache sin errores ni deprecaciones de PHP' -ForegroundColor Green
Write-Host '[CERTIFICADO] Apache + FastCGI ejecutó PHP 8.4.24 y completó el smoke test web.' -ForegroundColor Green
