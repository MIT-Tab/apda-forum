"""Build a checksummed plugin artifact from the exact clean CI checkout."""
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys

root = Path(__file__).resolve().parents[2]
output = Path(sys.argv[1]).resolve()
commit = subprocess.check_output(['git', '-C', str(root), 'rev-parse', 'HEAD'], text=True).strip()
if os.environ.get('GITHUB_SHA', commit) != commit:
    raise SystemExit('Checkout does not match the tested commit')
if subprocess.check_output(['git', '-C', str(root), 'status', '--porcelain', '--untracked-files=no']):
    raise SystemExit('Release builds require a clean checkout')
output.mkdir(parents=True, exist_ok=True)
archive = output / 'asgaros-forum.zip'
subprocess.run([sys.executable, str(root / 'scripts/package-plugin.py'), str(archive)], check=True)
digest = hashlib.sha256(archive.read_bytes()).hexdigest()
source = (root / 'includes/forum-database.php').read_text()
schema = int(re.search(r'private\s+\$db_version\s*=\s*(\d+)', source)[1])
(output / 'manifest.json').write_text(json.dumps({'commit': commit, 'sha256': digest, 'schema': schema}, indent=2)+'\n')
if os.environ.get('GITHUB_OUTPUT'):
    with open(os.environ['GITHUB_OUTPUT'], 'a') as result:
        result.write('digest='+digest+'\n')
print('Release built for '+commit)
