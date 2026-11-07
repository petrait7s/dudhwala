import { defineConfig } from 'astro/config';

export default defineConfig({
  output: 'static',
  site: process.env.PUBLIC_SITE_URL || 'https://dudhwala.vercel.app',
  trailingSlash: 'never',
  build: { format: 'file', inlineStylesheets: 'auto' },
  devToolbar: { enabled: false },
});
