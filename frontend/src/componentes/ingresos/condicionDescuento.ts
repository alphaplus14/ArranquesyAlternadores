/**
 * S-86 / S-95: condición del descuento de un ingreso. El descuento del documento y el descuento por
 * línea se excluyen (un 0 no cuenta); "mixto" solo existe en documentos anteriores a S-86.
 * La condición no se guarda: sale de los valores (la comparten el borrador y la corrección de RF-28).
 */
export type CondicionDescuento = 'ninguno' | 'documento' | 'producto' | 'mixto'
/** Las que se pueden elegir: "mixto" solo se hereda de un documento anterior a S-86. */
export type CondicionElegible = Exclude<CondicionDescuento, 'mixto'>

export const NOMBRE_CONDICION: Record<CondicionDescuento, string> = {
  ninguno: 'Sin descuento',
  documento: 'Descuento del documento',
  producto: 'Descuento por producto',
  mixto: 'Mixto (anterior)',
}

/** Condición según el descuento del documento (pesos o %) y los descuentos % de las líneas vigentes. */
export function condicionDe(documento: number, lineas: number[]): CondicionDescuento {
  const conDocumento = documento > 0
  const conLineas = lineas.some((d) => d > 0)
  if (conDocumento && conLineas) return 'mixto'
  if (conDocumento) return 'documento'
  if (conLineas) return 'producto'
  return 'ninguno'
}

/** S-95: textos cortos del chip y del control (una sola línea, sin partirse). */
export const NOMBRE_CORTO: Record<CondicionDescuento, string> = {
  ninguno: 'Sin descuento',
  documento: 'Del documento',
  producto: 'Por producto',
  mixto: 'Mixto (anterior)',
}

export const AYUDA_LINEAS_CON_DOCUMENTO = 'El documento tiene descuento: se reparte entre todos los ítems'
export const AYUDA_MIXTO = 'Mezcla descuento del documento y por producto. Elija una condición para modificarlos.'
