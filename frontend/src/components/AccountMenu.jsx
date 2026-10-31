import { useEffect, useRef, useState } from 'react'
import useLogout from '../hooks/useLogout'

export default function AccountMenu({ session }) {
  const [open, setOpen] = useState(false)
  const container = useRef(null)
  const trigger = useRef(null)
  const { logout, busy, error } = useLogout(session.logoutToken)

  useEffect(() => {
    if (!open) return

    function closeOutside(event) {
      if (!container.current?.contains(event.target)) setOpen(false)
    }

    function closeWithEscape(event) {
      if (event.key === 'Escape') {
        setOpen(false)
        trigger.current?.focus()
      }
    }

    function closeOnFocusOutside(event) {
      if (!container.current?.contains(event.target)) setOpen(false)
    }

    document.addEventListener('pointerdown', closeOutside)
    document.addEventListener('keydown', closeWithEscape)
    document.addEventListener('focusin', closeOnFocusOutside)
    return () => {
      document.removeEventListener('pointerdown', closeOutside)
      document.removeEventListener('keydown', closeWithEscape)
      document.removeEventListener('focusin', closeOnFocusOutside)
    }
  }, [open])

  return (
    <div className="account-menu" ref={container}>
      <button
        ref={trigger}
        className="account-menu-trigger"
        aria-expanded={open}
        aria-controls="account-menu-options"
        disabled={session.loading}
        onClick={() => setOpen((current) => !current)}
      >
        Mon compte <span aria-hidden="true">▾</span>
      </button>
      {open && (
        <div id="account-menu-options" className="account-menu-options">
          {session.user ? (
            <>
              <a href="/compte">Voir mon compte</a>
              <button onClick={logout} disabled={busy}>
                {busy ? 'Déconnexion en cours…' : 'Me déconnecter'}
              </button>
              {error && (
                <p role="alert" className="field-error">
                  {error}
                </p>
              )}
            </>
          ) : (
            <>
              <a href="/connexion">Se connecter</a>
              <a href="/inscription">Créer un compte</a>
            </>
          )}
        </div>
      )}
    </div>
  )
}
