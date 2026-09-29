<?php
require_once __DIR__ . '/../../helpers/Columnas.php';
$baseUrl = defined('APP_URL') ? APP_URL : '/xmlconcilia/public';
$devoluciones = $devoluciones ?? [];
$resumen = $resumen ?? ['pendiente' => 0, 'sin_nc' => 0, 'parcial' => 0, 'verificada' => 0, 'total' => 0];
$filtros = array_replace(
    ['tipo' => '', 'estado' => '', 'proveedor' => '', 'q' => ''],
    is_array($filtros ?? null) ? $filtros : []
);
$proveedoresFiltro = is_array($proveedoresFiltro ?? null) ? $proveedoresFiltro : [];

$badgeEstado = function ($estado) {
    switch ($estado) {
        case 'verificada': return '<span class="badge badge-green">Verificada</span>';
        case 'parcial': return '<span class="badge" style="background:#fef3c7;color:#92400e;">Parcial</span>';
        case 'sin_nc': return '<span class="badge" style="background:#fee2e2;color:#991b1b;">Sin NC</span>';
        default: return '<span class="badge badge-navy">Pendiente</span>';
    }
};
$labelTipo = function ($tipo) {
    return $tipo === 'boleta_local' ? 'Boleta (Ventas)' : 'Devolución (Cambios)';
};
?>
<div class="card" style="margin-bottom:10px;">
    <div class="card-header"><div class="card-title"><i class="fas fa-rotate-left" style="color:var(--gold);margin-right:6px;"></i>Devoluciones a proveedor</div></div>
    <form method="post" action="<?= $baseUrl ?>/devoluciones/subir" enctype="multipart/form-data" style="padding:0;display:flex;gap:7px;align-items:center;flex-wrap:wrap;">
        <input type="file" name="pdf_files[]" accept=".pdf" multiple required class="form-control" style="max-width:520px;">
        <button class="btn btn-primary" type="submit"><i class="fas fa-upload"></i> Importar reportes PDF</button>
        <span style="font-size:12px;color:var(--text-muted);">Acepta "Facturas Boleta Local" y "Reporte de Devolución a Proveedor". El PDF que no cuadre contra sus totales se rechaza.</span>
    </form>
</div>

<div class="card">
    <div class="card-header">
        <div class="card-title">
            Reportes importados <span class="badge badge-navy"><?= (int) $resumen['total'] ?></span>
        </div>
        <form method="post" action="<?= $baseUrl ?>/devoluciones/verificar" style="margin-left:auto;">
            <button class="btn btn-outline btn-sm" type="submit" title="Re-verificar todas las no verificadas">
                <i class="fas fa-rotate"></i> Verificar pendientes
            </button>
        </form>
    </div>

    <?php
    /*
     * Las insignias son el filtro de estado, y conservan lo demás: elegir un
     * proveedor y luego pulsar "Sin NC" tiene que dejar las dos cosas puestas,
     * no empezar de cero.
     */
    $urlEstado = function ($estado) use ($baseUrl, $filtros) {
        $q = array_filter([
            'q' => $filtros['q'], 'tipo' => $filtros['tipo'],
            'proveedor' => $filtros['proveedor'], 'estado' => $estado,
        ], 'strlen');
        return $baseUrl . '/devoluciones' . ($q ? '?' . http_build_query($q) : '');
    };
    $insignias = [
        ''           => ['Todas',       'badge badge-navy',  '', (int) $resumen['total']],
        'verificada' => ['Verificadas', 'badge badge-green', '', (int) $resumen['verificada']],
        'parcial'    => ['Parciales',   'badge', 'background:#fef3c7;color:#92400e;', (int) $resumen['parcial']],
        'sin_nc'     => ['Sin NC',      'badge', 'background:#fee2e2;color:#991b1b;', (int) $resumen['sin_nc']],
        'pendiente'  => ['Pendientes',  'badge', 'background:#e2e8f0;color:#334155;', (int) $resumen['pendiente']],
    ];
    ?>
    <div style="display:flex;gap:6px;flex-wrap:wrap;padding:7px 0 0;">
        <?php foreach ($insignias as $valor => [$etiqueta, $clase, $estilo, $cuantas]): ?>
        <a href="<?= htmlspecialchars($urlEstado($valor)) ?>" style="text-decoration:none;">
            <span class="<?= $clase ?>" style="<?= $estilo ?><?= $filtros['estado'] === $valor ? 'outline:2px solid var(--navy);outline-offset:1px;' : '' ?>">
                <?= $etiqueta ?>: <?= number_format($cuantas) ?>
            </span>
        </a>
        <?php endforeach; ?>
    </div>

    <form method="get" action="<?= $baseUrl ?>/devoluciones" class="filter-bar">
        <?php
        // Proveedor, número y tipo de documento. El desplegable "Estado" salió:
        // las insignias de arriba filtran lo mismo con un clic y ya dicen
        // cuántas hay en cada estado. El estado elegido viaja en la URL, así
        // que las insignias y este formulario no se pisan.
        $provFiltro = [
            'valor'    => $filtros['proveedor'] ?? '',
            'opciones' => $proveedoresFiltro ?? [],
        ]; include __DIR__ . '/../partials/filtro-proveedor.php';
        ?>
        <div class="filter-span-2">
            <label class="filter-label">Buscar</label>
            <input type="search" class="form-control" name="q" value="<?= htmlspecialchars($filtros['q']) ?>" placeholder="Número, factura o proveedor">
        </div>
        <?php /*
         * Una devolución no tiene saldo —no se paga, se espera una nota de
         * crédito por ella— así que aquí solo hay caja de monto.
         */
        $filtroImporte = [
            'nombre' => 'monto', 'etiqueta' => 'Monto',
            'valor' => $filtros['monto'] ?? '',
        ]; include __DIR__ . '/../partials/filtro-importe.php'; ?>
        <div>
            <label class="filter-label">Tipo de documento</label>
            <select class="form-control" name="tipo">
                <option value="">Todos</option>
                <option value="boleta_local" <?= $filtros['tipo'] === 'boleta_local' ? 'selected' : '' ?>>Boleta (Ventas)</option>
                <option value="devolucion_proveedor" <?= $filtros['tipo'] === 'devolucion_proveedor' ? 'selected' : '' ?>>Devolución (Cambios)</option>
            </select>
        </div>
        <input type="hidden" name="estado" value="<?= htmlspecialchars($filtros['estado']) ?>">
        <div class="filter-actions">
            <button class="btn btn-primary btn-sm" type="submit"><i class="fas fa-search"></i> Buscar</button>
            <a class="btn btn-outline btn-sm" href="<?= $baseUrl ?>/devoluciones?limpiar=1"><i class="fas fa-broom"></i> Limpiar</a>
            <?php // Los mismos filtros que están puestos; las columnas se las
                  // pega app.js, que sabe cuáles se están viendo. ?>
            <a class="btn btn-outline btn-sm" data-exportar="devoluciones"
               href="<?= $baseUrl ?>/devoluciones/exportar?<?= htmlspecialchars(http_build_query(array_filter([
                   'tipo' => $filtros['tipo'], 'estado' => $filtros['estado'],
                   'proveedor' => $filtros['proveedor'], 'q' => $filtros['q'],
                   'monto' => $filtros['monto'],
               ], static function ($v) { return (string) $v !== ''; }))) ?>"
               title="Bajar a Excel lo que estás viendo: estos filtros y estas columnas">
                <i class="fas fa-file-excel"></i> Exportar
            </a>
        </div>
    </form>

    <?php $columnasListado = 'devoluciones'; include __DIR__ . '/../partials/columnas.php'; ?>
    <div style="overflow-x:auto;margin-top:8px;"><table class="data-table" data-listado="devoluciones">
        <thead><tr>
            <th data-col="fecha">Fecha</th><th data-col="tipo">Tipo</th><th data-col="numero">N°</th>
            <th data-col="proveedor">Proveedor</th><th data-col="factura">Factura (boleta)</th>
            <th class="right" data-col="monto">Monto</th>
            <th class="right" data-col="nc_cantidad" title="Nota de crédito esperada por cantidad">NC esperada (cant.)</th>
            <th class="right" data-col="nc_costo" title="Nota de crédito esperada por costo">NC esperada (costo)</th>
            <th class="center" data-col="confirmados" title="Vínculos confirmados">Confirmados</th>
            <th class="center" data-col="sugeridos" title="Vínculos sugeridos, por confirmar">Sugeridos</th>
            <th data-col="estado">Estado</th>
            <?php // Lo que el reporte del ERP trae y no cabía: dónde se hizo,
                  // quién la hizo, qué se anotó y cuánto se devolvió. ?>
            <?= Columnas::cabecerasExtra('devoluciones') ?>
            <th data-columnas-boton></th>
        </tr></thead>
        <tbody>
        <?php if (empty($devoluciones)): ?>
        <tr><td colspan="20" style="text-align:center;padding:18px;color:var(--text-muted);">No hay reportes importados con estos filtros.</td></tr>
        <?php endif; ?>
        <?php foreach ($devoluciones as $d): ?>
        <?php
            /*
             * Cada cifra en su columna: el total de la devolución estuvo
             * amontonado con las dos de "NC esperadas", y esas dos, una
             * encima de la otra en la misma celda. Así no se comparaban
             * entre renglones, que es para lo que sirve un monto.
             *
             * Aquí no hay columna de saldo: una devolución no se paga, se
             * espera una nota de crédito por ella. Lo que haría de saldo es
             * justamente "NC esperadas".
             */
            $montoDevolucion = (float) $d['total'];
            $ncCantidad = (float) $d['nc_esperada_cantidad'];
            $ncCosto = (float) $d['nc_esperada_costo'];
        ?>
        <tr>
            <td style="white-space:nowrap;" data-col="fecha"><?= htmlspecialchars((string) $d['fecha']) ?></td>
            <td data-col="tipo"><?= $labelTipo((string) $d['tipo']) ?></td>
            <td data-col="numero"><strong><?= htmlspecialchars((string) $d['numero']) ?></strong></td>
            <td data-col="proveedor"><?= htmlspecialchars((string) ($d['proveedor_local'] ?: $d['proveedor_nombre_erp'] ?: '—')) ?></td>
            <td style="font-family:monospace;white-space:nowrap;" data-col="factura"><?= htmlspecialchars((string) ($d['numero_factura'] ?: '—')) ?></td>
            <td class="right" data-col="monto" style="white-space:nowrap;<?= $montoDevolucion > 0 ? '' : 'color:var(--text-muted);' ?>">
                <?= $montoDevolucion > 0 ? number_format($montoDevolucion, 2) : '—' ?>
            </td>
            <td class="right" style="white-space:nowrap;" data-col="nc_cantidad"><?= $ncCantidad > 0 ? number_format($ncCantidad, 2) : '—' ?></td>
            <td class="right" style="white-space:nowrap;" data-col="nc_costo"><?= $ncCosto > 0 ? number_format($ncCosto, 2) : '—' ?></td>
            <td class="center" data-col="confirmados">
                <?php if ((int) $d['matches_confirmados'] > 0): ?><span class="badge badge-green"><?= (int) $d['matches_confirmados'] ?> ✓</span><?php else: ?>—<?php endif; ?>
            </td>
            <td class="center" data-col="sugeridos">
                <?php if ((int) $d['matches_sugeridos'] > 0): ?><span class="badge" style="background:#fef3c7;color:#92400e;"><?= (int) $d['matches_sugeridos'] ?> ?</span><?php else: ?>—<?php endif; ?>
            </td>
            <td data-col="estado"><?= $badgeEstado((string) $d['estado']) ?></td>
            <?= Columnas::celdasExtra('devoluciones', $d) ?>
            <td style="white-space:nowrap;">
                <?php /*
                 * El detalle de una devolución no es una ficha que se lee: es
                 * la pantalla donde se vincula, se confirma y se descarta. Se
                 * abre entera en un marco sobre el listado —así funciona igual
                 * que siempre— y al cerrarla el listado se relee, porque lo
                 * que se hizo dentro cambió los renglones de aquí.
                 */ ?>
                <a class="btn btn-outline btn-sm" href="<?= $baseUrl ?>/devoluciones/detalle/<?= (int) $d['id'] ?>"
                   data-ventana="Devolución"
                   data-ventana-titulo="<?= htmlspecialchars((string) $d['numero']) ?>"
                   title="Detalle"><i class="fas fa-eye"></i></a>
                <a class="btn btn-outline btn-sm" href="<?= $baseUrl ?>/devoluciones/pdf/<?= (int) $d['id'] ?>" target="_blank"
                   data-ventana="PDF del reporte"
                   data-ventana-titulo="<?= htmlspecialchars((string) $d['numero']) ?>"
                   title="Ver PDF original"><i class="fas fa-file-pdf"></i></a>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
