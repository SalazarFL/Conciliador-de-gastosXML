#Requires -RunAsAdministrator

<#
    Corte definitivo del stack heredado al stack independiente.

    La aplicacion queda fuera de C:\xampp, MariaDB 11.4 toma el puerto 3306,
    Apache/PHP 8.4 permanece local en 8484 y Tailscale publica HTTPS 443.
    Los servicios antiguos se detienen y deshabilitan, pero no se desinstalan.
#>

[CmdletBinding()]
param(
    [switch]$Execute
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

if (-not $Execute) {
    throw 'Corte no iniciado. Vuelve a ejecutar con -Execute despues de cerrar las pantallas de Nexo Fiscal.'
}

$source = 'C:\xampp\htdocs\xmlconcilia'
$target = 'C:\WebApps\xmlconcilia'
$database = 'bd_xmlconcilia'
$oldApacheService = 'Apache2.4'
$oldMariaService = 'mysql'
$newApacheService = 'XmlConciliaApache84'
$newMariaService = 'XmlConciliaMariaDB114'
$apacheExe = 'C:\WebServer\Apache24\bin\httpd.exe'
$apacheConfig = 'C:\WebServer\Apache24\conf\httpd-xmlconcilia.conf'
$apacheFinalConfig = 'C:\WebServer\Apache24\conf\httpd-xmlconcilia-final.conf'
$mariaConfig = 'C:\WebServer\MariaDB114\my-xmlconcilia.ini'
$mariaFinalConfig = 'C:\WebServer\MariaDB114\my-xmlconcilia-final.ini'
$mariaClient = 'C:\WebServer\MariaDB114\bin\mariadb.exe'
$mariaDump = 'C:\WebServer\MariaDB114\bin\mariadb-dump.exe'
$mariaCheck = 'C:\WebServer\MariaDB114\bin\mariadb-check.exe'
$rootClient = 'C:\WebServer\secrets\mariadb114-root-client.ini'
$php = 'C:\WebServer\PHP84\php.exe'
$tailscale = 'C:\Program Files\Tailscale\tailscale.exe'
$smokeTest = Join-Path $target 'scripts\verificar-stack-migracion.ps1'
$backend = 'http://127.0.0.1:8484'
$taskNames = @(
    'XMLConcilia_ProcesarLotes',
    'XMLConcilia_SyncCorreo',
    'XMLConcilia_RespaldoBase',
    'XMLConcilia_SelectorCarpeta'
)

foreach ($path in @(
    $source, $target, $apacheExe, $apacheConfig, $apacheFinalConfig,
    $mariaConfig, $mariaFinalConfig, $mariaClient, $mariaDump, $mariaCheck,
    $rootClient, $php, $tailscale
)) {
    if (-not (Test-Path -LiteralPath $path)) {
        throw "Falta un elemento requerido: $path"
    }
}
foreach ($serviceName in @($oldApacheService, $oldMariaService, $newApacheService, $newMariaService, 'Tailscale')) {
    if ($null -eq (Get-Service -Name $serviceName -ErrorAction SilentlyContinue)) {
        throw "Falta el servicio requerido: $serviceName"
    }
}

function Invoke-Checked {
    param(
        [string]$FilePath,
        [string[]]$Arguments,
        [string]$Description
    )

    $previousPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $output = @(& $FilePath @Arguments 2>&1)
        $exitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousPreference
    }
    if ($exitCode -ne 0) {
        throw "$Description fallo con codigo ${exitCode}:`n$($output -join "`n")"
    }
    return @($output | ForEach-Object { [string]$_ })
}

function Wait-ServiceState([string]$Name, [string]$State, [int]$Seconds = 45) {
    (Get-Service -Name $Name).WaitForStatus($State, [TimeSpan]::FromSeconds($Seconds))
}

function Wait-Port([int]$Port, [bool]$Listening, [int]$Seconds = 45) {
    $deadline = (Get-Date).AddSeconds($Seconds)
    do {
        $found = @(Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue).Count -gt 0
        if ($found -eq $Listening) { return }
        Start-Sleep -Milliseconds 500
    } while ((Get-Date) -lt $deadline)
    $expected = if ($Listening) { 'abriera' } else { 'liberara' }
    throw "El puerto $Port no se $expected dentro de $Seconds segundos."
}

function Invoke-SourceSql([string]$Sql) {
    $args = @(
        '--protocol=tcp', '--host=127.0.0.1', '--port=3306', '--user=root',
        '--skip-ssl', '--batch', '--skip-column-names', "--database=$database",
        "--execute=$Sql"
    )
    return @(Invoke-Checked $mariaClient $args 'Consulta en MariaDB de origen')
}

function Invoke-TargetSql([string]$Sql, [switch]$WithoutDatabase) {
    $args = @(
        "--defaults-extra-file=$rootClient", '--protocol=tcp', '--host=127.0.0.1',
        '--port=3307', '--skip-ssl', '--batch', '--skip-column-names'
    )
    if (-not $WithoutDatabase) { $args += "--database=$database" }
    $args += "--execute=$Sql"
    return @(Invoke-Checked $mariaClient $args 'Consulta en MariaDB 11.4')
}

function Get-ExactCounts([ValidateSet('source','target')] [string]$Side) {
    $tableSql = "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='$database' AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME"
    $tables = if ($Side -eq 'source') { @(Invoke-SourceSql $tableSql) } else { @(Invoke-TargetSql $tableSql) }
    $counts = @{}
    foreach ($tableName in $tables) {
        $table = $tableName.Trim()
        if ($table -eq '') { continue }
        if ($table -notmatch '^[A-Za-z0-9_]+$') { throw "Nombre de tabla inesperado: $table" }
        $sql = "SELECT COUNT(*) FROM ``$table``"
        $value = if ($Side -eq 'source') { Invoke-SourceSql $sql } else { Invoke-TargetSql $sql }
        $counts[$table] = [int64]($value | Select-Object -Last 1)
    }
    return $counts
}

function Compare-Counts($SourceCounts, $TargetCounts) {
    $differences = @()
    foreach ($table in $SourceCounts.Keys) {
        if (-not $TargetCounts.ContainsKey($table)) {
            $differences += "$table falta en destino"
        } elseif ($SourceCounts[$table] -ne $TargetCounts[$table]) {
            $differences += "$table origen=$($SourceCounts[$table]) destino=$($TargetCounts[$table])"
        }
    }
    foreach ($table in $TargetCounts.Keys) {
        if (-not $SourceCounts.ContainsKey($table)) { $differences += "$table existe solo en destino" }
    }
    if ($differences.Count -gt 0) {
        throw "La copia final no coincide:`n$($differences -join "`n")"
    }
    return [int64](($SourceCounts.Values | Measure-Object -Sum).Sum)
}

function Set-TasksEnabled([hashtable]$States) {
    foreach ($name in $States.Keys) {
        $task = Get-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue
        if ($null -eq $task) { continue }
        if ($States[$name]) {
            Enable-ScheduledTask -TaskName $name | Out-Null
        } else {
            Disable-ScheduledTask -TaskName $name | Out-Null
        }
    }
}

function Restore-ParallelStack {
    param([hashtable]$TaskStates)
    Write-Host '[RETORNO] Restaurando el estado paralelo anterior...' -ForegroundColor Yellow
    Stop-Service -Name $newApacheService -Force -ErrorAction SilentlyContinue
    Stop-Service -Name $newMariaService -Force -ErrorAction SilentlyContinue

    if (Test-Path -LiteralPath $script:apacheBackup) {
        Copy-Item -LiteralPath $script:apacheBackup -Destination $apacheConfig -Force
    }
    if (Test-Path -LiteralPath $script:mariaBackup) {
        Copy-Item -LiteralPath $script:mariaBackup -Destination $mariaConfig -Force
    }
    if (Test-Path -LiteralPath $rootClient) {
        $rootText = [IO.File]::ReadAllText($rootClient)
        if ($rootText -match '(?m)^port=3306(?=\r?$)') {
            $rootText = $rootText -replace '(?m)^port=3306(?=\r?$)', 'port=3307'
            [IO.File]::WriteAllText($rootClient, $rootText, (New-Object Text.UTF8Encoding($false)))
        }
    }

    $previousPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        & $tailscale serve reset 2>&1 | Out-Host
    } finally {
        $ErrorActionPreference = $previousPreference
    }

    Set-Service -Name $oldMariaService -StartupType Automatic -ErrorAction SilentlyContinue
    Set-Service -Name $oldApacheService -StartupType Automatic -ErrorAction SilentlyContinue
    Start-Service -Name $oldMariaService -ErrorAction SilentlyContinue
    Start-Service -Name $newMariaService -ErrorAction SilentlyContinue
    Start-Service -Name $oldApacheService -ErrorAction SilentlyContinue
    Start-Service -Name $newApacheService -ErrorAction SilentlyContinue

    $previousPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        & $tailscale serve --bg --yes --https=8443 $backend 2>&1 | Out-Host
    } finally {
        $ErrorActionPreference = $previousPreference
    }
    Set-TasksEnabled $TaskStates
}

$freeBytes = (Get-PSDrive -Name C).Free
if ($freeBytes -lt 2GB) {
    throw 'Se requieren al menos 2 GB libres para el respaldo y la restauracion final.'
}

$syntax = Invoke-Checked $apacheExe @('-t', '-f', $apacheFinalConfig) 'Validacion de Apache final'
$oldVersion = (Invoke-SourceSql 'SELECT VERSION(), @@port' | Select-Object -Last 1)
$newVersion = (Invoke-TargetSql 'SELECT VERSION(), @@port' | Select-Object -Last 1)
if ($oldVersion -notmatch '^10\.4\..*\s3306$') { throw "Origen inesperado: $oldVersion" }
if ($newVersion -notmatch '^11\.4\..*\s3307$') { throw "Destino inesperado: $newVersion" }

$timestamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$backupRoot = "C:\WebMigration\cutovers\cutover-$timestamp"
New-Item -ItemType Directory -Path $backupRoot -Force | Out-Null
$backupAcl = New-Object Security.AccessControl.DirectorySecurity
$backupAcl.SetAccessRuleProtection($true, $false)
$inheritance = [Security.AccessControl.InheritanceFlags]'ContainerInherit, ObjectInherit'
$propagation = [Security.AccessControl.PropagationFlags]::None
$allow = [Security.AccessControl.AccessControlType]::Allow
foreach ($sidValue in @([Security.Principal.WindowsIdentity]::GetCurrent().User.Value, 'S-1-5-18')) {
    $sid = New-Object Security.Principal.SecurityIdentifier($sidValue)
    $rule = New-Object Security.AccessControl.FileSystemAccessRule(
        $sid, [Security.AccessControl.FileSystemRights]::FullControl,
        $inheritance, $propagation, $allow
    )
    $backupAcl.AddAccessRule($rule)
}
Set-Acl -LiteralPath $backupRoot -AclObject $backupAcl
$script:apacheBackup = Join-Path $backupRoot 'httpd-xmlconcilia.parallel.conf'
$script:mariaBackup = Join-Path $backupRoot 'my-xmlconcilia.parallel.ini'
$dumpFile = Join-Path $backupRoot "$database-final.sql"
$targetBeforeFile = Join-Path $backupRoot "$database-target-before.sql"
$importOut = Join-Path $backupRoot 'import.stdout.log'
$importErr = Join-Path $backupRoot 'import.stderr.log'
$tailscaleBackup = Join-Path $backupRoot 'tailscale-serve.json'
$stateFile = Join-Path $backupRoot 'estado.json'

Copy-Item -LiteralPath $apacheConfig -Destination $script:apacheBackup
Copy-Item -LiteralPath $mariaConfig -Destination $script:mariaBackup

$taskStates = @{}
foreach ($name in $taskNames) {
    $task = Get-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue
    if ($null -ne $task) { $taskStates[$name] = [bool]$task.Settings.Enabled }
}

@{
    status = 'starting'
    started_at = (Get-Date).ToString('o')
    source = $source
    target = $target
    backup = $backupRoot
    old_version = $oldVersion
    new_version = $newVersion
} | ConvertTo-Json | Set-Content -LiteralPath $stateFile -Encoding UTF8

$cutoverStarted = $false
try {
    Write-Host '[1/8] Cerrando tareas y servidores web...' -ForegroundColor Cyan
    foreach ($name in $taskStates.Keys) {
        Stop-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue
        Disable-ScheduledTask -TaskName $name | Out-Null
    }
    Stop-Service -Name $newApacheService -Force
    Stop-Service -Name $oldApacheService -Force
    Wait-ServiceState $newApacheService 'Stopped'
    Wait-ServiceState $oldApacheService 'Stopped'
    Wait-Port 8484 $false
    Wait-Port 80 $false
    Wait-Port 443 $false

    $cliProcesses = @(Get-CimInstance Win32_Process -Filter "Name='php.exe'" -ErrorAction SilentlyContinue |
        Where-Object { $_.CommandLine -like "*$source\cli\*" })
    foreach ($process in $cliProcesses) {
        Stop-Process -Id $process.ProcessId -Force -ErrorAction SilentlyContinue
    }

    Write-Host '[2/8] Sincronizando la aplicacion fuera de XAMPP...' -ForegroundColor Cyan
    & robocopy $source $target /E /COPY:DAT /DCOPY:DAT /R:2 /W:1 /XJ /XD "$source\storage\correo\tmp" /NFL /NDL /NP | Out-Host
    $copyExit = $LASTEXITCODE
    if ($copyExit -gt 7) { throw "Robocopy fallo con codigo $copyExit." }
    New-Item -ItemType Directory -Path "$target\storage\correo\tmp" -Force | Out-Null
    if (-not (Test-Path -LiteralPath "$target\app\config\local.production.php")) {
        throw 'La configuracion de produccion no llego al despliegue.'
    }

    Write-Host '[3/8] Generando respaldos finales...' -ForegroundColor Cyan
    $dumpArgs = @(
        '--protocol=tcp', '--host=127.0.0.1', '--port=3306', '--user=root',
        '--skip-ssl', '--default-character-set=utf8mb4', '--single-transaction',
        '--quick', '--routines', '--events', '--triggers', '--hex-blob',
        '--add-drop-table', "--result-file=$dumpFile", $database
    )
    [void](Invoke-Checked $mariaDump $dumpArgs 'Respaldo final de MariaDB 10.4')

    $targetDumpArgs = @(
        "--defaults-extra-file=$rootClient", '--protocol=tcp', '--host=127.0.0.1',
        '--port=3307', '--skip-ssl', '--default-character-set=utf8mb4',
        '--single-transaction', '--quick', '--routines', '--events', '--triggers',
        '--hex-blob', '--add-drop-table', "--result-file=$targetBeforeFile", $database
    )
    [void](Invoke-Checked $mariaDump $targetDumpArgs 'Respaldo preventivo de MariaDB 11.4')

    $dumpItem = Get-Item -LiteralPath $dumpFile
    $dumpTail = (Get-Content -LiteralPath $dumpFile -Tail 8) -join "`n"
    if ($dumpItem.Length -lt 1MB -or $dumpTail -notmatch 'Dump completed') {
        throw 'El respaldo final esta vacio o no contiene la marca de finalizacion.'
    }
    $dumpHash = (Get-FileHash -LiteralPath $dumpFile -Algorithm SHA256).Hash

    Write-Host '[4/8] Restaurando y comparando MariaDB 11.4...' -ForegroundColor Cyan
    $sourceCounts = Get-ExactCounts 'source'
    [void](Invoke-TargetSql "DROP DATABASE IF EXISTS ``$database``; CREATE DATABASE ``$database`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" -WithoutDatabase)

    $importArgs = @(
        "--defaults-extra-file=$rootClient", '--protocol=tcp', '--host=127.0.0.1',
        '--port=3307', '--skip-ssl', '--default-character-set=utf8mb4', $database
    )
    $import = Start-Process -FilePath $mariaClient -ArgumentList $importArgs `
        -RedirectStandardInput $dumpFile -RedirectStandardOutput $importOut `
        -RedirectStandardError $importErr -WindowStyle Hidden -Wait -PassThru
    if ($import.ExitCode -ne 0) {
        $details = if (Test-Path $importErr) { Get-Content $importErr -Tail 30 } else { @() }
        throw "La importacion fallo con codigo $($import.ExitCode):`n$($details -join "`n")"
    }

    $targetCounts = Get-ExactCounts 'target'
    $totalRows = Compare-Counts $sourceCounts $targetCounts
    [void](Invoke-Checked $mariaCheck @(
        "--defaults-extra-file=$rootClient", '--protocol=tcp', '--host=127.0.0.1',
        '--port=3307', '--skip-ssl', '--extended', $database
    ) 'Comprobacion extendida de MariaDB 11.4')

    Write-Host '[5/8] Entregando el puerto 3306 a MariaDB 11.4...' -ForegroundColor Cyan
    Stop-Service -Name $oldMariaService -Force
    Wait-ServiceState $oldMariaService 'Stopped'
    Wait-Port 3306 $false
    Stop-Service -Name $newMariaService -Force
    Wait-ServiceState $newMariaService 'Stopped'
    Wait-Port 3307 $false

    Copy-Item -LiteralPath $mariaFinalConfig -Destination $mariaConfig -Force
    $rootText = [IO.File]::ReadAllText($rootClient)
    if ($rootText -notmatch '(?m)^port=3307(?=\r?$)') { throw 'El cliente raiz no contiene el puerto esperado 3307.' }
    $rootText = $rootText -replace '(?m)^port=3307(?=\r?$)', 'port=3306'
    [IO.File]::WriteAllText($rootClient, $rootText, (New-Object Text.UTF8Encoding($false)))

    Start-Service -Name $newMariaService
    Wait-ServiceState $newMariaService 'Running'
    Wait-Port 3306 $true
    $cutoverStarted = $true

    Write-Host '[6/8] Iniciando Apache/PHP desde C:\WebApps...' -ForegroundColor Cyan
    Copy-Item -LiteralPath $apacheFinalConfig -Destination $apacheConfig -Force
    [void](Invoke-Checked $apacheExe @('-t', '-f', $apacheConfig) 'Configuracion activa de Apache')
    Start-Service -Name $newApacheService
    Wait-ServiceState $newApacheService 'Running'
    Wait-Port 8484 $true

    Write-Host '[7/8] Moviendo Tailscale al HTTPS estandar 443...' -ForegroundColor Cyan
    $tailscaleStatusJson = (Invoke-Checked $tailscale @('serve', 'status', '--json') 'Respaldo de Tailscale Serve') -join "`n"
    [IO.File]::WriteAllText($tailscaleBackup, $tailscaleStatusJson, (New-Object Text.UTF8Encoding($false)))
    [void](Invoke-Checked $tailscale @('serve', 'reset') 'Limpieza de Tailscale Serve temporal')
    & $tailscale serve --bg --yes --https=443 $backend 2>&1 | Out-Host
    if ($LASTEXITCODE -ne 0) { throw 'No se pudo publicar HTTPS 443 mediante Tailscale.' }

    $tailStatus = (& $tailscale status --json | ConvertFrom-Json)
    $dnsName = ([string]$tailStatus.Self.DNSName).TrimEnd('.')
    $finalUrl = "https://$dnsName"
    $env:XMLCONCILIA_SMOKE_DB_PORT = '3306'
    try {
        & $smokeTest -UseRunningInstance -BaseUrl $finalUrl
        if ($LASTEXITCODE -ne 0) { throw 'La validacion web final fallo.' }
    } finally {
        Remove-Item Env:XMLCONCILIA_SMOKE_DB_PORT -ErrorAction SilentlyContinue
    }

    Write-Host '[8/8] Actualizando tareas y deshabilitando servicios antiguos...' -ForegroundColor Cyan
    $taskActions = @{
        'XMLConcilia_ProcesarLotes' = "$target\storage\correo\lotes_launch.vbs"
        'XMLConcilia_SyncCorreo' = "$target\storage\correo\sync_launch.vbs"
        'XMLConcilia_RespaldoBase' = "$target\storage\correo\respaldo_launch.vbs"
    }
    foreach ($name in $taskActions.Keys) {
        if ($null -ne (Get-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue)) {
            $action = New-ScheduledTaskAction -Execute 'wscript.exe' -Argument "//B //Nologo `"$($taskActions[$name])`""
            Set-ScheduledTask -TaskName $name -Action $action | Out-Null
        }
    }
    if ($null -ne (Get-ScheduledTask -TaskName 'XMLConcilia_SelectorCarpeta' -ErrorAction SilentlyContinue)) {
        Unregister-ScheduledTask -TaskName 'XMLConcilia_SelectorCarpeta' -Confirm:$false
        $taskStates.Remove('XMLConcilia_SelectorCarpeta')
    }
    Set-TasksEnabled $taskStates

    Set-Service -Name $oldApacheService -StartupType Disabled
    Set-Service -Name $oldMariaService -StartupType Disabled
    & sc.exe description $newMariaService 'MariaDB 11.4 LTS para Nexo Fiscal (127.0.0.1:3306)' | Out-Null
    & sc.exe description $newApacheService 'Apache 2.4.68 y PHP 8.4 FastCGI para Nexo Fiscal' | Out-Null

    @{
        status = 'complete'
        completed_at = (Get-Date).ToString('o')
        url = $finalUrl
        application = $target
        database_data = 'C:\WebServer\Data\MariaDB114'
        backup = $dumpFile
        backup_sha256 = $dumpHash
        tables = $sourceCounts.Count
        rows = $totalRows
        old_services = 'stopped-disabled'
    } | ConvertTo-Json | Set-Content -LiteralPath $stateFile -Encoding UTF8

    Write-Host ''
    Write-Host "[COMPLETADO] Nexo Fiscal funciona sin XAMPP: $finalUrl" -ForegroundColor Green
    Write-Host "[DATOS] MariaDB 11.4 en C:\WebServer\Data\MariaDB114 ($totalRows filas)." -ForegroundColor Green
    Write-Host "[RESPALDO] $dumpFile" -ForegroundColor Green
    Write-Host '[RETORNO] XAMPP quedo instalado, pero detenido y deshabilitado.' -ForegroundColor Yellow
} catch {
    $failure = $_
    try {
        Restore-ParallelStack $taskStates
    } catch {
        Write-Warning "El retorno automatico tambien encontro un problema: $($_.Exception.Message)"
    }
    @{
        status = 'failed'
        failed_at = (Get-Date).ToString('o')
        error = $failure.Exception.Message
        backup = $backupRoot
        cutover_started = $cutoverStarted
    } | ConvertTo-Json | Set-Content -LiteralPath $stateFile -Encoding UTF8
    throw $failure
}
