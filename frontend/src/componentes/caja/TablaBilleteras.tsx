import type { GrupoBilleteraCaja } from '../../api/tipos'
import { pesos } from '../numeros'

/**
 * S-88: saldos de billeteras agrupados por dueño. `etiquetaNeto` es "Movimiento del día" o
 * "Movimiento del mes". El saldo es calculado (anterior + movimiento); no es el efectivo del cajón.
 */
export default function TablaBilleteras({ grupos, etiquetaNeto }: { grupos: GrupoBilleteraCaja[]; etiquetaNeto: string }) {
  if (grupos.length === 0) return null

  return (
    <div className="billeteras-saldo">
      <h3>Billeteras por dueño</h3>
      <p className="ayuda">El saldo es la suma de lo que ha entrado y salido de cada billetera. No mueve el efectivo de la caja.</p>
      <table className="tabla-caja">
        <caption className="solo-lector">Saldos de billeteras por dueño</caption>
        <thead>
          <tr>
            <th>Dueño</th>
            <th>Billetera</th>
            <th className="col-numero">Saldo anterior</th>
            <th className="col-numero">{etiquetaNeto}</th>
            <th className="col-numero">Saldo</th>
          </tr>
        </thead>
        {grupos.map((g) => (
          <tbody key={g.propietario_id ?? 'sin'}>
            <tr className="fila-total">
              <td>{g.propietario}</td>
              <td>{g.cuentas.length === 1 ? g.cuentas[0].nombre : `${g.cuentas.length} billeteras`}</td>
              <td className="col-numero">{pesos(g.saldo_anterior)}</td>
              <td className="col-numero">{pesos(g.neto)}</td>
              <td className="col-numero"><strong>{pesos(g.saldo)}</strong></td>
            </tr>
            {g.cuentas.length > 1 && g.cuentas.map((c) => (
              <tr key={c.cuenta_pago_id}>
                <td />
                <td>{c.nombre}</td>
                <td className="col-numero">{pesos(c.saldo_anterior)}</td>
                <td className="col-numero">{pesos(c.neto)}</td>
                <td className="col-numero">{pesos(c.saldo)}</td>
              </tr>
            ))}
          </tbody>
        ))}
      </table>
    </div>
  )
}
