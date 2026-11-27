# Adenda v1.3 — Módulo contable y avisos de vencimiento

Estado: **APROBADA** (23-nov-2026) por Franklin. Complementa la adenda v1.2. Donde se contradicen, manda esta.

**Renumerada al integrarla con la rama `cesar` (25-nov-2026).** En la rama de Franklin estos requerimientos se llamaban RF-21, RF-22 y RF-23, pero esos números ya eran, en `cesar`, los ajustes de inventario, el stock mínimo y el panel de gestión; y RF-24 a RF-28 eran la caja diaria, la cuenta de empleados y la corrección de ingresos. Por eso aquí pasan a **RF-29** (rol y módulo contable), **RF-30** (avisos de vencimiento) y **RF-31** (exportar a Excel). Sus supuestos S-47 a S-53 pasan a **S-62 a S-68** en `docs/supuestos.md`. El test es `RF29ModuloContableTest`.

**RF-31 retirado (25-nov-2026, decisión de César).** La exportación a Excel no se necesita: se quitó del código (backend y pantallas) y los informes vuelven a ser **solo en pantalla**, como en la v1.2. El número RF-31 queda reservado y no se reutiliza.

## 1. Contexto

La auxiliar contable revisa la cartera de las ventas a crédito del mostrador y las facturas de compra de los proveedores. Con eso programa los pagos y hace un análisis mensual en Excel. Pidió que el programa le avise qué facturas están por vencer y que pueda marcarlas como canceladas cuando se pagan.

## 2. Cambio de alcance

| Tema | v1.2 | v1.3 |
|---|---|---|
| Roles | admin, logística, mostrador | + **contable**, acotado a lo contable |
| Formato de salida de informes | Solo en pantalla | Solo en pantalla (sin cambio; la exportación a Excel se retiró, ver RF-31) |
| Pago a proveedor | Al saldo, aplicado FIFO | Al saldo (FIFO) **o a una factura elegida** |
| Avisos | — | Vencidas y por vencer, **solo dentro del programa** |

Siguen fuera: facturación, integración con Contapyme, contabilidad general, avisos por correo o WhatsApp.

## 3. Requerimientos

### RF-29. Rol y módulo contable
Rol nuevo `contable`. Lo crea el admin en Usuarios. Ve y usa solo lo contable:
- cartera de clientes, ventas a crédito y estado de cuenta de cada cliente (solo lectura);
- cuentas por pagar, compras, remisiones pendientes, diferencias remisión–factura y estado de cuenta de cada proveedor;
- registrar pagos a proveedores (RF-17);
- la bandeja de vencimientos (RF-30).

No ve inventario, ingresos, ventas, etiquetas, catálogo, usuarios ni auditoría. No registra abonos ni anula abonos o pagos: eso sigue como en la v1.2 (S-27). El admin conserva todo lo anterior.
**Aceptación.** Un usuario `contable` consulta cuentas por pagar y registra un pago; recibe 403 en `/ventas`, `/ingresos`, `/usuarios` y `/pagos/{id}/anulaciones`.

### RF-17 (ajuste). Pago a una factura elegida
> **Ajustado el 26-nov-2026 por S-79 (`docs/supuestos.md`):** `ingreso_id` es obligatorio en todo pago nuevo; ya no hay pago FIFO al crear ni saldo a favor nuevo. Los pagos anteriores sin ingreso se siguen leyendo FIFO. El criterio 1 queda: "sin elegir factura, el pago se rechaza (422)".

Un pago puede ligarse a un ingreso a crédito concreto del mismo proveedor (`ingreso_id`, opcional). Su valor no puede superar lo pendiente de ese ingreso; se admiten varios pagos parciales. Un pago sin ingreso se aplica FIFO como antes. Al calcular la antigüedad, primero se descuenta de cada ingreso lo pagado a él y el resto de pagos se reparte FIFO. Un ingreso queda **cancelado** cuando su pendiente llega a 0. Si el ingreso deja de contar (una remisión cubierta luego por una factura, RF-19), lo pagado a él pasa al pago FIFO.
**Aceptación.**
1. Facturas A (vence primero, 100) y B (200). Un pago de 200 a B deja A pendiente 100 y B cancelada; sin elegir factura, el mismo pago cancela A y deja B en 100.
2. Un pago a B por 201 se rechaza. Un pago a un ingreso de otro proveedor, de contado o en borrador se rechaza.
3. Anular el pago a B devuelve su pendiente a 200.

### RF-30. Avisos de vencimiento
Bandeja con los documentos que tienen saldo pendiente y vencen hasta hoy + N días (`config('cartera.aviso_dias')`, **1 día**):
- **Por pagar:** ingresos a crédito de proveedores (RF-16, RF-17 ajuste).
- **Por cobrar:** ventas a crédito cerradas de clientes (RF-14, RF-15), con los abonos aplicados FIFO.

Cada fila: titular, documento, vencimiento, días para vencer o días de mora, valor y pendiente; estado `vencida` o `por_vencer`, vencidas primero. El menú del módulo muestra cuántas hay. Desde una fila por pagar se registra el pago a esa factura. Un documento sale de la bandeja cuando su pendiente llega a 0. Solo se avisa dentro del programa.
**Aceptación.** Con aviso de 1 día y hoy 23-nov: una factura con pendiente que venció el 20-nov sale como vencida (3 días de mora), una que vence el 24-nov como por vencer, una que vence el 26-nov no sale, y una ya pagada no sale.

### RF-31. Exportar informes a Excel — RETIRADO
**Retirado el 25-nov-2026:** no se necesita. Los informes de RF-18 y la bandeja de RF-30 se consultan solo en pantalla; no hay `?formato=xlsx`. El número no se reutiliza.

## 4. Datos

- `users.rol`: se agrega `contable`.
- `pagos_proveedores.ingreso_id`: nullable, FK a `ingresos`, con índice. Inmutable como el resto del pago.

## 5. Endpoints

| Método y ruta | Rol | RF |
|---|---|---|
| `GET /reportes/*` de RF-18 y estados de cuenta | admin, contable | RF-18, RF-29 |
| `GET /proveedores/{id}/saldo` (incluye `documentos` pendientes) | admin, contable | RF-17, RF-29 |
| `POST /proveedores/{id}/pagos` (`ingreso_id` opcional) | admin, contable | RF-17 ajuste |
| `GET /vencimientos` | admin, contable | RF-30 |
| `GET /proveedores`, `GET /clientes` | admin, logística, mostrador, contable | RF-29 |

## 6. Supuestos

| # | Supuesto |
|---|---|
| S-27 | El contable no anula pagos ni registra o anula abonos de clientes; lo hace el admin (y el mostrador en abonos). Si se equivoca en un pago, lo anula el admin. |
| S-28 | El aviso es de 1 día y es el mismo para proveedores y clientes. Un documento sin vencimiento usa su fecha. |
| S-29 | Un pago se liga a lo sumo a un ingreso; para pagar varias facturas se registra un pago por factura. **Ajustado por S-79 (26-nov-2026):** todo pago nuevo se liga a exactamente un ingreso (sin FIFO). |
