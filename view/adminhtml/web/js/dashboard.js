define([
    'jquery',
    'mage/url',
    'Magento_Ui/js/modal/alert'
], function ($, urlBuilder, alert) {
    'use strict';

    return function (config, element) {
        var startUrl = config.startUrl;
        var confirmUrl = config.confirmUrl;
        var progressUrl = config.progressUrl;
        var formKey = config.formKey;
        var currentUpgradeId = null;
        var progressInterval = null;

        // Start Upgrade (scan + prepare)
        $('#btn-start-upgrade').on('click', function () {
            var targetVersion = $('#target-version').val();
            if (!targetVersion) {
                alert({ content: 'Please select a target version.' });
                return;
            }

            var btn = $(this);
            btn.prop('disabled', true).text('Scanning...');
            $('#scan-results-panel').show();
            $('#scan-status-badge').text('Scanning...').attr('class', 'status-badge status-badge--running');

            $.ajax({
                url: startUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    form_key: formKey,
                    target_version: targetVersion,
                    include_patches: $('#include-patches').is(':checked') ? 1 : 0
                },
                success: function (response) {
                    btn.prop('disabled', false).text('Scan & Prepare Upgrade');

                    if (response.success) {
                        currentUpgradeId = response.upgrade_id;
                        $('#scan-status-badge').text('Completed').attr('class', 'status-badge status-badge--completed');
                        showScanResults(response);
                        showConfirmModal();
                    } else {
                        $('#scan-status-badge').text('Failed').attr('class', 'status-badge status-badge--failed');
                        $('#scan-summary').html('<div class="autoupgrader-error">' + response.message + '</div>');
                    }
                },
                error: function (xhr) {
                    btn.prop('disabled', false).text('Scan & Prepare Upgrade');
                    $('#scan-status-badge').text('Failed').attr('class', 'status-badge status-badge--failed');
                    alert({ content: 'Request failed. Please check server logs.' });
                }
            });
        });

        // Confirm Modal
        function showConfirmModal() {
            $('#confirm-modal').show();
            var targetVersion = $('#target-version').val();
            $('#confirm-summary').html(
                '<p><strong>Upgrade path:</strong> Current &rarr; ' + targetVersion + '</p>' +
                '<p><strong>Upgrade ID:</strong> ' + currentUpgradeId + '</p>'
            );
        }

        $('#btn-cancel-upgrade').on('click', function () {
            $('#confirm-modal').hide();
        });

        $('.autoupgrader-modal__overlay').on('click', function () {
            $('#confirm-modal').hide();
        });

        // Confirm and Execute
        $('#btn-confirm-upgrade').on('click', function () {
            $('#confirm-modal').hide();
            startProgressTracking();

            var btn = $(this);
            btn.prop('disabled', true);

            $.ajax({
                url: confirmUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    form_key: formKey,
                    upgrade_id: currentUpgradeId
                },
                success: function (response) {
                    if (response.success) {
                        stopProgressTracking();
                        updateProgressUI(100, 'Upgrade completed successfully!', 'completed');
                    } else {
                        stopProgressTracking();
                        updateProgressUI(null, response.message, 'failed');
                        $('#rollback-actions').show();
                    }
                },
                error: function () {
                    stopProgressTracking();
                    updateProgressUI(null, 'Upgrade request failed.', 'failed');
                    $('#rollback-actions').show();
                }
            });
        });

        // Progress Tracking
        function startProgressTracking() {
            $('#progress-panel').show();
            $('#progress-status').text('In Progress').attr('class', 'status-badge status-badge--running');

            progressInterval = setInterval(function () {
                $.ajax({
                    url: progressUrl,
                    type: 'GET',
                    dataType: 'json',
                    data: { upgrade_id: currentUpgradeId },
                    success: function (response) {
                        if (response.success && response.data) {
                            var data = response.data;
                            updateProgressUI(data.progress_percent, data.current_step, data.status);
                            renderTimeline(data.steps || []);

                            if (data.status === 'completed' || data.status === 'failed') {
                                stopProgressTracking();
                            }
                        }
                    }
                });
            }, 2000);
        }

        function stopProgressTracking() {
            if (progressInterval) {
                clearInterval(progressInterval);
                progressInterval = null;
            }
        }

        function updateProgressUI(percent, step, status) {
            if (percent !== null) {
                $('#progress-fill').css('width', percent + '%');
                $('#progress-percent').text(percent + '%');
            }
            if (step) {
                $('#progress-step').text(step);
            }
            if (status === 'completed') {
                $('#progress-status').text('Completed').attr('class', 'status-badge status-badge--completed');
                $('#progress-fill').css('background', 'linear-gradient(90deg, #16a34a, #22c55e)');
            } else if (status === 'failed') {
                $('#progress-status').text('Failed').attr('class', 'status-badge status-badge--failed');
                $('#progress-error').show().text(step || 'An error occurred');
            }
        }

        function renderTimeline(steps) {
            var html = '';
            steps.forEach(function (step) {
                var statusClass = 'timeline-step--' + step.status;
                html += '<div class="timeline-step ' + statusClass + '">';
                html += '  <div class="timeline-step__dot"></div>';
                html += '  <div class="timeline-step__label">' + step.label + '</div>';
                if (step.message) {
                    html += '  <div class="timeline-step__message">' + step.message + '</div>';
                }
                html += '</div>';
            });
            $('#progress-timeline').html(html);
        }

        function showScanResults(response) {
            // Basic scan results display
            $('#scan-summary').html(
                '<p>Scan completed. Review and confirm to proceed with the upgrade.</p>'
            );
        }

        // Rollback
        $('#btn-rollback').on('click', function () {
            if (!confirm('Are you sure you want to rollback? This will restore the previous backup.')) {
                return;
            }

            var btn = $(this);
            btn.prop('disabled', true).text('Rolling back...');

            $.ajax({
                url: startUrl.replace('start', 'rollback'),
                type: 'POST',
                dataType: 'json',
                data: {
                    form_key: formKey,
                    upgrade_id: currentUpgradeId
                },
                success: function (response) {
                    btn.prop('disabled', false).text('Rollback to Previous Version');
                    if (response.success) {
                        alert({ content: 'Rollback completed successfully. Your store has been restored.' });
                    } else {
                        alert({ content: 'Rollback failed: ' + response.message });
                    }
                }
            });
        });
    };
});
