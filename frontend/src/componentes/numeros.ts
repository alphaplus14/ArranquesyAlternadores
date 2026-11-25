/**
 * Números escritos en formato es-CO: punto para miles y coma para decimales ("72.500,50").
 * Sin coma, un punto seguido de 1 o 2 dígitos al final son decimales ("85000.5"): los miles llevan 3.
 * null = vacío; NaN = no es un número válido (negativos incluidos) o trae más de 2 decimales.
 */
export function leerNumero(texto: string): number | null {
  let limpio = texto.trim().replace(/[\s$]/g, '')
  limpio = limpio.replace(/,$/, '') // "1.250," a medio escribir (S-52) vale 1250
  if (!limpio.includes(',') && /\.\d{1,2}$/.test(limpio)) limpio = limpio.replace(/\.(?=\d{1,2}$)/, ',')
  limpio = limpio.replace(/\./g, '').replace(',', '.')
  if (limpio === '') return null
  return /^\d+(\.\d{1,2})?$/.test(limpio) ? Number(limpio) : Number.NaN
}

// Los formateadores se crean una sola vez: toLocaleString con opciones arma uno nuevo en cada llamada,
// que es lo más caro de formatear, y la venta de mostrador formatea precios en cada render.
const formatoEntero = new Intl.NumberFormat('es-CO', { minimumFractionDigits: 0, maximumFractionDigits: 0 })
const formatoDosDecimales = new Intl.NumberFormat('es-CO', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const formatoPesos = new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 })

/** Con decimales, siempre dos cifras ("72.500,50"); sin decimales, ninguna ("85.000"). */
export function formatoNumero(valor: string | number) {
  const n = Number(valor)
  return (Number.isInteger(n) ? formatoEntero : formatoDosDecimales).format(n)
}

export const pesos = (n: number | string) => formatoPesos.format(Number(n))

/**
 * S-52: dinero escrito en formato es-CO mientras se digita ("1.250.000,5"): punto para miles y
 * coma para decimales, máximo 2. Se conservan solo dígitos y la primera coma; un punto escrito
 * al final (tecla decimal del teclado numérico) se toma como coma. El texto resultante lo lee
 * `leerNumero`, que entrega el número sin formato que va al backend.
 */
export function formatearDinero(texto: string): string {
  let crudo = texto
  if (!crudo.includes(',') && crudo.endsWith('.')) crudo = `${crudo.slice(0, -1)},`
  const limpio = crudo.replace(/[^\d,]/g, '')
  const coma = limpio.indexOf(',')
  const entero = (coma === -1 ? limpio : limpio.slice(0, coma)).replace(/^0+(?=\d)/, '')
  const miles = entero.replace(/\B(?=(\d{3})+(?!\d))/g, '.')
  if (coma === -1) return miles
  return `${miles || '0'},${limpio.slice(coma + 1).replace(/,/g, '').slice(0, 2)}`
}
