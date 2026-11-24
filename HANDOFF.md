# HANDOFF — Módulo contable (RF-21, RF-22, RF-23, RF-17 ajuste)

**Objetivo:** módulo para la auxiliar contable: cartera, cuentas por pagar, pagos a una factura elegida, avisos de vencimiento y exportación a Excel. Especificación en `docs/adenda-v1.3-modulo-contable.md`; supuestos S-27 a S-29.

**Hecho (rama `rf-21-modulo-contable`, commitado):**
- Backend: rol `contable`, migración `2026_11_25_000100` (enum de rol + `pagos_proveedores.ingreso_id`), `PagoService` valida el pago a una factura, `Antiguedad` aplica primero lo pagado a cada factura, `VencimientoService` + `GET /vencimientos`, `?formato=xlsx` en todos los informes (`Support\Xlsx` sin dependencias, `Support\InformeExcel`).
- Frontend: `paginas/contable/` (Vencimientos, Cuentas por pagar, Proveedor, Cartera, Cliente, Informes), contador en el menú, rol en Usuarios.
- Tests: 328 en verde (17 nuevos en `RF21ModuloContableTest`). `tsc`, eslint y build limpios.
- BD de desarrollo migrada; usuario `contable` / `cambiar123`.

**Pendiente:** probar en navegador con datos reales tras merge de `origin/cesar`.

**Verificar:** `cd backend && php artisan test`; entrar como `contable`.

**Siguiente paso:** merge de `origin/cesar` y resolver conflictos (RF-21 inventario vs contable).
