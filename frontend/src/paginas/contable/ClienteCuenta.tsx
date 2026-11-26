import { Link, useParams } from 'react-router-dom'
import EstadoCuenta from './EstadoCuenta'

/** RF-18 (5) / RF-29: estado de cuenta de un cliente, solo lectura para el contable (S-27). */
export default function ClienteCuenta() {
  const { id } = useParams()

  return (
    <div className="pila">
      <div className="pagina-encabezado">
        <p className="migas"><Link to="/contable/cartera">Cartera de clientes</Link> ›</p>
        <h1>Estado de cuenta del cliente</h1>
        <p>Ventas a crédito, reversos y abonos en orden de fecha. Los abonos los registra el mostrador o el administrador.</p>
      </div>
      <EstadoCuenta ruta={`/clientes/${id}/estado-cuenta`} />
    </div>
  )
}
