# Burnfront

A logic-puzzle web product built on **Firebreak** — a new pen-and-paper deduction genre
where you reconstruct a fire's containment from its arrival times. Every board carries
three machine-checked guarantees: exactly one solution, solvable by pure deduction, and
every firebreak justified by a visible clue.

## Where things live

| What | Where |
|---|---|
| The genre: rules, worked example, generation math | [`docs/GENRE.md`](docs/GENRE.md) |
| Product & publishing plan | [`PLAN.md`](PLAN.md) |
| **Build playbook** (workstreams, gates, schedule) | [`docs/BUILD_PLAYBOOK.md`](docs/BUILD_PLAYBOOK.md) |
| Resolved decisions (authoritative) | [`docs/decisions.md`](docs/decisions.md) + [`docs/adr/`](docs/adr/) |
| Rules for AI agent sessions | [`CLAUDE.md`](CLAUDE.md) |
| Workstream briefs + status ledger | [`tasks/`](tasks/) |
| Frozen prototype (behavioral authority) | [`reference/`](reference/) — read-only |

## Quick start

```bash
pnpm install
pnpm typecheck && pnpm lint && pnpm test   # gates 1-3
bash scripts/hygiene.sh                    # gate 9
python3 reference/firebreak.py --demo      # the genre, in a terminal
```

Open `reference/index.html` in a browser for the playable prototype.

## Working on this repo

Every change happens inside a workstream: read `CLAUDE.md`, then your
`tasks/WS-XX/brief.md`. Definition of done = the brief's acceptance criteria plus the
quality gates in the playbook §5.
