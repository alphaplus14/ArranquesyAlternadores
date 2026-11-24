import { useEffect, useState } from 'react'

/**
 * S-38: cantidad con botones − / + y entrada numérica (controles de 44 px). El valor siempre queda
 * entre `min` y `max`; lo escrito fuera de rango se corrige al salir del campo.
 * S-42: `compacto` pone la etiqueta pequeña a la izquierda y la ayuda en `title`, para alinearse en
 * la misma línea y altura que el campo de escaneo.
 */
export default function CampoCantidad({ id, etiqueta, valor, min = 1, max, onCambio, disabled = false, ayuda, compacto = false }: {
  id: string
  etiqueta: string
  valor: number
  min?: number
  max: number
  onCambio: (valor: number) => void
  disabled?: boolean
  ayuda?: string
  compacto?: boolean
}) {
  // Lo que se escribe se guarda como texto mientras se edita (permite borrar y escribir otro número).
  const [texto, setTexto] = useState(String(valor))
  useEffect(() => { setTexto(String(valor)) }, [valor])

  const acotar = (n: number) => Math.min(max, Math.max(min, Number.isFinite(n) ? Math.trunc(n) : min))
  const fijar = (n: number) => { const v = acotar(n); setTexto(String(v)); onCambio(v) }
  const idAyuda = ayuda && !compacto ? `${id}-ayuda` : undefined

  return (
    <div className={compacto ? 'campo-cantidad compacto' : 'campo-cantidad'} title={compacto ? ayuda : undefined}>
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
          title={compacto ? ayuda : undefined}
          onChange={(e) => {
            setTexto(e.target.value)
            const n = Number(e.target.value)
            if (e.target.value !== '' && Number.isInteger(n) && n >= min && n <= max) onCambio(n)
          }}
          onBlur={() => fijar(Number(texto))}
        />
        <button type="button" className="secundario" aria-label="Una más" disabled={disabled || valor >= max} onClick={() => fijar(valor + 1)}>+</button>
      </div>
      {ayuda && !compacto && <small id={idAyuda} className="ayuda">{ayuda}</small>}
    </div>
  )
}
