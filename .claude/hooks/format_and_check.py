#!/usr/bin/env python3
"""PostToolUse hook (matcher: Write|Edit).

- Auto-formats the touched file (php-cs-fixer for PHP, eslint --fix for TS/TSX).
- If a migration file under `migrations/` was touched, checks whether it still
  contains `check_room_count` and warns (does not block — PostToolUse can only
  give feedback, not undo the edit) if it looks like the constraint was removed.

Exit 0 always (formatting failures shouldn't halt the agent); messages on
stdout are informational only for this hook.
"""
import json
import subprocess
import sys
from pathlib import Path


def main() -> None:
    event = json.load(sys.stdin)
    file_path = event.get("tool_input", {}).get("file_path", "")
    if not file_path:
        sys.exit(0)

    path = Path(file_path)

    if path.suffix == ".php":
        subprocess.run(
            ["vendor/bin/php-cs-fixer", "fix", str(path), "--quiet"],
            check=False,
        )
        if "migrations" in path.parts:
            try:
                content = path.read_text()
            except OSError:
                content = ""
            if "room_type_inventory" in content and "check_room_count" not in content:
                print(
                    "NOTE: this migration touches room_type_inventory but does not "
                    "mention check_room_count — verify the overbooking constraint "
                    "is still in place (CLAUDE.md invariant #2).",
                    file=sys.stderr,
                )

    elif path.suffix in (".ts", ".tsx"):
        subprocess.run(
            ["npx", "--prefix", "frontend", "eslint", "--fix", str(path)],
            check=False,
        )

    sys.exit(0)


if __name__ == "__main__":
    main()
