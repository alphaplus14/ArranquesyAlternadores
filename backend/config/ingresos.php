<?php

/*
 * RF-04 (S-06): diferencia máxima, en pesos, entre el total digitado y el total que trae el QR
 * antes de exigir que logística acepte la diferencia al confirmar. Cubre redondeos.
 */
return [
    'tolerancia_total' => (float) env('INGRESOS_TOLERANCIA_TOTAL', 1),
];
