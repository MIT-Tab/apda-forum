"""Native SQL backup/restore regression: preserve four-byte Unicode exactly.

Use only a test database server. Creates/drops apda_unicode_roundtrip_test.
The deployment helper must explicitly use this charset even if wp-config defaults
or WP-CLI's posts-table heuristic would choose the three-byte utf8 charset.
"""
import os
import shlex
import subprocess

client = shlex.split(os.environ.get('MYSQL_TEST_COMMAND', 'mariadb'))
dumper = shlex.split(os.environ.get('MYSQL_DUMP_COMMAND', 'mariadb-dump'))
if 'MYSQL_DUMP_COMMAND' not in os.environ:
    dumper += client[1:]
name = 'apda_unicode_roundtrip_test'
value = "Historic text 😀 🇺🇸 🏳️‍🌈 𠜎, accents é, quotes ' and a backslash \\ preserved.\nNext line."
encoded = value.encode('utf-8').hex().upper()

def sql(query):
    return subprocess.run(client + ['--default-character-set=utf8mb4', '--batch', '--skip-column-names'],
                          input=query, text=True, capture_output=True, check=True).stdout.strip()

sql(f'CREATE DATABASE {name} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')
try:
    sql(f'''USE {name}; CREATE TABLE posts (id INT PRIMARY KEY, body LONGTEXT) ENGINE=MyISAM;
    INSERT INTO posts VALUES (1, CONVERT(UNHEX('{encoded}') USING utf8mb4));''')
    assert sql(f'USE {name}; SELECT HEX(body) FROM posts WHERE id=1') == encoded
    dump = subprocess.check_output(dumper + ['--default-character-set=utf8mb4', '--no-tablespaces', name], stderr=subprocess.PIPE)
    sql(f'USE {name}; DROP TABLE posts;')
    subprocess.run(client + ['--default-character-set=utf8mb4', name], input=dump, capture_output=True, check=True)
    assert sql(f'USE {name}; SELECT HEX(body) FROM posts WHERE id=1') == encoded
    print('Native SQL backup/restore preserved four-byte Unicode, newlines, quotes and backslashes')
finally:
    sql(f'DROP DATABASE {name}')
