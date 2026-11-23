import { RANGOS, type RangoAntiguedad } from '../../api/tipos'
import { pesos } from '../numeros'

/**
 * S-13: cartera por rango de antigüedad (al día, 1-30, 31-60, 61-90, +90 días vencidos). La barra
 * es proporcional y cada rango lleva su nombre y su monto en texto: no depende solo del color.
 */
export default function BarraAntiguedad({ valores, titulo = 'Antigüedad de la cartera' }: {
  valores: Record<RangoAntiguedad, string>
  titulo?: string
}) {
  const total = RANGOS.reduce((s, r) => s + Number(valores[r.clave]), 0)

  return (
    <figure className="antiguedad">
      <figcaption className="antiguedad-titulo">{titulo}</figcaption>
      <div className="antiguedad-barra" aria-hidden>
        {total > 0 && RANGOS.map((r) => {
          const v = Number(valores[r.clave])
          return v > 0 ? <span key={r.clave} className={`tramo ${r.clave}`} style={{ flexGrow: v }} /> : null
        })}
      </div>
      <ul className="antiguedad-rangos">
        {RANGOS.map((r) => (
          <li key={r.clave} className={Number(valores[r.clave]) > 0 ? 'con-valor' : ''}>
            <span className={`punto ${r.clave}`} aria-hidden />
            <span className="rango-nombre">{r.clave === 'al_dia' ? r.texto : `${r.texto} días`}</span>
            <strong>{pesos(valores[r.clave])}</strong>
          </li>
        ))}
      </ul>
    </figure>
  )
}
