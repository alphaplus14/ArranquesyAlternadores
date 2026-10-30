# Inventario — Arranques y Alternadores la 8va

Fuente de verdad: `docs/requerimientos-v1.1.md`.
Cita los RF por número (RF-01, RF-02…) en commits (`feat(RF-06): ...`), en comentarios de migraciones y en nombres de tests.

## Alcance (no negociable)

- SOLO inventario. No factura, no lleva contabilidad ni cartera: eso es Contapyme, que no se integra ni se toca.
- Fuera de alcance: órdenes de trabajo, mano de obra de taller, garantías, varias bodegas, reportes distintos a agotados por proveedor.
- Si algo cae fuera de alcance, o una decisión técnica contradice una regla de negocio: **detenerse y preguntar**.

## Estructura (monorepo)

- `backend/`: Laravel 12 (PHP 8.3), API REST bajo `/api`, Sanctum en modo SPA (cookies). Los comandos `composer`, `php artisan` y `pest` se corren dentro de `backend/`.
- `frontend/`: React + Vite + TypeScript, React Router y TanStack Query. Los comandos `npm` se corren dentro de `frontend/`.
- `docs/`: requerimientos y supuestos, compartidos.
- BD: MySQL 8.4 en `D:\mysql8`, puerto 3307 (arranque: `D:\mysql8\iniciar-mysql.cmd`). En el 3306 corre MariaDB de XAMPP, que **no** se usa.
- Tests: Pest. Cada criterio de aceptación de un RF implementado tiene al menos un test.
- Código, tablas y columnas en español, snake_case.
- Commits pequeños.

## Arquitectura (respetarla en todo cambio)

- Las dos apps son independientes y solo se hablan por HTTP. No se comparte código entre ellas ni se sirven pantallas desde Laravel (salvo el PDF de etiquetas).
- Backend, en un solo sentido: **Controller → Service → Model**. Detalle en `backend/README.md`.
  - Controllers: validan (FormRequest), delegan y responden (API Resources). Sin transacciones ni reglas de negocio.
  - Services: casos de uso y transacciones. Toda escritura de inventario pasa por `InventarioService`.
  - Models: relaciones, casts e integridad del propio registro (`Auditable`, `Inmutable`).
  - Reglas de negocio violadas → `ReglaInventarioException`. Configuración del dominio en `config/`.
- Frontend: `api/` (cliente y tipos), `auth/`, `hooks/`, `componentes/` y `paginas/<módulo>/`, con un módulo por rol. El estado del servidor vive solo en TanStack Query. El frontend muestra reglas, no las decide. Detalle en `frontend/README.md`.

## Reglas de negocio

1. **Código universal (RF-01).** Solo reguladores, portadiodos, algunas poleas y algunos automáticos (flag `categorias.admite_codigo_universal`). En reguladores el código de unificación es el de Transpo (ej. IM204). Fuera de ese esquema, la referencia original del proveedor es el código único. El backend rechaza `codigo_universal_id` en referencias de categorías que no lo admiten.
2. **Existencia agrupada (RF-02).** Universal = suma de sus referencias (`SUM(existencia)`, calculada, nunca guardada). Cada referencia y marca conserva la suya; siempre se muestra el desglose.
3. **Código de barras por UNIDAD FÍSICA (RF-03).** Del local, uno por unidad, único. Escanearlo descarga solo esa unidad.
4. **Etiquetas (RF-03).** PDF generado en backend, tres tamaños (grande, pequeño, más pequeño) elegidos por quien imprime.
5. **Remisión (RF-05).** Ingreso a mano, ítem por ítem, al mismo inventario.
6. **Venta de mostrador (RF-06).** El descargue es la venta. Cada escaneo descuenta la unidad **de inmediato** (no al cerrar) y guarda el usuario. No factura.
7. **Reverso (RF-07).** Las ventas NUNCA se borran. Devolución/anulación = movimiento nuevo ligado a la venta, con usuario, fecha y motivo obligatorio. Quitar un ítem de una venta abierta también es un reverso, no un DELETE.
8. **Auditoría (RF-11).** Toda edición guarda tabla, registro, campo, valor anterior, valor nuevo, usuario y fecha.
9. **Escaneado o digitado (RF-12).** Cada movimiento registra `metodo_captura` (`escaneado` | `digitado`). Si el escáner falla: digitar el código o buscar por referencia/universal y elegir la unidad.
10. **Descuento y sin IVA (RF-09, RF-10).** El ingreso registra el descuento de la factura y las líneas sin IVA.
11. **Agotados por proveedor (RF-08).** Referencias con existencia 0 de un proveedor.
12. **Roles.** `admin`, `logistica`, `mostrador`. Todo endpoint protegido por rol con middleware.
13. **Velocidad es requisito.** Índice en toda columna de búsqueda/filtro, sin N+1 (eager loading), paginación en servidor.

## Reglas técnicas

- `referencias.existencia` se modifica SOLO en la misma transacción que crea/cambia unidades, vía un único servicio de inventario. `lockForUpdate()` sobre la unidad para que dos mostradores no vendan la misma.
- Comando `inventario:recalcular`: compara el contador contra `COUNT(unidades disponibles)` y reporta diferencias.
- Ventas, venta_items, reversos, movimientos_inventario y auditoria son inmutables: el modelo lanza excepción en `updating`/`deleting` (salvo el cambio de estado permitido de la venta) y no hay rutas DELETE.
- Auditoría con trait reutilizable `Auditable` en referencias, codigos_universales, marcas, proveedores, categorias y users.
- Código de barras: Code128, prefijo del local + consecutivo con ceros (ej. `A8000001`), generado de forma segura ante concurrencia.
- Frontend: ráfaga de teclas < 30 ms terminada en Enter = `escaneado`; escrito a mano o elegido en búsqueda = `digitado`.
- Ningún nombre de marca en el código (las marcas son catálogo editable; solo aparecen en seeders).

## Supuestos (provisionales — detalle en `docs/supuestos.md`)

- **Etiqueta en piezas baratas de alto volumen:** por ahora todo lleva etiqueta por unidad. No implementar modo "referencia + cantidad", pero la existencia no debe depender de lógica que impida agregarlo.
- **Poleas y automáticos en el universal:** la categoría tiene el flag; el admin decide referencia por referencia.
- **Tercera marca "Jota":** marcas = catálogo editable; el seeder usa una marca de ejemplo.
- **Tiempo de respuesta:** escaneo → descuento confirmado < 300 ms p95 en red local; búsquedas < 500 ms. Log de duración en endpoints de escaneo y búsqueda.
- **Tamaños de etiqueta:** `backend/config/etiquetas.php`: grande 100×50 mm, pequeño 50×25 mm, más pequeño 38×19 mm.
- **QR/XML (RF-04):** NO se implementa. Solo `ingresos.tipo` (`remision` | `factura_xml`) y `ingresos.cufe` nullable único.
- **Cartera:** fuera de alcance (pendiente confirmar con Frank, no afecta la Fase 1).
