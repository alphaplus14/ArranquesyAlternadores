import { useState } from 'react'
import { Navigate } from 'react-router-dom'
import { mensajeError } from '../api/cliente'
import { useAuth } from '../auth/AuthProvider'
import { rutaInicio } from '../rutas'

export default function Login() {
  const { usuario, entrar } = useAuth()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [enviando, setEnviando] = useState(false)

  if (usuario) return <Navigate to={rutaInicio(usuario.rol)} replace />

  return (
    <div className="login">
      <form className="panel" onSubmit={async (e) => {
        e.preventDefault()
        setEnviando(true)
        setError(null)
        try {
          await entrar(email, password)
        } catch (err) {
          setError(mensajeError(err))
        } finally {
          setEnviando(false)
        }
      }}>
        <h1>Arranques y Alternadores la 8va</h1>
        <p>Inventario</p>
        <label>Correo<input type="email" autoFocus required value={email} onChange={(e) => setEmail(e.target.value)} /></label>
        <label>Contraseña<input type="password" required value={password} onChange={(e) => setPassword(e.target.value)} /></label>
        {error && <div className="aviso error">{error}</div>}
        <button className="primario" disabled={enviando}>Entrar</button>
      </form>
    </div>
  )
}
