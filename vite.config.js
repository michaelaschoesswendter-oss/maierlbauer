import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

const feedUrl = 'https://ical.deskline.net/SBG/services/e92719a9-bddc-4e25-a2b2-6be3c85a9a12/a6f3f579-2a54-4267-b7aa-30f7894b3c46.ics';

export default defineConfig({
  plugins: [react()],
  server: {
    proxy: {
      '/api/availability': {
        target: 'https://ical.deskline.net',
        changeOrigin: true,
        rewrite: () => new URL(feedUrl).pathname,
      },
    },
  },
});
