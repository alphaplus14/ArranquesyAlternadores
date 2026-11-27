# HANDOFF — Arranques (26-nov-2026)

**Objetivo:** integrar los pedidos de César (nov-2026) en `rf-21-modulo-contable`, sin push.

**Hecho:** S-85 (referencias en mayúscula), S-86 (descuento del documento y de línea se excluyen) y S-87 (movimientos de inventario por producto y buscador de compras) integrados en código, tests y docs. Suite: 782 pasan, 1 saltado.

**Pendiente:**
- Confirmar S-86 con el contador (pendiente 3).
- `php artisan migrate` en `inventario`: 6 migraciones anteriores más 3 nuevas.
- Revisar en pantalla los totales y el scroll de Compras y la pestaña «Por producto».
- Endpoint de usuarios para logística, si se quiere.
- Push cuando Franklin decida; avisar a César (renumera S/RF al mezclar).

**Decisiones:** NIT del buscador «contiene»; mayúscula solo en `descripcion` y `codigo_referencia`; `prellenar` copia sin descuento de línea si la factura tiene descuento del documento.

**Archivos:** `docs/supuestos.md`, `CLAUDE.md`, `.claude/ORQUESTACION.md`, `HANDOFF.md`.

**Verificar:** `bash .claude/scripts/agentes.sh tests`; `cd frontend && npm run build`.

**Siguiente paso:** migrar y probar en el navegador.
