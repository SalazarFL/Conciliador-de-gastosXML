<?php
/**
 * Un pago se identifica por su fecha, no por la semana en la que cae.
 *
 * Es el motivo entero del cambio: se paga cuando hay que pagar —un día suelto,
 * tres veces en la misma semana, una vez cada quince— y con la semana como
 * identidad eso no se podía ni escribir. Dos pagos del mismo lunes eran «la
 * semana 37» los dos, no se distinguían en la lista y no había forma de
 * ordenarlos.
 *
 * Lo que se fija acá:
 *
 *   · La fecha es lo que nombra al pago; el nombre es una etiqueta opcional
 *     para cuando dice algo que la fecha no dice («el urgente»).
 *   · Dos pagos del mismo día se distinguen y se ordenan.
 *   · La lista va por fecha de pago, no por cuándo se cargó el archivo: el
 *     pago del martes que se sube el jueves sale donde le toca.
 *   · Las dos formas de escribir una fecha que llegan en la práctica.
 *
 * La parte que habla con la base necesita base; sin ella se salta esa mitad y
 * se prueba lo demás, que es puro cálculo.
 *
 * Uso: php tests/PagoFechaTest.php
 */

require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/models/Pago.php';
require_once __DIR__ . '/soporte/SociedadDePrueba.php';

$fallos = 0;
function verificaPago($condicion, $mensaje)
{
    global $fallos;
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        $fallos++;
    }
}

// ── Cómo se nombra un pago en pantalla ────────────────────────────────
verificaPago(
    Pago::etiqueta(['nombre' => 'Pago 03/09/2026', 'fecha_pago' => '2026-09-03'])
        === 'Pago 03/09/2026',
    'un pago sin nombre propio se llama por su fecha, sin repetirla dos veces'
);
verificaPago(
    Pago::etiqueta(['nombre' => '', 'fecha_pago' => '2026-09-03']) === 'Pago 03/09/2026',
    'y sin nombre ninguno, igual'
);
verificaPago(
    Pago::etiqueta(['nombre' => 'Urgente proveedores', 'fecha_pago' => '2026-09-03'])
        === '03/09/2026 · Urgente proveedores',
    'el nombre que sí dice algo se muestra junto a la fecha, no en su lugar'
);
verificaPago(
    Pago::etiqueta(['id' => 12, 'nombre' => 'Semana pago 2708', 'fecha_pago' => null])
        === 'Semana pago 2708',
    'un pago viejo sin fecha conserva el nombre que tenía'
);
verificaPago(
    Pago::etiqueta(['id' => 12, 'nombre' => '', 'fecha_pago' => '']) === 'Pago #12',
    'y sin fecha ni nombre queda su número, que siempre existe'
);

// Tres pagos de la misma semana: lo que antes era imposible de distinguir.
$lunes  = Pago::etiqueta(['nombre' => '', 'fecha_pago' => '2026-09-07']);
$jueves = Pago::etiqueta(['nombre' => '', 'fecha_pago' => '2026-09-10']);
$otroLunes = Pago::etiqueta(['nombre' => 'Planilla', 'fecha_pago' => '2026-09-07']);
verificaPago($lunes !== $jueves && $lunes !== $otroLunes && $jueves !== $otroLunes,
    'tres pagos de la misma semana se llaman distinto entre sí');

// ── Las fechas que llegan de verdad ───────────────────────────────────
verificaPago(Pago::normalizarFecha('2026-09-03') === '2026-09-03',
    'la fecha del <input type="date"> se guarda tal cual');
verificaPago(Pago::normalizarFecha('3/9/2026') === '2026-09-03',
    'y la que alguien escribe a mano en dd/mm/aaaa se convierte');
verificaPago(Pago::normalizarFecha('03/09/2026') === '2026-09-03',
    'con o sin ceros a la izquierda');
verificaPago(Pago::normalizarFecha('2026-02-30') === '',
    'el 30 de febrero no se guarda: no existe');
verificaPago(Pago::normalizarFecha('31/04/2026') === '',
    'ni un 31 de abril escrito a mano');
verificaPago(Pago::normalizarFecha('') === '' && Pago::normalizarFecha('cuando sea') === '',
    'lo que no es una fecha no lo es');

// ── La mitad que necesita base ────────────────────────────────────────
$hayBase = false;
$pdo = null;
try {
    $config = require __DIR__ . '/../app/config/database.php';
    // Se guarda la conexión: hace falta para crear la empresa de la prueba.
    $pdo = new PDO($config['dsn'], $config['username'], $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $hayBase = true;
} catch (Throwable $e) {
}

if (!$hayBase) {
    echo "SKIP: la parte con base de datos (sin base disponible)\n";
} else {
    // Empresa de verdad: `pagos.sociedad_id` ya tiene clave foránea y un id
    // inventado —lo de antes— ya no entra.
    $sociedadPrueba = SociedadDePrueba::crear($pdo, 'pago fecha');
    // El modelo trabaja acotado a una empresa, como en la aplicacion: leer un
    // pago por su id tambien pasa por ese filtro.
    $modelo = (new Pago())->setSociedad($sociedadPrueba);
    $creados = [];

    // Dos pagos el mismo día y uno anterior cargado después: el orden de la
    // lista tiene que salir por fecha de pago, no por id.
    $creados[] = (int) $modelo->crear('', $sociedadPrueba, '2026-09-07');
    $creados[] = (int) $modelo->crear('Urgente', $sociedadPrueba, '2026-09-07');
    $creados[] = (int) $modelo->crear('Atrasado', $sociedadPrueba, '2026-09-01');

    $lista = (new Pago())->setSociedad($sociedadPrueba)->getAll();
    $fechas = array_map(fn($p) => (string) $p['fecha_pago'], $lista);

    verificaPago(count($lista) === 3, 'los tres pagos quedaron guardados');
    verificaPago($fechas === ['2026-09-07', '2026-09-07', '2026-09-01'],
        'la lista va por fecha de pago: el que se cargó tarde sale donde le toca');
    verificaPago($lista[0]['id'] > $lista[1]['id'],
        'y el id desempata los del mismo día, del último al primero');

    $sinFecha = $modelo->crear('Sin fecha dicha', $sociedadPrueba, '');
    $reciente = (new Pago())->setSociedad($sociedadPrueba)->getAll()[0];
    verificaPago((int) $reciente['id'] === (int) $sinFecha
        && (string) $reciente['fecha_pago'] === date('Y-m-d'),
        'un pago sin fecha dicha se crea con la de hoy, no sin fecha');
    $creados[] = (int) $sinFecha;

    // Cambiar la fecha de uno que se creó mal.
    $modelo->configurarFecha($creados[2], '2/9/2026');
    $corregido = $modelo->findById($creados[2]);
    verificaPago((string) $corregido['fecha_pago'] === '2026-09-02',
        'la fecha se puede corregir después, y acepta el formato de a mano');
    $modelo->configurarFecha($creados[2], 'no es fecha');
    $corregido = $modelo->findById($creados[2]);
    verificaPago((string) $corregido['fecha_pago'] === '2026-09-02',
        'y una fecha inválida no borra la que ya estaba');

    // Limpieza: los pagos primero y la empresa después, que es el orden que
    // exige la clave foránea —con pagos colgando, borrarla está prohibido—.
    // execute() es protegido, y con razón —nadie escribe SQL suelto contra un
    // modelo—; acá se abre solo para la prueba.
    $borrar = new class extends Model {
        public function borrarPagos(array $ids)
        {
            foreach ($ids as $id) {
                $this->execute('DELETE FROM pagos WHERE id = ?', [(int) $id]);
            }
        }
    };
    $borrar->borrarPagos($creados);
    SociedadDePrueba::borrar($pdo, $sociedadPrueba);
}

if ($fallos > 0) {
    fwrite(STDERR, "{$fallos} verificación(es) fallaron\n");
    exit(1);
}

echo "OK: el pago se identifica por su fecha, y dos del mismo día se distinguen\n";
