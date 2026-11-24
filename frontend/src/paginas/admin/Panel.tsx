import { useEffect, useId, useMemo, useRef, useState, type ReactNode } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useQuery, useQueryClient, type UseQueryResult } from '@tanstack/react-query'
import { api, mensajeError } from '../../api/cliente'
import {
  NOMBRE_CALIDAD, NOMBRE_MOTIVO,
  type MotivoAjuste, type OrdenMasVendidos, type PanelAntiguedad, type PanelControl, type PanelMasVendidos, type PanelReabastecer,
  type PanelReferencia, type PanelResumen, type PanelRotacion, type PanelSinMovimiento, type PanelVentasPorDia, type Referencia,
} from '../../api/tipos'
import ControlSegmentado from '../../componentes/ControlSegmentado'
import FichaReferencia from '../../componentes/FichaReferencia'
import FiltroPeriodo, { type ValorPeriodo } from '../../componentes/FiltroPeriodo'
import Paginacion from '../../componentes/Paginacion'
import SelectorProveedor from '../../componentes/SelectorProveedor'
import BarrasHorizontales from '../../componentes/graficos/BarrasHorizontales'
import Columnas from '../../componentes/graficos/Columnas'
import GraficoAreas from '../../componentes/graficos/GraficoAreas'
import { pesos } from '../../componentes/numeros'
import { useCategorias } from '../../hooks/useCatalogos'
import { RUTA_AJUSTES, RUTA_CARTERA } from '../../rutas'
import { formatearFecha } from '../../utilidades/fechas'
import { PERIODOS_PANEL, PERIODO_PANEL, errorRango, esPeriodo, hoyEnBogota, rangoDe } from '../../utilidades/periodos'
import './panel.css'

/**
 * RF-23 / S-45: panel de gestión del administrador. Solo en pantalla: sin exportar ni imprimir
 * (regla 16). Cada bloque tiene su propia consulta (caché de 5 minutos) y su error aislado.
 * El margen es estimado y solo se muestra aquí.
 */

const CINCO_MINUTOS = 5 * 60_000

/** Texto de ayuda del margen (S-45 d). */
const TEXTO_MARGEN = 'Estimado: venta sin IVA menos costo de compra. No es utilidad contable; el costo de una remisión es provisional y no incluye gastos.'

const ORDENES: { valor: OrdenMasVendidos; texto: string }[] = [
  { valor: 'unidades', texto: 'Unidades' },
  { valor: 'valor', texto: 'Valor' },
  { valor: 'margen', texto: 'Margen' },
]
const AGRUPACIONES: { valor: 'categoria' | 'proveedor'; texto: string }[] = [
  { valor: 'categoria', texto: 'Por categoría' },
  { valor: 'proveedor', texto: 'Por proveedor' },
]
const DIAS_ESTANCADO = [30, 60, 90, 120, 180, 365]
const NOMBRE_RANGO: Record<string, string> = { '0-30': '0-30 d', '31-90': '31-90 d', '91-180': '91-180 d', '181-365': '181-365 d', 'mas-365': '> 365 d' }
const NOMBRE_REABASTECER = { agotada: 'Agotada', bajo_minimo: 'Bajo mínimo', cobertura_corta: 'Cobertura corta' } as const

type Filtros = { desde?: string; hasta?: string; categoria_id?: string; proveedor_id?: string }

const pct = (n: number | null | undefined) => (n === null || n === undefined ? '—' : `${n.toLocaleString('es-CO', { maximumFractionDigits: 1 })} %`)
const miles = (n: number) => n.toLocaleString('es-CO')
const unidades = (n: number) => `${miles(n)} ${n === 1 ? 'unidad' : 'unidades'}`
const diaCorto = (dia: string) => `${dia.slice(8, 10)}/${dia.slice(5, 7)}`

function usePanel<T>(informe: string, filtros: Filtros, extra: Record<string, string | number>, forzar: { current: boolean }, activo: boolean) {
  return useQuery({
    queryKey: ['panel', informe, filtros, extra],
    queryFn: async () => (await api.get<T>(`/panel/${informe}`, { params: { ...filtros, ...extra, refrescar: forzar.current ? 1 : undefined } })).data,
    staleTime: CINCO_MINUTOS,
    enabled: activo,
  })
}

export default function Panel() {
  const qc = useQueryClient()
  const [params, setParams] = useSearchParams()
  const categorias = useCategorias()

  // El período y los filtros viven en la URL (recargar o compartir el enlace los conserva).
  const crudo = params.get('periodo')
  const periodo: ValorPeriodo = {
    periodo: esPeriodo(crudo) && crudo !== 'todo' ? crudo : PERIODO_PANEL,
    desde: params.get('desde') ?? '',
    hasta: params.get('hasta') ?? '',
  }
  const rango = rangoDe(periodo.periodo, hoyEnBogota(), periodo)
  const errorFechas = errorRango(rango) ?? (periodo.periodo === 'personalizado' && (!rango.desde || !rango.hasta) ? 'Elija las dos fechas del período.' : null)
  const categoriaId = params.get('categoria') ?? ''
  const proveedorId = params.get('proveedor') ?? ''
  const filtros: Filtros = useMemo(() => ({
    desde: rango.desde ?? undefined,
    hasta: rango.hasta ?? undefined,
    categoria_id: categoriaId || undefined,
    proveedor_id: proveedorId || undefined,
  }), [rango.desde, rango.hasta, categoriaId, proveedorId])

  const cambiar = (cambios: Record<string, string | null>) => setParams((actual) => {
    const siguiente = new URLSearchParams(actual)
    for (const [k, v] of Object.entries(cambios)) {
      if (v) siguiente.set(k, v)
      else siguiente.delete(k)
    }
    return siguiente
  }, { replace: true })
  const cambiarPeriodo = (v: ValorPeriodo) => cambiar({
    periodo: v.periodo,
    desde: v.periodo === 'personalizado' ? v.desde : null,
    hasta: v.periodo === 'personalizado' ? v.hasta : null,
  })

  const [orden, setOrden] = useState<OrdenMasVendidos>('unidades')
  const [agrupar, setAgrupar] = useState<'categoria' | 'proveedor'>('categoria')
  const [diasElegidos, setDiasElegidos] = useState<number | null>(null)
  const [paginaEstancado, setPaginaEstancado] = useState(1)
  const [fichaId, setFichaId] = useState<number | null>(null)

  // "Actualizar": las consultas activas se repiten con refrescar=1 (saltan la caché del servidor).
  const forzar = useRef(false)
  const [actualizando, setActualizando] = useState(false)
  const actualizar = async () => {
    forzar.current = true
    setActualizando(true)
    try {
      await qc.refetchQueries({ queryKey: ['panel'], type: 'active' })
    } finally {
      forzar.current = false
      setActualizando(false)
    }
  }

  const activo = errorFechas === null
  const configurados = useQuery({
    queryKey: ['configuracion', 'dias-sin-movimiento'],
    queryFn: async () => (await api.get<{ valor: number }>('/configuracion/dias-sin-movimiento')).data.valor,
    staleTime: CINCO_MINUTOS,
  })
  const dias = diasElegidos ?? configurados.data ?? null

  const resumen = usePanel<PanelResumen>('resumen', filtros, {}, forzar, activo)
  const porDia = usePanel<PanelVentasPorDia>('ventas-por-dia', filtros, {}, forzar, activo)
  const masVendidos = usePanel<PanelMasVendidos>('mas-vendidos', filtros, { orden, limite: 10 }, forzar, activo)
  const reabastecer = usePanel<PanelReabastecer>('reabastecer', filtros, {}, forzar, activo)
  const estancado = usePanel<PanelSinMovimiento>('sin-movimiento', filtros, { dias: dias ?? 0, page: paginaEstancado }, forzar, activo && dias !== null)
  const antiguedad = usePanel<PanelAntiguedad>('antiguedad-stock', filtros, {}, forzar, activo)
  const rotacion = usePanel<PanelRotacion>('rotacion', filtros, { agrupar }, forzar, activo)
  const control = usePanel<PanelControl>('control', filtros, {}, forzar, activo)

  // Los filtros cambian la página del estancado.
  useEffect(() => { setPaginaEstancado(1) }, [filtros, dias])

  const ficha = useQuery({
    queryKey: ['referencia', fichaId],
    queryFn: async () => (await api.get<Referencia>(`/referencias/${fichaId}`)).data,
    enabled: fichaId !== null,
  })

  const etiquetaRef = (r: PanelReferencia) => ({
    etiqueta: r.codigo_referencia,
    detalle: [r.proveedor.nombre, r.calidad ? NOMBRE_CALIDAD[r.calidad] : null, r.categoria?.nombre, r.universal ? `Universal ${r.universal.codigo}` : null].filter(Boolean).join(' · '),
  })
  const celdaRef = (r: PanelReferencia) => {
    const { etiqueta, detalle } = etiquetaRef(r)
    return (
      <span className="panel-ref">
        <button type="button" className="enlace" onClick={() => setFichaId(r.id)} title={r.descripcion}>{etiqueta}</button>
        <small>{detalle}</small>
      </span>
    )
  }

  return (
    <div className="pila panel-gestion">
      <div className="pagina-encabezado">
        <h1>Panel de gestión</h1>
        <p>Ventas, margen estimado, inventario, cartera y control del negocio. Solo en pantalla.</p>
      </div>

      <section className="tarjeta-pagina panel-filtros-caja" aria-label="Filtros del panel">
        <div className="formulario en-linea panel-filtros">
          <FiltroPeriodo id="panel-periodo" valor={periodo} onCambio={cambiarPeriodo} periodos={PERIODOS_PANEL} />
          <label>Categoría
            <select value={categoriaId} onChange={(e) => cambiar({ categoria: e.target.value || null })}>
              <option value="">Todas</option>
              {categorias.data?.map((c) => <option key={c.id} value={c.id}>{c.nombre}</option>)}
            </select>
          </label>
          <label>Proveedor
            <SelectorProveedor
              className="filtro"
              aria-label="Proveedor"
              value={proveedorId}
              onChange={(v) => cambiar({ proveedor: v || null })}
              placeholder="Todos los proveedores"
              limpiable
            />
          </label>
          <div className="panel-actualizado">
            <Actualizado generado={resumen.data?.generado_at} />
            <button type="button" className="secundario boton-44" onClick={actualizar} disabled={actualizando || !activo}>
              {actualizando ? 'Actualizando…' : 'Actualizar'}
            </button>
          </div>
        </div>
        {errorFechas && <p className="aviso error" role="alert">{errorFechas}</p>}
        {resumen.data && (
          <p className="ayuda panel-rango">
            Del {formatearFecha(resumen.data.desde)} al {formatearFecha(resumen.data.hasta)} ({resumen.data.dias_periodo} días); se compara con
            {' '}{formatearFecha(resumen.data.periodo_anterior.desde)} – {formatearFecha(resumen.data.periodo_anterior.hasta)}.
          </p>
        )}
      </section>

      {/* (1) Medidas */}
      <Bloque titulo="Resumen" consulta={resumen} sinTitulo alto={140}>
        {(r) => (
          <div className="panel-medidas">
            <Tarjeta etiqueta="Ventas" valor={pesos(r.ventas.total)} variacion={r.ventas.variacion_total}
              lineas={[`${miles(r.ventas.ventas)} ventas · ${unidades(r.ventas.unidades)}`, `Ticket promedio ${pesos(r.ventas.ticket_promedio)}`, `Contado ${pesos(r.ventas.contado)} · Crédito ${pesos(r.ventas.credito)}`]} />
            <Tarjeta
              etiqueta="Margen estimado"
              ayuda={<AyudaMargen />}
              valor={r.margen.margen === null ? '—' : `${pesos(r.margen.margen)}`}
              etiquetaValor={r.margen.margen === null ? 'Margen estimado sin datos de costo' : `Margen estimado ${pesos(r.margen.margen)}, ${pct(r.margen.margen_porcentaje)}`}
              sufijo="estimado"
              variacion={r.margen.variacion}
              lineas={[
                `${pct(r.margen.margen_porcentaje)} de la venta sin IVA`,
                r.margen.cobertura_costo === null ? 'Sin unidades vendidas' : `sobre el ${pct(r.margen.cobertura_costo)} de las unidades vendidas (el resto no tiene costo)`,
              ]}
            />
            <Tarjeta etiqueta="Valor del inventario" valor={pesos(r.inventario.valor_costo)} sufijo="a costo"
              lineas={[`${pesos(r.inventario.valor_venta_sin_iva)} a precio de venta sin IVA`, `${unidades(r.inventario.unidades)}${r.inventario.unidades_sin_costo ? ` · ${miles(r.inventario.unidades_sin_costo)} sin costo` : ''}`]} />
            <Tarjeta etiqueta="Cartera por cobrar" valor={pesos(r.cartera.por_cobrar)} lineas={[`Vencida ${pesos(r.cartera.vencida)}`]} enlace={{ a: RUTA_CARTERA, texto: 'Ver cartera' }} />
            <Tarjeta etiqueta="Cuentas por pagar" valor={pesos(r.cuentas_por_pagar.por_pagar)} lineas={[`Vencidas ${pesos(r.cuentas_por_pagar.vencidas)}`]} />
            <Tarjeta etiqueta="Compras del período" valor={pesos(r.compras.total)} lineas={['Facturas y remisiones confirmadas']} />
            <Tarjeta etiqueta="Agotados / bajo mínimo" valor={`${miles(r.agotados)} / ${miles(r.bajo_minimo)}`} lineas={['Referencias activas']} enlace={{ a: '/agotados', texto: 'Ver informe' }} />
          </div>
        )}
      </Bloque>

      {/* (2) Ventas por día */}
      <Bloque titulo="Ventas por día" consulta={porDia} esVacio={(d) => d.totales.ventas === 0} vacio="Sin ventas en el período." alto={260}>
        {(d) => (
          <>
            <p className="ayuda">Total {pesos(d.totales.total)}: contado {pesos(d.totales.contado)}, crédito {pesos(d.totales.credito)} · {unidades(d.totales.unidades)}.</p>
            <GraficoAreas
              datos={d.serie.map((x) => ({ etiqueta: diaCorto(x.dia), detalle: formatearFecha(x.dia), valores: { contado: Number(x.contado), credito: Number(x.credito) } }))}
              series={[
                { clave: 'contado', nombre: 'Contado', color: 'var(--grafico-contado)' },
                { clave: 'credito', nombre: 'Crédito', color: 'var(--grafico-credito)', rayas: true },
              ]}
              formato={(n) => pesos(n)}
              resumen={`Ventas por día del ${formatearFecha(d.desde)} al ${formatearFecha(d.hasta)}: total ${pesos(d.totales.total)}, contado ${pesos(d.totales.contado)} y crédito ${pesos(d.totales.credito)}`}
            />
          </>
        )}
      </Bloque>

      {/* (3) Más vendidos y Reabastecer */}
      <div className="panel-dos">
        <Bloque
          titulo="Más vendidos"
          consulta={masVendidos}
          esVacio={(d) => d.filas.length === 0}
          vacio="Sin ventas en el período."
          acciones={<ControlSegmentado id="panel-orden" etiqueta="Ordenar por" opciones={ORDENES} valor={orden} onCambio={setOrden} />}
          ayuda={orden === 'margen' ? <AyudaMargen /> : undefined}
        >
          {(d) => (
            <>
              <BarrasHorizontales
                resumen={`Diez más vendidos por ${orden}`}
                filas={d.filas.map((f) => {
                  const valor = orden === 'unidades' ? f.unidades : orden === 'valor' ? Number(f.valor) : Number(f.margen ?? 0)
                  const texto = orden === 'unidades' ? unidades(f.unidades) : orden === 'valor' ? pesos(f.valor) : (f.margen === null ? '— (sin costo)' : `${pesos(f.margen)} estimado`)
                  return {
                    id: f.referencia.id,
                    ...etiquetaRef(f.referencia),
                    valor,
                    texto,
                    extra: f.clase ? <span className={`chip clase-abc clase-${f.clase}`} title={`Clase ${f.clase} por valor de venta`}>{f.clase}</span> : undefined,
                    onElegir: () => setFichaId(f.referencia.id),
                  }
                })}
              />
              <div className="panel-tabla">
                <table className="tabla-apilable">
                  <caption className="solo-lector">Más vendidos, con margen estimado</caption>
                  <thead>
                    <tr>
                      <th>Referencia</th><th className="col-numero">Unidades</th><th className="col-numero">Valor (IVA incl.)</th>
                      <th className="col-numero">Margen $ estimado</th><th className="col-numero">Margen %</th><th className="col-numero">% del total</th>
                      {orden === 'valor' && <th>ABC</th>}
                    </tr>
                  </thead>
                  <tbody>
                    {d.filas.map((f) => (
                      <tr key={f.referencia.id}>
                        <td data-etiqueta="Referencia">{celdaRef(f.referencia)}</td>
                        <td data-etiqueta="Unidades" className="col-numero">{miles(f.unidades)}</td>
                        <td data-etiqueta="Valor" className="col-numero">{pesos(f.valor)}</td>
                        <td data-etiqueta="Margen $ estimado" className="col-numero">{f.margen === null ? <span title="Sin costo conocido">—</span> : pesos(f.margen)}</td>
                        <td data-etiqueta="Margen %" className="col-numero">{pct(f.margen_porcentaje)}</td>
                        <td data-etiqueta="% del total" className="col-numero">{pct(f.porcentaje_total)}</td>
                        {orden === 'valor' && <td data-etiqueta="ABC">{f.clase && <span className={`chip clase-abc clase-${f.clase}`}>{f.clase}</span>}</td>}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </>
          )}
        </Bloque>

        <Bloque titulo="Reabastecer" consulta={reabastecer} esVacio={(d) => d.filas.length === 0} vacio="Nada urgente: ninguna referencia que rote está agotada, bajo mínimo o por acabarse.">
          {(d) => (
            <div className="panel-tabla">
              <table className="tabla-apilable">
                <caption className="solo-lector">Referencias para reabastecer</caption>
                <thead><tr><th>Referencia</th><th>Motivo</th><th className="col-numero">Vendidas</th><th className="col-numero">Existencia</th><th className="col-numero">Cobertura</th></tr></thead>
                <tbody>
                  {d.filas.map((f) => (
                    <tr key={f.referencia.id}>
                      <td data-etiqueta="Referencia">{celdaRef(f.referencia)}</td>
                      <td data-etiqueta="Motivo"><span className={`chip motivo-${f.motivo}`}>{NOMBRE_REABASTECER[f.motivo]}</span></td>
                      <td data-etiqueta="Vendidas" className="col-numero">{miles(f.vendidas)}</td>
                      <td data-etiqueta="Existencia" className="col-numero">{miles(f.existencia)}{f.minimo_efectivo !== null && <small> (mín. {f.minimo_efectivo})</small>}</td>
                      <td data-etiqueta="Cobertura" className="col-numero">{f.existencia === 0 ? '0 días' : `${miles(Math.round(f.cobertura_dias))} días`}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
              {d.total > d.filas.length && <p className="ayuda">Se muestran {d.filas.length} de {d.total}.</p>}
            </div>
          )}
        </Bloque>
      </div>

      {/* (4) Stock estancado */}
      <section className="panel-bloque" aria-labelledby="panel-estancado">
        <div className="panel-bloque-cabeza">
          <h2 id="panel-estancado">Stock estancado</h2>
          <div className="panel-acciones">
            <label className="panel-dias">Sin vender en
              <select value={dias ?? ''} onChange={(e) => setDiasElegidos(Number(e.target.value))} disabled={dias === null}>
                {[...new Set([...DIAS_ESTANCADO, ...(configurados.data ? [configurados.data] : [])])].sort((a, b) => a - b).map((n) => (
                  <option key={n} value={n}>{n} días{n === configurados.data ? ' (por defecto)' : ''}</option>
                ))}
              </select>
            </label>
            <Link to="/admin/configuracion#panel-gestion">Cambiar valor por defecto</Link>
          </div>
        </div>
        <EstadoConsulta consulta={estancado} vacio="Ninguna referencia con existencia lleva tanto tiempo sin venderse." esVacio={(d) => d.total === 0} alto={60} anidado>
          {(d) => (
            <p className="panel-total-estancado">
              <strong>{miles(d.totales.referencias)}</strong> referencias · <strong>{unidades(d.totales.unidades)}</strong> · <strong>{pesos(d.totales.valor_costo)}</strong> a costo
              {d.totales.unidades_sin_costo > 0 && <> · {miles(d.totales.unidades_sin_costo)} sin costo</>}
              <small> (sin venta desde el {formatearFecha(d.desde_limite)})</small>
            </p>
          )}
        </EstadoConsulta>
        <EstadoConsulta consulta={antiguedad} anidado alto={200}>
          {(a) => (
            <>
              <h3 className="panel-subtitulo">Antigüedad del stock disponible</h3>
              <Columnas
                resumen={`Unidades disponibles por antigüedad: ${a.rangos.map((x) => `${NOMBRE_RANGO[x.rango] ?? x.rango} ${x.unidades}`).join(', ')}`}
                columnas={a.rangos.map((x) => ({
                  etiqueta: NOMBRE_RANGO[x.rango] ?? x.rango,
                  valor: x.unidades,
                  texto: miles(x.unidades),
                  detalle: `${NOMBRE_RANGO[x.rango] ?? x.rango}: ${unidades(x.unidades)}, ${pesos(x.valor_costo)} a costo, ${miles(x.referencias)} referencias${x.unidades_sin_costo ? `, ${x.unidades_sin_costo} sin costo` : ''}`,
                }))}
              />
            </>
          )}
        </EstadoConsulta>
        <EstadoConsulta consulta={estancado} anidado esVacio={(d) => d.total === 0} vacio="">
          {(d) => (
            <>
              <div className="panel-tabla">
                <table className="tabla-apilable">
                  <caption className="solo-lector">Referencias sin movimiento</caption>
                  <thead>
                    <tr>
                      <th>Referencia</th><th className="col-numero">Existencia</th><th className="col-numero">Valor a costo</th>
                      <th>Última venta</th><th className="col-numero">Días sin vender</th><th>En stock desde</th><th>Su universal</th>
                    </tr>
                  </thead>
                  <tbody>
                    {d.data.map((f) => (
                      <tr key={f.referencia.id}>
                        <td data-etiqueta="Referencia">{celdaRef(f.referencia)}</td>
                        <td data-etiqueta="Existencia" className="col-numero">{miles(f.existencia)}</td>
                        <td data-etiqueta="Valor a costo" className="col-numero">{pesos(f.valor_costo)}{f.unidades_sin_costo > 0 && <small> ({f.unidades_sin_costo} sin costo)</small>}</td>
                        <td data-etiqueta="Última venta">{f.ultima_venta ? formatearFecha(f.ultima_venta) : 'Nunca'}</td>
                        <td data-etiqueta="Días sin vender" className="col-numero">{miles(f.dias_sin_vender)}</td>
                        <td data-etiqueta="En stock desde">{formatearFecha(f.en_stock_desde)}</td>
                        <td data-etiqueta="Su universal">{f.universal_rota === null ? '—' : f.universal_rota ? <span className="chip universal-rota">Rota: otra referencia se vendió</span> : 'Tampoco se vende'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <Paginacion pagina={d} onCambiar={setPaginaEstancado} />
            </>
          )}
        </EstadoConsulta>
      </section>

      {/* (5) Rotación */}
      <Bloque
        titulo="Rotación"
        consulta={rotacion}
        esVacio={(d) => d.filas.length === 0}
        vacio="Sin ventas ni existencias para mostrar."
        acciones={<ControlSegmentado id="panel-agrupar" etiqueta="Agrupar" opciones={AGRUPACIONES} valor={agrupar} onCambio={setAgrupar} />}
        ayuda={<AyudaMargen />}
      >
        {(d) => (
          <>
            <BarrasHorizontales
              resumen={`Cobertura en días ${agrupar === 'proveedor' ? 'por proveedor' : 'por categoría'}`}
              filas={d.filas.slice(0, 12).map((f) => ({
                id: f.id,
                etiqueta: f.nombre,
                detalle: `${unidades(f.unidades_vendidas)} vendidas · ${pesos(f.ventas)}`,
                valor: f.cobertura_dias ?? 0,
                texto: f.cobertura_dias === null ? 'Sin rotación' : `${miles(Math.round(f.cobertura_dias))} días`,
                marca: f.sobrestock ? 'Sobrestock' : undefined,
              }))}
            />
            <div className="panel-tabla">
              <table className="tabla-apilable">
                <caption className="solo-lector">Rotación con margen estimado</caption>
                <thead>
                  <tr>
                    <th>{agrupar === 'proveedor' ? 'Proveedor' : 'Categoría'}</th><th className="col-numero">Vendidas</th><th className="col-numero">Ventas</th>
                    <th className="col-numero">Margen $ estimado</th><th className="col-numero">Margen %</th><th className="col-numero">Existencia</th>
                    <th className="col-numero">Valor a costo</th><th className="col-numero">Cobertura</th>
                    {agrupar === 'proveedor' && <th className="col-numero">Compras</th>}
                  </tr>
                </thead>
                <tbody>
                  {d.filas.map((f) => (
                    <tr key={f.id}>
                      <td data-etiqueta={agrupar === 'proveedor' ? 'Proveedor' : 'Categoría'}>{f.nombre}</td>
                      <td data-etiqueta="Vendidas" className="col-numero">{miles(f.unidades_vendidas)}</td>
                      <td data-etiqueta="Ventas" className="col-numero">{pesos(f.ventas)}</td>
                      <td data-etiqueta="Margen $ estimado" className="col-numero">{f.margen === null ? '—' : pesos(f.margen)}</td>
                      <td data-etiqueta="Margen %" className="col-numero">{pct(f.margen_porcentaje)}</td>
                      <td data-etiqueta="Existencia" className="col-numero">{miles(f.existencia)}</td>
                      <td data-etiqueta="Valor a costo" className="col-numero">{pesos(f.valor_costo)}</td>
                      <td data-etiqueta="Cobertura" className="col-numero">
                        {f.cobertura_dias === null ? 'Sin rotación' : `${miles(Math.round(f.cobertura_dias))} días`}
                        {f.sobrestock && <> <span className="chip marca-grafico">Sobrestock</span></>}
                      </td>
                      {agrupar === 'proveedor' && <td data-etiqueta="Compras" className="col-numero">{pesos(f.compras ?? 0)}</td>}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </>
        )}
      </Bloque>

      {/* (6) Control */}
      <Bloque titulo="Control" consulta={control}>
        {(c) => <BloqueControl c={c} />}
      </Bloque>

      {fichaId !== null && ficha.isError && <p className="aviso error" role="alert">{mensajeError(ficha.error)}</p>}
      {fichaId !== null && ficha.data && (
        <FichaReferencia
          modo="catalogo"
          referencia={ficha.data}
          onCerrar={() => setFichaId(null)}
          onGuardada={() => { setFichaId(null); qc.invalidateQueries({ queryKey: ['panel'] }); qc.invalidateQueries({ queryKey: ['referencias'] }) }}
        />
      )}
    </div>
  )
}

function BloqueControl({ c }: { c: PanelControl }) {
  const bajas = c.ajustes.filter((a) => a.tipo === 'baja')
  const otros = c.ajustes.filter((a) => a.tipo !== 'baja')
  const sumar = (lista: typeof c.ajustes) => lista.reduce((s, a) => s + Number(a.valor_costo), 0)

  return (
    <div className="panel-control">
      <article className="panel-control-tarjeta">
        <h3>Bajas de inventario</h3>
        <p className="panel-control-cifra">{unidades(bajas.reduce((s, a) => s + a.unidades, 0))} · {pesos(sumar(bajas))} a costo</p>
        {bajas.length > 0 && (
          <ul className="lista">
            {bajas.map((a) => <li key={a.motivo}>{NOMBRE_MOTIVO[a.motivo as MotivoAjuste] ?? a.motivo}: {unidades(a.unidades)} · {pesos(a.valor_costo)}</li>)}
          </ul>
        )}
        {otros.length > 0 && (
          <ul className="lista">
            {otros.map((a) => (
              <li key={a.tipo + a.motivo}>
                {a.tipo === 'alta' ? 'Alta' : 'Reintegro'} por {NOMBRE_MOTIVO[a.motivo as MotivoAjuste] ?? a.motivo}: {unidades(a.unidades)}
                {a.unidades_sin_costo > 0 ? ` (${a.unidades_sin_costo} sin costo)` : ` · ${pesos(a.valor_costo)}`}
              </li>
            ))}
          </ul>
        )}
        <Link to={RUTA_AJUSTES}>Historial de ajustes</Link>
      </article>
      <article className="panel-control-tarjeta">
        <h3>Reversos</h3>
        <p className="panel-control-cifra">{miles(c.reversos.devolucion.reversos)} devoluciones · {pesos(c.reversos.devolucion.valor)}</p>
        <p>{miles(c.reversos.anulacion.reversos)} anulaciones · {pesos(c.reversos.anulacion.valor)} ({unidades(c.reversos.anulacion.items)})</p>
        <Link to="/admin/auditoria">Auditoría</Link>
      </article>
      <article className="panel-control-tarjeta">
        <h3>Capturas digitadas</h3>
        <p className="panel-control-cifra">{pct(c.capturas.porcentaje_digitado)}</p>
        <p>{miles(c.capturas.digitadas)} de {unidades(c.capturas.total)} vendidas se digitaron en vez de escanearse.</p>
      </article>
      <article className="panel-control-tarjeta">
        <h3>Ventas por usuario</h3>
        {c.ventas_por_usuario.length === 0 ? <p className="vacio">Sin ventas en el período.</p> : (
          <ul className="lista">
            {c.ventas_por_usuario.map((u) => <li key={u.usuario.id}><strong>{u.usuario.nombre}</strong>: {miles(u.ventas)} ventas · {pesos(u.total)}</li>)}
          </ul>
        )}
      </article>
      <article className="panel-control-tarjeta">
        <h3>Ventas abiertas de días anteriores</h3>
        <p className="panel-control-cifra">{miles(c.ventas_abiertas_viejas)}</p>
        <p>Abiertas desde antes de hoy (ahora, sin importar el período).</p>
      </article>
      <article className="panel-control-tarjeta">
        <h3>Cambios de precio a crédito</h3>
        <p className="panel-control-cifra">{miles(c.cambios_precio_credito)}</p>
        <Link to="/admin/auditoria?tabla=venta_items">Ver en la auditoría</Link>
      </article>
      <article className="panel-control-tarjeta">
        <h3>Días sin ventas</h3>
        <p className="panel-control-cifra">{miles(c.dias_sin_ventas)} de {miles(c.dias)}</p>
        <Link to={RUTA_CARTERA}>Cartera</Link>
      </article>
    </div>
  )
}

function Tarjeta({ etiqueta, valor, lineas = [], variacion, ayuda, sufijo, etiquetaValor, enlace }: {
  etiqueta: string
  valor: string
  lineas?: string[]
  /** % contra el período anterior; null = sin base para comparar. undefined = no aplica. */
  variacion?: number | null
  ayuda?: ReactNode
  /** Palabra tras la cifra ("estimado", "a costo"). */
  sufijo?: string
  etiquetaValor?: string
  enlace?: { a: string; texto: string }
}) {
  return (
    <article className="panel-medida">
      <span className="panel-medida-etiqueta">{etiqueta}{ayuda}</span>
      <strong className="panel-medida-valor" aria-label={etiquetaValor}>{valor}{sufijo && <small> {sufijo}</small>}</strong>
      {variacion !== undefined && <Variacion valor={variacion} />}
      {lineas.map((l) => <span key={l} className="panel-medida-linea">{l}</span>)}
      {enlace && <Link to={enlace.a} className="panel-medida-enlace">{enlace.texto}</Link>}
    </article>
  )
}

/** Variación con flecha y texto (no solo color). */
function Variacion({ valor }: { valor: number | null }) {
  if (valor === null) return <span className="panel-variacion neutra">Sin base para comparar</span>
  const clase = valor > 0 ? 'sube' : valor < 0 ? 'baja' : 'neutra'
  const flecha = valor > 0 ? '▲' : valor < 0 ? '▼' : '='
  return <span className={`panel-variacion ${clase}`}><span aria-hidden>{flecha}</span> {valor > 0 ? 'Sube' : valor < 0 ? 'Baja' : 'Igual'} {pct(Math.abs(valor))} vs. período anterior</span>
}

function AyudaMargen() {
  const id = useId()
  return (
    <span className="ayuda-margen">
      <button type="button" className="ayuda-icono" aria-label="¿Qué es el margen estimado?" aria-describedby={id}>?</button>
      <span role="tooltip" id={id} className="ayuda-burbuja">{TEXTO_MARGEN}</span>
    </span>
  )
}

function Actualizado({ generado }: { generado?: string }) {
  const [, setTic] = useState(0)
  useEffect(() => {
    const t = setInterval(() => setTic((n) => n + 1), 30_000)
    return () => clearInterval(t)
  }, [])
  if (!generado) return <span className="ayuda">Calculando…</span>
  const minutos = Math.max(0, Math.floor((Date.now() - new Date(generado).getTime()) / 60_000))
  return <span className="ayuda" aria-live="polite">Actualizado {minutos === 0 ? 'hace menos de un minuto' : `hace ${minutos} min`}</span>
}

/** Bloque con título, acciones, esqueleto de carga, vacío y error aislado (los demás bloques siguen). */
function Bloque<T>({ titulo, consulta, children, esVacio, vacio = 'Sin datos en el período.', acciones, ayuda, sinTitulo = false, alto = 180 }: {
  titulo: string
  consulta: UseQueryResult<T>
  children: (datos: T) => ReactNode
  esVacio?: (datos: T) => boolean
  vacio?: string
  acciones?: ReactNode
  ayuda?: ReactNode
  sinTitulo?: boolean
  alto?: number
}) {
  const id = useId()
  return (
    <section className={sinTitulo ? 'panel-bloque sin-caja' : 'panel-bloque'} aria-labelledby={id}>
      <div className={sinTitulo ? 'solo-lector' : 'panel-bloque-cabeza'}>
        <div className="panel-titulo"><h2 id={id}>{titulo}</h2>{ayuda}</div>
        {acciones && <div className="panel-acciones">{acciones}</div>}
      </div>
      <EstadoConsulta consulta={consulta} esVacio={esVacio} vacio={vacio} alto={alto}>{children}</EstadoConsulta>
    </section>
  )
}

function EstadoConsulta<T>({ consulta, children, esVacio, vacio = 'Sin datos en el período.', alto = 180, anidado = false }: {
  consulta: UseQueryResult<T>
  children: (datos: T) => ReactNode
  esVacio?: (datos: T) => boolean
  vacio?: string
  alto?: number
  anidado?: boolean
}) {
  if (consulta.isError) {
    return (
      <div className="aviso error" role="alert">
        No se pudo cargar: {mensajeError(consulta.error)}{' '}
        <button type="button" className="enlace" onClick={() => consulta.refetch()}>Reintentar</button>
      </div>
    )
  }
  if (consulta.data === undefined) {
    return <div className="esqueleto" style={{ height: alto }} role="status" aria-label="Cargando" />
  }
  if (esVacio?.(consulta.data)) return vacio ? <p className={anidado ? 'vacio' : 'vacio panel-vacio'}>{vacio}</p> : null
  return <div className={consulta.isFetching ? 'panel-contenido actualizando' : 'panel-contenido'}>{children(consulta.data)}</div>
}
