<?php
/**
 * Catalogo de plantillas de parseo PDF por proveedor.
 *
 * Nota:
 * - provider_template_map: cedula => plantilla
 * - provider_alias_templates: plantilla => palabras clave en nombre de proveedor
 * - templates: reglas de extraccion por plantilla
 */

return [
    'default_template' => 'generic_cr',

    'provider_template_map' => [
        '4000042139' => 'cr_ice',
        '4000042138' => 'cr_aya',
        '117190756' => 'cr_johan_centeno',
    ],

    'provider_alias_templates' => [
        'cr_ice' => [
            'INSTITUTO COSTARRICENSE DE ELECTRICIDAD',
        ],
        'cr_aya' => [
            'INSTITUTO COSTARRICENSE DE ACUEDUCTOS Y ALCANTARILLADOS',
            'A Y A',
            'AYA',
        ],
        'cr_johan_centeno' => [
            'JOHAN JOSEPH CENTENO VARGAS',
            'JOHAN CENTENO',
        ],
    ],

    'templates' => [
        'generic_cr' => [
            'enabled' => false,
            'date_patterns' => [],
            'line_labels' => [],
            'heuristic_labels' => [],
            'numero_referencia_priority' => ['documento', 'clave'],
        ],

        'cr_ice' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*(?:de\\s*)?(?:Emision|Facturaci[oó]n)?\\s*[:\\-]?\\s*([0-3]?\\d\\s+(?:ENERO|FEBRERO|MARZO|ABRIL|MAYO|JUNIO|JULIO|AGOSTO|SETIEMBRE|SEPTIEMBRE|OCTUBRE|NOVIEMBRE|DICIEMBRE)\\s+20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d\\s+(?:ENERO|FEBRERO|MARZO|ABRIL|MAYO|JUNIO|JULIO|AGOSTO|SETIEMBRE|SEPTIEMBRE|OCTUBRE|NOVIEMBRE|DICIEMBRE)\\s+20\\d{2})\\b/iu',
            ],
            'line_labels' => [
                'subtotal' => ['Sub\\s*[Tt]otal', 'Subtotal\\s*Exento', 'Subtotal\\s*Gravado', 'Base\\s*Imponible'],
                'iva' => ['Impuesto\\s*de\\s*Valor\\s*Agregado\\s*13\\s*%', 'Impuesto\\s*al\\s*Valor\\s*Agregado\\s*13\\s*%', 'I\\.?\\s*V\\.?\\s*A\\.?', 'Impuesto\\s*de\\s*Venta'],
                'total' => ['Total\\s*Comprobante', 'Total\\s*a\\s*pagar', 'Monto\\s*Total', '(?<![a-zA-Z])Total(?!\\s*a\\s*pagar)(?![a-zA-Z])'],
            ],
            'heuristic_labels' => [
                'subtotal' => ['Sub\\s*[Tt]otal', 'Subtotal\\s*Exento', 'Subtotal\\s*Gravado', 'Base\\s*Imponible'],
                'iva' => ['Impuesto\\s*de\\s*Valor\\s*Agregado\\s*13\\s*%', 'Impuesto\\s*al\\s*Valor\\s*Agregado\\s*13\\s*%', 'I\\.?\\s*V\\.?\\s*A\\.?', 'Impuesto\\s*de\\s*Venta', 'Impuesto\\s*al\\s*Valor\\s*Agregado'],
                'total' => ['Total\\s*Comprobante', 'Total\\s*a\\s*pagar', 'Monto\\s*Total', '(?<![a-zA-Z])Total(?!\\s*a\\s*pagar)(?![a-zA-Z])'],
            ],
            'numero_referencia_priority' => ['documento', 'clave'],
        ],

        'cr_aya' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*(?:de\\s*)?(?:Emision|Facturaci[oó]n)?\\s*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => ['Sub\\s*[Tt]otal', 'Subtotal\\s*Servicios', 'Base\\s*Imponible'],
                'iva' => ['Total\\s*IVA\\s*\\(?13%\\)?', 'Neto\\s*IVA\\s*a\\s*Pagar', 'I\\.?\\s*V\\.?\\s*A\\.?', 'IVA\\s*\\(?13%\\)?', 'Impuesto\\s*al\\s*Valor\\s*Agregado'],
                'total' => ['Total\\s*del\\s*Mes', 'Importe\\s*Cuenta', 'Total\\s*a\\s*pagar', 'Total\\s*Factura', 'Importe\\s*Total', 'Monto\\s*Total', 'Total\\s*Comprobante'],
            ],
            'heuristic_labels' => [
                'subtotal' => ['Sub\\s*[Tt]otal', 'Subtotal\\s*Servicios', 'Base\\s*Imponible'],
                'iva' => ['I\\.?\\s*V\\.?\\s*A\\.?', 'IVA\\s*\\(?13%\\)?', 'Impuesto\\s*al\\s*Valor\\s*Agregado'],
                'total' => ['Total\\s*a\\s*pagar', 'Total\\s*Factura', 'Importe\\s*Total', 'Monto\\s*Total', '(?<![a-zA-Z])Total(?![a-zA-Z])'],
            ],
            'numero_referencia_priority' => ['documento', 'clave'],
        ],

        'cr_johan_centeno' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*(?:de\\s*)?(?:Emision|Facturaci[oó]n)?\\s*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => ['Sub\\s*[Tt]otal', 'Base\\s*Imponible', 'Total\\s*Ventas\\s*Gravadas'],
                'iva' => ['Total\\s*IVA\\s*\\(?13%\\)?', 'Impuesto\\s*\\(?13%\\)?', 'I\\.?\\s*V\\.?\\s*A\\.?', 'IVA'],
                'total' => ['Total\\s*Comprobante', 'Total\\s*Factura', 'Total\\s*a\\s*pagar', 'Monto\\s*Total', 'Importe\\s*Total'],
            ],
            'heuristic_labels' => [
                'subtotal' => ['Sub\\s*[Tt]otal', 'Base\\s*Imponible', 'Total\\s*Ventas\\s*Gravadas'],
                'iva' => ['Total\\s*IVA\\s*\\(?13%\\)?', 'Impuesto\\s*\\(?13%\\)?', 'I\\.?\\s*V\\.?\\s*A\\.?', 'IVA'],
                'total' => ['Total\\s*Comprobante', 'Total\\s*Factura', 'Total\\s*a\\s*pagar', 'Monto\\s*Total', 'Importe\\s*Total'],
            ],
            'numero_referencia_priority' => ['documento', 'clave'],
        ],
    ],
];
