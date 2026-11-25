import { api } from './cliente'

/**
 * RF-31: descarga el Excel de un informe. El backend lo genera con los mismos filtros de la
 * pantalla (?formato=xlsx) y lo nombra; aquí solo se guarda el archivo.
 */
export async function descargarExcel(ruta: string, params: Record<string, string | number | undefined> = {}): Promise<void> {
  const respuesta = await api.get<Blob>(ruta, { params: { ...params, formato: 'xlsx' }, responseType: 'blob' })
  const disposicion = String(respuesta.headers['content-disposition'] ?? '')
  const nombre = disposicion.match(/filename="?([^";]+)"?/)?.[1] ?? 'informe.xlsx'

  const url = URL.createObjectURL(respuesta.data)
  const enlace = document.createElement('a')
  enlace.href = url
  enlace.download = nombre
  document.body.appendChild(enlace)
  enlace.click()
  enlace.remove()
  URL.revokeObjectURL(url)
}
