import { pesos } from '../componentes/numeros'

/** RF-15 (S-30): saldo con su signo; negativo se muestra como saldo a favor. */
export function textoSaldo(saldo: string | number): string {
  const n = Number(saldo)
  return n < 0 ? `${pesos(-n)} a favor` : pesos(n)
}
