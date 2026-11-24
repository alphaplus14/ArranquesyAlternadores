/**
 * Períodos predefinidos para filtrar por fecha (RF-21 historial de ajustes; reutilizable en RF-18).
 * Todo es aritmética sobre fechas 'YYYY-MM-DD', sin la zona del navegador: "hoy" se calcula en la
 * hora de Bogotá y el backend interpreta desde/hasta como días de Bogotá (hasta inclusivo).
 */

export type Periodo = 'este_mes' | 'mes_anterior' | 'ultimos_3_meses' | 'ultimo_anio' | 'todo' | 'personalizado'

export const PERIODO_POR_DEFECTO: Periodo = 'este_mes'

export const PERIODOS: { valor: Periodo; texto: string }[] = [
  { valor: 'este_mes', texto: 'Este mes' },
  { valor: 'mes_anterior', texto: 'Mes anterior' },
  { valor: 'ultimos_3_meses', texto: 'Últimos 3 meses' },
  { valor: 'ultimo_anio', texto: 'Último año' },
  { valor: 'todo', texto: 'Todo el historial' },
  { valor: 'personalizado', texto: 'Personalizado' },
]

export const ZONA_LOCAL = 'America/Bogota'

export interface Rango {
  desde: string | null
  hasta: string | null
}

const FORMATO = /^\d{4}-\d{2}-\d{2}$/

export function esPeriodo(valor: unknown): valor is Periodo {
  return PERIODOS.some((p) => p.valor === valor)
}

/** Fecha válida 'YYYY-MM-DD' (formato y día existente). */
export function esFecha(valor: unknown): valor is string {
  if (typeof valor !== 'string' || !FORMATO.test(valor)) return false
  const [a, m, d] = valor.split('-').map(Number)
  return m >= 1 && m <= 12 && d >= 1 && d <= diasDelMes(a, m)
}

/** "Hoy" en Bogotá como 'YYYY-MM-DD'. De noche no salta al día siguiente como pasaría con UTC. */
export function hoyEnBogota(ahora: Date = new Date()): string {
  // en-CA da el formato aaaa-mm-dd.
  return new Intl.DateTimeFormat('en-CA', { timeZone: ZONA_LOCAL, year: 'numeric', month: '2-digit', day: '2-digit' }).format(ahora)
}

/**
 * Rango de un período a partir de "hoy" ('YYYY-MM-DD'). Función pura.
 * - este_mes: día 1 del mes actual → hoy.
 * - mes_anterior: día 1 → último día del mes anterior.
 * - ultimos_3_meses / ultimo_anio: el mismo día de hace 3 / 12 meses → hoy. Si ese día no existe
 *   (31 de mayo → 31 de febrero), se usa el último día de ese mes.
 * - todo: sin fechas.
 * - personalizado: lo digitado, cada extremo opcional; lo que no sea una fecha válida cuenta como vacío.
 */
export function rangoDe(periodo: Periodo, hoy: string, personalizado: Partial<Rango> = {}): Rango {
  const [a, m, d] = hoy.split('-').map(Number)

  switch (periodo) {
    case 'este_mes':
      return { desde: fecha(a, m, 1), hasta: hoy }
    case 'mes_anterior': {
      const [pa, pm] = sumarMeses(a, m, -1)
      return { desde: fecha(pa, pm, 1), hasta: fecha(pa, pm, diasDelMes(pa, pm)) }
    }
    case 'ultimos_3_meses':
      return { desde: mismoDiaHace(a, m, d, 3), hasta: hoy }
    case 'ultimo_anio':
      return { desde: mismoDiaHace(a, m, d, 12), hasta: hoy }
    case 'todo':
      return { desde: null, hasta: null }
    case 'personalizado':
      return {
        desde: esFecha(personalizado.desde) ? personalizado.desde : null,
        hasta: esFecha(personalizado.hasta) ? personalizado.hasta : null,
      }
  }
}

/** Mensaje si el rango es imposible (desde posterior a hasta); null si sirve. */
export function errorRango({ desde, hasta }: Rango): string | null {
  // Las fechas 'YYYY-MM-DD' se comparan bien como texto.
  return desde && hasta && desde > hasta ? '"Desde" no puede ser posterior a "Hasta".' : null
}

function mismoDiaHace(a: number, m: number, d: number, meses: number): string {
  const [na, nm] = sumarMeses(a, m, -meses)
  return fecha(na, nm, Math.min(d, diasDelMes(na, nm)))
}

function sumarMeses(a: number, m: number, delta: number): [number, number] {
  const indice = a * 12 + (m - 1) + delta
  return [Math.floor(indice / 12), (indice % 12) + 1]
}

function diasDelMes(a: number, m: number): number {
  const bisiesto = (a % 4 === 0 && a % 100 !== 0) || a % 400 === 0
  return [31, bisiesto ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31][m - 1]
}

function fecha(a: number, m: number, d: number): string {
  return `${a}-${String(m).padStart(2, '0')}-${String(d).padStart(2, '0')}`
}
