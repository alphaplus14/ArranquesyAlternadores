/**
 * Filtro de proveedores en el navegador (sin peticiones): por nombre o por NIT, "contiene",
 * sin importar mayúsculas ni tildes. El NIT se compara sin puntos, guiones ni espacios, así
 * "900.123.456-7" se encuentra con "9001234567" o con "900123". Primero van los que empiezan
 * por lo escrito y, dentro de cada grupo, por nombre.
 */

export interface ProveedorFiltrable { id: number; nombre: string; nit?: string | null }

/** Minúsculas y sin diacríticos: "Óchoa" → "ochoa". */
export function normalizarTexto(texto: string): string {
  return texto.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim()
}

/** NIT sin puntos, guiones ni espacios: "900.123.456-7" → "9001234567". */
export function normalizarNit(nit: string): string {
  return normalizarTexto(nit).replace(/[.\-\s]/g, '')
}

const porNombre = (a: ProveedorFiltrable, b: ProveedorFiltrable) => a.nombre.localeCompare(b.nombre, 'es', { sensitivity: 'base' })

export function filtrarProveedores<T extends ProveedorFiltrable>(lista: readonly T[], consulta: string): T[] {
  const texto = normalizarTexto(consulta)
  if (texto === '') return [...lista].sort(porNombre)
  const nitBuscado = normalizarNit(consulta)

  const empiezan: T[] = []
  const contienen: T[] = []
  for (const p of lista) {
    const nombre = normalizarTexto(p.nombre)
    const nit = p.nit ? normalizarNit(p.nit) : ''
    const conNit = nitBuscado !== '' && nit !== ''
    if (nombre.startsWith(texto) || (conNit && nit.startsWith(nitBuscado))) empiezan.push(p)
    else if (nombre.includes(texto) || (conNit && nit.includes(nitBuscado))) contienen.push(p)
  }
  return [...empiezan.sort(porNombre), ...contienen.sort(porNombre)]
}
