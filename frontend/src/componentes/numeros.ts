/**
 * Números escritos en formato es-CO: punto para miles y coma para decimales ("72.500,50").
 * Sin coma, un punto seguido de 1 o 2 dígitos al final son decimales ("85000.5"): los miles llevan 3.
 * null = vacío; NaN = no es un número válido (negativos incluidos) o trae más de 2 decimales.
 */
export function leerNumero(texto: string): number | null {
  let limpio = texto.trim().replace(/[\s$]/g, '')
  if (!limpio.includes(',') && /\.\d{1,2}$/.test(limpio)) limpio = limpio.replace(/\.(?=\d{1,2}$)/, ',')
  limpio = limpio.replace(/\./g, '').replace(',', '.')
  if (limpio === '') return null
  return /^\d+(\.\d{1,2})?$/.test(limpio) ? Number(limpio) : Number.NaN
}

/** Con decimales, siempre dos cifras ("72.500,50"); sin decimales, ninguna ("85.000"). */
export function formatoNumero(valor: string | number) {
  const n = Number(valor)
  const decimales = Number.isInteger(n) ? 0 : 2
  return n.toLocaleString('es-CO', { minimumFractionDigits: decimales, maximumFractionDigits: decimales })
}

export const pesos = (n: number | string) =>
  Number(n).toLocaleString('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 })
