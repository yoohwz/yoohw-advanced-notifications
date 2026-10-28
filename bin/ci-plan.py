#!/usr/bin/env python3
"""Select representative draft CI or the declared support boundaries."""

import json
import os
from pathlib import Path
import re


def plan(event, draft, readme):
    def header(name):
        match = re.search(r"^" + re.escape(name) + r": ([0-9]+\.[0-9]+(?:\.[0-9]+)?)$", readme, re.M)
        if not match:
            raise ValueError("Missing or invalid readme header: " + name)
        return match.group(1)

    minimum = header("Requires at least")
    tested = header("Tested up to")
    php = header("Requires PHP")
    if php != "8.1":
        raise ValueError("Update the CI PHP matrix when the declared minimum changes")
    full = event != "pull_request" or draft != "true"
    pairs = [(minimum, php), (minimum, "8.2"), (tested, php), (tested, "8.4")]
    if not full:
        pairs = [(tested, "8.4")]
    matrix = {"include": [dict(wordpress=wp, php=php_version) for wp, php_version in dict.fromkeys(pairs)]}
    return {"full": str(full).lower(), "matrix": json.dumps(matrix, separators=(",", ":"))}


if __name__ == "__main__":
    values = plan(os.environ["EVENT_NAME"], os.environ.get("PR_DRAFT", "false"), Path("readme.txt").read_text())
    with open(os.environ["GITHUB_OUTPUT"], "a") as output:
        for key, value in values.items():
            print(f"{key}={value}", file=output)
