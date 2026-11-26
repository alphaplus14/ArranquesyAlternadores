import type { OpcionReferenciaVenta } from '../../api/tipos'
import DialogoApilado from '../DialogoApilado'
import ChipCalidad from '../ChipCalidad'
import BotonCancelar from '../BotonCancelar'

/**
 * S-31: el código escaneado es el del proveedor y está en varias referencias (varios proveedores).
 * No se adivina: quien vende elige cuál y la venta reenvía el mismo código con referencia_id.
 * Escape o Cancelar cierran sin vender; el foco vuelve al campo de escaneo (lo hace la pantalla).
 */
export default function DialogoElegirReferencia({ titulo = '¿Cuál referencia se vende?', codigo, referencias, onElegir, onCerrar }: {
  /** S-34: lo usa también Salidas ("¿Cuál referencia sale?"). */
  titulo?: string
  codigo: string
  referencias: OpcionReferenciaVenta[]
  onElegir: (referenciaId: number) => void
  onCerrar: () => void
}) {
  return (
    <DialogoApilado
      titulo={titulo}
      nota={<p className="nota-obligatorio nota-referencias">El código <strong className="mono">{codigo}</strong> está en {referencias.length} referencias. Elija una.</p>}
      onCerrar={onCerrar}
      onEnviar={() => {}}
      confirmarSalida={false}
      pie={<BotonCancelar className="secundario boton-44" />}
    >
      <ul className="opciones-referencia">
        {referencias.map((r, n) => (
          <li key={r.id}>
            <button
              type="button"
              className="opcion-referencia"
              autoFocus={n === 0}
              disabled={r.existencia <= 0}
              onClick={() => onElegir(r.id)}
            >
              <span className="opcion-referencia-linea">
                <strong className="mono">{r.codigo_referencia}</strong>
                <span>{r.proveedor ?? '—'}</span>
                <ChipCalidad calidad={r.calidad} />
              </span>
              <small>{r.descripcion}</small>
              <small className={r.existencia > 0 ? 'opcion-existencia' : 'opcion-existencia agotado'}>
                {r.existencia > 0 ? `Existencia ${r.existencia}` : 'Sin unidades disponibles'}
              </small>
            </button>
          </li>
        ))}
      </ul>
    </DialogoApilado>
  )
}
