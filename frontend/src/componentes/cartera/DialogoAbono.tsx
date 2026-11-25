import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { AxiosError } from 'axios'
import { api, erroresCampo, mensajeError } from '../../api/cliente'
import { NOMBRE_MEDIO, type Abono, type DetalleCartera, type MedioPago, type ResumenCartera, type VentaCartera } from '../../api/tipos'
import DialogoApilado from '../DialogoApilado'
import ControlSegmentado from '../ControlSegmentado'
import Icono from '../Icono'
import { formatoNumero, leerNumero, pesos } from '../numeros'
import { fechaCorta } from '../fechas'
import { hoyEnBogota } from '../../utilidades/periodos'
import { nombreVenta } from '../../utilidades/cartera'

const MEDIOS = Object.keys(NOMBRE_MEDIO) as MedioPago[]

type Aplicar = 'general' | 'factura'

const APLICAR: { valor: Aplicar; texto: string }[] = [
  { valor: 'general', texto: 'A las más antiguas' },
  { valor: 'factura', texto: 'A una factura' },
]

/**
 * RF-15 (S-30, S-32): registrar un abono. Fecha (hoy por defecto, no futura), valor > 0, medio y
 * referencia/nota opcionales.
 * - "A las más antiguas" (general): se aplica FIFO. Si supera el saldo, pide confirmar el saldo a favor.
 * - "A una factura" (dirigido): se elige una venta con pendiente; el valor se prellena con su
 *   pendiente y no puede superarlo (el resto va como abono general aparte).
 * Las ventas salen de la misma consulta del detalle del cliente (en caché si se abre desde ahí).
 * La respuesta trae el resumen nuevo del cliente para actualizar la pantalla sin recargarla.
 */
export default function DialogoAbono({ cliente, saldo, ventaInicial, onGuardado, onCerrar }: {
  cliente: { id: number; nombre: string }
  /** Saldo actual (texto en pesos); negativo = saldo a favor. */
  saldo: string
  /** S-32: abre con esta venta elegida ("Abonar" desde la fila de la factura). */
  ventaInicial?: VentaCartera
  onGuardado: (abono: Abono, resumen: ResumenCartera) => void
  onCerrar: () => void
}) {
  const hoy = hoyEnBogota()
  const [f, setF] = useState({
    fecha: hoy,
    valor: ventaInicial ? formatoNumero(ventaInicial.pendiente) : '',
    medio: 'efectivo' as MedioPago,
    referencia: '',
    nota: '',
  })
  const [aplicar, setAplicar] = useState<Aplicar>(ventaInicial ? 'factura' : 'general')
  const [ventaId, setVentaId] = useState<number | null>(ventaInicial?.id ?? null)
  const [buscar, setBuscar] = useState('')
  const [confirmaExceso, setConfirmaExceso] = useState(false)

  const detalle = useQuery({
    queryKey: ['cartera-cliente', cliente.id],
    queryFn: async () => (await api.get<DetalleCartera>(`/clientes/${cliente.id}/cartera`)).data,
  })
  const conPendiente = (detalle.data?.ventas ?? []).filter((v) => Number(v.pendiente) > 0)
  const termino = buscar.trim().replace(/^#\s*/, '').toLowerCase()
  const visibles = termino === ''
    ? conPendiente
    : conPendiente.filter((v) => String(v.id) === termino || String(v.id).startsWith(termino) || (v.factura_contapyme ?? '').toLowerCase().startsWith(termino))
  const elegida = conPendiente.find((v) => v.id === ventaId) ?? (ventaInicial?.id === ventaId ? ventaInicial : undefined)
  const dirigido = aplicar === 'factura'

  const valor = leerNumero(f.valor)
  const valorNumero = valor !== null && !Number.isNaN(valor) ? valor : null
  const valorInvalido = f.valor.trim() !== '' && (valorNumero === null || valorNumero <= 0)
  const fechaFutura = f.fecha > hoy
  const superaFactura = dirigido && elegida !== undefined && valorNumero !== null && valorNumero > Number(elegida.pendiente)
  const excede = !dirigido && valorNumero !== null && valorNumero > Math.max(Number(saldo), 0)
  const listo = valorNumero !== null && !valorInvalido && f.fecha !== '' && !fechaFutura
    && (dirigido ? elegida !== undefined && !superaFactura : (!excede || confirmaExceso))

  const guardar = useMutation({
    mutationFn: async () => (await api.post<{ abono: Abono; resumen: ResumenCartera }>(`/clientes/${cliente.id}/abonos`, {
      fecha: f.fecha,
      valor: valorNumero,
      medio: f.medio,
      referencia: f.referencia.trim() || null,
      nota: f.nota.trim() || null,
      venta_id: dirigido ? ventaId : null,
    })).data,
    onSuccess: (d) => onGuardado(d.abono, d.resumen),
  })

  // Errores de validación por campo y, para reglas de negocio, el campo que indica el backend.
  const errores: Record<string, string> = guardar.isError ? erroresCampo(guardar.error) : {}
  if (guardar.isError && guardar.error instanceof AxiosError) {
    const datos = guardar.error.response?.data as { campo?: string; message?: string } | undefined
    if (datos?.campo && datos.message) errores[datos.campo] = datos.message
  }
  const general = guardar.isError && Object.keys(errores).length === 0 ? mensajeError(guardar.error) : null

  const cambiar = (cambios: Partial<typeof f>) => { setF({ ...f, ...cambios }); guardar.reset() }
  const campo = (k: 'fecha' | 'valor' | 'referencia' | 'nota') => ({
    value: f[k],
    onChange: (e: { target: { value: string } }) => { cambiar({ [k]: e.target.value }); if (k === 'valor') setConfirmaExceso(false) },
  })
  const elegir = (v: VentaCartera) => {
    setVentaId(v.id)
    cambiar({ valor: formatoNumero(v.pendiente) })
  }
  const saldoNum = Number(saldo)
  const aFavor = valorNumero !== null ? valorNumero - Math.max(saldoNum, 0) : 0

  const ayudaValor = errores.valor
    ?? (valorInvalido ? 'Debe ser mayor que 0.'
      : superaFactura && elegida ? `Supera lo pendiente de la venta ${nombreVenta(elegida)} (${pesos(elegida.pendiente)}). Registre el resto como abono general.`
        : dirigido && elegida ? `Hasta ${pesos(elegida.pendiente)} (pendiente de ${nombreVenta(elegida)}).`
          : valorNumero ? pesos(valorNumero) : 'Mayor que 0.')
  const valorConError = valorInvalido || superaFactura || !!errores.valor

  return (
    <DialogoApilado
      titulo={`Registrar abono · ${cliente.nombre}`}
      nota={<p className="nota-obligatorio">Saldo actual: <strong>{saldoNum < 0 ? `${pesos(-saldoNum)} a favor` : pesos(saldoNum)}</strong>. Los campos con <span className="obligatorio">*</span> son obligatorios.</p>}
      onCerrar={onCerrar}
      onEnviar={() => { if (listo && !guardar.isPending) guardar.mutate() }}
      pie={(
        <>
          <button type="button" className="secundario" onClick={onCerrar}>Cancelar</button>
          <button type="submit" className="primario" disabled={!listo || guardar.isPending}>{guardar.isPending ? 'Guardando…' : 'Registrar abono'}</button>
        </>
      )}
    >
      <div className="campo-aplicar">
        <ControlSegmentado
          id="abono-aplicar"
          etiqueta="Aplicar a"
          opciones={APLICAR}
          valor={aplicar}
          onCambio={(a) => { setAplicar(a); setConfirmaExceso(false); guardar.reset() }}
        />
        {!dirigido && <small className="ayuda">Se aplica a las ventas más antiguas con saldo.</small>}
      </div>

      {dirigido && (
        <fieldset className="selector-factura">
          <legend className="etiqueta-campo">Factura<span className="obligatorio" aria-hidden="true">*</span></legend>
          <label className="busca">
            <Icono nombre="buscar" />
            <input value={buscar} onChange={(e) => setBuscar(e.target.value)} placeholder="N.º de venta o factura" aria-label="Buscar la venta por número o factura" />
          </label>
          {detalle.isLoading ? <p className="ayuda">Cargando ventas…</p> : conPendiente.length === 0 ? (
            <p className="ayuda">Este cliente no tiene ventas con saldo pendiente.</p>
          ) : visibles.length === 0 ? (
            <p className="ayuda">Ninguna venta con pendiente coincide.</p>
          ) : (
            <ul className="opciones-factura" role="radiogroup" aria-label="Venta a la que se aplica el abono">
              {visibles.map((v) => (
                <li key={v.id}>
                  <button
                    type="button"
                    role="radio"
                    aria-checked={v.id === ventaId}
                    className={v.id === ventaId ? 'opcion-factura elegida' : 'opcion-factura'}
                    onClick={() => elegir(v)}
                  >
                    <span className="opcion-factura-linea">
                      <strong>{v.id === ventaId && <span aria-hidden>✓ </span>}Venta {nombreVenta(v)}</strong>
                      <strong className="opcion-factura-pendiente">{pesos(v.pendiente)}</strong>
                    </span>
                    <small>{v.vence_el ? `Vence ${fechaCorta(v.vence_el)}` : 'Sin vencimiento'} · {v.estado_texto}</small>
                  </button>
                </li>
              ))}
            </ul>
          )}
          {errores.venta_id && <small className="ayuda error" role="alert">{errores.venta_id}</small>}
        </fieldset>
      )}

      <div className="campos-dos">
        <label>
          <span className="etiqueta-campo">Fecha<span className="obligatorio" aria-hidden="true">*</span></span>
          <input type="date" required max={hoy} aria-invalid={fechaFutura || !!errores.fecha} aria-describedby="abono-fecha-ayuda" {...campo('fecha')} />
          <small id="abono-fecha-ayuda" className={fechaFutura || errores.fecha ? 'ayuda error' : 'ayuda'}>{errores.fecha ?? (fechaFutura ? 'La fecha no puede ser futura.' : 'Día en que el cliente pagó.')}</small>
        </label>
        <label>
          <span className="etiqueta-campo">Valor<span className="obligatorio" aria-hidden="true">*</span></span>
          <input required inputMode="decimal" autoFocus placeholder="Ej: 50.000" aria-invalid={valorConError} aria-describedby="abono-valor-ayuda" {...campo('valor')} />
          <small id="abono-valor-ayuda" className={valorConError ? 'ayuda error' : 'ayuda'}>{ayudaValor}</small>
        </label>
      </div>
      <label>
        <span className="etiqueta-campo">Medio de pago<span className="obligatorio" aria-hidden="true">*</span></span>
        <select value={f.medio} onChange={(e) => cambiar({ medio: e.target.value as MedioPago })}>
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
