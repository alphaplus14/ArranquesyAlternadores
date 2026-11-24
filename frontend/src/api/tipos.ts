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
  plazo_dias?: number | null
}

export interface Categoria extends Opcion {
  admite_codigo_universal: boolean
}

export interface Unidad {
  id: number
  codigo_barras: string
  estado: 'disponible' | 'vendida'
  referencia_id: number
  referencia?: Referencia
}

/** S-23: calidad opcional de una referencia. */
export type Calidad = 'fino' | 'generico'

export const NOMBRE_CALIDAD: Record<Calidad, string> = { fino: 'Fino', generico: 'Genérico' }

export interface Referencia {
  id: number
  codigo_referencia: string
  calidad: Calidad | null
  descripcion: string
  precio_venta: string
  /** RF-14 / S-15: IVA con que se vende (0 = sin IVA). */
  iva_venta_porcentaje: string
  existencia: number
  proveedor_id: number
  categoria_id: number
  codigo_universal_id: number | null
  proveedor?: Opcion
  categoria?: Categoria
  codigo_universal?: { id: number; codigo: string } | null
  unidades_disponibles?: Unidad[]
}

export interface DesgloseUniversal {
  id: number
  codigo: string
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
  created_at: string
  usuario: { id: number; name: string }
  unidad: Unidad
  reverso_id: number | null
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
}

/** RF-06: respuesta de POST /ventas/{id}/escanear. `item` tiene la misma forma que en GET /ventas/{id}. */
export interface RespuestaEscaneo {
  venta_id: number
  item: VentaItem
  venta_item_id: number
  metodo_captura: MetodoCaptura
  unidad: { id: number; codigo_barras: string; estado: Unidad['estado'] }
  referencia: { id: number; codigo_referencia: string; descripcion: string; calidad: Calidad | null; proveedor: string; existencia: number }
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

/** RF-15 / RF-17: lista fija de config/cartera.php. */
export type MedioPago = 'efectivo' | 'transferencia' | 'tarjeta' | 'cheque' | 'otro'

export const NOMBRE_MEDIO: Record<MedioPago, string> = {
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
  medio: MedioPago
  referencia: string | null
  nota: string | null
  usuario: { id: number; name: string } | null
  created_at: string
  anulacion: { motivo: string; usuario: { id: number; name: string } | null; created_at: string } | null
}

export type RangoAntiguedad = 'al_dia' | 'd1_30' | 'd31_60' | 'd61_90' | 'mas_90'

export const NOMBRE_RANGO: Record<RangoAntiguedad, string> = {
  al_dia: 'Al día',
  d1_30: '1-30',
  d31_60: '31-60',
  d61_90: '61-90',
  mas_90: 'Más de 90',
}

/** Saldo con antigüedad a una fecha de corte (FIFO: lo abonado cubre primero lo más antiguo). */
export interface ResumenCartera {
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

export interface SaldoCliente extends ResumenCartera {
  cliente: Cliente
  corte: string
  documentos: DocumentoPendiente[]
  abonos_recientes: MovimientoDinero[]
}

export interface SaldoProveedor extends ResumenCartera {
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

export interface FilaCartera extends ResumenCartera {
  cliente?: { id: number; nombre: string; documento: string | null; plazo_dias: number }
  proveedor?: { id: number; nombre: string; nit: string | null; plazo_dias: number | null }
}

export type TotalesCartera = Omit<ResumenCartera, 'rangos'> & { rangos: Record<RangoAntiguedad, string> }

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

export interface EstadoCuenta extends PaginaInforme<FilaEstadoCuenta, { cargo: string; abono: string; saldo_final: string }> {
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
