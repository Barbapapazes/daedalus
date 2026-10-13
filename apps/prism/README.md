# Prism

Client-only Nuxt app with Nuxt UI, Pinia, and Pinia Colada. Server-side rendering is disabled (`ssr: false`).

Run commands from the repository root:

```sh
pnpm install
pnpm dev:prism
pnpm --filter @daedalus/prism check
pnpm --filter @daedalus/prism build
```

## Argos chat

Open `/argos` (or follow the link from the home page). The page uses the vanilla `AgentClient` from `agents/client` to call Argos over WebSocket. The raw persisted transcript is displayed as JSON in a single `<pre>`; responses appear when the prompt completes. A chat ID is kept in browser local storage so reloads reuse the same conversation.

Run `pnpm dev` for Argos and `pnpm dev:prism` in another terminal. The Worker host defaults to `http://localhost:5173`. Set `NUXT_PUBLIC_ARGOS_HOST` to your Worker host for another environment, for example `https://argos.example.workers.dev`. For static hosting, set it before generating the app.

There is no user authentication yet: do not expose the Worker publicly without access controls and Gateway budget/rate limits.

For static hosting, run `pnpm --filter @daedalus/prism generate` and deploy `apps/prism/.output/public`. Configure your host to fall back to `index.html` for client-side routes.

Nuxt UI is registered as a Nuxt module, with its styles in `app/assets/css/main.css` and its application provider in `app/app.vue`. Pinia is registered through `@pinia/nuxt`; `app/plugins/colada.ts` installs Pinia Colada. Import `useQuery` and `useMutation` from `@pinia/colada` when adding data fetching.
