import { Fragment, useCallback, useEffect, useRef, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useMutation, useQuery } from '@tanstack/react-query'
import { api, mensajeError } from '../../api/cliente'
import type { CompraResumen, FilaCosto, HistorialCostos, Pagina, ProductoCostos, Referencia } from '../../api/tipos'
import BotonFila from '../../componentes/BotonFila'
import ChipCalidad from '../../componentes/ChipCalidad'
import Icono from '../../componentes/Icono'
import Paginacion from '../../componentes/Paginacion'
import SelectorProveedor from '../../componentes/SelectorProveedor'
import { formatoNumero } from '../../componentes/numeros'
import { useCategorias } from '../../hooks/useCatalogos'
import { useDebounce } from '../../hooks/useDebounce'
import { useListaPaginada } from '../../hooks/useListaPaginada'
import { useScanner } from '../../hooks/useScanner'
import { formatearFecha } from '../../utilidades/fechas'
import './costos.css'

/** Resultado de /buscar (RF-12): la unidad escaneada o referencias por código o descripción. */
interface Busqueda {
  unidad: { referencia?: Referencia } | null
  referencias: Referencia[] | { data: Referencia[] }
}
interface UniversalOpcion { id: number; codigo: string; descripcion: string | null }

const dinero = (v: string | null) => (v === null ? '—' : `$ ${formatoNumero(v)}`)
const porcentaje = (v: string) => `${Number(v).toLocaleString('es-CO', { maximumFractionDigits: 2 })} %`
const documento = (c: { tipo: string; numero_documento: string }) => `${c.tipo === 'factura' ? 'Factura' : 'Remisión'} ${c.numero_documento}`

/**
 * S-71 (presentación S-80): trazabilidad del costo de compra. Sin producto elegido, todas las compras confirmadas con
 * filtros (lo que traía RF-32 / S-77, integrado aquí). Con una referencia o un código universal
 * (RF-02), su historial con último, anterior y variación. Solo consulta y solo en pantalla. Admin y
 * logística (ver-historial-costos); la ruta y el backend lo exigen. Las cifras vienen del backend.
 */
export default function Costos() {
  const [params, setParams] = useSearchParams()
  const referenciaId = params.get('referencia')
  const universalId = referenciaId ? null : params.get('universal')
  const proveedorId = params.get('proveedor') ?? ''
  const categoriaId = params.get('categoria') ?? ''
  const desde = params.get('desde') ?? ''
  const hasta = params.get('hasta') ?? ''
  const hay = referenciaId !== null || universalId !== null
  // Sin producto elegido, lo escrito en el buscador también acota la lista general.
  const [termino, setTermino] = useState('')
  const q = hay || termino.length < 2 ? '' : termino
  const categorias = useCategorias()

  const elegir = (cambios: Record<string, string | null>) => setParams((actual) => {
    const p = new URLSearchParams(actual)
    for (const [k, v] of Object.entries(cambios)) {
      if (v) p.set(k, v)
      else p.delete(k)
    }
    return p
  })

  const { filas, resultado, irA, cargando } = useListaPaginada<FilaCosto>({
    clave: ['costos', referenciaId, universalId, proveedorId, categoriaId, desde, hasta, q],
    filtros: [referenciaId, universalId, proveedorId, categoriaId, desde, hasta, q],
    porPagina: { defecto: 20, tope: 100 },
    traer: async (pagina, porPagina) => (await api.get<HistorialCostos>('/costos', {
      params: {
        referencia_id: referenciaId ?? undefined,
        codigo_universal_id: universalId ?? undefined,
        proveedor_id: proveedorId || undefined,
        categoria_id: hay ? undefined : categoriaId || undefined,
        desde: desde || undefined,
        hasta: hasta || undefined,
        q: q || undefined,
        page: pagina,
        por_pagina: porPagina,
      },
    })).data,
  })
  const historial = resultado as HistorialCostos | undefined
  // Mientras llega otro producto (o la lista general), no se mezcla la cabecera anterior.
  const vigente = historial && (
    (referenciaId && historial.producto?.referencia?.id === Number(referenciaId))
    || (universalId && historial.producto?.universal?.id === Number(universalId))
  ) ? historial : undefined
  const esUniversal = vigente?.producto?.tipo === 'universal'
  // Las filas de otro modo (producto / lista general) no se muestran mientras llega el nuevo.
  const filasVigentes = hay ? (vigente ? filas : []) : (historial && historial.producto === null ? filas : [])
  const pagina = historial?.meta?.current_page ?? historial?.current_page ?? 1
  // S-80: los detalles abiertos se cierran al cambiar filtros o página (la tabla se monta de nuevo).
  const claveTabla = [referenciaId, universalId, proveedorId, categoriaId, desde, hasta, q, pagina].join('|')
  // S-90: con un producto, cada filtro se muestra solo si tiene algo que acotar (sobre todas sus
  // compras, no la página) o si está activo: un filtro activo nunca se oculta.
  const opciones = vigente?.opciones_filtro
  const proveedoresProducto = opciones?.proveedores ?? []
  const verProveedor = !hay || proveedorId !== '' || proveedoresProducto.length >= 2
  const verFechas = !hay || desde !== '' || hasta !== '' || (opciones?.compras ?? 0) > 1
  const verCategoria = !hay
  const filtrosActivos = proveedorId !== '' || desde !== '' || hasta !== '' || (!hay && categoriaId !== '')
  const limpiarFiltros = () => elegir({ proveedor: null, categoria: null, desde: null, hasta: null, page: null })

  return (
    <div className={`pila costos-pantalla${vigente?.producto ? '' : ' pagina-tabla-fija'}`}>
      <div className="pagina-encabezado">
        <h1>Costos por producto</h1>
        <p>Compras confirmadas con su costo neto sin IVA. Elija una referencia o un código universal para ver su último costo, el anterior y la variación.</p>
      </div>

      <section className="tarjeta-pagina">
        <BuscadorProducto
          onTermino={setTermino}
          onReferencia={(id) => elegir({ referencia: String(id), universal: null, page: null })}
          onUniversal={(id) => elegir({ universal: String(id), referencia: null, page: null })}
        />
      </section>

      {vigente?.producto && (
        <>
          <Cabecera
            producto={vigente.producto}
            onReferencia={(id) => elegir({ referencia: String(id), universal: null, proveedor: null })}
            onUniversal={(id) => elegir({ universal: String(id), referencia: null, proveedor: null })}
          />
          {vigente.resumen && <Resumen resumen={vigente.resumen} />}
        </>
      )}

      <section className="tarjeta-pagina tarjeta-tabla">
        <div className="fila-titulo">
          <h2>{hay ? 'Compras' : 'Todas las compras'}</h2>
          {hay && <button type="button" className="enlace" onClick={() => elegir({ referencia: null, universal: null, page: null })}>Ver todas las compras</button>}
        </div>
        {(verProveedor || verFechas || verCategoria) && (
        <div className="costos-filtros">
          {verProveedor && !hay && (
            <div className="costos-campo costos-campo-proveedor">
              <span className="costos-campo-etiqueta" aria-hidden>Proveedor</span>
              <SelectorProveedor
                className="filtro costos-filtro"
                aria-label="Proveedor"
                value={proveedorId}
                onChange={(v) => elegir({ proveedor: v || null })}
                opcionTodos="Todos los proveedores"
              />
            </div>
          )}
          {verProveedor && hay && (
            // S-90: con un producto, solo los proveedores de sus compras (no todo el catálogo).
            <label className="costos-campo">
              <span className="costos-campo-etiqueta">Proveedor</span>
              <select className="costos-select" value={proveedorId} onChange={(e) => elegir({ proveedor: e.target.value || null, page: null })}>
                <option value="">Todos los proveedores</option>
                {proveedoresProducto.map((p) => <option key={p.id} value={p.id}>{p.nombre}</option>)}
                {proveedorId !== '' && !proveedoresProducto.some((p) => String(p.id) === proveedorId) && (
                  <option value={proveedorId}>{opciones ? 'Proveedor sin compras de este producto' : 'Cargando…'}</option>
                )}
              </select>
            </label>
          )}
          {verCategoria && (
            <label className="costos-campo">
              <span className="costos-campo-etiqueta">Categoría</span>
              <select className="costos-select" value={categoriaId} onChange={(e) => elegir({ categoria: e.target.value || null })}>
                <option value="">Todas las categorías</option>
                {categorias.data?.map((c) => <option key={c.id} value={c.id}>{c.nombre}</option>)}
              </select>
            </label>
          )}
          {verFechas && (
            <>
              <label className="costos-campo">
                <span className="costos-campo-etiqueta">Desde</span>
                <input type="date" max={hasta || undefined} value={desde} onChange={(e) => elegir({ desde: e.target.value || null })} />
              </label>
              <label className="costos-campo">
                <span className="costos-campo-etiqueta">Hasta</span>
                <input type="date" min={desde || undefined} value={hasta} onChange={(e) => elegir({ hasta: e.target.value || null })} />
              </label>
            </>
          )}
        </div>
        )}
        {filasVigentes.length === 0 ? (
          cargando || filas.length > 0 || resultado === undefined
            ? <p className="cargando">Cargando…</p>
            : filtrosActivos
              ? (
                <div className="vacio costos-vacio">
                  <p>Nada coincide con estos filtros.</p>
                  <button type="button" className="secundario" onClick={limpiarFiltros}>Limpiar filtros</button>
                </div>
              )
              : <p className="vacio">{hay ? 'Este producto aún no tiene compras confirmadas.' : 'No hay compras confirmadas.'}</p>
        ) : (
          <div className={`tabla-desplazable tabla-viva${vigente?.producto ? '' : ' llena'}${cargando ? ' actualizando' : ''}`}>
            <TablaCostos
              key={claveTabla}
              filas={filasVigentes}
              modo={!hay ? 'lista' : esUniversal ? 'universal' : 'referencia'}
              onReferencia={(id) => elegir({ referencia: String(id), universal: null, page: null })}
            />
          </div>
        )}
        <Paginacion pagina={resultado as Pagina<FilaCosto> | undefined} onCambiar={irA} />
      </section>
    </div>
  )
}

/**
 * Campo único como el de Inventario: se escanea o se escribe. Escribir lista coincidencias de
 * referencias (RF-12) y, si el término es el comienzo de un código universal, ofrece verlo completo.
 * Un escaneo o Enter con una sola coincidencia exacta la abre directo.
 */
function BuscadorProducto({ onTermino, onReferencia, onUniversal }: { onTermino: (t: string) => void; onReferencia: (id: number) => void; onUniversal: (id: number) => void }) {
  const campo = useRef<HTMLInputElement>(null)
  const [q, setQ] = useState('')
  const termino = useDebounce(q.trim())
  const activo = termino.length >= 2
  useEffect(() => { onTermino(termino) }, [termino, onTermino])

  const referencias = useQuery({
    queryKey: ['costos-buscar', termino],
    queryFn: async () => {
      const { data } = await api.get<Busqueda>('/buscar', { params: { q: termino } })
      return Array.isArray(data.referencias) ? data.referencias : data.referencias.data ?? []
    },
    enabled: activo,
  })
  const universales = useQuery({
    queryKey: ['costos-universales', termino],
    queryFn: async () => (await api.get<Pagina<UniversalOpcion>>('/codigos-universales', { params: { q: termino, por_pagina: 5 } })).data.data,
    enabled: activo,
  })

  const limpiar = () => { setQ(''); if (campo.current) campo.current.value = '' }
  const abrirReferencia = (id: number) => { limpiar(); onReferencia(id) }
  const abrirUniversal = (id: number) => { limpiar(); onUniversal(id) }

  const localizar = useMutation({
    mutationFn: async (codigo: string) => (await api.get<Busqueda>('/buscar', { params: { q: codigo } })).data,
    onSuccess: (data, codigo) => {
      const lista = Array.isArray(data.referencias) ? data.referencias : data.referencias.data ?? []
      const exacta = data.unidad?.referencia
        ?? (lista.filter((r) => r.codigo_referencia.toUpperCase() === codigo.toUpperCase() || r.codigo_fabricante === codigo).length === 1
          ? lista.find((r) => r.codigo_referencia.toUpperCase() === codigo.toUpperCase() || r.codigo_fabricante === codigo)
          : undefined)
      if (exacta) abrirReferencia(exacta.id)
      else setQ(codigo)
    },
  })
  const onCodigo = useCallback((codigo: string) => { localizar.mutate(codigo) }, [localizar])
  const scanner = useScanner(onCodigo)

  const lista = activo ? referencias.data ?? [] : []
  const unis = activo ? universales.data ?? [] : []
  return (
    <div className="costos-buscador">
      <div className="campo-unico">
        <Icono nombre="buscar" />
        <input
          ref={campo}
          className="escaner"
          placeholder="Escanee o busque por código o descripción"
          aria-label="Escanear o buscar un producto"
          autoFocus
          autoComplete="off"
          onInput={(e) => setQ(e.currentTarget.value)}
          onKeyDown={(e) => {
            if (e.key === 'Escape' && e.currentTarget.value !== '') { e.preventDefault(); limpiar(); return }
            scanner.onKeyDown(e)
          }}
          onPaste={scanner.onPaste}
        />
      </div>
      {localizar.isError && <div className="aviso error" role="alert">{mensajeError(localizar.error)}</div>}
      {activo && (unis.length > 0 || lista.length > 0) && (
        <ul className="costos-opciones" aria-label="Coincidencias">
          {unis.map((u) => (
            <li key={`u${u.id}`}>
              <button type="button" className="costos-opcion universal" onClick={() => abrirUniversal(u.id)}>
                <strong>Ver todas las referencias del universal {u.codigo}</strong>
                {u.descripcion && <small>{u.descripcion}</small>}
              </button>
            </li>
          ))}
          {lista.map((r) => (
            <li key={r.id}>
              <button type="button" className="costos-opcion" onClick={() => abrirReferencia(r.id)}>
                <strong>{r.codigo_referencia}</strong>
                <small>{[r.descripcion, r.proveedor?.nombre].filter(Boolean).join(' · ')}</small>
                {r.calidad && <ChipCalidad calidad={r.calidad} />}
              </button>
            </li>
          ))}
        </ul>
      )}
      {activo && !referencias.isFetching && !universales.isFetching && lista.length === 0 && unis.length === 0 && (
        <p className="ayuda">Sin coincidencias para «{termino}».</p>
      )}
    </div>
  )
}

function Cabecera({ producto, onReferencia, onUniversal }: { producto: ProductoCostos; onReferencia: (id: number) => void; onUniversal: (id: number) => void }) {
  const { referencia: r, universal: u } = producto
  if (r) {
    return (
      <section className="tarjeta-pagina costos-cabecera">
        <div className="costos-cabecera-titulo">
          <h2>{r.codigo_referencia}</h2>
          {r.calidad && <ChipCalidad calidad={r.calidad} />}
          {!r.activo && <span className="chip inactiva">Inactiva</span>}
        </div>
        <p className="costos-descripcion">{r.descripcion}</p>
        <dl className="costos-datos">
          <div><dt>Proveedor</dt><dd>{r.proveedor.nombre}</dd></div>
          <div><dt>Existencia</dt><dd>{r.existencia}</dd></div>
          <div><dt>Precio de venta (IVA incl.)</dt><dd>{dinero(r.precio_venta)}</dd></div>
          {r.codigo_universal && (
            <div><dt>Código universal</dt><dd><button type="button" className="enlace" onClick={() => onUniversal(r.codigo_universal!.id)}>{r.codigo_universal.codigo}</button></dd></div>
          )}
        </dl>
      </section>
    )
  }
  if (!u) return null
  return (
    <section className="tarjeta-pagina costos-cabecera">
      <div className="costos-cabecera-titulo">
        <h2>Universal {u.codigo}</h2>
        <span className="chip">Existencia total {u.existencia_total}</span>
      </div>
      {u.descripcion && <p className="costos-descripcion">{u.descripcion}</p>}
      <ul className="costos-desglose" aria-label="Referencias del universal">
        {u.referencias.map((x) => (
          <li key={x.id}>
            <button type="button" className="enlace" onClick={() => onReferencia(x.id)}>{x.codigo_referencia}</button>
            <span>{x.proveedor.nombre}</span>
            {x.calidad && <ChipCalidad calidad={x.calidad} />}
            {!x.activo && <span className="chip inactiva">Inactiva</span>}
            <span className="costos-desglose-existencia">{x.existencia}</span>
          </li>
        ))}
      </ul>
    </section>
  )
}

/** Variación con icono y texto (no solo color). */
function Variacion({ valor, corta = false }: { valor: string | null; corta?: boolean }) {
  if (valor === null) return <span className="costos-variacion neutra">—</span>
  const n = Number(valor)
  const texto = `${Math.abs(n).toLocaleString('es-CO', { maximumFractionDigits: 1 })} %`
  const [clase, flecha, palabra] = n > 0 ? ['sube', '▲', 'Subió'] : n < 0 ? ['baja', '▼', 'Bajó'] : ['neutra', '=', 'Igual']
  return (
    <span className={`costos-variacion ${clase}`}>
      <span aria-hidden>{flecha}</span> {corta ? (n === 0 ? palabra : texto) : `${palabra}${n === 0 ? '' : ` ${texto}`}`}
    </span>
  )
}

function Resumen({ resumen }: { resumen: NonNullable<HistorialCostos['resumen']> }) {
  const pie = (c: CompraResumen) => (
    <>
      <small>{formatearFecha(c.fecha)} · {documento(c)}</small>
      <BotonFila compacto variante="abrir" to={`/logistica/ingresos/${c.ingreso_id}`} descripcion={`Abrir ${documento(c)}`} />
    </>
  )
  return (
    <div className="costos-tarjetas">
      <article className="costos-tarjeta">
        <span className="costos-tarjeta-etiqueta">Último costo (sin IVA)</span>
        {resumen.ultimo ? <><strong>{dinero(resumen.ultimo.valor)}</strong>{pie(resumen.ultimo)}</> : <strong>—</strong>}
      </article>
      <article className="costos-tarjeta">
        <span className="costos-tarjeta-etiqueta">Costo anterior</span>
        {resumen.anterior
          ? <><strong>{dinero(resumen.anterior.valor)}</strong>{pie(resumen.anterior)}</>
          : <><strong>—</strong><small>No hay compra anterior para comparar.</small></>}
      </article>
      <article className="costos-tarjeta">
        <span className="costos-tarjeta-etiqueta">Variación</span>
        <strong><Variacion valor={resumen.variacion} /></strong>
        <small>Último frente al anterior</small>
      </article>
      <article className="costos-tarjeta">
        <span className="costos-tarjeta-etiqueta">Compras registradas</span>
        <strong>{resumen.compras}</strong>
        <small>Confirmadas</small>
      </article>
    </div>
  )
}

type ModoTabla = 'lista' | 'referencia' | 'universal'

/**
 * S-80: seis columnas visibles. Lista general: Fecha | Referencia | Proveedor (con el documento debajo)
 * | Cant. | Costo | Acciones. Historial de una referencia o universal: Fecha | Documento y proveedor |
 * Cant. | Costo | Variación | Acciones. Costo en factura, descuento, IVA y costo con IVA van en un
 * detalle plegable por fila (solo lectura, con los datos que ya trae la fila). Se monta de nuevo al
 * cambiar filtros o página, así los detalles abiertos se cierran.
 */
function TablaCostos({ filas, modo, onReferencia }: { filas: FilaCosto[]; modo: ModoTabla; onReferencia: (id: number) => void }) {
  const [abiertas, setAbiertas] = useState<Set<number>>(() => new Set())
  const alternar = (id: number) => setAbiertas((actual) => {
    const nuevo = new Set(actual)
    if (nuevo.has(id)) nuevo.delete(id)
    else nuevo.add(id)
    return nuevo
  })
  const lista = modo === 'lista'

  return (
    <table className="costos-tabla">
      <thead>
        <tr>
          <th scope="col">Fecha</th>
          {lista ? <><th scope="col">Referencia</th><th scope="col">Proveedor</th></> : <th scope="col">Documento y proveedor</th>}
          <th scope="col" className="col-numero">Cant.</th>
          <th scope="col" className="col-numero">Costo<small className="costos-th-sub">sin IVA</small></th>
          {!lista && <th scope="col" className="col-numero">Variación<small className="costos-th-sub">vs anterior</small></th>}
          <th scope="col" className="boton-fila-columna boton-fila-doble">Acciones</th>
        </tr>
      </thead>
      <tbody>
        {filas.map((f) => (
          <FilaCompra
            key={f.linea_id}
            f={f}
            modo={modo}
            abierta={abiertas.has(f.linea_id)}
            onAlternar={() => alternar(f.linea_id)}
            onReferencia={lista ? onReferencia : undefined}
          />
        ))}
      </tbody>
    </table>
  )
}

const COLUMNAS = 6

/** Etiquetas visibles en la fila: Último/Anterior, corrección y "Cubierta" (su costo no cuenta). */
function EtiquetasFila({ f }: { f: FilaCosto }) {
  if (!f.ultimo && !f.anterior && !f.costo_corregido && !f.corregida && !f.cubierta_por) return null
  return (
    <span className="costos-chips">
      {f.ultimo && <span className="chip costos-chip-ultimo">Último</span>}
      {f.anterior && <span className="chip">Anterior</span>}
      {/* RF-28: "Costo corregido" si la corrección cambió el costo de esta línea; si no, el documento. */}
      {f.costo_corregido
        ? <span className="chip corregida" title="Una corrección cambió este costo; se muestra el vigente">Costo corregido</span>
        : f.corregida && <span className="chip corregida">Corregida</span>}
      {f.cubierta_por && <span className="chip" title={`Remisión cubierta por factura ${f.cubierta_por}: su costo no cuenta`}>Cubierta</span>}
    </span>
  )
}

function FilaCompra({ f, modo, abierta, onAlternar, onReferencia }: {
  f: FilaCosto
  modo: ModoTabla
  abierta: boolean
  onAlternar: () => void
  /** Lista general: abrir el historial de la referencia. */
  onReferencia?: (id: number) => void
}) {
  const doc = documento(f)
  const idDetalle = `costos-detalle-${f.linea_id}`
  const descuento = Number(f.descuento_porcentaje)
  const clases = [f.ultimo ? 'costos-fila-ultima' : '', abierta ? 'costos-fila-abierta' : ''].filter(Boolean).join(' ') || undefined
  const referencia = (
    <>
      {onReferencia
        ? <button type="button" className="enlace" title="Ver su historial de costos" onClick={() => onReferencia(f.referencia.id)}>{f.referencia.codigo_referencia}</button>
        : <span>{f.referencia.codigo_referencia}</span>}
      {f.referencia.calidad && <> <ChipCalidad calidad={f.referencia.calidad} /></>}
    </>
  )

  return (
    <Fragment>
      <tr className={clases}>
        <td data-label="Fecha" className="costos-col-fecha">{formatearFecha(f.fecha)}</td>
        {modo === 'lista' ? (
          <>
            <td data-label="Referencia">
              <span className="costos-celda-pila">
                <span>{referencia}</span>
                {f.referencia.descripcion && <small className="costos-ref-descripcion" title={f.referencia.descripcion}>{f.referencia.descripcion}</small>}
              </span>
            </td>
            <td data-label="Proveedor">
              <span className="costos-celda-pila">
                <span>{f.proveedor.nombre}</span>
                <small className="costos-secundario">{doc}</small>
                <EtiquetasFila f={f} />
              </span>
            </td>
          </>
        ) : (
          <td data-label="Documento">
            <span className="costos-celda-pila">
              <span>{doc}</span>
              <small className="costos-secundario">{f.proveedor.nombre}</small>
              {modo === 'universal' && <small className="costos-secundario">{referencia}</small>}
              <EtiquetasFila f={f} />
            </span>
          </td>
        )}
        <td data-label="Cant." className="col-numero">{f.cantidad}</td>
        <td data-label="Costo (sin IVA)" className="col-numero">
          <span className="costos-celda-pila costos-costo">
            {f.sin_costo || f.costo_neto === null
              ? <span><span className="chip sin-precio">Sin costo</span> —</span>
              : <strong>{dinero(f.costo_neto)}</strong>}
            {descuento > 0 && <small className="costos-secundario">−{porcentaje(f.descuento_porcentaje)} desc.</small>}
          </span>
        </td>
        {modo !== 'lista' && <td data-label="Variación vs anterior" className="col-numero"><Variacion valor={f.variacion} corta /></td>}
        <td data-label="Acciones" className="boton-fila-columna boton-fila-doble costos-acciones">
          <span className="boton-fila-acciones">
            <BotonFila variante="abrir" to={`/logistica/ingresos/${f.ingreso_id}`} descripcion={`Abrir ${doc} de ${f.proveedor.nombre}`} />
            <button
              type="button"
              className={abierta ? 'costos-detalle-boton abierto' : 'costos-detalle-boton'}
              aria-expanded={abierta}
              aria-controls={idDetalle}
              aria-label={`Ver detalle de ${doc} de ${f.proveedor.nombre}`}
              title="Detalle"
              onClick={onAlternar}
            >
              <Icono nombre="chevron" />
            </button>
          </span>
        </td>
      </tr>
      {abierta && (
        <tr id={idDetalle} className={f.ultimo ? 'costos-detalle-fila costos-fila-ultima' : 'costos-detalle-fila'}>
          <td colSpan={COLUMNAS} data-label="">
            <dl className="costos-detalle">
              <div><dt>Costo en factura</dt><dd>{f.sin_costo ? '—' : dinero(f.costo_factura)}</dd></div>
              <div><dt>Descuento %</dt><dd>{porcentaje(f.descuento_porcentaje)}</dd></div>
              <div><dt>IVA %</dt><dd>{porcentaje(f.iva_porcentaje)}</dd></div>
              <div><dt>Costo con IVA</dt><dd>{dinero(f.costo_con_iva)}</dd></div>
              {(f.cubierta_por || f.cubre_remisiones.length > 0) && (
                <div className="costos-detalle-ancho">
                  <dt>Remisiones y facturas</dt>
                  <dd className="costos-chips">
                    {f.cubierta_por && <span className="chip" title="Su costo no cuenta: manda el de la factura">Remisión cubierta por factura {f.cubierta_por}</span>}
                    {f.cubre_remisiones.map((n) => <span key={n} className="chip">Cubre remisión {n}</span>)}
                  </dd>
                </div>
              )}
            </dl>
          </td>
        </tr>
      )}
    </Fragment>
  )
}
