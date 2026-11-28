import { useCallback, useEffect, useRef, useState } from 'react'

export type TonoAlerta = 'ok' | 'error' | 'atencion' | 'info'

/** Tiempo base por tono (ms). Ningún aviso queda fijo: el error es el que más dura. */
const BASE: Record<TonoAlerta, number> = { ok: 4000, info: 6000, atencion: 8000, error: 10000 }
const TOPE = 20000
/** Tiempo extra de lectura por carácter, para que un mensaje largo no se vaya sin poder leerlo. */
const POR_CARACTER = 55

/** ms que debe verse un aviso: el del tono, o más si el texto es largo. Nunca más de 20 s. */
export function duracionAviso(tono: TonoAlerta, texto: string): number {
  return Math.min(TOPE, Math.max(BASE[tono], 2500 + texto.length * POR_CARACTER))
}

/**
 * Cierre automático con pausa. Llama `alCerrar` pasados `espera` ms.
 * `pausar` / `reanudar` (mouse o foco encima) congelan la cuenta y la retoman donde iba.
 * Cambiar `clave` reinicia la cuenta (un aviso nuevo, aunque sea el mismo componente).
 */
export function useAutoCierre(espera: number | null, alCerrar: () => void, clave: unknown) {
  const [pausado, setPausado] = useState(false)
  const restante = useRef(0)
  const inicio = useRef(0)
  const cerrar = useRef(alCerrar)

  useEffect(() => { cerrar.current = alCerrar })

  useEffect(() => {
    restante.current = espera ?? 0
    setPausado(false)
  }, [espera, clave])

  useEffect(() => {
    if (espera === null || pausado) return
    inicio.current = Date.now()
    const t = window.setTimeout(() => cerrar.current(), Math.max(restante.current, 0))
    return () => {
      window.clearTimeout(t)
      restante.current -= Date.now() - inicio.current
    }
  }, [espera, clave, pausado])

  const pausar = useCallback(() => setPausado(true), [])
  const reanudar = useCallback(() => setPausado(false), [])

  return { pausado, pausar, reanudar }
}
