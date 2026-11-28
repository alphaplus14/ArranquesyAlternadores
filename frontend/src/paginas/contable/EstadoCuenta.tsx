import { useQuery } from '@tanstack/react-query'
import { api } from '../../api/cliente'
import { infoPagina, type EstadoCuentaInforme as Estado } from '../../api/tipos'
import BarraFiltros, {CampoFiltro, TextoResultados, VacioFiltros } from '../../componentes/BarraFiltros'
import { rangoAtajo } from '../../utilidades/atajosPeriodo'
import Paginacion from '../../componentes/Paginacion'
import { fechaCorta } from '../../componentes/fechas'
import { pesos } from '../../componentes/numeros'
import { useFiltrosUrl } from '../../hooks/useFiltrosUrl'
import '../../componentes/cartera.css'

/**
 * RF-18 (5) y S-89: estado de cuenta de un cliente o proveedor por período, con saldo anterior y
 * corrido. El tipo de movimiento y la búsqueda solo ocultan filas: el saldo corrido y los totales del
 * período incluyen todos los movimientos. Filtros en la URL con prefijo `ec_` (convive con los de la
 * cuenta). Por defecto, los últimos 90 días.
 */
export default function EstadoCuenta({ ruta }: { ruta: string }) {
  const porDefecto = rangoAtajo('ultimos_90')
  const { filtros: f, fijar, pagina, irA, activos, limpiar } = useFiltrosUrl({ desde: porDefecto.desde, hasta: porDefecto.hasta, tipo: 'todos', q: '' }, 'ec_')
  const params = { desde: f.desde, hasta: f.hasta, tipo: f.tipo === 'todos' ? undefined : f.tipo, q: f.q || undefined }

  const { data, isFetching } = useQuery({
    queryKey: ['estado-cuenta', ruta, params, pagina],
    queryFn: async () => (await api.get<Estado>(ruta, { params: { ...params, page: pagina, por_pagina: 50 } })).data,
    // Solo los datos previos de la misma cuenta.
    placeholderData: (previo, consultaPrevia) => (consultaPrevia?.queryKey[1] === ruta ? previo : undefined),
  })
  const info = data ? infoPagina(data) : null

  return (
    <section className="tarjeta-pagina">
      <BarraFiltros
        busqueda={{ valor: f.q, onCambio: (q) => fijar({ q }), placeholder: 'Detalle o número de documento' }}
        periodo={{ desde: f.desde, hasta: f.hasta, onCambio: (desde, hasta) => fijar({ desde, hasta }) }}
        activos={activos}
        onLimpiar={limpiar}
      >
        <CampoFiltro etiqueta="Tipo de movimiento">
          <select value={f.tipo} onChange={(e) => fijar({ tipo: e.target.value })}>
            <option value="todos">Todos</option>
            <option value="cargos">Solo cargos</option>
            <option value="abonos">Solo abonos o pagos</option>
          </select>
        </CampoFiltro>
      </BarraFiltros>
      {data?.filtrado && <p className="aviso info" role="note">Filtro activo: el saldo mostrado incluye todos los movimientos.</p>}
      {info && <TextoResultados mostrando={info.total} total={data?.movimientos_periodo ?? info.total} de="movimientos del período" />}
      <div className="tabla-desplazable">
        <table aria-busy={isFetching}>
          <thead>
            <tr><th>Fecha</th><th>Detalle</th><th>Vence</th><th className="cifra">Cargo</th><th className="cifra">Abono o pago</th><th className="cifra">Saldo</th></tr>
          </thead>
          <tbody>
            {data && (
              <tr className="fila-anterior"><td>{fechaCorta(data.desde)}</td><td>Saldo anterior</td><td /><td /><td /><td className="cifra">{pesos(data.saldo_anterior)}</td></tr>
            )}
            {data?.data.map((m, i) => (
              <tr key={`${m.tipo}-${m.venta_id ?? m.ingreso_id ?? m.abono_id ?? m.pago_id}-${i}`}>
                <td>{fechaCorta(m.fecha)}</td>
                <td>{m.detalle}</td>
                <td>{m.vence_el ? fechaCorta(m.vence_el) : ''}</td>
                <td className="cifra">{Number(m.cargo) ? pesos(m.cargo) : ''}</td>
                <td className="cifra">{Number(m.abono) ? pesos(m.abono) : ''}</td>
                <td className="cifra">{pesos(m.saldo)}</td>
              </tr>
            ))}
            {data && data.data.length === 0 && (
              <tr><td colSpan={6} className="vacio"><VacioFiltros hayFiltros={!!data.filtrado} sinFiltros="Sin movimientos en el período." onLimpiar={limpiar} /></td></tr>
            )}
            {data && (
              <tr className="fila-total">
                <td colSpan={3}>Totales del período (todos los movimientos)</td>
                <td className="cifra">{pesos(data.totales.cargo)}</td>
                <td className="cifra">{pesos(data.totales.abono)}</td>
                <td className="cifra">{pesos(data.totales.saldo_final)}</td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
      <Paginacion pagina={data} onCambiar={irA} />
    </section>
  )
}
