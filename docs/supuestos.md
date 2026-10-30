# Supuestos abiertos

Cada supuesto cubre un pendiente de `docs/requerimientos-v1.1.md` (sección 10). Son provisionales: cuando el negocio confirme, se actualiza esta tabla y el código señalado.

| # | Pendiente | Supuesto vigente | Dónde vive en el código | Cómo cambiarlo |
|---|---|---|---|---|
| S-01 | Etiqueta en piezas baratas de alto volumen: ¿por unidad o por referencia + cantidad? (pendiente 3) | Todo lleva etiqueta por unidad. No existe modo "referencia + cantidad". | `referencias.existencia` es un contador que solo cambia el servicio de inventario; no se calcula con `COUNT(unidades)` en las lecturas. | Agregar un modo por referencia o categoría que mueva el contador sin crear unidades, y ajustar `inventario:recalcular` para no comparar esas referencias. |
| S-02 | Cuáles poleas y automáticos entran al universal (pendiente 4) | La categoría tiene `admite_codigo_universal`. Poleas y automáticos lo tienen activo; el admin asocia cada referencia a mano. | `categorias.admite_codigo_universal` y la validación de referencias. | Ninguno: se resuelve con datos. |
| S-03 | Nombre de la tercera marca, "Jota" (pendiente 5) | Las marcas son un catálogo editable. El seeder usa una marca de ejemplo. | Tabla `marcas`, seeder. | Editar la marca desde el admin. |
| S-04 | Tiempo de respuesta objetivo (pendiente 6) | Escaneo → descuento confirmado < 300 ms p95 en red local. Búsquedas < 500 ms. | Middleware de duración en los endpoints de escaneo y búsqueda (canal de log `rendimiento`). | Ajustar el objetivo aquí y en el umbral de alerta del log. |
| S-05 | Medidas de los tres tamaños de etiqueta (el documento no las da) | Grande 100×50 mm, pequeño 50×25 mm, más pequeño 38×19 mm. | `config/etiquetas.php` | Editar el config. |
| S-06 | QR y XML de la factura de compra (RF-04, pendiente 2) | No se implementa en la Fase 1. `ingresos.tipo` (`remision` \| `factura_xml`) y `ingresos.cufe` nullable único quedan listos. | Migración de `ingresos`. | Fase posterior, tras probar con una factura real. |
| S-07 | Cartera en Contapyme (pendiente 1) | Fuera de alcance. | — | Confirmar con Frank. |
| S-08 | Motor de base de datos | Decidido: MySQL 8 (puerto 3307 en `.env.example`). En la máquina de desarrollo solo hay MariaDB 10.4 (XAMPP). Como verificación previa, la suite se corrió en MariaDB con una base desechable (`inventario_test_mariadb`), pero la validación oficial queda pendiente en MySQL 8. | `.env`, `phpunit.xml`. | Instalar MySQL 8 y correr `php artisan test`. |
