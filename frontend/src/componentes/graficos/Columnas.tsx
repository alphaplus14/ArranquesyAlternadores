import { useAncho } from '../../hooks/useAncho'
import { cifraCorta, topeEje, useIndiceActivo } from './comun'
import './graficos.css'

export interface Columna {
  etiqueta: string
  valor: number
  /** Valor escrito sobre la columna (p. ej. "12 u."). */
  texto: string
  /** Texto del tooltip y de la región viva (p. ej. "31 a 90 días: 12 unidades, $ 1.200.000 a costo"). */
  detalle: string
}

const ALTO = 200
const M = { arriba: 22, derecha: 8, abajo: 30, izquierda: 44 }

/**
 * RF-23: columnas (antigüedad del stock). SVG propio al ancho del contenedor; el valor va escrito
 * encima de cada columna. Teclado: foco y ← → recorren las columnas; el detalle se anuncia.
 */
export default function Columnas({ columnas, resumen }: { columnas: Columna[]; resumen: string }) {
  const { ref, ancho } = useAncho<HTMLDivElement>()
  const { indice, setIndice, onKeyDown, onFocus } = useIndiceActivo(columnas.length)

  const anchoUtil = Math.max(0, ancho - M.izquierda - M.derecha)
  const altoUtil = ALTO - M.arriba - M.abajo
  const tope = topeEje(Math.max(0, ...columnas.map((c) => c.valor)))
  const paso = columnas.length > 0 ? anchoUtil / columnas.length : 0
  const anchoColumna = Math.max(4, Math.min(72, paso * 0.6))
  const y = (v: number) => M.arriba + altoUtil - (v / tope) * altoUtil
  const activo = indice !== null ? columnas[indice] : null

  return (
    <figure className="grafico">
      <div className="grafico-lienzo" ref={ref}>
        {ancho > 0 && (
          <svg
            width={ancho}
            height={ALTO}
            role="img"
            aria-label={`${resumen}. Use las flechas para recorrer las columnas.`}
            tabIndex={0}
            onKeyDown={onKeyDown}
            onFocus={onFocus}
            onMouseLeave={() => setIndice(null)}
            className="grafico-svg"
          >
            {[0, 0.5, 1].map((f) => (
              <g key={f}>
                <line x1={M.izquierda} x2={ancho - M.derecha} y1={y(f * tope)} y2={y(f * tope)} className="grafico-rejilla" />
                <text x={M.izquierda - 6} y={y(f * tope)} className="grafico-eje" textAnchor="end" dominantBaseline="middle">{cifraCorta(f * tope)}</text>
              </g>
            ))}
            {columnas.map((c, i) => {
              const cx = M.izquierda + paso * i + paso / 2
              const alto = Math.max(c.valor > 0 ? 2 : 0, M.arriba + altoUtil - y(c.valor))
              return (
                <g key={c.etiqueta} onMouseEnter={() => setIndice(i)}>
                  <rect x={cx - paso / 2} y={M.arriba} width={paso} height={altoUtil} fill="transparent" />
                  <rect
                    x={cx - anchoColumna / 2}
                    y={M.arriba + altoUtil - alto}
                    width={anchoColumna}
                    height={alto}
                    className={indice === i ? 'columna activa' : 'columna'}
                  />
                  <text x={cx} y={M.arriba + altoUtil - alto - 6} className="grafico-valor" textAnchor="middle">{c.texto}</text>
                  <text x={cx} y={ALTO - 10} className="grafico-eje" textAnchor="middle">{c.etiqueta}</text>
                </g>
              )
            })}
          </svg>
        )}
        {activo && ancho > 0 && (
          <div
            className="grafico-tooltip"
            aria-hidden
            style={{ left: Math.min(Math.max(M.izquierda + paso * indice! + paso / 2 - 90, 0), Math.max(0, ancho - 190)), top: 0 }}
          >
            {activo.detalle}
          </div>
        )}
      </div>
      <p className="solo-lector" aria-live="polite">{activo?.detalle ?? ''}</p>
    </figure>
  )
}
