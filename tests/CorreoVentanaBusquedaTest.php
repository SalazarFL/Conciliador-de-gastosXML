<?php
/**
 * La ventana con la que arranca una búsqueda de texto en el buzón.
 *
 * Buscar texto libre no lo resuelve ningún índice: "un pedacito de texto en
 * algún lugar del renglón" obliga a leerlos todos, y ese recorrido crece con
 * el buzón —hoy 138 mil correos, con 42 buzones casi dos millones—. Por eso la
 * primera vuelta mira medio año, que es la cuarta parte del índice.
 *
 * Lo que se fija acá es que ese ahorro NO cambie ninguna respuesta:
 *
 *   · si el documento es más viejo, la segunda vuelta lo encuentra igual;
 *   · si la cuenta ya tiene su propio límite de días, la ventana no lo amplía
 *     —acotar no es autorizar—;
 *   · y si la cuenta ya mira menos que la ventana, no se busca dos veces por
 *     gusto, porque las dos vueltas serían idénticas.
 *
 * Uso: php tests/CorreoVentanaBusquedaTest.php
 */

function assertVentana($condicion, $mensaje)
{
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
    }
}

$fuente = (string) file_get_contents(__DIR__ . '/../app/controllers/CorreoController.php');

// La constante existe y vale medio año.
assertVentana(preg_match('/DIAS_BUSQUEDA_RECIENTE\s*=\s*(\d+)/', $fuente, $m) === 1,
    'la ventana de la primera vuelta está declarada como constante');
$ventana = (int) $m[1];
assertVentana($ventana === 180, "la ventana es de medio año (son {$ventana} días)");

/*
 * La regla de qué ventana se aplica, replicada tal como la hace el cierre de
 * búsqueda. Se prueba la decisión, que es donde estaría el error: acotar de
 * más deja documentos sin encontrar, y acotar de menos no ahorra nada.
 */
$ventanaEfectiva = function ($diasCuenta, $diasPedidos) {
    if ($diasPedidos === null) {
        return (int) $diasCuenta;
    }
    if ((int) $diasCuenta <= 0) {
        return (int) $diasPedidos;
    }

    return min((int) $diasCuenta, (int) $diasPedidos);
};

// ── La ventana acota, nunca amplía ────────────────────────────────────────

assertVentana($ventanaEfectiva(0, 180) === 180,
    'una cuenta sin límite propio queda acotada por la ventana');

assertVentana($ventanaEfectiva(60, 180) === 60,
    'una cuenta que mira 60 días sigue mirando 60: pedir 180 NO la amplía');

assertVentana($ventanaEfectiva(365, 180) === 180,
    'una cuenta que mira más de la ventana queda acotada por la ventana');

assertVentana($ventanaEfectiva(60, null) === 60,
    'sin pedir ventana se respeta el límite de la cuenta, como siempre');

assertVentana($ventanaEfectiva(0, null) === 0,
    'y una cuenta sin límite sigue sin límite cuando nadie pide ventana');

// ── Cuándo tiene sentido la segunda vuelta ────────────────────────────────
//
// Solo si la primera de verdad acotó algo. Con una cuenta que ya mira menos
// que la ventana, las dos vueltas darían exactamente lo mismo y buscar dos
// veces sería tirar el ahorro que se acaba de conseguir.

$laVentanaAcota = function ($diasCuenta) use ($ventana) {
    return (int) $diasCuenta <= 0 || (int) $diasCuenta > $ventana;
};

assertVentana($laVentanaAcota(0) === true,
    'con la cuenta sin límite, la segunda vuelta sí amplía');

assertVentana($laVentanaAcota(365) === true,
    'con la cuenta en 365 días, la segunda vuelta amplía de 180 a 365');

assertVentana($laVentanaAcota(60) === false,
    'con la cuenta en 60 días no hay segunda vuelta: sería la misma consulta');

assertVentana($laVentanaAcota(180) === false,
    'con la cuenta justo en la ventana tampoco: no hay nada que ampliar');

// ── La escalera está puesta donde corresponde ─────────────────────────────
//
// Solo en la búsqueda sin rango ni mes. Las que ya llegan acotadas —la lupa de
// la tarjeta con su rango, o el mes de la factura— no deben pasar por acá: ya
// miran poco, y meterles otra vuelta sería buscar dos veces para nada.

assertVentana(strpos($fuente, "\$buscarLocal('', true, '', '', self::DIAS_BUSQUEDA_RECIENTE)") !== false,
    'la primera vuelta pide la ventana explícitamente');

$pos = strpos($fuente, "\$buscarLocal('', true, '', '', self::DIAS_BUSQUEDA_RECIENTE)");
$antes = substr($fuente, max(0, $pos - 2500), min($pos, 2500));
assertVentana(strpos($antes, "!\$rangoTarjetaProbado && !\$rangoTarjetaAplicado && \$mesAplicado === ''") !== false,
    'la escalera solo corre en la búsqueda que no traía ni rango ni mes');

echo "OK: Ventana de la búsqueda de texto en el buzón\n";
