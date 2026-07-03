"""Pipeline CLI (WS-05).

    python -m burnfront_pipeline.cli emit --date 20260706 --seeds seeds.json
    python -m burnfront_pipeline.cli verify --dir dist/content/v20260706-1 \
        --pubkey tests/fixtures/dev-signing-key.pub
    python -m burnfront_pipeline.cli measure --profile 6x6-minimal --count 200

Determinism: the date is an argument, never read from the clock. Exit
codes: 0 ok, 2 refusal/curation failure (nothing usable was written).
"""

import argparse
import re
import sys

from . import curator, emitter, measure, signer


def _cmd_emit(args):
    if not re.fullmatch(r"[0-9]{8}", args.date):
        print(f"emit: --date must be YYYYMMDD, got {args.date!r}",
              file=sys.stderr)
        return 2
    content_version = f"v{args.date}-{args.seq}"
    cfg = curator.load_seeds(args.seeds)
    key = signer.load_signing_key(args.key)
    dailies = curator.curate_dailies(cfg, args.days)
    academy = [] if args.skip_pack else curator.curate_academy(cfg)
    curator.assign_ids(dailies + academy)
    version_dir = emitter.emit_content(
        args.out, content_version, dailies, academy, key)
    print(f"emitted {len(dailies)} dailies "
          f"({dailies[0].date} .. {dailies[-1].date}), "
          f"{len(academy)} academy boards -> {version_dir}")
    return 0


def _cmd_verify(args):
    verify_key = signer.load_verify_key(args.pubkey)
    verified = emitter.verify_content(args.dir, verify_key)
    print(f"verified {len(verified)} files in {args.dir}")
    return 0


def _cmd_measure(args):
    rows = measure.run_measure(args.profile, args.count, args.jobs, args.out)
    import json as _json
    print(_json.dumps(measure.summarize(rows), indent=1, sort_keys=True))
    return 0


def build_parser():
    p = argparse.ArgumentParser(prog="burnfront-pipeline")
    sub = p.add_subparsers(dest="command", required=True)

    e = sub.add_parser("emit", help="curate and emit a content version")
    e.add_argument("--date", required=True,
                   help="content date, YYYYMMDD (an input, not the clock)")
    e.add_argument("--seq", type=int, default=1,
                   help="sequence number within the date (content_version v{date}-{seq})")
    e.add_argument("--seeds", required=True, help="seeds json path")
    e.add_argument("--days", type=int, default=60,
                   help="number of dailies from the seeds start_date")
    e.add_argument("--out", default="dist/content", help="output root")
    e.add_argument("--key", default=None,
                   help=f"signing key path (default ${signer.ENV_VAR})")
    e.add_argument("--skip-pack", action="store_true",
                   help="emit dailies only (no academy pack)")
    e.set_defaults(func=_cmd_emit)

    v = sub.add_parser("verify", help="verify a content version directory")
    v.add_argument("--dir", required=True)
    v.add_argument("--pubkey", required=True)
    v.set_defaults(func=_cmd_verify)

    m = sub.add_parser("measure", help="grading distribution measurement")
    m.add_argument("--profile", required=True,
                   choices=sorted(measure.PROFILES))
    m.add_argument("--count", type=int, default=200)
    m.add_argument("--jobs", type=int, default=1)
    m.add_argument("--out", default="measure.jsonl")
    m.set_defaults(func=_cmd_measure)
    return p


def main(argv=None):
    args = build_parser().parse_args(argv)
    try:
        return args.func(args)
    except (emitter.RefusalError, curator.CurationError,
            signer.SigningError) as exc:
        print(f"abort: {exc}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    sys.exit(main())
