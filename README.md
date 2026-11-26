# systopic/signage

Fluxo Signage: public screens, touch terminals and presentation walls as
sites of Fluxo CMS. Editors maintain the content as pages; an approved state
is exported as a versioned static HTML5 package and transferred to an
on-site signage server that runs without PHP or a database.

Status: early. The concept is in [.cursor/plans/signage.plan.md](.cursor/plans/signage.plan.md),
the current state and how to use it in [.cursor/docs/signage.md](.cursor/docs/signage.md)
(both German). Available so far:

- `bin/import-hotfolder.php` — takes a legacy hotfolder (`4x2A_Title.pdf`,
  `Welcome.txt`, `config.txt`, `screensaver_*`) over into a site as pages
  and media.

```bash
cd <project>
php vendor/systopic/signage/bin/import-hotfolder.php <hotfolder> <site> [--dry]
```

Requires PHP 8.4, `ext-imagick`, [systopic/system](https://github.com/systopicode/system),
[systopic/db](https://github.com/systopicode/db) and [systopic/cms](https://github.com/systopicode/cms).

© 2026 systopic GmbH, see [LICENSE](LICENSE).
