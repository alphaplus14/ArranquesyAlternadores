# HANDOFF — Orquestador de agentes (25-nov-2026)

**Objetivo:** que Claude Code trabaje como orquestador de varios agentes en paralelo sobre la rama al día con César.

**Hecho:**
- `rf-21-modulo-contable` adelantada a `origin/cesar` (`43e6e9b`): RF-24 a RF-28, RF-31 retirado, módulo contable renumerado a RF-29/RF-30 y S-62 a S-69. Lo de RQ-01 a RQ-05 (S-63 a S-67) ya está commiteado.
- Agentes del proyecto en `.claude/agents/`, flujo en `.claude/ORQUESTACION.md`, worktrees aislados con `.claude/scripts/agentes.sh` (base de tests propia por agente).
- Suite: 630 tests en verde (unos 195 s).

**Pendiente:**
- RQ-06 / S-68 «Saldo»: esperar la decisión de César.
- La base de desarrollo `inventario` tiene 6 migraciones pendientes (caja, empleados, correcciones): `cd backend && php artisan migrate` antes de probar en el navegador.
- Push de la rama cuando Franklin decida.

**Decisiones:** worktrees en `C:\dev\arranques-wt`; commits locales sin push; agentes versionados en el repo.

**Verificar:** `bash .claude/scripts/agentes.sh tests` y `cd frontend && npm run build`.

**Siguiente paso:** la próxima petición sigue `.claude/ORQUESTACION.md`.
