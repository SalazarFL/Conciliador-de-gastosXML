<#
    Cuánta memoria le da MariaDB al índice del buzón, y cómo cambiarla.

        .\scripts\memoria-base-datos.ps1              # dice cómo está y qué convendría
        .\scripts\memoria-base-datos.ps1 -Aplicar     # la deja en la medida recomendada
        .\scripts\memoria-base-datos.ps1 -Aplicar -GB 4

    Por que existe: MariaDB guarda en memoria las partes de las tablas que va
    usando, y mientras el indice del buzon quepa ahi, buscar una factura es
    cosa de milisegundos. Cuando deja de caber, cada busqueda empieza a leer
    del disco y no se degrada de a poco: cae de golpe.

    Hoy esa memoria esta en 256 MB, que es el valor de fabrica, y el indice
    pesa unos 116 MB con tres buzones. Cada buzon nuevo suma unos 39 MB, asi
    que alrededor del sexto deja de caber. Con 17 sociedades el indice pide
    1,7 GB.

    El valor no se toca solo: cambiarlo obliga a reiniciar MariaDB, y eso deja
    el sistema fuera de servicio unos segundos. Por eso hay que pedirlo.

    Sin -Aplicar solo informa.
#>

param(
    [switch]$Aplicar,
    [double]$GB = 0
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$ini      = 'C:\WebServer\MariaDB114\my-xmlconcilia.ini'
$servicio = 'XmlConciliaMariaDB114'

if (-not (Test-Path $ini)) {
    Write-Output "No se encontro la configuracion de MariaDB en $ini."
    Write-Output "Si esta instalacion la tiene en otro sitio, ajusta la ruta arriba."
    exit 1
}

# ── Como esta hoy ─────────────────────────────────────────────────────────

$contenido = Get-Content $ini -Raw
$actual = $null
if ($contenido -match '(?im)^\s*innodb_buffer_pool_size\s*=\s*(\S+)\s*$') {
    $actual = $Matches[1]
}

$ram = [math]::Round((Get-CimInstance Win32_ComputerSystem).TotalPhysicalMemory / 1GB, 1)

Write-Output ""
Write-Output "== Como esta =="
Write-Output ("  memoria de MariaDB : " + $(if ($actual) { $actual } else { "sin definir (usa el valor de fabrica, 128M)" }))
Write-Output "  RAM de la maquina  : $ram GB"

# ── Cuanto conviene ───────────────────────────────────────────────────────
#
# La regla: que el indice quepa entero con holgura, sin quitarle a Windows y a
# PHP lo que necesitan. Un cuarto de la RAM es conservador y funciona bien en
# una maquina que ademas sirve la aplicacion; en un servidor dedicado se puede
# subir mas, pero pasarse hace que Windows empiece a usar el archivo de
# paginacion, que es exactamente lo que se queria evitar.

$recomendado = [math]::Max(1, [math]::Floor($ram / 4))
if ($GB -gt 0) { $recomendado = $GB }

Write-Output ""
Write-Output "== Que conviene =="
Write-Output "  recomendado : $recomendado GB"
Write-Output "  alcanza para: aproximadamente $([math]::Floor($recomendado * 1024 / 39)) buzones"
Write-Output "                (el indice crece unos 39 MB por buzon, con 2 anos de retencion)"

if (-not $Aplicar) {
    Write-Output ""
    Write-Output "Informe solamente. Volve a correrlo con -Aplicar para cambiarlo."
    Write-Output "Ojo: aplicar reinicia MariaDB y el sistema queda fuera unos segundos."
    Write-Output ""
    exit 0
}

# ── Aplicar ───────────────────────────────────────────────────────────────

$respaldo = "$ini.$(Get-Date -Format 'yyyyMMdd_HHmmss').bak"
Copy-Item $ini $respaldo
Write-Output ""
Write-Output "== Aplicando =="
Write-Output "  respaldo de la configuracion: $respaldo"

$nuevo = "${recomendado}G"
if ($contenido -match '(?im)^\s*innodb_buffer_pool_size\s*=') {
    $contenido = $contenido -replace '(?im)^\s*innodb_buffer_pool_size\s*=.*$', "innodb_buffer_pool_size=$nuevo"
} else {
    $contenido = $contenido -replace '(?im)^\s*\[mysqld\]\s*$', "[mysqld]`r`ninnodb_buffer_pool_size=$nuevo"
}
Set-Content -Path $ini -Value $contenido -Encoding utf8
Write-Output "  innodb_buffer_pool_size = $nuevo"

Write-Output "  reiniciando $servicio..."
Restart-Service -Name $servicio -Force
Start-Sleep -Seconds 3

$estado = (Get-Service -Name $servicio).Status
Write-Output "  servicio: $estado"

if ($estado -ne 'Running') {
    Write-Output ""
    Write-Output "MariaDB no arranco. Se restaura la configuracion anterior:"
    Copy-Item $respaldo $ini -Force
    Restart-Service -Name $servicio -Force
    Write-Output "  restaurada. Revisa el log de MariaDB antes de volver a intentarlo."
    exit 1
}

Write-Output ""
Write-Output "Listo. Comprobalo con:"
Write-Output "  SHOW VARIABLES LIKE 'innodb_buffer_pool_size';"
Write-Output ""
