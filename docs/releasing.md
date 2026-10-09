# Release checklist

Use this list when preparing a new version.

## Version

Change the version everywhere in one commit:

- the plugin header and `PRESSQUEUE_VERSION` in `pressqueue.php`;
- `Stable tag` and `Tested up to` in `readme.txt`;
- `CHANGELOG.md` and the notes in `docs/releases/`;
- the translation catalog header (regenerate it from source).

## Checks

- Run `composer run ci` with a throwaway WordPress site.
- Run the Docker matrix for every supported PHP and WordPress version.
- Run Plugin Check on the built ZIP. It should report no errors or warnings.
- Install the ZIP on a clean site, activate it, connect a test Buffer account
  and send one Buffer draft.
- Make sure GitHub Actions use pinned commit references and that tool
  downloads are checksum pinned.
- Review `readme.txt`, `LICENSE` and `THIRD_PARTY_NOTICES.md`.
- Update the screenshots if an admin screen changed.
- Search the tree for API keys, site details and personal data before
  tagging.

## Publish

- Tag the release and attach the ZIP to the GitHub release.
- Record the ZIP's SHA-256 in the release notes.
- Upload the release to WordPress.org through SVN.
