# Revisión del módulo de Seguimiento

**Escrito para:** quien mantiene Nexo Fiscal (Fabián y quien herede el código).
Rendimiento, concurrencia, memoria, escalabilidad y seguridad de la cola única
de trabajo (`app/models/Seguimiento.php`, `app/controllers/SeguimientoController.php`,
`app/views/seguimiento/index.php`).

Cada hallazgo lleva un código (`P1`, `L2`, `S3`, `C1`…). Los que empiezan por
`L`, `S`, `C` y `R` tienen una prueba que los reproduce en
`tests/Seguimiento*BordeTest.php`, `…SeguridadTest.php` y `…ConcurrenciaTest.php`,
marcada como **DEFECTO ABIERTO**: el día que se arregle el defecto, la prueba
falla sola y hay que ascenderla a caso normal. Así esta lista no puede mentir.

## Estado al 2026-09-15

De los 24 defectos con prueba, **los 24 están cerrados** y sus pruebas pasaron
de `defecto()` a `caso()` (conservan el código en el nombre: `[S1] …`). De las
optimizaciones, se hicieron P1.1, P3, P4 y P2 (paso 1, y el `cli/` del paso 2).
El resto de este documento es el análisis original, sin reescribir.

| Código | Estado | Cómo quedó |
|---|---|---|
| S1 | Cerrado | `uno()` acota por la empresa de la sesión y el controlador además exige `Permisos::puedeSociedad()`. Un documento ajeno responde igual que uno inexistente. |
| S2 | Cerrado | `acotadoA()` + `enLaCola()`: antes de escribir, los items se cruzan por clave primaria con las tablas de cada rama (mismas condiciones que la unión). Los ajenos se descartan y la respuesta trae `descartados`. |
| S3 | Cerrado | `app/core/Csrf.php`, exigido en `Router::dispatch()` para todo lo que no es GET (todas las pantallas, no solo esta) y repetido en `actualizar()`. Token por sesión en `<meta name="csrf-token">`, que `app.js` pone a cada fetch/XHR/formulario del mismo origen. Se acepta también `Sec-Fetch-Site: same-origin` u `Origin` = `Host`; `cross-site` se rechaza siempre. |
| S4 | Cerrado | Los rechazos para la persona son `InvalidArgumentException` (422, mensaje tal cual); todo lo demás va a `registrarFallo()` y el navegador recibe un 500 genérico. |
| S5 | Cerrado | `TOPE_TANDA = 2000`, rechazado antes de consultar nada. |
| S6 | Cerrado | `%`, `_` y `\` se escapan en los tres buscadores de texto; los importes pasan siempre por `BusquedaImporte::numero()`. |
| S7 | Cerrado | La página se acota a la última; el OFFSET es siempre entero. |
| L1 | Cerrado | `cola()` recibe su tope (`$maximo`); la exportación pide 5 000 y la tarjeta de navegación 300 (también estaba recortada a 200). |
| L2–L9, L11, L12 | Cerrados | Ver cada prueba. L12 se resolvió rechazando: lo que está en revisión no puede quedarse sin motivo. |
| C1 | Cerrado | Estado anterior leído con `FOR UPDATE` dentro de la transacción. Verificado con dos conexiones reales: la segunda espera. |
| C2 | Cerrado | `GET_LOCK('xmlconcilia_seguimiento_recordatorios', 0)`: si otro barre, este no hace nada. Verificado con dos conexiones reales. |
| C3 | Cerrado | Items ordenados por `(origen, referencia_id)` antes de escribir. |
| C4 | Cerrado | 1213 se reintenta hasta 3 veces; 1205, una vez más. Otros errores no se reintentan. |
| R4, R5 | Cerrados | `Notificacion::cerrarPorFirmas()` y `avisarVarios()`: una escritura por tanda. |
| P3 | Hecho | Agregado por JOIN. Salida idéntica fila por fila (10 533 filas, 336 con notas vivas); la unión pasó de 200 a 143 ms. |
| P1.1 | Hecho | `COUNT(*) OVER ()` en la página. Solo se cuenta aparte si se pidió una página más allá de la última. |
| P4 | Hecho | Los desplegables se guardan en `storage/cache/seguimiento_dimensiones_<modo>_<empresa>.json` con la huella `UPDATE_TIME` de sus tablas (1 ms) y 10 min de vigencia. |
| P2 | Hecho (paso 1) | `generarRecordatoriosSiToca()`: la campana barre como mucho cada 5 min por computadora. `cli/recordatorios.php` existe para el Programador de tareas, **pero la tarea no se creó**. |
| P1.2 / P1.3 | **Descartados por ahora** | Tabla temporal o tabla-cola persistente. No se hacen: el uso real son 3–4 personas y ahí la pantalla ya abre por debajo del segundo. Ver "Cuándo hay que retomar P1.3" al final. |

**Medido después, igual que arriba** (copia local, mediana de 5; la máquina
tenía además la importación de correo corriendo, así que el "antes" se volvió
a medir en el mismo momento en vez de usar las cifras de abajo):

| | Antes | Después |
|---|---:|---:|
| Una carga (página + total + resumen + desplegables) | 1 092 ms | 359 ms |
| Consultas pesadas por carga | 4 | 2 (+1 de 1 ms) |
| 1 a la vez: p50 | 918 ms | 349 ms |
| 4 a la vez: p50 / p95 | 1 684 / 1 819 ms | 900 / 973 ms |
| 8 a la vez: p50 / p95 | 3 148 / 4 579 ms | 1 252 / 1 524 ms |
| 12 a la vez: p50 / p95 | 4 304 / 6 949 ms | 1 245 / 2 533 ms |
| Rendimiento con 12 | 2,2 cargas/s | 5,1 cargas/s |

Con 12 personas a la vez sigue habiendo cargas de 2,5 s con la base al lado:
P1.3 sigue siendo la única salida que escala plano. **Pero el uso esperado son
3–4 personas**, y en esa fila la pantalla abre en 900 ms parejos. Por eso P1.2 y
P1.3 quedan descartados; la condición para retomarlos está al final del documento.

### Certificación (2026-09-15): 111 pasan, 0 SKIP, 0 fallos

Corrida entera contra `xmlconcilia_php84_cert`, por primera vez sin una sola
prueba saltada. Antes salían ~20 en `SKIP` porque el perfil de certificación
entraba como `root`, y el root de MariaDB en Windows usa autenticación de
Windows (`auth_gssapi`), que el cliente de PHP no habla. Como una prueba que no
conecta se marca `SKIP` y sale con código 0, el resumen decía OK sin haber
tocado la base: **verde por no haber mirado**.

Al conectar aparecieron 8 fallos reales, todos por el mismo motivo: la base de
certificación tenía el esquema viejo (le faltaban `bitacora`,
`proveedor_codigo_erp`, `usuarios.cedula`, `facturas_xml.pago_id`…). Se
reconstruyó. Para rehacerla en cualquier momento:
`scripts/preparar-base-certificacion.ps1`, que lleva el diagnóstico completo en
la cabecera.

> **Una sola línea `SKIP` en una corrida significa que la base no conectó y que
> esa certificación no vale.** Es la primera línea que hay que mirar, antes que
> el resumen.

### Verificado en navegador (2026-09-15)

Con Chrome contra el Apache de la máquina de desarrollo, sesión iniciada y base
local. Cubre lo que las pruebas de PHP no ven:

- `S3` en las tres formas: un `POST` sin token da **403**; con token pasa; con
  token pero `Sec-Fetch-Site: cross-site` da **403**.
- El token se inyecta solo: un `POST` que la propia pantalla dispara sin
  intervención (`/correo/lotes/latido`) sale con **200**.
- La pantalla carga sus 50 renglones con **cero errores de consola**.
- Una escritura real de punta a punta: marcar un renglón → "Mandar a revisión" →
  `POST /seguimiento/actualizar` **200**, y la fila queda en `revision` con su
  motivo y su renglón de bitácora.
- `L12` desde la interfaz: sin motivo no deja aplicar y avisa
  ("Falta la descripción").

## Cómo se midió

- Base real de la copia local (MariaDB 11.4 en el 3307): 8 581 facturas del ERP,
  3 042 líneas de notas, 13 061 comprobantes XML. La unión de la cola son
  ~11 420 filas.
- Cada consulta, el mejor de 3 (OPcache caliente). Medido **con la base al lado**;
  en producción la base está al otro lado de la red (~85–100 ms por viaje), así
  que todo lo de abajo es un piso, no el número real que ve la oficina.
- Prueba de carga: N procesos PHP a la vez, cada uno 3 cargas de la pantalla.
  La máquina tiene 4 núcleos.

---

## 1. Rendimiento y escalabilidad

### El número que importa

Una sola carga de la pantalla de Seguimiento son **cuatro consultas pesadas**,
cada una sobre la misma unión de ~11 420 filas materializada de cero:

| Consulta | Con base al lado | Qué hace |
|---|---:|---|
| `COUNT(*)` (total para la paginación) | 143 ms | recorre la unión entera |
| página (`sqlBase` + `LIMIT 50`) | 136 ms | vuelve a construir la unión y ordena |
| `resumen()` (cuentas de las pestañas) | 171 ms | otra vez la unión, con 12 `SUM` |
| `dimensionesParaFiltro()` (proveedores y sucursales) | 157 ms | otra vez la unión, agrupada |
| **total por carga** | **~607 ms** | |

`EXPLAIN` de la página: la tabla derivada entra como `type=ALL`, 11 420 filas,
`Using where; Using filesort`. **Ningún índice puede ayudar**, porque se filtra y
se ordena sobre una tabla derivada que se construye en el momento. El único acceso
por índice que sobrevive es el `LEFT JOIN seguimiento` por `uk_seguimiento_referencia`.

### Cómo se degrada bajo carga (medido)

| A la vez | p50 por carga | p95 | Rendimiento |
|---:|---:|---:|---:|
| 1 | 664 ms | 677 ms | 1,4 cargas/s |
| 4 | 1 364 ms | 1 414 ms | 2,7 cargas/s |
| 8 | 2 551 ms | 3 053 ms | 2,6 cargas/s |
| 12 | 3 328 ms | **7 408 ms** | 2,3 cargas/s |

El rendimiento se estanca en ~2,6 cargas/s (4 núcleos, cada carga quema CPU
reconstruyendo la unión). Con 12 personas trabajando la cola a la vez —el destino
del módulo, es "la cola única de trabajo"— la mitad de las cargas pasan de 7
segundos. Y esto es **con la base al lado**; sumándole la red de producción y que
cada carga son 4 viajes, la cuenta empeora.

### P1 — La unión se reconstruye 4 veces por carga

`cola()` la arma para el COUNT y para la página; `resumen()` la arma de nuevo;
`dimensionesParaFiltro()` otra vez. Cuatro veces el mismo trabajo caro. El caché
`Model::$memoria` no ayuda: son cuatro consultas con SQL distinto.

**Optimización, paso a paso:**

1. **Unir COUNT y página en una pasada** con `COUNT(*) OVER ()`.
   Medido: **173 ms** contra los **280 ms** de las dos por separado. El total
   viene en cada fila; se lee de la primera.
   ```sql
   SELECT COUNT(*) OVER () AS total_filas, c.*, …  -- resto igual
   ```
   `cola()` toma `total` de `$filas[0]['total_filas']` (0 si no hay filas).

2. **Materializar la unión una vez por carga** en una `TEMPORARY TABLE` y correr
   contra ella el COUNT, la página, el resumen y las dimensiones. Medido:

   | | ms |
   |---|---:|
   | construir la temporal + índice | 348 ms |
   | página (con índice, `type=range`) | **2,4 ms** |
   | cuentas por pestaña | **3,8 ms** |
   | dimensiones | ~similar |

   De ~607 ms a **~360 ms**, y las tres consultas de después dejan de escalar con
   el tamaño de la cola. El `CREATE … AS SELECT` sigue costando lo suyo, pero se
   paga **una vez**, no cuatro.

3. **El salto de fondo: una tabla-cola persistente.** Hoy la unión existe solo en
   el momento de mirarla. Si en su lugar hubiera una tabla real
   `seguimiento_cola(origen, referencia_id, sociedad_id, estado_calculado, fecha,
   monto, saldo, tarea, …)` mantenida al escribir facturas/notas/comprobantes (o
   por un `cli/` cada pocos minutos), la pantalla sería un `SELECT` con índice
   `(sociedad_id, estado_efectivo, fecha, referencia_id)`: **~2–5 ms** por
   consulta, escala plano con la concurrencia, y los índices por fin sirven.
   Es la única salida que aguanta 12 personas a la vez. Cuesta más: hay que
   decidir dónde se refresca y aceptar que la cola puede ir unos minutos por
   detrás del ERP. Recomendado si el uso concurrente va a crecer.

### P3 — Dos subconsultas correlacionadas por cada factura del ERP

En `sqlUnionSistema()`, la rama de facturas trae `notas_vivas` y
`notas_vivas_saldo` con dos `SELECT … FROM notas_credito_lineas` correlacionados,
que se ejecutan una vez por fila. Medido: la unión pasa de **134 ms a 85 ms** al
quitarlos — **~35 % del costo**.

**Arreglo:** un solo `LEFT JOIN` a un agregado, mismos totales (verificado):
```sql
LEFT JOIN (SELECT factura_erp_id, COUNT(*) n, SUM(saldo) saldo
             FROM notas_credito_lineas
            WHERE estado_aplicacion = 'aplicable'
            GROUP BY factura_erp_id) nv ON nv.factura_erp_id = pe.id
```
Va por `idx_nc_linea_factura_erp`. Barato y sin cambiar la salida.

### P2 — La campana escribe en el camino de lectura de CADA página

`/avisos/resumen` se pide en **toda carga de cualquier pantalla** (la campana del
topbar), y ahí dentro corre `generarRecordatorios()`, que **escribe** (deja avisos,
hace `UPDATE … SET avisado_en`). Un `GET` que se dispara en cada navegación y que
en el peor caso escribe no es solo lento: es contención de escritura repartida por
toda la aplicación, y el motivo de los defectos de concurrencia `C2`/`R5`.

**Arreglo, por orden de esfuerzo:**
1. Correr `generarRecordatorios()` **como mucho una vez cada N minutos** por
   proceso/servidor (una marca en `storage/cache/` con la hora del último barrido),
   no en cada `GET`.
2. Mejor: sacarlo del camino web y ponerlo en un `cli/` con el Programador de
   tareas. La memoria del proyecto dice que hoy no hay tarea programada y por eso
   se hace así; una entrada del Programador que corra `cli/recordatorios.php`
   cada 10–15 min lo resuelve y quita escritura de todas las páginas.

### P4 — `dimensionesParaFiltro()` recorre la unión entera para llenar dos desplegables

157 ms por carga para poblar "Proveedor" y "Sucursal". Con la tabla-cola
persistente (P1.3) sale de un `GROUP BY` con índice. Mientras tanto, se puede
**cachear en sesión por (sociedad, modo)** e invalidar al escribir: los proveedores
de la cola no cambian entre una página y la siguiente.

### L1 — La exportación baja 200 filas, no 5 000 *(prueba: `SeguimientoFiltrosBordeTest`)*

`exportar()` pide `cola($filtros, 1, self::TOPE_EXPORTACION)` con
`TOPE_EXPORTACION = 5000`, pero `cola()` **acota** el tamaño de página a 200:
```php
$porPagina = max(10, min(200, (int) $porPagina));
```
El Excel sale con 200 renglones aunque la cola tenga miles, en silencio. Hay que
separar el tope de exportación del tope de página (un parámetro que permita el
tope alto solo en la exportación, o una consulta propia sin `min(200, …)`).
Memoria medida de 5 000 filas: +7,5 MB, pico 10,2 MB — sin problema para el
`memory_limit` de 512 MB.

---

## 2. Concurrencia

Es "la cola única de trabajo": el caso normal es varias personas sobre los mismos
renglones. Hoy no hay ningún bloqueo que lo ordene.

### C1 — Lectura-y-escritura sin bloqueo: se pierden decisiones *(prueba: `…ConcurrenciaTest`)*

`aplicar()` hace, dentro de la transacción: (1) lee el estado anterior, (2)
`upsert`, (3) escribe la bitácora. Entre (1) y (2) no hay candado. Si Ana y Beto
mueven el mismo renglón a la vez, los dos leen el estado viejo y la bitácora queda
**sin encadenar**: dos renglones que dicen "salió de `revision`" cuando en verdad
uno salió de lo que dejó el otro. El estado final es el del último `upsert`, pero
la historia miente y la decisión de quien perdió se traga sin aviso.

**Arreglo:** leer el estado anterior con `SELECT … FOR UPDATE` dentro de la misma
transacción (ya está abierta), o tomar la fila por su clave única antes de decidir.
Con `REPEATABLE-READ` (el nivel actual, verificado) el `FOR UPDATE` serializa a
los dos que tocan la misma fila.

### C2 / R5 — Recordatorios duplicados y N+1 en la campana *(pruebas: `…Concurrencia`, `…AplicarBorde`)*

`generarRecordatorios()` lee los vencidos y **después** marca `avisado_en`, sin
candado. Dos cargas simultáneas (P2) leen el mismo lote y **avisan dos veces** el
mismo pendiente. Además, cada aviso es un `INSERT` suelto: 50 vencidos = 50 viajes
a la base (R5), y el cierre de avisos al salir de revisión es un `UPDATE` por
renglón: 50 seleccionados = 50 viajes (R4).

**Arreglo:**
- Tomar un `GET_LOCK('seguimiento_recordatorios', 0)` al entrar; si no se obtiene,
  otro proceso ya está barriendo y este se salta el barrido. Resuelve C2 y, junto
  con P2, quita el trabajo repetido.
- Marcar `avisado_en` en el mismo `UPDATE … WHERE …` que selecciona (o al menos
  un `UPDATE` con `id IN (…)`, que ya se hace para la marca — falta hacerlo para
  los `INSERT` de avisos, agrupándolos).

### C3 / C4 — Orden de escritura y reintento de interbloqueo *(prueba: `…Concurrencia`)*

- **C3:** el `upsert` en tanda escribe los renglones en el orden en que llegan del
  navegador. Dos tandas que se solapan y se cruzan pueden interbloquearse. Escribir
  siempre en orden fijo (`ORDER BY origen, referencia_id` antes del `INSERT`)
  elimina el ciclo.
- **C4:** un interbloqueo (`SQLSTATE 40001 / 1213`) hoy sube tal cual y la persona
  ve "no se pudo guardar" después de seleccionar 50 renglones. Reintentar la
  transacción 2–3 veces ante `1213`/`1205` antes de rendirse.

### S5 — Una tanda no tiene tope *(prueba: `…AplicarBorde`)*

`aplicar()` acepta cualquier cantidad de items. 20 000 renglones seleccionados
(un "marcar todo" sobre la cola entera) arman `INSERT` gigantes y una transacción
larga que bloquea a todos los demás. Poner un tope por tanda (p. ej. 2 000) y
rechazar por encima con un mensaje claro.

---

## 3. Memoria

Sin hallazgos graves. La página trae 50 filas; la exportación, hasta 5 000 (medido
en +7,5 MB, pico 10,2 MB, muy por debajo de los 512 MB). Los `array_chunk(…, 200/300)`
del `upsert`, la bitácora y las lecturas de estado ya evitan armar sentencias
inmanejables. El único riesgo de memoria es indirecto: `S5` (tanda sin tope)
podría crecer sin límite.

---

## 4. Seguridad

### S1 — Fuga de datos entre empresas por lectura (IDOR) *(prueba: `…SeguridadTest`)*

`detalle()` (`GET /seguimiento/detalle?origen=…&referencia_id=…`) llama a
`uno($origen, $ref)`, que **no filtra por sociedad**:
```php
$fila = $this->fetchOne($this->sqlBase() . ' WHERE c.origen = ? AND c.referencia_id = ? LIMIT 1', …);
```
Cualquier usuario autenticado puede leer el expediente completo —proveedor, montos,
saldos, el motivo escrito a mano, la bitácora— de un documento de **otra empresa**
cambiando el `referencia_id` en la URL. La cola en pantalla sí se acota por
`sociedad_id` (`condiciones()`), pero el detalle se saltó ese filtro.

**Malicioso de prueba:** `GET /seguimiento/detalle?origen=factura&referencia_id=1`
recorriendo ids 1..N con una sesión de una empresa que no es la dueña.

**Arreglo:** que `uno()` reciba y aplique `sociedad_id` (o compruebe
`Permisos::puedeSociedad($fila['sociedad_id'])` antes de responder). Esto es lo
mismo que ya hace `SociedadesController::activar()` con `puedeSociedad()`.

### S2 — Escritura entre empresas / sobre renglones inexistentes (IDOR) *(prueba: `…SeguridadTest`)*

`aplicar()` hace `INSERT … ON DUPLICATE KEY UPDATE` por `(origen, referencia_id)`
**sin comprobar** que ese renglón exista en la cola de la empresa activa. Un POST
con `items[]=factura|<id de otra empresa>` crea/actualiza la marca de seguimiento
y la bitácora de un documento ajeno. La clave única evita colisiones de tabla,
pero no comprueba pertenencia.

**Arreglo:** antes del `upsert`, resolver los items contra la cola de la sociedad
activa (un `SELECT … FROM (union) WHERE sociedad_id = ? AND (origen,referencia_id) IN (…)`)
y descartar los que no pertenezcan.

### S3 — Sin protección CSRF en las acciones que cambian estado *(prueba: `…SeguridadTest`)*

`config.php` declara `'csrf_protection' => true`, pero solo el login lo usa
(`AuthController`). `POST /seguimiento/actualizar` cambia estados, escribe
bitácora y programa recordatorios **sin token**. La cookie de sesión es
`SameSite=Lax` (verificado), lo que frena el CSRF por formulario de terceros pero
**no** protege peticiones de nivel superior ni escenarios same-site; y `Lax` es la
única barrera, sin defensa en profundidad. Cualquier página que consiga que el
navegador de alguien con sesión mande el POST puede mover la cola.

**Arreglo:** emitir un token CSRF por sesión y exigirlo en todos los POST que
escriben (Seguimiento y el resto). Ya existe el patrón en `AuthController`
(`hash_equals`), falta generalizarlo — idealmente en `Router`/`Controller`, no
pantalla por pantalla.

### S4 — El error de la base llega crudo al navegador *(prueba: `…SeguridadTest`)*

`actualizar()` responde `['message' => $e->getMessage()]` con 422 ante cualquier
`Throwable`. Si el que falla es PDO, el navegador recibe el `SQLSTATE`, el número
de error y el nombre de la columna:
`SQLSTATE[22001]: … Data too long for column 'motivo' at row 1`. Es filtración de
estructura interna, útil para un atacante y confuso para el usuario. (En
`environment=production` PHP calla los avisos, pero **este mensaje no es un aviso
de PHP**: es texto que el controlador mete a propósito en el JSON.)

**Arreglo:** distinguir las excepciones "de negocio" (mensaje pensado para la
persona, se muestra) de las de infraestructura (`PDOException` → mensaje genérico
"No se pudo guardar, intentá de nuevo" + `registrarFallo()` al log). Va de la mano
con `L5` (validar longitudes antes de tocar la base).

### Inyección SQL — resistente (verificado, no es un defecto)

Se probaron 9 cargas de inyección (`' OR '1'='1' --`, `; DROP TABLE`,
`UNION SELECT … FROM usuarios`, byte nulo, comillas en masa) contra **los 24
filtros** de la URL en los dos modos, contra la selección de items y contra los
textos libres (motivo, comentario, responsable). **Todo viaja como parámetro
preparado**; nada se interpola. `LIMIT/OFFSET` se interpolan pero son enteros ya
acotados (salvo `S7`, abajo). `normalizarItems()` descarta orígenes e ids no
numéricos. Bien.

### XSS — resistente en la vista (verificado)

Se pintó la vista completa, en los dos modos, con `<script>`, `<img onerror>` y
`<svg onload>` metidos en cada dato (documento, proveedor, sucursal, motivo,
consecutivo, bitácora, `sistema_doc`…). Todo sale escapado con `htmlspecialchars`.
El JavaScript del expediente y de la campana usa una función `esc()` propia antes
de `innerHTML`. Bien. **Mantener la regla:** cualquier dato nuevo que entre al
`innerHTML` del panel tiene que pasar por `esc()`.

### S6 — Los comodines de `LIKE` no se escapan *(prueba: `…FiltrosBordeTest`)*

Buscar `50%_A` en el buscador arma `LIKE '%50%_A%'`, donde `%` y `_` son comodines
de SQL, no texto. No es una vulnerabilidad grave, pero da resultados desconcertantes
(y un `%%%%%` sobre la unión es trabajo extra gratis). Escapar `\ % _` en los
términos de `LIKE`.

### S7 — Desbordamiento del número de página *(prueba: `…FiltrosBordeTest`)*

`?pagina=99999999999999999999` → `(int)` satura a `PHP_INT_MAX`, y
`($pagina-1)*50` desborda a **float**, que se interpola en `LIMIT 50 OFFSET 4.6e20`.
MariaDB lo rechaza y la pantalla revienta en vez de mostrar la última página.
Acotar la página a `paginas` antes de calcular el offset.

---

## 5. Robustez (borde y valores nulos)

Defectos que no son de seguridad pero rompen o confunden. Todos con prueba.

| Código | Qué pasa | Dónde |
|---|---|---|
| `L2` | La **primera** acción sobre un renglón que nadie tocó lanza `Undefined array key` en desarrollo (en producción PHP lo calla y funciona de casualidad). `anotar()` lee `$antes[$clave]` de un mapa que no trae la clave nueva. | `Seguimiento::anotar` |
| `L3` | Una marca de otro modo (`lista` sobre un `xml_*`) no se valida en `aplicar()` contra el origen del renglón, solo contra el modo de la instancia. | `Seguimiento::aplicar` |
| `L4` | Un motivo de espacios invisibles (`\u00A0`, `\u200B`) pasa el "hay que describir el problema": `trim()` no los quita. Se manda a revisión sin motivo real. | `Seguimiento::aplicar` |
| `L5` | Motivo > 255 o comentario > 64 KB no se validan antes del `INSERT`: la base los corta o falla (ver `S4`). | `Seguimiento::aplicar` |
| `L6` | `referencia_id` con decimales/`1e3`/fuera de rango INT UNSIGNED: `is_numeric('1e3')` es `true` y `(int)` da `1000` → toca un renglón que nadie eligió. | `normalizarItems` |
| `L7` | Un `['origen' => ['x'], …]` (arreglo donde va texto) lanza `Array to string conversion` y tumba la tanda entera. | `normalizarItems` |
| `L8` | Fecha imposible (`2026-02-30`) pasa el regex y filtra: la cola sale vacía sin nada que lo explique. Falta `checkdate()`. | `filtrosDesde` |
| `L9` | Importe con bytes no-UTF-8 hace fallar `preg_replace` (devuelve `null`) → `Passing null to preg_match` deprecated/error. | `BusquedaImporte::numero` |
| `L11` | Un recordatorio enviado junto a un estado que no es `revision` se guarda igual: queda mudo y "resucita" al volver el renglón a revisión. | `Seguimiento::aplicar` |
| `L12` | No hay forma de saber si vaciar el motivo de algo en revisión dejó rastro (ni se rechaza ni se anota). | `Seguimiento::aplicar` |

El patrón de fondo de `L2`, `L7` y `L9`: **en producción `error_reporting(0)`
esconde estos fallos**, así que "funciona" hasta que alguien abre la misma pantalla
en una copia de desarrollo o de certificación y revienta. Las pruebas convierten
los avisos en excepciones a propósito, como hace `App` en desarrollo.

---

## 6. Las pruebas añadidas

Sin base de datos (dobles en memoria), corren con el certificador existente
(`scripts/verificar-php84.ps1`) y en PHP 8.4 con `error_reporting=E_ALL`.

| Archivo | Cubre | Casos | Defectos abiertos |
|---|---|---:|---:|
| `tests/soporte/SeguimientoSoporte.php` | arnés, PDO/almacén/sesión falsos | — | — |
| `tests/SeguimientoFiltrosBordeTest.php` | lectura de filtros, SQL, paginación, resumen | 35 | 0 (eran 5) |
| `tests/SeguimientoAplicarBordeTest.php` | selección, escritura, bitácora, recordatorios | 41 | 0 (eran 11) |
| `tests/SeguimientoControladorBordeTest.php` | POST/GET, recordatorio, `decorar()`, expediente | 17 | 0 |
| `tests/SeguimientoSeguridadTest.php` | inyección SQL, XSS, IDOR, CSRF, fuga de errores | 10 | 0 (eran 4) |
| `tests/SeguimientoConcurrenciaTest.php` | escrituras simultáneas, N+1, interbloqueo | 7 | 0 (eran 4) |
| `tests/CsrfEnrutadorTest.php` | la guardia CSRF del enrutador, para todas las rutas | 12 escenarios | — |
| `tests/CsrfTokenTest.js` | el token en fetch/XHR/formularios de `app.js` (`node`) | 19 comprobaciones | — |

**Ojo con el certificador:** hasta el 2026-09-15 todas las pruebas de
Seguimiento salían FAIL en `verificar-php84.ps1` sin que fuera culpa de ellas:
el certificador corre con `display_errors=1` y tres deprecaciones de PHP 8.4
(`AplicacionNotaCredito::estado()`, `RecuperadorDocumentos::__construct()`,
`str_getcsv` en `ProveedoresErpCsvParser`) ensuciaban la salida. Ya están
corregidas.

**Caso feliz, borde, nulos:** cubiertos en los tres primeros (petición vacía, todo
`null`, arreglos donde va texto, y el camino completo bien formado).

**El arnés separa dos cosas:** un `caso()` **tiene** que pasar (es el
comportamiento correcto de hoy, y queda protegido). Un `defecto()` afirma el
comportamiento **correcto** de algo que hoy está **mal**: sale como
`DEFECTO ABIERTO` y no rompe la certificación; cuando se arregle el defecto, la
prueba falla y obliga a ascenderlo a `caso()`. Así la lista de arriba y las
pruebas no pueden desincronizarse.

Correr solo lo de Seguimiento:
```
Get-ChildItem tests\Seguimiento*Test.php | ForEach-Object { & C:\WebServer\PHP84\php.exe -d error_reporting=E_ALL $_.FullName }
```

---

## 7. Diez casos de un usuario exigente y caótico

Pensados como los haría alguien real trabajando la cola a las apuradas, no como
pruebas unitarias. La mayoría tiene su defecto arriba.

| # | Lo que hace la persona | Qué debería pasar | Qué pasa hoy | Ref |
|---|---|---|---|---|
| 1 | Pone "Emitido desde" en 31 y "hasta" en 1 del mismo mes | Se entiende al revés y muestra el mes | ✔ Se endereza solo | `L8` (ok) |
| 2 | Escribe `30/02/2026` en la fecha (o la cola queda vacía "sin razón") | Avisar que la fecha no existe, o ignorarla | La cola sale vacía en silencio | `L8` |
| 3 | Marca "todo" en la cola entera (miles) y le da a "Cerrar" | Un tope con aviso, o hacerlo por lotes | Transacción gigante que bloquea a todos | `S5` |
| 4 | Manda a revisión con el motivo puesto a puros espacios (o pega un carácter invisible) | Exigir un motivo de verdad | Lo acepta: revisión sin motivo | `L4` |
| 5 | Copia el saldo de la pantalla (`₡1.200,50 %`) al buscador de importe | Buscar ese importe, o nada | El `%` se vuelve comodín de SQL | `S6` |
| 6 | Dos personas mueven el mismo renglón (una a "lista", otra a "cerrada") a la vez | La bitácora encadena las dos decisiones | Se pierde una, la bitácora miente | `C1` |
| 7 | Abre el expediente y en la URL cambia `referencia_id` a uno de otra empresa | 403 / "no existe" | Le muestra el documento y su bitácora ajenos | `S1` |
| 8 | Pega el motivo de un correo largo (300+ caracteres) al mandar a revisión | Avisar que no cabe, recortar con criterio | Error crudo `SQLSTATE… column 'motivo'` | `L5`,`S4` |
| 9 | Salta a la página `99999999999999999999` desde la URL | La última página | La pantalla revienta (`OFFSET 4.6e20`) | `S7` |
| 10 | Deja la campana sonando y navega rápido entre pantallas | Un aviso por pendiente | Recordatorios duplicados; escritura en cada carga | `C2`,`P2` |

---

## 8. Por dónde empezar

**Primero (seguridad, barato, alto impacto):**
- `S1` acotar `detalle()`/`uno()` por sociedad — es fuga de datos entre empresas.
- `S2` comprobar pertenencia antes de escribir.
- `S4` no devolver el error de PDO al navegador.
- `S3` token CSRF en los POST que escriben (generalizado, no solo aquí).

**Segundo (rendimiento, esfuerzo medio):**
- `P3` `notas_vivas` por JOIN agregado (−35 % de la unión, sin riesgo).
- `P1.1` `COUNT(*) OVER ()` para una consulta menos por carga.
- `P2` sacar `generarRecordatorios()` del camino de cada página.

**Tercero (escalabilidad, si el uso concurrente crece):**
- `P1.2/1.3` materializar la cola (temporal por carga, o tabla persistente).
  Es lo único que sostiene 12 personas a la vez; la prueba de carga muestra que
  hoy no llega.

**En paralelo (robustez):** `L2`–`L12`, guiados por las pruebas — cada uno deja
de ser "DEFECTO ABIERTO" cuando se arregla.

---

## 9. Cuándo hay que retomar P1.3

P1.2 y P1.3 quedaron descartados el 2026-09-15, y conviene que quede escrito
**por qué**, para que nadie los reabra por costumbre ni los olvide por descuido.

**Por qué no se hacen.** El uso esperado son **3–4 personas** trabajando la cola
a la vez. En esa fila de la prueba de carga la pantalla abre en **900 ms p50 /
973 ms p95**, parejos. P1.3 existe para aplanar la curva a partir de ~8 personas;
a 4 se pagaría una tabla nueva, un refresco que mantener y un atraso frente al
ERP que explicarle a la oficina, a cambio de milisegundos que nadie nota.
P1.2 tampoco aporta: su ganancia ya se cobró con P1.1, P3 y P4.

**Cuándo sí.** Si se cumple cualquiera de estas, vale la pena volver:

1. La pantalla de Seguimiento pasa de **~2 s** con la gente trabajando normal.
2. Se suman **más de 8 personas** a la cola al mismo tiempo.
3. La cola crece bastante por encima de las **~11 420 filas** de hoy (la unión se
   recorre entera en cada carga, así que el costo sube con el tamaño).

**Antes de meter mano, volver a medir.** Las cifras de este documento son con la
base al lado; en producción hay que sumarle ~85–100 ms por viaje y son 2 viajes
por carga. Repetir la prueba de carga antes de decidir: puede que el problema
esté en la red y no en la consulta, y entonces P1.3 no arregla nada.
