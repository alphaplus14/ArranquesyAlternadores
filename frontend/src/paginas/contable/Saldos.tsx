import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { api } from '../../api/cliente'
import { NOMBRE_RANGO, type FilaCartera, type PaginaInforme, type RangoAntiguedad, type TotalesCartera } from '../../api/tipos'
import BotonExcel from '../../componentes/BotonExcel'
import Paginacion from '../../componentes/Paginacion'
import { hoy } from '../../componentes/fechas'
import { pesos } from '../../componentes/numeros'
import '../../componentes/cartera.css'

const RANGOS = Object.keys(NOMBRE_RANGO) as RangoAntiguedad[]

const TEXTOS = {
  proveedores: {
    titulo: 'Cuentas por pagar',
    ayuda: 'Saldo por proveedor a la fecha de corte, con antigüedad. Abra un proveedor para ver sus facturas y registrar pagos.',
    ruta: '/reportes/cuentas-por-pagar',
    clave: 'cuentas-por-pagar',
    titular: 'Proveedor',
    detalle: '/contable/proveedores',
  },
  clientes: {
    titulo: 'Cartera de clientes',
    ayuda: 'Saldo por cliente de las ventas a crédito del mostrador, a la fecha de corte. Abra un cliente para ver su estado de cuenta.',
    ruta: '/reportes/cartera-clientes',
    clave: 'cartera-clientes',
    titular: 'Cliente',
    detalle: '/contable/clientes',
  },
} as const

/** RF-18 (3 y 4): cartera de clientes o cuentas por pagar, con antigüedad, en pantalla y en Excel (RF-23). */
export default function Saldos({ de }: { de: keyof typeof TEXTOS }) {
  const t = TEXTOS[de]
  const navegar = useNavigate()
  const [corte, setCorte] = useState(hoy())
  const [pagina, setPagina] = useState(1)

  const { data, isFetching } = useQuery({
    queryKey: [t.clave, corte, pagina],
    queryFn: async () => (await api.get<PaginaInforme<FilaCartera, TotalesCartera>>(t.ruta, { params: { corte, page: pagina, por_pagina: 50 } })).data,
    placeholderData: (previo) => previo,
  })

  return (
    <div className="pila">
      <div className="pagina-encabezado">
        <h1>{t.titulo}</h1>
        <p>{t.ayuda}</p>
      </div>
      <section className="tarjeta-pagina">
        <div className="filtros-informe">
          <label>Corte
            <input type="date" max={hoy()} value={corte} onChange={(e) => { setCorte(e.target.value || hoy()); setPagina(1) }} />
          </label>
          <BotonExcel ruta={t.ruta} params={{ corte }} />
        </div>
        <div className="tabla-desplazable">
          <table aria-busy={isFetching}>
            <thead>
              <tr>
                <th>{t.titular}</th>
                <th className="cifra">Saldo</th>
                <th className="cifra">Vencido</th>
                {RANGOS.map((r) => <th key={r} className="cifra">{r === 'al_dia' ? NOMBRE_RANGO[r] : `${NOMBRE_RANGO[r]} d`}</th>)}
              </tr>
            </thead>
            <tbody>
              {data?.data.map((f) => {
                const titular = f.proveedor ?? f.cliente
                return (
                  <tr key={titular?.id}>
                    <td>
                      <button type="button" className="enlace" onClick={() => navegar(`${t.detalle}/${titular?.id}`)}>{titular?.nombre}</button>
                      {Number(f.a_favor) > 0 && <> <span className="chip">A favor {pesos(f.a_favor)}</span></>}
                    </td>
                    <td className="cifra"><strong>{pesos(f.saldo)}</strong></td>
                    <td className="cifra">{pesos(f.vencido)}</td>
                    {RANGOS.map((r) => <td key={r} className="cifra">{pesos(f.rangos[r])}</td>)}
                  </tr>
                )
              })}
              {data && data.data.length === 0 && <tr><td colSpan={3 + RANGOS.length} className="vacio">Nadie tiene saldo a esta fecha.</td></tr>}
              {data && data.data.length > 0 && (
                <tr className="fila-total">
                  <td>Totales</td>
                  <td className="cifra">{pesos(data.totales.saldo)}</td>
                  <td className="cifra">{pesos(data.totales.vencido)}</td>
                  {RANGOS.map((r) => <td key={r} className="cifra">{pesos(data.totales.rangos[r])}</td>)}
                </tr>
              )}
            </tbody>
          </table>
        </div>
        <Paginacion pagina={data} onCambiar={setPagina} />
      </section>
    </div>
  )
}
