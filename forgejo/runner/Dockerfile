# One Forgejo Actions runner (forgejo/runner/compose.yml). Runbook:
# docs/forgejo.md, section 14. Pin the exact release and bump it by hand.
FROM data.forgejo.org/forgejo/runner:13.2.0

COPY --chmod=755 start.sh /usr/local/bin/sdoc-runner

# The image's ENTRYPOINT (dumb-init) stays: it reaps what jobs leave behind.
CMD ["/usr/local/bin/sdoc-runner"]
