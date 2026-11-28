import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { ReactNode } from 'react'
import { describe, expect, it, vi } from 'vitest'
import type { ResumenCaja } from '../../api/tipos'
import { DesgloseEfectivo, FormularioCierre } from './CierreDia'

// S-101 (RF-25): «Ver detalle» y «Agregar observación» son botones; la observación se puede quitar.

function conConsultas(hijo: ReactNode) {
  return <QueryClientProvider client={new QueryClient()}>{hijo}</QueryClientProvider>
}

const resumen = {
  efectivo: {
    neto: '100000', ventas: '120000', recaudo: '0', otros_ingresos: '0', ingresos_externos: '0', abonos_empleados: '0',
    devoluciones: '0', pagos_proveedores: '0', egresos: '20000', prestamos_empleados: '0',
  },
  apertura: { valor: '50000' },
  efectivo_esperado: '150000',
  base_caja: '135000',
  efectivo_en_cajon_esperado: '285000',
} as unknown as ResumenCaja

function formulario() {
  render(conConsultas(<FormularioCierre fecha="2026-11-27" esperado="150000" base="135000" bloqueado={false} onCerrado={vi.fn()} />))
  return userEvent.setup()
}

describe('S-101: «Ver detalle»', () => {
  it('es un botón que alterna el desglose, el texto y aria-expanded', async () => {
    const usuario = userEvent.setup()
    render(<DesgloseEfectivo resumen={resumen} />)
    const boton = screen.getByRole('button', { name: 'Ver detalle' })

    expect(boton.getAttribute('aria-expanded')).toBe('false')
    expect(boton.getAttribute('aria-controls')).toBe('caja-detalle-efectivo')
    expect(boton.className).toContain('secundario')
    expect(document.getElementById('caja-detalle-efectivo')).toBeNull()

    await usuario.click(boton)
    expect(boton.getAttribute('aria-expanded')).toBe('true')
    expect(boton.textContent).toBe('Ocultar detalle')
    expect(document.getElementById('caja-detalle-efectivo')).not.toBeNull()

    await usuario.click(boton)
    expect(boton.textContent).toBe('Ver detalle')
    expect(document.getElementById('caja-detalle-efectivo')).toBeNull()
  })
})

describe('S-101: observación del cierre', () => {
  it('«Agregar observación» abre el campo con el foco', async () => {
    const usuario = formulario()
    await usuario.click(screen.getByRole('button', { name: 'Agregar observación' }))

    expect(document.activeElement).toBe(screen.getByLabelText(/Observación/))
    expect(screen.getByRole('button', { name: 'Quitar la observación' })).toBeTruthy()
  })

  it('«Quitar» vacía el texto, cierra el campo y devuelve el foco a «Agregar observación»', async () => {
    const usuario = formulario()
    await usuario.click(screen.getByRole('button', { name: 'Agregar observación' }))
    await usuario.type(screen.getByLabelText(/Observación/), 'Se cayó una moneda')

    await usuario.click(screen.getByRole('button', { name: 'Quitar la observación' }))

    expect(screen.queryByLabelText(/Observación/)).toBeNull()
    const agregar = await screen.findByRole('button', { name: 'Agregar observación' })
    await vi.waitFor(() => expect(document.activeElement).toBe(agregar))
    // Al volver a abrirla, empieza vacía.
    await usuario.click(agregar)
    expect((screen.getByLabelText(/Observación/) as HTMLInputElement).value).toBe('')
  })

  it('Escape cierra el campo vacío y también el que tiene texto', async () => {
    const usuario = formulario()
    await usuario.click(screen.getByRole('button', { name: 'Agregar observación' }))
    await usuario.keyboard('{Escape}')
    expect(screen.queryByLabelText(/Observación/)).toBeNull()

    await usuario.click(screen.getByRole('button', { name: 'Agregar observación' }))
    await usuario.type(screen.getByLabelText(/Observación/), 'Nota')
    await usuario.keyboard('{Escape}')
    expect(screen.queryByLabelText(/Observación/)).toBeNull()
  })

  it('con la base faltante la observación es obligatoria: sin «Quitar» y Escape no la cierra', async () => {
    const usuario = formulario()
    await usuario.type(screen.getByLabelText(/Efectivo contado/), '100000') // menos que la base de 135.000

    const campo = screen.getByLabelText(/Observación/)
    expect(screen.queryByRole('button', { name: 'Quitar la observación' })).toBeNull()
    expect(screen.getByText('Obligatoria: falta la base de caja')).toBeTruthy()
    await usuario.type(campo, 'Faltó la base')
    await usuario.keyboard('{Escape}')
    expect(screen.getByLabelText(/Observación/)).toBeTruthy()

    // Al subir el contado ya no falta la base: el campo sigue con su texto y ya se puede quitar.
    const contado = screen.getByLabelText(/Efectivo contado/)
    await usuario.clear(contado)
    await usuario.type(contado, '285000')
    expect((screen.getByLabelText(/Observación/) as HTMLInputElement).value).toBe('Faltó la base')
    expect(screen.getByRole('button', { name: 'Quitar la observación' })).toBeTruthy()
  })
})
