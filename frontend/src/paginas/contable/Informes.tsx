import { useState, type ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api } from '../../api/cliente'
import type { FilaCompra, FilaRemisionPendiente, FilaVentaCredito, PaginaInforme } from '../../api/tipos'
import BotonExcel from '../../componentes/BotonExcel'
import ControlSegmentado from '../../componentes/ControlSegmentado'
import Paginacion from '../../componentes/Paginacion'
import { fechaCorta, hoy } from '../../componentes/fechas'
import { pesos } from '../../componentes/numeros'
import '../../componentes/cartera.css'

type Informe = 'compras' | 'ventas-credito' | 'remisiones-pendientes'

const RUTA: Record<Informe, string> = {
  compras: '/reportes/compras',
  'ventas-credito': '/reportes/ventas-credito',
  'remisiones-pendientes': '/reportes/remisiones-pendientes',
}

const primeroDelMes = () => `${hoy().slice(0, 8)}01`

/**
 * RF-18 (1, 2 y 6): compras, ventas a crédito y remisiones pendientes de facturar, para el
 * análisis mensual. Cada uno se exporta a Excel con los mismos filtros (RF-23).
 */
export default function Informes() {
  const [informe, setInforme] = useState<Informe>('compras')
  const [desde, setDesde] = useState(primeroDelMes())
  const [hasta, setHasta] = useState(hoy())
  const [pagina, setPagina] = useState(1)
  const porPeriodo = informe !== 'remisiones-pendientes'
  const params = porPeriodo ? { desde, hasta } : {}

  const { data, isFetching } = useQuery({
    queryKey: ['informe', informe, params, pagina],
    queryFn: async () => (await api.get<PaginaInforme<unknown>>(RUTA[informe], { params: { ...params, page: pagina, por_pagina: 50 } })).data,
    placeholderData: (previo) => previo,
  })
  const reiniciar = <T,>(fijar: (v: T) => void) => (v: T) => { fijar(v); setPagina(1) }

  return (
    <div className="pila">
      <div className="pagina-encabezado">
        <h1>Informes</h1>
        <p>Por defecto, el mes en curso. Los totales cubren todas las filas, no solo la página.</p>
      </div>
      <section className="tarjeta-pagina">
        <ControlSegmentado<Informe>
          id="informe"
          etiqueta="Informe"
          valor={informe}
          onCambio={reiniciar(setInforme)}
          opciones={[
            { valor: 'compras', texto: 'Compras' },
            { valor: 'ventas-credito', texto: 'Ventas a crédito' },
            { valor: 'remisiones-pendientes', texto: 'Remisiones sin facturar' },
          ]}
        />
        <div className="filtros-informe">
          {porPeriodo && (
            <>
              <label>Desde<input type="date" max={hasta} value={desde} onChange={(e) => reiniciar(setDesde)(e.target.value)} /></label>
              <label>Hasta<input type="date" min={desde} max={hoy()} value={hasta} onChange={(e) => reiniciar(setHasta)(e.target.value)} /></label>
            </>
          )}
          <BotonExcel ruta={RUTA[informe]} params={params} />
        </div>
        <div className="tabla-desplazable" aria-busy={isFetching}>
          {data && informe === 'compras' && <TablaCompras datos={data as PaginaInforme<FilaCompra>} />}
          {data && informe === 'ventas-credito' && <TablaVentas datos={data as PaginaInforme<FilaVentaCredito>} />}
          {data && informe === 'remisiones-pendientes' && <TablaRemisiones datos={data as PaginaInforme<FilaRemisionPendiente>} />}
        </div>
        <Paginacion pagina={data} onCambiar={setPagina} />
      </section>
    </div>
  )
}

function Tabla({ cabeza, filas, total, vacio, columnas }: { cabeza: ReactNode; filas: ReactNode[]; total?: ReactNode; vacio: string; columnas: number }) {
  return (
    <table>
      <thead><tr>{cabeza}</tr></thead>
      <tbody>
        {filas}
        {filas.length === 0 && <tr><td colSpan={columnas} className="vacio">{vacio}</td></tr>}
        {filas.length > 0 && total}
      </tbody>
    </table>
  )
}

function TablaCompras({ datos }: { datos: PaginaInforme<FilaCompra> }) {
  const t = datos.totales
  return (
    <Tabla
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
      total={<tr className="fila-total"><td colSpan={4}>Totales</td><td className="cifra">{pesos(t.subtotal)}</td><td className="cifra">{pesos(t.descuento)}</td><td className="cifra">{pesos(t.iva)}</td><td className="cifra">{pesos(t.total)}</td></tr>}
    />
  )
}

function TablaVentas({ datos }: { datos: PaginaInforme<FilaVentaCredito> }) {
  const t = datos.totales
  return (
    <Tabla
      columnas={7}
      vacio="No hay ventas a crédito en el período."
      cabeza={<><th>Venta</th><th>Fecha</th><th>Vence</th><th>Cliente</th><th className="cifra">Base</th><th className="cifra">IVA</th><th className="cifra">Total</th></>}
      filas={datos.data.map((f) => (
        <tr key={f.id}>
          <td>#{f.id}{f.factura_contapyme && <><br /><small>FE {f.factura_contapyme}</small></>}</td>
          <td>{fechaCorta(f.fecha)}</td>
          <td>{f.vence_el ? fechaCorta(f.vence_el) : '—'}</td>
          <td>{f.cliente.nombre}</td>
          <td className="cifra">{pesos(f.base)}</td>
          <td className="cifra">{pesos(f.iva)}</td>
          <td className="cifra"><strong>{pesos(f.total)}</strong></td>
        </tr>
      ))}
      total={<tr className="fila-total"><td colSpan={4}>Totales</td><td className="cifra">{pesos(t.base)}</td><td className="cifra">{pesos(t.iva)}</td><td className="cifra">{pesos(t.total)}</td></tr>}
    />
  )
}

function TablaRemisiones({ datos }: { datos: PaginaInforme<FilaRemisionPendiente> }) {
  return (
    <Tabla
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
      total={<tr className="fila-total"><td colSpan={5}>Total</td><td className="cifra">{pesos(datos.totales.total)}</td></tr>}
    />
  )
}
