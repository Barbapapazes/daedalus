# Talos implementation flow

## Reference and execution

Argos submits one approved intent, its original conversation snapshot, and one ready loaded Artifacts repository. The repository assignment contains `name`, `baseRef`, and the inspected `baseCommit`; Talos never resolves a moving branch as its implementation base.

Laravel stores this reference unchanged and atomically enqueues `TriggerImplementation` in the `implementations` database queue. A repeated request ID must carry the same payload. Historical requests without repository assignments are not dispatched.

The job sends the immutable assignment to `POST /internal/implementations`. Talos uses one Durable Object per request and one idempotent Pi operation. Work happens in `/workspace/repo` on `talos/<requestId>`. Delivery retries do not create a new branch or prompt.

> On ne réécrit pas l’intention à chaque étape : on conserve l’intention de référence, puis on ajoute les décisions et leur justification.

The conversation is supporting context, not additional approved scope. Material choices are appended with `record_decision`, including justification, alternatives, and intent references. A scope change requires a blocked report, not an edited reference intent. This initial flow has no automatic resume/amendment endpoint; resolving a blocked request requires an explicit future orchestration policy.

## Computer execution tiers

- File tools and the offline Worker shell are the default. No native tools or package installation are available there.
- The `sandbox` backend starts a full Linux container only when explicitly selected for a command. The image includes Node/npm, pnpm via Corepack, Python, Git, and C/C++ build tooling; it is not a preconfigured runtime for every language.
- Computer pushes source before execution and pulls changes afterward. Commands run sequentially, in the foreground, with a five-minute timeout. Unconfirmed source synchronization prevents publication; it can still be reported as a blocker.
- Dependency/cache patterns (`node_modules`, `.venv`, `.nuxt`, `.cache`) remain container-local. They are not visible to Worker file tools and must be rebuilt after container replacement. Do not store authored source there.
- Containers have no heartbeat, a bounded command activity window, and a one-minute idle timeout. Terminal processing closes the workspace transport and destroys any running container. The whole request has a 30-minute execution budget.
- Sandbox internet access enables dependency downloads. This is not an egress allowlist: source confidentiality still depends on the assigned agent and repository commands. Do not expose secrets to either execution backend. Worker-shell egress remains disabled.

## Publication and callbacks

The agent uses standard Git commands through `exec`: `git checkout -b talos/<requestId>`, `git add`, `git commit -F /workspace/context/implementation.commit-message`, and finally `git push -u origin talos/<requestId>`. Provisioning only clones and checks out the pinned base; it does not create the implementation branch. Both execution tiers have a default Talos commit identity. The supplied message losslessly encodes the complete canonical reference intent and request ID; publication validates every implementation commit's message. Worker-shell Git adapts this canonical `-F` command to Computer's supported `-m` operation internally. There are no dedicated branch, commit, or push model tools.

Authenticated push must use `backend: "shell"`. The Worker-shell Git adapter routes standard `git push` to an internal credential-safe implementation: it checks the assigned checkout, branch, clean working tree, and linear history rooted at the pinned base, then freezes the commit before network I/O. Only origin and the assigned branch are accepted; force, deletion, alternate URLs, and other refspecs are rejected. `git push` with no arguments also targets the assigned branch; `-u`/`--set-upstream` configures its upstream after success. The actual remote is resolved from the pinned Artifacts assignment, not model-editable Git configuration.

Run the final push as a standalone command after all edits and checks. Once publication is checkpointed, new file tools and commands are blocked except a standalone Worker-shell push retry in the checkout and the final report. Interrupted pushes can only retry the same commit. Completion revalidates the clean checkout and pushed HEAD. This does not make arbitrary native sandbox commands a Git-mutation security boundary: native Git supports local operations but receives no repository credentials, and authenticated publication goes through the Worker shell. Artifacts tokens are short-lived, held only internally, and revoked after use; transport exceptions are not returned to the model. Talos itself never merges or deploys. Confirmed completion automatically starts the separate integration stage below.

`finish_implementation` freezes local work and reports each original acceptance criterion verbatim, with `met`, `unmet`, or `unverified` evidence. A ready report requires a confirmed successful `git push`; completion processing only verifies that publication and delivers the callback, never stages, commits, or pushes automatically. Unmet criteria or blockers prevent a ready report and should be reported without publication. Unverified checks are explicitly retained; branch publication is not proof that every check passed.

Talos writes append-only progress, decision, execution, and terminal events to a durable outbox. It delivers these sequentially to Laravel's `POST /internal/implementation-requests/<requestId>/events`, retrying lost acknowledgements. Laravel deduplicates event IDs and requires contiguous sequence numbers. Original intent, conversation, repository assignment, and prior events are not updated by callbacks.

`GET /internal/implementation-requests/<requestId>` returns the reference intent, assignment, tracking status, and ordered events. A final `completed` event confirms the pushed branch and commit. Dispatch acknowledgement failures remain separate from execution failure, since a timeout may mean Talos already started.

## Autonomous integration

On accepting a new `completed` event, Laravel creates one immutable integration request per implementation and atomically enqueues `TriggerIntegration` on the same `implementations` database queue. Replaying completion does not dispatch another integration. Blocked or failed implementations do not start integration. The assignment contains the original intent, implementation report, recorded decisions, and exact source commits for changed repositories only. The target is explicitly `main`, regardless of the implementation's inspected base ref.

`POST /internal/integrations` starts the `Integrator` Durable Object in the existing Talos Worker. It has its own harness, workspace, sandbox container and callback outbox. No additional URL or service credentials are needed; protect this ingress with the same Cloudflare Access policy. Talos retains its branch-only Git permissions.

The integration agent autonomously prepares latest trunk with `refresh_repository`, uses native sandbox `git merge --squash <sourceCommit>`, resolves conflicts against the immutable reference intent and existing trunk behavior, records material decisions, and runs applicable checks. It commits using the supplied per-repository commit-message file, which includes the complete original intent, implementation request/source commit, and integration request. Genuine product contradictions or exhausted repair attempts may block integration, but code review and merge approval are not human gates.

`publish_integration` validates a clean single-parent squash commit on the assigned integration branch, with prepared main as its parent and the exact canonical commit message. It checkpoints the candidate before authenticated publication and pushes only to `main`, never with force. Credentials stay inside the adapter. Native Git remains local-only. Validation evidence and execution results are logged; the agent is responsible for selecting applicable checks and reporting unavailable checks honestly, not a universal deterministic CI gate.

Integrations use optimistic concurrency rather than a global repository lock. If main moves, the non-force push rejects stale/divergent candidates; the agent refreshes, repeats the squash, resolves conflicts and revalidates. Pending publication freezes edits until retry or recovery. Recovery detects the exact candidate in main's ancestry, including when another integration subsequently advanced main, and never reapplies an already-integrated candidate. Refresh discards an unpublished candidate only after confirming it was not integrated and main moved.

Publication is not atomic across repositories. Durable per-repository receipts survive retries and partial failures; terminal callbacks preserve them, and any ambiguous publication checkpoints are reported separately. `finish_integration` requires confirmed receipts for every changed repository before claiming success. Integration performs no deployment.

Laravel ingests the separate ordered, deduplicated callback stream at `POST /internal/integration-requests/<id>/events`. Implementation `completed` still means branch publication; integration `integrated` means all assigned repositories were merged. `GET /internal/implementation-requests/<id>` links the integration, and `GET /internal/integration-requests/<id>` exposes its assignment, status, dispatch error and events. Read-only agent inspection is available at the Worker's `GET /internal/integrations/<id>`.

## Configuration

Apply Laravel migrations and run the dedicated worker:

```sh
cd api
php artisan migrate
php artisan queue:work implementations --queue=implementations --timeout=30
```

The `implementations` queue intentionally uses the same default database as the request table so intake and queue insertion commit atomically. Do not move that queue to another database without introducing a transactional dispatch outbox.

Configure Laravel's `TALOS_URL`, `TALOS_ACCESS_CLIENT_ID`, and `TALOS_ACCESS_CLIENT_SECRET` (see `api/.env.example`). `TALOS_URL` should be a service origin, without a path prefix.

Configure Talos's `DAEDALUS_API_URL` and `CF_ACCESS_CLIENT_ID` / `CF_ACCESS_CLIENT_SECRET` (see `.env.example`). The Access credentials on each side authorize calls to the other side and can be different service tokens. Protect both services' `/internal/*` routes with Cloudflare Access service-token policies.

Talos's `daedalus` Artifacts namespace must permit read and write operations. Do not give model tools the Artifacts binding, tokens, or Laravel service credentials.

A running Docker-compatible daemon is required to build the configured sandbox image or run it locally. The daemon image version is pinned alongside `@cloudflare/computer`. No deployment or live repository push is performed by local typechecking or Worker bundling.
