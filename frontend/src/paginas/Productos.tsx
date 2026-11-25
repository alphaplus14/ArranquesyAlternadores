import { useCallback, useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, mensajeError } from '../api/cliente'
import { NOMBRE_CALIDAD, type Calidad, type MetodoCaptura, type Pagina, type Referencia } from '../api/tipos'
import ChipCalidad from '../componentes/ChipCalidad'
import { errorStockMinimo } from '../utilidades/stockMinimo'
import { useAuth } from '../auth/AuthProvider'
import Icono from '../componentes/Icono'
import Paginacion from '../componentes/Paginacion'
import SelectorProveedor from '../componentes/SelectorProveedor'
import SelectorCategoria from '../componentes/SelectorCategoria'
import AvisoCoincidencias from '../componentes/AvisoCoincidencias'
import Resumen from '../componentes/Resumen'
import { useCategorias } from '../hooks/useCatalogos'
import { useDebounce } from '../hooks/useDebounce'
import { useListaPaginada } from '../hooks/useListaPaginada'
import { useScanner } from '../hooks/useScanner'
import { useVentana } from '../hooks/useVentana'
import { useAncho } from '../hooks/useAncho'

/** Filas por petición. El tope del API es 100; el DOM solo pinta las visibles. */
const POR_PAGINA = 100
const ALTO_FILA = 56

/**
 * Modo del listado según el ancho de la tarjeta (no de la ventana):
 * - completa (≥ 1100 px): todas las columnas;
 * - media (760–1099 px): sin Categoría ni IVA (se ven al editar);
 * - tarjetas (< 760 px): cada producto es una tarjeta de alto fijo (la lista sigue virtualizada).
 */
type Modo = 'completa' | 'media' | 'tarjetas'
const modoDe = (ancho: number): Modo => (ancho === 0 || ancho >= 1100 ? 'completa' : ancho >= 760 ? 'media' : 'tarjetas')
/** Alto de cada tarjeta, con su separación: cabecera + 5 pares + acciones (admin). */
const ALTO_TARJETA = { conAcciones: 252, otro: 192 }

/** Columnas por modo. La Descripción es la única flexible; el resto, ancho fijo. */
/** Ancho de la columna Acciones según cuántos botones de 36 px muestra (0 = sin columna). */
function anchoAcciones(botones: number) {
  return botones === 0 ? '' : ` ${botones * 36 + (botones - 1) * 6 + 4}px`
}

function columnasDe(modo: Modo, botones: number) {
  const acciones = anchoAcciones(botones)
  // RF-22: Existencia a 124 px para el chip "Bajo mínimo"; la descripción absorbe la diferencia.
  if (modo === 'completa') return `176px minmax(0, 1fr) 150px 120px 104px 72px 124px${acciones}`
  return `150px minmax(0, 1fr) 132px 104px 124px${acciones}`
}

type Formulario = {
  codigo_referencia: string
  /** S-25: código de barras o QR del producto; '' = no trae. */
  codigo_fabricante: string
  /** S-23: '' = sin calidad. */
  calidad: '' | Calidad
  proveedor_id: string
  categoria_id: string
  codigo_universal_id: string
  descripcion: string
  precio_venta: string
  /** RF-22: '' = usa el general; '0' = no maneja stock mínimo. */
  stock_minimo: string
}

const vacio: Formulario = {
  codigo_referencia: '', codigo_fabricante: '', calidad: '', proveedor_id: '', categoria_id: '',
  codigo_universal_id: '', descripcion: '', precio_venta: '', stock_minimo: '',
}

/** S-18: ningún precio puede ser 0. Las referencias antiguas en 0 no se pueden vender hasta asignarles uno. */
function sinPrecio(r: Referencia) {
  return Number(r.precio_venta) === 0
}

interface Aviso { tipo: 'ok' | 'error' | 'atencion'; texto: string }
interface Lectura { codigo: string; metodo: MetodoCaptura; titulo: string; detalle: string }
interface Busqueda {
  unidad: { codigo_barras: string; estado: string; referencia?: Referencia } | null
  referencias: Referencia[] | { data: Referencia[] }
}

function comoLista(valor: Busqueda['referencias'] | undefined): Referencia[] {
  if (!valor) return []
  return Array.isArray(valor) ? valor : valor.data ?? []
}

async function traerReferencias(termino: string, proveedorId: string, inactivas: boolean, pagina: number) {
  const { data } = await api.get<Pagina<Referencia>>('/referencias', {
    // S-26: inactivas=1 las INCLUYE junto con las activas (solo admin y logística).
    params: { q: termino || undefined, proveedor_id: proveedorId || undefined, inactivas: inactivas ? 1 : undefined, page: pagina, por_pagina: POR_PAGINA },
  })
  return data
}

/**
 * RF-01: catálogo de referencias, paginado en el servidor.
 * RF-12: el escáner localiza la unidad al momento (escaneado o digitado).
 * Un solo campo para escanear y buscar: escribir filtra en vivo (con retardo, así una ráfaga de
 * escáner no dispara peticiones intermedias), Enter localiza y resalta, Esc limpia.
 */
export default function Productos() {
  const qc = useQueryClient()
  const { tieneRol, usuario } = useAuth()
  const esAdmin = tieneRol('admin')
  /** S-28: rol exacto (tieneRol también es verdadero para el admin). */
  const esMostrador = usuario?.rol === 'mostrador'
  // S-26: admin y logística crean, editan y desactivan referencias; mostrador solo consulta.
  const puedeGestionar = tieneRol('logistica') // el admin pasa siempre
  const [verInactivas, setVerInactivas] = useState(false)
  /** Fila con la confirmación "¿Desactivar?" abierta, y el error del backend en una fila. */
  const [confirmando, setConfirmando] = useState<number | null>(null)
  const [errorFila, setErrorFila] = useState<{ id: number; texto: string } | null>(null)
  const [q, setQ] = useState('')
  const [proveedorId, setProveedorId] = useState('')
  /** Referencias que encontró el último escaneo (S-25: un código de barras puede estar en varios proveedores). */
  const [resaltadas, setResaltadas] = useState<number[]>([])
  const [aviso, setAviso] = useState<Aviso | null>(null)
  const [lectura, setLectura] = useState<Lectura | null>(null)
  const [editando, setEditando] = useState<{ id: number | null; datos: Formulario } | null>(null)
  const lectorRef = useRef<HTMLInputElement>(null)
  const termino = useDebounce(q.trim())

  const { filas, resultado, info, pagina, irA, cargando } = useListaPaginada<Referencia>({
    clave: ['referencias', termino, proveedorId, verInactivas, POR_PAGINA],
    filtros: [q, proveedorId, verInactivas],
    prefetch: true,
    traer: (n) => traerReferencias(termino, proveedorId, verInactivas, n),
  })

  const agotadosPagina = filas.filter((r) => r.existencia === 0).length
  const tarjeta = useAncho<HTMLElement>()
  const modo = modoDe(tarjeta.ancho)
  const alto = modo === 'tarjetas' ? (puedeGestionar ? ALTO_TARJETA.conAcciones : ALTO_TARJETA.otro) : ALTO_FILA
  const ventana = useVentana(filas, alto)

  const scroller = ventana.ref
  const sincronizar = ventana.sincronizar
  useEffect(() => {
    const el = scroller.current
    if (!el) return
    el.scrollTop = 0
    sincronizar()
  }, [pagina, termino, scroller, sincronizar])
  useEffect(() => {
    const el = scroller.current
    if (!el || resaltadas.length === 0) return
    const indice = filas.findIndex((r) => resaltadas.includes(r.id))
    if (indice < 0) return
    el.scrollTop = indice * alto
    sincronizar()
  }, [resaltadas, filas, alto, scroller, sincronizar])

  const localizar = useMutation({
    mutationFn: async ({ codigo, metodo }: { codigo: string; metodo: MetodoCaptura }) => {
      const { data } = await api.get<Busqueda>('/buscar', { params: { q: codigo } })
      return { data, codigo, metodo }
    },
    onSuccess: ({ data, codigo, metodo }) => {
      const lista = comoLista(data.referencias)
      // Primero la que coincide exacto (referencia o código de barras, S-25); si no, la primera.
      const referencia = data.unidad?.referencia ?? lista.find((r) => r.codigo_fabricante === codigo || r.codigo_referencia === codigo) ?? lista[0]
      if (!referencia) {
        setResaltadas([])
        lectorRef.current?.select()
        setLectura({ codigo, metodo, titulo: 'Sin coincidencia', detalle: 'No está en el catálogo' })
        setAviso({ tipo: 'error', texto: `Sin resultados para ${codigo}.` })
        return
      }
      // S-25: si el código es un código de barras, puede estar en referencias de varios proveedores:
      // se filtra por el código leído (la búsqueda lo encuentra en todos) y se resaltan todas.
      const porBarras = data.unidad ? [] : lista.filter((r) => r.codigo_fabricante === codigo || r.codigo_referencia === codigo)
      const ids = porBarras.length > 1 ? porBarras.map((r) => r.id) : [referencia.id]
      const filtro = porBarras.length > 1 || referencia.codigo_fabricante === codigo ? codigo : referencia.codigo_referencia
      setResaltadas(ids)
      setQ(filtro)
      irA(1)
      // Queda en el campo el código encontrado, seleccionado: el próximo escaneo lo reemplaza.
      if (lectorRef.current) {
        lectorRef.current.value = filtro
        lectorRef.current.select()
      }
      // RF-21: una unidad vendida o retirada no está en inventario; se avisa con su estado.
      const fuera = data.unidad?.estado === 'vendida' ? 'Unidad vendida' : data.unidad?.estado === 'retirada' ? 'Unidad retirada del inventario' : null
      const leido = data.unidad?.codigo_barras ?? codigo
      // S-23: sin marca; el proveedor y la calidad distinguen la referencia.
      const calidad = referencia.calidad ? NOMBRE_CALIDAD[referencia.calidad] : null
      const origen = [referencia.proveedor?.nombre, calidad].filter(Boolean).join(' · ')
      setLectura({
        codigo: leido,
        metodo,
        titulo: referencia.codigo_referencia,
        detalle: fuera ?? `${origen ? `${origen} · ` : ''}existencia ${referencia.existencia}`,
      })
      // El código leído no se repite si es el mismo de la referencia (p. ej. el de la caja).
      const que = leido === referencia.codigo_referencia ? leido : `${leido} → ${referencia.codigo_referencia}`
      const partes = [que, referencia.descripcion, referencia.proveedor?.nombre, calidad, `existencia ${referencia.existencia}`]
      setAviso({
        tipo: fuera ? 'atencion' : 'ok',
        texto: `${fuera ? `${fuera} · ` : ''}${metodo === 'escaneado' ? 'Escaneado' : 'Digitado'} · ${partes.filter(Boolean).join(' · ')}${ids.length > 1 ? ` · y ${ids.length - 1} más con otro proveedor` : ''}`,
      })
    },
    onError: (e) => {
      setAviso({ tipo: 'error', texto: mensajeError(e) })
    },
  })

  const onCodigo = useCallback((codigo: string, metodo: MetodoCaptura) => {
    localizar.mutate({ codigo, metodo })
  }, [localizar])
  const scanner = useScanner(onCodigo)

  /** Limpia el campo único: sin filtro ni resaltado, listado completo y foco en el campo. */
  const quitarFiltro = () => {
    if (lectorRef.current) lectorRef.current.value = ''
    scanner.reiniciar()
    setQ('')
    setResaltadas([])
    irA(1)
    lectorRef.current?.focus()
  }

  const editar = (r: Referencia) => setEditando({
    id: r.id,
    datos: {
      codigo_referencia: r.codigo_referencia,
      codigo_fabricante: r.codigo_fabricante ?? '',
      calidad: r.calidad ?? '',
      proveedor_id: String(r.proveedor_id),
      categoria_id: String(r.categoria_id),
      codigo_universal_id: r.codigo_universal_id ? String(r.codigo_universal_id) : '',
      descripcion: r.descripcion,
      precio_venta: r.precio_venta,
      stock_minimo: r.stock_minimo == null ? '' : String(r.stock_minimo),
    },
  })

  // Acciones visibles: Editar + Desactivar/Reactivar (gestión) y Historial (solo admin: Auditoría es del admin).
  const botonesAcciones = puedeGestionar ? (esAdmin ? 3 : 2) : 0
  const columnas = columnasDe(modo, botonesAcciones)

  const enfocarCampo = () => setTimeout(() => lectorRef.current?.focus(), 0)
  const cambiarEstado = useMutation({
    mutationFn: async ({ r, accion }: { r: Referencia; accion: 'desactivar' | 'reactivar' }) =>
      (await api.post<Referencia>(`/referencias/${r.id}/${accion}`)).data,
    onSuccess: (_, { r, accion }) => {
      setConfirmando(null)
      setErrorFila(null)
      setAviso({ tipo: 'ok', texto: `${r.codigo_referencia} ${accion === 'desactivar' ? 'quedó inactiva' : 'quedó activa de nuevo'}.` })
      qc.invalidateQueries({ queryKey: ['referencias'] })
      enfocarCampo()
    },
    onError: (e, { r }) => { setConfirmando(null); setErrorFila({ id: r.id, texto: mensajeError(e) }) },
  })
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
          <h2>Listado</h2>
        </div>

        <div className="barra-busqueda">
          <div className="campo-unico">
            <Icono nombre="buscar" />
            <input
              ref={lectorRef}
              className="escaner"
              placeholder="Escanee o busque por código o descripción"
              onInput={(e) => { setQ(e.currentTarget.value); setResaltadas([]); setAviso(null); setErrorFila(null) }}
              onKeyDown={(e) => {
                // Esc con texto limpia el campo, el filtro y el resaltado.
                if (e.key === 'Escape' && e.currentTarget.value !== '') { e.preventDefault(); quitarFiltro(); return }
                scanner.onKeyDown(e)
              }}
              onPaste={scanner.onPaste}
              autoFocus
              autoComplete="off"
              aria-label="Escanear o buscar productos"
            />
            {q && (
              <button type="button" className="busca-limpiar" aria-label="Limpiar la búsqueda" title="Limpiar (Esc)" onClick={quitarFiltro}>
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden><path d="M6 6l12 12M18 6L6 18" /></svg>
              </button>
            )}
          </div>
          <div className="controles-listado">
            <SelectorProveedor className="filtro" aria-label="Proveedor" value={proveedorId} onChange={setProveedorId} opcionTodos="Todos los proveedores" />
            {puedeGestionar && (
              <>
                <label className="check ver-inactivas">
                  <input type="checkbox" checked={verInactivas} onChange={(e) => setVerInactivas(e.target.checked)} /> Ver inactivas
                </label>
                <button type="button" className="primario" onClick={() => setEditando({ id: null, datos: vacio })}>Nueva referencia</button>
              </>
            )}
          </div>
        </div>
        {aviso && <div className={`aviso ${aviso.tipo}`} role="status">{aviso.texto}</div>}

        <div className="tabla-contenedor">
        <div className={`tabla-scroll${cargando ? ' actualizando' : ''}`} role="table" aria-label="Productos" aria-rowcount={info?.total} ref={ventana.ref} onScroll={ventana.onScroll}>
          <div role="row" className="tabla-rejilla tabla-cabeza">
            <div role="columnheader">Referencia</div>
            <div role="columnheader">Descripción</div>
            <div role="columnheader">Proveedor</div>
            {completa && <div role="columnheader">Categoría</div>}
            <div role="columnheader" className="col-numero" title="Precio de venta con IVA incluido">Precio (IVA incl.)</div>
            {completa && <div role="columnheader" className="col-numero">IVA</div>}
            <div role="columnheader" className="col-numero">Existencia</div>
            {puedeGestionar && <div role="columnheader" className="col-acciones">Acciones</div>}
          </div>
          <div>
            {filas.length === 0 && !cargando && <div className="vacio">Sin productos para esta búsqueda.</div>}
            {filas.length > 0 && (
              <div style={{ height: ventana.altoTotal }}>
                <div style={{ transform: `translateY(${ventana.desplazamiento}px)` }}>
                  {ventana.visibles.map((r) => (
                    <div role="row" key={r.id} className={`tabla-rejilla tabla-fila${resaltadas.includes(r.id) ? ' resaltada' : ''}${r.activo === false ? ' inactiva' : ''}`} style={{ height: alto }}>
                      <div role="cell" className="corta celda-referencia" title={r.codigo_fabricante ? `${r.codigo_referencia} · código de barras ${r.codigo_fabricante}` : r.codigo_referencia}>
                        <strong>{r.codigo_referencia}</strong>
                        {r.activo === false && <span className="chip inactiva">Inactiva</span>}
                        {r.calidad && <> <ChipCalidad calidad={r.calidad} /></>}
                        {r.codigo_fabricante && r.codigo_fabricante !== r.codigo_referencia && (
                          <small className="codigo-barras" aria-label={`Código de barras ${r.codigo_fabricante}`}>▮ {r.codigo_fabricante}</small>
                        )}
                      </div>
                      <div role="cell" data-etiqueta="Descripción" className="corta" title={r.descripcion}>{r.descripcion}</div>
                      <div role="cell" data-etiqueta="Proveedor" className="corta" title={r.proveedor?.nombre}>{r.proveedor?.nombre}</div>
                      {completa && <div role="cell" className="corta" title={r.categoria?.nombre}>{r.categoria?.nombre}</div>}
                      <div role="cell" data-etiqueta="Precio" className="col-numero">{sinPrecio(r) ? <span className="chip sin-precio" title="No se puede vender hasta que el administrador le asigne un precio">Sin precio</span> : Number(r.precio_venta).toLocaleString('es-CO')}</div>
                      {(completa || modo === 'tarjetas') && <div role="cell" data-etiqueta="IVA" className="col-numero">{Number(r.iva_venta_porcentaje) === 0 ? <span className="chip">Sin IVA</span> : `${Number(r.iva_venta_porcentaje)} %`}</div>}
                      <div role="cell" data-etiqueta="Existencia" className={r.existencia === 0 ? 'agotado col-numero celda-existencia' : 'col-numero celda-existencia'}>
                        {/* S-28: el backend no lo envía a mostrador; tampoco se pinta. */}
                        {r.bajo_minimo && !esMostrador && (
                          <span className="chip bajo-minimo" title={`Mínimo ${r.minimo_efectivo ?? ''}${r.minimo_por_universal ? ' (del universal, sobre la suma)' : r.minimo_origen === 'general' ? ' (general)' : ''}`}>Bajo mínimo</span>
                        )}
                        {r.existencia}
                      </div>
                      {puedeGestionar && (
                        <div role="cell" className="acciones-fila">
                          {r.activo !== false && (
                            <button type="button" className="icono-accion" aria-label={`Editar ${r.codigo_referencia}`} title="Editar" onClick={() => editar(r)}>
                              <Icono nombre="editar" />
                            </button>
                          )}
                          {r.activo === false ? (
                            <button type="button" className="icono-accion ok" aria-label={`Reactivar ${r.codigo_referencia}`} title="Reactivar" disabled={cambiarEstado.isPending} onClick={() => cambiarEstado.mutate({ r, accion: 'reactivar' })}>
                              <Icono nombre="reactivar" />
                            </button>
                          ) : (
                            <button type="button" className="icono-accion peligro" aria-label={`Desactivar ${r.codigo_referencia}`} title="Desactivar" onClick={() => { setErrorFila(null); setConfirmando(r.id) }}>
                              <Icono nombre="prohibido" />
                            </button>
                          )}
                          {esAdmin && (
                            <Link className="icono-accion" to={`/admin/auditoria?tabla=referencias&registro_id=${r.id}`} aria-label={`Historial de ${r.codigo_referencia}`} title="Historial">
                              <Icono nombre="auditoria" />
                            </Link>
                          )}
                        </div>
                      )}
                      {confirmando === r.id && (
                        <div className="fila-flotante" role="group" aria-label={`Desactivar ${r.codigo_referencia}`} onKeyDown={(e) => { if (e.key === 'Escape') { e.preventDefault(); setConfirmando(null); enfocarCampo() } }}>
                          <span>¿Desactivar {r.codigo_referencia}?</span>
                          <button type="button" className="peligro" disabled={cambiarEstado.isPending} onClick={() => cambiarEstado.mutate({ r, accion: 'desactivar' })}>Sí</button>
                          <button type="button" className="secundario" autoFocus onClick={() => { setConfirmando(null); enfocarCampo() }}>No</button>
                        </div>
                      )}
                      {errorFila?.id === r.id && (
                        <div className="fila-flotante error" role="alert">
                          <span>{errorFila.texto}</span>
                          <button type="button" className="secundario" onClick={() => { setErrorFila(null); enfocarCampo() }}>Entendido</button>
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
        <Paginacion pagina={resultado} onCambiar={(n) => { irA(n); setResaltadas([]) }} />
      </section>

      {editando && (
        <FormularioReferencia
          id={editando.id}
          inicial={editando.datos}
          onListo={() => { setEditando(null); qc.invalidateQueries({ queryKey: ['referencias'] }); enfocarCampo() }}
          onCerrar={() => { setEditando(null); enfocarCampo() }}
        />
      )}
    </div>
  )
}

function FormularioReferencia({ id, inicial, onListo, onCerrar }: { id: number | null; inicial: Formulario; onListo: () => void; onCerrar: () => void }) {
  const [f, setF] = useState(inicial)
  /** S-25: códigos consultados al salir del campo (aviso si existen con otro proveedor). */
  const [consultados, setConsultados] = useState<string[]>([])
  const consultar = () => setConsultados([f.codigo_referencia, f.codigo_fabricante])
  const categorias = useCategorias()
  const categoria = categorias.data?.find((c) => String(c.id) === f.categoria_id)
  const admiteUniversal = categoria?.admite_codigo_universal ?? false

  const universales = useQuery({
    queryKey: ['universales', 'categoria', f.categoria_id],
    queryFn: async () => (await api.get<Pagina<{ id: number; codigo: string; categoria_id: number }>>('/codigos-universales', { params: { por_pagina: 100 } })).data.data,
    enabled: admiteUniversal,
  })

  const guardar = useMutation({
    mutationFn: async () => {
      const conUniversal = admiteUniversal && f.codigo_universal_id !== ''
      const datos = {
        ...f,
        codigo_fabricante: f.codigo_fabricante.trim() || null,
        calidad: f.calidad || null,
        codigo_universal_id: conUniversal ? Number(f.codigo_universal_id) : null,
        // RF-22: con universal, el mínimo es del universal; la referencia no lleva uno propio.
        stock_minimo: conUniversal || f.stock_minimo.trim() === '' ? null : Number(f.stock_minimo),
      }
      return id ? api.put(`/referencias/${id}`, datos) : api.post('/referencias', datos)
    },
    onSuccess: onListo,
  })

  const errorPrecioVenta = f.precio_venta === '' ? null : Number(f.precio_venta) > 0 ? null : 'El precio de venta debe ser mayor que 0.'
  const universalElegido = admiteUniversal && f.codigo_universal_id !== ''
  const errorMinimo = errorStockMinimo(f.stock_minimo)
  const campo = (k: keyof Formulario) => ({ value: f[k], onChange: (e: { target: { value: string } }) => setF({ ...f, [k]: e.target.value }) })

  return (
    <div className="modal">
      <form className="modal-caja" onSubmit={(e) => { e.preventDefault(); guardar.mutate() }}>
        <h2>{id ? 'Editar referencia' : 'Nueva referencia'}</h2>
        <label>Referencia del proveedor
          <input required={f.codigo_fabricante.trim() === ''} maxLength={80} aria-describedby={f.codigo_fabricante.trim() ? 'referencia-ayuda' : undefined} onBlur={consultar} {...campo('codigo_referencia')} />
        </label>
        {f.codigo_fabricante.trim() !== '' && (
          <p id="referencia-ayuda" className="ayuda">Si el producto no trae otra referencia, déjela vacía y se usará el código de barras.</p>
        )}
        <label>Código de barras o QR del producto (si lo trae)
          <input maxLength={120} autoComplete="off" onBlur={consultar} {...campo('codigo_fabricante')} />
        </label>
        <AvisoCoincidencias codigos={consultados} proveedorId={f.proveedor_id || null} accion={id ? 'guardarla' : 'crearla'} />
        <label>Descripción<input required {...campo('descripcion')} /></label>
        <label>Calidad
          <select {...campo('calidad')}>
            <option value="">—</option>
            <option value="fino">Fino</option>
            <option value="generico">Genérico</option>
          </select>
        </label>
        <label>Proveedor
          <SelectorProveedor required permitirCrear value={f.proveedor_id} onChange={(proveedor_id) => setF((v) => ({ ...v, proveedor_id }))} />
        </label>
        <SelectorCategoria value={f.categoria_id} onChange={(categoria_id) => setF((v) => ({ ...v, categoria_id }))} />
        {admiteUniversal && (
          <label>Código universal
            <select {...campo('codigo_universal_id')}>
              <option value="">Sin universal: la referencia es su código único</option>
              {universales.data?.filter((u) => String(u.categoria_id) === f.categoria_id).map((u) => <option key={u.id} value={u.id}>{u.codigo}</option>)}
            </select>
          </label>
        )}
        <label>Precio de venta
          <input type="number" min="0.01" step="0.01" required aria-invalid={errorPrecioVenta !== null} aria-describedby="precio-venta-ayuda" {...campo('precio_venta')} />
        </label>
        <p id="precio-venta-ayuda" className={errorPrecioVenta ? 'ayuda error' : 'ayuda'}>
          {errorPrecioVenta ?? 'Con IVA incluido. Mayor que 0: una referencia sin precio no se puede vender.'}
        </p>
        {universalElegido ? (
          <p className="ayuda">Stock mínimo: se define en el código universal y se evalúa sobre la suma de sus referencias.</p>
        ) : (
          <>
            <label>Stock mínimo (opcional)
              <input type="number" min="0" max="9999" step="1" inputMode="numeric" aria-invalid={errorMinimo !== null} aria-describedby="stock-minimo-ayuda" {...campo('stock_minimo')} />
            </label>
            <p id="stock-minimo-ayuda" className={errorMinimo ? 'ayuda error' : 'ayuda'}>
              {errorMinimo ?? 'Vacío = usa el general; 0 = este producto no maneja stock mínimo.'}
            </p>
          </>
        )}
        {guardar.isError && <div className="aviso error">{mensajeError(guardar.error)}</div>}
        <div className="acciones">
          <button type="button" className="secundario" onClick={onCerrar}>Cancelar</button>
          <button className="primario" disabled={guardar.isPending || errorPrecioVenta !== null || (!universalElegido && errorMinimo !== null)}>Guardar</button>
        </div>
      </form>
    </div>
  )
}
