import { useParams } from 'react-router-dom'
import CuentaContable from './CuentaContable'

/**
 * RF-18 / RF-29 (S-88): cuenta de un cliente para el contable, con la misma estructura que la del
 * proveedor y solo lectura: resumen, antigüedad, facturas con saldo (incluidas las ventas de solo
 * valor, S-73/S-74), historial de abonos y estado de cuenta. Los abonos los registra el mostrador o
 * el administrador desde Cartera; anular uno es solo del admin.
 */
export default function ClienteCuenta() {
  const { id = '' } = useParams()
  return (
    <CuentaContable
      key={id}
      config={{
        tipo: 'cliente',
        id,
        lista: { ruta: '/contable/cartera', texto: 'Cartera de clientes', detalle: '/contable/clientes' },
        rutaSaldo: `/clientes/${id}/saldo`,
        rutaHistorial: `/clientes/${id}/abonos`,
        rutaEstado: `/reportes/clientes/${id}/estado-cuenta`,
        rutaAnular: (abono) => `/abonos/${abono}/anulaciones`,
        titular: (d) => {
          const c = d.cliente as { nombre: string; documento: string | null; plazo_dias: number }
          return { nombre: c.nombre, detalle: [c.documento && `Doc. ${c.documento}`, `Plazo ${c.plazo_dias} días`].filter(Boolean).join(' · ') }
        },
        conPagar: false,
      }}
    />
  )
}
