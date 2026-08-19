#Requires -RunAsAdministrator

<#
    Publica Nexo Fiscal en la red de la oficina, ademas de Tailscale.

    Por que existe: desde el corte, la aplicacion se usa por navegador contra
    esta computadora, y el unico camino era Tailscale. En una maquina que no
    puede instalar Tailscale --por Fortinet o por falta de permisos-- ese camino
    no existe. Si las dos computadoras comparten la red local, no hace falta:
    basta con que Apache escuche tambien en la IP de esa red.

    Lo que hace, y nada mas:
      1. Respalda la configuracion de Apache antes de tocarla.
      2. Agrega un `Listen <ip-local>:8484`. El de 127.0.0.1 se queda: es el
         que usa Tailscale Serve, y quitarlo dejaria sin acceso al resto.
      3. Abre el puerto en el firewall SOLO para la subred local.
      4. Reinicia Apache y comprueba que responde por la IP nueva.

    La base de datos no se toca: sigue escuchando unicamente en 127.0.0.1.

    Uso:
      .\publicar-en-red-local.ps1              # publica
      .\publicar-en-red-local.ps1 -Revertir    # deja todo como estaba
#>

[CmdletBinding()]
param(
    [string] $IpLocal = '',
    [int]    $Puerto = 8484,
    [switch] $Revertir
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$apacheService = 'XmlConciliaApache84'
$reglaFirewall = 'Nexo Fiscal - red local'

# La ruta de la configuracion se le pregunta al propio servicio y no se
# escribe aqui: en conf\ conviven varios httpd-xmlconcilia*.conf identicos y
# solo uno esta en uso. Editar el otro no da error, simplemente no hace nada.
$parametros = Get-ItemProperty "HKLM:\SYSTEM\CurrentControlSet\Services\$apacheService\Parameters" -ErrorAction SilentlyContinue
$argumentos = @($parametros.ConfigArgs)
$indiceF = [Array]::IndexOf($argumentos, '-f')
if ($indiceF -lt 0 -or $indiceF + 1 -ge $argumentos.Count) {
    throw "El servicio $apacheService no declara con que archivo arranca (-f). Revisalo antes de seguir."
}
$conf = $argumentos[$indiceF + 1]
if (-not (Test-Path -LiteralPath $conf -PathType Leaf)) {
    throw "El servicio $apacheService apunta a una configuracion que no existe: $conf"
}
Write-Host "Configuracion en uso: $conf" -ForegroundColor Cyan

# La IP de la red de oficina: la del adaptador por el que sale a internet. Se
# descarta Tailscale (100.64.0.0/10) para no publicar sobre el propio tunel.
if ([string]::IsNullOrWhiteSpace($IpLocal)) {
    $candidata = Get-NetIPAddress -AddressFamily IPv4 |
        Where-Object {
            $_.IPAddress -notlike '127.*' -and
            $_.IPAddress -notlike '169.254.*' -and
            $_.PrefixOrigin -ne 'WellKnown' -and
            -not ($_.IPAddress -match '^100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\.')
        } |
        Sort-Object -Property SkipAsSource, InterfaceMetric |
        Select-Object -First 1
    if (-not $candidata) { throw 'No se pudo determinar la IP de la red local. Pasala con -IpLocal.' }
    $IpLocal = $candidata.IPAddress
}
Write-Host "IP de la red local: $IpLocal" -ForegroundColor Cyan

$linea = "Listen ${IpLocal}:${Puerto}"
$contenido = Get-Content -LiteralPath $conf

if ($Revertir) {
    Write-Host '[1/3] Quitando el Listen de la red local...' -ForegroundColor Cyan
    $nuevo = $contenido | Where-Object { $_.Trim() -notmatch "^Listen\s+\d+(\.\d+){3}:$Puerto$" -or $_ -match '127\.0\.0\.1' }
    Set-Content -LiteralPath $conf -Value $nuevo -Encoding utf8

    Write-Host '[2/3] Quitando la regla del firewall...' -ForegroundColor Cyan
    Remove-NetFirewallRule -DisplayName $reglaFirewall -ErrorAction SilentlyContinue

    Write-Host '[3/3] Reiniciando Apache...' -ForegroundColor Cyan
    Restart-Service -Name $apacheService -Force
    Write-Host 'Listo: solo queda el acceso por Tailscale.' -ForegroundColor Green
    return
}

# ── 1. Respaldo ─────────────────────────────────────────────────────────────
$respaldo = "$conf.$(Get-Date -Format 'yyyyMMdd-HHmmss').bak"
Copy-Item -LiteralPath $conf -Destination $respaldo
Write-Host "[1/4] Respaldo: $respaldo" -ForegroundColor Cyan

# ── 2. Listen ───────────────────────────────────────────────────────────────
if ($contenido -contains $linea) {
    Write-Host "[2/4] Apache ya escucha en $IpLocal`:$Puerto." -ForegroundColor Yellow
} else {
    $indice = [Array]::FindIndex([string[]] $contenido, [Predicate[string]] {
        param($l) $l.Trim() -eq "Listen 127.0.0.1:$Puerto"
    })
    if ($indice -lt 0) { throw "No se encontro 'Listen 127.0.0.1:$Puerto' en $conf." }
    $contenido = $contenido[0..$indice] + $linea + $contenido[($indice + 1)..($contenido.Count - 1)]
    Set-Content -LiteralPath $conf -Value $contenido -Encoding utf8
    Write-Host "[2/4] Agregado: $linea" -ForegroundColor Cyan
}

# ── 3. Firewall, acotado a la subred ────────────────────────────────────────
$subred = ($IpLocal -replace '\.\d+$', '.0/24')
Remove-NetFirewallRule -DisplayName $reglaFirewall -ErrorAction SilentlyContinue
New-NetFirewallRule -DisplayName $reglaFirewall -Direction Inbound -Action Allow `
    -Protocol TCP -LocalPort $Puerto -RemoteAddress $subred -Profile Any | Out-Null
Write-Host "[3/4] Firewall abierto en $Puerto solo para $subred" -ForegroundColor Cyan

# ── 4. Reinicio y comprobacion ──────────────────────────────────────────────
Write-Host '[4/4] Reiniciando Apache y comprobando...' -ForegroundColor Cyan
Restart-Service -Name $apacheService -Force
Start-Sleep -Seconds 2

$escuchando = @(Get-NetTCPConnection -State Listen -LocalPort $Puerto -ErrorAction SilentlyContinue |
    Where-Object { $_.LocalAddress -eq $IpLocal }).Count -gt 0
if (-not $escuchando) {
    throw "Apache reinicio pero no esta escuchando en ${IpLocal}:${Puerto}. Revisa $conf (respaldo en $respaldo)."
}

$url = "http://${IpLocal}:${Puerto}"
try {
    $respuesta = Invoke-WebRequest -Uri $url -UseBasicParsing -TimeoutSec 15 -MaximumRedirection 5
    Write-Host "Responde con codigo $($respuesta.StatusCode)." -ForegroundColor Green
} catch {
    throw "Apache escucha pero la aplicacion no respondio en ${url}: $($_.Exception.Message)"
}

Write-Host ''
Write-Host 'Listo. Desde la computadora de Sofia, en el navegador:' -ForegroundColor Green
Write-Host "    $url" -ForegroundColor White
Write-Host ''
Write-Host 'El acceso por Tailscale sigue igual. Para deshacer:' -ForegroundColor Gray
Write-Host '    .\publicar-en-red-local.ps1 -Revertir' -ForegroundColor Gray
