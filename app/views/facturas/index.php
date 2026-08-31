<?php
$baseUrl           = defined('APP_URL') ? APP_URL : '/xmlconcilia/public';
$facturas          = $facturas ?? [];
$historial         = $historial ?? [];
$importacionActiva = $importacionActiva ?? null;
$paginacion        = array_merge([
    'pagina' => 1, 'paginas' => 1, 'total' => 0,
    'hay_siguiente' => false, 'revisados' => 0, 'truncado' => false,
], is_array($paginacion ?? null) ? $paginacion : []);
$pagina            = max(1, (int) $paginacion['pagina']);
// 0 páginas = no se sabe cuántas hay: pasa solo con el filtro de respaldo,
// que se resuelve en el disco y no en la base.
$totalPaginas      = (int) $paginacion['paginas'];
$totalFacturas     = $paginacion['total'] === null ? null : (int) $paginacion['total'];
$proveedoresFiltro = is_array($proveedoresFiltro ?? null) ? $proveedoresFiltro : [];
$filtros           = array_merge([
    'q' => '', 'proveedor' => '', 'fecha_desde' => '', 'fecha_hasta' => '',
    'monto' => '', 'saldo' => '', 'respaldo' => '',
], is_array($filtros ?? null) ? $filtros : []);
$filtrosActivos = 0;
foreach ($filtros as $valorFiltro) {
    if ((string) $valorFiltro !== '') { $filtrosActivos++; }
}
$hayFiltros = $filtrosActivos > 0;
// 'limpiar' es lo que le dice al servidor que olvide los filtros guardados
// de esta pantalla: sin él, entrar sin criterios significa "devolvéme los
// que tenía puestos".
require_once __DIR__ . '/../../helpers/NavegacionDocumentos.php';

// El documento que se vino a persiguiendo no se suelta por nada que se haga
// en esta pantalla: ni al limpiar los filtros, ni al pasar de página, ni al
// escribir un criterio a mano. Los tres son GET, y en un GET lo que no viaja
// desaparece.
$contextoDoc = NavegacionDocumentos::contextoDeLaUrl($_GET);

$parametrosLimpiar = $importacionActiva
    ? ['importacion_id' => (int) $importacionActiva['id'], 'limpiar' => 1]
    : ['limpiar' => 1];
// Limpiar borra los buscadores, no la persecución: la tarjeta se queda.
$urlLimpiarFiltros = $baseUrl . '/facturas?'
                   . http_build_query(array_merge($parametrosLimpiar, $contextoDoc));

// Anterior y Siguiente llevan puestos los buscadores y el contexto con el que
// se entró: cambiar de página no puede cambiar lo que se está mirando.
$queryPagina = array_filter($filtros, function ($v) { return (string) $v !== ''; });
if ($importacionActiva) {
    $queryPagina['importacion_id'] = (int) $importacionActiva['id'];
}
$queryPagina = array_merge($queryPagina, $contextoDoc);
$urlPagina = function ($p) use ($baseUrl, $queryPagina) {
    return $baseUrl . '/facturas?' . http_build_query(array_merge($queryPagina, ['pagina' => $p]));
};
?>

<!-- CARGA DE FACTURAS XML -->

<?php /*
 * Acá arriba estaba la tarjeta "Semana de trabajo": un selector de semana que
 * decidía qué facturas se listaban abajo. Esta pantalla dejó de mirarse por
 * semana de pago —es el archivo de comprobantes XML, completo— así que la
 * lista sale entera y se acota con los buscadores de la barra. La columna
 * "Semana" también se fue: la semana no es del comprobante sino del pago en
 * el que está su fila del ERP, y se mira desde ahí o en el detalle.
 */ ?>

<?php /*
 * La subida de comprobantes, acá y no en una pantalla aparte: los XML que
 * entran son las filas de la tabla de abajo, así que cargar y comprobar que
 * entraron es el mismo sitio.
 *
 * No se pregunta a qué semana van. Al guardarse, cada comprobante busca su
 * factura en el listado del ERP por el consecutivo, y si esa factura está en
 * un pago semanal hereda esa semana. Elegirla a mano era una decisión que
 * nadie tenía por qué tomar, y equivocarse dejaba la factura fuera del pago
 * sin que nada lo avisara.
 */ ?>
<div class="card" style="margin-bottom:10px;">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-file-code" style="margin-right:6px;color:var(--gold);"></i>Cargar comprobantes XML
        </div>
    </div>

    <form method="post" action="<?= $baseUrl ?>/facturas/subir" enctype="multipart/form-data" id="form-xml">
        <input type="file" name="xml_files[]" id="xml_files" multiple accept=".xml" style="display:none;">
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <label for="xml_files" class="upload-file-btn" style="padding:8px 16px;font-size:12.5px;">
                <i class="fas fa-folder-open"></i> Seleccionar XML
            </label>
            <span id="xml-nombre" style="font-size:12px;color:var(--text-muted);font-style:italic;">
                Ningún archivo seleccionado
            </span>
            <button type="submit" class="btn btn-primary btn-sm" id="xml-btn">
                <i class="fas fa-cogs" style="margin-right:4px;"></i>Procesar XML
            </button>
            <span style="font-size:11px;color:var(--text-muted);margin-left:auto;max-width:420px;">
                Se suben por tandas, sin límite de cantidad. Los mensajes de Hacienda y las notas
                de débito se descartan solos.
            </span>
        </div>
    </form>

    <div id="xml-progreso" style="display:none;margin-top:14px;">
        <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:6px;gap:6px;flex-wrap:wrap;">
            <span id="xml-estado" style="font-weight:700;color:var(--navy);">Preparando…</span>
            <span id="xml-conteo" style="color:var(--text-muted);"></span>
        </div>
        <div style="background:#e2e8f0;border-radius:8px;height:14px;overflow:hidden;">
            <div id="xml-barra" style="background:linear-gradient(90deg,#0c2461,#1e3a8a);height:100%;width:0%;transition:width .3s;"></div>
        </div>
        <div id="xml-detalle" style="font-size:11.5px;color:var(--text-muted);margin-top:8px;line-height:1.6;"></div>
    </div>
</div>

<script>
// El XML se sube por tandas contra la cola: el navegador manda de a CHUNK
// archivos (por debajo del max_file_uploads de PHP) y después pide procesar
// en lotes. Sin esto, subir 300 XML de una semana se topaba con el límite del
// servidor y se perdían en silencio los que sobraban.
(function () {
    var BASE = '<?= $baseUrl ?>';
    var formXml = document.getElementById('form-xml');
    var entrada = document.getElementById('xml_files');
    if (!formXml || !entrada) { return; }

    entrada.addEventListener('change', function () {
        var n = entrada.files.length;
        document.getElementById('xml-nombre').textContent = n === 0
            ? 'Ningún archivo seleccionado'
            : (n === 1 ? entrada.files[0].name : n + ' archivos seleccionados');
    });

    if (!window.fetch || !window.FormData) { return; }

    var CHUNK = 10, LOTE = 10;
    var boton   = document.getElementById('xml-btn');
    var panel   = document.getElementById('xml-progreso');
    var estado  = document.getElementById('xml-estado');
    var conteo  = document.getElementById('xml-conteo');
    var barra   = document.getElementById('xml-barra');
    var detalle = document.getElementById('xml-detalle');

    function enviar(url, fd) {
        return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (res) {
                return res.json().catch(function () { return null; }).then(function (body) {
                    if (!res.ok || !body || body.ok === false) {
                        throw new Error((body && body.message) || ('Error HTTP ' + res.status));
                    }
                    return body;
                });
            });
    }
    function postJson(url, data) {
        var fd = new FormData();
        Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
        return enviar(url, fd);
    }
    function dormir(ms) { return new Promise(function (ok) { setTimeout(ok, ms); }); }
    function decir(t) { estado.textContent = t; }
    function avanzar(p) { barra.style.width = Math.max(0, Math.min(100, p)) + '%'; }
    function pintar(e) {
        var s = (e && e.stats) || {};
        conteo.textContent = 'Importadas: ' + (s.importado || 0) +
            ' - Ya existían: ' + (s.duplicado || 0) +
            ' - Errores: ' + (s.error || 0) + ' - Pendientes: ' + (s.pendiente || 0);
    }

    formXml.addEventListener('submit', function (e) {
        e.preventDefault();
        var archivos = Array.prototype.slice.call(entrada.files);
        if (!archivos.length) {
            AppDialog.alert('Selecciona al menos un archivo XML para comenzar la carga.', {
                title: 'Archivos requeridos', type: 'warning'
            });
            return;
        }

        boton.disabled = true;
        panel.style.display = 'block';
        avanzar(0);
        detalle.textContent = '';
        decir('Creando importación…');

        var importacionId = 0;

        postJson(BASE + '/facturas/cola/iniciar', { total_esperado: archivos.length })
        .then(function (inicio) {
            importacionId = inicio.importacion_id;
            var cadena = Promise.resolve();
            for (var i = 0; i < archivos.length; i += CHUNK) {
                (function (desde) {
                    cadena = cadena.then(function () {
                        var tanda = archivos.slice(desde, desde + CHUNK);
                        var fd = new FormData();
                        fd.append('importacion_id', importacionId);
                        tanda.forEach(function (f) { fd.append('xml_files[]', f, f.name); });
                        decir('Subiendo archivos… (' + Math.min(desde + tanda.length, archivos.length) + '/' + archivos.length + ')');
                        return enviar(BASE + '/facturas/cola/agregar', fd).then(function () {
                            avanzar(((desde + tanda.length) / archivos.length) * 30);
                        });
                    });
                })(i);
            }
            return cadena;
        })
        .then(function () {
            decir('Procesando facturas…');
            var sinAvance = 0;
            function paso() {
                return postJson(BASE + '/facturas/cola/procesar', {
                    importacion_id: importacionId, limit: LOTE
                }).then(function (r) {
                    var e2 = r.estado || {};
                    avanzar(30 + ((e2.progress_percent || 0) * 0.7));
                    pintar(e2);
                    if (r.completed) { return e2; }
                    if (!r.processed_in_batch) {
                        sinAvance++;
                        if (sinAvance >= 5) { throw new Error('El procesamiento se detuvo sin completar. Volvé a intentarlo.'); }
                        return dormir(2000).then(paso);
                    }
                    sinAvance = 0;
                    return paso();
                });
            }
            return paso();
        })
        .then(function (e2) {
            avanzar(100);
            var s = (e2 && e2.stats) || {};
            var importadas = s.importado || 0, repetidas = s.duplicado || 0, fallos = s.error || 0;

            var problemas = (e2 && e2.recent_issues) || [];
            if (problemas.length) {
                detalle.innerHTML = problemas.slice(0, 5).map(function (it) {
                    var motivo = (it.error_texto || it.estado || '').toString().split(':').pop().trim();
                    var caja = document.createElement('div');
                    caja.textContent = '- ' + (it.archivo_original || 'sin nombre') + ' -> ' + (it.estado || '') +
                                       (motivo ? ' (' + motivo.substring(0, 90) + ')' : '');
                    return caja.innerHTML;
                }).join('<br>');
            }

            if (importadas === 0) {
                decir(repetidas > 0 && fallos === 0
                    ? 'Las ' + repetidas + ' facturas ya estaban cargadas; no se duplicaron.'
                    : '⚠ Terminó sin facturas nuevas (' + fallos + ' con error). Revisá el detalle.');
                boton.disabled = false;
                return;
            }
            // La lista de abajo es justo la que acaba de cambiar: se recarga
            // para que las recién importadas aparezcan sin tener que pedirlo.
            if (repetidas > 0 || fallos > 0) {
                decir('Listo: ' + importadas + ' importadas, ' + repetidas + ' ya estaban y ' + fallos + ' con error.');
                setTimeout(function () { window.location.reload(); }, 3500);
                return;
            }
            decir('¡Listo! ' + importadas + ' facturas importadas.');
            setTimeout(function () { window.location.reload(); }, 800);
        })
        .catch(function (err) {
            decir('Error: ' + err.message);
            detalle.textContent = 'Podés reintentar: solo se bloquean las facturas que ya estaban cargadas.';
            boton.disabled = false;
        });
    });
})();
</script>

<?php
/*
 * La tarjeta del documento que se vino a buscar. Solo aparece si se llegó
 * desde el pago semanal o desde la cola de seguimiento.
 */
include __DIR__ . '/../partials/tarjeta-documento.php';
?>

<?php if ($elegirProveedor ?? false):
/*
 * Nadie ha dicho todavía qué quiere ver, así que aquí no hay listado: el
 * controlador ni siquiera lo consultó. En su lugar va la pregunta.
 */
$elegirProv = [
    'accion'   => $baseUrl . '/facturas',
    'opciones' => $proveedoresFiltro,
    'cuantos'  => $totalDelArchivo ?? null,
    'que'      => 'comprobantes',
];
include __DIR__ . '/../partials/elegir-proveedor.php';
else: ?>

<!-- Facturas Importadas -->
<div class="card">
    <div class="card-header">
        <div>
            <div class="card-title">
                <i class="fas fa-list" style="margin-right:6px;color:var(--navy-light);"></i>Facturas Importadas
                <?php if ($importacionActiva): ?>
                <span style="font-size:11px;font-weight:400;color:var(--text-muted);margin-left:6px;">
                    (filtrado por importación del <?= date('d/m/Y', strtotime($importacionActiva['fecha_importacion'])) ?>)
                </span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <form method="get" action="<?= $baseUrl ?>/facturas" class="filter-bar">
        <?php if ($importacionActiva): ?>
        <input type="hidden" name="importacion_id" value="<?= (int) $importacionActiva['id'] ?>">
        <?php endif; ?>
        <?php // Buscar a mano no puede borrar la tarjeta del documento que se
              // vino persiguiendo: se busca a mano justamente para dar con él.
              include __DIR__ . '/../partials/contexto-oculto.php'; ?>
        <?php
        // Los mismos cuatro buscadores que Notas de crédito XML, en el mismo
        // orden: proveedor, texto libre y el rango de fechas. Las dos listas
        // son el mismo archivo de comprobantes visto por tipo de documento,
        // así que se buscan igual y no hay que reaprender la barra al pasar
        // de una a la otra.
        //
        // Salió de aquí el desplegable "Respaldo" (con par / sin par /
        // archivo perdido). Lo que ese filtro señalaba se sigue viendo en el
        // renglón —la columna PDF marca lo pendiente y lo perdido— y el
        // controlador lo sigue entendiendo si llega por la URL; lo que ya no
        // hay es un control en la barra.
        //
        // La búsqueda por importe sí volvió, y por una razón concreta: cuando
        // lo que se tiene del comprobante es el monto —porque se está
        // cuadrando contra un pago— el número no ayuda, y sin esto no quedaba
        // más que recorrer ocho mil renglones con la vista.
        $provFiltro = [
            'valor'    => $filtros['proveedor'],
            'opciones' => $proveedoresFiltro,
        ]; include __DIR__ . '/../partials/filtro-proveedor.php';
        ?>
        <div class="filter-span-2">
            <label class="filter-label">Buscar</label>
            <input type="search" class="form-control" name="q"
                   value="<?= htmlspecialchars((string) $filtros['q']) ?>"
                   placeholder="Consecutivo, número o proveedor">
        </div>
        <div>
            <label class="filter-label">Fecha desde</label>
            <input type="date" class="form-control" name="fecha_desde"
                   value="<?= htmlspecialchars((string) $filtros['fecha_desde']) ?>">
        </div>
        <div>
            <label class="filter-label">Fecha hasta</label>
            <input type="date" class="form-control" name="fecha_hasta"
                   value="<?= htmlspecialchars((string) $filtros['fecha_hasta']) ?>">
        </div>
        <?php
        $filtroImporte = [
            'nombre' => 'monto', 'etiqueta' => 'Monto',
            'valor' => $filtros['monto'],
        ]; include __DIR__ . '/../partials/filtro-importe.php';

        $filtroImporte = [
            'nombre' => 'saldo', 'etiqueta' => 'Saldo',
            'valor' => $filtros['saldo'],
        ]; include __DIR__ . '/../partials/filtro-importe.php';
        ?>
        <?php // "Alcance" (semana seleccionada / todas las semanas) se fue con
              // el selector de semana: ya no hay una semana que acotar. ?>
        <div class="filter-actions">
            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Buscar</button>
            <?php if ($hayFiltros): ?>
            <a href="<?= htmlspecialchars($urlLimpiarFiltros) ?>" class="btn btn-outline btn-sm"><i class="fas fa-broom"></i> Limpiar</a>
            <?php endif; ?>
        </div>
    </form>

    <div class="filter-results">
        <i class="fas fa-filter" style="color:var(--navy-light);"></i>
        Mostrando <strong><?= count($facturas) ?></strong> factura<?= count($facturas) !== 1 ? 's' : '' ?>
        <?php if ($totalFacturas !== null && $totalFacturas > count($facturas)): ?>
        de <strong><?= $totalFacturas ?></strong>
        <?php endif; ?>
        <?= $hayFiltros ? 'con los filtros seleccionados' : 'en esta vista' ?>
        <?php if ($hayFiltros): ?>
        <span class="badge badge-navy" style="font-size:10px;"><?= $filtrosActivos ?> filtro<?= $filtrosActivos === 1 ? '' : 's' ?></span>
        <?php endif; ?>
        <?php if ($pagina > 1 || $totalPaginas > 1): ?>
        <span style="margin-left:8px;color:var(--text-muted);">
            — página <?= $pagina ?><?= $totalPaginas > 1 ? ' de ' . $totalPaginas : '' ?>
        </span>
        <?php endif; ?>
        <?php /*
         * El filtro de respaldo no lo resuelve la base: hay que preguntarle al
         * disco compartido por el XML y el PDF de cada comprobante, así que se
         * revisa hasta donde alcanza. Si se cortó ahí, se dice; callarlo haría
         * pasar por "no hay más" a un listado que sigue.
         */ ?>
        <?php if (!empty($paginacion['truncado'])): ?>
        <span style="margin-left:8px;color:var(--text-muted);">
            — se revisaron los <?= (int) $paginacion['revisados'] ?> comprobantes más recientes; hay más.
            Acotá con los buscadores para llegar a los demás.
        </span>
        <?php endif; ?>
    </div>

    <?php /*
     * ¿Está el comprobante que se vino a buscar? Va encima de la lista y no
     * en su hueco vacío: el buscador trae por coincidencia, así que abajo
     * puede haber treinta renglones y ninguno ser el que se buscaba.
     */
    $docNoEsta = [
        'navDoc'        => $navDoc ?? null,
        'termino'       => $filtros['q'] ?? '',
        'cargado'       => $docBuscadoCargado ?? null,
        'hayResultados' => !empty($facturas),
    ];
    include __DIR__ . '/../partials/documento-no-esta.php';
    ?>

    <div class="table-wrap">
        <table class="data-table">
            <?php /*
             * Las mismas once columnas, en el mismo orden, que el listado de
             * Notas de crédito XML: son el mismo archivo de comprobantes
             * electrónicos mirado por tipo de documento, y hasta ahora cada
             * pantalla enseñaba un recorte distinto del mismo renglón.
             *
             * Lo que entró: el consecutivo de veinte dígitos —que es lo que
             * se teclea cuando se busca un comprobante contra el ERP—, la
             * moneda, el subtotal y de dónde salió el documento.
             *
             * Lo que salió: "Semana", que es del pago semanal y no del
             * comprobante, y la marca "Respaldo", que decía a la vez del XML
             * y del PDF. El XML ahora lo dice su propio botón —si está, se
             * puede abrir—, y la columna PDF habla solo del PDF.
             */ ?>
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Proveedor</th>
                    <th>Consecutivo</th>
                    <th>Número</th>
                    <th>Moneda</th>
                    <th class="right">Subtotal</th>
                    <th class="right">IVA</th>
                    <th class="right">Total</th>
                    <th class="right">Saldo</th>
                    <th>PDF</th>
                    <th>Origen</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($facturas)): ?>
                    <tr class="empty-row">
                        <td colspan="12">
                            <i class="fas fa-inbox" style="font-size:28px;color:var(--border);display:block;margin-bottom:8px;"></i>
                            <?= $hayFiltros ? 'No se encontraron facturas con estos filtros.' : 'No hay facturas importadas todavía.' ?><br>
                            <span style="font-size:12px;"><?= $hayFiltros ? 'Cambia o limpia los criterios de búsqueda.' : 'Usa el formulario de arriba para subir archivos XML.' ?></span>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($facturas as $f): ?>
                    <tr>
                        <td class="muted"><?= htmlspecialchars($f['fecha_emision'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($f['proveedor_nombre'] ?? 'Sin proveedor') ?></td>
                        <td style="font-family:monospace;white-space:nowrap;"><?= htmlspecialchars((string) ($f['consecutivo_completo'] ?? '—')) ?></td>
                        <td>
                            <span style="font-weight:700;color:var(--navy);">
                                <?= htmlspecialchars((string) ($f['numero_factura_asistente'] ?? $f['consecutivo_completo'] ?? '—')) ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars((string) ($f['moneda'] ?? '')) ?></td>
                        <td class="right"><?= number_format((float)($f['subtotal'] ?? 0), 2) ?></td>
                        <td class="right"><?= number_format((float)($f['iva'] ?? 0), 2) ?></td>
                        <td class="right" style="font-weight:700;"><?= number_format((float)($f['total'] ?? 0), 2) ?></td>
                    <?php /*
                     * El saldo no es del comprobante: un XML dice cuánto se
                     * facturó, no cuánto queda por pagar. Sale del registro
                     * del ERP con el que esté enganchado, y cuando no lo está
                     * se dice con palabras: un cero ahí sería mentira.
                     */
                    $saldoErp = $f['saldo_erp'] ?? null;
                    $hayEnganche = $saldoErp !== null;
                    $debeAlgo = $hayEnganche && (float) $saldoErp > 0.005;
                    $docErp = trim((string) ($f['documento_erp'] ?? ''));
                    ?>
                    <td class="right" style="white-space:nowrap;<?= $debeAlgo ? '' : 'color:var(--text-muted);' ?>"
                        title="<?= $hayEnganche
                            ? 'Saldo de ' . htmlspecialchars($docErp) . ', el documento del ERP con el que está enganchado'
                            : 'Este comprobante todavía no está enganchado a ningún documento del ERP' ?>">
                        <?php if (!$hayEnganche): ?>sin enganche
                        <?php elseif ($debeAlgo): ?><?= number_format((float) $saldoErp, 2) ?>
                        <?php else: ?>sin saldo<?php endif; ?>
                    </td>
                        <td style="white-space:nowrap;">
                            <?php if (!empty($f['archivo_perdido'])): ?>
                            <?php
                            // Se archivó y desapareció de la carpeta compartida.
                            // Va antes que los demás estados porque los desmiente:
                            // la ruta está guardada, el archivo no está.
                            $marcaArchivo = ['id' => (int) $f['id'], 'estado' => [
                                'perdido' => true,
                                'recuperable' => !empty($f['archivo_recuperable']),
                                'que_falta' => $f['archivo_que_falta'] ?? '',
                            ]];
                            include __DIR__ . '/../partials/marca-archivo.php';
                            ?>
                            <?php elseif (!empty($f['archivo_pdf_ok'])): ?>
                            <span class="badge badge-green">Disponible</span>
                            <?php else: ?>
                            <span class="badge" style="background:#fef3c7;color:#92400e;">Pendiente</span>
                            <?php endif; ?>
                        </td>
                        <td><?= !empty($f['correo_cuenta_id']) ? 'Correo' : 'Carga XML' ?></td>
                        <td style="white-space:nowrap;">
                            <?php /*
                             * data-ficha: el detalle se abre en un cuadro
                             * sobre esta misma pantalla (visor de app.js) en
                             * vez de mandar a otro módulo. El href se queda
                             * para ctrl+clic y para un navegador sin JS.
                             */ ?>
                            <a href="<?= $baseUrl ?>/facturas/ver/<?= (int)$f['id'] ?>"
                               data-ficha="<?= (int)$f['id'] ?>"
                               class="btn btn-outline btn-sm" title="Ver la ficha del documento">
                                <i class="fas fa-eye"></i>
                            </a>
                            <?php if (!empty($f['archivo_xml_ok'])): ?>
                            <a href="<?= $baseUrl ?>/documentos/xml/<?= (int)$f['id'] ?>"
                               data-ventana="XML"
                               data-ventana-titulo="<?= htmlspecialchars(NumeroFactura::xmlOchoDigitos((string) ($f['numero_factura_asistente'] ?? ''))) ?>"
                               class="btn btn-outline btn-sm" target="_blank" title="Ver XML">
                                <i class="fas fa-code"></i>
                            </a>
                            <?php endif; ?>
                            <?php if (!empty($f['archivo_pdf_ok'])): ?>
                            <a href="<?= $baseUrl ?>/documentos/pdf/<?= (int)$f['id'] ?>"
                               data-ventana="PDF"
                               data-ventana-titulo="<?= htmlspecialchars(NumeroFactura::xmlOchoDigitos((string) ($f['numero_factura_asistente'] ?? ''))) ?>"
                               class="btn btn-outline btn-sm" target="_blank" title="Ver PDF">
                                <i class="fas fa-file-pdf"></i>
                            </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php /*
     * Anterior y Siguiente, como en el resto de los listados. Con el filtro de
     * respaldo puede no saberse cuántas páginas hay —eso costaría revisar el
     * archivo entero en el disco—, y entonces solo se dice en cuál se está y
     * si hay otra detrás.
     */ ?>
    <?php if ($pagina > 1 || !empty($paginacion['hay_siguiente'])): ?>
    <div style="display:flex;gap:8px;align-items:center;justify-content:center;margin-top:16px;font-size:12px;">
        <?php if ($pagina > 1): ?>
            <a href="<?= htmlspecialchars($urlPagina($pagina - 1)) ?>" class="btn btn-outline btn-sm">Anterior</a>
        <?php endif; ?>
        <span class="muted">Página <?= $pagina ?><?= $totalPaginas > 1 ? ' de ' . $totalPaginas : '' ?></span>
        <?php if (!empty($paginacion['hay_siguiente'])): ?>
            <a href="<?= htmlspecialchars($urlPagina($pagina + 1)) ?>" class="btn btn-outline btn-sm">Siguiente</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php endif; // fin de "ya se eligió proveedor" ?>

<!--
  Aquí estaba el lápiz para asignar o cambiar la semana de una factura a mano.
  Salió porque la semana dejó de ser algo que se elige: una factura pertenece a
  la semana del pago en el que está su fila del ERP. Cambiarla por fuera ya no
  la metía en ningún pago —el emparejamiento va por consecutivo, no por
  semana— y encima podía sacar su XML y su PDF de la carpeta del pago, porque
  esa carpeta se decide comparando la semana del comprobante con la del pago.
  Para mover una factura de semana se saca o se mete en el pago que
  corresponda, desde Pagos semanales.
-->

<script>
/* La tarjeta del documento que se busca. Pintarla y avanzar lo hace app.js;
 * acá solo se contesta qué significa "buscar" en esta pantalla: poner el
 * número en el buscador y enviar el filtro.
 *
 * Al pasar al siguiente con las flechas NO se busca solo: la búsqueda recarga
 * la página, y recargar en cada flecha impediría recorrer la lista de un
 * vistazo. Se recorre con las flechas y se busca con la lupa. */
(function () {
    var tarjeta = document.querySelector('[data-navdoc]');
    if (!tarjeta) { return; }

    tarjeta.addEventListener('navdoc:buscar', function (evento) {
        var doc = evento.detail;
        var campo = document.querySelector('form.filter-bar input[name="q"]');
        var form = campo && campo.form ? campo.form : document.querySelector('form.filter-bar');
        if (!campo || !form) { return; }

        campo.value = doc.busqueda || doc.numero;
        // El contexto viaja con el envío para que la tarjeta siga ahí después
        // de recargar, apuntando al mismo documento.
        tarjeta.dataset.navdocParams.split('&').concat(['ctx_item=' + encodeURIComponent(doc.id)])
            .forEach(function (par) {
                if (!par) { return; }
                var trozo = par.split('=');
                var oculto = form.querySelector('input[type="hidden"][name="' + trozo[0] + '"]');
                if (!oculto) {
                    oculto = document.createElement('input');
                    oculto.type = 'hidden';
                    oculto.name = decodeURIComponent(trozo[0]);
                    form.appendChild(oculto);
                }
                oculto.value = decodeURIComponent(trozo.slice(1).join('=') || '');
            });

        if (typeof form.requestSubmit === 'function') { form.requestSubmit(); }
        else { form.submit(); }
    });
})();
</script>
