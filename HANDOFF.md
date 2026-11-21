# HANDOFF — RF-04: diferencia contra el QR registrada (backend)

**Objetivo:** al confirmar una factura por QR, registrar la diferencia contra el total del QR, quién la aceptó y por qué.

**Hecho (backend, sin commit):**
- Migración `2026_11_21_000100`: `ingresos.confirmado_por`, `diferencia_qr`, `diferencia_aceptada`, `diferencia_motivo`.
- `POST /api/ingresos/{id}/confirmar` acepta `diferencia_motivo` (máx. 500). Si la diferencia supera la tolerancia: sin `aceptar_diferencia` → 422 `message`; con `aceptar_diferencia` y sin motivo → 422 `errors.diferencia_motivo`.
- `IngresoResource`: `qr.diferencia_aceptada`, `qr.diferencia_motivo`, `confirmado_por {id,name}`. Confirmado, `qr.diferencia` es la guardada.
- `GET /api/ingresos?con_diferencia=1`.
- Ingreso confirmado inmutable (modelo). Ediciones del borrador con `lockForUpdate` (carrera agregar ítem vs confirmar).
- Tests: 86 en verde (7 nuevos en RF04 + concurrencia `RF05IngresoConcurrenteTest`).

**Pendiente (frontend):** en `IngresoDetalle.tsx`, pedir el motivo al aceptar la diferencia y mostrar diferencia/motivo/confirmado_por; filtro "con diferencia" en `Ingresos.tsx`; tipos en `api/tipos.ts`.

**Verificar:** `cd backend && php artisan test`.

**Siguiente paso:** frontend; luego commits `feat(RF-04): ...` en una rama.
