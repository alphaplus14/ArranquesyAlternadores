import { useCallback, useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, mensajeError } from '../api/cliente'
import type { MetodoCaptura, Pagina, Referencia } from '../api/tipos'
import { useAuth } from '../auth/AuthProvider'
import Icono from '../componentes/Icono'
import Paginacion from '../componentes/Paginacion'
import Resumen from '../componentes/Resumen'
import { useCategorias, useMarcas, useProveedores } from '../hooks/useCatalogos'
import { useDebounce } from '../hooks/useDebounce'
import { useListaPaginada } from '../hooks/useListaPaginada'
import { useScanner } from '../hooks/useScanner'
import { useVentana } from '../hooks/useVentana'

/** Filas por petición. El tope del API es 100; el DOM solo pinta las visibles. */
const POR_PAGINA = 100
const ALTO_FILA = 56

type Formulario = {
  codigo_referencia: string
  marca_id: string
  proveedor_id: string
  categoria_id: string
  codigo_universal_id: string
  descripcion: string
  precio_venta: string
  /** RF-14 / S-15: el backend acepta 19 o 0 (sin IVA). */
  iva_venta_porcentaje: '19' | '0'
}

const vacio: Formulario = {
  codigo_referencia: '', marca_id: '', proveedor_id: '', categoria_id: '',
  codigo_universal_id: '', descripcion: '', precio_venta: '', iva_venta_porcentaje: '19',
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
  const [editando, setEditando] = useState<{ id: number | null; datos: Formulario } | null>(null)
  const lectorRef = useRef<HTMLInputElement>(null)
  const termino = useDebounce(q.trim())
  const proveedores = useProveedores()

  const { filas, resultado, info, pagina, irA, cargando } = useListaPaginada<Referencia>({
    clave: ['referencias', termino, proveedorId, POR_PAGINA],
    filtros: [q, proveedorId],
    prefetch: true,
    traer: (n) => traerReferencias(termino, proveedorId, n),
  })

  const agotadosPagina = filas.filter((r) => r.existencia === 0).length
  const ventana = useVentana(filas, ALTO_FILA)

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
    if (!el || resaltada == null) return
    const indice = filas.findIndex((r) => r.id === resaltada)
    if (indice < 0) return
    el.scrollTop = indice * ALTO_FILA
    sincronizar()
  }, [resaltada, filas, scroller, sincronizar])

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
      const marca = referencia.marca?.nombre ?? ''
      setLectura({
        codigo: data.unidad?.codigo_barras ?? codigo,
        metodo,
        titulo: referencia.codigo_referencia,
        detalle: vendida ? 'Unidad vendida' : `${marca} · existencia ${referencia.existencia}`,
      })
      setAviso({
        tipo: vendida ? 'atencion' : 'ok',
        texto: `${metodo === 'escaneado' ? 'Escaneado' : 'Digitado'} · ${data.unidad?.codigo_barras ?? codigo} · ${referencia.codigo_referencia} ${marca}`.trim(),
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

  const editar = (r: Referencia) => setEditando({
    id: r.id,
    datos: {
      codigo_referencia: r.codigo_referencia,
      marca_id: String(r.marca_id),
      proveedor_id: String(r.proveedor_id),
      categoria_id: String(r.categoria_id),
      codigo_universal_id: r.codigo_universal_id ? String(r.codigo_universal_id) : '',
      descripcion: r.descripcion,
      precio_venta: r.precio_venta,
      iva_venta_porcentaje: Number(r.iva_venta_porcentaje) === 0 ? '0' : '19',
    },
  })

  const columnas = esAdmin
    ? '148px minmax(180px, 1.6fr) 120px 140px 130px 120px 76px 96px 92px'
    : '148px minmax(180px, 1.6fr) 120px 140px 130px 120px 76px 96px'

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
          <h2>Listado</h2>
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
            <select aria-label="Proveedor" value={proveedorId} onChange={(e) => setProveedorId(e.target.value)}>
              <option value="">Todos los proveedores</option>
              {proveedores.data?.map((p) => <option key={p.id} value={p.id}>{p.nombre}</option>)}
            </select>
            {esAdmin && <button type="button" className="primario" onClick={() => setEditando({ id: null, datos: vacio })}>Nueva referencia</button>}
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

        <div className="tabla-contenedor">
        <div className={`tabla-scroll${cargando ? ' actualizando' : ''}`} role="table" aria-label="Productos" aria-rowcount={info?.total} ref={ventana.ref} onScroll={ventana.onScroll}>
          <div role="row" className="tabla-rejilla tabla-cabeza">
            <div role="columnheader">Referencia</div>
            <div role="columnheader">Descripción</div>
            <div role="columnheader">Marca</div>
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
                    <div role="row" key={r.id} className={r.id === resaltada ? 'tabla-rejilla tabla-fila resaltada' : 'tabla-rejilla tabla-fila'} style={{ height: ALTO_FILA }}>
                      <div role="cell" className="corta"><strong>{r.codigo_referencia}</strong></div>
                      <div role="cell" className="corta">{r.descripcion}</div>
                      <div role="cell" className="corta">{r.marca?.nombre}</div>
                      <div role="cell" className="corta">{r.proveedor?.nombre}</div>
                      <div role="cell" className="corta">{r.categoria?.nombre}</div>
                      <div role="cell">{sinPrecio(r) ? <span className="chip sin-precio" title="No se puede vender hasta que el administrador le asigne un precio">Sin precio</span> : Number(r.precio_venta).toLocaleString('es-CO')}</div>
                      <div role="cell">{Number(r.iva_venta_porcentaje) === 0 ? <span className="chip">Sin IVA</span> : `${Number(r.iva_venta_porcentaje)} %`}</div>
                      <div role="cell" className={r.existencia === 0 ? 'agotado col-numero' : 'col-numero'}>{r.existencia}</div>
                      {esAdmin && (
                        <div role="cell" className="acciones-fila">
                          <button
                            type="button"
                            className="icono-accion"
                            aria-label={`Editar ${r.codigo_referencia}`}
                            title="Editar"
                            onClick={() => editar(r)}
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

      {editando && (
        <FormularioReferencia
          id={editando.id}
          inicial={editando.datos}
          onListo={() => { setEditando(null); qc.invalidateQueries({ queryKey: ['referencias'] }) }}
          onCerrar={() => setEditando(null)}
        />
      )}
    </div>
  )
}

function FormularioReferencia({ id, inicial, onListo, onCerrar }: { id: number | null; inicial: Formulario; onListo: () => void; onCerrar: () => void }) {
  const [f, setF] = useState(inicial)
  const marcas = useMarcas()
  const proveedores = useProveedores()
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
      const datos = { ...f, iva_venta_porcentaje: Number(f.iva_venta_porcentaje), codigo_universal_id: admiteUniversal && f.codigo_universal_id ? Number(f.codigo_universal_id) : null }
      return id ? api.put(`/referencias/${id}`, datos) : api.post('/referencias', datos)
    },
    onSuccess: onListo,
  })

  const errorPrecioVenta = f.precio_venta === '' ? null : Number(f.precio_venta) > 0 ? null : 'El precio de venta debe ser mayor que 0.'
  const campo = (k: keyof Formulario) => ({ value: f[k], onChange: (e: { target: { value: string } }) => setF({ ...f, [k]: e.target.value }) })

  return (
    <div className="modal">
      <form className="modal-caja" onSubmit={(e) => { e.preventDefault(); guardar.mutate() }}>
        <h2>{id ? 'Editar referencia' : 'Nueva referencia'}</h2>
        <label>Código de referencia<input required {...campo('codigo_referencia')} /></label>
        <label>Descripción<input required {...campo('descripcion')} /></label>
        <label>Marca
          <select required {...campo('marca_id')}><option value="">—</option>{marcas.data?.map((m) => <option key={m.id} value={m.id}>{m.nombre}</option>)}</select>
        </label>
        <label>Proveedor
          <select required {...campo('proveedor_id')}><option value="">—</option>{proveedores.data?.map((p) => <option key={p.id} value={p.id}>{p.nombre}</option>)}</select>
        </label>
        <label>Categoría
          <select required {...campo('categoria_id')}><option value="">—</option>{categorias.data?.map((c) => <option key={c.id} value={c.id}>{c.nombre}</option>)}</select>
        </label>
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
          {errorPrecioVenta ?? 'Mayor que 0. Una referencia sin precio no se puede vender.'}
        </p>
        <label>IVA de venta (%)
          <select {...campo('iva_venta_porcentaje')}>
            <option value="19">19 %</option>
            <option value="0">0 % · sin IVA</option>
          </select>
        </label>
        <p className="ayuda">0 % significa que la referencia se vende sin IVA. Es el IVA que queda guardado en cada venta.</p>
        {guardar.isError && <div className="aviso error">{mensajeError(guardar.error)}</div>}
        <div className="acciones">
          <button type="button" className="secundario" onClick={onCerrar}>Cancelar</button>
          <button className="primario" disabled={guardar.isPending || errorPrecioVenta !== null}>Guardar</button>
        </div>
      </form>
    </div>
  )
}
