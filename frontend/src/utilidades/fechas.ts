/**
 * S-38: único lugar donde la pantalla formatea fechas. Siempre en hora de Colombia
 * (America/Bogota, locale es-CO), sin importar la zona del navegador o del equipo.
 *
 * - Un INSTANTE (created_at, cerrada_at…) llega del backend en ISO 8601 con zona ("…Z" o
 *   "-05:00"). Por compatibilidad, "AAAA-MM-DD HH:MM:SS" sin zona se toma como UTC (así lo guarda
 *   la base). Se convierte a la hora de Bogotá.
 * - Una FECHA SOLA ("AAAA-MM-DD": fecha de un abono, vencimiento, fecha del documento) es un día
 *   del calendario: no se convierte de zona, solo se reescribe como dd/mm/aaaa.
 */

export const ZONA_COLOMBIA = 'America/Bogota'
const LOCALE = 'es-CO'
const SOLO_FECHA = /^\d{4}-\d{2}-\d{2}$/
const SIN_ZONA = /^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2}(\.\d+)?)?$/

/** Un instante del backend como Date (ISO con zona; sin zona = UTC). */
export function aInstante(valor: string | Date): Date {
  if (valor instanceof Date) return valor
  const texto = valor.trim()
  return new Date(SIN_ZONA.test(texto) ? `${texto.replace(' ', 'T')}Z` : texto)
}

const formatoFechaHora = new Intl.DateTimeFormat(LOCALE, {
  timeZone: ZONA_COLOMBIA, day: '2-digit', month: '2-digit', year: 'numeric', hour: 'numeric', minute: '2-digit',
})
const formatoFecha = new Intl.DateTimeFormat(LOCALE, { timeZone: ZONA_COLOMBIA, day: '2-digit', month: '2-digit', year: 'numeric' })
const formatoHora = new Intl.DateTimeFormat(LOCALE, { timeZone: ZONA_COLOMBIA, hour: 'numeric', minute: '2-digit' })

/** "23/11/2026 9:33 p. m." (hora de Colombia). Vacío o inválido = "—". */
export function formatearFechaHora(valor: string | Date | null | undefined): string {
  if (!valor) return '—'
  const fecha = aInstante(valor)
  if (Number.isNaN(fecha.getTime())) return '—'
  // es-CO separa con ", " la fecha de la hora; se deja un espacio.
  return formatoFechaHora.format(fecha).replace(', ', ' ')
}

/**
 * "23/11/2026". Con "AAAA-MM-DD" reescribe el día tal cual (sin zona); con un instante, el día en
 * Colombia.
 */
export function formatearFecha(valor: string | Date | null | undefined): string {
  if (!valor) return '—'
  if (typeof valor === 'string' && SOLO_FECHA.test(valor.trim())) {
    const [a, m, d] = valor.trim().split('-')
    return `${d}/${m}/${a}`
  }
  const fecha = aInstante(valor)
  return Number.isNaN(fecha.getTime()) ? '—' : formatoFecha.format(fecha)
}

/** "9:33 p. m." (hora de Colombia). */
export function formatearHora(valor: string | Date | null | undefined): string {
  if (!valor) return '—'
  const fecha = aInstante(valor)
  return Number.isNaN(fecha.getTime()) ? '—' : formatoHora.format(fecha)
}

const formatoDiaIso = new Intl.DateTimeFormat('en-CA', { timeZone: ZONA_COLOMBIA, year: 'numeric', month: '2-digit', day: '2-digit' })

/** S-40: el día (AAAA-MM-DD) de un instante en Colombia. Sirve para comparar con hoyEnBogota(). */
export function diaEnColombia(valor: string | Date): string {
  return formatoDiaIso.format(aInstante(valor))
}
