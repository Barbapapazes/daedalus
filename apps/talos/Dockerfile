# Keep the daemon version aligned with @cloudflare/computer.
FROM ghcr.io/cloudflare/computer-computerd-linux-x64:0.4.0 AS computerd
FROM node:24-trixie-slim
RUN apt-get update && apt-get install -y --no-install-recommends \
    fuse3 libfuse2t64 ca-certificates git python3 make g++ \
    && rm -rf /var/lib/apt/lists/* \
    && corepack enable
COPY --from=computerd /usr/local/bin/computerd /usr/local/bin/computerd
ENV PORT=8080 MOUNT_POINT=/workspace FUSE_MOUNT=auto
EXPOSE 8080
ENTRYPOINT ["/usr/local/bin/computerd"]
