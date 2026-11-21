# Arranques y Alternadores la 8va

Fuente de verdad: `docs/requerimientos-v1.1.md` y `docs/adenda-v1.2-cartera-y-cuentas-por-pagar.md`. Donde se contradicen, manda la adenda. El cuaderno para personas está en `C:\Users\legri\OneDrive\Desktop\vaules obsidian\ARRANQUES Y ALTERNADORES`.
Cita los RF por número (RF-01, RF-02…) en commits (`feat(RF-06): ...`), en comentarios de migraciones y en nombres de tests. El v1.1 numera hasta RF-12. La adenda v1.2 numera RF-13 a RF-20 y reemplaza RF-04; usa esos números tal como están en ella y no inventes otros.

## Alcance (no negociable)

- Inventario, compras, cuentas por pagar, ventas de contado y a crédito, abonos, saldos de clientes y el vínculo entre remisiones y facturas de compra (alcance v1.2, aprobado).
- Contapyme se queda con la facturación electrónica DIAN y la contabilidad general. No se integra ni se modifica. Este sistema no factura (a lo sumo guarda el número de factura de Contapyme como texto de referencia).
- Los informes se consultan en pantalla. No se exportan.
- Fuera de alcance: órdenes de trabajo, mano de obra de taller, garantías, varias bodegas y contabilidad general.
- Si algo cae fuera de alcance, o una decisión técnica contradice una regla de negocio: **detenerse y preguntar**.
- Un pendiente no está resuelto. Si la respuesta depende de uno, decirlo y proponer un supuesto explícito.

## Estructura (monorepo)

- `backend/`: Laravel 12 (PHP 8.3), API REST bajo `/api`, Sanctum en modo SPA (cookies). Los comandos `composer`, `php artisan` y `pest` se corren dentro de `backend/`.
- `frontend/`: React + Vite + TypeScript, React Router y TanStack Query. Los comandos `npm` se corren dentro de `frontend/`.
- `docs/`: requerimientos, adenda y supuestos, compartidos.
- BD: MySQL 8.4, puerto **3307**, solo 127.0.0.1 (lo único que el código asume). La carpeta depende del equipo: `D:\mysql8` (César), `C:\mysql8` (Franklin). Se arranca con `<carpeta>\iniciar-mysql.cmd` y se detiene con `<carpeta>\detener-mysql.cmd`; esos scripts y `my.ini` viven en cada equipo, no en el repo. Si en el 3306 hay MariaDB de XAMPP, **no** se usa.
- Tests: Pest. Cada criterio de aceptación de un RF implementado tiene al menos un test.
- Código, tablas y columnas en español, snake_case.
- Commits pequeños.
- Esta carpeta es `C:\Users\legri\OneDrive\Desktop\arranques`. En otro equipo el mismo proyecto se nombró `D:\arranquesyalternadors`.

## Arquitectura (respetarla en todo cambio)

- Las dos apps son independientes y solo se hablan por HTTP. No se comparte código entre ellas ni se sirven pantallas desde Laravel (salvo el PDF de etiquetas).
- Backend, en un solo sentido: **Controller → Service → Model**. Detalle en `backend/README.md`.
  - Controllers: validan (FormRequest), delegan y responden (API Resources). Sin transacciones ni reglas de negocio.
  - Services: casos de uso y transacciones. Toda escritura de inventario pasa por `InventarioService`.
  - Models: relaciones, casts e integridad del propio registro (`Auditable`, `Inmutable`).
  - Reglas de negocio violadas → `ReglaInventarioException`. Configuración del dominio en `config/`.
- Frontend: `api/` (cliente y tipos), `auth/`, `hooks/`, `componentes/` y `paginas/<módulo>/`, con un módulo por rol. El estado del servidor vive solo en TanStack Query. El frontend muestra reglas, no las decide. Detalle en `frontend/README.md`.
- Al diseñar, entregar modelo de datos, endpoints o pantallas listos para implementar, con el RF que cubre cada parte.

## Reglas de negocio

1. **Código universal (RF-01).** Solo reguladores, portadiodos, algunas poleas y algunos automáticos (flag `categorias.admite_codigo_universal`). En reguladores el código de unificación es el de Transpo (ej. IM204). Fuera de ese esquema, la referencia original del proveedor es el código único. El backend rechaza `codigo_universal_id` en referencias de categorías que no lo admiten.
2. **Existencia agrupada (RF-02).** Universal = suma de sus referencias (`SUM(existencia)`, calculada, nunca guardada). Cada referencia y marca conserva la suya; siempre se muestra el desglose.
3. **Código de barras por UNIDAD FÍSICA (RF-03).** Del local, uno por unidad, único. Escanearlo descarga solo esa unidad.
4. **Etiquetas (RF-03).** PDF generado en backend, tres tamaños (grande, pequeño, más pequeño) elegidos por quien imprime.
5. **Remisión (RF-05).** Ingreso a mano, ítem por ítem, al mismo inventario.
6. **Factura de compra (RF-04 reemplazado, RF-20, RF-19).** No se lee el QR ni el XML. La factura se crea a mano igual que la remisión: tipo (`factura` | `remision`), proveedor, número y fecha. El mismo proveedor + tipo + número no entra dos veces (un borrador se reabre; uno confirmado se rechaza indicando el ingreso). Los ítems se agregan escaneando el `codigo_referencia` del proveedor del ingreso: si existe suma 1 a su línea; si no, se ofrece crear la referencia (logística puede crearla, no editarla). No se confirma con líneas sin costo. Una factura puede ligarse a una o varias remisiones del mismo proveedor para no duplicar inventario ni deuda (RF-19).
7. **Venta de mostrador (RF-06).** El descargue es la venta. Cada escaneo descuenta la unidad **de inmediato** (no al cerrar) y guarda el usuario. Puede ser de contado o a crédito. El crédito exige cliente y guarda el precio con que se vendió. No factura. **Ningún precio puede ser 0:** una referencia se crea y se edita con precio de venta > 0, y una unidad cuya referencia tenga precio 0 no se vende. De contado se cobra el precio estándar de la referencia, sin cambios. A crédito se aplica el estándar al escanear y, mientras la venta esté abierta, el precio de cada ítem vigente puede cambiarse a cualquier valor > 0, sin piso, con auditoría (RF-11). Al cerrar, los precios quedan fijos (S-18).
8. **Reverso (RF-07).** Las ventas NUNCA se borran. Devolución/anulación = movimiento nuevo ligado a la venta, con usuario, fecha y motivo obligatorio. Quitar un ítem de una venta abierta también es un reverso, no un DELETE. Abonos y pagos se anulan igual: movimiento nuevo, sin borrar el original.
9. **Auditoría (RF-11).** Toda edición guarda tabla, registro, campo, valor anterior, valor nuevo, usuario y fecha.
10. **Escaneado o digitado (RF-12).** Cada movimiento registra `metodo_captura` (`escaneado` | `digitado`). Si el escáner falla: digitar el código o buscar por referencia/universal y elegir la unidad.
11. **Descuento y sin IVA (RF-09, RF-10, RF-16).** Remisiones y facturas por igual: cada línea tiene su descuento en % y su IVA (19, 5, 0…; 0 = sin IVA, copiado de la referencia y editable en borrador); el descuento del documento se digita en $ o en % y se prorratea entre todas las líneas por su neto antes del IVA (S-21, S-22); el total se calcula solo en `App\Support\TotalesIngreso` (bcmath, redondeo al peso en un único método) y se guarda al confirmar. Todo ingreso es de contado o a crédito; a crédito, el vencimiento se digita tal como lo imprime el documento (el plazo del proveedor solo lo prellena). Confirmado, no cambian total, forma de pago, vencimiento ni descuento.
12. **Agotados por proveedor (RF-08).** Referencias con existencia 0 de un proveedor.
13. **Saldos (adenda).** Cliente = ventas a crédito cerradas, netas de reversos, menos abonos no anulados. Proveedor = ingresos a crédito confirmados menos pagos no anulados. Una remisión cubierta por una factura confirmada no se cuenta dos veces.
14. **Roles.** `admin`, `logistica`, `mostrador`. Todo endpoint protegido por rol con middleware.
15. **Velocidad es requisito.** Índice en toda columna de búsqueda/filtro, sin N+1 (eager loading), paginación en servidor.
16. **Informes.** Solo en pantalla. Sin exportar.

## Reglas técnicas

- `referencias.existencia` se modifica SOLO en la misma transacción que crea/cambia unidades, vía un único servicio de inventario. `lockForUpdate()` sobre la unidad para que dos mostradores no vendan la misma.
- Comando `inventario:recalcular`: compara el contador contra `COUNT(unidades disponibles)` y reporta diferencias.
- Ventas, venta_items, reversos, movimientos_inventario y auditoria son inmutables: el modelo lanza excepción en `updating`/`deleting` (salvo el cambio de estado permitido de la venta) y no hay rutas DELETE. Única excepción en venta_items: `precio_unitario`, y solo ese campo, mientras la venta es a crédito y está abierta, vía `VentaService::cambiarPrecio`, que bloquea la venta y deja la fila de auditoría (tabla `venta_items`) en la misma transacción; el guard de `VentaItem` rechaza cualquier otra edición. Abonos y pagos, cuando existan, siguen esa misma regla.
- Auditoría con trait reutilizable `Auditable` en referencias, codigos_universales, marcas, proveedores, categorias, clientes, ingresos y users.
- Código de barras: Code128, prefijo del local + consecutivo con ceros (ej. `A8000001`), generado de forma segura ante concurrencia.
- Frontend: ráfaga de teclas < 30 ms terminada en Enter = `escaneado`; escrito a mano o elegido en búsqueda = `digitado`.
- Ningún nombre de marca en el código (las marcas son catálogo editable; solo aparecen en seeders).

## Supuestos (provisionales — detalle en `docs/supuestos.md`)

- **Etiqueta en piezas baratas de alto volumen:** por ahora todo lleva etiqueta por unidad. No implementar modo "referencia + cantidad", pero la existencia no debe depender de lógica que impida agregarlo.
- **Poleas y automáticos en el universal:** la categoría tiene el flag; el admin decide referencia por referencia.
- **Tercera marca "Jota":** marcas = catálogo editable; el seeder usa una marca de ejemplo.
- **Tiempo de respuesta:** escaneo → descuento confirmado < 300 ms p95 en red local; búsquedas < 500 ms. Log de duración en endpoints de escaneo y búsqueda. El número en segundos que pide el local sigue sin cerrarse.
- **Tamaños de etiqueta:** `backend/config/etiquetas.php`: grande 100×50 mm, pequeño 50×25 mm, más pequeño 38×19 mm.
- **Ingreso por escaneo (RF-04, RF-20):** el QR y el XML se descartaron (S-06 superado). El código que se escanea en un ingreso es el `codigo_referencia` del proveedor, buscado solo entre sus referencias (S-19; falta confirmar qué código traen las cajas). La referencia nueva se crea con precio de venta > 0 fijado por quien la crea (S-20). El costo de una línea nueva se prellena con el último costo confirmado de la referencia.
- **Cartera (adenda):** este sistema la lleva. Falta confirmar con Frank y el contador que Contapyme no la lleve también. Mientras tanto, no diseñar una integración con Contapyme. Solo las ventas a crédito cerradas cuentan para la cartera (S-09); plazo por defecto de clientes 30 días (S-11).
- **Precio e IVA de venta (RF-14):** precio = `referencias.precio_venta` al escanear (S-10), siempre > 0; precio libre solo en crédito abierto (S-18); IVA 19 % salvo que la referencia tenga 0 (S-15).
- **Remisión parcial y vínculo equivocado:** sin decisión. No implementar facturación parcial ni un desvínculo de algo ya confirmado hasta cerrarlo.
- **Descuentos o precios libres en mostrador:** decidido (S-18). Contado, precio estándar sin cambios; crédito, precio libre > 0 por ítem mientras la venta está abierta. No agregar descuentos por porcentaje ni otros mecanismos por cuenta propia.

## Pendientes

1. Confirmar con Frank y el contador que Contapyme no llevará también la cartera de lo que factura.
2. Confirmar qué código traen los productos o sus cajas (S-19) y quién fija el precio de venta de una referencia nueva (S-20).
3. Confirmar con el contador el tratamiento de los descuentos (S-22) y cómo se redondea el IVA de una compra: por documento (hoy) o por línea (S-21).
4. Confirmar si una remisión puede facturarse parcialmente y cómo se corrige un vínculo equivocado ya confirmado.
5. Etiqueta por unidad o por referencia + cantidad en piezas baratas de alto volumen.
6. Cuáles poleas y automáticos entran al código universal.
7. Nombre de la tercera marca (en el audio suena «Jota»).
8. Tiempo de respuesta objetivo en segundos.
