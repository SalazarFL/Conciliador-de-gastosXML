<?php
/**
 * Plantilla de las credenciales de SharePoint (Microsoft Graph) de UNA
 * computadora.
 *
 * Copiar este archivo como `app/config/local.graph.php` y llenarlo:
 *
 *   copy app\config\graph.ejemplo.php app\config\local.graph.php
 *
 * Ese nombre está en .gitignore (app/config/local.*.php) a propósito: aquí va
 * un secreto que abre la biblioteca de documentos, y el repositorio es
 * público. El secreto no se manda por correo ni por chat; se escribe aquí,
 * en la computadora, y en ningún otro lado.
 *
 * Todas las computadoras que usan Nexo Fiscal lo necesitan: los XML y los PDF
 * de todas las empresas viven en SharePoint, y sin este archivo esta
 * computadora no puede ni guardarlos ni abrirlos. Diagnóstico lo dice, y los
 * módulos que trabajan con documentos se cierran hasta que esté.
 *
 * Mientras un valor siga empezando por "PEGA-", el archivo cuenta como vacío.
 *
 * Para comprobar que quedó bien:
 *   php cli/probar_sharepoint.php
 *
 * Cómo se consiguen estos datos: docs/SHAREPOINT.md.
 */

return [
    // Identificador de la organización (Entra ID → Información general).
    'tenant_id'     => 'PEGA-EL-ID-DE-LA-ORGANIZACION',

    // Identificador de la aplicación registrada (Registros de aplicaciones).
    'client_id'     => 'PEGA-EL-ID-DE-LA-APLICACION',

    // El VALOR del secreto de cliente (no su id). Vence: anota cuándo.
    'client_secret' => 'PEGA-EL-SECRETO',

    // La biblioteca de documentos: el id del "drive" (empieza por "b!").
    // Lo da: php cli/probar_sharepoint.php --bibliotecas=https://<empresa>.sharepoint.com/sites/<sitio>
    // (o el administrador, si a la aplicación solo se le dio una carpeta).
    'drive_id'      => 'PEGA-EL-ID-DE-LA-BIBLIOTECA',

    // Si Nexo Fiscal vive en UNA carpeta de una biblioteca que ya se usaba,
    // su ruta dentro de la biblioteca, sin el nombre de la biblioteca: por
    // ejemplo 'Contabilidad/Nexo Fiscal'. Vacío = la biblioteca entera.
    //
    // Es la misma en todas las computadoras y se elige una sola vez: las
    // rutas que guarda la base son relativas a esta carpeta.
    'carpeta'       => '',

    // El id de esa carpeta. Opcional: si falta, se averigua por la ruta. Hace
    // falta cuando el administrador le dio a la aplicación permiso SOLO sobre
    // la carpeta y SharePoint no deja buscarla por su ruta; es el mismo id que
    // él usó para dar el permiso.
    'carpeta_id'    => '',
];
