import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import Confirmar from './Confirmar'

// S-94: un diálogo abierto desde dentro de un <form> no queda anidado en él ni dispara su onSubmit.
describe('S-94: Dialogo en un portal', () => {
  it('no queda dentro del <form> que lo contiene y su envío no sube al formulario de afuera', async () => {
    const usuario = userEvent.setup()
    const enviarAfuera = vi.fn((e: { preventDefault: () => void }) => e.preventDefault())
    const confirmar = vi.fn()
    render(
      <form data-testid="afuera" onSubmit={enviarAfuera}>
        <Confirmar titulo="¿Seguro?" texto="Prueba" accion="Sí" onConfirmar={confirmar} onCancelar={() => {}} />
      </form>,
    )

    const dialogo = screen.getByRole('alertdialog')
    expect(screen.getByTestId('afuera').contains(dialogo)).toBe(false)
    expect(document.body.contains(dialogo)).toBe(true)
    expect(dialogo.closest('form')).toBe(dialogo)

    await usuario.click(within(dialogo).getByRole('button', { name: 'Sí' }))
    expect(confirmar).toHaveBeenCalledTimes(1)
    expect(enviarAfuera).not.toHaveBeenCalled()
  })

  it('ningún diálogo de la página queda dentro de un <form> ajeno', () => {
    render(
      <form>
        <Confirmar titulo="Uno" texto="a" onConfirmar={() => {}} onCancelar={() => {}} />
      </form>,
    )
    for (const d of document.querySelectorAll('[data-dialogo]')) {
      const padreForm = d.parentElement?.closest('form')
      expect(padreForm).toBeNull()
    }
  })
})
