import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { AxiosError } from 'axios'
import { api, erroresCampo, mensajeError } from '../api/cliente'
import type { Proveedor } from '../api/tipos'
import DialogoApilado from './DialogoApilado'

/** Texto que parece un NIT: solo dígitos, puntos, guiones o espacios, de 5 caracteres o más. */
const PARECE_NIT = /^[\d.\-\s]{5,}$/

/** Proveedor existente que devuelve el 422 por nombre o NIT repetido (S-24). */
function existenteDe(error: unknown): { id: number; nombre: string } | null {
  if (!(error instanceof AxiosError)) return null
  const datos = error.response?.data as { proveedor_existente_id?: number; proveedor_existente?: string } | undefined
  return datos?.proveedor_existente_id ? { id: datos.proveedor_existente_id, nombre: datos.proveedor_existente ?? '' } : null
}

/**
 * S-24: "Nuevo proveedor" encima del formulario actual. Logística y admin lo crean (el backend
 * valida y audita). Si el texto escrito parece un NIT va al NIT y el foco a Nombre; si no, al
 * revés. Un NIT o nombre repetido ofrece "Usar ese proveedor".
 */
export default function DialogoProveedor({ texto, onCreado, onUsar, onCerrar }: {
  texto: string
  onCreado: (proveedor: Proveedor) => void
  onUsar: (id: number) => void
  onCerrar: () => void
}) {
  const qc = useQueryClient()
  const esNit = PARECE_NIT.test(texto.trim())
  const [f, setF] = useState({ nombre: esNit ? '' : texto.trim(), nit: esNit ? texto.trim() : '', plazo_dias: '' })

  const crear = useMutation({
    mutationFn: async () => (await api.post<Proveedor>('/proveedores', {
      nombre: f.nombre.trim(),
      nit: f.nit.trim() || null,
      plazo_dias: f.plazo_dias === '' ? null : Number(f.plazo_dias),
    })).data,
    onSuccess: (nuevo) => {
      // Ya en la lista (sin esperar la recarga): el selector lo muestra y el plazo prellena el vencimiento.
      qc.setQueryData<Proveedor[]>(['proveedores'], (lista) => [...(lista ?? []), nuevo].sort((a, b) => a.nombre.localeCompare(b.nombre, 'es')))
      qc.invalidateQueries({ queryKey: ['proveedores'] })
      onCreado(nuevo)
    },
  })

  const errores = crear.isError ? erroresCampo(crear.error) : {}
  const existente = crear.isError ? existenteDe(crear.error) : null
  const general = crear.isError && Object.keys(errores).length === 0 ? mensajeError(crear.error) : null
  const plazoInvalido = f.plazo_dias !== '' && (!/^\d+$/.test(f.plazo_dias) || Number(f.plazo_dias) > 365)
  const listo = f.nombre.trim() !== '' && !plazoInvalido && !crear.isPending
  const campo = (k: keyof typeof f) => ({
    value: f[k],
    onChange: (e: { target: { value: string } }) => { setF({ ...f, [k]: e.target.value }); crear.reset() },
  })
  const error = (k: 'nombre' | 'nit' | 'plazo_dias', id: string) => errores[k] && <small id={id} className="ayuda error">{errores[k]}</small>

  return (
    <DialogoApilado
      titulo="Nuevo proveedor"
      onCerrar={onCerrar}
      onEnviar={() => { if (listo) crear.mutate() }}
      pie={(
        <>
          <button type="button" className="secundario" onClick={onCerrar}>Cancelar</button>
          <button type="submit" className="primario" disabled={!listo}>{crear.isPending ? 'Creando…' : 'Crear y usar'}</button>
        </>
      )}
    >
      <label>
        <span className="etiqueta-campo">Nombre<span className="obligatorio" aria-hidden="true">*</span></span>
        <input required maxLength={150} autoFocus={esNit} aria-invalid={!!errores.nombre} aria-describedby={errores.nombre ? 'proveedor-nombre-error' : undefined} {...campo('nombre')} />
        {error('nombre', 'proveedor-nombre-error')}
      </label>
      <label>
        <span className="etiqueta-campo">NIT (opcional)</span>
        <input maxLength={30} inputMode="numeric" autoFocus={!esNit} aria-invalid={!!errores.nit} aria-describedby={errores.nit ? 'proveedor-nit-error' : undefined} {...campo('nit')} />
        {error('nit', 'proveedor-nit-error')}
      </label>
      <label>
        <span className="etiqueta-campo">Plazo de crédito en días (opcional)</span>
        <input inputMode="numeric" aria-invalid={plazoInvalido || !!errores.plazo_dias} aria-describedby="proveedor-plazo-ayuda" {...campo('plazo_dias')} />
        {errores.plazo_dias
          ? <small id="proveedor-plazo-ayuda" className="ayuda error">{errores.plazo_dias}</small>
          : <small id="proveedor-plazo-ayuda" className={plazoInvalido ? 'ayuda error' : 'ayuda'}>{plazoInvalido ? 'Un número entero de 0 a 365.' : 'Prellena el vencimiento de sus compras a crédito.'}</small>}
      </label>
      {existente && (
        <div className="aviso atencion" role="alert">
          Ya existe el proveedor <strong>{existente.nombre}</strong>.{' '}
          <button type="button" className="enlace" onClick={() => onUsar(existente.id)}>Usar ese proveedor</button>
        </div>
      )}
      {general && <div className="aviso error" role="alert">{general}</div>}
    </DialogoApilado>
  )
}
