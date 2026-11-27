/** RF-29: contable = auxiliar contable, acotado a cartera, cuentas por pagar, pagos e informes. */
export type Rol = 'admin' | 'logistica' | 'mostrador' | 'contable'
export type MetodoCaptura = 'escaneado' | 'digitado'

export interface Usuario {
  id: number
  name: string
  /** Nombre de usuario con que se inicia sesión (no es un correo). */
  usuario: string
  rol: Rol
  activo?: boolean
  /** S-70: preferencias de pantalla guardadas (solo admin, logística y contable; {} si no hay). */
  preferencias?: Record<string, unknown>
}

/** RF-13: no se borra; se desactiva. */
export interface Cliente {
  id: number
  nombre: string
  documento: string | null
  telefono: string | null
  plazo_dias: number
  activo: boolean
}

export type FormaPago = 'contado' | 'credito'

export interface Opcion {
  id: number
  nombre: string
}

/** RF-16: plazo_dias solo prellena el vencimiento de las compras a crédito. */
export interface Proveedor extends Opcion {
  nit?: string | null
  /** S-29: nombre del asesor comercial (opcional). */
  asesor?: string | null
  plazo_dias?: number | null
}

export interface Categoria extends Opcion {
  admite_codigo_universal: boolean
}

export type EstadoUnidad = 'disponible' | 'vendida' | 'retirada'

export interface Unidad {
  id: number
  codigo_barras: string
  /** RF-21: `retirada` = salió del inventario por una baja (no se vende). */
  estado: EstadoUnidad
  referencia_id: number
  referencia?: Referencia
  /** S-34: en la verificación de una salida, 'referencia' si el sistema asignó la unidad. */
  origen_codigo?: OrigenCodigo
  /** RF-28: en las unidades de un ingreso, si ya se imprimió su etiqueta. */
  etiqueta_impresa?: boolean
}

/** S-23: calidad opcional de una referencia. */
export type Calidad = 'fino' | 'generico'

export const NOMBRE_CALIDAD: Record<Calidad, string> = { fino: 'Fino', generico: 'Genérico' }

export interface Referencia {
  id: number
  codigo_referencia: string
  /** S-25: código de barras o QR del producto (opcional). */
  codigo_fabricante?: string | null
  calidad: Calidad | null
  descripcion: string
  precio_venta: string
  /** RF-14 / S-15: IVA con que se vende (0 = sin IVA). */
  iva_venta_porcentaje: string
  existencia: number
  /** S-26: false = desactivada (no se lista, no se escanea, no entra a ingresos). */
  activo?: boolean
  /** RF-22: propio (null = usa el general; 0 = no maneja). Con universal manda el del universal. */
  stock_minimo?: number | null
  minimo_efectivo?: number | null
  minimo_origen?: 'general' | 'personalizado' | null
  minimo_por_universal?: boolean
  bajo_minimo?: boolean
  proveedor_id: number
  categoria_id: number
  codigo_universal_id: number | null
  proveedor?: Opcion
  categoria?: Categoria
  codigo_universal?: { id: number; codigo: string; stock_minimo?: number | null } | null
  /** RF-08 / S-47: solo en Agotados. Existencia de las demás referencias activas del universal (null sin universal). */
  universal_existencia?: number | null
  unidades_disponibles?: Unidad[]
  /** S-71: solo en el listado de Inventario y con permiso; null = sin compras confirmadas. */
  ultimo_costo?: UltimoCosto | null
}

/**
 * S-82: universal en el listado de Inventario (fila agrupadora). Existencia = suma de sus referencias
 * visibles (RF-02); precio y costo van en cada referencia. Mínimos solo para quien los ve (S-28).
 */
export interface UniversalInventario {
  tipo: 'universal'
  id: number
  codigo: string
  descripcion: string | null
  categoria_id: number
  categoria: Opcion | null
  existencia_total: number
  cantidad_referencias: number
  proveedores: Opcion[]
  stock_minimo?: number | null
  minimo_efectivo?: number | null
  minimo_origen?: 'general' | 'personalizado' | null
  bajo_minimo?: boolean
  /** Ids de las referencias hijas que coincidieron con la búsqueda (se resaltan). */
  coincidencias: number[]
  /** Sus referencias (con el filtro de proveedor, solo las de ese proveedor). */
  referencias: Referencia[]
}

/** S-82: ítem del listado de Inventario: un universal o una referencia sin universal. */
export type ItemInventario = UniversalInventario | (Referencia & { tipo: 'referencia' })

export interface IngresoItem {
  id: number
  referencia_id: number
  referencia: { codigo_referencia: string; descripcion: string } | null
  cantidad: number
  costo_unitario: string
  /** S-22: descuento de la línea en %. */
  descuento_porcentaje: string
  /** IVA de la línea (19, 5, 0…); 0 = sin IVA. */
  iva_porcentaje: string
  /** RF-09 / S-22: cifras del backend con el descuento del documento prorrateado; null fuera del ingreso. */
  neto: string | null
  base: string | null
  iva: string | null
  /** RF-19: unidades de la línea que ya entraron con las remisiones ligadas (no se crean otra vez). */
  cantidad_cubierta: number
}

export type TipoIngreso = 'remision' | 'factura'

export interface Ingreso {
  id: number
  /** RF-04: las dos se ingresan a mano y los ítems se escanean (RF-20). */
  tipo: TipoIngreso
  estado: 'borrador' | 'confirmado'
  /** RF-16: contado o crédito; a crédito, vence_el es el vencimiento que trae el documento. */
  forma_pago: FormaPago
  proveedor_id: number
  proveedor?: Proveedor
  numero_documento: string
  fecha: string
  vence_el: string | null
  /** S-22: en pesos; si se digitó en %, descuento_documento_porcentaje lo indica (solo en borrador). */
  descuento_documento: string
  descuento_documento_porcentaje: string | null
  /** RF-16: total fijado al confirmar; null en borrador. */
  total: string | null
  confirmado_at: string | null
  items: IngresoItem[]
  /** RF-09 (ajuste): calculado por el backend; el frontend solo lo muestra. */
  totales: {
    subtotal_bruto: string
    descuento_lineas: string
    subtotal_neto: string
    descuento_documento: string
    base_gravada: string
    base_sin_iva: string
    iva: string
    lineas_sin_iva: number
    total: string
    /** S-66: el borrador lo acepta; con true el backend no deja confirmar. */
    descuento_excede_subtotal: boolean
    /** S-66 (ajuste): min(descuento del documento, subtotal neto). Solo para mostrar el borrador. */
    descuento_aplicado: string
    /** S-66 (ajuste): lo que falta por aplicar hasta que haya líneas que lo cubran (0 si nada). */
    descuento_pendiente: string
    /** S-66 (ajuste): max(total, 0); el borrador nunca muestra un total negativo. */
    total_visible: string
  } | null
  /** RF-19: en una remisión, la factura que la cubre; en una factura, sus remisiones ligadas. */
  factura: { id: number; numero_documento: string; estado: Ingreso['estado'] } | null
  remisiones: RemisionLigada[]
  diferencia_aceptada: boolean
  diferencia_motivo: string | null
  /** RF-28: historial de correcciones (más reciente primero) y si tiene alguna. */
  correcciones?: CorreccionIngreso[]
  corregido?: boolean
}

export interface RemisionLigada {
  id: number
  numero_documento: string
  fecha: string
  total: string | null
  unidades: number
}

/** RF-19: respuesta 422 al confirmar una factura que cubre menos de lo recibido por sus remisiones. */
export interface DiferenciaRemision {
  referencia_id: number
  codigo_referencia: string
  recibido: number
  facturado: number
}

export interface VentaItem {
  id: number
  /** RF-14: precio e IVA copiados de la referencia al escanear; null en ventas antiguas. */
  precio_unitario: string | null
  iva_porcentaje: string | null
  metodo_captura: MetodoCaptura
  /** S-31: 'referencia' si se vendió con el código del proveedor (el sistema eligió la unidad). */
  origen_codigo?: OrigenCodigo
  created_at: string
  usuario: { id: number; name: string }
  unidad: Unidad
  reverso_id: number | null
}

/** S-31: con qué código se vendió la unidad. */
export type OrigenCodigo = 'etiqueta' | 'referencia'

/** S-31: una de las referencias que comparten el código escaneado (422 tipo 'elegir_referencia'). */
export interface OpcionReferenciaVenta {
  id: number
  codigo_referencia: string
  descripcion: string
  proveedor: string | null
  calidad: Calidad | null
  existencia: number
}

export interface Reverso {
  id: number
  tipo: 'devolucion' | 'anulacion'
  motivo: string
  created_at: string
  usuario: { id: number; name: string }
  venta_item_ids: number[]
  /** S-73: monto anulado de una venta a crédito de solo valor (null en los reversos con ítems). */
  valor?: string | null
}

export interface Venta {
  id: number
  /** S-46: `descartada` solo la pone el sistema a una abierta sin ítems vigentes. */
  estado: 'abierta' | 'cerrada' | 'descartada'
  /** RF-14 */
  forma_pago: FormaPago
  cliente: { id: number; nombre: string; documento: string | null; plazo_dias: number } | null
  /** Número de factura de Contapyme, solo como texto de referencia. */
  factura_contapyme: string | null
  usuario?: { id: number; name: string }
  created_at: string
  cerrada_at: string | null
  /** S-46: cuándo la descartó el sistema (solo en descartadas). */
  descartada_at?: string | null
  /** Fecha (aaaa-mm-dd) que calcula el backend al cerrar una venta a crédito. */
  vence_el: string | null
  items: VentaItem[]
  reversos: Reverso[]
  /** S-33 / S-15: precios con IVA incluido; total de los ítems vigentes y su desglose. */
  totales?: TotalesVenta
  /** RF-24 (S-48): forma de pago de la venta de contado (vacía en crédito y en las anteriores). */
  pagos?: VentaPago[]
  /** S-73: venta a crédito de solo valor (sin ítems): cliente + documento + valor total con IVA. */
  solo_valor?: boolean
  tipo_documento?: TipoDocumentoVenta | null
  /** "Factura N.º X", "Remisión N.º X" o "Factura sin número". */
  documento?: string | null
  valor_total?: string | null
  anulada?: boolean
}

/** S-73: documento con que se registra una venta a crédito de solo valor. */
export type TipoDocumentoVenta = 'factura' | 'remision'
export const NOMBRE_TIPO_DOCUMENTO: Record<TipoDocumentoVenta, string> = { factura: 'Factura', remision: 'Remisión' }

/* RF-24 (S-48): cuentas de pago. */
export type TipoCuentaPago = 'efectivo' | 'banco' | 'datafono' | 'billetera'
export const NOMBRE_TIPO_CUENTA: Record<TipoCuentaPago, string> = { efectivo: 'Efectivo', banco: 'Banco', datafono: 'Datáfono', billetera: 'Billetera' }

/** S-88: persona dueña de una o varias billeteras. */
export interface Propietario {
  id: number
  nombre: string
  activo: boolean
}

export interface CuentaPago {
  id: number
  nombre: string
  tipo: TipoCuentaPago
  activa: boolean
  /** S-88: solo en billeteras; null si no tiene dueño o no es billetera. */
  propietario: { id: number; nombre: string } | null
}

/** "Nequi · Dayana" cuando la billetera tiene dueño; si no, el nombre de la cuenta. */
export function etiquetaCuenta(c: { nombre: string; tipo?: TipoCuentaPago; propietario?: { nombre: string } | null }, conTipo = false): string {
  const base = c.propietario ? `${c.nombre} · ${c.propietario.nombre}` : c.nombre
  return conTipo && c.tipo ? `${base} (${NOMBRE_TIPO_CUENTA[c.tipo]})` : base
}

export interface VentaPago {
  id: number
  cuenta: { id: number; nombre: string; tipo: TipoCuentaPago } | null
  valor: string
  referencia: string | null
}

/** Lo que se envía al cerrar una venta de contado: la suma debe ser el total exacto (S-48). */
export interface PagoVentaNuevo {
  cuenta_pago_id: number
  valor: number
  referencia: string | null
}

/** S-33: total = suma de ítems vigentes (con IVA); base e IVA incluido extraídos por ítem. */
export interface TotalesVenta {
  total: string
  base: string
  iva_incluido: string
  /** Porcentajes de IVA distintos de los ítems vigentes (uno solo = se muestra en la línea de IVA). */
  iva_porcentajes: number[]
  /** Ítems antiguos sin IVA registrado: no se desglosan. */
  items_sin_iva: number
}

/** RF-21 (S-27): baja (salida) o reintegro de unidades. */
export type TipoAjuste = 'baja' | 'reintegro' | 'alta'
export type MotivoBaja = 'garantia' | 'devolucion_proveedor' | 'dano' | 'perdida' | 'faltante_inventario' | 'otro'
export type MotivoReintegro = 'error_de_registro' | 'otro'
/** S-39: motivos de una entrada por ajuste de inventario físico. */
export type MotivoAlta = 'sobrante_inventario' | 'sin_factura' | 'otro'
export type MotivoAjuste = MotivoBaja | MotivoReintegro | MotivoAlta

/** S-39: referencia elegible para una entrada (GET /ajustes/referencia). */
export interface ReferenciaEntrada {
  id: number
  codigo_referencia: string
  codigo_fabricante: string | null
  descripcion: string
  proveedor: { id: number; nombre: string } | null
  calidad: Calidad | null
  existencia: number
  precio_venta: string
  tiene_precio: boolean
}

export const NOMBRE_MOTIVO: Record<MotivoAjuste, string> = {
  garantia: 'Garantía',
  devolucion_proveedor: 'Devolución a proveedor',
  dano: 'Daño',
  perdida: 'Pérdida',
  faltante_inventario: 'Faltante de inventario físico',
  error_de_registro: 'Error de registro',
  sobrante_inventario: 'Sobrante de inventario físico',
  sin_factura: 'Mercancía sin factura',
  otro: 'Otro',
}

/** Referencia resumida de una unidad en ajustes y devoluciones. */
export interface ReferenciaUnidad {
  id: number
  codigo_referencia: string
  descripcion: string
  calidad: Calidad | null
  proveedor: Opcion
}

export interface AjusteInventario {
  id: number
  tipo: TipoAjuste
  motivo: MotivoAjuste
  nota: string | null
  created_at: string
  usuario: { id: number; name: string }
  cantidad: number
  unidades: Array<{
    id: number
    codigo_barras: string
    estado: EstadoUnidad
    metodo_captura: MetodoCaptura
    /** S-34: 'referencia' si se leyó el código del proveedor y el sistema eligió la unidad. */
    origen_codigo?: OrigenCodigo
    /** true si sigue retirada y esta es su última baja. */
    reintegrable: boolean
    referencia: ReferenciaUnidad
  }>
}

/** RF-07 / S-27: la venta de una unidad, para registrar la devolución de un cliente. */
export interface Devolucion {
  venta_item_id: number
  precio_unitario: string | null
  iva_porcentaje: string | null
  unidad: { id: number; codigo_barras: string; estado: EstadoUnidad }
  referencia: ReferenciaUnidad
  venta: {
    id: number
    forma_pago: FormaPago
    created_at: string
    cerrada_at: string | null
    cliente: { id: number; nombre: string; documento: string | null } | null
  }
  reverso: { id: number; tipo: 'devolucion' | 'anulacion'; motivo: string; created_at: string } | null
}

/**
 * S-34 / S-38: un grupo candidato a devolverse por código de proveedor: ítems sin reverso de una venta
 * cerrada, de la misma referencia y al mismo precio. `codigos` en el orden en que se devuelven.
 */
export interface GrupoDevolucion {
  venta_id: number
  cerrada_at: string
  forma_pago: FormaPago
  cliente: { id: number; nombre: string; documento: string | null } | null
  /** Saldo actual del cliente (solo ventas a crédito). */
  saldo_actual: string | null
  referencia: { id: number; codigo_referencia: string; descripcion: string; calidad: Calidad | null; proveedor: string | null }
  precio_unitario: string | null
  cantidad_disponible: number
  codigos: string[]
  venta_item_id: number
}

/** La consulta trae la venta de la etiqueta (con el saldo actual si es a crédito) o, con un código de proveedor, grupos. */
export type ConsultaDevolucion =
  | (Devolucion & { saldo_actual?: string | null })
  | { tipo: 'elegir_venta'; codigo: string; ventas: GrupoDevolucion[] }

/** S-38: respuesta de POST /devoluciones: el primer ítem, cuántas se devolvieron y el saldo antes y después. */
export interface ResultadoDevolucion extends Devolucion {
  cantidad: number
  total: string
  devueltas: string[]
  devueltas_ids: number[]
  saldo_cliente: { antes: string; despues: string } | null
}

/** RF-22: fila del informe Bajo mínimo (referencia sin universal o universal con su desglose). */
export interface FilaBajoMinimo {
  tipo: 'referencia' | 'universal'
  id: number
  codigo: string
  descripcion: string | null
  calidad: Calidad | null
  proveedor: Opcion | null
  categoria: Opcion
  existencia: number
  minimo: number
  origen: 'general' | 'personalizado'
  faltante: number
  referencias: Array<{ id: number; codigo_referencia: string; calidad: Calidad | null; proveedor: Opcion; existencia: number }> | null
}

/* RF-15 / RF-18 (S-30): cartera de clientes. Montos en pesos como texto con 2 decimales. */
export type MedioPago = 'efectivo' | 'transferencia' | 'consignacion' | 'otro'
export const NOMBRE_MEDIO: Record<MedioPago, string> = { efectivo: 'Efectivo', transferencia: 'Transferencia', consignacion: 'Consignación', otro: 'Otro' }

export type RangoAntiguedad = 'al_dia' | 'd1_30' | 'd31_60' | 'd61_90' | 'mas_90'
export const RANGOS: { clave: RangoAntiguedad; texto: string }[] = [
  { clave: 'al_dia', texto: 'Al día' },
  { clave: 'd1_30', texto: '1-30' },
  { clave: 'd31_60', texto: '31-60' },
  { clave: 'd61_90', texto: '61-90' },
  { clave: 'mas_90', texto: '+90' },
]

export interface ResumenCartera {
  cliente: { id: number; nombre: string; documento: string | null; telefono: string | null; plazo_dias: number; activo: boolean } | null
  ventas: string
  abonado: string
  saldo: string
  saldo_a_favor: boolean
  vencido: string
  proximo_vencimiento: string | null
  vencida_desde: string | null
  antiguedad: Record<RangoAntiguedad, string>
  corte?: string
  /** Solo en GET /cartera con búsqueda: la venta por la que apareció el cliente (n.º o factura). */
  coincidencia?: { venta_id: number; factura_contapyme: string | null } | null
}

export interface ListaCartera extends Pagina<ResumenCartera> {
  totales: {
    cartera_total: string
    saldo_a_favor: string
    vencido_total: string
    clientes_con_saldo: number
    clientes_vencidos: number
    antiguedad: Record<RangoAntiguedad, string>
  }
  corte: string
}

export interface Abono {
  id: number
  cliente_id: number
  /** S-32: null = general (a las ventas más antiguas); con número, dirigido a esa venta. */
  venta_id: number | null
  /** S-32: "General" o "Venta #11 · FE-1042". */
  aplicado_a: string
  fecha: string
  valor: string
  medio: MedioPago
  /** RF-24 (S-48): cuenta donde entró (null en abonos anteriores). */
  cuenta?: { id: number; nombre: string; tipo: TipoCuentaPago } | null
  referencia: string | null
  nota: string | null
  created_at: string
  usuario?: { id: number; name: string }
  anulado: boolean | null
  anulacion?: { id: number; motivo: string; created_at: string; usuario: { id: number; name: string } | null } | null
}

export interface VentaCartera {
  id: number
  /** N.º de factura de Contapyme (texto de referencia; este sistema no factura). */
  factura_contapyme: string | null
  /** S-73: tipo de documento de una venta de solo valor (null en las de ítems). */
  tipo_documento?: TipoDocumentoVenta | null
  /** S-73: "Factura N.º X", "Remisión N.º X" o "Factura sin número" (null en las de ítems). */
  documento?: string | null
  cerrada_at: string
  vence_el: string | null
  total: string
  aplicado: string
  /** S-32: parte de lo aplicado que vino de abonos dirigidos a esta venta. */
  aplicado_dirigido: string
  /** S-32: parte de lo aplicado que vino del pool general (FIFO). */
  aplicado_general: string
  pendiente: string
  estado: 'al_dia' | 'por_vencer' | 'vencida'
  estado_texto: string
  dias_vencida: number
  rango: RangoAntiguedad | null
}

export interface DetalleCartera {
  resumen: ResumenCartera
  ventas: VentaCartera[]
  /** Ventas a crédito del cliente sin filtros (para "N de M"). */
  ventas_total: number
  abonos: Abono[]
}

export type EstadoFiltroVenta = 'todas' | 'pendientes' | 'vencidas' | 'pagadas'

export interface MovimientoCuenta {
  tipo: 'venta' | 'reverso' | 'abono' | 'anulacion_abono'
  dia: string
  momento: string
  descripcion: string
  venta_id?: number
  reverso_id?: number
  abono_id?: number
  medio?: MedioPago
  referencia?: string | null
  nota?: string | null
  motivo?: string
  usuario?: string | null
  anulado?: boolean
  anulacion?: { motivo: string; usuario: string | null; fecha: string } | null
  /** S-32 (abonos): "General" o "Venta #11 · FE-1042". */
  aplicado_a?: string
  cargo: string
  abono: string
  saldo: string
}

export interface EstadoCuenta {
  cliente: { id: number; nombre: string; documento: string | null }
  desde: string | null
  hasta: string | null
  saldo_inicial: string
  movimientos: MovimientoCuenta[]
  saldo_final: string
}

/** S-40: una venta abierta en el listado (GET /ventas?estado=abierta). */
export interface VentaAbierta {
  id: number
  forma_pago: FormaPago
  cliente: { id: number; nombre: string; plazo_dias?: number } | null
  factura_contapyme: string | null
  created_at: string
  usuario: { id: number; name: string }
  /** Ítems vigentes (sin reverso). */
  items_count: number
  /** S-33: total con IVA incluido. */
  total: string | number
}

/** RF-06: respuesta de POST /ventas/{id}/escanear. `item` tiene la misma forma que en GET /ventas/{id}. */
export interface RespuestaEscaneo {
  venta_id: number
  item: VentaItem
  venta_item_id: number
  metodo_captura: MetodoCaptura
  unidad: { id: number; codigo_barras: string; estado: Unidad['estado'] }
  referencia: { id: number; codigo_referencia: string; descripcion: string; calidad: Calidad | null; proveedor: string; existencia: number }
  /** S-31: 'referencia' si se escaneó el código del proveedor y el sistema eligió la unidad. */
  origen_codigo?: OrigenCodigo
  /** S-33: totales de la venta después del escaneo (precios con IVA incluido). */
  totales?: TotalesVenta
  /** S-40: todos los ítems de la lectura (con cantidad > 1, varios); `item` es el último. */
  items?: VentaItem[]
  /** S-46: solo en POST /ventas (la venta acaba de nacer): la venta completa, igual que GET /ventas/{id}. */
  venta?: Venta
}

export interface Auditoria {
  id: number
  tabla: string
  registro_id: number
  campo: string
  valor_anterior: string | null
  valor_nuevo: string | null
  created_at: string
  usuario: { id: number; name: string } | null
}

/** Laravel devuelve dos formas de paginación: plana (paginate) o con meta (API Resources). */
export interface Pagina<T> {
  data: T[]
  current_page?: number
  last_page?: number
  total?: number
  meta?: { current_page: number; last_page: number; total: number }
}

/** RF-17: medios de un pago a proveedor (config/cartera.php). Los abonos de clientes usan MedioPago (S-30). */
export type MedioDinero = 'efectivo' | 'transferencia' | 'tarjeta' | 'cheque' | 'otro'

export const NOMBRE_MEDIO_DINERO: Record<MedioDinero, string> = {
  efectivo: 'Efectivo',
  transferencia: 'Transferencia',
  tarjeta: 'Tarjeta',
  cheque: 'Cheque',
  otro: 'Otro',
}

/** Abono de un cliente (RF-15) o pago a un proveedor (RF-17). No se edita ni se borra: se anula. */
export interface MovimientoDinero {
  id: number
  cliente_id?: number
  proveedor_id?: number
  /** RF-17 (ajuste), S-79: factura a la que se aplicó el pago; null solo en pagos anteriores a S-79 (se leen FIFO). */
  ingreso_id?: number | null
  ingreso?: { id: number; tipo: TipoIngreso; numero_documento: string } | null
  fecha: string
  valor: string
  medio: MedioDinero
  /** RF-24 (S-48): cuenta (null en registros anteriores). */
  cuenta?: { id: number; nombre: string; tipo: TipoCuentaPago } | null
  referencia: string | null
  nota: string | null
  usuario: { id: number; name: string } | null
  created_at: string
  anulacion: { motivo: string; usuario: { id: number; name: string } | null; created_at: string } | null
}

export const NOMBRE_RANGO: Record<RangoAntiguedad, string> = {
  al_dia: 'Al día',
  d1_30: '1-30',
  d31_60: '31-60',
  d61_90: '61-90',
  mas_90: 'Más de 90',
}

/** Saldo con antigüedad a una fecha de corte (FIFO: lo abonado cubre primero lo más antiguo). */
export interface ResumenSaldo {
  cargos: string
  abonos: string
  saldo: string
  a_favor: string
  vencido: string
  rangos: Record<RangoAntiguedad, string>
}

export interface DocumentoPendiente {
  id: number
  fecha: string
  vence_el: string | null
  valor: string
  pendiente: string
  /** S-79: lo ya pagado de este documento (valor − pendiente), con sus pagos parciales. */
  abonado: string
  dias_vencido: number
  dias_para_vencer: number
  rango: RangoAntiguedad
  /** Proveedor: factura o remisión. Cliente (S-88): de solo valor su tipo; con ítems, factura si lleva número de Contapyme. */
  tipo?: TipoIngreso | null
  numero_documento?: string | null
  /** S-88 (cliente): "Factura N.º X" en las ventas de solo valor. */
  documento?: string | null
}

/** S-88: GET /clientes/{id}/saldo (contable), mismo contrato que el del proveedor. */
export interface SaldoCliente extends ResumenSaldo {
  cliente: { id: number; nombre: string; documento: string | null; telefono: string | null; plazo_dias: number; activo: boolean }
  corte: string
  documentos: DocumentoPendiente[]
}

/** S-88: historial de pagos (proveedor) o abonos (cliente) con sus totales del filtro. */
export interface TotalesHistorial {
  vigente: string
  cantidad: number
  anulado: string
  cantidad_anulados: number
}
export interface PaginaHistorial<T> extends Pagina<T> {
  totales: TotalesHistorial
}

export interface SaldoProveedor extends ResumenSaldo {
  proveedor: Proveedor
  corte: string
  documentos: DocumentoPendiente[]
  pagos_recientes: MovimientoDinero[]
}

/** RF-18: informe paginado en el servidor; los totales cubren todas las filas, no solo la página. */
export interface PaginaInforme<T, Totales = Record<string, string>> extends Pagina<T> {
  totales: Totales
  desde?: string
  hasta?: string
  corte?: string
}

export interface FilaCompra {
  id: number
  tipo: TipoIngreso
  numero_documento: string
  proveedor: Opcion
  fecha: string
  forma_pago: FormaPago
  subtotal: string
  descuento: string
  base_gravada: string
  base_sin_iva: string
  iva: string
  total: string
}

export interface FilaVentaCredito {
  id: number
  cliente: Opcion
  fecha: string
  vence_el: string | null
  factura_contapyme: string | null
  /** S-73: tipo y nombre del documento de una venta de solo valor (null en las de ítems). */
  tipo_documento?: TipoDocumentoVenta | null
  documento?: string | null
  items: number
  base: string | null
  iva: string | null
  total: string
  /** S-88: anulada (todos sus ítems reversados o, en solo valor, con reverso). */
  anulada?: boolean
}

export interface FilaCartera extends ResumenSaldo {
  cliente?: { id: number; nombre: string; documento: string | null; plazo_dias: number }
  proveedor?: { id: number; nombre: string; nit: string | null; plazo_dias: number | null }
}

export type TotalesCartera = Omit<ResumenSaldo, 'rangos'> & {
  rangos: Record<RangoAntiguedad, string>
  /** S-88: titulares de lo filtrado y cuántos tienen saldo > 0. */
  titulares?: number
  con_saldo?: number
}

export type TipoMovimientoCuenta =
  | 'venta' | 'reverso' | 'abono' | 'anulacion_abono'
  | 'factura' | 'remision' | 'remision_cubierta' | 'pago' | 'anulacion_pago'

export interface FilaEstadoCuenta {
  fecha: string
  tipo: TipoMovimientoCuenta
  detalle: string
  cargo: string
  abono: string
  saldo: string
  venta_id?: number
  ingreso_id?: number
  abono_id?: number
  pago_id?: number
  vence_el?: string | null
}

export interface EstadoCuentaInforme extends PaginaInforme<FilaEstadoCuenta, { cargo: string; abono: string; saldo_final: string }> {
  saldo_anterior: string
  desde: string
  hasta: string
  /** S-88: movimientos del período sin el filtro de tipo ni la búsqueda (M en "N de M"). */
  movimientos_periodo?: number
  /** S-88: hay filtro de tipo o búsqueda (el saldo mostrado incluye todos los movimientos). */
  filtrado?: boolean
}

export interface FilaRemisionPendiente {
  id: number
  numero_documento: string
  proveedor: Opcion
  fecha: string
  forma_pago: FormaPago
  total: string | null
  dias: number
  factura_borrador: { id: number; numero_documento: string } | null
}

export interface FilaDiferenciaRemision {
  id: number
  numero_documento: string
  proveedor: Opcion
  confirmado_at: string
  confirmado_por: { id: number; name: string } | null
  motivo: string | null
  remisiones: Array<{ id: number; numero_documento: string }>
  detalle: DiferenciaRemision[]
}

export function infoPagina<T>(p: Pagina<T>) {
  return {
    actual: p.meta?.current_page ?? p.current_page ?? 1,
    ultima: p.meta?.last_page ?? p.last_page ?? 1,
    total: p.meta?.total ?? p.total ?? p.data.length,
  }
}

/* ───────────── RF-23 / S-45: panel de gestión del administrador (solo en pantalla) ───────────── */

export interface PanelGenerado { generado_at: string }
export interface PanelRango { desde: string; hasta: string; dias_periodo: number }

export interface PanelReferencia {
  id: number
  codigo_referencia: string
  descripcion: string
  calidad: Calidad | null
  proveedor: { id: number; nombre: string }
  categoria: { id: number; nombre: string } | null
  universal: { id: number; codigo: string } | null
}

export interface PanelVentas {
  total: string
  base: string
  iva_incluido: string
  ventas: number
  unidades: number
  ticket_promedio: string
  contado: string
  credito: string
  ventas_contado: number
  ventas_credito: number
}

export interface PanelMargen {
  /** null = ninguna unidad vendida con costo conocido. */
  margen: string | null
  margen_porcentaje: number | null
  base_con_costo: string
  costo: string
  cobertura_costo: number | null
  unidades_sin_costo: number
}

export interface PanelResumen extends PanelGenerado, PanelRango {
  periodo_anterior: { desde: string; hasta: string }
  ventas: PanelVentas & { anterior: PanelVentas; variacion_total: number | null }
  margen: PanelMargen & { anterior: PanelMargen; variacion: number | null }
  inventario: { unidades: number; valor_costo: string; valor_venta_sin_iva: string; unidades_sin_costo: number }
  cartera: { por_cobrar: string; vencida: string }
  cuentas_por_pagar: { por_pagar: string; vencidas: string }
  compras: { total: string }
  agotados: number
  bajo_minimo: number
}

export interface PanelDia { dia: string; contado: string; credito: string; total: string; unidades: number; ventas: number }
export interface PanelVentasPorDia extends PanelGenerado, PanelRango {
  serie: PanelDia[]
  totales: { contado: string; credito: string; total: string; unidades: number; ventas: number }
}

export type OrdenMasVendidos = 'unidades' | 'valor' | 'margen'
export interface PanelMasVendido {
  referencia: PanelReferencia
  unidades: number
  valor: string
  base: string
  margen: string | null
  margen_porcentaje: number | null
  unidades_sin_costo: number
  porcentaje_total: number | null
  clase: 'A' | 'B' | 'C' | null
}
export interface PanelMasVendidos extends PanelGenerado, PanelRango { orden: OrdenMasVendidos; total_valor: string; filas: PanelMasVendido[] }

export interface PanelReabastecer extends PanelGenerado, PanelRango {
  total: number
  filas: { referencia: PanelReferencia; vendidas: number; existencia: number; cobertura_dias: number; minimo_efectivo: number | null; motivo: 'agotada' | 'bajo_minimo' | 'cobertura_corta' }[]
}

export interface PanelEstancada {
  referencia: PanelReferencia
  existencia: number
  valor_costo: string
  unidades_sin_costo: number
  ultima_venta: string | null
  en_stock_desde: string
  dias_sin_vender: number
  universal_rota: boolean | null
}
export interface PanelSinMovimiento extends PanelGenerado, Pagina<PanelEstancada> {
  dias: number
  desde_limite: string
  totales: { referencias: number; unidades: number; valor_costo: string; unidades_sin_costo: number }
}

export interface PanelAntiguedad extends PanelGenerado {
  rangos: { rango: string; unidades: number; valor_costo: string; unidades_sin_costo: number; referencias: number }[]
}

export interface PanelRotacionFila {
  id: number
  nombre: string
  unidades_vendidas: number
  ventas: string
  margen: string | null
  margen_porcentaje: number | null
  existencia: number
  valor_costo: string
  unidades_sin_costo: number
  /** null = sin rotación en el período. */
  cobertura_dias: number | null
  sobrestock: boolean
  compras: string | null
}
export interface PanelRotacion extends PanelGenerado, PanelRango { agrupar: 'categoria' | 'proveedor'; filas: PanelRotacionFila[] }

export interface PanelControl extends PanelGenerado, PanelRango {
  ajustes: { tipo: 'baja' | 'alta' | 'reintegro'; motivo: string; unidades: number; valor_costo: string; unidades_sin_costo: number }[]
  reversos: Record<'devolucion' | 'anulacion', { reversos: number; items: number; valor: string }>
  capturas: { total: number; digitadas: number; porcentaje_digitado: number | null }
  ventas_por_usuario: { usuario: { id: number; nombre: string }; ventas: number; unidades: number; total: string }[]
  ventas_abiertas_viejas: number
  cambios_precio_credito: number
  dias_sin_ventas: number
  dias: number
}

/* RF-25 / RF-26 (S-49 a S-54): caja diaria. Montos en pesos como texto con 2 decimales. */
export type GrupoCaja = 'vario' | 'fijo' | 'financiero' | 'ingreso_externo' | 'otro_ingreso'
export const NOMBRE_GRUPO_CAJA: Record<GrupoCaja, string> = {
  vario: 'Gasto',
  fijo: 'Gasto fijo',
  financiero: 'Cuota bancaria',
  ingreso_externo: 'Ingreso externo',
  otro_ingreso: 'Otro ingreso',
}
export const GRUPOS_CAJA: GrupoCaja[] = ['vario', 'fijo', 'financiero', 'ingreso_externo', 'otro_ingreso']
/** S-52: todos los grupos salvo "otro ingreso" van solo en efectivo. */
export const soloEfectivo = (g: GrupoCaja) => g !== 'otro_ingreso'

export interface CategoriaCaja {
  id: number
  nombre: string
  grupo: GrupoCaja
  activa: boolean
}

export interface MovimientoCaja {
  id: number
  fecha: string
  grupo: GrupoCaja
  grupo_nombre: string
  categoria: { id: number; nombre: string }
  concepto: string
  valor: string
  cuenta: { id: number; nombre: string; tipo: TipoCuentaPago }
  referencia: string | null
  usuario: { id: number; name: string }
  created_at: string
  anulacion: { motivo: string; created_at: string; usuario: { id: number; name: string } | null } | null
}

/**
 * S-76: recaudo de una factura o remisión a crédito de Contapyme que no está en las ventas a crédito
 * del sistema. Entra a la caja (con el recaudo de cartera); no toca la cartera de clientes.
 */
export interface RecaudoDocumento {
  id: number
  fecha: string
  tipo_documento: TipoDocumentoVenta
  numero_documento: string
  /** "Factura N.º 12345" / "Remisión N.º 45". */
  documento: string
  valor: string
  cuenta: { id: number; nombre: string; tipo: TipoCuentaPago }
  observacion: string | null
  usuario: { id: number; name: string }
  created_at: string
  anulacion: { motivo: string; created_at: string; usuario: { id: number; name: string } | null } | null
}

/** S-76: consulta del contable: totales vigentes del rango, en total y por cuenta. */
export interface TotalesRecaudos {
  total_vigente: string
  cantidad_vigente: number
  por_cuenta: { cuenta_pago_id: number; nombre: string; tipo: TipoCuentaPago; total: string; cantidad: number }[]
}

/** S-88: una billetera dentro del grupo de su dueño. `neto` es el movimiento del período (día o mes). */
export interface FilaBilleteraCaja {
  cuenta_pago_id: number
  nombre: string
  neto: string
  saldo_anterior: string
  saldo: string
}

/** S-88: billeteras de un mismo dueño. "Sin dueño" agrupa las que todavía no tienen. */
export interface GrupoBilleteraCaja {
  propietario_id: number | null
  propietario: string
  neto: string
  saldo_anterior: string
  saldo: string
  cuentas: FilaBilleteraCaja[]
}

export interface FilaCuentaCaja {
  cuenta_pago_id: number | null
  nombre: string
  tipo: TipoCuentaPago | null
  activa: boolean
  /** S-88. */
  propietario_id?: number | null
  propietario?: string | null
  /** S-88: solo en billeteras. Calculado, no guardado. */
  saldo_anterior?: string
  saldo?: string
  ventas: string
  ventas_sin_detalle?: string
  recaudo: string
  pagos_proveedores: string
  otros_ingresos: string
  ingresos_externos: string
  egresos: string
  devoluciones: string
  /** RF-27: abonos y préstamos de empleados por cuenta. */
  abonos_empleados?: string
  prestamos_empleados?: string
  neto: string
}

export interface ResumenCaja {
  fecha: string
  cuentas: FilaCuentaCaja[]
  /** S-88: saldos del día agrupados por dueño. */
  billeteras?: GrupoBilleteraCaja[]
  ventas_contado: string
  ventas_sin_detalle: string
  ventas_credito: string
  devoluciones: string
  recaudo_cartera: string
  pagos_proveedores: string
  otros_ingresos: string
  ingresos_externos: string
  egresos: { vario: string; fijo: string; financiero: string; total: string }
  /** RF-27: abonos y préstamos de empleados en efectivo (si existen). */
  efectivo: { ventas: string; recaudo: string; otros_ingresos: string; ingresos_externos: string; devoluciones: string; pagos_proveedores: string; egresos: string; abonos_empleados?: string; prestamos_empleados?: string; neto: string }
  total_ingreso_dia: string
  efectivo_neto_dia: string
  /**
   * S-84: apertura efectiva del día (del cierre, cambio manual o arrastre del último cierre). null
   * solo el primer día sin cierres ni apertura: entonces el día no se cierra.
   */
  apertura: AperturaCaja | null
  /** S-84: el mostrador puede cambiarla (hoy o antes, sin cierre vigente). */
  apertura_editable: boolean
  /** Apertura + efectivo neto del día; null sin apertura efectiva (y sin ella no se cierra). */
  efectivo_esperado: string | null
  meta_venta_diaria: string
  faltante_meta: string
  /**
   * S-75: totales de la pestaña Movimientos, sin anulados. Gastos = egresos.total; ingresos = otros
   * ingresos + ingresos externos de todas las cuentas. Solo en el resumen del día.
   */
  totales_movimientos?: {
    gastos: TotalMovimientos
    ingresos: TotalMovimientos
    recaudos?: TotalMovimientos
    /** RF-27: préstamos y abonos de empleados del día, sin anulados; ni gasto ni ingreso. Nómina no mueve la caja. */
    empleados?: { prestamos: TotalMovimientos; abonos_caja: TotalMovimientos; abonos_nomina: TotalMovimientos }
  }
}

/** S-75: cantidad y total (sin anulados) de un tipo de movimiento de caja. */
export interface TotalMovimientos {
  cantidad: number
  total: string
}

/**
 * S-84: apertura efectiva de un día. `arrastre` = efectivo contado del último cierre vigente anterior
 * (en vivo); `manual` = cambiada a mano (con motivo si difiere); `cierre` = la guardada en el cierre.
 */
export interface AperturaCaja {
  valor: string
  origen: 'arrastre' | 'manual' | 'cierre'
  /** En un día cerrado, de dónde vino la apertura que se usó. */
  origen_usado: 'arrastre' | 'manual'
  /** Fecha del último cierre vigente anterior (de donde arrastra). */
  desde_cierre: string | null
  /** Lo que dejó ese cierre (para comparar con un cambio manual). */
  cierre_anterior_valor: string | null
  motivo: string | null
  fijado_por: { id: number; name: string } | null
  fijado_at: string | null
  /** Entre el último cierre y este día quedaron días con movimientos sin cerrar (aviso, no bloquea). */
  hay_dias_sin_cerrar: boolean
}

export interface CierreCaja {
  id: number
  fecha: string
  /** S-49: null en los cierres anteriores a la apertura diaria. */
  apertura: string | null
  efectivo_contado: string
  efectivo_esperado: string
  diferencia: string
  /** Columnas del modelo anterior (saldo encadenado). En los cierres nuevos repiten apertura y esperado. */
  saldo_anterior: string
  saldo_acumulado: string
  observacion: string | null
  cerrado_at: string
  usuario: { id: number; name: string } | null
  reapertura: { motivo: string; created_at: string; usuario: { id: number; name: string } | null } | null
}

export interface DiaCaja {
  fecha: string
  hoy: string
  resumen: ResumenCaja
  movimientos: MovimientoCaja[]
  /** S-76: recaudos de documentos del día (con los demás movimientos). */
  recaudos: RecaudoDocumento[]
  /** RF-27: préstamos y abonos de empleados del día (sin la apertura); nunca saldos (S-57). */
  movimientos_empleado?: MovimientoEmpleadoDia[]
  cierre: CierreCaja | null
  cierres_reabiertos: CierreCaja[]
  ventas_abiertas: { id: number; forma_pago: FormaPago; items_count: number; usuario: string | null }[]
}

export interface FilaMesCaja {
  fecha: string
  apertura: string | null
  /** S-84: de dónde viene la apertura (null si no hay). */
  apertura_origen: 'arrastre' | 'manual' | null
  ventas_efectivo: string
  ventas_banco: string
  ventas_datafono: string
  ventas_billetera: string
  ventas_credito: string
  otros_ingresos: string
  total_ingreso_dia: string
  egresos: string
  efectivo_neto_dia: string
  efectivo_esperado: string | null
  efectivo_contado: string | null
  diferencia: string | null
  /** Informativo: suma del efectivo neto desde el primer día del mes (empieza en 0). */
  neto_acumulado_mes: string
  meta: string
  faltante_meta: string
  observacion: string | null
  cerrado: boolean
}

export interface MesCaja {
  mes: string
  meta_venta_diaria: string
  dias: FilaMesCaja[]
  /** S-88: movimiento del mes y saldo al cierre del período, por dueño. */
  billeteras?: GrupoBilleteraCaja[]
  totales: Pick<FilaMesCaja, 'ventas_efectivo' | 'ventas_banco' | 'ventas_datafono' | 'ventas_billetera' | 'ventas_credito' | 'otros_ingresos'
    | 'total_ingreso_dia' | 'egresos' | 'efectivo_neto_dia' | 'neto_acumulado_mes' | 'meta' | 'faltante_meta'> & { diferencia: string; dias_cerrados: number; dias_sin_cerrar: number }
}

/* ───────────── RF-27 (S-55 a S-57): cuenta de empleados ───────────── */

/** Fila del resumen del admin: saldo vigente y fecha del último movimiento. */
export interface EmpleadoResumen {
  id: number
  nombre: string
  documento: string | null
  activo: boolean
  saldo: string
  ultimo_movimiento: string | null
}

export interface ResumenEmpleados extends Pagina<EmpleadoResumen> {
  totales: { total_pendiente: string; empleados_con_saldo: number }
}

export type TipoFilaEmpleado = 'prestamo' | 'abono' | 'anulacion'

export type FormaAbonoEmpleado = 'caja' | 'nomina'

/** Empleado activo para elegir (mostrador y admin), con su saldo vigente (S-81, ajusta S-57). */
export interface EmpleadoOpcion {
  id: number
  nombre: string
  documento: string | null
  /** Saldo vigente (≥ 0), p. ej. "30000.00". */
  saldo: string
}

/** Préstamo o abono de empleado en la caja del día. Anulado sigue visible. */
export interface MovimientoEmpleadoDia {
  id: number
  empleado_id: number
  empleado: { id: number; nombre: string }
  tipo: 'prestamo' | 'abono'
  fecha: string
  valor: string
  forma: FormaAbonoEmpleado | null
  cuenta: { id: number; nombre: string } | null
  nota: string | null
  usuario: { id: number; name: string }
  created_at: string
  anulacion: { motivo: string; created_at: string; usuario: { id: number; name: string } | null } | null
}

/** Fila del estado de cuenta: un préstamo, un abono o la anulación de uno de ellos, con saldo corrido. */
export interface FilaEstadoEmpleado {
  clave: string
  tipo: TipoFilaEmpleado
  movimiento_id: number
  anula?: 'prestamo' | 'abono'
  fecha: string
  registrado_at: string | null
  prestamo: string | null
  abono: string | null
  forma?: 'caja' | 'nomina' | null
  cuenta?: string | null
  apertura?: boolean
  nota?: string | null
  motivo?: string
  usuario: string | null
  anulado?: boolean
  anulacion?: { motivo: string; fecha: string; usuario: string | null } | null
  saldo: string
}

export interface EstadoCuentaEmpleado {
  empleado: { id: number; nombre: string; documento: string | null; activo: boolean }
  saldo: string
  movimientos: FilaEstadoEmpleado[]
}

/* ───────────── RF-28 (S-58, S-60, S-61): corrección de un ingreso confirmado ───────────── */

export interface LineaCorreccionFoto {
  id: number | null
  referencia_id: number
  codigo_referencia: string | null
  cantidad: number
  costo_unitario: string
  descuento_porcentaje: string
  iva_porcentaje: string
  cantidad_cubierta: number
}

export interface FotoCorreccion {
  encabezado: {
    numero_documento: string
    fecha: string
    forma_pago: FormaPago
    vence_el: string | null
    descuento_documento: string
    total: string | null
  }
  lineas: LineaCorreccionFoto[]
}

export interface CorreccionIngreso {
  id: number
  created_at: string
  usuario?: { id: number; name: string }
  ingreso?: { id: number; tipo: TipoIngreso; numero_documento: string; proveedor: { id: number; nombre: string } | null }
  motivo: string
  unidades_creadas: number
  unidades_retiradas: number
  total_anterior: string | null
  total_nuevo: string
  antes: FotoCorreccion
  despues: FotoCorreccion
}

/** Línea del cuerpo de la corrección: con id = existente; sin id = nueva (referencia_id). */
export interface LineaCorreccionEnvio {
  id?: number
  referencia_id?: number
  cantidad: number
  costo_unitario: number
  descuento_porcentaje: number
  iva_porcentaje: number
}

export interface VistaPreviaCorreccion {
  total_anterior: string | null
  total_nuevo: string
  totales: { subtotal_neto: string; descuento_documento: string; iva: string; total: string }
  unidades_crear: number
  unidades_retirar: number
  con_etiqueta_impresa: number
  requiere_confirmar_etiquetas: boolean
  requiere_aceptar_diferencia: boolean
  diferencias: Array<{ referencia_id: number; codigo_referencia: string | null; recibido: number; facturado: number }>
  por_referencia: Array<{ referencia_id: number; codigo_referencia: string | null; crear: number; retirar: number; con_etiqueta_impresa: number }>
  advertencias: Array<{ tipo: string; mensaje: string; cantidad?: number }>
  errores: Array<{ tipo: string; mensaje: string; referencia_id?: number; codigo_referencia?: string | null; vendidas?: number }>
  antes: FotoCorreccion
  despues: FotoCorreccion
}

/** RF-30: documento con saldo vencido o que vence dentro del aviso (config cartera.aviso_dias). */
export type TipoVencimiento = 'por_pagar' | 'por_cobrar'

export interface FilaVencimiento {
  tipo: TipoVencimiento
  estado: 'vencida' | 'por_vencer'
  titular: { id: number; nombre: string }
  documento_id: number
  documento: string
  fecha: string
  vence_el: string
  dias_vencido: number
  dias_para_vencer: number
  valor: string
  pendiente: string
  /** S-79: lo ya pagado o abonado al documento (pagos parciales). */
  abonado: string
}

type GrupoVencimiento = { cantidad: number; pendiente: string }

export interface Vencimientos extends PaginaInforme<FilaVencimiento, { vencidas: GrupoVencimiento; por_vencer: GrupoVencimiento; todas: GrupoVencimiento }> {
  hoy: string
  aviso_dias: number
}

/**
 * S-71: último costo de compra (neto unitario, sin IVA) de una referencia. Mostrador recibe solo
 * el valor; admin y logística también el documento. Sin fecha: está en el historial.
 */
export interface UltimoCosto {
  valor: string
  ingreso_id?: number
  tipo?: TipoIngreso
  numero_documento?: string
}

/** S-71: una compra del historial de costos. Cifras en cadenas decimales del backend. */
export interface FilaCosto {
  linea_id: number
  fecha: string
  ingreso_id: number
  tipo: TipoIngreso
  numero_documento: string
  proveedor: Opcion
  referencia: { id: number; codigo_referencia: string; calidad: Calidad | null; descripcion?: string }
  cantidad: number
  costo_factura: string
  descuento_porcentaje: string
  iva_porcentaje: string
  /** null = "Sin costo". */
  costo_neto: string | null
  costo_con_iva: string | null
  /** % frente a la compra anterior que cuenta (null si no aplica). */
  variacion: string | null
  ultimo: boolean
  anterior: boolean
  sin_costo: boolean
  /** RF-28: el documento tuvo una corrección. */
  corregida: boolean
  /** RF-28: la corrección cambió el costo de esta línea (se muestra el vigente). */
  costo_corregido: boolean
  /** RF-19: número de la factura que cubre esta remisión (su costo no cuenta). */
  cubierta_por: string | null
  /** RF-19: remisiones que cubre esta factura. */
  cubre_remisiones: string[]
}

export interface ProductoCostos {
  tipo: 'referencia' | 'universal'
  referencia?: {
    id: number
    codigo_referencia: string
    descripcion: string
    proveedor: Opcion
    calidad: Calidad | null
    existencia: number
    precio_venta: string
    activo: boolean
    codigo_universal: { id: number; codigo: string } | null
  }
  universal?: {
    id: number
    codigo: string
    descripcion: string | null
    existencia_total: number
    referencias: { id: number; codigo_referencia: string; proveedor: Opcion; calidad: Calidad | null; existencia: number; activo: boolean }[]
  }
}

export interface CompraResumen { valor: string; fecha: string; ingreso_id: number; tipo: TipoIngreso; numero_documento: string }

/** Sin producto elegido, `producto` y `resumen` llegan en null (lista general de compras). */
export interface HistorialCostos extends Pagina<FilaCosto> {
  producto: ProductoCostos | null
  resumen: { ultimo: CompraResumen | null; anterior: CompraResumen | null; variacion: string | null; compras: number } | null
  /**
   * S-89: lo que hay para filtrar en el historial del producto, sin aplicar los filtros (compras
   * confirmadas sin la remisión cubierta y proveedores distintos de esas compras). Null en la lista general.
   */
  opciones_filtro?: { compras: number; proveedores: Opcion[] } | null
}

/** RF-21 (S-87): tipos de movimiento de GET /movimientos-inventario (ingreso = entrada por compra). */
export type TipoMovimientoInventario = 'ingreso' | 'venta' | 'reverso' | 'baja' | 'reintegro' | 'alta'

/** RF-21 (S-87): una fila del kardex por unidad; solo admin y logística. */
export interface MovimientoInventario {
  id: number
  tipo: TipoMovimientoInventario
  created_at: string
  metodo_captura: MetodoCaptura | null
  usuario: { id: number; name: string } | null
  referencia: { id: number; codigo_referencia: string; descripcion: string; calidad: Calidad | null; proveedor: { id: number; nombre: string } }
  unidad: { id: number; codigo_barras: string } | null
  origen: { tipo: string; id: number; etiqueta: string } | null
}
