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

/** aaaa-mm-dd → dd/mm/aaaa (es-CO). */
export function fechaCorta(iso: string): string {
  const [a, m, d] = iso.split('-').map(Number)
  return new Date(a, m - 1, d).toLocaleDateString('es-CO', { day: '2-digit', month: '2-digit', year: 'numeric' })
}
