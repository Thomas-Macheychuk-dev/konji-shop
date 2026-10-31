import base64
import gzip
import hashlib
import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
import unittest

spec = importlib.util.spec_from_file_location("seo_download", Path(__file__).with_name("download.py"))
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class FakeChunks:
    def __init__(self, data):
        self.data = data
        self.calls = []

    def read_chunk(self, path, offset, length):
        self.calls.append((path, offset, length))
        return self.data[offset:offset + length]


def fixture():
    data = {"version": module.VERSION, "database_writes": False,
            "summary": {"exported_products": 1}, "products": [{"id": 1, "name": "Produkt ABC"}], "categories": []}
    raw = json.dumps(data, ensure_ascii=False).encode()
    payload = gzip.compress(raw, mtime=0)
    metadata = {"path": "/tmp/konji-seo-editorial.ABC123/review.json.gz", "bytes": len(payload), "json_bytes": len(raw),
                "sha256": hashlib.sha256(payload).hexdigest(), "json_sha256": hashlib.sha256(raw).hexdigest()}
    return raw, payload, metadata


class DownloadTests(unittest.TestCase):
    def test_download_validates_and_saves_json(self):
        raw, payload, metadata = fixture()
        with tempfile.TemporaryDirectory() as temporary:
            output, summary = module.download_export(FakeChunks(payload), metadata, Path(temporary) / "review.json")
            self.assertEqual(output.read_bytes(), raw)
            self.assertEqual(summary["exported_products"], 1)

    def test_corruption_never_creates_output(self):
        _, payload, metadata = fixture()
        with tempfile.TemporaryDirectory() as temporary:
            output = Path(temporary) / "review.json"
            with self.assertRaisesRegex(ValueError, "checksum mismatch"):
                module.download_export(FakeChunks(payload[:-1] + b"X"), metadata, output)
            self.assertFalse(output.exists())

    def test_existing_file_is_not_overwritten(self):
        _, payload, metadata = fixture()
        with tempfile.TemporaryDirectory() as temporary:
            output = Path(temporary) / "review.json"
            output.write_text("keep")
            client = FakeChunks(payload)
            with self.assertRaises(FileExistsError):
                module.download_export(client, metadata, output)
            self.assertEqual(output.read_text(), "keep")
            self.assertEqual(client.calls, [])

    def test_json_size_is_bounded(self):
        _, payload, metadata = fixture()
        metadata["json_bytes"] = 1
        with tempfile.TemporaryDirectory() as temporary:
            output = Path(temporary) / "review.json"
            with self.assertRaisesRegex(ValueError, "JSON size mismatch"):
                module.download_export(FakeChunks(payload), metadata, output)
            self.assertFalse(output.exists())

    def test_chunks_fit_ssm_stdout_limit(self):
        self.assertLess(len(base64.b64encode(b"X" * module.CHUNK_BYTES)), 24000)

    def test_remote_bundle_rejects_unexpected_filenames(self):
        with self.assertRaisesRegex(ValueError, "bundle"):
            module.export_command({"../unsafe.php": b"data"}, "private", "app")

    def test_ssm_poll_handles_initial_invocation_delay(self):
        sequence = [
            subprocess.CompletedProcess([], 0, json.dumps({"Command": {"CommandId": "command"}}), ""),
            subprocess.CompletedProcess([], 1, "", "InvocationDoesNotExist"),
            subprocess.CompletedProcess([], 0, json.dumps({"Status": "InProgress"}), ""),
            subprocess.CompletedProcess([], 0, json.dumps({"Status": "Success", "ResponseCode": 0, "StandardOutputContent": "ok"}), ""),
        ]
        client = module.SsmClient("profile", "region", "instance", runner=lambda *a, **kw: sequence.pop(0), sleep=lambda _: None)
        self.assertEqual(client.run("read-only command"), "ok")
        self.assertEqual(sequence, [])

    def test_unsafe_remote_read_path_is_rejected_before_aws(self):
        client = module.SsmClient("profile", "region", "instance", runner=lambda *a, **kw: self.fail("AWS should not be called"))
        with self.assertRaisesRegex(ValueError, "export path"):
            client.read_chunk("/etc/passwd", 0, 20)


if __name__ == "__main__":
    unittest.main()
