import { useRef, type KeyboardEvent, type ReactNode } from 'react'

/** `texto` puede ser una muestra (p. ej. "Aa" en otro tamaño); entonces `nombre` da el nombre accesible. */
export interface OpcionSegmento<T extends string> { valor: T; texto: ReactNode; nombre?: string }

/**
 * Control segmentado accesible: un radiogroup con botones role="radio" y foco itinerante.
 * Las flechas (y Inicio / Fin) cambian la opción y el foco se queda en el control. La opción
 * elegida se distingue también por la marca ✓ y el borde, no solo por el color.
 * Enter envía el formulario que lo contiene, si su botón de envío está habilitado.
 */
export default function ControlSegmentado<T extends string>({ id, etiqueta, opciones, valor, onCambio }: {
  id: string
  etiqueta: string
  opciones: OpcionSegmento<T>[]
  valor: T
  onCambio: (valor: T) => void
}) {
  const botones = useRef<Array<HTMLButtonElement | null>>([])

  const alPulsar = (e: KeyboardEvent<HTMLButtonElement>, i: number) => {
    const ultimo = opciones.length - 1
    const destino = {
      ArrowRight: i === ultimo ? 0 : i + 1,
      ArrowDown: i === ultimo ? 0 : i + 1,
      ArrowLeft: i === 0 ? ultimo : i - 1,
      ArrowUp: i === 0 ? ultimo : i - 1,
      Home: 0,
      End: ultimo,
    }[e.key]

    if (destino !== undefined) {
      e.preventDefault()
      onCambio(opciones[destino].valor)
      botones.current[destino]?.focus()
    } else if (e.key === 'Enter') {
      e.preventDefault()
      const form = e.currentTarget.form
      const enviar = form?.querySelector<HTMLButtonElement>('button[type="submit"]')
      if (form && enviar && !enviar.disabled) form.requestSubmit(enviar)
    }
  }

  return (
    <div className="campo-segmentado">
      <span id={`${id}-etiqueta`} className="etiqueta-campo">{etiqueta}</span>
      <div role="radiogroup" aria-labelledby={`${id}-etiqueta`} className="segmentado">
        {opciones.map((o, i) => {
          const elegida = o.valor === valor
          // Sin opción elegida (p. ej. una preferencia sin guardar), la primera recibe el Tab.
          const conFoco = elegida || (i === 0 && !opciones.some((x) => x.valor === valor))
          return (
            <button
              key={o.valor}
              ref={(b) => { botones.current[i] = b }}
              type="button"
              role="radio"
              aria-checked={elegida}
              tabIndex={conFoco ? 0 : -1}
              aria-label={o.nombre}
              className={elegida ? 'segmento elegido' : 'segmento'}
              onClick={() => onCambio(o.valor)}
              onKeyDown={(e) => alPulsar(e, i)}
            >
              {elegida && (
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" aria-hidden>
                  <path d="M5 12.5l4.5 4.5L19 7.5" />
                </svg>
              )}
              {o.texto}
            </button>
          )
        })}
      </div>
    </div>
  )
}
