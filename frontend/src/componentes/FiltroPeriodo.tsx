import { PERIODOS, errorRango, rangoDe, type Periodo } from '../utilidades/periodos'

export interface ValorPeriodo {
  periodo: Periodo
  /** Solo se usan con "personalizado". */
  desde: string
  hasta: string
}

/**
 * Filtro de fechas por período (RF-21; reutilizable en RF-18). Un select con rangos
 * predefinidos; "Desde" y "Hasta" aparecen solo con "Personalizado". Devuelve labels sueltos para
 * quedar en la misma fila de filtros (y apilarse con ellos en pantallas angostas).
 * La pantalla que lo usa calcula el rango con rangoDe() y no consulta si errorRango() da un mensaje.
 */
export default function FiltroPeriodo({ id, valor, onCambio, periodos = PERIODOS }: {
  id: string
  valor: ValorPeriodo
  onCambio: (valor: ValorPeriodo) => void
  /** RF-23: el panel usa su propia lista (PERIODOS_PANEL); las demás pantallas, la de siempre. */
  periodos?: { valor: Periodo; texto: string }[]
}) {
  const personalizado = valor.periodo === 'personalizado'
  const error = personalizado ? errorRango(rangoDe('personalizado', '', valor)) : null
  const idError = `${id}-error`

  return (
    <>
      <label>Período
        <select value={valor.periodo} onChange={(e) => onCambio({ ...valor, periodo: e.target.value as Periodo })}>
          {periodos.map((p) => <option key={p.valor} value={p.valor}>{p.texto}</option>)}
        </select>
      </label>
      {personalizado && (
        <>
          <label>Desde
            <input
              type="date"
              value={valor.desde}
              aria-invalid={error ? true : undefined}
              aria-describedby={error ? idError : undefined}
              onChange={(e) => onCambio({ ...valor, desde: e.target.value })}
            />
          </label>
          <label>Hasta
            <input
              type="date"
              value={valor.hasta}
              aria-invalid={error ? true : undefined}
              aria-describedby={error ? idError : undefined}
              onChange={(e) => onCambio({ ...valor, hasta: e.target.value })}
            />
            {error && <span id={idError} className="ayuda error" role="alert">{error}</span>}
          </label>
        </>
      )}
    </>
  )
}
