import { useRef, useState } from 'react'

export default function ReservationPdfButton({ reference, query = '' }) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const pending = useRef(false)

  async function download() {
    if (pending.current) return
    pending.current = true
    setBusy(true)
    setError('')
    try {
      const response = await fetch(
        `/api/reservations/${reference}/pdf${query}`,
        {
          signal: AbortSignal.timeout(20000),
        },
      )
      if (!response.ok) {
        const result = await response.json()
        throw new Error(result.message || 'Le PDF est indisponible. Réessayez.')
      }
      if (
        !response.headers.get('Content-Type')?.startsWith('application/pdf')
      ) {
        throw new Error('Le PDF n’a pas pu être généré. Réessayez.')
      }
      const file = await response.blob()
      const url = URL.createObjectURL(file)
      const link = document.createElement('a')
      link.href = url
      link.download = `reservation-${reference}.pdf`
      document.body.appendChild(link)
      link.click()
      link.remove()
      setTimeout(() => URL.revokeObjectURL(url), 1000)
    } catch (failure) {
      setError(
        failure.name === 'TimeoutError' || failure instanceof TypeError
          ? 'Le téléchargement est indisponible. Réessayez.'
          : failure.message,
      )
    } finally {
      pending.current = false
      setBusy(false)
    }
  }

  return (
    <div className="reservation-pdf">
      <button className="secondary-button" disabled={busy} onClick={download}>
        {busy ? 'Préparation du PDF…' : 'Télécharger ma confirmation PDF'}
      </button>
      {error && (
        <p className="field-error" role="alert">
          {error}
        </p>
      )}
    </div>
  )
}
