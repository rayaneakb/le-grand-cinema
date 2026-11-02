import { useEffect, useId, useRef } from 'react'

export default function Modal({ title, onClose, busy = false, children }) {
  const dialog = useRef(null)
  const titleId = useId()

  useEffect(() => {
    const element = dialog.current
    const previousFocus = document.activeElement
    const previousOverflow = document.body.style.overflow
    element.showModal()
    document.body.style.overflow = 'hidden'
    return () => {
      element.close()
      document.body.style.overflow = previousOverflow
      previousFocus?.focus()
    }
  }, [])

  return (
    <dialog
      ref={dialog}
      className="modal"
      aria-labelledby={titleId}
      onCancel={(event) => {
        event.preventDefault()
        if (!busy) onClose()
      }}
    >
      <div className="modal-header">
        <h2 id={titleId}>{title}</h2>
        <button
          type="button"
          className="secondary-button"
          disabled={busy}
          onClick={onClose}
          autoFocus
        >
          Fermer
        </button>
      </div>
      {children}
    </dialog>
  )
}
