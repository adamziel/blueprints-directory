# Blueprint Registry

A local WordPress plugin that provides a reviewable Blueprints gallery. It supports authored drafts, forks, update proposals, immutable revisions, public Blueprint bundle URLs, and a reviewer workflow.

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

- Contributors work at <http://localhost:8080/blueprints/manage/>. Public details offer **Run**, **Download**, and **Fork**; the author also sees **Edit**. Sign-in preserves the chosen contribution flow.
- Opening an editor does not create a draft. **Review changes** saves the JSON and files and opens the check screen. **Run preview ↗** opens the saved bundle in Playground. **Submit for review** sends that version to the queue. The editor warns before leaving with unsaved edits.
- Edits to a pending proposal stay separate from the submitted copy. **Replace submitted version** sends the checked changes without taking the proposal out of the queue first. Reviewers always see the submitted title, JSON, and files. An old check screen asks you to check again if another tab changed the contents.
- **My work** shows the latest work for each published Blueprint. **Edit** resumes it. **Remove draft** deletes unpublished draft work; published revisions remain untouched. Feedback stays above the editor, earlier feedback and submitted copies are in disclosures, and published revisions are linked from History.
- **Blueprints → Review queue** is available to administrators. Opening a proposal from the queue leads to a dedicated review screen: the locked submission compared against its base revision, the submitted bundle, and the accept / request-changes / reject decision.
- The public gallery is at <http://localhost:8080/blueprints/>. It is searchable, filterable by category, sortable, and switches between a grid and a table. Each Blueprint page summarises what the Blueprint brings in a sentence — which plugins and themes it installs, whether it imports content — shows the declaration itself, lists every revision in a picker, lets visitors select an older one and compare it against the current revision, and provides a file browser for whichever is selected.

## Bundle file storage

A Blueprint bundle may contain any file type — a SQL seed, a PHP helper, a font, an archive. Routing those through the Media Library would mean either rejecting them at WordPress's upload allow-list or widening that allow-list for the whole site; the first blocks real Blueprints, the second lets arbitrary types into every upload field on the install.

Proposal files therefore live in private storage instead:

```
browser packs selected files into one ZIP
  -> WordPress accepts the ZIP through its normal upload checks
  -> the plugin validates and unpacks the bundle
  -> uploads/blueprint-registry-<random>/<proposal id>/<key>.bin
  -> a manifest of path, size, and checksum in post meta
  -> accepted revision is packaged as the public bundle.zip
```

The directory carries a per-site random suffix and `.htaccess` / `web.config` denials. Nginx deployments must also deny direct access to these directories; a random name alone is not access control. Stored files take a generated name and a neutral `.bin` extension, so nothing about the upload influences the path on disk. Reads go only through the plugin's download route, which checks a nonce, then that the caller authored the proposal or reviews, then that the requested key belongs to that proposal. Responses are always `application/octet-stream` with `nosniff`, since a bundle may hold HTML or SVG and is served from the site's own origin.

`Blueprint_Registry_Storage` caps a single file at 64 MB, a whole bundle at 256 MB, and a bundle at 50 files, and refuses any path that climbs out of the bundle root. Only the released `bundle.zip` is a Media Library attachment, because that one is meant to be fetched.

Installs predating this keep working: bundle files are moved out of the Media Library once, on the next load.

## Interface

Every screen the plugin renders — public gallery, contributor workspace, and reviewer tools — shares one light palette and type scale, defined as tokens in `assets/tokens.css`.

Those tokens are the WordPress.org design system's own, read from the `wporg-parent-2021` theme's presets: blueberry `#3858e9` for actions, charcoal `#1e1e1e` for text, `#d9d9d9` hairlines on `#f6f6f6`, Inter and IBM Plex Mono, and the 2px radius used across the directories. WordPress.org is flat — a card carries a hairline and darkens that hairline on hover rather than lifting on a shadow — and the registry follows. Fonts load from Google Fonts here for convenience; WordPress.org self-hosts them and a production deploy should too.

The list screens follow the structure and interaction model of `@wordpress/dataviews`: a view toolbar carrying search, filters and sort, then a table or grid of the same records, then a pagination footer. Core does not expose `wp-dataviews` as a script handle for plugins, so `assets/dataviews.css` reproduces the grammar in markup close enough to the real component to swap later.

Registry pages render their own application shell rather than the active theme's header and footer. The admin bar and every enqueued asset still load through `wp_head()`.

The Blueprint editor is deliberately thin. It is laid out as a file browser beside a single editor slot so that [`BlueprintBundleEditor`](https://github.com/WordPress/wordpress-playground/pull/4295) from `@wp-playground/components` can replace the slot's contents once it lands, without touching the surrounding screen.

## Updating the demo site

The plugin is copied into the Studio site, not symlinked, so a change here is not live on the preview until both steps run:

```bash
rsync -a --delete --exclude tests --exclude .export \
  wp-content/plugins/blueprint-registry/ \
  ~/Studio/blueprint-registry/wp-content/plugins/blueprint-registry/
studio preview update <host> -p ~/Studio/blueprint-registry
```

## Moving the registry between installs

Registry rows carry post IDs inside meta — current revision, base revision, bundle attachment, per-file attachment lists — and none of that survives an ID remap, so a WXR export or a raw database copy produces a broken site. Export writes what the registry *means*, and import replays it through the real workflow:

```bash
# On the source install
docker compose run --rm \
  -e BLUEPRINT_EXPORT_DIR=/var/www/html/wp-content/plugins/blueprint-registry/.export \
  wpcli eval-file wp-content/plugins/blueprint-registry/tools/export-registry.php

# On the target install
BLUEPRINT_IMPORT_DIR=/path/to/registry-export \
  wp eval-file wp-content/plugins/blueprint-registry/tools/import-registry.php
```

Every Blueprint is rebuilt by proposing, submitting, and approving each revision in order, so IDs and bundles are consistent by construction. Proposals still in flight are recreated with their review messages.

## Seeding revision history

One Blueprint ships with a single revision, which is not much to browse. To build more, each going through the real propose–submit–review workflow:

```bash
docker compose run --rm \
  -e BLUEPRINT_SEED_SLUG=personal-blog -e BLUEPRINT_SEED_COUNT=4 \
  wpcli eval-file wp-content/plugins/blueprint-registry/tools/seed-revisions.php
```

## Walkthrough

`blueprint-registry-walkthrough.mp4` records the whole loop against a live site: browsing and filtering the gallery, forking a Blueprint, editing it, seeing the comparison, submitting, a reviewer requesting changes with a reason, the contributor addressing them, acceptance and release, a rejection with feedback, and withdrawing a draft.

To record it again:

```bash
docker compose run --rm wpcli eval-file \
  wp-content/plugins/blueprint-registry/tools/reset-walkthrough-demo.php
node .shots/demo.mjs
```

The reset script removes everything the recording creates, so it can be run repeatedly.

## Tests

```bash
docker compose run --rm wpcli eval-file wp-content/plugins/blueprint-registry/tests/integration.php
docker compose run --rm wpcli eval-file wp-content/plugins/blueprint-registry/tests/review-workflow.php
docker compose run --rm wpcli rewrite flush --hard
docker compose run --rm wpcli eval-file wp-content/plugins/blueprint-registry/tests/routes.php
docker compose run --rm wpcli eval-file wp-content/plugins/blueprint-registry/tests/contribution-workflow.php
```

The tests create temporary posts, attachments, releases, and review messages and remove them afterwards.

## UX map and browser tests

The screen-by-screen audit and interaction rules are in `docs/ux-review.md`.
The palette, typography, and flat-panel design remain unchanged.

The browser suite runs only against localhost. It creates dedicated users and
Blueprints, exercises actual form buttons, and deletes those records afterward.
It checks edit/check/submit, stale tabs, queued edits and resubmission, ZIP upload,
file removal, feedback, acceptance/rejection, historical files, upload recovery,
unsaved-change warnings, and the plain textarea fallback without JavaScript.

```bash
npm install --prefix .shots --no-save playwright@1.62.1
npx --prefix .shots playwright install chromium
node .shots/ux-flows.mjs
```

Desktop and phone screenshots are written to `outputs/ux/after/`. The suite
checks page overflow and JavaScript errors. It verifies the generated preview
ZIP and its contents; booting the remote Playground app is a separate check.

## Deliberately deferred

This first version has no AI/agent write API, no personal access tokens, and no Git receive endpoint. All write operations use normal WordPress login and nonce-protected forms.
