import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { AxiosError } from 'axios'
import { api, erroresCampo, mensajeError } from '../api/cliente'
import type { Proveedor } from '../api/tipos'
import DialogoApilado from './DialogoApilado'
import BotonCancelar from './BotonCancelar'

/** Texto que parece un NIT: solo dígitos, puntos, guiones o espacios, de 5 caracteres o más. */
const PARECE_NIT = /^[\d.\-\s]{5,}$/

/** S-29: mismo formato que el backend (dígitos, puntos, guion y espacios, con al menos un dígito). */
const FORMATO_NIT = /^(?=.*\d)[\d.\-\s]+$/

/** Proveedor existente que devuelve el 422 por nombre o NIT repetido (S-24). */
function existenteDe(error: unknown): { id: number; nombre: string } | null {
  if (!(error instanceof AxiosError)) return null
  const datos = error.response?.data as { proveedor_existente_id?: number; proveedor_existente?: string } | undefined
  return datos?.proveedor_existente_id ? { id: datos.proveedor_existente_id, nombre: datos.proveedor_existente ?? '' } : null
}

/**
 * S-24 / S-29: crear o editar un proveedor. Nombre y NIT obligatorios; asesor y plazo opcionales.
 * Crear: logística y admin (desde el selector de ingresos, encima del formulario, o desde
 * Proveedores y categorías). Si el texto escrito en el selector parece un NIT va al NIT y el foco a Nombre; si
 * no, al revés. Editar: solo el admin (Proveedores y categorías); el NIT también es obligatorio (un proveedor
 * antiguo sin NIT lo recibe al editarlo). Un NIT o nombre repetido ofrece usar el existente.
 */
export default function DialogoProveedor({ proveedor, texto = '', onGuardado, onUsar, textoUsar = 'Usar ese proveedor', onCerrar }: {
  /** Con proveedor, edita; sin él, crea. */
  proveedor?: Proveedor
  /** Al crear: lo que se escribió en el selector, para prellenar. */
  texto?: string
  onGuardado: (proveedor: Proveedor) => void
  onUsar?: (id: number) => void
  textoUsar?: string
  onCerrar: () => void
}) {
  const qc = useQueryClient()
  const editando = proveedor !== undefined
  const esNit = !editando && PARECE_NIT.test(texto.trim())
  const [f, setF] = useState(editando
    ? { nombre: proveedor.nombre, nit: proveedor.nit ?? '', asesor: proveedor.asesor ?? '', plazo_dias: proveedor.plazo_dias == null ? '' : String(proveedor.plazo_dias) }
    : { nombre: esNit ? '' : texto.trim(), nit: esNit ? texto.trim() : '', asesor: '', plazo_dias: '' })

  const guardar = useMutation({
    mutationFn: async () => {
      const datos = {
        nombre: f.nombre.trim(),
        nit: f.nit.trim(),
        asesor: f.asesor.trim() || null,
        plazo_dias: f.plazo_dias === '' ? null : Number(f.plazo_dias),
      }
      return (editando
        ? await api.patch<Proveedor>(`/proveedores/${proveedor.id}`, datos)
        : await api.post<Proveedor>('/proveedores', datos)).data
    },
    onSuccess: (guardado) => {
      // Ya en la lista (sin esperar la recarga): el selector lo muestra y el plazo prellena el vencimiento.
      qc.setQueryData<Proveedor[]>(['proveedores'], (lista) => [...(lista ?? []).filter((p) => p.id !== guardado.id), guardado]
        .sort((a, b) => a.nombre.localeCompare(b.nombre, 'es')))
      qc.invalidateQueries({ queryKey: ['proveedores'] })
      onGuardado(guardado)
    },
  })

  const errores = guardar.isError ? erroresCampo(guardar.error) : {}
  const existente = guardar.isError ? existenteDe(guardar.error) : null
  const general = guardar.isError && Object.keys(errores).length === 0 ? mensajeError(guardar.error) : null
  const nitInvalido = f.nit.trim() !== '' && !FORMATO_NIT.test(f.nit.trim())
  const plazoInvalido = f.plazo_dias !== '' && (!/^\d+$/.test(f.plazo_dias) || Number(f.plazo_dias) > 365)
  const listo = f.nombre.trim() !== '' && f.nit.trim() !== '' && !nitInvalido && !plazoInvalido && !guardar.isPending
  const campo = (k: keyof typeof f) => ({
    value: f[k],
    onChange: (e: { target: { value: string } }) => { setF({ ...f, [k]: e.target.value }); guardar.reset() },
  })
  const errorNit = errores.nit ?? (nitInvalido ? 'Solo números, puntos, guion y espacios.' : null)
  const sinNitAntes = editando && !proveedor.nit

  return (
    <DialogoApilado
      titulo={editando ? `Editar proveedor` : 'Nuevo proveedor'}
      nota={<p className="nota-obligatorio">Los campos con <span className="obligatorio">*</span> son obligatorios.</p>}
      onCerrar={onCerrar}
      onEnviar={() => { if (listo) guardar.mutate() }}
      pie={(
        <>
          <BotonCancelar />
          <button type="submit" className="primario" disabled={!listo}>
            {guardar.isPending ? 'Guardando…' : editando ? 'Guardar' : onUsar ? 'Crear y usar' : 'Crear'}
          </button>
        </>
      )}
    >
      <label>
        <span className="etiqueta-campo">Nombre<span className="obligatorio" aria-hidden="true">*</span></span>
        <input required maxLength={150} autoFocus={esNit || editando} aria-invalid={!!errores.nombre} aria-describedby={errores.nombre ? 'proveedor-nombre-error' : undefined} {...campo('nombre')} />
        {errores.nombre && <small id="proveedor-nombre-error" className="ayuda error">{errores.nombre}</small>}
      </label>
      <label>
        <span className="etiqueta-campo">NIT<span className="obligatorio" aria-hidden="true">*</span></span>
        <input
          required
          maxLength={30}
          inputMode="numeric"
          placeholder="Ej: 900123456-7"
          autoFocus={!esNit && !editando}
          aria-invalid={!!errorNit}
          aria-describedby="proveedor-nit-ayuda"
          {...campo('nit')}
        />
        {errorNit
          ? <small id="proveedor-nit-ayuda" className="ayuda error">{errorNit}</small>
          : <small id="proveedor-nit-ayuda" className="ayuda">{sinNitAntes ? 'Este proveedor no tenía NIT: escríbalo para guardar.' : 'Una vez guardado no se puede borrar, solo cambiar.'}</small>}
      </label>
      <label>
        <span className="etiqueta-campo">Nombre del asesor (opcional)</span>
        <input maxLength={120} autoComplete="off" aria-invalid={!!errores.asesor} aria-describedby={errores.asesor ? 'proveedor-asesor-error' : undefined} {...campo('asesor')} />
        {errores.asesor && <small id="proveedor-asesor-error" className="ayuda error">{errores.asesor}</small>}
      </label>
      <label>
        <span className="etiqueta-campo">Plazo de pago en días (opcional)</span>
        <input inputMode="numeric" aria-invalid={plazoInvalido || !!errores.plazo_dias} aria-describedby="proveedor-plazo-ayuda" {...campo('plazo_dias')} />
        {errores.plazo_dias
          ? <small id="proveedor-plazo-ayuda" className="ayuda error">{errores.plazo_dias}</small>
          : <small id="proveedor-plazo-ayuda" className={plazoInvalido ? 'ayuda error' : 'ayuda'}>{plazoInvalido ? 'Un número entero de 0 a 365.' : 'Prellena el vencimiento de sus compras a crédito.'}</small>}
      </label>
      {existente && (
        <div className="aviso atencion" role="alert">
          Ya existe el proveedor <strong>{existente.nombre}</strong>.{' '}
          {onUsar && <button type="button" className="enlace" onClick={() => onUsar(existente.id)}>{textoUsar}</button>}
        </div>
      )}
      {general && <div className="aviso error" role="alert">{general}</div>}
    </DialogoApilado>
  )
}
