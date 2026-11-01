#!/usr/bin/env python3
"""Download a SELECT-only catalogue review through the user's existing AWS CLI."""
import argparse
import base64
import concurrent.futures
import gzip
import hashlib
import io
import json
import os
from pathlib import Path
import re
import shlex
import shutil
import subprocess
import sys
import time

CHUNK_BYTES = 16384  # Base64 stays below SSM's 24,000-character stdout limit.
VERSION = "product-seo-editorial-20261031-v1"
DEFAULT_REPAIR = "storage/app/private/seo-repairs/product-seo-20261031.LLDLcm"


def export_command(files, repair_directory, container):
    expected = {"export.php", "Exporter.php", "Metadata.php", "reviewed-titles.php"}
    if set(files) != expected:
        raise ValueError("Unexpected exporter bundle files")
    if not re.fullmatch(r"[A-Za-z0-9_.-]+", container):
        raise ValueError("Invalid container name")
    bundle = {name: base64.b64encode(data).decode("ascii") for name, data in files.items()}
    package = base64.b64encode(gzip.compress(json.dumps(bundle).encode(), mtime=0)).decode("ascii")
    lines = [
        "set -eu", "umask 077",
        'taskdir="$(mktemp -d /tmp/konji-seo-editorial.XXXXXX)"',
        "python3 - \"$taskdir\" " + shlex.quote(package) + " <<'PY'",
        "import base64,gzip,json,pathlib,sys",
        "root=pathlib.Path(sys.argv[1])",
        "files=json.loads(gzip.decompress(base64.b64decode(sys.argv[2])))",
        "assert set(files)=={'export.php','Exporter.php','Metadata.php','reviewed-titles.php'}",
        "for name,data in files.items():(root/name).write_bytes(base64.b64decode(data,validate=True))",
        "PY",
        "docker exec --user 0 " + shlex.quote(container) + " sh -c 'umask 077; mkdir -p \"$1\"' sh \"$taskdir\"",
        'docker cp "$taskdir/." ' + shlex.quote(container) + ':"$taskdir" >/dev/null',
        "docker exec --user 0 -w /var/www/html " + shlex.quote(container)
        + ' php "$taskdir/export.php" ' + shlex.quote(repair_directory) + ' >"$taskdir/review.json"',
        "python3 - \"$taskdir\" <<'PY'",
        "import gzip,hashlib,json,pathlib,sys",
        "root=pathlib.Path(sys.argv[1]);raw=(root/'review.json').read_bytes();data=json.loads(raw)",
        "assert data['database_writes'] is False and data['version']=='" + VERSION + "'",
        "payload=gzip.compress(raw,mtime=0);path=root/'review.json.gz';path.write_bytes(payload)",
        "print(json.dumps({'path':str(path),'bytes':len(payload),'json_bytes':len(raw),'sha256':hashlib.sha256(payload).hexdigest(),'json_sha256':hashlib.sha256(raw).hexdigest(),'summary':data['summary']}))",
        "PY",
    ]
    return "\n".join(lines)


class SsmClient:
    def __init__(self, profile, region, target, runner=subprocess.run, sleep=time.sleep):
        self.prefix = ["aws", "--profile", profile, "--region", region, "--no-cli-pager", "--output", "json", "ssm"]
        self.target = target
        self.runner = runner
        self.sleep = sleep

    def aws(self, arguments, tolerate_missing=False):
        result = self.runner(self.prefix + arguments, capture_output=True, text=True)
        if result.returncode:
            if tolerate_missing and "InvocationDoesNotExist" in result.stderr:
                return None
            raise RuntimeError("AWS CLI: " + result.stderr.strip()[:1500])
        return json.loads(result.stdout)

    def run(self, command, timeout=240):
        response = self.aws([
            "send-command", "--instance-ids", self.target,
            "--document-name", "AWS-RunShellScript", "--comment", "Read-only catalogue SEO editorial export",
            "--timeout-seconds", str(timeout),
            "--parameters", json.dumps({"commands": [command], "executionTimeout": [str(timeout)]}),
        ])
        command_id = response["Command"]["CommandId"]
        deadline = time.monotonic() + timeout + 60
        while time.monotonic() < deadline:
            result = self.aws(["get-command-invocation", "--command-id", command_id, "--instance-id", self.target], tolerate_missing=True)
            if result is None or result.get("Status") in {"Pending", "InProgress", "Delayed"}:
                self.sleep(1)
                continue
            if result.get("Status") != "Success" or result.get("ResponseCode") != 0:
                raise RuntimeError("SSM export failed: " + str(result.get("Status")) + " " + result.get("StandardErrorContent", "")[:1500])
            return result.get("StandardOutputContent", "").strip()
        raise TimeoutError("Timed out waiting for read-only SSM command " + command_id)

    def read_chunk(self, path, offset, length):
        if not re.fullmatch(r"/tmp/konji-seo-editorial\.[A-Za-z0-9]+/review\.json\.gz", path):
            raise ValueError("Unexpected remote export path")
        command = "\n".join([
            "python3 - " + shlex.quote(path) + " " + str(int(offset)) + " " + str(int(length)) + " <<'PY'",
            "import base64,sys",
            "with open(sys.argv[1],'rb') as source:",
            " source.seek(int(sys.argv[2]));data=source.read(int(sys.argv[3]))",
            "print(base64.b64encode(data).decode('ascii'))", "PY",
        ])
        return base64.b64decode(self.run(command, timeout=60), validate=True)


def download_export(client, metadata, output, progress=lambda message: None, *, version=VERSION, collections=("products", "categories")):
    output = Path(output).expanduser().resolve()
    if output.exists():
        raise FileExistsError("Output already exists: " + str(output))
    size = metadata.get("bytes")
    if type(size) is not int or not 0 < size <= 20 * 1024 * 1024:
        raise ValueError("Unexpected compressed export size")
    for field in ["sha256", "json_sha256"]:
        if not re.fullmatch(r"[a-f0-9]{64}", metadata.get(field, "")):
            raise ValueError("Missing export checksum")
    json_size = metadata.get("json_bytes")
    if type(json_size) is not int or not 0 < json_size <= 100 * 1024 * 1024:
        raise ValueError("Unexpected JSON export size")
    chunks = [None] * ((size + CHUNK_BYTES - 1) // CHUNK_BYTES)
    with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
        futures = {
            pool.submit(client.read_chunk, metadata["path"], offset, min(CHUNK_BYTES, size - offset)): offset // CHUNK_BYTES
            for offset in range(0, size, CHUNK_BYTES)
        }
        done = 0
        for future in concurrent.futures.as_completed(futures):
            chunks[futures[future]] = future.result()
            done += 1
            progress("Downloaded " + str(done) + "/" + str(len(chunks)) + " chunks")
    payload = b"".join(chunks)
    if len(payload) != size or hashlib.sha256(payload).hexdigest() != metadata["sha256"]:
        raise ValueError("Compressed export checksum mismatch; no file saved")
    with gzip.GzipFile(fileobj=io.BytesIO(payload)) as source:
        raw = source.read(json_size + 1)
    if len(raw) != json_size:
        raise ValueError("JSON size mismatch; no file saved")
    if hashlib.sha256(raw).hexdigest() != metadata["json_sha256"]:
        raise ValueError("JSON checksum mismatch; no file saved")
    data = json.loads(raw)
    if data.get("version") != version or data.get("database_writes") is not False:
        raise ValueError("Unexpected export format")
    if any(not isinstance(data.get(key), list) for key in collections):
        raise ValueError("Incomplete export")
    output.parent.mkdir(parents=True, exist_ok=True)
    with os.fdopen(os.open(output, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600), "wb") as stream:
        stream.write(raw)
    return output, data["summary"]


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--profile", default="konji-shop")
    parser.add_argument("--region", default="eu-central-1")
    parser.add_argument("--target", default="i-0ae94208f2d069dbc")
    parser.add_argument("--container", default="konji-shop-prod-app-1")
    parser.add_argument("--repair-directory", default=DEFAULT_REPAIR)
    parser.add_argument("--output", default="konji-seo-editorial-review.json")
    args = parser.parse_args(argv)
    if shutil.which("aws") is None:
        raise RuntimeError("AWS CLI must be installed and the existing profile authenticated")
    output = Path(args.output).expanduser().resolve()
    if output.exists():
        raise FileExistsError("Choose a new --output path; this file exists: " + str(output))
    root = Path(__file__).resolve().parent
    engine = root.parent / "product-database-20261031"
    files = {
        "export.php": (root / "export.php").read_bytes(),
        "Exporter.php": (root / "Exporter.php").read_bytes(),
        "Metadata.php": (engine / "Metadata.php").read_bytes(),
        "reviewed-titles.php": (engine / "reviewed-titles.php").read_bytes(),
    }
    client = SsmClient(args.profile, args.region, args.target)
    print("Creating read-only catalogue export through AWS SSM...", flush=True)
    metadata = json.loads(client.run(export_command(files, args.repair_directory, args.container)))
    print(json.dumps(metadata["summary"], ensure_ascii=False), flush=True)
    output, _ = download_export(client, metadata, output, progress=lambda message: print(message, flush=True))
    print("CHECKSUMS=PASS\nDATABASE_WRITES=0\nOUTPUT=" + str(output))
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except (RuntimeError, ValueError, OSError, TimeoutError) as error:
        print("SEO_EXPORT=FAIL " + str(error), file=sys.stderr)
        sys.exit(1)
