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

### 2. El proyecto

Copiar el proyecto en `C:\xampp\htdocs\xmlconcilia`.

### 3. La configuración de esta computadora

Copiar la plantilla y llenarla:

```
copy app\config\local.ejemplo.php app\config\local.php
```

Ahí van las dos cosas que cambian de una máquina a otra: **cómo llega al
servidor de base de datos** (host, puerto, usuario, contraseña) y **dónde tiene
instalado `pdftotext`**, que se usa para leer los reportes PDF del ERP.

`app/config/local.php` **no se versiona**: el repositorio es público y ahí va
una contraseña. Nunca lo agregues a git.

> **El `host` tiene que ser el del servidor, no `localhost`.** Si apuntás a la
> base de esta misma computadora la aplicación abre y se ve normal, pero
> trabajás sobre una base que nadie más lee. El diagnóstico avisa cuando pasa.

**Esa computadora tiene que llegar al servidor**: si está fuera de la oficina,
con la VPN encendida.

En una instalación nueva, además:

```
mysql -u USUARIO -p BASE < database/schema.sql
```

Y después las migraciones de `database/`, en orden de fecha. El diagnóstico
(paso 5) dice exactamente cuáles faltan.

### 4. La carpeta compartida

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

### 5. Comprobar

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
| "No conecta" | La VPN, o el servidor de base de datos apagado |
| "Falta app/config/local.php" | Instalación a medias: hacer el paso 3 |
| "No veo lo que capturó mi compañero" | Su `local.php` apunta a `localhost` en vez del servidor |
| Todo va lento en una sola computadora | Su conexión al servidor; el diagnóstico da los ms por consulta |
| Un error que solo le pasa a esa persona | Su copia del código quedó atrás — `git pull` |

---

## Herramientas de línea de comandos

| Comando | Para qué |
|---|---|
| `php cli/diagnostico.php` | Revisar la instalación de esta computadora |
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
