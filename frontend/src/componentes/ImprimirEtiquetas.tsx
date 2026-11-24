import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { api, mensajeError } from '../api/cliente'

/** Tamaños definidos en el backend (config/etiquetas.php, S-05): la misma lista del módulo Etiquetas. */
interface Tamano { clave: string; nombre: string; ancho: number; alto: number }

/**
 * RF-03 / S-39: elegir el tamaño e imprimir (abrir el PDF) las etiquetas de unas unidades o de las
 * unidades creadas por un ajuste de entrada. Imprimirlas las marca como etiquetadas (S-31).
 */
export default function ImprimirEtiquetas({ cuerpo, cantidad }: {
  /** { ajuste_id } o { unidad_ids }. */
  cuerpo: { ajuste_id: number } | { unidad_ids: number[] }
  cantidad: number
}) {
  const [tamano, setTamano] = useState('pequeno')
  const tamanos = useQuery({
    queryKey: ['etiquetas', 'tamanos'],
    queryFn: async () => (await api.get<Tamano[]>('/etiquetas/tamanos')).data,
    staleTime: Infinity,
  })

  const imprimir = useMutation({
    mutationFn: async () => {
      const r = await api.post('/etiquetas', { ...cuerpo, tamano }, { responseType: 'blob' })
      return URL.createObjectURL(r.data as Blob)
    },
    onSuccess: (url) => window.open(url, '_blank'),
  })

  return (
    <div className="imprimir-etiquetas">
      <label>Tamaño de la etiqueta
        <select value={tamano} onChange={(e) => setTamano(e.target.value)}>
          {(tamanos.data ?? []).map((t) => <option key={t.clave} value={t.clave}>{t.nombre} ({t.ancho}×{t.alto} mm)</option>)}
        </select>
      </label>
      <button type="button" className="primario boton-44" disabled={imprimir.isPending || !tamanos.data} onClick={() => imprimir.mutate()}>
        {imprimir.isPending ? 'Generando…' : `Imprimir ${cantidad === 1 ? 'la etiqueta' : `${cantidad} etiquetas`}`}
      </button>
      {imprimir.isError && <div className="aviso error" role="alert">{mensajeError(imprimir.error)}</div>}
    </div>
  )
}
