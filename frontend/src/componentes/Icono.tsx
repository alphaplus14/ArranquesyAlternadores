import type { ReactNode } from 'react'

export type IconoNombre =
  | 'menu'
  | 'caja'
  | 'venta'
  | 'ingreso'
  | 'etiqueta'
  | 'universal'
  | 'catalogo'
  | 'usuarios'
  | 'auditoria'
  | 'agotados'
  | 'buscar'
  | 'persona'
  | 'salir'
  | 'ojo'
  | 'ojo-cerrado'
  | 'editar'
  | 'activar'
  | 'desactivar'
  | 'clave'
  | 'chevron'
  | 'prohibido'
  | 'configuracion'
  | 'inventario'
  | 'reactivar'
  | 'billetera'
  | 'agrandar'
  | 'achicar'
  | 'panel'
  | 'caja-diaria'
  | 'abrir'
  | 'quitar'
  | 'salida'
  | 'entrada'
  | 'devolucion'
  | 'historial'
  | 'producto'
  | 'mas'

/** Trazos propios. No hay fuente de iconos ni librería de UI. */
export default function Icono({ nombre }: { nombre: IconoNombre }) {
  return (
    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round" aria-hidden>
      {trazos[nombre]}
    </svg>
  )
}

const trazos: Record<IconoNombre, ReactNode> = {
  menu: <path d="M4 7h16M4 12h16M4 17h16" />,
  caja: <path d="M3 8l9-4 9 4-9 4-9-4zm0 0v8l9 4 9-4V8M12 12v8" />,
  venta: <path d="M5 6h2l1.4 8.2a1 1 0 0 0 1 .8h7.8a1 1 0 0 0 1-.8L20 8H7M9 20h.01M17 20h.01" />,
  ingreso: <path d="M8 4h8a1 1 0 0 1 1 1v15l-5-2.5L7 20V5a1 1 0 0 1 1-1z" />,
  etiqueta: <path d="M4 12V5h7l9 9-7 7-9-9zM8.5 8.5h.01" />,
  universal: <path d="M9 8H6a3 3 0 0 0 0 6h3M15 8h3a3 3 0 0 1 0 6h-3M8 12h8" />,
  catalogo: <path d="M5 5.5A2.5 2.5 0 0 1 7.5 3H20v16H7.5A2.5 2.5 0 0 0 5 21.5zM5 5.5V21" />,
  usuarios: <path d="M16 19v-1.2A3.8 3.8 0 0 0 12.2 14H7.8A3.8 3.8 0 0 0 4 17.8V19M10 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM20 19v-1.2A3.8 3.8 0 0 0 17 14.2M16 5.1a3 3 0 0 1 0 5.8" />,
  auditoria: <path d="M8 6h11M8 12h11M8 18h11M4 6h.01M4 12h.01M4 18h.01" />,
  agotados: <path d="M12 4l8 14H4L12 4zM12 10v4M12 16.5h.01" />,
  panel: <path d="M4 20h16M6 16v-4M10 16V8M14 16v-6M18 16V5" />, // RF-23
  'caja-diaria': <path d="M4 10h16v9a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1v-9zM6 10V5h8v5M14 7h4l2 3M8 14h3M14.5 14h.01M17 14h.01M8 17h8" />, // RF-25
  buscar: <path d="M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14zM20 20l-3.5-3.5" />,
  persona: <path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4.5 20a7.5 7.5 0 0 1 15 0" />,
  salir: <path d="M15 4h3a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1h-3M10 16l-4-4 4-4M6 12h10" />,
  ojo: <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12zm10 3a3 3 0 1 0 0-6 3 3 0 0 0 0 6z" />,
  'ojo-cerrado': <path d="M3 3l18 18M10.6 6.1A10.4 10.4 0 0 1 12 5.8c6.6 0 10 6.2 10 6.2a17 17 0 0 1-3.1 3.6M6.3 6.4C3.9 8.2 2 12 2 12s3.4 6.2 10 6.2a10 10 0 0 0 3.8-.8" />,
  editar: <path d="M11 4H4a1 1 0 0 0-1 1v15a1 1 0 0 0 1 1h15a1 1 0 0 0 1-1v-7M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" />,
  activar: <path d="M5 12.5l5 5L19.5 7" />,
  desactivar: <path d="M12 20a8 8 0 1 0 0-16 8 8 0 0 0 0 16zM7.2 7.2l9.6 9.6" />,
  clave: <g><circle cx="7.5" cy="15.5" r="3.5" /><path d="M10.6 13.4L21 3M16 8l2 2M19 5l2 2" /></g>,
  chevron: <path d="M6 9l6 6 6-6" />,
  configuracion: <path d="M4 7h10M18 7h2M4 17h4M12 17h8M16 5v4M10 15v4" />,
  inventario: <path d="M9 3.5h6v3H9zM9 5H6a1 1 0 0 0-1 1v14a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V6a1 1 0 0 0-1-1h-3M8.5 12h7M8.5 16h4.5" />,
  prohibido: <g><circle cx="12" cy="12" r="9" /><path d="M5.6 5.6l12.8 12.8" /></g>,
  billetera: <path d="M4 7.5A2.5 2.5 0 0 1 6.5 5H18v3M4 7.5V17a2 2 0 0 0 2 2h14V9H6.5A2.5 2.5 0 0 1 4 7.5zM16.5 14h.01" />,
  reactivar: <path d="M4 12a8 8 0 0 1 13.7-5.6L20 8.7M20 4v4.7h-4.7M20 12a8 8 0 0 1-13.7 5.6L4 15.3M4 20v-4.7h4.7" />,
  agrandar: <path d="M15 3h6v6M21 3l-7 7M3 21l7-7M9 21H3v-6" />,
  achicar: <path d="M14 10l7-7M20 10h-6V4M4 20l7-7M10 14H4v6" />,
  abrir: <path d="M9 6l6 6-6 6" />, // S-78: abrir el registro
  quitar: <path d="M6 6l12 12M18 6L6 18" />,
  mas: <path d="M12 5v14M5 12h14" />, // S-101: agregar
  salida: <g><path d="M9 6H5a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h4" /><path d="M13 8l4 4-4 4M8 12h9" /></g>,
  entrada: <g><path d="M15 6h4a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1h-4" /><path d="M11 8l-4 4 4 4M16 12H7" /></g>,
  devolucion: <g><path d="M9 14L4 9l5-5" /><path d="M4 9h10.5a5.5 5.5 0 1 1 0 11H12" /></g>,
  historial: <g><circle cx="12" cy="12" r="8" /><path d="M12 8v4.5l2.5 1.5" /></g>,
  producto: <g><path d="M12 3.5 20 8v8l-8 4.5L4 16V8z" /><path d="M12 12.2 20 8M12 12.2V20.5M12 12.2 4 8" /></g>,
}
