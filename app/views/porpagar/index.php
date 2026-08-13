<?php
$baseUrl        = defined('APP_URL') ? APP_URL : '/xmlconcilia/public';
$listados       = $listados ?? [];
$listado        = $listado ?? null;
$lineas         = $lineas ?? [];
$resumen        = $resumen ?? [];
$semanas        = is_array($semanas ?? null) ? $semanas : [];
$carpetasPago   = is_array($carpetasPago ?? null) ? $carpetasPago : [];
$semanaFiltro   = (int) ($semanaFiltro ?? 0);
$sinCoincidencia = (int) ($sinCoincidencia ?? 0);
$pagoCerrado = $listado !== null && ($listado['estado'] ?? 'abierto') === 'cerrado';
$filtros = array_replace([
    'q' => '', 'proveedor' => '', 'estado' => '', 'vinculo' => '',
    'fecha_desde' => '', 'fecha_hasta' => '', 'monto_desde' => '', 'monto_hasta' => '',
], is_array($filtros ?? null) ? $filtros : []);
$filtrosQuery = array_filter($filtros, function ($valor) { return $valor !== '' && $valor !== null; });
$filtrosActivos = count($filtrosQuery);

$respaldadas   = (int) ($resumen['respaldada'] ?? 0);
$conDiferencia = (int) ($resumen['con_diferencia'] ?? 0);
$sinRespaldo   = (int) ($resumen['sin_respaldo'] ?? 0);
$totalLineas   = $respaldadas + $conDiferencia + $sinRespaldo;
$montoTotal    = (float) ($resumen['respaldada_monto'] ?? 0)
               + (float) ($resumen['con_diferencia_monto'] ?? 0)
               + (float) ($resumen['sin_respaldo_monto'] ?? 0);
$contextoListado = [
    'semana_id' => $semanaFiltro,
    'listado_id' => (int) ($listado['id'] ?? 0),
];
$urlLimpiarFiltros = $baseUrl . '/por-pagar?' . http_build_query($contextoListado);
$urlExportar = $baseUrl . '/por-pagar/exportar?' . http_build_query(array_merge(
    ['listado_id' => (int) ($listado['id'] ?? 0)],
    $filtrosQuery
));
$queryRetornoFiltros = http_build_query(array_merge($contextoListado, $filtrosQuery));
$carpetaPagoActiva = '';
foreach ($semanas as $semanaItem) {
    if ((int) $semanaItem['id'] === $semanaFiltro) {
        $carpetaPagoActiva = trim((string) ($semanaItem['carpeta_pago'] ?? ''));
        if ($carpetaPagoActiva === '') $carpetaPagoActiva = (string) $semanaItem['nombre'];
        break;
    }
}

function ppFecha($f) {
    $ts = $f ? strtotime((string) $f) : false;
    return $ts !== false ? date('d/m/Y', $ts) : '—';
}
?>

<!-- ── Subir listado ── -->
<div class="card mb-20">
    <div class="card-header mb-12">
        <div class="card-title">
            <i class="fas fa-file-arrow-up" style="margin-right:6px;color:var(--navy-light);"></i>
            Subir listado del pago semanal
        </div>
    </div>
    <style>
    #pp-form-subir { display: grid; grid-template-columns: minmax(240px, 1.4fr) minmax(180px, 1fr) minmax(220px, 1.2fr) auto; gap: 12px 16px; align-items: start; }
    #pp-form-subir .pp-campo-label { min-height: 17px; }
    #pp-form-subir .pp-acciones { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; padding-top: 21px; }
    #pp-form-subir .pp-campo-ayuda { font-size: 10.5px; color: var(--text-muted); margin-top: 4px; }
    @media (max-width: 900px) { #pp-form-subir { grid-template-columns: 1fr 1fr; } #pp-form-subir .pp-acciones { padding-top: 0; grid-column: 1 / -1; } }
    @media (max-width: 560px) { #pp-form-subir { grid-template-columns: 1fr; } }
    </style>
    <form method="POST" action="<?= $baseUrl ?>/por-pagar/subir" enctype="multipart/form-data" id="pp-form-subir">
        <div>
            <label class="filter-label pp-campo-label">Archivo (CSV o XLSX)</label>
            <input type="file" name="listado_file" id="pp-listado-file" accept=".csv,.xlsx"
                   style="display:none;" onchange="ppUpdateFileDisplay(this)">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <label for="pp-listado-file" class="upload-file-btn" style="padding:8px 16px;font-size:12.5px;">
                    <i class="fas fa-folder-open"></i> Seleccionar archivo
                </label>
                <button type="button" class="btn btn-outline btn-sm" onclick="ppAbrirFormato()"
                        title="Ver el formato de archivo esperado">
                    <i class="fas fa-circle-info" style="margin-right:4px;color:var(--navy-light);"></i>Formato
                </button>
            </div>
            <div id="pp-listado-file-name" class="pp-campo-ayuda" style="font-style:italic;">
                Ningún archivo seleccionado
            </div>
        </div>
        <div>
            <label class="filter-label pp-campo-label">
                <i class="fas fa-calendar-week" style="margin-right:3px;color:var(--gold);"></i>Semana de trabajo
            </label>
            <select name="semana_id" class="form-control" style="font-size:12.5px;"
                    onchange="ppSemanaCambio(this)">
                <option value="" <?= $semanaFiltro === 0 ? 'selected' : '' ?>>— Sin semana —</option>
                <?php foreach ($semanas as $sem): ?>
                <option value="<?= (int) $sem['id'] ?>" <?= $semanaFiltro === (int) $sem['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($sem['nombre']) ?>
                </option>
                <?php endforeach; ?>
                <option value="nueva">➕ Nueva semana…</option>
            </select>
            <input type="text" name="semana_nueva" id="pp-semana-nueva" maxlength="100"
                   placeholder="Semana <?= date('d/m/Y') ?>"
                   class="form-control" style="display:none;font-size:12px;margin-top:5px;">
        </div>
        <div>
            <label class="filter-label pp-campo-label">
                <i class="fas fa-folder-tree" style="margin-right:3px;color:var(--gold);"></i>Carpeta de pago semanal
            </label>
            <input type="text" name="carpeta_pago" id="pp-carpeta-pago" list="pp-carpetas-pago"
                   maxlength="100" class="form-control" style="font-size:12.5px;"
                   value="<?= htmlspecialchars($carpetaPagoActiva) ?>"
                   placeholder="Ej. Pago semana 31" <?= $semanaFiltro <= 0 ? 'disabled' : 'required' ?>>
            <datalist id="pp-carpetas-pago">
                <?php foreach ($carpetasPago as $carpeta): ?>
                <option value="<?= htmlspecialchars($carpeta) ?>"></option>
                <?php endforeach; ?>
            </datalist>
            <div class="pp-campo-ayuda">Dentro de PAGOS SEMANALES. Puedes elegir una existente o escribir una nueva.</div>
        </div>
        <div class="pp-acciones">
            <button type="submit" class="btn btn-primary btn-sm" id="pp-btn-subir">
                <i class="fas fa-eye"></i> Vista previa
            </button>
            <button type="button" class="btn btn-outline btn-sm" id="pp-btn-comparar"
                    title="Comparar este archivo con el listado actual sin modificarlo">
                <i class="fas fa-code-compare"></i> Comparar listado
            </button>
        </div>
    </form>
</div>

<script>
// El selector "Semana de trabajo" controla lo que se ve abajo: al cambiar
// se recarga con el listado de esa opción ("Sin semana" = los no asignados).
// "Nueva semana" solo abre el campo del nombre, sin recargar.
function ppSemanaCambio(sel) {
    var box = document.getElementById('pp-semana-nueva');
    var carpeta = document.getElementById('pp-carpeta-pago');
    if (sel.value === 'nueva') {
        box.style.display = 'block';
        carpeta.disabled = false;
        carpeta.required = true;
        carpeta.value = '';
        box.focus();
        return;
    }
    box.style.display = 'none';
    carpeta.disabled = sel.value === '';
    carpeta.required = sel.value !== '';
    // "Sin semana" navega con semana_id=0 explícito para que se recuerde
    // como selección (y no se confunda con una llegada sin parámetro).
    var base = '<?= $baseUrl ?>/por-pagar';
    window.location.href = base + '?semana_id=' + (sel.value === '' ? '0' : sel.value);
}

document.getElementById('pp-semana-nueva').addEventListener('input', function () {
    var carpeta = document.getElementById('pp-carpeta-pago');
    if (!carpeta.dataset.editada) carpeta.value = this.value;
});
document.getElementById('pp-carpeta-pago').addEventListener('input', function () {
    this.dataset.editada = '1';
});

function ppUpdateFileDisplay(input) {
    var label = document.getElementById('pp-listado-file-name');
    label.textContent = input.files.length > 0 ? input.files[0].name : 'Ningún archivo seleccionado';
}
</script>

<!-- ── Vista previa de la importación ── -->
<div id="ppv-overlay" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:1000;overflow:auto;">
    <div style="background:#fff;border-radius:12px;max-width:780px;width:92%;margin:5vh auto;max-height:88vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.25);">
        <div style="padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;flex-shrink:0;">
            <i class="fas fa-eye" style="color:var(--gold);font-size:16px;"></i>
            <div style="flex:1;min-width:0;">
                <div style="font-size:15px;font-weight:800;color:var(--navy);">Vista previa de la importación</div>
                <div id="ppv-destino" style="font-size:12px;color:var(--text-muted);"></div>
            </div>
            <button type="button" id="ppv-cerrar" style="background:none;border:none;font-size:20px;color:#94a3b8;cursor:pointer;line-height:1;">&times;</button>
        </div>
        <div id="ppv-resumen" style="padding:10px 18px;display:flex;gap:16px;flex-wrap:wrap;font-size:13px;border-bottom:1px solid var(--border);flex-shrink:0;"></div>
        <!-- Facturas que no están en ningún listado del ERP: bloquean la carga. -->
        <div id="ppv-sin-erp" style="display:none;margin:10px 18px 0;padding:10px 12px;background:#fef2f2;
             border:1px solid #fecaca;border-radius:7px;color:#991b1b;font-size:12.5px;line-height:1.6;
             flex-shrink:0;"></div>
        <div style="overflow:auto;flex:1;">
            <table class="data-table" style="font-size:12.5px;">
                <thead>
                    <tr><th></th><th>Número</th><th>Proveedor</th><th>Fecha</th><th class="right">Total</th></tr>
                </thead>
                <tbody id="ppv-tbody"></tbody>
            </table>
        </div>
        <div style="padding:12px 18px;border-top:1px solid var(--border);display:flex;gap:8px;justify-content:flex-end;flex-shrink:0;">
            <button type="button" class="btn btn-outline btn-sm" id="ppv-cancelar">Cancelar</button>
            <button type="button" class="btn btn-primary btn-sm" id="ppv-importar"></button>
        </div>
    </div>
</div>

<!-- Comparacion independiente contra el listado actual de la semana -->
<div id="ppc-overlay" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:1010;overflow:auto;">
    <div style="background:#fff;border-radius:12px;max-width:1120px;width:95%;margin:4vh auto;max-height:91vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.25);">
        <div style="padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;flex-shrink:0;">
            <i class="fas fa-code-compare" style="color:var(--gold);font-size:17px;"></i>
            <div style="flex:1;min-width:0;">
                <div style="font-size:15px;font-weight:800;color:var(--navy);">Comparación con el listado actual</div>
                <div id="ppc-destino" style="font-size:12px;color:var(--text-muted);"></div>
            </div>
            <button type="button" id="ppc-cerrar" style="background:none;border:none;font-size:20px;color:#94a3b8;cursor:pointer;line-height:1;">&times;</button>
        </div>
        <div style="padding:9px 18px;background:#f8fafc;border-bottom:1px solid var(--border);font-size:12px;color:#475569;flex-shrink:0;">
            <i class="fas fa-shield-halved" style="color:#16a34a;margin-right:5px;"></i>
            Esta comparación es de solo lectura: no agrega, actualiza ni elimina facturas.
        </div>
        <div id="ppc-resumen" style="padding:10px 18px;display:flex;gap:14px;flex-wrap:wrap;font-size:13px;border-bottom:1px solid var(--border);flex-shrink:0;"></div>
        <div style="overflow:auto;flex:1;">
            <table class="data-table" style="font-size:12px;min-width:980px;">
                <thead>
                    <tr>
                        <th>Resultado</th><th>Número</th><th>Proveedor</th><th>Fecha</th>
                        <th class="right">Total</th><th style="min-width:310px;">Diferencias</th>
                    </tr>
                </thead>
                <tbody id="ppc-tbody"></tbody>
            </table>
        </div>
        <div id="ppc-limite" style="display:none;padding:7px 18px;border-top:1px solid var(--border);font-size:11px;color:var(--text-muted);"></div>
        <div style="padding:12px 18px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;flex-shrink:0;">
            <button type="button" class="btn btn-outline btn-sm" id="ppc-aceptar">Cerrar comparación</button>
        </div>
    </div>
</div>

<!-- ── Formato de archivo esperado ── -->
<div id="ppf-overlay" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:1000;overflow:auto;">
    <div style="background:#fff;border-radius:12px;max-width:640px;width:92%;margin:6vh auto;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.25);">
        <div style="padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;">
            <i class="fas fa-table-list" style="color:var(--gold);font-size:16px;"></i>
            <div style="flex:1;min-width:0;">
                <div style="font-size:15px;font-weight:800;color:var(--navy);">Formato del archivo esperado</div>
                <div style="font-size:12px;color:var(--text-muted);">CSV o XLSX — se aceptan dos formatos; el sistema detecta solo cuál es</div>
            </div>
            <button type="button" onclick="ppCerrarFormato()" style="background:none;border:none;font-size:20px;color:#94a3b8;cursor:pointer;line-height:1;">&times;</button>
        </div>

        <div style="padding:16px 18px;">
            <!-- ── Formato 1: tabla simple con encabezados ── -->
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
                <span style="background:var(--navy);color:#fff;border-radius:5px;padding:2px 9px;font-size:11.5px;font-weight:800;flex-shrink:0;">Formato 1</span>
                <span style="font-size:12.5px;font-weight:700;color:var(--navy);">Tabla simple de 4 columnas (primera fila = encabezados)</span>
            </div>
            <!-- Vista tipo hoja de cálculo -->
            <div style="border:1px solid var(--border);border-radius:8px;overflow:auto;box-shadow:0 1px 3px rgba(0,0,0,.06);">
                <table style="border-collapse:collapse;width:100%;font-size:12.5px;white-space:nowrap;">
                    <thead>
                        <tr style="background:var(--navy);color:#fff;">
                            <th style="padding:8px 12px;text-align:left;font-weight:700;border-right:1px solid rgba(255,255,255,.15);">Fecha</th>
                            <th style="padding:8px 12px;text-align:left;font-weight:700;border-right:1px solid rgba(255,255,255,.15);">Numero</th>
                            <th style="padding:8px 12px;text-align:left;font-weight:700;border-right:1px solid rgba(255,255,255,.15);">Proveedor</th>
                            <th style="padding:8px 12px;text-align:right;font-weight:700;">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr style="border-bottom:1px solid var(--border);">
                            <td style="padding:7px 12px;border-right:1px solid var(--border);color:#475569;">15/07/2026</td>
                            <td style="padding:7px 12px;border-right:1px solid var(--border);font-weight:600;color:var(--navy);">00100001010000000123</td>
                            <td style="padding:7px 12px;border-right:1px solid var(--border);">DISTRIBUIDORA LA CENTRAL S.A.</td>
                            <td style="padding:7px 12px;text-align:right;font-weight:600;">125 000,00</td>
                        </tr>
                        <tr style="border-bottom:1px solid var(--border);background:#f8fafc;">
                            <td style="padding:7px 12px;border-right:1px solid var(--border);color:#475569;">15/07/2026</td>
                            <td style="padding:7px 12px;border-right:1px solid var(--border);font-weight:600;color:var(--navy);">00100001010000000456</td>
                            <td style="padding:7px 12px;border-right:1px solid var(--border);">FERRETERÍA EL TORNILLO</td>
                            <td style="padding:7px 12px;text-align:right;font-weight:600;">48 350,50</td>
                        </tr>
                        <tr>
                            <td style="padding:7px 12px;border-right:1px solid var(--border);color:#475569;">16/07/2026</td>
                            <td style="padding:7px 12px;border-right:1px solid var(--border);font-weight:600;color:var(--navy);">00100001010000000789</td>
                            <td style="padding:7px 12px;border-right:1px solid var(--border);">SERVICIOS LOGÍSTICOS BM</td>
                            <td style="padding:7px 12px;text-align:right;font-weight:600;">1 200 000,00</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Descripción de cada columna -->
            <div style="margin-top:16px;display:flex;flex-direction:column;gap:9px;">
                <div style="display:flex;gap:10px;align-items:flex-start;">
                    <code style="background:var(--navy);color:#fff;padding:2px 8px;border-radius:5px;font-size:11.5px;font-weight:700;flex-shrink:0;">Fecha</code>
                    <span style="font-size:12.5px;color:var(--text-muted);">Fecha de la factura. Solo informativa: <strong>no se compara</strong> al conciliar.</span>
                </div>
                <div style="display:flex;gap:10px;align-items:flex-start;">
                    <code style="background:var(--navy);color:#fff;padding:2px 8px;border-radius:5px;font-size:11.5px;font-weight:700;flex-shrink:0;">Numero</code>
                    <span style="font-size:12.5px;color:var(--text-muted);">Número o clave de la factura. Es lo que se busca contra las facturas del sistema.</span>
                </div>
                <div style="display:flex;gap:10px;align-items:flex-start;">
                    <code style="background:var(--navy);color:#fff;padding:2px 8px;border-radius:5px;font-size:11.5px;font-weight:700;flex-shrink:0;">Proveedor</code>
                    <span style="font-size:12.5px;color:var(--text-muted);">Nombre del proveedor. Sirve de referencia visual en el listado.</span>
                </div>
                <div style="display:flex;gap:10px;align-items:flex-start;">
                    <code style="background:var(--navy);color:#fff;padding:2px 8px;border-radius:5px;font-size:11.5px;font-weight:700;flex-shrink:0;">Total</code>
                    <span style="font-size:12.5px;color:var(--text-muted);">Monto <strong>en colones</strong>. Se compara contra el total de la factura respaldo.</span>
                </div>
            </div>

            <div style="margin-top:14px;background:var(--gold-pale, #fffbeb);border:1px solid var(--gold-light, #fde68a);border-radius:8px;padding:10px 12px;font-size:12px;color:#78350f;display:flex;gap:8px;align-items:flex-start;">
                <i class="fas fa-lightbulb" style="color:var(--gold-dark);margin-top:2px;"></i>
                <span>Los encabezados deben llamarse exactamente <code>Fecha</code>, <code>Numero</code>, <code>Proveedor</code> y <code>Total</code>. El orden de las columnas no importa.</span>
            </div>

            <!-- ── Formato 2: reporte agrupado del sistema de la empresa ── -->
            <div style="display:flex;align-items:center;gap:8px;margin:20px 0 10px;">
                <span style="background:var(--navy);color:#fff;border-radius:5px;padding:2px 9px;font-size:11.5px;font-weight:800;flex-shrink:0;">Formato 2</span>
                <span style="font-size:12.5px;font-weight:700;color:var(--navy);">Reporte agrupado por proveedor (sin fila de encabezados)</span>
            </div>
            <div style="border:1px solid var(--border);border-radius:8px;overflow:auto;box-shadow:0 1px 3px rgba(0,0,0,.06);">
                <table style="border-collapse:collapse;width:100%;font-size:12.5px;white-space:nowrap;">
                    <tbody>
                        <tr style="background:#eef2ff;border-bottom:1px solid var(--border);">
                            <td colspan="4" style="padding:7px 12px;font-weight:800;color:var(--navy);">Proveedor 140000014 AGENCIAS JOP S.A.</td>
                        </tr>
                        <tr style="border-bottom:1px solid var(--border);">
                            <td style="padding:7px 12px;font-weight:600;color:var(--navy);">FACT-12339</td>
                            <td style="padding:7px 12px;color:#475569;">15/07/2026</td>
                            <td style="padding:7px 12px;color:#94a3b8;">30/07/2026</td>
                            <td style="padding:7px 12px;text-align:right;font-weight:600;">₡ 125 000,00</td>
                        </tr>
                        <tr style="border-bottom:1px solid var(--border);background:#f8fafc;">
                            <td style="padding:7px 12px;font-weight:600;color:var(--navy);">FACT-12401</td>
                            <td style="padding:7px 12px;color:#475569;">16/07/2026</td>
                            <td style="padding:7px 12px;color:#94a3b8;">31/07/2026</td>
                            <td style="padding:7px 12px;text-align:right;font-weight:600;">₡ 48 350,50</td>
                        </tr>
                        <tr style="border-bottom:1px solid var(--border);">
                            <td colspan="3" style="padding:7px 12px;text-align:right;font-style:italic;color:#94a3b8;">Total — se ignora</td>
                            <td style="padding:7px 12px;text-align:right;color:#94a3b8;">₡ 173 350,50</td>
                        </tr>
                        <tr style="background:#eef2ff;border-bottom:1px solid var(--border);">
                            <td colspan="4" style="padding:7px 12px;font-weight:800;color:var(--navy);">Proveedor 250000023 DISTRIBUIDORA LA CENTRAL S.A.</td>
                        </tr>
                        <tr>
                            <td style="padding:7px 12px;font-weight:600;color:var(--navy);">FACT-98012</td>
                            <td style="padding:7px 12px;color:#475569;">16/07/2026</td>
                            <td style="padding:7px 12px;color:#94a3b8;">01/08/2026</td>
                            <td style="padding:7px 12px;text-align:right;font-weight:600;">₡ 1 200 000,00</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div style="margin-top:12px;display:flex;flex-direction:column;gap:9px;">
                <div style="display:flex;gap:10px;align-items:flex-start;">
                    <code style="background:var(--navy);color:#fff;padding:2px 8px;border-radius:5px;font-size:11.5px;font-weight:700;flex-shrink:0;">Proveedor…</code>
                    <span style="font-size:12.5px;color:var(--text-muted);">La línea <strong>"Proveedor &lt;código&gt; &lt;nombre&gt;"</strong> abre un grupo: las facturas de abajo toman ese proveedor.</span>
                </div>
                <div style="display:flex;gap:10px;align-items:flex-start;">
                    <code style="background:var(--navy);color:#fff;padding:2px 8px;border-radius:5px;font-size:11.5px;font-weight:700;flex-shrink:0;">FACT-…</code>
                    <span style="font-size:12.5px;color:var(--text-muted);">De cada fila de documento se toman el <strong>número</strong>, la <strong>primera fecha</strong> (emisión) y el <strong>monto</strong>. La fecha de vencimiento se ignora.</span>
                </div>
                <div style="display:flex;gap:10px;align-items:flex-start;">
                    <code style="background:#e2e8f0;color:#475569;padding:2px 8px;border-radius:5px;font-size:11.5px;font-weight:700;flex-shrink:0;">Subtotales</code>
                    <span style="font-size:12.5px;color:var(--text-muted);">Los totales, títulos del reporte y filas de rótulos se descartan automáticamente. No hay que limpiar el archivo antes de subirlo.</span>
                </div>
            </div>

            <div style="margin-top:14px;background:var(--gold-pale, #fffbeb);border:1px solid var(--gold-light, #fde68a);border-radius:8px;padding:10px 12px;font-size:12px;color:#78350f;display:flex;gap:8px;align-items:flex-start;">
                <i class="fas fa-lightbulb" style="color:var(--gold-dark);margin-top:2px;"></i>
                <span>Este es el reporte tal como lo exporta el sistema de la empresa: se sube <strong>sin modificarlo</strong> y XMLConcilia lo reconoce y lo aplana solo.</span>
            </div>
        </div>

        <div style="padding:12px 18px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;">
            <button type="button" class="btn btn-primary btn-sm" onclick="ppCerrarFormato()">Entendido</button>
        </div>
    </div>
</div>

<script>
function ppAbrirFormato() { document.getElementById('ppf-overlay').style.display = 'block'; }
function ppCerrarFormato() { document.getElementById('ppf-overlay').style.display = 'none'; }
document.getElementById('ppf-overlay').addEventListener('click', function (e) {
    if (e.target === this) ppCerrarFormato();
});
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') ppCerrarFormato();
});
</script>

<script>
// Comparador separado: usa el archivo seleccionado, pero nunca llama a la
// importacion ni conserva el archivo temporal en el servidor.
(function () {
    var form = document.getElementById('pp-form-subir');
    var btn = document.getElementById('pp-btn-comparar');
    var overlay = document.getElementById('ppc-overlay');
    if (!form || !btn || !overlay) return;

    function cerrar() { overlay.style.display = 'none'; }
    document.getElementById('ppc-cerrar').addEventListener('click', cerrar);
    document.getElementById('ppc-aceptar').addEventListener('click', cerrar);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) cerrar(); });

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = String(s == null ? '' : s);
        return d.innerHTML;
    }
    function attr(s) {
        return esc(s).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function monto(n) {
        return Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function fecha(f) {
        return f ? String(f).split('-').reverse().join('/') : '—';
    }
    function valorCambio(campo, valor) {
        if (campo === 'total') return '₡' + monto(valor);
        if (campo === 'fecha') return fecha(valor);
        return valor == null || valor === '' ? '—' : String(valor);
    }
    function detalle(l) {
        if (l.estado !== 'modificada') return esc(l.motivo || '');
        return Object.keys(l.cambios || {}).map(function (campo) {
            var cambio = l.cambios[campo];
            return '<div style="margin-bottom:3px;"><strong>' + esc(campo) + ':</strong> '
                + '<span style="color:#64748b;">' + esc(valorCambio(campo, cambio.anterior)) + '</span>'
                + ' <i class="fas fa-arrow-right" style="font-size:9px;color:#94a3b8;margin:0 3px;"></i> '
                + '<span style="color:#9a3412;font-weight:700;">' + esc(valorCambio(campo, cambio.nuevo)) + '</span></div>';
        }).join('');
    }

    var estilos = {
        nueva: ['Nueva', '#dcfce7', '#166534'],
        modificada: ['Modificada', '#ffedd5', '#9a3412'],
        igual: ['Sin cambios', '#e2e8f0', '#475569'],
        faltante: ['Ausente', '#dbeafe', '#1e40af'],
        duplicada: ['Duplicada', '#fef3c7', '#92400e'],
        error: ['Error', '#fee2e2', '#991b1b']
    };

    function pintar(r) {
        var x = r.resumen || {};
        document.getElementById('ppc-destino').textContent = r.semana + ' · ' + r.archivo
            + (r.listado_existente ? ' · Contra "' + r.listado_existente + '"' : ' · La semana todavía no tiene listado');
        document.getElementById('ppc-resumen').innerHTML =
            '<span style="color:#166534;font-weight:800;">' + (x.nueva || 0) + ' nuevas</span>'
            + '<span style="color:#9a3412;font-weight:800;">' + (x.modificada || 0) + ' modificadas</span>'
            + '<span style="color:#475569;font-weight:700;">' + (x.igual || 0) + ' sin cambios</span>'
            + '<span style="color:#1e40af;font-weight:700;">' + (x.faltante || 0) + ' ausentes del archivo nuevo</span>'
            + ((x.duplicada || 0) ? '<span style="color:#92400e;">' + x.duplicada + ' duplicadas</span>' : '')
            + ((x.error || 0) ? '<span style="color:#991b1b;">' + x.error + ' con error</span>' : '');

        document.getElementById('ppc-tbody').innerHTML = (r.lineas || []).map(function (l) {
            var estilo = estilos[l.estado] || estilos.error;
            var badge = '<span style="display:inline-block;background:' + estilo[1] + ';color:' + estilo[2]
                + ';border-radius:10px;padding:2px 8px;font-size:10.5px;font-weight:800;white-space:nowrap;">'
                + estilo[0] + '</span>';
            var opacidad = l.estado === 'igual' ? ' style="opacity:.68;"' : '';
            return '<tr' + opacidad + '>'
                + '<td>' + badge + '</td>'
                + '<td style="white-space:nowrap;font-weight:650;">' + esc(l.numero) + '</td>'
                + '<td style="max-width:245px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + attr(l.proveedor) + '">' + esc(l.proveedor) + '</td>'
                + '<td style="white-space:nowrap;">' + fecha(l.fecha) + '</td>'
                + '<td class="right" style="white-space:nowrap;">₡' + monto(l.total) + '</td>'
                + '<td style="line-height:1.35;">' + detalle(l) + '</td>'
                + '</tr>';
        }).join('');

        var limite = document.getElementById('ppc-limite');
        if (r.total_resultados > (r.lineas || []).length) {
            limite.style.display = 'block';
            limite.textContent = 'Se muestran ' + r.lineas.length + ' de ' + r.total_resultados + ' resultados.';
        } else {
            limite.style.display = 'none';
        }
        overlay.style.display = 'block';
    }

    btn.addEventListener('click', function () {
        var archivo = document.getElementById('pp-listado-file');
        var semana = form.querySelector('[name="semana_id"]');
        if (!archivo.files.length) {
            alert('Selecciona un archivo CSV o XLSX primero.');
            return;
        }
        if (!/^\d+$/.test(semana.value) || Number(semana.value) <= 0) {
            alert('Selecciona una semana existente para hacer la comparación.');
            return;
        }

        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Comparando…';
        fetch('<?= $baseUrl ?>/por-pagar/comparar-listado', {
            method: 'POST', body: new FormData(form), credentials: 'same-origin'
        })
            .then(function (r) { return r.json().catch(function () { throw new Error('Respuesta inválida del servidor.'); }); })
            .then(function (r) {
                if (!r.ok) throw new Error(r.message || 'No se pudo comparar el listado.');
                pintar(r);
            })
            .catch(function (err) { alert(err.message); })
            .then(function () {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-code-compare"></i> Comparar listado';
            });
    });
})();
</script>

<script>
// Vista previa: el formulario de subida ya no importa directo — primero
// analiza el archivo (sin escribir nada) y muestra qué es nuevo, qué ya
// estaba en el listado de la semana y qué viene ilegible. Importar de
// verdad reenvía el archivo guardado (token) a /por-pagar/subir.
(function () {
    var form = document.getElementById('pp-form-subir');
    var overlay = document.getElementById('ppv-overlay');
    if (!form || !overlay) return;

    var btnSubir = document.getElementById('pp-btn-subir');
    var btnImportar = document.getElementById('ppv-importar');
    var vistaActual = null;

    function cerrar() { overlay.style.display = 'none'; }
    document.getElementById('ppv-cerrar').addEventListener('click', cerrar);
    document.getElementById('ppv-cancelar').addEventListener('click', cerrar);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) cerrar(); });

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = String(s == null ? '' : s);
        return d.innerHTML;
    }

    function fmtMonto(n) {
        return Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var fileInput = document.getElementById('pp-listado-file');
        if (!fileInput.files.length) {
            alert('Seleccioná un archivo CSV o XLSX primero.');
            return;
        }
        var fd = new FormData(form);
        btnSubir.disabled = true;
        btnSubir.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Analizando…';

        fetch('<?= $baseUrl ?>/por-pagar/previsualizar', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { throw new Error('Respuesta inválida del servidor.'); }); })
            .then(function (r) {
                if (!r.ok) throw new Error(r.message || 'No se pudo analizar el archivo.');
                vistaActual = r;
                pintar(r);
                overlay.style.display = 'block';
            })
            .catch(function (err) { alert(err.message); })
            .then(function () {
                btnSubir.disabled = false;
                btnSubir.innerHTML = '<i class="fas fa-eye"></i> Vista previa';
            });
    });

    function pintar(r) {
        document.getElementById('ppv-destino').textContent = r.listado_existente
            ? 'Se añadirán SOLO las nuevas al listado existente "' + r.listado_existente + '" · ' + r.archivo + ' · Carpeta: ' + r.carpeta_pago
            : 'Se creará un listado nuevo · ' + r.archivo + ' · Carpeta: ' + r.carpeta_pago;

        document.getElementById('ppv-resumen').innerHTML =
            '<span style="color:#16a34a;font-weight:800;">' + r.nuevas + ' nueva' + (r.nuevas !== 1 ? 's' : '') + '</span>' +
            '<span style="color:var(--text-muted);">₡' + fmtMonto(r.monto_nuevas) + ' en nuevas</span>' +
            (r.repetidas ? '<span style="color:#b45309;font-weight:600;">' + r.repetidas + ' ya estaba' + (r.repetidas !== 1 ? 'n' : '') + ' (se omiten)</span>' : '') +
            (r.errores ? '<span style="color:#b91c1c;font-weight:600;">' + r.errores + ' fila' + (r.errores !== 1 ? 's' : '') + ' ilegible' + (r.errores !== 1 ? 's' : '') + '</span>' : '');

        document.getElementById('ppv-tbody').innerHTML = (r.lineas || []).map(function (l) {
            var badge;
            if (l.estado === 'nueva') {
                badge = '<span style="background:#dcfce7;color:#166534;border-radius:10px;padding:1px 8px;font-size:11px;font-weight:700;white-space:nowrap;">nueva</span>';
            } else if (l.estado === 'repetida') {
                badge = '<span style="background:#fef3c7;color:#92400e;border-radius:10px;padding:1px 8px;font-size:11px;font-weight:700;white-space:nowrap;">ya estaba</span>';
            } else {
                badge = '<span style="background:#fee2e2;color:#991b1b;border-radius:10px;padding:1px 8px;font-size:11px;font-weight:700;white-space:nowrap;" title="' + esc(l.motivo) + '">error</span>';
            }
            var fecha = l.fecha ? String(l.fecha).split('-').reverse().join('/') : '—';
            return '<tr' + (l.estado !== 'nueva' ? ' style="opacity:.55;"' : '') + '>'
                + '<td>' + badge + '</td>'
                + '<td style="white-space:nowrap;font-weight:600;">' + esc(l.numero) + '</td>'
                + '<td style="max-width:230px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + esc(l.proveedor) + '">' + esc(l.proveedor) + '</td>'
                + '<td style="white-space:nowrap;">' + fecha + '</td>'
                + '<td class="right" style="white-space:nowrap;">' + fmtMonto(l.total) + '</td>'
                + '</tr>';
        }).join('');

        // Facturas que no están en ningún listado del ERP: la importación las
        // rechaza entera, así que se dice acá y se apaga el botón. Enterarse
        // al confirmar obligaba a repetir todo el camino.
        var avisoErp = document.getElementById('ppv-sin-erp');
        if (r.sin_erp > 0) {
            avisoErp.style.display = 'block';
            avisoErp.innerHTML = '<strong>' + r.sin_erp + ' factura' + (r.sin_erp !== 1 ? 's' : '') +
                ' no está' + (r.sin_erp !== 1 ? 'n' : '') + ' en ningún listado de facturas del ERP:</strong> ' +
                (r.sin_erp_muestra || []).map(esc).join(', ') +
                (r.sin_erp > (r.sin_erp_muestra || []).length ? ', …' : '') +
                '<br>El pago semanal no se puede cargar hasta que el reporte “Facturas por Proveedor” que las incluya esté cargado en ' +
                '<a href="<?= $baseUrl ?>/carga">Carga de documentos</a>.';
        } else {
            avisoErp.style.display = 'none';
            avisoErp.innerHTML = '';
        }

        var bloqueado = r.nuevas === 0 || r.sin_erp > 0;
        btnImportar.disabled = bloqueado;
        btnImportar.innerHTML = r.sin_erp > 0
            ? 'Faltan facturas en el ERP'
            : (r.nuevas === 0
                ? 'Nada nuevo que importar'
                : '<i class="fas fa-check-double"></i> Importar ' + r.nuevas + ' nueva' + (r.nuevas !== 1 ? 's' : ''));
    }

    btnImportar.addEventListener('click', function () {
        if (!vistaActual) return;
        btnImportar.disabled = true;
        btnImportar.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Importando…';

        var f = document.createElement('form');
        f.method = 'POST';
        f.action = '<?= $baseUrl ?>/por-pagar/subir';
        var semanaNueva = form.querySelector('[name="semana_nueva"]');
        var campos = {
            archivo_token: vistaActual.token,
            archivo_nombre: vistaActual.archivo,
            semana_id: form.querySelector('[name="semana_id"]').value,
            semana_nueva: semanaNueva ? semanaNueva.value : '',
            carpeta_pago: form.querySelector('[name="carpeta_pago"]').value
        };
        Object.keys(campos).forEach(function (k) {
            var i = document.createElement('input');
            i.type = 'hidden';
            i.name = k;
            i.value = campos[k];
            f.appendChild(i);
        });
        document.body.appendChild(f);
        f.submit();
    });
})();
</script>

<?php if ($listado): ?>

<!-- ── Resumen del listado ── -->
<div class="stats-grid mb-20">
    <div class="stat-card navy">
        <div class="stat-label"><i class="fas fa-list-check" style="margin-right:5px;"></i>Pagos semanales</div>
        <div class="stat-value"><?= $totalLineas ?></div>
        <div class="stat-sub">₡<?= number_format($montoTotal, 2) ?></div>
    </div>
    <div class="stat-card white">
        <div class="stat-label"><i class="fas fa-circle-check" style="margin-right:5px;"></i>Respaldadas</div>
        <div class="stat-value" style="color:var(--ok);"><?= $respaldadas ?></div>
        <div class="stat-sub">XML encontrado y monto cuadra</div>
    </div>
    <div class="stat-card white">
        <div class="stat-label"><i class="fas fa-triangle-exclamation" style="margin-right:5px;"></i>Con diferencia</div>
        <div class="stat-value" style="color:#dc2626;"><?= $conDiferencia ?></div>
        <div class="stat-sub">El monto no cuadra con el XML</div>
    </div>
    <div class="stat-card white">
        <div class="stat-label"><i class="fas fa-file-circle-question" style="margin-right:5px;"></i>Sin respaldo</div>
        <div class="stat-value" style="color:var(--warn);"><?= $sinRespaldo ?></div>
        <div class="stat-sub">Falta el XML: búscalo en el correo</div>
    </div>
</div>

<!-- ── Checklist ── -->
<div class="card">
    <div class="card-header mb-12" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <div class="card-title" style="margin-right:auto;">
            <i class="fas fa-clipboard-check" style="margin-right:6px;color:var(--navy-light);"></i>
            <?= htmlspecialchars($listado['nombre']) ?>
            <?php if (!empty($listado['semana_nombre'])): ?>
            <span class="badge badge-navy" style="font-size:10px;padding:2px 8px;margin-left:4px;"
                  title="Verificado solo contra las facturas de esta semana">
                <i class="fas fa-calendar-week"></i> <?= htmlspecialchars($listado['semana_nombre']) ?>
            </span>
            <?php if (!empty($listado['semana_carpeta_pago'])): ?>
            <span class="badge" style="font-size:10px;padding:2px 8px;margin-left:4px;background:#e0f2fe;color:#075985;"
                  title="Los pares XML/PDF respaldados se reúnen en esta carpeta">
                <i class="fas fa-folder"></i> PAGOS SEMANALES / <?= htmlspecialchars($listado['semana_carpeta_pago']) ?>
            </span>
            <?php endif; ?>
            <?php endif; ?>
            <?php if ($pagoCerrado): ?>
            <span class="badge badge-ok" style="font-size:10px;padding:2px 8px;margin-left:4px;"
                  title="Las coincidencias y las asignaciones ERP de este pago ya no se modifican">
                <i class="fas fa-lock"></i> Pago cerrado
            </span>
            <?php endif; ?>
            <span style="font-weight:400;font-size:11.5px;color:var(--text-muted);">
                — subido el <?= ppFecha($listado['fecha_subida']) ?>
                <?= !empty($listado['sociedad_nombre']) ? ' · ' . htmlspecialchars($listado['sociedad_nombre']) : '' ?>
                <?= empty($listado['semana_nombre']) ? ' · verificado contra todas las facturas' : '' ?>
                <?= $pagoCerrado && !empty($listado['cerrado_en']) ? ' · cerrado el ' . date('d/m/Y H:i', strtotime((string) $listado['cerrado_en'])) : '' ?>
            </span>
        </div>

        <?php if (count($listados) > 1): ?>
        <form method="GET" action="<?= $baseUrl ?>/por-pagar">
            <input type="hidden" name="semana_id" value="<?= (int) $semanaFiltro ?>">
            <?php foreach ($filtrosQuery as $nombreFiltro => $valorFiltro): ?>
            <input type="hidden" name="<?= htmlspecialchars($nombreFiltro) ?>" value="<?= htmlspecialchars((string) $valorFiltro) ?>">
            <?php endforeach; ?>
            <select name="listado_id" class="form-control" style="font-size:12px;padding:5px 8px;" onchange="this.form.submit()">
                <?php foreach ($listados as $l): ?>
                <option value="<?= (int) $l['id'] ?>" <?= (int) $l['id'] === (int) $listado['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($l['nombre']) ?><?= !empty($l['semana_nombre']) ? ' · ' . htmlspecialchars($l['semana_nombre']) : '' ?><?= ($l['estado'] ?? 'abierto') === 'cerrado' ? ' · Cerrado' : '' ?> (<?= (int) $l['total_lineas'] ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php endif; ?>

        <?php /* El botón "Verificar de nuevo" se eliminó: la verificación
                 corre sola cada vez que una factura entra o sale de la
                 semana (asignación manual, subida de XML o importación
                 del correo). La ruta /por-pagar/verificar sigue viva. */ ?>
        <?php if (!$pagoCerrado && !empty($listado['semana_id']) && !empty($sinCoincidencia)): ?>
        <button type="button" class="btn btn-sm" onclick="ppsAbrir()"
                style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;"
                title="Facturas XML de esta semana que no coinciden con ninguna línea del listado">
            <i class="fas fa-triangle-exclamation"></i> Sin coincidencia (<?= (int) $sinCoincidencia ?>)
        </button>
        <?php endif; ?>
        <a href="<?= htmlspecialchars($urlExportar) ?>" class="btn btn-outline btn-sm"
           title="<?= $filtrosActivos ? 'Exportar a Excel solamente los resultados filtrados' : 'Exportar todo el listado a Excel' ?>">
            <i class="fas fa-file-excel"></i> Exportar Excel
        </a>
        <?php if (!$pagoCerrado): ?>
        <form method="POST" action="<?= $baseUrl ?>/por-pagar/cerrar/<?= (int) $listado['id'] ?>" style="display:inline;"
              onsubmit="return confirm('¿Cerrar este pago semanal? Las facturas emparejadas quedarán asignadas a esta semana en Facturas ERP y el listado ya no se podrá modificar.');">
            <button type="submit" class="btn btn-primary btn-sm"
                    <?= $sinRespaldo > 0 ? 'disabled' : '' ?>
                    title="<?= $sinRespaldo > 0 ? 'Primero empareja todas las facturas con su XML' : 'Cerrar y asignar las facturas en el módulo ERP' ?>">
                <i class="fas fa-lock"></i> Cerrar pago semanal
            </button>
        </form>
        <form method="POST" action="<?= $baseUrl ?>/por-pagar/eliminar/<?= (int) $listado['id'] ?>" style="display:inline;"
              onsubmit="return confirm('¿Eliminar este listado y sus resultados?');">
            <button type="submit" class="btn btn-outline btn-sm" title="Eliminar listado" style="color:#b91c1c;border-color:#fed7d7;">
                <i class="fas fa-trash-can"></i>
            </button>
        </form>
        <?php endif; ?>
    </div>

    <form method="GET" action="<?= $baseUrl ?>/por-pagar" class="filter-bar">
        <input type="hidden" name="semana_id" value="<?= (int) $semanaFiltro ?>">
        <input type="hidden" name="listado_id" value="<?= (int) $listado['id'] ?>">
        <div class="filter-span-2">
            <label class="filter-label">Número de factura</label>
            <input type="search" name="q" class="form-control"
                   value="<?= htmlspecialchars((string) $filtros['q']) ?>"
                   placeholder="Número del listado o del XML">
        </div>
        <div class="filter-span-2">
            <label class="filter-label">Proveedor</label>
            <input type="search" name="proveedor" class="form-control"
                   value="<?= htmlspecialchars((string) $filtros['proveedor']) ?>"
                   placeholder="Proveedor del listado o del XML">
        </div>
        <div>
            <label class="filter-label">Estado</label>
            <select name="estado" class="form-control" onchange="this.form.submit()">
                <option value="">Todos</option>
                <option value="respaldada" <?= $filtros['estado'] === 'respaldada' ? 'selected' : '' ?>>Respaldada</option>
                <option value="con_diferencia" <?= $filtros['estado'] === 'con_diferencia' ? 'selected' : '' ?>>Con diferencia</option>
                <option value="sin_respaldo" <?= $filtros['estado'] === 'sin_respaldo' ? 'selected' : '' ?>>Sin respaldo</option>
            </select>
        </div>
        <div>
            <label class="filter-label">Vínculo XML</label>
            <select name="vinculo" class="form-control" onchange="this.form.submit()">
                <option value="">Todos</option>
                <option value="automatico" <?= $filtros['vinculo'] === 'automatico' ? 'selected' : '' ?>>Automático</option>
                <option value="manual" <?= $filtros['vinculo'] === 'manual' ? 'selected' : '' ?>>Manual</option>
                <option value="sin_vinculo" <?= $filtros['vinculo'] === 'sin_vinculo' ? 'selected' : '' ?>>Sin vínculo</option>
            </select>
        </div>
        <div>
            <label class="filter-label">Fecha desde</label>
            <input type="date" name="fecha_desde" class="form-control" value="<?= htmlspecialchars((string) $filtros['fecha_desde']) ?>">
        </div>
        <div>
            <label class="filter-label">Fecha hasta</label>
            <input type="date" name="fecha_hasta" class="form-control" value="<?= htmlspecialchars((string) $filtros['fecha_hasta']) ?>">
        </div>
        <div>
            <label class="filter-label">Monto desde</label>
            <input type="number" min="0" step="0.01" name="monto_desde" class="form-control"
                   value="<?= htmlspecialchars((string) $filtros['monto_desde']) ?>" placeholder="0.00">
        </div>
        <div>
            <label class="filter-label">Monto hasta</label>
            <input type="number" min="0" step="0.01" name="monto_hasta" class="form-control"
                   value="<?= htmlspecialchars((string) $filtros['monto_hasta']) ?>" placeholder="Sin límite">
        </div>
        <div class="filter-actions">
            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Buscar</button>
            <?php if ($filtrosActivos): ?>
            <a href="<?= htmlspecialchars($urlLimpiarFiltros) ?>" class="btn btn-outline btn-sm"><i class="fas fa-broom"></i> Limpiar</a>
            <?php endif; ?>
        </div>
    </form>

    <div class="filter-results">
        <i class="fas fa-filter" style="color:var(--navy-light);"></i>
        <?php if ($filtrosActivos): ?>
        Mostrando <strong><?= count($lineas) ?></strong> de <strong><?= $totalLineas ?></strong> facturas
        <span class="badge badge-navy" style="font-size:10px;"><?= $filtrosActivos ?> filtro<?= $filtrosActivos === 1 ? '' : 's' ?></span>
        <?php else: ?>
        Mostrando las <strong><?= $totalLineas ?></strong> facturas del listado
        <?php endif; ?>
    </div>

    <div class="table-wrap">
        <table class="data-table" style="font-size:12.5px;">
            <thead>
                <tr>
                    <th style="width:120px;">Estado</th>
                    <th>Fecha</th>
                    <th>Número</th>
                    <th>Proveedor</th>
                    <th class="right">Total listado</th>
                    <th>Factura XML</th>
                    <th class="right">Diferencia</th>
                    <th class="center" style="width:155px;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($lineas): ?>
                <?php foreach ($lineas as $linea): ?>
                <tr>
                    <td>
                        <?php if ($linea['estado'] === 'respaldada'): ?>
                        <span class="badge badge-ok"><i class="fas fa-check-circle"></i> Respaldada</span>
                        <?php elseif ($linea['estado'] === 'con_diferencia'): ?>
                        <span class="badge" style="background:#fee2e2;color:#b91c1c;"><i class="fas fa-triangle-exclamation"></i> Diferencia</span>
                        <?php else: ?>
                        <span class="badge" style="background:#f1f5f9;color:#475569;"><i class="fas fa-file-circle-question"></i> Sin respaldo</span>
                        <?php endif; ?>
                    </td>
                    <td style="white-space:nowrap;"><?= ppFecha($linea['fecha']) ?></td>
                    <td style="max-width:210px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:600;color:var(--navy);"
                        title="<?= htmlspecialchars($linea['numero']) ?>">
                        <?= htmlspecialchars($linea['numero']) ?>
                    </td>
                    <td style="max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                        title="<?= htmlspecialchars($linea['proveedor_texto']) ?>">
                        <?= htmlspecialchars($linea['proveedor_texto']) ?>
                    </td>
                    <td class="right" style="white-space:nowrap;"><?= number_format((float) $linea['total'], 2) ?></td>
                    <td style="white-space:nowrap;">
                        <?php if (!empty($linea['factura_xml_id'])): ?>
                        <?php if (!empty($linea['match_manual'])): ?>
                        <i class="fas fa-hand" style="color:var(--gold-dark);font-size:11px;margin-right:3px;"
                           title="Vinculada manualmente: solo se comparó el monto"></i>
                        <?php endif; ?>
                        <span style="font-weight:600;color:var(--navy);"><?= htmlspecialchars(NumeroFactura::xmlOchoDigitos($linea['xml_numero'])) ?></span>
                        <span style="color:var(--text-muted);font-size:11.5px;">· <?= number_format((float) $linea['xml_total'], 2) ?></span>
                        <?php else: ?>
                        <span style="color:#cbd5e1;">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="right" style="white-space:nowrap;">
                        <?php if ($linea['estado'] === 'con_diferencia' && $linea['diferencia'] !== null): ?>
                        <?php $dif = (float) $linea['diferencia']; ?>
                        <span style="color:<?= $dif > 0 ? '#16a34a' : '#b91c1c' ?>;font-weight:700;">
                            <?= ($dif > 0 ? '+' : '') . number_format($dif, 2) ?>
                        </span>
                        <?php else: ?>
                        <span style="color:#cbd5e1;">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="center" style="white-space:nowrap;">
                        <?php if ($linea['estado'] === 'sin_respaldo'): ?>
                        <a href="<?= $baseUrl ?>/correo?buscar=<?= urlencode((string) $linea['numero_busqueda']) ?>&pp_listado=<?= (int) $listado['id'] ?>&pp_linea=<?= (int) $linea['id'] ?>"
                           class="btn btn-outline btn-sm" title="Buscar esta factura en el correo">
                            <i class="fas fa-envelope-open-text"></i> Buscar en correo
                        </a>
                        <?php elseif (!empty($linea['factura_xml_id'])): ?>
                        <a href="<?= $baseUrl ?>/facturas/ver/<?= (int) $linea['factura_xml_id'] ?>"
                           class="btn btn-outline btn-sm" title="Ver la factura XML">
                            <i class="fas fa-eye"></i>
                        </a>
                        <?php endif; ?>
                        <?php if (!$pagoCerrado): ?>
                        <form method="POST" action="<?= $baseUrl ?>/por-pagar/factura/eliminar/<?= (int) $linea['id'] ?>?<?= htmlspecialchars($queryRetornoFiltros) ?>"
                              style="display:inline;margin-left:4px;"
                              onsubmit="return confirm('¿Eliminar esta factura del listado? La factura XML asociada no se eliminará.');">
                            <button type="submit" class="btn btn-outline btn-sm"
                                    title="Eliminar factura del listado" aria-label="Eliminar factura del listado"
                                    style="color:#b91c1c;border-color:#fed7d7;">
                                <i class="fas fa-trash-can"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php else: ?>
                <tr>
                    <td colspan="8" style="padding:28px;text-align:center;color:var(--text-muted);">
                        <i class="fas fa-search" style="font-size:20px;color:#cbd5e1;display:block;margin-bottom:7px;"></i>
                        No se encontraron facturas con los filtros seleccionados.
                        <a href="<?= htmlspecialchars($urlLimpiarFiltros) ?>" style="margin-left:4px;">Limpiar filtros</a>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if (!empty($listado['semana_id']) && !$pagoCerrado): ?>
<!-- ── Facturas sin coincidencia (de la semana, fuera del listado) ── -->
<div id="pps-overlay" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:1000;overflow:auto;">
    <div style="background:#fff;border-radius:12px;max-width:860px;width:94%;margin:5vh auto;max-height:88vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.25);">
        <div style="padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;flex-shrink:0;">
            <i class="fas fa-triangle-exclamation" style="color:var(--gold-dark);font-size:16px;"></i>
            <div style="flex:1;min-width:0;">
                <div style="font-size:15px;font-weight:800;color:var(--navy);">Facturas sin coincidencia</div>
                <div style="font-size:12px;color:var(--text-muted);">
                    Facturas XML de <strong><?= htmlspecialchars((string) ($listado['semana_nombre'] ?? 'la semana')) ?></strong>
                    que no coinciden con ninguna línea del listado. Muévelas de semana o vincúlalas a mano
                    (al vincular solo se compara el monto).
                </div>
            </div>
            <button type="button" onclick="ppsCerrar()" style="background:none;border:none;font-size:20px;color:#94a3b8;cursor:pointer;line-height:1;">&times;</button>
        </div>
        <div id="pps-body" style="overflow:auto;flex:1;padding:6px 0;">
            <div style="padding:30px;text-align:center;color:var(--text-muted);font-size:13px;">
                <i class="fas fa-spinner fa-spin"></i> Cargando…
            </div>
        </div>
        <div style="padding:12px 18px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;flex-shrink:0;">
            <button type="button" class="btn btn-outline btn-sm" onclick="ppsCerrar()">Cerrar</button>
        </div>
    </div>
</div>

<script>
// Modal "Sin coincidencia": lista las facturas XML de la semana que ningún
// match del listado reclamó. Cada una se puede mover a otra semana (repite
// el chequeo allá) o vincular a la fuerza con una línea sin respaldo — en
// ese caso el estado sale SOLO del monto y el vínculo queda protegido de
// la re-verificación automática (match_manual).
var PPS_BASE = '<?= $baseUrl ?>';
var PPS_LISTADO = <?= (int) $listado['id'] ?>;
var PPS_SEMANA = <?= (int) $listado['semana_id'] ?>;

function ppsEsc(s) {
    var d = document.createElement('div');
    d.textContent = String(s == null ? '' : s);
    return d.innerHTML;
}
function ppsMonto(n) {
    return Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function ppsAbrir() {
    document.getElementById('pps-overlay').style.display = 'block';
    fetch(PPS_BASE + '/por-pagar/sin-coincidencia?listado_id=' + PPS_LISTADO, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (r) {
            if (!r.ok) throw new Error(r.message || 'No se pudo cargar.');
            ppsPintar(r);
        })
        .catch(function (err) {
            document.getElementById('pps-body').innerHTML =
                '<div style="padding:30px;text-align:center;color:#b91c1c;font-size:13px;">' + ppsEsc(err.message) + '</div>';
        });
}
function ppsCerrar() { document.getElementById('pps-overlay').style.display = 'none'; }
document.getElementById('pps-overlay').addEventListener('click', function (e) {
    if (e.target === this) ppsCerrar();
});
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') ppsCerrar();
});

function ppsPintar(r) {
    var body = document.getElementById('pps-body');

    if (!r.facturas.length) {
        body.innerHTML = '<div style="padding:30px;text-align:center;color:var(--text-muted);font-size:13px;">'
            + '<i class="fas fa-circle-check" style="color:var(--ok);margin-right:6px;"></i>'
            + 'Todas las facturas de la semana coinciden con el listado.</div>';
        return;
    }

    var opcionesSemana = '<option value="">— Sin semana —</option>' + r.semanas
        .filter(function (s) { return s.id !== r.semana_id; })
        .map(function (s) { return '<option value="' + s.id + '">' + ppsEsc(s.nombre) + '</option>'; })
        .join('');

    body.innerHTML = r.facturas.map(function (f) {
        var fecha = f.fecha ? String(f.fecha).split(' ')[0].split('-').reverse().join('/') : '—';

        // Líneas sin respaldo ordenadas por cercanía de monto: la más
        // probable primero, con la diferencia visible en cada opción.
        var opcionesLinea = r.lineas.slice()
            .sort(function (a, b) { return Math.abs(a.total - f.total) - Math.abs(b.total - f.total); })
            .map(function (l) {
                var dif = Math.round((l.total - f.total) * 100) / 100;
                var difTxt = dif === 0 ? 'monto exacto' : 'Δ ' + (dif > 0 ? '+' : '') + ppsMonto(dif);
                var prov = l.proveedor.length > 26 ? l.proveedor.slice(0, 26) + '…' : l.proveedor;
                return '<option value="' + l.id + '">' + ppsEsc(l.numero + ' · ' + prov + ' · ₡' + ppsMonto(l.total) + ' (' + difTxt + ')') + '</option>';
            }).join('');

        return '<div style="padding:12px 18px;border-bottom:1px solid var(--border);">'
            + '<div style="display:flex;gap:14px;flex-wrap:wrap;align-items:baseline;margin-bottom:8px;">'
            +   '<span style="font-weight:700;color:var(--navy);">' + ppsEsc(f.numero) + '</span>'
            +   '<span style="font-size:12.5px;">' + ppsEsc(f.proveedor) + '</span>'
            +   '<span style="font-size:12.5px;font-weight:700;">₡' + ppsMonto(f.total) + '</span>'
            +   '<span style="font-size:12px;color:var(--text-muted);">' + fecha + '</span>'
            + '</div>'
            + '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;font-size:12px;">'
            +   '<select id="pps-sem-' + f.id + '" class="form-control" style="font-size:12px;padding:4px 8px;width:auto;max-width:200px;">' + opcionesSemana + '</select>'
            +   '<button type="button" class="btn btn-outline btn-sm" onclick="ppsMover(' + f.id + ')"><i class="fas fa-calendar-week"></i> Mover de semana</button>'
            +   '<span style="color:#cbd5e1;">|</span>'
            +   (r.lineas.length
                    ? '<select id="pps-lin-' + f.id + '" class="form-control" style="font-size:12px;padding:4px 8px;width:auto;max-width:340px;">' + opcionesLinea + '</select>'
                      + '<button type="button" class="btn btn-outline btn-sm" onclick="ppsVincular(' + f.id + ')" style="color:#92400e;border-color:#fde68a;"><i class="fas fa-link"></i> Vincular (solo monto)</button>'
                    : '<span style="color:var(--text-muted);font-style:italic;">No quedan líneas sin respaldo en el listado.</span>')
            + '</div>'
            + '</div>';
    }).join('');
}

function ppsMover(facturaId) {
    var sel = document.getElementById('pps-sem-' + facturaId);
    var fd = new FormData();
    fd.append('factura_id', facturaId);
    fd.append('semana_id', sel.value);
    fd.append('semana_nueva', '');

    fetch(PPS_BASE + '/facturas/semana', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (r) {
            if (!r.ok) throw new Error(r.message || 'No se pudo mover.');
            window.location.reload();
        })
        .catch(function (err) { alert(err.message); });
}

function ppsVincular(facturaId) {
    var sel = document.getElementById('pps-lin-' + facturaId);
    if (!sel || !sel.value) return;
    if (!confirm('El número y el proveedor no coinciden: se vinculará a la fuerza y solo se comparará el monto. ¿Continuar?')) {
        return;
    }

    var fd = new FormData();
    fd.append('linea_id', sel.value);
    fd.append('factura_id', facturaId);

    fetch(PPS_BASE + '/por-pagar/forzar', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (r) {
            if (!r.ok) throw new Error(r.message || 'No se pudo vincular.');

            // Si los nombres no se parecían, el sistema acaba de aprender que
            // son el mismo proveedor. Conviene decirlo: explica por qué otras
            // líneas cambiaron solas, y avisa de lo que va a pasar en adelante.
            if (r.alias_aprendido) {
                var msg = 'Vinculada.\n\n'
                    + 'Los nombres no se parecían, así que quedó aprendido que son el mismo '
                    + 'proveedor. Desde ahora sus facturas se emparejan solas.';
                if (r.lineas_resueltas > 0) {
                    msg += '\n\nCon eso se resolvieron ' + r.lineas_resueltas
                        + ' línea(s) más que estaban sin respaldo.';
                }
                alert(msg);
            }
            window.location.reload();
        })
        .catch(function (err) { alert(err.message); });
}
</script>
<?php endif; ?>

<?php else: ?>

<div class="card" style="text-align:center;padding:40px 20px;color:var(--text-muted);">
    <i class="fas fa-file-invoice-dollar" style="font-size:34px;color:#cbd5e1;margin-bottom:10px;display:block;"></i>
    <div style="font-size:14px;font-weight:700;color:var(--navy);margin-bottom:4px;">
        No hay listados <?= $semanaFiltro === 0 ? 'sin semana' : 'para esta semana' ?>
    </div>
    <div style="font-size:12.5px;">
        Elige otra semana en el selector de arriba, o sube el listado del pago de esta semana.
    </div>
</div>

<?php endif; ?>
