import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '../../api/cliente'
import type { DocumentoPendiente, MovimientoDinero, SaldoProveedor } from '../../api/tipos'
import { useAuth } from '../../auth/AuthProvider'
import BotonFila from '../../componentes/BotonFila'
import { Antiguedad, DialogoAnular, DialogoMovimiento, TablaMovimientos } from '../../componentes/Cartera'
import { fechaCorta } from '../../componentes/fechas'
import { pesos } from '../../componentes/numeros'
import EstadoCuenta from './EstadoCuenta'
import { invalidarCartera } from './cartera'
import '../../componentes/cartera.css'

const nombreDoc = (d: DocumentoPendiente) => `${d.tipo === 'factura' ? 'Factura' : 'Remisión'} ${d.numero_documento ?? `#${d.id}`}`

/**
 * RF-17 / RF-29: saldo de un proveedor, sus facturas con saldo, sus pagos y el estado de cuenta.
 * S-79: cada pago va a una factura elegida en su fila ("Pagar"); se admiten pagos parciales
 * sucesivos hasta cancelarla. Anular un pago es solo del admin (S-27).
 */
export default function ProveedorCuenta() {
  const { id } = useParams()
  const qc = useQueryClient()
  const { usuario } = useAuth()
  const [pagando, setPagando] = useState<DocumentoPendiente | null>(null)
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
  const proveedor = data.proveedor.nombre

  return (
    <div className="pila">
      <div className="pagina-encabezado">
        <p className="migas"><Link to="/contable/cuentas-por-pagar">Cuentas por pagar</Link> ›</p>
        <h1>{proveedor}</h1>
        <p>{data.proveedor.nit ? `NIT ${data.proveedor.nit} · ` : ''}Saldo a hoy {pesos(data.saldo)}{Number(data.vencido) > 0 ? ` · vencido ${pesos(data.vencido)}` : ''}</p>
      </div>

      <section className="tarjeta-pagina">
        <Antiguedad resumen={data} />
      </section>

      <section className="tarjeta-pagina">
        <div className="fila-titulo">
          <h2>Facturas con saldo</h2>
        </div>
        {data.documentos.length === 0 ? (
          <p className="vacio">Este proveedor no tiene facturas con saldo.</p>
        ) : (
          <div className="tabla-desplazable">
            <table className="documentos-pendientes">
              <thead>
                <tr><th>Documento</th><th>Fecha</th><th>Vence</th><th>Estado</th><th className="cifra">Valor</th><th className="cifra">Pendiente</th><th className="boton-fila-columna">Acciones</th></tr>
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
                    <td className="cifra">
                      {pesos(d.valor)}
                      {Number(d.abonado) > 0 && <small className="abonado-documento">Abonado {pesos(d.abonado)}</small>}
                    </td>
                    <td className="cifra"><strong>{pesos(d.pendiente)}</strong></td>
                    <td className="boton-fila-columna">
                      <BotonFila variante="pagar" descripcion={`Pagar ${nombreDoc(d)} de ${proveedor}`} onClick={() => setPagando(d)} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        <p className="ayuda">Elija la factura que va a pagar. Un pago parcial deja saldo en la factura.</p>
      </section>

      <section className="tarjeta-pagina">
        <div className="fila-titulo"><h2>Pagos recientes</h2></div>
        <TablaMovimientos
          movimientos={data.pagos_recientes}
          vacio="Todavía no hay pagos a este proveedor."
          titular={proveedor}
          onAnular={usuario?.rol === 'admin' ? setAnulando : undefined}
        />
      </section>

      <EstadoCuenta ruta={`/proveedores/${id}/estado-cuenta`} />

      {pagando && (
        <DialogoMovimiento
          titulo="Registrar pago"
          titular={proveedor}
          ruta={`/proveedores/${id}/pagos`}
          documento={{ id: pagando.id, nombre: nombreDoc(pagando), valor: pagando.valor, abonado: pagando.abonado, pendiente: pagando.pendiente }}
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
