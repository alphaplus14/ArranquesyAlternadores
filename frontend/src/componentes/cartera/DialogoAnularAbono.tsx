import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { api, erroresCampo, mensajeError } from '../../api/cliente'
import { NOMBRE_MEDIO, type MedioPago, type ResumenCartera } from '../../api/tipos'
import DialogoApilado from '../DialogoApilado'
import { pesos } from '../numeros'
import { fechaCorta } from '../fechas'

/**
 * RF-15 (S-14, S-30): anular un abono (solo admin). Exige motivo y muestra el abono. El abono no se
 * borra: queda visible, tachado, con el motivo y quién lo anuló. Sin confirmación extra.
 */
export default function DialogoAnularAbono({ abono, onAnulado, onCerrar }: {
  abono: { id: number; fecha: string; valor: string; medio: MedioPago; referencia?: string | null }
  onAnulado: (resumen: ResumenCartera) => void
  onCerrar: () => void
}) {
  const [motivo, setMotivo] = useState('')
  const anular = useMutation({
    mutationFn: async () => (await api.post<{ resumen: ResumenCartera }>(`/abonos/${abono.id}/anulaciones`, { motivo: motivo.trim() })).data,
    onSuccess: (d) => onAnulado(d.resumen),
  })
  const errores = anular.isError ? erroresCampo(anular.error) : {}
  const listo = motivo.trim().length >= 3 && !anular.isPending

  return (
    <DialogoApilado
      titulo="Anular abono"
      nota={<p className="nota-obligatorio">El abono no se borra: queda visible como anulado y deja de contar en el saldo.</p>}
      onCerrar={onCerrar}
      onEnviar={() => { if (listo) anular.mutate() }}
      pie={(
        <>
          <button type="button" className="secundario" onClick={onCerrar}>Cancelar</button>
          <button type="submit" className="peligro" disabled={!listo}>{anular.isPending ? 'Anulando…' : 'Anular abono'}</button>
        </>
      )}
    >
      <dl className="datos-fijos datos-abono">
        <div><dt>Valor</dt><dd>{pesos(abono.valor)}</dd></div>
        <div><dt>Fecha</dt><dd>{fechaCorta(abono.fecha)}</dd></div>
        <div><dt>Medio</dt><dd>{NOMBRE_MEDIO[abono.medio]}{abono.referencia ? ` · ${abono.referencia}` : ''}</dd></div>
      </dl>
      <label>
        <span className="etiqueta-campo">Motivo<span className="obligatorio" aria-hidden="true">*</span></span>
        <textarea required autoFocus minLength={3} maxLength={500} rows={3} value={motivo} aria-invalid={!!errores.motivo} onChange={(e) => { setMotivo(e.target.value); anular.reset() }} />
        {errores.motivo && <small className="ayuda error">{errores.motivo}</small>}
      </label>
      {anular.isError && !errores.motivo && <div className="aviso error" role="alert">{mensajeError(anular.error)}</div>}
    </DialogoApilado>
  )
}
