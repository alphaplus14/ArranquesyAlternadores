import CampoMoneda from './CampoMoneda'

export type ModoDescuento = '$' | '%'

/**
 * S-22: descuento del documento en pesos o en porcentaje (opcional, 0 por defecto). El backend
 * recibe uno u otro, nunca los dos, y valida que no supere el subtotal neto.
 */
export default function CampoDescuento({ id, modo, valor, error, ayuda, onModo, onValor }: {
  id: string
  modo: ModoDescuento
  valor: string
  error?: string
  ayuda?: string
  onModo: (modo: ModoDescuento) => void
  onValor: (valor: string) => void
}) {
  return (
    <label>Descuento del documento <small className="suave">(opcional)</small>
      <span className="campo-con-unidad">
        <select aria-label="Unidad del descuento" value={modo} onChange={(e) => onModo(e.target.value as ModoDescuento)}>
          <option value="$">$</option>
          <option value="%">%</option>
        </select>
        {modo === '$' ? (
          // S-52: los pesos llevan separador de miles; quien llama los lee con leerNumero.
          <CampoMoneda aria-invalid={!!error} aria-describedby={`${id}-ayuda`} value={valor} onChange={(e) => onValor(e.target.value)} />
        ) : (
          <input
            type="number"
            min="0"
            max={100}
            step="0.01"
            aria-invalid={!!error}
            aria-describedby={`${id}-ayuda`}
            value={valor}
            onChange={(e) => onValor(e.target.value)}
          />
        )}
      </span>
      <small id={`${id}-ayuda`} className={error ? 'ayuda error' : 'ayuda'}>
        {error ?? ayuda ?? (modo === '%' ? 'Porcentaje sobre el subtotal neto de las líneas.' : 'En pesos. Se reparte entre las líneas antes del IVA.')}
      </small>
    </label>
  )
}
