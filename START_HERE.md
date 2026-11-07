# Start here

This folder is a copy of `ak7shuvo/dudhwala` (commit 8e2efc5, without git history) plus the rebuild prompt.

1. Create a new empty GitHub repo.
2. Upload this folder's contents (see the commands below, the web uploader limits the number of files).
3. Start a new Claude Code session on that repo and paste the full text of `REBUILD_PROMPT.md` as the first message.
4. Answer the six questions at the end of the prompt before it writes code.

```bash
unzip dudhwala-for-rebuild.zip && cd dudhwala
git init -b main
git add -A && git commit -m "Import Dudhwala as the rebuild reference"
git remote add origin https://github.com/YOUR-USER/YOUR-NEW-REPO.git
git push -u origin main
```

The old `apps/`, `packages/` and `demo/` stay untouched as the behaviour reference. New work goes on a branch `rebuild-hosting`.
