# Composer Patches Inliner

Part of the "Enshittification-Ecosystem" due things like: https://github.com/orgs/community/discussions/157887

This package extends the capabilities of [cweagans/composer-patches](https://github.com/cweagans/composer-patches),
particularly the version patched with: https://github.com/cweagans/composer-patches/pull/627

The package downloads / inlines all remote patches into the project root and creates a composer patches file for cweagans/composer-patches to use.
On known rate-limited domains the download happens with a delay which should suffice to avoid hitting the rate limit.