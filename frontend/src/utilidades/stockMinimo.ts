/** RF-22: mismo rango que el backend (entero 0..9999). '' = sin valor (usa el general). */
export const STOCK_MINIMO_MAXIMO = 9999

export function errorStockMinimo(texto: string): string | null {
  const t = texto.trim()
  if (t === '') return null
  if (!/^\d+$/.test(t)) return 'Debe ser un número entero, sin decimales ni signo.'
  if (Number(t) > STOCK_MINIMO_MAXIMO) return `No puede pasar de ${STOCK_MINIMO_MAXIMO}.`
  return null
}

/** '' -> null; texto válido -> número. */
export function aValorMinimo(texto: string): number | null {
  return texto.trim() === '' ? null : Number(texto)
}

/** Texto corto para listar un mínimo guardado. */
export function textoMinimo(valor: number | null | undefined, vacio = 'General'): string {
  if (valor == null) return vacio
  return valor === 0 ? 'No maneja' : String(valor)
}
