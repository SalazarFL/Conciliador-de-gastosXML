<?php
/**
 * Recortar un adjunto del mensaje entero da lo mismo que pedírselo al servidor.
 *
 * MailFetcher baja cada correo del lote de una vez y recorta de ahí sus XML y
 * PDF (SeccionMime), en vez de un viaje por adjunto. Si el recorte difiere en
 * un solo byte de lo que habría dado el servidor, la factura queda corrupta;
 * por eso ante la duda contesta null y se le pregunta al servidor.
 *
 * Los casos salen de lo que se vio en mail.bm.cr el 28/09/2026 al comparar
 * 1.648 secciones reales contra el servidor: boundary sin comillas que empieza
 * con "--", boundary entre comillas en una línea doblada, multipart dentro de
 * multipart, y contenedores que no se recortan a propósito.
 */

require_once __DIR__ . '/../app/helpers/SeccionMime.php';

$fallas = [];

function comprobarMime($condicion, $mensaje)
{
    global $fallas;
    if (!$condicion) {
        $fallas[] = $mensaje;
    }
}

/** Une con CRLF, como llegan del servidor. */
function crlf(array $lineas)
{
    return implode("\r\n", $lineas);
}

$xml = base64_encode('<FacturaElectronica>1</FacturaElectronica>');
$pdf = base64_encode("%PDF-1.4\nuno");

// ── Lo común: texto, XML y PDF ──────────────────────────────────────────
$comun = crlf([
    'From: proveedor@ejemplo.cr',
    'Subject: Factura 1',
    'Content-Type: multipart/mixed; boundary="LIMITE"',
    '',
    'This is a multi-part message in MIME format.',
    '--LIMITE',
    'Content-Type: text/plain; charset=utf-8',
    '',
    'Adjunto la factura.',
    '--LIMITE',
    'Content-Type: text/xml; name="FE_1.xml"',
    'Content-Transfer-Encoding: base64',
    '',
    $xml,
    '--LIMITE',
    'Content-Type: application/pdf; name="FE_1.pdf"',
    'Content-Transfer-Encoding: base64',
    '',
    $pdf,
    '--LIMITE--',
    'epílogo que no cuenta',
]);
comprobarMime(SeccionMime::recortar($comun, '1') === 'Adjunto la factura.', 'la parte 1 es el texto');
comprobarMime(SeccionMime::recortar($comun, '2') === $xml, 'la parte 2 es el XML, sin el salto que precede al delimitador');
comprobarMime(SeccionMime::recortar($comun, '3') === $pdf, 'la parte 3 es el PDF');
comprobarMime(SeccionMime::recortar($comun, '4') === null, 'una parte que no existe: no se sabe');

// ── Un mensaje de una sola parte ────────────────────────────────────────
$solo = crlf([
    'Subject: solo',
    'Content-Type: text/xml; name="FE_2.xml"',
    'Content-Transfer-Encoding: base64',
    '',
    $xml,
]);
comprobarMime(SeccionMime::recortar($solo, '1') === $xml, 'sin partes, la "1" es el cuerpo');
comprobarMime(SeccionMime::recortar($solo, '2') === null && SeccionMime::recortar($solo, '1.1') === null,
    'y no hay nada más');

// ── Boundary sin comillas que empieza con "--", y doblado ──────────────
$raro = crlf([
    'Content-Type: multipart/mixed;',
    ' boundary=--boundary_267298_00d24709',
    '',
    '',
    '----boundary_267298_00d24709',
    'Content-Type: text/xml;',
    "\tname=\"FE_3.xml\"",
    '',
    $xml,
    '----boundary_267298_00d24709--',
    '',
    '',
]);
comprobarMime(SeccionMime::recortar($raro, '1') === $xml, 'un boundary que empieza con "--" y viene en otra línea');

// ── Multipart dentro de multipart ───────────────────────────────────────
$anidado = crlf([
    'Content-Type: multipart/mixed;',
    "\tboundary=\"----=_NextPart_000_095C\"",
    '',
    '------=_NextPart_000_095C',
    'Content-Type: multipart/alternative; boundary="ALT"',
    '',
    '--ALT',
    'Content-Type: text/plain',
    '',
    'texto',
    '--ALT',
    'Content-Type: text/html',
    '',
    '<p>texto</p>',
    '--ALT--',
    '',
    '------=_NextPart_000_095C',
    'Content-Type: application/pdf; name="FE_4.pdf"',
    'Content-Transfer-Encoding: base64',
    '',
    $pdf,
    '------=_NextPart_000_095C--',
]);
comprobarMime(SeccionMime::recortar($anidado, '1.1') === 'texto' && SeccionMime::recortar($anidado, '1.2') === '<p>texto</p>',
    'las partes de un multipart anidado se numeran 1.1, 1.2');
comprobarMime(SeccionMime::recortar($anidado, '1') === null,
    'un contenedor multipart no se recorta: el servidor le cuenta un salto de línea que el RFC no');
comprobarMime(SeccionMime::recortar($anidado, '2') === $pdf, 'y el adjunto que sigue al contenedor sale entero');

// ── Un correo reenviado (message/rfc822) ────────────────────────────────
$reenviado = crlf([
    'Content-Type: multipart/mixed; boundary="EXT"',
    '',
    '--EXT',
    'Content-Type: text/plain',
    '',
    'Te reenvío la factura.',
    '--EXT',
    'Content-Type: message/rfc822',
    '',
    'Subject: la original',
    'Content-Type: multipart/mixed; boundary="INT"',
    '',
    '--INT',
    'Content-Type: text/plain',
    '',
    'original',
    '--INT',
    'Content-Type: text/xml; name="FE_5.xml"',
    'Content-Transfer-Encoding: base64',
    '',
    $xml,
    '--INT--',
    '--EXT--',
]);
comprobarMime(SeccionMime::recortar($reenviado, '2.1') === 'original' && SeccionMime::recortar($reenviado, '2.2') === $xml,
    'dentro de un correo adjunto se sigue por sus partes: 2.1, 2.2');

// ── Lo que no se adivina ────────────────────────────────────────────────
$cortado = crlf([
    'Content-Type: multipart/mixed; boundary="CORTE"',
    '',
    '--CORTE',
    'Content-Type: text/xml; name="FE_6.xml"',
    '',
    $xml,
    '--CORTE',
    'Content-Type: application/pdf; name="FE_6.pdf"',
    '',
    'JVBERi0xLjQK',   // y ahí se acaba: sin delimitador de cierre
]);
comprobarMime(SeccionMime::recortar($cortado, '1') === null, 'un multipart sin cierre: no se sabe, al servidor');

$sinLimite = crlf(['Content-Type: multipart/mixed', '', '--X', '', 'a', '--X--']);
comprobarMime(SeccionMime::recortar($sinLimite, '1') === null, 'un multipart sin boundary: no se sabe');

$relleno = "Content-Type: multipart/mixed; boundary=R\r\n\r\n--R \t\r\nContent-Type: text/xml\r\n\r\n" . $xml . "\r\n--R--  \r\n";
comprobarMime(SeccionMime::recortar($relleno, '1') === $xml, 'espacios después del delimitador (relleno de transporte)');

foreach (['', '0', '1.0', 'a', '1..2', '-1'] as $invalida) {
    comprobarMime(SeccionMime::recortar($comun, $invalida) === null, 'sección inválida "' . $invalida . '": no se sabe');
}
comprobarMime(SeccionMime::recortar('', '1') === null, 'un mensaje vacío: no se sabe');

if ($fallas) {
    foreach ($fallas as $falla) {
        fwrite(STDERR, "FAIL: {$falla}\n");
    }
    exit(1);
}

echo "OK: un adjunto recortado del mensaje entero es el mismo que da el servidor, y ante la duda se le pregunta\n";
