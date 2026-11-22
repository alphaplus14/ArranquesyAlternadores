import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { api, mensajeError } from '../../api/cliente'
import type { Pagina, Rol, Usuario } from '../../api/tipos'
import { useAuth } from '../../auth/AuthProvider'
import { normalizarUsuario } from '../../auth/nombreUsuario'
import { useListaPaginada } from '../../hooks/useListaPaginada'
import Confirmar from '../../componentes/Confirmar'
import Icono from '../../componentes/Icono'
import Medida from '../../componentes/Medida'
import Resumen from '../../componentes/Resumen'
import Paginacion from '../../componentes/Paginacion'

type Confirmacion =
  | { tipo: 'rol'; id: number; nombre: string; desde: Rol; hacia: Rol }
  | { tipo: 'activo'; id: number; nombre: string; activo: boolean }

const ROLES: Rol[] = ['admin', 'logistica', 'mostrador']
/** Un empleado cambia entre estos roles; un admin nuevo se crea como cuenta nueva. */
const ROLES_EMPLEADO: Rol[] = ['logistica', 'mostrador']
const ROL: Record<Rol, string> = { admin: 'Administrador', logistica: 'Logística', mostrador: 'Mostrador' }

/** Usuarios: no se borran, se desactivan. Las cuentas admin están protegidas (el backend lo exige). */
export default function Usuarios() {
  const qc = useQueryClient()
  const { usuario: yo } = useAuth()
  const [nuevo, setNuevo] = useState({ name: '', usuario: '', password: '', rol: 'mostrador' as Rol })
  const [cambiandoClave, setCambiandoClave] = useState<Usuario | null>(null)
  const [confirmacion, setConfirmacion] = useState<Confirmacion | null>(null)

  const { filas, resultado, info, irA } = useListaPaginada<Usuario>({
    clave: ['usuarios'],
    traer: async (pagina) => (await api.get<Pagina<Usuario>>('/usuarios', { params: { page: pagina } })).data,
  })
  const refrescar = () => qc.invalidateQueries({ queryKey: ['usuarios'] })

  const crear = useMutation({ mutationFn: () => api.post('/usuarios', nuevo), onSuccess: () => { setNuevo({ name: '', usuario: '', password: '', rol: 'mostrador' }); refrescar() } })
  const cambiar = useMutation({ mutationFn: ({ id, ...datos }: Partial<Usuario> & { id: number }) => api.patch(`/usuarios/${id}`, datos), onSuccess: refrescar })
  const error = confirmacion ? crear.error : (crear.error ?? cambiar.error)

  const pedirConfirmacion = (c: Confirmacion) => { cambiar.reset(); setConfirmacion(c) }
  const aplicarConfirmacion = () => {
    if (!confirmacion) return
    const datos = confirmacion.tipo === 'rol'
      ? { id: confirmacion.id, rol: confirmacion.hacia }
      : { id: confirmacion.id, activo: !confirmacion.activo }
    cambiar.mutate(datos, { onSuccess: () => setConfirmacion(null) })
  }

  const activos = filas.filter((u) => u.activo !== false).length
  const aviso = confirmacion?.tipo === 'rol'
    ? {
        titulo: 'Cambiar rol',
        texto: `¿Cambiar el rol de ${confirmacion.nombre} de ${ROL[confirmacion.desde]} a ${ROL[confirmacion.hacia]}?`,
        accion: 'Sí, cambiar',
        peligro: false,
      }
    : confirmacion?.activo
      ? {
          titulo: 'Desactivar usuario',
          texto: `¿Desactivar a ${confirmacion.nombre}? No podrá iniciar sesión hasta que lo active de nuevo.`,
          accion: 'Sí, desactivar',
          peligro: true,
        }
      : {
          titulo: 'Activar usuario',
          texto: `¿Activar a ${confirmacion?.nombre}?`,
          accion: 'Sí, activar',
          peligro: false,
        }

  return (
    <div className="pila">
      <div className="pagina-encabezado">
        <h1>Usuarios</h1>
        <p>No se borran: se desactivan. Las cuentas de administrador están protegidas: cada una se edita desde «Mi cuenta».</p>
      </div>
      <Resumen
        clave="usuarios-resumen"
        compacto={(
          <>
            <span><strong>{info ? info.total.toLocaleString('es-CO') : '—'}</strong> usuarios</span>
            <span><strong>{resultado ? activos : '—'}</strong> activos</span>
            <span className={resultado && filas.length - activos > 0 ? 'resumen-agotados' : ''}><strong>{resultado ? filas.length - activos : '—'}</strong> inactivos</span>
            <span>Pág. <strong>{info ? `${info.actual}/${info.ultima}` : '—'}</strong></span>
          </>
        )}
      >
        <Medida etiqueta="Usuarios" valor={info ? info.total.toLocaleString('es-CO') : '—'} tono="azul" icono="usuarios" />
        <Medida etiqueta="Activos en esta página" valor={resultado ? activos : '—'} tono="verde" icono="caja" />
        <Medida etiqueta="Inactivos aquí" valor={resultado ? filas.length - activos : '—'} tono="rojo" icono="agotados" />
        <Medida etiqueta="Página" valor={info ? `${info.actual} / ${info.ultima}` : '—'} tono="amarillo" icono="auditoria" />
      </Resumen>
    <section className="tarjeta-pagina">
      <h2>Nuevo usuario</h2>
      <form className="formulario en-linea" onSubmit={(e) => { e.preventDefault(); crear.mutate() }}>
        <input required placeholder="Nombre" value={nuevo.name} onChange={(e) => setNuevo({ ...nuevo, name: e.target.value })} />
        <input required placeholder="Usuario" aria-label="Usuario" autoComplete="off" autoCapitalize="none" spellCheck={false} value={nuevo.usuario} onChange={(e) => setNuevo({ ...nuevo, usuario: normalizarUsuario(e.target.value) })} />
        <input required type="password" minLength={8} placeholder="Contraseña" value={nuevo.password} onChange={(e) => setNuevo({ ...nuevo, password: e.target.value })} />
        <select value={nuevo.rol} onChange={(e) => setNuevo({ ...nuevo, rol: e.target.value as Rol })}>
          {ROLES.map((r) => <option key={r} value={r}>{ROL[r]}</option>)}
        </select>
        <button className="primario" disabled={crear.isPending}>Crear usuario</button>
      </form>
      {error && <div className="aviso error">{mensajeError(error)}</div>}
      </section>

      <section className="tarjeta-pagina">
      <h2>Listado</h2>
      <table>
        <thead><tr><th>Nombre</th><th>Usuario</th><th>Rol</th><th>Estado</th><th>Contraseña</th></tr></thead>
        <tbody>
          {filas.map((u) => u.rol === 'admin' ? (
            <tr key={u.id} className={u.activo ? '' : 'reversado'}>
              <td>{u.name}</td>
              <td>{u.usuario}</td>
              <td>{ROL[u.rol]}</td>
              <td>{u.activo ? 'Activo' : 'Inactivo'}</td>
              <td><span className="chip">{u.id === yo?.id ? 'Tu cuenta · Mi cuenta' : 'Protegido'}</span></td>
            </tr>
          ) : (
            <tr key={u.id} className={u.activo ? '' : 'reversado'}>
              <td>{u.name}</td>
              <td>{u.usuario}</td>
              <td>
                <select aria-label={`Rol de ${u.name}`} value={u.rol} onChange={(e) => {
                  const rol = e.target.value as Rol
                  if (rol !== u.rol) pedirConfirmacion({ tipo: 'rol', id: u.id, nombre: u.name, desde: u.rol, hacia: rol })
                }}>
                  {ROLES_EMPLEADO.map((r) => <option key={r} value={r}>{ROL[r]}</option>)}
                </select>
              </td>
              <td>
                <button
                  type="button"
                  className={`icono-accion ${u.activo ? 'peligro' : 'ok'}`}
                  title={u.activo ? 'Desactivar' : 'Activar'}
                  aria-label={u.activo ? `Desactivar a ${u.name}` : `Activar a ${u.name}`}
                  onClick={() => pedirConfirmacion({ tipo: 'activo', id: u.id, nombre: u.name, activo: u.activo !== false })}
                >
                  <Icono nombre={u.activo ? 'desactivar' : 'activar'} />
                </button>
              </td>
              <td>
                <button
                  type="button"
                  className="icono-accion"
                  title="Cambiar contraseña"
                  aria-label={`Cambiar contraseña de ${u.name}`}
                  onClick={() => setCambiandoClave(u)}
                >
                  <Icono nombre="clave" />
                </button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      <Paginacion pagina={resultado} onCambiar={irA} />
      </section>

      {cambiandoClave && <CambiarClave usuario={cambiandoClave} onCerrar={() => setCambiandoClave(null)} />}
      {confirmacion && (
        <Confirmar
          id="confirmar-usuario"
          titulo={aviso.titulo}
          texto={aviso.texto}
          accion={aviso.accion}
          peligro={aviso.peligro}
          pendiente={cambiar.isPending}
          error={cambiar.isError ? mensajeError(cambiar.error) : null}
          onConfirmar={aplicarConfirmacion}
          onCancelar={() => { if (!cambiar.isPending) { cambiar.reset(); setConfirmacion(null) } }}
        />
      )}
    </div>
  )
}

function CambiarClave({ usuario, onCerrar }: { usuario: Usuario; onCerrar: () => void }) {
  const [f, setF] = useState({ password: '', password_confirmation: '' })
  const [seguro, setSeguro] = useState(false)
  const guardar = useMutation({ mutationFn: () => api.patch(`/usuarios/${usuario.id}`, f), onSuccess: onCerrar })

  return (
    <>
      <div className="modal">
        <form className="modal-caja" onSubmit={(e) => { e.preventDefault(); guardar.reset(); setSeguro(true) }}>
          <h2>Contraseña de {usuario.name}</h2>
          <label>Contraseña nueva<input type="password" required minLength={8} autoComplete="new-password" value={f.password} onChange={(e) => setF({ ...f, password: e.target.value })} /></label>
          <label>Repita la contraseña<input type="password" required autoComplete="new-password" value={f.password_confirmation} onChange={(e) => setF({ ...f, password_confirmation: e.target.value })} /></label>
          <p className="ayuda">Al guardar, se cierran todas las sesiones abiertas de {usuario.name}.</p>
          {guardar.isError && !seguro && <div className="aviso error">{mensajeError(guardar.error)}</div>}
          <div className="acciones">
            <button type="button" className="secundario" onClick={onCerrar}>Cancelar</button>
            <button className="primario" disabled={guardar.isPending}>Guardar</button>
          </div>
        </form>
      </div>
      {seguro && (
        <Confirmar
          id="confirmar-clave"
          titulo="Cambiar contraseña"
          texto={`¿Cambiar la contraseña de ${usuario.name}? Se cerrarán todas sus sesiones abiertas.`}
          accion="Sí, cambiar"
          pendiente={guardar.isPending}
          error={guardar.isError ? mensajeError(guardar.error) : null}
          onConfirmar={() => guardar.mutate()}
          onCancelar={() => { if (!guardar.isPending) setSeguro(false) }}
        />
      )}
    </>
  )
}
