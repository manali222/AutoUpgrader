define([
    'jquery',
    'Magento_Ui/js/modal/alert'
], function ($, alert) {
    'use strict';

    return function (config, element) {
        var scanUrl = config.scanUrl;
        var formKey = config.formKey;

        $('#btn-run-scan').on('click', function () {
            var targetVersion = $('#scan-target-version').val();
            if (!targetVersion) {
                alert({ content: 'Please select a target version.' });
                return;
            }

            var btn = $(this);
            btn.prop('disabled', true).text('Scanning... (this may take a moment)');

            $.ajax({
                url: scanUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    form_key: formKey,
                    target_version: targetVersion
                },
                success: function (response) {
                    btn.prop('disabled', false).text('Run Full Scan');

                    if (response.success) {
                        renderScanResults(response.data);
                        $('#scan-detail-results').show();
                    } else {
                        alert({ content: 'Scan failed: ' + response.message });
                    }
                },
                error: function () {
                    btn.prop('disabled', false).text('Run Full Scan');
                    alert({ content: 'Scan request failed. Check server logs.' });
                }
            });
        });

        function renderScanResults(data) {
            // Stats cards
            var statsHtml = '';
            statsHtml += '<div class="autoupgrader-card autoupgrader-card--danger">';
            statsHtml += '  <div class="autoupgrader-card__content"><div class="autoupgrader-card__number">' + (data.critical_issues || 0) + '</div>';
            statsHtml += '  <div class="autoupgrader-card__label">Critical Issues</div></div></div>';
            statsHtml += '<div class="autoupgrader-card autoupgrader-card--warning">';
            statsHtml += '  <div class="autoupgrader-card__content"><div class="autoupgrader-card__number">' + (data.warnings || 0) + '</div>';
            statsHtml += '  <div class="autoupgrader-card__label">Warnings</div></div></div>';
            statsHtml += '<div class="autoupgrader-card autoupgrader-card--success">';
            statsHtml += '  <div class="autoupgrader-card__content"><div class="autoupgrader-card__number">' + (data.auto_fixable || 0) + '</div>';
            statsHtml += '  <div class="autoupgrader-card__label">Auto-Fixable</div></div></div>';
            statsHtml += '<div class="autoupgrader-card autoupgrader-card--info">';
            statsHtml += '  <div class="autoupgrader-card__content"><div class="autoupgrader-card__number">' + (data.total_issues || 0) + '</div>';
            statsHtml += '  <div class="autoupgrader-card__label">Total Issues</div></div></div>';
            $('#scan-stats').html(statsHtml);

            // Impacted files
            var impactedFiles = data.impacted_files || [];
            var filesHtml = '';
            if (impactedFiles.length === 0) {
                filesHtml = '<p style="color: #16a34a; font-weight: 500;">No impacted files found - your code looks compatible!</p>';
            } else {
                impactedFiles.forEach(function (file) {
                    filesHtml += '<div class="file-list__item">' + file + '</div>';
                });
            }
            $('#impacted-files-list').html(filesHtml);

            // Issues by category
            var issues = data.issues || [];
            var categories = {};
            issues.forEach(function (issue) {
                var cat = issue.category || 'other';
                if (!categories[cat]) categories[cat] = [];
                categories[cat].push(issue);
            });

            var catHtml = '';
            Object.keys(categories).forEach(function (cat) {
                catHtml += '<div class="scan-section">';
                catHtml += '<h4>' + cat.replace(/_/g, ' ').toUpperCase() + ' (' + categories[cat].length + ')</h4>';
                catHtml += '<table class="autoupgrader-table"><thead><tr>';
                catHtml += '<th>Severity</th><th>File</th><th>Line</th><th>Description</th><th>Suggestion</th><th>Auto-Fix</th>';
                catHtml += '</tr></thead><tbody>';
                categories[cat].forEach(function (issue) {
                    var severityColor = issue.severity === 'critical' ? '#dc2626' : issue.severity === 'error' ? '#d97706' : '#64748b';
                    catHtml += '<tr>';
                    catHtml += '<td><span style="color:' + severityColor + '; font-weight:600;">' + issue.severity + '</span></td>';
                    catHtml += '<td style="font-family:monospace; font-size:11px;">' + (issue.file_path || '').split('/').pop() + '</td>';
                    catHtml += '<td>' + (issue.line_number || '-') + '</td>';
                    catHtml += '<td>' + (issue.description || '') + '</td>';
                    catHtml += '<td style="font-size:12px;">' + (issue.suggestion || '-') + '</td>';
                    catHtml += '<td>' + (issue.is_auto_fixable ? '<span style="color:#16a34a;">Yes</span>' : 'No') + '</td>';
                    catHtml += '</tr>';
                });
                catHtml += '</tbody></table></div>';
            });
            $('#issues-by-category').html(catHtml);

            // Extension compatibility
            var extensions = data.extensions || [];
            var extHtml = '';
            extensions.forEach(function (ext) {
                var statusClass = ext.status === 'compatible' ? 'color:#16a34a' : ext.status === 'no_compatible_version' ? 'color:#dc2626' : 'color:#d97706';
                extHtml += '<tr>';
                extHtml += '<td>' + (ext.package_name || '') + '</td>';
                extHtml += '<td>' + (ext.current_version || '') + '</td>';
                extHtml += '<td>' + (ext.compatible_version || 'N/A') + '</td>';
                extHtml += '<td><span style="' + statusClass + '; font-weight:600;">' + (ext.status || '').replace(/_/g, ' ') + '</span></td>';
                extHtml += '<td>' + (ext.action || '') + '</td>';
                extHtml += '</tr>';
            });
            $('#extension-compat-body').html(extHtml);

            // Show auto-fix button if there are fixable issues
            if ((data.auto_fixable || 0) > 0) {
                $('#btn-auto-fix').show().text('Auto-Fix ' + data.auto_fixable + ' Issues');
            }
        }

        // Auto-fix button
        $('#btn-auto-fix').on('click', function () {
            var btn = $(this);
            btn.prop('disabled', true).text('Applying fixes...');

            $.ajax({
                url: scanUrl.replace('scan/run', 'scan/fix'),
                type: 'POST',
                dataType: 'json',
                data: { form_key: formKey },
                success: function (response) {
                    btn.prop('disabled', false);
                    if (response.success) {
                        alert({
                            content: 'Fixed ' + response.data.fixed_count + ' issues. ' +
                                     response.data.failed_count + ' could not be fixed automatically.'
                        });
                    }
                }
            });
        });
    };
});
