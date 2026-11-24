import { useQueries } from '@tanstack/react-query'
import { api } from '../api/cliente'
import { NOMBRE_CALIDAD, type Calidad } from '../api/tipos'

interface Coincidencia {
  id: number
  codigo_referencia: string
  descripcion: string
  proveedor: { id: number; nombre: string }
  calidad: Calidad | null
  existencia: number
}

/**
 * S-25: aviso NO bloqueante cuando un código (referencia o código de barras) ya existe con otro
 * proveedor. El mismo producto lo venden varios proveedores y cada uno lleva su existencia.
 */
export default function AvisoCoincidencias({ codigos, proveedorId, accion = 'crearla' }: {
  codigos: string[]
  /** Proveedor de la referencia: sus propias referencias no cuentan. */
  proveedorId: string | number | null
  accion?: 'crearla' | 'guardarla'
}) {
  const unicos = [...new Set(codigos.map((c) => c.trim()).filter((c) => c.length >= 2))]
  const consultas = useQueries({
    queries: unicos.map((codigo) => ({
      queryKey: ['coincidencias', codigo, String(proveedorId ?? '')],
      queryFn: async () => (await api.get<Coincidencia[]>('/referencias/coincidencias', { params: { codigo, proveedor_id: proveedorId || undefined } })).data,
      staleTime: 30_000,
    })),
  })

  const vistas = new Set<number>()
  const encontradas = consultas.flatMap((c) => c.data ?? []).filter((r) => !vistas.has(r.id) && vistas.add(r.id))
  if (encontradas.length === 0) return null

  const detalle = encontradas
    .map((r) => [r.proveedor.nombre, r.descripcion, r.calidad ? NOMBRE_CALIDAD[r.calidad] : null, `existencia ${r.existencia}`].filter(Boolean).join(' · '))
    .join('; ')

  return (
    <div className="aviso atencion" role="status">
      Este código ya existe con {encontradas.length === 1 ? 'otro proveedor' : 'otros proveedores'}: {detalle}.{' '}
      Puede {accion} igual: cada proveedor lleva su propia existencia.
    </div>
  )
}
