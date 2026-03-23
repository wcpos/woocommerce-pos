---
name: electron-dev
user-invocable: true
description: Use when the user wants to spin up Electron dev from a worktree with logs visible in a Terminal window.
allowed-tools:
  - Bash
  - Read
---

# Electron Dev Worktree

Spins up the Electron app from an isolated worktree with logs in a visible Terminal window.

**Announce at start:** "Setting up Electron dev worktree."

## Steps

Run each step sequentially. Do NOT skip steps. Do NOT use `run_in_background`.

### 1. Pull latest

```bash
git pull origin main
```

### 2. Create worktree

```bash
git worktree add .worktrees/electron-dev -b electron-dev-session
```

If the branch already exists, remove the old worktree first:

```bash
git worktree remove .worktrees/electron-dev --force 2>/dev/null
git branch -D electron-dev-session 2>/dev/null
git worktree add .worktrees/electron-dev -b electron-dev-session
```

### 3. Init electron submodule and pull latest

First init the submodule (checks out whatever commit the monorepo pointer references), then pull latest from electron's main. The monorepo submodule pointer is often behind — skipping the pull means you get stale electron code.

```bash
cd <worktree-path> && git submodule update --init apps/electron
cd <worktree-path>/apps/electron && git checkout main && git pull origin main
```

### 4. Install dependencies

```bash
cd <worktree-path> && pnpm install --no-frozen-lockfile
```

### 5. Rebuild native modules

```bash
cd <worktree-path> && pnpm electron rebuild:all
```

This is required — Electron needs native modules rebuilt for its Node version.

### 6. Kill conflicting ports

Kill any existing processes on ports 8088 (Expo/Metro) and 9000 (Electron Forge logger):

```bash
lsof -ti :8088 | xargs kill 2>/dev/null; lsof -ti :9000 | xargs kill 2>/dev/null; echo "Ports cleared"
```

### 7. Launch in a visible Terminal window

**CRITICAL: Write the launch script to a temp file, then execute it.**

Inline osascript drops the `cd` from the command string. Always use a temp file:

```bash
WORKTREE_PATH="<absolute-worktree-path>"
cat > /tmp/launch-electron-dev.sh << SCRIPT
#!/usr/bin/env bash
osascript <<'APPLESCRIPT'
tell application "Terminal"
  do script "cd $WORKTREE_PATH && pnpm --filter @wcpos/app-electron dev"
  activate
end tell
APPLESCRIPT
SCRIPT
bash /tmp/launch-electron-dev.sh
```

Report: "Electron dev launched in Terminal window. Logs are visible there."

## Things that will break if you get them wrong

| Mistake | Result |
|---------|--------|
| Skip submodule init | Electron app directory is empty, nothing runs |
| Skip `git pull` in submodule after init | Monorepo pointer is often stale — you get old electron code missing recent fixes |
| Skip `pnpm electron rebuild:all` | Native module crashes at runtime |
| Don't kill port 8088 | Expo can't bind, white screen |
| Don't kill port 9000 | Electron Forge logger crashes |
| Use `run_in_background` | User can't see logs |
| Use inline osascript | `cd` gets dropped, runs from `~`, pnpm can't find workspace |
| Use `EXPO_PORT` env var | Not supported in current electron submodule, does nothing |

## Never

- Use `run_in_background` for the dev server
- Use inline `osascript` — always write to a temp file first
- Set `EXPO_PORT` — not supported in the current electron submodule
- Checkout branches in the main working tree
