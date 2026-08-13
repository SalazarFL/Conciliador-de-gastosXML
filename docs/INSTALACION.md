# Instalar XMLConcilia en una computadora

Cómo está montado: la **aplicación** se instala en la computadora de cada
persona, la **base de datos** es una sola en el servidor, y los **documentos**
(XML y PDF) viven en una carpeta de SharePoint sincronizada.

```
   Ana                Luis               Marta          ← cada quien con su copia
    │                  │                  │                de la aplicación
    ├──────────────────┼──────────────────┤
    │                                     │
  base de datos                    carpeta compartida
  (una, en el servidor)            (SharePoint sincronizado)
```

De ahí salen las dos reglas que gobiernan todo el código:

1. **Ningún documento se guarda dentro del proyecto ni dentro de la base.** Si
   un XML queda en `storage/` de una computadora, la fila la ven todos pero el
   archivo solo lo tiene quien lo bajó.
2. **Las rutas se guardan relativas a la carpeta compartida.** La carpeta está
   en un lugar distinto en cada máquina; guardar `C:\Users\ana\…` hace que el
   documento solo se abra en la computadora de Ana.

---

## Instalación paso a paso

### 1. XAMPP

Instalar XAMPP (Apache + PHP 8.2). En `php.ini` deben estar activas —sin el
`;` al principio— estas líneas:

```ini
extension=pdo_mysql
extension=imap
extension=zip
extension=mbstring
```

Reiniciar Apache.

### 2. Git

XAMPP no lo trae. Instalarlo desde <https://git-scm.com/download/win> con las
opciones por defecto.

**Después hay que cerrar y volver a abrir PowerShell.** El PATH se lee al
arrancar la ventana, así que en la que ya estaba abierta `git` va a seguir sin
reconocerse aunque la instalación haya ido bien. Comprobar con:

```powershell
git --version
```

### 3. El proyecto

```powershell
cd C:\xampp\htdocs
git clone -b trabajo-multisociedad-y-rutas-compartidas https://github.com/SalazarFL/Conciliador-de-gastosXML.git xmlconcilia
```

No hay que crear `xmlconcilia` antes: `git clone` la crea. Lo que sí debe
existir es `C:\xampp\htdocs`, que viene con XAMPP. El nombre al final del
comando no es opcional —sin él la carpeta se llamaría como el repositorio y
las rutas de la aplicación no coincidirían.

> **El `-b <rama>` no es opcional.** Sin él, `git clone` descarga la rama por
> defecto del repositorio (`main`), que va meses atrás de donde está el
> trabajo real. La instalación arranca, pero le faltan comandos enteros de
> `cli/` —entre ellos `diagnostico.php`— sin ningún aviso de que pasó.
> Confirmá con quien te pasó esta guía cuál es la rama vigente: puede haber
> cambiado desde que esto se escribió.
>
> Si ya clonaste sin `-b` y notás que faltan archivos, no hace falta volver a
> clonar — alcanza con cambiar de rama en el lugar:
>
> ```powershell
> git fetch origin
> git checkout trabajo-multisociedad-y-rutas-compartidas
> ```

> **Antes de clonar, comprobá que lo que hay en GitHub es lo que estás usando.**
> En la computadora que ya funciona:
>
> ```
> git status -sb
> ```
>
> La primera línea no debe decir `ahead` ni deben quedar archivos listados. Si
> falta subir algo, la máquina nueva va a instalar una versión distinta, y los
> errores que aparezcan solo le van a pasar a ella.
>
> Para ver qué falta subir: `git log '@{u}..HEAD' --oneline` (vacío = todo
> subido). **Las comillas son obligatorias en PowerShell**, que interpreta
> `@{...}` como una tabla hash y da un error de sintaxis sin ellas.

### 4. La red privada

La base de datos **no está publicada en internet**: escucha únicamente dentro
de una red privada de Tailscale. Sin este paso, la computadora no llega al
servidor y nada más funciona.

1. Instalar Tailscale: <https://tailscale.com/download/windows>
2. Iniciar sesión con **la misma cuenta** que las demás computadoras.
3. Comprobar que el servidor aparece: `tailscale status` debe listar
   `xmlconcilia-db`.

Esa es también la razón de que no haga falta VPN de oficina ni IP fija: la
computadora entra a la red privada desde donde sea.

### 5. La configuración de esta computadora

Copiar la plantilla y llenarla:

```
copy app\config\local.ejemplo.php app\config\local.php
```

Ahí van las dos cosas que cambian de una máquina a otra: **cómo llega al
servidor de base de datos** (host, puerto, usuario, contraseña) y **dónde tiene
instalado `pdftotext`**, que se usa para leer los reportes PDF del ERP.

`app/config/local.php` **no se versiona**: el repositorio es público y ahí va
una contraseña. Nunca lo agregues a git.

El `host` es la **dirección de Tailscale del servidor** (`100.x.x.x`), que se
obtiene con `tailscale status`. Pedile la contraseña a quien administra el
sistema; no está escrita en ningún archivo del repositorio.

> **Nunca pongas `localhost` ahí.** Si apuntás a la base de esta misma
> computadora, la aplicación abre y se ve normal, pero trabajás sobre una base
> vacía que nadie más lee. El diagnóstico avisa cuando pasa.

La base **ya existe y tiene los datos**: no hay que crear nada ni correr
`schema.sql`. Eso es solo para levantar un servidor desde cero.

### 6. La carpeta compartida

Abrir la biblioteca de SharePoint en el navegador y usar **Sincronizar**. Eso
crea una carpeta local, algo como:

```
C:\Users\<usuario>\<Empresa>\Documentos compartidos - Facturas
```

Entrar a la aplicación → **Correo** → escribir esa ruta en *Carpeta de
documentos*. Es lo único que cambia de una computadora a otra.

> **Espera a que termine de sincronizar la primera vez.** Si la aplicación
> arranca a media sincronización, va a reportar documentos que no encuentra —
> no están perdidos, todavía no han bajado.

### 7. Comprobar

```
php cli/diagnostico.php
```

O en la aplicación, **Administración → Diagnóstico**. Revisa versión de PHP,
extensiones, a qué base apunta esta computadora, la conexión y su latencia,
migraciones pendientes, la carpeta compartida y si los documentos se abren.
Cada problema viene con qué hacer para resolverlo.

La latencia importa más de lo que parece: la base ya no está en la misma
máquina, y una pantalla hace cientos de consultas. Por debajo de 40 ms por
consulta se siente ágil; muy por encima, el diagnóstico lo marca como aviso.

---

## Una copia local de la base

La base compartida vive en una sola computadora. Cuando esa máquina está
apagada, o Tailscale no llega hasta ella, **nadie puede trabajar**: la
aplicación abre y muere con `SQLSTATE[HY000] [2002]`.

Contra eso, una máquina puede tener además una **copia** de la base en su
propio MySQL, y cambiar de una a otra con un comando.

```
   base compartida  ──── copiar-base.ps1 ────>  copia en esta máquina
   (auxiliar-06c)          una sola dirección        (127.0.0.1)
```

> **La copia no es un segundo servidor.** No hay vuelta: lo que se escriba
> sobre la copia no sube a la oficina y se pierde entero la próxima vez que se
> refresque. Sirve para tres cosas —seguir consultando cuando el servidor no
> responde, probar una migración sin arriesgar los datos de todos, y tener un
> respaldo fuera del servidor— y para ninguna más. Capturar correo o cerrar un
> pago semanal se hace **siempre** contra la base de la oficina.

### Preparar la copia (una vez)

1. **XAMPP con MySQL corriendo** en esta máquina. Ya viene instalado.

2. **Crear el usuario `xmlconcilia` en el MySQL local**, con la misma
   contraseña que en el servidor. Así lo único que cambia entre una base y la
   otra es el host, y un respaldo se puede restaurar en cualquiera de las dos
   máquinas sin tocar nada:

   ```powershell
   C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE USER IF NOT EXISTS 'xmlconcilia'@'localhost' IDENTIFIED BY 'LA_CONTRASEÑA'; CREATE USER IF NOT EXISTS 'xmlconcilia'@'127.0.0.1' IDENTIFIED BY 'LA_CONTRASEÑA'; GRANT ALL PRIVILEGES ON bd_xmlconcilia.* TO 'xmlconcilia'@'localhost'; GRANT ALL PRIVILEGES ON bd_xmlconcilia.* TO 'xmlconcilia'@'127.0.0.1'; FLUSH PRIVILEGES;"
   ```

   El `GRANT ALL` sobre `bd_xmlconcilia.*` incluye poder borrarla y volver a
   crearla, que es lo que hace la restauración. No hace falta dar permisos
   sobre nada más.

3. **Los dos perfiles de conexión**, en `app/config/`:

   | Archivo | `host` | Qué es |
   |---|---|---|
   | `local.oficina.php` | `auxiliar-06c` | La base compartida, la de verdad |
   | `local.propia.php` | `127.0.0.1` | La copia de esta máquina |

   Se hacen copiando `local.ejemplo.php` y cambiando el `host`. Ninguno de los
   dos se versiona.

   La aplicación **no lee estos archivos**: sigue leyendo `local.php`. Los
   perfiles solo son las dos versiones de ese archivo, guardadas para poder ir
   y venir sin volver a escribir la contraseña.

### Cambiar de base

```powershell
.\scripts\cambiar-base.ps1              # dice cuál está activa
.\scripts\cambiar-base.ps1 oficina      # la compartida
.\scripts\cambiar-base.ps1 propia       # la copia local
```

Copia el perfil encima de `local.php` y corre el diagnóstico. Si `local.php`
estaba editado a mano, primero lo guarda aparte.

> **Saber sobre cuál base estás es el punto entero de este comando.** Trabajar
> sin darse cuenta sobre la copia se ve exactamente igual que trabajar bien,
> hasta que alguien pregunta por qué no aparece lo que capturaste. El
> diagnóstico marca con AVISO cuando el host es local, y `cambiar-base.ps1`
> sin argumentos lo dice en un segundo.

### Refrescar la copia — con el servidor a la vista

```powershell
.\scripts\copiar-base.ps1                 # respalda el servidor y restaura aquí
.\scripts\copiar-base.ps1 -SoloRespaldo   # solo genera el .sql, no toca la copia
```

Necesita que el servidor esté alcanzable, así que hay que correrlo **antes** de
quedarse sin conexión, no después. Lo que hace:

1. Lista las tablas del servidor y cuenta sus filas.
2. Exporta con `mysqldump` a `storage/backups/bd_xmlconcilia_<fecha>.sql`.
3. Borra la base local, la vuelve a crear y carga el respaldo.
4. Recrea las vistas y `sp_marcar_revisado` desde
   `database/vistas_y_procedimientos.sql`.
5. Vuelve a contar y compara tabla por tabla contra el servidor.

Tres decisiones del script que parecen caprichos y no lo son:

- **Sin `--routines`.** El usuario `xmlconcilia` del servidor no puede hacer
  `SHOW CREATE PROCEDURE` y `mysqldump` aborta entero. Por eso el respaldo trae
  solo tablas, y las vistas y el procedimiento se recrean aparte en el paso 4.
  Un respaldo hecho a mano tiene el mismo agujero: acordate del paso 4.
- **Con `--result-file=` y no con `>`.** La redirección de PowerShell escribe
  UTF-16 con BOM y `mysql` no puede leer ese archivo de vuelta.
- **La base local se borra entera**, no se sobrescribe tabla por tabla: si en
  el servidor se borró una tabla, sobrescribir la dejaría aquí para siempre.

`storage/backups/` no se versiona y los archivos pesan ~150 MB cada uno. El
script dice cuánto ocupa la carpeta; borrar los viejos es a mano y a
conciencia.

> **Antes de aplicar cualquier migración de esquema, sacá un respaldo.** Es lo
> único que separa un error de un desastre.

### Refrescar la copia — sin acceso al servidor

El camino de arriba tiene un problema de fondo: **no sirve justo cuando hace
falta**. Si Tailscale no llega a la máquina del servidor, tampoco llega
`mysqldump`, y la copia se queda congelada precisamente el día que es lo único
que hay.

La salida es invertir quién empieza. En vez de que esta computadora vaya a
buscar la copia, **la computadora que tiene la base la deja escrita en la
carpeta compartida**, y SharePoint hace el resto:

```
  máquina con la base                                      esta máquina
  ───────────────────                                      ────────────
  Administración → Diagnóstico                             copiar-base.ps1
  → Generar respaldo ahora        _TRABAJO/RESPALDOS/         -Desde ultimo
         │                          *.sql.gz                       │
         └───── mysqldump ──────>  (OneDrive) ──────────────>  restaura aquí
```

Ninguno de los dos extremos toca la red del otro. Funciona con Tailscale caído,
con la VPN caída y con el servidor apagado, siempre que el respaldo se haya
generado antes.

**En la computadora que tiene la base** (Administración → Diagnóstico, solo
admin):

- **Generar respaldo ahora** — vuelca la base y deja un `.sql.gz` en
  `_TRABAJO/RESPALDOS`. Corre en segundo plano: se puede cerrar la página, el
  proceso sigue y al volver se ve cómo terminó.
- **Todas las noches a las HH:MM** — registra una tarea programada de Windows
  que hace lo mismo sin que nadie se acuerde. Corre en la sesión del usuario
  conectado, sin contraseña ni permisos de administrador de Windows, y si la
  máquina estaba apagada a esa hora corre al encenderla.

Lo mismo desde la consola: `php cli/respaldar_base.php`.

**En esta computadora**, cuando el archivo ya sincronizó:

```powershell
.\scripts\copiar-base.ps1 -Desde ultimo                    # el más nuevo de la carpeta
.\scripts\copiar-base.ps1 -Desde C:\ruta\archivo.sql.gz    # uno concreto
```

Dos cosas que el script vigila, porque las dos se ven igual que un respaldo
bueno hasta que ya es tarde:

- **Un `.gz` a medio sincronizar.** OneDrive muestra el archivo antes de
  terminar de bajarlo; si se restaura, la base local ya se borró cuando falla
  la descompresión. El script se planta si el archivo es sospechosamente chico.
- **Un respaldo generado por esta misma máquina.** El nombre lleva el equipo
  (`bd_xmlconcilia_AUXILIAR-06C_20260813_2200.sql.gz`). Si el más nuevo lo hizo
  esta computadora, es la copia de la copia y avisa: restaurarlo sería reciclar
  datos viejos creyendo que te pusiste al día.

El archivo va comprimido a propósito: la carpeta la sincroniza **todo el
mundo**, así que 150 MB por respaldo se los baja cada persona. Comprimido son
unos 12 MB. Se conservan los últimos 5 y los viejos se borran solos.

### Lo que la copia no trae

Los **documentos** (XML y PDF) no están en la base: viven en la carpeta de
SharePoint sincronizada, y esa se sincroniza sola. La copia de la base y la
carpeta compartida son dos cosas independientes; sobre la copia local los
documentos se abren igual, porque las rutas se guardan relativas.

Tampoco trae la configuración de la máquina —carpeta de documentos, cuentas de
correo, tarea programada—, que vive en `storage/correo/` y es de cada
computadora.

---

## Actualizar a una versión nueva

En cada computadora:

```
git pull
php cli/diagnostico.php
```

Si el diagnóstico dice que faltan migraciones, aplicarlas **una sola vez** —la
base es compartida, así que la primera persona que actualice las corre y las
demás solo actualizan el código.

El orden importa: **primero las migraciones, después el código**, o al revés en
la práctica no pasa nada grave porque el diagnóstico avisa. Lo que sí hay que
evitar es dejar computadoras rezagadas: si una queda con código viejo y la base
ya cambió, esa persona va a ver errores que nadie más ve.

---

## Cuando alguien reporta un problema

Antes de conectarse a su equipo, pedirle:

```
php cli/diagnostico.php
```

o una captura de **Administración → Diagnóstico**. Dice en qué computadora
está y qué le falta a esa instalación. Los problemas más comunes en este
modelo, en orden de frecuencia:

| Síntoma | Casi siempre es |
|---|---|
| "No abre ningún documento" | La carpeta compartida apunta al lugar equivocado, o SharePoint aún no termina de bajar los archivos |
| "No abre *este* documento" | Alguien lo movió fuera de la carpeta compartida — devolvelo adentro y corré `php cli/organizar_documentos.php` |
| "Me da error al guardar" | Falta una migración en la base, o permiso de solo lectura en SharePoint |
| "No conecta" | Tailscale apagado en esa computadora, o el servidor caído. `tailscale status` lo dice en un segundo. Si va para largo, se puede seguir trabajando sobre la copia local — ver "Refrescar la copia — sin acceso al servidor" |
| "Falta app/config/local.php" | Instalación a medias: hacer el paso 5 |
| "No veo lo que capturó mi compañero" | Su `local.php` apunta a `localhost` en vez del servidor |
| Todo va lento en una sola computadora | Su conexión al servidor; el diagnóstico da los ms por consulta |
| Un error que solo le pasa a esa persona | Su copia del código quedó atrás — `git pull` |

---

## Herramientas de línea de comandos

| Comando | Para qué |
|---|---|
| `php cli/diagnostico.php` | Revisar la instalación de esta computadora |
| `.\scripts\cambiar-base.ps1` | Decir a qué base apunta esta computadora |
| `.\scripts\cambiar-base.ps1 oficina\|propia` | Cambiar entre la base compartida y la copia local |
| `.\scripts\copiar-base.ps1` | **Borra la copia local**: la respalda del servidor y la vuelve a bajar |
| `.\scripts\copiar-base.ps1 -SoloRespaldo` | Respaldar el servidor a `storage/backups` sin tocar nada más |
| `.\scripts\copiar-base.ps1 -Desde ultimo` | **Borra la copia local**: la rehace con el respaldo que dejó la otra máquina en SharePoint |
| `php cli/respaldar_base.php` | Dejar un respaldo en la carpeta compartida (lo mismo que el botón de Diagnóstico) |
| `php cli/migrar_rutas_relativas.php` | Ver qué rutas siguen siendo absolutas (no cambia nada) |
| `php cli/migrar_rutas_relativas.php --aplicar` | Convertirlas y sacar del proyecto los documentos que queden adentro |
| `php cli/organizar_documentos.php` | Volver a ubicar documentos movidos a mano (no mueve nada) |
| `php cli/organizar_documentos.php --forzar-orden` | **Mueve archivos**: ordena lo registrado por fecha, tipo y estado |
| `php cli/organizar_documentos.php --semana=N` | **Mueve archivos**: solo los de esa semana, a su carpeta de pago |
| `php cli/procesar_lotes.php` | Avanzar los lotes de correo sin el navegador abierto |
| `php cli/sync_correo.php` | Sincronizar el buzón y capturar correo nuevo si está habilitado |
| `php cli/adelgazar_correo_indice.php` | Medir cuánto ocupa el índice del correo (no cambia nada) |
| `php cli/adelgazar_correo_indice.php --aplicar --retencion-dias=N` | **Borra filas**: deja solo los últimos N días de índice y compacta |

Los dos de rutas se pueden correr las veces que haga falta: no duplican nada
ni deshacen lo ya hecho.

### Ningún documento se archiva solo

Los XML y PDF viven en una carpeta que la gente también abre en el Explorador.
La captura automática puede descargar XML/PDF nuevos a `_TRABAJO/BANDEJA`.
Esa es una zona de espera: **no los importa ni los archiva en las carpetas
finales**. Una persona debe revisarlos, seleccionarlos y confirmar la
importación desde Correo → Bandeja de revisión.

Fuera de esa zona de espera, la aplicación nunca mueve un archivo por su cuenta:
ni al importar del correo, ni al verificar un listado de Por Pagar, ni en la
tarea programada.

Mover lo ya archivado es siempre una orden explícita:

- **Correo → ⚙ → Ordenar el archivo**, que primero muestra qué haría.
- `--forzar-orden` o `--semana=N` por línea de comandos.

Lo que sí ocurre solo es lo contrario: cada 5 minutos la tarea programada
busca los archivos que alguien movió a mano y **corrige la base de datos para
seguirlos**, sin tocarlos. Agregá `--dry-run` a cualquier comando para ver el
resultado sin aplicarlo.
