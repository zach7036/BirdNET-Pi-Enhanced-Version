# Guided Review

## Purpose and scope

Review answers useful questions without requiring every detection to be checked.
Presence confirmation, individual recording verification, and a question's
workflow state are separate. The recorder, analysis model, recording thresholds,
retention settings, integrations, raw detections, and existing export defaults
are unchanged. Review does not train a model or retract previously uploaded data.

The default page is `scripts/review_guided.php`, included by `review.php`.
`?view=Review&visit_tools=1` retains the earlier whole-visit interface with an
explicit scope warning. Legacy POST and Undo contracts remain supported.

## Page layout

Each question separates the bird and a short explanation from two steps:
**Listen** and **Decide**. Desktop layouts place those steps side by side;
phones and narrow content areas stack them. Each card has one audio player.
The collapsed recording picker changes that player's recording, timestamp,
score, and decision target together. Extra recordings do not add more players.

Dates and session options, detailed reasons, comparison/reassignment tools, and
explicit bulk selection are collapsed until needed. Bulk decisions still require
their separate selection preview. Light and dark themes, keyboard controls,
Undo, and the existing save/retry safeguards work with the simplified layout.
This presentation change does not alter queue rules or stored review decisions.

## Selection rules (version 1)

- The default interval is today and six preceding local calendar dates. Date
  browsing permits any interval up to 31 days. Future-dated or still-active
  visits do not provide completed evidence.
- A discovery case groups one species across dates in the selected interval
  when no matching confirmed occurrence exists in the database. Its stable
  key depends on species, not the first raw detection date. This can include
  a historically recorded species never previously confirmed by a person;
  the label does not claim it is a brand-new machine detection.
- An unusual-occurrence case groups a regionally rare species/date when that
  date has no confirmation. Discovery and regional-rarity reasons combine.
- Ordinary identification checks group one completed visit and remain optional.
  Their initial candidate band is 60% to below 85%. The default Recommended
  count excludes these routine checks.
- Three or more rejected targeted visits in 90 days, at least as many as
  confirmations in that category, create a recurring-identification question.
  These are grouped once per species/date, not one urgent task per visit.
  This heuristic is not an estimated species-wide error rate.
- Recommended includes important open questions with readable audio. All items
  also includes optional routine checks. History includes acted-on questions,
  unresolved/postponed questions, and recent active/unavailable cases.
  Untouched older questions are discoverable with the date picker, not silently
  marked resolved. Acted-on state remains in History beyond the date window.
- Up to three available clips are initially offered, preferring different
  completed visits, then filling remaining slots within a visit. More evidence
  loads at most 100 candidates; explicit bulk decisions are bounded to 100.
  Scores and dates are shown per recording. Playing a clip selects it for action.
  Comparison references exclude the selected visit and label prior/bulk versus
  individually checked confirmations; unverified model matches are not truth.

“I can't tell” stores a baseline and no identification verdict. It does not
return merely because time passes. It reopens if audio becomes available after
none was available, or a readable candidate from a previously unseen visit has
a score at least 10 percentage points above the baseline. This is a transparent
initial evidence-change heuristic, **not** a claim of improved acoustic clarity.
The user can also reopen it manually. Later is a separate 24-hour postponement.

## Optional sampling and history

Up to two optional checks are selected from yesterday's completed visits for
species confirmed before yesterday and not regionally unusual. A deterministic
date/visit hash gives stable selection on an unchanged dataset. Selection occurs
before removing reviewed cases, so completing a sample does not replace it with
more homework. A second species and a different score band are preferred where
possible. Importing/backdating data or changing historical evidence can change
the eligible pool; there is no claim of a frozen statistical survey.

Samples are optional and do not inflate Today's Recommended count. Guided
sessions offer at most five questions, important first, optionally filling spare
places with samples. Session completion does not imply the remaining records
are correct or verified.

The evidence ledger distinguishes sampled, targeted, and bulk decisions. History
counts at most one recent decision per source/visit and excludes stale versions
or renamed identifications. Bulk contributes to targeted history, not sampling.
Ten positive sampled visits across at least three dates, with no sampled
rejections in the same ten-percentage-point score band in the last 90 days,
lower the ordering of comparable routine checks. They do not auto-confirm
anything or establish trust for weaker score bands. These are conservative UI
heuristics, not calibrated accuracy estimates; no automatic exclusion or
detector-threshold changes occur. Old whole-visit verdicts remain distinct and
do not become individually sampled training/evaluation records.

## Data, transactions, and compatibility

`review_cases.php` builds read-only projections. Presence is derived from
confirmed reviews joined to the same current filename/species/date/time.
Purged audio does not erase confirmation history; reassigned identities cannot
inherit it. The confirmed-species endpoint looks for surviving support and
reports when audio is no longer available.

`review_case_actions.php` creates four additive tables inside the first write:

- `review_cases`: stable workflow state, evidence baseline, snapshot and version.
- `review_evidence`: recording identity, provenance, score, visit and review version.
- `review_case_actions`: request IDs, request hashes, responses and Undo snapshots.
- `review_rename_archive`: review metadata preserved when identification changes.

No existing detection table is altered, rebuilt, or cleared. Recording verdicts
still use `detection_reviews` for existing statistic/export consumers. New
`reviewed_via` values distinguish individual, sample, and explicit bulk scope.
Old values and old verdicts are not migrated or relabeled.

Each new action has a client request ID. Repeating the identical request returns
its saved response; reusing the ID for a different body returns 409. The UI keeps
an unacknowledged request through reloads and retries the same payload. HTTP 4xx
responses are definitive; network/5xx failures retain the retry option.

The transaction validates the case version and each explicitly selected file's
identity/review version, saves only those selected files, updates case state,
and writes the Undo journal together. Any failure rolls everything back. A new
detection arriving outside that selection is never included. Bulk requires an
explicit preview acknowledgement. Missing/unreadable evidence cannot be confirmed
or rejected through the guided action endpoint.

Undo restores previous reviews, deferrals, provenance and case state. It checks
every affected entity before changing any, rejects newer decisions/renames,
and is idempotent. A confirmation from another recording remains valid. The
UI retains the last 20 guided Undo tokens in tab session storage; legacy tokens
and journals remain separate. Journal rows are not automatically purged.

Reassignment still uses the existing rename script. Its metadata follow-up now
archives the old verdict instead of transferring a verdict about a different
species onto the new identity. Failure is reported via `X-BirdNET-Notice` and
shown in Guided Review. Rename is not part of decision Undo; use the separate
reassignment workflow to change an identification back. Legacy metadata can be
recovered from the archive but is not silently reinstated by renaming back.

Insights cache watermarks include guided actions, legacy actions/Undo, and
reassignment archives, including same-second changes. The species page labels
raw machine totals separately from sampled decisions and confirmed dates.
Today's Story distinguishes possible discoveries from confirmed presence.

## API

- `GET /api/v1/reviews/cases`: `view=recommended|all|history|samples`, optional
  `start`, `end`, `limit` (0–100), `offset`, `key`, `details=1`.
  Returns `cases`, selected-view `total`, all `counts`, and the applied interval.
  A `key`-scoped detail response limits its counts and file checks to that case.
  Dashboard uses the same helper with `limit=0`; no GET creates DB tables.
- `GET /api/v1/reviews/confirmed?date=YYYY-MM-DD`: confirmed species and evidence
  availability, separating individual support from previous/bulk confirmations.
- `POST /api/v1/reviews/case-actions`: authenticated + existing custom CSRF header.
  Body has a 32–64 hex `request_id`, `action`, and `case` descriptor
  `{key,version,date,end_date,sci_name}`. Confirm/reject/hide additionally pass
  `files:[{file_name,file_revision}]`; multiple files require `bulk_confirmed:true`.
  `source:sample` is honored only for a server-selected sample and one recording.
  Other actions: `uncertain`, `later`, `resume`. Undo takes `undo_token` instead
  of a case. Successful non-Undo actions return a new `undo_token` and message.

Public options never accept test clock, filesystem, or visit-gap overrides.

## Validation and deployment

```sh
php -d extension=sqlite3 -d extension=mbstring tests/test_review_cases.php
python -m pytest tests/test_review_api.py
BIRDNET_TEST_BROWSER=chrome node --test tests/test_review_guided_ui.js tests/test_review_ui.js
php -d extension=sqlite3 -d extension=mbstring tests/benchmark_review_cases.php
```

Tests use synthetic SQLite data and mocked browser requests, not station data.
The opt-in memory-only benchmark contains 1.01 million detections, 10,000 recent
records, and checks filesystem-call counts as well as reporting timing/memory.
Desktop timings do not predict a particular Pi's speed. Avoid full-history raw
scans: the presence query deliberately starts from reviewed records and joins
through the filename index. The recording checks run after read statements are
finalized; normal case GETs must not retain a reader lock during file checks.
Inside a verdict transaction, filesystem checks are restricted to explicitly
selected recordings, not every other question's evidence.

On your existing Pi, use the normal development-branch workflow:

1. Before updating, take a consistent SQLite backup (SQLite's backup operation,
   not a raw copy during writes), and retain your configuration and current commit.
2. Check dashboard/Recommended count agreement; confirm one known clip and inspect
   the confirmed-species list. Other recordings should remain unverified.
3. Undo; try Can't tell, Later, and Resume. Check History and a short session.
4. Confirm recording/analysis continue and measure queue responsiveness on your data.
5. Test mobile layout and existing audio, then approve promotion separately.

A second Pi is not required. Application rollback leaves additive tables in place
and older versions ignore the new case state; compatible recording verdicts remain.
Do not automatically restore an old database on code rollback: that would discard
detections recorded after the backup. No deployment, push, or release is implicit.
