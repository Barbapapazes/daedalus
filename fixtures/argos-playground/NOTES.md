# Todo API fixture

A dependency-free Node.js todo list API for Argos repository demos.
No authentication, persistence, or user isolation. Restarting resets the list.

## Run locally

Requires Node.js 22 or newer. No installation is needed.

- `npm start`: run the API on http://127.0.0.1:3000 (override with PORT).
- `npm run check`: check JavaScript syntax.

| Method | Path | Body | Response |
| --- | --- | --- | --- |
| GET | /health | — | { ok: true } |
| GET | /todos | — | { todos: [...] } |
| POST | /todos | { title: string } | Created todo (201) |
| PATCH | /todos/:id | { title?: string, completed?: boolean } | Updated todo |
| DELETE | /todos/:id | — | No content (204) |

Todos contain id (string), title (1–200 trimmed characters), and completed
(boolean). Invalid input returns 400; missing todos return 404.

The frontend lives in ../argos-playground-ui and proxies requests to this API.

## Artifacts location

Namespace: daedalus. Repository: argos-playground. Branch: main.
Upload this directory as its own Git repository, not the whole monorepo.

Argos imports a local snapshot without Git credentials; it cannot push changes.
Its computer backend may not support running a Node.js HTTP server.
