import { useCallback, useRef, type KeyboardEvent } from 'react'
import type { MetodoCaptura } from '../api/tipos'

/** Un lector de código de barras "teclea" mucho más rápido que una persona. */
export const UMBRAL_RAFAGA_MS = 30
const MIN_CARACTERES = 3

/**
 * RF-12: decide si un código llegó escaneado o digitado.
 * Si todos los caracteres llegan en ráfaga (< 30 ms entre teclas) y terminan en Enter, es
 * `escaneado`. Cualquier otra cosa (tecleo humano, pegar, corregir) es `digitado`.
 * El input debe ser no controlado (el hook lee su valor al recibir Enter).
 */
export function useScanner(onCodigo: (codigo: string, metodo: MetodoCaptura) => void) {
  const ultimaTecla = useRef<number | null>(null)
  const maxPausa = useRef(0)
  const teclas = useRef(0)
  const editado = useRef(false)

  const reiniciar = useCallback(() => {
    ultimaTecla.current = null
    maxPausa.current = 0
    teclas.current = 0
    editado.current = false
  }, [])

  const enviar = useCallback(
    (input: HTMLInputElement) => {
      const codigo = input.value.trim()
      if (codigo) {
        const rafaga = !editado.current && teclas.current >= MIN_CARACTERES && maxPausa.current < UMBRAL_RAFAGA_MS
        onCodigo(codigo, rafaga ? 'escaneado' : 'digitado')
      }
      reiniciar()
    },
    [onCodigo, reiniciar],
  )

  const onKeyDown = useCallback(
    (e: KeyboardEvent<HTMLInputElement>) => {
      const ahora = performance.now()
      const desdeUltima = ultimaTecla.current === null ? Infinity : ahora - ultimaTecla.current

      if (e.key === 'Enter') {
        e.preventDefault()
        enviar(e.currentTarget)
        return
      }

      if (e.key.length !== 1) {
        // Backspace, flechas, pegar con Ctrl+V…: lo trata como digitado.
        if (e.key !== 'Shift') editado.current = true
        return
      }

      if (ultimaTecla.current !== null) maxPausa.current = Math.max(maxPausa.current, desdeUltima)
      ultimaTecla.current = ahora
      teclas.current += 1
    },
    [enviar],
  )

  return { onKeyDown, onPaste: () => { editado.current = true }, reiniciar }
}
