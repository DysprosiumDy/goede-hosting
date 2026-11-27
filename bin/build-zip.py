"""Build the release zip of the Goede Hosting plugin.

    python bin/build-zip.py [out.zip]

Default output: dist/goede-hosting.zip (the asset name the updater looks for in a
GitHub Release). The zip holds one folder, goede-hosting/, with the plugin files;
development files (.git, bin, dist, phpcs.xml.dist, .gitignore) are left out.
Checks that the Version header and GOEDE_HOSTING_VERSION match.
"""
import re
import sys
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SKIP_TOP = {'.git', 'bin', 'dist', '.gitignore', '.gitattributes', 'phpcs.xml.dist'}

main = (ROOT / 'goede-hosting.php').read_text(encoding='utf-8')
header = re.search(r'^\s*\*\s*Version:\s*(\S+)', main, re.M)
const = re.search(r"define\(\s*'GOEDE_HOSTING_VERSION',\s*'([^']+)'", main)
if not header or not const or header.group(1) != const.group(1):
	raise SystemExit('Version header and GOEDE_HOSTING_VERSION differ: %s / %s' % (header and header.group(1), const and const.group(1)))
version = header.group(1)

out = Path(sys.argv[1]) if len(sys.argv) > 1 else ROOT / 'dist' / 'goede-hosting.zip'
out.parent.mkdir(parents=True, exist_ok=True)
count = 0
with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as z:
	for f in sorted(ROOT.rglob('*')):
		rel = f.relative_to(ROOT)
		if f.is_file() and rel.parts[0] not in SKIP_TOP:
			z.write(f, 'goede-hosting/' + rel.as_posix())
			count += 1
print('plugin %s: %s (%d files, %d KB)' % (version, out, count, out.stat().st_size // 1024))
