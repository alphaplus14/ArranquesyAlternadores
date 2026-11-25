import type { CuentaPago, PagoVentaNuevo } from '../api/tipos'
import { formatoNumero, leerNumero } from '../componentes/numeros'

/* RF-24 (S-48): estado de la forma de pago del cierre de una venta de contado. */

/** Una fila del pago: cuenta, valor (texto es-CO) y referencia opcional. */
export interface FilaPago {
  clave: number
  cuenta_pago_id: number | ''
  valor: string
  referencia: string
}

let secuencia = 0
export const nuevaFila = (cuenta: number | '' = '', valor = ''): FilaPago => ({ clave: ++secuencia, cuenta_pago_id: cuenta, valor, referencia: '' })

/** Primera fila: Efectivo con el total prellenado (S-48). */
export function filasIniciales(cuentas: CuentaPago[], total: string | number): FilaPago[] {
  const efectivo = cuentas.find((c) => c.tipo === 'efectivo')
  return [nuevaFila(efectivo?.id ?? '', formatoNumero(total))]
}

const centavos = (n: number) => Math.round(n * 100)

/**
 * S-48: estado del pago. `diferencia` en centavos = total − suma (positivo: falta; negativo: sobra).
 * Listo cuando cada fila tiene cuenta y valor > 0, no se repite una cuenta y la suma es exacta.
 * El backend vuelve a validar todo con la venta bloqueada.
 */
export function evaluarPago(filas: FilaPago[], total: string | number) {
  let suma = 0
  let invalida = false
  const usadas = new Set<number>()
  let repetida = false
  for (const f of filas) {
    const n = leerNumero(f.valor)
    if (n === null || Number.isNaN(n) || n <= 0 || f.cuenta_pago_id === '') invalida = true
    else suma += centavos(n)
    if (f.cuenta_pago_id !== '') {
      if (usadas.has(f.cuenta_pago_id)) repetida = true
      usadas.add(f.cuenta_pago_id)
    }
  }
  const diferencia = centavos(Number(total)) - suma
  return { diferencia, invalida, repetida, listo: filas.length > 0 && !invalida && !repetida && diferencia === 0 }
}

export function aPagos(filas: FilaPago[]): PagoVentaNuevo[] {
  return filas.map((f) => ({ cuenta_pago_id: Number(f.cuenta_pago_id), valor: leerNumero(f.valor) ?? 0, referencia: f.referencia.trim() || null }))
}
