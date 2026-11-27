import type { QueryClient } from '@tanstack/react-query'

/** Tras un pago o una anulación, todo lo que muestra saldos se vuelve a pedir. */
export function invalidarCartera(qc: QueryClient) {
  for (const clave of ['vencimientos', 'cuentas-por-pagar', 'cartera-clientes', 'saldo-proveedor', 'saldo-cliente', 'historial-dinero', 'estado-cuenta']) {
    qc.invalidateQueries({ queryKey: [clave] })
  }
}

/** S-88: la lista (con sus filtros) a la que vuelve la miga de una cuenta. */
export function guardarVolver(detalle: string, url: string) {
  try { sessionStorage.setItem(`volver:${detalle}`, url) } catch { /* sin almacenamiento: la miga va a la lista sin filtros */ }
}

export function urlVolver(detalle: string, porDefecto: string): string {
  try { return sessionStorage.getItem(`volver:${detalle}`) ?? porDefecto } catch { return porDefecto }
}
