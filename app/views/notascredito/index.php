<?php
// Los nombres de las clases de nota salen de acá, y se usan antes de incluir
// el parcial que también lo pide.
require_once __DIR__ . '/../../helpers/ClaseNotaCredito.php';
// La situación de cada nota frente a su factura: etiquetas y colores.
require_once __DIR__ . '/../../helpers/AplicacionNotaCredito.php';
require_once __DIR__ . '/../../helpers/AlcanceProveedor.php';

$baseUrl = defined('APP_URL') ? APP_URL : '/xmlconcilia/public';
$listados = is_array($listados ?? null) ? $listados : [];
$listado = $listado ?? null;
$lineas = is_array($lineas ?? null) ? $lineas : [];
$resumen = is_array($resumen ?? null) ? $resumen : [];
$filtros = is_array($filtros ?? null) ? $filtros : [];
$filtrosActivos = 0;
foreach ($filtros as $claveFiltro => $valorFiltro) {
    // 'alcance' no es un filtro: es la respuesta a "¿de qué proveedor?", y
    // contarla hacía que pedir el listado entero dijera "1 filtro".
    if ($claveFiltro === AlcanceProveedor::PARAM) { continue; }
    if ((string) $valorFiltro !== '') { $filtrosActivos++; }
}
unset($claveFiltro, $valorFiltro);
$opciones = is_array($opciones ?? null) ? $opciones : ['sucursales' => []];
$proveedoresFiltro = is_array($proveedoresFiltro ?? null) ? $proveedoresFiltro : [];
$paginacion = is_array($paginacion ?? null) ? $paginacion : ['page' => 1, 'pages' => 1, 'total' => 0];

function ncFecha($value) {
    $time = $value ? strtotime((string) $value) : false;
    return $time ? date('d/m/Y', $time) : '—';
}
function ncMonto($value, $moneda = 'CRC') {
    return ($moneda === 'USD' ? '$' : '₡') . number_format((float) $value, 2, '.', ',');
}
/**
 * Si el XML está a nombre del mismo proveedor que la nota.
 *
 * Se compara por los primeros doce caracteres útiles porque los dos nombres
 * salen de sitios distintos —el reporte del ERP y el comprobante de Hacienda—
 * y difieren en la forma societaria, los puntos y los acentos: "CENTRO TEXTIL
 * JOSE BEFELER S.A." contra "Centro Textil Jose Befeler Sociedad Anónima" es
 * el mismo proveedor y ninguna comparación literal lo diría.
 *
 * Tiene un gemelo en JavaScript (ncMismoProveedor, más abajo) porque esta
 * tabla se repinta en vivo al filtrar; las dos aplican la misma regla.
 */
function ncMismoProveedor($nota, $xml) {
    $clave = static function ($texto) {
        $texto = mb_strtoupper(trim((string) $texto), 'UTF-8');
        return mb_substr((string) preg_replace('/[^A-Z0-9]/u', '', $texto), 0, 12);
    };
    $a = $clave($nota);
    $b = $clave($xml);
    // Sin nombre no hay nada que contradecir: no se avisa de lo que no se sabe.
    return $b === '' || $a === $b;
}
function ncQuery(array $changes = []) {
    $current = $_GET;
    foreach ($changes as $key => $value) {
        if ($value === null || $value === '') unset($current[$key]);
        else $current[$key] = $value;
    }
    return http_build_query($current);
}
?>

<style>
.nc-table-wrap{overflow:auto;max-height:68vh;border:1px solid var(--border);border-radius:9px}
.nc-table{min-width:1120px}.nc-table thead th{position:sticky;top:0;z-index:3}.nc-table td{vertical-align:top}
/* El encabezado y sus filtros, en una sola fila.  Antes eran dos —los rótulos
   arriba y las casillas abajo— y la de abajo se pegaba a 29 px medidos a ojo:
   en cuanto un rótulo ocupaba dos líneas, una fila tapaba a la otra. Ahora el
   rótulo es el nombre del propio campo, así que no hay medida que adivinar. */
.nc-head th{vertical-align:bottom;background:#f3f7fb;padding:9px 10px 10px;white-space:normal;border-bottom:2px solid var(--border)}
.nc-filtros{display:grid;grid-template-columns:repeat(2,minmax(96px,1fr));gap:7px 8px}
.nc-filtros.nc-una{grid-template-columns:minmax(96px,1fr)}
.nc-filtros.nc-fechas{grid-template-columns:repeat(2,minmax(108px,1fr))}
.nc-f{display:flex;flex-direction:column;gap:3px;min-width:0}
.nc-f.nc-ancho{grid-column:1/-1}
/* El rótulo de la columna es el del primer campo; los demás dicen qué dato
   buscan, que antes solo se leía dentro de la casilla y se iba al escribir. */
.nc-f>span,.nc-col-title{display:block;font-size:9.5px;line-height:13px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.nc-f>span.nc-ttl,.nc-col-title{font-size:10.5px;font-weight:800;color:var(--navy)}
.nc-f input,.nc-f select{width:100%;min-width:0;height:26px;border:1px solid #cbd5e1;border-radius:4px;background:#fff;padding:0 6px;font-family:inherit;font-size:11px;font-weight:600;letter-spacing:0;text-transform:none;color:var(--navy);outline:none}
.nc-f input:hover,.nc-f select:hover{border-color:#94a3b8}
.nc-f input:focus,.nc-f select:focus{border-color:var(--navy);box-shadow:0 0 0 2px rgba(212,160,23,.18)}
.nc-f input.nc-num{text-align:right}
.nc-f input[type=date]{padding-right:2px}
.nc-f input[type=date]::-webkit-calendar-picker-indicator{opacity:.5;padding:0;margin:0}
/* La casilla con algo puesto se marca sola: el filtro aplicado se ve de lejos */
.nc-f .nc-lleno{border-color:var(--gold);background:#fffdf5}
.nc-clear-col{margin-top:7px;display:inline-flex;align-items:center;gap:5px;border:1px solid #cbd5e1;border-radius:4px;background:#fff;padding:4px 8px;font-family:inherit;font-size:9.5px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--navy);cursor:pointer}
.nc-clear-col:hover{border-color:var(--gold);background:#fffdf5}
/* El display de arriba le gana al hidden del navegador: hay que repetirlo. */
.nc-clear-col[hidden]{display:none}
/* La columna guarda el lugar del botón para que la tabla no salte al mostrarlo */
.nc-head th:last-child{min-width:134px}
.nc-doc{max-width:230px;overflow-wrap:anywhere;font-weight:650;color:var(--navy)}
.nc-provider{max-width:245px;white-space:normal}.nc-reason{font-size:10.5px;color:#64748b;max-width:190px;white-space:normal;margin-top:4px}
/* La insignia de estado hace de botón: mismo aspecto, y se puede apretar. */
.nc-estado-btn{cursor:pointer;font-family:inherit;border:0;white-space:nowrap}
.nc-estado-btn:hover{filter:brightness(.94)}
.nc-estado-btn:focus-visible{outline:2px solid var(--gold);outline-offset:2px}

/* La segunda línea de una celda: fecha, saldo, total del XML, insignias */
.nc-sub{font-size:10.5px;color:#64748b;margin-top:3px;display:flex;gap:5px;align-items:center;flex-wrap:wrap;font-weight:400}
.nc-sub-der{justify-content:flex-end}
.nc-dif{color:#dc2626;font-weight:800}
.nc-badge-mini{font-size:9.5px;padding:1px 6px;white-space:nowrap}
/* El XML a nombre de otro proveedor: antes se escribía siempre y por eso no
   se notaba; ahora solo sale cuando no coincide, y sale marcado. */
.nc-otro-proveedor{color:#92400e;font-weight:700}

.nc-detail-context{padding:9px 11px;border:1px solid var(--border);border-radius:8px;background:#f8fafc;margin-bottom:10px}
.nc-detail-context strong{display:block;color:var(--navy);font-size:13px;overflow-wrap:anywhere}
.nc-detail-context span{display:block;color:var(--text-muted);font-size:11px;margin-top:3px;overflow-wrap:anywhere}
.nc-detail-reason{font-size:13px;line-height:1.55;color:var(--text);white-space:pre-wrap;overflow-wrap:anywhere}
.nc-actions{display:flex;gap:4px;align-items:center;flex-wrap:wrap;min-width:150px}
.nc-history-grid{display:grid;grid-template-columns:repeat(4,minmax(120px,1fr));gap:6px;margin:8px 0}
.nc-history-stat{border:1px solid var(--border);border-radius:7px;padding:7px 9px;background:#f8fafc}.nc-history-stat strong{display:block;font-size:17px;color:var(--navy)}.nc-history-stat span{font-size:9.5px;text-transform:uppercase;color:var(--text-muted);font-weight:700}
.nc-transition{white-space:nowrap;font-weight:700}.nc-arrow{color:#94a3b8;margin:0 5px}
.nc-modal{display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:1200;padding:2vh 2vw;overflow:auto}
.nc-modal.open{display:block}.nc-modal-panel{background:#fff;border-radius:12px;max-width:1100px;margin:auto;box-shadow:0 20px 60px rgba(0,0,0,.3);overflow:hidden}
.nc-modal-head{padding:9px 13px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px}.nc-modal-body{padding:10px 13px;max-height:78vh;overflow:auto}
.nc-close{margin-left:auto;background:none;border:0;font-size:23px;color:#64748b;cursor:pointer}
.nc-period{font-size:11px;color:var(--text-muted)}
</style>

<div class="card mb-20">
    <div class="card-header mb-12" style="flex-wrap:wrap;">
        <div class="card-title"><i class="fas fa-file-circle-minus" style="color:var(--gold);margin-right:6px;"></i>Notas de crédito acumuladas</div>
        <?php if ((int) ($revisionPendientes ?? 0) > 0): ?>
        <a href="<?= $baseUrl ?>/notas-credito/revision" class="btn btn-outline btn-sm"
           style="margin-left:auto;border-color:#f59e0b;color:#92400e;"
           title="Filas del reporte que no se pudieron leer y esperan que decidas si entran">
            <i class="fas fa-inbox" style="margin-right:4px;color:#d97706;"></i>En revisión
            <span class="badge" style="font-size:10px;padding:1px 6px;margin-left:4px;background:#fef3c7;color:#92400e;">
                <?= number_format((int) $revisionPendientes) ?>
            </span>
        </a>
        <?php endif; ?>
        <a href="<?= $baseUrl ?>/notas-xml" class="btn btn-primary btn-sm"
           style="<?= (int) ($revisionPendientes ?? 0) > 0 ? '' : 'margin-left:auto;' ?>"
           title="Importar comprobantes XML de notas de crédito">
            <i class="fas fa-file-code" style="margin-right:4px;"></i>Cargar notas XML
        </a>
    </div>
    <?php if (empty($sociedadActiva)): ?>
        <div class="alert alert-warning">Debes registrar y activar una sociedad desde Inicio antes de cargar un listado.</div>
    <?php else: ?>
        <div style="font-size:12px;color:var(--text-muted);margin-bottom:10px;">
            Sociedad: <strong style="color:var(--navy);"><?= htmlspecialchars($sociedadActiva['nombre']) ?></strong>
        </div>
        <?php /*
         * El CSV se sube acá y no en una pantalla aparte: lo que el archivo
         * actualiza es la tabla de abajo, así que cargar y comprobar el
         * resultado es el mismo sitio.
         */ ?>
        <form id="form-nc" action="<?= $baseUrl ?>/notas-credito/previa" method="POST"
              enctype="multipart/form-data" style="display:flex;gap:9px;align-items:center;flex-wrap:wrap;">
            <input type="file" name="listado_file" id="nc-archivo" accept=".csv" required style="display:none;">
            <label for="nc-archivo" class="upload-file-btn" style="padding:8px 16px;font-size:12.5px;">
                <i class="fas fa-folder-open"></i> Seleccionar CSV
            </label>
            <span id="nc-archivo-nombre" style="font-size:12px;color:var(--text-muted);font-style:italic;">
                Ningún archivo seleccionado
            </span>
            <button type="submit" class="btn btn-primary btn-sm" id="nc-btn">
                <i class="fas fa-eye" style="margin-right:4px;"></i>Vista previa
            </button>
        </form>
    <?php endif; ?>
</div>

<?php /*
 * Acá vivía una fila de cuatro cifras: total, coinciden, con diferencia y sin
 * respaldo. La tabla de abajo las dice una por una y el filtro de la columna
 * Estado lleva a cada grupo. El total sigue vivo donde sirve: en "N de M
 * notas conservadas", que además se actualiza con cada búsqueda.
 */ ?>
<?php if ($listado && ($elegirProveedor ?? false)):
/*
 * Sin líneas: el controlador no las pidió. Esta pantalla no pagina —las manda
 * todas de una vez— así que es la que más gana con preguntar antes.
 */
$elegirProv = [
    'accion'   => $baseUrl . '/notas-credito',
    'opciones' => $proveedoresFiltro,
    'cuantos'  => $totalDelArchivo ?? null,
    'que'      => 'líneas de nota',
    // El listado que se está mirando tiene que sobrevivir a la pregunta.
    'ocultos'  => ['listado_id' => (int) $listado['id']],
];
include __DIR__ . '/../partials/elegir-proveedor.php';
?>
<?php elseif ($listado): ?>
<div class="card">
    <?php /*
     * Encabezado al modo de Facturas: el título, debajo de qué carga viene lo
     * que se está mirando y, a la derecha, lo que se puede hacer. Era una
     * tarjeta suelta que repetía el total y ocupaba una franja entera.
     */ ?>
    <div class="card-header" style="flex-wrap:wrap;">
        <div>
            <div class="card-title">
                <i class="fas fa-list" style="margin-right:6px;color:var(--navy-light);"></i>Notas del acumulado
            </div>
            <div class="nc-period" style="margin-top:3px;">
                Última carga: <?= htmlspecialchars($listado['archivo_origen']) ?> ·
                Empresa del reporte: <?= htmlspecialchars($listado['empresa_reporte'] ?: '—') ?> ·
                Período: <?= ncFecha($listado['periodo_desde']) ?> al <?= ncFecha($listado['periodo_hasta']) ?>
            </div>
        </div>
        <button type="button" class="btn btn-outline btn-sm" id="nc-history-open"
                style="margin-left:auto;" data-listado="<?= (int) $listado['id'] ?>"
                title="Cuándo se verificó el acumulado y qué cambió en cada verificación">
            <i class="fas fa-clock-rotate-left"></i> Historial
        </button>
    </div>

    <form method="GET" action="<?= $baseUrl ?>/notas-credito" class="filter-bar" id="nc-filter-form">
        <input type="hidden" name="listado_id" value="<?= (int) $listado['id'] ?>">
        <?php
        /*
         * Proveedor, documento, clase, sucursal y estado. "NC proveedor
         * (con/sin)" salió de la barra: la fila de buscadores de la tabla
         * tiene su propia columna, y ahí se ve además cuál es.
         */
        $provFiltro = [
            'valor'    => $filtros['proveedor'] ?? '',
            'opciones' => $proveedoresFiltro ?? [],
        ]; include __DIR__ . '/../partials/filtro-proveedor.php';
        ?>
        <div class="filter-span-2">
            <label class="filter-label">Buscar</label>
            <input type="search" class="form-control" name="q" value="<?= htmlspecialchars($filtros['q'] ?? '') ?>"
                   placeholder="Documento, NC proveedor, entrada o proveedor">
        </div>
        <?php
        /*
         * Las cinco clases, incluida 'ajuste': acá se ve el acumulado entero,
         * no solo lo que se persigue. Se eligen varias a la vez y el buscador
         * en vivo se entera solo, porque lo elegido viaja en el campo
         * escondido del propio formulario.
         */
        $claseFiltro = [
            'valor'  => $filtros['clase'] ?? '',
            'clases' => ClaseNotaCredito::ETIQUETAS,
            'id'     => 'nc-clase',
        ]; include __DIR__ . '/../partials/filtro-clase.php';
        ?>
        <div>
            <label class="filter-label">Sucursal</label>
            <select class="form-control" name="sucursal">
                <option value="">Todas</option>
                <?php foreach ($opciones['sucursales'] as $option): ?>
                <option value="<?= htmlspecialchars($option['valor']) ?>" <?= ($filtros['sucursal'] ?? '') === $option['valor'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($option['valor']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="filter-label">Estado</label>
            <select class="form-control" name="estado">
                <option value="">Todos</option>
                <option value="coincide" <?= ($filtros['estado'] ?? '') === 'coincide' ? 'selected' : '' ?>>Coincide</option>
                <option value="con_diferencia" <?= ($filtros['estado'] ?? '') === 'con_diferencia' ? 'selected' : '' ?>>Con diferencia</option>
                <option value="sin_respaldo" <?= ($filtros['estado'] ?? '') === 'sin_respaldo' ? 'selected' : '' ?>>Sin respaldo</option>
            </select>
        </div>
        <div>
            <label class="filter-label">Saldo</label>
            <select class="form-control" name="condicion_saldo">
                <option value="">Todos</option>
                <option value="con_saldo" <?= ($filtros['condicion_saldo'] ?? '') === 'con_saldo' ? 'selected' : '' ?>>Con saldo</option>
                <option value="sin_saldo" <?= ($filtros['condicion_saldo'] ?? '') === 'sin_saldo' ? 'selected' : '' ?>>Sin saldo</option>
            </select>
        </div>
        <?php
        /*
         * Los dos importes. Son los MISMOS parámetros que los buscadores de la
         * cabecera de la tabla —'monto' y 'saldo'— a propósito: así hay un
         * solo filtro por concepto, se alcance desde la barra o desde la
         * columna, y los dos enseñan lo que está puesto.
         *
         * El desplegable "Saldo" de al lado dice si la nota tiene saldo o no;
         * este dice cuánto, que es otra pregunta.
         */
        $filtroImporte = [
            'nombre' => 'monto', 'etiqueta' => 'Monto de la nota',
            'valor' => $filtros['monto'] ?? '',
        ]; include __DIR__ . '/../partials/filtro-importe.php';

        $filtroImporte = [
            'nombre' => 'saldo', 'etiqueta' => 'Saldo de la nota',
            'valor' => $filtros['saldo'] ?? '',
        ]; include __DIR__ . '/../partials/filtro-importe.php';
        ?>
        <?php /*
         * Por dónde se entra a "qué puedo aplicar hoy". El estado sale de
         * cruzar el saldo de la nota con el de la factura que corrige, así
         * que se mueve solo cuando se paga una factura, sin que nadie lo
         * marque a mano.
         */ ?>
        <div>
            <label class="filter-label">Situación</label>
            <select class="form-control" name="aplicacion">
                <option value="">Todas</option>
                <?php foreach (AplicacionNotaCredito::ESTADOS as $ncApValor => $ncApInfo):
                    if ($ncApValor === AplicacionNotaCredito::NO_APLICA) { continue; } ?>
                <option value="<?= $ncApValor ?>" <?= ($filtros['aplicacion'] ?? '') === $ncApValor ? 'selected' : '' ?>>
                    <?= htmlspecialchars($ncApInfo[0]) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-actions">
            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Buscar</button>
            <?php if ($filtrosActivos): ?>
            <a href="<?= $baseUrl ?>/notas-credito?listado_id=<?= (int) $listado['id'] ?>&amp;limpiar=1" class="btn btn-outline btn-sm"><i class="fas fa-broom"></i> Limpiar</a>
            <?php endif; ?>
        </div>
    </form>

    <div id="nc-result-status" class="filter-results" style="margin-bottom:12px;">
        <i class="fas fa-filter" style="color:var(--navy-light);"></i>
        <span>
            Mostrando <strong id="nc-result-count"><?= count($lineas) ?></strong> de
            <strong id="nc-list-total"><?= (int) ($resumen['total'] ?? 0) ?></strong> notas conservadas.
            La búsqueda se aplica al acumulado completo.
        </span>
        <?php if ($filtrosActivos): ?>
        <span class="badge badge-navy" style="font-size:10px;"><?= $filtrosActivos ?> filtro<?= $filtrosActivos === 1 ? '' : 's' ?></span>
        <?php endif; ?>
    </div>
    <div class="nc-table-wrap">
        <table class="data-table nc-table">
            <thead>
                <?php /*
                 * Seis columnas, no trece. Las siete que se fueron estaban
                 * vacías o repetidas, contado sobre las 2.613 notas del
                 * acumulado:
                 *
                 *   Sucursal            Nunca vacía, pero con dos valores en
                 *                       total. Una columna entera para eso; va
                 *                       bajo el proveedor.
                 *   NC Proveedor        Vacía en el 63,7 %.  ┐ las dos cuelgan
                 *   Fecha NC proveedor  Vacía en el 63,7 %.  ┘ del documento.
                 *   Saldo               Volvió: en el 49,8 % repite el monto,
                 *                       pero el otro 50 % es lo que decide qué
                 *                       nota se puede aplicar, y colgando del
                 *                       monto no se podía comparar de un
                 *                       vistazo entre renglones.
                 *   Total XML           Vacía en el 80,1 %.  ┐ las dos cuelgan
                 *   Diferencia          Vacía en el 98,8 %.  ┘ del XML.
                 *   Acciones            El botón "Ver detalles" estaba en las
                 *                       2.613 filas; ahora es la propia
                 *                       insignia de estado, que se puede
                 *                       apretar. Vincular y desvincular se
                 *                       quedan, en su columna.
                 *
                 * La tabla pasó de 1.650 px de ancho mínimo a 1.120: deja de
                 * hacer falta correrla de lado para leer un renglón.
                 */ ?>
                <?php
                /*
                 * Los filtros de columna se escriben con su valor puesto: la
                 * tabla de abajo ya viene filtrada por ellos —del enlace o de
                 * lo que el módulo recordó— y dejarlos en blanco haría creer
                 * que no hay ningún filtro aplicado. El JS los vuelve a pisar
                 * solo cuando la clave viene en la URL.
                 */
                $ncCol = static function ($clave) use ($filtros) {
                    return htmlspecialchars((string) ($filtros[$clave] ?? ''));
                };
                ?>
                <?php /*
                 * Ninguna búsqueda se perdió al juntar columnas: cada casilla
                 * está en la celda del dato que busca, y ahora lleva su rótulo
                 * arriba en vez de adentro, donde se iba al escribir.
                 */ ?>
                <tr class="nc-head">
                    <th>
                        <div class="nc-filtros nc-una">
                            <label class="nc-f">
                                <span class="nc-ttl">Estado</span>
                                <select data-nc-filter="col_estado">
                                    <?php foreach ([
                                        ''               => 'Todos',
                                        'coincide'       => 'Coincide',
                                        'con_diferencia' => 'Con diferencia',
                                        'sin_respaldo'   => 'Sin respaldo',
                                    ] as $ncEstadoValor => $ncEstadoTexto): ?>
                                    <option value="<?= $ncEstadoValor ?>"<?= ($filtros['col_estado'] ?? '') === $ncEstadoValor ? ' selected' : '' ?>><?= $ncEstadoTexto ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </div>
                    </th>
                    <th>
                        <div class="nc-filtros">
                            <label class="nc-f">
                                <span class="nc-ttl">Proveedor</span>
                                <input data-nc-filter="proveedor_nombre" value="<?= $ncCol('proveedor_nombre') ?>" aria-label="Buscar proveedor">
                            </label>
                            <label class="nc-f">
                                <span>Sucursal</span>
                                <input data-nc-filter="sucursal_texto" value="<?= $ncCol('sucursal_texto') ?>" aria-label="Buscar sucursal">
                            </label>
                        </div>
                    </th>
                    <th>
                        <div class="nc-filtros nc-fechas">
                            <label class="nc-f">
                                <span class="nc-ttl">Documento</span>
                                <input data-nc-filter="documento" value="<?= $ncCol('documento') ?>" aria-label="Buscar documento">
                            </label>
                            <label class="nc-f">
                                <span>Fecha</span>
                                <input type="date" data-nc-filter="fecha" value="<?= $ncCol('fecha') ?>" aria-label="Buscar fecha">
                            </label>
                            <label class="nc-f">
                                <span>NC del proveedor</span>
                                <input data-nc-filter="nc_proveedor" value="<?= $ncCol('nc_proveedor') ?>" aria-label="Buscar NC proveedor">
                            </label>
                            <label class="nc-f">
                                <span>Fecha NC</span>
                                <input type="date" data-nc-filter="fecha_nc_proveedor" value="<?= $ncCol('fecha_nc_proveedor') ?>" aria-label="Buscar fecha NC proveedor">
                            </label>
                        </div>
                    </th>
                    <th class="right">
                        <div class="nc-filtros nc-una">
                            <label class="nc-f">
                                <span class="nc-ttl">Monto</span>
                                <input class="nc-num" data-nc-filter="monto" value="<?= $ncCol('monto') ?>" inputmode="decimal" aria-label="Buscar monto">
                            </label>
                        </div>
                    </th>
                    <th class="right">
                        <div class="nc-filtros nc-una">
                            <label class="nc-f">
                                <span class="nc-ttl">Saldo</span>
                                <input class="nc-num" data-nc-filter="saldo" value="<?= $ncCol('saldo') ?>" inputmode="decimal" aria-label="Buscar saldo">
                            </label>
                        </div>
                    </th>
                    <th>
                        <div class="nc-filtros">
                            <label class="nc-f nc-ancho">
                                <span class="nc-ttl">NC XML</span>
                                <input data-nc-filter="nc_xml" value="<?= $ncCol('nc_xml') ?>" aria-label="Buscar NC XML">
                            </label>
                            <label class="nc-f">
                                <span>Total XML</span>
                                <input class="nc-num" data-nc-filter="xml_total" value="<?= $ncCol('xml_total') ?>" inputmode="decimal" aria-label="Buscar total XML">
                            </label>
                            <label class="nc-f">
                                <span>Diferencia</span>
                                <input class="nc-num" data-nc-filter="diferencia" value="<?= $ncCol('diferencia') ?>" inputmode="decimal" aria-label="Buscar diferencia">
                            </label>
                        </div>
                    </th>
                    <th>
                        <span class="nc-col-title">Acciones</span>
                        <?php // El botón sale solo cuando hay algo escrito arriba. ?>
                        <button type="button" class="nc-clear-col" id="nc-limpiar-col" hidden>
                            <i class="fas fa-eraser"></i> Limpiar columnas
                        </button>
                    </th>
                </tr>
            </thead>
            <tbody id="nc-lines-body">
            <?php if (empty($lineas)): ?>
                <tr><td colspan="7" style="text-align:center;padding:18px;color:var(--text-muted);">No hay notas con estos filtros.</td></tr>
            <?php endif; ?>
            <?php foreach ($lineas as $row): ?>
                <?php
                $badge = $row['estado'] === 'coincide' ? 'badge-ok' : ($row['estado'] === 'con_diferencia' ? 'badge-diff' : 'badge-miss');
                $label = $row['estado'] === 'coincide' ? 'Coincide' : ($row['estado'] === 'con_diferencia' ? 'Con diferencia' : 'Sin respaldo');
                $motivoMatch = trim((string) ($row['motivo_match'] ?? ''));
                $tieneDetalle = !empty($row['match_manual']) || $motivoMatch !== '';
                ?>
                <?php
                $ncSaldoAparte = abs((float) $row['monto'] - (float) $row['saldo']) >= 0.005;
                $ncSinSaldo = abs((float) $row['saldo']) < 0.005;
                $ncDif = $row['diferencia'] !== null && $row['diferencia'] !== ''
                    ? (float) $row['diferencia'] : null;
                ?>
                <tr>
                    <td>
                        <?php /*
                         * La insignia es el botón. "Ver detalles" estaba debajo
                         * de las 2.613 filas, siempre, así que no distinguía
                         * ninguna: era una segunda línea en cada renglón para
                         * repetir que se puede abrir lo que ya se ve.
                         */ ?>
                        <?php if ($tieneDetalle): ?>
                        <button type="button" class="badge <?= $badge ?> nc-estado-btn nc-detail-btn"
                                data-estado="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>"
                                data-documento="<?= htmlspecialchars((string) $row['documento'], ENT_QUOTES, 'UTF-8') ?>"
                                data-proveedor="<?= htmlspecialchars((string) $row['proveedor_nombre'], ENT_QUOTES, 'UTF-8') ?>"
                                data-motivo="<?= htmlspecialchars($motivoMatch, ENT_QUOTES, 'UTF-8') ?>"
                                data-manual="<?= !empty($row['match_manual']) ? '1' : '0' ?>"
                                title="Ver por qué quedó así"
                                aria-haspopup="dialog" aria-controls="nc-detail-modal">
                            <?= $label ?><i class="fas fa-circle-info" style="margin-left:5px;opacity:.7;"></i>
                        </button>
                        <?php else: ?>
                        <span class="badge <?= $badge ?>"><?= $label ?></span>
                        <?php endif; ?>
                    </td>

                    <td class="nc-provider">
                        <?= htmlspecialchars($row['proveedor_nombre']) ?>
                        <?php if (trim((string) $row['sucursal']) !== ''): ?>
                        <div class="nc-reason"><?= htmlspecialchars($row['sucursal']) ?></div>
                        <?php endif; ?>
                    </td>

                    <td class="nc-doc">
                        <?= htmlspecialchars($row['documento']) ?>
                        <div class="nc-sub">
                            <?= ncFecha($row['fecha']) ?>
                            <?php /*
                             * El número de la factura solo se escribe si no está
                             * ya dentro del documento de la nota. En 915 de las
                             * 939 con insignia sí lo está —el consecutivo va
                             * embebido en el número de la nota— y repetirlo
                             * alargaba la insignia al doble para no decir nada.
                             * En las otras 24 sí hace falta, y ahí sale.
                             */
                            $apl = (string) ($row['estado_aplicacion'] ?? 'no_aplica');
                            $colorApl = AplicacionNotaCredito::color($apl);
                            if ($colorApl !== 'neutro'):
                                $estiloApl = $colorApl === 'ok'
                                    ? 'background:#dcfce7;color:#166534;border:1px solid #86efac;'
                                    : 'background:#fef3c7;color:#92400e;border:1px solid #fcd34d;';
                                $facApl = (string) ($row['factura_erp_documento'] ?? '');
                                $facAparte = $facApl !== ''
                                    && strpos((string) $row['documento'], $facApl) === false;
                            ?>
                            <span class="badge nc-badge-mini" style="<?= $estiloApl ?>"
                                  <?= $facApl !== '' ? 'title="Su factura: ' . htmlspecialchars($facApl, ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
                                <?= htmlspecialchars(AplicacionNotaCredito::etiqueta($apl)) ?><?php
                                    if ($facAparte): ?> · factura <?= htmlspecialchars($facApl) ?><?php endif; ?>
                            </span>
                            <?php endif; ?>

                            <?php // El número propio del proveedor: falta en 2 de cada 3. ?>
                            <?php if (trim((string) $row['nc_proveedor']) !== ''): ?>
                            <span title="Número de nota del proveedor">
                                · NC prov. <?= htmlspecialchars($row['nc_proveedor']) ?><?php
                                    if (!empty($row['fecha_nc_proveedor'])): ?> (<?= ncFecha($row['fecha_nc_proveedor']) ?>)<?php endif; ?>
                            </span>
                            <?php endif; ?>
                        </div>
                    </td>

                    <td class="right" style="white-space:nowrap;">
                        <?= ncMonto($row['monto'], $row['moneda']) ?>
                    </td>

                    <?php /*
                     * El saldo en su propia columna. Estuvo colgando del monto
                     * y solo cuando eran distintos, porque en la mitad de las
                     * notas repite el mismo número; puesto aparte se puede
                     * comparar de arriba abajo, que es lo que se hace al
                     * decidir qué nota aplicar.
                     */ ?>
                    <td class="right" style="white-space:nowrap;<?= $ncSinSaldo ? 'color:var(--text-muted);' : '' ?>"
                        title="<?= $ncSinSaldo ? 'La nota ya se aplicó entera' : 'Lo que queda por aplicar de esta nota' ?>">
                        <?= $ncSinSaldo ? 'sin saldo' : ncMonto($row['saldo'], $row['moneda']) ?>
                    </td>

                    <td>
                        <?php if (!empty($row['factura_xml_id'])): ?>
                            <a href="<?= $baseUrl ?>/notas-xml/ver/<?= (int) $row['factura_xml_id'] ?>" target="_blank" rel="noopener"
                               data-ficha="<?= (int) $row['factura_xml_id'] ?>"
                               class="nc-doc" title="Ver la ficha de esta nota">
                                <?= htmlspecialchars($row['xml_numero'] ?: $row['xml_consecutivo']) ?>
                            </a>
                            <div class="nc-sub">
                                <?= $row['xml_total'] !== null ? ncMonto($row['xml_total'], $row['moneda']) : '—' ?>
                                <?php if ($ncDif !== null && abs($ncDif) > 0.005): ?>
                                <span class="nc-dif">· dif. <?= ncMonto($row['diferencia'], $row['moneda']) ?></span>
                                <?php endif; ?>
                            </div>
                            <?php /*
                             * El nombre del proveedor del XML se escribía
                             * siempre, y en 451 de 521 era el mismo de la nota,
                             * dos columnas a la izquierda. Al enseñarlo solo
                             * cuando NO coincide deja de ser ruido y pasa a ser
                             * lo que de verdad es: la señal de que el
                             * emparejamiento puede estar equivocado.
                             */
                            if (!ncMismoProveedor($row['proveedor_nombre'], $row['xml_proveedor'] ?? '')): ?>
                            <div class="nc-reason nc-otro-proveedor"
                                 title="El XML está a nombre de otro proveedor: conviene revisar el emparejamiento">
                                <i class="fas fa-triangle-exclamation"></i>
                                <?= htmlspecialchars((string) ($row['xml_proveedor'] ?: 'sin proveedor')) ?>
                            </div>
                            <?php endif; ?>
                            <?php
                            // El XML está vinculado, pero su archivo puede
                            // haber desaparecido de la carpeta compartida: el
                            // enlace de arriba llevaría a una pantalla sin nada.
                            $marcaArchivo = ['id' => (int) $row['factura_xml_id'], 'estado' => [
                                'perdido' => !empty($row['archivo_perdido']),
                                'recuperable' => !empty($row['archivo_recuperable']),
                                'que_falta' => $row['archivo_que_falta'] ?? '',
                            ]];
                            include __DIR__ . '/../partials/marca-archivo.php';
                            ?>
                        <?php else: ?>—<?php endif; ?>
                    </td>

                    <td>
                        <div class="nc-actions">
                            <button type="button" class="btn btn-outline btn-sm nc-link-btn"
                                    data-linea="<?= (int) $row['id'] ?>">
                                <i class="fas fa-link"></i> <?= !empty($row['factura_xml_id']) ? 'Cambiar' : 'Vincular XML' ?>
                            </button>
                            <?php if (!empty($row['factura_xml_id'])): ?>
                            <form method="POST" action="<?= $baseUrl ?>/notas-credito/desvincular"
                                  data-confirm="La nota de crédito XML se desvinculará y el emparejamiento automático quedará bloqueado para esta fila."
                                  data-confirm-title="Desvincular nota de crédito"
                                  data-confirm-type="warning"
                                  data-confirm-accept="Desvincular">
                                <input type="hidden" name="linea_id" value="<?= (int) $row['id'] ?>">
                                <button class="btn btn-outline btn-sm" title="Desvincular"><i class="fas fa-link-slash"></i></button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php elseif (empty($listados)): ?>
<div class="card" style="text-align:center;padding:22px;color:var(--text-muted);">
    <i class="fas fa-file-circle-minus" style="font-size:34px;margin-bottom:12px;color:#cbd5e1;"></i>
    <div style="font-weight:700;color:var(--navy);">Aún no hay notas de crédito del ERP</div>
    <div style="font-size:12px;margin-top:5px;">Carga el primer CSV para iniciar el acumulado.</div>
</div>
<?php endif; ?>

<!-- Detalle del resultado de verificación -->
<div class="nc-modal" id="nc-detail-modal" role="dialog" aria-modal="true" aria-labelledby="nc-detail-title">
    <div class="nc-modal-panel" style="max-width:620px;">
        <div class="nc-modal-head">
            <i class="fas fa-circle-info" style="color:var(--gold);"></i>
            <strong id="nc-detail-title">Detalle de verificación</strong>
            <button class="nc-close" type="button" data-close="nc-detail-modal" aria-label="Cerrar">&times;</button>
        </div>
        <div class="nc-modal-body">
            <div class="nc-detail-context">
                <strong id="nc-detail-document"></strong>
                <span id="nc-detail-provider"></span>
            </div>
            <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:9px;">
                <span id="nc-detail-state" class="badge"></span>
                <span id="nc-detail-manual" class="badge badge-navy" style="display:none;"><i class="fas fa-hand-pointer"></i> Vínculo manual</span>
            </div>
            <div class="nc-detail-reason" id="nc-detail-reason"></div>
        </div>
    </div>
</div>

<!-- Vinculación manual -->
<div class="nc-modal" id="nc-link-modal">
    <div class="nc-modal-panel">
        <div class="nc-modal-head">
            <i class="fas fa-link" style="color:var(--gold);"></i>
            <div><strong>Vincular NC XML</strong><div id="nc-link-meta" class="nc-period"></div></div>
            <button class="nc-close" type="button" data-close="nc-link-modal">&times;</button>
        </div>
        <div class="nc-modal-body">
            <div style="font-size:12px;color:var(--text-muted);margin-bottom:10px;">
                Solo se muestran NC XML cuyo monto es exactamente igual al monto del reporte.
            </div>
            <div style="display:flex;gap:8px;margin-bottom:12px;">
                <input class="form-control" id="nc-candidate-q" placeholder="Filtrar por consecutivo o proveedor">
                <button type="button" class="btn btn-outline btn-sm" id="nc-candidate-search"><i class="fas fa-search"></i></button>
            </div>
            <div class="nc-table-wrap" style="max-height:52vh;">
                <table class="data-table" style="min-width:900px;">
                    <thead><tr><th>Consecutivo XML</th><th>Proveedor</th><th>Fecha</th><th class="right">Total</th><th class="right">Diferencia</th><th>Similitud</th><th></th></tr></thead>
                    <tbody id="nc-candidate-body"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Historial de verificaciones -->
<div class="nc-modal" id="nc-history-modal">
    <div class="nc-modal-panel" style="max-width:1250px;">
        <div class="nc-modal-head">
            <i class="fas fa-clock-rotate-left" style="color:var(--gold);"></i>
            <div><strong>Historial de verificaciones</strong><div class="nc-period">Resultados y cambios guardados en cada ejecución.</div></div>
            <button class="nc-close" type="button" data-close="nc-history-modal">&times;</button>
        </div>
        <div class="nc-modal-body">
            <div style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;">
                <div style="min-width:310px;flex:1;">
                    <label style="font-size:11px;font-weight:700;">Verificación</label>
                    <select class="form-control" id="nc-history-select"></select>
                </div>
            </div>
            <div id="nc-history-summary"></div>
            <div class="nc-table-wrap" style="max-height:48vh;">
                <table class="data-table" style="min-width:1180px;">
                    <thead><tr><th>Fila</th><th>Documento</th><th>Proveedor</th><th>Estado</th><th>NC XML</th><th>Diferencia</th><th>Motivo anterior</th><th>Motivo nuevo</th></tr></thead>
                    <tbody id="nc-history-body"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Vista previa de la actualización del CSV. Vivía en /carga; se vino con
     su formulario, que es lo unico que la abre. -->
<div id="nc-carga-modal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:1000;align-items:center;justify-content:center;padding:12px;">
    <div style="background:#fff;border-radius:10px;width:min(1000px,100%);max-height:88vh;display:flex;flex-direction:column;overflow:hidden;">
        <div style="padding:9px 13px;border-bottom:1px solid var(--border);display:flex;gap:8px;align-items:center;">
            <i class="fas fa-eye" style="color:var(--gold);"></i>
            <div>
                <strong>Vista previa de la actualización</strong>
                <div id="nc-carga-meta" style="font-size:12px;color:var(--text-muted);"></div>
            </div>
            <button type="button" data-nc-carga-cerrar
                    style="margin-left:auto;background:none;border:0;font-size:24px;cursor:pointer;color:var(--text-muted);line-height:1;">&times;</button>
        </div>
        <div style="padding:10px 13px;overflow:auto;">
            <div id="nc-carga-resumen" style="display:flex;gap:12px;flex-wrap:wrap;font-size:12px;margin-bottom:8px;"></div>
            <div id="nc-carga-aviso"></div>
            <div style="overflow:auto;max-height:45vh;">
                <table class="table" style="min-width:900px;font-size:12.5px;">
                    <thead><tr><th>Fila</th><th>Documento</th><th>Proveedor</th><th>Fecha</th>
                    <th style="text-align:right;">Monto</th><th style="text-align:right;">Saldo</th></tr></thead>
                    <tbody id="nc-carga-cuerpo"></tbody>
                </table>
            </div>
        </div>
        <div style="padding:9px 13px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:7px;">
            <button type="button" class="btn btn-outline btn-sm" data-nc-carga-cerrar>Cancelar</button>
            <form method="POST" action="<?= $baseUrl ?>/notas-credito/subir">
                <input type="hidden" name="archivo_token" id="nc-carga-token">
                <input type="hidden" name="archivo_nombre" id="nc-carga-original">
                <button class="btn btn-primary btn-sm" id="nc-carga-confirmar">
                    <i class="fas fa-rotate"></i> Actualizar saldos
                </button>
            </form>
        </div>
    </div>
</div>

<script>
// Subida del CSV: se analiza sin escribir y el modal dice qué cambiaría.
// Confirmar reenvía el archivo ya guardado por su token.
(function () {
    var BASE = <?= json_encode($baseUrl) ?>;
    var form = document.getElementById('form-nc');
    var modal = document.getElementById('nc-carga-modal');
    var entrada = document.getElementById('nc-archivo');
    if (!form || !modal) { return; }

    entrada.addEventListener('change', function () {
        document.getElementById('nc-archivo-nombre').textContent =
            entrada.files.length ? entrada.files[0].name : 'Ningún archivo seleccionado';
    });

    function esc(v) {
        var d = document.createElement('div');
        d.textContent = String(v == null ? '' : v);
        return d.innerHTML;
    }
    function moneda(v, m) {
        return (m === 'USD' ? '$' : '\u20a1') +
            Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function fecha(v) {
        if (!v) { return '\u2014'; }
        var p = String(v).split('-');
        return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : v;
    }

    modal.addEventListener('click', function (e) {
        if (e.target === modal || e.target.hasAttribute('data-nc-carga-cerrar')) {
            modal.style.display = 'none';
        }
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var btn = document.getElementById('nc-btn');
        btn.disabled = true;
        fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin' })
            .then(function (res) {
                return res.json().catch(function () { return null; }).then(function (body) {
                    if (!res.ok || !body || body.ok === false) {
                        throw new Error((body && body.message) || ('Error HTTP ' + res.status));
                    }
                    return body;
                });
            })
            .then(function (d) {
                document.getElementById('nc-carga-meta').textContent =
                    (d.empresa || '') + (d.periodo_desde ? ' - ' + d.periodo_desde + ' al ' + d.periodo_hasta : '');
                var st = d.estadisticas || {};
                var impacto = d.impacto || {};
                document.getElementById('nc-carga-resumen').innerHTML =
                    '<span>Notas: <b>' + (st.total || 0) + '</b></span>' +
                    '<span>Nuevas: <b>' + (impacto.nuevas || 0) + '</b></span>' +
                    '<span>Saldo cambia: <b>' + (impacto.actualizadas || 0) + '</b></span>' +
                    '<span>Sin cambios: <b>' + (impacto.sin_cambio || 0) + '</b></span>';

                var aviso = document.getElementById('nc-carga-aviso');
                document.getElementById('nc-carga-confirmar').disabled = false;
                aviso.innerHTML = '';
                if (d.duplicado) {
                    aviso.innerHTML = '<div class="alert alert-info" style="margin-bottom:12px;">' +
                        'Este mismo archivo ya fue procesado. Puedes aplicarlo de nuevo; las filas iguales no se tocarán.</div>';
                }
                // Las filas ilegibles ya no "se omiten": van a la bandeja de
                // revisión, donde se pueden corregir e incluir. Decirlo desde
                // la vista previa evita la sorpresa de cargar y descubrir
                // después que faltaban notas.
                if (d.revision) {
                    aviso.innerHTML += '<div class="alert alert-warning" style="margin-bottom:12px;">' +
                        d.revision + ' fila(s) no se pudieron leer y van a quedar en revisión, ' +
                        'para que decidas si entran. Ninguna se descarta sola.</div>';
                }

                document.getElementById('nc-carga-cuerpo').innerHTML = (d.lineas || []).map(function (r) {
                    return '<tr><td>' + esc(r.fila_origen) + '</td><td>' + esc(r.documento) + '</td>' +
                           '<td>' + esc(r.proveedor_nombre) + '</td><td>' + fecha(r.fecha) + '</td>' +
                           '<td style="text-align:right;">' + moneda(r.monto, r.moneda) + '</td>' +
                           '<td style="text-align:right;">' + moneda(r.saldo, r.moneda) + '</td></tr>';
                }).join('');

                document.getElementById('nc-carga-token').value = d.token || '';
                document.getElementById('nc-carga-original').value = d.archivo || '';
                modal.style.display = 'flex';
            })
            .catch(function (err) {
                AppDialog.alert(err.message, { title: 'No se pudo analizar el archivo', type: 'danger' });
            })
            .then(function () { btn.disabled = false; });
    });
})();
</script>

<script>
(function () {
    var BASE = <?= json_encode($baseUrl) ?>;
    var detailModal = document.getElementById('nc-detail-modal');
    var linkModal = document.getElementById('nc-link-modal');
    var historyModal = document.getElementById('nc-history-modal');
    var linesBody = document.getElementById('nc-lines-body');
    var filterForm = document.getElementById('nc-filter-form');
    var currentLine = 0;

    function esc(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];
        });
    }
    function money(value, currency) {
        return (currency === 'USD' ? '$' : '₡') + Number(value || 0).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
    }
    function dateEs(value) {
        if (!value) return '—';
        var p = String(value).split('-');
        return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : value;
    }
    function dateTimeEs(value) {
        if (!value) return '—';
        var parts = String(value).split(' ');
        return dateEs(parts[0]) + (parts[1] ? ' ' + parts[1].slice(0, 5) : '');
    }
    function stateLabel(value) {
        return {coincide:'Coincide', con_diferencia:'Con diferencia', sin_respaldo:'Sin respaldo'}[value] || value || '—';
    }
    // Sin el botón de verificar a mano, este historial es el único lugar que
    // dice qué disparó cada cruce: faltaban dos de los orígenes que escribe
    // el verificador y salían crudos ("carga_incremental").
    function originLabel(value) {
        return {
            manual: 'Manual',
            automatico: 'Automática',
            carga_inicial: 'Carga inicial',
            carga_incremental: 'Carga del listado',
            alias_nuevo: 'Alias nuevo'
        }[value] || value || 'Automática';
    }
    function lineRow(row) {
        var estado = String(row.estado || 'sin_respaldo');
        var badge = estado === 'coincide' ? 'badge-ok' : (estado === 'con_diferencia' ? 'badge-diff' : 'badge-miss');
        var label = estado === 'coincide' ? 'Coincide' : (estado === 'con_diferencia' ? 'Con diferencia' : 'Sin respaldo');
        // La insignia ES el boton: "Ver detalles" salia debajo de las 2.613
        // filas, asi que no distinguia ninguna y costaba una linea en cada una.
        var estadoHtml = '<span class="badge '+badge+'">'+label+'</span>';
        if (Number(row.match_manual || 0) > 0 || row.motivo_match) {
            estadoHtml = '<button type="button" class="badge '+badge+' nc-estado-btn nc-detail-btn"' +
                ' data-estado="'+esc(label)+'" data-documento="'+esc(row.documento || '')+'"' +
                ' data-proveedor="'+esc(row.proveedor_nombre || '')+'" data-motivo="'+esc(row.motivo_match || '')+'"' +
                ' data-manual="'+(Number(row.match_manual || 0) > 0 ? '1' : '0')+'"' +
                ' title="Ver por que quedo asi" aria-haspopup="dialog" aria-controls="nc-detail-modal">' +
                label + '<i class="fas fa-circle-info" style="margin-left:5px;opacity:.7;"></i></button>';
        }

        var tieneXml = Number(row.factura_xml_id || 0) > 0;
        var xmlHtml = '—';
        if (tieneXml) {
            var totalXmlTxt = row.xml_total !== null && row.xml_total !== ''
                ? money(row.xml_total, row.moneda) : '—';
            var difNum = row.diferencia !== null && row.diferencia !== '' ? Number(row.diferencia) : null;

            xmlHtml = '<a href="'+BASE+'/notas-xml/ver/'+Number(row.factura_xml_id)+'" target="_blank" rel="noopener"'
                + ' data-ficha="'+Number(row.factura_xml_id)+'" class="nc-doc" title="Ver la ficha de esta nota">' +
                esc(row.xml_numero || row.xml_consecutivo || '')+'</a>' +
                '<div class="nc-sub">' + totalXmlTxt +
                (difNum !== null && Math.abs(difNum) > 0.005
                    ? '<span class="nc-dif">· dif. '+money(row.diferencia, row.moneda)+'</span>' : '') +
                '</div>';

            // El nombre del proveedor del XML solo cuando NO es el de la nota:
            // así deja de ser ruido y pasa a ser el aviso de que el
            // emparejamiento puede estar equivocado. Misma regla que ncMismoProveedor().
            if (!mismoProveedor(row.proveedor_nombre, row.xml_proveedor)) {
                xmlHtml += '<div class="nc-reason nc-otro-proveedor" title="El XML está a nombre de otro proveedor: conviene revisar el emparejamiento">' +
                    '<i class="fas fa-triangle-exclamation"></i> ' +
                    esc(row.xml_proveedor || 'sin proveedor') + '</div>';
            }
            // El mismo aviso que pinta el servidor: el XML está vinculado pero
            // su archivo ya no está donde la base dice. Filtrar en vivo no
            // puede hacer desaparecer un problema.
            if (row.archivo_perdido) {
                xmlHtml += '<div><span class="badge badge-perdido" title="Se archivó y ya no está en la carpeta compartida">' +
                    '<i class="fas fa-link-slash"></i> ' +
                    (row.archivo_que_falta ? 'Falta el '+esc(row.archivo_que_falta) : 'Archivo perdido') + '</span>' +
                    (row.archivo_recuperable
                        ? '<button type="button" class="btn-recuperar" data-recuperar-doc="'+Number(row.factura_xml_id)+'"' +
                          ' title="Volver a bajarlo del correo y dejarlo en su misma carpeta">' +
                          '<i class="fas fa-cloud-arrow-down"></i></button>'
                        : '') + '</div>';
            }
        }
        /* El saldo va en su propia columna, igual que en el pintado del
         * servidor: si aquí se dejara colgando del monto, la tabla cambiaría
         * de forma en cuanto alguien escribiera en un filtro. */
        var sinSaldo = Math.abs(Number(row.saldo)) < 0.005;
        var saldoHtml = '<td class="right" style="white-space:nowrap;' +
            (sinSaldo ? 'color:var(--text-muted);' : '') + '">' +
            (sinSaldo ? 'sin saldo' : money(row.saldo, row.moneda)) + '</td>';

        var subDoc = '<div class="nc-sub">' + dateEs(row.fecha) + aplicacionHtml(row) +
            (row.nc_proveedor
                ? '<span title="Número de nota del proveedor">· NC prov. '+esc(row.nc_proveedor) +
                  (row.fecha_nc_proveedor ? ' ('+dateEs(row.fecha_nc_proveedor)+')' : '') + '</span>'
                : '') + '</div>';

        return '<tr>'+
            '<td>'+estadoHtml+'</td>'+
            '<td class="nc-provider">'+esc(row.proveedor_nombre || '')+
                (row.sucursal ? '<div class="nc-reason">'+esc(row.sucursal)+'</div>' : '')+'</td>'+
            '<td class="nc-doc">'+esc(row.documento || '')+subDoc+'</td>'+
            '<td class="right" style="white-space:nowrap;">'+money(row.monto, row.moneda)+'</td>'+
            saldoHtml+
            '<td>'+xmlHtml+'</td>'+
            '<td><div class="nc-actions">'+
                '<button type="button" class="btn btn-outline btn-sm nc-link-btn" data-linea="'+Number(row.id)+'">' +
                '<i class="fas fa-link"></i> '+(tieneXml ? 'Cambiar' : 'Vincular XML')+'</button>'+
                (tieneXml ? '<form method="POST" action="'+BASE+'/notas-credito/desvincular" data-confirm="La nota de crédito XML se desvinculará y el emparejamiento automático quedará bloqueado para esta fila." data-confirm-title="Desvincular nota de crédito" data-confirm-type="warning" data-confirm-accept="Desvincular">' +
                    '<input type="hidden" name="linea_id" value="'+Number(row.id)+'">' +
                    '<button class="btn btn-outline btn-sm" title="Desvincular"><i class="fas fa-link-slash"></i></button></form>' : '')+
                '</div></td></tr>';
    }
    /*
     * La situación de la nota frente a la factura que corrige. Va pegada al
     * documento y no en una columna propia porque es información de esa nota,
     * y una columna más en una tabla de trece no la lee nadie.
     */
    var APLICACION = <?= json_encode(
        array_map(function ($e) { return ['etiqueta' => $e[0], 'color' => $e[1]]; },
                  AplicacionNotaCredito::ESTADOS),
        JSON_UNESCAPED_UNICODE
    ) ?>;
    var APLICACION_COLOR = {
        ok:     'background:#dcfce7;color:#166534;border:1px solid #86efac;',
        aviso:  'background:#fef3c7;color:#92400e;border:1px solid #fcd34d;',
        neutro: ''
    };
    function aplicacionHtml(row) {
        var info = APLICACION[row.estado_aplicacion];
        if (!info || info.color === 'neutro') { return ''; }
        // El número de la factura solo si no está ya dentro del documento de la
        // nota, que es donde vive en 915 de los 939 casos. Misma regla que el
        // servidor; en el title está siempre.
        var fac = row.factura_erp_documento ? String(row.factura_erp_documento) : '';
        var aparte = fac !== '' && String(row.documento || '').indexOf(fac) === -1;
        return '<span class="badge nc-badge-mini" style="' + APLICACION_COLOR[info.color] + '"' +
               (fac !== '' ? ' title="Su factura: '+esc(fac)+'"' : '') + '>' +
               esc(info.etiqueta) + (aparte ? ' · factura ' + esc(fac) : '') + '</span>';
    }

    /** El gemelo de ncMismoProveedor() de PHP: la misma regla, la misma tabla. */
    function mismoProveedor(nota, xml) {
        function clave(t) {
            return String(t == null ? '' : t).toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 12);
        }
        var b = clave(xml);
        return b === '' || clave(nota) === b;
    }

    function renderLines(rows) {
        if (!linesBody) return;
        if (!rows.length) {
            linesBody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:18px;color:#64748b;">No hay notas con estos filtros.</td></tr>';
            return;
        }
        linesBody.innerHTML = rows.map(lineRow).join('');
    }
    function openModal(modal) { if (modal) modal.classList.add('open'); }
    function closeModal(modal) { if (modal) modal.classList.remove('open'); }

    document.querySelectorAll('[data-close]').forEach(function (button) {
        button.addEventListener('click', function () { closeModal(document.getElementById(button.dataset.close)); });
    });
    [detailModal, linkModal, historyModal].forEach(function (modal) {
        if (modal) modal.addEventListener('click', function (event) { if (event.target === modal) closeModal(modal); });
    });

    function showDetail(button) {
        var estado = button.dataset.estado || 'Sin respaldo';
        var state = document.getElementById('nc-detail-state');
        state.textContent = estado;
        state.className = 'badge ' + (estado === 'Coincide' ? 'badge-ok' : (estado === 'Con diferencia' ? 'badge-diff' : 'badge-miss'));
        document.getElementById('nc-detail-document').textContent = button.dataset.documento || 'Documento sin identificar';
        document.getElementById('nc-detail-provider').textContent = button.dataset.proveedor || 'Proveedor sin identificar';
        document.getElementById('nc-detail-reason').textContent = button.dataset.motivo || 'Este vínculo se realizó manualmente.';
        document.getElementById('nc-detail-manual').style.display = button.dataset.manual === '1' ? 'inline-block' : 'none';
        openModal(detailModal);
    }

    function renderHistory(data) {
        var select = document.getElementById('nc-history-select');
        var summary = document.getElementById('nc-history-summary');
        var body = document.getElementById('nc-history-body');
        var runs = data.verificaciones || [];
        var selected = data.seleccionada;
        select.innerHTML = runs.map(function (run) {
            return '<option value="'+Number(run.id)+'"'+(selected && Number(run.id) === Number(selected.id) ? ' selected' : '')+'>'+
                dateTimeEs(run.fecha_inicio)+' · '+originLabel(run.origen)+' · '+Number(run.cantidad_cambios)+' cambios</option>';
        }).join('');
        if (!selected) {
            summary.innerHTML = '<div class="alert alert-info" style="margin-top:12px;">Todavía no hay verificaciones guardadas. La próxima ejecución aparecerá aquí.</div>';
            body.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:18px;color:#64748b;">Sin historial disponible.</td></tr>';
            return;
        }
        summary.innerHTML = '<div class="nc-history-grid">'+
            '<div class="nc-history-stat"><strong>'+Number(selected.coincide)+'</strong><span>Coinciden</span></div>'+
            '<div class="nc-history-stat"><strong>'+Number(selected.con_diferencia)+'</strong><span>Con diferencia</span></div>'+
            '<div class="nc-history-stat"><strong>'+Number(selected.sin_respaldo)+'</strong><span>Sin respaldo</span></div>'+
            '<div class="nc-history-stat"><strong>'+Number(selected.cantidad_cambios)+'</strong><span>Cambios en esta ejecución</span></div></div>';
        var changes = data.cambios || [];
        if (!changes.length) {
            body.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:18px;color:#64748b;">La verificación terminó sin modificar ninguna nota.</td></tr>';
            return;
        }
        body.innerHTML = changes.map(function (change) {
            var oldXml = change.xml_anterior || (change.factura_xml_id_anterior ? 'XML #'+change.factura_xml_id_anterior : 'Sin XML');
            var newXml = change.xml_nuevo || (change.factura_xml_id_nuevo ? 'XML #'+change.factura_xml_id_nuevo : 'Sin XML');
            var oldDiff = change.diferencia_anterior === null ? '—' : money(change.diferencia_anterior, change.moneda);
            var newDiff = change.diferencia_nueva === null ? '—' : money(change.diferencia_nueva, change.moneda);
            return '<tr><td>'+esc(change.fila_origen || '—')+'</td><td class="nc-doc">'+esc(change.documento || '—')+'</td>'+
                '<td class="nc-provider">'+esc(change.proveedor_nombre || '—')+'</td>'+
                '<td class="nc-transition">'+esc(stateLabel(change.estado_anterior))+'<span class="nc-arrow">→</span>'+esc(stateLabel(change.estado_nuevo))+'</td>'+
                '<td class="nc-doc">'+esc(oldXml)+'<span class="nc-arrow">→</span>'+esc(newXml)+'</td>'+
                '<td class="nc-transition">'+oldDiff+'<span class="nc-arrow">→</span>'+newDiff+'</td>'+
                '<td>'+esc(change.motivo_anterior || '—')+'</td><td>'+esc(change.motivo_nuevo || '—')+'</td></tr>';
        }).join('');
    }

    function loadHistory(verificationId) {
        var opener = document.getElementById('nc-history-open');
        if (!opener) return;
        var body = document.getElementById('nc-history-body');
        body.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:18px;"><i class="fas fa-spinner fa-spin"></i> Cargando historial…</td></tr>';
        var url = BASE + '/notas-credito/historial/' + Number(opener.dataset.listado);
        if (verificationId) url += '?verificacion_id=' + Number(verificationId);
        fetch(url).then(function (response) { return response.json(); }).then(function (data) {
            if (!data.ok) throw new Error(data.message || 'No fue posible cargar el historial.');
            renderHistory(data);
        }).catch(function (error) {
            body.innerHTML = '<tr><td colspan="8" style="color:#dc2626;padding:20px;">'+esc(error.message)+'</td></tr>';
        });
    }

    var historyOpen = document.getElementById('nc-history-open');
    if (historyOpen) historyOpen.addEventListener('click', function () { openModal(historyModal); loadHistory(0); });
    var historySelect = document.getElementById('nc-history-select');
    if (historySelect) historySelect.addEventListener('change', function () { loadHistory(Number(historySelect.value)); });

    if (filterForm && linesBody) {
        var columnFilters = Array.prototype.slice.call(document.querySelectorAll('[data-nc-filter]'));
        var filterTimer = null;
        var filterRequest = null;
        var currentUrlParams = new URLSearchParams(window.location.search);

        columnFilters.forEach(function (input) {
            var saved = currentUrlParams.get(input.dataset.ncFilter);
            if (saved !== null) input.value = saved;
        });

        /*
         * Qué columnas están filtrando, a la vista: la casilla con algo puesto
         * queda marcada y el botón de limpiar aparece solo si hay alguna. Antes
         * un filtro heredado del enlace pasaba inadvertido y la lista parecía
         * incompleta sin motivo.
         */
        var clearColumns = document.getElementById('nc-limpiar-col');
        function markColumnFilters() {
            var activos = 0;
            columnFilters.forEach(function (input) {
                var lleno = String(input.value || '').trim() !== '';
                input.classList.toggle('nc-lleno', lleno);
                if (lleno) activos++;
            });
            if (clearColumns) clearColumns.hidden = activos === 0;
        }
        markColumnFilters();

        if (clearColumns) {
            clearColumns.addEventListener('click', function () {
                columnFilters.forEach(function (input) { input.value = ''; });
                markColumnFilters();
                scheduleLiveSearch(true);
            });
        }

        function filterParams() {
            var params = new URLSearchParams(new FormData(filterForm));
            params.delete('page');
            columnFilters.forEach(function (input) {
                var value = String(input.value || '').trim();
                if (value) params.set(input.dataset.ncFilter, value);
                else params.delete(input.dataset.ncFilter);
            });
            return params;
        }

        function runLiveSearch() {
            var params = filterParams();
            var resultCount = document.getElementById('nc-result-count');
            if (filterRequest) filterRequest.abort();
            filterRequest = new AbortController();
            if (resultCount) resultCount.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

            window.history.replaceState(null, '', BASE + '/notas-credito?' + params.toString());
            fetch(BASE + '/notas-credito/buscar?' + params.toString(), {signal:filterRequest.signal})
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (!data.ok) throw new Error(data.message || 'No fue posible buscar las notas.');
                    renderLines(data.lineas || []);
                    if (resultCount) resultCount.textContent = Number(data.total || 0);
                    var listTotal = document.getElementById('nc-list-total');
                    if (listTotal) listTotal.textContent = Number(data.total_listado || 0);
                })
                .catch(function (error) {
                    if (error.name === 'AbortError') return;
                    linesBody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:18px;color:#dc2626;">'+esc(error.message)+'</td></tr>';
                    if (resultCount) resultCount.textContent = '0';
                });
        }

        function scheduleLiveSearch(immediate) {
            clearTimeout(filterTimer);
            filterTimer = setTimeout(runLiveSearch, immediate ? 0 : 180);
        }

        filterForm.addEventListener('submit', function (event) {
            event.preventDefault();
            scheduleLiveSearch(true);
        });
        filterForm.querySelectorAll('input:not([type="hidden"])').forEach(function (input) {
            input.addEventListener('input', function () { scheduleLiveSearch(false); });
        });
        filterForm.querySelectorAll('select').forEach(function (select) {
            select.addEventListener('change', function () { scheduleLiveSearch(true); });
        });
        columnFilters.forEach(function (input) {
            input.addEventListener(input.tagName === 'SELECT' || input.type === 'date' ? 'change' : 'input', function () {
                markColumnFilters();
                scheduleLiveSearch(input.tagName === 'SELECT' || input.type === 'date');
            });
        });
    }

    function loadCandidates() {
        var q = document.getElementById('nc-candidate-q').value || '';
        var body = document.getElementById('nc-candidate-body');
        body.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:18px;"><i class="fas fa-spinner fa-spin"></i> Buscando NC XML…</td></tr>';
        fetch(BASE + '/notas-credito/candidatas?linea_id=' + currentLine + '&q=' + encodeURIComponent(q))
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data.ok) throw new Error(data.message || 'No fue posible cargar candidatas.');
                var line = data.linea;
                document.getElementById('nc-link-meta').textContent =
                    line.documento + ' · ' + line.proveedor_nombre + ' · ' + money(line.monto, line.moneda);
                if (!data.candidatas.length) {
                    body.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:18px;color:#64748b;">No hay NC XML disponibles de este proveedor y moneda.</td></tr>';
                    return;
                }
                // Las de monto distinto ahora también se listan, pero no se
                // vinculan de un clic: llevan la diferencia marcada y piden
                // confirmación, para que aceptarla sea una decisión y no un
                // descuido.
                body.innerHTML = data.candidatas.map(function (row) {
                    var exacto = !!row.monto_exacto;
                    var extra = exacto ? '' : '<input type="hidden" name="aceptar_diferencia" value="1">';
                    var confirma = exacto ? '' :
                        ' data-confirm="El monto del XML no coincide con el reporte. La nota quedará marcada con diferencia." data-confirm-title="Vincular con diferencia" data-confirm-type="warning" data-confirm-accept="Vincular de todos modos"';
                    return '<tr'+(exacto ? '' : ' style="background:#fffbeb;"')+'><td class="nc-doc">'+esc(row.consecutivo_completo || row.numero_factura_asistente)+'</td>' +
                        '<td>'+esc(row.proveedor_nombre)+'</td><td>'+dateEs(row.fecha_emision)+'</td>' +
                        '<td class="right">'+money(row.total,row.moneda)+'</td>' +
                        '<td class="right"'+(exacto ? '' : ' style="color:#b45309;font-weight:700;"')+'>'+money(row.diferencia,row.moneda)+'</td>' +
                        '<td>'+Number(row.score_proveedor).toFixed(1)+'%</td><td>' +
                        '<form method="POST" action="'+BASE+'/notas-credito/vincular"'+confirma+'>' +
                        '<input type="hidden" name="linea_id" value="'+currentLine+'"><input type="hidden" name="factura_id" value="'+Number(row.id)+'">' + extra +
                        '<button class="btn '+(exacto ? 'btn-primary' : 'btn-outline')+' btn-sm"><i class="fas fa-link"></i> '+(exacto ? 'Vincular' : 'Vincular con diferencia')+'</button></form></td></tr>';
                }).join('');
            })
            .catch(function (error) { body.innerHTML='<tr><td colspan="7" style="color:#dc2626;padding:20px;">'+esc(error.message)+'</td></tr>'; });
    }

    if (linesBody) linesBody.addEventListener('click', function (event) {
        var detailButton = event.target.closest('.nc-detail-btn');
        if (detailButton) {
            showDetail(detailButton);
            return;
        }
        var button = event.target.closest('.nc-link-btn');
        if (button) {
            currentLine = Number(button.dataset.linea);
            document.getElementById('nc-candidate-q').value = '';
            openModal(linkModal);
            loadCandidates();
        }
    });
    var search = document.getElementById('nc-candidate-search');
    if (search) search.addEventListener('click', loadCandidates);
    var qInput = document.getElementById('nc-candidate-q');
    if (qInput) qInput.addEventListener('keydown', function (event) { if (event.key === 'Enter') { event.preventDefault(); loadCandidates(); } });
})();
</script>
