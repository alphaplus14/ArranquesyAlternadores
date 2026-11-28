import { useEffect, useRef, type ReactNode } from 'react'
import ControlSegmentado from '../ControlSegmentado'
import { AYUDA_MIXTO, NOMBRE_CORTO, type CondicionDescuento, type CondicionElegible } from './condicionDescuento'

const OPCIONES: { valor: CondicionElegible; texto: string }[] = [
  { valor: 'ninguno', texto: NOMBRE_CORTO.ninguno },
  { valor: 'documento', texto: NOMBRE_CORTO.documento },
  { valor: 'producto', texto: NOMBRE_CORTO.producto },
]

/**
 * S-95: tarjeta "Descuento" del ingreso, compartida por la corrección (RF-28) y "Editar condiciones"
 * del borrador. Zona A: título y chip con la condición. Zona B: el control (Sin descuento | Del
 * documento | Por producto); con "Mixto (anterior)" ninguna opción queda elegida y una línea lo
 * explica. Zona C: el contenido de la condición (campo $ / %, ayuda o resumen de solo lectura).
 * Un cambio de condición se confirma en línea («Cambiar» / «Mantener»; Escape = Mantener) sin
 * cambiar la zona C hasta aplicarlo. Solo presentación: quien la usa decide qué se aplica.
 */
export default function TarjetaDescuento({
  id,
  condicion,
  soloLectura = false,
  onElegir,
  cambio = null,
  onCambiar,
  onMantener,
  campoDocumento,
  resumenMixto,
  contenido,
}: {
  id: string
  condicion: CondicionDescuento
  /** Sin el control de condición (borrador: la condición sale de lo que ya tiene). */
  soloLectura?: boolean
  onElegir?: (nueva: CondicionElegible) => void
  /** Cambio pedido y sin confirmar: la opción pulsada y el texto de lo que se quitará. */
  cambio?: { nueva: CondicionElegible; texto: string } | null
  onCambiar?: () => void
  onMantener?: () => void
  /** Zona C con "Del documento": el campo del valor ($ / %). */
  campoDocumento?: ReactNode
  /** Zona C con "Mixto (anterior)": resumen en texto, p. ej. «Documento: $67.500 · Ítems: 10 % en 5 ítems». */
  resumenMixto?: string
  /** Reemplaza la zona C (el borrador muestra siempre su campo). */
  contenido?: ReactNode
}) {
  const cambiar = useRef<HTMLButtonElement>(null)
  // Al abrir la confirmación, el foco va a «Cambiar».
  useEffect(() => { if (cambio) cambiar.current?.focus() }, [cambio])

  const zonaC = contenido ?? (
    condicion === 'documento' ? campoDocumento
      : condicion === 'producto' ? <p className="ayuda">Edite el % de cada ítem en la tabla.</p>
        : condicion === 'ninguno' ? <p className="ayuda">Sin descuento; elija una condición para darlo.</p>
          : <p className="tarjeta-descuento-resumen">{resumenMixto}</p>
  )

  return (
    <section className={soloLectura ? 'tarjeta-descuento lectura' : 'tarjeta-descuento'} aria-labelledby={`${id}-titulo`}>
      <div className="tarjeta-descuento-cabeza">
        <h3 id={`${id}-titulo`}>Descuento del documento</h3>
        <span className={condicion === 'mixto' ? 'chip atencion' : 'chip'}>{NOMBRE_CORTO[condicion]}</span>
      </div>

      {!soloLectura && (
        <div className="tarjeta-descuento-control">
          <ControlSegmentado<CondicionElegible>
            id={`${id}-condicion`}
            etiqueta="Condición del descuento"
            clase="control-condicion"
            valor={condicion === 'mixto' ? ('' as CondicionElegible) : condicion}
            pendiente={cambio?.nueva}
            describedBy={condicion === 'mixto' ? `${id}-mixto` : undefined}
            onCambio={(v) => onElegir?.(v)}
            opciones={OPCIONES}
          />
          {condicion === 'mixto' && <p id={`${id}-mixto`} className="ayuda">{AYUDA_MIXTO}</p>}
        </div>
      )}

      <div className="tarjeta-descuento-contenido">{zonaC}</div>

      {cambio && (
        <div
          className="confirmar-condicion"
          role="status"
          onKeyDown={(e) => { if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); onMantener?.() } }}
        >
          <span className="confirmar-condicion-texto">{cambio.texto}</span>
          <span className="confirmar-condicion-botones">
            <button ref={cambiar} type="button" className="primario" onClick={onCambiar}>Cambiar</button>
            <button type="button" className="secundario" onClick={onMantener}>Mantener</button>
          </span>
        </div>
      )}
    </section>
  )
}
