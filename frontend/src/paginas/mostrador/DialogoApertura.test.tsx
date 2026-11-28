import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { describe, expect, it, vi } from 'vitest'
import type { AperturaCaja } from '../../api/tipos'
import { DialogoApertura } from './CierreDia'

// S-84: el motivo de la apertura es siempre obligatorio en la pantalla (el servidor también lo valida).
function abrir(apertura: AperturaCaja | null) {
  render(
    <QueryClientProvider client={new QueryClient()}>
      <DialogoApertura fecha="2026-11-26" apertura={apertura} onCerrar={vi.fn()} onGuardada={vi.fn()} />
    </QueryClientProvider>,
  )
  return { usuario: userEvent.setup(), guardar: screen.getByRole('button', { name: 'Guardar apertura' }) as HTMLButtonElement }
}

const arrastre = (extra: Partial<AperturaCaja> = {}): AperturaCaja => ({
  valor: '650000.00', origen: 'arrastre', origen_usado: 'arrastre', desde_cierre: '2026-11-25', cierre_anterior_valor: '650000.00',
  motivo: null, fijado_por: null, fijado_at: null, hay_dias_sin_cerrar: false, ...extra,
})

describe('S-84: el motivo de la apertura es siempre obligatorio', () => {
  it('igual al arrastre: Guardar sigue deshabilitado hasta tener un motivo de 3 caracteres', async () => {
    const { usuario, guardar } = abrir(arrastre())
    expect(guardar.disabled).toBe(true)
    await usuario.type(screen.getByLabelText(/Motivo/), 'ab')
    expect(guardar.disabled).toBe(true)
    await usuario.type(screen.getByLabelText(/Motivo/), 'c')
    expect(guardar.disabled).toBe(false)
  })

  it('distinto del arrastre: el botón se habilita solo con un motivo de 3 caracteres o más', async () => {
    const { usuario, guardar } = abrir(arrastre())
    const valor = screen.getByLabelText(/Efectivo de apertura/)
    await usuario.clear(valor)
    await usuario.type(valor, '600000')

    expect(guardar.disabled).toBe(true)
    expect(screen.getByText('Falta el motivo')).toBeTruthy()
    await usuario.type(screen.getByLabelText(/Motivo/), 'Re')
    expect(guardar.disabled).toBe(true)
    await usuario.type(screen.getByLabelText(/Motivo/), 't')
    expect(guardar.disabled).toBe(false)
  })

  it('con un cambio manual vigente, volver al arrastre también exige motivo', async () => {
    const { usuario, guardar } = abrir(arrastre({ valor: '600000.00', origen: 'manual', motivo: 'Se retiró dinero' }))
    const motivo = screen.getByLabelText(/Motivo/)
    await usuario.clear(motivo)
    await usuario.click(screen.getByRole('button', { name: /Usar la del cierre anterior/ }))
    expect(guardar.disabled).toBe(true)
    await usuario.type(motivo, 'Se devolvió')
    expect(guardar.disabled).toBe(false)
  })

  it('el primer día sin cierres el motivo también es obligatorio', async () => {
    const { usuario, guardar } = abrir(null)
    await usuario.type(screen.getByLabelText(/Efectivo de apertura/), '50000')
    expect(guardar.disabled).toBe(true)
    await usuario.type(screen.getByLabelText(/Motivo/), 'Primer día')
    expect(guardar.disabled).toBe(false)
  })
})
