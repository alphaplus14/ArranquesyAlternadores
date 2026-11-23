import { errorStockMinimo } from '../utilidades/stockMinimo'

/**
 * RF-22: campo numérico opcional de stock mínimo (entero 0..9999). El valor es texto: '' = vacío.
 * La ayuda cambia según dónde se use (producto, universal o general).
 */
export default function CampoStockMinimo({ id, etiqueta = 'Stock mínimo (opcional)', ayuda, valor, onCambio, autoFocus }: {
  id: string
  etiqueta?: string
  ayuda: string
  valor: string
  onCambio: (valor: string) => void
  autoFocus?: boolean
}) {
  const error = errorStockMinimo(valor)
  return (
    <>
      <label htmlFor={id}>{etiqueta}</label>
      <input
        id={id}
        type="number"
        min="0"
        max="9999"
        step="1"
        inputMode="numeric"
        autoFocus={autoFocus}
        value={valor}
        aria-invalid={error !== null}
        aria-describedby={`${id}-ayuda`}
        onChange={(e) => onCambio(e.target.value)}
      />
      <p id={`${id}-ayuda`} className={error ? 'ayuda error' : 'ayuda'}>{error ?? ayuda}</p>
    </>
  )
}
