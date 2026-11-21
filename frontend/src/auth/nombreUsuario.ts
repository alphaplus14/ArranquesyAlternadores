/**
 * Formato del nombre de usuario, igual que la regla NombreUsuario del backend (que es la que decide):
 * 3 a 30 caracteres, solo minúsculas, números, punto, guion y guion bajo.
 * Aquí solo sirve para avisar mientras se escribe.
 */
export const USUARIO_MIN = 3
export const USUARIO_MAX = 30
const PATRON = /^[a-z0-9._-]+$/

/** Minúsculas y sin espacios, como lo guarda el backend. */
export function normalizarUsuario(valor: string): string {
  return valor.toLowerCase().replace(/\s+/g, '')
}

/** Devuelve el mensaje del primer problema, o null si el usuario tiene un formato válido. */
export function errorUsuario(valor: string): string | null {
  if (valor === '') return 'Escribe tu usuario.'
  if (valor.includes('@')) return 'Escribe tu nombre de usuario, no el correo.'
  if (!PATRON.test(valor)) return 'Solo letras, números, punto, guion y guion bajo.'
  if (valor.length < USUARIO_MIN) return `Mínimo ${USUARIO_MIN} caracteres.`
  if (valor.length > USUARIO_MAX) return `Máximo ${USUARIO_MAX} caracteres.`
  return null
}
