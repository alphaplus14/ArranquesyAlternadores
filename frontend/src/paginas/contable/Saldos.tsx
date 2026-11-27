import { useEffect } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useLocation } from 'react-router-dom'
import { api } from '../../api/cliente'
import { NOMBRE_RANGO, type FilaCartera, type PaginaInforme, type RangoAntiguedad, type TotalesCartera } from '../../api/tipos'
import BarraFiltros, { CampoFiltro, TextoResultados, VacioFiltros } from '../../componentes/BarraFiltros'
import BotonFila from '../../componentes/BotonFila'
import ControlSegmentado from '../../componentes/ControlSegmentado'
import Medida from '../../componentes/Medida'
import Paginacion from '../../componentes/Paginacion'
import { hoy } from '../../componentes/fechas'
import { infoPagina } from '../../api/tipos'
import { pesos } from '../../componentes/numeros'
import { useFiltrosUrl } from '../../hooks/useFiltrosUrl'
import { guardarVolver } from './cartera'
import '../../componentes/cartera.css'

const RANGOS = Object.keys(NOMBRE_RANGO) as RangoAntiguedad[]

const TEXTOS = {
  proveedores: {
    titulo: 'Cuentas por pagar',
    ayuda: 'Saldo por proveedor a la fecha de corte, con antigüedad. Use «Ver cuenta» para ver las facturas y registrar pagos.',
    ruta: '/reportes/cuentas-por-pagar',
    clave: 'cuentas-por-pagar',
    titular: 'Proveedor',
    buscar: 'Buscar proveedor o NIT',
    detalle: '/contable/proveedores',
  },
  clientes: {
    titulo: 'Cartera de clientes',
    ayuda: 'Saldo por cliente de las ventas a crédito del mostrador, a la fecha de corte. Use «Ver cuenta» para ver su resumen, facturas, abonos y estado de cuenta.',
    ruta: '/reportes/cartera-clientes',
    clave: 'cartera-clientes',
    titular: 'Cliente',
    buscar: 'Buscar cliente o documento',
    detalle: '/contable/clientes',
  },
} as const

type Estado = 'todos' | 'con_vencido' | 'al_dia'

/**
 * RF-18 (3 y 4) y S-88: cartera de clientes o cuentas por pagar, con antigüedad, solo en pantalla.
 * Filtros en el servidor (en la URL): texto, corte, estado, antigüedad, orden y (clientes) saldo a
 * favor. Tarjetas y totales son de lo filtrado.
 */
export default function Saldos({ de }: { de: keyof typeof TEXTOS }) {
  const t = TEXTOS[de]
  const { filtros: f, fijar, pagina, irA, activos, limpiar } = useFiltrosUrl({
    q: '', corte: '', estado: 'todos', antiguedad: 'cualquiera', orden: 'nombre', a_favor: '',
  })
  const corte = f.corte || hoy()
  const ubicacion = useLocation()
  const params = {
    q: f.q || undefined,
    corte,
    estado: f.estado === 'todos' ? undefined : f.estado,
    antiguedad: f.antiguedad === 'cualquiera' ? undefined : f.antiguedad,
    orden: f.orden === 'nombre' ? undefined : f.orden,
    a_favor: de === 'clientes' && f.a_favor ? 1 : undefined,
  }

  const { data, isFetching } = useQuery({
    queryKey: [t.clave, params, pagina],
    queryFn: async () => (await api.get<PaginaInforme<FilaCartera, TotalesCartera>>(t.ruta, { params: { ...params, page: pagina, por_pagina: 50 } })).data,
    // Las dos rutas usan esta pantalla: solo se conservan los datos previos de la misma.
    placeholderData: (previo, consultaPrevia) => (consultaPrevia?.queryKey[0] === t.clave ? previo : undefined),
  })
  const totales = data?.totales
  const info = data ? infoPagina(data) : null
  const pctVencido = totales && Number(totales.saldo) > 0 ? Math.round((Number(totales.vencido) / Number(totales.saldo)) * 100) : 0
  // La miga de la cuenta vuelve a esta lista con sus filtros.
  useEffect(() => { guardarVolver(t.detalle, `${ubicacion.pathname}${ubicacion.search}`) }, [t.detalle, ubicacion.pathname, ubicacion.search])

  return (
    <div className="pila">
      <div className="pagina-encabezado">
        <h1>{t.titulo}</h1>
        <p>{t.ayuda}</p>
      </div>

      <div className="medidas">
        <Medida etiqueta={activos > 0 ? 'Saldo (lo filtrado)' : 'Saldo total'} valor={totales ? pesos(totales.saldo) : '—'} tono="azul" icono="billetera" />
        <Medida etiqueta="Vencido" valor={totales ? pesos(totales.vencido) : '—'} pie={totales ? `${pctVencido} % del saldo` : undefined} tono="rojo" icono="agotados" />
        <Medida etiqueta="Titulares con saldo" valor={totales?.con_saldo ?? '—'} tono="verde" icono="persona" />
      </div>

      <section className="tarjeta-pagina">
        <BarraFiltros
          busqueda={{ valor: f.q, onCambio: (q) => fijar({ q }), placeholder: t.buscar }}
          activos={activos}
          onLimpiar={limpiar}
        >
          <CampoFiltro etiqueta="Corte">
            <input type="date" max={hoy()} value={corte} onChange={(e) => fijar({ corte: e.target.value === hoy() ? '' : e.target.value })} />
          </CampoFiltro>
          <ControlSegmentado<Estado>
            id={`${t.clave}-estado`}
            etiqueta="Estado"
            valor={f.estado as Estado}
            onCambio={(estado) => fijar({ estado })}
            opciones={[{ valor: 'todos', texto: 'Todos' }, { valor: 'con_vencido', texto: 'Con vencido' }, { valor: 'al_dia', texto: 'Al día' }]}
          />
          <CampoFiltro etiqueta="Antigüedad">
            <select value={f.antiguedad} onChange={(e) => fijar({ antiguedad: e.target.value })}>
              <option value="cualquiera">Cualquiera</option>
              <option value="mas_de_30">Más de 30 días</option>
              <option value="mas_de_60">Más de 60 días</option>
              <option value="mas_de_90">Más de 90 días</option>
            </select>
          </CampoFiltro>
          <CampoFiltro etiqueta="Orden">
            <select value={f.orden} onChange={(e) => fijar({ orden: e.target.value })}>
              <option value="nombre">Nombre</option>
              <option value="saldo_desc">Mayor saldo</option>
              <option value="vencido_desc">Mayor vencido</option>
            </select>
          </CampoFiltro>
          {de === 'clientes' && (
            <label className="check barra-filtros-check">
              <input type="checkbox" checked={!!f.a_favor} onChange={(e) => fijar({ a_favor: e.target.checked ? '1' : '' })} /> Con saldo a favor
            </label>
          )}
        </BarraFiltros>

        {info && <TextoResultados mostrando={data!.data.length} total={info.total} de={de === 'clientes' ? 'clientes' : 'proveedores'} />}
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
              {data?.data.map((fila) => {
                const titular = fila.proveedor ?? fila.cliente
                return (
                  <tr key={titular?.id}>
                    <td>
                      {titular?.nombre}
                      {Number(fila.a_favor) > 0 && <> <span className="chip">A favor {pesos(fila.a_favor)}</span></>}
                    </td>
                    <td className="cifra"><strong>{pesos(fila.saldo)}</strong></td>
                    <td className="cifra">{pesos(fila.vencido)}</td>
                    {RANGOS.map((r) => <td key={r} className="cifra">{pesos(fila.rangos[r])}</td>)}
                    <td className="boton-fila-columna">
                      {titular && <BotonFila variante="ver" to={`${t.detalle}/${titular.id}`} etiqueta="Ver cuenta" descripcion={`Ver cuenta de ${titular.nombre}`} />}
                    </td>
                  </tr>
                )
              })}
              {data && data.data.length === 0 && (
                <tr><td colSpan={4 + RANGOS.length} className="vacio">
                  <VacioFiltros hayFiltros={activos > 0} sinFiltros="Nadie tiene saldo a esta fecha." onLimpiar={limpiar} />
                </td></tr>
              )}
              {data && data.data.length > 0 && totales && (
                <tr className="fila-total">
                  <td>{activos > 0 ? 'Totales de lo filtrado' : 'Totales'}</td>
                  <td className="cifra">{pesos(totales.saldo)}</td>
                  <td className="cifra">{pesos(totales.vencido)}</td>
                  {RANGOS.map((r) => <td key={r} className="cifra">{pesos(totales.rangos[r])}</td>)}
                  <td className="boton-fila-columna" />
                </tr>
              )}
            </tbody>
          </table>
        </div>
        <Paginacion pagina={data} onCambiar={irA} />
      </section>
    </div>
  )
}
