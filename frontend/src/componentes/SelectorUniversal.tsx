import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, erroresCampo, mensajeError } from '../api/cliente'
import type { Pagina } from '../api/tipos'

interface UniversalOpcion { id: number; codigo: string; categoria_id: number }

/**
 * RF-01 / S-59: código universal de una referencia, solo de su categoría (que debe admitirlo).
 * "Crear código universal" lo crea en línea con la categoría de la referencia y lo deja elegido.
 * Lo usan el alta de referencia en un ingreso, la ficha de referencia y el formulario de Productos.
 */
export default function SelectorUniversal({ categoriaId, value, onChange, etiqueta = 'Código universal' }: {
  categoriaId: string
  value: string
  onChange: (id: string) => void
  etiqueta?: string
}) {
  const qc = useQueryClient()
  const [creando, setCreando] = useState(false)
  const [codigo, setCodigo] = useState('')

  const universales = useQuery({
    queryKey: ['universales', 'categoria', categoriaId],
    queryFn: async () => (await api.get<Pagina<UniversalOpcion>>('/codigos-universales', { params: { categoria_id: categoriaId, por_pagina: 100 } })).data.data,
    enabled: categoriaId !== '',
  })

  const crear = useMutation({
    mutationFn: async () => (await api.post<UniversalOpcion>('/codigos-universales', { codigo: codigo.trim().toUpperCase(), categoria_id: Number(categoriaId) })).data,
    onSuccess: (u) => {
      qc.setQueryData<UniversalOpcion[]>(['universales', 'categoria', categoriaId], (lista) => [...(lista ?? []), u].sort((a, b) => a.codigo.localeCompare(b.codigo)))
      qc.invalidateQueries({ queryKey: ['universales'] })
      onChange(String(u.id))
      setCodigo('')
      setCreando(false)
    },
  })
  const errores = crear.isError ? erroresCampo(crear.error) : {}
  const errorCrear = errores.codigo ?? errores.categoria_id ?? (crear.isError && Object.keys(errores).length === 0 ? mensajeError(crear.error) : null)

  return (
    <div className="selector-universal">
      <label>{etiqueta}
        <select value={value} onChange={(e) => onChange(e.target.value)}>
          <option value="">Sin universal: la referencia es su código único</option>
          {universales.data?.map((u) => <option key={u.id} value={u.id}>{u.codigo}</option>)}
        </select>
      </label>
      {creando ? (
        <div className="fila-crear-universal">
          <input
            autoFocus
            maxLength={60}
            placeholder="Código universal (ej. IM204)"
            aria-label="Código universal nuevo"
            value={codigo}
            aria-invalid={!!errorCrear}
            onChange={(e) => { setCodigo(e.target.value); crear.reset() }}
            onKeyDown={(e) => {
              // Enter crea el universal sin enviar el formulario de la referencia.
              if (e.key === 'Enter') { e.preventDefault(); if (codigo.trim() && !crear.isPending) crear.mutate() }
              if (e.key === 'Escape') { e.stopPropagation(); setCreando(false) }
            }}
          />
          <button type="button" className="primario" disabled={!codigo.trim() || crear.isPending} onClick={() => crear.mutate()}>
            {crear.isPending ? 'Creando…' : 'Crear'}
          </button>
          <button type="button" className="secundario" onClick={() => { setCreando(false); crear.reset() }}>Cancelar</button>
        </div>
      ) : (
        <button type="button" className="enlace" onClick={() => setCreando(true)}>Crear código universal</button>
      )}
      {errorCrear && <small className="ayuda error">{errorCrear}</small>}
      <small className="ayuda">En reguladores use el código de Transpo (ej. IM204).</small>
    </div>
  )
}
