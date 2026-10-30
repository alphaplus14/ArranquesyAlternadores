import { useCallback, useRef, type KeyboardEvent } from 'react'
import type { MetodoCaptura } from '../api/tipos'

/** Un lector de código de barras "teclea" mucho más rápido que una persona. */
export const UMBRAL_RAFAGA_MS = 30
const MIN_CARACTERES = 3

/**
 * RF-12: decide si un código llegó escaneado o digitado.
 * Si todos los caracteres llegan en ráfaga (< 30 ms entre teclas) y terminan en Enter, es
 * `escaneado`. Cualquier otra cosa (tecleo humano, pegar, corregir) es `digitado`.
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

  const onKeyDown = useCallback(
    (e: KeyboardEvent<HTMLInputElement>) => {
      const ahora = performance.now()

      if (e.key === 'Enter') {
        e.preventDefault()
        const codigo = e.currentTarget.value.trim()
        if (codigo) {
          const rafaga = !editado.current && teclas.current >= MIN_CARACTERES && maxPausa.current < UMBRAL_RAFAGA_MS
          onCodigo(codigo, rafaga ? 'escaneado' : 'digitado')
        }
        reiniciar()
        return
      }

      if (e.key.length !== 1) {
        // Backspace, flechas, pegar con Ctrl+V…: lo trata como digitado.
        if (e.key !== 'Shift') editado.current = true
        return
      }

      if (ultimaTecla.current !== null) {
        maxPausa.current = Math.max(maxPausa.current, ahora - ultimaTecla.current)
      }
      ultimaTecla.current = ahora
      teclas.current += 1
    },
    [onCodigo, reiniciar],
  )

  return { onKeyDown, onPaste: () => { editado.current = true }, reiniciar }
}
