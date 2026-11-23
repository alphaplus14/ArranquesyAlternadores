import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { api, mensajeError } from '../../api/cliente'
import type { Cliente, Pagina } from '../../api/tipos'
import Icono from '../../componentes/Icono'
import Medida from '../../componentes/Medida'
import Resumen from '../../componentes/Resumen'
import Paginacion from '../../componentes/Paginacion'
import { useDebounce } from '../../hooks/useDebounce'
import { useListaPaginada } from '../../hooks/useListaPaginada'

type FiltroActivo = '' | '1' | '0'
interface Borrador { nombre: string; documento: string; telefono: string; plazo_dias: string }

const vacio: Borrador = { nombre: '', documento: '', telefono: '', plazo_dias: '' }

/** Texto vacío = null; plazo vacío no se envía (el backend pone el suyo por defecto). */
function datosDe(b: Borrador) {
  return {
    nombre: b.nombre.trim(),
    documento: b.documento.trim() || null,
    telefono: b.telefono.trim() || null,
    ...(b.plazo_dias !== '' ? { plazo_dias: Number(b.plazo_dias) } : {}),
  }
}

/** RF-13: clientes. No se borran: se desactivan. Solo el admin edita. */
export default function Clientes() {
  const qc = useQueryClient()
  const [q, setQ] = useState('')
  const [activo, setActivo] = useState<FiltroActivo>('')
  const [nuevo, setNuevo] = useState<Borrador>(vacio)
  const [editando, setEditando] = useState<{ id: number; datos: Borrador } | null>(null)
  const termino = useDebounce(q.trim())

  const { filas, resultado, info, irA, cargando } = useListaPaginada<Cliente>({
    clave: ['clientes', termino, activo],
    filtros: [q, activo],
    traer: async (pagina) => (await api.get<Pagina<Cliente>>('/clientes', {
      params: { q: termino || undefined, activo: activo || undefined, page: pagina },
    })).data,
  })
  const refrescar = () => qc.invalidateQueries({ queryKey: ['clientes'] })

  const crear = useMutation({
    mutationFn: () => api.post('/clientes', datosDe(nuevo)),
    onSuccess: () => { setNuevo(vacio); refrescar() },
  })
  const guardar = useMutation({
    mutationFn: ({ id, datos }: { id: number; datos: Borrador }) => api.patch(`/clientes/${id}`, datosDe(datos)),
    onSuccess: () => { setEditando(null); refrescar() },
  })
  const alternar = useMutation({
    mutationFn: (c: Cliente) => api.patch(`/clientes/${c.id}`, { activo: !c.activo }),
    onSuccess: refrescar,
  })

  const activos = filas.filter((c) => c.activo).length
  const campoNuevo = (k: keyof Borrador) => ({ value: nuevo[k], onChange: (e: { target: { value: string } }) => setNuevo({ ...nuevo, [k]: e.target.value }) })
  const campoEdicion = (k: keyof Borrador) => ({
    value: editando?.datos[k] ?? '',
    onChange: (e: { target: { value: string } }) => setEditando((ed) => ed && { ...ed, datos: { ...ed.datos, [k]: e.target.value } }),
  })

  return (
    <div className="pila">
      <div className="pagina-encabezado">
        <h1>Clientes</h1>
        <p>Clientes para la venta a crédito. No se borran: se desactivan. El vencimiento de cada venta es su cierre más el plazo del cliente.</p>
      </div>
      <Resumen
        clave="clientes-resumen"
        compacto={(
          <>
            <span><strong>{info ? info.total.toLocaleString('es-CO') : '—'}</strong> clientes</span>
            <span><strong>{resultado ? activos : '—'}</strong> activos</span>
            <span className={resultado && filas.length - activos > 0 ? 'resumen-agotados' : ''}><strong>{resultado ? filas.length - activos : '—'}</strong> inactivos</span>
            <span>Pág. <strong>{info ? `${info.actual}/${info.ultima}` : '—'}</strong></span>
          </>
        )}
      >
        <Medida etiqueta="Clientes" valor={info ? info.total.toLocaleString('es-CO') : '—'} tono="azul" icono="persona" />
        <Medida etiqueta="Activos en esta página" valor={resultado ? activos : '—'} tono="verde" icono="caja" />
        <Medida etiqueta="Inactivos aquí" valor={resultado ? filas.length - activos : '—'} tono="rojo" icono="agotados" />
        <Medida etiqueta="Página" valor={info ? `${info.actual} / ${info.ultima}` : '—'} tono="amarillo" icono="auditoria" />
      </Resumen>

      <section className="tarjeta-pagina">
        <h2>Nuevo cliente</h2>
        <form className="formulario en-linea" onSubmit={(e) => { e.preventDefault(); crear.mutate() }}>
          <input required maxLength={150} placeholder="Nombre" aria-label="Nombre" {...campoNuevo('nombre')} />
          <input maxLength={30} placeholder="Documento (opcional)" aria-label="Documento" {...campoNuevo('documento')} />
          <input maxLength={30} placeholder="Teléfono (opcional)" aria-label="Teléfono" inputMode="tel" {...campoNuevo('telefono')} />
          <input type="number" min={0} max={365} placeholder="Plazo (días)" title="Si lo deja vacío, el sistema pone el plazo por defecto" aria-label="Plazo en días" {...campoNuevo('plazo_dias')} />
          <button className="primario" disabled={crear.isPending || nuevo.nombre.trim() === ''}>Crear cliente</button>
        </form>
        {crear.isError && <div className="aviso error">{mensajeError(crear.error)}</div>}
      </section>

      <section className="tarjeta-pagina">
        <div className="fila-titulo">
          <h2>Listado</h2>
          <div className="acciones">
            <label className="busca">
              <Icono nombre="buscar" />
              <input placeholder="Buscar nombre o documento" aria-label="Buscar clientes" value={q} onChange={(e) => setQ(e.target.value)} />
            </label>
            <select aria-label="Estado" value={activo} onChange={(e) => setActivo(e.target.value as FiltroActivo)}>
              <option value="">Todos</option>
              <option value="1">Activos</option>
              <option value="0">Inactivos</option>
            </select>
          </div>
        </div>
        {(guardar.isError || alternar.isError) && <div className="aviso error">{mensajeError(guardar.error ?? alternar.error)}</div>}
        <table className="tabla-apilable tabla-clientes">
          <thead><tr><th>Nombre</th><th>Documento</th><th>Teléfono</th><th>Plazo</th><th>Estado</th><th><span className="solo-lector">Acciones</span></th></tr></thead>
          <tbody>
            {filas.map((c) => editando?.id === c.id ? (
              <tr key={c.id}>
                <td data-etiqueta="Nombre"><input required maxLength={150} aria-label="Nombre" {...campoEdicion('nombre')} /></td>
                <td data-etiqueta="Documento"><input maxLength={30} aria-label="Documento" {...campoEdicion('documento')} /></td>
                <td data-etiqueta="Teléfono"><input maxLength={30} aria-label="Teléfono" inputMode="tel" {...campoEdicion('telefono')} /></td>
                <td data-etiqueta="Plazo (días)"><input type="number" min={0} max={365} required aria-label="Plazo en días" {...campoEdicion('plazo_dias')} /></td>
                <td data-etiqueta="Estado">{c.activo ? 'Activo' : 'Inactivo'}</td>
                <td className="celda-accion"><div className="acciones-fila">
                  <button
                    type="button"
                    className="enlace"
                    disabled={guardar.isPending || editando.datos.nombre.trim() === '' || editando.datos.plazo_dias === ''}
                    onClick={() => guardar.mutate(editando)}
                  >Guardar</button>
                  <button type="button" className="enlace" onClick={() => { setEditando(null); guardar.reset() }}>Cancelar</button>
                </div></td>
              </tr>
            ) : (
              <tr key={c.id} className={c.activo ? '' : 'reversado'}>
                <td data-etiqueta="Nombre"><strong>{c.nombre}</strong></td>
                <td data-etiqueta="Documento">{c.documento ?? '—'}</td>
                <td data-etiqueta="Teléfono">{c.telefono ?? '—'}</td>
                <td data-etiqueta="Plazo">{c.plazo_dias} días</td>
                <td data-etiqueta="Estado">
                  <button type="button" className="enlace" disabled={alternar.isPending} onClick={() => alternar.mutate(c)}>
                    {c.activo ? 'Activo · desactivar' : 'Inactivo · activar'}
                  </button>
                </td>
                <td className="celda-accion">
                  <button
                    type="button"
                    className="enlace"
                    onClick={() => {
                      guardar.reset()
                      setEditando({ id: c.id, datos: { nombre: c.nombre, documento: c.documento ?? '', telefono: c.telefono ?? '', plazo_dias: String(c.plazo_dias) } })
                    }}
                  >Editar</button>
                </td>
              </tr>
            ))}
            {filas.length === 0 && !cargando && <tr><td colSpan={6} className="vacio">Sin clientes para esta búsqueda.</td></tr>}
          </tbody>
        </table>
        <Paginacion pagina={resultado} onCambiar={irA} />
      </section>
    </div>
  )
}
