import axios, { AxiosError } from 'axios'

/** Cliente HTTP con cookies de sesión y token XSRF de Sanctum (modo SPA). */
export const api = axios.create({
  baseURL: '/api',
  withCredentials: true,
  withXSRFToken: true,
  headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
})

export async function pedirCsrf(): Promise<void> {
  await axios.get('/sanctum/csrf-cookie', { withCredentials: true })
}

/** Mensaje legible de un error de la API (validación, regla de negocio o red). */
export function mensajeError(error: unknown): string {
  if (error instanceof AxiosError) {
    const datos = error.response?.data as { message?: string; errors?: Record<string, string[]> } | undefined
    const primero = datos?.errors && Object.values(datos.errors)[0]?.[0]
    return primero ?? datos?.message ?? error.message
  }
  return error instanceof Error ? error.message : 'Error desconocido'
}
