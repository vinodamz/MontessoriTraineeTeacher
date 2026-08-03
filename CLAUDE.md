# Little Graduates — working notes for Claude

Conventions for anyone (human or agent) working in this repository.

## gstack

This project uses [gstack](https://github.com/garrytan/gstack) for its skills.

**Install it once, on your own machine:**

```bash
git clone --single-branch --depth 1 https://github.com/garrytan/gstack.git ~/.claude/skills/gstack \
  && cd ~/.claude/skills/gstack && ./setup
```

It installs to your home directory, not to this repo, so every person sets it
up themselves. Read `setup` before running it, as you would with any script
you are about to execute.

### Web browsing

**Use the `/browse` skill from gstack for all web browsing.**

**Never use the `mcp__claude-in-chrome__*` tools.** `/browse` is the single
supported path; going around it produces results nobody else can reproduce.

### Available skills

| | | |
|---|---|---|
| `/office-hours` | `/plan-ceo-review` | `/plan-eng-review` |
| `/plan-design-review` | `/design-consultation` | `/design-shotgun` |
| `/design-html` | `/review` | `/ship` |
| `/land-and-deploy` | `/canary` | `/benchmark` |
| `/browse` | `/connect-chrome` | `/qa` |
| `/qa-only` | `/design-review` | `/setup-browser-cookies` |
| `/setup-deploy` | `/setup-gbrain` | `/retro` |
| `/investigate` | `/document-release` | `/document-generate` |
| `/codex` | `/cso` | `/autoplan` |
| `/plan-devex-review` | `/devex-review` | `/careful` |
| `/freeze` | `/guard` | `/unfreeze` |
| `/gstack-upgrade` | `/learn` | |
