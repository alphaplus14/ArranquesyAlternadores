import type { IngresoItem } from '../../api/tipos'
import { formatoNumero, leerNumero } from '../../componentes/numeros'

export type Campo = 'cantidad' | 'costo_unitario' | 'descuento_porcentaje' | 'iva_porcentaje'
export const CAMPOS: Campo[] = ['cantidad', 'costo_unitario', 'descuento_porcentaje', 'iva_porcentaje']
/** IVA de una línea de compra (S-22): 0 = sin IVA. Si la línea trae otro valor, se agrega. */
export const IVAS = [19, 5, 0]

export const AYUDA_DESCUENTO_LINEA = 'El documento ya tiene descuento: quítelo para dar descuento por ítem'
export const AYUDA_DESCUENTO_DOCUMENTO = 'Quite los descuentos de los ítems para usar uno del documento'

/** Una fila de líneas en edición: todos sus campos a la vez (solo una fila a la vez). */
export interface Fila {
  /** S-22: el documento tiene descuento; el de la línea queda de solo lectura (borrador). */
  descuentoBloqueado: boolean
  itemId: number
  codigo: string
  /** Campo donde empieza el foco (el que se tocó). */
  foco: Campo
  valores: Record<Campo, string>
  originales: Record<Campo, string>
  /** Se intentó guardar: se muestran todos los errores, no solo los de lo editado. */
  intentado: boolean
  errorServidor: string | null
  guardando: boolean
}

export type LineaEditable = Pick<IngresoItem, 'id' | 'referencia' | 'cantidad' | 'costo_unitario' | 'descuento_porcentaje' | 'iva_porcentaje'>

export function valoresDe(it: LineaEditable): Record<Campo, string> {
  return {
    cantidad: String(it.cantidad),
    costo_unitario: formatoNumero(it.costo_unitario),
    descuento_porcentaje: formatoNumero(it.descuento_porcentaje),
    iva_porcentaje: String(Number(it.iva_porcentaje)),
  }
}

export function erroresDe(f: Fila): Record<Campo, string | null> {
  return Object.fromEntries(CAMPOS.map((c) => [c, REGLAS[c].validar(leerNumero(f.valores[c]))])) as Record<Campo, string | null>
}

/** Solo los campos que cambiaron, como números: se guardan en una sola petición (PATCH acepta varios). */
export function cambiosDe(f: Fila): Partial<Record<Campo, number>> {
  const cambios: Partial<Record<Campo, number>> = {}
  for (const c of CAMPOS) {
    const nuevo = leerNumero(f.valores[c])
    if (nuevo !== null && nuevo !== leerNumero(f.originales[c])) cambios[c] = nuevo
  }
  return cambios
}

/** Validación en vivo de cada celda. El backend vuelve a validar al guardar y al confirmar. */
export const REGLAS: Record<Campo, { etiqueta: string; validar: (n: number | null) => string | null }> = {
  cantidad: {
    etiqueta: 'Cantidad',
    validar: (n) => (n === null ? 'Escriba la cantidad.' : !Number.isInteger(n) || n < 1 ? 'Debe ser un número entero, 1 o más.' : n > 10000 ? 'Máximo 10.000.' : null),
  },
  costo_unitario: {
    etiqueta: 'Costo',
    validar: (n) => (n === null ? 'Escriba el costo.' : Number.isNaN(n) ? 'Costo no válido.' : n <= 0 ? 'El costo debe ser mayor que 0.' : null),
  },
  descuento_porcentaje: {
    etiqueta: 'Desc. %',
    validar: (n) => (n === null ? 'Escriba el descuento (0 si no hay).' : Number.isNaN(n) || n > 100 ? 'Entre 0 y 100.' : null),
  },
  iva_porcentaje: {
    etiqueta: 'IVA %',
    validar: (n) => (n === null ? 'Escriba el IVA.' : Number.isNaN(n) || n > 100 ? 'Entre 0 y 100.' : null),
  },
}
