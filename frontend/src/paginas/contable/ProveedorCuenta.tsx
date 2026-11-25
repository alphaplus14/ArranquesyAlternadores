import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '../../api/cliente'
import type { DocumentoPendiente, MovimientoDinero, SaldoProveedor } from '../../api/tipos'
import { useAuth } from '../../auth/AuthProvider'
import { Antiguedad, DialogoAnular, DialogoMovimiento, TablaMovimientos } from '../../componentes/Cartera'
import { fechaCorta } from '../../componentes/fechas'
import { pesos } from '../../componentes/numeros'
import EstadoCuenta from './EstadoCuenta'
import { invalidarCartera } from './cartera'
import '../../componentes/cartera.css'

const nombreDoc = (d: DocumentoPendiente) => `${d.tipo === 'factura' ? 'Factura' : 'Remisión'} ${d.numero_documento ?? `#${d.id}`}`

/**
 * RF-17 / RF-29: saldo de un proveedor, sus facturas con saldo (cada una se paga y queda
 * cancelada, RF-17 ajuste), sus pagos y el estado de cuenta. Anular un pago es solo del admin (S-27).
 */
export default function ProveedorCuenta() {
  const { id } = useParams()
  const qc = useQueryClient()
  const { usuario } = useAuth()
  const [pagando, setPagando] = useState<DocumentoPendiente | 'fifo' | null>(null)
  const [anulando, setAnulando] = useState<MovimientoDinero | null>(null)

  const { data } = useQuery({
    queryKey: ['saldo-proveedor', id],
    queryFn: async () => (await api.get<SaldoProveedor>(`/proveedores/${id}/saldo`)).data,
  })
  const listo = () => {
    setPagando(null)
    setAnulando(null)
    invalidarCartera(qc)
  }

  if (!data) return <div className="cargando">Cargando…</div>

  return (
    <div className="pila">
      <div className="pagina-encabezado">
        <p><Link to="/contable/cuentas-por-pagar">← Cuentas por pagar</Link></p>
        <h1>{data.proveedor.nombre}</h1>
        <p>{data.proveedor.nit ? `NIT ${data.proveedor.nit} · ` : ''}Saldo a hoy {pesos(data.saldo)}{Number(data.vencido) > 0 ? ` · vencido ${pesos(data.vencido)}` : ''}</p>
      </div>

      <section className="tarjeta-pagina">
        <Antiguedad resumen={data} />
      </section>

      <section className="tarjeta-pagina">
        <div className="fila-titulo">
          <h2>Facturas con saldo</h2>
          <button type="button" className="secundario" onClick={() => setPagando('fifo')}>Pago sin elegir factura</button>
        </div>
        <div className="tabla-desplazable">
          <table className="documentos-pendientes">
            <thead>
              <tr><th>Documento</th><th>Fecha</th><th>Vence</th><th>Estado</th><th className="cifra">Valor</th><th className="cifra">Pendiente</th><th><span className="solo-lector">Acciones</span></th></tr>
            </thead>
            <tbody>
              {data.documentos.map((d) => (
                <tr key={d.id}>
                  <td>{nombreDoc(d)}</td>
                  <td>{fechaCorta(d.fecha)}</td>
                  <td>{d.vence_el ? fechaCorta(d.vence_el) : '—'}</td>
                  <td>
                    {d.dias_vencido > 0
                      ? <span className="chip vencida">Vencida · {d.dias_vencido} d</span>
                      : <span className="chip">{d.dias_para_vencer === 0 ? 'Vence hoy' : `Faltan ${d.dias_para_vencer} d`}</span>}
                  </td>
                  <td className="cifra">{pesos(d.valor)}</td>
                  <td className="cifra"><strong>{pesos(d.pendiente)}</strong></td>
                  <td className="col-accion"><button type="button" className="enlace" onClick={() => setPagando(d)}>Pagar</button></td>
                </tr>
              ))}
              {data.documentos.length === 0 && <tr><td colSpan={7} className="vacio">Este proveedor no tiene facturas con saldo.</td></tr>}
            </tbody>
          </table>
        </div>
        <p className="ayuda">Un pago sin elegir factura se aplica primero a la que vence antes.</p>
      </section>

      <section className="tarjeta-pagina">
        <div className="fila-titulo"><h2>Pagos recientes</h2></div>
        <TablaMovimientos
          movimientos={data.pagos_recientes}
          vacio="Todavía no hay pagos a este proveedor."
          onAnular={usuario?.rol === 'admin' ? setAnulando : undefined}
        />
      </section>

      <EstadoCuenta ruta={`/proveedores/${id}/estado-cuenta`} />

      {pagando && (
        <DialogoMovimiento
          titulo="Registrar pago"
          titular={data.proveedor.nombre}
          saldo={data.saldo}
          ruta={`/proveedores/${id}/pagos`}
          documento={pagando === 'fifo' ? undefined : { id: pagando.id, nombre: nombreDoc(pagando), pendiente: pagando.pendiente }}
          onCerrar={() => setPagando(null)}
          onGuardado={listo}
        />
      )}
      {anulando && (
        <DialogoAnular
          titulo="Anular pago"
          descripcion={`Pago de ${pesos(anulando.valor)} del ${fechaCorta(anulando.fecha)}`}
          ruta={`/pagos/${anulando.id}/anulaciones`}
          onCerrar={() => setAnulando(null)}
          onHecho={listo}
        />
      )}
    </div>
  )
}
