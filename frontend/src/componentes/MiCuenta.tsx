import { useEffect, useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { api, mensajeError } from '../api/cliente'
import type { Usuario } from '../api/tipos'
import { useAuth } from '../auth/AuthProvider'
import { errorUsuario, normalizarUsuario } from '../auth/nombreUsuario'
import Confirmar from './Confirmar'

const vacias = { password_actual: '', password: '', password_confirmation: '' }

/**
 * Cuenta propia del admin. Cambiar la contraseña exige la actual, la nueva y su confirmación;
 * cambiar el usuario exige la actual. El backend vuelve a validar las tres cosas.
 */
export default function MiCuenta({ onCerrar }: { onCerrar: () => void }) {
  const { usuario, actualizarUsuario } = useAuth()
  const [f, setF] = useState({ name: usuario?.name ?? '', usuario: usuario?.usuario ?? '', ...vacias })
  const [listo, setListo] = useState(false)
  const [confirmando, setConfirmando] = useState(false)

  useEffect(() => {
    const alPulsar = (e: KeyboardEvent) => { if (e.key === 'Escape') onCerrar() }
    document.addEventListener('keydown', alPulsar)
    return () => document.removeEventListener('keydown', alPulsar)
  }, [onCerrar])

  const cambiaNombre = f.name.trim() !== usuario?.name
  const cambiaUsuario = f.usuario !== usuario?.usuario
  const errorDeUsuario = cambiaUsuario ? errorUsuario(f.usuario) : null
  const tocaClave = f.password_actual !== '' || f.password !== '' || f.password_confirmation !== ''
  const pideActual = cambiaUsuario || tocaClave
  const noCoinciden = f.password_confirmation !== '' && f.password !== f.password_confirmation
  const sinCambios = !cambiaNombre && !cambiaUsuario && !tocaClave

  const guardar = useMutation({
    mutationFn: async () => {
      const datos: Record<string, string> = {}
      if (cambiaNombre) datos.name = f.name.trim()
      if (cambiaUsuario) datos.usuario = f.usuario
      if (tocaClave) {
        datos.password = f.password
        datos.password_confirmation = f.password_confirmation
      }
      if (pideActual) datos.password_actual = f.password_actual
      return (await api.patch<Usuario>('/cuenta', datos)).data
    },
    onSuccess: (u) => {
      actualizarUsuario(u)
      setF({ name: u.name, usuario: u.usuario, ...vacias })
      setListo(true)
      setConfirmando(false)
    },
  })

  const partes: string[] = []
  if (cambiaNombre) partes.push(`el nombre a «${f.name.trim()}»`)
  if (cambiaUsuario) partes.push(`el usuario a «${f.usuario}»`)
  if (tocaClave) partes.push('la contraseña')
  const lista = partes.length > 1 ? `${partes.slice(0, -1).join(', ')} y ${partes[partes.length - 1]}` : partes[0]
  const avisoClave = tocaClave ? ' Al cambiar la contraseña, se cierran sus sesiones en otros equipos.' : ''
  const textoConfirmacion = `Va a cambiar ${lista}.${avisoClave} ¿Está seguro?`

  const campo = (k: keyof typeof f) => ({
    value: f[k],
    onChange: (e: { target: { value: string } }) => { setListo(false); setF({ ...f, [k]: e.target.value }) },
  })

  const inicial = (usuario?.name ?? '?').trim().charAt(0).toUpperCase()

  return (
    <>
    <div className="modal">
      <form
        className="modal-caja cuenta-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="mi-cuenta-titulo"
        onSubmit={(e) => { e.preventDefault(); guardar.reset(); setConfirmando(true) }}
      >
        <header className="modal-cabeza">
          <span className="avatar" aria-hidden>{inicial}</span>
          <div className="modal-cabeza-texto">
            <h2 id="mi-cuenta-titulo">Mi cuenta</h2>
            <p>Administrador · {usuario?.usuario}</p>
          </div>
          <button type="button" className="icono-boton modal-cerrar" aria-label="Cerrar" onClick={onCerrar}>
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" aria-hidden><path d="M6 6l12 12M18 6L6 18" /></svg>
          </button>
        </header>

        <section className="modal-seccion">
          <h3>Datos de la cuenta</h3>
          <div className="campos-dos">
            <label>Nombre<input required autoComplete="name" {...campo('name')} /></label>
            <label>Usuario
              <input
                required
                autoComplete="username"
                autoCapitalize="none"
                spellCheck={false}
                aria-invalid={errorDeUsuario !== null}
                aria-describedby={errorDeUsuario ? 'mi-usuario-error' : undefined}
                value={f.usuario}
                onChange={(e) => { setListo(false); setF({ ...f, usuario: normalizarUsuario(e.target.value) }) }}
              />
            </label>
          </div>
          {errorDeUsuario && <p id="mi-usuario-error" className="ayuda error">{errorDeUsuario}</p>}
          {cambiaUsuario && !errorDeUsuario && <p className="ayuda">Para cambiar el usuario, escriba abajo su contraseña actual.</p>}
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

        {guardar.isError && !confirmando && <div className="aviso error">{mensajeError(guardar.error)}</div>}
        {listo && <div className="aviso ok" role="status">Cambios guardados.</div>}

        <footer className="modal-pie">
          <button type="button" className="secundario" onClick={onCerrar}>Cancelar</button>
          <button className="primario" disabled={guardar.isPending || sinCambios || noCoinciden || errorDeUsuario !== null}>
            {guardar.isPending ? 'Guardando…' : 'Guardar cambios'}
          </button>
        </footer>
      </form>
    </div>
    {confirmando && (
      <Confirmar
        id="confirmar-cuenta"
        titulo="Guardar cambios"
        texto={textoConfirmacion}
        accion="Sí, guardar"
        pendiente={guardar.isPending}
        error={guardar.isError ? mensajeError(guardar.error) : null}
        onConfirmar={() => guardar.mutate()}
        onCancelar={() => { if (!guardar.isPending) setConfirmando(false) }}
      />
    )}
    </>
  )
}
