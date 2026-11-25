import { useState } from 'react'
import { mensajeError } from '../api/cliente'
import { descargarExcel } from '../api/excel'
import { useAvisoTexto } from './Avisos'

/** RF-31: botón "Exportar a Excel" de un informe, con los mismos filtros de la pantalla. */
export default function BotonExcel({ ruta, params }: { ruta: string; params?: Record<string, string | number | undefined> }) {
  const [bajando, setBajando] = useState(false)
  const [error, setError] = useState<string | null>(null)
  useAvisoTexto(error)

  const bajar = async () => {
    setBajando(true)
    setError(null)
    try {
      await descargarExcel(ruta, params)
    } catch (e) {
      setError(mensajeError(e))
    } finally {
      setBajando(false)
    }
  }

  return (
    <button type="button" className="secundario" disabled={bajando} onClick={bajar}>
      {bajando ? 'Generando…' : 'Exportar a Excel'}
    </button>
  )
}
