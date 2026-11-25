<?php

/*
 * RF-15 / RF-17: abonos de clientes y pagos a proveedores. RF-18: antigüedad de saldos.
 */
return [

    // Medio de pago de un abono o un pago. La clave es el valor que envía el frontend.
    'medios' => [
        'efectivo' => 'Efectivo',
        'transferencia' => 'Transferencia',
        'tarjeta' => 'Tarjeta',
        'cheque' => 'Cheque',
        'otro' => 'Otro',
    ],

    // S-13: rangos de días vencidos. "al_dia" es todo lo que aún no vence.
    'rangos' => [
        'al_dia' => 'Al día',
        'd1_30' => '1-30',
        'd31_60' => '31-60',
        'd61_90' => '61-90',
        'mas_90' => 'Más de 90',
    ],

    // Estado de cuenta sin período: los últimos días indicados.
    'dias_estado_cuenta' => 90,

    // RF-30 / S-28: la bandeja de vencimientos avisa lo vencido y lo que vence en estos días.
    'aviso_dias' => (int) env('CARTERA_AVISO_DIAS', 1),

];
