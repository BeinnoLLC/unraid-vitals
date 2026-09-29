# Phase 21 — Knowledge base cleanup & reset

[All proposed phases](./index.md)

## Current state

- The knowledge base is append-only. Nothing in the UI or `ajax.php` can delete, hide or correct a document.
- A category is the `topic` column. It is set by code to the agent id (`disks`, `thermal`, `pools`, `network`, `general`) or `research`. Users cannot create, rename or remove categories.
- Research answers are stored with `source='finding'`: `ingestFindingToKb()` hardcodes the source, so research documents cannot be told apart from agent findings except by topic.

Issue #38 plans curation (edit, merge, pin, feedback that adjusts confidence). These tickets cover removal: getting unwanted knowledge out, and keeping it out.

Every write action here is POST only with the CSRF token, like `include/actions.php`.

---

## P21-01 — Delete a document, with a trash

**Type:** enhancement · **Priority:** high

- Delete button on every KB document
- Deleting moves the document to a trash (`deleted_at` set); it leaves search, research context and agent context at once
- Trash view: restore or delete permanently
- Trash empties itself after 30 days, adjustable

Acceptance: a deleted document is absent from search and from the next research answer's context, and can be restored from the trash.

---

## P21-02 — "Not useful" on documents and findings

**Type:** enhancement · **Priority:** high

One click for "I don't like this", without deciding whether to delete.

- Thumbs-down on a KB document, an AI finding, or a research answer
- Effect is immediate: the item is excluded from retrieval and hidden from default views
- Optional reason: wrong, outdated, not relevant, duplicate
- Feeds the confidence score planned in #38
- "Show disliked" filter, with undo

Acceptance: a disliked document never appears in research context again, and the dislike can be undone.

---

## P21-03 — Removed knowledge stays removed

**Type:** enhancement · **Priority:** high

Agents re-create the same finding every run. Without this, a deleted document comes back within the hour.

- Deleting or disliking a document records a tombstone with its fingerprint (agent + subject + normalized title, from P20-03)
- Ingestion skips anything matching a tombstone
- Tombstones list in the UI, each with "allow again"
- A tombstone stops matching if the finding's severity rises, so a worsening problem is not silenced

Acceptance: a deleted finding document is not re-created by the next ten agent runs; after "allow again" it is.

---

## P21-04 — Bulk cleanup with preview

**Type:** enhancement · **Priority:** high

- Select by category, source (finding, research, manual, seed), severity, date range, or the current search results
- Preview shows the count and a sample before anything is removed
- Removed items go to the trash (P21-01)
- Apply accepts only the preview id, not a list from the client

Acceptance: "everything in `network` older than 90 days" shows a count, removes exactly that many, and all are restorable.

---

## P21-05 — Custom categories

**Type:** enhancement · **Priority:** medium

- `kb_categories` table; built-in categories (one per agent, plus research) are marked as such
- Create, rename and merge categories; move documents between them
- A document has one category; agents keep writing to their own
- Built-in categories can be emptied but not deleted

Acceptance: a category "UPS" is created, five documents moved into it, and the category chip filters to those five.

---

## P21-06 — Clear or delete a custom category

**Type:** enhancement · **Priority:** high

- **Clear:** remove the category's documents, keep the category
- **Delete:** remove the category; choose whether its documents go to the trash or to "uncategorized"
- "Clear all custom categories" in one action, with a preview per category
- Built-in categories are never affected by the bulk action

Acceptance: deleting a custom category with "keep documents" leaves its documents searchable under "uncategorized".

---

## P21-07 — Reset the knowledge base

**Type:** enhancement · **Priority:** medium

- Choices: learned documents only, research history, custom categories, tombstones and dislikes, or everything
- Seed documents (P20-11) and manual entries are kept unless ticked
- Automatic DB backup first (P19-01); the reset is refused if the backup fails
- Confirmation requires typing the word RESET
- Refused while an agent run or research job is active

Acceptance: after a full reset the KB holds only seed documents, and the pre-reset backup restores the previous state.

---

## P21-08 — Research history cleanup

**Type:** enhancement · **Priority:** medium

- Delete a research job; its KB document goes with it
- Dislike on an answer removes it from the KB and offers to run the question again
- Store research documents with `source='research'` and the job id in `source_ref`, and correct existing rows
- Clear all research history in one action

Acceptance: deleting a research job removes its answer from KB search.

---

## P21-09 — Dismiss or snooze a finding

**Type:** enhancement · **Priority:** medium

For findings the user has seen and accepts, such as a disk with old, stable reallocated sectors.

- Dismiss (until it changes) or snooze (7, 30, 90 days)
- A dismissed finding raises no notification and is hidden by default
- It returns if its severity rises or its numbers change
- Dismissed findings are passed to the agent as known and accepted, so it stops reporting them as new

Acceptance: a dismissed finding stays hidden across ten runs and reappears when its severity rises.

---

## P21-10 — Index and storage maintenance

**Type:** enhancement · **Priority:** medium

- Rebuild the FTS index after bulk removals and check it against `kb_documents`
- Remove embeddings belonging to deleted documents (#37)
- Reclaim file space after large deletions
- Report: documents, trash size, DB size, orphaned rows
- Registered in the schedule registry (P18-01), weekly

Acceptance: after deleting 10,000 documents and running maintenance, the DB file shrinks and the index check passes.

---

## P21-11 — Expiry by age

**Type:** enhancement · **Priority:** low

- Retention per category, in days; off by default
- A document expires when it has not been seen for that long (`last_seen` from P20-03)
- Pinned, manual and seed documents never expire
- Expired documents go to the trash

Acceptance: with 90-day retention on `thermal`, a document last seen 91 days ago is in the trash after the prune job.

---

## P21-12 — Audit log of knowledge-base changes

**Type:** enhancement · **Priority:** low

- Every delete, restore, dislike, category change and reset is recorded: when, what, how many
- Visible in the UI
- Shares the audit table with cleanup actions (P16-01)

Acceptance: a bulk delete appears in the log with its filter and count.
