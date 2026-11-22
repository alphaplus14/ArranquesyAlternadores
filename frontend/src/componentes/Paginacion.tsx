import { infoPagina, type Pagina } from '../api/tipos'

export default function Paginacion<T>({ pagina, onCambiar }: { pagina?: Pagina<T>; onCambiar: (n: number) => void }) {
  if (!pagina) return null
  const { actual, ultima, total } = infoPagina(pagina)

  return (
    <div className="paginacion">
      <span>{total === 0 ? 'Sin registros' : total === 1 ? '1 registro' : `${total.toLocaleString('es-CO')} registros`}</span>
      <div className="paginacion-controles">
        <button type="button" aria-label="Primera página" disabled={actual <= 1} onClick={() => onCambiar(1)}>«</button>
        <button type="button" aria-label="Página anterior" disabled={actual <= 1} onClick={() => onCambiar(actual - 1)}>‹</button>
        <span className="pagina-actual" aria-current="page">{actual}</span>
        <button type="button" aria-label="Página siguiente" disabled={actual >= ultima} onClick={() => onCambiar(actual + 1)}>›</button>
        <button type="button" aria-label="Última página" disabled={actual >= ultima} onClick={() => onCambiar(ultima)}>»</button>
      </div>
    </div>
  )
}
