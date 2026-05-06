/**
 * Disciple.Tools Migration - Import flow (AJAX, progress, confirmation)
 */
( function( $ ) {
    'use strict';

    const strings = ( typeof dtMigrationImport !== 'undefined' && dtMigrationImport.strings ) ? dtMigrationImport.strings : {};

    function t( key, fallback ) {
        return strings[ key ] || fallback;
    }

    let $modal, $modalTitle, $progress, $confirmInput, $confirmBtn, $cancelBtn, $summary;
    let $confirmGate, $modalWarning;
    let $progressBar, $progressText, $stepList, $currentPhase, $cancelImport, $importSpinner;
    let $errorDetails, $errorScroll;
    let $pfModal, $pfInfoWrap, $pfInfoText, $pfWarningsWrap, $pfWarningsText, $pfStatus, $pfProceed, $pfClose;
    let pendingPreflightSection = null;

    let cancelled = false;
    let phases = [];
    let currentPhaseIndex = 0;
    let totalSteps = 0;
    let completedSteps = 0;
    let activeImportChannel = 'api';
    let isSlimConfirmMode = false;
    /** File job UUID for the current or last-started file import (from data-file-job-id on the file section). */
    let fileJobIdForRun = '';
    /** First records batch of this run sends init_records_import (clears stale deferred queue if settings step was skipped). */
    let sentRecordsImportInit = false;
    /** Per-record or connection-pass issues logged while import continues. */
    let hadNonFatalImportIssues = false;

    const CONFIRM_WORD = 'IMPORT';

    function escapeHtml( s ) {
        const div = document.createElement( 'div' );
        div.textContent = s == null ? '' : String( s );
        return div.innerHTML;
    }

    /**
     * One logical row per array item. Preserves leading spaces (e.g. indented field lines).
     * Replaces embedded newlines only so each server line stays a single textarea row.
     */
    function preflightLinesToTextareaValue( lines ) {
        if ( ! Array.isArray( lines ) || ! lines.length ) {
            return '';
        }
        return lines.map( function( line ) {
            return String( line == null ? '' : line ).replace( /\r?\n/g, ' ' );
        } ).join( '\n' );
    }

    function getSelectedSettings( $scope ) {
        const root = $scope && $scope.length ? $scope : $( document );
        const out = [];
        root.find( '.dt-migration-setting-checkbox:checked:not(:disabled)' ).each( function() {
            out.push( $( this ).val() );
        } );
        return out;
    }

    function getSelectedRecords( $scope ) {
        const root = $scope && $scope.length ? $scope : $( document );
        const out = {};
        root.find( '.dt-migration-record-checkbox:checked' ).each( function() {
            const pt = $( this ).data( 'post-type' );
            const count = $( this ).data( 'record-count' ) || 0;
            out[ pt ] = { count };
        } );
        return out;
    }

    function beginImportFlow( $section ) {
        const fromBtn = $section.find( '.dt-migration-start-import' ).first().data( 'importChannel' );
        activeImportChannel = fromBtn === 'file' ? 'file' : 'api';
        if ( activeImportChannel === 'file' ) {
            const jid = String( $section.attr( 'data-file-job-id' ) || '' ).trim();
            if ( ! jid ) {
                window.alert( 'No file migration job is active. Use Upload & Preview or Retry from the job list first.' );
                return;
            }
            fileJobIdForRun = jid;
        } else {
            fileJobIdForRun = '';
        }

        const settings = getSelectedSettings( $section );
        const records = getSelectedRecords( $section );
        if ( ! settings.length && ! Object.keys( records ).length ) {
            window.alert( 'Please select at least one setting type or record type to import.' );
            return;
        }
        phases = buildPhases( $section );
        if ( ! phases.length ) {
            return;
        }
        totalSteps = phases.length;
        currentPhaseIndex = 0;
        completedSteps = 0;
        sentRecordsImportInit = false;
        startNextPhase();
    }

    function appendFileJobId( data ) {
        if ( activeImportChannel === 'file' && fileJobIdForRun ) {
            data.file_job_id = fileJobIdForRun;
        }
        return data;
    }

    function notifyFileJobCompleteIfNeeded() {
        if ( activeImportChannel === 'file' && fileJobIdForRun && typeof dtMigrationImport !== 'undefined' ) {
            $.post( dtMigrationImport.ajaxUrl, {
                action: 'dt_migration_file_job_complete',
                nonce: dtMigrationImport.nonce,
                file_job_id: fileJobIdForRun
            } );
        }
    }

    function notifyFileJobFailedIfNeeded() {
        if ( activeImportChannel === 'file' && fileJobIdForRun && typeof dtMigrationImport !== 'undefined' ) {
            $.post( dtMigrationImport.ajaxUrl, {
                action: 'dt_migration_file_job_failed',
                nonce: dtMigrationImport.nonce,
                file_job_id: fileJobIdForRun
            } );
        }
    }

    function notifyFileJobCancelledIfNeeded() {
        if ( activeImportChannel === 'file' && fileJobIdForRun && typeof dtMigrationImport !== 'undefined' ) {
            $.post( dtMigrationImport.ajaxUrl, {
                action: 'dt_migration_file_job_cancelled',
                nonce: dtMigrationImport.nonce,
                file_job_id: fileJobIdForRun
            } );
        }
    }

    function runPreflightRequest( $section ) {
        const fromBtn = $section.find( '.dt-migration-run-preflight' ).first().data( 'importChannel' );
        const channel = fromBtn === 'file' ? 'file' : 'api';
        const settings = getSelectedSettings( $section );
        const records = getSelectedRecords( $section );
        const recordPts = Object.keys( records );

        if ( ! settings.length && ! recordPts.length ) {
            window.alert( 'Please select at least one setting type or record type.' );
            return;
        }

        if ( ! $pfModal.length ) {
            window.alert( t( 'preflightFailed', 'Preflight is not available on this screen.' ) );
            return;
        }

        $pfStatus.text( t( 'preflightRunning', 'Running preflight…' ) ).prop( 'hidden', false );
        $pfInfoText.val( '' );
        $pfWarningsText.val( '' );
        $pfInfoWrap.prop( 'hidden', true );
        $pfWarningsWrap.prop( 'hidden', true );
        $pfModal.show();

        const payload = {
            action: 'dt_migration_preflight',
            nonce: dtMigrationImport.nonce,
            import_channel: channel,
            settings_selected: settings,
            records_selected: recordPts
        };
        if ( channel === 'file' ) {
            const $fileSec = $section && $section.length && $section.is( '.dt-migration-import-section[data-import-channel="file"]' ) ? $section : $section.closest( '.dt-migration-import-section[data-import-channel="file"]' );
            const fid = $fileSec && $fileSec.length ? String( $fileSec.attr( 'data-file-job-id' ) || '' ).trim() : '';
            if ( ! fid ) {
                window.alert( t( 'preflightFileJobMissing', 'No file migration job is active. Use Upload & Preview or Retry from the job list first.' ) );
                $pfStatus.prop( 'hidden', true );
                $pfModal.hide();
                return;
            }
            payload.file_job_id = fid;
        }

        $.post( dtMigrationImport.ajaxUrl, payload ).done( function( r ) {
            $pfStatus.prop( 'hidden', true );
            if ( ! r.success || ! r.data ) {
                const msg = r.data && r.data.message ? r.data.message : t( 'preflightFailed', 'Preflight request failed.' );
                window.alert( msg );
                $pfModal.hide();
                return;
            }
            const data = r.data;
            const warnings = data.warnings || [];
            const info = data.info || [];

            if ( info.length ) {
                $pfInfoText.val( preflightLinesToTextareaValue( info ) );
                $pfInfoWrap.prop( 'hidden', false );
            } else {
                $pfInfoText.val( '' );
                $pfInfoWrap.prop( 'hidden', true );
            }

            if ( warnings.length ) {
                $pfWarningsText.val( preflightLinesToTextareaValue( warnings ) );
            } else {
                $pfWarningsText.val( t( 'preflightNoIssues', 'No preflight warnings for the current selection and sample data.' ) );
            }
            $pfWarningsWrap.prop( 'hidden', false );

            pendingPreflightSection = $section;
        } ).fail( function() {
            $pfStatus.prop( 'hidden', true );
            window.alert( t( 'preflightFailed', 'Preflight request failed.' ) );
            $pfModal.hide();
        } );
    }

    function buildPhases( $section ) {
        const settings = getSelectedSettings( $section );
        const records = getSelectedRecords( $section );
        const phasesOut = [];

        if ( settings.length ) {
            phasesOut.push( {
                type: 'settings',
                label: 'Import settings (' + settings.join( ', ' ) + ')',
                settings: settings,
                records: null
            } );
        }
        const order = ( window.dtMigrationImport && Array.isArray( window.dtMigrationImport.recordImportOrder ) && window.dtMigrationImport.recordImportOrder.length )
            ? window.dtMigrationImport.recordImportOrder
            : [ 'peoplegroups', 'groups', 'contacts', 'trainings' ];
        const rest = Object.keys( records ).filter( pt => ! order.includes( pt ) );
        const ordered = order.filter( pt => records[ pt ] ).concat( rest );
        const recordPts = ordered.filter( pt => records[ pt ] );
        recordPts.forEach( ( pt, idx ) => {
            phasesOut.push( {
                type: 'records',
                post_type: pt,
                label: 'Import ' + pt + ' (' + ( records[ pt ].count || 0 ) + ' records)',
                records: records,
                isLastRecordTypePhase: idx === recordPts.length - 1
            } );
        } );
        return phasesOut;
    }

    function getPhaseConfirmMessage( phase ) {
        if ( phase.type === 'settings' ) {
            return 'This will overwrite the selected settings on this site.';
        }
        const source = activeImportChannel === 'file' ? 'the uploaded export file' : 'Server A';
        return 'This will delete existing ' + phase.post_type + ' and replace them with records from ' + source + '. Record IDs will be preserved for relationships.';
    }

    function buildSlimModalSummaryHtml( completedPhase, nextPhase ) {
        const completedL = t( 'completedLabel', 'Completed:' );
        const nextL = t( 'nextLabel', 'Next:' );
        return (
            '<p class="dt-migration-slim-completed"><strong>' + escapeHtml( completedL ) + '</strong> ' + escapeHtml( completedPhase.label ) + '</p>' +
            '<p class="dt-migration-slim-next"><strong>' + escapeHtml( nextL ) + '</strong> ' + escapeHtml( nextPhase.label ) + '</p>' +
            '<p class="dt-migration-slim-detail">' + escapeHtml( getPhaseConfirmMessage( nextPhase ) ) + '</p>'
        );
    }

    function showFullModal( phase ) {
        isSlimConfirmMode = false;
        $modal.removeClass( 'dt-migration-modal--slim' );
        $modalTitle.text( t( 'confirmImport', 'Confirm Import' ) );
        $modalWarning.show();
        $confirmGate.show();
        $summary.text( getPhaseConfirmMessage( phase ) );
        $confirmInput.val( '' ).prop( 'disabled', false );
        $confirmBtn.text( t( 'confirm', 'Confirm' ) ).prop( 'disabled', true );
        cancelled = false;
        $modal.show();
    }

    function showSlimModal( completedPhase, nextPhase ) {
        isSlimConfirmMode = true;
        $modal.addClass( 'dt-migration-modal--slim' );
        $modalTitle.text( t( 'continueImport', 'Continue import' ) );
        $modalWarning.hide();
        $confirmGate.hide();
        $summary.html( buildSlimModalSummaryHtml( completedPhase, nextPhase ) );
        $confirmBtn.text( t( 'continue', 'Continue' ) ).prop( 'disabled', false );
        cancelled = false;
        $modal.show();
    }

    function setProgress( percent ) {
        const n = ( typeof percent === 'number' && ! isNaN( percent ) ) ? Math.round( percent ) : 0;
        $progressBar.css( 'width', n + '%' );
        $progressText.text( n + '%' );
    }

    function setImportSpinner( on ) {
        if ( ! $importSpinner || ! $importSpinner.length ) {
            return;
        }
        $importSpinner.prop( 'hidden', ! on );
        $importSpinner.attr( 'aria-hidden', on ? 'false' : 'true' );
        $progress.attr( 'aria-busy', on ? 'true' : 'false' );
    }

    function addStep( label, status ) {
        const cls = status === 'done' ? 'done' : ( status === 'active' ? 'active' : '' );
        $stepList.append( '<li class="' + cls + '">' + label + '</li>' );
    }

    function markStepDone( index ) {
        $stepList.find( 'li' ).eq( index ).addClass( 'done' ).removeClass( 'active' );
    }

    function markStepActive( index ) {
        $stepList.find( 'li' ).removeClass( 'active' );
        $stepList.find( 'li' ).eq( index ).addClass( 'active' );
    }

    function hideModal() {
        $modal.hide();
    }

    function showProgress() {
        $progress.show();
        $errorDetails.hide();
        $errorScroll.text( '' );
        hadNonFatalImportIssues = false;
        phases.forEach( ( p, i ) => addStep( p.label, i === 0 ? 'active' : '' ) );
    }

    function appendImportLogLines( lines ) {
        if ( ! Array.isArray( lines ) || ! lines.length ) {
            return;
        }
        const block = lines.join( '\n' );
        const cur = $errorScroll.text();
        $errorScroll.text( cur ? cur + '\n' + block : block );
        $errorDetails.show();
        hadNonFatalImportIssues = true;
    }

    function showError( message ) {
        $errorScroll.text( message || '' );
        $errorDetails[ message ? 'show' : 'hide' ]();
    }

    function runPhase( phase ) {
        return new Promise( ( resolve, reject ) => {
            if ( cancelled ) {
                resolve( { cancelled: true } );
                return;
            }
            $currentPhase.text( phase.label + '...' );

            if ( phase.type === 'settings' ) {
                $.post( dtMigrationImport.ajaxUrl, appendFileJobId( {
                    action: 'dt_migration_import_batch',
                    nonce: dtMigrationImport.nonce,
                    import_channel: activeImportChannel,
                    step: 'settings',
                    settings_selected: phase.settings
                } ) ).done( function( r ) {
                    if ( r.success ) {
                        resolve( r.data );
                    } else {
                        reject( r.data && r.data.message ? r.data.message : 'Settings import failed' );
                    }
                } ).fail( function( xhr ) {
                    reject( xhr.statusText || 'Request failed' );
                } );
                return;
            }

            if ( phase.type === 'records' ) {
                let offset = 0;
                let totalImported = 0;
                const totalExpected = phase.records[ phase.post_type ] ? phase.records[ phase.post_type ].count : 0;

                function finishRecordPhase() {
                    if ( ! phase.isLastRecordTypePhase ) {
                        resolve( { imported: totalImported } );
                        return;
                    }
                    $currentPhase.text( 'Applying connection fields…' );
                    $.post( dtMigrationImport.ajaxUrl, appendFileJobId( {
                        action: 'dt_migration_import_batch',
                        nonce: dtMigrationImport.nonce,
                        import_channel: activeImportChannel,
                        step: 'apply_deferred_connections'
                    } ) ).done( function( r2 ) {
                        if ( r2.success ) {
                            const d2 = r2.data || {};
                            appendImportLogLines( d2.connection_errors );
                            resolve( { imported: totalImported } );
                        } else {
                            reject( r2.data && r2.data.message ? r2.data.message : 'Connection pass failed' );
                        }
                    } ).fail( function( xhr ) {
                        reject( xhr.statusText || 'Request failed' );
                    } );
                }

                function fetchBatch() {
                    if ( cancelled ) {
                        resolve( { cancelled: true } );
                        return;
                    }
                    const payload = {
                        action: 'dt_migration_import_batch',
                        nonce: dtMigrationImport.nonce,
                        import_channel: activeImportChannel,
                        step: 'records',
                        post_type: phase.post_type,
                        offset: offset
                    };
                    if ( offset === 0 && ! sentRecordsImportInit ) {
                        payload.init_records_import = '1';
                        sentRecordsImportInit = true;
                    }
                    $.post( dtMigrationImport.ajaxUrl, appendFileJobId( payload ) ).done( function( r ) {
                        if ( r.success ) {
                            const d = r.data;
                            appendImportLogLines( d.record_errors );
                            totalImported += d.imported || 0;
                            const pct = totalExpected ? ( totalImported / totalExpected ) * 100 : 100;
                            const phasePct = totalSteps ? ( currentPhaseIndex / totalSteps ) * 100 + ( pct / totalSteps ) : 0;
                            setProgress( Math.min( 100, phasePct ) );

                            if ( d.has_more ) {
                                offset = d.next_offset || ( offset + 50 );
                                fetchBatch();
                            } else {
                                finishRecordPhase();
                            }
                        } else {
                            reject( r.data && r.data.message ? r.data.message : 'Records import failed' );
                        }
                    } ).fail( function( xhr ) {
                        reject( xhr.statusText || 'Request failed' );
                    } );
                }
                fetchBatch();
            }
        } );
    }

    function startNextPhase() {
        if ( currentPhaseIndex >= phases.length ) {
            notifyFileJobCompleteIfNeeded();
            setImportSpinner( false );
            setProgress( 100 );
            $currentPhase.text(
                hadNonFatalImportIssues
                    ? t( 'importCompleteWithLog', 'Import complete. Review logged issues below.' )
                    : 'Import complete.'
            );
            $cancelImport.hide();
            return;
        }
        setImportSpinner( false );
        const phase = phases[ currentPhaseIndex ];
        if ( currentPhaseIndex === 0 ) {
            showFullModal( phase );
        } else {
            showSlimModal( phases[ currentPhaseIndex - 1 ], phase );
        }
    }

    function runCurrentPhase() {
        if ( currentPhaseIndex >= phases.length ) {
            return;
        }
        const phase = phases[ currentPhaseIndex ];
        hideModal();
        if ( ! $progress.is( ':visible' ) ) {
            showProgress();
            setProgress( 0 );
            $cancelImport.show();
        }
        setImportSpinner( true );
        markStepActive( currentPhaseIndex );
        runPhase( phase ).then( function( result ) {
            if ( result && result.cancelled ) {
                notifyFileJobCancelledIfNeeded();
                setImportSpinner( false );
                $currentPhase.text( 'Import cancelled.' );
                $cancelImport.hide();
                return;
            }
            markStepDone( currentPhaseIndex );
            completedSteps++;
            currentPhaseIndex++;
            startNextPhase();
        } ).catch( function( err ) {
            notifyFileJobFailedIfNeeded();
            setImportSpinner( false );
            $currentPhase.text( 'Import failed.' );
            showError( err );
            $cancelImport.hide();
        } );
    }

    function onConfirmClick() {
        if ( isSlimConfirmMode ) {
            runCurrentPhase();
            return;
        }
        const val = $confirmInput.val().trim().toUpperCase();
        if ( val === CONFIRM_WORD ) {
            runCurrentPhase();
        }
    }

    function init() {
        $modal = $( '#dt-migration-import-modal' );
        $modalTitle = $( '#dt-migration-modal-title' );
        $modalWarning = $( '.dt-migration-modal-warning' );
        $confirmGate = $( '.dt-migration-modal-confirm-gate' );
        $progress = $( '#dt-migration-progress-panel' );
        $confirmInput = $( '#dt-migration-confirm-input' );
        $confirmBtn = $( '.dt-migration-modal-confirm' );
        $cancelBtn = $( '.dt-migration-modal-cancel' );
        $summary = $( '.dt-migration-modal-summary' );
        $progressBar = $( '.dt-migration-progress-fill' );
        $progressText = $( '.dt-migration-progress-text' );
        $stepList = $( '.dt-migration-step-list' );
        $currentPhase = $( '.dt-migration-current-phase' );
        $cancelImport = $( '.dt-migration-cancel-import' );
        $importSpinner = $( '.dt-migration-import-spinner' );
        $errorDetails = $( '#dt-migration-error-details' );
        $errorScroll = $( '.dt-migration-error-scroll' );
        $pfModal = $( '#dt-migration-preflight-modal' );
        $pfInfoWrap = $( '.dt-migration-preflight-info-wrap' );
        $pfInfoText = $( '#dt-migration-preflight-info-text' );
        $pfWarningsWrap = $( '.dt-migration-preflight-warnings-wrap' );
        $pfWarningsText = $( '#dt-migration-preflight-warnings-text' );
        $pfStatus = $( '.dt-migration-preflight-status' );
        $pfProceed = $( '.dt-migration-preflight-proceed' );
        $pfClose = $( '.dt-migration-preflight-close' );

        // Recent-jobs Delete works on any page with the jobs table, even when no
        // upload preview is visible. Register before the modal guard below.
        $( document ).on( 'click', '.dt-migration-file-job-delete', function( e ) {
            e.preventDefault();
            const $btn = $( this );
            const id = $btn.data( 'job-id' );
            if ( ! id || ! window.confirm( t( 'deleteFileJobConfirm', 'Delete this file migration job and its stored data?' ) ) ) {
                return;
            }
            if ( typeof dtMigrationImport === 'undefined' ) {
                return;
            }
            $btn.prop( 'disabled', true );
            $.post( dtMigrationImport.ajaxUrl, {
                action: 'dt_migration_file_job_delete',
                nonce: dtMigrationImport.nonce,
                job_id: id
            } ).done( function( r ) {
                if ( r.success ) {
                    window.location.reload();
                    return;
                }
                window.alert( ( r.data && r.data.message ) ? r.data.message : t( 'deleteFileJobFailed', 'Could not delete the job.' ) );
                $btn.prop( 'disabled', false );
            } ).fail( function() {
                window.alert( t( 'deleteFileJobFailed', 'Could not delete the job.' ) );
                $btn.prop( 'disabled', false );
            } );
        } );

        if ( ! $modal.length || ! $( '.dt-migration-start-import' ).length ) {
            return;
        }

        $confirmInput.on( 'input', function() {
            if ( isSlimConfirmMode ) {
                return;
            }
            const val = $( this ).val().trim().toUpperCase();
            $confirmBtn.prop( 'disabled', val !== CONFIRM_WORD );
        } );

        $confirmInput.on( 'keydown', function( e ) {
            if ( e.key === 'Enter' ) {
                onConfirmClick();
            }
        } );

        $confirmBtn.on( 'click', onConfirmClick );

        $cancelBtn.on( 'click', function() {
            hideModal();
        } );

        $cancelImport.on( 'click', function() {
            cancelled = true;
            $( this ).prop( 'disabled', true ).text( 'Cancelling...' );
        } );

        $( '.dt-migration-run-preflight' ).on( 'click', function() {
            const $section = $( this ).closest( '.dt-migration-import-section' );
            runPreflightRequest( $section );
        } );

        $pfClose.on( 'click', function() {
            pendingPreflightSection = null;
            $pfModal.hide();
        } );
        $pfProceed.on( 'click', function() {
            const $section = pendingPreflightSection;
            pendingPreflightSection = null;
            $pfModal.hide();
            if ( $section && $section.length ) {
                beginImportFlow( $section );
            }
        } );

        $( '.dt-migration-start-import' ).on( 'click', function() {
            const $section = $( this ).closest( '.dt-migration-import-section' );
            beginImportFlow( $section );
        } );

        $( document ).on( 'change', '.dt-migration-select-all-settings', function() {
            const $section = $( this ).closest( '.dt-migration-import-section' );
            const checked = $( this ).prop( 'checked' );
            $section.find( '.dt-migration-setting-checkbox:not(:disabled)' ).prop( 'checked', checked );
        } );

        $( document ).on( 'change', '.dt-migration-select-all-records', function() {
            const $section = $( this ).closest( '.dt-migration-import-section' );
            const checked = $( this ).prop( 'checked' );
            $section.find( '.dt-migration-record-checkbox' ).prop( 'checked', checked );
        } );

    }

    $( document ).ready( init );

} )( jQuery );
