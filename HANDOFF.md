# HANDOFF — Arranques (27-nov-2026)

**Objetivo:** integrar los pedidos de César en `rf-21-modulo-contable`, sin push.

**Hecho:** S-65 (avisos: acierto 5 s, error 20-30 s; errores de carga fijos), S-70 (menú del admin desplegado), S-88 completo (el mostrador no recibe dueños ni saldos de billeteras), S-94 (Cartera con rango «ventas del período» y miga con filtros), S-95 (desglose fijo del universal en Inventario). Docs al día.

**Pendiente:**
- Probar en navegador: sticky del desglose (top 44/36 px fijos; densidad compacta y táctil), miga con filtros, Cartera con rango, Configuración.
- `php artisan migrate` en `inventario`.
- Tests de frontend: no existen.
- Confirmar S-86 con el contador.
- Ramas viejas sin integrar: `panel-*`, `diseno-logi`, `legal-datos`.
- Push cuando Franklin decida; avisar a César (renumera S/RF).

**Archivos:** `docs/supuestos.md`, `CLAUDE.md`, `HANDOFF.md`.

**Verificar:** `bash .claude/scripts/agentes.sh tests`; `cd frontend && npm run build`.

**Siguiente paso:** migrar y probar en el navegador.
