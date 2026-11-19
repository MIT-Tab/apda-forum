"""Package tracked runtime files into a canonical WordPress plugin ZIP."""
import pathlib
import subprocess
import sys
import zipfile

root = pathlib.Path(__file__).resolve().parents[1]
output = pathlib.Path(sys.argv[1] if len(sys.argv) > 1 else '/tmp/asgaros-forum-apda.zip').resolve()
allowed = {'admin', 'includes', 'integrations', 'js', 'languages', 'libs', 'skin', 'widgets'}
files = subprocess.check_output(['git', '-C', str(root), 'ls-files', '-z']).decode().split('\0')
with zipfile.ZipFile(output, 'w', zipfile.ZIP_DEFLATED) as archive:
    for name in files:
        if not name:
            continue
        path = pathlib.PurePosixPath(name)
        if path.parts[0] in allowed or name in {'asgaros-forum.php', 'uninstall.php', 'readme.txt', 'LICENSE'}:
            archive.write(root / name, 'asgaros-forum/' + name)
print(output)
