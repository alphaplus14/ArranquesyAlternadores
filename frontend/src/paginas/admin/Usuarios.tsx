import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, mensajeError } from '../../api/cliente'
import type { Pagina, Rol, Usuario } from '../../api/tipos'

const ROLES: Rol[] = ['admin', 'logistica', 'mostrador']

/** Usuarios: no se borran, se desactivan, para conservar quién hizo cada movimiento. */
export default function Usuarios() {
  const qc = useQueryClient()
  const [nuevo, setNuevo] = useState({ name: '', email: '', password: '', rol: 'mostrador' as Rol })

  const lista = useQuery({ queryKey: ['usuarios'], queryFn: async () => (await api.get<Pagina<Usuario>>('/usuarios')).data })
  const refrescar = () => qc.invalidateQueries({ queryKey: ['usuarios'] })

  const crear = useMutation({ mutationFn: () => api.post('/usuarios', nuevo), onSuccess: () => { setNuevo({ name: '', email: '', password: '', rol: 'mostrador' }); refrescar() } })
  const cambiar = useMutation({ mutationFn: ({ id, ...datos }: Partial<Usuario> & { id: number }) => api.patch(`/usuarios/${id}`, datos), onSuccess: refrescar })
  const error = crear.error ?? cambiar.error

  return (
    <div className="panel">
      <h1>Usuarios</h1>
      <form className="formulario en-linea" onSubmit={(e) => { e.preventDefault(); crear.mutate() }}>
        <input required placeholder="Nombre" value={nuevo.name} onChange={(e) => setNuevo({ ...nuevo, name: e.target.value })} />
        <input required type="email" placeholder="Correo" value={nuevo.email} onChange={(e) => setNuevo({ ...nuevo, email: e.target.value })} />
        <input required type="password" minLength={8} placeholder="Contraseña" value={nuevo.password} onChange={(e) => setNuevo({ ...nuevo, password: e.target.value })} />
        <select value={nuevo.rol} onChange={(e) => setNuevo({ ...nuevo, rol: e.target.value as Rol })}>
          {ROLES.map((r) => <option key={r}>{r}</option>)}
        </select>
        <button className="primario" disabled={crear.isPending}>Crear usuario</button>
      </form>
      {error && <div className="aviso error">{mensajeError(error)}</div>}

      <table>
        <thead><tr><th>Nombre</th><th>Correo</th><th>Rol</th><th>Estado</th></tr></thead>
        <tbody>
          {lista.data?.data.map((u) => (
            <tr key={u.id} className={u.activo ? '' : 'reversado'}>
              <td>{u.name}</td>
              <td>{u.email}</td>
              <td>
                <select value={u.rol} onChange={(e) => cambiar.mutate({ id: u.id, rol: e.target.value as Rol })}>
                  {ROLES.map((r) => <option key={r}>{r}</option>)}
                </select>
              </td>
              <td>
                <button className="enlace" onClick={() => cambiar.mutate({ id: u.id, activo: !u.activo })}>
                  {u.activo ? 'Activo · desactivar' : 'Inactivo · activar'}
                </button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
