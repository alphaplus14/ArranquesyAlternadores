import { useEffect, useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { api, mensajeError } from '../api/cliente'
import type { Usuario } from '../api/tipos'
import { useAuth } from '../auth/AuthProvider'

const vacias = { password_actual: '', password: '', password_confirmation: '' }

/**
 * Cuenta propia del admin. Cambiar la contraseña exige la actual, la nueva y su confirmación;
 * cambiar el correo exige la actual. El backend vuelve a validar las tres cosas.
 */
export default function MiCuenta({ onCerrar }: { onCerrar: () => void }) {
  const { usuario, actualizarUsuario } = useAuth()
  const [f, setF] = useState({ name: usuario?.name ?? '', email: usuario?.email ?? '', ...vacias })
  const [listo, setListo] = useState(false)

  useEffect(() => {
    const alPulsar = (e: KeyboardEvent) => { if (e.key === 'Escape') onCerrar() }
    document.addEventListener('keydown', alPulsar)
    return () => document.removeEventListener('keydown', alPulsar)
  }, [onCerrar])

  const cambiaNombre = f.name.trim() !== usuario?.name
  const cambiaEmail = f.email.trim() !== usuario?.email
  const tocaClave = f.password_actual !== '' || f.password !== '' || f.password_confirmation !== ''
  const pideActual = cambiaEmail || tocaClave
  const noCoinciden = f.password_confirmation !== '' && f.password !== f.password_confirmation
  const sinCambios = !cambiaNombre && !cambiaEmail && !tocaClave

  const guardar = useMutation({
    mutationFn: async () => {
      const datos: Record<string, string> = {}
      if (cambiaNombre) datos.name = f.name.trim()
      if (cambiaEmail) datos.email = f.email.trim()
      if (tocaClave) {
        datos.password = f.password
        datos.password_confirmation = f.password_confirmation
      }
      if (pideActual) datos.password_actual = f.password_actual
      return (await api.patch<Usuario>('/cuenta', datos)).data
    },
    onSuccess: (u) => {
      actualizarUsuario(u)
      setF({ name: u.name, email: u.email, ...vacias })
      setListo(true)
    },
  })

  const campo = (k: keyof typeof f) => ({
    value: f[k],
    onChange: (e: { target: { value: string } }) => { setListo(false); setF({ ...f, [k]: e.target.value }) },
  })

  const inicial = (usuario?.name ?? '?').trim().charAt(0).toUpperCase()

  return (
    <div className="modal">
      <form
        className="modal-caja cuenta-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="mi-cuenta-titulo"
        onSubmit={(e) => { e.preventDefault(); guardar.mutate() }}
      >
        <header className="modal-cabeza">
          <span className="avatar" aria-hidden>{inicial}</span>
          <div className="modal-cabeza-texto">
            <h2 id="mi-cuenta-titulo">Mi cuenta</h2>
            <p>Administrador · {usuario?.email}</p>
          </div>
          <button type="button" className="icono-boton modal-cerrar" aria-label="Cerrar" onClick={onCerrar}>
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" aria-hidden><path d="M6 6l12 12M18 6L6 18" /></svg>
          </button>
        </header>

        <section className="modal-seccion">
          <h3>Datos de la cuenta</h3>
          <div className="campos-dos">
            <label>Nombre<input required autoComplete="name" {...campo('name')} /></label>
            <label>Correo<input required type="email" autoComplete="email" {...campo('email')} /></label>
          </div>
          {cambiaEmail && <p className="ayuda">Para cambiar el correo, escriba abajo su contraseña actual.</p>}
        </section>

        <section className="modal-seccion">
          <h3>Cambiar contraseña</h3>
          <p className="ayuda">Déjelo vacío si no la va a cambiar. Al guardarla, se cierran sus sesiones en otros equipos.</p>
          <label>Contraseña actual
            <input type="password" autoComplete="current-password" required={pideActual} {...campo('password_actual')} />
          </label>
          <div className="campos-dos">
            <label>Contraseña nueva
              <input type="password" autoComplete="new-password" minLength={8} required={tocaClave} {...campo('password')} />
            </label>
            <label>Repita la contraseña nueva
              <input type="password" autoComplete="new-password" required={tocaClave} aria-invalid={noCoinciden} {...campo('password_confirmation')} />
            </label>
          </div>
          {noCoinciden
            ? <p className="ayuda error">Las contraseñas nuevas no coinciden.</p>
            : <p className="ayuda">Mínimo 8 caracteres.</p>}
        </section>

        {guardar.isError && <div className="aviso error">{mensajeError(guardar.error)}</div>}
        {listo && <div className="aviso ok" role="status">Cambios guardados.</div>}

        <footer className="modal-pie">
          <button type="button" className="secundario" onClick={onCerrar}>Cancelar</button>
          <button className="primario" disabled={guardar.isPending || sinCambios || noCoinciden}>
            {guardar.isPending ? 'Guardando…' : 'Guardar cambios'}
          </button>
        </footer>
      </form>
    </div>
  )
}
