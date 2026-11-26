import { formatearDinero, formatoNumero } from '../../componentes/numeros'
import { formatearFecha, formatearFechaHora } from '../../utilidades/fechas'

/**
 * RF-11: presentación del historial de auditoría. Solo traduce y da formato; los valores guardados
 * (valor_anterior y valor_nuevo) no se cambian. Un campo que no esté aquí se muestra con su
 * nombre técnico.
 */

/** Nombres legibles de los campos auditados (todas las tablas con `Auditable`). */
export const ETIQUETAS_CAMPO: Record<string, string> = {
  // Referencias y códigos universales
  codigo_referencia: 'Referencia',
  codigo_fabricante: 'Código de fabricante',
  codigo: 'Código',
  calidad: 'Calidad',
  descripcion: 'Descripción',
  precio_venta: 'Precio de venta',
  iva_venta_porcentaje: 'IVA de venta',
  existencia: 'Existencia',
  stock_minimo: 'Stock mínimo',
  proveedor_id: 'Proveedor',
  categoria_id: 'Categoría',
  codigo_universal_id: 'Código universal',
  // Proveedores, clientes, categorías, empleados, usuarios
  nombre: 'Nombre',
  nit: 'NIT',
  asesor: 'Asesor',
  plazo_dias: 'Plazo (días)',
  documento: 'Documento',
  telefono: 'Teléfono',
  admite_codigo_universal: 'Admite código universal',
  name: 'Nombre',
  usuario: 'Usuario',
  password: 'Contraseña',
  rol: 'Rol',
  activo: 'Activo',
  activa: 'Activa',
  // Ingresos (compras) y sus líneas
  tipo: 'Tipo',
  factura_id: 'Factura',
  forma_pago: 'Forma de pago',
  numero_documento: 'Número del documento',
  fecha: 'Fecha',
  vence_el: 'Vence el',
  descuento_documento: 'Descuento del documento',
  descuento_documento_porcentaje: 'Descuento del documento (%)',
  total: 'Total',
  subtotal: 'Subtotal',
  iva: 'IVA',
  diferencia_aceptada: 'Diferencia aceptada',
  diferencia_motivo: 'Motivo de la diferencia',
  estado: 'Estado',
  usuario_id: 'Usuario',
  confirmado_por: 'Confirmado por',
  confirmado_at: 'Confirmado el',
  ingreso_id: 'Ingreso',
  referencia_id: 'Referencia',
  cantidad: 'Cantidad',
  cantidad_cubierta: 'Cantidad cubierta',
  costo_unitario: 'Costo unitario',
  descuento_porcentaje: 'Descuento (%)',
  iva_porcentaje: 'IVA (%)',
  // Ventas
  venta_id: 'Venta',
  unidad_id: 'Unidad',
  precio_unitario: 'Precio unitario',
  cliente_id: 'Cliente',
  // Caja (RF-24 a RF-27) y configuración
  grupo: 'Grupo',
  valor: 'Valor',
  clave: 'Clave',
  cuenta_pago_id: 'Cuenta',
  categoria_caja_id: 'Categoría de caja',
  empleado_id: 'Empleado',
}

export const etiquetaCampo = (campo: string) => ETIQUETAS_CAMPO[campo] ?? campo

const DINERO = new Set([
  'precio_venta', 'total', 'subtotal', 'iva', 'descuento_documento', 'costo_unitario', 'precio_unitario',
  'precio', 'diferencia_aceptada',
])
/** Campos que son dinero solo en ciertas tablas ("valor" de configuración no lo es). */
const DINERO_EN_TABLA = new Set(['aperturas_caja.valor'])
const PORCENTAJE = new Set(['iva_venta_porcentaje', 'descuento_documento_porcentaje', 'descuento_porcentaje', 'iva_porcentaje'])
const BOOLEANO = new Set(['activo', 'activa', 'admite_codigo_universal'])
/** Valores de una lista cerrada: primera letra en mayúscula y "_" como espacio. */
const ESTADO = new Set(['estado', 'tipo', 'forma_pago', 'calidad', 'rol', 'grupo', 'metodo_captura', 'origen_codigo'])
/** Fechas de calendario: se muestran como dd/mm/aaaa, sin convertir de zona. */
const SOLO_FECHA = new Set(['fecha', 'vence_el'])

/** Nombres de apoyo que devuelve el endpoint: campo de id => { id: nombre }. */
export type NombresAuditoria = Record<string, Record<string, string>>

const VACIO = '—'

function capitalizar(texto: string) {
  const t = texto.replace(/_/g, ' ')
  return t.charAt(0).toLocaleUpperCase('es') + t.slice(1)
}

/** Un valor auditado (texto guardado, o el valor de un campo dentro de una foto JSON) para leer. */
export function formatearValor(tabla: string, campo: string, valor: unknown, nombres: NombresAuditoria): string {
  if (valor === null || valor === undefined || valor === '') return VACIO
  const texto = typeof valor === 'string' ? valor : typeof valor === 'object' ? JSON.stringify(valor) : String(valor)

  if (campo in nombres) return nombres[campo][texto] ?? `#${texto}`
  if (BOOLEANO.has(campo)) {
    if (valor === true || texto === '1' || texto === 'true') return 'Sí'
    if (valor === false || texto === '0' || texto === 'false') return 'No'
  }
  const numero = Number(texto)
  const esNumero = texto.trim() !== '' && Number.isFinite(numero)
  if (esNumero && (DINERO.has(campo) || DINERO_EN_TABLA.has(`${tabla}.${campo}`))) return `$ ${formatearDinero(formatoNumero(numero))}`
  if (esNumero && PORCENTAJE.has(campo)) return `${formatoNumero(numero)} %`
  // Una fecha con cast se guardó como "AAAA-MM-DD 00:00:00": se toma solo el día.
  if (SOLO_FECHA.has(campo)) return formatearFecha(texto.slice(0, 10))
  // Instantes guardados en UTC ("AAAA-MM-DD HH:MM:SS"): a hora de Colombia, como la columna Fecha.
  if (campo.endsWith('_at')) return formatearFechaHora(texto)
  if (ESTADO.has(campo)) return capitalizar(texto)
  return texto
}

/** Foto JSON de una creación o un borrado (campo "*"). null si el texto no es un objeto JSON. */
export function leerFoto(texto: string | null): Record<string, unknown> | null {
  if (!texto) return null
  try {
    const datos: unknown = JSON.parse(texto)
    return datos && typeof datos === 'object' && !Array.isArray(datos) ? (datos as Record<string, unknown>) : null
  } catch {
    return null
  }
}

/** Campos de la foto que se listan en "Ver detalle" (el id ya está en la columna Registro). */
export function camposDeFoto(tabla: string, foto: Record<string, unknown>, nombres: NombresAuditoria) {
  return Object.entries(foto)
    .filter(([campo]) => campo !== 'id')
    .map(([campo, valor]) => ({ campo, etiqueta: etiquetaCampo(campo), valor: formatearValor(tabla, campo, valor, nombres) }))
}

/** Resumen de una línea de la foto: lo que identifica al registro en cada tabla. */
export function resumenFoto(tabla: string, foto: Record<string, unknown>, nombres: NombresAuditoria): string {
  const v = (campo: string) => (foto[campo] === null || foto[campo] === undefined || foto[campo] === '' ? null : formatearValor(tabla, campo, foto[campo], nombres))
  const unir = (...partes: (string | null)[]) => partes.filter(Boolean).join(' · ')
  switch (tabla) {
    case 'referencias': return unir(v('codigo_referencia'), v('descripcion')) || VACIO
    case 'codigos_universales': return unir(v('codigo'), v('descripcion')) || VACIO
    case 'ingreso_items': return unir(v('referencia_id'), foto.cantidad != null ? `${v('cantidad')} u.` : null, v('costo_unitario')) || VACIO
    case 'ingresos': return unir(v('tipo'), v('numero_documento'), v('proveedor_id')) || VACIO
    case 'aperturas_caja': return unir(v('fecha'), v('valor')) || VACIO
    case 'users': return unir(v('name'), v('rol')) || VACIO
    case 'categorias_caja': return unir(v('nombre'), v('grupo')) || VACIO
    case 'cuentas_pago': return unir(v('nombre'), v('tipo')) || VACIO
    default: {
      const titular = v('nombre') ?? v('name') ?? v('codigo') ?? v('descripcion')
      if (titular) return titular
      const primero = camposDeFoto(tabla, foto, nombres)[0]
      return primero ? `${primero.etiqueta}: ${primero.valor}` : VACIO
    }
  }
}
