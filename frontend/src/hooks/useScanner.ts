import { useCallback, useEffect, useRef, type KeyboardEvent } from 'react'
import type { MetodoCaptura } from '../api/tipos'

/** Un lector de código de barras "teclea" mucho más rápido que una persona. */
export const UMBRAL_RAFAGA_MS = 30
const MIN_CARACTERES = 3
/** Modo multilínea: silencio que marca el fin de la lectura de un QR. */
const PAUSA_FIN_MS = 120

interface Opciones {
  /**
   * RF-04: el QR de una factura trae saltos de línea y el lector en modo teclado los envía como
   * Enter. En este modo, un Enter dentro de la ráfaga se toma como separador (espacio) y la
   * lectura termina por silencio. Un Enter tecleado a mano, tras una pausa, sigue enviando.
   */
  multilinea?: boolean
}

/**
 * RF-12: decide si un código llegó escaneado o digitado.
 * Si todos los caracteres llegan en ráfaga (< 30 ms entre teclas) y terminan en Enter, es
 * `escaneado`. Cualquier otra cosa (tecleo humano, pegar, corregir) es `digitado`.
 * El input debe ser no controlado (el hook lee y, en modo multilínea, escribe su valor).
 */
export function useScanner(onCodigo: (codigo: string, metodo: MetodoCaptura) => void, opciones: Opciones = {}) {
  const ultimaTecla = useRef<number | null>(null)
  const maxPausa = useRef(0)
  const teclas = useRef(0)
  const editado = useRef(false)
  const temporizador = useRef<number | null>(null)

  const cancelarTemporizador = () => {
    if (temporizador.current !== null) window.clearTimeout(temporizador.current)
    temporizador.current = null
  }

  const reiniciar = useCallback(() => {
    cancelarTemporizador()
    ultimaTecla.current = null
    maxPausa.current = 0
    teclas.current = 0
    editado.current = false
  }, [])

  useEffect(() => cancelarTemporizador, [])

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
      const input = e.currentTarget
      const desdeUltima = ultimaTecla.current === null ? Infinity : ahora - ultimaTecla.current

      if (e.key === 'Enter') {
        e.preventDefault()
        if (opciones.multilinea && desdeUltima < UMBRAL_RAFAGA_MS) {
          // Salto de línea del QR: separador, y se espera el silencio para enviar.
          input.value += ' '
          maxPausa.current = Math.max(maxPausa.current, desdeUltima)
          ultimaTecla.current = ahora
          cancelarTemporizador()
          temporizador.current = window.setTimeout(() => enviar(input), PAUSA_FIN_MS)
          return
        }
        enviar(input)
        return
      }

      if (e.key.length !== 1) {
        // Backspace, flechas, pegar con Ctrl+V…: lo trata como digitado.
        if (e.key !== 'Shift') editado.current = true
        return
      }

      // Llegó otra tecla: la lectura multilínea sigue.
      cancelarTemporizador()
      if (ultimaTecla.current !== null) maxPausa.current = Math.max(maxPausa.current, desdeUltima)
      ultimaTecla.current = ahora
      teclas.current += 1
    },
    [enviar, opciones.multilinea],
  )

  return { onKeyDown, onPaste: () => { editado.current = true }, reiniciar }
}
