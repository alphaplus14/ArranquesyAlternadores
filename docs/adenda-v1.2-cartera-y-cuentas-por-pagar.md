# Adenda v1.2 — cartera y cuentas por pagar

Captura de trabajo del 20 de noviembre de 2026. El archivo original de la adenda no está en este repositorio; este texto guarda el alcance y las reglas que mandan cuando contradicen `docs/requerimientos-v1.1.md`. Si aparece el original con los RF-13 a RF-18 redactados, se reemplaza esta captura y no se inventan esos números mientras tanto.

| | |
|---|---|
| Manda sobre | `docs/requerimientos-v1.1.md`, solo en lo que se contradicen |
| Estado | Vigente como contexto de trabajo. Los pendientes de abajo no están resueltos. |

## Alcance

El sistema lleva inventario, compras, cuentas por pagar, ventas a crédito, abonos, saldos de clientes y el vínculo entre remisiones y facturas de compra.

Contapyme se queda con la facturación electrónica DIAN y la contabilidad general. No se integra ni se modifica. Este sistema no factura.

Los informes se consultan en pantalla. No se exportan a ningún formato.

Fuera de alcance: órdenes de trabajo, mano de obra de taller, garantías, varias bodegas y contabilidad general.

## Reglas que la adenda agrega o cambia

Lo que el v1.1 ya dice de inventario (RF-01 a RF-12, decisión del QR del 14 de noviembre) sigue vigente, con estos cambios:

1. **Venta de mostrador (RF-06).** El descargue sigue siendo la venta y se descuenta al escanear, con el usuario responsable. La venta puede ser de contado o a crédito. El crédito exige cliente y guarda el precio con que se vendió. El sistema no factura.
2. **Reverso (RF-07).** Las ventas no se borran. Lo mismo aplica a abonos y pagos: se anulan con un movimiento nuevo, con usuario, fecha y motivo. No hay DELETE.
3. **Factura de compra (RF-04).** No se lee el XML. El QR llena el encabezado (CUFE, NIT, totales). Los ítems se digitan o se prellenan desde las remisiones ligadas. Un CUFE no entra dos veces.
4. **Remisión y factura (RF-05, RF-19).** La remisión se ingresa a mano, ítem por ítem. Una factura puede ligarse a una o varias remisiones del mismo proveedor para no duplicar inventario ni deuda.
5. **Saldo de cliente.** Ventas a crédito cerradas, netas de reversos, menos abonos no anulados.
6. **Saldo de proveedor.** Ingresos a crédito confirmados menos pagos no anulados. Una remisión cubierta por una factura confirmada no se cuenta dos veces.
7. **Informes.** Solo en pantalla. Sin exportar.

El v1.1 decía que la cartera quedaba en Contapyme (sección 0 y la nota bajo RF-07). Esa frase queda reemplazada por esta adenda.

## Pendientes

No darlos por resueltos. Si una respuesta depende de uno, decirlo y proponer un supuesto explícito.

1. Confirmar con Frank y el contador que Contapyme no llevará también la cartera de lo que factura.
2. Probar con una factura real qué trae el QR. Hay una foto en el Project.
3. Confirmar si una remisión puede facturarse parcialmente y cómo se corrige un vínculo equivocado ya confirmado.
4. ~~Confirmar si en mostrador se necesitan descuentos o precios libres.~~ Resuelto el 21-nov-2026: precio estándar en contado, precio libre > 0 en crédito abierto, ningún precio en 0 (`docs/supuestos.md`, S-10 y S-18).
5. Etiqueta por unidad, o por referencia más cantidad, en piezas baratas de alto volumen.
6. Cuáles poleas y cuáles automáticos entran al código universal.
7. Nombre de la tercera marca. En el audio se oye «Jota».
8. Tiempo de respuesta objetivo, en segundos.
