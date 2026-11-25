import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api } from '../../api/cliente'
import type { EstadoCuentaInforme as Estado } from '../../api/tipos'
import BotonExcel from '../../componentes/BotonExcel'
import Paginacion from '../../componentes/Paginacion'
import { fechaCorta, hoy, sumarDias } from '../../componentes/fechas'
import { pesos } from '../../componentes/numeros'
import '../../componentes/cartera.css'

/** RF-18 (5): estado de cuenta de un cliente o proveedor por período, con saldo anterior y corrido. */
export default function EstadoCuenta({ ruta }: { ruta: string }) {
  const [desde, setDesde] = useState(sumarDias(hoy(), -90))
  const [hasta, setHasta] = useState(hoy())
  const [pagina, setPagina] = useState(1)

  const { data, isFetching } = useQuery({
    queryKey: ['estado-cuenta', ruta, desde, hasta, pagina],
    queryFn: async () => (await api.get<Estado>(ruta, { params: { desde, hasta, page: pagina, por_pagina: 50 } })).data,
    placeholderData: (previo) => previo,
  })

  return (
    <section className="tarjeta-pagina">
      <div className="fila-titulo"><h2>Estado de cuenta</h2></div>
      <div className="filtros-informe">
        <label>Desde<input type="date" max={hasta} value={desde} onChange={(e) => { setDesde(e.target.value); setPagina(1) }} /></label>
        <label>Hasta<input type="date" min={desde} max={hoy()} value={hasta} onChange={(e) => { setHasta(e.target.value); setPagina(1) }} /></label>
        <BotonExcel ruta={ruta} params={{ desde, hasta }} />
      </div>
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
            {data && (
              <tr className="fila-total">
                <td colSpan={3}>Totales del período</td>
                <td className="cifra">{pesos(data.totales.cargo)}</td>
                <td className="cifra">{pesos(data.totales.abono)}</td>
                <td className="cifra">{pesos(data.totales.saldo_final)}</td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
      <Paginacion pagina={data} onCambiar={setPagina} />
    </section>
  )
}
