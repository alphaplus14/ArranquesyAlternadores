import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { api } from '../api/cliente'
import type { FilaBajoMinimo, Pagina, Referencia } from '../api/tipos'
import { useCategorias, useProveedores } from '../hooks/useCatalogos'
import { useListaPaginada } from '../hooks/useListaPaginada'
import Medida from '../componentes/Medida'
import Resumen from '../componentes/Resumen'
import Paginacion from '../componentes/Paginacion'
import SelectorProveedor from '../componentes/SelectorProveedor'
import ChipCalidad from '../componentes/ChipCalidad'
import ControlSegmentado from '../componentes/ControlSegmentado'

type Vista = 'agotados' | 'bajo-minimo'

const VISTAS: { valor: Vista; texto: string }[] = [
  { valor: 'agotados', texto: 'Agotados' },
  { valor: 'bajo-minimo', texto: 'Bajo mínimo' },
]

/**
 * RF-08 / S-47: referencias activas con existencia 0, de todos los proveedores por defecto (proveedor
 * y categoría acotan); una agotada cuyo universal tiene existencia en otras referencias se marca
 * (regla 2). RF-22: productos con existencia igual o menor a su stock mínimo (universales por la suma
 * de sus referencias). Solo en pantalla.
 */
export default function Agotados() {
  const [params, setParams] = useSearchParams()
  // S-28: la pantalla es de logística y admin (ruta protegida); las dos pestañas para ambos.
  const vista: Vista = params.get('vista') === 'bajo-minimo' ? 'bajo-minimo' : 'agotados'
  const proveedores = useProveedores()
  const categorias = useCategorias()
  // Los filtros se comparten entre las dos pestañas.
  const [proveedorId, setProveedorId] = useState('')
  const [categoriaId, setCategoriaId] = useState('')

  const agotados = useListaPaginada<Referencia>({
    clave: ['agotados', proveedorId, categoriaId],
    filtros: [proveedorId, categoriaId],
    enabled: vista === 'agotados',
    porPagina: { defecto: 50 }, // S-70
    traer: async (pagina, porPagina) => (await api.get<Pagina<Referencia>>('/reportes/agotados', {
      params: { proveedor_id: proveedorId || undefined, categoria_id: categoriaId || undefined, page: pagina, por_pagina: porPagina },
    })).data,
  })

  const bajo = useListaPaginada<FilaBajoMinimo>({
    clave: ['bajo-minimo', proveedorId, categoriaId],
    filtros: [proveedorId, categoriaId],
    enabled: vista === 'bajo-minimo',
    porPagina: { defecto: 50 }, // S-70
    traer: async (pagina, porPagina) => (await api.get<Pagina<FilaBajoMinimo>>('/reportes/bajo-minimo', {
      params: { proveedor_id: proveedorId || undefined, categoria_id: categoriaId || undefined, page: pagina, por_pagina: porPagina },
    })).data,
  })

  const proveedor = proveedores.data?.find((p) => String(p.id) === proveedorId)
  const categoria = categorias.data?.find((c) => String(c.id) === categoriaId)
  const actual = vista === 'agotados' ? agotados : bajo
  const info = actual.info
  const faltanteTotal = bajo.filas.reduce((s, f) => s + f.faltante, 0)

  return (
    <div className="pila pagina-agotados">
      <div className="pagina-encabezado">
        <h1>{vista === 'agotados' ? (proveedor ? `Agotados de ${proveedor.nombre}` : 'Agotados') : 'Bajo mínimo'}</h1>
        <p>
          {vista === 'agotados'
            ? 'Referencias con existencia cero de todos los proveedores. Filtre por proveedor o categoría para acotar.'
            : 'Productos con existencia igual o menor a su stock mínimo. Los universales se evalúan por la suma de sus referencias.'}
        </p>
      </div>
      <Resumen
        clave="agotados-resumen"
        compacto={(
          <>
            <span>Proveedor <strong>{proveedor?.nombre ?? 'Todos'}</strong></span>
            <span className={info && info.total > 0 ? 'resumen-agotados' : ''}><strong>{info ? info.total.toLocaleString('es-CO') : '—'}</strong> {vista === 'agotados' ? 'agotados' : 'bajo mínimo'}</span>
            <span>Pág. <strong>{info ? `${info.actual}/${info.ultima}` : '—'}</strong></span>
            <span>Categoría <strong>{categoria?.nombre ?? 'Todas'}</strong></span>
          </>
        )}
      >
        <Medida etiqueta="Proveedor" valor={proveedor?.nombre ?? 'Todos'} tono="azul" icono="catalogo" />
        <Medida etiqueta={vista === 'agotados' ? 'Agotados' : 'Bajo mínimo'} valor={info ? info.total.toLocaleString('es-CO') : '—'} tono="rojo" icono="agotados" />
        <Medida etiqueta="Página" valor={info ? `${info.actual} / ${info.ultima}` : '—'} tono="amarillo" icono="auditoria" />
        {vista === 'agotados'
          ? <Medida etiqueta="En esta página" valor={agotados.resultado ? agotados.filas.length : '—'} tono="verde" icono="caja" />
          : <Medida etiqueta="Faltante (página)" valor={bajo.resultado ? faltanteTotal.toLocaleString('es-CO') : '—'} tono="verde" icono="caja" />}
      </Resumen>
      <section className="tarjeta-pagina">
        <ControlSegmentado
          id="vista-agotados"
          etiqueta="Informe"
          opciones={VISTAS}
          valor={vista}
          onCambio={(v) => setParams(v === 'agotados' ? {} : { vista: v }, { replace: true })}
        />
        <div className="formulario en-linea filtros-informe">
          <label>Proveedor
            <SelectorProveedor
              className="filtro"
              aria-label="Proveedor"
              value={proveedorId}
              onChange={setProveedorId}
              placeholder="Todos los proveedores"
              limpiable
            />
          </label>
          <label>Categoría
            <select value={categoriaId} onChange={(e) => setCategoriaId(e.target.value)}>
              <option value="">Todas</option>
              {categorias.data?.map((c) => <option key={c.id} value={c.id}>{c.nombre}</option>)}
            </select>
          </label>
        </div>

        {vista === 'agotados' ? (
          <>
            {agotados.resultado && (
              <>
                <div className="tabla-viva">
                <table className="tabla-apilable tabla-agotados">
                  <caption className="solo-lector">Referencias agotadas, por proveedor y código</caption>
                  <thead><tr><th>Código</th><th>Descripción</th><th>Proveedor</th><th>Categoría</th><th>Calidad</th><th className="col-numero">Existencia</th></tr></thead>
                  <tbody>
                    {agotados.filas.map((r) => (
                      <tr key={r.id}>
                        <td data-etiqueta="Código">
                          <span className="agotado-codigo">
                            <strong>{r.codigo_referencia}</strong>
                            {r.codigo_universal && (r.universal_existencia ?? 0) > 0 && (
                              <small
                                className="aviso-universal"
                                title={`El repuesto no está agotado en conjunto: el universal ${r.codigo_universal.codigo} suma ${r.universal_existencia} en otras referencias (la existencia del universal es la suma de sus referencias).`}
                              >
                                Universal {r.codigo_universal.codigo}: {r.universal_existencia} en otras referencias
                              </small>
                            )}
                          </span>
                        </td>
                        <td data-etiqueta="Descripción">{r.descripcion ?? '—'}</td>
                        <td data-etiqueta="Proveedor">{r.proveedor?.nombre ?? '—'}</td>
                        <td data-etiqueta="Categoría">{r.categoria?.nombre ?? '—'}</td>
                        <td data-etiqueta="Calidad"><ChipCalidad calidad={r.calidad} /></td>
                        <td data-etiqueta="Existencia" className="col-numero agotado">{r.existencia}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
                </div>
                {agotados.filas.length === 0 && (
                  <p className="vacio">{proveedor ? 'Este proveedor no tiene agotados' : 'No hay agotados'}{categoria ? ' en esa categoría' : ''}.</p>
                )}
                <Paginacion pagina={agotados.resultado} onCambiar={agotados.irA} />
              </>
            )}
          </>
        ) : (
          <>
            {bajo.resultado && (
              <div className="tabla-viva">
              <table className="tabla-apilable tabla-bajo-minimo">
                <caption className="solo-lector">Productos bajo mínimo, mayor faltante primero</caption>
                <thead><tr><th>Código</th><th>Descripción</th><th>Proveedor</th><th>Categoría</th><th className="col-numero">Existencia</th><th className="col-numero">Mínimo</th><th className="col-numero">Faltante</th></tr></thead>
                <tbody>
                  {bajo.filas.map((f) => (
                    <tr key={`${f.tipo}-${f.id}`}>
                      <td data-etiqueta="Código">
                        <span>
                          <strong>{f.codigo}</strong>{' '}
                          {f.tipo === 'universal' ? <span className="chip">Universal</span> : <ChipCalidad calidad={f.calidad} />}
                        </span>
                      </td>
                      <td data-etiqueta="Descripción">{f.descripcion ?? '—'}</td>
                      <td data-etiqueta="Proveedor">
                        {f.proveedor ? f.proveedor.nombre : (
                          <ul className="desglose-minimo" aria-label={`Desglose de ${f.codigo}`}>
                            {f.referencias?.map((r) => (
                              <li key={r.id}><strong>{r.codigo_referencia}</strong> · {r.proveedor.nombre} · {r.existencia}</li>
                            ))}
                          </ul>
                        )}
                      </td>
                      <td data-etiqueta="Categoría">{f.categoria.nombre}</td>
                      <td data-etiqueta="Existencia" className={f.existencia === 0 ? 'col-numero agotado' : 'col-numero'}>{f.existencia}</td>
                      <td data-etiqueta="Mínimo" className="col-numero">
                        <span>{f.minimo} <small className="origen-minimo">{f.origen === 'general' ? 'general' : 'propio'}</small></span>
                      </td>
                      <td data-etiqueta="Faltante" className="col-numero"><strong>{f.faltante}</strong></td>
                    </tr>
                  ))}
                </tbody>
              </table>
              </div>
            )}
            {bajo.resultado && bajo.filas.length === 0 && (
              <p className="vacio">Nada bajo mínimo con estos filtros. Si nadie tiene mínimo, el administrador puede fijar uno general en Configuración.</p>
            )}
            <Paginacion pagina={bajo.resultado} onCambiar={bajo.irA} />
          </>
        )}
      </section>
    </div>
  )
}
