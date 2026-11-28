import { useState, type ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api } from '../../api/cliente'
import { infoPagina, type FilaCompra, type FilaRemisionPendiente, type FilaVentaCredito, type Opcion, type Pagina, type PaginaInforme } from '../../api/tipos'
import BarraFiltros, { CampoFiltro, TextoResultados, VacioFiltros } from '../../componentes/BarraFiltros'
import ControlSegmentado from '../../componentes/ControlSegmentado'
import Paginacion from '../../componentes/Paginacion'
import SelectorProveedor from '../../componentes/SelectorProveedor'
import { fechaCorta } from '../../componentes/fechas'
import { pesos } from '../../componentes/numeros'
import { useDebounce } from '../../hooks/useDebounce'
import { useFiltrosUrl } from '../../hooks/useFiltrosUrl'
import { rangoAtajo } from '../../utilidades/atajosPeriodo'
import '../../componentes/cartera.css'

/** Filtros propios de cada informe en su valor inicial (el informe y el período no se incluyen). */
const FILTROS_VACIOS = { proveedor: '', cliente: '', cliente_nombre: '', forma_pago: '', tipo: '', estado: 'vigentes', antiguedad: '', q: '' }

type Informe = 'compras' | 'ventas-credito' | 'remisiones-pendientes'

const RUTA: Record<Informe, string> = {
  compras: '/reportes/compras',
  'ventas-credito': '/reportes/ventas-credito',
  'remisiones-pendientes': '/reportes/remisiones-pendientes',
}

/**
 * RF-18 (1, 2 y 6) y S-89: compras, ventas a crédito y remisiones pendientes de facturar, para el
 * análisis mensual. Solo en pantalla. Filtros en el servidor y en la URL: período (atajos), proveedor,
 * forma de pago, tipo y número en compras; cliente, tipo, estado y número en ventas a crédito;
 * proveedor y antigüedad en remisiones. Los totales cubren todas las filas filtradas.
 */
export default function Informes() {
  const mes = rangoAtajo('este_mes')
  const { filtros: f, fijar, pagina, irA, activos } = useFiltrosUrl({
    informe: 'compras', desde: mes.desde, hasta: mes.hasta, ...FILTROS_VACIOS,
  })
  const informe = f.informe as Informe
  const params: Record<string, string | undefined> = informe === 'compras'
    ? { desde: f.desde, hasta: f.hasta, proveedor_id: f.proveedor || undefined, forma_pago: f.forma_pago || undefined, tipo: f.tipo || undefined, q: f.q || undefined }
    : informe === 'ventas-credito'
      ? { desde: f.desde, hasta: f.hasta, cliente_id: f.cliente || undefined, tipo: f.tipo || undefined, estado: f.estado === 'vigentes' ? undefined : f.estado, q: f.q || undefined }
      : { proveedor_id: f.proveedor || undefined, antiguedad: f.antiguedad || undefined }

  const { data, isFetching } = useQuery({
    queryKey: ['informe', informe, params, pagina],
    queryFn: async () => (await api.get<PaginaInforme<unknown>>(RUTA[informe], { params: { ...params, page: pagina, por_pagina: 50 } })).data,
    // Solo se conservan los datos previos del mismo informe: con otro, la forma de las filas cambia.
    placeholderData: (previo, consultaPrevia) => (consultaPrevia?.queryKey[1] === informe ? previo : undefined),
  })
  const info = data ? infoPagina(data) : null
  // El informe no cuenta como filtro activo.
  const filtrosActivos = activos - (informe === 'compras' ? 0 : 1)
  // Conserva el informe elegido (un solo cambio de la URL).
  const limpiarFiltros = () => fijar({ desde: mes.desde, hasta: mes.hasta, ...FILTROS_VACIOS })
  const periodo = informe !== 'remisiones-pendientes'
    ? { desde: f.desde, hasta: f.hasta, onCambio: (desde: string, hasta: string) => fijar({ desde, hasta }) }
    : undefined

  return (
    <div className="pila">
      <div className="pagina-encabezado">
        <h1>Informes</h1>
        <p>Por defecto, el mes en curso. Los totales cubren todas las filas filtradas, no solo la página.</p>
      </div>
      <section className="tarjeta-pagina">
        <ControlSegmentado<Informe>
          id="informe"
          etiqueta="Informe"
          valor={informe}
          onCambio={(v) => fijar({ informe: v, ...FILTROS_VACIOS })}
          opciones={[
            { valor: 'compras', texto: 'Compras' },
            { valor: 'ventas-credito', texto: 'Ventas a crédito' },
            { valor: 'remisiones-pendientes', texto: 'Remisiones sin facturar' },
          ]}
        />
        <BarraFiltros
          busqueda={informe !== 'remisiones-pendientes' ? { valor: f.q, onCambio: (q) => fijar({ q }), placeholder: informe === 'compras' ? 'Número del documento' : 'Número de factura o de venta' } : undefined}
          periodo={periodo}
          activos={Math.max(0, filtrosActivos)}
          onLimpiar={limpiarFiltros}
        >
          {informe !== 'ventas-credito' && (
            <CampoFiltro etiqueta="Proveedor">
              <SelectorProveedor className="filtro" aria-label="Proveedor" value={f.proveedor} onChange={(proveedor) => fijar({ proveedor })} opcionTodos="Todos los proveedores" />
            </CampoFiltro>
          )}
          {informe === 'compras' && (
            <CampoFiltro etiqueta="Forma de pago">
              <select value={f.forma_pago} onChange={(e) => fijar({ forma_pago: e.target.value })}>
                <option value="">Todas</option>
                <option value="credito">Crédito</option>
                <option value="contado">Contado</option>
              </select>
            </CampoFiltro>
          )}
          {informe !== 'remisiones-pendientes' && (
            <CampoFiltro etiqueta="Tipo de documento">
              <select value={f.tipo} onChange={(e) => fijar({ tipo: e.target.value })}>
                <option value="">Todos</option>
                <option value="factura">Factura</option>
                <option value="remision">Remisión</option>
              </select>
            </CampoFiltro>
          )}
          {informe === 'ventas-credito' && (
            <>
              <FiltroCliente id={f.cliente} nombre={f.cliente_nombre} onElegir={(c) => fijar({ cliente: c ? String(c.id) : '', cliente_nombre: c?.nombre ?? '' })} />
              <CampoFiltro etiqueta="Estado">
                <select value={f.estado} onChange={(e) => fijar({ estado: e.target.value })}>
                  <option value="vigentes">Vigentes</option>
                  <option value="anuladas">Anuladas</option>
                  <option value="todas">Todas</option>
                </select>
              </CampoFiltro>
            </>
          )}
          {informe === 'remisiones-pendientes' && (
            <CampoFiltro etiqueta="Antigüedad">
              <select value={f.antiguedad} onChange={(e) => fijar({ antiguedad: e.target.value })}>
                <option value="">Cualquiera</option>
                <option value="mas_de_30">Más de 30 días</option>
                <option value="mas_de_60">Más de 60 días</option>
                <option value="mas_de_90">Más de 90 días</option>
              </select>
            </CampoFiltro>
          )}
        </BarraFiltros>
        {info && <TextoResultados mostrando={(data as Pagina<unknown>).data.length} total={info.total} />}
        <div className="tabla-desplazable" aria-busy={isFetching}>
          {!data && <div className="cargando">Cargando…</div>}
          {data && informe === 'compras' && <TablaCompras datos={data as PaginaInforme<FilaCompra>} filtrado={filtrosActivos > 0} onLimpiar={limpiarFiltros} />}
          {data && informe === 'ventas-credito' && <TablaVentas datos={data as PaginaInforme<FilaVentaCredito>} filtrado={filtrosActivos > 0} onLimpiar={limpiarFiltros} />}
          {data && informe === 'remisiones-pendientes' && <TablaRemisiones datos={data as PaginaInforme<FilaRemisionPendiente>} filtrado={filtrosActivos > 0} onLimpiar={limpiarFiltros} />}
        </div>
        <Paginacion pagina={data} onCambiar={irA} />
      </section>
    </div>
  )
}

/** S-89: cliente del informe de ventas, buscado desde 2 caracteres (como S-72), sin crear. */
function FiltroCliente({ id, nombre, onElegir }: { id: string; nombre: string; onElegir: (c: Opcion | null) => void }) {
  const [texto, setTexto] = useState('')
  const termino = useDebounce(texto.trim(), 250)
  const { data } = useQuery({
    queryKey: ['clientes', 'buscar', termino],
    // La búsqueda rápida (S-72) responde un arreglo; el listado paginado, { data }.
    queryFn: async () => {
      const r = (await api.get<Opcion[] | { data: Opcion[] }>('/clientes', { params: { q: termino, limite: 8 } })).data
      return Array.isArray(r) ? r : r.data
    },
    enabled: termino.length >= 2 && !id,
  })
  if (id) {
    return (
      <CampoFiltro etiqueta="Cliente">
        <span className="filtro-elegido">
          <strong>{nombre || `#${id}`}</strong>
          <button type="button" className="enlace" onClick={() => onElegir(null)} aria-label={`Quitar el filtro del cliente ${nombre}`}>Quitar</button>
        </span>
      </CampoFiltro>
    )
  }
  return (
    <label className="barra-filtros-campo filtro-cliente">
      <span className="etiqueta-campo">Cliente</span>
      <input type="search" autoComplete="off" placeholder="Escriba 2 letras o más" value={texto} onChange={(e) => setTexto(e.target.value)} aria-describedby="filtro-cliente-lista" />
      {termino.length >= 2 && (
        <ul id="filtro-cliente-lista" className="filtro-cliente-lista" aria-label="Clientes encontrados">
          {(data ?? []).map((c) => (
            <li key={c.id}><button type="button" onClick={() => { setTexto(''); onElegir(c) }}>{c.nombre}</button></li>
          ))}
          {data && data.length === 0 && <li className="ayuda">Ningún cliente coincide.</li>}
        </ul>
      )}
    </label>
  )
}

function Tabla({ cabeza, filas, total, vacio, columnas, filtrado, onLimpiar }: { cabeza: ReactNode; filas: ReactNode[]; total?: ReactNode; vacio: string; columnas: number; filtrado: boolean; onLimpiar: () => void }) {
  return (
    <table>
      <thead><tr>{cabeza}</tr></thead>
      <tbody>
        {filas}
        {filas.length === 0 && <tr><td colSpan={columnas} className="vacio"><VacioFiltros hayFiltros={filtrado} sinFiltros={vacio} onLimpiar={onLimpiar} /></td></tr>}
        {filas.length > 0 && total}
      </tbody>
    </table>
  )
}

function TablaCompras({ datos, filtrado, onLimpiar }: { datos: PaginaInforme<FilaCompra>; filtrado: boolean; onLimpiar: () => void }) {
  const t = datos.totales
  return (
    <Tabla
      filtrado={filtrado}
      onLimpiar={onLimpiar}
      columnas={8}
      vacio="No hay compras en el período."
      cabeza={<><th>Fecha</th><th>Documento</th><th>Proveedor</th><th>Pago</th><th className="cifra">Subtotal</th><th className="cifra">Descuento</th><th className="cifra">IVA</th><th className="cifra">Total</th></>}
      filas={datos.data.map((f) => (
        <tr key={f.id}>
          <td>{fechaCorta(f.fecha)}</td>
          <td>{f.tipo === 'factura' ? 'Factura' : 'Remisión'} {f.numero_documento}</td>
          <td>{f.proveedor.nombre}</td>
          <td>{f.forma_pago === 'credito' ? <span className="chip credito">Crédito</span> : 'Contado'}</td>
          <td className="cifra">{pesos(f.subtotal)}</td>
          <td className="cifra">{pesos(f.descuento)}</td>
          <td className="cifra">{pesos(f.iva)}</td>
          <td className="cifra"><strong>{pesos(f.total)}</strong></td>
        </tr>
      ))}
      total={<tr className="fila-total"><td colSpan={4}>{filtrado ? 'Totales de lo filtrado' : 'Totales'}</td><td className="cifra">{pesos(t.subtotal)}</td><td className="cifra">{pesos(t.descuento)}</td><td className="cifra">{pesos(t.iva)}</td><td className="cifra">{pesos(t.total)}</td></tr>}
    />
  )
}

function TablaVentas({ datos, filtrado, onLimpiar }: { datos: PaginaInforme<FilaVentaCredito>; filtrado: boolean; onLimpiar: () => void }) {
  const t = datos.totales
  return (
    <Tabla
      filtrado={filtrado}
      onLimpiar={onLimpiar}
      columnas={7}
      vacio="No hay ventas a crédito en el período."
      cabeza={<><th>Venta</th><th>Fecha</th><th>Vence</th><th>Cliente</th><th className="cifra">Base</th><th className="cifra">IVA</th><th className="cifra">Total</th></>}
      filas={datos.data.map((f) => (
        <tr key={f.id}>
          <td>
            #{f.id}{f.documento ? <><br /><small>{f.documento}</small></> : f.factura_contapyme && <><br /><small>FE {f.factura_contapyme}</small></>}
            {f.anulada && <> <span className="chip">Anulada</span></>}
          </td>
          <td>{fechaCorta(f.fecha)}</td>
          <td>{f.vence_el ? fechaCorta(f.vence_el) : '—'}</td>
          <td>{f.cliente.nombre}</td>
          <td className="cifra">{f.base === null ? '—' : pesos(f.base)}</td>
          <td className="cifra">{f.iva === null ? '—' : pesos(f.iva)}</td>
          <td className="cifra"><strong>{pesos(f.total)}</strong></td>
        </tr>
      ))}
      total={<tr className="fila-total"><td colSpan={4}>{filtrado ? 'Totales de lo filtrado' : 'Totales'}</td><td className="cifra">{pesos(t.base)}</td><td className="cifra">{pesos(t.iva)}</td><td className="cifra">{pesos(t.total)}</td></tr>}
    />
  )
}

function TablaRemisiones({ datos, filtrado, onLimpiar }: { datos: PaginaInforme<FilaRemisionPendiente>; filtrado: boolean; onLimpiar: () => void }) {
  return (
    <Tabla
      filtrado={filtrado}
      onLimpiar={onLimpiar}
      columnas={6}
      vacio="Todas las remisiones están facturadas."
      cabeza={<><th>Remisión</th><th>Proveedor</th><th>Fecha</th><th className="cifra">Días</th><th>Factura en borrador</th><th className="cifra">Valor provisional</th></>}
      filas={datos.data.map((f) => (
        <tr key={f.id}>
          <td>{f.numero_documento}</td>
          <td>{f.proveedor.nombre}</td>
          <td>{fechaCorta(f.fecha)}</td>
          <td className="cifra">{f.dias}</td>
          <td>{f.factura_borrador?.numero_documento ?? '—'}</td>
          <td className="cifra">{f.total === null ? '—' : pesos(f.total)}</td>
        </tr>
      ))}
      total={<tr className="fila-total"><td colSpan={5}>{filtrado ? 'Total de lo filtrado' : 'Total'}</td><td className="cifra">{pesos(datos.totales.total)}</td></tr>}
    />
  )
}
