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
    const filterLayers = [];
    let timeline = null;
    let currentTimelineEvent = null;
    let timelineFlight = false;
    map.on('moveend', function() { timelineFlight = false; });
    const timelineFeatures = L.featureGroup().addTo(map);
    let updateTimelineView = function() {};
    let updateTimelineFilters = function() {};
    let featuresLoaded = false;
    let filters = null;
    const matchesFilters = layer => !filters || filters.matches(layer);
    const updateEmptyMessage = function() {
        const hasFeatures = currentTimelineEvent && currentTimelineEvent.start_date
            ? timelineFeatures.getLayers().length > 0
            : filterLayers.some(entry => matchesFilters(entry.layer));
        filterDiv.find('.mapping-template-empty').prop('hidden', hasFeatures);
    };
    const refreshFeatureGroups = function() {
        // Rebuild each group in bulk so filtering an expanded cluster also resets its index.
        featuresPoint.clearLayers();
        featuresPoly.clearLayers();
        filterLayers.forEach(function(entry) {
            if (matchesFilters(entry.layer)) entry.group.addLayer(entry.layer);
        });
    };
    map.on('mapping:sidebarclosed', function() {
        map.stop();
        map.mapping_map_interaction = true;
        currentTimelineEvent = null;
        timelineFeatures.clearLayers();
        map.addLayer(features);
        if (filters) refreshFeatureGroups();
        updateEmptyMessage();
        const bounds = features.getBounds();
        if (bounds.isValid()) {
            // The sidebar has closed and resized the map before this event.
            // Fit the remaining pins, never zooming in on a lone result.
            map.fitBounds(bounds, {padding: [20, 20], maxZoom: map.getZoom()});
        }
    });
    const applyFilters = function() {
        // Filtering changes visibility, not the visitor's chosen map position.
        // Leaflet's public stop() calls setZoom(), even when no animation is
        // running. Cancel only our pending flight, without snapping its zoom.
        if (timelineFlight) {
            map._stop();
            map._moveEnd(true);
        }
        map.mapping_map_interaction = true;
        map.closePopup();
        MappingModule.clearMarkerHighlight(map);
        if (map.mapping_close_sidebar) map.mapping_close_sidebar();
        timelineFeatures.clearLayers();
        updateTimelineFilters();
        if (!timeline) refreshFeatureGroups();
        updateTimelineView(false);
        updateEmptyMessage();
    };
    if (filterDiv.length) {
        filters = new MappingFilters(filterDiv[0], mapData.filters || {
            rows: [{type: 'resource_template'}]
        }, applyFilters);
        map.on('unload', function() { filters.destroy(); });
    }
    const registerFilterLayer = filters ? function(layer, type) {
        const group = type === 'Point' ? featuresPoint : featuresPoly;
        filterLayers.push({layer, group});
        filters.register(layer);
        if (map.hasLayer(features) && !matchesFilters(layer) && group.hasLayer(layer)) group.removeLayer(layer);
    } : null;

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
            featuresLoaded = true;
            if (filters) filters.render();
            updateTimelineFilters();
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
        let categoryLabels;
        if (timelineDiv.parent().hasClass('mapping-timeline-layout')) {
            timelineDiv.siblings('.mapping-timeline-group-labels').remove();
            timelineDiv.unwrap();
        }
        if (mapData.timeline.row_group_by) {
            timelineDiv.wrap('<div class="mapping-timeline-layout"></div>');
            categoryLabels = $('<div>', {class: 'mapping-timeline-group-labels', role: 'list'}).insertBefore(timelineDiv)[0];
        }
        const timelineEventResourceId = function(event) {
            return event.resource_id || event.unique_id;
        };
        // TimelineJS converts date data in place. Keep pristine events for restoring filters.
        const allTimelineEvents = JSON.parse(JSON.stringify(timelineDiv.data('data').events));
        let updatingTimelineFilters = false;

        timeline = new TL.Timeline(
            timelineDiv[0],
            timelineDiv.data('data'),
            timelineDiv.data('options')
        );
        timelineDiv[0].mapping_timeline = timeline;
        if (mapData.timeline.row_group_by) {
            const groupLabels = new Map(timelineDiv.data('data').events.map(event => [event.group, event.mapping_group_label]));
            const labelGroups = function(nav) {
                // TimelineJS treats group labels as HTML. Group by stable IDs
                // and keep plain-text labels in their own column, away from markers.
                const labelsTop = categoryLabels.getBoundingClientRect().top;
                categoryLabels.replaceChildren(...nav._groups.map(group => {
                    const band = group._el.container.getBoundingClientRect();
                    const label = document.createElement('div');
                    label.className = 'mapping-timeline-group-label';
                    label.setAttribute('role', 'listitem');
                    label.textContent = groupLabels.get(group.data.label) || group.data.label;
                    label.title = label.textContent;
                    label.style.top = (band.top - labelsTop) + 'px';
                    label.style.height = band.height + 'px';
                    return label;
                }));
            };
            const prepareTimelineGroups = function() {
                const nav = timeline._timenav;
                if (!nav || !nav.timescale || nav.mappingGroupsPrepared) return;
                nav.mappingGroupsPrepared = true;
                const positionGroups = nav._positionGroups;
                nav._positionGroups = function() {
                    positionGroups.apply(this, arguments);
                    // Join neighbouring bands and extend the last to the visible
                    // date axis: the compact nav is taller than its internal height.
                    const axisTop = this._el.timeaxis_background.offsetTop;
                    this._groups.forEach((group, index) => {
                        const band = group._el.container;
                        const next = this._groups[index + 1];
                        const bottom = next ? parseFloat(next._el.container.style.top) : axisTop;
                        band.style.height = Math.max(0, bottom - parseFloat(band.style.top)) + 'px';
                    });
                    labelGroups(this);
                };
                // TimelineJS recalculates marker rows on zoom/resize, but leaves
                // each existing band's row count (and sometimes its height) stale.
                const assignRows = nav._assignRowsToMarkers;
                nav._assignRowsToMarkers = function() {
                    const rows = new Map(this.timescale.getGroupLabels().map(group => [group.label, group.rows]));
                    this._groups.forEach(group => { group.data.rows = rows.get(group.data.label) || 1; });
                    assignRows.apply(this, arguments);
                    this._positionGroups();
                };
                // A redraw creates new bands; remove the old ones first.
                const createGroups = nav._createGroups;
                nav._createGroups = function() {
                    this._groups.forEach(group => group._el.container.remove());
                    createGroups.apply(this, arguments);
                };
                nav._assignRowsToMarkers();
            };
            timeline.on('loaded', prepareTimelineGroups);
            prepareTimelineGroups();
        }
        const filteredEventFeatures = function(event) {
            const resourceFeatures = featuresByResource[timelineEventResourceId(event)];
            if (!resourceFeatures) return null;
            const layers = resourceFeatures.getLayers().filter(matchesFilters);
            return layers.length ? L.featureGroup(layers) : null;
        };
        const timelineContainer = timelineDiv.parent().hasClass('mapping-timeline-layout')
            ? timelineDiv.parent() : timelineDiv;
        const timelineEmpty = timelineContainer.siblings('.mapping-timeline-filter-empty');
        let timelineReady = timeline.ready;
        let timelineInitializing = !timelineReady;
        updateTimelineFilters = function() {
            // Wait for every feature page so later results are not mistaken for non-matches.
            if (!filters || !featuresLoaded || !timelineReady) return;
            const events = allTimelineEvents.filter(function(event) {
                const resourceFeatures = featuresByResource[timelineEventResourceId(event)];
                return resourceFeatures && resourceFeatures.getLayers().some(matchesFilters);
            });
            const eventIds = new Set(events.map(event => event.unique_id));
            if (currentTimelineEvent && !eventIds.has(currentTimelineEvent.unique_id)) {
                currentTimelineEvent = null;
            }
            timelineEmpty.prop('hidden', events.length > 0);
            timelineContainer.toggle(events.length > 0);
            // TimelineJS requires at least one event. Keep its internal data while
            // the entire navigation is hidden, and restore matching events below.
            if (!events.length) return;
            const previousId = timeline.current_id;
            updatingTimelineFilters = true;
            try {
                // Add before removing so switching between disjoint categories
                // never gives TimelineJS an empty date range.
                events.forEach(function(event) {
                    if (!timeline.config.event_dict[event.unique_id]) {
                        timeline.add(JSON.parse(JSON.stringify(event)));
                    }
                });
                timeline.config.events.slice().forEach(function(event) {
                    if (!eventIds.has(event.unique_id)) timeline.removeId(event.unique_id);
                });
                if (categoryLabels) timeline._timenav._createGroups();
                timeline.updateDisplay();
                timeline.goToId(eventIds.has(previousId) ? previousId : events[0].unique_id);
            } finally {
                updatingTimelineFilters = false;
            }
        };
        timeline.on('loaded', function() {
            timelineReady = true;
            updateTimelineFilters();
            // TimelineJS selects its first slide immediately after firing loaded.
            // This is initialization, not a visitor choosing a map location.
            queueMicrotask(function() { timelineInitializing = false; });
        });
        updateTimelineView = function(setView = false) {
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
                if (eventFeatures) {
                    const eventLayers = eventFeatures.getLayers();
                    const selectedLayer = eventLayers.find(layer => layer.getLatLng) || eventLayers[0];
                    if (setView) {
                        // Only timeline navigation opens details and focuses the site.
                        // Opening the sidebar first gives the map its final width.
                        selectedLayer.fire('click');
                        if (selectedLayer.getPopup()) {
                            selectedLayer.openPopup();
                        }
                        const bounds = eventFeatures.getBounds();
                        if (bounds.isValid()) {
                            const maxZoom = $.isNumeric(mapData.timeline.fly_to)
                                ? parseInt(mapData.timeline.fly_to, 10) : 13;
                            timelineFlight = true;
                            map.flyToBounds(bounds, {maxZoom});
                        }
                    } else {
                        // Keep the selection ring without popup auto-pan or sidebar changes.
                        MappingModule.highlightMarker(map, selectedLayer);
                    }
                }
            } else {
                // Restore all eligible features without resetting the map position.
                timelineFeatures.clearLayers();
                map.addLayer(features);
                if (filters) refreshFeatureGroups();
            }
        };
        timeline.on('change', function(e) {
            // Adding/removing timeline events can select a neighbour internally.
            // Only a visitor's timeline navigation should move the map.
            if (updatingTimelineFilters || timelineInitializing) return;
            currentTimelineEvent = timeline.config.event_dict[e.unique_id] || null;
            updateTimelineView(true);
            updateEmptyMessage();
        });
        // TimelineJS emits no change when the active event is clicked again.
        // Allow that event to refocus the map after panning or changing filters.
        const refocusActiveEvent = function(event) {
            if (event.type === 'keydown' && !['Enter', 'Space'].includes(event.code)) return;
            const marker = event.target.closest('.tl-timemarker-active');
            const activeEvent = timeline.config.event_dict[timeline.current_id];
            if (marker && activeEvent && activeEvent.start_date) {
                currentTimelineEvent = activeEvent;
                updateTimelineView(true);
                updateEmptyMessage();
            }
        };
        timelineDiv[0].addEventListener('click', refocusActiveEvent, true);
        timelineDiv[0].addEventListener('keydown', refocusActiveEvent, true);
        map.on('unload', function() {
            timelineDiv[0].removeEventListener('click', refocusActiveEvent, true);
            timelineDiv[0].removeEventListener('keydown', refocusActiveEvent, true);
        });
        updateTimelineFilters();
    }
}

$(document).ready( function() {
    $('.mapping-block:visible').each(function() {
        const blockDiv = $(this);
        MappingBlock(
            blockDiv.find('.mapping-map').first(),
            blockDiv.find('.mapping-timeline, .tl-timeline').first()
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
