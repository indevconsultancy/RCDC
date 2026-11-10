# ePIS integration

Pulls notifiable-disease records from ePIS, keeps the ones whose ICD-11 codes
BDSIS follows, and holds them in a verification queue. **Nothing here writes a
BDSIS record.** A notification becomes a case only when somebody verifies it,
accepts it, and completes the ordinary Records wizard — so every imported case
passes exactly the validation, subject-id allocation, scope and RBAC rules that
a hand-entered case passes.

```
ePIS feed ──▶ sync ──▶ bdsis_epis_case (New)
                            │
                  verify ───┼── Reject ──▶ Rejected   (kept for audit, never a record)
                            │
                        Accept ──▶ Accepted ──▶ records/case.php?action=1&epis=<id>
                                                      │ user completes + saves
                                                      ▼
                                              Imported + linked_subject_id
                                              (now visible under Records)
```

## Files

| File | What it is |
|---|---|
| `includes/epis-lib.php` | Service layer: token, fetch, ICD matching, store, decisions, prefill. Never throws. |
| `epis/epis-management.php` | The queue listing, with the Sync button. |
| `epis/epis-view.php` | One notification in full, with Accept / Reject. |
| `epis/epis-action.php` | POST-only, CSRF-checked handler for sync / accept / reject. |
| `config/epis-config.php` | Endpoints, timeouts, cache location. Committed; holds no secret. |
| `config/epis-secrets.php` | Consumer key and secret. **Git-ignored.** Copy from `epis-secrets.example.php`. |
| `config/epis-icd.php` | The ICD-11 codes BDSIS acts on, generated from `Updated ICD 11 Code_RCDC.xlsx`. |
| `sql/2026-09-24_epis_pending.sql` | Creates `bdsis_epis_case`. Rollback beside it. |

## Setting it up

1. `mysql -u root -P 3307 bdsis-up < sql/2026-09-24_epis_pending.sql`
2. `cp config/epis-secrets.example.php config/epis-secrets.php` and fill in the
   consumer key and secret. Any value may instead come from a
   `BDSIS_EPIS_<KEY>` environment variable, which is the better option where the
   web server can set one.
3. Connect the VPN. The token endpoint is reachable from the open internet; the
   **data API resolves only inside the government network**, so a sync without
   the VPN fails at DNS. `bdsis_epis_health()` reports the two legs separately
   so the error says which one is down.
4. Open **ePIS Verification** in the sidebar and press **Sync from ePIS**.

## Rehearsing without the VPN

```bash
BDSIS_EPIS_USE_FIXTURE=1 BDSIS_EPIS_FIXTURE_FILE=/path/to/response.json
```

Fixture mode is switched on by the environment, never by editing
`epis-config.php`, so a rehearsal cannot be left switched on by a commit.

## Things worth knowing

**Matching is on the whole code.** `1A09` (Salmonella) and `1A09.0` (food-borne
illness) are different diseases and both are configured, as are `1A40` and
`1A40.Z`. A substring test would conflate them, so codes are compared with
`in_array(..., true)` after the `CODE:Description` pairs are split and
shape-checked. A code named inside a *description* is not a match.

**Identity is a content hash.** The feed carries no encounter id and the same
UHID recurs with different onset dates, so a row is keyed on
`sha1(UHID|onset|ProvisionalDiagnosis|NotifiableDiseases)` with a `UNIQUE` key.
Re-syncing is therefore safe and is the normal case: an existing row is
refreshed from the feed, but its status, decision and subject link are never
touched — a sync can neither undo an accept nor resurrect a rejection.

**Dates are converted, not truncated.** ePIS timestamps are UTC; Bhutan is
+06:00. Reading the first ten characters would put every encounter after 18:00
on the previous day, which for an onset date is a real surveillance error.

**Scope.** ePIS names a dzongkhag, never a health centre, so the usual
`center_id` scope clause does not apply. A national role sees the whole queue; a
district or facility role sees its own dzongkhag. Both the listing and each
individual notification are checked, because the id travels in the URL.

**Capabilities.** Viewing the queue needs `view` on `records`; syncing,
accepting and rejecting need `add` — a read-only role (DHO, MoH, BFDA) sees the
queue and is offered no buttons, and the endpoint refuses the POST independently.

**Duplicate imports.** `linked_subject_id` is `UNIQUE` and the status is checked
in the `WHERE` clause of every transition, so a double submit, a replayed
request or two people with the wizard open cannot register one notification
twice. The link is stamped only after the case INSERT commits, so an abandoned
or rejected-by-validation form leaves the notification still waiting.

**Secrets.** The key and secret live server-side only, in a git-ignored file or
the environment. Nothing reaches JavaScript: the browser posts `do=sync` and the
server holds the credential. The bearer token is cached as JSON in the system
temp directory — outside the document root — at mode 0600, carrying a
fingerprint of the key that minted it, so rotating the credential invalidates
the cache instead of replaying a stale token. A 401 from the data API purges the
cache and retries once.
