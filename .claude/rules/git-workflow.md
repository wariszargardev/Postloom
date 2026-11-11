# Git workflow

## Long-lived branches

| Branch    | Use                                                           |
| --------- | ------------------------------------------------------------- |
| `main`    | Production-ready. Only receives `stage`.                      |
| `stage`   | Final check before `main`. Receives `test`.                   |
| `test`    | Testing several finished pieces together. Receives `develop`. |
| `develop` | Daily integration. Every piece PR targets this branch.        |

Promotion (only when Waris says): `develop → test → stage → main`, each through a PR.

## Where to work and one piece at a time (Waris, 2026-11-11)

- Always work in the main folder `D:\Afia Insurance\Projects\Personal\postloom`. Never create a git worktree or a second checkout for Postloom; switch branches in this folder (`git checkout <branch>`).
- After switching branches: `composer install` if `composer.lock` changed, `npm run build`, `php artisan config:clear`, then ask Waris to restart with `stop.ps1` + `start.ps1` (queue workers keep the old code in memory until restarted).
- One piece at a time: the previous piece's PR must be **merged** before a new piece starts. If it is still open, finish or fix it on its own branch first and wait for Waris to merge; only then cut the next branch from the updated `develop`.

## Piece branches

- Cut from the latest `develop`, after the previous piece's PR is merged.
- Name: `<type>/<NN>-<short-kebab-name>`. Types: `feat`, `fix`, `chore`, `docs`. `NN` = the piece number from `docs/roadmap.md` (fixes use the piece they fix).
  Examples: `chore/00-project-setup`, `feat/03-linkedin-connect`, `fix/07-scheduler-double-post`.
- PR title: `[NN] <Title from roadmap>`.
- PR description uses `.github/pull_request_template.md`: summary, what changed, how to test, KB and skill paths, logging added, tests run.
- One push per piece when it's ready; more pushes only for review fixes.

## Fixes while a PR is open

- If the piece's PR is still **open**, every fix for it (bug found in testing, review comment, CI failure, doc correction) is committed on **the same branch** and pushed, so it lands in **the same PR**. Never open a second PR or a `fix/...` branch for it, and never ask Waris to close the PR.
- After pushing, add a line about the fix to the PR description (what broke, what changed, tests).
- A `fix/NN-...` branch and new PR are only for bugs found **after** the piece's PR is merged.

## Opening the PR (Claude does it directly)

When a piece is done and its tests pass, Claude commits, pushes the piece branch and **opens the PR into `develop` itself**. Waris does not open PRs by hand, and does not need to ask for this separately.

1. `gh auth switch --user wariszargardev` (the PC's default `gh` account is the office one, which cannot see this private repo).
2. `gh pr create --repo wariszargardev/Postloom --base develop --head <branch> --title "[NN] <Title>" --body-file <filled PR template>`
3. `gh auth switch --user warisfullstackdev` to put the office account back.
4. Give Waris the PR link.

If `wariszargardev` is not logged in to `gh`, ask Waris to run `gh auth login` once (browser login), then continue. Promotion PRs (`develop → test → stage → main`) are also opened by Claude when Waris says to promote.

## Never

- Merge a PR. Waris merges.
- Commit, push or open a PR for unfinished work.
- Force-push, rebase shared branches or delete branches without asking.
- Commit `.env`, tokens, keys or uploaded media.

## Remote

`git@github-personal:wariszargardev/Postloom.git` (SSH alias `github-personal` in `~/.ssh/config`).
