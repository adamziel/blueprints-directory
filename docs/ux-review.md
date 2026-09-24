# Blueprint Registry: UX review and plan

## One clear path

A visitor finds Coffee Shop, opens its details, and chooses Fork. They edit the
JSON, choose **Review changes**, see the saved contents, and optionally run them
in Playground. **Submit for review** sends that version to the queue. Nothing is
published until a reviewer accepts it.

```
Gallery → Details → Run / Download / Fork / Edit (author)
                        ↓
My work ← Editor → Review changes → Submit for review
             ↑                            ↓
             └── Reviewer feedback ← Review queue → Published revision
```

Opening the editor must not write a draft. Editing must not silently replace a
submitted version. The review screen must show the contents being submitted.

## Screen-by-screen findings and changes

| Screen | Useful | Friction found | Reworked experience |
| --- | --- | --- | --- |
| Gallery, grid and table | Thumbnails, search, categories, Run | Grid lacks the table's Details action; signed-in users see two create actions | Explicit Details and Run; one contribution action in the page header |
| Details, current and older revisions | Thumbnail, description, files, revision picker | Run is prominent but Download is distant; older revision shows today's title/description; malformed metadata list; signed-out Fork loses intent | Keep Run, Download, Fork, Edit together; use selected revision metadata; preserve Fork through sign-in |
| File reader | Sibling files, code, download | Long paths overflow headings; many badges compete with filename | Keep the file content central; wrap paths, contain horizontal code scrolling |
| Sign-in gate | Private work protected; gallery still public | Long explanation before sign-in | Short invitation, one sign-in action that returns to the chosen flow |
| My work | One row per published Blueprint, thumbnail, status filters | Duplicate empty-state create action; verbose Kind repeats title; destructive action after View; wide table on phones | One create action, concise kind, Remove before Published/Edit, phone-friendly rows |
| New/fork/update editor | JSON is the source of title/description; bundle files | Many instructions; files push editor down on phones; no save-state feedback | One primary Review changes action; short save-state hint; optional upload-path details; code first on phones |
| Saved/pending editor | Pending copy stays reviewable while author edits | Duplicate form action makes Review and submit only save; Valid badge stays after edits; claim that reviewer is actively looking; old feedback fills page | One unambiguous submit action; unsaved-change warning; truthful queue status; newest feedback first and earlier feedback folded away |
| Pre-submit review | Shared comparison with reviewer | New Blueprint has no contents shown; generic success notice; Preview does not say it opens a running site; stale tab could submit unseen edits | Title/description preview, actual JSON and files, named Playground action, exact-content check before sending |
| Pending/history | Review messages, earlier proposals | Submitted copies inaccessible to contributor; historical information scattered | Submitted copies and file links in an optional history section; published history remains reachable without another view-only editor |
| Reviewer queue and decision | Oldest first, snapshot comparison, three decisions | Heading can reflect unsubmitted edits; empty feedback loses form on server error; permanent rejection too close to common action | Use submitted title; validate required feedback before navigation; show consequences next to decisions; destructive action first |
| Mobile/shared shell | Existing tokens and shared controls | Navigation disappears; editor sidebar leads; focus styles suppressed in admin | Keep navigation reachable; sensible reading order; visible keyboard focus; wrap actions without overflow |

## Scope

Keep the current palette, fonts, icons, flat panels, borders, and DataViews-style
lists. No new framework, autosave service, AI features, or replacement bundle
editor. File uploads remain client-packed ZIPs using normal WordPress upload
checks. A running preview opens on demand in Playground; no automatic execution
of contributed code or misleading thumbnail standing in for a live preview.

## Verification

- Browser regression: new → check → back → edit → check really reaches review.
- Browser regression: save while pending → submitted copy unchanged → resubmit.
- Browser regression: upload arbitrary file in client ZIP; removal preserves JSON.
- Browser regression: invalid JSON/upload cannot produce a successful review;
  stale review tab cannot send newer contents without another comparison.
- Reviewer: feedback required for changes/rejection; feedback visible to author;
  acceptance publishes the exact submitted files, not later working edits.
- No draft from GET; draft removal; history and published file routes still work.
- Existing PHP integration, contribution, review, and route suites.
- Desktop and phone captures of public, contributor, and reviewer screens.

## Results

Implemented on the local Docker site. The hosted Studio preview has not been
replaced by this work.

- All four PHP suites pass: integration, review workflow, routes, contribution.
- Browser flows pass with separate contributor, reviewer, and visitor sessions.
- 48 renders cover 24 states at 1440px and 390px; no page-level horizontal overflow.
- Browser checks include unsaved navigation, stale contributor/reviewer tabs,
  resume-existing-update links, in-place additions/removals in text diffs,
  submitted history, upload failure recovery, and no-JavaScript editing.
- The preview route returns a valid ZIP containing the saved JSON and resources.
  Booting the external Playground app was not part of this automated run.
- No online data, existing Blueprints, or existing user accounts were removed.

Open `outputs/ux/index.html` for the visual index and test logs. The test data is
removed after each browser run. Older test records already on the local gallery
were left alone; the integration test now tracks its backslash fixture correctly
so repeated runs do not add more abandoned entries.
