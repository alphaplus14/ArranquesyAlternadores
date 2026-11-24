import { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { api, mensajeError } from '../api/cliente'
import { NOMBRE_CALIDAD, type MetodoCaptura, type Pagina, type Referencia } from '../api/tipos'
import { useAviso } from '../componentes/Avisos'
import ChipCalidad from '../componentes/ChipCalidad'
import FichaReferencia from '../componentes/FichaReferencia'
import { useAuth } from '../auth/AuthProvider'
import Icono from '../componentes/Icono'
import Paginacion from '../componentes/Paginacion'
import SelectorProveedor from '../componentes/SelectorProveedor'
import Resumen from '../componentes/Resumen'
import { useDebounce } from '../hooks/useDebounce'
import { useListaPaginada } from '../hooks/useListaPaginada'
import { useScanner } from '../hooks/useScanner'
import { useVentana } from '../hooks/useVentana'
import { useAncho } from '../hooks/useAncho'

/** Filas por petición. El tope del API es 100; el DOM solo pinta las visibles. */
const POR_PAGINA = 100
const ALTO_GRANDE = 56
const ALTO_COMPACTO = 40
const CLAVE_FILAS = 'productos-filas'

function filasCompactasGuardadas(): boolean {
  try {
    return localStorage.getItem(CLAVE_FILAS) === 'compactas'
  } catch {
    return false
  }
}

/**
 * Modo del listado según el ancho de la tarjeta (no de la ventana):
 * - completa (≥ 1100 px): todas las columnas;
 * - media (760–1099 px): sin Categoría ni IVA (se ven al editar);
 * - tarjetas (< 760 px): cada producto es una tarjeta de alto fijo (la lista sigue virtualizada).
 */
type Modo = 'completa' | 'media' | 'tarjetas'
const modoDe = (ancho: number): Modo => (ancho === 0 || ancho >= 1100 ? 'completa' : ancho >= 760 ? 'media' : 'tarjetas')
/** Alto de cada tarjeta, con su separación: cabecera + 5 pares + acciones (admin). */
const ALTO_TARJETA = { admin: 236, otro: 192 }

/** Columnas por modo. La Descripción es la única flexible; el resto, ancho fijo. */
function columnasDe(modo: Modo, esAdmin: boolean) {
  const acciones = esAdmin ? ' 92px' : ''
  if (modo === 'completa') return `176px minmax(0, 1fr) 150px 120px 104px 72px 88px${acciones}`
  return `150px minmax(0, 1fr) 132px 104px 88px${acciones}`
}

type FichaCatalogo = { tipo: 'nueva' } | { tipo: 'editar'; referencia: Referencia }

/** S-18: ningún precio puede ser 0. Las referencias antiguas en 0 no se pueden vender hasta asignarles uno. */
function sinPrecio(r: Referencia) {
  return Number(r.precio_venta) === 0
}

interface Lectura { codigo: string; metodo: MetodoCaptura; titulo: string; detalle: string }
interface Busqueda {
  unidad: { codigo_barras: string; estado: string; referencia?: Referencia } | null
  referencias: Referencia[] | { data: Referencia[] }
}

function comoLista(valor: Busqueda['referencias'] | undefined): Referencia[] {
  if (!valor) return []
  return Array.isArray(valor) ? valor : valor.data ?? []
}

async function traerReferencias(termino: string, proveedorId: string, pagina: number) {
  const { data } = await api.get<Pagina<Referencia>>('/referencias', {
    params: { q: termino || undefined, proveedor_id: proveedorId || undefined, page: pagina, por_pagina: POR_PAGINA },
  })
  return data
}

/**
 * RF-01: catálogo de referencias, paginado en el servidor.
 * RF-12: el escáner localiza la unidad al momento (escaneado o digitado).
 */
export default function Productos() {
  const qc = useQueryClient()
  const avisos = useAviso()
  const { tieneRol } = useAuth()
  const esAdmin = tieneRol('admin')
  const [q, setQ] = useState('')
  const [proveedorId, setProveedorId] = useState('')
  const [resaltada, setResaltada] = useState<number | null>(null)
  /** Código con que el último escaneo filtró la lista (se muestra como chip para quitarlo). */
  const [filtroEscaneo, setFiltroEscaneo] = useState<string | null>(null)
  const [lectura, setLectura] = useState<Lectura | null>(null)
  const [ficha, setFicha] = useState<FichaCatalogo | null>(null)
  const [compactas, setCompactas] = useState(filasCompactasGuardadas)
  const lectorRef = useRef<HTMLInputElement>(null)
  const termino = useDebounce(q.trim())
  const altoFila = compactas ? ALTO_COMPACTO : ALTO_GRANDE
  const altoFilaRef = useRef(altoFila)
  altoFilaRef.current = altoFila

  const { filas, resultado, info, pagina, irA, cargando } = useListaPaginada<Referencia>({
    clave: ['referencias', termino, proveedorId, POR_PAGINA],
    filtros: [q, proveedorId],
    prefetch: true,
    traer: (n) => traerReferencias(termino, proveedorId, n),
  })

  const agotadosPagina = filas.filter((r) => r.existencia === 0).length
  const tarjeta = useAncho<HTMLElement>()
  const modo = modoDe(tarjeta.ancho)
  const alto = modo === 'tarjetas' ? (esAdmin ? ALTO_TARJETA.admin : ALTO_TARJETA.otro) : altoFila
  const ventana = useVentana(filas, alto)

  const scroller = ventana.ref
  const sincronizar = ventana.sincronizar
  const altoAnterior = useRef(altoFila)
  useEffect(() => {
    const el = scroller.current
    if (!el) return
    el.scrollTop = 0
    sincronizar()
  }, [pagina, termino, scroller, sincronizar])
  useLayoutEffect(() => {
    if (modo === 'tarjetas') return
    const el = scroller.current
    const anterior = altoAnterior.current
    if (!el || anterior === altoFila) return
    el.scrollTop = (el.scrollTop / anterior) * altoFila
    altoAnterior.current = altoFila
    sincronizar()
  }, [altoFila, modo, scroller, sincronizar])
  useEffect(() => {
    const el = scroller.current
    if (!el || resaltada == null) return
    const indice = filas.findIndex((r) => r.id === resaltada)
    if (indice < 0) return
    el.scrollTop = indice * (modo === 'tarjetas' ? alto : altoFilaRef.current)
    sincronizar()
  }, [resaltada, filas, alto, modo, scroller, sincronizar])

  const elegirTamano = (siguiente: boolean) => {
    setCompactas(siguiente)
    try {
      localStorage.setItem(CLAVE_FILAS, siguiente ? 'compactas' : 'grandes')
    } catch { /* el navegador puede bloquear el almacenamiento */ }
  }

  const localizar = useMutation({
    mutationFn: async ({ codigo, metodo }: { codigo: string; metodo: MetodoCaptura }) => {
      const { data } = await api.get<Busqueda>('/buscar', { params: { q: codigo } })
      return { data, codigo, metodo }
    },
    onSuccess: ({ data, codigo, metodo }) => {
      const referencia = data.unidad?.referencia ?? comoLista(data.referencias)[0]
      if (!referencia) {
        setResaltada(null)
        setLectura({ codigo, metodo, titulo: 'Sin coincidencia', detalle: 'No está en el catálogo' })
        avisos.mostrar('error', `Sin resultados para ${codigo}.`)
        return
      }
      setResaltada(referencia.id)
      setQ(referencia.codigo_referencia)
      setFiltroEscaneo(referencia.codigo_referencia)
      irA(1)
      const vendida = data.unidad?.estado === 'vendida'
      const leido = data.unidad?.codigo_barras ?? codigo
      // S-23: sin marca; el proveedor y la calidad distinguen la referencia.
      const calidad = referencia.calidad ? NOMBRE_CALIDAD[referencia.calidad] : null
      const origen = [referencia.proveedor?.nombre, calidad].filter(Boolean).join(' · ')
      setLectura({
        codigo: leido,
        metodo,
        titulo: referencia.codigo_referencia,
        detalle: vendida ? 'Unidad vendida' : `${origen ? `${origen} · ` : ''}existencia ${referencia.existencia}`,
      })
      // El código leído no se repite si es el mismo de la referencia (p. ej. el de la caja).
      const que = leido === referencia.codigo_referencia ? leido : `${leido} → ${referencia.codigo_referencia}`
      const partes = [que, referencia.descripcion, referencia.proveedor?.nombre, calidad, `existencia ${referencia.existencia}`]
      avisos.mostrar(vendida ? 'atencion' : 'ok', `${vendida ? 'Unidad vendida · ' : ''}${metodo === 'escaneado' ? 'Escaneado' : 'Digitado'} · ${partes.filter(Boolean).join(' · ')}`)
    },
    onError: (e) => {
      avisos.mostrar('error', mensajeError(e))
    },
  })

  const onCodigo = useCallback((codigo: string, metodo: MetodoCaptura) => {
    if (lectorRef.current) lectorRef.current.value = ''
    localizar.mutate({ codigo, metodo })
  }, [localizar])
  const scanner = useScanner(onCodigo)

  /** Quita el filtro (del escaneo o escrito): listado completo y foco en el lector. */
  const quitarFiltro = () => {
    setQ('')
    setResaltada(null)
    setFiltroEscaneo(null)
    irA(1)
    lectorRef.current?.focus()
  }
  const hayFiltroEscaneo = filtroEscaneo !== null && q === filtroEscaneo

  const columnas = columnasDe(modo, esAdmin)
  const completa = modo === 'completa'

  return (
    <div>
      <div className="pagina-encabezado">
        <h1>Productos</h1>
        <p>El catálogo llega por páginas. El escáner localiza la unidad en el momento.</p>
      </div>

      <Resumen
        clave="productos-resumen"
        compacto={(
          <>
            <span><strong>{info ? info.total.toLocaleString('es-CO') : '—'}</strong> {info?.total === 1 ? 'resultado' : 'resultados'}</span>
            <span>Pág. <strong>{info ? `${info.actual}/${info.ultima}` : '—'}</strong></span>
            <span className={resultado && agotadosPagina > 0 ? 'resumen-agotados' : ''}>
              <strong>{resultado ? agotadosPagina : '—'}</strong> agotados
            </span>
            <span>Lectura <strong>{lectura?.titulo ?? '—'}</strong></span>
          </>
        )}
      >
            <article className="medida">
              <div>
                <span className="medida-etiqueta">Resultados</span>
                <strong className="medida-numero">{info ? info.total.toLocaleString('es-CO') : '—'}</strong>
              </div>
              <span className="medida-icono azul"><Icono nombre="caja" /></span>
            </article>
            <article className="medida">
              <div>
                <span className="medida-etiqueta">Página</span>
                <strong className="medida-numero">{info ? `${info.actual} / ${info.ultima}` : '—'}</strong>
              </div>
              <span className="medida-icono amarillo"><Icono nombre="catalogo" /></span>
            </article>
            <article className="medida">
              <div>
                <span className="medida-etiqueta">Agotados en esta página</span>
                <strong className="medida-numero">{resultado ? agotadosPagina : '—'}</strong>
              </div>
              <span className="medida-icono rojo"><Icono nombre="agotados" /></span>
            </article>
            <article className="medida">
              <div>
                <span className="medida-etiqueta">Última lectura</span>
                <strong className="medida-numero medida-codigo">{lectura?.titulo ?? '—'}</strong>
                <span className="medida-pie">{lectura ? `${lectura.codigo} · ${lectura.metodo}` : 'Esperando un código'}</span>
              </div>
              <span className="medida-icono verde"><Icono nombre="etiqueta" /></span>
            </article>
      </Resumen>

      <section ref={tarjeta.ref} className={`tarjeta-pagina listado-${modo}`} style={{ ['--cols' as string]: columnas }}>
        <div className="fila-titulo">
          <div className="fila-titulo-inicio">
            <h2>Listado</h2>
            {modo !== 'tarjetas' && (
              <button
                type="button"
                className="icono-boton"
                aria-pressed={compactas}
                aria-label={compactas ? 'Agrandar filas' : 'Achicar filas'}
                title={compactas ? 'Agrandar filas' : 'Achicar filas'}
                onClick={() => elegirTamano(!compactas)}
              >
                <Icono nombre={compactas ? 'agrandar' : 'achicar'} />
              </button>
            )}
          </div>
          <div className="acciones">
            <div className="busca busca-productos">
              <Icono nombre="buscar" />
              <input
                placeholder="Buscar por código o descripción"
                value={q}
                onChange={(e) => { setQ(e.target.value); setResaltada(null) }}
                aria-label="Buscar productos"
              />
              {q && (
                <button type="button" className="busca-limpiar" aria-label="Limpiar la búsqueda" title="Limpiar la búsqueda" onClick={quitarFiltro}>
                  <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden><path d="M6 6l12 12M18 6L6 18" /></svg>
                </button>
              )}
            </div>
            <SelectorProveedor className="filtro" aria-label="Proveedor" value={proveedorId} onChange={setProveedorId} opcionTodos="Todos los proveedores" />
            {esAdmin && <button type="button" className="primario" onClick={() => setFicha({ tipo: 'nueva' })}>Nueva referencia</button>}
          </div>
        </div>

        <input
          ref={lectorRef}
          className="escaner"
          placeholder="Escanee la etiqueta, o escriba el código y pulse Enter"
          onKeyDown={(e) => {
            // Esc con el lector vacío quita el filtro y vuelve al listado completo.
            if (e.key === 'Escape' && e.currentTarget.value === '' && q !== '') { e.preventDefault(); quitarFiltro(); return }
            scanner.onKeyDown(e)
          }}
          onPaste={scanner.onPaste}
          autoFocus
          autoComplete="off"
          aria-label="Lector de código de barras"
        />
        {hayFiltroEscaneo && (
          <div className="filtro-activo">
            <span className="chip filtro">
              Filtrando por <strong>{filtroEscaneo}</strong>
              <button type="button" aria-label={`Quitar el filtro ${filtroEscaneo} y ver todo el listado`} title="Quitar el filtro (Esc en el lector)" onClick={quitarFiltro}>
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2.25" strokeLinecap="round" aria-hidden><path d="M6 6l12 12M18 6L6 18" /></svg>
              </button>
            </span>
          </div>
        )}

        <div className={compactas && modo !== 'tarjetas' ? 'tabla-contenedor filas-compactas' : 'tabla-contenedor'}>
        <div className={`tabla-scroll${cargando ? ' actualizando' : ''}`} role="table" aria-label="Productos" aria-rowcount={info?.total} ref={ventana.ref} onScroll={ventana.onScroll}>
          <div role="row" className="tabla-rejilla tabla-cabeza">
            <div role="columnheader">Referencia</div>
            <div role="columnheader">Descripción</div>
            <div role="columnheader">Proveedor</div>
            {completa && <div role="columnheader">Categoría</div>}
            <div role="columnheader" className="col-numero">Precio</div>
            {completa && <div role="columnheader" className="col-numero">IVA</div>}
            <div role="columnheader" className="col-numero">Existencia</div>
            {esAdmin && <div role="columnheader" className="col-acciones">Acciones</div>}
          </div>
          <div>
            {filas.length === 0 && !cargando && <div className="vacio">Sin productos para esta búsqueda.</div>}
            {filas.length > 0 && (
              <div style={{ height: ventana.altoTotal }}>
                <div style={{ transform: `translateY(${ventana.desplazamiento}px)` }}>
                  {ventana.visibles.map((r) => (
                    <div
                      role="row"
                      key={r.id}
                      className={`tabla-rejilla tabla-fila${r.id === resaltada ? ' resaltada' : ''}${esAdmin ? ' tabla-fila-editable' : ''}`}
                      style={{ height: alto }}
                      onDoubleClick={esAdmin ? () => setFicha({ tipo: 'editar', referencia: r }) : undefined}
                      title={esAdmin ? 'Doble clic para editar' : undefined}
                    >
                      <div role="cell" className="corta celda-referencia" title={r.codigo_referencia}>
                        <strong>{r.codigo_referencia}</strong>
                        {r.calidad && <> <ChipCalidad calidad={r.calidad} /></>}
                      </div>
                      <div role="cell" data-etiqueta="Descripción" className="corta" title={r.descripcion}>{r.descripcion}</div>
                      <div role="cell" data-etiqueta="Proveedor" className="corta" title={r.proveedor?.nombre}>{r.proveedor?.nombre}</div>
                      {completa && <div role="cell" className="corta" title={r.categoria?.nombre}>{r.categoria?.nombre}</div>}
                      <div role="cell" data-etiqueta="Precio" className="col-numero">{sinPrecio(r) ? <span className="chip sin-precio" title="No se puede vender hasta que el administrador le asigne un precio">Sin precio</span> : Number(r.precio_venta).toLocaleString('es-CO')}</div>
                      {(completa || modo === 'tarjetas') && <div role="cell" data-etiqueta="IVA" className="col-numero">{Number(r.iva_venta_porcentaje) === 0 ? <span className="chip">Sin IVA</span> : `${Number(r.iva_venta_porcentaje)} %`}</div>}
                      <div role="cell" data-etiqueta="Existencia" className={r.existencia === 0 ? 'agotado col-numero' : 'col-numero'}>{r.existencia}</div>
                      {esAdmin && (
                        <div role="cell" className="acciones-fila" onDoubleClick={(e) => e.stopPropagation()}>
                          <button
                            type="button"
                            className="icono-accion"
                            aria-label={`Editar ${r.codigo_referencia}`}
                            title="Editar"
                            onClick={() => setFicha({ tipo: 'editar', referencia: r })}
                          >
                            <Icono nombre="editar" />
                          </button>
                          <Link
                            className="icono-accion"
                            to={`/admin/auditoria?tabla=referencias&registro_id=${r.id}`}
                            aria-label={`Historial de ${r.codigo_referencia}`}
                            title="Historial"
                          >
                            <Icono nombre="auditoria" />
                          </Link>
                        </div>
                      )}
                    </div>
                  ))}
                </div>
              </div>
            )}
          </div>
        </div>
        </div>
        <Paginacion pagina={resultado} onCambiar={(n) => { irA(n); setResaltada(null) }} />
      </section>

      {ficha && (
        <FichaReferencia
          modo="catalogo"
          referencia={ficha.tipo === 'editar' ? ficha.referencia : null}
          onGuardada={() => { setFicha(null); qc.invalidateQueries({ queryKey: ['referencias'] }) }}
          onCerrar={() => setFicha(null)}
        />
      )}
    </div>
  )
}