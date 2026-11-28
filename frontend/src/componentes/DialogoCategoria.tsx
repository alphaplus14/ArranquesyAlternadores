import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { AvisoPagina } from './AvisoPagina'
import { AxiosError } from 'axios'
import { api, erroresCampo, mensajeError } from '../api/cliente'
import type { Categoria } from '../api/tipos'
import DialogoApilado from './DialogoApilado'
import BotonCancelar from './BotonCancelar'

/** Categoría existente que devuelve el 422 por nombre repetido (S-24). */
function existenteDe(error: unknown): { id: number; nombre: string } | null {
  if (!(error instanceof AxiosError)) return null
  const datos = error.response?.data as { categoria_existente_id?: number; categoria_existente?: string } | undefined
  return datos?.categoria_existente_id ? { id: datos.categoria_existente_id, nombre: datos.categoria_existente ?? '' } : null
}

/**
 * S-24: "Nueva categoría" encima del formulario de referencia. Logística y admin la crean; nace
 * sin código universal (si la crea logística, el backend lo fuerza; activarlo es del admin).
 */
export default function DialogoCategoria({ onCreada, onUsar, onCerrar }: {
  onCreada: (categoria: Categoria) => void
  onUsar: (id: number) => void
  onCerrar: () => void
}) {
  const qc = useQueryClient()
  const [nombre, setNombre] = useState('')

  const crear = useMutation({
    mutationFn: async () => (await api.post<Categoria>('/categorias', { nombre: nombre.trim() })).data,
    onSuccess: (nueva) => {
      qc.setQueryData<Categoria[]>(['categorias'], (lista) => [...(lista ?? []), nueva].sort((a, b) => a.nombre.localeCompare(b.nombre, 'es')))
      qc.invalidateQueries({ queryKey: ['categorias'] })
      onCreada(nueva)
    },
  })

  const errores = crear.isError ? erroresCampo(crear.error) : {}
  const existente = crear.isError ? existenteDe(crear.error) : null
  const general = crear.isError && Object.keys(errores).length === 0 ? mensajeError(crear.error) : null
  const listo = nombre.trim() !== '' && !crear.isPending

  return (
    <DialogoApilado
      titulo="Nueva categoría"
      onCerrar={onCerrar}
      onEnviar={() => { if (listo) crear.mutate() }}
      pie={(
        <>
          <BotonCancelar />
          <button type="submit" className="primario" disabled={!listo}>{crear.isPending ? 'Creando…' : 'Crear y usar'}</button>
        </>
      )}
    >
      <label>
        <span className="etiqueta-campo">Nombre<span className="obligatorio" aria-hidden="true">*</span></span>
        <input
          required
          autoFocus
          maxLength={100}
          value={nombre}
          aria-invalid={!!errores.nombre}
          aria-describedby={errores.nombre ? 'categoria-nombre-error' : 'categoria-nota'}
          onChange={(e) => { setNombre(e.target.value); crear.reset() }}
        />
        {errores.nombre && <small id="categoria-nombre-error" className="ayuda error">{errores.nombre}</small>}
      </label>
      <p id="categoria-nota" className="ayuda">La categoría nace sin código universal; lo activa el administrador.</p>
      {existente && (
        <div className="aviso atencion" role="alert">
          Ya existe la categoría <strong>{existente.nombre}</strong>.{' '}
          <button type="button" className="enlace" onClick={() => onUsar(existente.id)}>Usar esa categoría</button>
        </div>
      )}
      {general && <AvisoPagina tono="error">{general}</AvisoPagina>}
    </DialogoApilado>
  )
}
