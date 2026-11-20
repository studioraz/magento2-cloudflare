define(['jquery', 'mage/translate', 'Magento_Ui/js/modal/confirm'], function ($, $t, confirm) {
    'use strict';

    return function (config, element) {
        var root = $(element),
            feedback = root.find('[data-role="feedback"]'),
            table = root.find('[data-role="zones"]'),
            selection = root.find('[data-role="zone-selection"]'),
            selectAll = table.find('[data-role="select-all-zones"]'),
            savedSelection = selection.val(),
            details = root.find('[data-role="deployment-details"]'),
            message = root.find('[data-role="deployment-message"]'),
            buttons = root.find('button[data-action]'),
            deleteButton = buttons.filter('[data-action="delete"]'),
            deployButton = buttons.filter('[data-action="deploy"]'),
            engineStatus = $('#row_srcloudflare_cache_current_cache_engine .control-value'),
            engineField = $('#srcloudflare_cache_magento_cache_caching_application'),
            account = $('#srcloudflare_cache_account_id'),
            workerName = $('#srcloudflare_cache_worker_name'),
            deleteTarget = {
                account_id: (account.val() || '').trim().toLowerCase(),
                worker_name: (workerName.val() || '').trim()
            },
            statusRequest,
            canDelete = false,
            canDeploy = false,
            isCloudflare,
            busy = false,
            credentialsChanged = false;

        function request(action, data) {
            return $.ajax({
                url: root.data(action + '-url'),
                method: 'POST',
                dataType: 'json',
                data: $.extend({form_key: window.FORM_KEY}, data)
            });
        }

        function updateButtons() {
            buttons.prop('disabled', busy);
            deleteButton.prop('disabled', busy || !canDelete || credentialsChanged);
            deployButton.prop('disabled', busy || !canDeploy || credentialsChanged || selection.val() !== savedSelection);
            table.find('[data-role="forget-zone"]').prop('disabled', busy || credentialsChanged || selection.val() !== savedSelection);
        }

        function showZones(zones, unavailableZoneIds) {
            var body = table.find('tbody').empty(),
                zoneIds = Object.keys(zones),
                value = selection.val(),
                all = value === 'all',
                selected = all || value === 'none' ? [] : value.split(',');

            zoneIds.forEach(function (id) {
                var checkbox = $('<input type="checkbox" data-role="select-zone">')
                    .val(id)
                    .attr('aria-label', $t('Select zone %1').replace('%1', zones[id]))
                    .prop('checked', all || selected.indexOf(id) !== -1)
                    .prop('disabled', selection.prop('disabled'));

                $('<tr>').append($('<td>').append(checkbox), $('<td>').text(id), $('<td>').text(zones[id]))
                    .appendTo(body);
            });
            unavailableZoneIds.forEach(function (id) {
                var forget = $('<button type="button" class="action-default" data-role="forget-zone">')
                    .data('zone-id', id)
                    .text($t('Forget deleted zone'))
                    .attr('aria-label', $t('Forget deleted zone %1').replace('%1', id));

                $('<tr>').append($('<td>'), $('<td>').text(id), $('<td>').text($t('Unavailable') + ' ').append(forget))
                    .appendTo(body);
            });
            updateSelectAll();
            updateButtons();
            table.prop('hidden', false);
        }

        function updateSelectAll() {
            var checkboxes = table.find('[data-role="select-zone"]'),
                checked = checkboxes.filter(':checked').length;

            selectAll.prop('checked', checkboxes.length > 0 && checked === checkboxes.length)
                .prop('indeterminate', checked > 0 && checked < checkboxes.length)
                .prop('disabled', selection.prop('disabled') || checkboxes.length === 0);
        }

        table.on('change', '[data-role="select-all-zones"], [data-role="select-zone"]', function () {
            var checkboxes = table.find('[data-role="select-zone"]'),
                selected;

            if (this === selectAll[0]) {
                checkboxes.prop('checked', this.checked);
            }
            selected = checkboxes.filter(':checked').map(function () {
                return this.value;
            }).get();
            selection.val(selected.length === checkboxes.length ? 'all' : (selected.join(',') || 'none'));
            updateSelectAll();
            updateButtons();
            feedback.text(selection.val() === savedSelection ? '' : $t('Save Configuration to apply the zone selection.'));
        });

        function refreshDeployment() {
            if (statusRequest) {
                statusRequest.abort();
            }
            canDelete = false;
            canDeploy = false;
            updateButtons();
            details.prop('hidden', true);
            message.text($t('Loading deployment status…'));
            statusRequest = request('status').done(function (response) {
                var deployment = response.deployment,
                    versions;

                canDelete = response.canDelete === true && !!deleteTarget.account_id && !!deleteTarget.worker_name;
                canDeploy = response.canDeploy === true;
                if (response.cacheEngine) {
                    engineStatus.text(response.cacheEngine);
                    if (isCloudflare !== undefined && response.isCloudflare !== isCloudflare) {
                        if (response.isCloudflare && !engineField.find('option[value="3"]').length) {
                            engineField.append($('<option>').val('3').text($t('Cloudflare CDN')));
                        }
                        engineField.val(response.isCloudflare ? '3' : '2').prop('disabled', true);
                    }
                    isCloudflare = response.isCloudflare;
                }
                updateButtons();
                if (response.zones) {
                    showZones(response.zones, response.unavailableZoneIds || []);
                }
                if (response.error || !deployment) {
                    message.text(response.error || $t('Worker has not been deployed yet.'));
                    return;
                }

                versions = root.find('[data-role="versions"]').empty();
                deployment.versions.forEach(function (id) {
                    var row = $('<div>').append($('<code>').text(id.slice(0, 8)).attr('title', id));

                    if (navigator.clipboard) {
                        $('<button type="button" class="action-default">')
                            .text($t('Copy Version ID'))
                            .on('click', function () {
                                navigator.clipboard.writeText(id).catch(function () {
                                    feedback.text($t('Could not copy the Version ID.'));
                                });
                            }).appendTo(row);
                    }
                    row.appendTo(versions);
                });
                root.find('[data-role="deployed"]').text(new Date(deployment.deployedAt).toLocaleString(undefined, {
                    year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
                    second: '2-digit', timeZoneName: 'short'
                }));
                message.empty();
                details.prop('hidden', false);
            }).fail(function (xhr, state) {
                if (state !== 'abort') {
                    message.text($t('Could not load deployment status from Cloudflare.'));
                }
            });
        }

        account.add(workerName).add('#srcloudflare_cache_api_token').on('input', function () {
            credentialsChanged = true;
            updateButtons();
            if (statusRequest) {
                statusRequest.abort();
            }
            table.prop('hidden', true);
            details.prop('hidden', true);
            message.empty();
            feedback.text($t('Save Configuration to use the updated settings.'));
        });

        function runAction(action, data) {
            if (busy) {
                return;
            }
            if (credentialsChanged) {
                feedback.text($t('Save Configuration to use the updated settings.'));
                return;
            }
            if (action !== 'delete' && selection.val() !== savedSelection) {
                feedback.text($t('Save Configuration to apply the zone selection.'));
                return;
            }
            if (action === 'delete' && !canDelete) {
                return;
            }
            if (statusRequest) {
                statusRequest.abort();
            }
            if (action === 'verify') {
                canDeploy = false;
            }
            busy = true;
            updateButtons();
            feedback.text($t('Contacting Cloudflare…'));
            request(action, data).done(function (response) {
                var results = response.results || {},
                    messages = [response.error || response.message || $t('Cloudflare credentials verified.')];

                if (credentialsChanged) {
                    return;
                }
                if (action === 'forget' && response.selection) {
                    savedSelection = response.selection;
                    selection.val(savedSelection);
                    canDeploy = false;
                }
                if (response.zones) {
                    showZones(response.zones, response.unavailableZoneIds || []);
                }
                if (action === 'verify') {
                    canDeploy = response.canDeploy === true;
                }
                Object.keys(results).forEach(function (domain) {
                    messages.push(domain + ' — ' + results[domain]);
                });
                feedback.empty();
                messages.forEach(function (item) {
                    $('<div>').text(item).appendTo(feedback);
                });
            }).fail(function () {
                feedback.text($t('The Cloudflare operation could not be completed.'));
            }).always(function () {
                busy = false;
                if ((action === 'deploy' || action === 'delete' || action === 'forget') && !credentialsChanged) {
                    refreshDeployment();
                } else {
                    updateButtons();
                }
            });
        }

        table.on('click', '[data-role="forget-zone"]', function () {
            var zoneId = $(this).data('zone-id');

            confirm({
                title: $t('Forget deleted zone'),
                content: $t('Confirm that zone %1 was deleted in Cloudflare. Magento will remove its saved configuration and purge jobs.')
                    .replace('%1', $('<span>').text(zoneId).html()),
                actions: {
                    confirm: function () {
                        runAction('forget', {account_id: deleteTarget.account_id, zone_id: zoneId, confirmed: '1'});
                    }
                }
            });
        });

        buttons.on('click', function () {
            var action = $(this).data('action');

            if (action !== 'delete') {
                runAction(action);
                return;
            }

            confirm({
                title: $t('Delete Worker'),
                content: $t('Delete Worker "%1" and its Magento-managed routes?')
                    .replace('%1', $('<span>').text(deleteTarget.worker_name).html()) + ' '
                    + $t('Manually configured routes will also stop invoking this Worker. If Magento uses Cloudflare CDN, it will switch to Varnish.'),
                actions: {
                    confirm: function () {
                    runAction('delete', deleteTarget);
                    }
                }
            });
        });

        refreshDeployment();
    };
});
