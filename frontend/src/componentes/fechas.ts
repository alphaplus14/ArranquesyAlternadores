import { formatearFecha } from '../utilidades/fechas'
import { hoyEnBogota } from '../utilidades/periodos'

/**
 * RF-16: prellenado del vencimiento = fecha del documento + plazo del proveedor. Solo sugiere el
 * valor en pantalla; el usuario lo corrige con la fecha que trae el documento y el backend valida.
 * Fechas aaaa-mm-dd en hora local, para que la zona horaria no corra el día.
 */
export function sumarDias(fecha: string, dias: number): string {
  const [a, m, d] = fecha.split('-').map(Number)
  if (!a || !m || !d) return ''
  return new Date(a, m - 1, d + dias).toLocaleDateString('en-CA')
}

/** Hoy en hora de Colombia (aaaa-mm-dd), no en la zona del navegador. */
export function hoy(): string {
  return hoyEnBogota()
}

/** aaaa-mm-dd → dd/mm/aaaa sin cambiar de zona; un instante → su día en Colombia (S-38). */
export function fechaCorta(valor: string): string {
  return formatearFecha(valor)
}
