# Orquestación con agentes

Para la sesión principal de Claude Code, que hace de orquestador. Los agentes de `.claude/agents/` reciben lo suyo en su definición y en el prompt; no necesitan leer esto.

## Equipo

| Agente | Hace | Escribe en |
|---|---|---|
| `arquitecto` | Diseño listo para implementar y reparto de trabajo | nada (solo lectura) |
| `backend-laravel` | Migraciones, servicios, endpoints y sus tests | `backend/` |
| `frontend-react` | Tipos, pantallas, componentes y hooks | `frontend/` |
| `tests-pest` | Tests por criterio de aceptación, separados de quien implementa | `backend/tests/` |
| `revisor-reglas` | Revisa el diff contra CLAUDE.md antes de integrar | nada (solo lectura) |
| `docs-supuestos` | `docs/supuestos.md`, adendas, CLAUDE.md, READMEs, HANDOFF | `docs/` y `*.md` |

`Explore` (integrado) sirve para búsquedas amplias de solo lectura.

## Flujo por petición

0. **Preparar.** Leer `HANDOFF.md`. `git fetch`: si `origin/cesar` avanzó, avisar y proponer traerlo (fast-forward o merge) antes de repartir. MySQL arriba (`<carpeta-mysql>\iniciar-mysql.cmd`). Árbol limpio en la carpeta principal.
1. **Clasificar.** ¿Está en alcance? RF y S afectados, pendientes que bloquean, tamaño. Si es chico (uno o dos archivos, sin regla nueva), lo hace el orquestador sin agentes.
2. **Diseñar.** `arquitecto` (o el orquestador, si es simple) entrega el contrato: datos, endpoints, tipos TS, pantallas, tests, S-xx nuevos y reparto. Las decisiones de negocio abiertas se le preguntan al usuario **antes** de implementar.
3. **Repartir.** Una unidad = un agente = un worktree = una rama `agente/<nombre>`, con archivos de propiedad exclusiva. El contrato se fija primero; `routes/api.php`, `frontend/src/api/tipos.ts`, `App.tsx`, `Layout.tsx` y `tests/Pest.php` tienen un solo dueño por tarea. Máximo 3 agentes escribiendo a la vez.
   Patrón típico: `backend-laravel` ‖ `tests-pest` ‖ `frontend-react` (con el contrato ya fijado) → `revisor-reglas` → `docs-supuestos`.
4. **Lanzar.** `bash .claude/scripts/agentes.sh crear <nombre> [--sin-front | --sin-back]` (unos 3 min con frontend), y luego el agente con la plantilla de abajo.
5. **Revisar.** Leer el reporte, `git -C <worktree> log --oneline <base>..` y el diff. `revisor-reglas` sobre el rango. Si hay hallazgos bloqueantes, `SendMessage` al mismo agente para que corrija.
6. **Integrar** en la carpeta principal, rama por rama: si la principal avanzó, `git -C <worktree> rebase <rama-principal>`; luego `git merge --ff-only agente/<nombre>`. Los conflictos los resuelve el orquestador. Al final: `bash .claude/scripts/agentes.sh tests` (suite completa), `npm run build` y `npm run lint` en `frontend/`.
7. **Cerrar.** `docs-supuestos` si cambió una regla, un supuesto, un endpoint o una pantalla. `bash .claude/scripts/agentes.sh borrar <nombre>`. `HANDOFF.md` si queda algo a medias. Resumen al usuario. **Sin push**: Franklin decide cuándo subir y avisar a César.

## Plantilla de prompt

```
Worktree: C:\dev\arranques-wt\<nombre>  (rama agente/<nombre>, base <sha>)
Objetivo: ...
RF / S: ...
Criterios de aceptación:
1. ...
Contrato: endpoints, tipos y pantallas (copiados del diseño, exactos)
Puedes editar: ...
No toques (lo tiene otro agente): ...
Verificación: ...
Entrega: commits pequeños en tu rama, sin push, y el reporte con el formato de tu definición.
```

## Git

- Las ramas de agente salen del HEAD de la carpeta principal. Mensajes `tipo(RF-xx, S-yy): ...` en español.
- Nunca push, `--force`, reescribir commits de César ni trabajar en `main`.
- Solo el orquestador integra.

## Entorno

- Worktrees en `C:\dev\arranques-wt` (variable `ARRANQUES_WT`), fuera de OneDrive. Cada uno tiene su `.env` y su base `inventario_test_<nombre>`. `agentes.sh tests` nunca toca `inventario` ni `inventario_test`. En la carpeta principal usa `inventario_test`.
- `.claude/settings.local.json` (no se versiona) da permiso de edición en esa carpeta. En otro equipo hay que crear el suyo con su ruta.
- Datos al 25-nov-2026 (verificar antes de usarlos): la suite tiene 630 tests en verde y tarda unos 195 s. El último RF es RF-30 (RF-31 está retirado) y el último supuesto, S-69. S-68 «Saldo» espera la decisión de César.
- Archivos calientes, que no se reparten a dos agentes a la vez: `routes/api.php`, `api/tipos.ts`, `App.tsx`, `Layout.tsx`, `tests/Pest.php`, `IngresoService.php`, `VentaService.php`, `CajaService.php`, `Venta.tsx`, `IngresoDetalle.tsx`.
