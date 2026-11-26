import { createContext, useCallback, useContext, useEffect, useLayoutEffect, useRef, useState, type RefObject } from 'react'

/**
 * Un solo camino para cerrar una modal (Dialogo y DialogoApilado): la X, Esc, el clic en el fondo y
 * los botones "Cancelar" llaman a `solicitarCierre`, que pregunta antes de perder cambios. Los
 * cierres después de guardar con éxito usan el `onCerrar` del padre o `cerrarSinPreguntar`.
 */
export interface CierreDialogo {
  solicitarCierre: () => void
  cerrarSinPreguntar: () => void
  /** Hay un envío en curso: nada cierra la modal. */
  pendiente: boolean
}

export const ContextoCierreDialogo = createContext<CierreDialogo | null>(null)

/** Para los hijos de una modal (por ejemplo, el botón "Cancelar" del pie). */
export function useCerrarDialogo(): CierreDialogo {
  const contexto = useContext(ContextoCierreDialogo)
  if (!contexto) throw new Error('useCerrarDialogo se usa dentro de un Dialogo o DialogoApilado.')
  return contexto
}

/** Atributo que marca la caja de cada modal: los campos de una modal anidada no cuentan para la de afuera. */
export const ATRIBUTO_DIALOGO = 'data-dialogo'

/** Atributo de un campo que atiende Esc por su cuenta: mientras lo tenga, Esc no cierra la modal. */
export const ATRIBUTO_ESCAPE_LOCAL = 'data-escape-local'

const CAMPOS = 'input, textarea, select, [role="radio"], [role="checkbox"], [role="switch"]'

function valorDe(el: Element): string {
  if (el instanceof HTMLInputElement) return el.type === 'checkbox' || el.type === 'radio' ? String(el.checked) : el.value
  if (el instanceof HTMLTextAreaElement || el instanceof HTMLSelectElement) return el.value
  return el.getAttribute('aria-checked') ?? ''
}

/**
 * Cambios del usuario desde que abrió la modal. Cada campo se fotografía la PRIMERA vez que el
 * usuario lo toca (foco o clic), antes de que cambie; al cerrar se compara con su valor actual. Así:
 * - llenar un campo y dejarlo como estaba no cuenta;
 * - lo que cambia solo (opciones que cargan tarde, valores por defecto) no cuenta, porque nadie lo tocó;
 * - en un grupo de opciones (radios, control segmentado) se fotografía el grupo entero.
 */
function useCambiosDelUsuario(cajaRef: RefObject<HTMLElement | null>) {
  const fotos = useRef(new Map<Element, string>())

  useEffect(() => {
    const caja = cajaRef.current
    if (!caja) return
    const propio = (el: Element) => el.closest(`[${ATRIBUTO_DIALOGO}]`) === caja
    const fotografiar = (el: Element) => { if (!fotos.current.has(el)) fotos.current.set(el, valorDe(el)) }

    const alTocar = (e: Event) => {
      const campo = e.target instanceof Element ? e.target.closest(CAMPOS) : null
      if (!campo || !propio(campo)) return
      const grupo = campo.closest('[role="radiogroup"]')
      if (grupo) grupo.querySelectorAll('[role="radio"]').forEach(fotografiar)
      else if (campo instanceof HTMLInputElement && campo.type === 'radio' && campo.name) {
        caja.querySelectorAll(`input[type="radio"][name="${CSS.escape(campo.name)}"]`).forEach(fotografiar)
      }
      fotografiar(campo)
    }

    caja.addEventListener('focusin', alTocar, true)
    caja.addEventListener('pointerdown', alTocar, true)
    // Un campo con autoFocus ya tiene el foco antes de este efecto: se fotografía ahora, sin cambios aún.
    if (document.activeElement && caja.contains(document.activeElement)) alTocar({ target: document.activeElement } as unknown as Event)
    return () => {
      caja.removeEventListener('focusin', alTocar, true)
      caja.removeEventListener('pointerdown', alTocar, true)
    }
  }, [cajaRef])

  return useCallback(() => {
    for (const [el, antes] of fotos.current) {
      if (el.isConnected && valorDe(el) !== antes) return true
    }
    return false
  }, [])
}

/**
 * Estado de cierre de una modal: decide si pregunta, guarda el foco de quien la abrió y lo devuelve al
 * cerrar. `modificado` fuerza el resultado (modales con listas u otro estado complejo);
 * `confirmarSalida={false}` cierra directo (confirmaciones de sí/no, vistas de solo lectura).
 */
export function useSalidaDialogo({ cajaRef, onCerrar, pendiente, modificado, confirmarSalida }: {
  cajaRef: RefObject<HTMLElement | null>
  onCerrar: () => void
  pendiente: boolean
  modificado?: boolean
  confirmarSalida: boolean
}) {
  const [preguntando, setPreguntando] = useState(false)
  const hayCambios = useCambiosDelUsuario(cajaRef)

  // Foco: al cerrar vuelve al botón que abrió la modal (si sigue en la página). Se devuelve en la
  // limpieza del layout, antes de los efectos del padre: si el padre enfoca otra cosa, gana el padre.
  // Se guarda en el primer render, antes de que un diálogo apilado deje la app inerte (y le quite el foco).
  const [quienAbrio] = useState<HTMLElement | null>(() => (document.activeElement instanceof HTMLElement ? document.activeElement : null))
  useLayoutEffect(() => {
    return () => {
      if (quienAbrio?.isConnected) quienAbrio.focus({ preventScroll: true })
    }
  }, [quienAbrio])

  const cerrarSinPreguntar = useCallback(() => { setPreguntando(false); onCerrar() }, [onCerrar])

  const solicitarCierre = useCallback(() => {
    if (pendiente) return
    const conCambios = confirmarSalida && (modificado ?? hayCambios())
    if (conCambios) setPreguntando(true)
    else onCerrar()
  }, [pendiente, confirmarSalida, modificado, hayCambios, onCerrar])

  return {
    contexto: { solicitarCierre, cerrarSinPreguntar, pendiente } satisfies CierreDialogo,
    preguntando,
    seguirEditando: () => setPreguntando(false),
  }
}

/**
 * Clic en el fondo: solo si el clic empezó y terminó en el fondo (arrastrar una selección desde un
 * campo hasta afuera no cierra).
 */
export function useClicEnFondo(solicitarCierre: () => void) {
  const empezoEnFondo = useRef(false)
  return {
    onMouseDown: (e: { target: EventTarget; currentTarget: EventTarget }) => { empezoEnFondo.current = e.target === e.currentTarget },
    onClick: (e: { target: EventTarget; currentTarget: EventTarget }) => {
      if (empezoEnFondo.current && e.target === e.currentTarget) solicitarCierre()
      empezoEnFondo.current = false
    },
  }
}

/**
 * Pila de modales abiertas (Dialogo y DialogoApilado): la de encima es la única que atiende Escape.
 * Así una confirmación, o un diálogo apilado, no cierra el de abajo.
 */
const pila: number[] = []
const oyentes = new Set<() => void>()
let secuenciaDialogo = 0

function notificar() {
  oyentes.forEach((fn) => fn())
}

export function useTope(): boolean {
  const id = useRef(0)
  if (id.current === 0) id.current = ++secuenciaDialogo
  const [, pintar] = useState(0)

  useEffect(() => {
    const propio = id.current
    pila.push(propio)
    const actualizar = () => pintar((n) => n + 1)
    oyentes.add(actualizar)
    notificar()
    return () => {
      const i = pila.lastIndexOf(propio)
      if (i >= 0) pila.splice(i, 1)
      oyentes.delete(actualizar)
      notificar()
    }
  }, [])

  return pila[pila.length - 1] === id.current
}

/** Escape cierra (por `solicitarCierre`) solo la modal de encima. */
export function useEscape(esTope: boolean, solicitarCierre: () => void) {
  useEffect(() => {
    if (!esTope) return
    const alPulsar = (e: globalThis.KeyboardEvent) => {
      if (e.key !== 'Escape') return
      // Un campo con `data-escape-local` usa Esc para sí (por ejemplo, limpiar un buscador con texto).
      if (e.target instanceof Element && e.target.closest(`[${ATRIBUTO_ESCAPE_LOCAL}]`)) return
      e.preventDefault()
      e.stopPropagation()
      solicitarCierre()
    }
    document.addEventListener('keydown', alPulsar, true)
    return () => document.removeEventListener('keydown', alPulsar, true)
  }, [esTope, solicitarCierre])
}
