export interface AccionEleccion {
  id: string
  texto: string
  onElegir: () => void
  /** Código de barras: va en mono. */
  mono?: boolean
}

export interface FilaEleccion {
  id: string | number
  titulo: string
  meta?: string
  detalle?: string
  agotada?: boolean
  /** La fila entera elige. No se combina con varias acciones. */
  onElegir?: () => void
  acciones?: AccionEleccion[]
}

/**
 * Elegir uno: vacío, pendiente, fila y el alta al final.
 * Cliente y unidad son dos adapters de esta interface. La tabla de administración no entra aquí.
 */
export default function ListaEleccion({
  filas,
  pendiente = false,
  vacio,
  elegida,
  alFinal,
}: {
  filas: FilaEleccion[]
  pendiente?: boolean
  vacio?: string
  elegida?: { titulo: string; meta?: string; onCambiar: () => void }
  alFinal?: { texto: string; onClick: () => void }
}) {
  if (elegida) {
    return (
      <div className="eleccion-elegida">
        <div>
          <strong>{elegida.titulo}</strong>
          {elegida.meta && <span className="eleccion-meta">{elegida.meta}</span>}
        </div>
        <button type="button" className="enlace" onClick={elegida.onCambiar}>Cambiar</button>
      </div>
    )
  }

  return (
    <div className="eleccion">
      {pendiente && <p className="eleccion-estado">Buscando…</p>}
      {!pendiente && filas.length === 0 && vacio && <p className="eleccion-estado">{vacio}</p>}
      {filas.map((fila) => {
        const cuerpo = (
          <>
            <strong>{fila.titulo}</strong>
            {fila.meta && <span className={fila.agotada ? 'eleccion-meta agotado' : 'eleccion-meta'}>{fila.meta}</span>}
            {fila.detalle && <small>{fila.detalle}</small>}
          </>
        )
        if (fila.onElegir && !fila.acciones?.length) {
          return (
            <button key={fila.id} type="button" className="eleccion-fila" onClick={fila.onElegir}>
              {cuerpo}
            </button>
          )
        }
        return (
          <div key={fila.id} className="eleccion-fila">
            {cuerpo}
            {fila.acciones && fila.acciones.length > 0 && (
              <div className="eleccion-acciones">
                {fila.acciones.map((a) => (
                  <button key={a.id} type="button" className={a.mono ? 'secundario mono' : 'secundario'} onClick={a.onElegir}>{a.texto}</button>
                ))}
              </div>
            )}
          </div>
        )
      })}
      {alFinal && (
        <button type="button" className="eleccion-alta" onClick={alFinal.onClick}>{alFinal.texto}</button>
      )}
    </div>
  )
}
