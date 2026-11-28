import { hoyEnBogota } from './periodos'

/** S-89: atajos de período de las pantallas contables. */
export type AtajoPeriodo = 'hoy' | 'este_mes' | 'mes_anterior' | 'ultimos_90' | 'este_anio' | 'personalizado'

export const ATAJOS: { valor: Exclude<AtajoPeriodo, 'personalizado'>; texto: string }[] = [
  { valor: 'hoy', texto: 'Hoy' },
  { valor: 'este_mes', texto: 'Este mes' },
  { valor: 'mes_anterior', texto: 'Mes anterior' },
  { valor: 'ultimos_90', texto: 'Últimos 90 días' },
  { valor: 'este_anio', texto: 'Este año' },
]

const dos = (n: number) => String(n).padStart(2, '0')

/** Rango de un atajo a partir de hoy (Bogotá). Fechas 'YYYY-MM-DD'. */
export function rangoAtajo(atajo: Exclude<AtajoPeriodo, 'personalizado'>, hoy = hoyEnBogota()): { desde: string; hasta: string } {
  const [a, m, d] = hoy.split('-').map(Number)
  switch (atajo) {
    case 'hoy': return { desde: hoy, hasta: hoy }
    case 'este_mes': return { desde: `${a}-${dos(m)}-01`, hasta: hoy }
    case 'mes_anterior': {
      const pa = m === 1 ? a - 1 : a
      const pm = m === 1 ? 12 : m - 1
      return { desde: `${pa}-${dos(pm)}-01`, hasta: `${pa}-${dos(pm)}-${dos(new Date(pa, pm, 0).getDate())}` }
    }
    case 'ultimos_90': {
      const f = new Date(a, m - 1, d - 90)
      return { desde: `${f.getFullYear()}-${dos(f.getMonth() + 1)}-${dos(f.getDate())}`, hasta: hoy }
    }
    case 'este_anio': return { desde: `${a}-01-01`, hasta: hoy }
  }
}

/** El atajo que corresponde a un rango, o 'personalizado'. */
export function atajoDe(desde: string, hasta: string): AtajoPeriodo {
  return ATAJOS.find((x) => { const r = rangoAtajo(x.valor); return r.desde === desde && r.hasta === hasta })?.valor ?? 'personalizado'
}
