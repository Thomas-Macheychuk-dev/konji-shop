# Herdegen release integration tests

`tests/Feature/Console/HerdegenReleaseImportTest.php` validates the exact
HERDEGEN-18D4 operational release archive. The ZIP is not committed to the
repository and is not provisioned by the standard GitHub Actions workflow.

A fresh checkout explicitly skips these four release integration tests when
the archive is absent. Other Herdegen tests continue to run normally.
No download, production storage access, or importer changes are introduced.

To run the release tests, supply the verified archive locally:

```bash
HERDEGEN_RELEASE_ARCHIVE=/absolute/path/herdegen18d4-transfer.zip \
    ./vendor/bin/pest tests/Feature/Console/HerdegenReleaseImportTest.php
```

The original default path is also supported:
`storage/app/private/scrapers/herdegen/releases/herdegen18d4-transfer.zip`.

An explicitly configured path must exist. An existing archive must match
SHA-256 `ce1258c94cd7a973d753761f6259225cdb9978aadc0cd575c9003d33814e755c`.
Missing explicit inputs, incorrect checksums, and import assertion failures
fail the tests; they are never converted into skips.

The suite uses the existing isolated SQLite in-memory database and fake
public storage. It checks dry-run behavior, complete draft import, duplicate
import rejection, and database rollback. A standard CI pass with four skips
does not establish that this particular release archive imports successfully.
