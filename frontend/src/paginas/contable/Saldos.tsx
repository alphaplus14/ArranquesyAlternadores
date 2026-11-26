import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api } from '../../api/cliente'
import { NOMBRE_RANGO, type FilaCartera, type PaginaInforme, type RangoAntiguedad, type TotalesCartera } from '../../api/tipos'
import BotonFila from '../../componentes/BotonFila'
import Paginacion from '../../componentes/Paginacion'
import { hoy } from '../../componentes/fechas'
import { pesos } from '../../componentes/numeros'
import '../../componentes/cartera.css'

const RANGOS = Object.keys(NOMBRE_RANGO) as RangoAntiguedad[]

const TEXTOS = {
  proveedores: {
    titulo: 'Cuentas por pagar',
    ayuda: 'Saldo por proveedor a la fecha de corte, con antigüedad. Use «Ver cuenta» para ver las facturas y registrar pagos.',
    ruta: '/reportes/cuentas-por-pagar',
    clave: 'cuentas-por-pagar',
    titular: 'Proveedor',
    detalle: '/contable/proveedores',
  },
  clientes: {
    titulo: 'Cartera de clientes',
    ayuda: 'Saldo por cliente de las ventas a crédito del mostrador, a la fecha de corte. Use «Ver cuenta» para ver su estado de cuenta.',
    ruta: '/reportes/cartera-clientes',
    clave: 'cartera-clientes',
    titular: 'Cliente',
    detalle: '/contable/clientes',
  },
} as const

/** RF-18 (3 y 4): cartera de clientes o cuentas por pagar, con antigüedad, solo en pantalla. */
export default function Saldos({ de }: { de: keyof typeof TEXTOS }) {
  const t = TEXTOS[de]
  const [corte, setCorte] = useState(hoy())
  const [pagina, setPagina] = useState(1)

  const { data, isFetching } = useQuery({
    queryKey: [t.clave, corte, pagina],
    queryFn: async () => (await api.get<PaginaInforme<FilaCartera, TotalesCartera>>(t.ruta, { params: { corte, page: pagina, por_pagina: 50 } })).data,
    // Las dos rutas usan esta misma pantalla: al pasar de proveedores a clientes no se muestran las
    // filas del otro (ni sus enlaces); solo se conservan al cambiar de página o de corte.
    placeholderData: (previo, consultaPrevia) => (consultaPrevia?.queryKey[0] === t.clave ? previo : undefined),
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
        </div>
        <div className="tabla-desplazable">
          <table aria-busy={isFetching}>
            <thead>
              <tr>
                <th>{t.titular}</th>
                <th className="cifra">Saldo</th>
                <th className="cifra">Vencido</th>
                {RANGOS.map((r) => <th key={r} className="cifra">{r === 'al_dia' ? NOMBRE_RANGO[r] : `${NOMBRE_RANGO[r]} d`}</th>)}
                <th className="boton-fila-columna">Acciones</th>
              </tr>
            </thead>
            <tbody>
              {data?.data.map((f) => {
                const titular = f.proveedor ?? f.cliente
                return (
                  <tr key={titular?.id}>
                    <td>
                      {titular?.nombre}
                      {Number(f.a_favor) > 0 && <> <span className="chip">A favor {pesos(f.a_favor)}</span></>}
                    </td>
                    <td className="cifra"><strong>{pesos(f.saldo)}</strong></td>
                    <td className="cifra">{pesos(f.vencido)}</td>
                    {RANGOS.map((r) => <td key={r} className="cifra">{pesos(f.rangos[r])}</td>)}
                    <td className="boton-fila-columna">
                      {titular && <BotonFila variante="ver" to={`${t.detalle}/${titular.id}`} etiqueta="Ver cuenta" descripcion={`Ver cuenta de ${titular.nombre}`} />}
                    </td>
                  </tr>
                )
              })}
              {data && data.data.length === 0 && <tr><td colSpan={4 + RANGOS.length} className="vacio">Nadie tiene saldo a esta fecha.</td></tr>}
              {data && data.data.length > 0 && (
                <tr className="fila-total">
                  <td>Totales</td>
                  <td className="cifra">{pesos(data.totales.saldo)}</td>
                  <td className="cifra">{pesos(data.totales.vencido)}</td>
                  {RANGOS.map((r) => <td key={r} className="cifra">{pesos(data.totales.rangos[r])}</td>)}
                  <td className="boton-fila-columna" />
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
