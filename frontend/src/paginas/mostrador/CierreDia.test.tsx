import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { AxiosError, AxiosHeaders } from 'axios'
import type { ReactNode } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'

// S-94 (RF-25): el cierre del día se envía una sola vez y el diálogo no queda dentro del <form>.
const post = vi.hoisted(() => vi.fn())
vi.mock('../../api/cliente', async (original) => {
  const real = await original<typeof import('../../api/cliente')>()
  return { ...real, api: { ...real.api, post } }
})

import { FormularioCierre } from './CierreDia'

function conConsultas(hijo: ReactNode) {
  const qc = new QueryClient({ defaultOptions: { mutations: { retry: false }, queries: { retry: false } } })
  return <QueryClientProvider client={qc}>{hijo}</QueryClientProvider>
}

function error422(mensaje: string) {
  const respuesta = { status: 422, statusText: 'Unprocessable', headers: {}, config: { headers: new AxiosHeaders() }, data: { message: mensaje } }
  return new AxiosError(mensaje, 'ERR_BAD_REQUEST', respuesta.config, {}, respuesta)
}

async function abrirConfirmacion() {
  const usuario = userEvent.setup()
  const onCerrado = vi.fn()
  render(conConsultas(<FormularioCierre fecha="2026-11-26" esperado="570000" bloqueado={false} onCerrado={onCerrado} />))
  await usuario.type(screen.getByLabelText(/Efectivo contado/), '570000')
  const abrir = screen.getByRole('button', { name: 'Cerrar día' })
  await usuario.click(abrir)
  const dialogo = await screen.findByRole('alertdialog')
  return { usuario, onCerrado, abrir, dialogo }
}

describe('S-94: cierre de caja (FormularioCierre)', () => {
  beforeEach(() => { post.mockClear(); post.mockImplementation(() => Promise.resolve({ data: {} })) })

  it('confirmar envía POST /caja/cierres una sola vez y cierra el diálogo', async () => {
    post.mockResolvedValue({ data: { id: 1 } })
    const { usuario, onCerrado, dialogo } = await abrirConfirmacion()

    await usuario.click(within(dialogo).getByRole('button', { name: 'Cerrar día' }))

    await waitFor(() => expect(onCerrado).toHaveBeenCalledTimes(1))
    expect(post).toHaveBeenCalledTimes(1)
    expect(post).toHaveBeenCalledWith('/caja/cierres', { fecha: '2026-11-26', efectivo_contado: 570000, observacion: null })
    expect(screen.queryByRole('alertdialog')).toBeNull()
  })

  it('con un 422 el diálogo muestra el error y sigue abierto', async () => {
    post.mockImplementation(() => Promise.reject(error422('Hay ventas abiertas en el día.')))
    const { usuario, onCerrado, dialogo } = await abrirConfirmacion()

    await usuario.click(within(dialogo).getByRole('button', { name: 'Cerrar día' }))

    expect((await within(dialogo).findByRole('alert')).textContent).toBe('Hay ventas abiertas en el día.')
    expect(screen.getByRole('alertdialog')).toBe(dialogo)
    expect(post).toHaveBeenCalledTimes(1)
    expect(onCerrado).not.toHaveBeenCalled()
  })

  it('Cancelar cierra sin enviar y devuelve el foco al botón que abrió el diálogo', async () => {
    const { usuario, abrir, dialogo } = await abrirConfirmacion()

    await usuario.click(within(dialogo).getByRole('button', { name: 'Cancelar' }))

    expect(screen.queryByRole('alertdialog')).toBeNull()
    expect(post).not.toHaveBeenCalled()
    expect(document.activeElement).toBe(abrir)
  })

  it('Escape cierra sin enviar y devuelve el foco al botón que abrió el diálogo', async () => {
    const { usuario, abrir } = await abrirConfirmacion()

    await usuario.keyboard('{Escape}')

    await waitFor(() => expect(screen.queryByRole('alertdialog')).toBeNull())
    expect(post).not.toHaveBeenCalled()
    expect(document.activeElement).toBe(abrir)
  })

  it('el diálogo cuelga de document.body, fuera del formulario del cierre', async () => {
    const { dialogo } = await abrirConfirmacion()

    expect(dialogo.parentElement?.parentElement).toBe(document.body)
    expect(document.querySelector('form.caja-form-cierre')?.contains(dialogo)).toBe(false)
  })

  it('S-98: con base, la ayuda resta la base; si falta la base exige la observación antes de confirmar', async () => {
    const usuario = userEvent.setup()
    post.mockImplementation(() => Promise.resolve({ data: { id: 1 } }))
    render(conConsultas(<FormularioCierre fecha="2026-11-26" esperado="650000" base="135000" bloqueado={false} onCerrado={vi.fn()} />))
    const campo = screen.getByLabelText(/Efectivo contado \(todo el cajón/)
    expect(campo.getAttribute('placeholder')).toBe('Ej: 785.000')

    await usuario.type(campo, '785000')
    expect(screen.getByText(/Base \$\s135\.000 → efectivo para el cuadre \$\s650\.000\. Cuadra/)).toBeTruthy()

    await usuario.clear(campo)
    await usuario.type(campo, '100000')
    expect(screen.getByRole('alert').textContent).toContain('Falta la base')
    const cerrar = screen.getByRole('button', { name: 'Cerrar día' }) as HTMLButtonElement
    expect(cerrar.disabled).toBe(true)
    await usuario.type(screen.getByLabelText(/Observación/), 'Se llevaron la base')
    expect(cerrar.disabled).toBe(false)
  })
})

