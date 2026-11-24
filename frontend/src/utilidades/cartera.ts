import { pesos } from '../componentes/numeros'

/** RF-15 (S-30): saldo con su signo; negativo se muestra como saldo a favor. */
export function textoSaldo(saldo: string | number): string {
  const n = Number(saldo)
  return n < 0 ? `${pesos(-n)} a favor` : pesos(n)
}

/**
 * S-33: rótulo de la línea de IVA del total de una venta. Con un solo porcentaje lo dice
 * ("IVA 19 % incluido"); con varios (o ninguno), solo "IVA incluido".
 */
export function rotuloIvaIncluido(porcentajes: number[]): string {
  return porcentajes.length === 1 ? `IVA ${porcentajes[0].toLocaleString('es-CO')} % incluido` : 'IVA incluido'
}

/** S-32: "#11 · FE-1042" o "#11" (n.º de venta y factura de Contapyme si la tiene). */
export function nombreVenta(v: { id: number; factura_contapyme: string | null }): string {
  return `#${v.id}${v.factura_contapyme ? ` · ${v.factura_contapyme}` : ''}`
}
