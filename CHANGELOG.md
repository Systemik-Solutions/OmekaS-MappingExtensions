# Changelog

All notable changes to this module will be documented in this file.

## [1.0.1] - 2026-03-20

### Fixed
- **Coding standards:** Replaced all loose comparisons (`==`, `!=`) with strict equivalents (`===`, `!==`) in `IndexController.php`.
- **Coding standards:** Added missing parentheses on `new ViewModel` instantiations.
- **Bug fix:** Fixed potential negative array index in `getAutoColor()` caused by `crc32()` returning negative values on 32-bit systems; added `abs()` wrapper.
- **Security:** Added validation (regex allowlist) for `popup_props` query parameter in `getFeaturePopupContentAction()` to prevent injection via untrusted property terms.
- **Error handling:** Narrowed all `catch (\Throwable)` blocks to `catch (\Exception)` to avoid silently catching fatal errors.
- **Error handling:** Added `$this->logger()->warn()` / `->debug()` calls to all catch blocks — no more silently swallowed exceptions.

### Changed
- **Namespace:** Renamed root namespace from `Mapping` to `MappingExtensions` across all PHP files to avoid conflicts with the official Mapping module.
- **Code quality:** Extracted duplicated geography-to-array conversion into new `buildFeatureArray()` helper method.
- **Code quality:** Extracted duplicated JSON response pattern into new `buildFeaturesJsonResponse()` helper method.
- **Code quality:** Extracted linked-properties parsing into new `parseLinkedProperties()` helper method.
- **Code quality:** Merged duplicate `searchOne('properties')` API calls in `addLegendForItem()` into a single call.
- **Code quality:** Renamed `getFeatureActionforJourneyItems()` to `getFeatureActionForJourneyItems()` for consistent camelCase.

### Added
- Class-level and method-level docblocks on all public and private methods in `IndexController.php`.

## [1.0.0] - Initial release

- Initial release of the Mapping Extensions module.
