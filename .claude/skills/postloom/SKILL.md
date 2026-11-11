---
name: postloom
description: Start here for any work in the Postloom repo (personal social media scheduler — Laravel 13, Inertia Vue, MySQL). Use when planning or building a piece, finding where a feature lives, choosing the branch name or PR title, or checking what a piece must ship with (KB doc, skill, rules, roadmap, logging, tests). Routes to the feature skill for the area being changed.
---

# Postloom

## When to use

- Any task in this repo, before opening files.
- "Start piece NN", "what's next", "which branch", "where is X".

## Steps

0. Work only in `D:\Afia Insurance\Projects\Personal\postloom` (no worktrees). Before a new piece, check the previous piece's PR is merged; if not, finish it first and wait for Waris to merge. See "Where to work" in `.claude/rules/git-workflow.md`.
1. Read `CLAUDE.md` and `docs/roadmap.md` to find the piece, its branch name and title.
2. Open the feature skill from the table below, then its KB doc.
3. Plan the piece (files, approach, logging points, tests, KB/skill updates, risks) and wait for "approved".
4. Build on a branch named per `.claude/rules/git-workflow.md`.
5. Ship the checklist in `.claude/rules/kb-and-skills.md`.

## Feature skills

| Area                                 | Skill                 | KB                                             |
| ------------------------------------ | --------------------- | ---------------------------------------------- |
| Setup, processes, database           | `postloom` (this one) | `knowledge-base/project-setup/README.md`       |
| Logging                              | `logging`             | `knowledge-base/logging/README.md`             |
| Login, owner account                 | `auth`                | `knowledge-base/auth/README.md`                |
| Workspaces, social accounts          | `workspaces-accounts` | `knowledge-base/workspaces-accounts/README.md` |
| LinkedIn connect and posting         | `linkedin`            | `knowledge-base/linkedin/README.md`            |
| Media library, uploads               | `media`               | `knowledge-base/media/README.md`               |
| Posts, editor, platform rules        | `posts`               | `knowledge-base/posts/README.md`               |
| Calendar, drag to reschedule         | `calendar`            | `knowledge-base/calendar/README.md`            |
| Scheduler, publishing queue, retries | `scheduler`           | `knowledge-base/scheduler/README.md`           |
| YouTube connect, token renewal       | `youtube`             | `knowledge-base/youtube/README.md`             |
| Look and feel, tokens, components    | `design`              | `knowledge-base/design/README.md`              |
| Facebook Pages connect, Graph errors | `facebook`            | `knowledge-base/facebook/README.md`            |
| TikTok connect, tokens, publishing   | `tiktok`              | `knowledge-base/tiktok/README.md`              |

Add a row here whenever a piece adds a new skill.
