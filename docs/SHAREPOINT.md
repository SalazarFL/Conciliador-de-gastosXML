# Documentos en SharePoint: cómo se opera

Esta es la guía de trabajo de la opción B de
[ALMACENAMIENTO_DOCUMENTOS.md](ALMACENAMIENTO_DOCUMENTOS.md): los XML y PDF de
una empresa viven en una biblioteca de SharePoint, y Nexo Fiscal se los pide a
SharePoint por Microsoft Graph en vez de leerlos de una carpeta sincronizada.

Está escrita para quien mantiene la instalación. Las personas que usan el
sistema no notan la diferencia: los documentos se abren igual.

---

## La idea en tres líneas

- **Cada empresa elige dónde archiva lo nuevo**: disco (la carpeta compartida
  de siempre) o SharePoint. Se cambia en *Configuración → Empresas del grupo*.
- **Cada documento sabe dónde está** (`facturas_xml.almacen`: NULL es disco).
  Para leer manda la fila, no la empresa. Por eso se puede pasar una empresa
  de a poco y volver atrás.
- **La base de datos nunca va a SharePoint.** Solo los documentos.

Las dos formas conviven: una empresa en SharePoint y otra en disco funcionan
lado a lado en la misma instalación.

---

## 1. Preparar SharePoint (una vez)

Lo hace alguien con permisos de administrador del tenant. El detalle de por qué
cada paso está en
[ALMACENAMIENTO_DOCUMENTOS.md](ALMACENAMIENTO_DOCUMENTOS.md#lo-que-hay-que-pedirle-al-administrador-solo-opción-b).

1. Registrar la aplicación en Entra ID, con un **secreto de cliente**. Anotar
   cuándo vence: el día que venza, ninguna PC podrá abrir documentos.
2. Permiso **`Sites.Selected`** de tipo *aplicación*, con consentimiento del
   administrador.
3. Autorizar la aplicación con rol **`write`** sobre el sitio de la biblioteca.
4. Crear la **biblioteca nueva** para Nexo Fiscal (no reutilizar la que
   sincroniza OneDrive hoy).
5. Añadirle una columna **"Una línea de texto"** llamada exactamente
   **`HashSha256`**. Sin ella todo funciona, pero SharePoint no guarda el
   SHA-256 de cada documento y, para saber si dos archivos son el mismo, hay
   que bajarlos.

## 2. Preparar cada computadora

Solo las que trabajan con empresas en SharePoint.

```
copy app\config\graph.ejemplo.php app\config\local.graph.php
```

Completar `tenant_id`, `client_id` y `client_secret`. Para el `drive_id`:

```
php cli/probar_sharepoint.php --bibliotecas=https://<empresa>.sharepoint.com/sites/<sitio>
```

Y comprobar todo de punta a punta (sube, baja, compara y borra un archivo de
prueba):

```
php cli/probar_sharepoint.php --almacen
```

La última línea dice si la biblioteca tiene la columna `HashSha256`.

**`local.graph.php` no se versiona** (git lo ignora por `app/config/local.*.php`)
y el repositorio es público. El secreto se escribe en la computadora y en
ningún otro lado: ni correo, ni chat, ni commits.

Si `probar_sharepoint.php` dice que falta un certificado raíz, PHP no tiene con
qué validar HTTPS: hay que configurar `curl.cainfo` en `php.ini` y **reiniciar
Apache** (el servicio `XmlConciliaApache84`), que no toma el cambio solo.

Al final, *Diagnóstico* tiene que mostrar "SharePoint en esta computadora" en
verde.

## 3. Aplicar la migración de la base

Una vez, en el servidor de base de datos:

```
mysql -u root bd_xmlconcilia < database/migration_almacen_graph.sql
```

Es segura de repetir. Mientras no se cambie ninguna empresa, no cambia nada.

## 4. Pasar una empresa

Una empresa nueva y vacía se pasa desde *Configuración → Empresas del grupo*,
eligiendo "SharePoint" y pulsando *Cambiar*: antes de cambiar el ajuste se
prueba la conexión con la biblioteca, y si no contesta no se cambia nada. Lo
que llegue desde ese momento se archiva en SharePoint.

Una empresa **con documentos** se pasa con el comando de migración, que sube
lo que ya existe y cambia el ajuste solo cuando no queda nada pendiente:

```
php cli/migrar_documentos_a_graph.php --sociedad=N                 cómo va
php cli/migrar_documentos_a_graph.php --sociedad=N --todo --dry-run
php cli/migrar_documentos_a_graph.php --sociedad=N --todo --limite=2000 --muestreo=20
```

`--todo` es: inventario → subir → verificar → reescribir. Cada paso también
existe por separado (`--inventario`, `--subir`, `--verificar`, `--reescribir`).

| Paso | Qué hace |
|---|---|
| inventario | Lee la **base**: qué archivos de la empresa están en disco. Lo que ya no está queda *omitido* y no frena nada. |
| subir | A la **misma ruta relativa** (`SISTEMA/EMPRESA/2026/…`). Antes comprueba que el archivo del disco sea el que la base archivó. |
| verificar | Tamaño y hash declarado, de 20 en 20. `--muestreo=N` además baja N al azar y los rehashea. |
| reescribir | Pone `almacen` y los ids en cada fila, **sin tocar su ruta**. Al final, si no queda nada pendiente, cambia el ajuste de la empresa. |

**Se puede cortar en cualquier momento** (Ctrl+C, un corte de luz) y retomar
corriendo lo mismo, desde cualquier PC con credenciales. El avance vive en la
tabla `almacen_migracion`.

Lo que conviene saber antes:

- **Correrlo con el usuario de Windows que sincroniza OneDrive**, no como
  tarea del sistema. Los archivos que OneDrive tiene solo en la nube solo se
  bajan para ese usuario.
- **Tiempo:** cada archivo son dos llamadas a SharePoint, y cada llamada tarda
  de medio segundo a un segundo. Mil archivos son del orden de media hora.
  Con `--limite` se trocea en noches.
- **Antes de cortar, vaciar la bandeja de esa empresa.** Lo que queda en
  tránsito termina donde empezó; no se pierde, pero es más limpio.
- **Orden recomendado:** la empresa más chica primero, y una semana mirándola
  antes de pasar la siguiente.
- **La carpeta de pagos:** después del corte, la tarea programada rehace en
  SharePoint las copias de pago de los documentos de esa empresa que estén en
  un pago, 100 por vuelta. Con mucha historia tarda algunas horas en ponerse
  al día.

Si algo queda en *error* o *conflicto*, `--sociedad=N` (sin más) lo lista con
su motivo. Una vez resuelto, `--reintentar` lo vuelve a poner en la fila.

## 5. Volver atrás

```
php cli/migrar_documentos_a_graph.php --sociedad=N --revertir
php cli/migrar_documentos_a_graph.php --sociedad=N --revertir --bajando
```

**Nada de la migración borra el disco**, así que volver es instantáneo: cada
fila vuelve a su ruta de disco de siempre y la empresa vuelve a archivar en
disco.

Lo que se archivó en SharePoint *después* del corte no tiene original en
disco. Si hay algo así, `--revertir` se niega sin tocar nada y pide
`--bajando`, que primero baja todo eso a la carpeta compartida y recién
después devuelve las filas.

---

## Lo que corre solo

La tarea programada de correo (`cli/sync_correo.php`), en cada vuelta, además
de lo de siempre:

- **Revisa la biblioteca** (`ReconciliadorGraph`), solo si hay alguna empresa o
  algún documento en SharePoint. Lee los cambios desde la vuelta anterior —si
  no hubo, es una sola llamada— y:
  - si alguien **movió o renombró** un documento o una carpeta desde el
    navegador, la base sigue al documento por su id;
  - si alguien lo **borró**, el listado lo muestra como *Archivo perdido* y se
    puede volver a bajar del correo con su botón, como en disco; además está
    en la papelera del sitio 93 días;
  - si lo **restauraron**, la marca se quita sola;
  - si lo **editaron**, avisa en el registro: ya no es el que se archivó.

  Además repasa por id 100 documentos por vuelta, siguiendo donde quedó la
  anterior, como red de seguridad. Nunca baja archivos para eso.
- **Arma las copias de la carpeta del pago** de los documentos que están en
  SharePoint. Al armar un pago en la pantalla, esas copias no se hacen ahí
  (serían varias llamadas por archivo con alguien esperando): la pantalla
  avisa y la tarea las hace en los minutos siguientes.
- **Termina los borrados pendientes** (`almacen_pendientes`): si se subió un
  documento y la base no pudo guardar su fila, lo subido se borra; si
  SharePoint no contestaba en ese momento, queda anotado y se reintenta aquí.

A mano:

```
php cli/organizar_documentos.php --sharepoint --dry-run    qué haría
php cli/organizar_documentos.php --sharepoint              hacerlo
```

Dos PC con la tarea programada no se pisan: el organizador y el reconciliador
toman un candado en la base (`GET_LOCK`), que se suelta solo si el proceso
muere.

---

## Dónde mirar cuando algo falla

| Síntoma | Dónde mirar |
|---|---|
| Nada de SharePoint abre en una PC | *Diagnóstico* → "SharePoint en esta computadora". Luego `php cli/probar_sharepoint.php`. |
| Dejó de abrir en todas las PC a la vez | El secreto de cliente venció. Se genera otro en Entra ID y se cambia en el `local.graph.php` de cada PC. |
| Documentos marcados "Archivo perdido" | Los borraron en SharePoint: papelera del sitio, o el botón de volver a bajarlo del correo. |
| Un documento movido a mano no se encuentra | Espera a la próxima vuelta de la tarea (5 min); o `organizar_documentos.php --sharepoint`. |
| Copias de pago que no aparecen | `storage/logs/reconciliador_sharepoint.log`. |
| Lentitud o errores 429 | `storage/logs/graph.log`: cada llamada con su código, duración e intento. |
| "SharePoint no contestó a tiempo" | Tras cinco fallos seguidos, el cliente deja de llamar 60 s para no empeorarlo; se recupera solo. |

`storage/logs/graph.log` nunca guarda el token ni el secreto.

---

## Lo que no está resuelto (a propósito)

- **Lo que alguien sube a la biblioteca por su cuenta no se archiva**: igual
  que en disco, lo que no está en la base se queda donde lo pusieron.
- **Una copia de la carpeta del pago borrada a mano en SharePoint no se
  repone sola.** Se repone cuando el pago cambia.
- **El PDF de una devolución movido a mano en SharePoint no se sigue.** Las
  facturas sí.
- **Los respaldos de la base (`.sql.gz`) no van a SharePoint por Graph.**
  Siguen en `_TRABAJO/RESPALDOS` de la carpeta compartida: no son documentos
  de ninguna empresa.
- **Borrar los originales del disco después de migrar no es parte de esto.**
  Es una decisión aparte, y mientras no se tome, volver atrás es gratis.

---

## Para quien toca el código

- La puerta única es `AlmacenDocumentos` (`app/helpers/`), con dos
  implementaciones: `AlmacenEnDisco` y `AlmacenEnGraph`. Nadie más va al disco
  por su cuenta: `tests/PuertaUnicaTest.php` lo vigila.
- Las direcciones de SharePoint llevan prefijo: `sharepoint:SISTEMA/…`. Una
  ruta relativa sin prefijo siempre es de disco.
- **Los listados nunca salen a la red.** `existeEnFila()` contesta desde la
  base (`archivo_faltante_en`); la red se usa solo para contenido.
- `ClienteGraph` hace el token, los reintentos (`Retry-After`, espera
  creciente), el presupuesto de tiempo (8 s en web, 300 s en consola), el
  cortacircuitos, los lotes de 20 y las subidas por trozos.
- Las pruebas corren **sin red ni credenciales**, contra `GraphEnMemoria`
  (`tests/soporte/`), que se comporta como Graph: ids permanentes, 409, delta,
  410. Las de punta a punta: `PuntaAPuntaSharePointTest`,
  `ReconciliadorGraphTest`, `MigracionGraphTest`.
- Tablas: `almacen_pendientes` (borrados por terminar), `almacen_delta`
  (enlace de cambios y revisión rotativa, uno para todas las PC),
  `almacen_copias_pago` (copias ya hechas), `almacen_migracion` (el paso a paso
  de cada migración).
