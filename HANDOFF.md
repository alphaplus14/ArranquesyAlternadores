# HANDOFF — Arranques (28-nov-2026)

**Objetivo:** reflejar en la documentación el motivo siempre obligatorio al cambiar la apertura de caja (S-84, RF-25).

**Hecho:** el backend (commit 0a046fe) exige motivo de 3 a 200 caracteres en cualquier `PUT /api/caja/dias/{fecha}/apertura`, aunque el valor sea igual al automático o sea el primer día. Se quitaron la excepción «sin motivo si es igual», el motivo opcional del primer día y el motivo automático. Docs actualizadas.

**Pendiente:** revisar que el frontend de la apertura pida siempre el motivo (no verificado aquí).

**Decisiones:** sigue siendo S-84; sin numeración nueva. Filas viejas pueden mostrarse como `arrastre`.

**Archivos:** `docs/supuestos.md` (S-84, S-49 nota), `CLAUDE.md` (regla 17 y supuestos), `HANDOFF.md`.

**Verificar:** `cd backend && vendor/bin/pest --filter=apertura`.

**Siguiente paso:** probar el cambio de apertura en el navegador.
