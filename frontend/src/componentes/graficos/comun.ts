import { useCallback, useState, type KeyboardEvent } from 'react'

/**
 * RF-23: piezas comunes de los gráficos SVG propios (sin librerías). Los colores salen de las
 * variables --grafico-* de index.css; ningún gráfico depende solo del color (patrones, trazos y
 * texto acompañan cada serie).
 */

/** Tope "redondo" del eje (1, 2, 2,5 o 5 × 10^n) para que las rayas caigan en cifras legibles. */
export function topeEje(maximo: number): number {
  if (!(maximo > 0)) return 1
  const potencia = 10 ** Math.floor(Math.log10(maximo))
  const paso = [1, 2, 2.5, 5, 10].find((p) => p * potencia >= maximo) ?? 10
  return paso * potencia
}

/** Cifra corta para los ejes: 1,2 M · 850 mil · 950. */
export function cifraCorta(n: number): string {
  const abs = Math.abs(n)
  if (abs >= 1_000_000) return `${(n / 1_000_000).toLocaleString('es-CO', { maximumFractionDigits: 1 })} M`
  if (abs >= 1_000) return `${(n / 1_000).toLocaleString('es-CO', { maximumFractionDigits: 0 })} mil`
  return n.toLocaleString('es-CO', { maximumFractionDigits: 0 })
}

/**
 * Punto activo de un gráfico recorrible con el teclado: ← → Inicio Fin. Al tomar el foco sin
 * punto activo, activa el último (el más reciente). Escape lo suelta.
 */
export function useIndiceActivo(cantidad: number) {
  const [indice, setIndice] = useState<number | null>(null)

  const onKeyDown = useCallback((e: KeyboardEvent) => {
    if (cantidad === 0) return
    const actual = indice ?? cantidad - 1
    const siguiente = ({
      ArrowRight: Math.min(cantidad - 1, actual + 1),
      ArrowUp: Math.min(cantidad - 1, actual + 1),
      ArrowLeft: Math.max(0, actual - 1),
      ArrowDown: Math.max(0, actual - 1),
      Home: 0,
      End: cantidad - 1,
    } as Record<string, number>)[e.key]
    if (e.key === 'Escape') { setIndice(null); return }
    if (siguiente === undefined) return
    e.preventDefault()
    setIndice(siguiente)
  }, [cantidad, indice])

  const onFocus = useCallback(() => setIndice((i) => i ?? (cantidad > 0 ? cantidad - 1 : null)), [cantidad])

  return { indice: indice !== null && indice < cantidad ? indice : null, setIndice, onKeyDown, onFocus }
}
