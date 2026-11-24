import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { api, mensajeError } from '../api/cliente'
import type { Usuario } from '../api/tipos'
import { useAuth } from '../auth/AuthProvider'
import { errorUsuario, normalizarUsuario } from '../auth/nombreUsuario'
import { useAviso, useAvisoTexto } from './Avisos'
import Confirmar from './Confirmar'
import Dialogo, { DialogoSeccion } from './Dialogo'

const vacias = { password_actual: '', password: '', password_confirmation: '' }

/**
 * Cuenta propia del admin. Cambiar la contraseña exige la actual, la nueva y su confirmación;
 * cambiar el usuario exige la actual. El backend vuelve a validar las tres cosas.
 */
export default function MiCuenta({ onCerrar }: { onCerrar: () => void }) {
  const { usuario, actualizarUsuario } = useAuth()
  const avisos = useAviso()
  const [f, setF] = useState({ name: usuario?.name ?? '', usuario: usuario?.usuario ?? '', ...vacias })
  const [confirmando, setConfirmando] = useState(false)

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
      setConfirmando(false)
      avisos.mostrar('ok', 'Cambios guardados.')
    },
  })
  useAvisoTexto(guardar.isError && !confirmando ? mensajeError(guardar.error) : null)

  const partes: string[] = []
  if (cambiaNombre) partes.push(`el nombre a «${f.name.trim()}»`)
  if (cambiaUsuario) partes.push(`el usuario a «${f.usuario}»`)
  if (tocaClave) partes.push('la contraseña')
  const lista = partes.length > 1 ? `${partes.slice(0, -1).join(', ')} y ${partes[partes.length - 1]}` : partes[0]
  const avisoClave = tocaClave ? ' Al cambiar la contraseña, se cierran sus sesiones en otros equipos.' : ''
  const textoConfirmacion = `Va a cambiar ${lista}.${avisoClave} ¿Está seguro?`

  const campo = (k: keyof typeof f) => ({
    value: f[k],
    onChange: (e: { target: { value: string } }) => setF({ ...f, [k]: e.target.value }),
  })

  const inicial = (usuario?.name ?? '?').trim().charAt(0).toUpperCase()

  return (
    <>
    <Dialogo
      titulo="Mi cuenta"
      descripcion={`Administrador · ${usuario?.usuario ?? ''}`}
      marca={inicial}
      onCerrar={onCerrar}
      onEnviar={() => { guardar.reset(); setConfirmando(true) }}
      pie={(
        <>
          <button type="button" className="secundario" onClick={onCerrar}>Cancelar</button>
          <button type="submit" className="primario" disabled={guardar.isPending || sinCambios || noCoinciden || errorDeUsuario !== null}>
            {guardar.isPending ? 'Guardando…' : 'Guardar cambios'}
          </button>
        </>
      )}
    >
        <DialogoSeccion titulo="Datos de la cuenta">
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
                onChange={(e) => setF({ ...f, usuario: normalizarUsuario(e.target.value) })}
              />
            </label>
          </div>
          {errorDeUsuario && <p id="mi-usuario-error" className="ayuda error">{errorDeUsuario}</p>}
          {cambiaUsuario && !errorDeUsuario && <p className="ayuda">Para cambiar el usuario, escriba abajo su contraseña actual.</p>}
        </DialogoSeccion>

        <DialogoSeccion titulo="Cambiar contraseña">
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
        </DialogoSeccion>
    </Dialogo>
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
