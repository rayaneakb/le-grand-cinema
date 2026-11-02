import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

export default defineConfig({
  plugins: [react()],
  server: {
    proxy: {
      '/api': process.env.CINEMA_API_TARGET || 'http://127.0.0.1:8000',
      '/demo': process.env.CINEMA_API_TARGET || 'http://127.0.0.1:8000',
    },
  },
})
