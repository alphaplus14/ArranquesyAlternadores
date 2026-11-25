import { useLayoutEffect, useRef, type ClipboardEvent, type InputHTMLAttributes, type Ref } from 'react'
import { formatearDinero, formatoNumero, leerNumero } from './numeros'

/** Dígitos y coma antes de `pos`: lo que el cursor debe tener a su izquierda tras formatear. */
const significativos = (texto: string, pos: number) => texto.slice(0, pos).replace(/[^\d,]/g, '').length

function posicionTras(formateado: string, cuenta: number) {
  if (cuenta === 0) return 0
  let vistos = 0
  for (let i = 0; i < formateado.length; i++) {
    if (/[\d,]/.test(formateado[i]) && ++vistos === cuenta) return i + 1
  }
  return formateado.length
}

type Props = Omit<InputHTMLAttributes<HTMLInputElement>, 'type' | 'value' | 'onChange' | 'inputMode'> & {
  /** Texto en formato es-CO (el que se muestra). */
  value: string
  onChange: (e: { target: { value: string } }) => void
  ref?: Ref<HTMLInputElement>
}

/** Campo de precio, costo o valor en pesos con separador de miles (S-67). */
export default function CampoMoneda({ value, onChange, ref, onPaste, ...resto }: Props) {
  const campo = useRef<HTMLInputElement | null>(null)
  const cursor = useRef<number | null>(null)

  useLayoutEffect(() => {
    if (cursor.current === null || !campo.current || document.activeElement !== campo.current) return
    campo.current.setSelectionRange(cursor.current, cursor.current)
    cursor.current = null
  }, [value])

  const asignarRef = (el: HTMLInputElement | null) => {
    campo.current = el
    if (typeof ref === 'function') ref(el)
    else if (ref) (ref as { current: HTMLInputElement | null }).current = el
  }

  const cambiar = (crudo: string, pos: number) => {
    // Borrar justo un punto de miles no cambia el número: se borra también el dígito anterior.
    if (crudo.length === value.length - 1 && value[pos] === '.' && crudo === value.slice(0, pos) + value.slice(pos + 1) && pos > 0) {
      crudo = crudo.slice(0, pos - 1) + crudo.slice(pos)
      pos -= 1
    }
    const formateado = formatearDinero(crudo)
    cursor.current = posicionTras(formateado, significativos(crudo, pos))
    onChange({ target: { value: formateado } })
  }

  // Pegar "1250000.50" o "$ 1.250.000" desde otro programa: se interpreta completo, no carácter por carácter.
  const pegar = (e: ClipboardEvent<HTMLInputElement>) => {
    onPaste?.(e)
    if (e.defaultPrevented) return
    const n = leerNumero(e.clipboardData.getData('text'))
    if (n === null || Number.isNaN(n)) return
    e.preventDefault()
    const formateado = formatoNumero(n)
    cursor.current = formateado.length
    onChange({ target: { value: formateado } })
  }

  return (
    <input
      {...resto}
      ref={asignarRef}
      type="text"
      inputMode="decimal"
      autoComplete="off"
      value={value}
      onPaste={pegar}
      onChange={(e) => cambiar(e.target.value, e.target.selectionStart ?? e.target.value.length)}
    />
  )
}
