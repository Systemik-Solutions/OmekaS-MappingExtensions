# Changelog

All notable changes to this module will be documented in this file.

## [Unreleased]

### Added

- **Timeline rows by property:** Group timeline rows by any selected item property, such as schema:category, in Map by attachments and Map by query blocks. Items with multiple values appear in each category, missing values use a No value row, and category filters also hide non-selected rows. Timeline selections still focus the corresponding map pin.
- **Timeline category rows:** Added a Group timeline rows by setting for resource templates or resource classes in Map by attachments and Map by query blocks. Categories occupy separate labelled bands, with adjacent rows for overlapping events and room for every category. Automatic rows remain the default; grouping preserves map selection and filter behaviour.
- **Multiple filter criteria:** Added separate editor buttons for template, class, and property filters, making it easier to combine both resource types with any number of property fields in one panel. Items must match every active filter.
- **Configurable map filters:** Added a show/hide setting and multiple filters by resource template, resource class, or property. Property values appear as compact, clickable tags populated from all mapped values. Filter sections can be collapsed independently. Selections combine across filters and remain active during timeline navigation. Existing filter settings are preserved.
- **Legend visibility:** Added a Show legend checkbox under Groups for Map by attachments, Map by query, and Journey map blocks. Enabled by default for existing and new blocks; hiding the legend leaves marker colours and resource template filters unchanged.

### Fixed

- **Filter colours:** Property filter highlights use the same configured or automatic category colours as property-grouped map pins, including separate colours for choices from multi-valued items.
- **Filtered timeline:** Hide non-matching timeline events when map filters change, restore them when selections are cleared, and show an empty message when no dated items match. Timeline navigation follows the filtered events without resetting the map view.
- **Sidebar overview:** Closing details with the close button, Escape, or the map background restores all pins matching the active filters, including undated items, and zooms out to fit them. Filter changes still preserve the current map view.
- **Filter map position:** Preserve the current map center and zoom when changing or resetting filters, including during a timeline flight. Filter changes no longer call Leaflet's zoom setter. Only timeline item selection focuses a site, using its locations and the configured Fly to zoom (13 by default), without fitting unrelated contemporaneous items. Version the block script by its contents so browsers receive these fixes even before a module version change.
- **Timeline category bands:** Align label and timeline band boundaries through the date axis, including after zooming or resizing, and remove stale bands on redraw.
- **Linked-item timeline dates:** Include displayed linked items even when they also have map locations of their own.
- **Filter editor validation:** Show an inline message and focus the visible property dropdown when a field is missing, instead of blocking Save with a hidden-control error. Disabled filters and removed blocks do not prevent saving.
- **Filter property choices:** Limit the editor's property selector to fields used by the current mapped items. Refresh choices when the query, attachments, or linked-item settings change, and preserve saved filters whose properties are no longer used.
- **Sidebar controls:** Dock item and cluster details beside wide maps and below narrow maps so the panel cannot cover zoom, fullscreen, legends, or filters. Keep the layout usable in fullscreen and restore the map width when details close.
- **Sidebar dismissal:** Clicking the map background closes the details sidebar and clears the selected marker's highlight circle.

## [1.1.3] - 2026-09-16

### Added

- **Resource template filters:** Added a compact filter panel in the bottom-right corner of Map by attachments and Map by query blocks. Categories toggle independently, support any combination including none, and use the configured marker colours when selected or grey when deselected. Includes Reset Filters and Hide/Show controls, keyboard support, and a block setting to disable the panel.
- **Timeline appearance settings:** Added optional font family, text colour, and background colour settings. Blank fields preserve the default timeline appearance; each override applies independently.

### Changed

- **Timeline layout:** Show the timeline at full width below the map by default, with an optional side-by-side layout on larger screens. Hide the separate slide/detail area and retain the compact date navigation.
- **Timeline selection:** Selecting an event highlights its map pin and opens its popup or configured sidebar. Resource template filters remain active during timeline navigation. The initial map continues to show all matching items with locations, including undated items; only the timeline requires a valid date.

### Fixed

- **Contemporaneous events:** Include overlapping events with Fly to enabled, and correctly compare timestamps with intervals in either selection direction. Check every date value, show each matching item once, and fit the view to the matching features while respecting resource template filters.
- **Mobile filter layout:** Keep the filter panel within the map and reposition the legend on narrow maps to prevent overlapping controls.

## [1.1.2] - 2026-07-30

### Added
- **Timeline marker rows:** Added a block setting for Map by attachments and Map by query to configure a maximum of 4, 6, 8, or 10 timeline marker rows. The default is 4 rows.
- **Multiple temporal values:** Added every timestamp or interval value from an item to the timeline as a separate event while keeping each event linked to the same item and map feature.

### Fixed
- **Contemporaneous events:** Fixed overlapping interval markers not appearing on the map and ensured the comparison checks all interval values.

## [1.1.1] - 2026-07-01

### Added
- **Group legend labels:** Added an option to show or hide the grouped property label in property-value legend labels. By default, legends now show only the grouped value.

### Fixed
- **Map by query group controls:** Fixed group-by and node color settings not saving after the sidebar tabs form was added to Map by query blocks.
- **Sidebar tab properties:** Preserved the order of selected sidebar tab properties by switching them to ordered single-property rows.

## [1.1.0] - 2026-06-25

### Added
- **Sidebar tabs:** Added configurable sidebar tabs for Map by query blocks, allowing marker details to open in a map sidebar with tab-specific content.
- **Sidebar popup mode:** Added an option to render the same configured tabs inside marker popups instead of the sidebar.
- **Sidebar content options:** Added tab content controls for media, selected properties, linked-from items, and external item links.

### Fixed
- **Upgrade path:** Prevented inherited Mapping module schema migrations from running during Mapping Extensions 1.x upgrades when the current database already has the newer mapping tables.
- **Timeline layout:** Fix the location of the timeline slider.


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
