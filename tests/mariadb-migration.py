"""Verify the exact reaction migration SQL against a private MySQL/MariaDB DB.
MYSQL_TEST_COMMAND is a shell-quoted client command (defaults to mariadb).
The fixed apda_reaction_migration_test database is created and dropped by this test.
"""
import os
import pathlib
import re
import shlex
import subprocess

root = pathlib.Path(__file__).resolve().parents[1]
source = (root / 'includes/forum-database.php').read_text()
match = re.search(r'ALTER TABLE \{\$this->tables->reactions\} DROP PRIMARY KEY, ADD PRIMARY KEY \(post_id, user_id, reaction\)', source)
assert match, 'Expected migration SQL not found'
client = shlex.split(os.environ.get('MYSQL_TEST_COMMAND', 'mariadb'))
def sql(query):
    return subprocess.run(client + ['--batch', '--skip-column-names'], input=query,
                          text=True, capture_output=True, check=True).stdout.strip()
name = 'apda_reaction_migration_test'
sql(f'CREATE DATABASE {name}')
try:
    sql(f'''USE {name}; CREATE TABLE forum_posts (id INT PRIMARY KEY, text LONGTEXT);
    INSERT INTO forum_posts VALUES (1, 'Historic APDA discussion');
    CREATE TABLE forum_reactions (
      post_id INT NOT NULL, user_id INT NOT NULL, reaction VARCHAR(20) NOT NULL,
      author_id INT NOT NULL, datestamp DATETIME NOT NULL,
      PRIMARY KEY (post_id, user_id));
    INSERT INTO forum_reactions VALUES (1, 2, 'up', 1, '2020-01-01 00:00:00'),
      (1, 3, 'down', 1, '2021-01-01 00:00:00');''')
    before = sql(f'USE {name}; SELECT * FROM forum_reactions ORDER BY user_id; SELECT * FROM forum_posts;')
    migration = match[0].replace('{$this->tables->reactions}', 'forum_reactions')
    sql(f'USE {name}; {migration};')
    assert before == sql(f'USE {name}; SELECT * FROM forum_reactions ORDER BY user_id; SELECT * FROM forum_posts;'), 'History changed'
    sql(f"USE {name}; INSERT INTO forum_reactions VALUES (1, 2, 'heart', 1, NOW());")
    assert sql(f'USE {name}; SELECT COUNT(*) FROM forum_reactions;') == '3'
    try:
        sql(f"USE {name}; INSERT INTO forum_reactions VALUES (1, 2, 'heart', 1, NOW());")
    except subprocess.CalledProcessError:
        pass
    else:
        raise AssertionError('Duplicate reactions were accepted')
    sql(f"USE {name}; DELETE FROM forum_reactions WHERE post_id=1 AND user_id=2 AND reaction='heart';")
    assert before == sql(f'USE {name}; SELECT * FROM forum_reactions ORDER BY user_id; SELECT * FROM forum_posts;')
    print('MariaDB migration preserves historical posts/votes; multiple emojis and uniqueness verified')
finally:
    sql(f'DROP DATABASE {name}')
