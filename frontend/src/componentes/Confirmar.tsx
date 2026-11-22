import { useEffect } from 'react'

/** Pregunta antes de guardar un cambio. Escape o Cancelar vuelven atrás sin aplicar nada. */
export default function Confirmar({
  id = 'confirmar-titulo',
  titulo,
  texto,
  accion = 'Sí, estoy seguro',
  peligro = false,
  pendiente = false,
  error,
  onConfirmar,
  onCancelar,
}: {
  id?: string
  titulo: string
  texto: string
  accion?: string
  peligro?: boolean
  pendiente?: boolean
  error?: string | null
  onConfirmar: () => void
  onCancelar: () => void
}) {
  useEffect(() => {
    const alPulsar = (e: KeyboardEvent) => {
      if (e.key !== 'Escape' || pendiente) return
      e.preventDefault()
      e.stopPropagation()
      onCancelar()
    }
    document.addEventListener('keydown', alPulsar, true)
    return () => document.removeEventListener('keydown', alPulsar, true)
  }, [onCancelar, pendiente])

  return (
    <div className="modal confirmar">
      <div className="modal-caja" role="alertdialog" aria-modal="true" aria-labelledby={id}>
        <h2 id={id}>{titulo}</h2>
        <p>{texto}</p>
        {error && <div className="aviso error">{error}</div>}
        <div className="acciones">
          <button type="button" className="secundario" autoFocus disabled={pendiente} onClick={onCancelar}>Cancelar</button>
          <button type="button" className={peligro ? 'peligro' : 'primario'} disabled={pendiente} onClick={onConfirmar}>
            {pendiente ? 'Guardando…' : accion}
          </button>
        </div>
      </div>
    </div>
  )
}
