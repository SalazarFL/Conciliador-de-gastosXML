[CmdletBinding()]
param(
    [string]$PhpExecutable = 'C:\WebServer\PHP84\php.exe',
    [switch]$SoloCodigo
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
if (-not (Test-Path -LiteralPath $PhpExecutable -PathType Leaf)) {
    throw "No existe el ejecutable PHP indicado: $PhpExecutable"
}
$php84 = (Resolve-Path -LiteralPath $PhpExecutable).Path

function Invoke-Php84 {
    param([string[]]$Arguments)

    $previousErrorActionPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $output = & $php84 @Arguments 2>&1
        $exitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }
    return [PSCustomObject]@{
        ExitCode = $exitCode
        Output = @($output | ForEach-Object { [string] $_ })
    }
}

Push-Location $projectRoot
try {
    $versionCheck = Invoke-Php84 @(
        '-r',
        "echo PHP_VERSION, '|', PHP_VERSION_ID, '|', (PHP_ZTS ? 'TS' : 'NTS'), PHP_EOL;"
    )
    if ($versionCheck.ExitCode -ne 0 -or $versionCheck.Output.Count -eq 0) {
        throw "No se pudo consultar la versión de PHP.`n$($versionCheck.Output -join "`n")"
    }

    $versionParts = $versionCheck.Output[-1].Split('|')
    $versionId = [int] $versionParts[1]
    if ($versionId -lt 80400 -or $versionId -ge 80500) {
        throw "La certificación exige PHP 8.4.x; se recibió $($versionParts[0])."
    }
    $threadMode = $versionParts[2].Trim()
    if ($threadMode -notin @('TS', 'NTS')) {
        throw "No se pudo determinar el modo de hilos de PHP: $threadMode."
    }
    Write-Host "[OK] PHP $($versionParts[0]) $threadMode" -ForegroundColor Green

    $requiredExtensions = @(
        'curl', 'fileinfo', 'imap', 'mbstring', 'openssl', 'pdo',
        'pdo_mysql', 'simplexml', 'xml', 'zip'
    )
    $extensionCheck = Invoke-Php84 @('-r', 'echo json_encode(get_loaded_extensions());')
    if ($extensionCheck.ExitCode -ne 0) {
        throw "No se pudo consultar las extensiones.`n$($extensionCheck.Output -join "`n")"
    }
    $loadedExtensions = @((($extensionCheck.Output -join '') | ConvertFrom-Json))
    $missingExtensions = @($requiredExtensions | Where-Object { $_ -notin $loadedExtensions })
    if ($missingExtensions.Count -gt 0) {
        throw "Faltan extensiones requeridas: $($missingExtensions -join ', ')"
    }
    Write-Host "[OK] Extensiones requeridas: $($requiredExtensions -join ', ')" -ForegroundColor Green

    $phpFiles = @(
        Get-ChildItem -LiteralPath app, public, cli, tests -Recurse -File -Filter '*.php' |
            Sort-Object FullName
    )
    $lintFailures = @()
    foreach ($phpFile in $phpFiles) {
        $lint = Invoke-Php84 @(
            '-d', 'error_reporting=E_ALL',
            '-d', 'display_errors=1',
            '-l', $phpFile.FullName
        )
        $lintText = $lint.Output -join "`n"
        if ($lint.ExitCode -ne 0 -or $lintText -match '(?i)deprecated|warning|fatal error|parse error') {
            $lintFailures += "$($phpFile.FullName)`n$lintText"
        }
    }
    if ($lintFailures.Count -gt 0) {
        throw "Falló la revisión sintáctica/de deprecaciones:`n$($lintFailures -join "`n`n")"
    }
    Write-Host "[OK] $($phpFiles.Count) archivos PHP sin errores ni deprecaciones" -ForegroundColor Green

    if ($SoloCodigo) {
        Write-Host '[PARCIAL] No se ejecutaron pruebas funcionales por -SoloCodigo.' -ForegroundColor Yellow
        return
    }

    $testConfigFile = [Environment]::GetEnvironmentVariable('XMLCONCILIA_CONFIG_FILE')
    if ([string]::IsNullOrWhiteSpace($testConfigFile) -or
        -not (Test-Path -LiteralPath $testConfigFile -PathType Leaf)) {
        throw 'Para ejecutar las pruebas define XMLCONCILIA_CONFIG_FILE con una configuración de base aislada.'
    }
    $testConfigFile = (Resolve-Path -LiteralPath $testConfigFile).Path
    $configProbe = @'
$local = require $argv[1];
$db = is_array($local) && isset($local['database']) && is_array($local['database']) ? $local['database'] : [];
echo json_encode(['host' => $db['host'] ?? '', 'database' => $db['database'] ?? '']);
'@
    $databaseCheck = Invoke-Php84 @('-r', $configProbe, $testConfigFile)
    if ($databaseCheck.ExitCode -ne 0) {
        throw "No se pudo leer la configuración aislada.`n$($databaseCheck.Output -join "`n")"
    }
    $databaseTarget = (($databaseCheck.Output -join '') | ConvertFrom-Json)
    if ($databaseTarget.host -notin @('127.0.0.1', 'localhost', '::1')) {
        throw "Las pruebas se negaron a usar una base remota: $($databaseTarget.host)."
    }
    if ([string]$databaseTarget.database -notmatch '(?i)(test|cert)') {
        throw "La base aislada debe incluir 'test' o 'cert' en su nombre; se recibió '$($databaseTarget.database)'."
    }
    Write-Host "[OK] Base aislada: $($databaseTarget.database) en $($databaseTarget.host)" -ForegroundColor Green

    $testFailures = @()
    $testSkips = @()
    $testFiles = @(Get-ChildItem -LiteralPath tests -File -Filter '*Test.php' | Sort-Object Name)
    foreach ($testFile in $testFiles) {
        $test = Invoke-Php84 @(
            '-d', 'error_reporting=E_ALL',
            '-d', 'display_errors=1',
            $testFile.FullName
        )
        $testText = $test.Output -join "`n"
        if ($testText -match '(?m)^SKIP:') {
            $testSkips += "$($testFile.Name): $testText"
            Write-Host "[SKIP] $($testFile.Name)" -ForegroundColor Yellow
        } elseif ($test.ExitCode -ne 0 -or $testText -match '(?i)deprecated|warning|fatal error|parse error|^FAIL:') {
            $testFailures += "$($testFile.Name)`n$testText"
            Write-Host "[FAIL] $($testFile.Name)" -ForegroundColor Red
        } else {
            Write-Host "[OK] $($testFile.Name)" -ForegroundColor Green
        }
    }

    if ($testSkips.Count -gt 0 -or $testFailures.Count -gt 0) {
        $details = @()
        if ($testSkips.Count -gt 0) {
            $details += "Pruebas omitidas:`n$($testSkips -join "`n")"
        }
        if ($testFailures.Count -gt 0) {
            $details += "Pruebas fallidas:`n$($testFailures -join "`n`n")"
        }
        throw "La certificación no está completa.`n$($details -join "`n`n")"
    }

    Write-Host "[CERTIFICADO] $($testFiles.Count) pruebas aprobadas en PHP $($versionParts[0])." -ForegroundColor Green
} finally {
    Pop-Location
}
