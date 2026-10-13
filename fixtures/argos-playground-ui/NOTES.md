# Todo frontend fixture

A minimal Nuxt 4 app with Tailwind CSS 4 and Nuxt UI.
Add, rename, complete/uncomplete, and delete todos. No authentication or persistence.

## Run locally

Requires Node.js 22 or newer and pnpm.

1. In ../argos-playground, run npm start (API on port 3000).
2. In this directory, run pnpm install --ignore-workspace.
3. Run pnpm dev and open http://localhost:3001.

The Nuxt server proxies /api/todos requests to the API so no CORS setup is needed.
Set NUXT_TODO_API_URL to use a different API origin (default http://127.0.0.1:3000).

- pnpm check: Vue and TypeScript checks.
- pnpm build: build the frontend and its server proxy.
- pnpm preview: preview the build on port 3001 (keep the API running).

This fixture is intentionally outside the root pnpm workspace. It can be uploaded
as a separate Artifacts repository named argos-playground-ui in namespace daedalus.
