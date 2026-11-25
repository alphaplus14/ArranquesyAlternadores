import { useId, type MouseEvent } from 'react'
import { useAncho } from '../../hooks/useAncho'
import { cifraCorta, topeEje, useIndiceActivo } from './comun'
import './graficos.css'

export interface SerieArea {
  clave: string
  nombre: string
  /** Variable CSS del color, p. ej. 'var(--grafico-contado)'. */
  color: string
  /** Rayado diagonal además del color (la serie no depende solo del color). */
  rayas?: boolean
}

export interface PuntoArea {
  /** Texto corto del eje (p. ej. "10/11"). */
  etiqueta: string
  /** Texto largo para el tooltip y lectores de pantalla (p. ej. "lunes 10/11/2026"). */
  detalle: string
  valores: Record<string, number>
}

const ALTO = 240
const M = { arriba: 12, derecha: 12, abajo: 28, izquierda: 64 }

/**
 * RF-23: áreas apiladas por día (contado abajo, crédito encima). SVG propio que se ajusta al
 * ancho del contenedor. Teclado: foco en el gráfico y ← → Inicio Fin recorren los días; el valor
 * del día activo se anuncia en una región viva.
 */
export default function GraficoAreas({ datos, series, formato, resumen, alto = ALTO }: {
  datos: PuntoArea[]
  series: SerieArea[]
  formato: (n: number) => string
  /** Resumen de la serie para aria-label (p. ej. "Ventas de 30 días: total $…"). */
  resumen: string
  /** Alto del lienzo en px. El del panel es más bajo para no dejar un bloque vacío. */
  alto?: number
}) {
  const { ref, ancho } = useAncho<HTMLDivElement>()
  const uid = useId().replace(/:/g, '')
  const { indice, setIndice, onKeyDown, onFocus } = useIndiceActivo(datos.length)

  const anchoUtil = Math.max(0, ancho - M.izquierda - M.derecha)
  const altoUtil = alto - M.arriba - M.abajo
  const totales = datos.map((d) => series.reduce((s, x) => s + (d.valores[x.clave] ?? 0), 0))
  const tope = topeEje(Math.max(0, ...totales))
  const x = (i: number) => M.izquierda + (datos.length <= 1 ? anchoUtil / 2 : (i * anchoUtil) / (datos.length - 1))
  const y = (v: number) => M.arriba + altoUtil - (v / tope) * altoUtil

  // Acumulados por serie, en el orden dado (la primera queda abajo).
  const acumulados: number[][] = []
  series.forEach((s, k) => {
    acumulados.push(datos.map((d, i) => (k === 0 ? 0 : acumulados[k - 1][i]) + (d.valores[s.clave] ?? 0)))
  })
  const area = (k: number) => {
    const arriba = acumulados[k].map((v, i) => `${x(i)},${y(v)}`)
    const abajo = datos.map((_, i) => `${x(i)},${y(k === 0 ? 0 : acumulados[k - 1][i])}`).reverse()
    return `M${arriba.join(' L')} L${abajo.join(' L')} Z`
  }
  const linea = (k: number) => `M${acumulados[k].map((v, i) => `${x(i)},${y(v)}`).join(' L')}`

  const rayas = [0, 0.25, 0.5, 0.75, 1].map((f) => f * tope)
  const cadaCuanto = Math.max(1, Math.ceil(datos.length / Math.max(2, Math.floor(anchoUtil / 70))))

  const alMover = (e: MouseEvent<SVGSVGElement>) => {
    if (datos.length === 0 || anchoUtil <= 0) return
    const caja = e.currentTarget.getBoundingClientRect()
    const px = e.clientX - caja.left - M.izquierda
    setIndice(Math.max(0, Math.min(datos.length - 1, Math.round((px / anchoUtil) * (datos.length - 1)))))
  }

  const activo = indice !== null ? datos[indice] : null
  const textoActivo = activo
    ? `${activo.detalle}: ${series.map((s) => `${s.nombre} ${formato(activo.valores[s.clave] ?? 0)}`).join(', ')}; total ${formato(totales[indice!])}`
    : ''

  return (
    <figure className="grafico">
      <div className="grafico-lienzo" ref={ref}>
        {ancho > 0 && (
          <svg
            width={ancho}
            height={alto}
            role="img"
            aria-label={`${resumen}. Use las flechas para recorrer los días.`}
            tabIndex={0}
            onKeyDown={onKeyDown}
            onFocus={onFocus}
            onMouseMove={alMover}
            onMouseLeave={() => { if (document.activeElement !== ref.current?.querySelector('svg')) setIndice(null) }}
            className="grafico-svg"
          >
            <defs>
              {series.filter((s) => s.rayas).map((s) => (
                <pattern key={s.clave} id={`${uid}-${s.clave}`} width="7" height="7" patternUnits="userSpaceOnUse" patternTransform="rotate(45)">
                  <rect width="7" height="7" style={{ fill: s.color }} opacity="0.35" />
                  <line x1="0" y1="0" x2="0" y2="7" style={{ stroke: s.color }} strokeWidth="3" />
                </pattern>
              ))}
            </defs>
            {rayas.map((v) => (
              <g key={v}>
                <line x1={M.izquierda} x2={ancho - M.derecha} y1={y(v)} y2={y(v)} className="grafico-rejilla" />
                <text x={M.izquierda - 8} y={y(v)} className="grafico-eje" textAnchor="end" dominantBaseline="middle">{cifraCorta(v)}</text>
              </g>
            ))}
            {series.map((s, k) => (
              <g key={s.clave}>
                <path d={area(k)} style={{ fill: s.rayas ? `url(#${uid}-${s.clave})` : s.color }} opacity={s.rayas ? 1 : 0.55} />
                <path d={linea(k)} style={{ stroke: s.color }} className="grafico-linea" strokeDasharray={s.rayas ? '6 4' : undefined} />
              </g>
            ))}
            {datos.map((d, i) => (i % cadaCuanto === 0 || i === datos.length - 1) && (
              <text key={d.etiqueta + i} x={x(i)} y={alto - 8} className="grafico-eje" textAnchor="middle">{d.etiqueta}</text>
            ))}
            {indice !== null && (
              <g>
                <line x1={x(indice)} x2={x(indice)} y1={M.arriba} y2={M.arriba + altoUtil} className="grafico-guia" />
                {series.map((s, k) => (
                  <circle key={s.clave} cx={x(indice)} cy={y(acumulados[k][indice])} r="4.5" style={{ stroke: s.color }} className="grafico-punto" />
                ))}
              </g>
            )}
          </svg>
        )}
        {activo && ancho > 0 && (
          <div
            className="grafico-tooltip"
            aria-hidden
            style={{ left: Math.min(Math.max(x(indice!) - 90, 0), Math.max(0, ancho - 190)), top: 4 }}
          >
            <strong>{activo.detalle}</strong>
            {series.map((s) => (
              <span key={s.clave}><i className={`grafico-muestra${s.rayas ? ' rayas' : ''}`} style={{ background: s.color }} /> {s.nombre}: {formato(activo.valores[s.clave] ?? 0)}</span>
            ))}
            <span>Total: <strong>{formato(totales[indice!])}</strong></span>
          </div>
        )}
      </div>
      <figcaption className="grafico-leyenda">
        {series.map((s) => (
          <span key={s.clave}><i className={`grafico-muestra${s.rayas ? ' rayas' : ''}`} style={{ background: s.color }} aria-hidden /> {s.nombre}{s.rayas ? ' (rayado, línea punteada)' : ' (sólido)'}</span>
        ))}
      </figcaption>
      <p className="solo-lector" aria-live="polite">{textoActivo}</p>
    </figure>
  )
}
