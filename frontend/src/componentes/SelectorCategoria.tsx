import { useRef, useState, type RefObject } from 'react'
import { useAuth } from '../auth/AuthProvider'
import { useCategorias } from '../hooks/useCatalogos'
import DialogoCategoria from './DialogoCategoria'

/**
 * Campo Categoría de una referencia (RF-01 / RF-20). S-24: admin y logística ven «+ Crear
 * categoría», que abre "Nueva categoría" encima; al crearla (o al usar una existente) queda
 * elegida y el foco pasa a `alElegir` (el campo siguiente) o vuelve al selector.
 */
export default function SelectorCategoria({ value, onChange, selectRef, autoFocus, alElegir }: {
  value: string
  onChange: (id: string) => void
  selectRef?: RefObject<HTMLSelectElement | null>
  autoFocus?: boolean
  alElegir?: () => void
}) {
  const categorias = useCategorias()
  const { tieneRol } = useAuth()
  const puedeCrear = tieneRol('logistica') // el admin pasa siempre
  const propio = useRef<HTMLSelectElement>(null)
  const campo = selectRef ?? propio
  const [creando, setCreando] = useState(false)

  const elegir = (id: number) => {
    setCreando(false)
    onChange(String(id))
    setTimeout(() => (alElegir ? alElegir() : campo.current?.focus()), 0)
  }

  return (
    <div className="campo-con-accion">
      <label>Categoría
        <select ref={campo} required autoFocus={autoFocus} value={value} onChange={(e) => onChange(e.target.value)}>
          <option value="">—</option>
          {categorias.data?.map((c) => <option key={c.id} value={c.id}>{c.nombre}</option>)}
        </select>
      </label>
      {puedeCrear && (
        <button type="button" className="enlace accion-campo" onClick={() => setCreando(true)}>+ Crear categoría</button>
      )}
      {creando && (
        <DialogoCategoria
          onCreada={(nueva) => elegir(nueva.id)}
          onUsar={elegir}
          onCerrar={() => { setCreando(false); setTimeout(() => campo.current?.focus(), 0) }}
        />
      )}
    </div>
  )
}
