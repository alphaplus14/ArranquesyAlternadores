import { useQuery } from '@tanstack/react-query'
import { api } from '../api/cliente'
import type { Categoria, CategoriaCaja, CuentaPago, Propietario, Proveedor } from '../api/tipos'

// Catálogos pequeños y estables: se cachean 5 minutos.
const cincoMinutos = 5 * 60 * 1000

export const useProveedores = () =>
  useQuery({ queryKey: ['proveedores'], queryFn: async () => (await api.get<Proveedor[]>('/proveedores')).data, staleTime: cincoMinutos })

export const useCategorias = () =>
  useQuery({ queryKey: ['categorias'], queryFn: async () => (await api.get<Categoria[]>('/categorias')).data, staleTime: cincoMinutos })

/** RF-24 (S-48): cuentas de pago activas (mostrador y admin). El admin pide todas para gestionarlas. */
export const useCuentasPago = (incluirInactivas = false) =>
  useQuery({
    queryKey: ['cuentas-pago', incluirInactivas],
    queryFn: async () => (await api.get<{ data: CuentaPago[] }>('/cuentas-pago', { params: incluirInactivas ? { incluir_inactivas: 1 } : {} })).data.data,
    staleTime: cincoMinutos,
  })

/** S-88: dueños de billetera (activos; el admin, con inactivos para asignarlos). */
export const usePropietarios = (incluirInactivas = false) =>
  useQuery({
    queryKey: ['propietarios', incluirInactivas],
    queryFn: async () => (await api.get<{ data: Propietario[] }>('/propietarios', { params: incluirInactivas ? { incluir_inactivas: 1 } : {} })).data.data,
    staleTime: cincoMinutos,
  })

/** RF-26: categorías de caja (activas; el admin, con inactivas para gestionarlas). */
export const useCategoriasCaja = (incluirInactivas = false) =>
  useQuery({
    queryKey: ['caja-categorias', incluirInactivas],
    queryFn: async () => (await api.get<{ data: CategoriaCaja[] }>('/caja/categorias', { params: incluirInactivas ? { incluir_inactivas: 1 } : {} })).data.data,
    staleTime: cincoMinutos,
  })
