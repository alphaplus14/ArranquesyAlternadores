import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { api, mensajeError } from '../../api/cliente'
import { useAvisoTexto } from '../../componentes/Avisos'
import type { Pagina } from '../../api/tipos'
import { useListaPaginada } from '../../hooks/useListaPaginada'
import { useCategorias } from '../../hooks/useCatalogos'
import { useDebounce } from '../../hooks/useDebounce'
import Medida from '../../componentes/Medida'
import Resumen from '../../componentes/Resumen'
import Paginacion from '../../componentes/Paginacion'
import { RUTA_UNIVERSALES } from '../../rutas'
import { useAuth } from '../../auth/AuthProvider'
import { aValorMinimo, errorStockMinimo, textoMinimo } from '../../utilidades/stockMinimo'

interface FilaUniversal { id: number; codigo: string; descripcion: string | null; existencia: string | null; stock_minimo: number | null; categoria: { nombre: string } }

/** RF-01 / RF-02: códigos universales con su existencia calculada. S-59: admin y logística crean y editan; nadie elimina aquí. */
export default function Universales() {
  const qc = useQueryClient()
  const [q, setQ] = useState('')
  const [nuevo, setNuevo] = useState({ codigo: '', categoria_id: '', descripcion: '', stock_minimo: '' })
  const termino = useDebounce(q.trim())
  const categorias = useCategorias()
  // S-28 / S-59: logística crea universales, pero el mínimo del universal lo fija solo el admin.
  const esAdmin = useAuth().usuario?.rol === 'admin'

  const { filas, resultado, info, irA } = useListaPaginada<FilaUniversal>({
    clave: ['universales', termino],
    filtros: q,
    porPagina: { defecto: 25 }, // S-70
    traer: async (pagina, porPagina) => (await api.get<Pagina<FilaUniversal>>('/codigos-universales', { params: { q: termino || undefined, page: pagina, por_pagina: porPagina } })).data,
  })

  const crear = useMutation({
    mutationFn: async () => api.post('/codigos-universales', esAdmin
      ? { ...nuevo, stock_minimo: aValorMinimo(nuevo.stock_minimo) }
      : { codigo: nuevo.codigo, categoria_id: nuevo.categoria_id, descripcion: nuevo.descripcion }),
    onSuccess: () => { setNuevo({ codigo: '', categoria_id: '', descripcion: '', stock_minimo: '' }); qc.invalidateQueries({ queryKey: ['universales'] }) },
  })
  useAvisoTexto(crear.isError ? mensajeError(crear.error) : null)

  return (
    <div className="pila">
      <div className="pagina-encabezado">
        <h1>Códigos universales</h1>
        <p>La existencia del universal es la suma de sus referencias. El detalle no se pierde.</p>
      </div>
      <Resumen
        clave="universales-resumen"
        compacto={(
          <>
            <span><strong>{info ? info.total.toLocaleString('es-CO') : '—'}</strong> universales</span>
            <span>Pág. <strong>{info ? `${info.actual}/${info.ultima}` : '—'}</strong></span>
            <span>En página <strong>{resultado ? filas.length : '—'}</strong></span>
            <span>Búsqueda <strong>{termino || 'Todas'}</strong></span>
          </>
        )}
      >
        <Medida etiqueta="Universales" valor={info ? info.total.toLocaleString('es-CO') : '—'} tono="azul" icono="universal" />
        <Medida etiqueta="Página" valor={info ? `${info.actual} / ${info.ultima}` : '—'} tono="amarillo" icono="catalogo" />
        <Medida etiqueta="En esta página" valor={resultado ? filas.length : '—'} tono="verde" icono="caja" />
        <Medida etiqueta="Búsqueda" valor={termino || 'Todas'} tono="rojo" icono="buscar" codigo={!!termino} />
      </Resumen>
      <section className="tarjeta-pagina">
      <h2>Nuevo código</h2>
      <form className="formulario en-linea" onSubmit={(e) => { e.preventDefault(); crear.mutate() }}>
        <input required maxLength={60} title="En reguladores use el código de Transpo (ej. IM204)" placeholder="Código (ej. IM204)" value={nuevo.codigo} onChange={(e) => setNuevo({ ...nuevo, codigo: e.target.value })} />
        <select required value={nuevo.categoria_id} onChange={(e) => setNuevo({ ...nuevo, categoria_id: e.target.value })}>
          <option value="">Categoría…</option>
          {categorias.data?.filter((c) => c.admite_codigo_universal).map((c) => <option key={c.id} value={c.id}>{c.nombre}</option>)}
        </select>
        <input placeholder="Descripción" value={nuevo.descripcion} onChange={(e) => setNuevo({ ...nuevo, descripcion: e.target.value })} />
        {esAdmin && <input
          type="number" min="0" max="9999" step="1" inputMode="numeric"
          aria-label="Stock mínimo del universal (opcional)"
          title="Vacío = usa el general; 0 = no maneja stock mínimo. Se evalúa sobre la suma de sus referencias."
          placeholder="Stock mínimo (opcional)"
          aria-invalid={errorStockMinimo(nuevo.stock_minimo) !== null}
          value={nuevo.stock_minimo}
          onChange={(e) => setNuevo({ ...nuevo, stock_minimo: e.target.value })}
        />}
        <button className="primario" disabled={crear.isPending || errorStockMinimo(nuevo.stock_minimo) !== null}>Crear</button>
      </form>
      </section>

      <section className="tarjeta-pagina">
      <div className="fila-titulo">
        <h2>Listado</h2>
        <label className="busca">
          <input aria-label="Buscar código universal" placeholder="Buscar código universal" value={q} onChange={(e) => setQ(e.target.value)} />
        </label>
      </div>
      <table className="tabla-apilable">
        <thead><tr><th>Código</th><th>Categoría</th><th>Descripción</th><th>Existencia total</th><th>Stock mínimo</th></tr></thead>
        <tbody>
          {filas.map((u) => (
            <tr key={u.id}>
              <td data-etiqueta="Código"><Link to={`${RUTA_UNIVERSALES}/${u.id}`}><strong>{u.codigo}</strong></Link></td>
              <td data-etiqueta="Categoría">{u.categoria.nombre}</td>
              <td data-etiqueta="Descripción">{u.descripcion || '—'}</td>
              <td data-etiqueta="Existencia total">{Number(u.existencia ?? 0)}</td>
              <td data-etiqueta="Stock mínimo">{textoMinimo(u.stock_minimo)}</td>
            </tr>
          ))}
        </tbody>
      </table>
      <Paginacion pagina={resultado} onCambiar={irA} />
      </section>
    </div>
  )
}
