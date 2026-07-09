/**
 * Mohan_ProductQueueSave — save-via-queue.js
 *
 * Handles the "Save via Queue" button on the admin product edit page
 * using Magento's UI Registry to retrieve product data.
 */
define([
    'jquery',
    'uiRegistry',
    'mage/translate',
    'Magento_Ui/js/modal/alert',
    'Magento_Ui/js/modal/confirm',
    'jquery/ui'
], function ($, registry, $t, uiAlert, uiConfirm) {
    'use strict';

    $.widget('mohan.saveViaQueue', {

        options: {
            url: '',          // Queue-save controller URL
            redirectUrl: '',  // Product grid URL
            queueLogUrl: '',  // Product Queue Log admin page URL
            productId: null
        },

        /** @inheritdoc */
        _create: function () {
            this.element.on('click.saveViaQueue', this._onClick.bind(this));
        },

        _onClick: function (e) {
            e.preventDefault();
            e.stopImmediatePropagation();

            var self = this;

            uiConfirm({
                title: $t('Queue Product Save'),
                content: $t(
                    'The product will be saved asynchronously via the message queue. ' +
                    'Changes will not be visible immediately. Continue?'
                ) + '<br><br>' +
                $t('You can view the queue status by going to<br>') +
                '<strong><a href="' + self.options.queueLogUrl + '" target="_blank">' +
                $t('Catalog') + ' &rarr; ' + $t('Product Queue Log') +
                '</a></strong>',
                actions: {
                    confirm: function () { self._submit(); },
                    cancel: function () { /* do nothing */ }
                }
            });

            return false;
        },

        _submit: function () {
            var self = this;
            console.log('[SaveViaQueue] _submit v7 — links from grid data sources');

            // 1. Find Data Source
            var provider = this.options.provider || 'product_form.product_form_data_source';
            var dataSource = registry.get(provider);

            if (!dataSource || !dataSource.data) {
                this._error($t('Cannot find product data in memory registry. Please reload the page.'));
                return;
            }

            this._loader(true);

            // 2. Identify and Sync Form
            var $form = $('#product_form');
            if (!$form.length) {
                $form = $('form[data-role="edit-product-form"], form.entry-edit-form').first();
            }
            if (!$form.length) {
                $form = $('input[name="form_key"]').closest('form').first();
            }
            
            if ($form.length) {
                // Sync UI components to the form inputs - lighter than full validate()
                $form.trigger('afterValidate');
                $form.trigger('delegate-validate');
                // Removed formComponent.validate() - it is the primary bottleneck (15s+)
            }
            
            // 3. Grab latest data from memory
            var rawData = dataSource.get('data');

            // 3. Format payload (ensure it's wrapped in "product" key if not already)
            var payload = JSON.parse(JSON.stringify(rawData)); // Deep clone is safe once validation is removed

            if (!payload.product) {
                payload = { product: payload };
            }

            // ── Product links (related / upsell / crosssell) ────────────────────────
            // rawData.links is correct for ADDITIONS (the popup callback writes new items
            // into the DynamicRows AND into rawData), but STALE for DELETIONS of
            // pre-existing (page-load) items — the DynamicRows "Remove" action only
            // updates the component's own recordData(), it does NOT write back to the
            // form data source (rawData).
            //
            // Fix: read directly from the DynamicRows recordData() for each link type.
            // Registry paths come from Related.php modifier:
            //   group=related → related fieldset → related DynamicRows
            // Soft-deleted records (delete:'1') are filtered out.
            var linkDynamicRows = {
                'related':   'product_form.product_form.related.related.related',
                'upsell':    'product_form.product_form.related.upsell.upsell',
                'crosssell': 'product_form.product_form.related.crosssell.crosssell'
            };
            var freshLinks = {};
            var anyDrFound = false;
            $.each(linkDynamicRows, function (linkType, componentPath) {
                var dr = registry.get(componentPath);
                if (dr && typeof dr.recordData === 'function') {
                    anyDrFound = true;
                    var records = dr.recordData() || [];
                    var kept = [];
                    $.each(records, function (i, r) {
                        if (!r['delete'] && !r['_delete']) {
                            kept.push({
                                id:       r.id || r.entity_id,
                                position: parseInt(r.position, 10) || 0
                            });
                        }
                    });
                    freshLinks[linkType] = kept;
                }
            });
            if (anyDrFound) {
                payload.links = freshLinks;
                payload.links_managed = '1';
                console.log('[SaveViaQueue] links from DynamicRows:', JSON.stringify(freshLinks));
            } else {
                // Fallback: rawData.links — correct for additions, stale for deletions.
                // Do NOT set links_managed so PHP skips setProductLinks() entirely (preserves existing links).
                console.log('[SaveViaQueue] DynamicRows not found — falling back to rawData.links (no link sync)');
            }

            // ── Gallery removal sync ────────────────────────────────────────────────
            // Magento's gallery widget uses value_id as the DOM array key (file_id),
            // while rawData uses 0-based sequential indices. They never merge in PHP.
            // Also, with 49+ images the combined POST vars can exceed max_input_vars (1000),
            // silently dropping the DOM-indexed gallery inputs before they reach PHP.
            //
            // Fix: read .is-removed inputs directly from the DOM gallery (indexed by value_id),
            // correlate with rawData images by value_id, and apply removed:'1' in-place.
            // Scan the DOM gallery for removed images and collect their value_ids.
            // We send these as a separate field (gallery_remove_ids) rather than
            // patching payload.product.media_gallery.images, because rawData is
            // populated at page-load time and may be stale if the product was saved
            // again since then — causing a value_id mismatch with the current DOM.
            var removeIds = [];
            $('[data-role="image"]').each(function () {
                var $isRemoved = $(this).find('.is-removed');
                if ($isRemoved.length && String($isRemoved.val()) === '1') {
                    // Input name: product[media_gallery][images][<value_id>][removed]
                    var match = ($isRemoved.attr('name') || '').match(/\[(\d+)\]\[removed\]$/);
                    if (match) {
                        removeIds.push(match[1]);
                    }
                }
            });
            // gallery_remove_ids goes into the URL, not POST body — never truncated by max_input_vars.
            var galleryRemoveIdsStr = removeIds.join(',');
            console.log('[SaveViaQueue] gallery_remove_ids:', galleryRemoveIdsStr);

            // ── Gallery: strip all rawData images, rebuild new uploads from DOM ──────
            //
            // rawData.product.media_gallery contains all DB images (could be 500+).
            // Sending them as POST vars would blow past max_input_vars (1000).
            // Removals are handled via gallery_remove_ids in the URL.
            // New uploads are NOT in rawData (rawData is populated at page-load),
            // so we must harvest them from the DOM form inputs.
            //
            // Strategy:
            //  1. Delete ALL rawData gallery images from payload.
            //  2. Scan DOM for product[media_gallery][images][idx][...] inputs.
            //  3. Group by idx; keep only groups where value_id is empty (new uploads,
            //     not existing DB images).
            //  4. Set those as payload.product.media_gallery.images so they reach PHP.

            // Step 1 – drop all rawData images
            if (payload.product && payload.product.media_gallery) {
                delete payload.product.media_gallery;
            }

            // Step 2 & 3 – collect new uploads from DOM
            var domGalleryByIdx = {};
            $('[name^="product[media_gallery][images]["]').each(function () {
                var m = (this.name || '').match(
                    /^product\[media_gallery\]\[images\]\[([^\]]+)\]\[([^\]]+)\]/
                );
                if (!m) { return; }
                var idx = m[1], field = m[2];
                if (!domGalleryByIdx[idx]) { domGalleryByIdx[idx] = {}; }
                // For 'removed', prefer the last value written (is-removed wins)
                domGalleryByIdx[idx][field] = this.value;
            });

            // Build a set of image file paths that are variation-specific.
            // When the user uploads an image for a variation in the configurable matrix,
            // Magento's admin also adds it to the main product gallery DOM. We must
            // exclude these from the parent product's gallery — they belong to children only.
            // Build a set of every image file referenced by ANY variation row.
            // rawData['configurable-matrix'] is a JS array (fields: image, small_image,
            // thumbnail, swatch_image, thumbnail_image, media_gallery).
            // The DOM hidden input [name="configurable-matrix"] is empty, so we must
            // read directly from rawData — do NOT filter by typeof string.
            var variationImageFiles = {};
            try {
                var cmRaw = rawData['configurable-matrix'];
                var cmData = Array.isArray(cmRaw) ? cmRaw : [];
                if (!cmData.length && typeof cmRaw === 'string' && cmRaw.length > 2) {
                    cmData = JSON.parse(cmRaw);
                }

                $.each(cmData, function (i, row) {
                    var roles = ['image', 'small_image', 'thumbnail', 'swatch_image', 'thumbnail_image'];
                    $.each(roles, function (j, role) {
                        var val = row[role];
                        if (!val || val === 'no_selection') { return; }
                        if (typeof val === 'string') {
                            variationImageFiles[val] = true;
                            variationImageFiles[val.replace(/\.tmp$/, '')] = true;
                            if (!/\.tmp$/.test(val)) { variationImageFiles[val + '.tmp'] = true; }
                        } else if (val && typeof val === 'object') {
                            // {src: "http://.../catalog/product/0/3/file.jpg?..."}
                            var src = val.src || val.url || val.file || '';
                            var m = src.match(/catalog\/product(\/.+?)(\?|$)/);
                            if (m) {
                                variationImageFiles[m[1]] = true;
                                variationImageFiles[m[1].replace(/\.tmp$/, '')] = true;
                                if (!/\.tmp$/.test(m[1])) { variationImageFiles[m[1] + '.tmp'] = true; }
                            }
                        }
                    });
                    // media_gallery.images — covers all gallery images assigned to the variation.
                    // media_gallery may be a plain object, a string (JSON-encoded), or null.
                    var mg = row.media_gallery;
                    if (typeof mg === 'string' && mg.length > 2) {
                        try { mg = JSON.parse(mg); } catch (ex) { mg = null; }
                    }
                    var mgImages = (mg || {}).images || [];
                    // Magento often stores gallery images as a numeric-keyed object {0:{...},1:{...}}
                    if (!Array.isArray(mgImages)) { mgImages = Object.values(mgImages || {}); }
                    $.each(mgImages, function (k, img) {
                        if (!img) { return; }
                        var f = img.file || img.url || img.src || '';
                        if (f) {
                            variationImageFiles[f] = true;
                            variationImageFiles[f.replace(/\.tmp$/, '')] = true;
                            if (!/\.tmp$/.test(f)) { variationImageFiles[f + '.tmp'] = true; }
                        }
                    });
                });
                console.log('[SaveViaQueue] variation image files excluded from parent:', Object.keys(variationImageFiles));
            } catch (e) {
                console.warn('[SaveViaQueue] configurable-matrix image exclusion error:', e);
            }

            var newDomImages = [];
            $.each(domGalleryByIdx, function (idx, img) {
                // Skip existing (value_id present) and already-removed images
                if (img.value_id || String(img.removed) === '1') { return; }
                if (!img.file) { return; }
                // Skip images that are variation-specific (belong to child products only)
                if (variationImageFiles[img.file]) { return; }
                newDomImages.push(img);
            });

            // Step 4 – inject new images into the payload
            if (newDomImages.length > 0) {
                if (!payload.product) { payload.product = {}; }
                payload.product.media_gallery = { images: newDomImages };
                console.log('[SaveViaQueue] New uploads from DOM:', newDomImages.length,
                    newDomImages.map(function (i) { return i.file; }));
            }

            // 4. Capture supplementary data from the DOM
            // Skip product[media_gallery][images][...] — new uploads are already
            // collected above (Step 2-4); existing images are intentionally omitted.
            var allFormData = $('[name^="product["], [name^="configurable-"], [name="form_key"]')
                .not('[data-index="configurable_matrix"] *')
                .not('.admin__control-table *')
                .not('.configurable-matrix *')
                .not('.modal-popup *')
                .not('.modal-slide *')
                .serializeArray();

            $.each(allFormData, function () {
                var name = this.name;
                var value = this.value;

                // Skip gallery image array inputs — handled above via gallery_remove_ids.
                if (name.indexOf('product[media_gallery][images][') === 0) {
                    return;
                }

                // Merge product fields and other relevant inputs
                // Flat assignment ensures PHP correctly reconstructs nested arrays like media_gallery
                if (name.indexOf('product[') === 0) {
                    if (name === 'product[image]' || name === 'product[small_image]' ||
                        name === 'product[thumbnail]' || name === 'product[swatch_image]') {
                        if (value && value !== 'no_selection') {
                            payload[name] = value;
                            if (payload.product) {
                                var key = name.replace('product[', '').replace(']', '');
                                payload.product[key] = value;
                            }
                        }
                    } else {
                        payload[name] = value;
                    }
                } else if (name !== 'product') {
                    payload[name] = value;
                }
            });

            // ── Preserve configurable-matrix from rawData ────────────────────────────
            // rawData['configurable-matrix'] is a JS array (not a string).
            // The deep clone of rawData above copied it as an array too.
            // When jQuery serializes an array value it expands to bracket-notation params
            // (configurable-matrix[0][image]=…) which can push past PHP's max_input_vars=1000,
            // silently dropping the image/role fields before PHP receives them.
            //
            // Fix: delete the key (removes the deep-clone array from the object) and
            // re-add it as a JSON string.  Deleting + re-adding moves the key to the END
            // of the object so it is serialized last — as exactly ONE POST param — and
            // PHP receives the full JSON string regardless of param count.
            delete payload['configurable-matrix'];
            var rawCm = rawData['configurable-matrix'];
            if (rawCm !== null && rawCm !== undefined) {
                payload['configurable-matrix'] = Array.isArray(rawCm)
                    ? JSON.stringify(rawCm)
                    : (typeof rawCm === 'string' ? rawCm : JSON.stringify(rawCm));
                console.log('[SaveViaQueue] Serialized rawData configurable-matrix, rows:',
                    Array.isArray(rawCm) ? rawCm.length : typeof rawCm);
            }
            delete payload['variations-matrix'];
            var rawVm = rawData['variations-matrix'];
            if (rawVm !== null && rawVm !== undefined) {
                payload['variations-matrix'] = typeof rawVm === 'string'
                    ? rawVm : JSON.stringify(rawVm);
            }

            // Add queue_save sentinel at the top level
            payload.queue_save = '1';

            // Pass form_key and gallery_remove_ids in the URL — never truncated by max_input_vars.
            var formKey = $('input[name="form_key"]').first().val();
            var ajaxUrl = this.options.url +
                (this.options.url.indexOf('?') === -1 ? '?' : '&') +
                'form_key=' + encodeURIComponent(formKey) +
                (galleryRemoveIdsStr ? '&gallery_remove_ids=' + encodeURIComponent(galleryRemoveIdsStr) : '');

            // 4. Send AJAX
            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: payload,
                dataType: 'json',
                cache: false
            })
                .done(function (resp) {
                    self._loader(false);

                    if (resp && resp.success === true) {
                        window.location.href = self.options.redirectUrl;
                    } else {
                        self._error(
                            (resp && resp.message)
                                ? resp.message
                                : $t('Failed to queue the product. Please try again.')
                        );
                    }
                })
                .fail(function (xhr) {
                    self._loader(false);

                    var msg = $t('Request failed.');
                    try {
                        var body = JSON.parse(xhr.responseText);
                        if (body && body.message) { msg = body.message; }
                    } catch (ignore) {
                        msg = (xhr.status || '') + ' ' + (xhr.statusText || msg);
                    }
                    self._error(msg);
                });
        },

        _loader: function (show) {
            $('body').trigger(show ? 'processStart' : 'processStop');
        },

        _error: function (message) {
            uiAlert({ title: $t('Queue Save Error'), content: message });
        }
    });

    return $.mohan.saveViaQueue;
});
