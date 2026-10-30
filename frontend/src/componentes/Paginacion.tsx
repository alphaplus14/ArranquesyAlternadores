import { infoPagina, type Pagina } from '../api/tipos'

export default function Paginacion<T>({ pagina, onCambiar }: { pagina?: Pagina<T>; onCambiar: (n: number) => void }) {
  if (!pagina) return null
  const { actual, ultima, total } = infoPagina(pagina)

  return (
    <div className="paginacion">
      <button disabled={actual <= 1} onClick={() => onCambiar(actual - 1)}>‹ Anterior</button>
      <span>Página {actual} de {ultima} · {total} registros</span>
      <button disabled={actual >= ultima} onClick={() => onCambiar(actual + 1)}>Siguiente ›</button>
    </div>
  )
}
