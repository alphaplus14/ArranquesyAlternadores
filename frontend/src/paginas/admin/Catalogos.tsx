import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { api, mensajeError } from '../../api/cliente'
import { useCategorias, useMarcas, useProveedores } from '../../hooks/useCatalogos'

/** Catálogos editables (S-03: las marcas no viven en el código). RF-11: cada cambio queda auditado. */
export default function Catalogos() {
  const marcas = useMarcas()
  const proveedores = useProveedores()
  const categorias = useCategorias()

  return (
    <div className="columnas">
      <Catalogo titulo="Marcas" ruta="marcas" clave="marcas" filas={marcas.data ?? []} />
      <Catalogo titulo="Proveedores" ruta="proveedores" clave="proveedores" filas={proveedores.data ?? []} />
      <Catalogo
        titulo="Categorías"
        ruta="categorias"
        clave="categorias"
        filas={categorias.data ?? []}
        extra={(c) => (c as { admite_codigo_universal?: boolean }).admite_codigo_universal ? 'admite universal' : ''}
      />
    </div>
  )
}

function Catalogo({ titulo, ruta, clave, filas, extra }: {
  titulo: string
  ruta: string
  clave: string
  filas: Array<{ id: number; nombre: string }>
  extra?: (f: { id: number; nombre: string }) => string
}) {
  const qc = useQueryClient()
  const [nombre, setNombre] = useState('')
  const [edicion, setEdicion] = useState<{ id: number; nombre: string } | null>(null)

  const refrescar = () => qc.invalidateQueries({ queryKey: [clave] })
  const crear = useMutation({ mutationFn: () => api.post(`/${ruta}`, { nombre }), onSuccess: () => { setNombre(''); refrescar() } })
  const renombrar = useMutation({ mutationFn: (e: { id: number; nombre: string }) => api.patch(`/${ruta}/${e.id}`, { nombre: e.nombre }), onSuccess: () => { setEdicion(null); refrescar() } })
  const error = crear.error ?? renombrar.error

  return (
    <section className="panel">
      <h2>{titulo}</h2>
      <form className="formulario en-linea" onSubmit={(e) => { e.preventDefault(); crear.mutate() }}>
        <input required placeholder={`Nueva ${titulo.toLowerCase().replace(/s$/, '')}`} value={nombre} onChange={(e) => setNombre(e.target.value)} />
        <button>Agregar</button>
      </form>
      {error && <div className="aviso error">{mensajeError(error)}</div>}
      <ul className="lista">
        {filas.map((f) => (
          <li key={f.id}>
            {edicion?.id === f.id ? (
              <form onSubmit={(e) => { e.preventDefault(); renombrar.mutate(edicion) }}>
                <input autoFocus value={edicion.nombre} onChange={(e) => setEdicion({ ...edicion, nombre: e.target.value })} />
                <button>Guardar</button> <button type="button" onClick={() => setEdicion(null)}>Cancelar</button>
              </form>
            ) : (
              <>
                {f.nombre} {extra?.(f) && <span className="chip">{extra(f)}</span>}{' '}
                <button className="enlace" onClick={() => setEdicion({ id: f.id, nombre: f.nombre })}>Renombrar</button>
              </>
            )}
          </li>
        ))}
      </ul>
    </section>
  )
}
