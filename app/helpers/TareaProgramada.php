<?php
/**
 * Con qué cuenta corren las tareas programadas de Windows.
 *
 * Se registraban "como el usuario con sesión abierta" (LogonType
 * Interactive): corrían solo mientras esa persona tuviera la sesión iniciada.
 * En una PC de oficina pasaba desapercibido; en un servidor nadie deja la
 * sesión abierta, y tras un reinicio el correo dejaba de bajar sin que nada
 * lo dijera.
 *
 * Ahora corren con la misma cuenta que el servidor web, haya o no alguien
 * conectado. Es la cuenta que ya lee todo lo que las tareas leen —la clave
 * de los buzones, storage/, la configuración— así que no aparece un permiso
 * nuevo que pueda faltar. Quien registra la tarea ES el servidor web (el PHP
 * de Apache lanza el PowerShell), por eso basta con preguntar quién soy.
 *
 *   SYSTEM / LocalService / NetworkService  -> ServiceAccount, sin contraseña.
 *   Una cuenta común (usuario de servicio)  -> S4U: sin contraseña guardada y
 *                                              sin sesión; no tiene credenciales
 *                                              para carpetas compartidas de red,
 *                                              que el sistema ya no usa (IMAP,
 *                                              SharePoint y la base van por TCP).
 *
 * Qué clase de cuenta es se decide por el SID, no por el nombre: en un
 * Windows en otro idioma "NT AUTHORITY\SYSTEM" puede venir traducido. Pero a
 * la tarea se le pasa el nombre tal como lo da Windows en esta máquina, que
 * es el que el Programador sabe resolver.
 */
class TareaProgramada
{
    /**
     * Fragmento de PowerShell que deja en $principal la cuenta de la tarea.
     * Sin acentos: los .ps1 corren en PowerShell 5.1.
     */
    public static function principalPowerShell()
    {
        return <<<'POWERSHELL'
# Misma cuenta que el servidor web, haya o no alguien con sesion abierta.
$yo = [System.Security.Principal.WindowsIdentity]::GetCurrent()
if (@('S-1-5-18', 'S-1-5-19', 'S-1-5-20') -contains $yo.User.Value) {
    $principal = New-ScheduledTaskPrincipal -UserId $yo.Name -LogonType ServiceAccount -RunLevel Highest
} else {
    $principal = New-ScheduledTaskPrincipal -UserId $yo.Name -LogonType S4U -RunLevel Limited
}
POWERSHELL;
    }
}
