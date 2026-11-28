import type { FotoCorreccion, LineaCorreccionFoto } from '../../api/tipos'
import { fechaCorta } from '../fechas'
import { formatoNumero, pesos } from '../numeros'
import { NOMBRE_CONDICION } from './condicionDescuento'

const NOMBRE_FORMA = { contado: 'Contado', credito: 'Crédito' } as const

/** Clave de una línea entre el antes y el después: su id, o la referencia si es nueva. */
const clave = (l: LineaCorreccionFoto, i: number) => (l.id !== null ? `i${l.id}` : `n${i}-${l.referencia_id}`)

/**
 * RF-28: el antes y el después de una corrección, solo con lo que cambió (encabezado y líneas).
 * Lo usan la lista de correcciones del admin y el historial de cada ingreso.
 */
export default function DetalleCorreccion({ antes, despues }: { antes: FotoCorreccion; despues: FotoCorreccion }) {
  const a = antes.encabezado
  const d = despues.encabezado
  const encabezado: Array<[string, string, string]> = []
  if (a.numero_documento !== d.numero_documento) encabezado.push(['Número', a.numero_documento, d.numero_documento])
  if (a.fecha !== d.fecha) encabezado.push(['Fecha', fechaCorta(a.fecha), fechaCorta(d.fecha)])
  if (a.forma_pago !== d.forma_pago) encabezado.push(['Forma de pago', NOMBRE_FORMA[a.forma_pago], NOMBRE_FORMA[d.forma_pago]])
  if (a.vence_el !== d.vence_el) encabezado.push(['Vencimiento', a.vence_el ? fechaCorta(a.vence_el) : '—', d.vence_el ? fechaCorta(d.vence_el) : '—'])
  if (a.condicion_descuento && d.condicion_descuento && a.condicion_descuento !== d.condicion_descuento) {
    encabezado.push(['Condición del descuento', NOMBRE_CONDICION[a.condicion_descuento], NOMBRE_CONDICION[d.condicion_descuento]])
  }
  if (Number(a.descuento_documento) !== Number(d.descuento_documento)) encabezado.push(['Descuento del documento', pesos(a.descuento_documento), pesos(d.descuento_documento)])
  if (Number(a.total ?? 0) !== Number(d.total ?? 0)) encabezado.push(['Total', a.total === null ? '—' : pesos(a.total), d.total === null ? '—' : pesos(d.total)])

  const previas = new Map(antes.lineas.map((l, i) => [clave(l, i), l]))
  const lineas = despues.lineas
    .map((l, i) => ({ despues: l, antes: l.id !== null ? previas.get(`i${l.id}`) ?? null : null, k: clave(l, i) }))
    .filter(({ antes: x, despues: y }) => !x || x.cantidad !== y.cantidad || Number(x.costo_unitario) !== Number(y.costo_unitario)
      || Number(x.descuento_porcentaje) !== Number(y.descuento_porcentaje) || Number(x.iva_porcentaje) !== Number(y.iva_porcentaje))

  const texto = (l: LineaCorreccionFoto | null) => l
    ? `${l.cantidad} × ${pesos(l.costo_unitario)}${Number(l.descuento_porcentaje) ? ` −${formatoNumero(l.descuento_porcentaje)} %` : ''} · IVA ${formatoNumero(l.iva_porcentaje)} %`
    : 'Ítem nuevo'

  return (
    <div className="detalle-correccion">
      {encabezado.length > 0 && (
        <dl className="datos-fijos">
          {encabezado.map(([campo, x, y]) => <div key={campo}><dt>{campo}</dt><dd>{x} → <strong>{y}</strong></dd></div>)}
        </dl>
      )}
      {lineas.length > 0 && (
        <ul className="lista-cambios">
          {lineas.map(({ antes: x, despues: y, k }) => (
            <li key={k}>
              <strong>{y.codigo_referencia ?? `#${y.referencia_id}`}</strong>{' '}
              {y.cantidad === 0 ? <span>{texto(x)} → <strong>ítem quitado</strong></span> : <span>{texto(x)} → <strong>{texto(y)}</strong></span>}
            </li>
          ))}
        </ul>
      )}
      {encabezado.length === 0 && lineas.length === 0 && <p className="ayuda">Sin cambios visibles.</p>}
    </div>
  )
}
