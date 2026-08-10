<?php
/**
 * Plantilla de la configuración de UNA computadora.
 *
 * Copiar este archivo como `app/config/local.php` y llenarlo. Ese nombre está
 * en .gitignore a propósito: aquí va la contraseña de la base y el repositorio
 * es público.
 *
 * Lo que cambia de una computadora a otra y por eso vive aquí:
 *   - cómo llega esta máquina al servidor de base de datos;
 *   - dónde tiene instaladas sus herramientas.
 *
 * Lo que NO va aquí:
 *   - la carpeta compartida de documentos, que se configura desde la
 *     aplicación (Correo → Carpeta de documentos) y queda en
 *     storage/correo/config.json;
 *   - nada que deba ser igual para todos: eso va en config.php, versionado.
 */

return [
    /**
     * La base de datos es UNA sola, en el servidor, y todas las computadoras
     * escriben en ella. Si aquí pones un MySQL local vas a trabajar sobre una
     * base que nadie más ve.
     */
    'database' => [
        'host'     => '127.0.0.1',
        'port'     => '3306',
        'database' => 'bd_xmlconcilia',
        'username' => 'xmlconcilia',
        'password' => '',
    ],

    /**
     * pdftotext (Poppler), para leer los reportes PDF del ERP.
     * Vacío = buscar 'pdftotext' en el PATH del sistema.
     */
    'pdftotext_path' => '',
];
