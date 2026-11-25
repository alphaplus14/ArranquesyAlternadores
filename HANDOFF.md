# HANDOFF — Requerimientos de César (24-nov-2026, RQ-01 a RQ-06)

**Objetivo:** cumplir `Desktop\Requerimientos_Arranques.md`. Registrados como S-48 a S-53 en `docs/supuestos.md`.

**Hecho (rama `ajustes-cesar-nov`, sin commitear):**
- S-48 menú: "Compras y remisiones" y "Movimientos" / "Historial de movimientos".
- S-49: el nuevo ingreso (factura y remisión) abre en Crédito.
- S-50: los avisos de acierto se cierran a los 5 s; el del ítem escaneado también cuando falta el costo.
- S-51: descuento del documento aceptado en el borrador aun sin líneas; "≤ subtotal" solo al confirmar; `totales.descuento_excede_subtotal` y aviso.
- Merge con `origin/cesar` reparado: tipos contables recuperados (`FilaVencimiento`, `Vencimientos`, campos de `DocumentoPendiente` y `MovimientoDinero`), imports con los nombres de César y pago a factura (RF-17) recuperado en `DialogoMovimiento`. `tsc` y build limpios.
- S-52: `componentes/CampoMoneda.tsx` (separador de miles) en precio de venta, costo de línea, descuento $, abonos/pagos y precio a crédito.

**Pendiente:**
- RQ-06 / S-53 "Saldo": esperar decisión de César.
- Probar en el navegador.
- Commits por RQ.
- 3 archivos de backend modificados que no son de esta tarea (`CarteraService`, migración `ventas_descartada`, `Pest.php`).

**Verificar:** `cd backend && php artisan test` (539 en verde), `cd frontend && npm run build`.

**Siguiente paso:** prueba manual de ingresos y del mostrador, luego commits.
