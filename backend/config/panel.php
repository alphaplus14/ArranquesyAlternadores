<?php

/*
 * RF-23 / S-45: umbrales del panel de gestión del administrador. Viven aquí, no en la interfaz.
 * Solo informan: el panel no escribe nada.
 */
return [
    // S-45 g: cada informe se guarda 5 minutos por combinación de filtros; `refrescar=1` lo salta.
    'cache_segundos' => 300,

    // S-45 e: "días sin movimiento" (configurable por el admin en Configuración).
    'sin_movimiento' => [
        'dias_por_defecto' => 120,
        'dias_minimo' => 30,
        'dias_maximo' => 730,
        'por_pagina' => 20,
    ],

    // S-45 f: reabastecer = vendió al menos `min_vendidas` en el período y (existencia 0, cobertura
    // menor a `cobertura_dias` o existencia <= mínimo efectivo de StockMinimoService).
    'reabastecer' => [
        'min_vendidas' => 3,
        'cobertura_dias' => 15,
        'limite' => 20,
    ],

    // S-45 f: sobrestock = cobertura mayor a estos días.
    'sobrestock_dias' => 180,

    // S-45 f: clase ABC por valor de venta (acumulado, en %). A hasta 80, B hasta 95, C el resto.
    'abc' => ['A' => 80, 'B' => 95],

    // S-45: antigüedad de las unidades disponibles, en días (límite superior inclusivo; null = sin tope).
    'antiguedad' => [
        '0-30' => 30,
        '31-90' => 90,
        '91-180' => 180,
        '181-365' => 365,
        'mas-365' => null,
    ],

    'mas_vendidos' => ['limite' => 10, 'limite_maximo' => 50],
];
