<?php
/**
 * Facturas ERP: carga del reporte "Facturas por Proveedor" y listado en
 * columnas. El foco es el saldo pendiente, así que el filtro de saldo va
 * arriba y la columna Saldo se resalta.
 */
$baseUrl = defined('APP_URL') ? APP_URL : '/xmlconcilia/public';
$facturas = is_array($facturas ?? null) ? $facturas : [];
$opciones = is_array($opciones ?? null) ? $opciones : ['sucursales' => []];
$proveedoresFiltro = is_array($proveedoresFiltro ?? null) ? $proveedoresFiltro : [];
$cargas = is_array($cargas ?? null) ? $cargas : [];
$ultimaCarga = $ultimaCarga ?? null;
$incidenciasTotal = (int) ($incidenciasTotal ?? 0);
$incidenciasAbiertas = (int) ($incidenciasAbiertas ?? 0);
$pagina = (int) ($pagina ?? 1);
$totalPaginas = (int) ($totalPaginas ?? 1);
$total = (int) ($total ?? 0);

$filtros = array_replace([
    'texto' => '', 'proveedor' => '', 'sucursal' => '', 'origen' => '', 'estado' => '',
    'desde' => '', 'hasta' => '', 'solo_saldo' => 0,
], is_array($filtros ?? null) ? $filtros : []);

$queryFiltros = array_filter([
    'q' => $filtros['texto'], 'proveedor' => $filtros['proveedor'],
    'sucursal' => $filtros['sucursal'], 'origen' => $filtros['origen'], 'estado' => $filtros['estado'],
    'desde' => $filtros['desde'], 'hasta' => $filtros['hasta'],
    'solo_saldo' => $filtros['solo_saldo'] ? '1' : '',
], function ($v) { return $v !== '' && $v !== null; });

$urlExportar = $baseUrl . '/facturas-erp/exportar?' . http_build_query($queryFiltros);

function feFecha($f)
{
    $ts = $f ? strtotime((string) $f) : false;
    return $ts !== false ? date('d/m/Y', $ts) : '—';
}
function feMonto($v)
{
    return number_format((float) $v, 2);
}
?>

<!-- ── Subir listado ── -->
<div class="card mb-20">
    <div class="card-header mb-12" style="flex-wrap:wrap;">
        <div class="card-title">
            <i class="fas fa-file-arrow-up" style="margin-right:6px;color:var(--navy-light);"></i>
            Cargar listado del ERP
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

    <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
        <a href="<?= $baseUrl ?>/carga" class="btn btn-primary btn-sm">
            <i class="fas fa-inbox" style="margin-right:4px;"></i>Cargar listado
        </a>
        <span style="font-size:11px;color:var(--text-muted);max-width:560px;">
            <i class="fas fa-circle-info" style="margin-right:3px;color:var(--navy-light);"></i>
            El reporte <strong>Facturas por Proveedor</strong> se carga desde
            <a href="<?= $baseUrl ?>/carga">Carga de documentos</a>, junto con el resto de los
            archivos. Se puede volver a subir cuantas veces haga falta: las facturas nuevas se
            agregan, las que cambiaron de saldo se actualizan y las que siguen igual no se tocan.
        </span>
    </div>
</div>

<?php /*
 * Acá vivía una fila de cuatro tarjetas: facturas, con saldo, proveedores e
 * incidencias. Eran cifras de lectura —cuánto hay— y este módulo es para
 * buscar una factura, no para mirar totales.
 *
 * La de Incidencias no era una cifra: era la única puerta a esa pantalla. Se
 * quedó, convertida en el botón que le corresponde, junto a Exportar.
 */ ?>

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
        <a href="<?= $baseUrl ?>/facturas-erp/incidencias" class="btn btn-outline btn-sm"
           title="Problemas detectados al leer las cargas del reporte del ERP">
            <i class="fas fa-triangle-exclamation" style="margin-right:4px;<?= $incidenciasAbiertas > 0 ? 'color:var(--diff);' : '' ?>"></i>Incidencias
            <?php if ($incidenciasAbiertas > 0): ?>
            <span class="badge" style="font-size:10px;padding:1px 6px;margin-left:4px;background:#fee2e2;color:#991b1b;">
                <?= number_format($incidenciasAbiertas) ?>
            </span>
            <?php endif; ?>
        </a>
        <a href="<?= htmlspecialchars($urlExportar) ?>" class="btn btn-outline btn-sm">
            <i class="fas fa-file-csv" style="margin-right:4px;"></i>Exportar
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
                <option value="asignada_semana" <?= $filtros['estado'] === 'asignada_semana' ? 'selected' : '' ?>>Asignada a una semana</option>
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

    <div style="overflow-x:auto;">
        <table class="data-table" style="font-size:12.5px;min-width:1250px;">
            <thead>
                <tr>
                    <th>Documento</th>
                    <th>Proveedor</th>
                    <th>Estado</th>
                    <th>Sucursal</th>
                    <th>Fecha</th>
                    <th>Vence</th>
                    <th>Compra</th>
                    <th class="center">Moneda</th>
                    <th class="right">Monto</th>
                    <th class="right">Saldo</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$facturas): ?>
                <tr>
                    <td colspan="10" class="muted" style="text-align:center;padding:18px;">
                        <?= $total === 0 && !$queryFiltros
                            ? 'Todavía no se ha cargado ningún listado. Subí el CSV del ERP para empezar.'
                            : 'Ninguna factura coincide con el filtro.' ?>
                    </td>
                </tr>
                <?php endif; ?>
                <?php foreach ($facturas as $f):
                    $saldo = (float) $f['saldo'];
                    $tieneSaldo = $saldo > 0.005;
                    $doc = (string) $f['documento'];
                ?>
                <tr>
                    <td style="font-family:ui-monospace,monospace;font-size:11.5px;">
                        <?php if ($doc !== ''): ?>
                            <?= htmlspecialchars($f['tipo']) ?>-<?= htmlspecialchars($doc) ?>
                        <?php else: ?>
                            <span class="muted" title="El reporte no imprime número para esta factura">sin número</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= htmlspecialchars($f['proveedor_nombre']) ?>
                        <div class="muted" style="font-size:10.5px;"><?= htmlspecialchars($f['proveedor_codigo']) ?></div>
                    </td>
                    <td style="white-space:nowrap;">
                        <?php if (($f['estado'] ?? 'pendiente') === 'asignada_semana'): ?>
                            <span class="badge badge-ok" style="font-size:10px;padding:2px 7px;">
                                <i class="fas fa-calendar-check"></i> Asignada a una semana
                            </span>
                            <?php if (!empty($f['semana_nombre'])): ?>
                            <div class="muted" style="font-size:10.5px;margin-top:3px;">
                                <?= htmlspecialchars((string) $f['semana_nombre']) ?>
                            </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge badge-default" style="font-size:10px;padding:2px 7px;">Pendiente</span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($f['sucursal'] !== '' ? $f['sucursal'] : '—') ?></td>
                    <td><?= feFecha($f['fecha_emision']) ?></td>
                    <td><?= feFecha($f['fecha_vence']) ?></td>
                    <td>
                        <span class="badge <?= $f['origen'] === 'Local' ? 'badge-navy' : 'badge-default' ?>"
                              style="font-size:10px;padding:2px 7px;">
                            <?= htmlspecialchars($f['origen']) ?>
                        </span>
                    </td>
                    <td class="center"><?= htmlspecialchars($f['moneda']) ?></td>
                    <td class="right"><?= feMonto($f['monto']) ?></td>
                    <td class="right" style="<?= $tieneSaldo ? 'font-weight:700;color:var(--warn);' : 'color:var(--text-muted);' ?>">
                        <?= feMonto($saldo) ?>
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

<?php if ($cargas): ?>
<!-- ── Historial de cargas ── -->
<div class="card mt-20">
    <div class="card-header mb-12">
        <div class="card-title">
            <i class="fas fa-clock-rotate-left" style="margin-right:6px;color:var(--navy-light);"></i>
            Historial de cargas
        </div>
    </div>
    <div style="overflow-x:auto;">
        <table class="data-table" style="font-size:12px;min-width:860px;">
            <thead>
                <tr>
                    <th>Archivo</th><th>Reporte impreso</th><th class="right">Leídas</th>
                    <th class="right">Nuevas</th><th class="right">Actualizadas</th>
                    <th class="right">Sin cambio</th><th>Cuadre</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($cargas as $c): ?>
                <tr>
                    <td>
                        <?= htmlspecialchars((string) $c['archivo_origen']) ?>
                        <div class="muted" style="font-size:10.5px;">
                            subido <?= date('d/m/Y H:i', strtotime((string) $c['creado_en'])) ?>
                        </div>
                    </td>
                    <td><?= !empty($c['impreso_en']) ? date('d/m/Y H:i', strtotime((string) $c['impreso_en'])) : '—' ?></td>
                    <td class="right"><?= number_format((int) $c['filas_leidas']) ?></td>
                    <td class="right"><?= number_format((int) $c['insertadas']) ?></td>
                    <td class="right"><?= number_format((int) $c['actualizadas']) ?></td>
                    <td class="right muted"><?= number_format((int) $c['sin_cambio']) ?></td>
                    <td>
                        <?php if ((int) $c['cuadre_ok'] === 1): ?>
                            <span class="badge badge-ok" style="font-size:10px;padding:2px 7px;">
                                <?= number_format((int) $c['totales_verificados']) ?> totales OK
                            </span>
                        <?php else: ?>
                            <span class="badge badge-warn" style="font-size:10px;padding:2px 7px;"
                                  title="<?= htmlspecialchars((string) ($c['advertencias'] ?? '')) ?>">
                                <?= number_format((int) $c['totales_descuadrados']) ?> sin cuadrar
                            </span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
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
