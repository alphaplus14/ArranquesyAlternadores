import { useQuery } from '@tanstack/react-query'
import { api } from '../api/cliente'
import type { Categoria, Proveedor } from '../api/tipos'

// Catálogos pequeños y estables: se cachean 5 minutos.
const cincoMinutos = 5 * 60 * 1000

export const useProveedores = () =>
  useQuery({ queryKey: ['proveedores'], queryFn: async () => (await api.get<Proveedor[]>('/proveedores')).data, staleTime: cincoMinutos })

export const useCategorias = () =>
  useQuery({ queryKey: ['categorias'], queryFn: async () => (await api.get<Categoria[]>('/categorias')).data, staleTime: cincoMinutos })
