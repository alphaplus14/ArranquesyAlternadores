import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { AxiosError } from 'axios'
import { api, erroresCampo, mensajeError } from '../../api/cliente'
import { etiquetaCuenta, type Abono, type DetalleCartera, type ResumenCartera, type VentaCartera } from '../../api/tipos'
import { useCuentasPago } from '../../hooks/useCatalogos'
import DialogoApilado from '../DialogoApilado'
import Icono from '../Icono'
import { formatoNumero, leerNumero, pesos } from '../numeros'
import { fechaCorta } from '../fechas'
import { hoyEnBogota } from '../../utilidades/periodos'
import { diaEnColombia, formatearFecha } from '../../utilidades/fechas'
import { nombreVenta } from '../../utilidades/cartera'
import CampoMoneda from '../CampoMoneda'

/**
 * S-79: nombre de la venta en el selector. Venta de solo valor (S-73): "Factura 1234" o, sin número,
 * "Remisión sin número · #id"; venta con ítems: "Venta #id · FE-…".
 */
function nombreDocumento(v: VentaCartera): string {
  if (!v.tipo_documento) return `Venta ${nombreVenta(v)}`
  const tipo = v.tipo_documento === 'factura' ? 'Factura' : 'Remisión'
  return v.factura_contapyme ? `${tipo} ${v.factura_contapyme}` : `${tipo} sin número · #${v.id}`
}

/** Primero la que vence antes (sin vencimiento, por su cierre); a igual fecha, la más antigua. */
const porVencimiento = (a: VentaCartera, b: VentaCartera) =>
  (a.vence_el ?? diaEnColombia(a.cerrada_at)).localeCompare(b.vence_el ?? diaEnColombia(b.cerrada_at)) || a.id - b.id

/**
 * RF-15 (S-30, S-32, S-79): registrar un abono, siempre a UNA venta a crédito cerrada con pendiente
 * (ya no hay abono general ni saldo a favor nuevo). Abierto desde la fila ("Abonar"), trae esa venta;
 * desde la cabecera, preselecciona la que vence primero. Valor por defecto = pendiente, editable hacia
 * abajo (abono parcial); máximo = pendiente. Fecha: hoy por defecto, no futura ni anterior al cierre
 * de la venta. Cuenta donde entra (RF-24, S-48: el medio lo deriva el backend). El backend vuelve a
 * validar con la venta bloqueada. La respuesta trae el resumen nuevo del cliente.
 */
export default function DialogoAbono({ cliente, saldo, ventaInicial, onGuardado, onCerrar }: {
  cliente: { id: number; nombre: string }
  /** Saldo actual (texto en pesos); negativo = saldo a favor de abonos antiguos. */
  saldo: string
  /** Abre con esta venta elegida ("Abonar" desde la fila de la venta). */
  ventaInicial?: VentaCartera
  onGuardado: (abono: Abono, resumen: ResumenCartera) => void
  onCerrar: () => void
}) {
  const hoy = hoyEnBogota()
  const [f, setF] = useState({ fecha: hoy, cuenta: '' as number | '', referencia: '', nota: '' })
  /** null = el pendiente de la venta elegida (valor por defecto). */
  const [valorEscrito, setValorEscrito] = useState<string | null>(null)
  const [ventaId, setVentaId] = useState<number | null>(ventaInicial?.id ?? null)
  const [buscar, setBuscar] = useState('')
  const cuentas = useCuentasPago()
  // Efectivo por defecto, cuando llegan las cuentas.
  const cuentaElegida = f.cuenta !== '' ? f.cuenta : (cuentas.data?.find((c) => c.tipo === 'efectivo')?.id ?? '')

  const detalle = useQuery({
    queryKey: ['cartera-cliente', cliente.id],
    queryFn: async () => (await api.get<DetalleCartera>(`/clientes/${cliente.id}/cartera`)).data,
  })
  const conPendiente = (detalle.data?.ventas ?? []).filter((v) => Number(v.pendiente) > 0).sort(porVencimiento)
  const termino = buscar.trim().replace(/^#\s*/, '').toLowerCase()
  const visibles = termino === ''
    ? conPendiente
    : conPendiente.filter((v) => String(v.id).startsWith(termino) || (v.factura_contapyme ?? '').toLowerCase().startsWith(termino))
  // La elegida; si no hay, la que vence primero (también cuando hay una sola).
  const elegida = conPendiente.find((v) => v.id === ventaId)
    ?? (ventaInicial && ventaInicial.id === ventaId && detalle.isLoading ? ventaInicial : undefined)
    ?? conPendiente[0]
  const sinFacturas = !detalle.isLoading && conPendiente.length === 0

  const textoValor = valorEscrito ?? (elegida ? formatoNumero(elegida.pendiente) : '')
  const valor = leerNumero(textoValor)
  const valorNumero = valor !== null && !Number.isNaN(valor) ? valor : null
  const valorInvalido = textoValor.trim() !== '' && (valorNumero === null || valorNumero <= 0 || Math.round(valorNumero * 100) !== valorNumero * 100)
  const supera = elegida !== undefined && valorNumero !== null && valorNumero > Number(elegida.pendiente)
  const cancela = elegida !== undefined && valorNumero !== null && valorNumero === Number(elegida.pendiente)
  const cierre = elegida ? diaEnColombia(elegida.cerrada_at) : undefined
  const fechaFutura = f.fecha > hoy
  const antesDelCierre = cierre !== undefined && f.fecha !== '' && f.fecha < cierre
  const listo = elegida !== undefined && valorNumero !== null && !valorInvalido && !supera
    && f.fecha !== '' && !fechaFutura && !antesDelCierre && cuentaElegida !== ''

  const guardar = useMutation({
    mutationFn: async () => (await api.post<{ abono: Abono; resumen: ResumenCartera }>(`/clientes/${cliente.id}/abonos`, {
      fecha: f.fecha,
      valor: valorNumero,
      cuenta_pago_id: cuentaElegida,
      referencia: f.referencia.trim() || null,
      nota: f.nota.trim() || null,
      venta_id: elegida?.id,
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
  const campo = (k: 'fecha' | 'referencia' | 'nota') => ({
    value: f[k],
    onChange: (e: { target: { value: string } }) => cambiar({ [k]: e.target.value }),
  })
  const elegir = (v: VentaCartera) => {
    setVentaId(v.id)
    setValorEscrito(null)
    guardar.reset()
  }
  const saldoNum = Number(saldo)

  const ayudaValor = errores.valor
    ?? (valorInvalido ? 'Debe ser mayor que 0, con hasta 2 decimales.'
      : supera && elegida ? `No puede superar lo pendiente de ${nombreDocumento(elegida)} (${pesos(elegida.pendiente)}).`
        : !elegida || valorNumero === null ? (elegida ? `Hasta ${pesos(elegida.pendiente)}.` : 'Mayor que 0.')
          : cancela ? 'Cancela la factura.' : 'Abono parcial: la factura sigue con saldo.')
  const valorConError = valorInvalido || supera || !!errores.valor
  const ayudaFecha = errores.fecha
    ?? (fechaFutura ? 'La fecha no puede ser futura.'
      : antesDelCierre && cierre ? `No puede ser anterior al cierre de la venta (${fechaCorta(cierre)}).` : 'Día en que el cliente pagó.')

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
      <fieldset className="selector-factura">
        <legend className="etiqueta-campo">Factura o remisión<span className="obligatorio" aria-hidden="true">*</span></legend>
        {conPendiente.length > 5 && (
          <label className="busca">
            <Icono nombre="buscar" />
            <input value={buscar} onChange={(e) => setBuscar(e.target.value)} placeholder="N.º de venta o factura" aria-label="Buscar la venta por número o factura" />
          </label>
        )}
        {detalle.isLoading ? <p className="ayuda">Cargando ventas…</p> : sinFacturas ? (
          <p className="aviso info" role="status">Este cliente no tiene facturas con saldo.</p>
        ) : visibles.length === 0 ? (
          <p className="ayuda">Ninguna venta con pendiente coincide.</p>
        ) : (
          <ul className="opciones-factura" role="radiogroup" aria-label="Factura o remisión a la que se aplica el abono">
            {visibles.map((v) => {
              const marcada = v.id === elegida?.id
              return (
                <li key={v.id}>
                  <button
                    type="button"
                    role="radio"
                    aria-checked={marcada}
                    className={marcada ? 'opcion-factura elegida' : 'opcion-factura'}
                    onClick={() => elegir(v)}
                  >
                    <span className="opcion-factura-linea">
                      <strong>{marcada && <span aria-hidden>✓ </span>}{nombreDocumento(v)} · {formatearFecha(v.cerrada_at)}</strong>
                      <strong className="opcion-factura-pendiente">pendiente {pesos(v.pendiente)}</strong>
                    </span>
                    <small>{v.vence_el ? `Vence ${fechaCorta(v.vence_el)}` : 'Sin vencimiento'} · {v.estado_texto}</small>
                  </button>
                </li>
              )
            })}
          </ul>
        )}
        {errores.venta_id && <small className="ayuda error" role="alert">{errores.venta_id}</small>}
      </fieldset>

      {elegida && (
        <p className="documento-a-pagar" aria-label={`${nombreDocumento(elegida)}: valor ${pesos(elegida.total)}, abonado ${pesos(elegida.aplicado)}, pendiente ${pesos(elegida.pendiente)}`}>
          <span>Valor <strong>{pesos(elegida.total)}</strong></span>
          <span aria-hidden="true">·</span>
          <span>Abonado <strong>{pesos(elegida.aplicado)}</strong></span>
          <span aria-hidden="true">·</span>
          <span>Pendiente <strong>{pesos(elegida.pendiente)}</strong></span>
        </p>
      )}

      <div className="campos-dos">
        <label>
          <span className="etiqueta-campo">Fecha<span className="obligatorio" aria-hidden="true">*</span></span>
          <input type="date" required min={cierre} max={hoy} aria-invalid={fechaFutura || antesDelCierre || !!errores.fecha} aria-describedby="abono-fecha-ayuda" {...campo('fecha')} />
          <small id="abono-fecha-ayuda" className={fechaFutura || antesDelCierre || errores.fecha ? 'ayuda error' : 'ayuda'}>{ayudaFecha}</small>
        </label>
        <label>
          <span className="etiqueta-campo">Valor<span className="obligatorio" aria-hidden="true">*</span></span>
          <CampoMoneda
            required
            autoFocus
            placeholder="Ej: 50.000"
            disabled={!elegida}
            aria-invalid={valorConError}
            aria-describedby="abono-valor-ayuda"
            value={textoValor}
            onChange={(e) => { setValorEscrito(e.target.value); guardar.reset() }}
          />
          <small id="abono-valor-ayuda" className={valorConError ? 'ayuda error' : 'ayuda'}>{ayudaValor}</small>
        </label>
      </div>
      <label>
        <span className="etiqueta-campo">Cuenta<span className="obligatorio" aria-hidden="true">*</span></span>
        <select value={cuentaElegida} aria-invalid={!!errores.cuenta_pago_id} onChange={(e) => cambiar({ cuenta: e.target.value === '' ? '' : Number(e.target.value) })}>
          {cuentaElegida === '' && <option value="">{cuentas.isLoading ? 'Cargando cuentas…' : 'Elija la cuenta…'}</option>}
          {(cuentas.data ?? []).map((c) => <option key={c.id} value={c.id}>{etiquetaCuenta(c, true)}</option>)}
        </select>
        {errores.cuenta_pago_id && <small className="ayuda error" role="alert">{errores.cuenta_pago_id}</small>}
      </label>
      <label>
        <span className="etiqueta-campo">Referencia (opcional)</span>
        <input maxLength={60} placeholder="N.º de transferencia o recibo" {...campo('referencia')} />
      </label>
      <label>
        <span className="etiqueta-campo">Nota (opcional)</span>
        <input maxLength={255} {...campo('nota')} />
      </label>
      {general && <div className="aviso error" role="alert">{general}</div>}
    </DialogoApilado>
  )
}
