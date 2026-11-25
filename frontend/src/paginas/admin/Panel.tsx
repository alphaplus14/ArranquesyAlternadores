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
import Icono, { type IconoNombre } from '../../componentes/Icono'
import FichaReferencia from '../../componentes/FichaReferencia'
import FiltroPeriodo, { type ValorPeriodo } from '../../componentes/FiltroPeriodo'
import Paginacion from '../../componentes/Paginacion'
import SelectorProveedor from '../../componentes/SelectorProveedor'
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
const DIAS_MIN = 30
const DIAS_MAX = 730

function esOrden(valor: string | null): valor is OrdenMasVendidos {
  return valor === 'unidades' || valor === 'valor' || valor === 'margen'
}

/** Entero dentro de un rango, o null si la URL trae otra cosa. */
function leerEntero(raw: string | null, min: number, max: number): number | null {
  if (!raw || !/^\d+$/.test(raw)) return null
  const n = Number(raw)
  return n >= min && n <= max ? n : null
}
const NOMBRE_RANGO: Record<string, string> = { '0-30': '0-30 d', '31-90': '31-90 d', '91-180': '91-180 d', '181-365': '181-365 d', 'mas-365': '> 365 d' }
const NOMBRE_REABASTECER = { agotada: 'Agotada', bajo_minimo: 'Bajo mínimo', cobertura_corta: 'Cobertura corta' } as const

type Filtros = { desde?: string; hasta?: string; categoria_id?: string; proveedor_id?: string }

const pct = (n: number | null | undefined) => (n === null || n === undefined ? '—' : `${n.toLocaleString('es-CO', { maximumFractionDigits: 1 })} %`)
const miles = (n: number) => n.toLocaleString('es-CO')
/** Conteo con su sustantivo en singular o plural: "1 venta", "3 ventas". */
const cuenta = (n: number, singular: string, plural: string) => `${miles(n)} ${n === 1 ? singular : plural}`
const unidades = (n: number) => cuenta(n, 'unidad', 'unidades')
const textoDias = (n: number) => cuenta(n, 'día', 'días')
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

  // Orden, agrupación, días y página van en la misma URL que el período. El valor por
  // defecto no se escribe: así el enlace compartido solo lleva lo que el usuario cambió.
  const ordenParam = params.get('orden')
  const orden: OrdenMasVendidos = esOrden(ordenParam) ? ordenParam : 'unidades'
  const agrupar = params.get('agrupar') === 'proveedor' ? 'proveedor' : 'categoria'
  const diasUrl = leerEntero(params.get('dias'), DIAS_MIN, DIAS_MAX)
  const paginaEstancado = leerEntero(params.get('page'), 2, 10_000) ?? 1

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
    page: null,
  })

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
  const dias = diasUrl ?? configurados.data ?? null

  const resumen = usePanel<PanelResumen>('resumen', filtros, {}, forzar, activo)
  const porDia = usePanel<PanelVentasPorDia>('ventas-por-dia', filtros, {}, forzar, activo)
  const masVendidos = usePanel<PanelMasVendidos>('mas-vendidos', filtros, { orden, limite: 10 }, forzar, activo)
  const reabastecer = usePanel<PanelReabastecer>('reabastecer', filtros, {}, forzar, activo)
  const estancado = usePanel<PanelSinMovimiento>('sin-movimiento', filtros, { dias: dias ?? 0, page: paginaEstancado }, forzar, activo && dias !== null)
  const antiguedad = usePanel<PanelAntiguedad>('antiguedad-stock', filtros, {}, forzar, activo)
  const rotacion = usePanel<PanelRotacion>('rotacion', filtros, { agrupar }, forzar, activo)
  const control = usePanel<PanelControl>('control', filtros, {}, forzar, activo)

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
        <button type="button" className="enlace" translate="no" onClick={() => setFichaId(r.id)} title={r.descripcion}>{etiqueta}</button>
        <small>{detalle}</small>
      </span>
    )
  }

  return (
    <div className="pila panel-gestion">
      <div className="pagina-encabezado">
        <h1>Panel de gestión</h1>
        <p>Lo vendido, lo que hay que reponer y lo que lleva tiempo quieto.</p>
      </div>

      <section className="tarjeta-pagina panel-filtros-caja" aria-label="Filtros del panel">
        <div className="formulario en-linea panel-filtros">
          <FiltroPeriodo id="panel-periodo" valor={periodo} onCambio={cambiarPeriodo} periodos={PERIODOS_PANEL} />
          <label>Categoría
            <select name="categoria" autoComplete="off" value={categoriaId} onChange={(e) => cambiar({ categoria: e.target.value || null, page: null })}>
              <option value="">Todas</option>
              {categorias.data?.map((c) => <option key={c.id} value={c.id}>{c.nombre}</option>)}
            </select>
          </label>
          <label>Proveedor
            <SelectorProveedor
              className="filtro"
              aria-label="Proveedor"
              value={proveedorId}
              onChange={(v) => cambiar({ proveedor: v || null, page: null })}
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
      <Bloque titulo="Resumen" consulta={resumen} plano tituloOculto alto={120}>
        {(r) => (
          <div className="panel-medidas">
            <Tarjeta tono="azul" icono="venta" etiqueta="Ventas" valor={pesos(r.ventas.total)} variacion={r.ventas.variacion_total}
              lineas={[`${cuenta(r.ventas.ventas, 'venta', 'ventas')} · ticket ${pesos(r.ventas.ticket_promedio)}`, `Contado ${pesos(r.ventas.contado)} · crédito ${pesos(r.ventas.credito)}`]} />
            <Tarjeta
              tono="naranja" icono="panel"
              etiqueta="Margen estimado"
              ayuda={<AyudaMargen />}
              valor={r.margen.margen === null ? '—' : `${pesos(r.margen.margen)}`}
              etiquetaValor={r.margen.margen === null ? 'Margen estimado sin datos de costo' : `Margen estimado ${pesos(r.margen.margen)}, ${pct(r.margen.margen_porcentaje)}`}
              sufijo="estimado"
              negativo={r.margen.margen !== null && Number(r.margen.margen) < 0}
              variacion={r.margen.variacion}
              lineas={[`${pct(r.margen.margen_porcentaje)} sin IVA`]}
            />
            <Tarjeta tono="teal" icono="inventario" etiqueta="Inventario" valor={pesos(r.inventario.valor_costo)} sufijo="a costo"
              lineas={[unidades(r.inventario.unidades)]} />
            <Tarjeta tono="violeta" icono="ingreso" etiqueta="Compras" valor={pesos(r.compras.total)} lineas={['Del período']} />
            <Tarjeta tono="verde" icono="billetera" etiqueta="Por cobrar" valor={pesos(r.cartera.por_cobrar)} lineas={[`Vencida ${pesos(r.cartera.vencida)}`]} enlace={{ a: RUTA_CARTERA, texto: 'Abrir cartera' }} />
            <Tarjeta tono="rosa" icono="caja" etiqueta="Por pagar" valor={pesos(r.cuentas_por_pagar.por_pagar)} lineas={[`Vencidas ${pesos(r.cuentas_por_pagar.vencidas)}`]} />
            <Tarjeta tono="rojo" icono="agotados" etiqueta="Agotadas" valor={miles(r.agotados)} lineas={['Activas en cero']} enlace={{ a: '/agotados', texto: 'Ver agotados' }} />
            <Tarjeta tono="ambar" icono="configuracion" etiqueta="Bajo mínimo" valor={miles(r.bajo_minimo)} lineas={['En el mínimo o por debajo']} enlace={{ a: '/agotados', texto: 'Ver mínimos' }} />
          </div>
        )}
      </Bloque>

      {/* (2) Ventas por día */}
      <div className="panel-duo">
      <Bloque titulo="Ventas por día" consulta={porDia} esVacio={(d) => d.totales.ventas === 0} vacio="Sin ventas en el período." alto={200}>
        {(d) => (
          <>
            <p className="ayuda">Total {pesos(d.totales.total)}: contado {pesos(d.totales.contado)}, crédito {pesos(d.totales.credito)} · {unidades(d.totales.unidades)}.</p>
            <GraficoAreas
              alto={180}
              leyendaArriba
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
        <Bloque titulo="Reabastecer" consulta={reabastecer} esVacio={(d) => d.filas.length === 0} vacio="Nada urgente: ninguna referencia que rote está agotada, bajo mínimo o por acabarse.">
          {(d) => (
            <>
              <ul className="panel-reponer">
                {d.filas.map((f) => (
                  <li key={f.referencia.id}>
                    {celdaRef(f.referencia)}
                    <span className={`chip motivo-${f.motivo}`}>{NOMBRE_REABASTECER[f.motivo]}</span>
                    <span className="panel-reponer-dato">{miles(f.existencia)} en stock · {cuenta(f.vendidas, 'vendida', 'vendidas')} · {f.existencia === 0 ? textoDias(0) : textoDias(Math.round(f.cobertura_dias))}</span>
                  </li>
                ))}
              </ul>
              {d.total > d.filas.length && <p className="ayuda">Se muestran {d.filas.length} de {d.total}.</p>}
            </>
          )}
        </Bloque>
      </div>

      <Bloque
          titulo="Más vendidos"
          consulta={masVendidos}
          diferido
          esVacio={(d) => d.filas.length === 0}
          vacio="Sin ventas en el período."
          acciones={<ControlSegmentado id="panel-orden" etiqueta="Ordenar por" opciones={ORDENES} valor={orden} onCambio={(v) => cambiar({ orden: v === 'unidades' ? null : v })} />}
          ayuda={orden === 'margen' ? <AyudaMargen /> : undefined}
        >
          {(d) => (
            <>
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

      {/* (4) Stock estancado */}
      <section className="panel-bloque panel-diferido" aria-labelledby="panel-estancado">
        <div className="panel-bloque-cabeza">
          <h2 id="panel-estancado">Stock estancado</h2>
          <div className="panel-acciones">
            <label className="panel-dias">Sin vender en
              <select name="dias_sin_vender" autoComplete="off" value={dias ?? ''} onChange={(e) => {
                const n = Number(e.target.value)
                cambiar({ dias: n === configurados.data ? null : String(n), page: null })
              }} disabled={dias === null}>
                {[...new Set([...DIAS_ESTANCADO, ...(configurados.data ? [configurados.data] : []), ...(diasUrl ? [diasUrl] : [])])].sort((a, b) => a - b).map((n) => (
                  <option key={n} value={n}>{n} días{n === configurados.data ? ' (por defecto)' : ''}</option>
                ))}
              </select>
            </label>
            <Link to="/admin/configuracion#panel-gestion">Cambiar valor por defecto</Link>
          </div>
        </div>
        <div className="panel-stock">
        <EstadoConsulta consulta={estancado} vacio="Ninguna referencia con existencia lleva tanto tiempo sin venderse." esVacio={(d) => d.total === 0} alto={60} anidado>
          {(d) => (
            <p className="panel-total-estancado">
              <strong>{miles(d.totales.referencias)}</strong> {d.totales.referencias === 1 ? 'referencia' : 'referencias'} · <strong>{unidades(d.totales.unidades)}</strong> · <strong>{pesos(d.totales.valor_costo)}</strong> a costo
              {d.totales.unidades_sin_costo > 0 && <> · {miles(d.totales.unidades_sin_costo)} sin costo</>}
              <small> (sin venta desde el {formatearFecha(d.desde_limite)})</small>
            </p>
          )}
        </EstadoConsulta>
        <EstadoConsulta consulta={antiguedad} anidado alto={160}>
          {(a) => (
            <>
              <h3 className="panel-subtitulo">Antigüedad del stock disponible</h3>
              <Columnas
                resumen={`Unidades disponibles por antigüedad: ${a.rangos.map((x) => `${NOMBRE_RANGO[x.rango] ?? x.rango} ${x.unidades}`).join(', ')}`}
                columnas={a.rangos.map((x) => ({
                  etiqueta: NOMBRE_RANGO[x.rango] ?? x.rango,
                  valor: x.unidades,
                  texto: miles(x.unidades),
                  detalle: `${NOMBRE_RANGO[x.rango] ?? x.rango}: ${unidades(x.unidades)}, ${pesos(x.valor_costo)} a costo, ${cuenta(x.referencias, 'referencia', 'referencias')}${x.unidades_sin_costo ? `, ${x.unidades_sin_costo} sin costo` : ''}`,
                }))}
              />
            </>
          )}
        </EstadoConsulta>
        </div>
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
              <Paginacion pagina={d} onCambiar={(n) => cambiar({ page: n <= 1 ? null : String(n) })} />
            </>
          )}
        </EstadoConsulta>
      </section>

      {/* (5) Rotación */}
      <Bloque
        titulo="Rotación"
        consulta={rotacion}
        diferido
        esVacio={(d) => d.filas.length === 0}
        vacio="Sin ventas ni existencias para mostrar."
        acciones={<ControlSegmentado id="panel-agrupar" etiqueta="Agrupar" opciones={AGRUPACIONES} valor={agrupar} onCambio={(v) => cambiar({ agrupar: v === 'categoria' ? null : v })} />}
        ayuda={<AyudaMargen />}
      >
        {(d) => (
          <>
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
                        {f.cobertura_dias === null ? 'Sin rotación' : textoDias(Math.round(f.cobertura_dias))}
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
      <Bloque titulo="Control" consulta={control} plano diferido>
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
      <article className="panel-control-tarjeta tono-naranja">
        <h3><span className="panel-medida-icono tono-naranja"><Icono nombre="caja" /></span>Bajas de inventario</h3>
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
        <Link to={RUTA_AJUSTES}>Historial de movimientos</Link>
      </article>
      <article className="panel-control-tarjeta tono-rojo">
        <h3><span className="panel-medida-icono tono-rojo"><Icono nombre="reactivar" /></span>Reversos</h3>
        <p className="panel-control-cifra">{cuenta(c.reversos.devolucion.reversos, 'devolución', 'devoluciones')} · {pesos(c.reversos.devolucion.valor)}</p>
        <p>{cuenta(c.reversos.anulacion.reversos, 'anulación', 'anulaciones')} · {pesos(c.reversos.anulacion.valor)} ({unidades(c.reversos.anulacion.items)})</p>
        <Link to="/admin/auditoria">Auditoría</Link>
      </article>
      <article className="panel-control-tarjeta tono-teal">
        <h3><span className="panel-medida-icono tono-teal"><Icono nombre="etiqueta" /></span>Capturas digitadas</h3>
        <p className="panel-control-cifra">{pct(c.capturas.porcentaje_digitado)}</p>
        <p>{miles(c.capturas.digitadas)} de {unidades(c.capturas.total)} {c.capturas.total === 1 ? 'vendida' : 'vendidas'} {c.capturas.digitadas === 1 ? 'se digitó' : 'se digitaron'} en vez de escanearse.</p>
      </article>
      <article className="panel-control-tarjeta tono-azul">
        <h3><span className="panel-medida-icono tono-azul"><Icono nombre="usuarios" /></span>Ventas por usuario</h3>
        {c.ventas_por_usuario.length === 0 ? <p className="vacio">Sin ventas en el período.</p> : (
          <ul className="lista">
            {c.ventas_por_usuario.map((u) => <li key={u.usuario.id}><strong>{u.usuario.nombre}</strong>: {cuenta(u.ventas, 'venta', 'ventas')} · {pesos(u.total)}</li>)}
          </ul>
        )}
      </article>
      <article className="panel-control-tarjeta tono-ambar">
        <h3><span className="panel-medida-icono tono-ambar"><Icono nombre="venta" /></span>Ventas abiertas de días anteriores</h3>
        <p className="panel-control-cifra">{miles(c.ventas_abiertas_viejas)}</p>
        <p>Abiertas desde antes de hoy (ahora, sin importar el período).</p>
      </article>
      <article className="panel-control-tarjeta tono-violeta">
        <h3><span className="panel-medida-icono tono-violeta"><Icono nombre="editar" /></span>Cambios de precio a crédito</h3>
        <p className="panel-control-cifra">{miles(c.cambios_precio_credito)}</p>
        <Link to="/admin/auditoria?tabla=venta_items">Ver en la auditoría</Link>
      </article>
      <article className="panel-control-tarjeta tono-rosa">
        <h3><span className="panel-medida-icono tono-rosa"><Icono nombre="panel" /></span>Días sin ventas</h3>
        <p className="panel-control-cifra">{miles(c.dias_sin_ventas)} de {miles(c.dias)}</p>
        <Link to={RUTA_CARTERA}>Cartera</Link>
      </article>
    </div>
  )
}

type Tono = 'azul' | 'naranja' | 'teal' | 'violeta' | 'verde' | 'rosa' | 'rojo' | 'ambar'

function Tarjeta({ tono, icono, etiqueta, valor, lineas = [], variacion, ayuda, sufijo, etiquetaValor, enlace, negativo = false }: {
  tono: Tono
  icono: IconoNombre
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
  /** La cifra en sí es negativa: va en rojo y un «sube» no se pinta de verde. */
  negativo?: boolean
}) {
  return (
    <article className={`panel-medida tono-${tono}`}>
      <span className={`panel-medida-icono tono-${tono}`}><Icono nombre={icono} /></span>
      <div className="panel-medida-cuerpo">
        <span className="panel-medida-etiqueta">{etiqueta}{ayuda}</span>
        <strong className={negativo ? 'panel-medida-valor negativo' : 'panel-medida-valor'} aria-label={etiquetaValor}>{valor}{sufijo ? <small> {sufijo}</small> : null}</strong>
        {variacion !== undefined ? <Variacion valor={variacion} sinSubida={negativo} /> : null}
        {lineas[0] ? <span className="panel-medida-linea">{lineas[0]}</span> : null}
        {lineas[1] ? <span className="panel-medida-linea">{lineas[1]}</span> : null}
        {enlace ? <Link to={enlace.a} className="panel-medida-enlace">{enlace.texto}</Link> : null}
      </div>
    </article>
  )
}

/** Variación con flecha y texto (no solo color). */
function Variacion({ valor, sinSubida = false }: { valor: number | null; sinSubida?: boolean }) {
  if (valor === null) return <span className="panel-variacion neutra">Sin base para comparar</span>
  const clase = valor < 0 ? 'baja' : valor > 0 && !sinSubida ? 'sube' : 'neutra'
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
function Bloque<T>({ titulo, consulta, children, esVacio, vacio = 'Sin datos en el período.', acciones, ayuda, plano = false, tituloOculto = false, diferido = false, alto = 180 }: {
  titulo: string
  consulta: UseQueryResult<T>
  children: (datos: T) => ReactNode
  esVacio?: (datos: T) => boolean
  vacio?: string
  acciones?: ReactNode
  ayuda?: ReactNode
  /** Sin caja propia: las tarjetas hijas son la superficie. */
  plano?: boolean
  /** El título queda para el lector de pantalla. Las tarjetas ya se nombran solas. */
  tituloOculto?: boolean
  /** Debajo del pliegue: el navegador aplaza el layout hasta que el bloque se acerca. */
  diferido?: boolean
  alto?: number
}) {
  const id = useId()
  const clase = [plano ? 'panel-bloque sin-caja' : 'panel-bloque', diferido ? 'panel-diferido' : ''].filter(Boolean).join(' ')
  return (
    <section className={clase} aria-labelledby={id}>
      <div className={tituloOculto ? 'solo-lector' : 'panel-bloque-cabeza'}>
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
