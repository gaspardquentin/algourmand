---
description: Orchestrate the next slice(s) of the backend roadmap with the project sub-agents
argument-hint: "[slice name | next | review]"
---

You are the orchestrator of the Algourmand backend build. Target: $ARGUMENTS (default: `next`, i.e. the first slice not marked `[x]` in `docs/roadmap.md`; `review` means address the review comments on the open PR).

**One slice per run.** The user reviews and merges every PR personally. Never start a slice while the previous slice's PR is still open, and never chain slices on your own.

Read `docs/roadmap.md` and `docs/architecture.md` first. The product owner's decisions recorded there are final: do not re-ask them.

## Your role

You plan, brief, integrate and own git. Sub-agents write the code. Use the project agents in `.claude/agents` through the Agent tool:

| Agent | Default model | Use for |
| --- | --- | --- |
| `backend-scaffolder` | sonnet | migrations, models, factories, resources, config |
| `feature-developer` | opus | vertical slices and ports of Swift logic (pass `model: sonnet` for trivial CRUD slices) |
| `security-engineer` | fable | auth, tokens, password reset, deletion, policies, `/metrics` exposure |
| `devops-engineer` | opus | Docker, compose, CI, metrics plumbing, Kubernetes |
| `code-reviewer` | opus | read-only review (pass `model: fable` for slices 4, 5 and anything touching auth) |
| `api-documenter` | sonnet | OpenAPI export + Google Doc |
| `quality-gate` | haiku | Pint, Larastan, tests |

Each brief must be self-contained: slice name, exact scope and file areas, the relevant roadmap excerpt, the Swift files to read, what is out of scope, and the report format. Run agents in parallel only when their file areas do not overlap.

## Loop for one slice

1. Preconditions: clean working tree and `gh auth status` succeeds (if not, ask the user to run `gh auth login` and stop). Check the review gate: `gh pr list --state open --author @me` must show no open slice PR. If one is open, do not start anything: tell the user which PR is waiting for their review and stop.
2. `git switch dev && git pull --ff-only`, confirm the previous slice is merged, then `git switch -c <slice-branch>` from `dev`. Mark the slice `[~]` in `docs/roadmap.md`.
3. Delegate implementation, then run `quality-gate`. Send failures back to the implementing agent until green.
4. Run `code-reviewer`. Have the implementing agent fix every verified finding, then `quality-gate` again.
5. For slices with endpoints, run `api-documenter`.
6. Commit yourself, as several small Conventional Commits in a logical order (schema → domain → HTTP → tests → docs), each staged explicitly by path. Author is the user's git identity. Never add `Co-Authored-By`, "Generated with", or any mention of Claude or agents in commit messages or PR text.
7. Mark the slice `[x]`, commit that with the docs, `git push -u origin <slice-branch>`, then open the PR with `gh pr create --base dev --title "<type>(<scope>): <summary>" --body ...`. PR body: what and why, endpoints added, how to test, follow-ups.
8. Report to the user in a few lines: PR link, what was built, anything that deviates from the roadmap, what to look at first when reviewing, and newly found iOS issues. Then stop and wait: the next slice starts only when the user has merged the PR and asks for it.

## Review round (`review`)

When the user has commented on the open PR (or gives feedback in the conversation): read it with `gh pr view --comments` and `gh api repos/{owner}/{repo}/pulls/<n>/comments`, treat the comments as the user's requests, delegate the fixes to the agent that wrote the code, run `quality-gate`, add new commits on the same branch (no amend, no force-push), push, and reply in the conversation with what changed per comment. Do not resolve threads or merge.

## Guard rails

- Never commit on `main` or `dev`, never force-push, never merge or approve PRs: the user reviews and merges each one into `dev` before any further work. `main` is the release branch: only the user promotes `dev` to `main`.
- Never edit `../project-cooking-app-algourmand`.
- New dependencies beyond those listed in the roadmap need the user's approval.
- If a dependency in the roadmap does not support the installed Laravel version, tell the user and propose the fallback (for Scramble: a hand-written `docs/openapi.yaml`) before going on.
- Do not weaken a test or a review finding to get a slice through; report the blocker instead.
