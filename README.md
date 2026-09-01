# Blueprint Registry

A local WordPress plugin that provides a reviewable Blueprints gallery. It supports authored drafts, forks, update proposals, immutable releases, public Blueprint bundle URLs, and a reviewer workflow.

## Run locally

```bash
docker compose up -d
docker compose run --rm wpcli core install \
  --url=http://localhost:8080 \
  --title='Blueprint Registry' \
  --admin_user=admin \
  --admin_password=password \
  --admin_email=admin@example.test \
  --skip-email
docker compose run --rm wpcli plugin activate blueprint-registry
docker compose run --rm wpcli rewrite structure '/%postname%/' --hard
docker compose run --rm wpcli rewrite flush --hard
```

Open <http://localhost:8080/wp-admin/> and sign in with `admin` / `password`.

- Contributors work at <http://localhost:8080/blueprints/manage/>. A public Blueprint page offers **Fork as a new Blueprint** and **Propose update**.
- A contributor can edit a draft or a proposal returned with requested changes. **Review changes** saves the draft and opens its pre-submit comparison. From there, **Submit changes for review** sends it to reviewers. To change a proposal that is waiting in the queue, use **Edit changes**. An accepted proposal stays as the release record; use **Edit this Blueprint** to start a fresh update from the current release.
- **Blueprints → Review queue** is available to administrators. It shows a locked proposal, a WordPress split diff for its JSON and text resources, the bundle-file changes, a preview link, and review decisions.
- The public gallery is at <http://localhost:8080/blueprints/>. Each Blueprint page lists every release, lets visitors select an older release, and provides a file browser for that release.

## Tests

```bash
docker compose run --rm wpcli eval-file wp-content/plugins/blueprint-registry/tests/integration.php
docker compose run --rm wpcli eval-file wp-content/plugins/blueprint-registry/tests/review-workflow.php
docker compose run --rm wpcli rewrite flush --hard
docker compose run --rm wpcli eval-file wp-content/plugins/blueprint-registry/tests/routes.php
docker compose run --rm wpcli eval-file wp-content/plugins/blueprint-registry/tests/contribution-workflow.php
```

The tests create temporary posts, attachments, releases, and review messages and remove them afterwards.

## Deliberately deferred

This first version has no AI/agent write API, no personal access tokens, and no Git receive endpoint. All write operations use normal WordPress login and nonce-protected forms.
