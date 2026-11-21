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

export interface Referencia {
  id: number
  codigo_referencia: string
  descripcion: string
  precio_venta: string
  /** RF-14 / S-15: IVA con que se vende (0 = sin IVA). */
  iva_venta_porcentaje: string
  existencia: number
  marca_id: number
  proveedor_id: number
  categoria_id: number
  codigo_universal_id: number | null
  marca?: Opcion
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
  referencias: Array<{
    id: number
    codigo_referencia: string
    descripcion: string
    marca: Opcion
    proveedor: Opcion
    existencia: number
    updated_at: string
  }>
  por_marca: Array<{ marca_id: number; marca: string; existencia: number }>
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

export function infoPagina<T>(p: Pagina<T>) {
  return {
    actual: p.meta?.current_page ?? p.current_page ?? 1,
    ultima: p.meta?.last_page ?? p.last_page ?? 1,
    total: p.meta?.total ?? p.total ?? p.data.length,
  }
}
