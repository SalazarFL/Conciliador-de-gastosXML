<#
    Trae la base compartida al MySQL de esta computadora.

    Dos caminos, según si se alcanza el servidor o no:

        .\scripts\copiar-base.ps1                 # directo del servidor: respalda y restaura aquí
        .\scripts\copiar-base.ps1 -SoloRespaldo   # solo el archivo .sql (esto es lo que corre el servidor)
        .\scripts\copiar-base.ps1 -Desde ultimo   # del respaldo que dejó la otra máquina en SharePoint
        .\scripts\copiar-base.ps1 -Desde C:\ruta\archivo.sql.gz
        .\scripts\copiar-base.ps1 -SinPreguntar   # para tareas programadas

    El camino -Desde existe porque el directo no sirve justo cuando más falta
    hace: si Tailscale no llega al servidor, tampoco llega mysqldump. Entonces
    se invierte quién empieza — la máquina que SÍ tiene la base genera el
    respaldo (botón en Administración -> Diagnóstico, o `php
    cli/respaldar_base.php`), lo deja en la carpeta compartida, SharePoint lo
    sincroniza, y aquí se levanta sin tocar la red.

    Va siempre en una sola dirección: servidor -> esta máquina. La copia local
    se BORRA y se vuelve a crear en cada corrida, así que nada de lo que se
    haya trabajado sobre ella sobrevive. No existe el camino de vuelta y no
    debería: dos bases que se escriben a la vez no se pueden reconciliar solas.

    Detalles que parecen caprichos y no lo son:
      - Sin --routines. El usuario `xmlconcilia` del servidor no puede hacer
        SHOW CREATE PROCEDURE y mysqldump aborta. Por eso se exportan solo las
        tablas y las vistas y el procedimiento se recrean después desde
        database/vistas_y_procedimientos.sql.
      - Con --result-file en vez de `>`. La redirección de PowerShell escribe
        UTF-16 con BOM y mysql no puede leer el archivo.
      - La contraseña va en un archivo temporal, no en la línea de comandos,
        donde la vería cualquiera que liste procesos.
#>

param(
    [switch] $SoloRespaldo,
    [switch] $SinPreguntar,
    [string] $Desde = ''
)

$ErrorActionPreference = 'Stop'

$raiz    = Split-Path -Parent $PSScriptRoot
$config  = Join-Path $raiz 'app\config'
$destino = Join-Path $raiz 'storage\backups'

function Get-PhpExe {
    $cmd = Get-Command php -ErrorAction SilentlyContinue
    if ($null -ne $cmd) { return $cmd.Source }
    if (Test-Path 'C:\WebServer\PHP84\php.exe') { return 'C:\WebServer\PHP84\php.exe' }
    throw 'No se encontró php.exe en el PATH ni en C:\WebServer\PHP84.'
}

function Get-MysqlBin([string]$exe) {
    $equivalentes = switch ($exe.ToLowerInvariant()) {
        'mysql.exe'     { @('mariadb.exe', 'mysql.exe') }
        'mysqldump.exe' { @('mariadb-dump.exe', 'mysqldump.exe') }
        default         { @($exe) }
    }
    foreach ($nombre in $equivalentes) {
        $ruta = Join-Path 'C:\WebServer\MariaDB114\bin' $nombre
        if (Test-Path $ruta) { return $ruta }
        $cmd = Get-Command $nombre -ErrorAction SilentlyContinue
        if ($null -ne $cmd) { return $cmd.Source }
    }
    throw "No se encontró $exe en el PATH ni en C:\WebServer\MariaDB114\bin."
}

# Los datos de conexión salen de los mismos archivos que usa la aplicación,
# leídos por PHP: si un día cambia la contraseña se cambia en un solo lugar.
function Get-Conexion([string]$archivo) {
    if (-not (Test-Path $archivo)) {
        throw "Falta $archivo. Ver docs/INSTALACION.md, sección `"Una copia local de la base`"."
    }
    # Comillas simples dentro del código PHP a propósito: PowerShell 5.1 se
    # come las dobles al pasar argumentos a un .exe y PHP recibe basura. Por
    # lo mismo el separador es chr(9) y no "\t", que entre comillas simples
    # PHP no interpreta.
    $codigo = '$c=require getenv(''XMLC_LOCAL'');$d=$c[''database''];echo implode(chr(9),[$d[''host''],$d[''port''],$d[''database''],$d[''username''],$d[''password'']]);'
    $env:XMLC_LOCAL = $archivo
    try { $linea = & (Get-PhpExe) -r $codigo } finally { Remove-Item Env:\XMLC_LOCAL }
    $p = $linea -split "`t"
    return [pscustomobject]@{
        Host = $p[0]; Puerto = $p[1]; Base = $p[2]; Usuario = $p[3]; Clave = $p[4]
    }
}

# mysql lee usuario y contraseña de aquí; el archivo vive lo que dura el script.
function New-Cnf($cx) {
    $cnf = Join-Path $env:TEMP ('xmlc_' + [guid]::NewGuid().ToString('N') + '.cnf')
    $texto = "[client]`r`nuser=$($cx.Usuario)`r`npassword=$($cx.Clave)`r`nhost=$($cx.Host)`r`nport=$($cx.Puerto)`r`n"
    Set-Content -Path $cnf -Value $texto -Encoding ascii -NoNewline
    return $cnf
}

function Invoke-Sql($cnf, [string]$base, [string]$sql) {
    $mysql = Get-MysqlBin 'mysql.exe'
    # Sin base cuando toca crearla: pasarla vacía sería un argumento vacío y
    # mysql lo toma como nombre de base, no como ausencia.
    if ($base -eq '') {
        return & $mysql "--defaults-extra-file=$cnf" --default-character-set=utf8mb4 -N -B -e $sql
    }
    return & $mysql "--defaults-extra-file=$cnf" --default-character-set=utf8mb4 -N -B -e $sql $base
}

# La carpeta compartida sale de la configuración de la aplicación, no de una
# ruta escrita aquí: en cada máquina OneDrive la deja en otro lugar.
function Get-CarpetaRespaldos {
    $cfg = Join-Path $raiz 'storage\correo\config.json'
    if (-not (Test-Path $cfg)) {
        throw "No hay carpeta compartida configurada (falta storage\correo\config.json). Configurala en Correo -> Carpeta de documentos."
    }
    $json = Get-Content $cfg -Raw -Encoding UTF8 | ConvertFrom-Json
    $carpeta = [string]$json.carpeta_destino
    if ([string]::IsNullOrWhiteSpace($carpeta)) {
        throw 'La configuración no tiene carpeta_destino. Configurala en Correo -> Carpeta de documentos.'
    }
    return (Join-Path (Join-Path $carpeta '_TRABAJO') 'RESPALDOS')
}

function Resolve-Respaldo([string]$que) {
    if ($que -ne 'ultimo' -and $que -ne 'último') {
        if (-not (Test-Path $que)) { throw "No existe el archivo $que" }
        return (Resolve-Path $que).Path
    }

    $carpeta = Get-CarpetaRespaldos
    if (-not (Test-Path $carpeta)) {
        throw ("Todavía no existe $carpeta.`n" +
               "Nadie ha generado un respaldo, o SharePoint aún no lo sincronizó hasta acá.")
    }
    $archivos = @(Get-ChildItem $carpeta -Filter *.sql.gz -ErrorAction SilentlyContinue |
                  Sort-Object LastWriteTime -Descending)
    if ($archivos.Count -eq 0) {
        throw ("No hay ningún respaldo en $carpeta.`n" +
               "En la computadora que tiene la base: Administración -> Diagnóstico -> Generar respaldo ahora.")
    }
    return $archivos[0].FullName
}

# .gz por trozos: el archivo descomprimido no cabe en memoria.
function Expand-Gz([string]$origen, [string]$salida) {
    $entrada = [System.IO.File]::OpenRead($origen)
    try {
        $gz = New-Object System.IO.Compression.GZipStream($entrada, [System.IO.Compression.CompressionMode]::Decompress)
        try {
            $out = [System.IO.File]::Create($salida)
            try { $gz.CopyTo($out, 1MB) } finally { $out.Dispose() }
        } finally { $gz.Dispose() }
    } finally { $entrada.Dispose() }
}

# Borra la base local y la rehace desde un .sql. Se recrea entera y no se
# sobrescriben las tablas una por una: si en el servidor se borró una tabla,
# sobrescribir la dejaría aquí para siempre.
function Restore-Base($cnf, $cx, [string]$archivoSql) {
    $mysql = Get-MysqlBin 'mysql.exe'

    Invoke-Sql $cnf '' "DROP DATABASE IF EXISTS ``$($cx.Base)``; CREATE DATABASE ``$($cx.Base)`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" | Out-Null

    # cmd para la redirección: PowerShell no tiene `<` y mandar 150 MB por
    # tubería reinterpreta la codificación y corrompe los acentos.
    & cmd.exe /c "`"$mysql`" --defaults-extra-file=`"$cnf`" --default-character-set=utf8mb4 $($cx.Base) < `"$archivoSql`""
    if ($LASTEXITCODE -ne 0) { throw "La restauración falló con código $LASTEXITCODE." }

    # El volcado nunca trae vistas ni el procedimiento (ver cabecera).
    $vistas = Join-Path $raiz 'database\vistas_y_procedimientos.sql'
    & cmd.exe /c "`"$mysql`" --defaults-extra-file=`"$cnf`" --default-character-set=utf8mb4 $($cx.Base) < `"$vistas`""
    if ($LASTEXITCODE -ne 0) { throw "No se pudieron recrear las vistas y el procedimiento (código $LASTEXITCODE)." }
}

function Get-Resumen($cnf, $cx) {
    $linea = Invoke-Sql $cnf $cx.Base @"
SELECT (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$($cx.Base)' AND table_type='BASE TABLE'),
       (SELECT COUNT(*) FROM information_schema.views WHERE table_schema='$($cx.Base)'),
       (SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema='$($cx.Base)')
"@
    $p = (($linea | Where-Object { $_ -ne '' }) -split "`t")
    return [pscustomobject]@{ Tablas = [int]$p[0]; Vistas = [int]$p[1]; Rutinas = [int]$p[2] }
}

function Confirmar([string]$base) {
    if ($SinPreguntar) { return $true }
    Write-Host ''
    Write-Host "Se va a BORRAR la base local $base y reemplazarla por esta copia." -ForegroundColor Yellow
    $r = Read-Host 'Escribí "si" para continuar'
    return ($r -eq 'si')
}

$locales = @('localhost', '127.0.0.1', '::1')

$cnfOrigen = $null
$cnfCopia  = $null
try {

# ── Camino 2: levantar un respaldo que dejó la otra máquina ────────
if ($Desde -ne '') {
    $archivoGz = Resolve-Respaldo $Desde
    $copia = Get-Conexion (Join-Path $config 'local.propia.php')
    if (-not ($locales -contains $copia.Host.ToLower())) {
        throw "El perfil 'propia' apunta a $($copia.Host), que no es esta máquina. Restaurar ahí borraría una base ajena."
    }

    $item = Get-Item $archivoGz
    $edad = [math]::Round(((Get-Date) - $item.LastWriteTime).TotalHours, 1)
    Write-Host ''
    Write-Host "  Respaldo: $($item.Name)" -ForegroundColor Cyan
    Write-Host "            $([math]::Round($item.Length / 1MB, 1)) MB · generado hace $edad h ($($item.LastWriteTime))"
    Write-Host "  Destino : $($copia.Base) en $($copia.Host)  (se borra y se vuelve a crear)" -ForegroundColor Yellow

    # Un .gz a medio sincronizar se ve como un archivo normal hasta que falla
    # al descomprimir, y para entonces la base local ya está borrada.
    if ($item.Length -lt 1024) {
        throw "El archivo pesa $($item.Length) bytes: OneDrive todavía no terminó de bajarlo. Esperá a que se complete."
    }

    # El nombre del archivo lleva el equipo que lo generó. Si el más nuevo lo
    # hizo ESTA máquina, es el respaldo de la copia local —no del servidor— y
    # restaurarlo sería reciclar datos viejos creyendo que se actualizó.
    if ($item.Name -match [regex]::Escape("_$env:COMPUTERNAME`_")) {
        Write-Host ''
        Write-Host "  OJO: ese respaldo lo generó esta misma computadora ($env:COMPUTERNAME)," -ForegroundColor Yellow
        Write-Host '  así que es una copia de la copia, no del servidor. Si lo que querés es' -ForegroundColor Yellow
        Write-Host '  ponerte al día, pedí un respaldo nuevo en la máquina que tiene la base.' -ForegroundColor Yellow
    }

    if (-not (Confirmar $copia.Base)) {
        Write-Host 'Cancelado.' -ForegroundColor DarkGray
        exit 0
    }

    if (-not (Test-Path $destino)) { New-Item -ItemType Directory -Path $destino | Out-Null }
    $sql = Join-Path $destino ($item.BaseName)   # quita solo el .gz

    Write-Host ''
    Write-Host 'Descomprimiendo...' -ForegroundColor DarkGray
    Expand-Gz $item.FullName $sql
    Write-Host "  $([math]::Round((Get-Item $sql).Length / 1MB, 1)) MB" -ForegroundColor DarkGray

    $cnfCopia = New-Cnf $copia
    Write-Host 'Restaurando en la base local...' -ForegroundColor DarkGray
    Restore-Base $cnfCopia $copia $sql

    $r = Get-Resumen $cnfCopia $copia
    Write-Host ''
    Write-Host "  Tablas: $($r.Tablas)   Vistas: $($r.Vistas)   Procedimientos: $($r.Rutinas)" -ForegroundColor Green
    Write-Host ''
    Write-Host "El .sql descomprimido quedó en $sql (borralo si no lo necesitás)." -ForegroundColor DarkGray
    Write-Host 'Para trabajar sobre esta copia:  .\scripts\cambiar-base.ps1 propia' -ForegroundColor Cyan
    exit 0
}

# ── Camino 1: directo del servidor ─────────────────────────────────
$origen = Get-Conexion (Join-Path $config 'local.oficina.php')

# Solo cuando de verdad se va a restaurar: la máquina que hospeda la base
# respalda con -SoloRespaldo y no tiene por qué tener perfil 'propia'.
$copia = $null
if (-not $SoloRespaldo) { $copia = Get-Conexion (Join-Path $config 'local.propia.php') }

$origenEsLocal = $locales -contains $origen.Host.ToLower()

# En la máquina que HOSPEDA la base, el origen es local y no hay copia que
# refrescar —sería bajarse encima de uno mismo—, pero respaldar sí tiene todo
# el sentido: es justamente donde más falta hace.
if ($origenEsLocal -and -not $SoloRespaldo) {
    throw ("El perfil 'oficina' apunta a $($origen.Host): esta computadora ES la que hospeda la base. " +
           "Restaurar aquí sería bajarse una copia encima de sí misma.`n" +
           "Lo que sí corresponde en esta máquina:`n    .\scripts\copiar-base.ps1 -SoloRespaldo")
}
if (-not $SoloRespaldo -and -not ($locales -contains $copia.Host.ToLower())) {
    throw "El perfil 'propia' apunta a $($copia.Host), que no es esta máquina. Restaurar ahí borraría una base ajena."
}

Write-Host ''
Write-Host "  Origen : $($origen.Base) en $($origen.Host):$($origen.Puerto)" -ForegroundColor Cyan
if (-not $SoloRespaldo) {
    Write-Host "  Destino: $($copia.Base) en $($copia.Host)  (se borra y se vuelve a crear)" -ForegroundColor Yellow
}
Write-Host ''

    $cnfOrigen = New-Cnf $origen

    Write-Host 'Leyendo la lista de tablas del servidor...' -ForegroundColor DarkGray
    $tablas = Invoke-Sql $cnfOrigen $origen.Base @"
SELECT table_name FROM information_schema.tables
WHERE table_schema = '$($origen.Base)' AND table_type = 'BASE TABLE'
ORDER BY table_name
"@
    if ($LASTEXITCODE -ne 0) {
        # El 2002 de este montaje casi nunca es contraseña: es que Tailscale no
        # llegó a la otra máquina y el socket nunca se abrió.
        throw ("No se pudo conectar a $($origen.Host). Revisá que esa computadora esté encendida " +
               "y que Tailscale la vea:`n    tailscale status`n" +
               "Si aparece como 'offline', el problema está allá, no aquí.`n`n" +
               "Mientras tanto, el camino que no depende de la red:`n" +
               "  1. Que alguien apriete el botón en esa computadora " +
               "(Administración -> Diagnóstico -> Generar respaldo ahora).`n" +
               "  2. Cuando SharePoint lo sincronice:  .\scripts\copiar-base.ps1 -Desde ultimo")
    }
    $tablas = @($tablas | Where-Object { $_ -ne '' })
    if ($tablas.Count -eq 0) { throw "El servidor respondió pero $($origen.Base) no tiene ninguna tabla." }
    Write-Host "  $($tablas.Count) tablas" -ForegroundColor DarkGray

    # Conteo exacto en el origen, para comparar al final. table_rows de
    # information_schema es una estimación de InnoDB y llega a errar por miles.
    $union = ($tablas | ForEach-Object { "SELECT '$_' t, COUNT(*) n FROM ``$_``" }) -join ' UNION ALL '
    $filasOrigen = @{}
    Invoke-Sql $cnfOrigen $origen.Base $union | Where-Object { $_ -ne '' } | ForEach-Object {
        $p = $_ -split "`t"
        $filasOrigen[$p[0]] = [int64]$p[1]
    }
    $totalOrigen = ($filasOrigen.Values | Measure-Object -Sum).Sum
    Write-Host "  $totalOrigen filas" -ForegroundColor DarkGray

    if (-not (Test-Path $destino)) { New-Item -ItemType Directory -Path $destino | Out-Null }
    $archivo = Join-Path $destino ("bd_xmlconcilia_{0}.sql" -f (Get-Date -Format 'yyyyMMdd_HHmmss'))

    Write-Host ''
    Write-Host 'Exportando (puede tardar varios minutos)...' -ForegroundColor DarkGray
    $reloj = [diagnostics.stopwatch]::StartNew()
    $mysqldump = Get-MysqlBin 'mysqldump.exe'
    & $mysqldump "--defaults-extra-file=$cnfOrigen" `
        --single-transaction --quick --default-character-set=utf8mb4 `
        --add-drop-table --result-file="$archivo" $origen.Base @tablas
    if ($LASTEXITCODE -ne 0) { throw "mysqldump falló con código $LASTEXITCODE." }
    $reloj.Stop()

    $mb = [math]::Round((Get-Item $archivo).Length / 1MB, 1)
    Write-Host "  $(Split-Path -Leaf $archivo)  ($mb MB en $([math]::Round($reloj.Elapsed.TotalSeconds)) s)" -ForegroundColor Green

    if ($SoloRespaldo) {
        Write-Host ''
        Write-Host 'Listo (solo respaldo).' -ForegroundColor Green
        exit 0
    }

    if (-not (Confirmar $copia.Base)) {
        Write-Host "Cancelado. El respaldo quedó en $archivo" -ForegroundColor DarkGray
        exit 0
    }

    $cnfCopia = New-Cnf $copia

    Write-Host ''
    Write-Host 'Restaurando en la base local...' -ForegroundColor DarkGray
    Restore-Base $cnfCopia $copia $archivo

    Write-Host ''
    Write-Host 'Comparando con el origen...' -ForegroundColor DarkGray
    $filasCopia = @{}
    Invoke-Sql $cnfCopia $copia.Base $union | Where-Object { $_ -ne '' } | ForEach-Object {
        $p = $_ -split "`t"
        $filasCopia[$p[0]] = [int64]$p[1]
    }
    $totalCopia = ($filasCopia.Values | Measure-Object -Sum).Sum

    $difieren = @()
    foreach ($t in $tablas) {
        $a = $filasOrigen[$t]
        $b = 0
        if ($filasCopia.ContainsKey($t)) { $b = $filasCopia[$t] }
        if ($a -ne $b) { $difieren += "    $t : servidor $a / copia $b" }
    }

    $r = Get-Resumen $cnfCopia $copia

    Write-Host ''
    Write-Host "  Tablas : $($filasCopia.Count) / $($tablas.Count)"
    Write-Host "  Filas  : $totalCopia / $totalOrigen"
    Write-Host "  Vistas : $($r.Vistas)    Procedimientos: $($r.Rutinas)"
    if ($difieren.Count -gt 0) {
        Write-Host ''
        Write-Host '  No coinciden:' -ForegroundColor Yellow
        $difieren | ForEach-Object { Write-Host $_ -ForegroundColor Yellow }
        Write-Host '  (si alguien estaba trabajando durante el respaldo, es normal que difieran unas pocas filas)' -ForegroundColor DarkGray
    } else {
        Write-Host ''
        Write-Host '  Copia idéntica al servidor.' -ForegroundColor Green
    }

    $ocupado = [math]::Round((Get-ChildItem $destino -Filter *.sql | Measure-Object -Property Length -Sum).Sum / 1MB)
    Write-Host ''
    Write-Host "storage/backups ocupa $ocupado MB. Borrá a mano los respaldos viejos que ya no sirvan." -ForegroundColor DarkGray
    Write-Host ''
    Write-Host 'Para trabajar sobre esta copia:  .\scripts\cambiar-base.ps1 propia' -ForegroundColor Cyan
    Write-Host 'Para volver a la de la oficina:  .\scripts\cambiar-base.ps1 oficina' -ForegroundColor Cyan
}
catch {
    # Sin esto PowerShell escupe el rastro del error encima del mensaje y hay
    # que leer diez líneas para encontrar qué pasó.
    Write-Host ''
    Write-Host $_.Exception.Message -ForegroundColor Red
    Write-Host ''
    exit 1
}
finally {
    if ($null -ne $cnfOrigen) { Remove-Item $cnfOrigen -ErrorAction SilentlyContinue }
    if ($null -ne $cnfCopia)  { Remove-Item $cnfCopia  -ErrorAction SilentlyContinue }
}
