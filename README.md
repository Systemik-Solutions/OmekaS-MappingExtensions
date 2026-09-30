# Mapping Extensions

Mapping Extensions is a fork of the official Omeka S [Mapping](https://omeka.org/s/modules/Mapping/) module (v2.2.0). 
It retains all the core features of the original module and serves as a full replacement, while introducing enhanced 
capabilities for linked item mapping, group-based visualizations, and journey maps.

## Overview

This module expands the mapping functionality in Omeka S by adding new configuration options and visualization modes. 
It allows users to map not only attached or queried items, but also their linked items, group items with custom 
colors, and visualize journeys of geo-located resources.

## Installation

> [!WARNING]
> The Mapping Extensions module is designed to function only as a full replacement for the official Omeka S Mapping 
module. It cannot operate alongside the original Mapping module. If the official Mapping module is currently 
installed, it must be fully uninstalled before installing Mapping Extensions.

- Download a ZIP package from one of the 
[releases](https://github.com/Systemik-Solutions/OmekaS-MappingExtensions/releases).
- Extract the ZIP into the modules directory of your Omeka S installation.
- Rename the folder to 'MappingExtensions'.
- In the Omeka S admin panel, go to Modules and click Install next to “Mapping Extensions”.

For more details, refer to the [Omeka S module installation guide](https://omeka.org/s/docs/user-manual/modules/).

### Upgrading from 1.0.0 to 1.0.1

> [!CAUTION]
> DON'T uninstall the older version of the module, or you will lose all your existing maps and configurations.

As the v1.0.1 release resolves the namespace conflict with the original Mapping module, the directory name of the module 
has changed from "Mapping" to "MappingExtensions". To upgrade from v1.0.0 to v1.0.1, follow these steps to manually
update the module without losing your existing maps and configurations:

1. Back up your Omeka S database and the "Mapping" module directory in your Omeka S installation.
2. Remove the "Mapping" module directory in `modules/` from your Omeka S installation.
3. Download the v1.0.1 release and extract the zip file into the `modules/` directory. After this step, you should have 
a new directory named "MappingExtensions" in `modules/`.
4. Go to the Omeka S MySQL database and open the database table `module`. Find the record for the `Mapping` module (`id='Mapping'`'), 
then update the `id` field to `MappingExtensions` and the `version` field to `1.0.1`.
5. Go to the "Modules" page in the Omeka S admin panel and verify that the "Mapping Extensions" module is listed with 
version 1.0.1 and is active.

## Usage

### Timeline layout and appearance

Map by attachments and Map by query blocks show their timeline at full width below the map.
In the block's **Timeline** settings, choose **Timeline layout** to switch to a side-by-side
layout on larger screens. Both layouts stack on small screens. The timeline shows only the date navigation; the separate
slide/detail area is hidden. Selecting a timeline item highlights its map pin and opens its popup
(or the configured marker sidebar). The map focuses on the selected item's locations using
the **Fly to** maximum zoom (13 by default).

The timeline keeps its default font and colours unless an override is supplied in the block editor. Set **Timeline font family** to a
font stack already available on the site (for example `Georgia, serif`). **Timeline text colour**
and **Timeline background colour** accept three- or six-digit hex colours such as `#333333`.
Leave these fields blank to preserve the default timeline appearance. Each override applies independently.

Use **Group timeline rows by** to organise dated items into labelled bands by **Resource template**
or **Resource class**. Each row belongs to one category; overlapping events can use adjacent rows
within the same category. Items without a template or class appear in a separate labelled band.
Category labels stay in a fixed column so sliding event cards cannot cover them. The timeline
grows when more categories need space, and the bands stay aligned when zooming or resizing.
Choose **None (automatic rows)** to keep the original layout; this remains the default
for existing and new blocks.

Row grouping is independent of the map filters. Selecting an event still highlights its map pin
and opens its details, subject to the active filters. In linked-items mode, the category belongs
to the displayed linked item.

The initial map shows all items with locations matching the block query or attachments, including
items without dates. The timeline includes only items with a valid numeric timestamp or interval
in the selected date property. Selecting a timeline event narrows the map to that event (and any
contemporaneous events when enabled); returning to the initial timeline view restores all mapped
items, subject to the map filters, without resetting the current map view. In linked-items mode, dates belong to the displayed
linked items.

Closing the sidebar with its close button, Escape, or a click on the map background restores
all mapped items matching the active filters, including undated items, and zooms out to fit them.
The timeline stays at its current position; clicking its selected event again refocuses that site.
Changing a filter still preserves the current map center and zoom.

With **Show contemporaneous events** enabled, selecting an event shows every mapped item whose
interval overlaps it, including timestamps within that interval. This works with both the default
and configured **Fly to** zoom settings; the map focuses on the selected item's locations while
the other matching features remain available. Multiple date values on one
item are checked individually, and each matching item is shown once. Map filters
still apply.

### Map filters

Map by attachments and Map by query blocks can display a compact filter panel in the bottom-right
corner of the map. In the block editor, open **Map filters** and use **Show map filters** to enable
or disable the panel. Use **Add template filter**, **Add class filter**, and **Add property filter**
to configure any combination of:

- **Resource template** or **Resource class:** coloured category buttons. All categories start
  selected; clicking a button turns it grey and hides that category. Click again to restore it.
- **Property values:** select a property, such as `schema:category`, to list its values as compact
  clickable tags. Click tags to select any combination; click again to deselect. Every distinct
  value found on the mapped items appears, including multiple values on one item.
  No selection means all values, including items
  without that property. Linked resources and URI values are supported as well as text values.

Click **Add property filter** again for each additional field. For example, one panel can contain
a resource template filter, a resource class filter, a category field, and a language field, all
working together. Adding a filter keeps the other configured filters.

The editor's property selector lists only fields with values on the current mapped items. Its
choices refresh when the query, attachments, or linked-item settings change. A saved filter using
a property that is no longer present is retained and flagged for review.

Each filter can have an optional display label. Selections within one filter are combined with
OR; separate filters are combined with AND. **Reset Filters** clears every selection and restores
all categories. Click a filter heading to collapse or expand that section; **Hide** and **Show**
collapse or expand the whole panel. The options come from all items
loaded for this map and remain available when another filter is applied.

Adding or removing filter selections, including **Reset Filters**, preserves the map's center
and zoom. Filters apply to markers and shapes, including timeline selections. Timeline events remain
available; an empty-state message appears when no mapped items match the current filters. In
linked-items mode, filter values belong to the displayed linked item. Existing blocks keep their
resource-template filter and its visibility setting until their configuration is changed.

### Mapping Linked Items

The Mapping Extensions module introduces the ability to visualize linked items instead of only attached or queried 
items. This feature is available in both the Map by attachments and Map by query blocks.

- In your site, add either a "Map by attachments" or "Map by query" block.
- In the block configuration, check the option “Map linked items instead”.
- Choose properties (optional). Use the "Property" dropdown to select one or more properties that define which 
linked items should be included. If no property is selected, all linked items connected to the original item will be 
displayed.
- Configure the popup content. You can configure which additional properties of linked items appear in the popup.

### Sidebar tabs

Sidebar tabs let a Map by query block show marker details in a map sidebar instead of popup. This is
useful when item records need more room for item property display

- In your site, add or edit a "Map by query" block.
- In the "Sidebar tabs" section, check "Show tabs in place sidebar" to open item details in a panel on the map.
- Optionally check "Show tabs in marker popup" if you want the same tab configuration to appear in the marker popup instead of the sidebar.
- Add one or more tabs. Each tab can have a label, popup content, and selected fields.
- Available fields include media, property values, linked-from items, and an external link to the full item
record.
- If no tabs are configured, the module falls back to a default Details tab using the block's configured popup display properties.

### Grouping Items

The Grouping feature in Mapping Extensions allows you to visually organize items on the map by applying colors to 
groups. This makes it easier to distinguish categories of items at a glance.

- In your site, add either a "Map by attachments" or "Map by query" block.
- In the block configuration, use the “Group by” dropdown to choose how items should be grouped:
  - Class – group items by their resource class.
  - Resource template – group items by the template they use.
  - Property value – group items by the value of a selected property. If you choose this option, an additional 
  dropdown will appear to select the specific property.
- Configure Group Colors (Optional): After selecting a grouping option, you can assign specific colors to each group. 
If no colors are specified, the module applies a default sequence of colors automatically.
- Under **Groups**, uncheck **Show legend** to hide the map's colour legend. It is enabled by default; marker colours and resource template filters remain available when it is unchecked.

### Multiple Timeline Dates and Intervals

When an item contains multiple values for the temporal property configured by the map timeline, Mapping Extensions
creates a separate timeline event for every matching value. This applies to both fixed timestamps and temporal
intervals; the timeline is not limited to the first value on the item.

All timeline events created from the same item remain linked to that item's map feature. Selecting any of its dates or
intervals in the timeline therefore highlights and moves the map to the same marker.

When "Show contemporaneous events?" is enabled and "Fly to" uses the default view, selecting an interval also displays
the map features for every item with at least one overlapping interval. The comparison checks all interval values on
each item, not only the first value.

Use the "Maximum timeline marker rows" block setting to provide 4, 6, 8, or 10 rows for densely grouped events.
Increasing the number of rows makes the timeline navigation taller and reduces marker overlap. The default is 4 rows.

### Journey Maps

The Journey Map block is a new visualization mode introduced by Mapping Extensions. It allows you to display 
sequences of geo-located items as connected journeys, making it ideal for representing paths, itineraries, or 
historical routes.

- In your site, add a "Journey Map" block. This block works similarly to the "Map by query" block, with additional 
options for journey visualization.
- Configure the query to select the items which contain the property with journey data.
- In the "Journey" configuration section, choose the property that contains the sequence of geo-located items. The 
journey will follow the order of values in the specified property, ensuring the path reflects the intended sequence.
- Use the Groups section to apply colors to journeys based on class, resource template, or property value.

#### Rendering behavior

- The map will draw markers for each geo-located item in the journey property.
- A polyline connects the markers in the order they appear in the property values.
- The popup for each marker displays the geo-located item’s information.
- The popup for the polyline displays information about the item that owns the journey property.
- Note: Geo shapes that are not markers (e.g., polygons, rectangles) are excluded from the journey.

## Credits

Developed on top of the official Omeka S [Mapping](https://omeka.org/s/modules/Mapping/) module.

## License

This module is distributed under the 
[GNU General Public License v3.0 (GPL-3.0)](https://www.gnu.org/licenses/gpl-3.0.en.html).
