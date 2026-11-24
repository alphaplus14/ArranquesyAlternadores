import { useAvisoTexto } from './Avisos'
import Dialogo from './Dialogo'

/** Pregunta antes de guardar un cambio. Escape o Cancelar vuelven atrás sin aplicar nada. */
export default function Confirmar({
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
  useAvisoTexto(error ?? null)

  return (
    <Dialogo
      titulo={titulo}
      descripcion={texto}
      alertdialog
      variante="confirmar"
      onCerrar={onCancelar}
      pendiente={pendiente}
      onEnviar={onConfirmar}
      pie={(
        <>
          <button type="button" className="secundario" autoFocus disabled={pendiente} onClick={onCancelar}>Cancelar</button>
          <button type="submit" className={peligro ? 'peligro' : 'primario'} disabled={pendiente}>
            {pendiente ? 'Guardando…' : accion}
          </button>
        </>
      )}
    />
  )
}
