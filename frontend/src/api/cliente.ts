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

/** Errores de validación por campo (422), para mostrarlos junto a cada campo. */
export function erroresCampo(error: unknown): Record<string, string> {
  if (!(error instanceof AxiosError)) return {}
  const errores = (error.response?.data as { errors?: Record<string, string[]> } | undefined)?.errors ?? {}
  return Object.fromEntries(Object.entries(errores).map(([campo, mensajes]) => [campo, mensajeParaPantalla(mensajes[0])]))
}

const NOMBRE_MODELO: Record<string, string> = {
  Ingreso: 'el ingreso',
  IngresoItem: 'la línea del ingreso',
  Referencia: 'la referencia',
  Unidad: 'la unidad',
  Venta: 'la venta',
  VentaItem: 'el ítem de la venta',
  Cliente: 'el cliente',
  Proveedor: 'el proveedor',
  User: 'el usuario',
  CodigoUniversal: 'el código universal',
  Categoria: 'la categoría',
  Reverso: 'el reverso',
  ReversoItem: 'el reverso',
  MovimientoInventario: 'el movimiento',
  Auditoria: 'el registro de auditoría',
}

const TECNICO = /could not be found|is not supported for route|SQLSTATE|App\\|Illuminate\\|Symfony\\|vendor[\\/]|Stack trace|Unauthenticated\.?$|^Too Many Attempts\.?$|^Server Error\.?$|Target class |^Request failed with status code \d+$/

/** Ningún aviso de pantalla nombra una ruta, una clase o una consulta. */
function mensajeParaPantalla(texto: string, estado?: number): string {
  // Un 500 nunca es una regla de negocio (esas responden 4xx): no se muestra su texto técnico.
  if (estado === 500) return 'Error del servidor. Avise al administrador del sistema.'
  const modelo = texto.match(/No query results for model \[App\\Models\\(\w+)\]/)
  if (modelo) return `No se encontró ${NOMBRE_MODELO[modelo[1]] ?? 'el registro'}.`
  if (!TECNICO.test(texto)) return texto
  if (estado === 401) return 'Debe iniciar sesión.'
  if (estado === 404) return 'No se encontró lo que buscaba.'
  if (estado === 405) return 'Esa acción no está disponible.'
  if (estado === 429) return 'Demasiados intentos. Espere un momento e intente de nuevo.'
  return 'No se pudo completar la operación.'
}

/** Mensaje legible de un error de la API (validación, regla de negocio o red). */
export function mensajeError(error: unknown): string {
  if (error instanceof AxiosError) {
    // Sin respuesta (backend caído, red): la petición salió y no volvió nada. Una cancelada no cuenta.
    if (!error.response && error.request && error.code !== AxiosError.ERR_CANCELED) {
      return 'No hay conexión con el servidor. Avise al administrador del sistema.'
    }
    const datos = error.response?.data as { message?: string; errors?: Record<string, string[]> } | undefined
    const primero = datos?.errors && Object.values(datos.errors)[0]?.[0]
    return mensajeParaPantalla(primero ?? datos?.message ?? error.message, error.response?.status)
  }
  return error instanceof Error ? mensajeParaPantalla(error.message) : 'Error desconocido'
}
