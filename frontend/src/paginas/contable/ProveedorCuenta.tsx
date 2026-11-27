import { useParams } from 'react-router-dom'
import CuentaContable from './CuentaContable'

/**
 * RF-17 / RF-29 (S-79, S-88): cuenta de un proveedor. Facturas con saldo (cada una se paga, con
 * pagos parciales), historial de pagos y estado de cuenta, en pestañas. Anular un pago es solo del
 * admin (S-27).
 */
export default function ProveedorCuenta() {
  const { id = '' } = useParams()
  return (
    <CuentaContable
      key={id}
      config={{
        tipo: 'proveedor',
        id,
        lista: { ruta: '/contable/cuentas-por-pagar', texto: 'Cuentas por pagar', detalle: '/contable/proveedores' },
        rutaSaldo: `/proveedores/${id}/saldo`,
        rutaHistorial: `/proveedores/${id}/pagos`,
        rutaEstado: `/proveedores/${id}/estado-cuenta`,
        rutaAnular: (pago) => `/pagos/${pago}/anulaciones`,
        titular: (d) => {
          const p = d.proveedor as { nombre: string; nit: string | null }
          return { nombre: p.nombre, detalle: p.nit ? `NIT ${p.nit}` : '' }
        },
        conPagar: true,
      }}
    />
  )
}
