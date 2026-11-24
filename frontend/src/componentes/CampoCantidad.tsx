import { useEffect, useState } from 'react'

/**
 * S-38: cantidad con botones − / + y entrada numérica (controles de 44 px). El valor siempre queda
 * entre `min` y `max`; lo escrito fuera de rango se corrige al salir del campo.
 */
export default function CampoCantidad({ id, etiqueta, valor, min = 1, max, onCambio, disabled = false, ayuda }: {
  id: string
  etiqueta: string
  valor: number
  min?: number
  max: number
  onCambio: (valor: number) => void
  disabled?: boolean
  ayuda?: string
}) {
  // Lo que se escribe se guarda como texto mientras se edita (permite borrar y escribir otro número).
  const [texto, setTexto] = useState(String(valor))
  useEffect(() => { setTexto(String(valor)) }, [valor])

  const acotar = (n: number) => Math.min(max, Math.max(min, Number.isFinite(n) ? Math.trunc(n) : min))
  const fijar = (n: number) => { const v = acotar(n); setTexto(String(v)); onCambio(v) }
  const idAyuda = ayuda ? `${id}-ayuda` : undefined

  return (
    <div className="campo-cantidad">
      <label htmlFor={id} className="etiqueta-campo">{etiqueta}</label>
      <div className="stepper" role="group" aria-label={etiqueta}>
        <button type="button" className="secundario" aria-label="Una menos" disabled={disabled || valor <= min} onClick={() => fijar(valor - 1)}>−</button>
        <input
          id={id}
          type="number"
          inputMode="numeric"
          min={min}
          max={max}
          step={1}
          value={texto}
          disabled={disabled}
          aria-describedby={idAyuda}
          onChange={(e) => {
            setTexto(e.target.value)
            const n = Number(e.target.value)
            if (e.target.value !== '' && Number.isInteger(n) && n >= min && n <= max) onCambio(n)
          }}
          onBlur={() => fijar(Number(texto))}
        />
        <button type="button" className="secundario" aria-label="Una más" disabled={disabled || valor >= max} onClick={() => fijar(valor + 1)}>+</button>
      </div>
      {ayuda && <small id={idAyuda} className="ayuda">{ayuda}</small>}
    </div>
  )
}
