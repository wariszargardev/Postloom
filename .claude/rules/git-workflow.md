# Git workflow

## Long-lived branches

| Branch    | Use                                                           |
| --------- | ------------------------------------------------------------- |
| `main`    | Production-ready. Only receives `stage`.                      |
| `stage`   | Final check before `main`. Receives `test`.                   |
| `test`    | Testing several finished pieces together. Receives `develop`. |
| `develop` | Daily integration. Every piece PR targets this branch.        |

Promotion (only when Waris says): `develop → test → stage → main`, each through a PR.

## Piece branches

- Cut from the latest `develop`.
- Name: `<type>/<NN>-<short-kebab-name>`. Types: `feat`, `fix`, `chore`, `docs`. `NN` = the piece number from `docs/roadmap.md` (fixes use the piece they fix).
  Examples: `chore/00-project-setup`, `feat/03-linkedin-connect`, `fix/07-scheduler-double-post`.
- PR title: `[NN] <Title from roadmap>`.
- PR description uses `.github/pull_request_template.md`: summary, what changed, how to test, KB and skill paths, logging added, tests run.
- One push per piece when it's ready; more pushes only for review fixes.

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
