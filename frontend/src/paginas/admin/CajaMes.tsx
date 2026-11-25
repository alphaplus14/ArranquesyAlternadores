import { useQuery } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { api, mensajeError } from '../../api/cliente'
import type { FilaMesCaja, MesCaja } from '../../api/tipos'
import { pesos } from '../../componentes/numeros'
import { hoyEnBogota } from '../../utilidades/periodos'

const DIAS_SEMANA = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb']

/** "lun 03" a partir de aaaa-mm-dd (día del calendario, sin zona). */
function etiquetaDia(fecha: string): string {
  const [a, m, d] = fecha.split('-').map(Number)
  return `${DIAS_SEMANA[new Date(a, m - 1, d).getDay()]} ${String(d).padStart(2, '0')}`
}

const signo = (v: string | null) => (v === null ? '—' : `${Number(v) > 0 ? '+' : ''}${pesos(v)}`)

/**
 * RF-25: cuadre mensual al estilo de la planilla (solo admin, solo en pantalla). Una fila por día hasta
 * hoy: apertura (S-49), ventas por efectivo, banco, datáfono y billetera, crédito, otros ingresos,
 * total, egresos, neto acumulado del mes (informativo: suma del efectivo neto desde el día 1, desde 0),
 * meta, faltante, diferencia (contado − apertura − neto) y observación. No hay saldo encadenado.
 * Los días sin cierre se calculan en vivo y se marcan "sin cerrar".
 */
export default function CajaMes({ onVerDia }: { onVerDia: (fecha: string) => void }) {
  const mesActual = hoyEnBogota().slice(0, 7)
  const [params, setParams] = useSearchParams()
  const mesParam = params.get('mes')
  const mes = mesParam && /^\d{4}-\d{2}$/.test(mesParam) && mesParam <= mesActual ? mesParam : mesActual

  const datos = useQuery({
    queryKey: ['caja', 'mes', mes],
    queryFn: async () => (await api.get<MesCaja>(`/caja/meses/${mes}`)).data,
  })
  const m = datos.data

  return (
    <section className="tarjeta-pagina caja-mes">
      <div className="fila-titulo">
        <label className="caja-fecha">
          <span className="etiqueta-campo">Mes</span>
          <input
            type="month"
            max={mesActual}
            value={mes}
            onChange={(e) => setParams((p) => {
              const n = new URLSearchParams(p)
              if (!e.target.value || e.target.value === mesActual) n.delete('mes'); else n.set('mes', e.target.value)
              return n
            }, { replace: true })}
          />
        </label>
        {m && (
          <p className="ayuda">
            Meta diaria {pesos(m.meta_venta_diaria)} · {m.totales.dias_cerrados} días cerrados, {m.totales.dias_sin_cerrar} sin cerrar
          </p>
        )}
      </div>
      {datos.isLoading && <p className="cargando">Cargando el mes…</p>}
      {datos.isError && <div className="aviso error">{mensajeError(datos.error)}</div>}
      {m && (
        <div className="tabla-mes-scroll">
          <table className="tabla-caja tabla-mes">
            <caption className="solo-lector">Cuadre mensual de caja de {mes}</caption>
            <thead>
              <tr>
                <th>Día</th>
                <th className="col-numero">Apertura</th>
                <th className="col-numero">Efectivo</th><th className="col-numero">Banco</th><th className="col-numero">Datáfono</th><th className="col-numero">Billetera</th>
                <th className="col-numero">Crédito</th><th className="col-numero">Otros ingresos</th><th className="col-numero">Total</th>
                <th className="col-numero">Egresos</th><th className="col-numero" title="Informativo: suma del efectivo neto del día desde el primer día del mes">Neto acumulado del mes</th><th className="col-numero">Meta</th>
                <th className="col-numero">Faltante</th><th className="col-numero" title="Efectivo contado − (apertura + efectivo neto del día)">Diferencia</th><th>Observación</th>
              </tr>
            </thead>
            <tbody>
              {m.dias.length === 0 && <tr><td colSpan={15} className="vacio">Sin días para mostrar.</td></tr>}
              {m.dias.map((d) => <FilaDia key={d.fecha} d={d} onVerDia={onVerDia} />)}
            </tbody>
            {m.dias.length > 0 && (
              <tfoot>
                <tr className="fila-total">
                  <td>Totales</td>
                  <td />
                  <td className="col-numero">{pesos(m.totales.ventas_efectivo)}</td>
                  <td className="col-numero">{pesos(m.totales.ventas_banco)}</td>
                  <td className="col-numero">{pesos(m.totales.ventas_datafono)}</td>
                  <td className="col-numero">{pesos(m.totales.ventas_billetera)}</td>
                  <td className="col-numero">{pesos(m.totales.ventas_credito)}</td>
                  <td className="col-numero">{pesos(m.totales.otros_ingresos)}</td>
                  <td className="col-numero">{pesos(m.totales.total_ingreso_dia)}</td>
                  <td className="col-numero">{pesos(m.totales.egresos)}</td>
                  <td className="col-numero">{pesos(m.totales.neto_acumulado_mes)}</td>
                  <td className="col-numero">{pesos(m.totales.meta)}</td>
                  <td className="col-numero">{pesos(m.totales.faltante_meta)}</td>
                  <td className="col-numero">{signo(m.totales.diferencia)}</td>
                  <td />
                </tr>
              </tfoot>
            )}
          </table>
        </div>
      )}
      <p className="ayuda">Los días sin cierre se calculan en vivo con los movimientos registrados. Toque un día para ver su detalle.</p>
    </section>
  )
}

function FilaDia({ d, onVerDia }: { d: FilaMesCaja; onVerDia: (fecha: string) => void }) {
  return (
    <tr className={d.cerrado ? undefined : 'sin-cerrar'}>
      <td>
        <button type="button" className="enlace" onClick={() => onVerDia(d.fecha)}>{etiquetaDia(d.fecha)}</button>
        {!d.cerrado && <span className="chip abierta">sin cerrar</span>}
      </td>
      <td className="col-numero">{d.apertura !== null ? pesos(d.apertura) : '—'}</td>
      <td className="col-numero">{pesos(d.ventas_efectivo)}</td>
      <td className="col-numero">{pesos(d.ventas_banco)}</td>
      <td className="col-numero">{pesos(d.ventas_datafono)}</td>
      <td className="col-numero">{pesos(d.ventas_billetera)}</td>
      <td className="col-numero">{pesos(d.ventas_credito)}</td>
      <td className="col-numero">{pesos(d.otros_ingresos)}</td>
      <td className="col-numero"><strong>{pesos(d.total_ingreso_dia)}</strong></td>
      <td className="col-numero">{pesos(d.egresos)}</td>
      <td className="col-numero">{pesos(d.neto_acumulado_mes)}</td>
      <td className="col-numero">{pesos(d.meta)}</td>
      <td className={Number(d.faltante_meta) > 0 ? 'col-numero antes' : 'col-numero despues'}>{pesos(d.faltante_meta)}</td>
      <td className={d.diferencia !== null && Number(d.diferencia) !== 0 ? 'col-numero antes' : 'col-numero'}>{signo(d.diferencia)}</td>
      <td>{d.observacion ?? ''}</td>
    </tr>
  )
}
