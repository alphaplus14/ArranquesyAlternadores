import { Component, type ErrorInfo, type ReactNode } from 'react'

/**
 * Si una pantalla falla al pintarse, muestra un aviso con "Recargar" en lugar de dejar todo en
 * blanco. El menú y la barra quedan. Al cambiar de ruta (`clave`) se vuelve a intentar.
 */
export default class ErrorPantalla extends Component<{ clave: string; children: ReactNode }, { error: boolean; clave: string }> {
  state = { error: false, clave: this.props.clave }

  static getDerivedStateFromError() {
    return { error: true }
  }

  static getDerivedStateFromProps(props: { clave: string }, state: { error: boolean; clave: string }) {
    return props.clave !== state.clave ? { error: false, clave: props.clave } : null
  }

  componentDidCatch(error: unknown, info: ErrorInfo) {
    console.error('Error en la pantalla', error, info.componentStack)
  }

  render() {
    if (!this.state.error) return this.props.children
    return (
      <section className="tarjeta-pagina" role="alert">
        <p className="aviso error">Ocurrió un error en esta pantalla. Recargue la página o avise al administrador del sistema.</p>
        <button type="button" className="primario boton-44" onClick={() => window.location.reload()}>Recargar</button>
      </section>
    )
  }
}
