define([
    'jquery',
    'mage/url',
    'Magento_Ui/js/modal/alert'
], function ($, urlBuilder, alert) {
    'use strict';

    return function (config, element) {
        var startUrl = config.startUrl;
        var confirmUrl = config.confirmUrl;
        var systemCheckUrl = config.systemCheckUrl;
        var formKey = config.formKey;
        var currentUpgradeId = null;
        var progressInterval = null;
        var statusUrl = null;
        var currentStep = 1;

        function goToStep(step) {
            currentStep = step;
            $('.wizard-step').hide();
            $('#wizard-step-' + step).show();

            // Update step indicators
            $('.step-indicator').each(function () {
                var s = parseInt($(this).data('step'), 10);
                $(this).removeClass('step-indicator--active step-indicator--completed');
                if (s === step) {
                    $(this).addClass('step-indicator--active');
                } else if (s < step) {
                    $(this).addClass('step-indicator--completed');
                }
            });
        }

        // Step 1: Select Version → Next → System Check
        $('#btn-step1-next').on('click', function () {
            var targetVersion = $('#target-version').val();
            if (!targetVersion) {
                alert({ content: 'Please select a target version.' });
                return;
            }

            goToStep(2);
            runSystemCheck(targetVersion);
        });

        // Step 2: System Check
        function runSystemCheck(targetVersion) {
            var badge = $('#system-check-badge');
            var table = $('#system-check-table');
            var tbody = $('#system-check-tbody');
            var errorDiv = $('#system-check-error');
            var nextBtn = $('#btn-step2-next');

            badge.show().text('Checking...').attr('class', 'status-badge status-badge--running');
            table.hide();
            tbody.empty();
            errorDiv.hide();
            nextBtn.prop('disabled', true);

            $.ajax({
                url: systemCheckUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    form_key: formKey,
                    target_version: targetVersion
                },
                success: function (response) {
                    if (response.success) {
                        renderSystemChecks(response.checks);
                        table.show();

                        if (response.compatible) {
                            badge.text('Compatible').attr('class', 'status-badge status-badge--completed');
                            nextBtn.prop('disabled', false);
                        } else {
                            badge.text('Incompatible').attr('class', 'status-badge status-badge--failed');
                            nextBtn.prop('disabled', true);
                        }
                    } else {
                        badge.text('Error').attr('class', 'status-badge status-badge--failed');
                        errorDiv.show().text(response.message || 'System check failed.');
                    }
                },
                error: function () {
                    badge.text('Error').attr('class', 'status-badge status-badge--failed');
                    errorDiv.show().text('Request failed. Please check server logs.');
                }
            });
        }

        function renderSystemChecks(checks) {
            var tbody = $('#system-check-tbody');
            tbody.empty();

            checks.forEach(function (check) {
                var statusHtml = check.passed
                    ? '<span class="check-pass">&#10003; Pass</span>'
                    : '<span class="check-fail">&#10007; Fail</span>';

                var row = '<tr class="' + (check.passed ? '' : 'check-row--failed') + '">' +
                    '<td>' + check.requirement + '</td>' +
                    '<td>' + check.current + '</td>' +
                    '<td>' + check.required + '</td>' +
                    '<td>' + statusHtml + '</td>' +
                    '</tr>';
                tbody.append(row);
            });
        }

        // Step 2 navigation
        $('#btn-step2-back').on('click', function () {
            goToStep(1);
        });

        $('#btn-step2-next').on('click', function () {
            goToStep(3);
            runScan();
        });

        // Step 3: Scan
        function runScan() {
            var targetVersion = $('#target-version').val();
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
                error: function () {
                    $('#scan-status-badge').text('Failed').attr('class', 'status-badge status-badge--failed');
                    alert({ content: 'Request failed. Please check server logs.' });
                }
            });
        }

        // Step 3 navigation
        $('#btn-step3-back').on('click', function () {
            goToStep(2);
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
                        statusUrl = response.status_url;
                        goToStep(6);
                        startProgressTracking();
                    } else {
                        updateProgressUI(null, response.message, 'failed');
                        goToStep(6);
                        $('#rollback-actions').show();
                    }
                },
                error: function () {
                    updateProgressUI(null, 'Failed to start upgrade process.', 'failed');
                    goToStep(6);
                    $('#rollback-actions').show();
                }
            });
        });

        // Progress Tracking
        function startProgressTracking() {
            $('#progress-status').text('In Progress').attr('class', 'status-badge status-badge--running');

            progressInterval = setInterval(function () {
                $.ajax({
                    url: statusUrl,
                    type: 'GET',
                    dataType: 'json',
                    success: function (response) {
                        if (response.success && response.data) {
                            var data = response.data;
                            updateProgressUI(data.progress_percent, data.current_step, data.status);
                            renderTimeline(data.steps || []);

                            if (data.status === 'completed') {
                                stopProgressTracking();
                                updateProgressUI(100, 'Upgrade completed successfully!', 'completed');
                                // Move to step 7 (Done)
                                goToStep(7);
                            } else if (data.status === 'failed') {
                                stopProgressTracking();
                                updateProgressUI(null, data.error_message || 'Upgrade failed', 'failed');
                                $('#rollback-actions').show();
                            }
                        }
                    },
                    error: function () {
                        // Keep polling; the process may still be starting up.
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

        // Initialize: show step 1
        goToStep(1);
    };
});
