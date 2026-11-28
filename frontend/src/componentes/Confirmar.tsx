import Dialogo, { DialogoSeccion } from './Dialogo'
import BotonCancelar from './BotonCancelar'

/**
 * Pregunta antes de guardar un cambio. Escape o Cancelar vuelven atrás sin aplicar nada.
 * S-94: si la acción falla, el error se ve dentro del diálogo, que sigue abierto para reintentar o cancelar.
 */
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
  return (
    <Dialogo
      titulo={titulo}
      descripcion={texto}
      alertdialog
      variante="confirmar"
      confirmarSalida={false}
      onCerrar={onCancelar}
      pendiente={pendiente}
      onEnviar={onConfirmar}
      pie={(
        <>
          <BotonCancelar autoFocus={!error} />
          <button type="submit" className={peligro ? 'peligro' : 'primario'} disabled={pendiente}>
            {pendiente ? 'Guardando…' : accion}
          </button>
        </>
      )}
    >
      {error && (
        <DialogoSeccion>
          <p className="aviso error" role="alert">{error}</p>
        </DialogoSeccion>
      )}
    </Dialogo>
  )
}
