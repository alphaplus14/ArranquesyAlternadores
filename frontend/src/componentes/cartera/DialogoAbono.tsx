import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { api, erroresCampo, mensajeError } from '../../api/cliente'
import { NOMBRE_MEDIO, type Abono, type MedioPago, type ResumenCartera } from '../../api/tipos'
import DialogoApilado from '../DialogoApilado'
import { leerNumero, pesos } from '../numeros'
import { hoyEnBogota } from '../../utilidades/periodos'

const MEDIOS = Object.keys(NOMBRE_MEDIO) as MedioPago[]

/**
 * RF-15 (S-30): registrar un abono. Fecha (hoy por defecto, no futura), valor > 0, medio y
 * referencia/nota opcionales. Si el valor supera el saldo, pide confirmar: queda saldo a favor.
 * La respuesta trae el resumen nuevo del cliente para actualizar la pantalla sin recargarla.
 */
export default function DialogoAbono({ cliente, saldo, onGuardado, onCerrar }: {
  cliente: { id: number; nombre: string }
  /** Saldo actual (texto en pesos); negativo = saldo a favor. */
  saldo: string
  onGuardado: (abono: Abono, resumen: ResumenCartera) => void
  onCerrar: () => void
}) {
  const hoy = hoyEnBogota()
  const [f, setF] = useState({ fecha: hoy, valor: '', medio: 'efectivo' as MedioPago, referencia: '', nota: '' })
  const [confirmaExceso, setConfirmaExceso] = useState(false)

  const valor = leerNumero(f.valor)
  const valorInvalido = f.valor.trim() !== '' && (valor === null || Number.isNaN(valor) || valor <= 0)
  const fechaFutura = f.fecha > hoy
  const excede = valor !== null && !Number.isNaN(valor) && valor > Math.max(Number(saldo), 0)
  const listo = valor !== null && !valorInvalido && f.fecha !== '' && !fechaFutura && (!excede || confirmaExceso)

  const guardar = useMutation({
    mutationFn: async () => (await api.post<{ abono: Abono; resumen: ResumenCartera }>(`/clientes/${cliente.id}/abonos`, {
      fecha: f.fecha,
      valor,
      medio: f.medio,
      referencia: f.referencia.trim() || null,
      nota: f.nota.trim() || null,
    })).data,
    onSuccess: (d) => onGuardado(d.abono, d.resumen),
  })

  const errores = guardar.isError ? erroresCampo(guardar.error) : {}
  const general = guardar.isError && Object.keys(errores).length === 0 ? mensajeError(guardar.error) : null
  const campo = (k: 'fecha' | 'valor' | 'referencia' | 'nota') => ({
    value: f[k],
    onChange: (e: { target: { value: string } }) => { setF({ ...f, [k]: e.target.value }); guardar.reset(); if (k === 'valor') setConfirmaExceso(false) },
  })
  const saldoNum = Number(saldo)
  const aFavor = valor !== null && !Number.isNaN(valor) ? valor - Math.max(saldoNum, 0) : 0

  return (
    <DialogoApilado
      titulo={`Registrar abono · ${cliente.nombre}`}
      nota={<p className="nota-obligatorio">Saldo actual: <strong>{saldoNum < 0 ? `${pesos(-saldoNum)} a favor` : pesos(saldoNum)}</strong>. Los campos con <span className="obligatorio">*</span> son obligatorios.</p>}
      onCerrar={onCerrar}
      onEnviar={() => { if (listo) guardar.mutate() }}
      pie={(
        <>
          <button type="button" className="secundario" onClick={onCerrar}>Cancelar</button>
          <button type="submit" className="primario" disabled={!listo || guardar.isPending}>{guardar.isPending ? 'Guardando…' : 'Registrar abono'}</button>
        </>
      )}
    >
      <div className="campos-dos">
        <label>
          <span className="etiqueta-campo">Fecha<span className="obligatorio" aria-hidden="true">*</span></span>
          <input type="date" required max={hoy} aria-invalid={fechaFutura || !!errores.fecha} aria-describedby="abono-fecha-ayuda" {...campo('fecha')} />
          <small id="abono-fecha-ayuda" className={fechaFutura || errores.fecha ? 'ayuda error' : 'ayuda'}>{errores.fecha ?? (fechaFutura ? 'La fecha no puede ser futura.' : 'Día en que el cliente pagó.')}</small>
        </label>
        <label>
          <span className="etiqueta-campo">Valor<span className="obligatorio" aria-hidden="true">*</span></span>
          <input required inputMode="decimal" autoFocus placeholder="Ej: 50.000" aria-invalid={valorInvalido || !!errores.valor} aria-describedby="abono-valor-ayuda" {...campo('valor')} />
          <small id="abono-valor-ayuda" className={valorInvalido || errores.valor ? 'ayuda error' : 'ayuda'}>{errores.valor ?? (valorInvalido ? 'Debe ser mayor que 0.' : valor ? pesos(valor) : 'Mayor que 0.')}</small>
        </label>
      </div>
      <label>
        <span className="etiqueta-campo">Medio de pago<span className="obligatorio" aria-hidden="true">*</span></span>
        <select value={f.medio} onChange={(e) => { setF({ ...f, medio: e.target.value as MedioPago }); guardar.reset() }}>
          {MEDIOS.map((m) => <option key={m} value={m}>{NOMBRE_MEDIO[m]}</option>)}
        </select>
      </label>
      <label>
        <span className="etiqueta-campo">Referencia (opcional)</span>
        <input maxLength={60} placeholder="N.º de transferencia o recibo" {...campo('referencia')} />
      </label>
      <label>
        <span className="etiqueta-campo">Nota (opcional)</span>
        <input maxLength={255} {...campo('nota')} />
      </label>
      {excede && (
        <div className="aviso atencion" role="alert">
          El abono supera el saldo: quedará <strong>{pesos(aFavor)} a favor</strong> del cliente.
          <label className="check confirmar-exceso">
            <input type="checkbox" checked={confirmaExceso} onChange={(e) => setConfirmaExceso(e.target.checked)} />
            Confirmo: quedará saldo a favor
          </label>
        </div>
      )}
      {general && <div className="aviso error" role="alert">{general}</div>}
    </DialogoApilado>
  )
}
