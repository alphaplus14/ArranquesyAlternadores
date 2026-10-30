# Toma de requerimientos

Sistema de inventario para un local de venta y mantenimiento de arranques y alternadores.

| Versión | Fecha | Cambio |
|---|---|---|
| 1.0 | 29 de octubre de 2026 | Versión inicial a partir de las dos notas de voz. |
| 1.1 | 29 de octubre de 2026 | Alcance limitado a inventario (la facturación sigue en Contapyme). Venta escaneada con descargue inmediato, reversos sin borrado, auditoría de ediciones, ingreso digitado cuando el escáner falla, propuesta técnica para RF-04 y nuevo pendiente de etiqueta por referencia. |

| | |
|---|---|
| Fuente inicial | Nota de voz `Requerimientos arranques y alternadores.m4a` (29 de octubre de 2026, 2 min 36 s) |
| Respuestas | Nota de voz `Respuestas.m4a` (29 de octubre de 2026, 5 min 58 s). Cierra las preguntas de la sección 9. |
| Estado | Alcance de descubrimiento cerrado en lo que las dos notas y las definiciones de Frank alcanzan a decir. Siguen abiertos la prueba del QR con una factura real, la confirmación de que la cartera queda en Contapyme, el esquema de etiquetas para piezas baratas de alto volumen y el nombre de una marca usada como ejemplo. |

Este documento junta las dos notas. La primera plantea el sistema. La segunda recorre, una por una, las preguntas que habían quedado abiertas.

## 0. Alcance

El sistema es **solo de inventario**. La facturación electrónica ante la DIAN ya la hace **Contapyme** y no se toca: el sistema no emite, guarda ni modifica facturas de venta.

La cartera de clientes y de proveedores (antes RF-07) sale del alcance y se sigue llevando en Contapyme. [PENDIENTE: confirmar con Frank]

## 1. Contexto del negocio

La empresa vende y da mantenimiento de arranques y alternadores. Esa es la línea fuerte.

El catálogo es más ancho. Los rubros que manejan son:

- arranques
- alternadores
- reguladores
- portadiodos
- poleas
- automáticos o solenoides
- válvulas de inyección
- sensores
- y más productos del mismo ramo

Trabajan marcas finas y marcas económicas. La variación de referencia entre proveedores se da sobre todo en las económicas: el mismo repuesto llega con un código distinto según quién lo factura.

Hoy el programa que tienen es muy lento y se demora mucho. La velocidad de respuesta es parte del pedido, no un detalle.

## 2. Problema

Los programas que han mirado guardan una referencia por producto. En su operación, un solo regulador llega con varias referencias y sigue siendo la misma pieza. Sin un código que las una, el inventario no dice cuántas unidades hay de verdad.

El caso que usan es el de los reguladores **Transpo**. Donde esa marca sirve como código común, quieren usarla como código universal.

Ejemplo de la primera nota: el regulador **IM204**. Tres referencias de proveedor con 2, 2 y 2 unidades deben verse como **6** en el código universal, y el detalle de cada referencia se conserva.

El otro dolor es la entrada de mercancía. La mercancía nueva llega con la factura de compra dentro de las cajas. El auxiliar de logística digita línea por línea. Quieren pasar un lector de código QR y que aparezcan los ítems, el detalle y las cantidades, para que la persona solo valide que la mercancía está y que las cantidades están completas.

## 3. Objetivo

Un programa de inventario, con descargue por venta de mostrador y usuarios responsables, que:

1. Agrupe bajo un código universal las piezas en las que varios proveedores cambian la referencia, y deje la referencia original en el resto.
2. Imprima códigos de barras únicos del local, uno por unidad física, en tres tamaños de etiqueta.
3. Arme el ingreso de la factura de compra al leer su QR y permita el ingreso manual cuando el documento es una remisión.
4. Descargue el inventario de inmediato en cada venta de mostrador escaneada, dejando quién hizo la operación.
5. Permita reversar ventas sin borrarlas y deje auditada toda edición de datos.
6. Responda rápido, muestre agotados por proveedor, el descuento de la factura y los productos que llegan sin IVA.

## 4. Quién interviene

| Actor | Qué hace |
|---|---|
| Administrador | Módulo propio. La nota pide el módulo y no detalla sus pantallas. |
| Logística | Recibe la mercancía, valida lo que muestra el QR e imprime y pega las etiquetas. |
| Mostrador | Parte de adelante. Hace el descargue, que es la venta. |
| Responsable de cada movimiento | Toda operación queda a nombre de quien la hizo. El ejemplo explícito es quién descargó. |
| Proveedor | Entrega la misma pieza con su referencia. A veces factura con QR y a veces manda remisión. |
| Cliente | Compra en mostrador. Su facturación y su cartera se manejan en Contapyme. |
| Contapyme | Sistema externo de facturación DIAN y contabilidad. No se integra ni se modifica. |

## 5. Requerimientos funcionales

### RF-01. Código universal, solo donde la referencia cambia

El código universal no cubre todo el catálogo. Aplica a:

- reguladores
- portadiodos
- algunas poleas
- algunos automáticos

En reguladores, el código de unificación es el de Transpo. IM204 es el ejemplo de trabajo.

Si el producto no está en el código universal Transpo, se deja la referencia original. Esa referencia es su código único.

**Aceptación.** Un regulador Transpo con tres referencias de proveedor y existencias 2, 2 y 2 muestra 6 en el universal y el desglose de cada una. Una pieza fuera de ese esquema se consulta por su referencia original, sin forzarla a un código Transpo.

Queda por precisar cuáles poleas y cuáles automáticos entran al universal.

### RF-02. Existencia agrupada, sin perder el detalle

La cantidad del código universal es la suma de sus referencias. Cada referencia, y cada marca, conserva su existencia. El sistema muestra el total y el desglose.

**Aceptación.** Si una referencia pasa de 2 a 3, el universal pasa de 6 a 7 y el detalle identifica cuál cambió.

### RF-03. Código de barras por unidad física

El código de barras identifica cada unidad física, no solo la referencia. Es único del local.

El ejemplo de la segunda nota: tres reguladores iguales, uno por marca.

| Marca | Unidades antes de escanear |
|---|---|
| Bosch | 1 |
| Transpo | 1 |
| Una tercera marca, que en el audio se oye como Jota | 1 |
| **Total del mismo regulador** | **3** |

Al pasar el lector por el código de una unidad, sale del inventario esa unidad. Si se escanea la de Bosch, Bosch queda en 0 y el total pasa a 2. Las otras marcas no se tocan.

Las etiquetas las imprime el sistema. La persona que imprime y pega elige el tamaño. Hay tres, porque las cajas no son iguales:

| Tamaño | Ejemplo de caja |
|---|---|
| Grande | Arranques |
| Pequeño | Sensores |
| Más pequeño | Sensor de temperatura |

**Aceptación.** Cada unidad recibe una etiqueta del tamaño elegido, con un código del local. Escanearla descarga esa unidad y deja quietas las demás marcas del mismo repuesto.

### RF-04. Ingreso de compra por QR, con validación humana

Cuando llega mercancía nueva, la factura de compra viene dentro de las cajas. Al leer el QR deben aparecer los ítems, el detalle de cada uno y las cantidades. La persona de logística valida que la mercancía exista y que las cantidades estén completas. Con eso se evita digitar el ingreso.

El QR de la factura electrónica colombiana normalmente trae solo el CUFE (código único de la factura) y los totales, no el detalle de los ítems. El detalle está en el archivo XML que el proveedor envía por correo.

**Propuesta.** Procesar el XML de la factura para obtener ítems, referencias, cantidades, descuentos e IVA, y usar el QR para ubicar la factura correspondiente por su CUFE. Logística escanea el QR, el sistema muestra las líneas del XML y la persona valida.

**Aceptación.** Al escanear el QR de una factura cuyo XML ya está cargado, aparecen sus ítems y cantidades listos para validar, sin digitar.

[PENDIENTE: confirmar con una factura real qué trae el QR y cómo llega el XML.]

### RF-05. Ingreso manual cuando el documento es una remisión

Hay proveedores que no mandan factura de compra con QR. Mandan remisión. Esos ingresos se hacen a mano.

**Aceptación.** Un recibo por remisión se puede cargar ítem por ítem, sin QR, y entra al mismo inventario.

### RF-06. Descargue de inventario: venta de mostrador

El descargue es la venta, la venta por el mostrador. En esta nota no es consumo de taller, ni traslado, ni ajuste. El sistema no factura: solo descuenta inventario. La factura se hace en Contapyme.

Toda venta de mostrador se escanea y descuenta el inventario **de inmediato**, con el usuario responsable.

Se maneja por módulos y por usuarios:

- módulo administrador
- módulo de logística
- módulo de descargue, que es la parte de adelante

Cada descargue tiene un responsable: queda identificado quién lo hizo.

**Aceptación.** Una venta de mostrador baja la unidad escaneada, baja el total del código que corresponda y deja el usuario que vendió.

### RF-07. Reverso de ventas: devolución o anulación

Una venta se puede reversar por devolución o por anulación, pero **nunca se borra**. El reverso es un movimiento nuevo, ligado a la venta original, con usuario, fecha y motivo. La unidad vuelve al inventario y las dos operaciones quedan en el historial.

**Aceptación.** Al reversar una venta, la unidad recupera su existencia, la venta original sigue visible y el reverso muestra quién lo hizo, cuándo y por qué.

> La cartera de clientes y proveedores, que antes ocupaba este número, sale del alcance (ver sección 0).

### RF-11. Auditoría de ediciones

Toda edición de datos queda registrada con el valor anterior, el valor nuevo, el usuario y la fecha.

**Aceptación.** Si se cambia el precio o la descripción de una referencia, el historial muestra el dato antes y después, quién lo cambió y cuándo.

### RF-12. Código digitado cuando el escáner no lee

Si el escáner no lee la etiqueta, la persona puede digitar el código, o buscar por referencia o por código universal y elegir la unidad. El movimiento registra si el código fue **escaneado** o **digitado**.

**Aceptación.** Una venta hecha con código digitado descuenta la misma unidad que el escaneo y queda marcada como digitada.

### RF-08. Agotados por proveedor

El programa debe poder filtrar por proveedor para ver los agotados.

### RF-09. Descuento de la factura

Si una factura trae descuento, el sistema muestra cuánto es ese descuento. En la nota esto se dice junto a la factura del proveedor.

### RF-10. Productos sin IVA

A veces la mercancía llega sin IVA. El ingreso tiene que poder registrar ese caso.

## 6. Reglas de negocio

1. El código universal agrupa referencias solo en reguladores, portadiodos, algunas poleas y algunos automáticos.
2. La variación de referencia entre proveedores es, sobre todo, de las marcas económicas.
3. Fuera del código Transpo, manda la referencia original del producto.
4. La existencia universal es la suma de sus referencias. Cada marca conserva la suya.
5. El código de barras es del local, uno por unidad física, y al escanearlo sale esa unidad.
6. La etiqueta la imprime el sistema y el tamaño lo elige quien la pega.
7. El QR de la factura de compra debe terminar en una lista de ítems y cantidades para validar, no para redigitar.
8. La remisión se ingresa a mano.
9. Descargar inventario es vender en el mostrador, con un usuario responsable, y ocurre de inmediato al escanear.
10. El sistema no factura ni lleva cartera: eso queda en Contapyme.
11. Ninguna venta se borra. Un reverso es un movimiento nuevo ligado a la venta, con usuario, fecha y motivo.
12. Toda edición queda auditada con valor anterior, valor nuevo, usuario y fecha.
13. Cada movimiento registra si el código fue escaneado o digitado.

## 7. Cualidades del sistema

- Tiempo de respuesta corto. El programa actual es muy lento y se demora mucho. No se fijó un número de segundos.
- Tres tamaños de etiqueta, elegidos al imprimir.
- El catálogo tiene muchos rubros. El modelo de producto tiene que servir para todos los de la sección 1, aunque el código universal solo agrupe una parte.

## 8. Lo que sigue fuera de estas notas

Siguen sin pedirse órdenes de trabajo, mano de obra de taller ni garantías, aunque la empresa sí da mantenimiento.

Quedan fuera la facturación electrónica, la contabilidad y la cartera de clientes y proveedores, que se manejan en Contapyme.

Tampoco se definieron varias bodegas ni reportes distintos al filtro de agotados.

## 9. Preguntas y respuestas

La segunda nota responde en orden las nueve preguntas. Lo que no alcanzó a precisarse queda al final de cada punto.

1. **¿El código universal aplica a todo el catálogo?**
   Aplica a reguladores, portadiodos, algunas poleas y algunos automáticos. El local maneja marcas finas y económicas, y el cambio de referencia entre proveedores está sobre todo en las económicas.
   Pendiente: cuáles poleas y cuáles automáticos entran.

2. **¿Qué código se usa cuando la pieza no es Transpo?**
   Se deja la referencia original. Si no está en el código universal Transpo, esa referencia es su código único.

3. **¿Cuáles son los rubros?**
   Arranques, alternadores, reguladores, portadiodos, poleas, automáticos o solenoides, válvulas de inyección, sensores, y más productos del ramo. La pieza que en la primera nota no se entendía es el portadiodos.

4. **¿El QR trae el detalle o solo identifica la factura?**
   No lo saben. Lo que quieren es pasar el lector y ver cada ítem, su detalle y las cantidades, para validar que llegó completo. La mercancía nueva trae la factura dentro de las cajas.
   Pendiente: probar con una factura real si el QR alcanza para armar esas líneas.

5. **¿Quién imprime las etiquetas?**
   El sistema. Se manejan tres tamaños, porque las cajas de arranques son grandes, las de sensores pequeñas y las del sensor de temperatura aún más pequeñas. Quien imprime y pega elige el tamaño de cada producto.

6. **¿El código de barras es de la unidad o de la referencia?**
   De cada unidad física. Tres reguladores iguales, en Bosch, Transpo y una tercera marca, tienen cada uno su código. Escanear uno saca esa unidad: esa marca queda en cero y el total baja de 3 a 2.
   Pendiente: confirmar el nombre de la tercera marca. En el audio se oye como Jota.

7. **¿Qué es el descargue de inventario?**
   La venta por el mostrador. Va en su propio módulo, el de la parte de adelante, distinto del de administrador y del de logística. Cada descargue tiene un responsable.

8. **¿Qué movimientos entran en la cartera?**
   Facturas, créditos a clientes, abonos de esos créditos y saldos. El detalle hay que confirmarlo con el auxiliar contable.
   Actualización v1.1: la cartera sale del alcance y queda en Contapyme. [PENDIENTE: confirmar con Frank]

9. **¿Qué requisitos faltaban después del corte de la primera nota?**
   - Que el programa responda rápido. El actual es muy lento y se demora mucho.
   - Filtrar por proveedor para ver los agotados.
   - Si la factura trae descuento, mostrar cuánto es.
   - Registrar productos que llegan sin IVA.
   - Ingresar a mano cuando el proveedor manda remisión y no factura con QR.
   - Con eso, por ahora, cerraron la lista.

## 10. Pendientes para cerrar el documento

1. Confirmar que la cartera de clientes y proveedores queda en Contapyme.
2. Probar con una factura real qué trae el QR y cómo llega el XML (RF-04).
3. Definir si en piezas baratas de alto volumen se usa etiqueta por unidad o por referencia con cantidad.
4. Precisar cuáles poleas y cuáles automáticos entran al código universal.
5. Confirmar el nombre de la tercera marca (en el audio se oye como Jota).
6. Definir un tiempo de respuesta objetivo en segundos.

## Anexo A. Lectura de la primera nota

La empresa vende y da mantenimiento de arranques y alternadores, y también partes eléctricas. Tienen muchos rubros. Necesitan un inventario con código universal, el ejemplo IM204 (2 + 2 + 2 = 6), códigos de barras del local al ingresar el producto en la factura, lectura QR de la factura de compra para no digitar el ingreso, cartera de proveedores y de clientes, rapidez, y un módulo de descargue de inventario. La grabación se corta cuando quien habla pregunta si el teléfono sigue grabando.

## Anexo B. Lectura de las respuestas

El código universal aplica a reguladores, portadiodos, algunas poleas y algunos automáticos, porque en las marcas económicas la referencia cambia según el proveedor. Lo que no es Transpo conserva la referencia original, y esa es su código único.

Los rubros son arranques, alternadores, reguladores, portadiodos, poleas, automáticos o solenoides, válvulas de inyección, sensores y más.

Sobre el QR, no saben qué trae por dentro. Quieren que, al pasar el lector sobre la factura que viene en las cajas, aparezcan los ítems, el detalle y las cantidades, y que logística solo valide que está completo.

Las etiquetas las imprime el sistema, en tres tamaños, y los elige quien las pega. El código identifica la unidad física: Bosch, Transpo y una tercera marca del mismo regulador se descargan por separado al escanear cada etiqueta.

El descargue es la venta de mostrador, con módulos de administrador, logística y mostrador, y con un responsable por operación.

En la cartera entran facturas, créditos, abonos y saldos. Esa parte la quieren confirmar con el auxiliar contable.

Además piden velocidad, filtro de agotados por proveedor, ver el descuento de la factura, productos sin IVA e ingreso manual por remisión. Por ahora no agregan más.
