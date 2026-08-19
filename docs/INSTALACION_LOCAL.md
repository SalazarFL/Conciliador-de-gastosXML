# Instalación autónoma en una computadora suelta

Para una máquina que **no puede llegar al servidor**: sin Tailscale, sin red
compartida, sin XAMPP. Queda con su propio servidor web y su propia base, y los
datos entran por un respaldo.

Es el caso de una computadora bloqueada por un filtro corporativo, o de un
puesto de pruebas.

> **Esto no es un segundo servidor.** Lo que se trabaje aquí **no llega a
> nadie** y se pierde entero la próxima vez que se refresque la copia. Sirve
> para probar, para consultar y para capacitar. Capturar correo o cerrar un
> pago semanal se hace siempre contra la base de la oficina.

Para el modelo normal —una base compartida y varias computadoras— ver
[`INSTALACION.md`](INSTALACION.md).

---

## Antes de ir a la otra computadora

Lo que hay que llevar en una llave USB. **No lo descargues por el hotspot**:
son ~420 MB y sale más caro en datos que en tiempo.

1. **La carpeta `C:\WebServer` completa, menos `Data\` y `logs\`.**

   Copiarla en vez de descargar cada componente tiene tres ventajas que no son
   comodidad: las versiones quedan idénticas a las del servidor (PHP 8.4.24
   NTS, Apache 2.4.68, MariaDB 11.4.12), las rutas de los `.conf` y los `.ini`
   apuntan a `C:/WebServer` y `C:/WebApps/xmlconcilia` —que van a ser las
   mismas allá—, así que no hay que editar ni una línea; y sobre todo **ya trae
   `php_imap.dll`**, que en PHP 8.4 no viene incluida y hay que sacarla de PECL
   con la variante exacta (8.4, x64, NTS). Es la parte más molesta de montar
   este stack desde cero.

   `Data\` no se copia porque son los archivos vivos de la base: copiarlos con
   el servicio encendido produce una base corrupta. `logs\` no sirve de nada.

2. **La carpeta `C:\tools\poppler-25.12.0`** (55 MB), que es de donde sale
   `pdftotext`. No está dentro de `C:\WebServer`, así que no viaja con la copia,
   y sin ella no se pueden leer los reportes PDF del ERP. Va en la misma ruta
   en la máquina nueva.

3. **Un respaldo reciente.** En esta computadora: *Administración →
   Diagnóstico → Generar respaldo ahora*, o `php cli/respaldar_base.php`. Deja
   un `.sql.gz` de unos 12 MB en `_TRABAJO/RESPALDOS`.

4. **La contraseña de `xmlconcilia`** y el archivo
   `C:\WebServer\secrets\mariadb114-root-client.ini`, que va dentro de la copia
   del punto 1. Son contraseñas: la llave USB no se presta y esos archivos no
   se suben a SharePoint.

---

## En la computadora nueva

Todo lo que sigue es en **PowerShell como administrador**, salvo donde diga
otra cosa.

### 1. El stack

Pegar la carpeta en `C:\WebServer` y crear las carpetas que no se copiaron:

```powershell
New-Item -ItemType Directory -Force C:\WebServer\Data\MariaDB114,
    C:\WebServer\Data\import-export, C:\WebServer\logs, C:\WebServer\tmp | Out-Null
```

### 2. Git y el proyecto

Instalar Git desde <https://git-scm.com/download/win> con las opciones por
defecto —esto sí por el hotspot, son unos 60 MB—. **Cerrar y volver a abrir
PowerShell** para que tome el PATH.

```powershell
New-Item -ItemType Directory -Force C:\WebApps | Out-Null
cd C:\WebApps
git clone -b trabajo-multisociedad-y-rutas-compartidas https://github.com/SalazarFL/Conciliador-de-gastosXML.git xmlconcilia
```

El `-b <rama>` no es opcional: sin él se descarga `main`, que va meses atrás.
Confirmá con quien administra el sistema cuál es la rama vigente.

### 3. El puerto

**Antes de crear la base**, cambiar el puerto de 3306 a **3307** en los dos
archivos que vinieron en la copia:

- `C:\WebServer\MariaDB114\my-xmlconcilia.ini` — tiene **dos** líneas
  `port=3306`, en `[client]` y en `[mariadb]`; hay que cambiar las dos.
- `C:\WebServer\secrets\mariadb114-root-client.ini` — una sola línea.

No es un capricho. Windows reserva rangos de puertos para Hyper-V, WSL y Docker,
y el 3306 suele caer adentro: MariaDB arranca, intenta abrirlo y muere con
`error 10013: Intento de acceso a un socket no permitido`, que no significa
"está ocupado" sino "no se te permite". Además `instalar-servicios-migracion.ps1`
—el del paso 8— exige el 3307 y aborta si no lo ve.

Comprobar que el 3307 esté libre en esta máquina:

```powershell
netsh int ipv4 show excludedportrange protocol=tcp
```

Si el 3307 cae dentro de algún rango de la tabla, elegir otro (3308, 3309) y
usarlo en todos los pasos que siguen, incluido `local.php`.

### 4. La base, vacía

Primero el directorio de datos:

```powershell
& 'C:\WebServer\MariaDB114\bin\mariadb-install-db.exe' `
    --datadir=C:\WebServer\Data\MariaDB114 `
    --config=C:\WebServer\MariaDB114\my-xmlconcilia.ini `
    --port=3307 `
    --password='LA_CONTRASEÑA_ROOT'
```

`LA_CONTRASEÑA_ROOT` es la que está en `C:\WebServer\secrets\mariadb114-root.txt`;
va entre comillas simples por si trae `$`. Usar la misma que en el servidor hace
que el `mariadb114-root-client.ini` de la copia sirva tal cual.

Y aparte el servicio. **Sin `--service` en el comando de arriba**: esa opción
registra el servicio contra un `my.ini` que el instalador escribe en el
directorio de datos, con lo cual `my-xmlconcilia.ini` —y el cambio de puerto del
paso 3— quedan ignorados.

```powershell
& 'C:\WebServer\MariaDB114\bin\mariadbd.exe' --install XmlConciliaMariaDB114 `
    --defaults-file=C:\WebServer\MariaDB114\my-xmlconcilia.ini

Start-Service XmlConciliaMariaDB114
Get-NetTCPConnection -LocalPort 3307 -State Listen
```

La última línea tiene que mostrar `127.0.0.1` y `3307`.

Si el servicio ya se había registrado con `--service`, hay que borrarlo antes
con `sc.exe delete XmlConciliaMariaDB114` y volver a registrarlo así. Borrar el
servicio no toca los datos.

### 5. La base y su usuario

Con la misma contraseña de `xmlconcilia` que en el servidor: así un respaldo se
restaura en cualquiera de las dos máquinas sin tocar nada.

```powershell
& 'C:\WebServer\MariaDB114\bin\mariadb.exe' `
    --defaults-extra-file=C:\WebServer\secrets\mariadb114-root-client.ini `
    -e "CREATE DATABASE IF NOT EXISTS bd_xmlconcilia CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER IF NOT EXISTS 'xmlconcilia'@'localhost' IDENTIFIED BY 'LA_CONTRASEÑA'; CREATE USER IF NOT EXISTS 'xmlconcilia'@'127.0.0.1' IDENTIFIED BY 'LA_CONTRASEÑA'; GRANT ALL PRIVILEGES ON bd_xmlconcilia.* TO 'xmlconcilia'@'localhost'; GRANT ALL PRIVILEGES ON bd_xmlconcilia.* TO 'xmlconcilia'@'127.0.0.1'; FLUSH PRIVILEGES;"
```

### 6. La configuración de esta computadora

```powershell
cd C:\WebApps\xmlconcilia
copy app\config\local.ejemplo.php app\config\local.php
notepad app\config\local.php
```

El `host` va en `127.0.0.1`, el `port` en `'3307'` —aquí sí, y es la única
instalación donde eso es correcto— y la contraseña es la del paso 5.
`local.php` no se versiona nunca.

Y hace falta un segundo archivo, `app/config/local.production.php`. Apache no
lee `local.php` directamente: `conf/extra/httpd-php84-fcgid-final.conf` le pasa
a PHP la variable `XMLCONCILIA_CONFIG_FILE` apuntando a ese otro archivo, y si
no existe, PHP aborta con un 500 antes de arrancar. Tampoco está en el
repositorio —`.gitignore` excluye todos los `local.*.php` porque llevan la
contraseña—, así que hay que escribirlo:

```php
<?php
/** Configuración del stack permanente en esta computadora. */
$config = require __DIR__ . '/local.php';

if (!isset($config['database']) || !is_array($config['database'])) {
    throw new RuntimeException('local.php no contiene la sección database.');
}

$config['database']['host'] = '127.0.0.1';
$config['database']['port'] = '3307';
$config['environment'] = 'production';

return $config;
```

Es el mismo del servidor con el puerto cambiado: no repite la contraseña, la
toma de `local.php`. `environment = production` es lo que apaga el detalle de
errores en pantalla; dejarlo así mantiene la copia fiel al servidor.

### 7. Los datos

`copiar-base.ps1` no lee `local.php`: lee **`local.propia.php`**, el perfil de
"la copia local", y se niega a restaurar si ese perfil apunta a un servidor
ajeno. Como aquí la copia local *es* la instalación entera, el perfil es el
mismo archivo:

```powershell
copy app\config\local.php app\config\local.propia.php
```

Ninguno de los dos está en el repositorio —`.gitignore` los excluye porque
llevan la contraseña—, así que este paso no se puede saltar.

Después, copiar el `.sql.gz` a la máquina y restaurarlo:

```powershell
.\scripts\copiar-base.ps1 -Desde C:\ruta\al\bd_xmlconcilia_XXXX.sql.gz
```

Borra la base local, la vuelve a crear, carga el respaldo, recrea las vistas y
`sp_marcar_revisado`, y cuenta las filas para comprobar que quedó completa.

### 8. El servidor web

```powershell
& 'C:\WebApps\xmlconcilia\scripts\instalar-servicios-migracion.ps1'
```

Registra Apache como servicio, lo hace depender de MariaDB, configura el
reinicio automático ante fallos y termina probando un inicio de sesión real.
Ese último paso es la razón de dejarlo para el final: sin la base restaurada no
hay con qué iniciar sesión y el script fallaría al cierre.

### 9. Los documentos

Los XML y PDF no están en la base. Si esta computadora sincroniza SharePoint,
entrar a la aplicación → **Correo** → *Carpeta de documentos* y escribir la
ruta local. Si no lo sincroniza, la aplicación funciona igual y los documentos
aparecen como no encontrados: para probar la conciliación alcanza.

### 10. Comprobar

```powershell
C:\WebServer\PHP84\php.exe cli\diagnostico.php
```

Con la ruta completa: en una máquina sin XAMPP no hay ningún `php` en el PATH.

Y abrir <http://127.0.0.1:8484> en el navegador de esa misma computadora.

El diagnóstico va a marcar con **AVISO** que la base es local. Está bien: es
exactamente lo que se acaba de montar, y ese aviso es lo que impide confundir
esta copia con la de la oficina.

---

## Después

### Refrescar los datos

Cada vez que haga falta ponerse al día, llevar un `.sql.gz` nuevo y repetir el
paso 7. **Lo trabajado sobre la copia se pierde ahí**, así que no hay que
dejar nada importante solo en esta máquina.

### Actualizar el código

```powershell
cd C:\WebApps\xmlconcilia
git pull
php cli\diagnostico.php
```

### Volver al modelo compartido

Si algún día esta computadora sí llega al servidor, no hay que reinstalar
nada: se crean los dos perfiles de conexión y se cambia con
`scripts\cambiar-base.ps1` — ver [`INSTALACION.md`](INSTALACION.md), sección
"Una copia local de la base".
