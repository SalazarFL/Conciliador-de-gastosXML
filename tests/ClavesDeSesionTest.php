<?php
/**
 * Ninguna pantalla lee una clave de sesión que el login nunca escribe.
 *
 * Es un error que no se ve. `$_SESSION['usuario_id'] ?? null` compila, corre,
 * no avisa de nada y devuelve NULL para siempre, porque el login escribe
 * `user_id` y nadie escribió nunca `usuario_id`. Estuvo así en trece sitios de
 * FacturasErpController y NotasCreditoController: cada incidencia descartada y
 * cada línea incluida en esos dos módulos guardó "lo hizo nadie". El `?? null`
 * es justo lo que lo tapa —sin él habría saltado un aviso el primer día—, y
 * como la columna acepta NULL, la base tampoco se quejó.
 *
 * Lo barato es comprobarlo leyendo el código: se juntan todas las claves que
 * alguien ESCRIBE y todas las que alguien LEE, y la segunda lista no puede
 * tener nada que no esté en la primera.
 *
 * No hace falta base de datos ni sesión: es lectura de archivos.
 *
 * Uso: php tests/ClavesDeSesionTest.php
 */

$raiz = dirname(__DIR__);
$carpetas = ['app', 'cli', 'public'];

$escritas = [];
$leidas = [];   // clave => [archivo:linea, ...]

foreach ($carpetas as $carpeta) {
    $dir = $raiz . DIRECTORY_SEPARATOR . $carpeta;
    if (!is_dir($dir)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $archivo) {
        if ($archivo->getExtension() !== 'php') {
            continue;
        }
        $relativo = str_replace($raiz . DIRECTORY_SEPARATOR, '', $archivo->getPathname());
        $lineas = file($archivo->getPathname());
        if ($lineas === false) {
            continue;
        }
        foreach ($lineas as $n => $linea) {
            if (strpos($linea, '$_SESSION[') === false) {
                continue;
            }

            // Escritura: $_SESSION['x'] = ...  y también la forma anidada
            // $_SESSION['x'][$lo_que_sea] = ..., que es como se guardan los
            // filtros por módulo. El (?!=) descarta las comparaciones == y ===.
            if (preg_match_all(
                '/\$_SESSION\[\'([a-z_]+)\'\](?:\[[^\]]*\])*\s*=(?!=)/i',
                $linea,
                $m
            )) {
                foreach ($m[1] as $clave) {
                    $escritas[$clave] = true;
                }
            }

            // Lectura: cualquier otra aparición.
            if (preg_match_all('/\$_SESSION\[\'([a-z_]+)\'\]/i', $linea, $m)) {
                foreach ($m[1] as $clave) {
                    $leidas[$clave][] = $relativo . ':' . ($n + 1);
                }
            }
        }
    }
}

if (!$escritas || !$leidas) {
    fwrite(STDERR, "FAIL: no se encontró ningún uso de \$_SESSION. ¿Cambió la "
        . "estructura de carpetas o la forma de acceder a la sesión?\n");
    exit(1);
}

$fallas = [];
foreach ($leidas as $clave => $sitios) {
    if (isset($escritas[$clave])) {
        continue;
    }

    // Parecidas, para que el informe diga qué se quiso escribir. Casi siempre
    // es eso: una variante del nombre bueno (usuario_id por user_id).
    $parecidas = [];
    foreach (array_keys($escritas) as $buena) {
        similar_text($clave, $buena, $pct);
        if ($pct >= 60) {
            $parecidas[] = $buena;
        }
    }

    $sitios = array_unique($sitios);
    $muestra = array_slice($sitios, 0, 4);
    if (count($sitios) > 4) {
        $muestra[] = '… y ' . (count($sitios) - 4) . ' más';
    }

    $fallas[] = "\$_SESSION['{$clave}'] se lee en " . count($sitios)
        . ' sitio(s) y no se escribe en ninguno, así que siempre vale NULL.'
        . ($parecidas ? ' ¿Querías ' . implode(' o ', array_map(
            function ($p) { return "'{$p}'"; }, $parecidas)) . '?' : '')
        . "\n         " . implode("\n         ", $muestra);
}

if ($fallas) {
    foreach ($fallas as $f) {
        fwrite(STDERR, "FAIL: {$f}\n");
    }
    exit(1);
}

echo 'OK: ClavesDeSesion (' . count($leidas) . ' claves leídas, todas escritas en algún lado)' . "\n";
exit(0);
