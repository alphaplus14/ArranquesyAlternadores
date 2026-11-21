# Adenda v1.2 — Compras, cuentas por pagar, ventas a crédito, cartera y vínculo remisión–factura

Estado: **APROBADA** (20-nov-2026), con cambios el 21-nov-2026: precios (RF-14, S-10, S-18), ingreso manual de facturas con ítems por escaneo (RF-04 reemplazado, RF-20, S-19, S-20) y dos ajustes tomados de la factura real FEV 1545 del Project (RF-09, RF-16, S-21). Complementa `toma-requerimientos-arranques-alternadores-v1.1.md` y reemplaza lo que ese documento dice sobre cartera, reportes y lectura del QR. La implementación la hace Claude (Claude Code) sobre el repositorio `D:\arranquesyalternadors`. Esta versión reemplaza la captura de trabajo que había en `docs/`.

## 1. Cambio de alcance

| Tema | v1.1 | v1.2 |
|---|---|---|
| Facturación electrónica DIAN | Contapyme | Contapyme (sin cambio). El sistema no factura ni se integra. |
| Cartera de clientes (abonos, saldos) | Fuera, en Contapyme | **Dentro** |
| Cuentas por pagar a proveedores | Fuera, en Contapyme | **Dentro** |
| Compras ingresadas | Solo inventario | Inventario + valor (informe) |
| Ventas a crédito | No existían | **Dentro** |
| Vínculo remisión–factura de compra | No existía | **Dentro** |
| Lectura del QR / XML de la factura de compra | RF-04 (propuesta XML, luego QR) | **Descartada.** La factura se registra a mano, igual que la remisión. |
| Reportes | Solo agotados por proveedor | Agotados + informes de compras, ventas a crédito, cartera, cuentas por pagar y remisiones pendientes de facturar |
| Formato de salida | — | Ninguno: los informes se consultan en pantalla |

Siguen fuera: órdenes de trabajo, mano de obra, garantías, varias bodegas y contabilidad general.

Se actualizan: sección 0, sección 4 (actores), reglas 5 y 10 de la sección 6, sección 8 y pendientes 1, 2 y 4 de la v1.1 (la cartera ya no queda en Contapyme y ya no hay QR que probar).

## 2. Requerimientos nuevos

### RF-04 (reemplazado). Ingreso manual de facturas de compra
Se descarta leer el QR o el XML de la factura. Logística crea el ingreso a mano: elige el tipo (`factura` o `remision`), el proveedor, digita el número del documento y la fecha, y agrega los ítems escaneándolos (RF-20). El mismo número de documento del mismo proveedor y del mismo tipo no entra dos veces: si ya hay un borrador se reabre y si ya está confirmado se rechaza con un mensaje que indica el ingreso existente.
**Aceptación.** Se crea una factura con proveedor, número y fecha sin ningún QR. Repetir proveedor, tipo y número no crea un segundo ingreso.

### RF-09 (ajuste). Descuento del documento e IVA
Cuando una factura trae un descuento sobre el total, el IVA se calcula sobre lo que queda después del descuento. El descuento del documento se prorratea entre las líneas, en proporción al subtotal de cada una, para calcular la base del IVA de cada línea. Así el total del sistema coincide con el de la factura.
**Aceptación.** Con la factura real FEV 1545 (5 líneas, subtotal 735.000, descuento del documento 110.250, que es el 15 %, IVA 19 %), el sistema calcula una base de 624.750, IVA 118.703 y total 743.453. Se admite una diferencia de unos pocos pesos por redondeo. Entrar el mismo 15 % como descuento en cada línea da el mismo total.

### RF-13. Clientes
Catálogo de clientes con nombre, documento (opcional, único), teléfono y plazo de crédito en días (por defecto 30). Toda edición queda auditada (RF-11).
**Aceptación.** Se crea un cliente; al cambiar su plazo, la auditoría muestra valor anterior, nuevo, usuario y fecha.

### RF-14. Venta a crédito y precios
La venta de mostrador puede ser `contado` (por defecto, igual que hoy) o `credito`. El crédito exige cliente. Cada ítem guarda el precio y el IVA con que se vendió. Al cerrar una venta a crédito se fija su vencimiento: fecha de cierre + plazo del cliente. Se puede anotar de forma opcional el número de factura de Contapyme, solo como texto de referencia.

**Precios.**
- Un precio nunca puede ser 0. El precio de venta de una referencia debe ser mayor que 0 al crearla o editarla, y una unidad cuya referencia no tenga precio no se puede vender.
- En la venta de **contado** el precio es el estándar de la referencia y no se cambia.
- En la venta a **crédito** el precio es libre: al escanear se aplica el estándar y, mientras la venta esté abierta, se puede cambiar el precio de cada ítem por cualquier valor mayor que 0. Cada cambio queda auditado con valor anterior, valor nuevo, usuario y fecha (RF-11). Al cerrar la venta, los precios quedan fijos.

**Aceptación.**
1. Una venta a crédito sin cliente se rechaza.
2. Cambiar el precio de la referencia después no cambia el valor de ventas ya hechas.
3. Una venta de contado se escanea igual que hoy, sin pedir cliente, y no admite cambiar el precio.
4. Escanear una unidad de una referencia sin precio se rechaza con un mensaje claro.
5. En una venta a crédito abierta se cambia el precio de un ítem y la auditoría muestra el valor anterior, el nuevo, quién y cuándo.
6. No se puede poner precio 0 ni negativo, ni cambiar el precio de un ítem reversado ni de una venta cerrada.

### RF-15. Abonos de clientes y saldo
Un abono es un movimiento del cliente: fecha, valor, medio de pago, referencia opcional, usuario. Nunca se borra ni se edita; anular un abono es un movimiento nuevo con usuario, fecha y motivo (misma lógica de RF-07). El saldo del cliente es la suma de sus ventas a crédito cerradas, netas de reversos, menos sus abonos no anulados. Si el saldo es negativo se muestra como saldo a favor. La antigüedad se calcula aplicando los abonos a las ventas más antiguas (FIFO), sin guardar la asignación.
**Aceptación.** Venta a crédito de 100 y abono de 40 dejan saldo 60. Reversar un ítem de la venta baja el saldo. Anular el abono lo devuelve a 100 y el abono sigue visible.

### RF-16. Condición de pago en compras (cuentas por pagar)
Todo ingreso (remisión o factura) se registra de `contado` o a `credito`. El crédito lleva la **fecha de vencimiento** que trae el documento: la factura real FEV 1545 imprime la forma de pago (crédito) y su fecha de vencimiento. Se prellena con fecha + plazo del proveedor, si el proveedor lo tiene, y se puede corregir. Al confirmar el ingreso se guarda su total (subtotal, descuento, IVA, total) con el cálculo de RF-09 (ajuste). Solo los ingresos a crédito generan deuda.
**Aceptación.** Un ingreso a crédito confirmado aparece en las cuentas por pagar con su vencimiento. Uno de contado no.

### RF-17. Pagos a proveedores y saldo
Mismo esquema que RF-15 aplicado a proveedores: pagos inmutables, anulación como movimiento nuevo, saldo = ingresos a crédito confirmados − pagos no anulados, antigüedad FIFO.
**Aceptación.** Ingreso a crédito de 500 y pago de 200 dejan saldo 300. Anular el pago lo devuelve a 500.

### RF-18. Informes (solo en pantalla)
1. **Compras** por período y proveedor: subtotal, descuento, IVA, sin IVA y total, con totales del período.
2. **Ventas a crédito** por período y cliente, netas de reversos.
3. **Cartera de clientes**: saldo por cliente con antigüedad (al día, 1-30, 31-60, 61-90, más de 90 días vencidos) a una fecha de corte.
4. **Cuentas por pagar**: lo mismo por proveedor.
5. **Estado de cuenta** de un cliente o proveedor: facturas, reversos, abonos o pagos y anulaciones en orden de fecha.
6. **Remisiones pendientes de facturar** por proveedor, con valor provisional y antigüedad, y **diferencias remisión–factura** (RF-19).
**Aceptación.** Los totales de cada informe coinciden con la suma de sus movimientos. Las ventas reversadas no cuentan. Una remisión cubierta por una factura confirmada no se cuenta dos veces.

### RF-19. Vínculo remisión–factura de compra
Una factura de compra puede ligarse a una o varias remisiones confirmadas del mismo proveedor, para no duplicar inventario ni deuda.

- Al crear la factura en borrador, logística elige entre las remisiones confirmadas del mismo proveedor que aún no tienen factura. El sistema las propone ordenadas por fecha.
- **Prellenado:** un botón copia a la factura las líneas de las remisiones ligadas, sumadas por referencia. Logística solo ajusta costo, descuento, IVA y cantidades según la factura.
- **Al confirmar la factura**, por cada referencia: `cantidad_cubierta` = lo recibido por las remisiones ligadas, hasta lo facturado. Solo se crean unidades nuevas (y etiquetas) por `cantidad − cantidad_cubierta`. Las unidades de las remisiones no se tocan.
- **Diferencias.** Si se facturó menos de lo recibido, o una referencia de la remisión no aparece en la factura, la confirmación exige aceptar la diferencia explícitamente con un motivo. Queda registrada, con quién la aceptó, y se lista en el informe de diferencias.
- **Sin doble conteo.** Una remisión ligada a una factura confirmada queda excluida de compras y de cuentas por pagar; cuenta la factura. Una remisión ligada a una factura todavía en borrador sigue contando hasta que esa factura se confirme.
- **Solo en borrador.** Mientras la factura está en borrador se puede ligar y desligar. Al confirmarla, el vínculo es definitivo.
- Cada cambio del vínculo queda en la auditoría (RF-11).

**Aceptación.**
1. Remisión de 4 unidades y factura ligada de 4 de la misma referencia: al confirmar no se crean unidades y la existencia sigue en 4.
2. Factura de 5 contra remisión de 4: se crea 1 unidad nueva y la existencia pasa a 5.
3. Factura de 3 contra remisión de 4: exige aceptar la diferencia y la deja listada.
4. No se puede ligar una remisión de otro proveedor, ya ligada a otra factura o sin confirmar.
5. Remisión ligada a factura confirmada no suma dos veces en compras ni en cuentas por pagar.
6. El prellenado suma por referencia las líneas de varias remisiones.

### RF-20. Ingreso de ítems por escaneo y creación de referencias al vuelo
En un ingreso en borrador (factura o remisión) los ítems se agregan escaneando el código que trae el producto. El sistema lo busca entre las referencias **del proveedor del ingreso**.

- Si existe, agrega la línea con cantidad 1; escanear el mismo código otra vez suma 1 a esa línea. La cantidad, el costo, el descuento y el IVA se corrigen en la tabla. El costo se prellena con el último registrado de esa referencia, si lo hay.
- Si no existe, el sistema ofrece **crear la referencia** con el código ya precargado: marca, categoría, descripción, precio de venta (mayor que 0), IVA de venta y, si la categoría lo admite, código universal. Al guardarla, la línea se agrega sola.
- Logística puede **crear** referencias; editarlas o desactivarlas sigue siendo del admin.
- No se puede confirmar un ingreso con líneas sin costo (costo mayor que 0).

**Aceptación.**
1. Escanear un código existente agrega la línea; escanearlo de nuevo suma 1.
2. Escanear un código inexistente ofrece crear la referencia con el código precargado, y tras crearla la línea queda agregada.
3. Logística crea una referencia pero no puede editarla.
4. Crear una referencia con el mismo código para el mismo proveedor se rechaza.
5. Confirmar con una línea sin costo se rechaza.

## 3. Modelo de datos (listo para migraciones)

Todas las columnas de búsqueda llevan índice (regla 13). Las tablas de movimientos son inmutables, con el trait `Inmutable`, y no tienen rutas DELETE.

- `clientes`: `id`, `nombre` (150, index), `documento` (30, único, nullable), `telefono` (30, nullable), `plazo_dias` (smallint, 30), `activo` (bool, true), timestamps. `Auditable`. (RF-13)
- `referencias`: + `iva_venta_porcentaje` decimal(5,2) default 19 (0 = sin IVA). `precio_venta` debe ser > 0 (validación en la API). Ya es único `(proveedor_id, codigo_referencia)`. (RF-14, RF-20)
- `ventas`: + `forma_pago` enum(`contado`,`credito`) default `contado`, `cliente_id` FK nullable, `vence_el` date nullable, `factura_contapyme` string(40) nullable. Índice `(forma_pago, cliente_id, vence_el)`. El modelo solo permite cambiar `estado`, `cerrada_at` y `vence_el` al cerrar. (RF-14)
- `venta_items`: + `precio_unitario` decimal(12,2) nullable, `iva_porcentaje` decimal(5,2) nullable. Nullable porque las ventas anteriores no tienen precio; no se rellenan. Es inmutable salvo una excepción: `precio_unitario` se puede cambiar mientras la venta esté abierta y sea a crédito, siempre con auditoría. (RF-14)
- `abonos_clientes`: `id`, `cliente_id` FK index, `fecha` date, `valor` decimal(14,2) > 0, `medio` string(30), `referencia` string(60) nullable, `nota` string(255) nullable, `usuario_id`, `created_at`. Índice `(cliente_id, fecha)`. Inmutable. (RF-15)
- `abono_anulaciones`: `id`, `abono_id` único FK, `motivo` string(500), `usuario_id`, `created_at`. Inmutable. (RF-15)
- `proveedores`: + `plazo_dias` smallint nullable (solo para prellenar el vencimiento). (RF-16)
- `ingresos`: `tipo` pasa a enum(`remision`,`factura`) (antes `factura_qr`); **se eliminan** `cufe`, `subtotal_qr`, `iva_qr`, `total_qr` y `diferencia_qr`; **único** `(proveedor_id, tipo, numero_documento)`; se conservan `confirmado_por`, `diferencia_aceptada` y `diferencia_motivo` para las diferencias de RF-19. + `forma_pago` enum(`contado`,`credito`) default `contado`, `vence_el` date nullable (digitado o prellenado), `total` decimal(14,2) nullable (se fija al confirmar), `factura_id` FK nullable a `ingresos.id` (solo en remisiones, index). Índice `(forma_pago, proveedor_id, vence_el)`. `Ingreso` pasa a ser `Auditable` (cierra el hueco de RF-11 en ingresos). (RF-04, RF-16, RF-19)
- `ingreso_items`: + `cantidad_cubierta` unsigned int default 0 (se fija al confirmar una factura ligada). El costo debe ser > 0 al confirmar. (RF-19, RF-20)
- `pagos_proveedores` y `pago_anulaciones`: iguales a `abonos_clientes` y `abono_anulaciones`, con `proveedor_id`. (RF-17)

## 4. Endpoints

| Método y ruta | Rol | RF |
|---|---|---|
| `POST /ingresos` (con `tipo`: `factura` o `remision`) | logística | RF-04 |
| `POST /ingresos/{ingreso}/escanear` (`codigo`; agrega o suma 1; 404 con código leído si no existe la referencia) | logística | RF-20 |
| `POST /referencias` | admin, logística | RF-20 |
| `GET /clientes` | admin, logística, mostrador | RF-13 |
| `POST /clientes` | admin, mostrador | RF-13 |
| `PUT /clientes/{id}` | admin | RF-13 |
| `POST /ventas` (con `forma_pago`, `cliente_id`) | mostrador | RF-14 |
| `PATCH /ventas/{venta}/items/{item}` (`precio_unitario`, solo venta a crédito abierta) | mostrador | RF-14 |
| `POST /clientes/{id}/abonos` | admin, mostrador | RF-15 |
| `POST /abonos/{id}/anulaciones` (motivo obligatorio) | admin | RF-15 |
| `POST /ingresos` y `PUT /ingresos/{id}` aceptan `forma_pago`, `vence_el` | logística | RF-16 |
| `GET /ingresos/{factura}/remisiones-disponibles` | logística | RF-19 |
| `POST /ingresos/{factura}/remisiones` (`remision_ids[]`) y `DELETE /ingresos/{factura}/remisiones/{remision}` (solo en borrador) | logística | RF-19 |
| `POST /ingresos/{factura}/prellenar` | logística | RF-19 |
| `POST /ingresos/{id}/confirmar` acepta `aceptar_diferencia` y `motivo` para diferencias de remisión | logística | RF-19 |
| `POST /proveedores/{id}/pagos` | admin | RF-17 |
| `POST /pagos/{id}/anulaciones` | admin | RF-17 |
| `GET /reportes/compras?desde&hasta&proveedor_id` | admin | RF-18 |
| `GET /reportes/ventas-credito?desde&hasta&cliente_id` | admin | RF-18 |
| `GET /reportes/cartera-clientes?corte` | admin | RF-18 |
| `GET /reportes/cuentas-por-pagar?corte` | admin | RF-18 |
| `GET /reportes/remisiones-pendientes` y `GET /reportes/diferencias-remision` | admin | RF-18, RF-19 |
| `GET /clientes/{id}/estado-cuenta` y `GET /proveedores/{id}/estado-cuenta` | admin | RF-18 |

Se elimina `POST /ingresos/qr`.

Servicios nuevos: `CarteraService` (saldos, FIFO, estado de cuenta, clientes y proveedores), `AbonoService` / `PagoService` (movimientos) y la lógica de vínculo dentro de `IngresoService` (`ligarRemisiones`, `prellenar`, y el cálculo de `cantidad_cubierta` al confirmar). `IngresoService` suma `escanear`. `VentaService` suma `cambiarPrecio`. `Ingreso::totalCalculado()` se ajusta a RF-09. `InventarioService::darEntrada` debe crear unidades solo por `cantidad − cantidad_cubierta`. Los movimientos de dinero no tocan existencias. Se elimina `FacturaQrService`.

## 5. Orden de construcción

1. RF-13 y RF-14 (clientes, precio en la venta, venta a crédito) y el ajuste de precios libres a crédito.
2. RF-04 reemplazado y RF-20 (ingreso manual con escaneo y creación de referencias; retiro del QR).
3. RF-09 (ajuste) y RF-16 (descuento del documento prorrateado, forma de pago, vencimiento y `total` al confirmar).
4. RF-15 (abonos, saldo, anulación).
5. RF-19 (vínculo remisión–factura).
6. RF-17 (pagos a proveedores).
7. RF-18 (informes y pantallas).

Cada paso con un test Pest por criterio de aceptación y commit `feat(RF-xx)`.

## 6. Choques con reglas actuales

- **Regla 5 de la v1.1.** Dejó de aplicar: ya no se procesa el XML ni se lee el QR. Logística sí digita el número del documento y escanea los ítems. Sin lectura del QR no hay control automático contra el total de la factura: un error de digitación no se detecta. Queda como mejora posible un campo opcional "total del documento" para cuadrar al confirmar.
- **Descuento del documento.** Hoy `Ingreso::totalCalculado()` resta el descuento del documento pero calcula el IVA sobre las líneas sin descontarlo. Con la factura FEV 1545 daría 764.400 en lugar de 743.453, es decir, 20.947 de más, porque el IVA quedaría sobreestimado. Hay que corregirlo según RF-09 (ajuste) antes de construir cuentas por pagar e informes de compras sobre ese total.
- **Redondeo del IVA.** La factura FEV 1545 imprime un IVA de 118.703 calculado por documento, mientras que la suma de los IVA unitarios impresos da 118.709. Una diferencia de unos pesos entre el sistema y la factura es normal y no debe bloquear la confirmación.
- **Cartera en dos sistemas.** Contapyme también puede llevar cartera de lo que factura. Sin integración, los dos libros pueden diferir. `factura_contapyme` ayuda a conciliar a mano.
- **Reversos.** El valor a crédito de una venta es neto de reversos.
- **Ventas existentes.** Las anteriores a este cambio no tienen precio y no entran con valor en los informes.
- **Ingreso confirmado inmodificable.** El único campo que cambia en una remisión confirmada es `factura_id`, solo desde `IngresoService` y auditado.
- **Costos de la remisión.** Son provisionales; el valor definitivo es el de la factura. Los informes toman el de la factura.
- **Inmutabilidad de `venta_items`.** El precio libre a crédito es una excepción deliberada y acotada: solo `precio_unitario`, solo en venta a crédito abierta, siempre auditada. La alternativa es anular el ítem y volver a escanearlo, que es más lenta en el mostrador.
- **Referencias con precio 0 ya existentes.** La validación nueva no corrige las que ya están en 0: quedan bloqueadas para la venta hasta que el admin les ponga precio.
- **Referencias creadas por logística.** El precio de venta es obligatorio al crearlas (regla de precios). Si logística no lo conoce al recibir, tiene que consultarlo con el admin antes de crear la referencia.

## 7. Supuestos explícitos

| # | Supuesto |
|---|---|
| S-06 | **Superado (21-nov-2026):** ya no se lee el QR ni el XML. Ver RF-04 reemplazado. |
| S-09 | Solo las ventas a crédito **cerradas** cuentan para la cartera. |
| S-10 | Contado: precio estándar de la referencia, sin cambios. Crédito: se aplica el estándar al escanear y se puede cambiar, con auditoría, mientras la venta esté abierta. Ningún precio puede ser 0. |
| S-11 | Plazo por defecto de clientes: 30 días. |
| S-12 | Los abonos se aplican FIFO a las ventas más antiguas; no se elige a qué venta se abona. |
| S-13 | Rangos de antigüedad: al día, 1-30, 31-60, 61-90 y más de 90 días vencidos. |
| S-14 | Abonos de clientes: admin y mostrador. Pagos a proveedores e informes: solo admin. |
| S-15 | IVA de venta 19 % salvo que la referencia tenga 0. |
| S-16 | Una remisión se factura completa en una sola factura; una factura puede cubrir varias remisiones. Lo que no cuadre se trata como diferencia aceptada. |
| S-17 | El vínculo se hace con la factura en borrador y es definitivo al confirmarla. No hay desvinculación posterior. |
| S-18 | El precio libre a crédito no tiene piso: solo debe ser mayor que 0. Pueden cambiarlo mostrador y admin. |
| S-19 | El código que se escanea en un ingreso es el `codigo_referencia` del proveedor (el que trae el producto o su caja). Se busca solo entre las referencias del proveedor del ingreso. La factura real FEV 1545 trae una columna "Código" con ese tipo de código (AR-015, AP006, AP013, TP019, 9014A), lo que lo hace plausible, pero falta confirmar qué código traen las cajas. |
| S-20 | Una referencia nueva se crea con precio de venta mayor que 0, fijado por quien la crea (logística o admin). |
| S-21 | El descuento del documento se prorratea entre las líneas por su subtotal para calcular la base del IVA (RF-09, ajuste). El vencimiento de una compra a crédito se digita tal como lo imprime la factura. |

## 8. Pendientes

1. Confirmar con Frank y el contador que Contapyme no llevará también la cartera de lo que factura.
2. Confirmar si se factura parcialmente una remisión (S-16) y cómo se corrige un vínculo equivocado ya confirmado (S-17).
3. Confirmar si el precio libre a crédito necesita un piso o un permiso especial (S-18).
4. Confirmar qué código traen los productos o sus cajas (S-19). Si es un código de barras distinto de la referencia, hay que guardarlo en la referencia para poder escanearlo.
5. Confirmar quién fija el precio de venta de una referencia nueva (S-20) y si se quiere el campo opcional "total del documento" para cuadrar la factura al confirmar.
6. Confirmar con el contador cómo debe redondearse el IVA de una compra (por documento o por línea).
7. Pendientes de v1.1 que siguen abiertos: etiqueta por unidad en piezas baratas, poleas y automáticos en el universal, tercera marca y tiempo de respuesta objetivo.
