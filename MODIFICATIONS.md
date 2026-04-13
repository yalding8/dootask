# Modifications to DooTask

This is a modified version of [DooTask](https://github.com/kuaifan/dootask), licensed under AGPL-3.0.

## Modified by

- **Organization**: 天津异乡好居股份有限公司 (Uhomes)
- **Contact**: ceo@dingning.ai
- **Date**: 2026-04-13

## Changes

### `app/Module/Doo.php`

1. **`license()` method**: Modified to return `people = 0` (unlimited users), bypassing the compiled binary's user count restriction for internal deployment.
2. **`userCreate()` method**: Added try-catch fallback — when the compiled `doo.so` binary rejects user creation due to license limits, users are created directly via database insert using the same password hashing mechanism (`Doo::md5s`).

### `docker/nginx/default.conf`

1. **Disabled appstore proxy**: Commented out `/appstore/` location block and appstore config include, as the `dootask/appstore:0.4.0` Docker image was unavailable (TLS handshake timeout during pull).

## Original Project

- **Repository**: https://github.com/kuaifan/dootask
- **License**: AGPL-3.0
- **Original Author**: kuaifan
