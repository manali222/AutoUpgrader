define(['jquery'], function ($) {
    'use strict';

    return function (config) {
        var scanUrl = config.scanUrl,
            fixUrl = config.fixUrl,
            executeUrl = config.executeUrl,
            prepareUrl = config.prepareUrl,
            progressUrl = config.progressUrl,
            statusUrl = config.statusUrl,
            statusToken = null,
            rollbackUrl = config.rollbackUrl,
            systemCheckUrl = config.systemCheckUrl,
            formKey = config.formKey,
            currentVersion = config.currentVersion,
            currentStep = 1,
            scanData = null,
            upgradeId = null,
            progressTimer = null;

        // ─── Wizard Navigation ───
        function goToStep(step) {
            currentStep = step;
            $('.wizard-panel').hide();
            $('#wizard-step-' + step).fadeIn(300);
            $('.wizard-step-indicator').each(function () {
                var s = parseInt($(this).data('step'));
                $(this).removeClass('active completed');
                if (s < step) $(this).addClass('completed');
                if (s === step) $(this).addClass('active');
            });
            window.scrollTo({top: 0, behavior: 'smooth'});
        }

        // ─── STEP 1: Version Selection ───
        $('#target-version').on('change', function () {
            var val = $(this).val();
            if (val) {
                var php = $(this).find(':selected').data('php') || '';
                $('#version-target').text(val);
                $('#version-php').text(php);
                $('#version-info').slideDown(200);
                $('#btn-step1-next').prop('disabled', false);
            } else {
                $('#version-info').slideUp(200);
                $('#btn-step1-next').prop('disabled', true);
            }
        });

        $('#btn-step1-next').on('click', function () {
            goToStep(2);
            runSystemCheck();
        });

        // ─── STEP 2: System Check ───
        function runSystemCheck() {
            var targetVersion = $('#target-version').val();
            $('#syscheck-badge').text('Checking...').attr('class', 'status-badge status-badge--running');
            $('#syscheck-spinner').show();
            $('#syscheck-results').hide();
            $('#btn-step2-next').prop('disabled', true);

            $.ajax({
                url: systemCheckUrl,
                type: 'POST',
                dataType: 'json',
                data: {form_key: formKey, target_version: targetVersion},
                success: function (resp) {
                    $('#syscheck-spinner').hide();
                    $('#syscheck-results').show();

                    if (resp.success) {
                        renderSystemChecks(resp.checks, resp.compatible);
                    } else {
                        $('#syscheck-badge').text('Failed').attr('class', 'status-badge status-badge--failed');
                        $('#syscheck-summary').html(
                            '<div class="readiness-item readiness-item--blocked">' +
                            '<span class="readiness-dot readiness-dot--red"></span> ' +
                            'System check failed: ' + (resp.message || 'Unknown error') +
                            '</div>'
                        );
                    }
                },
                error: function () {
                    $('#syscheck-spinner').hide();
                    $('#syscheck-results').show();
                    $('#syscheck-badge').text('Failed').attr('class', 'status-badge status-badge--failed');
                    $('#syscheck-summary').html(
                        '<div class="readiness-item readiness-item--blocked">' +
                        '<span class="readiness-dot readiness-dot--red"></span> ' +
                        'System check request failed. Check server logs.' +
                        '</div>'
                    );
                }
            });
        }

        function renderSystemChecks(checks, compatible) {
            var tableHtml = '';
            var failedCritical = 0;
            var failedNonCritical = 0;

            checks.forEach(function (check) {
                var statusHtml;
                if (check.passed) {
                    statusHtml = '<span style="color:#16a34a;font-weight:600">Pass</span>';
                } else if (check.critical) {
                    statusHtml = '<span style="color:#dc2626;font-weight:600">FAIL</span>';
                    failedCritical++;
                } else {
                    statusHtml = '<span style="color:#d97706;font-weight:600">Warning</span>';
                    failedNonCritical++;
                }

                tableHtml += '<tr>';
                tableHtml += '<td>' + check.requirement + '</td>';
                tableHtml += '<td>' + check.current + '</td>';
                tableHtml += '<td>' + check.required + '</td>';
                tableHtml += '<td>' + statusHtml + '</td>';
                tableHtml += '</tr>';
            });

            $('#syscheck-table-body').html(tableHtml);

            var summaryHtml = '';
            if (compatible) {
                $('#syscheck-badge').text('Compatible').attr('class', 'status-badge status-badge--completed');
                summaryHtml += '<div class="readiness-item readiness-item--ok">';
                summaryHtml += '<span class="readiness-dot readiness-dot--green"></span> ';
                summaryHtml += 'All system checks passed. Your server is compatible with the selected version.';
                summaryHtml += '</div>';
                $('#btn-step2-next').prop('disabled', false);
            } else {
                $('#syscheck-badge').text('Incompatible').attr('class', 'status-badge status-badge--failed');
                if (failedCritical > 0) {
                    summaryHtml += '<div class="readiness-item readiness-item--blocked">';
                    summaryHtml += '<span class="readiness-dot readiness-dot--red"></span> ';
                    summaryHtml += failedCritical + ' critical requirement(s) not met. Please resolve before continuing.';
                    summaryHtml += '</div>';
                }
                if (failedNonCritical > 0) {
                    summaryHtml += '<div class="readiness-item readiness-item--warning">';
                    summaryHtml += '<span class="readiness-dot readiness-dot--yellow"></span> ';
                    summaryHtml += failedNonCritical + ' non-critical warning(s) detected.';
                    summaryHtml += '</div>';
                }
                $('#btn-step2-next').prop('disabled', true);
            }

            $('#syscheck-summary').html(summaryHtml);
        }

        $('#btn-step2-back').on('click', function () { goToStep(1); });
        $('#btn-step2-next').on('click', function () {
            goToStep(3);
            runScan();
        });

        // ─── STEP 3: Scanning ───
        function runScan() {
            var targetVersion = $('#target-version').val();
            $('#scan-badge').text('Scanning...').attr('class', 'status-badge status-badge--running');
            $('#scan-log').html('');
            addScanLog('Starting compatibility scan for ' + targetVersion + '...');

            $.ajax({
                url: scanUrl,
                type: 'POST',
                dataType: 'json',
                data: {form_key: formKey, target_version: targetVersion},
                success: function (resp) {
                    if (resp.success) {
                        scanData = resp.data;
                        addScanLog('Scan complete. Found ' + scanData.total_issues + ' issues.');
                        addScanLog(scanData.auto_fixable + ' issues can be auto-fixed.');
                        addScanLog(scanData.critical_issues + ' critical issues found.');
                        addScanLog('Checked ' + (scanData.extensions || []).length + ' extensions.');
                        $('#scan-badge').text('Complete').attr('class', 'status-badge status-badge--completed');
                        $('.scan-spinner').hide();
                        $('#scan-status-text').text('Scan complete! Moving to review...');
                        setTimeout(function () {
                            renderReview();
                            goToStep(4);
                        }, 1500);
                    } else {
                        $('#scan-badge').text('Failed').attr('class', 'status-badge status-badge--failed');
                        addScanLog('ERROR: ' + resp.message);
                        $('.scan-spinner').hide();
                    }
                },
                error: function () {
                    $('#scan-badge').text('Failed').attr('class', 'status-badge status-badge--failed');
                    addScanLog('ERROR: Request failed. Check server logs.');
                    $('.scan-spinner').hide();
                }
            });
        }

        function addScanLog(msg) {
            $('#scan-log').append('<div class="scan-log-entry">&gt; ' + msg + '</div>');
        }

        // ─── STEP 4: Review ───
        function renderReview() {
            if (!scanData) return;

            var issues = scanData.issues || [];
            var unfixedCritical = 0;
            var unfixedErrors = 0;
            var autoFixableCount = 0;

            issues.forEach(function (i) {
                if (i.is_auto_fixable && !i.is_fixed) autoFixableCount++;
                if (!i.is_auto_fixable && !i.is_fixed) {
                    if (i.severity === 'critical') unfixedCritical++;
                    if (i.severity === 'error') unfixedErrors++;
                }
            });

            // Stats
            var statsHtml = '';
            var cards = [
                {n: scanData.critical_issues || 0, l: 'Critical', c: 'danger'},
                {n: scanData.warnings || 0, l: 'Warnings', c: 'warning'},
                {n: autoFixableCount, l: 'Auto-Fixable', c: 'success'},
                {n: scanData.total_issues || 0, l: 'Total Issues', c: 'info'}
            ];
            cards.forEach(function (c) {
                statsHtml += '<div class="autoupgrader-card autoupgrader-card--' + c.c + '">';
                statsHtml += '<div class="autoupgrader-card__content">';
                statsHtml += '<div class="autoupgrader-card__number">' + c.n + '</div>';
                statsHtml += '<div class="autoupgrader-card__label">' + c.l + '</div>';
                statsHtml += '</div></div>';
            });
            $('#review-stats').html(statsHtml);

            // Auto-fix button
            if (autoFixableCount > 0) {
                $('#review-autofix-area').show();
                $('#autofix-count').text(autoFixableCount);
            } else {
                $('#review-autofix-area').hide();
            }

            // Files
            var files = scanData.impacted_files || [];
            var filesHtml = '';
            if (files.length === 0) {
                filesHtml = '<p class="no-issues">No impacted files found - your code looks compatible!</p>';
            } else {
                files.forEach(function (f) {
                    filesHtml += '<div class="file-list__item">' + f + '</div>';
                });
            }
            $('#review-files').html(filesHtml);

            // Issues
            var issuesHtml = '';
            if (issues.length === 0) {
                issuesHtml = '<p class="no-issues">No issues detected.</p>';
            } else {
                issuesHtml = '<table class="autoupgrader-table"><thead><tr>';
                issuesHtml += '<th>Severity</th><th>File</th><th>Description</th><th>Status</th>';
                issuesHtml += '</tr></thead><tbody>';
                issues.forEach(function (i) {
                    var color = i.severity === 'critical' ? '#dc2626' : i.severity === 'error' ? '#d97706' : i.severity === 'warning' ? '#d97706' : '#64748b';
                    var statusLabel = '';
                    if (i.is_fixed) {
                        statusLabel = '<span class="fix-done">Fixed</span>';
                    } else if (i.is_auto_fixable) {
                        statusLabel = '<span class="fix-yes">Auto-Fixable</span>';
                    } else {
                        statusLabel = '<span class="fix-manual">Manual</span>';
                    }
                    issuesHtml += '<tr>';
                    issuesHtml += '<td><span style="color:' + color + ';font-weight:600">' + i.severity + '</span></td>';
                    issuesHtml += '<td class="mono">' + (i.file_path || '').split('/').pop() + ':' + (i.line_number || '') + '</td>';
                    issuesHtml += '<td>' + (i.description || '') + '<br><small>' + (i.suggestion || '') + '</small></td>';
                    issuesHtml += '<td>' + statusLabel + '</td>';
                    issuesHtml += '</tr>';
                });
                issuesHtml += '</tbody></table>';
            }
            $('#review-issues').html(issuesHtml);

            // Extensions
            var exts = scanData.extensions || [];
            var extHtml = '';
            var hasBlockingExtensions = false;
            if (exts.length === 0) {
                extHtml = '<tr><td colspan="4">No third-party extensions found.</td></tr>';
            } else {
                exts.forEach(function (e) {
                    var statusText = (e.status || '').replace(/_/g, ' ');
                    var sc = 'color:#d97706';
                    if (e.status === 'compatible') sc = 'color:#16a34a';
                    else if (e.status === 'no_compatible_version') { sc = 'color:#dc2626'; hasBlockingExtensions = true; }
                    else if (e.status === 'manual_check' || e.status === 'check_failed') sc = 'color:#d97706';
                    else if (e.status === 'not_found') { sc = 'color:#d97706'; statusText = 'will check during upgrade'; }

                    extHtml += '<tr>';
                    extHtml += '<td>' + e.package_name + '</td>';
                    extHtml += '<td>' + (e.current_version || '') + '</td>';
                    extHtml += '<td>' + (e.compatible_version || 'N/A') + '</td>';
                    extHtml += '<td><span style="' + sc + ';font-weight:600">' + statusText + '</span></td>';
                    extHtml += '</tr>';
                });
            }
            $('#review-extensions').html(extHtml);

            // Readiness check - gate the upgrade
            updateReadiness(unfixedCritical, unfixedErrors, hasBlockingExtensions);
        }

        function updateReadiness(unfixedCritical, unfixedErrors, hasBlockingExtensions) {
            var canProceed = true;
            var readinessHtml = '';

            if (unfixedCritical > 0) {
                canProceed = false;
                readinessHtml += '<div class="readiness-item readiness-item--blocked">';
                readinessHtml += '<span class="readiness-dot readiness-dot--red"></span> ';
                readinessHtml += unfixedCritical + ' critical issue(s) must be resolved before upgrading';
                readinessHtml += '</div>';
            } else {
                readinessHtml += '<div class="readiness-item readiness-item--ok">';
                readinessHtml += '<span class="readiness-dot readiness-dot--green"></span> No critical issues';
                readinessHtml += '</div>';
            }

            if (unfixedErrors > 0) {
                readinessHtml += '<div class="readiness-item readiness-item--warning">';
                readinessHtml += '<span class="readiness-dot readiness-dot--yellow"></span> ';
                readinessHtml += unfixedErrors + ' error(s) detected - auto-fix recommended';
                readinessHtml += '</div>';
            } else {
                readinessHtml += '<div class="readiness-item readiness-item--ok">';
                readinessHtml += '<span class="readiness-dot readiness-dot--green"></span> No errors';
                readinessHtml += '</div>';
            }

            if (hasBlockingExtensions) {
                readinessHtml += '<div class="readiness-item readiness-item--warning">';
                readinessHtml += '<span class="readiness-dot readiness-dot--yellow"></span> ';
                readinessHtml += 'Some extensions may need manual updates';
                readinessHtml += '</div>';
            } else {
                readinessHtml += '<div class="readiness-item readiness-item--ok">';
                readinessHtml += '<span class="readiness-dot readiness-dot--green"></span> Extensions OK';
                readinessHtml += '</div>';
            }

            $('#review-readiness').html(readinessHtml);
            $('#btn-step4-next').prop('disabled', !canProceed);
            if (!canProceed) {
                $('#btn-step4-next').text('Fix Critical Issues to Continue');
            } else {
                $('#btn-step4-next').html('Next: Confirm Upgrade &rarr;');
            }
        }

        // Auto-fix button handler
        $('#btn-autofix').on('click', function () {
            var btn = $(this);
            btn.prop('disabled', true).text('Applying fixes...');
            $.ajax({
                url: fixUrl,
                type: 'POST',
                dataType: 'json',
                data: {form_key: formKey, scan_id: scanData.scan_id},
                success: function (resp) {
                    if (resp.success && resp.data) {
                        var fixResult = resp.data;
                        btn.text(fixResult.fixed_count + ' fixed, ' + fixResult.failed_count + ' failed');
                        btn.addClass('autoupgrader-btn--success').removeClass('autoupgrader-btn--primary');

                        // Update issue statuses in scanData
                        if (fixResult.details) {
                            fixResult.details.forEach(function (d) {
                                (scanData.issues || []).forEach(function (issue) {
                                    if (issue.file_path === d.file && d.status === 'fixed') {
                                        issue.is_fixed = true;
                                    }
                                });
                            });
                        }

                        // Mark auto-fixable issues as fixed
                        (scanData.issues || []).forEach(function (issue) {
                            if (issue.is_auto_fixable) {
                                issue.is_fixed = true;
                            }
                        });

                        // Re-render review
                        renderReview();
                    } else {
                        btn.text('Fix Failed - ' + (resp.message || 'Unknown error'));
                        btn.prop('disabled', false);
                    }
                },
                error: function () {
                    btn.text('Fix Failed - Server Error');
                    btn.prop('disabled', false);
                }
            });
        });

        $('#btn-step4-back').on('click', function () { goToStep(1); });
        $('#btn-step4-next').on('click', function () {
            if ($(this).prop('disabled')) return;
            var targetVersion = $('#target-version').val();
            $('#confirm-summary').html(
                '<div class="confirm-detail"><strong>From:</strong> ' + currentVersion + '</div>' +
                '<div class="confirm-detail"><strong>To:</strong> ' + targetVersion + '</div>' +
                '<div class="confirm-detail"><strong>Issues to auto-fix:</strong> ' + (scanData ? scanData.auto_fixable : 0) + '</div>' +
                '<div class="confirm-detail"><strong>Extensions to upgrade:</strong> ' +
                    ((scanData && scanData.extensions) ? scanData.extensions.filter(function(e){return e.status==='compatible'}).length : 0) + '</div>'
            );
            goToStep(5);
        });

        // ─── STEP 5: Confirm ───
        $('#btn-step5-back').on('click', function () { goToStep(4); });
        $('#btn-step5-start').on('click', function () {
            $(this).prop('disabled', true).text('Starting...');
            goToStep(6);
            executeUpgrade();
        });

        // ─── STEP 6: Execute ───
        function executeUpgrade() {
            var targetVersion = $('#target-version').val();
            var scanId = scanData ? scanData.scan_id : null;

            // Initialize timeline
            var steps = [
                {key: 'backup', label: 'Creating Backup'},
                {key: 'auto_fix', label: 'Auto-Fixing Issues'},
                {key: 'extensions', label: 'Upgrading Extensions'},
                {key: 'composer', label: 'Composer Update'},
                {key: 'setup', label: 'Setup Upgrade'},
                {key: 'compile', label: 'DI Compilation'},
                {key: 'static', label: 'Static Content Deploy'},
                {key: 'cache', label: 'Cache Flush'},
                {key: 'verify', label: 'Verification'}
            ];
            var timelineHtml = '';
            steps.forEach(function (s) {
                timelineHtml += '<div class="timeline-step timeline-step--pending" id="ts-' + s.key + '">';
                timelineHtml += '<div class="timeline-step__dot"></div>';
                timelineHtml += '<div class="timeline-step__label">' + s.label + '</div>';
                timelineHtml += '<div class="timeline-step__message"></div>';
                timelineHtml += '</div>';
            });
            $('#progress-timeline').html(timelineHtml);

            // Phase 1: Create upgrade log to get upgrade_id
            $.ajax({
                url: prepareUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    form_key: formKey,
                    target_version: targetVersion,
                    scan_id: scanId
                },
                success: function (prepResp) {
                    if (!prepResp.success) {
                        showUpgradeError(prepResp.message || 'Failed to prepare upgrade');
                        return;
                    }

                    upgradeId = prepResp.upgrade_id;
                    statusToken = prepResp.status_token;
                    startProgressPolling();

                    // Phase 2: Fire the long-running execute with the upgrade_id
                    $.ajax({
                        url: executeUrl,
                        type: 'POST',
                        dataType: 'json',
                        data: {
                            form_key: formKey,
                            target_version: targetVersion,
                            scan_id: scanId,
                            upgrade_id: upgradeId
                        },
                        timeout: 0,
                        success: function (resp) {
                            stopProgressPolling();
                            if (resp.success) {
                                fetchFinalProgress();
                            } else {
                                showUpgradeError(resp.message || 'Upgrade failed');
                            }
                        },
                        error: function () {
                            stopProgressPolling();
                            if (upgradeId) {
                                fetchFinalProgress();
                            } else {
                                showUpgradeError('Connection lost. Check server logs.');
                            }
                        }
                    });
                },
                error: function () {
                    showUpgradeError('Failed to prepare upgrade. Check server logs.');
                }
            });
        }

        function startProgressPolling() {
            progressTimer = setInterval(function () {
                if (!statusToken) return;
                $.ajax({
                    url: statusUrl + '?token=' + encodeURIComponent(statusToken),
                    type: 'GET',
                    dataType: 'json',
                    success: function (resp) {
                        if (resp.success && resp.data) {
                            updateProgressUI(resp.data);
                        }
                    }
                });
            }, 2000);
        }

        function stopProgressPolling() {
            if (progressTimer) {
                clearInterval(progressTimer);
                progressTimer = null;
            }
        }

        function updateProgressUI(data) {
            var pct = data.progress_percent || 0;
            $('#progress-fill').css('width', pct + '%');
            $('#progress-percent').text(pct + '%');
            $('#progress-step').text(data.current_step || '');

            var steps = data.steps || [];
            steps.forEach(function (s) {
                var el = $('#ts-' + s.key);
                if (el.length) {
                    el.attr('class', 'timeline-step timeline-step--' + s.status);
                    el.find('.timeline-step__message').text(s.message || '');
                }
            });

            if (data.status === 'completed') {
                stopProgressPolling();
                $('#upgrade-badge').text('Complete').attr('class', 'status-badge status-badge--completed');
                $('#progress-fill').css('background', 'linear-gradient(90deg, #16a34a, #22c55e)');
                setTimeout(function () { showCompletion(true, data); }, 1000);
            } else if (data.status === 'failed') {
                stopProgressPolling();
                showUpgradeError(data.error_message || 'Upgrade failed');
            }
        }

        function fetchFinalProgress() {
            if (statusToken) {
                $.ajax({
                    url: statusUrl + '?token=' + encodeURIComponent(statusToken),
                    type: 'GET',
                    dataType: 'json',
                    success: function (resp) {
                        if (resp.success && resp.data) {
                            updateProgressUI(resp.data);
                        }
                    }
                });
            }
        }

        function showUpgradeError(msg) {
            $('#upgrade-badge').text('Failed').attr('class', 'status-badge status-badge--failed');
            $('#progress-error').show().html(
                '<strong>Error:</strong> ' + msg +
                '<div class="wizard-nav" style="margin-top:16px">' +
                '<button class="autoupgrader-btn autoupgrader-btn--warning" onclick="document.getElementById(\'btn-rollback\').click()">Rollback to Previous Version</button>' +
                '</div>'
            );
        }

        function showCompletion(success, data) {
            goToStep(7);
            var html = '';
            if (success) {
                html += '<div class="completion-success">';
                html += '<div class="completion-icon">&#10003;</div>';
                html += '<h2>Upgrade Successful!</h2>';
                html += '<p>Your Magento installation has been upgraded from <strong>' + currentVersion + '</strong> to <strong>' + $('#target-version').val() + '</strong></p>';
                html += '<div class="completion-details">';
                html += '<p><strong>Backup location:</strong> ' + (data.backup_path || 'See var/autoupgrader_backups/') + '</p>';
                html += '</div>';
                html += '<div class="wizard-nav"><a href="' + window.location.href + '" class="autoupgrader-btn autoupgrader-btn--primary">Back to Dashboard</a></div>';
                html += '</div>';
            } else {
                html += '<div class="completion-failed">';
                html += '<div class="completion-icon completion-icon--failed">&#10007;</div>';
                html += '<h2>Upgrade Failed</h2>';
                html += '<p>The upgrade could not be completed. Your backup is available for rollback.</p>';
                html += '</div>';
            }
            $('#completion-body').html(html);
        }
    };
});
