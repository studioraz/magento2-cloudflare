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
            account = $('#srcloudflare_cache_account_id'),
            workerName = $('#srcloudflare_cache_worker_name'),
            deleteTarget = {
                account_id: (account.val() || '').trim().toLowerCase(),
                worker_name: (workerName.val() || '').trim()
            },
            statusRequest,
            canDelete = false,
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
        }

        function showZones(zones) {
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
            updateSelectAll();
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
            feedback.text(selection.val() === savedSelection ? '' : $t('Save Configuration to apply the zone selection.'));
        });

        function refreshDeployment() {
            if (statusRequest) {
                statusRequest.abort();
            }
            canDelete = false;
            updateButtons();
            details.prop('hidden', true);
            message.text($t('Loading deployment status…'));
            statusRequest = request('status').done(function (response) {
                var deployment = response.deployment,
                    versions;

                canDelete = response.canDelete === true && !!deleteTarget.account_id && !!deleteTarget.worker_name;
                updateButtons();
                if (response.zones) {
                    showZones(response.zones);
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

        function runAction(action) {
            if (busy) {
                return;
            }
            if (credentialsChanged) {
                feedback.text($t('Save Configuration to use the updated settings.'));
                return;
            }
            if (action === 'deploy' && selection.val() !== savedSelection) {
                feedback.text($t('Save Configuration to apply the zone selection.'));
                return;
            }
            if (action === 'delete' && !canDelete) {
                return;
            }
            busy = true;
            updateButtons();
            feedback.text($t('Contacting Cloudflare…'));
            request(action, action === 'delete' ? deleteTarget : {}).done(function (response) {
                var results = response.results || {},
                    messages = [response.error || response.message || $t('Cloudflare credentials verified.')];

                if (credentialsChanged) {
                    return;
                }
                if (response.zones) {
                    showZones(response.zones);
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
                if ((action === 'deploy' || action === 'delete') && !credentialsChanged) {
                    refreshDeployment();
                } else {
                    updateButtons();
                }
            });
        }

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
                    + $t('Manually configured routes will also stop invoking this Worker.'),
                actions: {
                    confirm: function () {
                        runAction('delete');
                    }
                }
            });
        });

        refreshDeployment();
    };
});
