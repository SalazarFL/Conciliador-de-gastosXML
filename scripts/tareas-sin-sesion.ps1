#Requires -RunAsAdministrator
<#
    Deja las tareas programadas de Nexo Fiscal corriendo sin nadie conectado.

    Las tareas se registraban "como el usuario con sesion abierta": en un
    servidor, sin nadie conectado o despues de un reinicio, el correo dejaba
    de bajar y los lotes se quedaban a medias sin que nada lo avisara.

    Este script pasa cada tarea XMLConcilia_* a la cuenta del servicio de
    Apache - la misma con la que corre la aplicacion, que ya lee todo lo que las
    tareas leen - con la opcion "ejecutar tanto si el usuario inicio sesion
    como si no". Desde ahora la aplicacion ya las registra asi sola
    (app/helpers/TareaProgramada.php); esto es para las que ya existian.

    Si no existe XMLConcilia_ProcesarLotes, la crea: ninguna pantalla la
    registra y sin ella los lotes de Correo solo avanzan con la pagina abierta.

    XMLConcilia_SelectorCarpeta no se toca: abre una ventana en el escritorio
    y solo tiene sentido con alguien sentado frente a la pantalla.

    Uso (PowerShell como administrador):
        .\scripts\tareas-sin-sesion.ps1
        .\scripts\tareas-sin-sesion.ps1 -Servicio Apache2.4
#>
[CmdletBinding()]
param(
    [string]$Servicio = 'XmlConciliaApache84',
    [int]$MinutosLotes = 10
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$raiz = Split-Path -Parent $PSScriptRoot

$svc = Get-CimInstance Win32_Service -Filter "Name='$Servicio'"
if (-not $svc) {
    throw "No existe el servicio '$Servicio'. Indica el de Apache con -Servicio."
}
$cuenta = [string]$svc.StartName
Write-Output "Apache ($Servicio) corre como: $cuenta"

# Las cuentas de servicio integradas no tienen contrasena: van como
# ServiceAccount. Cualquier otra, como S4U (sin contrasena guardada y sin
# credenciales de red, que el sistema no usa: IMAP, SharePoint y la base van
# por TCP).
function Nuevo-Principal([string]$Cuenta) {
    switch -Regex ($Cuenta) {
        '^(LocalSystem|.*\\SYSTEM)$' {
            return New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
        }
        '^NT AUTHORITY\\(LocalService|LOCAL SERVICE)$' {
            return New-ScheduledTaskPrincipal -UserId 'LOCAL SERVICE' -LogonType ServiceAccount -RunLevel Limited
        }
        '^NT AUTHORITY\\(NetworkService|NETWORK SERVICE)$' {
            return New-ScheduledTaskPrincipal -UserId 'NETWORK SERVICE' -LogonType ServiceAccount -RunLevel Limited
        }
        default {
            $usuario = $Cuenta -replace '^\.\\', "$env:COMPUTERNAME\"
            return New-ScheduledTaskPrincipal -UserId $usuario -LogonType S4U -RunLevel Limited
        }
    }
}
$principal = Nuevo-Principal $cuenta

# ProcesarLotes: se crea igual que la que existia a mano en la PC de desarrollo.
if (-not (Get-ScheduledTask -TaskName 'XMLConcilia_ProcesarLotes' -ErrorAction SilentlyContinue)) {
    $php = 'C:\WebServer\PHP84\php.exe'
    $script = Join-Path $raiz 'cli\procesar_lotes.php'
    if (-not (Test-Path -LiteralPath $php)) { throw "No se encontro $php" }
    $dirVbs = Join-Path $raiz 'storage\correo'
    New-Item -ItemType Directory -Force -Path $dirVbs | Out-Null
    $vbs = Join-Path $dirVbs 'lotes_launch.vbs'
    $tope = [Math]::Max(30, $MinutosLotes * 60 - 60)
    $linea = 'CreateObject("WScript.Shell").Run """' + $php + '"" ""' + $script + '"" ' + $tope + '", 0, False'
    Set-Content -LiteralPath $vbs -Value $linea -Encoding ASCII

    $accion = New-ScheduledTaskAction -Execute 'wscript.exe' -Argument ('//B //Nologo "' + $vbs + '"')
    $t = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1)
    $r = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes $MinutosLotes)
    $t.Repetition = $r.Repetition
    $ajustes = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 30)
    Register-ScheduledTask -TaskName 'XMLConcilia_ProcesarLotes' -Action $accion -Trigger $t -Principal $principal -Settings $ajustes | Out-Null
    Write-Output "  creada   XMLConcilia_ProcesarLotes (cada $MinutosLotes min)"
}

$tareas = @(Get-ScheduledTask -TaskName 'XMLConcilia_*' -ErrorAction SilentlyContinue |
    Where-Object { $_.TaskName -ne 'XMLConcilia_SelectorCarpeta' })
if (-not $tareas) {
    Write-Output 'No hay tareas XMLConcilia_* registradas. Activa la sincronizacion desde Correo y vuelve a correr esto.'
    return
}

foreach ($tarea in $tareas) {
    Set-ScheduledTask -TaskName $tarea.TaskName -TaskPath $tarea.TaskPath -Principal $principal | Out-Null
    $p = (Get-ScheduledTask -TaskName $tarea.TaskName -TaskPath $tarea.TaskPath).Principal
    Write-Output ("  ajustada {0}  ->  {1} ({2})" -f $tarea.TaskName, $p.UserId, $p.LogonType)
}

Write-Output ''
Write-Output 'Listo. Las tareas corren aunque nadie tenga la sesion iniciada.'
if ($principal.LogonType -eq 'S4U') {
    Write-Output "Ojo: la cuenta $cuenta necesita el derecho 'Iniciar sesion como proceso por lotes'"
    Write-Output "y permiso de escritura en $raiz\storage."
}
