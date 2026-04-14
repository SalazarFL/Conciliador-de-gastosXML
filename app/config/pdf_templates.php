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
        4000042139 => 'cr_ice',
        4000042138 => 'cr_aya',
        117190756 => 'cr_johan_centeno',
        3102947091 => 'cr_tribu_cr',
        3101136572 => 'cr_bct_arrendadora',
        3101610198 => 'cr_liberty_telecomunicaciones',
        3101589892 => 'cr_arkcon',
        112980808 => 'cr_cesar_cerdas_monge',
        3101088889 => 'cr_rio_java',
        3101844376 => 'cr_grupo_gmw_orientales',
        3101915419 => 'cr_ortiz_palacios',
        3101007583 => 'cr_inarsa',
        3101204902 => 'cr_leaho',
        118160139 => 'cr_josue_granados_sibaja',
        3101184143 => 'cr_servicentro_coto_brus',
        601860470 => 'cr_ernestino_vega_sanchez',
        304430828 => 'cr_catherine_bonilla',
        604410264 => 'cr_gian_carlos_alvarez_cordero',
        604230766 => 'cr_andrea_molina',
        603840380 => 'cr_pamela_leon_cascante',
        114740545 => 'cr_mario_dario_cordero_camacho',
        3101746783 => 'cr_gaman_core',
        3101718276 => 'cr_avs_solutions',
        3101409261 => 'cr_ncq_solutions',
        602180425 => 'cr_walter_granados_bermudez',
        3101523233 => 'cr_innova_desarrollos_mobiliarios',
        3101058779 => 'cr_distribuidora_valbag',
        3101104186 => 'cr_servicentro_paso_real',
        3102946360 => 'cr_bakery_dominical',
        114200360 => 'cr_marvin_arroyo_mena',
        603460268 => 'cr_glendy_vargas_jimenez',
        3101660388 => 'cr_bm_del_general',
        3101697873 => 'cr_bm_quepos',
        3101560680 => 'cr_bm_uvita',
        602870384 => 'cr_freddy_altamirano_uva',
        3101328816 => 'cr_diunytra',
        3101722251 => 'cr_inversiones_petueles',
        3101082969 => 'cr_almacenes_el_colono',
        3101690353 => 'cr_gs_la_colonia',
        3101833216 => 'cr_jonday',
        3101737329 => 'cr_grupo_majaic',
        3102787614 => 'cr_sabores_coto_brus',
        3102889446 => 'cr_compania_carragonza',
        603270237 => 'cr_aracelly_charpantier_barquero',
        115290180 => 'cr_maria_jose_fonseca_sibaja',
        601720676 => 'cr_graciela_mesen_salas',
        3102568465 => 'cr_finca_los_manglares_uvita',
        3101648332 => 'cr_surbm',
        3101691397 => 'cr_arrendadora_bm_pz',
        3101663226 => 'cr_bm_del_palmar',
        3101393600 => 'cr_comercial_corcovado_del_sur',
        115600082828 => 'cr_fan_zhongwei',
        602690945 => 'cr_ronald_enrique_murillo_soto',
        118970006 => 'cr_brittany_alejandra_godinez_cascante',
        3101137736 => 'cr_roma_del_sur',
        3102781846 => 'cr_corporacion_alfaro_mano_de_dios',
        3101182387 => 'cr_inversiones_idaly_arias',
        112720557 => 'cr_cindy_retana_mora',
        3102010554 => 'cr_tractoreos_ariete',
        604890933 => 'cr_nathaly_sofia_rojas_guerra',
        116210430 => 'cr_braulio_david_monge_mata',
        111490719 => 'cr_jorge_rodriguez_gamboa',
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
        'cr_tribu_cr' => [
            'TRIBU-CR',
            '3-102-947091 SOCIEDAD DE RESPONSABILIDAD LIMITADA',
            '3102947091 SOCIEDAD DE RESPONSABILIDAD LIMITADA',
        ],
        'cr_bct_arrendadora' => [
            'BCT ARRENDADORA S.A.',
            'BCT ARRENDADORA SA',
            'BCT ARRENDADORA',
            'BCT ARRENDADORA SOCIEDAD ANONIMA',
        ],
        'cr_liberty_telecomunicaciones' => [
            'LIBERTY TELECOMUNICACIONES DE COSTA RICA LY S.A.',
            'LIBERTY TELECOMUNICACIONES DE COSTA RICA LY SA',
            'LIBERTY TELECOMUNICACIONES DE COSTA RICA',
            'LIBERTY TELECOMUNICACIONES DE COSTA RICA LY SOCIEDAD ANONIMA',
        ],
        'cr_arkcon' => [
            'ARKCON DE COSTA RICA S.A.',
            'ARKCON DE COSTA RICA, S.A.',
            'ARKCON DE COSTA RICA',
        ],
        'cr_cesar_cerdas_monge' => [
            'CESAR RODOLFO CERDAS MONGE',
            'PERIFONEO Y SONIDO DJ CESAR',
            'PERIFONEO Y SONIDO DJ CÉSAR',
        ],
        'cr_rio_java' => [
            'COMPANIA RIO JAVA S.A.',
            'RIO JAVA',
        ],
        'cr_grupo_gmw_orientales' => [
            'GRUPO G M W ORIENTALES SOCIEDAD ANONIMA',
            'GRUPO G M W ORIENTALES',
            'GRUPO GMW ORIENTALES',
        ],
        'cr_ortiz_palacios' => [
            'ORTIZ PALACIOS REFRIGERACION INDUSTRIAL SOCIEDAD ANONIMA',
            'ORTIZ PALACIOS REFRIGERACION INDUSTRIAL',
        ],
        'cr_inarsa' => [
            'INVERSIONES INARSA S.A.',
            'INVERSIONES INARSA SA',
            'INARSA',
        ],
        'cr_leaho' => [
            'LEAHO REFRIGERACION INDUSTRIAL SOCIEDAD ANONIMA',
            'LEAHO REFRIGERACION INDUSTRIAL',
        ],
        'cr_josue_granados_sibaja' => [
            'GRANADOS SIBAJA DAVID JOSUE',
            'DAVID GRANADOS',
            'JOSUE GRANADOS SIBAJA',
        ],
        'cr_servicentro_coto_brus' => [
            'SERVICENTRO COTO BRUS',
            'SERVICENTRO COTO BRUS SOCIEDAD ANONIMA',
        ],
        'cr_ernestino_vega_sanchez' => [
            'ERNESTINO VEGA SANCHEZ',
            'TALLER DE RADIO Y TELEVISION VEGA',
            'TALLER DE RADIO Y TELEVISIÓN VEGA',
        ],
        'cr_catherine_bonilla' => [
            'CATHERIN LUPITA BONILLA AGUILAR',
            'CATHERINE LUPITA BONILLA AGUILAR',
            'CATHERINE BONILLA',
        ],
        'cr_gian_carlos_alvarez_cordero' => [
            'ALVAREZ CORDERO GIAN CARLOS',
            'GIAN CARLOS ALVAREZ CORDERO',
            'GIAN CARLOS ALVAREZ',
            'IMPRESIONARTE',
        ],
        'cr_andrea_molina' => [
            'ANDREA MARIA MOLINA SEGURA',
            'ANDREA MOLINA',
            'BUFETE AMS',
        ],
        'cr_pamela_leon_cascante' => [
            'PAMELA PATRICIA LEON CASCANTE',
            'PAMELA LEON CASCANTE',
            'PAMELA LEON',
            'FAOL EVENTOS',
        ],
        'cr_mario_dario_cordero_camacho' => [
            'MARIO DARIO CORDERO CAMACHO',
            'PZ NOTICIAS',
        ],
        'cr_gaman_core' => [
            'GAMAN CORE SOCIEDAD ANONIMA',
            'GAMAN CORE S.A.',
            'GAMAN CORE',
            'GAMAN',
        ],
        'cr_avs_solutions' => [
            'AVS SOLUTIONS SOCIEDAD ANONIMA',
            'AVS SOLUTIONS',
            'AVSCR',
        ],
        'cr_ncq_solutions' => [
            'NCQ SOLUTIONS S.A.',
            'NCQ SOLUTIONS SA',
            'NCQ SOLUTIONS',
            'NCQ',
        ],
        'cr_walter_granados_bermudez' => [
            'WALTER DE JESUS GRANADOS BERMUDEZ',
            'WALTER GRANADOS BERMUDEZ',
            'WALTER GRANADOS BRERMUDEZ',
            'WALTER GRANADOS',
        ],
        'cr_innova_desarrollos_mobiliarios' => [
            'INNOVA DESAROLLOS Y MOBILIARIOS S.A.',
            'INNOVA DESARROLLOS Y MOBILIARIOS S.A.',
            'INNOVA DESARROLLOS Y MOBILIARIOS SOCIEDAD ANONIMA',
            'INNOVA DESARROLLOS',
        ],
        'cr_distribuidora_valbag' => [
            'DISTRIBUIDORA VALBAG S.A.',
            'DISTRIBUIDORA VALBAG SOCIEDAD ANONIMA',
            'DIST VALBAG',
            'VALBAG',
        ],
        'cr_servicentro_paso_real' => [
            'SERVICENTRO PASO REAL S.A.',
            'SERVICENTRO PASO REAL SOCIEDAD ANONIMA',
            'SERVICENTRO PASO REAL',
        ],
        'cr_bakery_dominical' => [
            '3-102-946360 SOCIEDAD DE RESPONSABILIDAD LIMITADA',
            'THE BAKERY CR- DOMINICAL',
            'THE BAKERY CR',
        ],
        'cr_marvin_arroyo_mena' => [
            'MARVIN ARROYO MENA',
            'SERVICIOS ELECTRO ARROYO',
        ],
        'cr_glendy_vargas_jimenez' => [
            'GLENDY JOHANA VARGAS JIMENEZ',
            'PANADERIA FLOR',
            'PANADERIA FLOR - GLENDY JOHANA VARGAS JIMENEZ',
        ],
        'cr_bm_del_general' => [
            'BM. DEL GENERAL SOCIEDAD ANONIMA',
            'BM DEL GENERAL S.A.',
            'BM DEL GENERAL',
        ],
        'cr_bm_quepos' => [
            'BMQUEPOS SOCIEDAD ANONIMA',
            'BMQUEPOS S.A.',
            'BMQUEPOS',
        ],
        'cr_bm_uvita' => [
            'BM DE UVITA SOCIEDAD ANONIMA',
            'BM DE UVITA S.A.',
            'BM DE UVITA',
        ],
        'cr_freddy_altamirano_uva' => [
            'FREDDY ALTAMIRANO UVA',
            'RESTAURANTE CHABELLAS',
        ],
        'cr_diunytra' => [
            'DIUNYTRA SOCIEDAD ANONIMA',
            'DIUNYTRA S.A.',
            'DIUNYTRA',
        ],
        'cr_esmeralda_tijerino_caballero' => [
            'ESMERALDA TIJERINO CABALLERO',
        ],
        'cr_yurlin_antonio_chaves_delgado' => [
            'YURLIN ANTONIO CHAVES DELGADO',
        ],
        'cr_norma_gerardina_ceciliano' => [
            'NORMA GERARDINA CECILIANO VALV',
            'NORMA GERARDINA CECILIANO',
        ],
        'cr_natalia_ramirez_montero' => [
            'NATALIA RAMIREZ MONTERO',
        ],
        'cr_paula_sibaja_leon' => [
            'PAULA SIBAJA LEON',
        ],
        'cr_murcia_diaz_ana_joulin' => [
            'MURCIA DÍAZ ANA JOULIN',
            'MURCIA DIAZ ANA JOULIN',
            'ANA JOULIN MURCIA DIAZ',
        ],
        'cr_jose_pablo_jimenez_chavarria' => [
            'JOSE PABLO JIMENEZ CHAVARRIA',
        ],
        'cr_annys_floristeria_detalles' => [
            'ANNYS FLORISTERIA Y DETALLES S',
            'ANNYS FLORISTERIA Y DETALLES',
            'ANNYS FLORISTERIA',
        ],
        'cr_josue_david_mora_fonseca' => [
            'JOSUE DAVID MORA FONSECA',
            'JOSEU DAVID MORA FONSECA',
        ],
        'cr_inversiones_petueles' => [
            'INVERSIONES PETUELES SOCIEDAD ANONIMA',
            'SERVICENTRO LAS MERCEDES',
            'INVERSIONES PETUELES',
        ],
        'cr_almacenes_el_colono' => [
            'ALMACENES EL COLONO SOCIEDAD ANONIMA',
            'ALMACENES EL COLONO S.A',
            'COLONO PZ',
        ],
        'cr_gs_la_colonia' => [
            'GS LA COLONIA SOCIEDAD ANONIMA',
            'ESTACION DE SERVICIO GS LA COLONIA S.A.',
            'ESTACION DE SERVICIO GS LA COLONIA',
        ],
        'cr_jonday' => [
            'JON&DAY SOCIEDAD ANONIMA',
            'JONDAY SOCIEDAD ANONIMA',
            'AROMA A CAFE',
        ],
        'cr_grupo_majaic' => [
            'GRUPO MAJAIC CR SOCIEDAD ANONIMA',
            'GRUPO MAJAIC C.R. S.A.',
            'MOCCA-CHEERS',
        ],
        'cr_sabores_coto_brus' => [
            'SABORES COTO BRUS LIMITADA',
            'SABORES COTO BRUS LTDA',
            'SABORES COTO BRUS',
            'RIVIERA SUBS',
        ],
        'cr_compania_carragonza' => [
            'COMPAÑIA CARRAGONZA LIMITADA',
            'COMPANIA CARRAGONZA LIMITADA',
            'CARRAGONZA LIMITADA',
            'CABRITO\'S COFFEE',
            'CABRITOS COFFEE',
        ],
        'cr_aracelly_charpantier_barquero' => [
            'ARACELLY CHARPANTIER BARQUERO',
            'ARACELLY CHARPANTIER',
            'SODA LA COLOCHA',
        ],
        'cr_maria_jose_fonseca_sibaja' => [
            'MARIA JOSE FONSECA SIBAJA',
            'CAFÉ COSECHAS',
            'CAFE COSECHAS',
        ],
        'cr_graciela_mesen_salas' => [
            'GRACIELA MESEN SALAS',
            'HOTEL HOJA DE ORO CORCOVADO',
            'HOTEL HOJA DE ORO',
        ],
        'cr_finca_los_manglares_uvita' => [
            'FINCA LOS MANGLARES DE UVITA SOCIEDAD DE RESPONSABILIDAD LIMITADA',
            'FINCA LOS MANGLARES DE UVITA SRL',
            'FINCA LOS MANGLARES',
            'RESTAURANTE MARINO BALLENA',
        ],
        'cr_surbm' => [
            'SURBM SOCIEDAD ANONIMA',
            'SURBM S.A.',
            'SURBM',
            'SUR BM',
        ],
        'cr_arrendadora_bm_pz' => [
            'ARRENDADORA BM PZ SOCIEDAD ANONIMA',
            'ARRENDADORA BM PZ SA',
            'ARRENDADORA BM PZ',
            'RIO CLARO',
            'BM RIO CLARO',
        ],
        'cr_bm_del_palmar' => [
            'B.M. DEL PALMAR SOCIEDAD ANONIMA',
            'BM DEL PALMAR S.A.',
            'BM DEL PALMAR',
            'PALMAR',
            'BM PALMAR',
        ],
        'cr_comercial_corcovado_del_sur' => [
            'COMERCIAL CORCOVADO DEL SUR SOCIEDAD ANONIMA',
            'COMERCIAL CORCOVADO DEL SUR S.A.',
            'COMERCIAL CORCOVADO DEL SUR',
            'CORCOVADO DEL SUR',
        ],
        'cr_fan_zhongwei' => [
            'FAN ZHONGWEI',
            'ZHONGWEI FAN',
            'RESTAURANT DELFIN BLANCO',
            'RESTAURANTE DELFIN BLANCO',
        ],
        'cr_ronald_enrique_murillo_soto' => [
            'RONALD ENRIQUE MURILLO SOTO',
            'RAPIPOLLO',
        ],
        'cr_brittany_alejandra_godinez_cascante' => [
            'BRITTANY ALEJANDRA GODINEZ CASCANTE',
            'POLLO Y PIZZA HEI HEI',
            'HEI HEI',
        ],
        'cr_roma_del_sur' => [
            'ROMA DEL SUR SOCIEDAD ANONIMA',
            'ROMA DEL SUR S.A',
            'ROMA DEL SUR S.A.',
            'ROMA DEL SUR',
            'LIBRERIA EL ESTUDIANTE',
        ],
        'cr_corporacion_alfaro_mano_de_dios' => [
            'CORPORACION ALFARO DE LA MANO DE DIOS SOCIEDAD DE RESPONSABILIDAD LIMITADA',
            'CORPORACION ALFARO DE LA MANO DE DIOS',
            'RESTAURANTE RICAR2',
            'RICAR2',
        ],
        'cr_inversiones_idaly_arias' => [
            'INVERSIONES IDALY ARIAS SOCIEDAD ANONIMA',
            'INVERSIONES IDALY ARIAS SA',
            'INVERSIONES IDALY',
        ],
        'cr_cindy_retana_mora' => [
            'CINDY MARIA RETANA MORA',
            'CINDY RETANA MORA',
            'RESTAURANTE Y HELADERIA Q RIKO',
            'Q RIKO',
        ],
        'cr_tractoreos_ariete' => [
            'TRACTOREOS ARIETE SOCIEDAD DE RESPONSABILIDAD LIMITADA',
            'PIZZERIA LILIANA',
            'TRACTOREOS ARIETE SRL',
        ],
        'cr_nathaly_sofia_rojas_guerra' => [
            'NATHALY SOFIA ROJAS GUERRA',
            'NATHALY',
        ],
        'cr_braulio_david_monge_mata' => [
            'BRAULIO DAVID MONGE MATA',
            'DELIPOLLO',
        ],
        'cr_jorge_rodriguez_gamboa' => [
            'JORGE RODRIGUEZ GAMBOA',
            'REPUESTOS DE EQUIPO PESADO CAJON',
        ],
    ],
    'templates' => [
        'generic_cr' => [
            'enabled' => false,
            'date_patterns' => [],
            'line_labels' => [],
            'heuristic_labels' => [],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_ice' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*y\\s*Hora\\s*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\bFecha\\s*(?:de\\s*)?(?:Emisi[oó]n|Facturaci[oó]n)?\\s*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\bFecha\\s*(?:de\\s*)?(?:Emision|Facturaci[oó]n)?\\s*[:\\-]?\\s*([0-3]?\\d\\s+(?:ENERO|FEBRERO|MARZO|ABRIL|MAYO|JUNIO|JULIO|AGOSTO|SETIEMBRE|SEPTIEMBRE|OCTUBRE|NOVIEMBRE|DICIEMBRE)\\s+20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d\\s+(?:ENERO|FEBRERO|MARZO|ABRIL|MAYO|JUNIO|JULIO|AGOSTO|SETIEMBRE|SEPTIEMBRE|OCTUBRE|NOVIEMBRE|DICIEMBRE)\\s+20\\d{2})\\b/iu',
            ],
            'line_labels' => [
                'subtotal' => [
                    'Total\\s*venta\\s*neta',
                    'Total\\s*gravado',
                    'Subtotal\\s*Exento',
                    'Subtotal\\s*Gravado',
                    'Subtotal\\s*Neto',
                    'Base\\s*Imponible',
                ],
                'iva' => [
                    'Total\\s*impuestos',
                    'Total\\s*IVA\\b',
                    'Impuesto\\s*de\\s*Valor\\s*Agregado\\s*13\\s*%',
                    'Impuesto\\s*al\\s*Valor\\s*Agregado\\s*13\\s*%',
                    'I\\.?\\s*V\\.?\\s*A\\.?',
                    'Impuesto\\s*de\\s*Venta',
                ],
                'total' => [
                    'Importe\\s*Total\\s*a\\s*Pagar',
                    'Importe\\s*Total\\s*Facturado',
                    'Total\\s*Comprobante',
                    'Total\\s*a\\s*pagar',
                    'Monto\\s*Total',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    'Total\\s*venta\\s*neta',
                    'Total\\s*gravado',
                    'Subtotal\\s*Exento',
                    'Subtotal\\s*Gravado',
                    'Subtotal\\s*Neto',
                    'Base\\s*Imponible',
                    'Total\\s*servicios\\s*gravados',
                ],
                'iva' => [
                    'Total\\s*impuestos',
                    'Total\\s*IVA\\b',
                    'Impuesto\\s*de\\s*Valor\\s*Agregado\\s*13\\s*%',
                    'Impuesto\\s*al\\s*Valor\\s*Agregado\\s*13\\s*%',
                    'I\\.?\\s*V\\.?\\s*A\\.?',
                    'Impuesto\\s*de\\s*Venta',
                    'Impuesto\\s*al\\s*Valor\\s*Agregado',
                ],
                'total' => [
                    'Importe\\s*Total\\s*a\\s*Pagar',
                    'Importe\\s*Total\\s*Facturado',
                    'Total\\s*Comprobante',
                    'Total\\s*a\\s*pagar',
                    'Monto\\s*Total',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_aya' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*(?:de\\s*)?(?:Emision|Facturaci[oó]n)?\\s*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    'Sub\\s*[Tt]otal',
                    'Subtotal\\s*Servicios',
                    'Base\\s*Imponible',
                ],
                'iva' => [
                    'Total\\s*IVA\\s*\\(?13%\\)?',
                    'Neto\\s*IVA\\s*a\\s*Pagar',
                    'I\\.?\\s*V\\.?\\s*A\\.?',
                    'IVA\\s*\\(?13%\\)?',
                    'Impuesto\\s*al\\s*Valor\\s*Agregado',
                ],
                'total' => [
                    'Total\\s*del\\s*Mes',
                    'Importe\\s*Cuenta',
                    'Total\\s*a\\s*pagar',
                    'Total\\s*Factura',
                    'Importe\\s*Total',
                    'Monto\\s*Total',
                    'Total\\s*Comprobante',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    'Sub\\s*[Tt]otal',
                    'Subtotal\\s*Servicios',
                    'Base\\s*Imponible',
                ],
                'iva' => [
                    'I\\.?\\s*V\\.?\\s*A\\.?',
                    'IVA\\s*\\(?13%\\)?',
                    'Impuesto\\s*al\\s*Valor\\s*Agregado',
                ],
                'total' => [
                    'Total\\s*a\\s*pagar',
                    'Total\\s*Factura',
                    'Importe\\s*Total',
                    'Monto\\s*Total',
                    '(?<![a-zA-Z])Total(?![a-zA-Z])',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_johan_centeno' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*(?:de\\s*)?(?:Emision|Facturaci[oó]n)?\\s*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    'Sub\\s*[Tt]otal',
                    'Base\\s*Imponible',
                    'Total\\s*Ventas\\s*Gravadas',
                ],
                'iva' => [
                    'Total\\s*IVA\\s*\\(?13%\\)?',
                    'Impuesto\\s*\\(?13%\\)?',
                    'I\\.?\\s*V\\.?\\s*A\\.?',
                    'IVA',
                ],
                'total' => [
                    'Total\\s*Comprobante',
                    'Total\\s*Factura',
                    'Total\\s*a\\s*pagar',
                    'Monto\\s*Total',
                    'Importe\\s*Total',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    'Sub\\s*[Tt]otal',
                    'Base\\s*Imponible',
                    'Total\\s*Ventas\\s*Gravadas',
                ],
                'iva' => [
                    'Total\\s*IVA\\s*\\(?13%\\)?',
                    'Impuesto\\s*\\(?13%\\)?',
                    'I\\.?\\s*V\\.?\\s*A\\.?',
                    'IVA',
                ],
                'total' => [
                    'Total\\s*Comprobante',
                    'Total\\s*Factura',
                    'Total\\s*a\\s*pagar',
                    'Monto\\s*Total',
                    'Importe\\s*Total',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_tribu_cr' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    'Total\\s*venta\\s*neta',
                    'Total\\s*gravado',
                    'Total\\s*venta\\b',
                    'Total\\s*servicios\\s*gravados',
                ],
                'iva' => [
                    'Total\\s*impuestos',
                    'Total\\s*IVA\\b',
                    'I\\.?\\s*V\\.?\\s*A\\.?',
                ],
                'total' => [
                    'Total\\s*comprobante',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    'Total\\s*venta\\s*neta',
                    'Total\\s*gravado',
                    'Total\\s*venta\\b',
                    'Total\\s*servicios\\s*gravados',
                ],
                'iva' => [
                    'Total\\s*impuestos',
                    'Total\\s*IVA\\b',
                    'I\\.?\\s*V\\.?\\s*A\\.?',
                ],
                'total' => [
                    'Total\\s*comprobante',
                ],
            ],
            'numero_referencia_priority' => [
                'clave',
                'documento',
            ],
        ],
        'cr_bct_arrendadora' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*de\\s*Emisi[oó]n\\s*[:\\-]?\\s*([0-3]?\\d[\\.\\/\\-][01]?\\d[\\.\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\.\\/\\-][01]?\\d[\\.\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    'Sub\\s*Total\\s*Neto',
                    'Subtotal\\s*Neto',
                ],
                'iva' => [
                    '^IVA\\b',
                    'Total\\s*IVA\\b',
                ],
                'total' => [
                    'Total\\s*Factura',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    'Sub\\s*Total\\s*Neto',
                    'Subtotal\\s*Neto',
                ],
                'iva' => [
                    '\\bIVA\\b',
                    'Total\\s*IVA\\b',
                ],
                'total' => [
                    'Total\\s*Factura',
                ],
            ],
            'numero_referencia_priority' => [
                'clave',
                'documento',
            ],
        ],
        'cr_liberty_telecomunicaciones' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*emisi[oó]n\\s*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    'Subtotal\\s*facturado\\s*antes\\s*de\\s*impuestos',
                ],
                'iva' => [
                    'Impuesto\\s*valor\\s*agregado\\s*\\(IVA\\)',
                    '^I\\.?\\s*V\\.?\\s*A\\.?\\s*:',
                ],
                'total' => [
                    'Total\\s*a\\s*pagar',
                    'Total\\s*facturaci[oó]n\\s*mes\\s*actual',
                    '^\\bTotal\\s*:',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    'Subtotal\\s*facturado\\s*antes\\s*de\\s*impuestos',
                    '^Subtotal\\s*:',
                ],
                'iva' => [
                    'Impuesto\\s*valor\\s*agregado\\s*\\(IVA\\)',
                    '\\bI\\.?\\s*V\\.?\\s*A\\.?\\s*:',
                ],
                'total' => [
                    'Total\\s*a\\s*pagar',
                    'Total\\s*facturaci[oó]n\\s*mes\\s*actual',
                    '\\bTotal\\s*:',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_arkcon' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*de\\s*Emisi[oó]n\\s*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    'Subtotal\\s*Neto',
                ],
                'iva' => [
                    'Total\\s*IVA\\b',
                    'I\\.?\\s*V\\.?\\s*A\\.?',
                ],
                'total' => [
                    'Total\\s*Factura',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    'Subtotal\\s*Neto',
                ],
                'iva' => [
                    'Total\\s*IVA\\b',
                    '\\bIVA\\b',
                ],
                'total' => [
                    'Total\\s*Factura',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_cesar_cerdas_monge' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFECHA\\s*EMISI[^\\n\\r:]*[:\\-]?\\s*(20\\d{2}[\\/\\-][01]?\\d[\\/\\-][0-3]?\\d)(?=[T\\s]|\\b)/iu',
                '/\\bFecha\\s*emisi[^\\n\\r:]*[:\\-]?\\s*(20\\d{2}[\\/\\-][01]?\\d[\\/\\-][0-3]?\\d)(?=[T\\s]|\\b)/iu',
                '/\\b(20\\d{2}[\\/\\-][01]?\\d[\\/\\-][0-3]?\\d)(?=[T\\s]|\\b)/u',
                '/\\bFECHA\\s*EMISI[oó]N\\s*[:\\-]?\\s*(20\\d{2}[\\/\\-][01]?\\d[\\/\\-][0-3]?\\d)\\b/iu',
                '/\\bFecha\\s*emisi[oó]n\\s*[:\\-]?\\s*(20\\d{2}[\\/\\-][01]?\\d[\\/\\-][0-3]?\\d)\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
                '/\\b(20\\d{2}[\\/\\-][01]?\\d[\\/\\-][0-3]?\\d)\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    '^Subtotal\\s*:',
                ],
                'iva' => [
                    '^Impuesto\\s*:',
                ],
                'total' => [
                    '^\\bTotal\\s*:',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    '\\bSubtotal\\s*:',
                ],
                'iva' => [
                    '\\bImpuesto\\s*:',
                ],
                'total' => [
                    '\\bTotal\\s*:',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_servicentro_coto_brus' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\bFe\\.\\s*emisi[^\\n\\r:]*[:\\-]?\\s*(20\\d{2}[\\/\\-][01]?\\d[\\/\\-][0-3]?\\d)\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
                '/\\b(20\\d{2}[\\/\\-][01]?\\d[\\/\\-][0-3]?\\d)\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    'Sub\\s*-?\\s*Total',
                    'Subtotal',
                ],
                'iva' => [
                    'Total\\s*IVA\\b',
                    'I\\.?\\s*V\\.?\\s*A\\.?',
                ],
                'total' => [
                    '^Total\\b(?!\\s*IVA)',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    'Sub\\s*-?\\s*Total',
                    'Subtotal',
                ],
                'iva' => [
                    'Total\\s*IVA\\b',
                    'I\\.?\\s*V\\.?\\s*A\\.?',
                ],
                'total' => [
                    '^Total\\b(?!\\s*IVA)',
                    '\\bTotal\\b(?!\\s*IVA)',
                ],
            ],
            'numero_referencia_priority' => [
                'clave',
                'documento',
            ],
        ],
        'cr_ernestino_vega_sanchez' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    'SUB\\s*-\\s*TOTAL',
                    'SUB-?TOTAL',
                ],
                'iva' => [
                    'IVA\\s*13%',
                ],
                'total' => [
                    'TOTAL\\s*CRC',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    'SUB\\s*-\\s*TOTAL',
                    'SUB-?TOTAL',
                ],
                'iva' => [
                    'IVA\\s*13%',
                    '\\bIVA\\b',
                ],
                'total' => [
                    'TOTAL\\s*CRC',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_rio_java' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFe\\.\\s*emisi[^\\n\\r:]*[:\\-]?\\s*(20\\d{2}[\\/\\-][01]?\\d[\\/\\-][0-3]?\\d)\\b/iu',
                '/\\bFecha\\s*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b(20\\d{2}[\\/\\-][01]?\\d[\\/\\-][0-3]?\\d)\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    'Sub\\s*-?\\s*Total',
                    'Subtotal',
                ],
                'iva' => [
                    'Total\\s*IVA\\b',
                    'I\\.?\\s*V\\.?\\s*A\\.?',
                ],
                'total' => [
                    '^Total\\b(?!\\s*IVA)',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    'Sub\\s*-?\\s*Total',
                    'Subtotal',
                ],
                'iva' => [
                    'Total\\s*IVA\\b',
                    'I\\.?\\s*V\\.?\\s*A\\.?',
                ],
                'total' => [
                    '^Total\\b(?!\\s*IVA)',
                    '\\bTotal\\b(?!\\s*IVA)',
                ],
            ],
            'numero_referencia_priority' => [
                'clave',
                'documento',
            ],
        ],
        'cr_grupo_gmw_orientales' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*de\\s*emisi[^\\n\\r:]*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    'Total\\s*Venta\\s*Neta',
                    'Total\\s*Gravado',
                ],
                'iva' => [
                    'Total\\s*Impuestos',
                    'IVA\\s*13%',
                ],
                'total' => [
                    'Total\\s*Comprobante',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    'Total\\s*Venta\\s*Neta',
                    'Total\\s*Gravado',
                    'Subtotal\\s*venta',
                ],
                'iva' => [
                    'Total\\s*Impuestos',
                    'IVA\\s*13%',
                ],
                'total' => [
                    'Total\\s*Comprobante',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_ortiz_palacios' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*[:\\-]?\\s*((?:ENERO|FEBRERO|MARZO|ABRIL|MAYO|JUNIO|JULIO|AGOSTO|SETIEMBRE|SEPTIEMBRE|OCTUBRE|NOVIEMBRE|DICIEMBRE)\\s+[0-3]?\\d,\\s+20\\d{2})\\b/iu',
                '/\\bFecha\\s*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    'Total\\s*Venta\\s*Neta',
                    'Total\\s*Gravado',
                    'Total\\s*Servicios\\s*Gravados',
                ],
                'iva' => [
                    'Total\\s*Impuestos',
                ],
                'total' => [
                    'TOTAL\\s*COMPROBANTE',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    'Total\\s*Venta\\s*Neta',
                    'Total\\s*Gravado',
                    'Total\\s*Servicios\\s*Gravados',
                ],
                'iva' => [
                    'Total\\s*Impuestos',
                ],
                'total' => [
                    'TOTAL\\s*COMPROBANTE',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_inarsa' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    'Total\\s*venta\\s*neta',
                    'Subtotal\\s*venta',
                    'Total\\s*gravado',
                ],
                'iva' => [
                    'Total\\s*impuestos',
                    '\\bIVA\\b',
                ],
                'total' => [
                    '^Total\\s*:',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    'Total\\s*venta\\s*neta',
                    'Subtotal\\s*venta',
                    'Total\\s*gravado',
                ],
                'iva' => [
                    'Total\\s*impuestos',
                    '\\bIVA\\b',
                ],
                'total' => [
                    '^Total\\s*:',
                    'Total\\s*:',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_leaho' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    'Importe\\s*base',
                ],
                'iva' => [
                    'IVA\\s*13%',
                ],
                'total' => [
                    '^Total\\b',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    'Importe\\s*base',
                ],
                'iva' => [
                    'IVA\\s*13%',
                ],
                'total' => [
                    '^Total\\b',
                    '\\bTotal\\b',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_josue_granados_sibaja' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    'Total\\s*venta\\s*neta',
                    'Total\\s*gravado',
                    'Total\\s*venta\\b',
                    'Total\\s*mercanc[^\\n\\r]*gravadas',
                ],
                'iva' => [
                    'Total\\s*impuestos',
                    'Total\\s*IVA\\b',
                    'I\\.?\\s*V\\.?\\s*A\\.?',
                ],
                'total' => [
                    'Total\\s*comprobante',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    'Total\\s*venta\\s*neta',
                    'Total\\s*gravado',
                    'Total\\s*venta\\b',
                    'Total\\s*mercanc[^\\n\\r]*gravadas',
                ],
                'iva' => [
                    'Total\\s*impuestos',
                    'Total\\s*IVA\\b',
                    'I\\.?\\s*V\\.?\\s*A\\.?',
                ],
                'total' => [
                    'Total\\s*comprobante',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_catherine_bonilla' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*de\\s*emisi[^\\n\\r:]*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    'Total\\s*Venta\\s*Neta',
                    'Total\\s*Gravado',
                ],
                'iva' => [
                    'Total\\s*Impuestos',
                    'IVA\\s*13%',
                ],
                'total' => [
                    'Total\\s*Comprobante',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    'Total\\s*Venta\\s*Neta',
                    'Total\\s*Gravado',
                    'Total\\s*Venta',
                ],
                'iva' => [
                    'Total\\s*Impuestos',
                    'IVA\\s*13%',
                ],
                'total' => [
                    'Total\\s*Comprobante',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_andrea_molina' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*de\\s*emisi[^\\n\\r:]*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    'Total\\s*Venta\\s*Neta',
                    'Total\\s*Gravado',
                ],
                'iva' => [
                    'Total\\s*Impuestos',
                    'IVA\\s*13%',
                ],
                'total' => [
                    'Total\\s*Comprobante',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    'Total\\s*Venta\\s*Neta',
                    'Total\\s*Gravado',
                    'Total\\s*Venta',
                ],
                'iva' => [
                    'Total\\s*Impuestos',
                    'IVA\\s*13%',
                ],
                'total' => [
                    'Total\\s*Comprobante',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_gian_carlos_alvarez_cordero' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    'Total\\s*venta\\s*neta',
                    'Total\\s*gravado',
                    'Total\\s*venta\\b',
                    'Total\\s*servicios\\s*gravados',
                ],
                'iva' => [
                    'Total\\s*impuestos',
                    'Total\\s*IVA\\b',
                    'I\\.?\\s*V\\.?\\s*A\\.?',
                ],
                'total' => [
                    'Total\\s*comprobante',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    'Total\\s*venta\\s*neta',
                    'Total\\s*gravado',
                    'Total\\s*venta\\b',
                    'Total\\s*servicios\\s*gravados',
                ],
                'iva' => [
                    'Total\\s*impuestos',
                    'Total\\s*IVA\\b',
                    'I\\.?\\s*V\\.?\\s*A\\.?',
                ],
                'total' => [
                    'Total\\s*comprobante',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_pamela_leon_cascante' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    '^SUB\\s*-\\s*TOTAL\\b',
                    '^SUB-?TOTAL\\b',
                ],
                'iva' => [
                    '^IVA\\s*13%\\b',
                    '^IVA\\b',
                ],
                'total' => [
                    '^TOTAL\\s*CRC\\b',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    '^SUB\\s*-\\s*TOTAL\\b',
                    '^SUB-?TOTAL\\b',
                ],
                'iva' => [
                    '^IVA\\s*13%\\b',
                    '^IVA\\b',
                ],
                'total' => [
                    '^TOTAL\\s*CRC\\b',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_mario_dario_cordero_camacho' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFECHA\\b[^\\n\\r:]*[:\\-]?\\s*([0-3]?\\d\\s*(?:[\\/\\-]|\\s)\\s*(?:ENE|FEB|MAR|ABR|MAY|JUN|JUL|AGO|SET|SEP|OCT|NOV|DIC)\\.?\\s*(?:[\\/\\-]|\\s)\\s*20\\d{2})/iu',
                '/\\b([0-3]?\\d\\s*(?:[\\/\\-]|\\s)\\s*(?:ENE|FEB|MAR|ABR|MAY|JUN|JUL|AGO|SET|SEP|OCT|NOV|DIC)\\.?\\s*(?:[\\/\\-]|\\s)\\s*20\\d{2})\\b/iu',
            ],
            'line_labels' => [
                'subtotal' => [
                    '^Subtotal\\b',
                ],
                'iva' => [
                    '^IVA\\b',
                ],
                'total' => [
                    '^TOTAL\\b',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    '^Subtotal\\b',
                    '\\bSubtotal\\b',
                ],
                'iva' => [
                    '^IVA\\b',
                    '\\bIVA\\b',
                ],
                'total' => [
                    '^TOTAL\\b',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_gaman_core' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    'Sub\\s*-\\s*Total',
                ],
                'iva' => [
                    '^Impuestos\\b',
                ],
                'total' => [
                    '^TOTAL\\s*¢\\b',
                    '^TOTAL\\b',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    'Sub\\s*-\\s*Total',
                    '^Gravado\\b',
                ],
                'iva' => [
                    '^Impuestos\\b',
                ],
                'total' => [
                    '^TOTAL\\s*¢\\b',
                    '^TOTAL\\b',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_avs_solutions' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*emisi[oó]n\\s*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    '^Subtotal\\s*:',
                ],
                'iva' => [
                    '^IVA\\s*:',
                ],
                'total' => [
                    '^Total\\s*:',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    '^Subtotal\\s*:',
                ],
                'iva' => [
                    '^IVA\\s*:',
                ],
                'total' => [
                    '^Total\\s*:',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_ncq_solutions' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*[:\\-]?\\s*([0-3]?\\d\\s*(?:[\\/\\-]|\\s)\\s*(?:ENE|FEB|MAR|ABR|MAY|JUN|JUL|AGO|SET|SEP|OCT|NOV|DIC)\\.?\\s*(?:[\\/\\-]|\\s)\\s*20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d\\s*(?:[\\/\\-]|\\s)\\s*(?:ENE|FEB|MAR|ABR|MAY|JUN|JUL|AGO|SET|SEP|OCT|NOV|DIC)\\.?\\s*(?:[\\/\\-]|\\s)\\s*20\\d{2})\\b/iu',
            ],
            'line_labels' => [
                'subtotal' => [
                    '^Sub\\s*total\\s*:',
                ],
                'iva' => [
                    '^Total\\s*impuestos\\s*:',
                ],
                'total' => [
                    '\\bTotal\\s*:',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    '^Sub\\s*total\\s*:',
                ],
                'iva' => [
                    '^Total\\s*impuestos\\s*:',
                ],
                'total' => [
                    '\\bTotal\\s*:',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_walter_granados_bermudez' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*de\\s*Emisi[oó]n\\s*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    'Subtotal\\s*Neto',
                ],
                'iva' => [
                    'Total\\s*IVA\\b',
                ],
                'total' => [
                    'Total\\s*Factura',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    'Subtotal\\s*Neto',
                ],
                'iva' => [
                    'Total\\s*IVA\\b',
                    '\\bIVA\\b',
                ],
                'total' => [
                    'Total\\s*Factura',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_innova_desarrollos_mobiliarios' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*de\\s*Emisi[oó]n\\s*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    'Subtotal\\s*Neto',
                ],
                'iva' => [
                    'Total\\s*IVA\\b',
                ],
                'total' => [
                    'Total\\s*Factura',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    'Subtotal\\s*Neto',
                ],
                'iva' => [
                    'Total\\s*IVA\\b',
                    '\\bIVA\\b',
                ],
                'total' => [
                    'Total\\s*Factura',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_distribuidora_valbag' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*de\\s*emisi[oó]n\\s*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\bFecha\\s*de\\s*creaci[oó]n\\s*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [],
                'iva' => [
                    'Iva\\s*\\(13\\.00%\\)',
                ],
                'total' => [
                    'Total\\s*a\\s*pagar',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    'Subtotal',
                ],
                'iva' => [
                    'Iva\\s*\\(13\\.00%\\)',
                ],
                'total' => [
                    'Total\\s*a\\s*pagar',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_servicentro_paso_real' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    'Sub\\s*Total\\s*:',
                ],
                'iva' => [
                    'Imp\\.\\s*de\\s*Ventas\\s*\\(13%\\)',
                ],
                'total' => [
                    'Total\\s*Factura\\s*:',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    'Sub\\s*Total\\s*:',
                ],
                'iva' => [
                    'Imp\\.\\s*de\\s*Ventas\\s*\\(13%\\)',
                ],
                'total' => [
                    'Total\\s*Factura\\s*:',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_bakery_dominical' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*de\\s*emisi[^\\n\\r:]*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    'Total\\s*Venta\\s*Neta',
                    'Total\\s*Gravado',
                ],
                'iva' => [
                    'Total\\s*Impuestos',
                    'IVA\\s*13%',
                ],
                'total' => [
                    'Total\\s*Comprobante',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    'Total\\s*Venta\\s*Neta',
                    'Total\\s*Gravado',
                    'Total\\s*Exento',
                ],
                'iva' => [
                    'Total\\s*Impuestos',
                    'IVA\\s*13%',
                ],
                'total' => [
                    'Total\\s*Comprobante',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_marvin_arroyo_mena' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*de\\s*Emisi[oó]n\\s*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    'Subtotal\\s*Neto',
                ],
                'iva' => [
                    'Total\\s*IVA\\b',
                ],
                'total' => [
                    'Total\\s*Factura',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    'Subtotal\\s*Neto',
                ],
                'iva' => [
                    'Total\\s*IVA\\b',
                    '\\bIVA\\b',
                ],
                'total' => [
                    'Total\\s*Factura',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_glendy_vargas_jimenez' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*[:\\-]?\\s*([0-3]?\\d[\\/\\-][A-Za-z\\.]{3,10}[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][A-Za-z\\.]{3,10}[\\/\\-]20\\d{2})\\b/iu',
            ],
            'line_labels' => [
                'subtotal' => [
                    '^Sub\\s*total\\s*:',
                ],
                'iva' => [
                    '^Total\\s*impuestos\\s*:',
                ],
                'total' => [
                    '(?<!Sub\\s)Total\\s*:',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    '^Sub\\s*total\\s*:',
                ],
                'iva' => [
                    '^Total\\s*impuestos\\s*:',
                ],
                'total' => [
                    '(?<!Sub\\s)Total\\s*:',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_bm_del_general' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    'SUBTOTAL\\s*\\*+',
                ],
                'iva' => [
                    '(?<!\\w)IVA\\s*\\*+',
                ],
                'total' => [
                    '(?<!\\w)TOTAL\\s*\\*+',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    'SUBTOTAL\\s*\\*+',
                ],
                'iva' => [
                    '(?<!\\w)IVA\\s*\\*+',
                ],
                'total' => [
                    '(?<!\\w)TOTAL\\s*\\*+',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_bm_quepos' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    'SUBTOTAL\\s*\\*+',
                ],
                'iva' => [
                    '(?<!\\w)IVA\\s*\\*+',
                ],
                'total' => [
                    '(?<!\\w)TOTAL\\s*\\*+',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    'SUBTOTAL\\s*\\*+',
                ],
                'iva' => [
                    '(?<!\\w)IVA\\s*\\*+',
                ],
                'total' => [
                    '(?<!\\w)TOTAL\\s*\\*+',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_bm_uvita' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    'SUBTOTAL\\s*\\*+',
                ],
                'iva' => [
                    '(?<!\\w)IVA\\s*\\*+',
                ],
                'total' => [
                    '(?<!\\w)TOTAL\\s*\\*+',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    'SUBTOTAL\\s*\\*+',
                ],
                'iva' => [
                    '(?<!\\w)IVA\\s*\\*+',
                ],
                'total' => [
                    '(?<!\\w)TOTAL\\s*\\*+',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_freddy_altamirano_uva' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [],
                'iva' => [],
                'total' => [],
            ],
            'heuristic_labels' => [],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_diunytra' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*Emisi[oó]n\\s*(20\\d{2}[\\/\\-][01]?\\d[\\/\\-][0-3]?\\d)\\b/iu',
                '/\\b(20\\d{2}[\\/\\-][01]?\\d[\\/\\-][0-3]?\\d)\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    'Venta\\s*Neta',
                ],
                'iva' => [
                    '^Impuestos\\b',
                ],
                'total' => [
                    'TOTAL\\s*EN\\s*CRC',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    'Venta\\s*Neta',
                    '^Gravado\\b',
                ],
                'iva' => [
                    '^Impuestos\\b',
                ],
                'total' => [
                    'TOTAL\\s*EN\\s*CRC',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_esmeralda_tijerino_caballero' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    'SubTotal\\s*:',
                ],
                'iva' => [
                    'Impuestos\\s*:',
                ],
                'total' => [
                    'TOTAL\\s*:',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    'SubTotal\\s*:',
                ],
                'iva' => [
                    'Impuestos\\s*:',
                ],
                'total' => [
                    'TOTAL\\s*:',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_yurlin_antonio_chaves_delgado' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    'SubTotal\\s*:',
                ],
                'iva' => [
                    'Impuestos\\s*:',
                ],
                'total' => [
                    'TOTAL\\s*:',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    'SubTotal\\s*:',
                ],
                'iva' => [
                    'Impuestos\\s*:',
                ],
                'total' => [
                    'TOTAL\\s*:',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_norma_gerardina_ceciliano' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    'SubTotal\\s*:',
                ],
                'iva' => [
                    'Impuestos\\s*:',
                ],
                'total' => [
                    'TOTAL\\s*:',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    'SubTotal\\s*:',
                ],
                'iva' => [
                    'Impuestos\\s*:',
                ],
                'total' => [
                    'TOTAL\\s*:',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_natalia_ramirez_montero' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    'SubTotal\\s*:',
                ],
                'iva' => [
                    'Impuestos\\s*:',
                ],
                'total' => [
                    'TOTAL\\s*:',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    'SubTotal\\s*:',
                ],
                'iva' => [
                    'Impuestos\\s*:',
                ],
                'total' => [
                    'TOTAL\\s*:',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_paula_sibaja_leon' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    'SubTotal\\s*:',
                ],
                'iva' => [
                    'Impuestos\\s*:',
                ],
                'total' => [
                    'TOTAL\\s*:',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    'SubTotal\\s*:',
                ],
                'iva' => [
                    'Impuestos\\s*:',
                ],
                'total' => [
                    'TOTAL\\s*:',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_murcia_diaz_ana_joulin' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    'SubTotal\\s*:',
                ],
                'iva' => [
                    'Impuestos\\s*:',
                ],
                'total' => [
                    'TOTAL\\s*:',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    'SubTotal\\s*:',
                ],
                'iva' => [
                    'Impuestos\\s*:',
                ],
                'total' => [
                    'TOTAL\\s*:',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_jose_pablo_jimenez_chavarria' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    'SubTotal\\s*:',
                ],
                'iva' => [
                    'Impuestos\\s*:',
                ],
                'total' => [
                    'TOTAL\\s*:',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    'SubTotal\\s*:',
                ],
                'iva' => [
                    'Impuestos\\s*:',
                ],
                'total' => [
                    'TOTAL\\s*:',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_annys_floristeria_detalles' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    'SubTotal\\s*:',
                ],
                'iva' => [
                    'Impuestos\\s*:',
                ],
                'total' => [
                    'TOTAL\\s*:',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    'SubTotal\\s*:',
                ],
                'iva' => [
                    'Impuestos\\s*:',
                ],
                'total' => [
                    'TOTAL\\s*:',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_josue_david_mora_fonseca' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    'SubTotal\\s*:',
                ],
                'iva' => [
                    'Impuestos\\s*:',
                ],
                'total' => [
                    'TOTAL\\s*:',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    'SubTotal\\s*:',
                ],
                'iva' => [
                    'Impuestos\\s*:',
                ],
                'total' => [
                    'TOTAL\\s*:',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_inversiones_petueles' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*y\\s*hora\\s*de\\s*emisi[oó]n\\s*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    'Sub\\s*Total2\\s*:',
                    'Sub\\s*Total\\s*:',
                ],
                'iva' => [
                    'Impuesto\\s*de\\s*Valor\\s*Agregado',
                ],
                'total' => [
                    'Total\\s*de\\s*Factura',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    'Sub\\s*Total2\\s*:',
                    'Sub\\s*Total\\s*:',
                ],
                'iva' => [
                    'Impuesto\\s*de\\s*Valor\\s*Agregado',
                ],
                'total' => [
                    'Total\\s*de\\s*Factura',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_almacenes_el_colono' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFECHA\\s*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    'SUB\\s*-\\s*TOTAL',
                ],
                'iva' => [
                    'IVA\\s*NETO',
                    '^\\s*IVA\\s*:',
                ],
                'total' => [
                    '^\\s*TOTAL\\b',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    'SUB\\s*-\\s*TOTAL',
                ],
                'iva' => [
                    'IVA\\s*NETO',
                    '^\\s*IVA\\s*:',
                ],
                'total' => [
                    '^\\s*TOTAL\\b',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_gs_la_colonia' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    'Sub\\s*Total\\s*:',
                ],
                'iva' => [
                    'Imp\\.\\s*de\\s*Ventas\\s*\\(13%\\)',
                ],
                'total' => [
                    'Total\\s*Factura\\s*:',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    'Sub\\s*Total\\s*:',
                ],
                'iva' => [
                    'Imp\\.\\s*de\\s*Ventas\\s*\\(13%\\)',
                ],
                'total' => [
                    'Total\\s*Factura\\s*:',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_jonday' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    '^\\s*SUBTOTAL\\s*:',
                ],
                'iva' => [
                    '^\\s*IVA\\s*:',
                ],
                'total' => [
                    'TOTAL\\s*CRC',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    '^\\s*SUBTOTAL\\s*:',
                ],
                'iva' => [
                    '^\\s*IVA\\s*:',
                ],
                'total' => [
                    'TOTAL\\s*CRC',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_grupo_majaic' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*Emisi[oó]n\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    'Tot\\.\\s*Venta\\s*Neta',
                ],
                'iva' => [
                    'Tot\\.\\s*IVA',
                ],
                'total' => [
                    'Tot\\.\\s*Comprobante',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    'Tot\\.\\s*Venta\\s*Neta',
                    'Tot\\.\\s*Gravado',
                ],
                'iva' => [
                    'Tot\\.\\s*IVA',
                ],
                'total' => [
                    'Tot\\.\\s*Comprobante',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_sabores_coto_brus' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [],
                'iva' => [],
                'total' => [],
            ],
            'heuristic_labels' => [
                'subtotal' => [],
                'iva' => [],
                'total' => [],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_compania_carragonza' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    'VENTA\\s*NETA',
                ],
                'iva' => [
                    '\\bIVA\\b',
                    'Total\\s*Impuesto',
                ],
                'total' => [
                    'TOTAL\\s*CRC',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    'VENTA\\s*NETA',
                    'SUB\\s*-\\s*TOTAL',
                ],
                'iva' => [
                    '\\bIVA\\b',
                    'Total\\s*Impuesto',
                ],
                'total' => [
                    'TOTAL\\s*CRC',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_aracelly_charpantier_barquero' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*de\\s*emisi[^\\n\\r:]*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-](?:20)?\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-](?:20)?\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    'Subtotal\\s*Neto',
                ],
                'iva' => [
                    'Total\\s*Impuesto',
                ],
                'total' => [
                    'Total\\s*Comprobante',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    'Subtotal\\s*Neto',
                ],
                'iva' => [
                    'Total\\s*Impuesto',
                ],
                'total' => [
                    'Total\\s*Comprobante',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_maria_jose_fonseca_sibaja' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*de\\s*emisi[^\\n\\r:]*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    '\\bSubtotal\\s*:',
                ],
                'iva' => [
                    '\\bIVA\\s*:',
                ],
                'total' => [
                    'Total\\s*Factura\\s*Electr',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    '\\bSubtotal\\s*:',
                    '\\bNeto\\s*:',
                ],
                'iva' => [
                    '\\bIVA\\s*:',
                ],
                'total' => [
                    'Total\\s*Factura\\s*Electr',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_graciela_mesen_salas' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*de\\s*Emisi[oÃ³]n\\s*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    'Subtotal\\s*Neto',
                ],
                'iva' => [
                    'Total\\s*Impuesto',
                ],
                'total' => [
                    'Total\\s*Factura',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    'Subtotal\\s*Neto',
                ],
                'iva' => [
                    'Total\\s*Impuesto',
                ],
                'total' => [
                    'Total\\s*Factura',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_finca_los_manglares_uvita' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    'SubTotal\\s*:',
                    'Venta\\s*Neta\\s*:',
                ],
                'iva' => [
                    'Total\\s*I\\.?\\s*V\\.?\\s*A\\.?\\s*:',
                    'I\\.?\\s*V\\.?\\s*A\\.?\\s*13%\\s*:',
                ],
                'total' => [
                    '^TOTAL\\s*:',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    'SubTotal\\s*:',
                    'Venta\\s*Neta\\s*:',
                    'Subtotal\\s*gravado\\s*:',
                ],
                'iva' => [
                    'Total\\s*I\\.?\\s*V\\.?\\s*A\\.?\\s*:',
                    'I\\.?\\s*V\\.?\\s*A\\.?\\s*13%\\s*:',
                ],
                'total' => [
                    '^TOTAL\\s*:',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_surbm' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    'SUBTOTAL\\s*\\*+',
                ],
                'iva' => [
                    '(?<!\\w)IVA\\s*\\*+',
                ],
                'total' => [
                    '(?<!\\w)TOTAL\\s*\\*+',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    'SUBTOTAL\\s*\\*+',
                ],
                'iva' => [
                    '(?<!\\w)IVA\\s*\\*+',
                ],
                'total' => [
                    '(?<!\\w)TOTAL\\s*\\*+',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_arrendadora_bm_pz' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    'SUBTOTAL\\s*\\*+',
                ],
                'iva' => [
                    '(?<!\\w)IVA\\s*\\*+',
                ],
                'total' => [
                    '(?<!\\w)TOTAL\\s*\\*+',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    'SUBTOTAL\\s*\\*+',
                ],
                'iva' => [
                    '(?<!\\w)IVA\\s*\\*+',
                ],
                'total' => [
                    '(?<!\\w)TOTAL\\s*\\*+',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_bm_del_palmar' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    'SUBTOTAL\\s*\\*+',
                ],
                'iva' => [
                    '(?<!\\w)IVA\\s*\\*+',
                ],
                'total' => [
                    '(?<!\\w)TOTAL\\s*\\*+',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    'SUBTOTAL\\s*\\*+',
                ],
                'iva' => [
                    '(?<!\\w)IVA\\s*\\*+',
                ],
                'total' => [
                    '(?<!\\w)TOTAL\\s*\\*+',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_comercial_corcovado_del_sur' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    'SUBTOTAL\\s*\\*+',
                ],
                'iva' => [
                    '(?<!\\w)IVA\\s*\\*+',
                ],
                'total' => [
                    '(?<!\\w)TOTAL\\s*\\*+',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    'SUBTOTAL\\s*\\*+',
                ],
                'iva' => [
                    '(?<!\\w)IVA\\s*\\*+',
                ],
                'total' => [
                    '(?<!\\w)TOTAL\\s*\\*+',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_fan_zhongwei' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*de\\s*emisi[^\\n\\r:]*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    'Total\\s*Venta\\s*Neta',
                    'Total\\s*Exento',
                ],
                'iva' => [
                    'Total\\s*Impuestos',
                    'IVA\\s*13%',
                ],
                'total' => [
                    'Total\\s*Comprobante',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    'Total\\s*Venta\\s*Neta',
                    'Total\\s*Exento',
                    'Total\\s*Venta',
                ],
                'iva' => [
                    'Total\\s*Impuestos',
                    'IVA\\s*13%',
                ],
                'total' => [
                    'Total\\s*Comprobante',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_ronald_enrique_murillo_soto' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    '^\\s*SUBTOTAL\\s*:',
                    'SUBTOTAL\\s*GRAVADO\\s*:',
                ],
                'iva' => [
                    '^\\s*IVA\\s*:',
                ],
                'total' => [
                    'TOTAL\\s*CRC',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    '^\\s*SUBTOTAL\\s*:',
                    'SUBTOTAL\\s*GRAVADO\\s*:',
                ],
                'iva' => [
                    '^\\s*IVA\\s*:',
                ],
                'total' => [
                    'TOTAL\\s*CRC',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_brittany_alejandra_godinez_cascante' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*Emisi[oÃ³]n\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    'Tot\\.\\s*Venta\\s*Neta',
                    'Tot\\.\\s*Gravado',
                ],
                'iva' => [
                    'Tot\\.\\s*IVA',
                    'IVA\\s*Total',
                ],
                'total' => [
                    'Tot\\.\\s*Comprobante',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    'Tot\\.\\s*Venta\\s*Neta',
                    'Tot\\.\\s*Gravado',
                    'SubTot\\.\\s*Neto',
                ],
                'iva' => [
                    'Tot\\.\\s*IVA',
                    'IVA\\s*Total',
                ],
                'total' => [
                    'Tot\\.\\s*Comprobante',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_roma_del_sur' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [],
                'iva' => [],
                'total' => [],
            ],
            'heuristic_labels' => [
                'subtotal' => [],
                'iva' => [],
                'total' => [],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_corporacion_alfaro_mano_de_dios' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*de\\s*emisi[^\\n\\r:]*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    'Total\\s*Venta\\s*Neta',
                    'Total\\s*Gravado',
                ],
                'iva' => [
                    'Total\\s*Impuestos',
                ],
                'total' => [
                    'Total\\s*Comprobante',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    'Total\\s*Venta\\s*Neta',
                    'Total\\s*Gravado',
                ],
                'iva' => [
                    'Total\\s*Impuestos',
                ],
                'total' => [
                    'Total\\s*Comprobante',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_inversiones_idaly_arias' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*Emisi[oÃ³]n\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    'Tot\\.\\s*Venta\\s*Neta',
                    'Tot\\.\\s*Gravado',
                ],
                'iva' => [
                    'Tot\\.\\s*IVA',
                ],
                'total' => [
                    'Tot\\.\\s*Comprobante',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    'Tot\\.\\s*Venta\\s*Neta',
                    'Tot\\.\\s*Gravado',
                ],
                'iva' => [
                    'Tot\\.\\s*IVA',
                ],
                'total' => [
                    'Tot\\.\\s*Comprobante',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_cindy_retana_mora' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [],
                'iva' => [],
                'total' => [],
            ],
            'heuristic_labels' => [
                'subtotal' => [],
                'iva' => [],
                'total' => [],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_tractoreos_ariete' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [],
                'iva' => [],
                'total' => [],
            ],
            'heuristic_labels' => [],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_nathaly_sofia_rojas_guerra' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFecha\\s*de\\s*emisi[^\\n\\r:]*[:\\-]?\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    'Total\\s*Venta\\s*Neta',
                    'Total\\s*Exento',
                ],
                'iva' => [
                    'Total\\s*Impuestos',
                    'IVA\\s*13%',
                ],
                'total' => [
                    'Total\\s*Comprobante',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    'Total\\s*Venta\\s*Neta',
                    'Total\\s*Exento',
                ],
                'iva' => [
                    'Total\\s*Impuestos',
                    'IVA\\s*13%',
                ],
                'total' => [
                    'Total\\s*Comprobante',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_braulio_david_monge_mata' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFECHA\\s*EMISI[^\\n\\r:]*[:\\-]?\\s*(20\\d{2}[\\/\\-][01]?\\d[\\/\\-][0-3]?\\d)(?=[T\\s]|\\b)/iu',
                '/\\b(20\\d{2}[\\/\\-][01]?\\d[\\/\\-][0-3]?\\d)(?=[T\\s]|\\b)/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    '\\bSubtotal\\s*:',
                ],
                'iva' => [
                    '\\bImpuesto\\s*:',
                ],
                'total' => [
                    '\\bTotal\\s*:',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    '\\bSubtotal\\s*:',
                ],
                'iva' => [
                    '\\bImpuesto\\s*:',
                ],
                'total' => [
                    '\\bTotal\\s*:',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        'cr_jorge_rodriguez_gamboa' => [
            'enabled' => true,
            'date_patterns' => [
                '/\\bFECHA\\s*([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/iu',
                '/\\b([0-3]?\\d[\\/\\-][01]?\\d[\\/\\-]20\\d{2})\\b/u',
            ],
            'line_labels' => [
                'subtotal' => [
                    'MERCANCIAS\\s*GRAVADAS',
                    'SERVICIOS\\s*GRAVADOS',
                ],
                'iva' => [
                    'TOTAL\\s*IMPUESTOS\\(IVA\\)',
                ],
                'total' => [
                    'MONTO\\s*TOTAL',
                ],
            ],
            'heuristic_labels' => [
                'subtotal' => [
                    'MERCANCIAS\\s*GRAVADAS',
                    'SERVICIOS\\s*GRAVADOS',
                ],
                'iva' => [
                    'TOTAL\\s*IMPUESTOS\\(IVA\\)',
                ],
                'total' => [
                    'MONTO\\s*TOTAL',
                ],
            ],
            'numero_referencia_priority' => [
                'documento',
                'clave',
            ],
        ],
        ],
];
