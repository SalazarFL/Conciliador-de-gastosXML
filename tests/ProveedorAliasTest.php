<?php
/**
 * Emparejar a mano una vez debe resolver el problema para siempre.
 *
 * El caso real: el listado del ERP dice "COOPEAGRI" y el XML de Hacienda dice
 * "COOPERATIVA AGRICOLA INDUSTRIAL Y DE SERVICIOS MULTIPLES EL GENERAL R.L.".
 * No comparten ni una palabra. Ningún algoritmo de parecido las va a juntar, y
 * bajar el umbral hasta lograrlo solo empezaría a mezclar proveedores
 * distintos. La única fuente válida es una persona diciendo "son la misma".
 *
 * Lo que se prueba es que esa decisión se guarde UNA vez y valga desde
 * entonces: para las facturas que ya estaban sin respaldo y para las que
 * lleguen después. Si no, el usuario tendría que repetir el emparejamiento
 * manual en cada factura de ese proveedor, cada semana.
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/models/ProveedorAlias.php';
require_once __DIR__ . '/../app/helpers/FacturaMatcher.php';

$fallos = 0;
function verificaAlias($condicion, $mensaje)
{
    global $fallos;
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        $fallos++;
    }
}

$config = require __DIR__ . '/../app/config/database.php';
try {
    $pdo = new PDO(
        $config['dsn'],
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $pdo->query('SELECT 1 FROM proveedor_alias LIMIT 1');
} catch (Throwable $e) {
    echo "SKIP: ProveedorAlias (sin base de datos o sin migration_proveedor_alias.sql)\n";
    exit(0);
}

$NOMBRE_LARGO = 'COOPERATIVA AGRICOLA INDUSTRIAL Y DE SERVICIOS MULTIPLES EL GENERAL R.L.';
$CORTO        = 'COOPEAGRI';
$OTRO_NOMBRE  = 'DISTRIBUIDORA LA CANASTA SOCIEDAD ANONIMA';

$cedula = '3999' . substr((string) time(), -8);
$cedulaOtro = '3888' . substr((string) time(), -8);
$provId = $otroId = 0;

$limpiar = function () use ($pdo, &$provId, &$otroId) {
    foreach ([$provId, $otroId] as $id) {
        if ($id > 0) {
            $pdo->prepare('DELETE FROM proveedor_alias WHERE proveedor_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM proveedores WHERE id = ?')->execute([$id]);
        }
    }
    ProveedorAlias::olvidarMapa();
};

try {
    $ins = $pdo->prepare('INSERT INTO proveedores (rfc, razon_social) VALUES (?, ?)');
    $ins->execute([$cedula, $NOMBRE_LARGO]);
    $provId = (int) $pdo->lastInsertId();
    $ins->execute([$cedulaOtro, $OTRO_NOMBRE]);
    $otroId = (int) $pdo->lastInsertId();

    $modelo = new ProveedorAlias();

    // ── Antes de enseñar nada: no se parecen y no deben juntarse ──────
    ProveedorAlias::olvidarMapa();
    $antes = FacturaMatcher::similaridadTexto($CORTO, $NOMBRE_LARGO);
    verificaAlias($antes < FacturaMatcher::UMBRAL_PROVEEDOR,
        "sin alias, \"{$CORTO}\" y el nombre largo no se parecen (score {$antes}) — por eso hace falta el manual");

    // ── La persona empareja a mano: se aprende la equivalencia ────────
    verificaAlias($modelo->aprender($provId, $CORTO) === true,
        'emparejar a mano deja aprendida la equivalencia');

    $despues = FacturaMatcher::similaridadTexto($CORTO, $NOMBRE_LARGO);
    verificaAlias($despues >= FacturaMatcher::UMBRAL_PROVEEDOR,
        "tras aprenderla, las siguientes facturas sí se emparejan (score {$despues})");
    verificaAlias(FacturaMatcher::mismoProveedor($CORTO, $NOMBRE_LARGO) === true,
        'y se reconocen como el mismo proveedor en la comparación estricta');

    // En los dos sentidos: da igual cuál venga del ERP y cuál del XML.
    verificaAlias(FacturaMatcher::mismoProveedor($NOMBRE_LARGO, $CORTO) === true,
        'la equivalencia vale en los dos sentidos');

    // Y con la escritura de todos los días: minúsculas, tildes, "S.A.".
    verificaAlias(FacturaMatcher::mismoProveedor('Coopeagri S.A.', $NOMBRE_LARGO) === true,
        'no importa cómo venga escrito el alias (minúsculas, puntos, S.A.)');

    // ── Lo que NO debe pasar: arrastrar a otros proveedores ───────────
    verificaAlias(FacturaMatcher::mismoProveedor($CORTO, $OTRO_NOMBRE) === false,
        'el alias no vuelve iguales a dos proveedores distintos');
    verificaAlias(FacturaMatcher::similaridadTexto($CORTO, $OTRO_NOMBRE) < FacturaMatcher::UMBRAL_PROVEEDOR,
        'un proveedor ajeno sigue sin parecerse');

    // ── Un segundo alias para el mismo proveedor ──────────────────────
    verificaAlias($modelo->aprender($provId, 'COOPE AGRI EL GENERAL') === true,
        'un proveedor puede acumular varias formas de escribirse');
    verificaAlias(FacturaMatcher::mismoProveedor('COOPE AGRI EL GENERAL', $CORTO) === true,
        'dos alias del mismo proveedor también se reconocen entre sí');

    // ── Corregir un error: el último emparejamiento manda ─────────────
    verificaAlias($modelo->aprender($otroId, $CORTO) === true,
        'volver a emparejar reescribe un alias equivocado');
    verificaAlias(FacturaMatcher::mismoProveedor($CORTO, $OTRO_NOMBRE) === true,
        'tras la corrección, el alias apunta al proveedor nuevo');
    verificaAlias(FacturaMatcher::mismoProveedor($CORTO, $NOMBRE_LARGO) === false,
        'y deja de apuntar al anterior — un texto no puede ser dos proveedores');

    $cuantos = (int) $pdo->query("SELECT COUNT(*) FROM proveedor_alias
                                   WHERE alias_norm = '{$CORTO}'")->fetchColumn();
    verificaAlias($cuantos === 1, 'corregir no duplica la fila, la reescribe');

    // ── Nada que aprender cuando los nombres ya se parecían ───────────
    verificaAlias($modelo->aprender($provId, $NOMBRE_LARGO) === false,
        'no se guarda un alias igual al nombre real');
    verificaAlias($modelo->aprender($provId, '') === false,
        'no se guarda un alias vacío');
    verificaAlias($modelo->aprender(0, 'LO QUE SEA') === false,
        'no se guarda un alias sin proveedor');
} catch (Throwable $e) {
    $limpiar();
    fwrite(STDERR, 'FAIL: excepción inesperada: ' . $e->getMessage() . "\n");
    exit(1);
}

$limpiar();

if ($fallos > 0) {
    fwrite(STDERR, "{$fallos} verificación(es) fallaron\n");
    exit(1);
}
echo "OK: emparejar a mano una vez vale para todas las facturas de ese proveedor\n";
