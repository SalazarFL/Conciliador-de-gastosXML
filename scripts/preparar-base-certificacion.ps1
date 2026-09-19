<#
    Deja la base de certificación lista para correr las pruebas.

        .\scripts\preparar-base-certificacion.ps1
        .\scripts\preparar-base-certificacion.ps1 -SinPreguntar   # para tareas

    Qué hace: le copia a `xmlconcilia_php84_cert` el ESQUEMA de la copia local
    (sin datos) y los CATÁLOGOS mínimos que las pruebas necesitan para no
    saltarse solas. No copia datos de trabajo: facturas, comprobantes, correo y
    seguimiento quedan vacíos a propósito, porque cada prueba crea y borra lo
    suyo.

    ---------------------------------------------------------------------------
    POR QUÉ EXISTE ESTE SCRIPT

    La certificación llevaba tiempo saliendo "toda verde" sin haber tocado la
    base ni una vez. Dos fallas encadenadas, y ninguna avisaba:

    1. `local.php84-cert.php` entraba como `root`. El root de MariaDB en Windows
       usa autenticación de Windows (`auth_gssapi`), que el cliente de PHP no
       sabe hablar, así que la conexión moría con:

           SQLSTATE[HY000] [2054] The server requested authentication method
           unknown to the client [auth_gssapi_client]

    2. Cada prueba con base traduce un fallo de conexión a `SKIP` y sale con
       código 0. Con las dos juntas, ~20 pruebas se marcaban "no revisado" y el
       resumen final decía OK.

       Verde por no haber mirado. Es peor que rojo: rojo se arregla, verde se
       archiva.

    El arreglo del usuario va en `local.php84-cert.php`, que NO se versiona
    (lleva contraseña y el repositorio es público). Por eso está aquí abajo
    escrito: si esta máquina se reinstala o llega otra, hay que rehacerlo a
    mano. `xmlconcilia` ya tiene ALL PRIVILEGES sobre `xmlconcilia_php84_cert`,
    así que NO hace falta crear ni reparar ningún usuario de MariaDB:

        'username' => 'xmlconcilia',        // NO 'root'
        'password' => <la misma de local.propia.php>,

    Comprobar que quedó bien:

        $env:XMLCONCILIA_CONFIG_FILE = "$PWD\app\config\local.php84-cert.php"
        Get-ChildItem tests\*Test.php | ForEach-Object { & php $_.FullName }

    Si ves una sola línea que empiece con SKIP, la base no está conectando y la
    certificación no vale: arréglalo antes de mirar cualquier otra cosa.
    ---------------------------------------------------------------------------
#>
[CmdletBinding()]
param(
    [switch] $SinPreguntar
)

$ErrorActionPreference = 'Stop'

$raiz    = Split-Path -Parent $PSScriptRoot
$perfil  = Join-Path $raiz 'app\config\local.propia.php'
$destino = 'xmlconcilia_php84_cert'

$mysql     = 'C:\WebServer\MariaDB114\bin\mysql.exe'
$mysqldump = 'C:\WebServer\MariaDB114\bin\mysqldump.exe'
foreach ($exe in @($mysql, $mysqldump)) {
    if (-not (Test-Path $exe)) { throw "No encuentro $exe. Ajusta la ruta de MariaDB en este script." }
}

# Los datos de conexión salen del perfil "propia", que es el que apunta a la
# copia local. Se leen con PHP para no reimplementar aquí el formato del array.
$php = 'C:\WebServer\PHP84\php.exe'
if (-not (Test-Path $php)) { $php = 'php' }
$lectura = & $php -r "`$c = require '$($perfil -replace '\\','/')'; `$d = `$c['database']; echo `$d['host'],'|',`$d['port'],'|',`$d['database'],'|',`$d['username'],'|',`$d['password'];"
$h, $puerto, $origen, $usuario, $clave = $lectura -split '\|'

Write-Host ""
Write-Host "  origen  : $origen (esquema y catálogos)" -ForegroundColor Cyan
Write-Host "  destino : $destino (se BORRA y se rehace)" -ForegroundColor Yellow
Write-Host ""

if (-not $SinPreguntar) {
    $r = Read-Host "Se borran todas las tablas de $destino. Escribe SI para continuar"
    if ($r -ne 'SI') { Write-Host 'Cancelado.'; exit 0 }
}

$tmp = Join-Path $env:TEMP "cert-$(Get-Date -Format yyyyMMdd-HHmmss)"
New-Item -ItemType Directory -Path $tmp | Out-Null

# 1. Respaldo de lo que había, por si alguien guardó algo ahí sin decirlo.
$respaldo = Join-Path $tmp 'antes.sql'
& $mysqldump -h $h -P $puerto -u $usuario "-p$clave" --databases $destino --result-file=$respaldo
Write-Host "  respaldo de lo anterior: $respaldo" -ForegroundColor DarkGray

# 2. Esquema de la copia local, sin datos.
$esquema = Join-Path $tmp 'esquema.sql'
& $mysqldump -h $h -P $puerto -u $usuario "-p$clave" --no-data --skip-add-drop-table $origen --result-file=$esquema

# 3. Catálogos: lo mínimo para que ninguna prueba se salte por falta de datos.
#    sociedades  -> SociedadPorUsuario, SociedadAislamiento, CorreoLoteWorker
#    proveedores -> DocumentosFueraDeLaBase, SociedadAislamiento
#    correo_cuentas -> CorreoLoteWorker, CorreoLoteSaltaProcesados
$catalogos = Join-Path $tmp 'catalogos.sql'
& $mysqldump -h $h -P $puerto -u $usuario "-p$clave" --no-create-info --complete-insert `
    $origen sociedades proveedores roles rol_permisos correo_cuenta_sociedades --result-file=$catalogos
$cuentas = Join-Path $tmp 'cuentas.sql'
& $mysqldump -h $h -P $puerto -u $usuario "-p$clave" --no-create-info --complete-insert `
    $origen correo_cuentas --result-file=$cuentas

# 4. Vaciar el destino y recargarlo.
#    Las sentencias DROP se arman aquí y no con GROUP_CONCAT en SQL: el acento
#    invertido es el carácter de escape de PowerShell y dentro de un here-string
#    llega a mysql como barra invertida, que lo rechaza.
$tablas = & $mysql -h $h -P $puerto -u $usuario "-p$clave" -N -B -e `
    "SELECT table_name FROM information_schema.tables WHERE table_schema = '$destino'"

$lineas = @('SET FOREIGN_KEY_CHECKS=0;')
foreach ($t in $tablas) {
    if ([string]::IsNullOrWhiteSpace($t)) { continue }
    $lineas += ('DROP TABLE IF EXISTS `{0}`;' -f $t.Trim())
}
$lineas += 'SET FOREIGN_KEY_CHECKS=1;'

$borrarArchivo = Join-Path $tmp 'borrar.sql'
[System.IO.File]::WriteAllText($borrarArchivo, ($lineas -join "`n"), [System.Text.UTF8Encoding]::new($false))

& $mysql -h $h -P $puerto -u $usuario "-p$clave" $destino -e "source $($borrarArchivo -replace '\\','/')"
& $mysql -h $h -P $puerto -u $usuario "-p$clave" $destino -e "source $($esquema -replace '\\','/')"
& $mysql -h $h -P $puerto -u $usuario "-p$clave" $destino -e "source $($catalogos -replace '\\','/')"
& $mysql -h $h -P $puerto -u $usuario "-p$clave" $destino -e "source $($cuentas -replace '\\','/')"

# 5. Decir en qué quedó, que es lo único que importa.
$cuenta = & $mysql -h $h -P $puerto -u $usuario "-p$clave" -N -B -e @"
SELECT CONCAT(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$destino'), ' tablas, ',
  (SELECT COUNT(*) FROM $destino.sociedades),  ' sociedades, ',
  (SELECT COUNT(*) FROM $destino.proveedores), ' proveedores, ',
  (SELECT COUNT(*) FROM $destino.correo_cuentas), ' cuentas de correo');
"@

Write-Host ""
Write-Host "  $destino listo: $cuenta" -ForegroundColor Green
Write-Host ""
Write-Host "  Ahora la certificación:" -ForegroundColor Cyan
Write-Host "    `$env:XMLCONCILIA_CONFIG_FILE = `"$raiz\app\config\local.php84-cert.php`""
Write-Host "    Get-ChildItem tests\*Test.php | ForEach-Object { & php `$_.FullName }"
Write-Host ""
Write-Host "  Una sola línea SKIP significa que la base no conecta y que la" -ForegroundColor Yellow
Write-Host "  certificación no vale. Ver la cabecera de este script." -ForegroundColor Yellow
Write-Host ""
