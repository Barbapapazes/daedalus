Never automatically update README.md.

## Apps

### Argos

The main conversational entry point: discover and create repositories, challenge and refine ideas, and hand approved intents to Talos.

Never write tests to this project.

### Talos

A Cloudflare Agent that implements approved intents.

Never write tests to this project.

### Prism

A Nuxt app that is the main interface for interacting with Daedalus. The `/admin/*` routes are protected by Cloudflare Access.

Never write tests to this project.

### Hestia

A Laravel API that serves as the persistence and service-to-service backend for Daedalus. The `/internal/*` routes are service-to-service endpoints protected by Cloudflare Access. Tests are mandatory for this project. However, each agent stores its own chat history.

Always write tests to this project.

## References

- <https://nuxt.com/llms.txt>
- <https://ui.nuxt.com/llms.txt>
- <https://comark.dev/llms.txt>
- <https://pinia-colada.esm.dev/llms.txt>
- <https://developers.cloudflare.com/agents/harnesses/pi/>
- <https://developers.cloudflare.com/agents/communication-channels/chat/client-sdk/>
- <https://developers.cloudflare.com/artifacts/>
