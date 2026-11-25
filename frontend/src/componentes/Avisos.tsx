import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { Link } from 'react-router-dom'

export type TonoAviso = 'ok' | 'error' | 'atencion'

interface EnlaceAviso { to: string; texto: string }

interface Mensaje {
  id: number
  tono: TonoAviso
  texto: string
  enlace?: EnlaceAviso
  /** ms hasta cerrarse solo; sin valor manda el tono (SE_VA_SOLO). */
  espera?: number
}

interface Avisos {
  /** Reemplaza al aviso anterior. Devuelve el id para poder cerrar solo ese. `espera` (ms) lo cierra solo aunque el tono no lo haga. */
  mostrar: (tono: TonoAviso, texto: string, enlace?: EnlaceAviso, espera?: number) => number
  /** Sin id cierra el que esté. Con id, solo si sigue siendo ese. */
  limpiar: (id?: number) => void
  /** Cierra el aviso solo si es de ese tono. Un acierto no borra un error, ni al revés. */
  limpiarSi: (tono: TonoAviso) => void
}

const Contexto = createContext<Avisos | null>(null)

/** El acierto se va solo. El error y la atención se quedan hasta que otro aviso los reemplace o se cierren. */
const SE_VA_SOLO: Partial<Record<TonoAviso, number>> = { ok: 5000 } // S-50: 5 s

let secuencia = 0

/**
 * Un aviso a la vez, fuera del flujo de la página: tono, región viva y salida viven aquí.
 * Quien llama solo entrega el texto.
 */
export function AvisosProvider({ children }: { children: ReactNode }) {
  const [mensaje, setMensaje] = useState<Mensaje | null>(null)

  const mostrar = useCallback((tono: TonoAviso, texto: string, enlace?: EnlaceAviso, espera?: number) => {
    const id = ++secuencia
    setMensaje({ id, tono, texto, enlace, espera })
    return id
  }, [])

  const limpiar = useCallback((id?: number) => {
    setMensaje((actual) => (!actual || (id !== undefined && actual.id !== id) ? actual : null))
  }, [])

  const limpiarSi = useCallback((tono: TonoAviso) => {
    setMensaje((actual) => (actual?.tono === tono ? null : actual))
  }, [])

  useEffect(() => {
    const espera = mensaje ? (mensaje.espera ?? SE_VA_SOLO[mensaje.tono]) : undefined
    if (!mensaje || espera === undefined) return
    const id = mensaje.id
    const t = window.setTimeout(() => limpiar(id), espera)
    return () => window.clearTimeout(t)
  }, [mensaje, limpiar])

  const api = useMemo<Avisos>(() => ({ mostrar, limpiar, limpiarSi }), [mostrar, limpiar, limpiarSi])

  return (
    <Contexto.Provider value={api}>
      {children}
      {mensaje && createPortal(
        <div className={`aviso-flotante ${mensaje.tono}`} role={mensaje.tono === 'error' ? 'alert' : 'status'}>
          <div className="aviso-flotante-texto">
            <p>{mensaje.texto}</p>
            {mensaje.enlace && <Link to={mensaje.enlace.to} onClick={() => limpiar(mensaje.id)}>{mensaje.enlace.texto}</Link>}
          </div>
          <button type="button" className="aviso-cerrar" aria-label="Cerrar aviso" onClick={() => limpiar(mensaje.id)}>
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" aria-hidden><path d="M6 6l12 12M18 6L6 18" /></svg>
          </button>
        </div>,
        document.body,
      )}
    </Contexto.Provider>
  )
}

export function useAviso() {
  const ctx = useContext(Contexto)
  if (!ctx) throw new Error('useAviso fuera de AvisosProvider')
  return ctx
}

/**
 * Muestra `texto` mientras no sea null y cierra ese aviso al cambiar o al desmontar.
 * No pisa un aviso más nuevo: limpiar solo actúa si el id sigue siendo el suyo.
 */
export function useAvisoTexto(texto: string | null, tono: TonoAviso = 'error', enlace?: EnlaceAviso | null) {
  const { mostrar, limpiar } = useAviso()
  const id = useRef<number | null>(null)
  const destino = enlace?.to ?? ''
  const etiqueta = enlace?.texto ?? ''

  useEffect(() => {
    if (!texto) {
      if (id.current !== null) limpiar(id.current)
      id.current = null
      return
    }
    id.current = mostrar(tono, texto, destino ? { to: destino, texto: etiqueta } : undefined)
    return () => {
      if (id.current !== null) limpiar(id.current)
      id.current = null
    }
  }, [texto, tono, destino, etiqueta, mostrar, limpiar])
}
