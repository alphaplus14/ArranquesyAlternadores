export type Rol = 'admin' | 'logistica' | 'mostrador'
export type MetodoCaptura = 'escaneado' | 'digitado'

export interface Usuario {
  id: number
  name: string
  /** Nombre de usuario con que se inicia sesión (no es un correo). */
  usuario: string
  rol: Rol
  activo?: boolean
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
  unidades_disponibles?: Unidad[]
}

export interface DesgloseUniversal {
  id: number
  codigo: string
  /** RF-22: null = usa el general; 0 = no maneja. */
  stock_minimo?: number | null
  descripcion: string | null
  categoria: Opcion
  existencia_total: number
  /** RF-02 / S-23: desglose por referencia, ordenado por código. */
  referencias: Array<{
    id: number
    codigo_referencia: string
    descripcion: string
    proveedor: Opcion
    calidad: Calidad | null
    existencia: number
    updated_at: string
  }>
}

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
  } | null
  /** RF-19: en una remisión, la factura que la cubre; en una factura, sus remisiones ligadas. */
  factura: { id: number; numero_documento: string; estado: Ingreso['estado'] } | null
  remisiones: RemisionLigada[]
  diferencia_aceptada: boolean
  diferencia_motivo: string | null
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
}

export interface Venta {
  id: number
  estado: 'abierta' | 'cerrada'
  /** RF-14 */
  forma_pago: FormaPago
  cliente: { id: number; nombre: string; documento: string | null; plazo_dias: number } | null
  /** Número de factura de Contapyme, solo como texto de referencia. */
  factura_contapyme: string | null
  usuario?: { id: number; name: string }
  created_at: string
  cerrada_at: string | null
  /** Fecha (aaaa-mm-dd) que calcula el backend al cerrar una venta a crédito. */
  vence_el: string | null
  items: VentaItem[]
  reversos: Reverso[]
  /** S-33 / S-15: precios con IVA incluido; total de los ítems vigentes y su desglose. */
  totales?: TotalesVenta
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
  fecha: string
  valor: string
  medio: MedioDinero
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
  dias_vencido: number
  rango: RangoAntiguedad
}

export interface SaldoCliente extends ResumenSaldo {
  cliente: Cliente
  corte: string
  documentos: DocumentoPendiente[]
  abonos_recientes: MovimientoDinero[]
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
  items: number
  base: string
  iva: string
  total: string
}

export interface FilaCartera extends ResumenSaldo {
  cliente?: { id: number; nombre: string; documento: string | null; plazo_dias: number }
  proveedor?: { id: number; nombre: string; nit: string | null; plazo_dias: number | null }
}

export type TotalesCartera = Omit<ResumenSaldo, 'rangos'> & { rangos: Record<RangoAntiguedad, string> }

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
