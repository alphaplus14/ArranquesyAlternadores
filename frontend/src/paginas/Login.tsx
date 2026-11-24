import { useEffect, useRef, useState, type KeyboardEvent } from 'react'
import { Navigate } from 'react-router-dom'
import { AxiosError } from 'axios'
import { mensajeError } from '../api/cliente'
import { useAviso } from '../componentes/Avisos'
import { useAuth } from '../auth/AuthProvider'
import { errorUsuario, normalizarUsuario } from '../auth/nombreUsuario'
import Icono from '../componentes/Icono'
import logo from '../assets/logo-la8va.jpg'
import { rutaInicio } from '../rutas'

type Campo = 'usuario' | 'password'

/**
 * Inicio de sesión con nombre de usuario. Valida mientras se escribe, sin pedir nada al servidor:
 * la única petición es la del envío. El error de un campo aparece cuando ya se tocó y se va
 * apenas se corrige. La contraseña solo es obligatoria: no se revela ninguna política aquí.
 * Login fallido (422): se vacía la contraseña y vuelve el foco a ella. Demasiados intentos (429):
 * "Entrar" queda deshabilitado los segundos que diga el backend. El aviso se va al escribir.
 */
export default function Login() {
  const { usuario: sesion, entrar } = useAuth()
  const avisos = useAviso()
  const tipoAviso = useRef<'credenciales' | 'bloqueo' | 'otro' | null>(null)
  const [usuario, setUsuario] = useState('')
  const [password, setPassword] = useState('')
  const [tocado, setTocado] = useState<Record<Campo, boolean>>({ usuario: false, password: false })
  const [enviando, setEnviando] = useState(false)
  const [verClave, setVerClave] = useState(false)
  const [bloqMayus, setBloqMayus] = useState(false)
  /** Segundos que faltan para poder reintentar tras un 429 (0 = sin bloqueo). */
  const [espera, setEspera] = useState(0)
  const campoClave = useRef<HTMLInputElement>(null)

  useEffect(() => {
    if (espera <= 0) return
    const t = window.setTimeout(() => {
      setEspera((s) => s - 1)
      // Terminado el bloqueo, su aviso ya no aplica.
      if (espera === 1 && tipoAviso.current === 'bloqueo') { tipoAviso.current = null; avisos.limpiar() }
    }, 1000)
    return () => window.clearTimeout(t)
  }, [espera, avisos])

  if (sesion) return <Navigate to={rutaInicio(sesion.rol)} replace />

  const errores: Record<Campo, string | null> = {
    usuario: errorUsuario(usuario),
    password: password === '' ? 'Escribe tu contraseña.' : null,
  }
  const invalido = errores.usuario !== null || errores.password !== null
  const mostrar = (c: Campo) => (tocado[c] ? errores[c] : null)
  const tocar = (c: Campo) => setTocado((t) => (t[c] ? t : { ...t, [c]: true }))

  const atributos = (c: Campo) => ({
    'aria-invalid': mostrar(c) !== null,
    'aria-describedby': mostrar(c) ? `${c}-error` : undefined,
    'data-tocado': tocado[c],
    onBlur: () => tocar(c),
  })

  const revisarMayus = (e: KeyboardEvent<HTMLInputElement>) => setBloqMayus(e.getModifierState('CapsLock'))

  const enviar = async () => {
    setTocado({ usuario: true, password: true })
    if (invalido || enviando || espera > 0) return
    setEnviando(true)
    tipoAviso.current = null
    avisos.limpiar()
    try {
      await entrar(usuario, password)
    } catch (err) {
      const estado = err instanceof AxiosError ? err.response?.status : undefined
      if (estado === 422) {
        // Se conserva el usuario; la contraseña se vacía (sin marcarla como error) y recibe el foco.
        tipoAviso.current = 'credenciales'
        avisos.mostrar('error', `${mensajeError(err)} Si no puede entrar, consulte al administrador.`)
        setPassword('')
        setTocado((t) => ({ ...t, password: false }))
        requestAnimationFrame(() => campoClave.current?.focus())
      } else if (estado === 429) {
        const datos = (err as AxiosError<{ message?: string; retry_after?: number }>).response?.data
        tipoAviso.current = 'bloqueo'
        avisos.mostrar('error', mensajeError(err))
        if (typeof datos?.retry_after === 'number' && datos.retry_after > 0) setEspera(Math.ceil(datos.retry_after))
      } else {
        tipoAviso.current = 'otro'
        avisos.mostrar('error', mensajeError(err))
      }
    } finally {
      setEnviando(false)
    }
  }

  return (
    <div className="login">
      <div className="login-marco">
        <form className="panel" noValidate onSubmit={(e) => { e.preventDefault(); void enviar() }}>
        <header className="login-cabeza">
          <img className="login-logo" src={logo} alt="Arranques y Alternadores la 8va" />
          <h1>Inventario</h1>
        </header>

        <label>Usuario
          <input
            name="usuario"
            autoFocus
            autoComplete="username"
            autoCapitalize="none"
            autoCorrect="off"
            spellCheck={false}
            placeholder="Tu usuario"
            value={usuario}
            onChange={(e) => {
              setUsuario(normalizarUsuario(e.target.value))
              tocar('usuario')
              tipoAviso.current = null
              avisos.limpiar()
            }}
            {...atributos('usuario')}
          />
        </label>
        {mostrar('usuario') && <p id="usuario-error" className="ayuda error">{mostrar('usuario')}</p>}

        <label>Contraseña
          <span className="campo-clave">
            <input
              ref={campoClave}
              name="password"
              type={verClave ? 'text' : 'password'}
              autoComplete="current-password"
              placeholder="Tu contraseña"
              value={password}
              onChange={(e) => {
                setPassword(e.target.value)
                tocar('password')
                tipoAviso.current = null
                avisos.limpiar()
              }}
              onKeyDown={revisarMayus}
              onKeyUp={revisarMayus}
              {...atributos('password')}
              onBlur={() => { tocar('password'); setBloqMayus(false) }}
            />
            <button
              type="button"
              className="ver-clave"
              aria-pressed={verClave}
              aria-label={verClave ? 'Ocultar contraseña' : 'Mostrar contraseña'}
              onClick={() => setVerClave((v) => !v)}
            >
              <Icono nombre={verClave ? 'ojo-cerrado' : 'ojo'} />
            </button>
          </span>
        </label>
        {mostrar('password') && <p id="password-error" className="ayuda error">{mostrar('password')}</p>}
        {bloqMayus && <p className="ayuda atencion" role="status">Bloq Mayús está activado.</p>}

        <button className="primario" disabled={enviando || espera > 0}>
          {enviando ? 'Entrando…' : espera > 0 ? `Reintentar en ${espera} s` : 'Entrar'}
        </button>
        </form>
      </div>
    </div>
  )
}
