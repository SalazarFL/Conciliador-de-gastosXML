<?php
/**
 * Panel principal.
 *
 * Tres bandas, y se leen de arriba abajo:
 *   1. Cómo va el trabajo — cuatro métricas del mismo peso, cada una puerta
 *      del módulo que la explica.
 *   2. Con qué empresa se trabaja — un renglón, no una tarjeta a media asta.
 *   3. Qué módulos puede abrir QUIEN ENTRÓ — los que le da su rol, todos, no
 *      cuatro atajos escritos a mano. La lista es config/menu.php, la misma
 *      que dibuja la barra lateral: ver app/helpers/Navegacion.php.
 *
 * Y antes que las tres, cuando hace falta: los pasos que le faltan a la
 * instalación. Es lo primero que se lee porque mientras falten, casi nada de
 * lo demás se puede abrir — ver app/core/Requisitos.php.
 */

require_once __DIR__ . '/../../helpers/Navegacion.php';
require_once __DIR__ . '/../../core/Requisitos.php';

$baseUrl        = defined('APP_URL') ? APP_URL : '/xmlconcilia/public';
$stats          = $stats ?? ['total_facturas' => 0, 'bandeja_pendientes' => 0];
$sociedades     = $sociedades ?? [];
$sociedadActiva = $sociedadActiva ?? null;

// La empresa marcada es la de ESTE usuario, no la columna `activa` de la
// tabla. `activa` es solo el valor por omisión del sistema: con dos personas
// en empresas distintas, leerla aquí marcaba la fila equivocada y dejaba sin
// botón —imposible de seleccionar— justamente a la que había que elegir.
$sociedadEnUsoId = (int) ($sociedadActiva['id'] ?? 0);
$ultimoListado  = $ultimoListado ?? null;
$resumenListado = $resumenListado ?? [];

$respaldadas   = (int) ($resumenListado['respaldada'] ?? 0);
$conDiferencia = (int) ($resumenListado['con_diferencia'] ?? 0);
$sinRespaldo   = (int) ($resumenListado['sin_respaldo'] ?? 0);
$totalLineas   = $respaldadas + $conDiferencia + $sinRespaldo;
$porResolver   = $sinRespaldo + $conDiferencia;

$pendientesCorreo = (int) $stats['bandeja_pendientes'];

/*
 * ── Las métricas ──────────────────────────────────────────────────────────
 * Todas con la misma forma: pastilla de color, nombre, número y una línea
 * que lo explica. El color entra por el estado del número —verde cuando no
 * falta nada, ámbar cuando sí, apagado cuando no hay de qué hablar—, no por
 * la tarjeta entera: cuatro fondos distintos compitiendo hacían que ninguno
 * se leyera primero.
 *
 * 'ruta' es a dónde lleva, y solo se pone si la persona puede entrar: una
 * métrica que lleva a "no tienes permiso" —o a "falta configurar"— es peor
 * que una que no lleva a ningún sitio.
 */
$abre = fn(string $permiso, string $modulo): bool =>
    Permisos::puede($permiso) && !Requisitos::moduloCerrado($modulo);

$metricas = [
    [
        'titulo' => 'Facturas XML',
        'icono'  => 'fa-file-invoice',
        'tono'   => 'navy',
        'valor'  => number_format((int) $stats['total_facturas']),
        'estado' => '',
        'sub'    => 'Importadas en el sistema',
        'ruta'   => $abre('facturas_xml.ver', 'facturas_xml') ? '/facturas' : null,
    ],
    [
        'titulo' => 'Bandeja de correo',
        'icono'  => 'fa-envelope-open-text',
        'tono'   => 'oro',
        'valor'  => number_format($pendientesCorreo),
        'estado' => $pendientesCorreo > 0 ? 'alerta' : 'apagado',
        'sub'    => $pendientesCorreo > 0 ? 'Facturas pendientes de importar' : 'Nada pendiente de importar',
        'ruta'   => $abre('correo.ver', 'correo') ? '/correo' : null,
    ],
    [
        'titulo' => 'Último pago',
        'icono'  => 'fa-file-invoice-dollar',
        'tono'   => 'verde',
        'valor'  => $ultimoListado ? $respaldadas . '/' . $totalLineas : '—',
        'estado' => !$ultimoListado ? 'apagado' : ($porResolver > 0 ? 'alerta' : 'ok'),
        'sub'    => $ultimoListado
            ? 'Respaldadas · ' . Pago::etiqueta($ultimoListado)
            : 'Aún no hay ningún pago',
        'ruta'   => $abre('por_pagar.ver', 'por_pagar') ? '/por-pagar' : null,
    ],
    [
        'titulo' => 'Por resolver',
        'icono'  => 'fa-triangle-exclamation',
        'tono'   => 'oro',
        'valor'  => $ultimoListado ? (string) $porResolver : '—',
        'estado' => !$ultimoListado ? 'apagado' : ($porResolver > 0 ? 'alerta' : 'ok'),
        'sub'    => $ultimoListado
            ? $sinRespaldo . ' sin respaldo · ' . $conDiferencia . ' con diferencia'
            : 'Crea el pago y sube su archivo en Pagos',
        'ruta'   => $abre('seguimiento.ver', 'seguimiento') ? '/seguimiento' : null,
    ],
];

/*
 * Lo pendiente de cada módulo, para colgárselo a su tarjeta. Solo lo que ya
 * se calculó para las métricas: el panel no hace consultas de más.
 */
$pendientesPorModulo = [
    'correo'      => [$pendientesCorreo, 'pendientes de importar'],
    'seguimiento' => [$ultimoListado ? $porResolver : 0, 'sin respaldo o con diferencia'],
];

$seccionesPanel = Navegacion::secciones();

/*
 * ── Lo que le falta a la instalación ──────────────────────────────────────
 * Mientras falte alguno de los pasos, casi ningún módulo se abre. Así
 * que se dice aquí arriba, con nombre y botón, y no abajo en letra pequeña:
 * el que entra el primer día tiene que saber por dónde empezar sin que nadie
 * se lo explique.
 */
$pasosQueFaltan = Requisitos::faltantes();
?>

<?php if ($pasosQueFaltan): ?>
<!-- ── Falta ponerlo en marcha ── -->
<div class="panel-arranque">
    <div class="panel-arranque-titulo">
        <i class="fas fa-flag-checkered"></i>
        Falta configurar el sistema
        <span class="panel-arranque-cuenta">
            <?= count($pasosQueFaltan) ?> de <?= count(Requisitos::declarados()) ?> pasos
        </span>
    </div>
    <p class="panel-arranque-texto">
        Los módulos que dependen de estos pasos están cerrados hasta completarlos.
        Se hace una sola vez.
    </p>
    <div class="panel-arranque-pasos">
        <?php $n = 0; foreach ($pasosQueFaltan as $paso): $n++;
            $loPuedeHacer = empty($paso['permiso']) || Permisos::puede((string) $paso['permiso']); ?>
        <div class="arranque-paso">
            <span class="arranque-numero"><?= $n ?></span>
            <div class="arranque-cuerpo">
                <div class="arranque-nombre"><?= htmlspecialchars((string) $paso['titulo']) ?></div>
                <div class="arranque-porque"><?= htmlspecialchars((string) ($paso['porque'] ?? '')) ?></div>
            </div>
            <?php if ($loPuedeHacer && !empty($paso['ruta'])): ?>
            <a href="<?= $baseUrl . $paso['ruta'] ?>" class="btn btn-outline btn-sm"
               title="<?= htmlspecialchars((string) ($paso['donde'] ?? '')) ?>">
                Hacerlo
            </a>
            <?php else: ?>
            <?php // Sin la llave no se ofrece un botón que acabaría en "no
                  // tienes permiso": se dice a quién pedírselo. ?>
            <span class="arranque-ajeno" title="<?= htmlspecialchars((string) ($paso['donde'] ?? '')) ?>">
                Lo hace el administrador
            </span>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- ── Cómo va el trabajo ── -->
<div class="panel-metricas">
    <?php foreach ($metricas as $m):
        $etiqueta = $m['ruta'] ? 'a' : 'div';
        $destino  = $m['ruta'] ? ' href="' . $baseUrl . $m['ruta'] . '"' : '';
        $ayuda    = $m['ruta'] ? ' title="Ir a ' . htmlspecialchars($m['titulo']) . '"' : '';
    ?>
    <<?= $etiqueta ?> class="metrica tono-<?= $m['tono'] ?>"<?= $destino . $ayuda ?>>
        <span class="metrica-chip"><i class="fas <?= $m['icono'] ?>"></i></span>
        <span class="metrica-cuerpo">
            <span class="metrica-label"><?= htmlspecialchars($m['titulo']) ?></span>
            <span class="metrica-valor <?= $m['estado'] ? 'es-' . $m['estado'] : '' ?>">
                <?= htmlspecialchars($m['valor']) ?>
            </span>
            <span class="metrica-sub" title="<?= htmlspecialchars($m['sub']) ?>">
                <?= htmlspecialchars($m['sub']) ?>
            </span>
        </span>
    </<?= $etiqueta ?>>
    <?php endforeach; ?>
</div>

<?php // Elegir con qué empresa se trabaja es cosa de todos los días y por eso
      // sigue acá. Registrarlas, editarlas y borrarlas —que se hace una vez—
      // se mudó a Configuración, junto al resto de los ajustes. ?>
<div class="panel-empresa">
    <div class="panel-empresa-titulo">
        <i class="fas fa-building"></i> Empresa con la que trabajas
    </div>

    <?php if (empty($sociedades)): ?>
    <div class="panel-empresa-aviso">
        <?php if (Permisos::puede('empresas.administrar')): ?>
        Todavía no hay ninguna empresa registrada. La que elijas es con la que trabajas.
        <a href="<?= $baseUrl ?>/configuracion?ir=empresas">Registra la primera</a>.
        <?php else: ?>
        No tienes ninguna empresa asignada. Pídesela al administrador: hasta
        entonces los listados saldrán vacíos.
        <?php endif; ?>
    </div><!-- /panel-empresa-aviso -->
    <?php else: ?>
    <div class="panel-empresa-lista">
        <?php foreach ($sociedades as $soc):
            $enUso = (int) $soc['id'] === $sociedadEnUsoId; ?>
        <form method="POST" action="<?= $baseUrl ?>/sociedades/activar/<?= (int) $soc['id'] ?>">
            <button type="submit" class="empresa-chip<?= $enUso ? ' es-activa' : '' ?>"
                    <?= $enUso ? 'disabled' : '' ?>
                    title="<?= $enUso ? 'Estás trabajando con esta empresa' : 'Trabajar con esta empresa' ?>">
                <i class="<?= $enUso ? 'fas fa-circle-check' : 'far fa-circle' ?>"></i>
                <span class="empresa-chip-nombre"><?= htmlspecialchars($soc['nombre']) ?></span>
                <span class="empresa-chip-cedula">
                    <?= htmlspecialchars($soc['cedula']) ?>
                    <?php if (!empty($soc['activa'])): ?>
                    · <span title="Con la que arranca quien nunca ha elegido y con la que corren las tareas automáticas">por omisión</span>
                    <?php endif; ?>
                </span>
            </button>
        </form>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php // Registrar empresas es de administración; elegir con cuál se
          // trabaja lo hace cualquiera, entre las suyas. ?>
    <?php if (Permisos::puede('empresas.administrar')): ?>
    <a href="<?= $baseUrl ?>/configuracion?ir=empresas" class="btn btn-outline btn-sm"
       title="Registrar, editar o eliminar empresas">
        <i class="fas fa-gear"></i> Administrar
    </a>
    <?php endif; ?>
</div>

<!-- ── Lo que este usuario puede abrir ── -->
<div class="panel-modulos-cabecera">
    <div class="panel-modulos-titulo">Tus módulos</div>
    <div class="panel-rol">
        Según tu rol: <strong><?= htmlspecialchars(Permisos::nombreRol()) ?></strong>
    </div>
</div>

<?php if (!$seccionesPanel): ?>
<div class="panel-vacio">
    <i class="fas fa-circle-info"></i>
    Tu rol (<strong><?= htmlspecialchars(Permisos::nombreRol()) ?></strong>) todavía no
    incluye ningún módulo. Pídele al administrador que te asigne uno.
</div>
<?php else: ?>
    <?php foreach ($seccionesPanel as $seccion): ?>
    <div class="panel-seccion"><?= htmlspecialchars($seccion['titulo']) ?></div>
    <div class="panel-modulos">
        <?php foreach ($seccion['entradas'] as $entrada):
            [$pendiente, $queSon] = $pendientesPorModulo[$entrada['id']] ?? [0, ''];

            /*
             * El módulo cerrado por configuración se queda en la lista, pero
             * como tarjeta apagada y sin enlace: en vez de su resumen dice el
             * paso que falta. Es la única forma de que quien lo busca entienda
             * por qué no está en vez de creer que se rompió.
             */
            $cerrado = !empty($entrada['bloqueada']);
            $etiquetaModulo = $cerrado ? 'div' : 'a';
            $faltaTexto = $cerrado
                ? (count($entrada['falta']) === 1 ? 'Falta ' : 'Faltan ')
                    . implode(' y ', array_map(
                        fn($r) => lcfirst((string) ($r['titulo'] ?? '')), $entrada['falta']))
                : ''; ?>
        <<?= $etiquetaModulo ?> <?= $cerrado ? '' : 'href="' . $baseUrl . $entrada['ruta'] . '"' ?>
           class="modulo tono-<?= $entrada['tono'] ?><?= $cerrado ? ' es-cerrado' : '' ?>"
           title="<?= htmlspecialchars($cerrado ? $faltaTexto : $entrada['ayuda']) ?>">
            <span class="modulo-icono"><i class="fas <?= $entrada['icono'] ?>"></i></span>
            <span class="modulo-cuerpo">
                <span class="modulo-nombre">
                    <?= htmlspecialchars($entrada['titulo']) ?>
                    <?php if ($cerrado): ?>
                    <span class="modulo-candado" title="Todavía no se puede usar"><i class="fas fa-lock"></i></span>
                    <?php elseif ($pendiente > 0): ?>
                    <span class="modulo-marca" title="<?= $pendiente ?> <?= htmlspecialchars($queSon) ?>"><?= number_format($pendiente) ?></span>
                    <?php endif; ?>
                </span>
                <span class="modulo-resumen">
                    <?= htmlspecialchars($cerrado ? $faltaTexto : $entrada['resumen']) ?>
                </span>
            </span>
            <?php if (!$cerrado): ?>
            <i class="fas fa-chevron-right modulo-flecha"></i>
            <?php endif; ?>
        </<?= $etiquetaModulo ?>>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
<?php endif; ?>
