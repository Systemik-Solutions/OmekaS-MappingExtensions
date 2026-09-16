function MappingBlock(mapDiv, timelineDiv) {
    // Preserve the filter panel when rebuilding an existing map.
    const filterDiv = mapDiv.closest('.mapping-block').find('.mapping-template-filter').first().detach();

    // Call remove() on an existing Leaflet map object to destroy it.
    if (mapDiv[0].mapping_map) {
        mapDiv[0].mapping_map.remove();
    }

    // Instantiate the Leaflet map object.
    const mapData = mapDiv.data('data');

    // Set the basemap provider.
    let basemapProvider;
    if (mapData.basemap_provider) {
        basemapProvider = mapData.basemap_provider;
    } else if (mapDiv.data('basemap-provider')) {
        basemapProvider = mapDiv.data('basemap-provider');
    }

    const isJourneyMap = !!(mapData && mapData.journey );
    const [
        map,
        features,
        featuresPoint,
        featuresPoly,
        baseMaps
    ] = MappingModule.initializeMap(mapDiv[0], {
        minZoom: mapData.min_zoom ? mapData.min_zoom : 0,
        maxZoom: mapData.max_zoom ? mapData.max_zoom : 19
    }, {
        disableClustering: mapDiv.data('disable-clustering'),
        basemapProvider: basemapProvider,
        excludeLayersControl: true,
        excludeFitBoundsControl: (timelineDiv && timelineDiv.length),
    } , isJourneyMap);

    // For easy reference, assign the Leaflet map object directly to the map element.
    mapDiv[0].mapping_map = map;

    // Set and prepare opacity control.
    let opacityControl;
    const handleOpacityControl = function(overlay, label) {
        if ('inclusive' === mapData.overlay_mode) {
            // Do not display opacity control when overlay mode is inclusive.
            return;
        }
        if (opacityControl) {
            // Only one control at a time.
            map.removeControl(opacityControl);
            opacityControl = null;
        }
        if (overlay !== noOverlayLayer) {
            // The "No overlay" overlay gets no control.
            opacityControl = new L.Control.Opacity(overlay, label);
            map.addControl(opacityControl);
        }
    };

    // Add base map and grouped layers.
    const featuresByResource = {};
    const noOverlayLayer = new L.GridLayer();
    const groupedLayersGroups = {'Overlays': {}};
    const groupedLayersOptions = {};
    if ('inclusive' !== mapData.overlay_mode) {
        groupedLayersGroups['Overlays']['No overlay'] = noOverlayLayer;
        groupedLayersOptions.exclusiveGroups = ['Overlays'];
    }
    const groupedLayers = L.control.groupedLayers(
        baseMaps,
        groupedLayersGroups,
        groupedLayersOptions
    ).addTo(map);
    map.addLayer(noOverlayLayer);

    // Add overlays.
    const addOverlays = async function () {
        if (!mapData.overlays) {
            return;
        }
        for (const overlayData of mapData.overlays) {
            let overlayLayer;
            switch (overlayData.type) {
                case 'wms':
                    overlayLayer = L.tileLayer.wms(overlayData.base_url, {
                        layers: overlayData.layers,
                        styles: overlayData.styles,
                        format: 'image/png',
                        transparent: true,
                        // Leaflet sets the default maxZoom for TileLayer to 18.
                        // Here we set a high maxZoom for WMS layers that zoom
                        // beyond that. This way the only realistic limitation
                        // is the map's default maxZoom.
                        maxZoom: 30,
                    });
                    break;
                case 'iiif':
                    overlayLayer = new Allmaps.WarpedMapLayer()
                    await overlayLayer.addGeoreferenceAnnotationByUrl(overlayData.url)
                    break;
                case 'geojson':
                    overlayLayer = L.geoJSON(JSON.parse(overlayData.geojson), {
                        onEachFeature: function(feature, layer) {
                            if (feature.properties) {
                                // Filter out non-string properties.
                                $.each(feature.properties, function(key, value) {
                                    if ('string' !== typeof value) {
                                        delete feature.properties[key];
                                    }
                                });
                                if (!$.isEmptyObject(feature.properties)) {
                                    // Add the popup.
                                    const popup = $('<div>', {
                                        class: 'mapping-feature-popup-content',
                                    });
                                    // Add the popup label.
                                    const labelKey = overlayData.property_key_label;
                                    if (feature.properties[labelKey] && 'string' === typeof feature.properties[labelKey]) {
                                        $('<span>', {class: 'group-type'}).text(feature.properties[labelKey]).appendTo(popup);
                                    }
                                    // Add the popup comment.
                                    const commentKey = overlayData.property_key_comment;
                                    if (feature.properties[commentKey] && 'string' === typeof feature.properties[commentKey]) {
                                        $('<span>', {class: 'group-value'}).text(feature.properties[commentKey]).appendTo(popup);
                                    }
                                    // Add the GeoJSON properties to the popup.
                                    if (overlayData.show_property_list) {
                                        const dl = $('<dl class="geojson-properties">');
                                        $.each(feature.properties, function(key, value) {
                                            if ('string' === typeof value) {
                                                const dt = $('<dt>').text(key);
                                                const dd = $('<dd>').text(value);
                                                dl.append(dt, dd);
                                            }
                                        });
                                        popup.append(dl);
                                    }
                                    // Show popup only when it has contents.
                                    if (popup.contents().length) {
                                        layer.bindPopup(popup[0]);
                                    }
                                }
                            }
                        }
                    });
                    break;
            }
            if (overlayLayer) {
                if (overlayData.open) {
                    // This overlay is open by default.
                    map.removeLayer(noOverlayLayer);
                    map.addLayer(overlayLayer);
                    handleOpacityControl(overlayLayer, overlayData.label);
                }
                groupedLayers.addOverlay(overlayLayer, overlayData.label, 'Overlays');
            }
        }
    }
    addOverlays();

    // Handle the overlay opacity control.
    map.on('overlayadd', function(e) {
        handleOpacityControl(e.layer, e.name);
    });

    // Set the scroll wheel zoom behavior.
    switch (mapData['scroll_wheel_zoom']) {
        case 'disable':
            map.scrollWheelZoom.disable()
            break;
        case 'click':
            map.scrollWheelZoom.disable()
            map.on('click', function() {
                if (!map.scrollWheelZoom.enabled()) {
                    map.scrollWheelZoom.enable();
                }
            });
            break;
        default:
            map.scrollWheelZoom.enable()
            break;
    }

    // Set the default view.
    const setDefaultView = function() {
        if (mapData['bounds']) {
            const bounds = mapData['bounds'].split(',');
            const southWest = [bounds[1], bounds[0]];
            const northEast = [bounds[3], bounds[2]];
            map.fitBounds([southWest, northEast]);
        } else {
            const bounds = features.getBounds();
            if (bounds.isValid()) {
                map.fitBounds(bounds);
            }
        }
    };

    const getFeaturesUrl = mapDiv.data('featuresUrl');
    const getFeaturePopupContentUrl = mapDiv.data('featurePopupContentUrl');

    if (filterDiv.length) {
        mapDiv.append(filterDiv);
        L.DomEvent.disableClickPropagation(filterDiv[0]);
        L.DomEvent.disableScrollPropagation(filterDiv[0]);
        L.DomEvent.on(filterDiv[0], 'keydown', L.DomEvent.stopPropagation);
    }
    const filterButtons = filterDiv.find('.mapping-template-buttons');
    filterButtons.empty();
    filterDiv.prop('hidden', true);
    const templateButtons = new Map();
    const filterLayers = [];
    const excludedTemplates = new Set();
    let timeline = null;
    let currentTimelineEvent = null;
    const timelineFeatures = L.featureGroup().addTo(map);
    let updateTimelineView = function() {};
    const matchesTemplate = function(layer) {
        return !excludedTemplates.has(String(layer.mapping_resource_template.id));
    };
    const updateEmptyMessage = function() {
        const hasFeatures = currentTimelineEvent && currentTimelineEvent.start_date
            ? timelineFeatures.getLayers().length > 0
            : filterLayers.some(entry => matchesTemplate(entry.layer));
        filterDiv.find('.mapping-template-empty').prop('hidden', hasFeatures);
    };
    const setFilterButtonColor = function(button, color) {
        // Match the marker colour supplied by the module's colour configuration.
        if (!/^#(?:[a-f0-9]{3}|[a-f0-9]{6}|[a-f0-9]{8})$/i.test(color || '')) {
            color = '#6699ff';
        }
        let hex = color.slice(1);
        if (hex.length === 3) hex = hex.split('').map(c => c + c).join('');
        const alpha = hex.length === 8 ? parseInt(hex.slice(6, 8), 16) / 255 : 1;
        const channels = [0, 2, 4].map(offset => {
            const value = (parseInt(hex.slice(offset, offset + 2), 16) * alpha + 255 * (1 - alpha)) / 255;
            return value <= 0.04045 ? value / 12.92 : Math.pow((value + 0.055) / 1.055, 2.4);
        });
        const luminance = channels[0] * 0.2126 + channels[1] * 0.7152 + channels[2] * 0.0722;
        button[0].style.setProperty('--mapping-category-color', color);
        button[0].style.setProperty('--mapping-category-text', luminance > 0.179 ? '#000' : '#fff');
    };
    filterDiv.find('.mapping-template-toggle').off('click.mappingTemplates').on('click.mappingTemplates', function() {
        const expanded = this.getAttribute('aria-expanded') !== 'true';
        $(this).attr('aria-expanded', String(expanded)).text($(this).data(expanded ? 'hide-label' : 'show-label'));
        filterDiv.find('.mapping-template-body').prop('hidden', !expanded);
        filterDiv.toggleClass('is-collapsed', !expanded);
    });
    const registerFilterLayer = filterDiv.length ? function(layer, type) {
        const template = layer.mapping_resource_template;
        const key = String(template.id);
        const group = type === 'Point' ? featuresPoint : featuresPoly;
        filterLayers.push({layer: layer, group: group});
        if (!matchesTemplate(layer) && group.hasLayer(layer)) {
            group.removeLayer(layer);
        }
        if (!templateButtons.has(key)) {
            const button = $('<button>', {type: 'button', 'data-template-id': key, 'aria-pressed': String(!excludedTemplates.has(key))})
                .text(template.label || filterDiv.data('no-template'))
                .attr('aria-label', template.label || filterDiv.data('no-template'));
            setFilterButtonColor(button, template.color);
            templateButtons.set(key, button);
            // Insert without recreating existing buttons, preserving keyboard focus.
            const next = filterButtons.children('[data-template-id]').toArray().find(element =>
                element.textContent.localeCompare(button.text()) > 0
            );
            if (next) {
                button.insertBefore(next);
            } else {
                button.appendTo(filterButtons);
            }
            filterDiv.prop('hidden', false);
        }
    } : null;
    filterDiv.off('click.mappingTemplates').on('click.mappingTemplates', 'button[data-template-id]', function() {
        const templateId = this.dataset.templateId;
        if (templateId === 'all') {
            excludedTemplates.clear();
        } else if (excludedTemplates.has(templateId)) {
            excludedTemplates.delete(templateId);
        } else {
            excludedTemplates.add(templateId);
        }
        filterButtons.children('button').each(function() {
            $(this).attr('aria-pressed', String(!excludedTemplates.has(this.dataset.templateId)));
        });
        map.closePopup();
        MappingModule.clearMarkerHighlight(map);
        if (map.mapping_close_sidebar) {
            map.mapping_close_sidebar();
        }
        timelineFeatures.clearLayers();
        filterLayers.forEach(function(entry) {
            if (matchesTemplate(entry.layer)) {
                if (!entry.group.hasLayer(entry.layer)) {
                    entry.group.addLayer(entry.layer);
                }
            } else if (entry.group.hasLayer(entry.layer)) {
                entry.group.removeLayer(entry.layer);
            }
        });
        updateTimelineView();
        updateEmptyMessage();
    });

    // Load features synchronously.
    mapDiv.closest('.mapping-block').find('.mapping-feature-popup-content').each(function() {
        const popupContent = $(this);
        const featureId = popupContent.data('featureId');
        const featureGeography = popupContent.data('featureGeography');
        L.geoJSON(featureGeography, {
            onEachFeature: function(feature, layer) {
                const popup = L.popup();
                layer.bindPopup(popup);
                if (getFeaturePopupContentUrl) {
                    layer.on('popupopen', function() {
                        $.get(getFeaturePopupContentUrl, {feature_id: featureId}, function(popupContent) {
                            popup.setContent(popupContent);
                        });
                    });
                } else {
                    popup.setContent(popupContent[0]);
                }
                MappingModule.addFeature(map, featuresPoint, featuresPoly, layer, feature.type);
            }
        });
    });

    // Whether can add search input at top, (display properteies in )
    // Load features asynchronously.
    if (getFeaturesUrl) {
        const onFeaturesLoad = function() {
            updateTimelineView(false);
            updateEmptyMessage();
            if (!map.mapping_map_interaction) {
                // Call setDefaultView only when there was no map interaction. This
                // prevents the map view from changing after a change has already
                // been done.
                setDefaultView();
            }
        };
        MappingModule.loadFeaturesAsync(
            map,
            featuresPoint,
            featuresPoly,
            getFeaturesUrl,
            getFeaturePopupContentUrl,
            JSON.stringify(mapDiv.data('itemsQuery')),
            JSON.stringify(mapDiv.data('featuresQuery')),
            onFeaturesLoad,
            featuresByResource,
            1,
            JSON.stringify(mapData),
            registerFilterLayer,
        );
    }

    setDefaultView();

    if (timelineDiv && timelineDiv.length) {
        const timelineEventResourceId = function(event) {
            return event.resource_id || event.unique_id;
        };

        timeline = new TL.Timeline(
            timelineDiv[0],
            timelineDiv.data('data'),
            timelineDiv.data('options')
        );
        timelineDiv[0].mapping_timeline = timeline;
        const filteredEventFeatures = function(event) {
            const resourceFeatures = featuresByResource[timelineEventResourceId(event)];
            if (!resourceFeatures) return null;
            const layers = resourceFeatures.getLayers().filter(matchesTemplate);
            return layers.length ? L.featureGroup(layers) : null;
        };
        updateTimelineView = function(setView = true) {
            const currentEvent = currentTimelineEvent;
            map.closePopup();
            MappingModule.clearMarkerHighlight(map);
            if (currentEvent && currentEvent.start_date) {
                // Changed to an event slide. Set the timeline event view.
                map.removeLayer(features);
                timelineFeatures.clearLayers();
                // Changed to an event slide. Set the event's map view.
                const eventFeatures = filteredEventFeatures(currentEvent);
                if (eventFeatures) {
                    timelineFeatures.addLayer(eventFeatures);
                }
                if (mapData.timeline.show_contemporaneous) {
                    const shownResources = new Set(eventFeatures
                        ? [String(timelineEventResourceId(currentEvent))] : []);
                    Object.values(timeline.config.event_dict).forEach(function(event) {
                        const resourceId = String(timelineEventResourceId(event));
                        if (shownResources.has(resourceId)
                            || !MappingModule.timelineEventsOverlap(currentEvent, event)) {
                            return;
                        }
                        const contemporaneousFeatures = filteredEventFeatures(event);
                        if (contemporaneousFeatures) {
                            timelineFeatures.addLayer(contemporaneousFeatures);
                            shownResources.add(resourceId);
                        }
                    });
                }
                if (setView) {
                    if ($.isNumeric(mapData.timeline.fly_to)) {
                        const bounds = timelineFeatures.getBounds();
                        if (bounds.isValid()) {
                            map.flyToBounds(bounds, {maxZoom: parseInt(mapData.timeline.fly_to, 10)});
                        }
                    } else {
                        setDefaultView();
                    }
                }
                if (eventFeatures) {
                    // Use the same interaction as clicking the corresponding pin.
                    // For an item with several locations, open its first point.
                    const eventLayers = eventFeatures.getLayers();
                    const selectedLayer = eventLayers.find(layer => layer.getLatLng) || eventLayers[0];
                    selectedLayer.fire('click');
                    if (selectedLayer.getPopup()) {
                        selectedLayer.openPopup();
                    }
                }
            } else {
                // Changed to the title slide. Set the default map view.
                timelineFeatures.clearLayers();
                map.addLayer(features);
                if (setView) setDefaultView();
            }
        };
        timeline.on('change', function(e) {
            currentTimelineEvent = timeline.config.event_dict[e.unique_id] || null;
            updateTimelineView();
            updateEmptyMessage();
        });
    }
}

$(document).ready( function() {
    $('.mapping-block:visible').each(function() {
        const blockDiv = $(this);
        MappingBlock(
            blockDiv.children('.mapping-map'),
            blockDiv.children('.mapping-timeline, .tl-timeline')
        );
    });
});

$(document).on('click', '.mapping-show-group-item-features', function(e) {
    const thisButton = $(this);
    const groupPopup = thisButton.closest('.mapping-feature-popup-content');
    const groupBlock = thisButton.closest('.mapping-block');
    const itemsBlock = groupBlock.next('.mapping-block');
    const itemsBlockMap = itemsBlock.find('.mapping-map');

    // Copy filters markup to items block.
    itemsBlock.find('.search-filters').html(groupPopup.find('.mapping-search-filters-template').html());

    groupBlock.hide();
    itemsBlock.show();

    // Prepare and load the items map.
    itemsBlockMap.data('itemsQuery', groupPopup.data('itemsQuery'));
    MappingBlock(itemsBlockMap);
});

$(document).on('click', '.mapping-show-group-features', function() {
    const thisButton = $(this);
    const mappingBlockItems = thisButton.closest('.mapping-block');
    const mappingBlock = mappingBlockItems.prev('.mapping-block');
    mappingBlockItems.hide();
    mappingBlock.show();
});
