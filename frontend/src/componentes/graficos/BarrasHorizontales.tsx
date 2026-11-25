import type { ReactNode } from 'react'
import './graficos.css'

export interface FilaBarra {
  id: number | string
  etiqueta: string
  /** Texto pequeño bajo la etiqueta (proveedor, calidad…). */
  detalle?: string
  valor: number
  /** Valor ya formateado (pesos, unidades, días…). */
  texto: string
  /** Marca textual además del color (p. ej. "Sobrestock"); la barra se pinta con --grafico-alerta. */
  marca?: string
  /** Contenido extra a la derecha del valor (p. ej. la clase ABC). */
  extra?: ReactNode
  onElegir?: () => void
}

/**
 * RF-23: barras horizontales (tops y rotación). Cada fila es texto + una barra SVG proporcional
 * con role="img" y su aria-label; el valor siempre va escrito, así nada depende solo del color ni
 * del largo de la barra. La etiqueta abre el detalle si hay onElegir (teclado: Tab + Enter).
 */
export default function BarrasHorizontales({ filas, resumen, vacio = 'Sin datos.' }: {
  filas: FilaBarra[]
  resumen: string
  vacio?: string
}) {
  const maximo = Math.max(0, ...filas.map((f) => f.valor))
  if (filas.length === 0) return <p className="vacio">{vacio}</p>

  return (
    <ol className="barras" aria-label={resumen}>
      {filas.map((f) => {
        const fraccion = maximo > 0 ? Math.max(0, f.valor) / maximo : 0
        return (
          <li key={f.id} className={f.marca ? 'barra-fila con-marca' : 'barra-fila'}>
            <span className="barra-etiqueta">
              {f.onElegir
                ? <button type="button" className="enlace" onClick={f.onElegir}>{f.etiqueta}</button>
                : <span>{f.etiqueta}</span>}
              {f.detalle && <small>{f.detalle}</small>}
            </span>
            <svg className="barra-svg" viewBox="0 0 100 10" preserveAspectRatio="none" role="img" aria-label={`${f.etiqueta}: ${f.texto}${f.marca ? `, ${f.marca}` : ''}`}>
              <rect x="0" y="0" width="100" height="10" className="barra-fondo" />
              <rect x="0" y="0" width={Math.max(fraccion * 100, f.valor > 0 ? 1 : 0)} height="10" className={f.marca ? 'barra-valor alerta' : 'barra-valor'} />
            </svg>
            <span className="barra-texto">
              {f.texto}
              {f.marca && <span className="chip marca-grafico">{f.marca}</span>}
              {f.extra}
            </span>
          </li>
        )
      })}
    </ol>
  )
}
