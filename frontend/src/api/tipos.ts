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

export interface Opcion {
  id: number
  nombre: string
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
  descuento: string
  iva_porcentaje: string
  sin_iva: boolean
  subtotal: number
  iva: number
}

export interface Ingreso {
  id: number
  tipo: 'remision' | 'factura_qr'
  estado: 'borrador' | 'confirmado'
  proveedor_id: number
  proveedor?: Opcion
  numero_documento: string
  cufe: string | null
  fecha: string
  confirmado_at: string | null
  /** RF-04: totales leídos del QR (solo en factura_qr) y diferencia contra lo digitado. */
  qr: {
    subtotal: string | null
    iva: string | null
    total: string | null
    diferencia: number | null
    tolerancia: number
  } | null
  items: IngresoItem[]
  totales: {
    subtotal: number
    descuento_lineas: number
    descuento_total: string
    iva: number
    lineas_sin_iva: number
    total: number
  } | null
}

export interface VentaItem {
  id: number
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
  usuario?: { id: number; name: string }
  created_at: string
  cerrada_at: string | null
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
