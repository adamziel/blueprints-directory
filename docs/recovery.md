# September 5 source recovery

Recovered on September 24, 2026. The original working folder was missing; the
older Conductor checkout and GitHub `trunk` did not contain the later work.

## Sources

- The September 3 WordPress Studio deployment supplied the plugin source.
  Its admin, bundle, and JavaScript file hashes match the hashes in the saved
  September 3 diffs (`193abbf`, `ed9a0ef`, and `c289325`).
- Saved project edits and source reads supplied the PHP tests and README.
  The recovered review test matches its recorded Git blob hash before the UX
  changes (`25222c2a3909ab58fee2b2b7cfe64a6132023927`).
- The saved September 5 edits restored the UX changes, browser tests, fixtures,
  and UX review document. Only source-file edits were replayed, in an isolated
  copy; old deployment commands and database commands were not replayed.

This includes the client-side ZIP upload path, private file storage, the shared
visual design, contribution and review workflows, and the September 5 UX pass.
The later suggestion to rename “Review changes” to “Continue” was not implemented
at the time, so the recovered code keeps the existing label.

## What was not recovered

The original Git history, database, recordings, and old visual captures are not
part of this recovery. Generated files and task logs are not committed.

## Fresh validation

- PHP syntax checks and JavaScript syntax checks pass.
- All four PHP suites pass on a separate WordPress installation: integration,
  review workflow, routes, and contribution workflow.
- Browser contribution and reviewer flows pass, with 48 fresh desktop/mobile
  captures. The recovered suite ran with only its local test URL changed from
  port 8080 to port 8187 in a temporary copy of the runner.

The test installation uses separate Docker volumes. The original site's
database and the existing Studio installation were not changed.
