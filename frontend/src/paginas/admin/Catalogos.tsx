import { useMemo, useState, type KeyboardEvent } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { api, mensajeError } from '../../api/cliente'
import type { Categoria, Proveedor } from '../../api/tipos'
import Icono from '../../componentes/Icono'
import DialogoProveedor from '../../componentes/DialogoProveedor'
import { filtrarProveedores } from '../../componentes/filtroProveedores'
import { useCategorias, useProveedores } from '../../hooks/useCatalogos'

type Clave = 'proveedores' | 'categorias'

const PESTANAS: { clave: Clave; titulo: string; singular: string; alta: string; ruta: string }[] = [
  { clave: 'proveedores', titulo: 'Proveedores', singular: 'proveedor', alta: 'Nuevo proveedor', ruta: 'proveedores' },
  { clave: 'categorias', titulo: 'Categorías', singular: 'categoría', alta: 'Nueva categoría', ruta: 'categorias' },
]

/**
 * Catálogos editables (solo admin). RF-11: cada cambio queda auditado. S-23: la empresa no maneja
 * marcas. S-29: los proveedores se crean y editan con todos sus campos en un diálogo.
 */
export default function Catalogos() {
  const proveedores = useProveedores()
  const categorias = useCategorias()
  const [activa, setActiva] = useState<Clave>('proveedores')

  const datos: Record<Clave, { filas: Array<Proveedor | Categoria>; cargando: boolean }> = {
    proveedores: { filas: proveedores.data ?? [], cargando: proveedores.isLoading },
    categorias: { filas: categorias.data ?? [], cargando: categorias.isLoading },
  }
  const pestana = PESTANAS.find((p) => p.clave === activa) ?? PESTANAS[0]

  function mover(e: KeyboardEvent) {
    if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return
    e.preventDefault()
    const i = PESTANAS.findIndex((p) => p.clave === activa)
    const dir = e.key === 'ArrowRight' ? 1 : -1
    const siguiente = PESTANAS[(i + dir + PESTANAS.length) % PESTANAS.length]
    setActiva(siguiente.clave)
    document.getElementById(`pestana-${siguiente.clave}`)?.focus()
  }

  return (
    <div>
      <div className="pagina-encabezado">
        <h1>Catálogos</h1>
        <p>Proveedores y categorías se editan aquí. Cada cambio queda auditado.</p>
      </div>
      <section className="panel catalogo">
        <div className="pestanas" role="tablist" aria-label="Catálogos" onKeyDown={mover}>
          {PESTANAS.map((p) => (
            <button
              key={p.clave}
              type="button"
              role="tab"
              id={`pestana-${p.clave}`}
              aria-selected={activa === p.clave}
              aria-controls="panel-catalogo"
              tabIndex={activa === p.clave ? 0 : -1}
              className={activa === p.clave ? 'pestana activa' : 'pestana'}
              onClick={() => setActiva(p.clave)}
            >
              {p.titulo}
              <span className="pestana-cuenta">{datos[p.clave].cargando ? '…' : datos[p.clave].filas.length}</span>
            </button>
          ))}
        </div>
        {pestana.clave === 'proveedores' ? (
          <ListaProveedores filas={proveedores.data ?? []} cargando={proveedores.isLoading} />
        ) : (
          <Catalogo
            key={pestana.clave}
            titulo={pestana.titulo}
            singular={pestana.singular}
            alta={pestana.alta}
            ruta={pestana.ruta}
            clave={pestana.clave}
            filas={datos[pestana.clave].filas}
            cargando={datos[pestana.clave].cargando}
            esCategoria
          />
        )}
      </section>
    </div>
  )
}

/**
 * S-29: proveedores con NIT y asesor. "Nuevo proveedor" y "Editar" abren el mismo diálogo. El
 * buscador filtra en el navegador por nombre, NIT (sin puntos ni guiones) y asesor.
 */
function ListaProveedores({ filas, cargando }: { filas: Proveedor[]; cargando: boolean }) {
  const [busqueda, setBusqueda] = useState('')
  const [dialogo, setDialogo] = useState<{ proveedor?: Proveedor } | null>(null)
  const [aviso, setAviso] = useState<string | null>(null)
  const visibles = useMemo(() => filtrarProveedores(filas, busqueda), [filas, busqueda])

  return (
    <div role="tabpanel" id="panel-catalogo" aria-labelledby="pestana-proveedores">
      <div className="catalogo-barra">
        <button type="button" className="primario boton-alta" onClick={() => { setAviso(null); setDialogo({}) }}>Nuevo proveedor</button>
        <label className="busca">
          <Icono nombre="buscar" />
          <input placeholder="Nombre, NIT o asesor" value={busqueda} onChange={(e) => setBusqueda(e.target.value)} aria-label="Buscar proveedor" title="Busca por nombre, NIT o asesor" />
        </label>
      </div>
      {aviso && <div className="aviso ok catalogo-aviso" role="status">{aviso}</div>}
      <div className="tabla-marcos-scroll">
        <table className="tabla-marcos tabla-proveedores">
          <thead>
            <tr><th>Nombre</th><th>NIT</th><th>Asesor</th><th className="col-accion">Acciones</th></tr>
          </thead>
          <tbody>
            {cargando && <tr className="tabla-marcos-vacia"><td colSpan={4}>Cargando proveedores…</td></tr>}
            {!cargando && visibles.length === 0 && (
              <tr className="tabla-marcos-vacia">
                <td colSpan={4}>{filas.length === 0 ? 'Todavía no hay proveedores.' : `Ningún resultado para «${busqueda.trim()}».`}</td>
              </tr>
            )}
            {visibles.map((p) => (
              <tr key={p.id}>
                <td data-etiqueta="Nombre"><strong>{p.nombre}</strong></td>
                <td data-etiqueta="NIT">{p.nit ? <span className="mono">{p.nit}</span> : <span className="chip sin-nit">Sin NIT</span>}</td>
                <td data-etiqueta="Asesor">{p.asesor ?? <span className="suave">—</span>}</td>
                <td className="col-accion">
                  <button type="button" className="accion" aria-label={`Editar ${p.nombre}`} onClick={() => { setAviso(null); setDialogo({ proveedor: p }) }}>Editar</button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {dialogo && (
        <DialogoProveedor
          key={dialogo.proveedor?.id ?? 'nuevo'}
          proveedor={dialogo.proveedor}
          onGuardado={(p) => { setDialogo(null); setAviso(dialogo.proveedor ? `Proveedor ${p.nombre} actualizado.` : `Proveedor ${p.nombre} creado.`) }}
          // Si ya existe, se abre su edición en lugar de crear otro.
          onUsar={(id) => { const existente = filas.find((f) => f.id === id); setDialogo(existente ? { proveedor: existente } : null) }}
          textoUsar="Editar ese proveedor"
          onCerrar={() => setDialogo(null)}
        />
      )}
    </div>
  )
}

function admiteUniversal(fila: Proveedor | Categoria): boolean {
  return 'admite_codigo_universal' in fila && fila.admite_codigo_universal
}

function Catalogo({ titulo, singular, alta, ruta, clave, filas, cargando, esCategoria }: {
  titulo: string
  singular: string
  alta: string
  ruta: string
  clave: string
  filas: Array<Proveedor | Categoria>
  cargando: boolean
  esCategoria: boolean
}) {
  const qc = useQueryClient()
  const [nombre, setNombre] = useState('')
  const [busqueda, setBusqueda] = useState('')
  const [edicion, setEdicion] = useState<{ id: number; nombre: string } | null>(null)

  const refrescar = () => qc.invalidateQueries({ queryKey: [clave] })
  const crear = useMutation({ mutationFn: () => api.post(`/${ruta}`, { nombre }), onSuccess: () => { setNombre(''); refrescar() } })
  const renombrar = useMutation({ mutationFn: (e: { id: number; nombre: string }) => api.patch(`/${ruta}/${e.id}`, { nombre: e.nombre }), onSuccess: () => { setEdicion(null); refrescar() } })
  const error = crear.error ?? renombrar.error

  const visibles = useMemo(() => {
    const q = busqueda.trim().toLocaleLowerCase()
    if (!q) return filas
    return filas.filter((f) => f.nombre.toLocaleLowerCase().includes(q))
  }, [filas, busqueda])

  const columnas = esCategoria ? 3 : 2

  return (
    <div role="tabpanel" id="panel-catalogo" aria-labelledby={`pestana-${clave}`}>
      <div className="catalogo-barra">
        <form className="formulario en-linea" onSubmit={(e) => { e.preventDefault(); crear.mutate() }}>
          <input required placeholder={alta} value={nombre} onChange={(e) => setNombre(e.target.value)} aria-label={alta} />
          <button className="primario boton-alta" disabled={crear.isPending}>Agregar</button>
        </form>
        <label className="busca">
          <Icono nombre="buscar" />
          <input
            placeholder={`Buscar ${singular}`}
            value={busqueda}
            onChange={(e) => setBusqueda(e.target.value)}
            aria-label={`Buscar ${singular}`}
          />
        </label>
      </div>
      {error && <div className="aviso error catalogo-aviso">{mensajeError(error)}</div>}
      <div className="tabla-marcos-scroll">
        <table className="tabla-marcos">
          <thead>
            <tr>
              <th>Nombre</th>
              {esCategoria && <th>Código universal</th>}
              <th className="col-accion">Acciones</th>
            </tr>
          </thead>
          <tbody>
            {cargando && (
              <tr className="tabla-marcos-vacia">
                <td colSpan={columnas}>Cargando {titulo.toLocaleLowerCase()}…</td>
              </tr>
            )}
            {!cargando && visibles.length === 0 && (
              <tr className="tabla-marcos-vacia">
                <td colSpan={columnas}>
                  {filas.length === 0 ? `Todavía no hay ${titulo.toLocaleLowerCase()}.` : `Ningún resultado para «${busqueda.trim()}».`}
                </td>
              </tr>
            )}
            {visibles.map((f) => (
              <tr key={f.id}>
                {edicion?.id === f.id ? (
                  <td colSpan={columnas}>
                    <form className="edicion-fila" onSubmit={(e) => { e.preventDefault(); renombrar.mutate(edicion) }}>
                      <input autoFocus required value={edicion.nombre} onChange={(e) => setEdicion({ ...edicion, nombre: e.target.value })} aria-label={`Nuevo nombre de ${f.nombre}`} />
                      <button className="primario" disabled={renombrar.isPending}>Guardar</button>
                      <button type="button" className="secundario" onClick={() => setEdicion(null)}>Cancelar</button>
                    </form>
                  </td>
                ) : (
                  <>
                    <td>{f.nombre}</td>
                    {esCategoria && (
                      <td>
                        {admiteUniversal(f)
                          ? <span className="chip">Admite</span>
                          : <span className="suave">No</span>}
                      </td>
                    )}
                    <td className="col-accion">
                      <button type="button" className="accion" onClick={() => setEdicion({ id: f.id, nombre: f.nombre })}>Renombrar</button>
                    </td>
                  </>
                )}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
