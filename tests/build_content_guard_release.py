"""Deterministic extension-only archive for the qualified content guard patch."""
from pathlib import Path
import hashlib, json, zipfile

root = Path(__file__).resolve().parents[1]
source = root / 'magicsmtp'
target = root / 'releases'
target.mkdir(exist_ok=True)
archive = target / 'magicsmtp-1.3.0-content-guard-20261104.zip'
paths = sorted(p for p in source.rglob('*') if p.is_file())
hashes = {}
with zipfile.ZipFile(archive, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as out:
    for path in paths:
        relative = path.relative_to(root)
        if path.is_symlink() or path.suffix not in {'.php', '.js', '.css', '.md', '.sql'}:
            raise ValueError('Unexpected release file')
        raw = path.read_bytes()
        info = zipfile.ZipInfo(relative.as_posix(), (2026, 11, 4, 0, 0, 0))
        info.compress_type = zipfile.ZIP_DEFLATED
        info.external_attr = 0o100644 << 16
        info.create_system = 3
        out.writestr(info, raw)
        hashes[relative.as_posix()] = hashlib.sha256(raw).hexdigest()
with zipfile.ZipFile(archive) as check:
    assert check.testzip() is None
    assert all(hashlib.sha256(check.read(name)).hexdigest() == digest for name, digest in hashes.items())
manifest = {'archive': archive.name, 'sha256': hashlib.sha256(archive.read_bytes()).hexdigest(),
            'bytes': archive.stat().st_size, 'fileCount': len(paths), 'files': hashes}
(target / 'magicsmtp-1.3.0-content-guard-20261104.manifest.json').write_text(json.dumps(manifest, indent=2)+'\n')
print(json.dumps({k:v for k,v in manifest.items() if k != 'files'}))
