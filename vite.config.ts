import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [react()],
  root: './assets',
  base: '/',
  build: {
    outDir: '../public/build',
    emptyOutDir: true,
    manifest: true,
    rollupOptions: {
      input: {
        app: './assets/js/app.tsx'
      }
    }
  },
  server: {
    port: 5173,
    strictPort: true,
    origin: 'http://localhost:5173'
  }
});
