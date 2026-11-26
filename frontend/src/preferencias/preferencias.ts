import { useSyncExternalStore } from 'react'
import { api } from '../api/cliente'
import type { Rol } from '../api/tipos'
import paletas from './paletas.json'

/**
 * S-70: preferencias de pantalla por usuario (admin, logística y contable). Único módulo que las
 * aplica:
 * - tamaño de letra, densidad y color se escriben como variables CSS en <html>: cambiarlas NO
 *   re-renderiza la app (las reglas de index.css leen las variables);
 * - filas por página y menú plegado los leen solo sus consumidores con useSyncExternalStore.
 * Sin preferencias guardadas no se escribe nada: la app queda exactamente como antes (index.css).
 */

export type TamanoLetra = 'normal' | 'grande' | 'muy_grande'
export type Densidad = 'comoda' | 'compacta'
export type Acento = keyof typeof paletas
export type FilasPorPagina = 25 | 50 | 100

export interface Preferencias {
  tamano_letra?: TamanoLetra
  densidad?: Densidad
  acento?: Acento
  filas_por_pagina?: FilasPorPagina
  menu_plegado?: boolean
}
export type ClavePreferencia = keyof Preferencias

export const CLAVES: ClavePreferencia[] = ['tamano_letra', 'densidad', 'acento', 'filas_por_pagina', 'menu_plegado']
export const TAMANOS: TamanoLetra[] = ['normal', 'grande', 'muy_grande']
export const DENSIDADES: Densidad[] = ['comoda', 'compacta']
export const ACENTOS = Object.keys(paletas) as Acento[]
export const FILAS: FilasPorPagina[] = [25, 50, 100]
export const PALETAS = paletas

const ROLES_CON_PREFERENCIAS: Rol[] = ['admin', 'logistica', 'contable']

/** El mostrador no tiene preferencias: su pantalla se ve igual para todos. */
export function admitePreferencias(rol: Rol | null | undefined): boolean {
  return !!rol && ROLES_CON_PREFERENCIAS.includes(rol)
}

/** Valores por defecto del rol (los de hoy). filas_por_pagina no tiene: cada listado conserva el suyo. */
export function porDefecto(rol: Rol | null): Required<Omit<Preferencias, 'filas_por_pagina'>> {
  return { tamano_letra: 'normal', densidad: 'comoda', acento: 'azul', menu_plegado: rol === 'admin' }
}

/* ───────────── Variables CSS ───────────── */

const ESCALA: Record<TamanoLetra, string | null> = { normal: null, grande: '1.125', muy_grande: '1.25' }
// Compacta reduce ~25-30 % el relleno vertical de filas, campos y tarjetas (cómoda = valores de index.css).
const COMPACTA = { '--espacio-fila': '8px', '--espacio-campo': '6px', '--espacio-tarjeta': '13px' }

function poner(nombre: string, valor: string | null) {
  const estilo = document.documentElement.style
  if (valor === null) estilo.removeProperty(nombre)
  else estilo.setProperty(nombre, valor)
}

/** Escribe (o quita, si es el valor por defecto) las variables de tamaño, densidad y color. */
function aplicarVariables(p: Preferencias) {
  poner('--escala-letra', ESCALA[p.tamano_letra ?? 'normal'])
  for (const [nombre, valor] of Object.entries(COMPACTA)) poner(nombre, p.densidad === 'compacta' ? valor : null)
  const paleta = p.acento && p.acento !== 'azul' ? paletas[p.acento] : null
  poner('--acento', paleta?.acento ?? null)
  poner('--acento-hover', paleta?.hover ?? null)
  poner('--acento-suave', paleta?.suave ?? null)
  poner('--acento-suave-hover', paleta ? `color-mix(in srgb, ${paleta.suave} 85%, ${paleta.acento})` : null)
  poner('--acento-texto-sobre-acento', paleta?.texto ?? null)
}

/* ───────────── Store ───────────── */

let rolActual: Rol | null = null
let guardadas: Preferencias = {}
const oyentes = new Set<() => void>()

function notificar() {
  oyentes.forEach((fn) => fn())
}

function suscribir(fn: () => void) {
  oyentes.add(fn)
  return () => { oyentes.delete(fn) }
}

/** Deja solo claves y valores conocidos (lo que llegue del backend). */
function limpiar(p: unknown): Preferencias {
  if (!p || typeof p !== 'object') return {}
  const o = p as Record<string, unknown>
  const r: Preferencias = {}
  if (TAMANOS.includes(o.tamano_letra as TamanoLetra)) r.tamano_letra = o.tamano_letra as TamanoLetra
  if (DENSIDADES.includes(o.densidad as Densidad)) r.densidad = o.densidad as Densidad
  if (ACENTOS.includes(o.acento as Acento)) r.acento = o.acento as Acento
  if (FILAS.includes(o.filas_por_pagina as FilasPorPagina)) r.filas_por_pagina = o.filas_por_pagina as FilasPorPagina
  if (typeof o.menu_plegado === 'boolean') r.menu_plegado = o.menu_plegado
  return r
}

/** Al entrar (/me o login) y al salir. Llega en la misma respuesta de /me: sin peticiones nuevas. */
export function iniciarPreferencias(rol: Rol | null, preferencias?: unknown) {
  rolActual = rol
  guardadas = admitePreferencias(rol) ? limpiar(preferencias) : {}
  aplicarVariables(guardadas)
  notificar()
}

export function preferenciasGuardadas(): Preferencias {
  return guardadas
}

/** Valor efectivo de cada clave: lo guardado o el defecto del rol. */
export function efectivas(base: Preferencias = guardadas): Required<Omit<Preferencias, 'filas_por_pagina'>> & Pick<Preferencias, 'filas_por_pagina'> {
  return { ...porDefecto(rolActual), ...base }
}

/** Vista previa en vivo: solo variables CSS (sin re-render, sin tocar lo guardado). */
export function vistaPrevia(p: Preferencias) {
  aplicarVariables(p)
}

/** Vuelve a lo guardado (cerrar o cancelar la modal, o un error al guardar). */
export function revertirVistaPrevia() {
  aplicarVariables(guardadas)
}

/** UN PATCH con solo lo que cambió (null borra la clave). Actualiza el store con la respuesta. */
export async function guardarPreferencias(cambios: Partial<Record<ClavePreferencia, Preferencias[ClavePreferencia] | null>>) {
  const r = await api.patch<{ preferencias: unknown }>('/me/preferencias', cambios)
  guardadas = limpiar(r.data.preferencias)
  aplicarVariables(guardadas)
  notificar()
}

/* ───────────── Consumidores ───────────── */

/**
 * Filas por página de un listado: la preferencia guardada, o el valor de siempre del listado si no
 * hay. Limitada al tope del endpoint.
 */
export function useFilasPorPagina(defecto: number, tope = 100): number {
  const preferida = useSyncExternalStore(suscribir, () => guardadas.filas_por_pagina)
  return Math.min(preferida ?? defecto, tope)
}

/** Menú lateral con grupos plegados por defecto (admin: sí; logística y contable: no). */
export function useMenuPlegado(): boolean {
  return useSyncExternalStore(suscribir, () => (admitePreferencias(rolActual) ? (guardadas.menu_plegado ?? rolActual === 'admin') : false))
}
