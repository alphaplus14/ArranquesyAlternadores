import Dialogo from './Dialogo'

/**
 * Pregunta antes de cerrar una modal con cambios sin guardar. Va encima de la modal (Esc, la X o el
 * fondo vuelven a editar) y es de confirmación: ella misma no pregunta al cerrarse.
 */
export default function DialogoSalir({ onSeguir, onSalir }: { onSeguir: () => void; onSalir: () => void }) {
  return (
    <Dialogo
      titulo="¿Salir sin guardar?"
      descripcion="Hay cambios sin guardar. Si sale ahora, se pierden."
      alertdialog
      variante="salir"
      confirmarSalida={false}
      onCerrar={onSeguir}
      pie={(
        <>
          <button type="button" className="secundario" autoFocus onClick={onSeguir}>Seguir editando</button>
          <button type="button" className="peligro" onClick={onSalir}>Salir sin guardar</button>
        </>
      )}
    />
  )
}
