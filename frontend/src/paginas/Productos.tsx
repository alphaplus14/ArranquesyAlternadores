import { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { api, mensajeError } from '../api/cliente'
import { NOMBRE_CALIDAD, type MetodoCaptura, type Pagina, type Proveedor, type Referencia } from '../api/tipos'
import ChipCalidad from '../componentes/ChipCalidad'
import FichaReferencia from '../componentes/FichaReferencia'
import { useAuth } from '../auth/AuthProvider'
import Icono from '../componentes/Icono'
import Paginacion from '../componentes/Paginacion'
import Resumen from '../componentes/Resumen'
import { useProveedores } from '../hooks/useCatalogos'
import { useDebounce } from '../hooks/useDebounce'
import { useListaPaginada } from '../hooks/useListaPaginada'
import { useScanner } from '../hooks/useScanner'
import { useVentana } from '../hooks/useVentana'

/** Filas por petición. El tope del API es 100; el DOM solo pinta las visibles. */
const POR_PAGINA = 100
/** Alto de cada fila. Grandes es la lectura cómoda; pequeños cabe más en la misma pantalla. */
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

type FichaCatalogo = { tipo: 'nueva' } | { tipo: 'editar'; referencia: Referencia }

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
  const { tieneRol } = useAuth()
  const esAdmin = tieneRol('admin')
  const [q, setQ] = useState('')
  const [proveedorId, setProveedorId] = useState('')
  const [resaltada, setResaltada] = useState<number | null>(null)
  const [aviso, setAviso] = useState<Aviso | null>(null)
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
  const ventana = useVentana(filas, altoFila)

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
    const el = scroller.current
    const anterior = altoAnterior.current
    if (!el || anterior === altoFila) return
    el.scrollTop = (el.scrollTop / anterior) * altoFila
    altoAnterior.current = altoFila
    sincronizar()
  }, [altoFila, scroller, sincronizar])
  useEffect(() => {
    const el = scroller.current
    if (!el || resaltada == null) return
    const indice = filas.findIndex((r) => r.id === resaltada)
    if (indice < 0) return
    el.scrollTop = indice * altoFilaRef.current
    sincronizar()
  }, [resaltada, filas, scroller, sincronizar])

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
        setAviso({ tipo: 'error', texto: `Sin resultados para ${codigo}.` })
        return
      }
      setResaltada(referencia.id)
      setQ(referencia.codigo_referencia)
      irA(1)
      const vendida = data.unidad?.estado === 'vendida'
      // S-23: sin marca; el proveedor y la calidad distinguen la referencia.
      const origen = [referencia.proveedor?.nombre, referencia.calidad && NOMBRE_CALIDAD[referencia.calidad]].filter(Boolean).join(' · ')
      setLectura({
        codigo: data.unidad?.codigo_barras ?? codigo,
        metodo,
        titulo: referencia.codigo_referencia,
        detalle: vendida ? 'Unidad vendida' : `${origen ? `${origen} · ` : ''}existencia ${referencia.existencia}`,
      })
      setAviso({
        tipo: vendida ? 'atencion' : 'ok',
        texto: `${metodo === 'escaneado' ? 'Escaneado' : 'Digitado'} · ${data.unidad?.codigo_barras ?? codigo} · ${referencia.codigo_referencia} ${origen}`.trim(),
      })
    },
    onError: (e) => {
      setAviso({ tipo: 'error', texto: mensajeError(e) })
    },
  })

  const onCodigo = useCallback((codigo: string, metodo: MetodoCaptura) => {
    if (lectorRef.current) lectorRef.current.value = ''
    localizar.mutate({ codigo, metodo })
  }, [localizar])
  const scanner = useScanner(onCodigo)

  const columnas = esAdmin
    ? '190px minmax(180px, 1.6fr) 140px 130px 120px 76px 96px 92px'
    : '190px minmax(180px, 1.6fr) 140px 130px 120px 76px 96px'

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
            <span><strong>{info ? info.total.toLocaleString('es-CO') : '—'}</strong> resultados</span>
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

      <section className="tarjeta-pagina" style={{ ['--cols' as string]: columnas }}>
        <div className="fila-titulo">
          <div className="fila-titulo-inicio">
            <h2>Listado</h2>
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
          </div>
          <div className="acciones">
            <label className="busca">
              <Icono nombre="buscar" />
              <input
                placeholder="Buscar código o descripción"
                value={q}
                onChange={(e) => { setQ(e.target.value); setResaltada(null) }}
                aria-label="Buscar productos"
              />
            </label>
            <FiltroProveedor
              valor={proveedorId}
              onCambio={(id) => { setProveedorId(id); setResaltada(null) }}
            />
            {esAdmin && <button type="button" className="primario" onClick={() => setFicha({ tipo: 'nueva' })}>Nueva referencia</button>}
          </div>
        </div>

        <input
          ref={lectorRef}
          className="escaner"
          placeholder="Escanee la etiqueta, o escriba el código y pulse Enter"
          onKeyDown={scanner.onKeyDown}
          onPaste={scanner.onPaste}
          autoFocus
          autoComplete="off"
          aria-label="Lector de código de barras"
        />
        {aviso && <div className={`aviso ${aviso.tipo}`} role="status">{aviso.texto}</div>}

        <div className={compactas ? 'tabla-contenedor filas-compactas' : 'tabla-contenedor'}>
        <div className={`tabla-scroll${cargando ? ' actualizando' : ''}`} role="table" aria-label="Productos" aria-rowcount={info?.total} ref={ventana.ref} onScroll={ventana.onScroll}>
          <div role="row" className="tabla-rejilla tabla-cabeza">
            <div role="columnheader">Referencia</div>
            <div role="columnheader">Descripción</div>
            <div role="columnheader">Proveedor</div>
            <div role="columnheader">Categoría</div>
            <div role="columnheader">Precio</div>
            <div role="columnheader">IVA</div>
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
                      className={
                        r.id === resaltada
                          ? `tabla-rejilla tabla-fila resaltada${esAdmin ? ' tabla-fila-editable' : ''}`
                          : `tabla-rejilla tabla-fila${esAdmin ? ' tabla-fila-editable' : ''}`
                      }
                      style={{ height: altoFila }}
                      onDoubleClick={esAdmin ? () => setFicha({ tipo: 'editar', referencia: r }) : undefined}
                      title={esAdmin ? 'Doble clic para editar' : undefined}
                    >
                      <div role="cell" className="corta">
                        <strong>{r.codigo_referencia}</strong>
                        {r.calidad && <> <ChipCalidad calidad={r.calidad} /></>}
                      </div>
                      <div role="cell" className="corta">{r.descripcion}</div>
                      <div role="cell" className="corta">{r.proveedor?.nombre}</div>
                      <div role="cell" className="corta">{r.categoria?.nombre}</div>
                      <div role="cell">{sinPrecio(r) ? <span className="chip sin-precio" title="No se puede vender hasta que el administrador le asigne un precio">Sin precio</span> : Number(r.precio_venta).toLocaleString('es-CO')}</div>
                      <div role="cell">{Number(r.iva_venta_porcentaje) === 0 ? <span className="chip">Sin IVA</span> : `${Number(r.iva_venta_porcentaje)} %`}</div>
                      <div role="cell" className={r.existencia === 0 ? 'agotado col-numero' : 'col-numero'}>{r.existencia}</div>
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

/** Compara sin tildes ni mayúsculas, para que «electrica» encuentre «Eléctrica». */
function normalizar(texto: string) {
  return texto.normalize('NFD').replace(/\p{M}/gu, '').toLocaleLowerCase('es')
}

function inicialDe(nombre: string) {
  const letra = nombre.trim().charAt(0)
  return letra ? letra.toLocaleUpperCase('es') : '?'
}

/** Elige un proveedor de GET /proveedores para filtrar el listado. */
function FiltroProveedor({ valor, onCambio }: { valor: string; onCambio: (id: string) => void }) {
  const proveedores = useProveedores()
  const [abierto, setAbierto] = useState(false)
  const botonRef = useRef<HTMLButtonElement>(null)
  const yaAbrio = useRef(false)
  const elegido = proveedores.data?.find((p) => String(p.id) === valor)
  const cerrar = useCallback(() => setAbierto(false), [])
  const elegir = useCallback((id: string) => {
    onCambio(id)
    setAbierto(false)
  }, [onCambio])

  useEffect(() => {
    if (abierto) yaAbrio.current = true
    else if (yaAbrio.current) botonRef.current?.focus()
  }, [abierto])

  const etiqueta = elegido?.nombre ?? 'Todos los proveedores'

  return (
    <>
      <button
        ref={botonRef}
        type="button"
        className={valor ? 'filtro-proveedor activo' : 'filtro-proveedor'}
        aria-haspopup="dialog"
        aria-expanded={abierto}
        aria-label={elegido ? `Proveedor: ${elegido.nombre}. Cambiar filtro` : 'Filtrar por proveedor'}
        title={etiqueta}
        onClick={() => setAbierto(true)}
      >
        <span className="filtro-proveedor-texto">{etiqueta}</span>
        <span className="filtro-proveedor-chevron"><Icono nombre="chevron" /></span>
      </button>
      {abierto && (
        <DialogoProveedores
          valor={valor}
          proveedores={proveedores.data ?? []}
          cargando={proveedores.isLoading}
          error={proveedores.isError && !proveedores.data ? mensajeError(proveedores.error) : null}
          onReintentar={() => { void proveedores.refetch() }}
          onElegir={elegir}
          onCerrar={cerrar}
        />
      )}
    </>
  )
}

function DialogoProveedores({
  valor,
  proveedores,
  cargando,
  error,
  onReintentar,
  onElegir,
  onCerrar,
}: {
  valor: string
  proveedores: Proveedor[]
  cargando: boolean
  error: string | null
  onReintentar: () => void
  onElegir: (id: string) => void
  onCerrar: () => void
}) {
  const [q, setQ] = useState('')
  const partes = normalizar(q.trim()).split(/\s+/).filter(Boolean)
  const visibles = proveedores.filter((p) => {
    const texto = normalizar(`${p.nombre} ${p.nit ?? ''}`)
    return partes.every((parte) => texto.includes(parte))
  })
  const elegidoRef = useRef<HTMLButtonElement>(null)
  const listaRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const alPulsar = (e: KeyboardEvent) => { if (e.key === 'Escape') onCerrar() }
    document.addEventListener('keydown', alPulsar)
    const anterior = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    const fila = elegidoRef.current
    const lista = listaRef.current
    if (fila && lista) {
      const delta = fila.getBoundingClientRect().top - lista.getBoundingClientRect().top
      lista.scrollTop += delta - 64
    }
    return () => {
      document.removeEventListener('keydown', alPulsar)
      document.body.style.overflow = anterior
    }
  }, [onCerrar])

  const subtitulo = error
    ? 'No se pudo cargar el catálogo'
    : cargando
      ? 'Consultando el catálogo…'
      : partes.length > 0
        ? `${visibles.length} ${visibles.length === 1 ? 'coincidencia' : 'coincidencias'}`
        : `${proveedores.length} ${proveedores.length === 1 ? 'proveedor' : 'proveedores'}`

  return (
    <div className="modal modal-proveedor">
      <div className="modal-caja cuenta-modal proveedor-modal" role="dialog" aria-modal="true" aria-labelledby="filtro-proveedor-titulo">
        <header className="modal-cabeza">
          <span className="avatar" aria-hidden><Icono nombre="catalogo" /></span>
          <div className="modal-cabeza-texto">
            <h2 id="filtro-proveedor-titulo">Filtrar por proveedor</h2>
            <p>{subtitulo}</p>
          </div>
          <button type="button" className="icono-boton modal-cerrar" aria-label="Cerrar" onClick={onCerrar}>
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" aria-hidden><path d="M6 6l12 12M18 6L6 18" /></svg>
          </button>
        </header>

        <section className="modal-seccion selector-cuerpo">
          <label className="busca selector-busca">
            <Icono nombre="buscar" />
            <input
              autoFocus
              value={q}
              onChange={(e) => setQ(e.target.value)}
              placeholder="Buscar por nombre o NIT"
              aria-label="Buscar proveedor"
              autoComplete="off"
            />
          </label>

          {error && (
            <div className="aviso error" role="alert">
              {error}{' '}
              <button type="button" className="enlace" onClick={onReintentar}>Reintentar</button>
            </div>
          )}

          <div className="selector-lista" ref={listaRef}>
            <button
              type="button"
              className={valor === '' ? 'selector-opcion fijo elegido' : 'selector-opcion fijo'}
              aria-current={valor === '' ? 'true' : undefined}
              onClick={() => onElegir('')}
            >
              <span className="selector-avatar" aria-hidden><Icono nombre="catalogo" /></span>
              <span className="selector-texto">
                <strong>Todos los proveedores</strong>
                <small>Sin filtrar el listado</small>
              </span>
              {valor === '' && <MarcaElegido />}
            </button>
            <ul>
            {visibles.map((p) => {
              const activo = String(p.id) === valor
              return (
                <li key={p.id}>
                  <button
                    ref={activo ? elegidoRef : undefined}
                    type="button"
                    className={activo ? 'selector-opcion elegido' : 'selector-opcion'}
                    aria-current={activo ? 'true' : undefined}
                    onClick={() => onElegir(String(p.id))}
                  >
                    <span className="selector-avatar" aria-hidden>{inicialDe(p.nombre)}</span>
                    <span className="selector-texto">
                      <strong>{p.nombre}</strong>
                      {p.nit ? <small>NIT {p.nit}</small> : null}
                    </span>
                    {activo && <MarcaElegido />}
                  </button>
                </li>
              )
            })}
            {!cargando && !error && proveedores.length === 0 && <li className="selector-estado">No hay proveedores en el catálogo.</li>}
            {!cargando && !error && proveedores.length > 0 && visibles.length === 0 && (
              <li className="selector-estado">Ningún proveedor coincide con «{q.trim()}».</li>
            )}
            {cargando && <li className="selector-estado">Cargando proveedores…</li>}
            </ul>
          </div>
        </section>
      </div>
    </div>
  )
}

function MarcaElegido() {
  return (
    <svg className="selector-marca" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="2.25" strokeLinecap="round" strokeLinejoin="round" aria-hidden>
      <path d="M5 12.5l4.2 4.2L19 7.5" />
    </svg>
  )
}

