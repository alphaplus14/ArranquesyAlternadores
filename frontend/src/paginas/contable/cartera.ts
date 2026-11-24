import type { QueryClient } from '@tanstack/react-query'

/** Tras un pago o una anulación, todo lo que muestra saldos se vuelve a pedir. */
export function invalidarCartera(qc: QueryClient) {
  for (const clave of ['vencimientos', 'cuentas-por-pagar', 'cartera-clientes', 'saldo-proveedor', 'estado-cuenta']) {
    qc.invalidateQueries({ queryKey: [clave] })
  }
}
