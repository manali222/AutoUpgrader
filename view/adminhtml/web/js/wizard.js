define(['jquery'], function ($) {
    'use strict';

    return function (config) {
        var scanUrl = config.scanUrl,
            executeUrl = config.executeUrl,
            progressUrl = config.progressUrl,
            rollbackUrl = config.rollbackUrl,
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
            runScan();
        });

        // ─── STEP 2: Scanning ───
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
                            goToStep(3);
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

        // ─── STEP 3: Review ───
        function renderReview() {
            if (!scanData) return;

            // Stats
            var statsHtml = '';
            var cards = [
                {n: scanData.critical_issues || 0, l: 'Critical', c: 'danger'},
                {n: scanData.warnings || 0, l: 'Warnings', c: 'warning'},
                {n: scanData.auto_fixable || 0, l: 'Auto-Fixable', c: 'success'},
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
            var issues = scanData.issues || [];
            var issuesHtml = '';
            if (issues.length === 0) {
                issuesHtml = '<p class="no-issues">No issues detected.</p>';
            } else {
                issuesHtml = '<table class="autoupgrader-table"><thead><tr>';
                issuesHtml += '<th>Severity</th><th>File</th><th>Description</th><th>Auto-Fix</th>';
                issuesHtml += '</tr></thead><tbody>';
                issues.forEach(function (i) {
                    var color = i.severity === 'critical' ? '#dc2626' : i.severity === 'error' ? '#d97706' : '#64748b';
                    issuesHtml += '<tr>';
                    issuesHtml += '<td><span style="color:' + color + ';font-weight:600">' + i.severity + '</span></td>';
                    issuesHtml += '<td class="mono">' + (i.file_path || '').split('/').pop() + ':' + (i.line_number || '') + '</td>';
                    issuesHtml += '<td>' + (i.description || '') + '<br><small>' + (i.suggestion || '') + '</small></td>';
                    issuesHtml += '<td>' + (i.is_auto_fixable ? '<span class="fix-yes">Auto</span>' : 'Manual') + '</td>';
                    issuesHtml += '</tr>';
                });
                issuesHtml += '</tbody></table>';
            }
            $('#review-issues').html(issuesHtml);

            // Extensions
            var exts = scanData.extensions || [];
            var extHtml = '';
            if (exts.length === 0) {
                extHtml = '<tr><td colspan="4">No third-party extensions found.</td></tr>';
            } else {
                exts.forEach(function (e) {
                    var sc = e.status === 'compatible' ? 'color:#16a34a' : e.status === 'no_compatible_version' ? 'color:#dc2626' : 'color:#d97706';
                    extHtml += '<tr>';
                    extHtml += '<td>' + e.package_name + '</td>';
                    extHtml += '<td>' + (e.current_version || '') + '</td>';
                    extHtml += '<td>' + (e.compatible_version || 'N/A') + '</td>';
                    extHtml += '<td><span style="' + sc + ';font-weight:600">' + (e.status || '').replace(/_/g, ' ') + '</span></td>';
                    extHtml += '</tr>';
                });
            }
            $('#review-extensions').html(extHtml);
        }

        $('#btn-step3-back').on('click', function () { goToStep(1); });
        $('#btn-step3-next').on('click', function () {
            var targetVersion = $('#target-version').val();
            $('#confirm-summary').html(
                '<div class="confirm-detail"><strong>From:</strong> ' + currentVersion + '</div>' +
                '<div class="confirm-detail"><strong>To:</strong> ' + targetVersion + '</div>' +
                '<div class="confirm-detail"><strong>Issues to auto-fix:</strong> ' + (scanData ? scanData.auto_fixable : 0) + '</div>' +
                '<div class="confirm-detail"><strong>Extensions to upgrade:</strong> ' +
                    ((scanData && scanData.extensions) ? scanData.extensions.filter(function(e){return e.status==='compatible'}).length : 0) + '</div>'
            );
            goToStep(4);
        });

        // ─── STEP 4: Confirm ───
        $('#btn-step4-back').on('click', function () { goToStep(3); });
        $('#btn-step4-start').on('click', function () {
            $(this).prop('disabled', true).text('Starting...');
            goToStep(5);
            executeUpgrade();
        });

        // ─── STEP 5: Execute ───
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

            $.ajax({
                url: executeUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    form_key: formKey,
                    target_version: targetVersion,
                    scan_id: scanId
                },
                timeout: 0,
                beforeSend: function () {
                    startProgressPolling();
                },
                success: function (resp) {
                    stopProgressPolling();
                    if (resp.success) {
                        upgradeId = resp.upgrade_id;
                        fetchFinalProgress();
                    } else {
                        showUpgradeError(resp.message || 'Upgrade failed');
                    }
                },
                error: function (xhr) {
                    stopProgressPolling();
                    // Check if upgrade actually succeeded despite timeout
                    if (upgradeId) {
                        fetchFinalProgress();
                    } else {
                        showUpgradeError('Connection lost. Check server logs.');
                    }
                }
            });
        }

        function startProgressPolling() {
            progressTimer = setInterval(function () {
                if (!upgradeId) return;
                $.ajax({
                    url: progressUrl,
                    type: 'GET',
                    dataType: 'json',
                    data: {upgrade_id: upgradeId},
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
            $.ajax({
                url: progressUrl,
                type: 'GET',
                dataType: 'json',
                data: {upgrade_id: upgradeId},
                success: function (resp) {
                    if (resp.success && resp.data) {
                        updateProgressUI(resp.data);
                    }
                }
            });
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
            goToStep(6);
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
