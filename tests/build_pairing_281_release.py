"""Deterministic extension-only 1.3.1 package; never includes licensed core candidates."""
from pathlib import Path
import hashlib, json, zipfile

root = Path(__file__).resolve().parents[1]
target = root / 'releases' / 'magicsmtp-1.3.1.zip'
assert "public $version = '1.3.1';" in (root / 'magicsmtp/MagicsmtpExt.php').read_text()
files = sorted(p for p in (root / 'magicsmtp').rglob('*') if p.is_file())
hashes = {}
with zipfile.ZipFile(target, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as out:
    for path in files:
        assert not path.is_symlink() and path.suffix in {'.php', '.js', '.css', '.md', '.sql'}
        name = path.relative_to(root).as_posix()
        raw = path.read_bytes()
        info = zipfile.ZipInfo(name, (2026, 11, 6, 0, 0, 0))
        info.compress_type = zipfile.ZIP_DEFLATED
        info.external_attr = 0o100644 << 16
        info.create_system = 3
        out.writestr(info, raw)
        hashes[name] = hashlib.sha256(raw).hexdigest()
with zipfile.ZipFile(target) as check:
    assert check.testzip() is None
    assert all(hashlib.sha256(check.read(n)).hexdigest() == h for n, h in hashes.items())
manifest = {'archive': target.name, 'sha256': hashlib.sha256(target.read_bytes()).hexdigest(),
            'bytes': target.stat().st_size, 'fileCount': len(files), 'files': hashes}
target.with_suffix('.manifest.json').write_text(json.dumps(manifest, indent=2) + '\n')
print(json.dumps({k: v for k, v in manifest.items() if k != 'files'}))
