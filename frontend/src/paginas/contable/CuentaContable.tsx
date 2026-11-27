import { useRef, useState, type KeyboardEvent } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '../../api/cliente'
import { infoPagina, type DocumentoPendiente, type PaginaHistorial, type ResumenSaldo } from '../../api/tipos'
import { useAuth } from '../../auth/AuthProvider'
import BarraFiltros, {CampoFiltro, TextoResultados, VacioFiltros } from '../../componentes/BarraFiltros'
import { rangoAtajo } from '../../utilidades/atajosPeriodo'
import BotonFila from '../../componentes/BotonFila'
import { Antiguedad, DialogoAnular, DialogoMovimiento } from '../../componentes/Cartera'
import ControlSegmentado from '../../componentes/ControlSegmentado'
import Paginacion from '../../componentes/Paginacion'
import { fechaCorta } from '../../componentes/fechas'
import { pesos } from '../../componentes/numeros'
import { useCuentasPago } from '../../hooks/useCatalogos'
import { useFiltrosUrl } from '../../hooks/useFiltrosUrl'
import { formatearFechaHora } from '../../utilidades/fechas'
import EstadoCuenta from './EstadoCuenta'
import { invalidarCartera, urlVolver } from './cartera'
import '../../componentes/cartera.css'

/** Saldo de un titular (proveedor o cliente) con el contrato común de /saldo. */
interface SaldoTitular extends ResumenSaldo {
  corte: string
  documentos: DocumentoPendiente[]
}

/** Fila del historial de pagos (proveedor) o abonos (cliente), con lo común de los dos recursos. */
interface MovimientoHistorial {
  id: number
  fecha: string
  valor: string
  medio: string
  cuenta?: { id: number; nombre: string } | null
  referencia: string | null
  nota: string | null
  created_at: string
  usuario?: { id: number; name: string }
  /** Proveedor: la factura del pago. */
  ingreso?: { id: number; tipo: string; numero_documento: string } | null
  /** Cliente: "Venta #11 · FE-1042" o "General". */
  aplicado_a?: string
  anulacion?: { motivo: string; created_at: string; usuario?: { id: number; name: string } | null } | null
}

type Pestana = 'facturas' | 'historial' | 'estado'

export interface ConfigCuenta {
  tipo: 'proveedor' | 'cliente'
  id: string
  /** Lista a la que vuelve la miga (con sus filtros, si se guardaron). */
  lista: { ruta: string; texto: string; detalle: string }
  rutaSaldo: string
  rutaHistorial: string
  rutaEstado: string
  rutaAnular: (id: number) => string
  /** Nombre y subtítulo del titular, sacados de la respuesta de /saldo. */
  titular: (d: SaldoTitular & Record<string, unknown>) => { nombre: string; detalle: string }
  /** Solo proveedor: registrar un pago a la factura (contable y admin, S-79). */
  conPagar: boolean
}

const nombreDocumento = (d: DocumentoPendiente) => d.documento
  ?? (d.tipo === 'factura' ? `Factura ${d.numero_documento ?? `#${d.id}`}` : d.tipo === 'remision' ? `Remisión ${d.numero_documento ?? `#${d.id}`}` : `Venta #${d.id}`)

const PESTANAS: { valor: Pestana; texto: string }[] = [
  { valor: 'facturas', texto: 'Facturas con saldo' },
  { valor: 'historial', texto: 'Historial' },
  { valor: 'estado', texto: 'Estado de cuenta' },
]

/**
 * RF-17 / RF-18 / RF-29 y S-88: cuenta de un proveedor o de un cliente para el contable (y el
 * admin). Encabezado con miga, saldo y antigüedad; pestañas (en la URL) Facturas con saldo |
 * Historial de pagos o abonos | Estado de cuenta. Solo el proveedor tiene "Pagar" (S-79); en el
 * cliente es solo lectura. "Anular" en el historial, solo el admin (S-27).
 */
export default function CuentaContable({ config: c }: { config: ConfigCuenta }) {
  const [params, setParams] = useSearchParams()
  const pestana = (PESTANAS.some((p) => p.valor === params.get('pestana')) ? params.get('pestana') : 'facturas') as Pestana
  const fijarPestana = (p: Pestana) => setParams((a) => { const n = new URLSearchParams(a); if (p === 'facturas') n.delete('pestana'); else n.set('pestana', p); return n }, { replace: true })
  const botones = useRef<Record<Pestana, HTMLButtonElement | null>>({ facturas: null, historial: null, estado: null })

  const { data } = useQuery({
    queryKey: [c.tipo === 'proveedor' ? 'saldo-proveedor' : 'saldo-cliente', c.id],
    queryFn: async () => (await api.get<SaldoTitular & Record<string, unknown>>(c.rutaSaldo)).data,
  })

  const alTeclear = (e: KeyboardEvent<HTMLButtonElement>, i: number) => {
    const destino = e.key === 'ArrowRight' ? (i + 1) % PESTANAS.length : e.key === 'ArrowLeft' ? (i + PESTANAS.length - 1) % PESTANAS.length : null
    if (destino === null) return
    e.preventDefault()
    fijarPestana(PESTANAS[destino].valor)
    botones.current[PESTANAS[destino].valor]?.focus()
  }

  if (!data) return <div className="cargando">Cargando…</div>
  const t = c.titular(data)
  const historial = c.tipo === 'proveedor' ? 'Historial de pagos' : 'Historial de abonos'

  return (
    <div className="pila">
      <div className="pagina-encabezado">
        <p className="migas"><Link to={urlVolver(c.lista.detalle, c.lista.ruta)}>{c.lista.texto}</Link> ›</p>
        <h1>{t.nombre}</h1>
        <p>{t.detalle}{t.detalle ? ' · ' : ''}Saldo a hoy {pesos(data.saldo)}{Number(data.vencido) > 0 ? ` · vencido ${pesos(data.vencido)}` : ''}</p>
      </div>

      <section className="tarjeta-pagina">
        <Antiguedad resumen={data} />
      </section>

      <div className="pestanas cuenta-pestanas" role="tablist" aria-label="Secciones de la cuenta">
        {PESTANAS.map((p, i) => (
          <button
            key={p.valor}
            ref={(b) => { botones.current[p.valor] = b }}
            type="button"
            role="tab"
            id={`pestana-${p.valor}`}
            aria-selected={pestana === p.valor}
            aria-controls={`panel-${p.valor}`}
            tabIndex={pestana === p.valor ? 0 : -1}
            className={pestana === p.valor ? 'pestana activa' : 'pestana'}
            onClick={() => fijarPestana(p.valor)}
            onKeyDown={(e) => alTeclear(e, i)}
          >
            {p.valor === 'historial' ? historial : p.texto}
            {p.valor === 'facturas' && <span className="pestana-cuenta">{data.documentos.length}</span>}
          </button>
        ))}
      </div>

      <div role="tabpanel" id={`panel-${pestana}`} aria-labelledby={`pestana-${pestana}`}>
        {pestana === 'facturas' && <FacturasConSaldo config={c} titular={t.nombre} documentos={data.documentos} onVerHistorial={() => fijarPestana('historial')} />}
        {pestana === 'historial' && <HistorialDinero config={c} titular={t.nombre} />}
        {pestana === 'estado' && <EstadoCuenta ruta={c.rutaEstado} />}
      </div>
    </div>
  )
}

type EstadoFactura = 'todas' | 'vencidas' | 'por_vencer'

/** Facturas con saldo: la lista ya viene completa; se filtra en el cliente (estado y número). */
function FacturasConSaldo({ config: c, titular, documentos, onVerHistorial }: {
  config: ConfigCuenta
  titular: string
  documentos: DocumentoPendiente[]
  onVerHistorial: () => void
}) {
  const qc = useQueryClient()
  const { filtros: f, fijar, activos, limpiar } = useFiltrosUrl({ f_estado: 'todas', f_q: '' })
  const [pagando, setPagando] = useState<DocumentoPendiente | null>(null)
  const q = f.f_q.trim().toLowerCase()
  const visibles = documentos
    .filter((d) => f.f_estado === 'todas' || (f.f_estado === 'vencidas' ? d.dias_vencido > 0 : d.dias_vencido === 0))
    .filter((d) => q === '' || nombreDocumento(d).toLowerCase().includes(q) || String(d.numero_documento ?? '').toLowerCase().includes(q))
    .sort((a, b) => (a.vence_el ?? a.fecha).localeCompare(b.vence_el ?? b.fecha) || a.id - b.id)
  const pendiente = visibles.reduce((s, d) => s + Number(d.pendiente), 0)

  return (
    <section className="tarjeta-pagina">
      <BarraFiltros busqueda={{ valor: f.f_q, onCambio: (v) => fijar({ f_q: v }), placeholder: 'Número de documento' }} activos={activos} onLimpiar={limpiar}>
        <ControlSegmentado<EstadoFactura>
          id={`${c.tipo}-facturas-estado`}
          etiqueta="Estado"
          valor={f.f_estado as EstadoFactura}
          onCambio={(v) => fijar({ f_estado: v })}
          opciones={[{ valor: 'todas', texto: 'Todas' }, { valor: 'vencidas', texto: 'Vencidas' }, { valor: 'por_vencer', texto: 'Por vencer' }]}
        />
      </BarraFiltros>
      <TextoResultados mostrando={visibles.length} total={documentos.length} de="documentos con saldo" />
      {visibles.length === 0 ? (
        <p className="vacio"><VacioFiltros hayFiltros={activos > 0} sinFiltros={`${c.tipo === 'proveedor' ? 'Este proveedor' : 'Este cliente'} no tiene facturas con saldo.`} onLimpiar={limpiar} /></p>
      ) : (
        <div className="tabla-desplazable">
          <table className="documentos-pendientes">
            <thead>
              <tr><th>Documento</th><th>Fecha</th><th>Vence</th><th>Estado</th><th className="cifra">Valor</th><th className="cifra">Pendiente</th>{c.conPagar && <th className="boton-fila-columna">Acciones</th>}</tr>
            </thead>
            <tbody>
              {visibles.map((d) => (
                <tr key={d.id}>
                  <td>{nombreDocumento(d)}</td>
                  <td>{fechaCorta(d.fecha)}</td>
                  <td>{d.vence_el ? fechaCorta(d.vence_el) : '—'}</td>
                  <td>
                    {d.dias_vencido > 0
                      ? <span className="chip vencida">Vencida · {d.dias_vencido} d</span>
                      : <span className="chip">{d.dias_para_vencer === 0 ? 'Vence hoy' : `Faltan ${d.dias_para_vencer} d`}</span>}
                  </td>
                  <td className="cifra">
                    {pesos(d.valor)}
                    {Number(d.abonado) > 0 && <small className="abonado-documento">Abonado {pesos(d.abonado)}</small>}
                  </td>
                  <td className="cifra"><strong>{pesos(d.pendiente)}</strong></td>
                  {c.conPagar && (
                    <td className="boton-fila-columna">
                      <BotonFila variante="pagar" descripcion={`Pagar ${nombreDocumento(d)} de ${titular}`} onClick={() => setPagando(d)} />
                    </td>
                  )}
                </tr>
              ))}
              <tr className="fila-total">
                <td colSpan={5}>{activos > 0 ? 'Pendiente de lo filtrado' : 'Pendiente total'}</td>
                <td className="cifra">{pesos(pendiente)}</td>
                {c.conPagar && <td />}
              </tr>
            </tbody>
          </table>
        </div>
      )}
      <p className="ayuda">
        {c.conPagar ? 'Elija la factura que va a pagar. Un pago parcial deja saldo en la factura. ' : 'Los abonos los registra el mostrador o el administrador desde Cartera. '}
        <button type="button" className="enlace" onClick={onVerHistorial}>Ver el historial completo</button>
      </p>

      {pagando && (
        <DialogoMovimiento
          titulo="Registrar pago"
          titular={titular}
          ruta={`/proveedores/${c.id}/pagos`}
          documento={{ id: pagando.id, nombre: nombreDocumento(pagando), valor: pagando.valor, abonado: pagando.abonado, pendiente: pagando.pendiente }}
          onCerrar={() => setPagando(null)}
          onGuardado={() => { setPagando(null); invalidarCartera(qc) }}
        />
      )}
    </section>
  )
}

type EstadoHistorial = 'vigentes' | 'anulados' | 'todos'

/** S-88: historial de pagos o abonos, paginado en el servidor, con filtros en la URL (prefijo h_). */
function HistorialDinero({ config: c, titular }: { config: ConfigCuenta; titular: string }) {
  const qc = useQueryClient()
  const esAdmin = useAuth().usuario?.rol === 'admin'
  const cuentas = useCuentasPago()
  const porDefecto = rangoAtajo('este_anio')
  const { filtros: f, fijar, pagina, irA, activos, limpiar } = useFiltrosUrl({
    desde: porDefecto.desde, hasta: porDefecto.hasta, estado: 'vigentes', cuenta: '', q: '',
  }, 'h_')
  const [anulando, setAnulando] = useState<MovimientoHistorial | null>(null)
  const params = { desde: f.desde, hasta: f.hasta, estado: f.estado, cuenta_pago_id: f.cuenta || undefined, q: f.q || undefined }

  const { data, isFetching } = useQuery({
    queryKey: ['historial-dinero', c.rutaHistorial, params, pagina],
    queryFn: async () => (await api.get<PaginaHistorial<MovimientoHistorial>>(c.rutaHistorial, { params: { ...params, page: pagina, por_pagina: 50 } })).data,
    placeholderData: (previo, consultaPrevia) => (consultaPrevia?.queryKey[1] === c.rutaHistorial ? previo : undefined),
  })
  const info = data ? infoPagina(data) : null
  const t = data?.totales
  const nombre = c.tipo === 'proveedor' ? 'pago' : 'abono'
  const aplicado = (m: MovimientoHistorial) => (c.tipo === 'proveedor'
    ? (m.ingreso ? `A ${m.ingreso.tipo === 'factura' ? 'factura' : 'remisión'} ${m.ingreso.numero_documento}` : 'Sin factura')
    : (m.aplicado_a ?? '—'))

  return (
    <section className="tarjeta-pagina">
      <BarraFiltros
        busqueda={{ valor: f.q, onCambio: (q) => fijar({ q }), placeholder: c.tipo === 'proveedor' ? 'Referencia, nota o n.º de factura' : 'Referencia, nota, n.º de venta o factura' }}
        periodo={{ desde: f.desde, hasta: f.hasta, onCambio: (desde, hasta) => fijar({ desde, hasta }) }}
        activos={activos}
        onLimpiar={limpiar}
      >
        <ControlSegmentado<EstadoHistorial>
          id={`${c.tipo}-historial-estado`}
          etiqueta="Estado"
          valor={f.estado as EstadoHistorial}
          onCambio={(estado) => fijar({ estado })}
          opciones={[{ valor: 'vigentes', texto: 'Vigentes' }, { valor: 'anulados', texto: 'Anulados' }, { valor: 'todos', texto: 'Todos' }]}
        />
        <CampoFiltro etiqueta="Cuenta">
          <select value={f.cuenta} onChange={(e) => fijar({ cuenta: e.target.value })}>
            <option value="">Todas las cuentas</option>
            {(cuentas.data ?? []).map((x) => <option key={x.id} value={x.id}>{x.nombre}</option>)}
          </select>
        </CampoFiltro>
      </BarraFiltros>
      {info && <TextoResultados mostrando={data!.data.length} total={info.total} de={`${nombre}s`} />}
      <div className="tabla-desplazable">
        <table aria-busy={isFetching}>
          <thead>
            <tr><th>Fecha</th><th className="cifra">Valor</th><th>Cuenta</th><th>Aplicado a</th><th>Referencia</th><th>Registró</th><th>Estado</th>{esAdmin && <th className="boton-fila-columna">Acciones</th>}</tr>
          </thead>
          <tbody>
            {data?.data.map((m) => (
              <tr key={m.id} className={m.anulacion ? 'reversado' : undefined}>
                <td>{fechaCorta(m.fecha)}</td>
                <td className="cifra">{pesos(m.valor)}</td>
                <td>{m.cuenta?.nombre ?? m.medio}</td>
                <td>{aplicado(m)}</td>
                <td>{m.referencia ?? '—'}{m.nota && <small className="bloque">{m.nota}</small>}</td>
                <td>{m.usuario?.name ?? '—'}<small className="bloque">{formatearFechaHora(m.created_at)}</small></td>
                <td>
                  {m.anulacion
                    ? <><span className="chip">Anulado</span><small className="bloque">«{m.anulacion.motivo}» · {m.anulacion.usuario?.name ?? '—'}</small></>
                    : 'Vigente'}
                </td>
                {esAdmin && (
                  <td className="boton-fila-columna">
                    {!m.anulacion && (
                      <BotonFila variante="anular" descripcion={`Anular el ${nombre} de ${pesos(m.valor)} del ${fechaCorta(m.fecha)} de ${titular}`} onClick={() => setAnulando(m)} />
                    )}
                  </td>
                )}
              </tr>
            ))}
            {data && data.data.length === 0 && (
              <tr><td colSpan={esAdmin ? 8 : 7} className="vacio">
                <VacioFiltros hayFiltros={activos > 0} sinFiltros={`Todavía no hay ${nombre}s.`} onLimpiar={limpiar} />
              </td></tr>
            )}
            {data && data.data.length > 0 && t && (
              <tr className="fila-total">
                <td>{activos > 0 ? 'Vigente filtrado' : 'Total vigente'} ({t.cantidad})</td>
                <td className="cifra">{pesos(t.vigente)}</td>
                <td colSpan={esAdmin ? 6 : 5}>{t.cantidad_anulados > 0 ? `Anulados aparte: ${t.cantidad_anulados} por ${pesos(t.anulado)}` : ''}</td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
      <Paginacion pagina={data} onCambiar={irA} />

      {anulando && (
        <DialogoAnular
          titulo={`Anular ${nombre}`}
          descripcion={`${nombre === 'pago' ? 'Pago' : 'Abono'} de ${pesos(anulando.valor)} del ${fechaCorta(anulando.fecha)}`}
          ruta={c.rutaAnular(anulando.id)}
          onCerrar={() => setAnulando(null)}
          onHecho={() => { setAnulando(null); invalidarCartera(qc) }}
        />
      )}
    </section>
  )
}
