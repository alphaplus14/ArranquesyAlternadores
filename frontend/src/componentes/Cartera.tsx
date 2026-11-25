import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { api, erroresCampo, mensajeError } from '../api/cliente'
import { NOMBRE_MEDIO_DINERO, NOMBRE_RANGO, NOMBRE_TIPO_CUENTA, type MovimientoDinero, type RangoAntiguedad, type ResumenSaldo } from '../api/tipos'
import { useCuentasPago } from '../hooks/useCatalogos'
import { useAvisoTexto } from './Avisos'
import Dialogo, { DialogoSeccion } from './Dialogo'
import { fechaCorta, hoy } from './fechas'
import { formatoNumero, leerNumero, pesos } from './numeros'
import CampoMoneda from './CampoMoneda'
import './cartera.css'

const RANGOS = Object.keys(NOMBRE_RANGO) as RangoAntiguedad[]

/** Saldo por antigüedad (FIFO). Las cifras vienen del backend: aquí no se recalculan. */
export function Antiguedad({ resumen }: { resumen: Pick<ResumenSaldo, 'rangos' | 'saldo'> }) {
  return (
    <div className="antiguedad" aria-label="Saldo por antigüedad">
      {RANGOS.map((r) => (
        <div key={r} className={r !== 'al_dia' && Number(resumen.rangos[r]) > 0 ? 'vencida' : ''}>
          <span>{r === 'al_dia' ? NOMBRE_RANGO[r] : `${NOMBRE_RANGO[r]} días`}</span>
          <strong>{pesos(resumen.rangos[r])}</strong>
        </div>
      ))}
      <div>
        <span>Saldo</span>
        <strong>{pesos(resumen.saldo)}</strong>
      </div>
    </div>
  )
}

/**
 * RF-15 / RF-17: abonos o pagos recientes. Un anulado sigue visible con su motivo: nada se borra.
 * Solo el admin anula (onAnular); el mostrador ve la lista sin esa acción.
 */
export function TablaMovimientos({ movimientos, vacio, onAnular }: {
  movimientos: MovimientoDinero[]
  vacio: string
  onAnular?: (m: MovimientoDinero) => void
}) {
  return (
    <div className="tabla-desplazable">
      <table>
        <thead>
          <tr><th>Fecha</th><th className="cifra">Valor</th><th>Medio</th><th>Referencia</th><th>Registró</th><th>Estado</th>{onAnular && <th><span className="solo-lector">Acciones</span></th>}</tr>
        </thead>
        <tbody>
          {movimientos.map((m) => (
            <tr key={m.id} className={m.anulacion ? 'reversado' : ''}>
              <td>{fechaCorta(m.fecha)}</td>
              <td className="cifra">{pesos(m.valor)}</td>
              <td>{m.cuenta?.nombre ?? NOMBRE_MEDIO_DINERO[m.medio] ?? m.medio}</td>
              <td>
                {m.referencia ?? '—'}
                {m.ingreso && <><br /><small>A {m.ingreso.tipo === 'factura' ? 'factura' : 'remisión'} {m.ingreso.numero_documento}</small></>}
                {m.nota && <><br /><small>{m.nota}</small></>}
              </td>
              <td>{m.usuario?.name ?? '—'}</td>
              <td>
                {m.anulacion
                  ? <><span className="chip">Anulado</span> <small>{m.anulacion.motivo} · {m.anulacion.usuario?.name}</small></>
                  : 'Vigente'}
              </td>
              {onAnular && (
                <td className="col-accion">
                  {!m.anulacion && <button type="button" className="enlace" onClick={() => onAnular(m)}>Anular</button>}
                </td>
              )}
            </tr>
          ))}
          {movimientos.length === 0 && <tr><td colSpan={onAnular ? 7 : 6} className="vacio">{vacio}</td></tr>}
        </tbody>
      </table>
    </div>
  )
}

/**
 * RF-15 / RF-17: registrar un abono o un pago. Fecha de hoy por defecto, editable hacia atrás,
 * nunca futura; valor en pesos enteros > 0; cuenta activa (RF-24, S-48: el medio lo deriva el
 * backend del tipo de la cuenta). El backend vuelve a validar.
 */
export function DialogoMovimiento({ titulo, titular, saldo, ruta, documento, onGuardado, onCerrar }: {
  titulo: string
  titular: string
  saldo: string
  /** POST que registra el movimiento (/clientes/{id}/abonos o /proveedores/{id}/pagos). */
  ruta: string
  /** RF-17 (ajuste): pago a una factura elegida; el valor se prellena con su pendiente y no puede superarlo. */
  documento?: { id: number; nombre: string; pendiente: string }
  onGuardado: (m: MovimientoDinero) => void
  onCerrar: () => void
}) {
  const [fecha, setFecha] = useState(hoy())
  // S-67: el valor se muestra con separador de miles.
  const [valor, setValor] = useState(documento ? formatoNumero(Math.round(Number(documento.pendiente))) : '')
  const [cuenta, setCuenta] = useState<number | ''>('')
  const cuentas = useCuentasPago()
  const cuentaElegida = cuenta !== '' ? cuenta : (cuentas.data?.find((c) => c.tipo === 'efectivo')?.id ?? '')
  const [referencia, setReferencia] = useState('')
  const [nota, setNota] = useState('')

  const numero = leerNumero(valor)
  const errorValor = valor.trim() === '' ? null
    : numero === null || Number.isNaN(numero) || !Number.isInteger(numero) || numero < 1 ? 'Escriba un valor en pesos enteros, mayor que 0.'
    : documento && numero > Number(documento.pendiente) ? `No puede superar lo pendiente de ${documento.nombre} (${pesos(documento.pendiente)}).` : null
  const quedaAFavor = !documento && numero !== null && !Number.isNaN(numero) && numero > Number(saldo)
  const cancela = documento && numero !== null && numero === Math.round(Number(documento.pendiente))

  const guardar = useMutation({
    mutationFn: async () => (await api.post<MovimientoDinero>(ruta, {
      fecha,
      valor: numero,
      cuenta_pago_id: cuentaElegida,
      referencia: referencia.trim() || null,
      nota: nota.trim() || null,
      ...(documento ? { ingreso_id: documento.id } : {}),
    })).data,
    onSuccess: onGuardado,
  })
  const errores = guardar.isError ? erroresCampo(guardar.error) : {}
  const general = guardar.isError && Object.keys(errores).length === 0 ? mensajeError(guardar.error) : null
  useAvisoTexto(general)

  return (
    <Dialogo
      titulo={titulo}
      descripcion={documento
        ? `${titular} · ${documento.nombre} · pendiente ${pesos(documento.pendiente)}`
        : `${titular} · saldo actual ${pesos(saldo)}`}
      onCerrar={onCerrar}
      pendiente={guardar.isPending}
      onEnviar={() => guardar.mutate()}
      pie={(
        <>
          <button type="button" className="secundario" disabled={guardar.isPending} onClick={onCerrar}>Cancelar</button>
          <button type="submit" className="primario" disabled={guardar.isPending || numero === null || errorValor !== null || cuentaElegida === ''}>
            {guardar.isPending ? 'Guardando…' : 'Registrar'}
          </button>
        </>
      )}
    >
      <DialogoSeccion>
        <label>Valor
          <CampoMoneda
            autoFocus
            required
            aria-invalid={!!(errorValor ?? errores.valor)}
            aria-describedby="movimiento-valor-ayuda"
            value={valor}
            onChange={(e) => setValor(e.target.value)}
          />
          <small id="movimiento-valor-ayuda" className={errorValor ?? errores.valor ? 'ayuda error' : 'ayuda'}>
            {errorValor ?? errores.valor ?? (quedaAFavor ? 'Supera el saldo: la diferencia queda como saldo a favor.'
              : cancela ? 'Con este valor la factura queda cancelada.'
              : documento ? 'Pago parcial: la factura sigue con saldo.' : 'Pesos enteros.')}
          </small>
        </label>
        <div className="campos-dos">
          <label>Fecha
            <input type="date" required max={hoy()} aria-invalid={!!errores.fecha} value={fecha} onChange={(e) => setFecha(e.target.value)} />
            {errores.fecha && <small className="ayuda error">{errores.fecha}</small>}
          </label>
          <label>Cuenta
            <select value={cuentaElegida} aria-invalid={!!errores.cuenta_pago_id} onChange={(e) => setCuenta(e.target.value === '' ? '' : Number(e.target.value))}>
              {cuentaElegida === '' && <option value="">{cuentas.isLoading ? 'Cargando cuentas…' : 'Elija la cuenta…'}</option>}
              {(cuentas.data ?? []).map((c) => <option key={c.id} value={c.id}>{c.nombre} ({NOMBRE_TIPO_CUENTA[c.tipo]})</option>)}
            </select>
            {errores.cuenta_pago_id && <small className="ayuda error">{errores.cuenta_pago_id}</small>}
          </label>
        </div>
        <label>Referencia (opcional)
          <input maxLength={60} placeholder="N.º de transferencia, cheque o recibo" value={referencia} onChange={(e) => setReferencia(e.target.value)} />
          {errores.referencia && <small className="ayuda error">{errores.referencia}</small>}
        </label>
        <label>Nota (opcional)
          <input maxLength={255} value={nota} onChange={(e) => setNota(e.target.value)} />
        </label>
        <p className="ayuda">Un movimiento guardado no se edita ni se borra; si hay un error, el administrador lo anula con un motivo.</p>
      </DialogoSeccion>
    </Dialogo>
  )
}

/** RF-15 / RF-17: anular es un movimiento nuevo con motivo obligatorio; el original no se toca. */
export function DialogoAnular({ titulo, descripcion, ruta, onHecho, onCerrar }: {
  titulo: string
  descripcion: string
  /** POST de la anulación (/abonos/{id}/anulaciones o /pagos/{id}/anulaciones). */
  ruta: string
  onHecho: () => void
  onCerrar: () => void
}) {
  const [motivo, setMotivo] = useState('')
  const anular = useMutation({
    mutationFn: () => api.post(ruta, { motivo: motivo.trim() }),
    onSuccess: onHecho,
  })
  useAvisoTexto(anular.isError ? mensajeError(anular.error) : null)

  return (
    <Dialogo
      titulo={titulo}
      descripcion={descripcion}
      alertdialog
      onCerrar={onCerrar}
      pendiente={anular.isPending}
      onEnviar={() => anular.mutate()}
      pie={(
        <>
          <button type="button" className="secundario" disabled={anular.isPending} onClick={onCerrar}>Cancelar</button>
          <button type="submit" className="peligro" disabled={anular.isPending || motivo.trim().length < 3}>{anular.isPending ? 'Anulando…' : 'Anular'}</button>
        </>
      )}
    >
      <DialogoSeccion>
        <label>Motivo
          <textarea autoFocus required minLength={3} maxLength={500} rows={3} value={motivo} onChange={(e) => setMotivo(e.target.value)} />
        </label>
        <p className="ayuda">El movimiento seguirá visible como anulado y el saldo vuelve a subir.</p>
      </DialogoSeccion>
    </Dialogo>
  )
}
