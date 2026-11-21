import { useState, type KeyboardEvent } from 'react'
import { Navigate } from 'react-router-dom'
import { AxiosError } from 'axios'
import { mensajeError } from '../api/cliente'
import { useAuth } from '../auth/AuthProvider'
import { errorUsuario, normalizarUsuario } from '../auth/nombreUsuario'
import { rutaInicio } from '../rutas'

type Campo = 'usuario' | 'password'

/**
 * Inicio de sesión con nombre de usuario. Valida mientras se escribe, sin pedir nada al servidor:
 * la única petición es la del envío. El error de un campo aparece cuando ya se tocó y se va
 * apenas se corrige. La contraseña solo es obligatoria: no se revela ninguna política aquí.
 */
export default function Login() {
  const { usuario: sesion, entrar } = useAuth()
  const [usuario, setUsuario] = useState('')
  const [password, setPassword] = useState('')
  const [tocado, setTocado] = useState<Record<Campo, boolean>>({ usuario: false, password: false })
  const [errorServidor, setErrorServidor] = useState<string | null>(null)
  const [enviando, setEnviando] = useState(false)
  const [verClave, setVerClave] = useState(false)
  const [bloqMayus, setBloqMayus] = useState(false)

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
    if (invalido || enviando) return
    setEnviando(true)
    setErrorServidor(null)
    try {
      await entrar(usuario, password)
    } catch (err) {
      setErrorServidor(err instanceof AxiosError && err.response?.status === 429
        ? 'Demasiados intentos. Espera un minuto y vuelve a intentarlo.'
        : mensajeError(err))
    } finally {
      setEnviando(false)
    }
  }

  return (
    <div className="login">
      <form className="panel" noValidate onSubmit={(e) => { e.preventDefault(); void enviar() }}>
        <div className="marca-login"><span className="marca-ocho">8</span></div>
        <h1>Arranques y Alternadores la 8va</h1>
        <p>Inventario</p>

        <label>Usuario
          <input
            name="usuario"
            autoFocus
            autoComplete="username"
            autoCapitalize="none"
            autoCorrect="off"
            spellCheck={false}
            value={usuario}
            onChange={(e) => {
              setUsuario(normalizarUsuario(e.target.value))
              tocar('usuario')
              setErrorServidor(null)
            }}
            {...atributos('usuario')}
          />
        </label>
        {mostrar('usuario') && <p id="usuario-error" className="ayuda error">{mostrar('usuario')}</p>}

        <label>Contraseña
          <span className="campo-clave">
            <input
              name="password"
              type={verClave ? 'text' : 'password'}
              autoComplete="current-password"
              value={password}
              onChange={(e) => {
                setPassword(e.target.value)
                tocar('password')
                setErrorServidor(null)
              }}
              onKeyDown={revisarMayus}
              onKeyUp={revisarMayus}
              {...atributos('password')}
              onBlur={() => { tocar('password'); setBloqMayus(false) }}
            />
            <button
              type="button"
              className="enlace ver-clave"
              aria-pressed={verClave}
              aria-label={verClave ? 'Ocultar contraseña' : 'Mostrar contraseña'}
              onClick={() => setVerClave((v) => !v)}
            >
              {verClave ? 'Ocultar' : 'Mostrar'}
            </button>
          </span>
        </label>
        {mostrar('password') && <p id="password-error" className="ayuda error">{mostrar('password')}</p>}
        {bloqMayus && <p className="ayuda atencion" role="status">Bloq Mayús está activado.</p>}

        {errorServidor && <div className="aviso error" role="alert">{errorServidor}</div>}
        <button className="primario" disabled={invalido || enviando}>{enviando ? 'Entrando…' : 'Entrar'}</button>
      </form>
    </div>
  )
}
