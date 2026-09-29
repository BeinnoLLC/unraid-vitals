# Proposed tickets (draft)

Draft tickets for phases 13–21, written 2026-09-29. **Not filed on GitHub yet.** Each ticket is written in the same shape as the existing issues (title, body, `Acceptance:` line) so it can be filed as-is.

Phases 0–12 already exist on GitHub (issues #1–#38). Nothing here duplicates them; where a ticket builds on an existing issue, it says so.

| Phase | File | Tickets | Theme |
| --- | --- | --- | --- |
| 13 | [Foundations & data integrity](./phase-13-foundations.md) | 11 | Things the later phases depend on |
| 14 | [Diagnosis checks](./phase-14-diagnosis-checks.md) | 16 | Detect the common Unraid problems |
| 15 | [Data study](./phase-15-data-study.md) | 10 | Forecasts, anomalies, storage analysis |
| 16 | [Cleanup actions](./phase-16-cleanup-actions.md) | 8 | Reclaim space safely |
| 17 | [Diagnosis tools](./phase-17-diagnosis-tools.md) | 5 | One-click health check, playbooks, export |
| 18 | [Scheduler settings](./phase-18-scheduler-settings.md) | 9 | Adjust every schedule from the settings page |
| 19 | [Database backup & restore](./phase-19-db-backup-restore.md) | 8 | Protect the knowledge-base DB |
| 20 | [AI agents & RAG quality](./phase-20-ai-rag-quality.md) | 19 | Better findings, better research answers |
| 21 | [Knowledge base cleanup & reset](./phase-21-kb-cleanup.md) | 12 | Remove unwanted knowledge and keep it out |

**Total: 98 tickets.**

## Already fixed (on `main`, not tickets)

- Knowledge-base DB moved from RAM (`/var/tmp`) to the Unraid appdata share, with one-time migration.
- AI notifications no longer repeat every hour.
- Uninstall now removes the agents cron.
- KB search and research retrieval returned nothing for every query (`ambiguous column name: topic`); fixed.

## Suggested order

1. **P13-01, P13-02, P13-03** — checks engine, honest rollups, LF line endings.
2. **P20-01, P20-03, P20-09, P20-18** — the AI layer's correctness problems.
3. **P19-01, P19-02, P19-03** — DB backups, now that the DB is worth keeping.
4. **P18-01, P18-02, P18-03** — schedule registry and settings panel; later jobs register into it.
5. **P14-09, P14-01, P14-03, P14-04** — the four most common real-world problems.
6. **P16-01, P16-02, P16-04** — cleanup safety framework, then the two biggest space wins.
7. **P15-01, P17-01** — capacity forecast and one-click health check.
8. **P21-01, P21-02, P21-03, P21-06** — delete, dislike, keep removed knowledge out, clear custom categories. P21-03 needs P20-03.
9. Everything else by interest.

## Priority key

- **high** — correctness, data loss, or a dependency of other tickets
- **medium** — clear user value
- **low** — nice to have
