<?php
/**
 * Los comprobantes sin código salieron de la campana, pero no del sistema.
 *
 * Es un cambio fácil de romper sin notarlo: basta que alguien añada una
 * consulta nueva a la campana y se olvide del filtro para que vuelvan los
 * veinte renglones que tapaban los tres avisos que sí urgían. Y al revés:
 * basta que el filtro se cuele en la lista de Correo para que el trabajo de
 * catálogo desaparezca de la única pantalla que lo muestra, sin que nada lo
 * diga.
 *
 * Por eso se comprueban las dos mitades a la vez: que la campana NO los vea y
 * que la pantalla de Correo SÍ los vea, con el mismo aviso y en la misma
 * corrida. Un aviso de otro tipo hace de testigo, para distinguir "los filtró"
 * de "no devolvió nada".
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/models/Notificacion.php';
require_once __DIR__ . '/../app/models/ProveedorCodigoErp.php';

function assertSinCodigo($condicion, $mensaje)
{
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
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
} catch (Throwable $e) {
    echo "SKIP: AvisosSinCodigo (sin base de datos disponible)\n";
    exit(0);
}

$marca = 'test_sin_codigo_' . bin2hex(random_bytes(4));
$firmaSinCodigo = 'proveedor_sin_codigo|' . $marca;
$firmaTestigo   = 'prueba|' . $marca;

$limpiar = function () use ($pdo, $marca) {
    $pdo->prepare('DELETE FROM notificaciones WHERE firma LIKE ?')->execute(['%' . $marca . '%']);
};
$limpiar();

try {
    $avisos = new Notificacion();

    // El de verdad: el que deja XmlDocumentImporter cuando llega un
    // comprobante de una cédula que el catálogo del ERP no conoce.
    $avisos->avisar([
        'tipo' => 'proveedor_sin_codigo',
        'titulo' => 'Llegó un comprobante de un proveedor sin código',
        'firma' => $firmaSinCodigo,
        'ref_tabla' => 'proveedor_codigo_erp',
        'ref_clave' => $marca,
        'datos' => ['cedula' => $marca, 'nombre' => 'PROVEEDOR DE PRUEBA'],
    ]);

    // El testigo: cualquier otro aviso, que sí tiene que seguir sonando.
    $avisos->avisar([
        'tipo' => 'prueba',
        'titulo' => 'Aviso de prueba que sí va a la campana',
        'firma' => $firmaTestigo,
        'ref_clave' => $marca . '-testigo',
    ]);

    $existe = $pdo->prepare('SELECT COUNT(*) FROM notificaciones WHERE firma = ?');
    $existe->execute([$firmaSinCodigo]);
    assertSinCodigo((int) $existe->fetchColumn() === 1,
        'el aviso se sigue guardando: sacarlo de la campana no es dejar de registrarlo');

    // ── La campana no los ve ──
    $firmasCampana = array_column($avisos->listar('pendiente', 500), 'firma');
    assertSinCodigo(!in_array($firmaSinCodigo, $firmasCampana, true),
        'la pantalla de Avisos no lista los comprobantes sin código');
    assertSinCodigo(in_array($firmaTestigo, $firmasCampana, true),
        'la pantalla de Avisos sigue listando los demás avisos');

    // El panel desplegable no devuelve la firma —pide solo lo que pinta—, así
    // que los avisos de esta prueba se reconocen por su referencia.
    $enPanel = array_column($avisos->recientes(500), 'ref_clave');
    assertSinCodigo(!in_array($marca, $enPanel, true),
        'el panel desplegable de la campana no muestra los comprobantes sin código');
    assertSinCodigo(in_array($marca . '-testigo', $enPanel, true),
        'el panel desplegable sigue mostrando los demás avisos');

    // El número rojo: se compara contra sí mismo antes y después, porque la
    // base de certificación puede traer pendientes de otras pruebas.
    $antes = $avisos->pendientes();
    $avisos->avisar([
        'tipo' => 'proveedor_sin_codigo',
        'titulo' => 'Otro comprobante sin código',
        'firma' => $firmaSinCodigo . '|otro',
        'datos' => ['cedula' => $marca . '9', 'nombre' => 'OTRO PROVEEDOR'],
    ]);
    assertSinCodigo($avisos->pendientes() === $antes,
        'un comprobante sin código más no sube el número rojo de la campana');

    // ── La pantalla de Correo sí los ve ──
    $deCorreo = $avisos->porTipo('proveedor_sin_codigo', 'pendiente');
    $firmasCorreo = array_column($deCorreo, 'firma');
    assertSinCodigo(in_array($firmaSinCodigo, $firmasCorreo, true),
        'Comprobantes sin código lista el aviso que la campana dejó pasar');
    assertSinCodigo(!in_array($firmaTestigo, $firmasCorreo, true),
        'Comprobantes sin código no se llena con avisos de otro tipo');

    $conteo = $avisos->contarPorTipo('proveedor_sin_codigo');
    assertSinCodigo($conteo >= 2,
        'el número del botón cuenta los comprobantes sin código pendientes');

    // Los datos que necesita la lista para poder decidir: sin cédula no hay
    // a qué darle el código, y el renglón sería una fila muda.
    $fila = null;
    foreach ($deCorreo as $candidata) {
        if ($candidata['firma'] === $firmaSinCodigo) {
            $fila = $candidata;
            break;
        }
    }
    assertSinCodigo($fila !== null, 'el aviso buscado está en la lista');
    $datos = json_decode((string) $fila['datos'], true);
    assertSinCodigo(is_array($datos) && ($datos['cedula'] ?? '') === $marca,
        'el aviso trae la cédula con la que se le va a dar el código');

    // ── Decidido uno, se va de la lista ──
    $avisos->cerrar((int) $fila['id'], 'resuelta', 0, 'Se le dio el código de prueba');
    $pendientesAhora = array_column($avisos->porTipo('proveedor_sin_codigo', 'pendiente'), 'firma');
    assertSinCodigo(!in_array($firmaSinCodigo, $pendientesAhora, true),
        'el que ya se decidió sale de los pendientes');
    $resueltos = array_column($avisos->porTipo('proveedor_sin_codigo', 'resuelta'), 'firma');
    assertSinCodigo(in_array($firmaSinCodigo, $resueltos, true),
        'el que ya se decidió se puede seguir consultando en "Ya resueltos"');

    // ── El código se elige, no se escribe ──
    //
    // La lista de Correo ofrece los mismos códigos que Configuración →
    // Proveedores, y el servidor solo acepta uno que exista: escribir a mano
    // dejaba pasar una cédula o un número mal copiado como código, y ese error
    // no se ve hasta que una factura acepta el comprobante equivocado, que es
    // justo lo que el aviso venía a evitar.
    $catalogo = new ProveedorCodigoErp();

    assertSinCodigo($catalogo->codigoExiste('ZZ' . $marca) === false,
        'un código inventado no pasa la guarda que sostiene la lista');
    assertSinCodigo($catalogo->opcionesDeCodigo('ZZ' . $marca) === [],
        'la lista no ofrece códigos que no existen');

    // Con el catálogo cargado se comprueba la otra mitad: que un código real
    // sí aparece y sí se acepta. Sin listado importado no hay nada que
    // ofrecer, y eso es correcto, no un fallo.
    $unCodigo = (string) $pdo->query(
        'SELECT proveedor_codigo FROM proveedor_codigo_erp ORDER BY proveedor_codigo LIMIT 1'
    )->fetchColumn();
    if ($unCodigo !== '') {
        assertSinCodigo($catalogo->codigoExiste($unCodigo),
            'un código del catálogo sí pasa la guarda');
        $ofrecidos = array_column($catalogo->opcionesDeCodigo($unCodigo), 'codigo');
        assertSinCodigo(in_array($unCodigo, $ofrecidos, true),
            'buscar un código del catálogo lo encuentra en la lista');
    }
} finally {
    $limpiar();
}

echo "OK: los comprobantes sin código no suenan en la campana y se atienden en Correo\n";
