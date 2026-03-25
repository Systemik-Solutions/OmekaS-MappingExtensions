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

### Mapping Linked Items

The Mapping Extensions module introduces the ability to visualize linked items instead of only attached or queried 
items. This feature is available in both the Map by attachments and Map by query blocks.

- In your site, add either a "Map by attachments" or "Map by query" block.
- In the block configuration, check the option “Map linked items instead”.
- Choose properties (optional). Use the "Property" dropdown to select one or more properties that define which 
linked items should be included. If no property is selected, all linked items connected to the original item will be 
displayed.
- Configure the popup content. You can configure which additional properties of linked items appear in the popup.

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
