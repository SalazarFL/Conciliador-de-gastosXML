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

### 3. La base de datos

Editar `app/config/database.php` con los datos del servidor. **Esa
computadora tiene que llegar al servidor**: si está fuera de la oficina, con la
VPN encendida.

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
extensiones, conexión a la base, migraciones pendientes, la carpeta compartida
y si los documentos se abren. Cada problema viene con qué hacer para
resolverlo.

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
| "No abre *este* documento" | Alguien lo movió a mano — `php cli/organizar_documentos.php --reconciliar` |
| "Me da error al guardar" | Falta una migración en la base, o permiso de solo lectura en SharePoint |
| "No conecta" | La VPN, o el servidor de base de datos apagado |
| Un error que solo le pasa a esa persona | Su copia del código quedó atrás — `git pull` |

---

## Herramientas de línea de comandos

| Comando | Para qué |
|---|---|
| `php cli/diagnostico.php` | Revisar la instalación de esta computadora |
| `php cli/migrar_rutas_relativas.php` | Ver qué rutas siguen siendo absolutas (no cambia nada) |
| `php cli/migrar_rutas_relativas.php --aplicar` | Convertirlas y sacar del proyecto los documentos que queden adentro |
| `php cli/organizar_documentos.php --reconciliar` | Volver a ubicar documentos movidos a mano |
| `php cli/procesar_lotes.php` | Avanzar los lotes de correo sin el navegador abierto |
| `php cli/sync_correo.php` | Sincronizar el buzón |

Los dos de rutas se pueden correr las veces que haga falta: no duplican nada
ni deshacen lo ya hecho.
