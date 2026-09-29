# Documentos en SharePoint: cómo se opera

Los XML y PDF de todas las empresas viven en SharePoint, y Nexo Fiscal se los
pide por Microsoft Graph. **Desde el 27/09/2026 es la única forma**: ya no hay
carpeta compartida ni empresas que archiven en disco. Es la opción B de
[ALMACENAMIENTO_DOCUMENTOS.md](ALMACENAMIENTO_DOCUMENTOS.md).

Está escrita para quien mantiene la instalación. Las personas que usan el
sistema no notan la diferencia: los documentos se abren igual.

---

## La idea en tres líneas

- **Todo lo nuevo va a SharePoint**, de cualquier empresa, y también lo que
  todavía no se sabe de quién es. Cada computadora necesita las credenciales
  (`app/config/local.graph.php`); sin ellas, Correo, Facturas XML, Notas XML,
  Devoluciones y Por pagar quedan cerrados, y Diagnóstico lo marca en rojo.
- **Cada documento sabe dónde está** (`facturas_xml.almacen`: NULL es la
  carpeta compartida de antes). Lo archivado antes de SharePoint se sigue
  abriendo de donde quedó hasta que se migra, y la migración se puede cortar
  y retomar.
- **La base de datos nunca va a SharePoint.** Solo los documentos.

Nexo Fiscal puede tener **una biblioteca para ella sola** o vivir en **una
carpeta de una biblioteca que la empresa ya usa**, con permiso solo sobre esa
carpeta. Ver el paso 1.

## Qué va a SharePoint y qué se queda en la computadora

A la biblioteca (o a la carpeta) van **los XML y los PDF, y nada más**: los
archivados en `SISTEMA/EMPRESA/AAAA/MM MES/…`, las copias de la carpeta del
pago (`PAGOS/<pago>/`), los PDF de devoluciones y lo que espera en la Bandeja
(`_TRABAJO/BANDEJA`). Así todas las personas, desde cualquier PC, ven los
mismos documentos.

**La bandeja del correo sube solo si el documento se queda esperando.** Un
comprobante que llega con la entrada automática encendida se captura y se
archiva en la misma corrida: su paso por la bandeja dura dos segundos y no lo
ve nadie más, así que se queda en `storage/correo/transito` de esa
computadora. En cuanto pasa a ESPERAR en la Bandeja —Hacienda lo rechazó,
viene a nombre de otra cédula, no se resolvió su empresa, falló al importar—
sí sube: lo va a mirar una persona que puede estar en otra PC. Lo hace
`EntradaAutomatica::compartir()`, y si una corrida se cortó antes de archivar,
la siguiente en esa misma computadora sube lo que quedó
(`rescatarLoQueQuedoAqui()`). Procesar un correo a mano deja la fila
esperando, así que ahí la bandeja sube siempre.

Todo lo demás se queda en la computadora que lo usa:

| Qué | Dónde |
|---|---|
| Temporales: adjuntos recién bajados, documentos bajados para un ZIP o para leer un PDF | `storage/correo/tmp`, `storage/tmp/almacen` (se borran solos) |
| Bandeja de lo que se archiva en el acto | `storage/correo/transito` |
| Excel y CSV que se cargan (pagos, reporte del ERP, notas) | `public/uploads` |
| Respaldos de la base | `storage/backups/RESPALDOS` |
| Registros, cachés, token de Graph | `storage/logs`, `storage/cache` |

No es solo costumbre: `AlmacenEnGraph::escribir()` **rechaza** cualquier
archivo que no sea `.xml` o `.pdf`, sin salir a la red.

---

## 1. Preparar SharePoint (una vez)

Lo hace alguien con permisos de administrador del tenant. Primero, lo común a
las dos formas:

1. Registrar la aplicación en Entra ID, con un **secreto de cliente**. Anotar
   cuándo vence: el día que venza, ninguna PC podrá abrir documentos.
2. Elegir dónde va a vivir Nexo Fiscal: **A** o **B**, abajo.
3. Opcional pero recomendado: en la biblioteca, una columna **"Una línea de
   texto"** llamada exactamente **`HashSha256`**. Sin ella todo funciona, pero
   SharePoint no guarda el SHA-256 de cada documento y, para saber si dos
   archivos son el mismo, hay que bajarlos.

### A. Una biblioteca para Nexo Fiscal

- Permiso **`Sites.Selected`** de tipo *aplicación*, con consentimiento del
  administrador.
- Autorizar la aplicación con rol **`write`** sobre el sitio de la biblioteca.
- Crear la biblioteca nueva.

La aplicación ve el sitio entero. Es lo que tiene hoy el tenant de pruebas.

### B. Una carpeta de una biblioteca que ya existe

Para no crear otra biblioteca: se le da a la aplicación **una carpeta** de una
que la empresa ya usa (por ejemplo `Contabilidad/Nexo Fiscal` dentro de
"Documentos"), y **nada más**. Microsoft Graph lo permite desde 2024 con los
permisos *Selected* de archivos, carpetas y elementos de lista
([Microsoft Learn](https://learn.microsoft.com/graph/permissions-selected-overview)).

1. Crear la carpeta en la biblioteca, desde el navegador.
2. Darle a la aplicación el permiso **`Files.SelectedOperations.Selected`** de
   tipo *aplicación*, con consentimiento del administrador. (Si además se
   quiere la columna `HashSha256` y SharePoint no deja escribirla con ese
   permiso, sirve `ListItems.SelectedOperations.Selected`, que cubre las
   columnas del elemento.)
3. Averiguar dos ids, desde [Graph Explorer](https://developer.microsoft.com/graph/graph-explorer)
   con una cuenta de administrador:

   ```
   GET https://graph.microsoft.com/v1.0/sites/<empresa>.sharepoint.com:/sites/<sitio>:/drives
       → el "id" de la biblioteca (empieza por b!): es el drive_id
   GET https://graph.microsoft.com/v1.0/drives/<drive_id>/root:/Contabilidad/Nexo Fiscal
       → el "id" de la carpeta: es el carpeta_id
   ```

4. Darle a la aplicación acceso de escritura sobre esa carpeta:

   ```
   POST https://graph.microsoft.com/v1.0/drives/<drive_id>/items/<carpeta_id>/permissions
   {
     "roles": ["write"],
     "grantedToV2": { "application": { "id": "<client_id de la aplicación>" } }
   }
   ```

   Contesta `201 Created`. Hace falta hacerlo con una cuenta o aplicación que
   tenga control total del sitio (`Sites.FullControl.All`, o dueño del sitio).

Lo que hay que saber de esta forma:

- La aplicación **no ve nada fuera de la carpeta**: ni la raíz de la
  biblioteca, ni las otras carpetas, ni la biblioteca misma (contestan 403).
  Nexo Fiscal nunca les pregunta nada: todo se dirige desde la carpeta.
- Dar el permiso **rompe la herencia** de permisos de esa carpeta (SharePoint
  lo hace así con cualquier permiso propio). Las personas que ya tenían acceso
  lo conservan; lo que se cambie después en los permisos de la biblioteca ya
  no baja solo a la carpeta.
- Si la biblioteca la sincroniza alguien con OneDrive, **esa persona va a ver
  bajar a su PC todo lo que archive Nexo Fiscal**. No hace daño, pero ocupa
  disco: conviene que deje de sincronizar esa carpeta.
- Mover documentos **dentro** de la carpeta no rompe nada: la tarea los sigue.
  **Sacarlos** de la carpeta es, para Nexo Fiscal, como borrarlos: aparecen
  como *Archivo perdido* hasta que alguien los devuelva.
- La carpeta se puede renombrar o mover: el permiso y el id van con ella.

## 2. Preparar cada computadora

Todas las que usan Nexo Fiscal.

```
copy app\config\graph.ejemplo.php app\config\local.graph.php
```

Completar `tenant_id`, `client_id`, `client_secret` y `drive_id`. Con la forma
**B**, además `carpeta` (la ruta dentro de la biblioteca, sin el nombre de la
biblioteca: `Contabilidad/Nexo Fiscal`) y `carpeta_id`. Con la forma **A**, el
`drive_id` lo da:

```
php cli/probar_sharepoint.php --bibliotecas=https://<empresa>.sharepoint.com/sites/<sitio>
```

Y comprobar todo de punta a punta (sube, baja, compara y borra un archivo de
prueba; con carpeta, dice además si la aplicación ve solo la carpeta y si
SharePoint da el enlace de cambios de ella):

```
php cli/probar_sharepoint.php --almacen
```

Para ensayar una carpeta sin tocar `local.graph.php`:
`php cli/probar_sharepoint.php --almacen --carpeta="Contabilidad/Nexo Fiscal"`.

**`local.graph.php` no se versiona** (git lo ignora por `app/config/local.*.php`)
y el repositorio es público. El secreto se escribe en la computadora y en
ningún otro lado: ni correo, ni chat, ni commits.

Si `probar_sharepoint.php` dice que falta un certificado raíz, PHP no tiene con
qué validar HTTPS: hay que configurar `curl.cainfo` en `php.ini` y **reiniciar
Apache** (el servicio `XmlConciliaApache84`), que no toma el cambio solo.

Al final, *Configuración → Archivo de documentos* tiene que decir "Con
credenciales", y *Diagnóstico* "SharePoint en esta computadora" en verde.

**La carpeta (`carpeta`) se elige una vez, al instalar, y es la misma en todas
las computadoras.** Las rutas que guarda la base son relativas a ella: cambiarla
con documentos ya archivados los deja fuera de alcance.

## 3. Aplicar la migración de la base

Una vez, en el servidor de base de datos:

```
mysql -u root bd_xmlconcilia < database/migration_almacen_graph.sql
```

Es segura de repetir. Agrega las columnas con las que cada documento anota
dónde está (`almacen`, los ids y los etag).

## 4. Lo que quedó en la carpeta compartida

Lo archivado antes de SharePoint sigue donde estaba y se sigue abriendo desde
las computadoras que tengan esa carpeta. Configuración (en la tarjeta de cada
empresa) y Diagnóstico dicen cuántos quedan. Se pasan a SharePoint con el
comando de migración, empresa por empresa:

```
php cli/migrar_documentos_a_graph.php --sociedad=N                 cómo va
php cli/migrar_documentos_a_graph.php --sociedad=N --todo --dry-run
php cli/migrar_documentos_a_graph.php --sociedad=N --todo --limite=2000 --muestreo=20
```

La carpeta de donde lee es la que esa computadora tenía configurada. Si ya no
la tiene, o para leer de otra copia: `--carpeta="C:\Users\…\Documentos compartidos"`.

`--todo` es: inventario → subir → verificar → reescribir. Cada paso también
existe por separado (`--inventario`, `--subir`, `--verificar`, `--reescribir`).

| Paso | Qué hace |
|---|---|
| inventario | Lee la **base**: qué archivos de la empresa están en disco. Lo que ya no está queda *omitido* y no frena nada. |
| subir | A la **misma ruta relativa** (`SISTEMA/EMPRESA/2026/…`), dentro de la biblioteca o de la carpeta. Antes comprueba que el archivo del disco sea el que la base archivó. |
| verificar | Tamaño y hash declarado, de 20 en 20. `--muestreo=N` además baja N al azar y los rehashea. |
| reescribir | Pone `almacen`, los ids y el prefijo `sharepoint:` en cada fila. Al final dice si a la empresa ya no le queda nada en disco. |

**Se puede cortar en cualquier momento** (Ctrl+C, un corte de luz) y retomar
corriendo lo mismo, desde cualquier PC con credenciales y la carpeta de antes.
El avance vive en la tabla `almacen_migracion`.

Lo que conviene saber antes:

- **Correrlo con el usuario de Windows que sincroniza OneDrive**, no como
  tarea del sistema. Los archivos que OneDrive tiene solo en la nube solo se
  bajan para ese usuario.
- **Tiempo:** cada archivo son dos llamadas a SharePoint; calcular del orden de
  un cuarto de hora por cada mil archivos. Con `--limite` se trocea en noches.
- **La carpeta de pagos:** después de migrar, la tarea programada rehace en
  SharePoint las copias de pago de los documentos que estén en un pago, 100
  por vuelta.

Si algo queda en *error* o *conflicto*, `--sociedad=N` (sin más) lo lista con
su motivo. Una vez resuelto, `--reintentar` lo vuelve a poner en la fila.

Cuando no quede nada en ninguna empresa, la carpeta compartida deja de hacer
falta. **Borrarla no es parte de esto**: es una decisión aparte.

## 5. Rescate: devolver las filas a la carpeta de antes

```
php cli/migrar_documentos_a_graph.php --sociedad=N --revertir
php cli/migrar_documentos_a_graph.php --sociedad=N --revertir --bajando
```

**Nada de la migración borra el disco**, así que las filas pueden volver a su
ruta de la carpeta compartida al instante. Lo que se archivó en SharePoint
después no tiene original en disco: `--revertir` se niega y pide `--bajando`,
que primero lo baja todo. Es un rescate —por ejemplo, antes de dar de baja un
tenant—, no un camino de vuelta: **lo nuevo sigue yendo a SharePoint**.

---

## Lo que corre solo

La tarea programada de correo (`cli/sync_correo.php`), en cada vuelta, además
de lo de siempre:

- **Revisa la biblioteca** (`ReconciliadorGraph`). Lee los cambios desde la
  vuelta anterior —si no hubo, es una sola llamada— **de la carpeta de Nexo
  Fiscal** si la hay, y:
  - si alguien **movió o renombró** un documento o una carpeta desde el
    navegador, la base sigue al documento por su id;
  - si alguien lo **borró** —o lo sacó de la carpeta de Nexo Fiscal—, el
    listado lo muestra como *Archivo perdido* y se puede volver a bajar del
    correo con su botón; además está en la papelera del sitio 93 días;
  - si lo **restauraron** o lo devolvieron, la marca se quita sola;
  - si lo **editaron**, avisa en el registro: ya no es el que se archivó.

  Además repasa por id 100 documentos por vuelta, siguiendo donde quedó la
  anterior, como red de seguridad. Nunca baja archivos para eso.

  **Si SharePoint no da el enlace de cambios de la carpeta** (en el tenant de
  pruebas sí lo da; con permisos muy recortados puede que no), no pasa nada
  grave: la tarea lo anota, repasa 300 por vuelta en vez de 100 y se entera de
  todo igual, algo más tarde. Vuelve a probar cada seis horas.
- **Arma las copias de la carpeta del pago.** Al armar un pago en la pantalla,
  esas copias no se hacen ahí (serían varias llamadas por archivo con alguien
  esperando): la pantalla avisa y la tarea las hace en los minutos siguientes.
- **Termina los borrados pendientes** (`almacen_pendientes`): si se subió un
  documento y la base no pudo guardar su fila, lo subido se borra; si
  SharePoint no contestaba en ese momento, queda anotado y se reintenta aquí.

Ya **no** ordena la carpeta compartida: lo que queda ahí se migra, no se
ordena. A mano:

```
php cli/organizar_documentos.php --dry-run    qué haría en SharePoint
php cli/organizar_documentos.php              hacerlo
php cli/organizar_documentos.php --carpeta=RUTA [--completo|--forzar-orden]   la carpeta de antes, solo nombrándola
```

Dos PC con la tarea programada no se pisan: el reconciliador toma un candado
en la base (`GET_LOCK`), que se suelta solo si el proceso muere.

---

## Dónde mirar cuando algo falla

| Síntoma | Dónde mirar |
|---|---|
| Los módulos de documentos aparecen cerrados | Esta computadora no tiene `app/config/local.graph.php`: *Configuración → Archivo de documentos*. |
| Nada de SharePoint abre en una PC | *Diagnóstico* → "SharePoint en esta computadora". Luego `php cli/probar_sharepoint.php`. |
| Dejó de abrir en todas las PC a la vez | El secreto de cliente venció. Se genera otro en Entra ID y se cambia en el `local.graph.php` de cada PC. |
| "La aplicación no puede buscar la carpeta por su ruta" | Permiso solo sobre la carpeta y sin `carpeta_id` en `local.graph.php`: ponerlo (es el id con el que se dio el permiso). |
| "No existe la carpeta … en la biblioteca" | `carpeta` mal escrita en `local.graph.php`: es la ruta dentro de la biblioteca, sin el nombre de la biblioteca. |
| Documentos marcados "Archivo perdido" | Los borraron —o los sacaron de la carpeta de Nexo Fiscal— en SharePoint: papelera del sitio, devolverlos, o el botón de volver a bajarlo del correo. |
| Un documento movido a mano no se encuentra | Espera a la próxima vuelta de la tarea (5 min); o `organizar_documentos.php`. |
| Documentos "todavía en la carpeta compartida" | Lo de antes de SharePoint: sección 4, migrarlos. |
| Copias de pago que no aparecen | `storage/logs/reconciliador_sharepoint.log`. |
| Lentitud o errores 429 | `storage/logs/graph.log`: cada llamada con su código, duración e intento. |
| "SharePoint no contestó a tiempo" | Tras cinco fallos seguidos, el cliente deja de llamar 60 s para no empeorarlo; se recupera solo. |

`storage/logs/graph.log` nunca guarda el token ni el secreto.

---

## Lo que no está resuelto (a propósito)

- **Lo que alguien sube a la biblioteca por su cuenta no se archiva**: lo que
  no está en la base se queda donde lo pusieron.
- **Una copia de la carpeta del pago borrada a mano en SharePoint no se
  repone sola.** Se repone cuando el pago cambia.
- **El PDF de una devolución movido a mano en SharePoint no se sigue.** Las
  facturas sí.
- **Los respaldos de la base (`.sql.gz`) no van a SharePoint.** Quedan en
  `storage/backups/RESPALDOS` de la computadora que los genera: no son
  documentos de ninguna empresa. Llevarlos a otra computadora es copiarlos.
- **Cambiar de biblioteca o de carpeta con documentos ya archivados.** Las
  rutas de la base son relativas a la carpeta de hoy; mudarse de tenant o de
  carpeta es otra migración, que no está hecha.
- **Borrar la carpeta compartida después de migrar.** Es una decisión aparte.

---

## Para quien toca el código

- La puerta única es `AlmacenDocumentos` (`app/helpers/`). Lo nuevo va a
  `AlmacenDocumentos::principal()` —SharePoint—, y `para($sociedad)` contesta
  lo mismo para todas; sin credenciales lanza con `SIN_SHAREPOINT`, nunca cae
  al disco. `AlmacenEnDisco` queda solo para leer lo de antes. Nadie más va al
  disco por su cuenta: `tests/PuertaUnicaTest.php` lo vigila.
- Las direcciones de SharePoint llevan prefijo: `sharepoint:SISTEMA/…`. Una
  ruta relativa sin prefijo siempre es de disco. **Todo lo que se guarda en
  SharePoint se guarda con el prefijo en la base**, y la ruta de una fila
  **nunca** se deduce de dónde se archiva lo nuevo. El 19/09/2026, por
  deducirlo, el organizador leyó como de SharePoint los 14.043 documentos en
  disco de una empresa recién pasada, los marcó "sin archivo" y repuntó 1.580
  a las copias de `PAGOS`. `EmpresaPasadaASharePointTest` y
  `LoNuevoASharePointTest` lo vigilan.
- Con carpeta, `ClienteGraph` dirige todo desde ella: `raizApi()` es
  `/drives/{id}/items/{carpeta}` y `rutaItem()` es
  `/drives/{id}/items/{carpeta}:/SISTEMA/…:`. Nunca `/root`, ni `/drives/{id}`
  a secas. Las rutas que devuelve SharePoint (`parentReference.path`) son
  desde la raíz de la biblioteca: `relativaALaCarpeta()` las pasa a relativas,
  con la ruta **actual** de la carpeta, que el reconciliador pide en cada
  vuelta (alguien pudo renombrarla).
- Contra una base de pruebas —nombre con `test` o `cert`— SharePoint de
  verdad **no se da de alta** aunque la PC tenga `local.graph.php`
  (`AlmacenDocumentos::descubrir()`): una prueba que archiva sin elegir
  almacén falla en vez de dejar huérfanos en la biblioteca. Las que no tratan
  del archivo usan `tests/soporte/ArchivoDePrueba.php`.
- **Los listados nunca salen a la red.** `existeEnFila()` contesta desde la
  base (`archivo_faltante_en`); la red se usa solo para contenido.
- Lo que cuesta guardar un comprobante que entra solo: **4 llamadas**, y dos
  de ellas son subir el XML y el PDF a su carpeta. `BandejaEnTransitoTest` las
  cuenta y falla si pasan de 4.
- `ClienteGraph` hace el token, los reintentos (`Retry-After`, espera
  creciente), el presupuesto de tiempo (8 s en web, 300 s en consola), el
  cortacircuitos, los lotes de 20 y las subidas por trozos.
- Las pruebas corren **sin red ni credenciales**, contra `GraphEnMemoria`
  (`tests/soporte/`), que se comporta como Graph: ids permanentes, 409, delta
  —de la biblioteca o de una carpeta—, 410, y con `$soloCarpeta` el permiso
  sobre una sola carpeta (403 a la raíz). Las de punta a punta:
  `PuntaAPuntaSharePointTest`, `ReconciliadorGraphTest`, `MigracionGraphTest`,
  `SinCarpetaCompartidaTest` (correo → bandeja → importación → carpeta del
  pago → otra PC lo abre, en una carpeta con permiso solo sobre ella),
  `SoloUnaCarpetaTest` (direcciones, y la tarea con documentos movidos, sacados
  de la carpeta, la carpeta renombrada y sin enlace de cambios) y
  `BandejaEnTransitoTest`.
- `SinCarpetaCompartidaTest` también corre contra la biblioteca **de verdad**
  de `local.graph.php`, y borra lo que sube al terminar (sale a la red; no es
  parte de la certificación):

  ```
  $env:XMLCONCILIA_CONFIG_FILE = 'app\config\local.php84-cert.php'
  $env:XMLCONCILIA_SHAREPOINT_REAL = '1'
  php tests/SinCarpetaCompartidaTest.php
  ```
- Tablas: `almacen_pendientes` (borrados por terminar), `almacen_delta`
  (enlace de cambios y revisión rotativa, uno para todas las PC; su llave
  lleva la biblioteca y la carpeta), `almacen_copias_pago` (copias ya hechas),
  `almacen_migracion` (el paso a paso de cada migración).
  `sociedades.almacen_documentos` quedó en las bases que la tienen, pero nada
  la lee.
