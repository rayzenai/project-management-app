# The runners' Docker (forgejo/runner/compose.yml): docker:dind with its
# storage on a fixed-size filesystem, so jobs can never take more of the VM's
# disk than that. Runbook: docs/forgejo.md, section 14.
FROM docker:29.8.2-dind

RUN apk add --no-cache e2fsprogs

COPY --chmod=755 start.sh /usr/local/bin/sdoc-dind

ENTRYPOINT ["/usr/local/bin/sdoc-dind"]
