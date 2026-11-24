import { pesos } from '../componentes/numeros'

/** RF-15 (S-30): saldo con su signo; negativo se muestra como saldo a favor. */
export function textoSaldo(saldo: string | number): string {
  const n = Number(saldo)
  return n < 0 ? `${pesos(-n)} a favor` : pesos(n)
}

/** S-32: "#11 · FE-1042" o "#11" (n.º de venta y factura de Contapyme si la tiene). */
export function nombreVenta(v: { id: number; factura_contapyme: string | null }): string {
  return `#${v.id}${v.factura_contapyme ? ` · ${v.factura_contapyme}` : ''}`
}
