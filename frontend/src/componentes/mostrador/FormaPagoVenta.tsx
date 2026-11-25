import { useMemo } from 'react'
import { NOMBRE_TIPO_CUENTA, type CuentaPago } from '../../api/tipos'
import { formatoNumero, leerNumero, pesos } from '../numeros'
import { evaluarPago, nuevaFila, type FilaPago } from '../../utilidades/formaPago'

/**
 * RF-24 (S-48): "Forma de pago" del cierre de una venta de contado. Filas cuenta + valor (+ referencia
 * opcional); "Agregar otra cuenta" divide el pago; muestra en vivo cuánto falta o sobra.
 */
export default function FormaPagoVenta({ cuentas, total, filas, deshabilitado, onCambio }: {
  cuentas: CuentaPago[]
  total: string | number
  filas: FilaPago[]
  deshabilitado?: boolean
  onCambio: (filas: FilaPago[]) => void
}) {
  const estado = useMemo(() => evaluarPago(filas, total), [filas, total])
  const cambiar = (clave: number, cambios: Partial<FilaPago>) => onCambio(filas.map((f) => (f.clave === clave ? { ...f, ...cambios } : f)))
  const agregar = () => {
    const usadas = new Set(filas.map((f) => f.cuenta_pago_id))
    const libre = cuentas.find((c) => !usadas.has(c.id))
    const resto = estado.diferencia > 0 ? formatoNumero(estado.diferencia / 100) : ''
    onCambio([...filas, nuevaFila(libre?.id ?? '', resto)])
  }

  return (
    <fieldset className="forma-pago" disabled={deshabilitado}>
      <legend className="etiqueta-campo">Forma de pago</legend>
      {filas.map((f, i) => {
        const n = leerNumero(f.valor)
        const valorMalo = f.valor.trim() !== '' && (n === null || Number.isNaN(n) || n <= 0)
        return (
          <div className="fila-pago" key={f.clave}>
            <label>
              <span className="solo-lector">Cuenta del pago {i + 1}</span>
              <select value={f.cuenta_pago_id} aria-invalid={f.cuenta_pago_id === ''} onChange={(e) => cambiar(f.clave, { cuenta_pago_id: e.target.value === '' ? '' : Number(e.target.value) })}>
                <option value="">Elija la cuenta…</option>
                {cuentas.map((c) => <option key={c.id} value={c.id}>{c.nombre} ({NOMBRE_TIPO_CUENTA[c.tipo]})</option>)}
              </select>
            </label>
            <label>
              <span className="solo-lector">Valor del pago {i + 1}</span>
              <input inputMode="decimal" autoComplete="off" placeholder="Valor" aria-invalid={valorMalo} value={f.valor} onChange={(e) => cambiar(f.clave, { valor: e.target.value })} />
            </label>
            <label>
              <span className="solo-lector">Referencia del pago {i + 1} (opcional)</span>
              <input maxLength={60} placeholder="Referencia (opcional)" value={f.referencia} onChange={(e) => cambiar(f.clave, { referencia: e.target.value })} />
            </label>
            {filas.length > 1
              ? <button type="button" className="enlace" aria-label={`Quitar el pago ${i + 1}`} onClick={() => onCambio(filas.filter((x) => x.clave !== f.clave))}>Quitar</button>
              : <span />}
          </div>
        )
      })}
      <div className="forma-pago-pie">
        <button type="button" className="secundario" disabled={filas.length >= Math.min(10, cuentas.length)} onClick={agregar}>Agregar otra cuenta</button>
        <span className={estado.diferencia === 0 && !estado.invalida ? 'cuadre ok' : 'cuadre pendiente'} role="status" aria-live="polite">
          {estado.repetida ? 'Hay una cuenta repetida.'
            : estado.diferencia > 0 ? `Falta ${pesos(estado.diferencia / 100)}`
              : estado.diferencia < 0 ? `Sobra ${pesos(-estado.diferencia / 100)}`
                : estado.invalida ? 'Complete cuenta y valor de cada fila.' : 'Cuadra con el total'}
        </span>
      </div>
    </fieldset>
  )
}
