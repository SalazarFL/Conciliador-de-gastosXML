<#
    Cambia a qué base de datos apunta ESTA computadora.

        .\scripts\cambiar-base.ps1              # dice cuál está activa
        .\scripts\cambiar-base.ps1 oficina      # la base compartida (la de verdad)
        .\scripts\cambiar-base.ps1 propia       # la copia local de esta máquina

    La aplicación lee siempre app/config/local.php. Este script no edita ese
    archivo: copia encima uno de los perfiles (local.oficina.php /
    local.propia.php), que es lo mismo que se haría a mano pero sin
    equivocarse de línea.

    Editar local.php a mano sigue funcionando; lo que este script agrega es
    poder ir y volver sin reescribir la contraseña, y decir en voz alta sobre
    cuál base se está trabajando — que es el error caro de este montaje.
#>

param(
    [ValidateSet('oficina', 'propia', '')]
    [string] $Perfil = ''
)

$ErrorActionPreference = 'Stop'

$raiz    = Split-Path -Parent $PSScriptRoot
$config  = Join-Path $raiz 'app\config'
$activo  = Join-Path $config 'local.php'
$perfiles = [ordered]@{
    'oficina' = Join-Path $config 'local.oficina.php'
    'propia'  = Join-Path $config 'local.propia.php'
}

function Get-PhpExe {
    $cmd = Get-Command php -ErrorAction SilentlyContinue
    if ($null -ne $cmd) { return $cmd.Source }
    if (Test-Path 'C:\xampp\php\php.exe') { return 'C:\xampp\php\php.exe' }
    throw 'No se encontró php.exe. Agregá C:\xampp\php al PATH.'
}

# El host que la aplicación va a usar de verdad, leído como lo lee ella.
function Get-HostActivo {
    if (-not (Test-Path $activo)) { return $null }
    $php = Get-PhpExe
    # Comillas simples dentro del código PHP a propósito: PowerShell 5.1 se
    # come las dobles al pasar argumentos a un .exe y PHP recibe basura.
    $codigo = '$c = require getenv(''XMLC_LOCAL''); echo $c[''database''][''host''] . '':'' . $c[''database''][''port''];'
    $env:XMLC_LOCAL = $activo
    try { return (& $php -r $codigo) } finally { Remove-Item Env:\XMLC_LOCAL }
}

# Qué perfil es el que está copiado, comparando contenido.
function Get-PerfilActivo {
    if (-not (Test-Path $activo)) { return $null }
    $suyo = (Get-FileHash $activo -Algorithm SHA256).Hash
    foreach ($nombre in $perfiles.Keys) {
        if (-not (Test-Path $perfiles[$nombre])) { continue }
        if ((Get-FileHash $perfiles[$nombre] -Algorithm SHA256).Hash -eq $suyo) { return $nombre }
    }
    return 'a mano'   # local.php editado directamente, sin coincidir con ningún perfil
}

function Show-Estado {
    $nombre = Get-PerfilActivo
    if ($null -eq $nombre) {
        Write-Host 'No existe app/config/local.php: esta computadora no sabe a qué base conectarse.' -ForegroundColor Red
        Write-Host 'Corré:  .\scripts\cambiar-base.ps1 oficina'
        return
    }
    $donde = Get-HostActivo
    $color = 'Green'
    if ($nombre -ne 'oficina') { $color = 'Yellow' }
    Write-Host ''
    Write-Host "  Base activa: $nombre  ($donde)" -ForegroundColor $color
    if ($nombre -eq 'propia') {
        Write-Host '  Es la copia de esta máquina. Lo que trabajes aquí no lo ve nadie más' -ForegroundColor Yellow
        Write-Host '  y se pierde en el próximo copiar-base.ps1.' -ForegroundColor Yellow
    }
    if ($nombre -eq 'a mano') {
        Write-Host '  local.php no coincide con ningún perfil: alguien lo editó directamente.' -ForegroundColor Yellow
    }
    Write-Host ''
}

if ($Perfil -eq '') { Show-Estado; exit 0 }

$origen = $perfiles[$Perfil]
if (-not (Test-Path $origen)) {
    Write-Host "Falta $origen." -ForegroundColor Red
    Write-Host 'Copiá app/config/local.ejemplo.php con ese nombre y llenálo.'
    exit 1
}

# Si local.php estaba editado a mano, guardarlo antes de pisarlo: puede tener
# una ruta de pdftotext o una contraseña que no esté en ningún perfil.
if ((Test-Path $activo) -and ((Get-PerfilActivo) -eq 'a mano')) {
    $respaldo = Join-Path $config ('local.php.antes-' + (Get-Date -Format 'yyyyMMdd_HHmmss'))
    Copy-Item $activo $respaldo
    Write-Host "local.php estaba editado a mano; se guardó una copia en $(Split-Path -Leaf $respaldo)" -ForegroundColor Yellow
}

Copy-Item $origen $activo -Force
Show-Estado

$php = Get-PhpExe
Write-Host 'Comprobando la conexión...' -ForegroundColor DarkGray
Push-Location $raiz
try { & $php 'cli/diagnostico.php' } finally { Pop-Location }
