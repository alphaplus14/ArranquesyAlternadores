import { useCallback, useRef, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery } from '@tanstack/react-query'
import { api, mensajeError } from '../../api/cliente'
import type { CompraResumen, FilaCosto, HistorialCostos, Pagina, Referencia } from '../../api/tipos'
import ChipCalidad from '../../componentes/ChipCalidad'
import Icono from '../../componentes/Icono'
import Paginacion from '../../componentes/Paginacion'
import SelectorProveedor from '../../componentes/SelectorProveedor'
import { formatoNumero } from '../../componentes/numeros'
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
 * S-71: trazabilidad del costo de compra de una referencia o de un código universal (RF-02).
 * Solo consulta y solo en pantalla. Admin y logística (ver-historial-costos); la ruta y el
 * backend lo exigen. Las cifras (costo neto, con IVA, variación) vienen del backend.
 */
export default function Costos() {
  const [params, setParams] = useSearchParams()
  const referenciaId = params.get('referencia')
  const universalId = referenciaId ? null : params.get('universal')
  const proveedorId = params.get('proveedor') ?? ''
  const hay = referenciaId !== null || universalId !== null

  const elegir = (cambios: Record<string, string | null>) => setParams((actual) => {
    const p = new URLSearchParams(actual)
    for (const [k, v] of Object.entries(cambios)) {
      if (v) p.set(k, v)
      else p.delete(k)
    }
    return p
  })

  const { filas, resultado, irA, cargando } = useListaPaginada<FilaCosto>({
    clave: ['costos', referenciaId, universalId, proveedorId],
    filtros: [referenciaId, universalId, proveedorId],
    enabled: hay,
    porPagina: { defecto: 20, tope: 100 },
    traer: async (pagina, porPagina) => (await api.get<HistorialCostos>('/costos', {
      params: {
        referencia_id: referenciaId ?? undefined,
        codigo_universal_id: universalId ?? undefined,
        proveedor_id: proveedorId || undefined,
        page: pagina,
        por_pagina: porPagina,
      },
    })).data,
  })
  const historial = resultado as HistorialCostos | undefined
  // Mientras llega otro producto, no se mezcla la cabecera anterior.
  const vigente = historial && (
    (referenciaId && historial.producto.referencia?.id === Number(referenciaId))
    || (universalId && historial.producto.universal?.id === Number(universalId))
  ) ? historial : undefined
  const esUniversal = vigente?.producto.tipo === 'universal'

  return (
    <div className="pila costos-pantalla">
      <div className="pagina-encabezado">
        <h1>Costos por producto</h1>
        <p>Compras confirmadas de una referencia o de un código universal, con su costo neto sin IVA.</p>
      </div>

      <section className="tarjeta-pagina">
        <BuscadorProducto
          onReferencia={(id) => elegir({ referencia: String(id), universal: null, page: null })}
          onUniversal={(id) => elegir({ universal: String(id), referencia: null, page: null })}
        />
      </section>

      {hay && !vigente && !cargando && resultado === undefined && <div className="cargando">Cargando…</div>}
      {vigente && (
        <>
          <Cabecera
            historial={vigente}
            onReferencia={(id) => elegir({ referencia: String(id), universal: null, proveedor: null })}
            onUniversal={(id) => elegir({ universal: String(id), referencia: null, proveedor: null })}
          />
          <Resumen resumen={vigente.resumen} />

          <section className="tarjeta-pagina">
            <div className="fila-titulo">
              <h2>Compras</h2>
              <SelectorProveedor
                className="filtro costos-filtro"
                aria-label="Proveedor"
                value={proveedorId}
                onChange={(v) => elegir({ proveedor: v || null })}
                opcionTodos="Todos los proveedores"
              />
            </div>
            {filas.length === 0 ? (
              <p className="vacio">Este producto aún no tiene compras confirmadas.</p>
            ) : (
              <div className={`tabla-desplazable${cargando ? ' actualizando' : ''}`}>
                <table className="tabla-lineas costos-tabla">
                  <thead>
                    <tr>
                      <th>Fecha</th><th>Documento</th><th>Proveedor</th>{esUniversal && <th>Referencia</th>}
                      <th className="col-numero">Cant.</th><th className="col-numero">Costo en factura</th>
                      <th className="col-numero">Desc. %</th><th className="col-numero">IVA %</th>
                      <th className="col-numero">Costo neto sin IVA</th><th className="col-numero">Costo con IVA</th>
                      <th className="col-numero">Variación vs anterior</th>
                    </tr>
                  </thead>
                  <tbody>
                    {filas.map((f) => <FilaCompra key={f.linea_id} f={f} esUniversal={esUniversal} />)}
                  </tbody>
                </table>
              </div>
            )}
            <Paginacion pagina={resultado as Pagina<FilaCosto> | undefined} onCambiar={irA} />
          </section>
        </>
      )}
    </div>
  )
}

/**
 * Campo único como el de Inventario: se escanea o se escribe. Escribir lista coincidencias de
 * referencias (RF-12) y, si el término es el comienzo de un código universal, ofrece verlo completo.
 * Un escaneo o Enter con una sola coincidencia exacta la abre directo.
 */
function BuscadorProducto({ onReferencia, onUniversal }: { onReferencia: (id: number) => void; onUniversal: (id: number) => void }) {
  const campo = useRef<HTMLInputElement>(null)
  const [q, setQ] = useState('')
  const termino = useDebounce(q.trim())
  const activo = termino.length >= 2

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

function Cabecera({ historial, onReferencia, onUniversal }: { historial: HistorialCostos; onReferencia: (id: number) => void; onUniversal: (id: number) => void }) {
  const { referencia: r, universal: u } = historial.producto
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

function Resumen({ resumen }: { resumen: HistorialCostos['resumen'] }) {
  const pie = (c: CompraResumen) => (
    <small>{formatearFecha(c.fecha)} · <Link to={`/logistica/ingresos/${c.ingreso_id}`}>{documento(c)}</Link></small>
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

function FilaCompra({ f, esUniversal }: { f: FilaCosto; esUniversal: boolean }) {
  return (
    <tr className={f.ultimo ? 'costos-fila-ultima' : undefined}>
      <td data-label="Fecha">{formatearFecha(f.fecha)}</td>
      <td data-label="Documento">
        <Link to={`/logistica/ingresos/${f.ingreso_id}`}>{documento(f)}</Link>
        <span className="costos-chips">
          {f.ultimo && <span className="chip costos-chip-ultimo">Último</span>}
          {f.anterior && <span className="chip">Anterior</span>}
          <span className="chip">{f.tipo === 'factura' ? 'Factura' : 'Remisión'}</span>
          {f.corregida && <span className="chip corregida">Corregida</span>}
          {f.sin_costo && <span className="chip sin-precio">Sin costo</span>}
          {f.cubierta_por && <span className="chip" title="Su costo no cuenta: manda el de la factura">Remisión cubierta por factura {f.cubierta_por}</span>}
          {f.cubre_remisiones.map((n) => <span key={n} className="chip">Cubre remisión {n}</span>)}
        </span>
      </td>
      <td data-label="Proveedor">{f.proveedor.nombre}</td>
      {esUniversal && (
        <td data-label="Referencia">{f.referencia.codigo_referencia} {f.referencia.calidad && <ChipCalidad calidad={f.referencia.calidad} />}</td>
      )}
      <td data-label="Cant." className="col-numero">{f.cantidad}</td>
      <td data-label="Costo en factura" className="col-numero">{f.sin_costo ? '—' : dinero(f.costo_factura)}</td>
      <td data-label="Desc. %" className="col-numero">{porcentaje(f.descuento_porcentaje)}</td>
      <td data-label="IVA %" className="col-numero">{porcentaje(f.iva_porcentaje)}</td>
      <td data-label="Costo neto sin IVA" className="col-numero"><strong>{dinero(f.costo_neto)}</strong></td>
      <td data-label="Costo con IVA" className="col-numero">{dinero(f.costo_con_iva)}</td>
      <td data-label="Variación vs anterior" className="col-numero"><Variacion valor={f.variacion} corta /></td>
    </tr>
  )
}
