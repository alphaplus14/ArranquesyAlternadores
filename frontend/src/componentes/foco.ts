import type { KeyboardEvent } from 'react'

const ENFOCABLES = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'

/**
 * Mantiene Tab y Shift+Tab dentro de un diálogo modal: del último control vuelve al primero y
 * al revés. Así el foco no cae en lo que hay detrás (por ejemplo, el campo de escaneo).
 */
export function atraparTab(e: KeyboardEvent<HTMLElement>) {
  if (e.key !== 'Tab') return
  const controles = [...e.currentTarget.querySelectorAll<HTMLElement>(ENFOCABLES)].filter((c) => c.tabIndex >= 0)
  if (controles.length === 0) return
  const primero = controles[0]
  const ultimo = controles[controles.length - 1]
  const activo = document.activeElement

  if (e.shiftKey && (activo === primero || !e.currentTarget.contains(activo))) {
    e.preventDefault()
    ultimo.focus()
  } else if (!e.shiftKey && (activo === ultimo || !e.currentTarget.contains(activo))) {
    e.preventDefault()
    primero.focus()
  }
}
