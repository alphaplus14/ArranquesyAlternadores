<?php

/*
 * RF-03: etiquetas por unidad física.
 * Supuesto S-05 (docs/supuestos.md): el documento no da medidas; estos valores son provisionales.
 */
return [

    // Código del local: prefijo + consecutivo con ceros a la izquierda (A8000001).
    'prefijo' => env('ETIQUETAS_PREFIJO', 'A8'),
    'digitos' => (int) env('ETIQUETAS_DIGITOS', 6),

    // Medidas en milímetros. La clave es el valor que envía el frontend.
    'tamanos' => [
        'grande' => ['nombre' => 'Grande', 'ancho' => 100, 'alto' => 50],
        'pequeno' => ['nombre' => 'Pequeño', 'ancho' => 50, 'alto' => 25],
        'mas_pequeno' => ['nombre' => 'Más pequeño', 'ancho' => 38, 'alto' => 19],
    ],

];
