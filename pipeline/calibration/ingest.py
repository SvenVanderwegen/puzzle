"""Playtest calibration ingest (stub — v1 has no live recalibration).

Reads a solves CSV export and prints per-weekday completion/hint rates next
to the product §7 targets, as input for a human retune of the band
thresholds in burnfront_pipeline/grader.py. Deliberately minimal: the
calibration loop is a post-launch concern; this file pins the interface.

Expected CSV columns:
    date,puzzle_id,grade_tier,started,contained,hint_s1,hint_s2,hint_s3
"""

import csv
import sys
from collections import defaultdict

# Product §7: daily completion targets per weekday (contained / started).
TARGETS = {"mon": 0.75, "tue": 0.65, "wed": 0.60, "thu": 0.60,
           "fri": 0.55, "sat": 0.45, "sun": 0.60}

WEEKDAYS = ("mon", "tue", "wed", "thu", "fri", "sat", "sun")


def ingest(path):
    import datetime
    by_day = defaultdict(lambda: {"started": 0, "contained": 0})
    with open(path, newline="", encoding="utf-8") as f:
        for row in csv.DictReader(f):
            day = WEEKDAYS[datetime.date.fromisoformat(row["date"]).weekday()]
            by_day[day]["started"] += int(row["started"])
            by_day[day]["contained"] += int(row["contained"])
    return by_day


def main(argv):
    if len(argv) != 2:
        print("usage: python calibration/ingest.py solves.csv", file=sys.stderr)
        return 2
    by_day = ingest(argv[1])
    for day in WEEKDAYS:
        d = by_day.get(day)
        if not d or not d["started"]:
            print(f"{day}: no data")
            continue
        rate = d["contained"] / d["started"]
        print(f"{day}: completion {rate:.2f} (target {TARGETS[day]:.2f}, "
              f"n={d['started']})")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
