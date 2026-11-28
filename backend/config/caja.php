<?php

/*
 * RF-24 a RF-26 (S-48 a S-54): caja diaria del mostrador. Solo registra lo que entra y sale; no es
 * contabilidad general. Los valores editables (meta y saldo inicial) viven en `configuraciones`.
 */
return [

    // S-51: meta de venta diaria por defecto (pesos) mientras el admin no fije otra en Configuración.
    'meta_venta_diaria' => 10900000,

    // S-98: base fija de caja (pesos). Siempre queda en el cajón y no cuenta para el cuadre; el admin
    // la cambia en Configuración (clave base_caja). Cada cierre guarda la base con que se cerró.
    'base' => 135000,

    // Máximo de partes en el pago de una venta de contado (pago dividido, S-48).
    'max_pagos_por_venta' => 10,

];
