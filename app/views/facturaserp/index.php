<?php
require_once __DIR__ . '/../../helpers/Columnas.php';
/**
 * Facturas: carga del reporte "Facturas por Proveedor" del ERP y listado en
 * columnas. El foco es el saldo pendiente, así que el filtro de saldo va
 * arriba y la columna Saldo se resalta.
 *
 * El formulario de subida está acá y no en una pantalla aparte: lo que el
 * archivo produce se ve en la tabla de abajo, así que cargar y comprobar es
 * el mismo sitio.
 */
$baseUrl = defined('APP_URL') ? APP_URL : '/xmlconcilia/public';
$facturas = is_array($facturas ?? null) ? $facturas : [];
$opciones = is_array($opciones ?? null) ? $opciones : ['sucursales' => []];
$proveedoresFiltro = is_array($proveedoresFiltro ?? null) ? $proveedoresFiltro : [];
$ultimaCarga = $ultimaCarga ?? null;
$incidenciasTotal = (int) ($incidenciasTotal ?? 0);
$incidenciasAbiertas = (int) ($incidenciasAbiertas ?? 0);
$revisionPendientes = (int) ($revisionPendientes ?? 0);
$quitadasTotal = (int) ($quitadasTotal ?? 0);
$puedeQuitar = !empty($puedeQuitar);
$notasPorFactura = is_array($notasPorFactura ?? null) ? $notasPorFactura : [];
$pagina = (int) ($pagina ?? 1);
$totalPaginas = (int) ($totalPaginas ?? 1);
$total = (int) ($total ?? 0);

$filtros = array_replace([
    'texto' => '', 'proveedor' => '', 'sucursal' => '', 'origen' => '', 'estado' => '',
    'desde' => '', 'hasta' => '', 'solo_saldo' => 0,
    'monto' => '', 'saldo' => '',
], is_array($filtros ?? null) ? $filtros : []);

$queryFiltros = array_filter([
    'q' => $filtros['texto'], 'proveedor' => $filtros['proveedor'],
    'sucursal' => $filtros['sucursal'], 'origen' => $filtros['origen'], 'estado' => $filtros['estado'],
    'desde' => $filtros['desde'], 'hasta' => $filtros['hasta'],
    'solo_saldo' => $filtros['solo_saldo'] ? '1' : '',
    // Los importes también viajan al exportar: lo que se baja tiene que ser
    // lo que se está viendo.
    'monto' => $filtros['monto'], 'saldo' => $filtros['saldo'],
], function ($v) { return $v !== '' && $v !== null; });

// Las columnas se las pega app.js al enlace: quién las eligió lo sabe el
// navegador, no el servidor.
$urlExportar = $baseUrl . '/facturas-erp/exportar?' . http_build_query($queryFiltros);

function feFecha($f)
{
    $ts = $f ? strtotime((string) $f) : false;
    return $ts !== false ? date('d/m/Y', $ts) : '—';
}
/**
 * El importe con su símbolo pegado.
 *
 * Había una columna "Moneda" para un carácter, y las 5.000 facturas del
 * listado dicen colones salvo un puñado. Con el símbolo en el número la
 * columna sobra, y las pocas en dólares se distinguen mejor así que teniendo
 * que mirar de reojo dos columnas más allá.
 */
function feMonto($v, $moneda = '')
{
    $simbolo = strpos((string) $moneda, '$') !== false ? '$' : '₡';
    return $simbolo . number_format((float) $v, 2);
}
?>

<!-- ── Subir listado ── -->
<div class="card mb-20">
    <div class="card-header mb-12" style="flex-wrap:wrap;">
        <div class="card-title">
            <i class="fas fa-file-arrow-up" style="margin-right:6px;color:var(--navy-light);"></i>
            Cargar listado del sistema
        </div>
        <a href="<?= $baseUrl ?>/facturas" class="btn btn-primary btn-sm" style="margin-left:auto;"
           title="Importar comprobantes XML">
            <i class="fas fa-file-code" style="margin-right:4px;"></i>Cargar facturas XML
        </a>
        <?php if ($ultimaCarga): ?>
        <div style="font-size:11.5px;color:var(--text-muted);">
            Última carga:
            <strong><?= htmlspecialchars((string) $ultimaCarga['archivo_origen']) ?></strong>
            <?php if (!empty($ultimaCarga['impreso_en'])): ?>
                · reporte impreso el <?= date('d/m/Y H:i', strtotime((string) $ultimaCarga['impreso_en'])) ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php /*
     * El CSV se mira antes de aplicarse, como en Notas de crédito y en los
     * pagos. Este archivo mueve de una vez el saldo de miles de facturas: el
     * botón dice "Vista previa" y no "Cargar" porque hasta que no se confirma
     * en el modal no se escribe nada.
     */ ?>
    <form method="POST" action="<?= $baseUrl ?>/facturas-erp/previa" enctype="multipart/form-data"
          id="fe-form-subir" style="display:flex;gap:9px;align-items:center;flex-wrap:wrap;">
        <input type="file" name="listado_file" id="fe-listado-file" accept=".csv" required
               style="display:none;" onchange="feNombreArchivo(this)">
        <label for="fe-listado-file" class="upload-file-btn" style="padding:8px 16px;font-size:12.5px;">
            <i class="fas fa-folder-open"></i> Seleccionar CSV
        </label>
        <span id="fe-listado-nombre" style="font-size:12px;color:var(--text-muted);font-style:italic;">
            Ningún archivo seleccionado
        </span>
        <button type="submit" class="btn btn-primary btn-sm" id="fe-btn-previa">
            <i class="fas fa-eye" style="margin-right:4px;"></i>Vista previa
        </button>
    </form>
</div>

<!-- ── Vista previa del listado: qué cambiaría si se aplica ── -->
<div id="fe-previa-modal" role="dialog" aria-modal="true" aria-labelledby="fe-previa-titulo"
     style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:1000;
            align-items:center;justify-content:center;padding:12px;">
    <div style="background:#fff;border-radius:10px;width:min(1000px,100%);max-height:88vh;
                display:flex;flex-direction:column;overflow:hidden;">
        <div style="padding:9px 13px;border-bottom:1px solid var(--border);display:flex;gap:8px;align-items:center;">
            <i class="fas fa-eye" style="color:var(--gold);"></i>
            <div style="min-width:0;">
                <strong id="fe-previa-titulo">Vista previa del listado</strong>
                <div id="fe-previa-meta" style="font-size:12px;color:var(--text-muted);"></div>
            </div>
            <button type="button" data-fe-previa-cerrar aria-label="Cerrar"
                    style="margin-left:auto;background:none;border:0;font-size:24px;cursor:pointer;
                           color:var(--text-muted);line-height:1;">&times;</button>
        </div>

        <div style="padding:10px 13px;overflow:auto;">
            <div id="fe-previa-resumen" style="display:flex;gap:12px;flex-wrap:wrap;font-size:12px;margin-bottom:8px;"></div>
            <div id="fe-previa-avisos"></div>

            <?php /*
             * La tabla no lista las 5.700 facturas del reporte: lista las que
             * cambian de saldo, que es lo único que una carga hace de verdad.
             * Las nuevas y las que quedan igual se cuentan arriba.
             */ ?>
            <div id="fe-previa-cambios-caja" style="display:none;">
                <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;
                            letter-spacing:.04em;margin:10px 0 4px;">
                    Saldos que cambian <span id="fe-previa-cambios-n"></span>
                </div>
                <div style="overflow:auto;max-height:45vh;">
                    <table class="table" style="min-width:760px;font-size:12.5px;">
                        <thead><tr>
                            <th>Proveedor</th><th>Documento</th>
                            <th style="text-align:right;">Saldo actual</th>
                            <th style="text-align:right;">Saldo del reporte</th>
                            <th style="text-align:right;">Diferencia</th>
                        </tr></thead>
                        <tbody id="fe-previa-cuerpo"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <div style="padding:9px 13px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:7px;">
            <button type="button" class="btn btn-outline btn-sm" data-fe-previa-cerrar>Cancelar</button>
            <form method="POST" action="<?= $baseUrl ?>/facturas-erp/subir" style="margin:0;">
                <input type="hidden" name="archivo_token" id="fe-previa-token">
                <input type="hidden" name="archivo_nombre" id="fe-previa-original">
                <button class="btn btn-primary btn-sm" id="fe-previa-confirmar">
                    <i class="fas fa-database" style="margin-right:4px;"></i>Aplicar listado
                </button>
            </form>
        </div>
    </div>
</div>

<style>
.fe-tabla .fe-badge-mini{font-size:10px;padding:2px 7px;white-space:nowrap;font-family:inherit}
/* Venció y todavía se debe. Sin saldo no se marca: no hay nada que correr. */
.fe-tabla .fe-vencida{color:var(--miss);font-weight:700}
.fe-tabla td{vertical-align:top}
</style>

<script>
function feNombreArchivo(input) {
    document.getElementById('fe-listado-nombre').textContent =
        input.files.length ? input.files[0].name : 'Ningún archivo seleccionado';
}
</script>

<script>
// El CSV se lee sin escribir y el modal dice qué cambiaría. Confirmar reenvía
// el archivo que quedó guardado, por su token: se aplica el mismo que se miró.
(function () {
    var form = document.getElementById('fe-form-subir');
    var modal = document.getElementById('fe-previa-modal');
    if (!form || !modal) { return; }

    var btn = document.getElementById('fe-btn-previa');
    var confirmar = document.getElementById('fe-previa-confirmar');

    function esc(v) {
        var d = document.createElement('div');
        d.textContent = String(v == null ? '' : v);
        return d.innerHTML;
    }
    function monto(v) {
        return Number(v || 0).toLocaleString('en-US',
            { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function colones(v) { return '₡' + monto(v); }
    function cerrar() { modal.style.display = 'none'; }

    modal.addEventListener('click', function (e) {
        if (e.target === modal || e.target.hasAttribute('data-fe-previa-cerrar')) { cerrar(); }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal.style.display === 'flex') { cerrar(); }
    });

    function pintar(d) {
        var imp = d.impacto || {};
        var rev = d.revision || {};
        var cua = d.cuadre || {};

        document.getElementById('fe-previa-meta').textContent =
            d.archivo + (d.impreso_en ? ' · impreso el ' + d.impreso_en : '') +
            (d.sociedad ? ' · ' + d.sociedad : '');

        document.getElementById('fe-previa-resumen').innerHTML =
            '<span>Facturas leídas: <b>' + (d.leidas || 0) + '</b></span>' +
            '<span>Proveedores: <b>' + (d.proveedores || 0) + '</b></span>' +
            '<span>Nuevas: <b>' + (imp.nuevas || 0) + '</b></span>' +
            '<span>Saldo cambia: <b>' + (imp.actualizadas || 0) + '</b></span>' +
            '<span>Sin cambios: <b>' + (imp.sin_cambio || 0) + '</b></span>';

        var avisos = '';

        // Lo que impide aplicar va primero y apaga el botón: no tiene sentido
        // leer el resto si la carga se va a negar igual.
        if (!d.puede_cargar) {
            avisos += '<div class="alert alert-danger" style="margin-bottom:10px;">' +
                '<strong>Este archivo no se puede aplicar.</strong> ' +
                esc((d.errores || []).join(' ')) + '</div>';
        }
        if (cua.descuadres) {
            avisos += '<div class="alert alert-warning" style="margin-bottom:10px;">' +
                cua.descuadres + ' total(es) impresos no cuadran con lo leído. ' +
                'Se puede aplicar igual; quedan en Incidencias.' +
                '<div style="font-size:11.5px;margin-top:4px;">' +
                (cua.detalle || []).map(function (t) { return esc(t); }).join('<br>') + '</div></div>';
        } else if (cua.saldo_general_ok) {
            avisos += '<div class="alert alert-success" style="margin-bottom:10px;">' +
                'El saldo leído (' + colones(cua.saldo_leido) +
                ') coincide con el Total General del reporte.</div>';
        }
        if (d.repetida) {
            avisos += '<div class="alert alert-info" style="margin-bottom:10px;">' +
                'Este mismo reporte ya se cargó (' + esc(d.repetida.archivo) + ', ' +
                esc(d.repetida.cuando) + '). Se puede aplicar de nuevo; las filas iguales no se tocan.</div>';
        }
        if (rev.pendientes) {
            avisos += '<div class="alert alert-warning" style="margin-bottom:10px;">' +
                rev.pendientes + ' línea(s) quedan en revisión: no se pudieron leer, o son ' +
                'idénticas a otra del archivo. Ninguna se descarta sola.</div>';
        }
        if (imp.quitadas) {
            avisos += '<div class="alert alert-info" style="margin-bottom:10px;">' +
                imp.quitadas + ' factura(s) del reporte se saltan: las quitaste del listado.</div>';
        }
        if (rev.rescatadas || rev.descartadas) {
            avisos += '<div class="alert alert-info" style="margin-bottom:10px;">' +
                (rev.rescatadas ? rev.rescatadas + ' línea(s) entran con la corrección que ya guardaste. ' : '') +
                (rev.descartadas ? rev.descartadas + ' se descartan: ya lo decidiste.' : '') +
                '</div>';
        }
        // El dinero en movimiento, cuando lo hay: es la frase que dice si este
        // archivo es la carga rutinaria de siempre o algo fuera de lo normal.
        if (imp.actualizadas) {
            avisos += '<div style="font-size:12px;color:var(--text-muted);margin-bottom:8px;">' +
                'Bajan ' + colones(imp.saldo_baja) + ' y suben ' + colones(imp.saldo_sube) +
                (imp.nuevas ? ' · entran ' + colones(imp.saldo_nuevas) + ' en facturas nuevas' : '') +
                '.</div>';
        }
        document.getElementById('fe-previa-avisos').innerHTML = avisos;

        var cambios = imp.cambios || [];
        var caja = document.getElementById('fe-previa-cambios-caja');
        caja.style.display = cambios.length ? '' : 'none';
        document.getElementById('fe-previa-cambios-n').textContent =
            cambios.length < (imp.cambios_total || 0)
                ? '(' + cambios.length + ' de ' + imp.cambios_total + ', los de mayor diferencia)'
                : '(' + cambios.length + ')';
        document.getElementById('fe-previa-cuerpo').innerHTML = cambios.map(function (c) {
            var baja = Number(c.diferencia) < 0;
            return '<tr><td>' + esc(c.proveedor_nombre) + '</td>' +
                '<td>' + esc(c.documento) + '</td>' +
                '<td style="text-align:right;">' + monto(c.anterior) + '</td>' +
                '<td style="text-align:right;">' + monto(c.nuevo) + '</td>' +
                '<td style="text-align:right;color:' + (baja ? 'var(--ok,#16a34a)' : 'var(--diff,#b45309)') + ';">' +
                (baja ? '' : '+') + monto(c.diferencia) + '</td></tr>';
        }).join('');

        document.getElementById('fe-previa-token').value = d.token || '';
        document.getElementById('fe-previa-original').value = d.archivo || '';
        confirmar.disabled = !d.puede_cargar;
        modal.style.display = 'flex';
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin" style="margin-right:4px;"></i>Leyendo…';
        fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin' })
            .then(function (res) {
                return res.json().catch(function () { return null; }).then(function (body) {
                    if (!res.ok || !body || body.ok === false) {
                        throw new Error((body && body.message) || ('Error HTTP ' + res.status));
                    }
                    return body;
                });
            })
            .then(pintar)
            .catch(function (err) {
                AppDialog.alert(err.message, { title: 'No se pudo leer el listado', type: 'danger' });
            })
            .then(function () {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-eye" style="margin-right:4px;"></i>Vista previa';
            });
    });
})();
</script>

<?php /*
 * Acá vivía una fila de cuatro tarjetas: facturas, con saldo, proveedores e
 * incidencias. Eran cifras de lectura —cuánto hay— y este módulo es para
 * buscar una factura, no para mirar totales.
 *
 * La de Incidencias no era una cifra: era la única puerta a esa pantalla. Se
 * quedó, convertida en el botón que le corresponde, junto a Exportar.
 */ ?>

<?php if ($elegirProveedor ?? false):
/*
 * Sin listado: el controlador no lo consultó. La barra de arriba —cargar el
 * reporte, ver incidencias— se queda, que para eso no hace falta elegir.
 */
$elegirProv = [
    'accion'   => $baseUrl . '/facturas-erp',
    'opciones' => $proveedoresFiltro,
    'cuantos'  => $totalDelArchivo ?? null,
    'que'      => 'facturas del sistema',
];
include __DIR__ . '/../partials/elegir-proveedor.php';
else: ?>

<!-- ── Listado ── -->
<div class="card">
    <div class="card-header mb-12">
        <div class="card-title" style="margin-right:auto;">
            <i class="fas fa-table-list" style="margin-right:6px;color:var(--gold);"></i>
            Facturas del listado
            <span class="badge badge-navy" style="font-size:10px;padding:2px 8px;margin-left:4px;">
                <?= number_format($total) ?>
            </span>
        </div>
        <?php if ($revisionPendientes > 0): ?>
        <a href="<?= $baseUrl ?>/facturas-erp/revision" class="btn btn-outline btn-sm"
           title="Líneas del reporte que no se pudieron leer y esperan que decidas si entran"
           style="border-color:#f59e0b;color:#92400e;">
            <i class="fas fa-inbox" style="margin-right:4px;color:#d97706;"></i>En revisión
            <span class="badge" style="font-size:10px;padding:1px 6px;margin-left:4px;background:#fef3c7;color:#92400e;">
                <?= number_format($revisionPendientes) ?>
            </span>
        </a>
        <?php endif; ?>
        <a href="<?= $baseUrl ?>/facturas-erp/incidencias" class="btn btn-outline btn-sm"
           title="Problemas detectados al leer las cargas del reporte del sistema">
            <i class="fas fa-triangle-exclamation" style="margin-right:4px;<?= $incidenciasAbiertas > 0 ? 'color:var(--diff);' : '' ?>"></i>Incidencias
            <?php if ($incidenciasAbiertas > 0): ?>
            <span class="badge" style="font-size:10px;padding:1px 6px;margin-left:4px;background:#fee2e2;color:#991b1b;">
                <?= number_format($incidenciasAbiertas) ?>
            </span>
            <?php endif; ?>
        </a>
        <?php if ($quitadasTotal > 0): ?>
        <a href="<?= $baseUrl ?>/facturas-erp/quitadas" class="btn btn-outline btn-sm"
           title="Facturas que sacaste del listado. Las cargas del reporte las saltan; desde ahí se pueden devolver.">
            <i class="fas fa-box-archive" style="margin-right:4px;"></i>Quitadas
            <span class="badge badge-default" style="font-size:10px;padding:1px 6px;margin-left:4px;">
                <?= number_format($quitadasTotal) ?>
            </span>
        </a>
        <?php endif; ?>
        <a href="<?= htmlspecialchars($urlExportar) ?>" class="btn btn-outline btn-sm"
           data-exportar="facturaserp"
           title="Bajar a Excel lo que estás viendo: estos filtros y estas columnas">
            <i class="fas fa-file-excel" style="margin-right:4px;"></i>Exportar
        </a>
    </div>

    <form method="GET" action="<?= $baseUrl ?>/facturas-erp" class="filter-bar">
        <?php
        /*
         * Primero el proveedor: es por donde se entra a este listado. Después
         * el número, la sucursal y en qué va el pago.
         *
         * "Compra (Local/Exter)" salió de la barra: no es una pregunta que se
         * haga al buscar una factura, y el listado ya trae la columna. Sigue
         * funcionando por la URL.
         */
        $provFiltro = [
            'valor'    => $filtros['proveedor'],
            'opciones' => $proveedoresFiltro ?? [],
        ]; include __DIR__ . '/../partials/filtro-proveedor.php';
        ?>
        <div class="filter-span-2">
            <label class="filter-label">Buscar</label>
            <input type="text" name="q" class="form-control" placeholder="Documento, código o nombre"
                   value="<?= htmlspecialchars($filtros['texto']) ?>">
        </div>
        <div>
            <label class="filter-label">Sucursal</label>
            <select name="sucursal" class="form-control">
                <option value="">Todas</option>
                <?php foreach ($opciones['sucursales'] as $s): ?>
                <option value="<?= htmlspecialchars($s['sucursal']) ?>"
                    <?= $filtros['sucursal'] === $s['sucursal'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($s['sucursal'] !== '' ? $s['sucursal'] : '(sin sucursal)') ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="filter-label">Estado de pago</label>
            <select name="estado" class="form-control">
                <option value="">Todos</option>
                <option value="pendiente" <?= $filtros['estado'] === 'pendiente' ? 'selected' : '' ?>>Pendiente</option>
                <option value="asignada_pago" <?= $filtros['estado'] === 'asignada_pago' ? 'selected' : '' ?>>Asignada a un pago</option>
            </select>
        </div>
        <div>
            <label class="filter-label">Emitida desde</label>
            <input type="date" name="desde" class="form-control" value="<?= htmlspecialchars($filtros['desde']) ?>">
        </div>
        <div>
            <label class="filter-label">Emitida hasta</label>
            <input type="date" name="hasta" class="form-control" value="<?= htmlspecialchars($filtros['hasta']) ?>">
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
        <div style="display:flex;align-items:flex-end;gap:8px;">
            <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer;white-space:nowrap;">
                <input type="checkbox" name="solo_saldo" value="1" <?= $filtros['solo_saldo'] ? 'checked' : '' ?>>
                Solo con saldo
            </label>
        </div>
        <div style="display:flex;align-items:flex-end;gap:8px;">
            <button type="submit" class="btn btn-primary btn-sm">
                <i class="fas fa-filter" style="margin-right:4px;"></i>Filtrar
            </button>
            <a href="<?= $baseUrl ?>/facturas-erp?limpiar=1" class="btn btn-outline btn-sm">Limpiar</a>
        </div>
    </form>

    <?php $columnasListado = 'facturaserp'; include __DIR__ . '/../partials/columnas.php'; ?>
    <div style="overflow-x:auto;">
        <table class="data-table fe-tabla" style="font-size:12.5px;min-width:820px;" data-listado="facturaserp">
            <?php /*
             * Un dato por celda. Hubo un tiempo en que código y sucursal iban
             * bajo el proveedor, el vencimiento bajo la emisión y el pago, la
             * compra y las notas directas en insignias bajo el documento: la
             * tabla era angosta, pero nada de eso se podía mover, apagar ni
             * leer de arriba abajo. Cada uno tiene ahora su columna, y quien
             * no la quiera la apaga desde la esquina.
             *
             * Moneda y estado siguen apagadas de fábrica: el símbolo va
             * pegado al importe, y "asignada a un pago" ya lo dice la columna
             * Pago con el nombre.
             */ ?>
            <thead>
                <tr>
                    <th data-col="documento">Documento</th>
                    <th data-col="proveedor">Proveedor</th>
                    <th data-col="codigo">Código proveedor</th>
                    <th data-col="sucursal">Sucursal</th>
                    <th data-col="fecha">Fecha</th>
                    <th data-col="vence">Vence</th>
                    <th class="right" data-col="monto">Monto</th>
                    <th class="right" data-col="saldo">Saldo</th>
                    <th class="center" data-col="notas_aplicables"
                        title="Notas de crédito que se le pueden aplicar a la factura">Notas aplicables</th>
                    <th data-col="pago">Pago</th>
                    <th data-col="origen">Compra</th>
                    <?= Columnas::cabecerasExtra('facturaserp') ?>
                    <?php // Esta tabla no tiene columna de acciones, así que el
                          // botón necesita su propia esquina. ?>
                    <th style="width:1%;" data-columnas-boton></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$facturas): ?>
                <tr>
                    <td colspan="13" class="muted" style="text-align:center;padding:18px;">
                        <?= $total === 0 && !$queryFiltros
                            ? 'Todavía no se ha cargado ningún listado. Subí el CSV del sistema para empezar.'
                            : 'Ninguna factura coincide con el filtro.' ?>
                    </td>
                </tr>
                <?php endif; ?>
                <?php foreach ($facturas as $f):
                    $saldo = (float) $f['saldo'];
                    $tieneSaldo = $saldo > 0.005;
                    $doc = (string) $f['documento'];
                    // Las notas de crédito que se le pueden aplicar a ESTA factura.
                    $notasFactura = $notasPorFactura[(int) $f['id']] ?? [];
                    $saldoNotas = 0.0;
                    foreach ($notasFactura as $nf) { $saldoNotas += (float) $nf['saldo']; }

                    $asignada = ($f['estado'] ?? 'pendiente') === 'asignada_pago';
                    // "Local" es el 100 % del listado: se escribe apagado, y
                    // lo que no lo es resalta.
                    $importada = trim((string) $f['origen']) !== '' && $f['origen'] !== 'Local';
                    // Vencida solo cuenta si todavía se debe: sin saldo no hay nada que correr.
                    $vencida = $tieneSaldo && !empty($f['fecha_vence'])
                        && $f['fecha_vence'] < date('Y-m-d');
                ?>
                <tr>
                    <td data-col="documento">
                        <div style="font-family:ui-monospace,monospace;font-size:11.5px;font-weight:650;">
                            <?php if ($doc !== ''): ?>
                                <?= htmlspecialchars($f['tipo']) ?>-<?= htmlspecialchars($doc) ?>
                            <?php else: ?>
                                <span class="muted" title="El reporte no imprime número para esta factura">sin número</span>
                            <?php endif; ?>
                        </div>
                    </td>

                    <td data-col="proveedor"><?= htmlspecialchars($f['proveedor_nombre']) ?></td>

                    <td style="white-space:nowrap;" data-col="codigo">
                        <?= trim((string) $f['proveedor_codigo']) !== ''
                            ? htmlspecialchars($f['proveedor_codigo']) : '<span class="muted">—</span>' ?>
                    </td>

                    <td style="white-space:nowrap;" data-col="sucursal">
                        <?= trim((string) $f['sucursal']) !== ''
                            ? htmlspecialchars($f['sucursal']) : '<span class="muted">—</span>' ?>
                    </td>

                    <td style="white-space:nowrap;" data-col="fecha"><?= feFecha($f['fecha_emision']) ?></td>

                    <?php // Una factura vencida que todavía se debe se marca:
                          // sin saldo no hay nada que correr. ?>
                    <td style="white-space:nowrap;" data-col="vence"
                        <?= $vencida ? 'class="fe-vencida" title="Venció y todavía tiene saldo"' : '' ?>>
                        <?php if (empty($f['fecha_vence'])): ?>
                        <span class="muted">—</span>
                        <?php else: ?>
                        <?php if ($vencida): ?><i class="fas fa-triangle-exclamation"></i><?php endif; ?>
                        <?= feFecha($f['fecha_vence']) ?>
                        <?php endif; ?>
                    </td>

                    <?php /*
                     * Monto y saldo, cada uno en su columna. Antes mandaba el
                     * saldo y el monto asomaba debajo solo cuando no
                     * coincidían: para comparar dos facturas por lo que
                     * costaron había que leer renglón por renglón cuál
                     * mostraba el suyo y cuál no.
                     */ ?>
                    <td class="right" style="white-space:nowrap;" data-col="monto">
                        <?= feMonto($f['monto'], $f['moneda']) ?>
                    </td>

                    <td class="right" style="white-space:nowrap;" data-col="saldo">
                        <span style="<?= $tieneSaldo ? 'font-weight:700;color:var(--warn);' : 'color:var(--text-muted);' ?>">
                            <?= $tieneSaldo ? feMonto($saldo, $f['moneda']) : 'sin saldo' ?>
                        </span>
                    </td>

                    <?php // El aviso que hay que ver antes de pagarla completa: si
                          // se paga entera, la nota queda sin dónde descontarse.
                          // El mismo botón que en Pago y en Seguimiento. ?>
                    <td class="center" data-col="notas_aplicables">
                        <?php
                        $naFacturaId = (int) $f['id'];
                        $naDocumento = (string) $f['documento'];
                        $naCuantas = count($notasFactura);
                        $naSaldo = $saldoNotas;
                        include __DIR__ . '/../partials/notas-aplicables.php';
                        ?>
                    </td>

                    <td style="white-space:nowrap;" data-col="pago">
                        <?php if ($asignada): ?>
                        <span class="badge badge-ok fe-badge-mini"
                              title="Ya entró a un pago: su saldo está reservado">
                            <i class="fas fa-calendar-check" style="margin-right:3px;"></i>
                            <?= htmlspecialchars((string) ($f['pago_nombre'] ?: 'Asignada a un pago')) ?>
                        </span>
                        <?php else: ?>
                        <span class="muted">—</span>
                        <?php endif; ?>
                    </td>

                    <td style="white-space:nowrap;" data-col="origen">
                        <?php if ($importada): ?>
                        <span class="badge badge-default fe-badge-mini" title="Compra que no es local">
                            <?= htmlspecialchars($f['origen']) ?>
                        </span>
                        <?php else: ?>
                        <span class="muted"><?= htmlspecialchars(trim((string) $f['origen']) !== '' ? $f['origen'] : '—') ?></span>
                        <?php endif; ?>
                    </td>
                    <?= Columnas::celdasExtra('facturaserp', $f) ?>
                    <td style="white-space:nowrap;">
                        <?php /*
                         * Para la fila que no es una factura: la misma cargada
                         * dos veces en el ERP con otra fecha. Descartar su
                         * incidencia solo calla el aviso; esto la saca.
                         */ ?>
                        <?php if ($puedeQuitar): ?>
                        <button type="button" class="btn btn-outline btn-sm fe-quitar"
                                style="padding:2px 7px;font-size:11px;"
                                data-id="<?= (int) $f['id'] ?>"
                                data-factura="<?= htmlspecialchars($f['tipo'] . '-' . ($doc !== '' ? $doc : 'sin número')
                                    . ' · ' . $f['proveedor_nombre'] . ' · emitida ' . feFecha($f['fecha_emision'])
                                    . ' · ' . feMonto($f['monto'], $f['moneda'])) ?>"
                                <?= $asignada ? 'disabled title="Está en un pago: quitala primero del pago, en Pagos."'
                                              : 'title="Quitar del listado: no es una factura (por ejemplo, la misma cargada dos veces)"' ?>>
                            <i class="fas fa-eye-slash"></i>
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPaginas > 1): ?>
    <div style="display:flex;gap:8px;align-items:center;justify-content:center;margin-top:16px;font-size:12px;">
        <?php
        $qs = function ($p) use ($baseUrl, $queryFiltros) {
            return $baseUrl . '/facturas-erp?' . http_build_query(array_merge($queryFiltros, ['pagina' => $p]));
        };
        ?>
        <?php if ($pagina > 1): ?>
            <a href="<?= htmlspecialchars($qs($pagina - 1)) ?>" class="btn btn-outline btn-sm">Anterior</a>
        <?php endif; ?>
        <span class="muted">Página <?= $pagina ?> de <?= $totalPaginas ?></span>
        <?php if ($pagina < $totalPaginas): ?>
            <a href="<?= htmlspecialchars($qs($pagina + 1)) ?>" class="btn btn-outline btn-sm">Siguiente</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; // fin de "ya se eligió proveedor" ?>

<?php if ($puedeQuitar): ?>
<form method="POST" action="<?= $baseUrl ?>/facturas-erp/quitar" id="fe-quitar-form" style="display:none;">
    <input type="hidden" name="id" id="fe-quitar-id">
    <input type="hidden" name="motivo" id="fe-quitar-motivo">
</form>
<script>
document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('.fe-quitar') : null;
    if (!btn || btn.disabled) { return; }

    var campo = document.createElement('div');
    campo.innerHTML =
        '<label class="filter-label" for="fe-quitar-motivo-txt">Motivo (opcional)</label>' +
        '<input type="text" id="fe-quitar-motivo-txt" class="form-control" maxlength="255" ' +
        'placeholder="Ej.: cargada dos veces en el ERP">';

    AppDialog.form({
        title: 'Quitar del listado',
        type: 'warning',
        message: btn.dataset.factura + '\n\nDeja de verse en Facturas, Pagos y Seguimiento, y las ' +
            'próximas cargas del reporte la saltan. Se puede devolver desde "Quitadas".',
        content: campo,
        confirmText: 'Quitar',
        pendingText: 'Quitando…',
        onConfirm: function (cuerpo) {
            return { motivo: cuerpo.querySelector('#fe-quitar-motivo-txt').value };
        }
    }).then(function (r) {
        if (!r) { return; }
        document.getElementById('fe-quitar-id').value = btn.dataset.id;
        document.getElementById('fe-quitar-motivo').value = r.motivo || '';
        document.getElementById('fe-quitar-form').submit();
    });
});
</script>
<?php endif; ?>

<script>
function feMostrarArchivo(input) {
    var el = document.getElementById('fe-file-name');
    if (!el) return;
    if (input.files && input.files.length) {
        el.textContent = input.files[0].name;
        el.style.fontStyle = 'normal';
    } else {
        el.textContent = 'Ningún archivo seleccionado';
        el.style.fontStyle = 'italic';
    }
}
</script>
