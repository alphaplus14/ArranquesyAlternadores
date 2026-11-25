<?php

/*
 * RF-24 a RF-26 (S-48 a S-54): caja diaria del mostrador. Solo registra lo que entra y sale; no es
 * contabilidad general. Los valores editables (meta y saldo inicial) viven en `configuraciones`.
 */
return [

    // S-51: meta de venta diaria por defecto (pesos) mientras el admin no fije otra en Configuración.
    'meta_venta_diaria' => 10900000,

    // Máximo de partes en el pago de una venta de contado (pago dividido, S-48).
    'max_pagos_por_venta' => 10,

];
