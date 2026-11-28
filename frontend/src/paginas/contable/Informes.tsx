import { useState, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { api } from '../../api/cliente'
import {
  infoPagina, type FilaAbonoRecuperado, type FilaClienteRecuperado, type FilaCompra, type FilaRemisionPendiente, type FilaVentaCredito,
  type Opcion, type Pagina, type PaginaInforme, type TotalesRecuperados,
} from '../../api/tipos'
import BotonFila from '../../componentes/BotonFila'
import Medida from '../../componentes/Medida'
import { useCuentasPago } from '../../hooks/useCatalogos'
import { useFilasPorPagina } from '../../preferencias/preferencias'
import BarraFiltros, { CampoFiltro, TextoResultados, VacioFiltros } from '../../componentes/BarraFiltros'
import { rangoAtajo } from '../../utilidades/atajosPeriodo'
import ControlSegmentado from '../../componentes/ControlSegmentado'
import Paginacion from '../../componentes/Paginacion'
import SelectorProveedor from '../../componentes/SelectorProveedor'
import { fechaCorta } from '../../componentes/fechas'
import { pesos } from '../../componentes/numeros'
import { useDebounce } from '../../hooks/useDebounce'
import { useFiltrosUrl } from '../../hooks/useFiltrosUrl'
import '../../componentes/cartera.css'

type Informe = 'compras' | 'ventas-credito' | 'remisiones-pendientes' | 'cartera-recuperada'
type Vista = 'detalle' | 'por_cliente'

const RUTA: Record<Informe, string> = {
  compras: '/reportes/compras',
  'ventas-credito': '/reportes/ventas-credito',
  'remisiones-pendientes': '/reportes/remisiones-pendientes',
  'cartera-recuperada': '/reportes/cartera-recuperada', // S-97
}

/** Filtros que se limpian al cambiar de informe o con "Limpiar filtros" (el informe y la vista se conservan). */
const SIN_FILTROS = { proveedor: '', cliente: '', cliente_nombre: '', forma_pago: '', tipo: '', estado: 'vigentes', antiguedad: '', q: '', cuenta: '', aplicacion: 'todos', orden: '' }

/**
 * RF-18 (1, 2 y 6) y S-89: compras, ventas a crédito y remisiones pendientes de facturar, para el
 * análisis mensual. Solo en pantalla. Filtros en el servidor y en la URL: período (atajos), proveedor,
 * forma de pago, tipo y número en compras; cliente, tipo, estado y número en ventas a crédito;
 * proveedor y antigüedad en remisiones. Los totales cubren todas las filas filtradas.
 * S-97: "Cartera recuperada": abonos de clientes del período (detalle o por cliente), con cliente,
 * cuenta, aplicación, estado y búsqueda; tarjetas y total por cuenta de lo filtrado. Sin recaudos de
 * Contapyme (están en Recaudos). Un solo scroll, el de la tabla (S-91).
 */
export default function Informes() {
  const mes = rangoAtajo('este_mes')
  const { filtros: f, fijar, pagina, irA, activos } = useFiltrosUrl({
    informe: 'compras', desde: mes.desde, hasta: mes.hasta, vista: 'detalle', ...SIN_FILTROS,
  })
  const informe = f.informe as Informe
  const vista = f.vista as Vista
  const recuperada = informe === 'cartera-recuperada'
  const porPagina = useFilasPorPagina(50) // S-70
  const cuentas = useCuentasPago(true)
  const params: Record<string, string | undefined> = recuperada
    ? {
        vista, desde: f.desde, hasta: f.hasta, cliente_id: f.cliente || undefined, cuenta_pago_id: f.cuenta || undefined,
        estado: f.estado === 'vigentes' ? undefined : f.estado, tipo_aplicacion: f.aplicacion === 'todos' ? undefined : f.aplicacion,
        q: f.q || undefined, orden: f.orden || undefined,
      }
    : informe === 'compras'
    ? { desde: f.desde, hasta: f.hasta, proveedor_id: f.proveedor || undefined, forma_pago: f.forma_pago || undefined, tipo: f.tipo || undefined, q: f.q || undefined }
    : informe === 'ventas-credito'
      ? { desde: f.desde, hasta: f.hasta, cliente_id: f.cliente || undefined, tipo: f.tipo || undefined, estado: f.estado === 'vigentes' ? undefined : f.estado, q: f.q || undefined }
      : { proveedor_id: f.proveedor || undefined, antiguedad: f.antiguedad || undefined }

  const { data, isFetching } = useQuery({
    queryKey: ['informe', informe, vista, params, porPagina, pagina],
    queryFn: async () => (await api.get<PaginaInforme<unknown, unknown>>(RUTA[informe], { params: { ...params, page: pagina, por_pagina: porPagina } })).data,
    // Solo se conservan los datos previos del mismo informe y vista: con otros, la forma de las filas cambia.
    placeholderData: (previo, consultaPrevia) => (consultaPrevia?.queryKey[1] === informe && consultaPrevia?.queryKey[2] === vista ? previo : undefined),
  })
  const info = data ? infoPagina(data) : null
  // Ni el informe ni la vista cuentan como filtro activo; en remisiones tampoco el período por defecto.
  const fueraDeFiltro = (informe === 'compras' ? 0 : 1) + (vista === 'detalle' ? 0 : 1)
    + (informe === 'remisiones-pendientes' ? Number(f.desde !== mes.desde) + Number(f.hasta !== mes.hasta) : 0)
  const filtrosActivos = activos - fueraDeFiltro
  // Conserva el informe y la vista elegidos (un solo cambio de la URL).
  const limpiarFiltros = () => fijar({ desde: mes.desde, hasta: mes.hasta, ...SIN_FILTROS })
  const totalesRecuperados = recuperada && data ? (data as PaginaInforme<unknown, TotalesRecuperados>).totales : null
  const periodo = informe !== 'remisiones-pendientes'
    ? { desde: f.desde, hasta: f.hasta, onCambio: (desde: string, hasta: string) => fijar({ desde, hasta }) }
    : undefined

  return (
    <div className="pila pagina-tabla-fija">
      <div className="pagina-encabezado">
        <h1>Informes</h1>
        {recuperada
          ? <p>Abonos de clientes del período. Los cobros de facturas de Contapyme están en <Link to="/contable/recaudos">Recaudos</Link>.</p>
          : <p>Por defecto, el mes en curso. Los totales cubren todas las filas filtradas, no solo la página.</p>}
      </div>
      {recuperada && <ResumenRecuperado totales={totalesRecuperados} filtrado={filtrosActivos > 0} />}
      <section className="tarjeta-pagina tarjeta-tabla">
        <ControlSegmentado<Informe>
          id="informe"
          etiqueta="Informe"
          valor={informe}
          onCambio={(v) => fijar({ informe: v, vista: 'detalle', ...SIN_FILTROS })}
          opciones={[
            { valor: 'compras', texto: 'Compras' },
            { valor: 'ventas-credito', texto: 'Ventas a crédito' },
            { valor: 'remisiones-pendientes', texto: 'Remisiones sin facturar' },
            { valor: 'cartera-recuperada', texto: 'Cartera recuperada' },
          ]}
        />
        <BarraFiltros
          busqueda={informe !== 'remisiones-pendientes' ? {
            valor: f.q,
            onCambio: (q) => fijar({ q }),
            placeholder: recuperada ? 'Cliente, documento, factura, referencia o nota' : informe === 'compras' ? 'Número del documento' : 'Número de factura o de venta',
          } : undefined}
          periodo={periodo}
          activos={Math.max(0, filtrosActivos)}
          onLimpiar={limpiarFiltros}
        >
          {recuperada && (
            <>
              <ControlSegmentado<Vista>
                id="informe-vista"
                etiqueta="Vista"
                clase="vista-recuperada"
                valor={vista}
                onCambio={(v) => fijar({ vista: v, orden: '' })}
                opciones={[{ valor: 'detalle', texto: 'Detalle' }, { valor: 'por_cliente', texto: 'Por cliente' }]}
              />
              <FiltroCliente id={f.cliente} nombre={f.cliente_nombre} onElegir={(c) => fijar({ cliente: c ? String(c.id) : '', cliente_nombre: c?.nombre ?? '' })} />
              <CampoFiltro etiqueta="Cuenta">
                <select value={f.cuenta} onChange={(e) => fijar({ cuenta: e.target.value })}>
                  <option value="">Todas las cuentas</option>
                  {(cuentas.data ?? []).map((c) => <option key={c.id} value={c.id}>{c.nombre}</option>)}
                </select>
              </CampoFiltro>
              <CampoFiltro etiqueta="Aplicación">
                <select value={f.aplicacion} onChange={(e) => fijar({ aplicacion: e.target.value })}>
                  <option value="todos">Todos</option>
                  <option value="a_factura">A una factura</option>
                  <option value="general">General</option>
                </select>
              </CampoFiltro>
              <CampoFiltro etiqueta="Estado">
                <select value={f.estado} onChange={(e) => fijar({ estado: e.target.value })}>
                  <option value="vigentes">Vigentes</option>
                  <option value="anulados">Anulados</option>
                  <option value="todos">Todos</option>
                </select>
              </CampoFiltro>
              <CampoFiltro etiqueta="Orden">
                <select value={f.orden} onChange={(e) => fijar({ orden: e.target.value })}>
                  {vista === 'detalle'
                    ? <><option value="">Más recientes</option><option value="valor_desc">Mayor valor</option></>
                    : <><option value="">Mayor recuperado</option><option value="nombre">Nombre</option></>}
                </select>
              </CampoFiltro>
            </>
          )}
          {informe !== 'ventas-credito' && !recuperada && (
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
          {informe !== 'remisiones-pendientes' && !recuperada && (
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
        <div className="tabla-desplazable tabla-viva llena" aria-busy={isFetching}>
          {!data && <div className="cargando">Cargando…</div>}
          {data && recuperada && vista === 'detalle' && <TablaRecuperadoDetalle datos={data as PaginaInforme<FilaAbonoRecuperado, TotalesRecuperados>} filtrado={filtrosActivos > 0} onLimpiar={limpiarFiltros} />}
          {data && recuperada && vista === 'por_cliente' && <TablaRecuperadoClientes datos={data as PaginaInforme<FilaClienteRecuperado, TotalesRecuperados>} filtrado={filtrosActivos > 0} onLimpiar={limpiarFiltros} />}
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

const NOMBRE_TIPO_DOC = { factura: 'Factura', remision: 'Remisión' } as const

/** S-97: tarjetas y total por cuenta de lo filtrado (no de la página). */
function ResumenRecuperado({ totales: t, filtrado }: { totales: TotalesRecuperados | null; filtrado: boolean }) {
  const maximo = Math.max(1, ...(t?.por_cuenta ?? []).map((c) => Number(c.total)))
  return (
    <div className="resumen-recuperado">
      <div className="medidas">
        <Medida etiqueta={filtrado ? 'Recuperado (lo filtrado)' : 'Recuperado'} valor={t ? pesos(t.total_recuperado) : '—'} tono="verde" icono="billetera" />
        <Medida etiqueta="Abonos" valor={t ? t.cantidad_abonos.toLocaleString('es-CO') : '—'} tono="azul" icono="caja" />
        <Medida etiqueta="Clientes" valor={t ? t.clientes.toLocaleString('es-CO') : '—'} tono="azul" icono="persona" />
        {t && t.cantidad_anulados > 0 && (
          <Medida etiqueta="Anulado (aparte)" valor={pesos(t.total_anulado)} pie={`${t.cantidad_anulados} ${t.cantidad_anulados === 1 ? 'abono' : 'abonos'}`} tono="amarillo" icono="agotados" />
        )}
      </div>
      {t && t.por_cuenta.length > 0 && (
        <ul className="recuperado-por-cuenta" aria-label="Recuperado por cuenta">
          {t.por_cuenta.map((c) => (
            <li key={c.cuenta_id ?? 'sin-cuenta'}>
              <span className="recuperado-cuenta-nombre">{c.cuenta}</span>
              <span className="recuperado-cuenta-barra" aria-hidden><span style={{ width: `${Math.round((Number(c.total) / maximo) * 100)}%` }} /></span>
              <strong>{pesos(c.total)}</strong>
              <small>{c.cantidad} {c.cantidad === 1 ? 'abono' : 'abonos'}</small>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

const cuentaDe = (clienteId: number) => `/contable/clientes/${clienteId}?pestana=historial`

function TablaRecuperadoDetalle({ datos, filtrado, onLimpiar }: { datos: PaginaInforme<FilaAbonoRecuperado, TotalesRecuperados>; filtrado: boolean; onLimpiar: () => void }) {
  const t = datos.totales
  return (
    <Tabla
      filtrado={filtrado}
      onLimpiar={onLimpiar}
      columnas={9}
      vacio="No hay abonos en el período."
      cabeza={<><th>Fecha</th><th>Cliente</th><th>Documento</th><th>Cuenta</th><th>Referencia</th><th className="cifra">Valor</th><th>Usuario</th><th>Estado</th><th className="boton-fila-columna">Acciones</th></>}
      filas={datos.data.map((a) => (
        <tr key={a.id} className={a.anulado ? 'reversado' : undefined}>
          <td>{fechaCorta(a.fecha)}</td>
          <td>{a.cliente?.nombre ?? '—'}{a.cliente?.documento && <small className="bloque">{a.cliente.documento}</small>}</td>
          <td>{a.documento ? `${a.documento.tipo ? NOMBRE_TIPO_DOC[a.documento.tipo] : 'Venta'} ${a.documento.numero ?? `#${a.documento.venta_id}`}` : 'General'}</td>
          <td>{a.cuenta?.nombre ?? 'Sin cuenta (anterior)'}</td>
          <td>{a.referencia ?? '—'}{a.nota && <small className="bloque">{a.nota}</small>}</td>
          <td className="cifra">{pesos(a.valor)}</td>
          <td>{a.usuario?.name ?? '—'}</td>
          <td>
            {a.anulado ? (
              <>
                <span className="chip">Anulado</span>
                {a.anulacion && <small className="bloque">«{a.anulacion.motivo}» · {a.anulacion.usuario?.name ?? '—'}</small>}
              </>
            ) : 'Vigente'}
          </td>
          <td className="boton-fila-columna">
            {a.cliente && <BotonFila variante="ver" to={cuentaDe(a.cliente.id)} descripcion={`Ver cuenta de ${a.cliente.nombre}`} />}
          </td>
        </tr>
      ))}
      total={(
        <tr className="fila-total">
          <td colSpan={5}>{filtrado ? 'Recuperado de lo filtrado' : 'Recuperado'} ({t.cantidad_abonos})</td>
          <td className="cifra">{pesos(t.total_recuperado)}</td>
          <td colSpan={3}>{t.cantidad_anulados > 0 ? `Anulados aparte: ${t.cantidad_anulados} por ${pesos(t.total_anulado)}` : ''}</td>
        </tr>
      )}
    />
  )
}

function TablaRecuperadoClientes({ datos, filtrado, onLimpiar }: { datos: PaginaInforme<FilaClienteRecuperado, TotalesRecuperados>; filtrado: boolean; onLimpiar: () => void }) {
  const t = datos.totales
  return (
    <Tabla
      filtrado={filtrado}
      onLimpiar={onLimpiar}
      columnas={6}
      vacio="No hay abonos en el período."
      cabeza={<><th>Cliente</th><th className="cifra">Abonos</th><th className="cifra">Total recuperado</th><th>Último abono</th><th className="cifra">Saldo actual</th><th className="boton-fila-columna">Acciones</th></>}
      filas={datos.data.map((c) => (
        <tr key={c.cliente.id}>
          <td>{c.cliente.nombre}{c.cliente.documento && <small className="bloque">{c.cliente.documento}</small>}</td>
          <td className="cifra">{c.abonos}</td>
          <td className="cifra"><strong>{pesos(c.recuperado)}</strong></td>
          <td>{fechaCorta(c.ultimo_abono)}</td>
          <td className="cifra">{pesos(c.saldo_actual)}</td>
          <td className="boton-fila-columna"><BotonFila variante="ver" to={cuentaDe(c.cliente.id)} descripcion={`Ver cuenta de ${c.cliente.nombre}`} /></td>
        </tr>
      ))}
      total={(
        <tr className="fila-total">
          <td>{filtrado ? 'Lo filtrado' : 'Total'} ({t.clientes} {t.clientes === 1 ? 'cliente' : 'clientes'})</td>
          <td className="cifra">{t.cantidad_abonos}</td>
          <td className="cifra">{pesos(t.total_recuperado)}</td>
          <td colSpan={3} />
        </tr>
      )}
    />
  )
}

