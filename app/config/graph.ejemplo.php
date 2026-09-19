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
 * Solo lo necesitan las computadoras que trabajan con empresas que archivan
 * en SharePoint. Sin este archivo, esas empresas no se pueden abrir desde esta
 * computadora y Diagnóstico lo dice; las que archivan en disco funcionan igual.
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
    'drive_id'      => 'PEGA-EL-ID-DE-LA-BIBLIOTECA',
];
