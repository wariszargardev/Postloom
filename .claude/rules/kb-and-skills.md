# Every piece ships with these

A piece is not done until all of these exist or are updated in the same branch:

1. **KB doc** `knowledge-base/<feature>/README.md` with these sections: Purpose, Flow, Entry points (routes, jobs, commands), Data (tables/fields), Rules, Logging (feature enum + key messages), Config (`.env` keys), Tests, Known limits. Link it in `knowledge-base/README.md`.
2. **Skill** `.claude/skills/<feature>/SKILL.md`: frontmatter `name` = folder name, `description` under 1024 characters (aim for under 900) saying when to use it; body points to the KB doc, lists the key files and explains how to debug from the logs. Add it to the routing table in `.claude/skills/postloom/SKILL.md`.
3. **Rules**: if the piece introduces a rule that applies everywhere, add it to `CLAUDE.md` or `.claude/rules/`.
4. **Roadmap**: update the piece's status and branch in `docs/roadmap.md`.
5. **Logging**: follows `logging.md`.
6. **Tests**: Pest tests for changed behaviour, run and passing.

Update an existing KB doc or skill before creating a new one. Never keep two docs for the same feature.
